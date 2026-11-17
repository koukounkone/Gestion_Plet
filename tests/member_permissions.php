<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Admin\AdminService;
use MonElevage\Auth\FarmAccess;
use MonElevage\Dashboard\DashboardService;
use MonElevage\Database\Connection;
use MonElevage\Sales\SalesService;
use MonElevage\Stock\StockService;

$pdo = Connection::get($config);
$suffix = bin2hex(random_bytes(5));
$adminId = 0;
$userId = 0;
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
    $createUser = $pdo->prepare(
        'INSERT INTO users
            (full_name, email, password_hash, status, is_admin, email_verified_at)
         VALUES (:name, :email, :password_hash, \'active\', :is_admin, UTC_TIMESTAMP())'
    );
    $testPasswordHash = password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT);
    $createUser->execute([
        'name' => 'Administrateur permissions test',
        'email' => 'member-permission-admin-' . $suffix . '@example.test',
        'password_hash' => $testPasswordHash,
        'is_admin' => 1,
    ]);
    $adminId = (int) $pdo->lastInsertId();
    $createUser->execute([
        'name' => 'Utilisateur permissions test',
        'email' => 'member-permission-user-' . $suffix . '@example.test',
        'password_hash' => $testPasswordHash,
        'is_admin' => 0,
    ]);
    $userId = (int) $pdo->lastInsertId();

    $createFarm = $pdo->prepare(
        'INSERT INTO farms (owner_user_id, name, currency_code)
         VALUES (:owner_id, :name, \'XOF\')'
    );
    foreach (['principal', 'secondaire'] as $label) {
        $createFarm->execute([
            'owner_id' => $userId,
            'name' => 'Ferme permissions ' . $label . ' ' . $suffix,
        ]);
        $farmIds[] = (int) $pdo->lastInsertId();
    }
    [$farmId, $secondFarmId] = $farmIds;

    $createMembership = $pdo->prepare(
        "INSERT INTO farm_members (farm_id, user_id, role, status)
         VALUES (:farm_id, :user_id, :role, 'active')"
    );
    $createMembership->execute([
        'farm_id' => $farmId,
        'user_id' => $userId,
        'role' => 'owner',
    ]);
    $createMembership->execute([
        'farm_id' => $secondFarmId,
        'user_id' => $userId,
        'role' => 'owner',
    ]);
    $createMembership->execute([
        'farm_id' => $farmId,
        'user_id' => $adminId,
        'role' => 'operator',
    ]);

    $assert(
        FarmAccess::allows($pdo, $adminId, $farmId, 'expense.manage'),
        'l’administrateur du site conserve toutes les permissions dans son exploitation autorisée'
    );

    AdminService::saveMemberPermissions($pdo, $adminId, $farmId, $userId, [
        'farm.view',
        'flock.view',
        'stock.view',
    ]);
    $assert(
        FarmAccess::allows($pdo, $userId, $farmId, 'stock.view'),
        'accorde individuellement la consultation des stocks'
    );
    $assert(
        !FarmAccess::allows($pdo, $userId, $farmId, 'sale.view'),
        'refuse individuellement les ventes malgré le rôle propriétaire'
    );
    $assert(
        !FarmAccess::allows($pdo, $userId, $farmId, 'stock.manage'),
        'distingue la consultation et la gestion du stock'
    );
    $restrictedDashboard = DashboardService::load(
        $pdo,
        $farmId,
        new DateTimeImmutable('today', new DateTimeZone('UTC')),
        false,
        $userId,
        false,
        false
    );
    $assert(
        $restrictedDashboard['can_view_flocks'] === false
            && $restrictedDashboard['flocks'] === []
            && $restrictedDashboard['operations'] === [],
        'ne divulgue pas les élevages ni les opérations sur le tableau de bord sans permission'
    );
    $assert(
        StockService::products($pdo, $userId, $farmId) === [],
        'autorise l’appel métier de lecture des stocks accordé'
    );

    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    $expectFailure(
        static fn () => SalesService::sales($pdo, $userId, $farmId, $today, $today),
        'refuse l’accès métier direct aux ventes interdites'
    );
    $assert(
        SalesService::sales($pdo, $userId, $secondFarmId, $today, $today) === [],
        'limite les permissions individuelles à l’exploitation sélectionnée'
    );

    $detail = AdminService::memberPermissions($pdo, $adminId, $userId, $farmId);
    $assert(
        $detail['permissions']['stock.view'] && !$detail['permissions']['sale.view'],
        'affiche les permissions effectives dans le détail administrateur'
    );
    $secondFarmDetail = AdminService::memberPermissions($pdo, $adminId, $userId, $secondFarmId);
    $assert(
        $secondFarmDetail['permissions']['sale.view'],
        'conserve les permissions héritées du rôle sur une autre exploitation'
    );
    $adminDetail = AdminService::memberPermissions($pdo, $adminId, $adminId, $farmId);
    $assert(
        !in_array(false, $adminDetail['permissions'], true),
        'présente toutes les permissions comme accordées à un administrateur du site'
    );

    $errors = [];
    $flash = null;
    $csrfToken = 'member-permissions-render-test';
    $memberPermissionDetail = $detail;
    ob_start();
    require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
        . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Pages'
        . DIRECTORY_SEPARATOR . 'user-permissions-content.php';
    $html = (string) ob_get_clean();
    $assert(
        str_contains($html, 'Utilisateur permissions test')
            && str_contains($html, 'name="permissions[]"')
            && str_contains($html, 'Enregistrer les permissions'),
        'rend l’identité et les cases de permissions dans la vue administrateur'
    );

    $expectFailure(
        static fn () => AdminService::saveMemberPermissions($pdo, $userId, $farmId, $userId, []),
        'interdit à un utilisateur standard de modifier les permissions'
    );
    $expectFailure(
        static fn () => AdminService::saveMemberPermissions(
            $pdo,
            $adminId,
            $farmId,
            $userId,
            ['permission.inconnue']
        ),
        'rejette les permissions hors catalogue'
    );
} finally {
    if ($adminId > 0 || $userId > 0) {
        $userIds = array_values(array_filter([$adminId, $userId]));
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        if ($farmIds !== []) {
            $farmPlaceholders = implode(',', array_fill(0, count($farmIds), '?'));
            $pdo->prepare(
                "DELETE FROM audit_logs WHERE actor_user_id IN ($placeholders) OR farm_id IN ($farmPlaceholders)"
            )->execute([...$userIds, ...$farmIds]);
            $pdo->prepare(
                "DELETE FROM farm_members WHERE farm_id IN ($farmPlaceholders)"
            )->execute($farmIds);
            $pdo->prepare(
                "DELETE FROM farms WHERE id IN ($farmPlaceholders)"
            )->execute($farmIds);
        }
        $pdo->prepare("DELETE FROM users WHERE id IN ($placeholders)")->execute($userIds);
    }
}

printf("OK — %d assertions de permissions par utilisateur.\n", $passed);
