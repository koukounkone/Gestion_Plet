<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\AuthService;
use MonElevage\Auth\NativeMailSender;
use MonElevage\Database\Connection;
use MonElevage\Support\AuthSession;

AuthSession::start();
$pdo = Connection::get($config);
$userId = AuthSession::userId($pdo);
if ($userId === null) {
    header('Location: auth.php?view=login', true, 303);
    exit;
}

$mailFrom = $config['mail']['from'] ?? getenv('MON_ELEVAGE_MAIL_FROM');
$auth = new AuthService(
    $pdo,
    $config,
    new NativeMailSender(is_string($mailFrom) && $mailFrom !== '' ? $mailFrom : null)
);
$errors = [];
$success = null;
$flash = AuthSession::takeFlash();
if (is_array($flash) && isset($flash['message'])) {
    $success = (string) $flash['message'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthSession::verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $errors['form'] = 'Le formulaire a expiré ou ne peut pas être vérifié. Rechargez la page.';
    } else {
        $currentPassword = is_string($_POST['current_password'] ?? null)
            ? $_POST['current_password']
            : '';
        $newPassword = is_string($_POST['new_password'] ?? null)
            ? $_POST['new_password']
            : '';
        $confirmation = is_string($_POST['password_confirmation'] ?? null)
            ? $_POST['password_confirmation']
            : '';
        try {
            if (!hash_equals($newPassword, $confirmation)) {
                throw new RuntimeException('Les deux nouveaux mots de passe ne correspondent pas.');
            }
            $auth->changePassword($userId, $currentPassword, $newPassword);
            AuthSession::rotate();
            AuthSession::flash('success', 'Votre mot de passe a été modifié.');
            header('Location: profile.php', true, 303);
            exit;
        } catch (RuntimeException $exception) {
            $errors['form'] = $exception->getMessage();
        }
    }
}

$csrfToken = AuthSession::csrfToken();
$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Content-Type-Options: nosniff');
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Modifier le mot de passe — Mon Élevage</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body class="auth-body profile-body">
<header class="auth-topbar">
    <a class="brand auth-brand" href="profile.php">
        <span class="brand-mark" aria-hidden="true">M</span>
        <span class="brand-name">Mon Élevage<small>GESTION AVICOLE</small></span>
    </a>
    <a class="button button-secondary" href="profile.php">Retour au profil</a>
    <a class="button button-primary" href="./">Tableau de bord</a>
    <form method="post" action="logout.php">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
        <button class="button button-secondary" type="submit">Se déconnecter</button>
    </form>
</header>
<main class="profile-main">
    <div class="profile-heading">
        <p class="auth-eyebrow">ESPACE PRIVÉ · SÉCURITÉ</p>
        <h1>Modifier le mot de passe</h1>
        <p>Confirmez votre mot de passe actuel avant d’en choisir un nouveau.</p>
    </div>
    <?php if ($success !== null): ?>
        <div class="form-message form-message-success" role="status"><?= $escape($success) ?></div>
    <?php endif; ?>
    <?php if (isset($errors['form'])): ?>
        <div class="form-message form-message-error" role="alert"><?= $escape($errors['form']) ?></div>
    <?php endif; ?>
    <section class="profile-grid">
        <article class="auth-panel password-panel">
            <div class="panel-heading">
                <h2>Nouveau mot de passe</h2>
                <p>12 à 72 octets ; le mot de passe actuel est requis.</p>
            </div>
            <form method="post" action="password.php" class="auth-form-content">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <label class="auth-field" for="current_password">Mot de passe actuel
                    <input id="current_password" name="current_password" type="password"
                           autocomplete="current-password" required maxlength="72">
                </label>
                <label class="auth-field" for="new_password">Nouveau mot de passe
                    <input id="new_password" name="new_password" type="password"
                           autocomplete="new-password" required minlength="12" maxlength="72">
                    <small>Utilisez une phrase de passe unique d’au moins 12 caractères.</small>
                </label>
                <label class="auth-field" for="password_confirmation">Confirmer le nouveau mot de passe
                    <input id="password_confirmation" name="password_confirmation" type="password"
                           autocomplete="new-password" required minlength="12" maxlength="72">
                </label>
                <button class="button button-primary" type="submit">Enregistrer le nouveau mot de passe</button>
            </form>
        </article>
    </section>
</main>
</body>
</html>
