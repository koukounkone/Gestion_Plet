<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$permissionLabels = \MonElevage\Auth\FarmAccess::permissionCatalog();
$targetUser = $memberPermissionDetail['user'];
$selectedMembership = $memberPermissionDetail['selected_membership'];
$isSiteAdministrator = (int) $targetUser['is_admin'] === 1;
?>
<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $error): ?><p><?= $escape($error) ?></p><?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if (is_array($flash) && isset($flash['message'])): ?>
    <div class="alert alert-success" role="status"><?= $escape((string) $flash['message']) ?></div>
<?php endif; ?>

<section class="card admin-card admin-member-permission-card" id="permissions">
    <div class="card-heading">
        <div>
            <h2>Permissions de <?= $escape((string) $targetUser['full_name']) ?></h2>
            <p><?= $escape((string) $targetUser['email']) ?></p>
        </div>
        <a class="button button-secondary" href="admin.php#users">Retour aux utilisateurs</a>
    </div>

    <?php if ($isSiteAdministrator): ?>
        <div class="admin-permission-note" role="status">
            Ce compte est administrateur du site. Il conserve tous les droits; ses permissions ne peuvent pas être restreintes ici.
        </div>
    <?php elseif ($memberPermissionDetail['memberships'] === []): ?>
        <div class="admin-permission-note" role="status">
            Cet utilisateur n’a encore aucune adhésion à une exploitation. Ses permissions seront configurables après son ajout à une exploitation.
        </div>
    <?php else: ?>
        <form class="admin-permission-farm-picker" method="get" action="admin.php">
            <input type="hidden" name="view" value="user-permissions">
            <input type="hidden" name="user_id" value="<?= (int) $targetUser['id'] ?>">
            <label class="field">
                <span>Exploitation concernée</span>
                <select name="farm_id" required>
                    <?php foreach ($memberPermissionDetail['memberships'] as $membership): ?>
                        <option value="<?= (int) $membership['farm_id'] ?>"
                            <?= $selectedMembership !== null
                                && (int) $selectedMembership['farm_id'] === (int) $membership['farm_id']
                                ? 'selected'
                                : '' ?>>
                            <?= $escape((string) $membership['farm_name']) ?>
                            — <?= $escape((string) $membership['role']) ?>
                            (<?= $escape((string) $membership['status']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <button class="button button-secondary" type="submit">Afficher</button>
        </form>

        <?php if ($selectedMembership !== null): ?>
            <div class="admin-permission-scope">
                <strong><?= $escape((string) $selectedMembership['farm_name']) ?></strong>
                <span>Rôle de base : <?= $escape((string) $selectedMembership['role']) ?></span>
                <span>État de l’adhésion : <?= $escape((string) $selectedMembership['status']) ?></span>
            </div>
            <p class="admin-permission-explanation">
                Les cases affichent les droits effectifs pour cette exploitation. Les choix enregistrés ici sont propres à cet utilisateur et remplacent ses valeurs par défaut de rôle.
            </p>
            <form class="admin-member-permissions-form" method="post"
                  action="admin.php?view=user-permissions&amp;user_id=<?= (int) $targetUser['id'] ?>&amp;farm_id=<?= (int) $selectedMembership['farm_id'] ?>#permissions">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="member_permissions_save">
                <input type="hidden" name="user_id" value="<?= (int) $targetUser['id'] ?>">
                <input type="hidden" name="farm_id" value="<?= (int) $selectedMembership['farm_id'] ?>">
                <div class="admin-member-permission-grid">
                    <?php foreach ($permissionLabels as $permission => $label): ?>
                        <label class="admin-checkbox">
                            <input type="checkbox" name="permissions[]"
                                   value="<?= $escape($permission) ?>"
                                <?= !empty($memberPermissionDetail['permissions'][$permission])
                                    ? 'checked'
                                    : '' ?>>
                            <span><?= $escape($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <button class="button button-primary" type="submit">Enregistrer les permissions</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</section>
