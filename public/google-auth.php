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
$intent = $_GET['intent'] ?? null;
if (!is_string($intent) || !in_array($intent, ['login', 'register', 'link'], true)) {
    http_response_code(400);
    exit('Demande Google invalide.');
}

$client = new GoogleOidcClient(
    is_array($config['google_oauth'] ?? null) ? $config['google_oauth'] : []
);
$userId = AuthSession::userId($pdo);
if ($intent === 'link') {
    if ($userId === null) {
        header('Location: auth.php?view=login', true, 303);
        exit;
    }
    if ((new GoogleAccountService($pdo, $config))->hasGoogleIdentity($userId)) {
        AuthSession::flash('error', 'Un compte Google est déjà associé à votre profil.');
        header('Location: profile.php', true, 303);
        exit;
    }
}
if (!$client->isConfigured()) {
    AuthSession::flash(
        'error',
        'La connexion Google n’est pas encore configurée pour ce site.'
    );
    header(
        $intent === 'link' ? 'Location: profile.php' : 'Location: auth.php?view=' . $intent,
        true,
        303
    );
    exit;
}

$state = bin2hex(random_bytes(32));
$nonce = bin2hex(random_bytes(32));
$codeVerifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
$codeChallenge = rtrim(
    strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'),
    '='
);
$_SESSION['google_oauth_context'] = [
    'state' => $state,
    'nonce' => $nonce,
    'code_verifier' => $codeVerifier,
    'intent' => $intent,
    'user_id' => $intent === 'link' ? $userId : null,
];

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('Location: ' . $client->authorizationUrl($state, $nonce, $codeChallenge), true, 303);
exit;
