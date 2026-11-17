<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$reportFarmId = $currentFarmId ?? ($dashboard['farm']['id'] ?? null);
$canNavigateTo = static fn (string $permission): bool => $isLiveDashboard
    && isset($pdo, $userId)
    && $reportFarmId !== null
    && \MonElevage\Auth\FarmAccess::allows(
        $pdo,
        (int) $userId,
        (int) $reportFarmId,
        $permission
    );
$canViewDashboard = $canNavigateTo('farm.view');
$canViewFlocks = $canNavigateTo('flock.view');
$canViewStock = $canNavigateTo('stock.view');
$canViewSalesMenu = $canNavigateTo('sale.view');
$canViewHealthMenu = $canNavigateTo('health.view');
$canViewFinanceMenu = $canNavigateTo('expense.view');
$canViewReports = $canViewReports ?? (
    $canNavigateTo('report.view')
);
?>
<aside class="sidebar" id="sidebar" aria-label="Navigation principale">
    <a class="brand" href="./" aria-label="Mon Élevage — tableau de bord">
        <span class="brand-mark" aria-hidden="true">
            <svg viewBox="0 0 32 32" focusable="false">
                <path d="M8 22.5c1.2-5.7 4.3-9 8.2-9 3.3 0 5.3 2.5 5.8 6.5l-4.1 3.5H8Z"/>
                <path d="M14.5 13.7c-.8-2.4.3-4.3 2.4-4.8.4 1.8-.1 3.5-1.7 4.7m6.2 1.2 4 1.2-3.7 1.3"/>
                <circle cx="19.8" cy="15.7" r=".65"/>
                <path d="M11 23v2m7-2v2"/>
            </svg>
        </span>
        <span class="brand-name">Mon Élevage<small>GESTION AVICOLE</small></span>
    </a>

    <?php if ($isLiveDashboard && ($isTraining ?? false)): ?>
        <div class="workspace-label">
            <span class="avatar" aria-hidden="true">
                <?php if ($hasCurrentProfilePhoto): ?>
                    <img src="profile-photo.php?v=<?= rawurlencode($profilePhotoVersion) ?>" alt="">
                <?php else: ?>
                    <?= $escape($userInitials) ?>
                <?php endif; ?>
            </span>
            <span class="workspace-copy"><?= $isTrainingAdmin ? 'Administration' : 'Espace formation' ?><small><?= $escape($currentUserName) ?></small></span>
        </div>
        <nav class="nav-list" aria-label="Navigation de la formation">
            <div class="nav-group">
                <p class="nav-group-label">RESSOURCES</p>
                <a class="nav-link<?= $pageKey === 'training' ? ' is-active' : '' ?>"
                   href="training.php" <?= $pageKey === 'training' ? 'aria-current="page"' : '' ?>>
                    <svg class="nav-icon" aria-hidden="true"><use href="#icon-document"></use></svg>
                    <span>Guide de formation</span>
                </a>
                <a class="nav-link<?= $pageKey === 'user-guide' ? ' is-active' : '' ?>"
                   href="guide.php" <?= $pageKey === 'user-guide' ? 'aria-current="page"' : '' ?>>
                    <svg class="nav-icon" aria-hidden="true"><use href="#icon-clipboard"></use></svg>
                    <span>Guide d’utilisation</span>
                </a>
                <?php if ($isTrainingAdmin): ?>
                    <a class="nav-link<?= $pageKey === 'admin' ? ' is-active' : '' ?>"
                       href="admin.php" <?= $pageKey === 'admin' ? 'aria-current="page"' : '' ?>>
                        <svg class="nav-icon" aria-hidden="true"><use href="#icon-settings"></use></svg>
                        <span>Administration du site</span>
                    </a>
                    <a class="nav-link<?= $pageKey === 'training-admin' ? ' is-active' : '' ?>"
                       href="admin-training.php" <?= $pageKey === 'training-admin' ? 'aria-current="page"' : '' ?>>
                        <svg class="nav-icon" aria-hidden="true"><use href="#icon-settings"></use></svg>
                        <span>Gestion du guide</span>
                    </a>
                <?php endif; ?>
            </div>
        </nav>
        <div class="sidebar-footer">
            <span class="avatar avatar-profile" aria-hidden="true">
                <?php if ($hasCurrentProfilePhoto): ?>
                    <img src="profile-photo.php?v=<?= rawurlencode($profilePhotoVersion) ?>" alt="">
                <?php else: ?>
                    <?= $escape($userInitials) ?>
                <?php endif; ?>
            </span>
            <span class="profile-copy"><?= $escape($currentUserName) ?><small><?= $isTrainingAdmin ? 'Administrateur' : 'Éleveur' ?></small></span>
            <a class="profile-menu" href="profile.php" aria-label="Profil et paramètres">···</a>
        </div>
        <form class="sidebar-logout" method="post" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <button class="button button-secondary" type="submit">Se déconnecter</button>
        </form>
    <?php elseif ($isLiveDashboard): ?>
        <div class="workspace-label">
            <span class="avatar" aria-hidden="true">
                <?php if ($hasCurrentProfilePhoto): ?>
                    <img src="profile-photo.php?v=<?= rawurlencode($profilePhotoVersion) ?>" alt="">
                <?php else: ?>
                    <?= $escape($userInitials) ?>
                <?php endif; ?>
            </span>
            <span class="workspace-copy">Espace éleveur<small><?= $escape($currentFarmName ?? $dashboard['farm']['name'] ?? 'Aucune exploitation') ?></small></span>
        </div>
        <nav class="nav-list" aria-label="Navigation de l’espace éleveur">
            <div class="nav-group">
                <p class="nav-group-label">VOTRE ACTIVITÉ</p>
                <?php if ($canViewDashboard): ?>
                <a class="nav-link<?= $pageKey === 'dashboard' ? ' is-active' : '' ?>"
                   href="<?= isset($currentFarmId)
                       ? './?farm_id=' . (int) $currentFarmId
                       : (isset($dashboard['farm']['id']) ? './?farm_id=' . (int) $dashboard['farm']['id'] : './') ?>"
                   <?= $pageKey === 'dashboard' ? 'aria-current="page"' : '' ?>>
                    <svg class="nav-icon" aria-hidden="true"><use href="#icon-dashboard"></use></svg>
                    <span>Vue d’ensemble</span>
                </a>
                <?php endif; ?>
                <?php if ($canViewFlocks): ?>
                <a class="nav-link<?= $pageKey === 'flocks' ? ' is-active' : '' ?>"
                   href="flocks.php?farm_id=<?= (int) ($currentFarmId ?? $dashboard['farm']['id'] ?? 0) ?>"
                   <?= $pageKey === 'flocks' ? 'aria-current="page"' : '' ?>>
                    <svg class="nav-icon" aria-hidden="true"><use href="#icon-flock"></use></svg>
                    <span>Élevages</span>
                </a>
                <?php endif; ?>
                <?php if ($canViewSalesMenu): ?>
                    <a class="nav-link<?= $pageKey === 'sales' ? ' is-active' : '' ?>"
                       href="sales.php?farm_id=<?= (int) ($currentFarmId ?? $dashboard['farm']['id'] ?? 0) ?>"
                       <?= $pageKey === 'sales' ? 'aria-current="page"' : '' ?>>
                        <svg class="nav-icon" aria-hidden="true"><use href="#icon-cart"></use></svg>
                        <span>Commandes et ventes</span>
                    </a>
                <?php endif; ?>
                <?php if ($canViewFlocks): ?>
                <a class="nav-link<?= $pageKey === 'followups' ? ' is-active' : '' ?>"
                   href="followups.php?farm_id=<?= (int) ($currentFarmId ?? $dashboard['farm']['id'] ?? 0) ?>"
                   <?= $pageKey === 'followups' ? 'aria-current="page"' : '' ?>>
                    <svg class="nav-icon" aria-hidden="true"><use href="#icon-clipboard"></use></svg>
                    <span>Suivi quotidien</span>
                </a>
                <?php endif; ?>
                <?php if ($canViewHealthMenu): ?>
                    <a class="nav-link<?= $pageKey === 'health-monitoring' ? ' is-active' : '' ?>"
                       href="health-monitoring.php?farm_id=<?= (int) ($currentFarmId ?? $dashboard['farm']['id'] ?? 0) ?>"
                       <?= $pageKey === 'health-monitoring' ? 'aria-current="page"' : '' ?>>
                        <svg class="nav-icon" aria-hidden="true"><use href="#icon-bell"></use></svg>
                        <span>Santé et alertes</span>
                    </a>
                <?php endif; ?>
                <?php if ($canViewFlocks): ?>
                <a class="nav-link<?= $pageKey === 'performance' ? ' is-active' : '' ?>"
                   href="performance.php?farm_id=<?= (int) ($currentFarmId ?? $dashboard['farm']['id'] ?? 0) ?>"
                   <?= $pageKey === 'performance' ? 'aria-current="page"' : '' ?>>
                    <svg class="nav-icon" aria-hidden="true"><use href="#icon-chart"></use></svg>
                    <span>Croissance et ponte</span>
                </a>
                <?php endif; ?>
                <?php if ($canViewFinanceMenu): ?>
                    <a class="nav-link<?= $pageKey === 'finance' ? ' is-active' : '' ?>"
                       href="finance.php?farm_id=<?= (int) ($currentFarmId ?? $dashboard['farm']['id'] ?? 0) ?>"
                       <?= $pageKey === 'finance' ? 'aria-current="page"' : '' ?>>
                        <svg class="nav-icon" aria-hidden="true"><use href="#icon-cart"></use></svg>
                        <span>Finances et budgets</span>
                    </a>
                <?php endif; ?>
                <?php if ($canViewReports): ?>
                    <a class="nav-link<?= $pageKey === 'reports' ? ' is-active' : '' ?>"
                       href="reports.php?farm_id=<?= (int) ($currentFarmId ?? $dashboard['farm']['id'] ?? 0) ?>"
                       <?= $pageKey === 'reports' ? 'aria-current="page"' : '' ?>>
                        <svg class="nav-icon" aria-hidden="true"><use href="#icon-document"></use></svg>
                        <span>Rapports PDF</span>
                    </a>
                <?php endif; ?>
                <?php if ($canViewStock): ?>
                <a class="nav-link<?= $pageKey === 'stock' ? ' is-active' : '' ?>"
                   href="stock.php?farm_id=<?= (int) ($currentFarmId ?? $dashboard['farm']['id'] ?? 0) ?>"
                   <?= $pageKey === 'stock' ? 'aria-current="page"' : '' ?>>
                    <svg class="nav-icon" aria-hidden="true"><use href="#icon-box"></use></svg>
                    <span>Produits et stocks</span>
                </a>
                <?php endif; ?>
                <a class="nav-link" href="training.php">
                    <svg class="nav-icon" aria-hidden="true"><use href="#icon-document"></use></svg>
                    <span>Guide de formation</span>
                </a>
                <a class="nav-link<?= $pageKey === 'user-guide' ? ' is-active' : '' ?>"
                   href="guide.php" <?= $pageKey === 'user-guide' ? 'aria-current="page"' : '' ?>>
                    <svg class="nav-icon" aria-hidden="true"><use href="#icon-clipboard"></use></svg>
                    <span>Guide d’utilisation</span>
                </a>
                <?php if (isset($pdo, $userId)
                    && \MonElevage\Training\TrainingService::isAdministrator($pdo, (int) $userId)): ?>
                    <a class="nav-link<?= $pageKey === 'admin' ? ' is-active' : '' ?>"
                       href="admin.php" <?= $pageKey === 'admin' ? 'aria-current="page"' : '' ?>>
                        <svg class="nav-icon" aria-hidden="true"><use href="#icon-settings"></use></svg>
                        <span>Administration</span>
                    </a>
                <?php endif; ?>
                <?php if ($pageKey === 'dashboard'): ?>
                <a class="nav-link" href="#next-steps">
                    <svg class="nav-icon" aria-hidden="true"><use href="#icon-clipboard"></use></svg>
                    <span>Prochaines étapes</span>
                </a>
                <a class="nav-link" href="#alerts">
                    <svg class="nav-icon" aria-hidden="true"><use href="#icon-bell"></use></svg>
                    <span>Alertes</span>
                </a>
                <a class="nav-link" href="#operations">
                    <svg class="nav-icon" aria-hidden="true"><use href="#icon-document"></use></svg>
                    <span>Dernières opérations</span>
                </a>
                <?php endif; ?>
            </div>
        </nav>
        <div class="sidebar-footer">
            <span class="avatar avatar-profile" aria-hidden="true">
                <?php if ($hasCurrentProfilePhoto): ?>
                    <img src="profile-photo.php?v=<?= rawurlencode($profilePhotoVersion) ?>" alt="">
                <?php else: ?>
                    <?= $escape($userInitials) ?>
                <?php endif; ?>
            </span>
            <span class="profile-copy"><?= $escape($currentUserName) ?><small><?= $escape($currentFarmRole ?? $dashboard['role'] ?? 'Éleveur') ?></small></span>
            <a class="profile-menu" href="profile.php" aria-label="Profil et paramètres">···</a>
        </div>
        <form class="sidebar-logout" method="post" action="logout.php">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <button class="button button-secondary" type="submit">Se déconnecter</button>
        </form>
    <?php else: ?>
        <div class="workspace-label">
            <span class="avatar" aria-hidden="true">AK</span>
            <span class="workspace-copy">Espace éleveur<small>Compte de démonstration</small></span>
            <span class="chevron" aria-hidden="true">⌄</span>
        </div>
        <nav class="nav-list" aria-label="Sections de l’espace éleveur">
            <?php foreach ($navigation as $group => $items): ?>
                <div class="nav-group">
                    <p class="nav-group-label"><?= $escape($group) ?></p>
                    <?php foreach ($items as $item): ?>
                        <?php $active = $item['key'] === $pageKey; ?>
                        <a class="nav-link<?= $active ? ' is-active' : '' ?>"
                           href="?page=<?= rawurlencode($item['key']) ?>"
                           <?= $active ? 'aria-current="page"' : '' ?>>
                            <svg class="nav-icon" aria-hidden="true" focusable="false">
                                <use href="#icon-<?= $escape($pages[$item['key']]['icon']) ?>"></use>
                            </svg>
                            <span><?= $escape($item['label']) ?></span>
                            <?php if ($item['key'] === 'followups'): ?>
                                <span class="nav-count" aria-label="Une observation à vérifier">1</span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>
        <div class="sidebar-footer">
            <span class="avatar avatar-profile" aria-hidden="true">AK</span>
            <span class="profile-copy">Aminata K.<small>Ferme du Soleil · exemple</small></span>
            <span class="profile-menu" aria-hidden="true">···</span>
        </div>
    <?php endif; ?>
</aside>
