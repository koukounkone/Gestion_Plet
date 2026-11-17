<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\AuthService;
use MonElevage\Auth\FarmAccess;
use MonElevage\Auth\GoogleAccountService;
use MonElevage\Auth\NativeMailSender;
use MonElevage\Auth\ProfilePhotoService;
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
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        try {
            if ($action === 'profile') {
                $fullName = is_string($_POST['full_name'] ?? null)
                    ? $_POST['full_name']
                    : '';
                $auth->updateProfile($userId, $fullName);
                $success = 'Votre profil a été mis à jour.';
            } elseif ($action === 'profile_photo') {
                (new ProfilePhotoService($pdo))->storeUploadedFile(
                    $userId,
                    $_FILES['profile_photo'] ?? null
                );
                $success = 'Votre photo de profil a été mise à jour.';
            } else {
                http_response_code(400);
                $errors['form'] = 'Action inconnue.';
            }
        } catch (RuntimeException $exception) {
            $errors['form'] = $exception->getMessage();
        }
    }
}

$user = $auth->user($userId);
$accountRole = (int) ($user['is_admin'] ?? 0) === 1 ? 'Administrateur' : 'Utilisateur';
$memberships = FarmAccess::memberships($pdo, $userId);
$googleLinked = (new GoogleAccountService($pdo, $config))->hasGoogleIdentity($userId);
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
    <title>Mon profil — Mon Élevage</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/auth.css">
</head>
<body class="auth-body profile-body">
<header class="auth-topbar">
    <a class="brand auth-brand" href="profile.php">
        <span class="brand-mark" aria-hidden="true">M</span>
        <span class="brand-name">Mon Élevage<small>GESTION AVICOLE</small></span>
    </a>
    <a class="button button-primary" href="./">Tableau de bord</a>
    <form method="post" action="logout.php">
        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
        <button class="button button-secondary" type="submit">Se déconnecter</button>
    </form>
</header>
<main class="profile-main">
    <div class="profile-heading">
        <p class="auth-eyebrow">ESPACE PRIVÉ · COMPTE</p>
        <h1>Mon profil</h1>
        <p>Vos informations personnelles et vos adhésions actives.</p>
        <?php
        $nameParts = preg_split('/\s+/u', trim((string) $user['full_name']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $initials = mb_strtoupper(
            mb_substr($nameParts[0] ?? '', 0, 1, 'UTF-8')
            . mb_substr($nameParts[1] ?? '', 0, 1, 'UTF-8'),
            'UTF-8'
        );
        ?>
        <div class="profile-avatar-area">
            <form method="post" action="profile.php" enctype="multipart/form-data"
                  class="profile-photo-form" data-profile-photo-form>
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="action" value="profile_photo">
                <input id="profile-photo-input" class="profile-photo-input" type="file"
                       name="profile_photo" accept="image/jpeg,image/png,image/webp"
                       aria-label="Choisir une image de profil" required data-profile-photo-input>
                <label class="profile-avatar-picker" for="profile-photo-input"
                       aria-label="Ajouter ou modifier la photo de profil">
                    <span class="profile-avatar">
                        <?php if (($user['profile_photo_mime_type'] ?? null) === 'image/webp'): ?>
                            <img src="profile-photo.php?v=<?= rawurlencode((string) $user['updated_at']) ?>"
                                 alt="" aria-hidden="true">
                        <?php else: ?>
                            <span aria-hidden="true"><?= $escape($initials !== '' ? $initials : 'M') ?></span>
                        <?php endif; ?>
                    </span>
                    <span class="profile-avatar-add" aria-hidden="true">+</span>
                </label>
                <p class="profile-photo-help" data-profile-photo-status aria-live="polite">
                    Cliquez sur le + pour choisir une image (JPEG, PNG ou WEBP, 5 Mio maximum).
                </p>
                <button class="button button-secondary profile-photo-submit" type="submit">
                    Enregistrer la photo
                </button>
            </form>
        </div>
    </div>
    <?php if ($success !== null): ?>
        <div class="form-message form-message-success" role="status"><?= $escape($success) ?></div>
    <?php endif; ?>
    <?php if (isset($errors['form'])): ?>
        <div class="form-message form-message-error" role="alert"><?= $escape($errors['form']) ?></div>
    <?php endif; ?>

    <section class="profile-grid">
        <article class="auth-panel">
            <div class="panel-heading"><h2>Informations du compte</h2><p>Votre adresse e-mail ne peut pas encore être modifiée.</p></div>
            <form method="post" action="profile.php" class="auth-form-content">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <input type="hidden" name="action" value="profile">
                <label class="auth-field" for="full_name">Nom complet
                    <input id="full_name" name="full_name" type="text" maxlength="120"
                           autocomplete="name" required value="<?= $escape((string) $user['full_name']) ?>">
                </label>
                <label class="auth-field" for="email">Adresse e-mail
                    <input id="email" type="email" value="<?= $escape((string) $user['email']) ?>" disabled>
                </label>
                <button class="button button-primary" type="submit">Mettre à jour le profil</button>
            </form>
        </article>

        <article class="auth-panel">
            <div class="panel-heading">
                <h2>Rôle attribué</h2>
            </div>
            <?php if ($memberships === []): ?>
                <div class="profile-empty">
                    <?= $escape($accountRole) ?> · Aucune adhésion active n’est associée à ce compte.
                </div>
            <?php else: ?>
                <ul class="membership-list">
                    <?php foreach ($memberships as $membership): ?>
                        <li>
                            <span class="membership-mark" aria-hidden="true">♧</span>
                            <span class="membership-copy">
                                <strong><?= $escape((string) $membership['name']) ?></strong>
                                <small><?= $escape($accountRole) ?> · <?= $escape((string) $membership['currency_code']) ?></small>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>

        <article class="auth-panel">
            <div class="panel-heading">
                <h2>Connexion Google</h2>
                <p>L’association nécessite une session ouverte et la même adresse vérifiée.</p>
            </div>
            <div class="auth-form-content">
                <?php if ($googleLinked): ?>
                    <p class="profile-note">Un compte Google est déjà associé à ce profil.</p>
                <?php else: ?>
                    <a class="button button-secondary" href="google-auth.php?intent=link">Lier mon compte Google</a>
                <?php endif; ?>
            </div>
        </article>

        <article class="auth-panel password-panel">
            <div class="panel-heading">
                <h2>Mot de passe</h2>
                <p>Modifiez votre mot de passe depuis une page sécurisée dédiée.</p>
            </div>
            <div class="auth-form-content">
                <a class="button button-primary" href="password.php">Modifier Mdp</a>
            </div>
        </article>
    </section>
    <p class="auth-footnote">L’accès aux fonctions métier est limité aux exploitations et permissions associées à votre compte.</p>
</main>
<script src="assets/js/profile-photo.js" defer></script>
</body>
</html>
