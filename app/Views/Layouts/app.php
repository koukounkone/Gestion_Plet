<?php
declare(strict_types=1);

$isLiveDashboard = $isLiveDashboard ?? false;
$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$hasCurrentProfilePhoto = false;
$profilePhotoVersion = '';
if ($isLiveDashboard && isset($pdo, $userId)) {
    $profilePhotoStatement = $pdo->prepare(
        "SELECT profile_photo_mime_type, updated_at
         FROM users
         WHERE id = :user_id AND status = 'active'
         LIMIT 1"
    );
    $profilePhotoStatement->execute(['user_id' => (int) $userId]);
    $currentProfilePhoto = $profilePhotoStatement->fetch(\PDO::FETCH_ASSOC);
    if (is_array($currentProfilePhoto)
        && is_string($currentProfilePhoto['profile_photo_mime_type'] ?? null)
        && $currentProfilePhoto['profile_photo_mime_type'] !== '') {
        $hasCurrentProfilePhoto = true;
        $profilePhotoVersion = (string) $currentProfilePhoto['updated_at'];
    }
}
if ($isLiveDashboard && isset($currentUserName)) {
    $userNameParts = preg_split('/\s+/u', trim((string) $currentUserName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $userInitials = mb_strtoupper(
        mb_substr($userNameParts[0] ?? '', 0, 1, 'UTF-8')
            . mb_substr($userNameParts[1] ?? '', 0, 1, 'UTF-8'),
        'UTF-8'
    );
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= $escape($isLiveDashboard ? 'Tableau de bord dynamique de Mon Élevage.' : 'Interface de démonstration responsive de Mon Élevage.') ?>">
    <meta name="theme-color" content="#153b30">
    <title><?= $escape($page['title']) ?> — Mon Élevage</title>
    <link rel="stylesheet" href="assets/css/app.css">
    <link rel="stylesheet" href="assets/css/site-refresh.css">
    <?php foreach (($extraScripts ?? []) as $extraScript): ?>
        <script src="<?= $escape((string) $extraScript) ?>" defer></script>
    <?php endforeach; ?>
    <script src="assets/js/app.js" defer></script>
</head>
<body>
<svg class="icon-library" aria-hidden="true" xmlns="http://www.w3.org/2000/svg">
    <symbol id="icon-dashboard" viewBox="0 0 24 24"><rect x="3.5" y="3.5" width="7" height="7" rx="1.5"/><rect x="13.5" y="3.5" width="7" height="5" rx="1.5"/><rect x="13.5" y="11.5" width="7" height="9" rx="1.5"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.5"/></symbol>
    <symbol id="icon-flock" viewBox="0 0 24 24"><path d="M4 19c1-5 4-8 8-8s7 3 8 8"/><circle cx="12" cy="7" r="3.5"/><path d="m15 6 3-1-1.5 2.5M8 19v2m8-2v2"/></symbol>
    <symbol id="icon-clipboard" viewBox="0 0 24 24"><rect x="5" y="4.5" width="14" height="17" rx="2"/><path d="M9 4.5v-1h6v1M8.5 10h7m-7 4h7m-7 4h4"/></symbol>
    <symbol id="icon-box" viewBox="0 0 24 24"><path d="m12 3 9 5v8l-9 5-9-5V8l9-5Z"/><path d="m3.5 8 8.5 5 8.5-5M12 13v8m-4-15 9 5"/></symbol>
    <symbol id="icon-cart" viewBox="0 0 24 24"><path d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h8.5a2 2 0 0 0 1.9-1.5L21 9H6"/><circle cx="10" cy="20" r="1"/><circle cx="18" cy="20" r="1"/></symbol>
    <symbol id="icon-chart" viewBox="0 0 24 24"><path d="M4 20V4m0 16h17"/><path d="m7 15 4-4 3 2 6-7"/><path d="M16 6h4v4"/></symbol>
    <symbol id="icon-document" viewBox="0 0 24 24"><path d="M6 3.5h8l4 4V20a1.5 1.5 0 0 1-1.5 1.5h-9A1.5 1.5 0 0 1 6 20V3.5Z"/><path d="M14 4v4h4m-9 5h6m-6 4h6"/></symbol>
    <symbol id="icon-settings" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.1.9-1.4 2.5-1.4-.5a8 8 0 0 1-1.7 1l-.3 1.5h-2.9l-.3-1.5a8 8 0 0 1-1.7-1l-1.4.5-1.4-2.5 1.1-.9a8 8 0 0 1 0-2L6.1 12l1.4-2.5 1.4.5a8 8 0 0 1 1.7-1l.3-1.5h2.9l.3 1.5a8 8 0 0 1 1.7 1l1.4-.5 1.4 2.5-1.1.9a8 8 0 0 1 0 2Z"/></symbol>
    <symbol id="icon-menu" viewBox="0 0 24 24"><path d="M4 6h16M4 12h16M4 18h16"/></symbol>
    <symbol id="icon-bell" viewBox="0 0 24 24"><path d="M18 9a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9m-8 12h4"/></symbol>
    <symbol id="icon-arrow" viewBox="0 0 24 24"><path d="M5 12h14m-6-6 6 6-6 6"/></symbol>
</svg>

<div class="app-shell">
    <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Components'
        . DIRECTORY_SEPARATOR . 'sidebar.php'; ?>

    <button class="sidebar-backdrop" type="button" tabindex="-1"
            aria-label="Fermer le menu" data-menu-close></button>

    <div class="workspace">
        <?php require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Components'
            . DIRECTORY_SEPARATOR . 'topbar.php'; ?>

        <main id="main-content" class="main-content" tabindex="-1">
            <div class="page-heading"<?= $isLiveDashboard && $pageKey === 'dashboard' ? ' id="overview"' : '' ?>>
                <div>
                    <p class="eyebrow"><?= $escape($page['section']) ?></p>
                    <h1><?= $escape($page['title']) ?></h1>
                    <p class="page-description"><?= $escape($page['description']) ?></p>
                </div>
                <?php if (!$isLiveDashboard): ?>
                    <div class="heading-actions">
                        <button class="button button-secondary button-loading-toggle"
                                type="button" aria-controls="demo-content"
                                aria-pressed="false" data-loading-toggle>
                            Voir l’état de chargement
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!$isLiveDashboard): ?>
                <div class="demo-notice" role="note">
                    <span class="demo-notice-icon" aria-hidden="true">i</span>
                    <p><strong>Démonstration visuelle.</strong> Toutes les données affichées sont fictives ; rien n’est enregistré.</p>
                </div>
            <?php endif; ?>

            <?php if (!$isLiveDashboard && $notFound): ?>
                <div class="alert alert-warning" role="status">
                    Cette page de démonstration n’existe pas. L’aperçu du tableau de bord est affiché.
                </div>
            <?php endif; ?>

            <div id="<?= $pageKey === 'dashboard'
                ? 'dashboard-content'
                : ($isLiveDashboard ? 'live-content' : 'demo-content') ?>">
                <?php require $isLiveDashboard
                    ? ($liveViewPath ?? $dashboardViewPath)
                    : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Pages'
                        . DIRECTORY_SEPARATOR . 'demo-content.php'; ?>
            </div>

            <?php if (!$isLiveDashboard): ?>
            <section class="loading-preview" id="loading-preview" hidden
                     aria-label="Aperçu de chargement" aria-live="polite">
                <div class="loading-grid" aria-hidden="true">
                    <?php for ($i = 0; $i < 4; $i++): ?>
                        <div class="card skeleton-card">
                            <span class="skeleton skeleton-line skeleton-short"></span>
                            <span class="skeleton skeleton-line skeleton-value"></span>
                            <span class="skeleton skeleton-line skeleton-medium"></span>
                        </div>
                    <?php endfor; ?>
                </div>
                <div class="card skeleton-panel" aria-hidden="true">
                    <span class="skeleton skeleton-line skeleton-medium"></span>
                    <span class="skeleton skeleton-block"></span>
                    <span class="skeleton skeleton-line"></span>
                    <span class="skeleton skeleton-line skeleton-short"></span>
                </div>
                <p class="loading-caption">Aperçu de l’état de chargement — aucune requête n’est effectuée.</p>
            </section>
            <?php endif; ?>
        </main>

        <footer class="app-footer">
            <span>© 2026 Mon Élevage</span>
            <span><?= $escape($pageKey === 'user-guide'
                ? 'Guide pratique · les actions dépendent de vos permissions'
                : (($isTraining ?? false)
                    ? 'Contenus publiés et progression de votre compte'
                    : ($isLiveDashboard
                        ? 'Données issues de votre exploitation'
                        : 'Squelette d’interface · données fictives'))) ?></span>
        </footer>
    </div>
</div>
</body>
</html>
