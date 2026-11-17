<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\AuthRateLimiter;
use MonElevage\Auth\AuthService;
use MonElevage\Auth\FarmAccess;
use MonElevage\Auth\MailSender;
use MonElevage\Auth\NativeMailSender;
use MonElevage\Auth\SmtpMailSender;
use MonElevage\Database\Connection;
use MonElevage\Support\AuthSession;

final class TestMailSender implements MailSender
{
    public array $messages = [];

    public function send(string $recipient, string $subject, string $body): void
    {
        $this->messages[] = [
            'recipient' => $recipient,
            'subject' => $subject,
            'body' => $body,
        ];
    }
}

$pdo = Connection::get($config);
$mailer = new TestMailSender();
$testConfig = $config;
$testConfig['base_url'] = 'http://localhost/mon-elevage';
$auth = new AuthService($pdo, $testConfig, $mailer);
$limiter = new AuthRateLimiter($pdo);
$suffix = bin2hex(random_bytes(5));
$emailOne = 'auth-test-one-' . $suffix . '@example.test';
$emailTwo = 'auth-test-two-' . $suffix . '@example.test';
$emailThree = 'auth-test-three-' . $suffix . '@example.test';
$emailFour = 'auth-test-four-' . $suffix . '@example.test';
$unknownEmail = 'auth-test-missing-' . $suffix . '@example.test';
$passwordOne = 'Poultry-Access-2026!';
$passwordTwo = 'Another-Secret-2026!';
$ipAddresses = [
    '198.51.100.17',
    '198.51.100.18',
    '198.51.100.19',
    '198.51.100.20',
    '127.0.0.1',
    'unknown',
];
$userIds = [];
$farmIds = [];
$passed = 0;

