<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\GoogleAccountService;
use MonElevage\Auth\GoogleOidcClient;
use MonElevage\Database\Connection;
use MonElevage\Support\AuthSession;

AuthSession::start();
$pdo = Connection::get($config);
$context = $_SESSION['google_oauth_context'] ?? null;
unset($_SESSION['google_oauth_context']);
$state = is_string($_GET['state'] ?? null) ? $_GET['state'] : '';
$intent = is_array($context) && in_array($context['intent'] ?? null, ['login', 'register', 'link'], true)
    ? (string) $context['intent']
    : 'login';
$redirect = $intent === 'link' ? 'profile.php' : 'auth.php?view=' . $intent;

if (!is_array($context)
    || !is_string($context['state'] ?? null)
    || $state === ''
    || !hash_equals($context['state'], $state)) {
    AuthSession::flash('error', 'La demande Google a expiré ou n’est pas valide. Recommencez.');
    header('Location: ' . $redirect, true, 303);
    exit;
}

if (is_string($_GET['error'] ?? null)) {
    AuthSession::flash(
        'error',
        $_GET['error'] === 'access_denied'
            ? 'La connexion Google a été annulée. Vous pouvez réessayer.'
            : 'Google n’a pas pu terminer la connexion. Vous pouvez réessayer.'
    );
    header('Location: ' . $redirect, true, 303);
    exit;
}

try {
    $code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
    $client = new GoogleOidcClient(
        is_array($config['google_oauth'] ?? null) ? $config['google_oauth'] : []
    );
    $profile = $client->exchangeCode(
        $code,
        is_string($context['code_verifier'] ?? null) ? $context['code_verifier'] : '',
        is_string($context['nonce'] ?? null) ? $context['nonce'] : ''
    );
    $accounts = new GoogleAccountService($pdo, $config);

    if ($intent === 'link') {
        $currentUserId = AuthSession::userId($pdo);
        $expectedUserId = filter_var($context['user_id'] ?? null, FILTER_VALIDATE_INT);
        if ($currentUserId === null || !is_int($expectedUserId) || $currentUserId !== $expectedUserId) {
            throw new RuntimeException('Votre session a changé. Reconnectez-vous puis recommencez la liaison.');
        }
        $accounts->linkIdentity($currentUserId, $profile);
        AuthSession::flash('success', 'Votre compte Google a été lié à votre profil.');
        header('Location: profile.php', true, 303);
        exit;
    }

    $linkedAccount = $accounts->findLinkedAccount($profile['subject']);
    if ($linkedAccount !== null) {
        if ($linkedAccount['status'] !== 'active') {
            throw new RuntimeException('Ce compte Mon Élevage est désactivé.');
        }
        AuthSession::login($linkedAccount['user_id']);
        header('Location: ./', true, 303);
        exit;
    }

    $emailAccount = $accounts->findAccountByEmail($profile['email']);
    if ($emailAccount !== null) {
        throw new RuntimeException(
            'Cette adresse e-mail appartient déjà à un compte. Connectez-vous avec ce compte, puis liez Google depuis votre profil.'
        );
    }

    $_SESSION['google_pending_profile'] = $profile;
    header('Location: auth.php?view=google-complete', true, 303);
    exit;
} catch (RuntimeException $exception) {
    AuthSession::flash('error', $exception->getMessage());
    header('Location: ' . $redirect, true, 303);
    exit;
}
