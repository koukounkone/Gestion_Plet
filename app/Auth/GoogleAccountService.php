<?php
declare(strict_types=1);

namespace MonElevage\Auth;

use MonElevage\Admin\AdminService;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class GoogleAccountService
{
    public function __construct(private readonly PDO $pdo, private readonly array $config)
    {
    }

    public function findLinkedAccount(string $subject): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT u.id, u.status
             FROM oauth_identities i
             JOIN users u ON u.id = i.user_id
             WHERE i.provider = \'google\' AND i.provider_subject = :subject
             LIMIT 1'
        );
        $statement->execute(['subject' => $subject]);
        $account = $statement->fetch();
        return is_array($account)
            ? ['user_id' => (int) $account['id'], 'status' => (string) $account['status']]
            : null;
    }

    public function findAccountByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, status FROM users WHERE email = :email LIMIT 1'
        );
        $statement->execute(['email' => AuthService::normalizeEmail($email)]);
        $account = $statement->fetch();
        return is_array($account)
            ? ['user_id' => (int) $account['id'], 'status' => (string) $account['status']]
            : null;
    }

    public function hasGoogleIdentity(int $userId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM oauth_identities WHERE user_id = :user_id AND provider = \'google\' LIMIT 1'
        );
        $statement->execute(['user_id' => $userId]);
        return $statement->fetchColumn() !== false;
    }

    public function createAccount(
        array $profile,
        string $farmName,
        bool $acceptedTerms,
        bool $acceptedPrivacy,
        bool $farmAuthorizationConfirmed,
        string $requestedName = ''
    ): int {
        $email = AuthService::normalizeEmail((string) ($profile['email'] ?? ''));
        $name = trim((string) ($profile['name'] ?? ''));
        if ($name === '' || mb_strlen($name, 'UTF-8') > 120) {
            $name = trim($requestedName);
        }
        $farmName = trim($farmName);
        if (($profile['email_verified'] ?? false) !== true
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || strlen($email) > 254
            || $name === ''
            || mb_strlen($name, 'UTF-8') > 120
            || $farmName === ''
            || mb_strlen($farmName, 'UTF-8') > 160) {
            throw new RuntimeException('Les informations du compte ou de l’exploitation sont invalides.');
        }
        if (!$acceptedTerms || !$acceptedPrivacy || !$farmAuthorizationConfirmed) {
            throw new RuntimeException('Les trois confirmations sont obligatoires pour créer le compte.');
        }
        $subject = $this->validatedSubject($profile);

        try {
            $this->pdo->beginTransaction();
            if ($this->findAccountByEmail($email) !== null) {
                throw new RuntimeException(
                    'Cette adresse e-mail est déjà associée à un compte. Connectez-vous à ce compte avant de lier Google.'
                );
            }
            if ($this->findLinkedAccount($subject) !== null) {
                throw new RuntimeException('Ce compte Google est déjà associé à un compte Mon Élevage.');
            }

            $user = $this->pdo->prepare(
                "INSERT INTO users (full_name, email, password_hash, status, email_verified_at)
                 VALUES (:name, :email, :password_hash, 'active', UTC_TIMESTAMP())"
            );
            $user->execute([
                'name' => $name,
                'email' => $email,
                'password_hash' => password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
            ]);
            $userId = (int) $this->pdo->lastInsertId();

            $consent = $this->pdo->prepare(
                'INSERT INTO user_consents
                    (user_id, terms_version, privacy_version, authorization_confirmed)
                 VALUES (:user_id, :terms_version, :privacy_version, 1)'
            );
            $consent->execute([
                'user_id' => $userId,
                'terms_version' => ConsentPolicy::TERMS_VERSION,
                'privacy_version' => ConsentPolicy::PRIVACY_VERSION,
            ]);

            $farm = $this->pdo->prepare(
                'INSERT INTO farms (owner_user_id, name, currency_code)
                 VALUES (:user_id, :name, :currency_code)'
            );
            $farm->execute([
                'user_id' => $userId,
                'name' => $farmName,
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
            $this->insertIdentity($userId, $subject, $email);
            $this->writeAudit($farmId, $userId, 'google.account_created', $userId);
            $this->pdo->commit();
            return $userId;
        } catch (PDOException $exception) {
            $this->rollback();
            if ($exception->getCode() === '23000') {
                throw new RuntimeException(
                    'Cette adresse ou ce compte Google est déjà associé à un compte. Connectez-vous puis liez les comptes depuis votre profil.'
                );
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function linkIdentity(int $userId, array $profile): void
    {
        $email = AuthService::normalizeEmail((string) ($profile['email'] ?? ''));
        if (($profile['email_verified'] ?? false) !== true
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || strlen($email) > 254) {
            throw new RuntimeException('Google doit confirmer l’adresse e-mail avant la liaison.');
        }
        $subject = $this->validatedSubject($profile);
        try {
            $this->pdo->beginTransaction();
            $userQuery = $this->pdo->prepare(
                "SELECT email FROM users WHERE id = :user_id AND status = 'active' LIMIT 1 FOR UPDATE"
            );
            $userQuery->execute(['user_id' => $userId]);
            $accountEmail = $userQuery->fetchColumn();
            if (!is_string($accountEmail)) {
                throw new RuntimeException('Le compte est introuvable ou désactivé.');
            }
            if (!hash_equals(AuthService::normalizeEmail($accountEmail), $email)) {
                throw new RuntimeException(
                    'Pour lier Google, utilisez un compte Google vérifié avec la même adresse e-mail que votre compte Mon Élevage.'
                );
            }

            $existingSubject = $this->findLinkedAccount($subject);
            if ($existingSubject !== null) {
                if ($existingSubject['user_id'] === $userId) {
                    $this->pdo->commit();
                    return;
                }
                throw new RuntimeException('Ce compte Google est déjà associé à un autre compte.');
            }
            if ($this->hasGoogleIdentity($userId)) {
                throw new RuntimeException('Un compte Google est déjà associé à votre compte Mon Élevage.');
            }

            $this->insertIdentity($userId, $subject, $email);
            $this->writeAudit(null, $userId, 'google.identity_linked', $userId);
            $this->pdo->commit();
        } catch (PDOException $exception) {
            $this->rollback();
            if ($exception->getCode() === '23000') {
                throw new RuntimeException('Ce compte Google est déjà associé à un compte.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    private function insertIdentity(int $userId, string $subject, string $email): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO oauth_identities (user_id, provider, provider_subject, provider_email)
             VALUES (:user_id, \'google\', :subject, :email)'
        );
        $statement->execute([
            'user_id' => $userId,
            'subject' => $subject,
            'email' => $email,
        ]);
    }

    private function writeAudit(?int $farmId, int $actorId, string $action, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs
                (farm_id, actor_user_id, action, resource_type, resource_id, details)
             VALUES (:farm_id, :actor_id, :action, \'user\', :user_id, :details)'
        );
        $statement->execute([
            'farm_id' => $farmId,
            'actor_id' => $actorId,
            'action' => $action,
            'user_id' => $userId,
            'details' => json_encode(
                ['provider' => 'google'],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            ),
        ]);
    }

    private function validatedSubject(array $profile): string
    {
        $subject = $profile['subject'] ?? null;
        if (!is_string($subject) || $subject === '' || strlen($subject) > 255) {
            throw new RuntimeException('L’identifiant du compte Google est invalide.');
        }
        return $subject;
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
