<?php
declare(strict_types=1);

use MonElevage\Admin\AdminService;

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$permissionLabels = AdminService::permissions();
$referenceLabels = [
    'flock_type' => 'Types d’élevage',
    'poultry_type' => 'Espèces',
    'production_type' => 'Productions',
    'product_category' => 'Catégories de produits',
    'expense_category' => 'Catégories de dépenses',
];
$userStatusLabels = ['active' => 'Actif', 'pending' => 'En attente', 'disabled' => 'Désactivé'];
$memberStatusLabels = ['active' => 'Actif', 'invited' => 'Invité', 'suspended' => 'Suspendu'];
$roleLabels = [
    'owner' => 'Propriétaire',
    'manager' => 'Responsable',
    'operator' => 'Opérateur',
    'accountant' => 'Comptable',
];
$settingsByKey = [];
foreach ($data['settings'] as $setting) {
    $settingsByKey[$setting['setting_key']] = $setting;
}
$thresholdsByKey = [];
foreach ($data['thresholds'] as $threshold) {
    $thresholdsByKey[$threshold['threshold_key']] = $threshold;
}
$weatherService = $data['services'][0] ?? [
    'service_key' => 'weather',
    'is_enabled' => 1,
    'endpoint_url' => 'https://api.open-meteo.com/v1/forecast',
];
?>
<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $error): ?><p><?= $escape($error) ?></p><?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if (is_array($flash) && isset($flash['message'])): ?>
    <div class="alert alert-success" role="status"><?= $escape((string) $flash['message']) ?></div>
<?php endif; ?>

<div class="admin-safety-note" role="note">
    Les opérations sont autorisées et journalisées côté serveur. Les comptes administrateurs
    sont provisionnés hors de cette interface : aucun formulaire ne peut attribuer ce statut.
    Avant une suppression définitive, téléchargez l’archive PDF si vous souhaitez conserver les données.
</div>
<nav class="admin-section-nav" aria-label="Sections d’administration">
    <a href="#users">Utilisateurs</a><a href="#memberships">Rôles</a>
    <a href="#training">Formation</a><a href="#references">Référentiels</a>
    <a href="#calendar">Calendriers</a><a href="#settings">Paramètres</a>
    <a href="#thresholds">Alertes</a><a href="#services">Services externes</a>
    <a href="#audit">Journaux d’audit</a>
</nav>