$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('Échec du test : ' . $message);
    }
    $passed++;
};
$expectFailure = static function (callable $operation, string $message) use ($assert): void {
    try {
        $operation();
    } catch (RuntimeException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

try {
    $assert(AuthService::validatePassword('short') !== null, 'rejette un mot de passe trop court');
    $assert(AuthService::validatePassword(str_repeat('x', 73)) !== null, 'rejette un mot de passe dépassant 72 octets');
    $assert(AuthService::validatePassword($passwordOne) === null, 'accepte une phrase de passe conforme');
    $expectFailure(
        static fn () => (new SmtpMailSender([]))->send($emailOne, 'Test', 'Test'),
        'refuse un transport SMTP sans configuration sécurisée'
    );
    $expectFailure(
        static fn () => $auth->register('', $emailOne, $passwordOne, 'Ferme test', true, true, true),
        'rejette un nom vide'
    );
    $expectFailure(
        static fn () => $auth->register(
            'Utilisateur test',
            'adresse invalide',
            $passwordOne,
            'Ferme test',
            true,
            true,
            true
        ),
        'rejette un courriel invalide'
    );

    $expectFailure(
        static fn () => $auth->register(
            'Utilisateur test',
            $emailOne,
            $passwordOne,
            'Ferme test',
            false,
            true,
            true
        ),
        'refuse la création sans acceptation des conditions'
    );
    $expectFailure(
        static fn () => $auth->register(
            'Utilisateur test',
            $emailOne,
            $passwordOne,
            'Ferme test',
            true,
            false,
            true
        ),
        'refuse la création sans acceptation de la confidentialité'
    );
    $expectFailure(
        static fn () => $auth->register(
            'Utilisateur test',
            $emailOne,
            $passwordOne,
            'Ferme test',
            true,
            true,
            false
        ),
        'refuse la création sans confirmation de l’autorisation'
    );
    $userCount = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :email');
    $userCount->execute(['email' => $emailOne]);
    $assert((int) $userCount->fetchColumn() === 0, 'ne crée aucun compte sans les trois confirmations');
    $auth->register(
        'Utilisateur Test Un',
        $emailOne,
        $passwordOne,
        'Ferme Test Un',
        true,
        true,
        true,
        $ipAddresses[0]
    );
    $auth->register(
        'Utilisateur Test Deux',
        $emailTwo,
        $passwordTwo,
        'Ferme Test Deux',
        true,
        true,
        true,
        $ipAddresses[1]
    );
    $expectFailure(
        static fn () => $auth->authenticate($emailOne, $passwordOne, $ipAddresses[0]),
        'refuse la connexion avant la confirmation du code'
    );
    $userCount->execute(['email' => $emailOne]);
    $assert((int) $userCount->fetchColumn() === 0, 'ne crée pas le compte avant le bon code');
    preg_match('/code de confirmation est : ([0-9]{6})/i', $mailer->messages[0]['body'], $codeOneMatch);
    preg_match('/code de confirmation est : ([0-9]{6})/i', $mailer->messages[1]['body'], $codeTwoMatch);
    $codeOne = $codeOneMatch[1] ?? '';
    $codeTwo = $codeTwoMatch[1] ?? '';
    $assert(strlen($codeOne) === 6 && strlen($codeTwo) === 6, 'envoie des codes aléatoires à six chiffres');
    $assert(str_contains($mailer->messages[0]['body'], 'une minute'), 'annonce une validité d’une minute');
    $pending = $pdo->prepare(
        'SELECT password_hash, code_hash, expires_at FROM pending_registrations WHERE email = :email'
    );
    $pending->execute(['email' => $emailOne]);
    $pendingRegistration = $pending->fetch();
    $expirySeconds = $pdo->prepare(
        'SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), expires_at)
         FROM pending_registrations WHERE email = :email'
    );
    $expirySeconds->execute(['email' => $emailOne]);
    $expiryRemaining = (int) $expirySeconds->fetchColumn();
    $assert(
        is_array($pendingRegistration)
            && password_verify($passwordOne, (string) $pendingRegistration['password_hash']),
        'stocke uniquement une empreinte vérifiable du mot de passe en attente'
    );
    $assert($expiryRemaining >= 1 && $expiryRemaining <= 60, 'expire le code après une minute');
    $assert(
        is_array($pendingRegistration)
            && (string) $pendingRegistration['code_hash'] !== $codeOne,
        'ne stocke pas le code en clair'
    );
    $wrongCodeOne = $codeOne === '000000' ? '000001' : '000000';
    $expectFailure(
        static fn () => $auth->verifyPendingRegistration($emailOne, $wrongCodeOne),
        'refuse un code incorrect'
    );
    $userCount->execute(['email' => $emailOne]);
    $assert((int) $userCount->fetchColumn() === 0, 'un code incorrect ne crée aucun compte');

    $pdo->prepare(
        'UPDATE pending_registrations
         SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND)
         WHERE email = :email'
    )->execute(['email' => $emailTwo]);
    $expectFailure(
        static fn () => $auth->verifyPendingRegistration($emailTwo, $codeTwo),
        'refuse un code expiré'
    );
    $userCount->execute(['email' => $emailTwo]);
    $assert((int) $userCount->fetchColumn() === 0, 'un code expiré ne crée aucun compte');
    $auth->resendPendingRegistration($emailTwo, $ipAddresses[4]);
    preg_match('/code de confirmation est : ([0-9]{6})/i', $mailer->messages[2]['body'], $resentExpiredMatch);
    $resentExpiredCode = $resentExpiredMatch[1] ?? '';
    $assert(strlen($resentExpiredCode) === 6, 'permet de renvoyer un code après expiration');
    $userTwoId = $auth->verifyPendingRegistration($emailTwo, $resentExpiredCode);

    $userIds[] = $auth->verifyPendingRegistration($emailOne, $codeOne);
    $assert(count($userIds) === 1, 'crée le compte seulement après confirmation');
    $userIds[] = $userTwoId;
    $expectFailure(
        static fn () => $auth->verifyPendingRegistration($emailOne, $codeOne),
        'refuse la réutilisation d’un code confirmé'
    );
    $userStatement = $pdo->prepare(
        'SELECT status, email_verified_at, password_hash FROM users WHERE id = :id'
    );
    $userStatement->execute(['id' => $userIds[0]]);
    $user = $userStatement->fetch();
    $assert(
        is_array($user) && $user['status'] === 'active' && $user['email_verified_at'] !== null,
        'active immédiatement le compte après confirmation'
    );
    $assert(
        is_array($user) && password_verify($passwordOne, (string) $user['password_hash']),
        'transfère le hash du mot de passe au compte créé'
    );
    $consent = $pdo->prepare(
        'SELECT terms_version, privacy_version, authorization_confirmed, accepted_at
         FROM user_consents WHERE user_id = :user_id ORDER BY id DESC LIMIT 1'
    );
    $consent->execute(['user_id' => $userIds[0]]);
    $accepted = $consent->fetch();
    $assert(
        is_array($accepted)
            && $accepted['terms_version'] === '1.0-draft'
            && $accepted['privacy_version'] === '1.0-draft'
            && (int) $accepted['authorization_confirmed'] === 1
            && is_string($accepted['accepted_at']),
        'enregistre les consentements uniquement avec le compte confirmé'
    );

    $auth->register(
        'Utilisateur Test Trois',
        $emailThree,
        $passwordTwo,
        'Ferme Test Trois',
        true,
        true,
        true,
        $ipAddresses[2]
    );
    preg_match('/code de confirmation est : ([0-9]{6})/i', $mailer->messages[3]['body'], $firstResendMatch);
    $firstResendCode = $firstResendMatch[1] ?? '';
    $assert(strlen($firstResendCode) === 6, 'génère un code avant le renvoi');
    $auth->resendPendingRegistration($emailThree, $ipAddresses[4]);
    preg_match('/code de confirmation est : ([0-9]{6})/i', $mailer->messages[4]['body'], $resentCodeMatch);
    $resentCode = $resentCodeMatch[1] ?? '';
    $assert(strlen($resentCode) === 6, 'renvoie un nouveau code de confirmation');
    $expectFailure(
        static fn () => $auth->verifyPendingRegistration($emailThree, $firstResendCode),
        'invalide le code précédent lors d’un renvoi'
    );
    $userIds[] = $auth->verifyPendingRegistration($emailThree, $resentCode);

    $auth->register(
        'Utilisateur Test Quatre',
        $emailFour,
        $passwordTwo,
        'Ferme Test Quatre',
        true,
        true,
        true,
        $ipAddresses[3]
    );
    preg_match('/code de confirmation est : ([0-9]{6})/i', $mailer->messages[5]['body'], $codeFourMatch);
    $codeFour = $codeFourMatch[1] ?? '';
    $wrongCodeFour = $codeFour === '000000' ? '000001' : '000000';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $expectFailure(
            static fn () => $auth->verifyPendingRegistration($emailFour, $wrongCodeFour),
            'limite les tentatives de saisie du code'
        );
    }
    $userCount->execute(['email' => $emailFour]);
    $assert(
        (int) $userCount->fetchColumn() === 0,
        'supprime une inscription après cinq codes incorrects'
    );
    $expectFailure(
        static fn () => $auth->verifyPendingRegistration($emailFour, $codeFour),
        'ne permet pas de confirmer après le blocage des codes'
    );

    $farmQuery = $pdo->prepare(
        'SELECT id FROM farms WHERE owner_user_id = :user_id ORDER BY id'
    );
    $farmQuery->execute(['user_id' => $userIds[0]]);
    $assert($farmQuery->fetchColumn() !== false, 'crée l’exploitation pendant la confirmation du compte');

    foreach ($userIds as $userId) {
        $farmQuery->execute(['user_id' => $userId]);
        $farmIds[] = (int) $farmQuery->fetchColumn();
    }
    $assert(FarmAccess::role($pdo, $userIds[0], $farmIds[0]) === 'owner', 'attribue le rôle propriétaire');
    $expectFailure(
        static fn () => FarmAccess::role($pdo, $userIds[0], $farmIds[1]),
        'refuse l’accès à l’exploitation d’un autre utilisateur'
    );
    $expectFailure(
        static fn () => $auth->register(
            'Autre nom',
            $emailOne,
            $passwordOne,
            'Autre ferme',
            true,
            true,
            true
        ),
        'refuse un e-mail déjà enregistré'
    );

    $membership = $pdo->prepare(
        "UPDATE farm_members SET role = 'operator'
         WHERE farm_id = :farm_id AND user_id = :user_id"
    );
    $membership->execute(['farm_id' => $farmIds[1], 'user_id' => $userIds[1]]);
    $assert(
        FarmAccess::allows($pdo, $userIds[1], $farmIds[1], 'followup.write'),
        'autorise la saisie au rôle opérateur'
    );
    $assert(
        !FarmAccess::allows($pdo, $userIds[1], $farmIds[1], 'sale.manage'),
        'refuse la gestion des ventes à l’opérateur'
    );
    $membership = $pdo->prepare(
        "UPDATE farm_members SET role = 'accountant'
         WHERE farm_id = :farm_id AND user_id = :user_id"
    );
    $membership->execute(['farm_id' => $farmIds[1], 'user_id' => $userIds[1]]);
    $assert(
        FarmAccess::allows($pdo, $userIds[1], $farmIds[1], 'payment.manage'),
        'autorise le suivi des paiements au comptable'
    );
    $assert(
        !FarmAccess::allows($pdo, $userIds[1], $farmIds[1], 'expense.manage'),
        'interdit les modifications comptables au comptable dans cette matrice minimale'
    );

    $expectFailure(
        static fn () => $auth->authenticate($emailOne, 'incorrect-password', $ipAddresses[0]),
        'refuse un mot de passe incorrect'
    );
    $expectFailure(
        static fn () => $auth->authenticate($unknownEmail, $passwordOne, $ipAddresses[1]),
        'refuse un compte inexistant'
    );
    for ($attempt = 0; $attempt < 4; $attempt++) {
        $expectFailure(
            static fn () => $auth->authenticate($emailOne, 'incorrect-password', $ipAddresses[0]),
            'compte les tentatives de connexion échouées'
        );
    }
    $expectFailure(
        static fn () => $auth->authenticate($emailOne, $passwordOne, $ipAddresses[0]),
        'bloque une connexion après cinq échecs'
    );
    $assert(
        $auth->authenticate($emailOne, $passwordOne, $ipAddresses[2]) === $userIds[0],
        'accepte des identifiants valides hors du seau limité'
    );

    $sessionBeforeLogin = session_id();
    AuthSession::login($userIds[0]);
    $assert(session_id() !== $sessionBeforeLogin, 'renouvelle l’identifiant de session à la connexion');
    $csrfToken = AuthSession::csrfToken();
    $assert(AuthSession::verifyCsrf($csrfToken), 'valide le jeton CSRF de session');
    $assert(!AuthSession::verifyCsrf('jeton-invalide'), 'rejette un jeton CSRF invalide');
    $assert(AuthSession::userId($pdo) === $userIds[0], 'reconnaît la session du compte actif');
    $assert(count(FarmAccess::memberships($pdo, $userIds[0])) === 1, 'ne liste que les adhésions du compte courant');

    $expectFailure(
        static fn () => $auth->changePassword($userIds[0], 'mauvais-mot-de-passe', 'New-Password-2026!'),
        'exige le mot de passe actuel pour le changer'
    );

    $auth->requestPasswordReset($emailOne, $ipAddresses[2], 'auth.php');
    $assert(count($mailer->messages) === 7, 'envoie le lien par le transport configuré');
    $assert($mailer->messages[6]['recipient'] === $emailOne, 'adresse le lien au bon compte');
    $assert(str_contains($mailer->messages[6]['body'], '30 minutes'), 'indique l’expiration du lien');
    preg_match('/token=([a-f0-9]{64})/', $mailer->messages[6]['body'], $matches);
    $resetToken = $matches[1] ?? '';
    $assert(strlen($resetToken) === 64, 'crée un jeton aléatoire de réinitialisation');
    $assert($auth->isPasswordResetTokenValid($resetToken), 'reconnaît le jeton de réinitialisation actif');
    $storedToken = $pdo->prepare(
        "SELECT token_hash FROM auth_tokens
         WHERE purpose = 'password_reset' AND token_hash = :token_hash"
    );
    $storedToken->execute(['token_hash' => hash('sha256', $resetToken)]);
    $assert($storedToken->fetchColumn() !== false, 'ne stocke que l’empreinte du jeton');

    $auth->requestPasswordReset($unknownEmail, $ipAddresses[2], 'auth.php');
    $assert(count($mailer->messages) === 7, 'ne révèle pas et ne contacte pas un compte inexistant');
    $expectFailure(
        static fn () => $auth->resetPassword($resetToken, 'short'),
        'refuse un nouveau mot de passe trop court'
    );
    $auth->resetPassword($resetToken, 'Reset-Password-2026!');
    $assert(!$auth->isPasswordResetTokenValid($resetToken), 'consomme le jeton après usage');
    $expectFailure(
        static fn () => $auth->resetPassword($resetToken, 'Reused-Password-2026!'),
        'refuse la réutilisation du jeton'
    );
    $expectFailure(
        static fn () => $auth->authenticate($emailOne, $passwordOne, $ipAddresses[2]),
        'refuse l’ancien mot de passe après réinitialisation'
    );
    $assert(
        $auth->authenticate($emailOne, 'Reset-Password-2026!', $ipAddresses[2]) === $userIds[0],
        'accepte le nouveau mot de passe après réinitialisation'
    );
    $auth->requestPasswordReset($emailOne, $ipAddresses[2], 'auth.php');
    preg_match('/token=([a-f0-9]{64})/', $mailer->messages[7]['body'], $expiredTokenMatch);
    $expiredToken = $expiredTokenMatch[1] ?? '';
    $pdo->prepare(
        "UPDATE auth_tokens
         SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)
         WHERE token_hash = :token_hash"
    )->execute(['token_hash' => hash('sha256', $expiredToken)]);
    $assert(!$auth->isPasswordResetTokenValid($expiredToken), 'refuse un lien de réinitialisation expiré');
    $expectFailure(
        static fn () => $auth->resetPassword($expiredToken, 'Expired-Password-2026!'),
        'empêche l’utilisation d’un lien expiré'
    );

    $auth->changePassword($userIds[0], 'Reset-Password-2026!', 'Changed-Password-2026!');
    AuthSession::rotate();
    $assert(AuthSession::userId($pdo) === $userIds[0], 'conserve la session après rotation');
    $auth->updateProfile($userIds[0], 'Nom modifié');
    $profileUser = $auth->user($userIds[0]);
    $assert(
        $profileUser['full_name'] === 'Nom modifié'
            && (int) $profileUser['is_admin'] === 0,
        'retourne le profil et le rôle utilisateur du compte courant'
    );

    $pdo->prepare("UPDATE users SET status = 'disabled' WHERE id = :id")
        ->execute(['id' => $userIds[1]]);
    $assert(AuthSession::userId($pdo) === $userIds[0], 'une session reste limitée à son propre compte');
    $pdo->prepare("UPDATE users SET status = 'disabled' WHERE id = :id")
        ->execute(['id' => $userIds[0]]);
    $assert(AuthSession::userId($pdo) === null, 'termine la session d’un compte désactivé');

    AuthSession::logout();
    $assert(AuthSession::userId($pdo) === null, 'invalide la session après déconnexion');
} finally {
    AuthSession::logout();
    foreach ($userIds as $userId) {
        $pdo->prepare('DELETE FROM farm_members WHERE user_id = :user_id')
            ->execute(['user_id' => $userId]);
        $pdo->prepare('DELETE FROM farms WHERE owner_user_id = :user_id')
            ->execute(['user_id' => $userId]);
        $pdo->prepare('DELETE FROM users WHERE id = :user_id')
            ->execute(['user_id' => $userId]);
    }

    foreach ([$emailOne, $emailTwo, $emailThree, $emailFour, $unknownEmail] as $email) {
        foreach (['login', 'reset', 'verification', 'register', 'register-resend'] as $scope) {
            foreach ($ipAddresses as $ipAddress) {
                $key = $limiter->key(
                    $scope,
                    AuthService::normalizeEmail($email),
                    $ipAddress,
                    hash('sha256', 'mon-elevage-auth-v1:' . (string) $config['db']['password'])
                );
                $pdo->prepare('DELETE FROM login_attempts WHERE identifier_hash = :key')
                    ->execute(['key' => $key]);
            }
        }
        foreach (['register-ip', 'register-resend-ip'] as $scope) {
            foreach ($ipAddresses as $ipAddress) {
                $key = $limiter->key(
                    $scope,
                    'all',
                    $ipAddress,
                    hash('sha256', 'mon-elevage-auth-v1:' . (string) $config['db']['password'])
                );
                $pdo->prepare('DELETE FROM login_attempts WHERE identifier_hash = :key')
                    ->execute(['key' => $key]);
            }
        }
        $pdo->prepare('DELETE FROM pending_registrations WHERE email = :email')
            ->execute(['email' => $email]);
    }
}

printf("OK — %d assertions d’authentification et de permissions.\n", $passed);
