<?php
declare(strict_types=1);

namespace MonElevage\Auth;

use MonElevage\Admin\AdminService;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class AuthService
{
    private const REGISTRATION_CODE_ATTEMPTS = 5;

    private readonly AuthRateLimiter $rateLimiter;
    private readonly string $pepper;

    public function __construct(
        private readonly PDO $pdo,
        private readonly array $config,
        private readonly MailSender $mailer
    ) {
        $this->rateLimiter = new AuthRateLimiter($pdo);
        $databasePassword = (string) ($config['db']['password'] ?? '');
        $this->pepper = hash('sha256', 'mon-elevage-auth-v1:' . $databasePassword);
    }

    public static function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    public static function validatePassword(string $password): ?string
    {
        if (strlen($password) < 12) {
            return 'Le mot de passe doit contenir au moins 12 caractères.';
        }
        if (strlen($password) > 72) {
            return 'Le mot de passe ne peut pas dépasser 72 octets.';
        }

        return null;
    }

    public function register(
        string $fullName,
        string $email,
        string $password,
        string $farmName,
        bool $acceptedTerms,
        bool $acceptedPrivacy,
        bool $farmAuthorizationConfirmed,
        string $ipAddress = 'unknown'
    ): void {
        $fullName = trim($fullName);
        $farmName = trim($farmName);
        $email = self::normalizeEmail($email);
        if ($fullName === '' || mb_strlen($fullName, 'UTF-8') > 120) {
            throw new RuntimeException('Saisissez un nom valide (120 caractères maximum).');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new RuntimeException('Saisissez une adresse e-mail valide.');
        }
        if ($farmName === '' || mb_strlen($farmName, 'UTF-8') > 160) {
            throw new RuntimeException('Saisissez un nom d’exploitation valide (160 caractères maximum).');
        }
        if (!$acceptedTerms) {
            throw new RuntimeException('Vous devez accepter les conditions d’utilisation.');
        }
        if (!$acceptedPrivacy) {
            throw new RuntimeException('Vous devez accepter la politique de confidentialité.');
        }
        if (!$farmAuthorizationConfirmed) {
            throw new RuntimeException(
                'Confirmez que vous êtes autorisé à créer cet espace pour cette exploitation.'
            );
        }
        $passwordError = self::validatePassword($password);
        if ($passwordError !== null) {
            throw new RuntimeException($passwordError);
        }
        $emailRegistrationKey = $this->rateLimiter->key(
            'register',
            $email,
            $ipAddress,
            $this->pepper
        );
        $ipRegistrationKey = $this->rateLimiter->key(
            'register-ip',
            'all',
            $ipAddress,
            $this->pepper
        );
        $this->rateLimiter->assertAllowed($emailRegistrationKey);
        $this->rateLimiter->assertAllowed($ipRegistrationKey);
        $this->rateLimiter->consumeResetRequest($emailRegistrationKey);
        $this->rateLimiter->consumeResetRequest($ipRegistrationKey, 10);

        $existingUser = $this->pdo->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
        $existingUser->execute(['email' => $email]);
        if ($existingUser->fetchColumn() !== false) {
            throw new RuntimeException('Cette adresse e-mail est déjà utilisée.');
        }

        $this->pdo->exec(
            'DELETE FROM pending_registrations
             WHERE expires_at <= UTC_TIMESTAMP()
               AND created_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)
             ORDER BY expires_at
             LIMIT 100'
        );
        $code = $this->newRegistrationCode();
        $codeHash = $this->registrationCodeHash($email, $code);
        $statement = $this->pdo->prepare(
            'INSERT INTO pending_registrations
                (full_name, email, password_hash, farm_name, terms_version, privacy_version,
                 authorization_confirmed, consented_at, code_hash, expires_at, code_attempts)
             VALUES
                (:full_name, :email, :password_hash, :farm_name, :terms_version, :privacy_version,
                 1, UTC_TIMESTAMP(), :code_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 MINUTE), 0)
             ON DUPLICATE KEY UPDATE
                full_name = VALUES(full_name),
                password_hash = VALUES(password_hash),
                farm_name = VALUES(farm_name),
                terms_version = VALUES(terms_version),
                privacy_version = VALUES(privacy_version),
                authorization_confirmed = 1,
                consented_at = UTC_TIMESTAMP(),
                code_hash = VALUES(code_hash),
                expires_at = VALUES(expires_at),
                code_attempts = 0'
        );
        $statement->execute([
            'full_name' => $fullName,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'farm_name' => $farmName,
            'terms_version' => ConsentPolicy::TERMS_VERSION,
            'privacy_version' => ConsentPolicy::PRIVACY_VERSION,
            'code_hash' => $codeHash,
        ]);

        $this->deliverRegistrationCode($email, $code, $codeHash);
    }

    public function resendPendingRegistration(string $email, string $ipAddress): void
    {
        $email = self::normalizeEmail($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new RuntimeException('Saisissez une adresse e-mail valide.');
        }

        $key = $this->rateLimiter->key('register-resend', $email, $ipAddress, $this->pepper);
        $ipKey = $this->rateLimiter->key('register-resend-ip', 'all', $ipAddress, $this->pepper);
        $this->rateLimiter->assertAllowed($key);
        $this->rateLimiter->assertAllowed($ipKey);
        $this->rateLimiter->consumeResetRequest($key);
        $this->rateLimiter->consumeResetRequest($ipKey, 10);

        $statement = $this->pdo->prepare(
            'SELECT 1 FROM pending_registrations WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        if ($statement->fetchColumn() === false) {
            return;
        }

        $code = $this->newRegistrationCode();
        $codeHash = $this->registrationCodeHash($email, $code);
        $update = $this->pdo->prepare(
            'UPDATE pending_registrations
             SET code_hash = :code_hash,
                 expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 MINUTE),
                 code_attempts = 0
             WHERE email = :email'
        );
        $update->execute(['code_hash' => $codeHash, 'email' => $email]);
        if ($update->rowCount() !== 1) {
            return;
        }

        $this->deliverRegistrationCode($email, $code, $codeHash);
    }

    public function verifyPendingRegistration(string $email, string $code): int
    {
        $email = self::normalizeEmail($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
            throw new RuntimeException('Saisissez une adresse e-mail valide.');
        }

        $this->pdo->beginTransaction();
        try {
            $select = $this->pdo->prepare(
                'SELECT id, full_name, email, password_hash, farm_name, terms_version,
                        privacy_version, authorization_confirmed, consented_at, code_hash,
                        expires_at <= UTC_TIMESTAMP() AS expired, code_attempts
                 FROM pending_registrations
                 WHERE email = :email
                 LIMIT 1
                 FOR UPDATE'
            );
            $select->execute(['email' => $email]);
            $pending = $select->fetch();
            if (!is_array($pending)) {
                $this->pdo->rollBack();
                throw new RuntimeException('Le code est invalide ou expiré. Demandez un nouveau code.');
            }

            if ((int) $pending['expired'] === 1) {
                $this->pdo->commit();
                throw new RuntimeException('Le code a expiré. Demandez un nouveau code.');
            }

            $expectedHash = $this->registrationCodeHash($email, $code);
            if (preg_match('/\\A[0-9]{6}\\z/D', $code) !== 1
                || !hash_equals((string) $pending['code_hash'], $expectedHash)) {
                $attempts = (int) $pending['code_attempts'] + 1;
                if ($attempts >= self::REGISTRATION_CODE_ATTEMPTS) {
                    $this->pdo->prepare(
                        'DELETE FROM pending_registrations WHERE id = :id'
                    )->execute(['id' => (int) $pending['id']]);
                } else {
                    $this->pdo->prepare(
                        'UPDATE pending_registrations
                         SET code_attempts = :attempts
                         WHERE id = :id'
                    )->execute(['attempts' => $attempts, 'id' => (int) $pending['id']]);
                }
                $this->pdo->commit();
                throw new RuntimeException(
                    $attempts >= self::REGISTRATION_CODE_ATTEMPTS
                        ? 'Trop de codes incorrects. Recommencez la création du compte.'
                        : 'Le code est incorrect.'
                );
            }

            $insertUser = $this->pdo->prepare(
                "INSERT INTO users (full_name, email, password_hash, status, email_verified_at)
                 VALUES (:full_name, :email, :password_hash, 'active', UTC_TIMESTAMP())"
            );
            $insertUser->execute([
                'full_name' => $pending['full_name'],
                'email' => $pending['email'],
                'password_hash' => $pending['password_hash'],
            ]);
            $userId = (int) $this->pdo->lastInsertId();

            $consent = $this->pdo->prepare(
                'INSERT INTO user_consents
                    (user_id, terms_version, privacy_version, authorization_confirmed, accepted_at)
                 VALUES (:user_id, :terms_version, :privacy_version, :authorization_confirmed, :accepted_at)'
            );
            $consent->execute([
                'user_id' => $userId,
                'terms_version' => $pending['terms_version'],
                'privacy_version' => $pending['privacy_version'],
                'authorization_confirmed' => (int) $pending['authorization_confirmed'],
                'accepted_at' => $pending['consented_at'],
            ]);

            $farm = $this->pdo->prepare(
                'INSERT INTO farms (owner_user_id, name, currency_code)
                 VALUES (:owner_user_id, :name, :currency_code)'
            );
            $farm->execute([
                'owner_user_id' => $userId,
                'name' => $pending['farm_name'],
                'currency_code' => AdminService::settingValue(
                    $this->pdo,
                    'default_currency',
                    (string) ($this->config['default_currency'] ?? 'XOF')
                ),
            ]);
            $farmId = (int) $this->pdo->lastInsertId();

            $membership = $this->pdo->prepare(
                "INSERT INTO farm_members (farm_id, user_id, role, status)
                 VALUES (:farm_id, :user_id, 'owner', 'active')"
            );
            $membership->execute(['farm_id' => $farmId, 'user_id' => $userId]);
            $this->pdo->prepare(
                'DELETE FROM pending_registrations WHERE id = :id'
            )->execute(['id' => (int) $pending['id']]);
            $this->pdo->commit();

            return $userId;
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('Cette adresse e-mail est déjà utilisée.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function newRegistrationCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function registrationCodeHash(string $email, string $code): string
    {
        return hash_hmac('sha256', "registration-code\0" . $email . "\0" . $code, $this->pepper);
    }

    private function deliverRegistrationCode(string $email, string $code, string $codeHash): void
    {
        try {
            $this->mailer->send(
                $email,
                'Code de confirmation — Mon Élevage',
                "Votre code de confirmation est : {$code}\n\n"
                . "Il est valable pendant une minute. Si vous n’avez pas demandé de compte, "
                . "ignorez ce message."
            );
        } catch (Throwable $exception) {
            $this->pdo->prepare(
                'DELETE FROM pending_registrations
                 WHERE email = :email AND code_hash = :code_hash'
            )->execute(['email' => $email, 'code_hash' => $codeHash]);
            error_log('Registration confirmation email delivery failed.');
            throw new RuntimeException(
                'Le courriel de confirmation n’a pas pu être envoyé. Vérifiez la configuration du service de messagerie.'
            );
        }

        $this->pdo->prepare(
            'UPDATE pending_registrations
             SET expires_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)
             WHERE email = :email AND code_hash = :code_hash'
        )->execute(['email' => $email, 'code_hash' => $codeHash]);
    }

    public function requestEmailVerification(
        string $email,
        string $ipAddress,
        string $scriptPath
    ): void {
        $email = self::normalizeEmail($email);
        $key = $this->rateLimiter->key('verification', $email, $ipAddress, $this->pepper);
        try {
            $this->rateLimiter->assertAllowed($key);
            $this->rateLimiter->consumeResetRequest($key);
        } catch (RuntimeException) {
            error_log('Email verification request throttled.');
            return;
        }

        $statement = $this->pdo->prepare(
            "SELECT id, email
             FROM users
             WHERE email = :email AND status = 'pending'
             LIMIT 1"
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();
        if (!is_array($user)) {
            return;
        }

        $invalidate = $this->pdo->prepare(
            "UPDATE auth_tokens
             SET used_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id
               AND purpose = 'email_verification'
               AND used_at IS NULL"
        );
        $invalidate->execute(['user_id' => (int) $user['id']]);
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $insert = $this->pdo->prepare(
            "INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at)
             VALUES (:user_id, 'email_verification', :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))"
        );
        $insert->execute(['user_id' => (int) $user['id'], 'token_hash' => $tokenHash]);
        $tokenId = (int) $this->pdo->lastInsertId();

        $baseUrl = rtrim(
            (string) ($this->config['base_url'] ?? 'http://localhost/mon-elevage'),
            '/'
        );
        $link = $baseUrl . '/' . ltrim($scriptPath, '/')
            . '?view=verify&token=' . rawurlencode($token);

        try {
            $this->mailer->send(
                (string) $user['email'],
                'Vérification de votre adresse — Mon Élevage',
                "Confirmez la création de votre compte en ouvrant ce lien dans les 24 heures :\n"
                . $link . "\n\nSi vous n’avez pas demandé de compte, ignorez ce message."
            );
        } catch (Throwable $exception) {
            $delete = $this->pdo->prepare(
                "DELETE FROM auth_tokens
                 WHERE id = :id AND purpose = 'email_verification' AND used_at IS NULL"
            );
            $delete->execute(['id' => $tokenId]);
            error_log(sprintf(
                'Email verification delivery failed: %s',
                $exception->getMessage()
            ));
        }
    }

    public function isEmailVerificationTokenValid(string $token): bool
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            return false;
        }

        $statement = $this->pdo->prepare(
            "SELECT 1
             FROM auth_tokens
             WHERE token_hash = :token_hash
               AND purpose = 'email_verification'
               AND used_at IS NULL
               AND expires_at > UTC_TIMESTAMP()
             LIMIT 1"
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        return $statement->fetchColumn() !== false;
    }

    public function verifyEmail(string $token): void
    {
        if (!$this->isEmailVerificationTokenValid($token)) {
            throw new RuntimeException('Ce lien de vérification est invalide ou expiré.');
        }

        try {
            $this->pdo->beginTransaction();
            $select = $this->pdo->prepare(
                "SELECT id, user_id
                 FROM auth_tokens
                 WHERE token_hash = :token_hash
                   AND purpose = 'email_verification'
                   AND used_at IS NULL
                   AND expires_at > UTC_TIMESTAMP()
                 LIMIT 1
                 FOR UPDATE"
            );
            $select->execute(['token_hash' => hash('sha256', $token)]);
            $verification = $select->fetch();
            if (!is_array($verification)) {
                throw new RuntimeException('Ce lien de vérification est invalide ou expiré.');
            }

            $update = $this->pdo->prepare(
                "UPDATE users
                 SET status = 'active', email_verified_at = UTC_TIMESTAMP()
                 WHERE id = :user_id AND status = 'pending'"
            );
            $update->execute(['user_id' => (int) $verification['user_id']]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Ce lien de vérification est invalide ou expiré.');
            }

            $consume = $this->pdo->prepare(
                'UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE id = :id'
            );
            $consume->execute(['id' => (int) $verification['id']]);
            $invalidate = $this->pdo->prepare(
                "UPDATE auth_tokens
                 SET used_at = UTC_TIMESTAMP()
                 WHERE user_id = :user_id
                   AND purpose = 'email_verification'
                   AND used_at IS NULL"
            );
            $invalidate->execute(['user_id' => (int) $verification['user_id']]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function authenticate(string $email, string $password, string $ipAddress): int
    {
        $email = self::normalizeEmail($email);
        $key = $this->rateLimiter->key('login', $email, $ipAddress, $this->pepper);
        $this->rateLimiter->assertAllowed($key);

        $statement = $this->pdo->prepare(
            'SELECT id, password_hash, status
             FROM users
             WHERE email = :email
             LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();
        $hash = is_array($user)
            ? (string) $user['password_hash']
            : password_hash('not-a-real-password', PASSWORD_DEFAULT);
        $validPassword = password_verify(strlen($password) <= 72 ? $password : '', $hash);

        if (!is_array($user) || $user['status'] !== 'active' || !$validPassword) {
            $this->rateLimiter->failedLogin($key);
            throw new RuntimeException('Adresse e-mail ou mot de passe incorrect.');
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $rehash = $this->pdo->prepare(
                'UPDATE users SET password_hash = :password_hash WHERE id = :id'
            );
            $rehash->execute([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'id' => (int) $user['id'],
            ]);
        }

        $this->rateLimiter->clear($key);
        return (int) $user['id'];
    }

    public function requestPasswordReset(
        string $email,
        string $ipAddress,
        string $scriptPath
    ): void {
        $email = self::normalizeEmail($email);
        $key = $this->rateLimiter->key('reset', $email, $ipAddress, $this->pepper);
        try {
            $this->rateLimiter->assertAllowed($key);
            $this->rateLimiter->consumeResetRequest($key);
        } catch (RuntimeException $exception) {
            error_log('Password reset request throttled.');
            return;
        }

        $statement = $this->pdo->prepare(
            "SELECT id, email
             FROM users
             WHERE email = :email AND status = 'active'
             LIMIT 1"
        );
        $statement->execute(['email' => $email]);
        $user = $statement->fetch();
        if (!is_array($user)) {
            return;
        }

        $invalidate = $this->pdo->prepare(
            "UPDATE auth_tokens
             SET used_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id
               AND purpose = 'password_reset'
               AND used_at IS NULL"
        );
        $invalidate->execute(['user_id' => (int) $user['id']]);
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $insert = $this->pdo->prepare(
            "INSERT INTO auth_tokens (user_id, purpose, token_hash, expires_at)
             VALUES (:user_id, 'password_reset', :token_hash, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 30 MINUTE))"
        );
        $insert->execute(['user_id' => (int) $user['id'], 'token_hash' => $tokenHash]);
        $tokenId = (int) $this->pdo->lastInsertId();

        $baseUrl = rtrim(
            (string) ($this->config['base_url'] ?? 'http://localhost/mon-elevage'),
            '/'
        );
        $link = $baseUrl . '/' . ltrim($scriptPath, '/')
            . '?view=reset&token=' . rawurlencode($token);

        try {
            $this->mailer->send(
                (string) $user['email'],
                'Réinitialisation du mot de passe — Mon Élevage',
                "Une demande de réinitialisation a été effectuée pour votre compte.\n\n"
                . "Ouvrez ce lien dans les 30 minutes :\n$link\n\n"
                . "Si vous n’êtes pas à l’origine de cette demande, ignorez ce message."
            );
        } catch (Throwable $exception) {
            $delete = $this->pdo->prepare(
                "DELETE FROM auth_tokens
                 WHERE id = :id AND purpose = 'password_reset' AND used_at IS NULL"
            );
            $delete->execute(['id' => $tokenId]);
            error_log(sprintf(
                'Password reset delivery failed: %s',
                $exception->getMessage()
            ));
        }
    }

    public function resetPassword(string $token, string $newPassword): void
    {
        $passwordError = self::validatePassword($newPassword);
        if ($passwordError !== null) {
            throw new RuntimeException($passwordError);
        }
        if (preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            throw new RuntimeException('Ce lien de réinitialisation est invalide ou expiré.');
        }

        try {
            $this->pdo->beginTransaction();
            $statement = $this->pdo->prepare(
                "SELECT id, user_id
                 FROM auth_tokens
                 WHERE token_hash = :token_hash
                   AND purpose = 'password_reset'
                   AND used_at IS NULL
                   AND expires_at > UTC_TIMESTAMP()
                 LIMIT 1
                 FOR UPDATE"
            );
            $statement->execute(['token_hash' => hash('sha256', $token)]);
            $reset = $statement->fetch();
            if (!is_array($reset)) {
                throw new RuntimeException('Ce lien de réinitialisation est invalide ou expiré.');
            }

            $update = $this->pdo->prepare(
                "UPDATE users
                 SET password_hash = :password_hash
                 WHERE id = :user_id AND status = 'active'"
            );
            $update->execute([
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'user_id' => (int) $reset['user_id'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Ce lien de réinitialisation est invalide ou expiré.');
            }

            $consume = $this->pdo->prepare(
                'UPDATE auth_tokens SET used_at = UTC_TIMESTAMP() WHERE id = :id'
            );
            $consume->execute(['id' => (int) $reset['id']]);
            $invalidate = $this->pdo->prepare(
                "UPDATE auth_tokens
                 SET used_at = UTC_TIMESTAMP()
                 WHERE user_id = :user_id
                   AND purpose = 'password_reset'
                   AND used_at IS NULL"
            );
            $invalidate->execute(['user_id' => (int) $reset['user_id']]);
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function isPasswordResetTokenValid(string $token): bool
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $token) !== 1) {
            return false;
        }

        $statement = $this->pdo->prepare(
            "SELECT 1
             FROM auth_tokens
             WHERE token_hash = :token_hash
               AND purpose = 'password_reset'
               AND used_at IS NULL
               AND expires_at > UTC_TIMESTAMP()
             LIMIT 1"
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        return $statement->fetchColumn() !== false;
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $passwordError = self::validatePassword($newPassword);
        if ($passwordError !== null) {
            throw new RuntimeException($passwordError);
        }
        if (hash_equals($currentPassword, $newPassword)) {
            throw new RuntimeException('Le nouveau mot de passe doit être différent de l’ancien.');
        }

        $statement = $this->pdo->prepare(
            "SELECT password_hash FROM users WHERE id = :id AND status = 'active' LIMIT 1"
        );
        $statement->execute(['id' => $userId]);
        $hash = $statement->fetchColumn();
        if (!is_string($hash) || !password_verify($currentPassword, $hash)) {
            throw new RuntimeException('Le mot de passe actuel est incorrect.');
        }

        $update = $this->pdo->prepare(
            'UPDATE users SET password_hash = :password_hash WHERE id = :id'
        );
        $update->execute([
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            'id' => $userId,
        ]);
        $invalidate = $this->pdo->prepare(
            "UPDATE auth_tokens
             SET used_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id
               AND purpose = 'password_reset'
               AND used_at IS NULL"
        );
        $invalidate->execute(['user_id' => $userId]);
    }

    public function updateProfile(int $userId, string $fullName): void
    {
        $fullName = trim($fullName);
        if ($fullName === '' || mb_strlen($fullName, 'UTF-8') > 120) {
            throw new RuntimeException('Saisissez un nom valide (120 caractères maximum).');
        }

        $statement = $this->pdo->prepare(
            "UPDATE users
             SET full_name = :full_name
             WHERE id = :id AND status = 'active'"
        );
        $statement->execute(['full_name' => $fullName, 'id' => $userId]);
    }

    public function user(int $userId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, full_name, email, is_admin, profile_photo_mime_type, updated_at
             FROM users
             WHERE id = :id AND status = 'active'
             LIMIT 1"
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();
        if (!is_array($user)) {
            throw new RuntimeException('Compte introuvable ou désactivé.');
        }

        return $user;
    }
}