<section class="card admin-card" id="users">
    <div class="card-heading">
        <div><h2>Utilisateurs</h2><p><?= count($data['users']) ?> comptes affichés · gestion du nom, du statut et suppression définitive.</p></div>
    </div>
    <div class="admin-table-scroll">
        <table class="finance-table admin-table">
            <thead><tr><th>Compte</th><th>Créé le</th><th>Statut</th><th>Accès administrateur</th><th>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($data['users'] as $user): ?>
                <tr>
                    <td>
                        <a class="admin-user-link"
                           href="admin.php?view=user-permissions&amp;user_id=<?= (int) $user['id'] ?>">
                            <?= $escape((string) $user['full_name']) ?>
                        </a>
                        <small><?= $escape((string) $user['email']) ?></small>
                        <form id="admin-user-<?= (int) $user['id'] ?>" class="admin-inline-form" method="post" action="admin.php#users">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="action" value="user_save">
                            <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                            <label class="admin-user-rename"><span>Modifier le nom</span>
                                <input class="admin-text-input" name="full_name" maxlength="120" required
                                   aria-label="Nom de <?= $escape((string) $user['email']) ?>"
                                   value="<?= $escape((string) $user['full_name']) ?>">
                            </label>
                        </form>
                    </td>
                    <td><?= $escape((string) $user['created_at']) ?></td>
                    <td>
                        <?php if ((int) $user['is_admin'] === 1): ?>
                            <input type="hidden" form="admin-user-<?= (int) $user['id'] ?>" name="status" value="active">
                            <span class="badge badge-success">Actif</span>
                        <?php else: ?>
                            <select class="admin-select" form="admin-user-<?= (int) $user['id'] ?>" name="status" aria-label="Statut du compte">
                                <?php foreach ($userStatusLabels as $status => $label): ?>
                                    <option value="<?= $escape($status) ?>" <?= $user['status'] === $status ? 'selected' : '' ?>><?= $escape($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) $user['is_admin'] === 1
                        ? '<span class="badge badge-warning">Administrateur · géré hors interface</span>'
                        : '<span class="badge badge-neutral">Compte standard</span>' ?></td>
                    <td>
                        <div class="admin-user-actions">
                            <button class="button button-secondary" type="submit" form="admin-user-<?= (int) $user['id'] ?>">Enregistrer</button>
                            <?php if ((int) $user['is_admin'] !== 1): ?>
                                <a class="button button-secondary"
                                   href="admin.php?download_user_archive=<?= (int) $user['id'] ?>">
                                    Télécharger l’archive PDF
                                </a>
                                <form method="post" action="admin.php#users"
                                      data-confirm="Ce compte, ses exploitations et toutes leurs données seront supprimés définitivement. Téléchargez l’archive PDF auparavant si vous souhaitez en conserver une copie. Confirmer la suppression ?">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="action" value="user_delete">
                                    <input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>">
                                    <button class="button button-danger" type="submit">Supprimer le compte</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="card admin-card" id="memberships">
    <div class="card-heading">
        <div><h2>Adhésions, rôles et permissions</h2><p>Le rôle « administrateur du site » est distinct des rôles d’exploitation et ne peut pas être attribué ici.</p></div>
    </div>
    <div class="admin-table-scroll">
        <table class="finance-table admin-table">
            <thead><tr><th>Utilisateur</th><th>Exploitation</th><th>Rôle</th><th>Accès</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($data['memberships'] as $membership): ?>
                <tr>
                    <td><strong><?= $escape((string) $membership['full_name']) ?></strong><small><?= $escape((string) $membership['email']) ?></small></td>
                    <td><?= $escape((string) $membership['farm_name']) ?></td>
                    <td>
                        <form id="admin-membership-<?= (int) $membership['farm_id'] ?>-<?= (int) $membership['user_id'] ?>" class="admin-inline-form" method="post" action="admin.php#memberships">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="action" value="membership_save">
                            <input type="hidden" name="farm_id" value="<?= (int) $membership['farm_id'] ?>">
                            <input type="hidden" name="user_id" value="<?= (int) $membership['user_id'] ?>">
                        <?php if ((int) $membership['owner_user_id'] === (int) $membership['user_id']): ?>
                            <input type="hidden" name="role" value="owner">
                            <span class="badge badge-success">Propriétaire protégé</span>
                        <?php else: ?>
                            <select class="admin-select" name="role" aria-label="Rôle d’exploitation">
                                <?php foreach ($roleLabels as $role => $label): if ($role === 'owner') { continue; } ?>
                                    <option value="<?= $escape($role) ?>" <?= $membership['role'] === $role ? 'selected' : '' ?>><?= $escape($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                        </form>
                    </td>
                    <td>
                        <?php if ((int) $membership['owner_user_id'] === (int) $membership['user_id']): ?>
                            <input type="hidden" form="admin-membership-<?= (int) $membership['farm_id'] ?>-<?= (int) $membership['user_id'] ?>" name="status" value="active">
                            <span class="badge badge-success">Actif</span>
                        <?php else: ?>
                            <select class="admin-select" form="admin-membership-<?= (int) $membership['farm_id'] ?>-<?= (int) $membership['user_id'] ?>" name="status" aria-label="Statut de l’adhésion">
                                <?php foreach ($memberStatusLabels as $status => $label): ?>
                                    <option value="<?= $escape($status) ?>" <?= $membership['status'] === $status ? 'selected' : '' ?>><?= $escape($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </td>
                    <td><button class="button button-secondary" type="submit" form="admin-membership-<?= (int) $membership['farm_id'] ?>-<?= (int) $membership['user_id'] ?>">Enregistrer</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <h3 class="admin-subheading">Permissions par rôle d’exploitation</h3>
    <div class="admin-role-grid">
        <?php foreach ($roleLabels as $role => $roleLabel): ?>
            <form class="admin-role-form" method="post" action="admin.php#memberships">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="permissions_save">
                <input type="hidden" name="role" value="<?= $escape($role) ?>">
                <h4><?= $escape($roleLabel) ?></h4>
                <?php foreach ($permissionLabels as $permission => $permissionLabel): ?>
                    <label class="admin-checkbox">
                        <input type="checkbox" name="permissions[]" value="<?= $escape($permission) ?>"
                               <?= !empty($data['role_permissions'][$role][$permission]) ? 'checked' : '' ?>>
                        <span><?= $escape($permissionLabel) ?></span>
                    </label>
                <?php endforeach; ?>
                <button class="button button-secondary" type="submit">Enregistrer les permissions</button>
            </form>
        <?php endforeach; ?>
    </div>
</section>

<section class="card admin-card" id="training">
    <div class="card-heading">
        <div><h2>Modules de formation</h2><p>La rédaction et la publication des modules et leçons disposent de leur espace dédié.</p></div>
        <a class="button button-primary" href="admin-training.php">Gérer les modules</a>
    </div>
</section>

<section class="card admin-card" id="references">
    <div class="card-heading"><div><h2>Types d’élevage et catalogue de référence</h2><p>Gérez les libellés, descriptions, ordre et disponibilité des références.</p></div></div>
    <form class="admin-form-grid" method="post" action="admin.php#references">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="reference_save">
        <label class="field"><span>Type de référence</span><select name="reference_type" required>
            <?php foreach ($referenceLabels as $type => $label): ?><option value="<?= $escape($type) ?>"><?= $escape($label) ?></option><?php endforeach; ?>
        </select></label>
        <label class="field"><span>Code stable</span><input name="code" maxlength="64" pattern="[a-z0-9][a-z0-9_-]*" required></label>
        <label class="field"><span>Libellé</span><input name="label" maxlength="120" required></label>
        <label class="field"><span>Ordre</span><input type="number" name="position" min="0" max="65535" value="10" required></label>
        <label class="field"><span>État</span><select name="status"><option value="active">Actif</option><option value="archived">Archivé</option></select></label>
        <label class="field field-wide"><span>Description</span><input name="description" maxlength="500"></label>
        <button class="button button-primary" type="submit">Ajouter au catalogue</button>
    </form>
    <div class="admin-reference-list">
        <?php foreach ($data['references'] as $item): ?>
            <form class="admin-reference-row" method="post" action="admin.php#references">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="reference_save">
                <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                <label class="field"><span>Type</span><select name="reference_type">
                    <?php foreach ($referenceLabels as $type => $label): ?><option value="<?= $escape($type) ?>" <?= $item['reference_type'] === $type ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?>
                </select></label>
                <label class="field"><span>Code</span><input name="code" maxlength="64" required value="<?= $escape((string) $item['code']) ?>"></label>
                <label class="field"><span>Libellé</span><input name="label" maxlength="120" required value="<?= $escape((string) $item['label']) ?>"></label>
                <label class="field"><span>Ordre</span><input type="number" name="position" min="0" max="65535" required value="<?= (int) $item['position'] ?>"></label>
                <label class="field"><span>État</span><select name="status">
                    <option value="active" <?= $item['status'] === 'active' ? 'selected' : '' ?>>Actif</option>
                    <option value="archived" <?= $item['status'] === 'archived' ? 'selected' : '' ?>>Archivé</option>
                </select></label>
                <label class="field field-wide"><span>Description</span><input name="description" maxlength="500" value="<?= $escape((string) $item['description']) ?>"></label>
                <button class="button button-secondary" type="submit">Enregistrer</button>
            </form>
        <?php endforeach; ?>
    </div>
</section>

<section class="card admin-card" id="calendar">
    <div class="card-heading"><div><h2>Calendriers de référence</h2><p>Durées estimatives par espèce et type de production; un calendrier propre à l’exploitation reste prioritaire.</p></div></div>
    <form class="admin-form-grid" method="post" action="admin.php#calendar">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="calendar_save">
        <label class="field"><span>Espèce</span><select name="poultry_type">
            <option value="chicken">Poulet</option><option value="turkey">Dinde</option><option value="duck">Canard</option><option value="other">Autre volaille</option>
        </select></label>
        <label class="field"><span>Production</span><select name="production_type">
            <option value="meat">Viande</option><option value="eggs">Œufs</option><option value="breeding">Reproduction</option><option value="other">Autre production</option>
        </select></label>
        <label class="field"><span>Durée cible (jours)</span><input type="number" name="target_cycle_days" min="1" max="2000" required></label>
        <button class="button button-primary" type="submit">Ajouter / mettre à jour</button>
    </form>
    <div class="admin-table-scroll"><table class="finance-table admin-table">
        <thead><tr><th>Espèce</th><th>Production</th><th>Durée cible</th><th>Action</th></tr></thead><tbody>
        <?php foreach ($data['calendar'] as $calendar): ?>
            <tr><td><?= $escape((string) $calendar['poultry_type']) ?></td><td><?= $escape((string) $calendar['production_type']) ?></td><td>
                <form id="admin-calendar-<?= $escape((string) $calendar['poultry_type']) ?>-<?= $escape((string) $calendar['production_type']) ?>" class="admin-inline-form" method="post" action="admin.php#calendar">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>"><input type="hidden" name="action" value="calendar_save">
                    <input type="hidden" name="poultry_type" value="<?= $escape((string) $calendar['poultry_type']) ?>">
                    <input type="hidden" name="production_type" value="<?= $escape((string) $calendar['production_type']) ?>">
                    <input class="admin-number-input" type="number" name="target_cycle_days" min="1" max="2000" required value="<?= (int) $calendar['target_cycle_days'] ?>">
                </form>
            </td><td><button class="button button-secondary" type="submit" form="admin-calendar-<?= $escape((string) $calendar['poultry_type']) ?>-<?= $escape((string) $calendar['production_type']) ?>">Enregistrer</button></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>

<section class="card admin-card" id="settings">
    <div class="card-heading"><div><h2>Paramètres globaux</h2><p>Les nouvelles exploitations utilisent la devise par défaut configurée ici.</p></div></div>
    <div class="admin-setting-grid">
        <?php foreach (AdminService::settingsCatalog() as $key => $definition):
            $default = match ($key) {
                'application_name' => 'Mon Élevage',
                'default_currency' => 'XOF',
                default => '',
            };
            $value = (string) ($settingsByKey[$key]['setting_value'] ?? $default);
        ?>
            <form class="admin-setting-form" method="post" action="admin.php#settings">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="setting_save">
                <input type="hidden" name="setting_key" value="<?= $escape($key) ?>">
                <label class="field"><span><?= $escape($definition['label']) ?></span>
                    <input name="setting_value" maxlength="<?= (int) $definition['max'] ?>"
                           value="<?= $escape($value) ?>" <?= $key === 'support_email' ? 'type="email"' : 'type="text"' ?>>
                </label>
                <button class="button button-secondary" type="submit">Enregistrer</button>
            </form>
        <?php endforeach; ?>
    </div>
</section>

<section class="card admin-card" id="thresholds">
    <div class="card-heading"><div><h2>Seuils d’alerte</h2><p>Repères de vigilance modifiables; ils ne constituent ni un diagnostic ni une prescription.</p></div></div>
    <div class="admin-setting-grid">
        <?php foreach (AdminService::thresholdCatalog() as $key => $definition):
            $fallback = [
                'indoor_temperature_high' => 35, 'indoor_temperature_low' => 10,
                'indoor_humidity_high' => 80, 'indoor_humidity_low' => 30,
                'outdoor_temperature_high' => 35, 'outdoor_temperature_low' => 5,
            ][$key];
            $value = $thresholdsByKey[$key]['threshold_value'] ?? $fallback;
        ?>
            <form class="admin-setting-form" method="post" action="admin.php#thresholds">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="threshold_save">
                <input type="hidden" name="threshold_key" value="<?= $escape($key) ?>">
                <label class="field"><span><?= $escape($definition['label']) ?> (<?= $escape($definition['unit']) ?>)</span>
                    <input type="number" name="threshold_value" step="0.1"
                           min="<?= $definition['min'] ?>" max="<?= $definition['max'] ?>"
                           required value="<?= $escape((string) $value) ?>">
                </label>
                <button class="button button-secondary" type="submit">Enregistrer</button>
            </form>
        <?php endforeach; ?>
    </div>
</section>

<section class="card admin-card" id="services">
    <div class="card-heading"><div><h2>Services externes</h2><p>Open-Meteo fournit des prévisions extérieures; aucune clé secrète n’est requise.</p></div></div>
    <form class="admin-service-form" method="post" action="admin.php#services">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="service_save">
        <label class="field"><span>Service</span><input value="Prévisions météo — Open-Meteo" disabled></label>
        <label class="field"><span>Endpoint documenté</span>
            <input name="endpoint_url" type="url" maxlength="255" required
                   value="<?= $escape((string) $weatherService['endpoint_url']) ?>">
        </label>
        <label class="admin-checkbox admin-service-toggle">
            <input type="checkbox" name="is_enabled" value="1" <?= (int) $weatherService['is_enabled'] === 1 ? 'checked' : '' ?>>
            <span>Service actif</span>
        </label>
        <button class="button button-primary" type="submit">Enregistrer le service</button>
    </form>
</section>

<section class="card admin-card" id="audit">
    <div class="card-heading"><div><h2>Journaux d’audit</h2><p>Les 100 dernières actions globales; les changements et leurs journaux sont enregistrés dans la même transaction.</p></div></div>
    <div class="admin-table-scroll"><table class="finance-table admin-table">
        <thead><tr><th>Date UTC</th><th>Acteur</th><th>Action</th><th>Ressource</th><th>Exploitation</th><th>Détails</th></tr></thead>
        <tbody>
        <?php foreach ($data['audit_logs'] as $log): ?>
            <?php
            $details = json_decode((string) ($log['details'] ?? ''), true);
            $detailsText = is_array($details)
                ? (string) json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : 'Détails indisponibles';
            ?>
            <tr>
                <td><?= $escape((string) $log['created_at']) ?></td>
                <td><?= $escape((string) ($log['actor_name'] ?? 'Compte supprimé')) ?><small><?= $escape((string) ($log['actor_email'] ?? '')) ?></small></td>
                <td><strong><?= $escape((string) $log['action']) ?></strong></td>
                <td><?= $escape((string) $log['resource_type']) ?><?= $log['resource_id'] === null ? '' : ' · ' . (int) $log['resource_id'] ?></td>
                <td><?= $escape((string) ($log['farm_name'] ?? 'Globale')) ?></td>
                <td class="admin-audit-details"><?= $escape($detailsText) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</section>
