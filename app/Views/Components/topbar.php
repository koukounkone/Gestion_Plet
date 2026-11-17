<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
?>
<header class="topbar">
    <button class="icon-button menu-toggle" id="menu-toggle" type="button"
            aria-label="Ouvrir le menu" aria-controls="sidebar"
            aria-expanded="false" data-menu-toggle>
        <svg aria-hidden="true" focusable="false"><use href="#icon-menu"></use></svg>
    </button>
    <div class="breadcrumbs" aria-label="Fil d’Ariane">
        <a href="./">Mon Élevage</a>
        <span aria-hidden="true">/</span>
        <strong><?= $escape($page['title']) ?></strong>
    </div>
    <div class="topbar-actions">
        <?php if ($isLiveDashboard && count($memberships) > 1): ?>
            <form method="get" action="<?= $escape($farmSwitchAction ?? './') ?>" class="farm-switcher">
                <label class="visually-hidden" for="farm-switcher">Exploitation affichée</label>
                <select id="farm-switcher" name="farm_id">
                    <?php foreach ($memberships as $membership): ?>
                        <option value="<?= (int) $membership['id'] ?>"
                            <?= (int) $membership['id'] === (int) ($currentFarmId ?? $dashboard['farm']['id'] ?? 0) ? 'selected' : '' ?>>
                            <?= $escape((string) $membership['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="button button-secondary" type="submit">Afficher</button>
            </form>
        <?php endif; ?>
        <span class="topbar-date"><?= $escape($topbarDate) ?></span>
        <span class="topbar-divider" aria-hidden="true"></span>
        <?php if ($isLiveDashboard): ?>
            <a class="avatar avatar-small" href="profile.php"
               aria-label="Profil de <?= $escape($currentUserName) ?>">
                <?php if ($hasCurrentProfilePhoto): ?>
                    <img src="profile-photo.php?v=<?= rawurlencode($profilePhotoVersion) ?>" alt="">
                <?php else: ?>
                    <?= $escape($userInitials) ?>
                <?php endif; ?>
            </a>
        <?php else: ?>
            <button class="icon-button notification-button" type="button"
                    aria-label="Notifications (aperçu uniquement)" disabled>
                <svg aria-hidden="true" focusable="false"><use href="#icon-bell"></use></svg>
                <span class="notification-dot" aria-hidden="true"></span>
            </button>
            <span class="avatar avatar-small" aria-label="Profil fictif Aminata K.">AK</span>
        <?php endif; ?>
    </div>
</header>
