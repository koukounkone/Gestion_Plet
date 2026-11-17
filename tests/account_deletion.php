<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Admin\AccountDeletionService;
use MonElevage\Database\Connection;
use MonElevage\Reports\AccountArchivePdfRenderer;

$pdo = Connection::get($config);
$suffix = bin2hex(random_bytes(5));
$adminId = 0;
$otherAdminId = 0;
$userId = 0;
$farmId = 0;
$flockId = 0;
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
    $passwordHash = password_hash(bin2hex(random_bytes(20)), PASSWORD_DEFAULT);
    $createUser->execute([
        'name' => 'Administrateur suppression test',
        'email' => 'account-deletion-admin-' . $suffix . '@example.test',
        'password_hash' => $passwordHash,
        'is_admin' => 1,
    ]);
    $adminId = (int) $pdo->lastInsertId();
    $createUser->execute([
        'name' => 'Second administrateur suppression test',
        'email' => 'account-deletion-other-admin-' . $suffix . '@example.test',
        'password_hash' => $passwordHash,
        'is_admin' => 1,
    ]);
    $otherAdminId = (int) $pdo->lastInsertId();
    $createUser->execute([
        'name' => 'Utilisateur suppression test',
        'email' => 'account-deletion-user-' . $suffix . '@example.test',
        'password_hash' => $passwordHash,
        'is_admin' => 0,
    ]);
    $userId = (int) $pdo->lastInsertId();

    $createFarm = $pdo->prepare(
        'INSERT INTO farms (owner_user_id, name, currency_code)
         VALUES (:owner_id, :name, \'XOF\')'
    );
    $createFarm->execute([
        'owner_id' => $userId,
        'name' => 'Exploitation suppression test ' . $suffix,
    ]);
    $farmId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO farm_members (farm_id, user_id, role, status)
         VALUES (:farm_id, :user_id, 'owner', 'active')"
    )->execute(['farm_id' => $farmId, 'user_id' => $userId]);
    $createFlock = $pdo->prepare(
        'INSERT INTO flocks
            (farm_id, code, name, started_on, initial_count, created_by_user_id)
         VALUES (:farm_id, :code, :name, UTC_DATE(), 20, :user_id)'
    );
    $createFlock->execute([
        'farm_id' => $farmId,
        'code' => 'TEST-' . $suffix,
        'name' => 'Élevage suppression test',
        'user_id' => $userId,
    ]);
    $flockId = (int) $pdo->lastInsertId();
    $createFollowup = $pdo->prepare(
        'INSERT INTO daily_followups
            (farm_id, flock_id, followup_date, created_by_user_id)
         VALUES (:farm_id, :flock_id, UTC_DATE(), :user_id)'
    );
    $createFollowup->execute([
        'farm_id' => $farmId,
        'flock_id' => $flockId,
        'user_id' => $userId,
    ]);
    $createMovement = $pdo->prepare(
        'INSERT INTO animal_movements
            (farm_id, flock_id, movement_type, quantity_delta, occurred_at, created_by_user_id)
         VALUES (:farm_id, :flock_id, \'adjustment\', :quantity, UTC_TIMESTAMP(), :user_id)'
    );
    $createMovement->execute([
        'farm_id' => $farmId,
        'flock_id' => $flockId,
        'quantity' => 1,
        'user_id' => $userId,
    ]);
    $parentMovementId = (int) $pdo->lastInsertId();
    $createMovementWithReversal = $pdo->prepare(
        'INSERT INTO animal_movements
            (farm_id, flock_id, movement_type, quantity_delta, occurred_at,
             reversal_of_id, created_by_user_id)
         VALUES (:farm_id, :flock_id, \'adjustment\', -1, UTC_TIMESTAMP(),
                 :reversal_id, :user_id)'
    );
    $createMovementWithReversal->execute([
        'farm_id' => $farmId,
        'flock_id' => $flockId,
        'reversal_id' => $parentMovementId,
        'user_id' => $userId,
    ]);

    $archive = AccountDeletionService::archiveData($pdo, $adminId, $userId);
    $assert(
        $archive['user']['email'] === 'account-deletion-user-' . $suffix . '@example.test'
            && count($archive['farms']) === 1
            && (int) $archive['farms'][0]['farm']['id'] === $farmId
            && count($archive['farms'][0]['tables']['flocks']) === 1
            && count($archive['farms'][0]['tables']['animal_movements']) === 2,
        'prépare l’archive du compte et de son exploitation'
    );
    $assert(
        str_starts_with(AccountArchivePdfRenderer::render($archive), '%PDF-'),
        'génère un document PDF valide'
    );

    $expectFailure(
        static fn () => AccountDeletionService::deleteUser($pdo, $adminId, $adminId),
        'interdit à l’administrateur de supprimer son propre compte'
    );
    $expectFailure(
        static fn () => AccountDeletionService::deleteUser($pdo, $adminId, $otherAdminId),
        'interdit la suppression d’un autre compte administrateur'
    );

    $result = AccountDeletionService::deleteUser($pdo, $adminId, $userId);
    $userExists = $pdo->prepare('SELECT 1 FROM users WHERE id = :id');
    $userExists->execute(['id' => $userId]);
    $farmExists = $pdo->prepare('SELECT 1 FROM farms WHERE id = :id');
    $farmExists->execute(['id' => $farmId]);
    $assert(
        $userExists->fetchColumn() === false
            && $farmExists->fetchColumn() === false
            && $result['farm_count'] === 1,
        'supprime définitivement le compte et son exploitation'
    );
    $deleteLog = $pdo->prepare(
        'SELECT action FROM audit_logs
         WHERE actor_user_id = :actor_id AND resource_type = \'user\'
           AND resource_id = :user_id ORDER BY id DESC LIMIT 1'
    );
    $deleteLog->execute(['actor_id' => $adminId, 'user_id' => $userId]);
    $assert($deleteLog->fetchColumn() === 'user.deleted', 'journalise la suppression définitive');
} finally {
    if ($farmId > 0) {
        $pdo->prepare('DELETE FROM audit_logs WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('UPDATE animal_movements SET reversal_of_id = NULL WHERE farm_id = :farm_id')
            ->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM animal_movements WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM daily_followups WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM flocks WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM farm_members WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM farms WHERE id = :farm_id')->execute(['farm_id' => $farmId]);
    }
    $adminIds = array_values(array_filter([$adminId, $otherAdminId]));
    foreach ($adminIds as $cleanupAdminId) {
        $pdo->prepare('DELETE FROM audit_logs WHERE actor_user_id = :user_id')->execute(['user_id' => $cleanupAdminId]);
        $pdo->prepare('DELETE FROM users WHERE id = :user_id')->execute(['user_id' => $cleanupAdminId]);
    }
    if ($userId > 0) {
        $pdo->prepare('DELETE FROM users WHERE id = :user_id')->execute(['user_id' => $userId]);
    }
}

printf("OK — %d assertions de suppression et d’archive PDF de compte.\n", $passed);
