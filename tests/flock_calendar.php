<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Database\Connection;
use MonElevage\Flocks\FlockStageService;
use MonElevage\Auth\FarmAccess;

$pdo = Connection::get($config);
$today = new DateTimeImmutable('2026-10-11', new DateTimeZone('UTC'));
$suffix = bin2hex(random_bytes(5));
$passed = 0;
$userIds = [];
$farmIds = [];
$flockId = 0;

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
    $pdo->beginTransaction();
    foreach (['Propriétaire calendrier', 'Opérateur calendrier', 'Autre ferme'] as $index => $name) {
        $user = $pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, status, email_verified_at)
             VALUES (:name, :email, 'test-hash', 'active', UTC_TIMESTAMP())"
        );
        $user->execute([
            'name' => $name,
            'email' => 'calendar-' . $index . '-' . $suffix . '@example.test',
        ]);
        $userIds[] = (int) $pdo->lastInsertId();
    }

    foreach ([['Exploitation calendrier', $userIds[0]], ['Autre exploitation', $userIds[2]]] as [$name, $ownerId]) {
        $farm = $pdo->prepare(
            'INSERT INTO farms (owner_user_id, name, currency_code, timezone)
             VALUES (:user_id, :name, \'XOF\', \'UTC\')'
        );
        $farm->execute(['user_id' => $ownerId, 'name' => $name]);
        $farmIds[] = (int) $pdo->lastInsertId();
    }

    $membership = $pdo->prepare(
        "INSERT INTO farm_members (farm_id, user_id, role, status)
         VALUES (:farm_id, :user_id, :role, 'active')"
    );
    $membership->execute(['farm_id' => $farmIds[0], 'user_id' => $userIds[0], 'role' => 'owner']);
    $membership->execute(['farm_id' => $farmIds[0], 'user_id' => $userIds[1], 'role' => 'operator']);
    $membership->execute(['farm_id' => $farmIds[1], 'user_id' => $userIds[2], 'role' => 'owner']);

    $flock = $pdo->prepare(
        "INSERT INTO flocks
            (farm_id, code, name, started_on, initial_count, status, created_by_user_id)
         VALUES (:farm_id, :code, 'Bande calendrier', '2026-10-01', 100, 'active', :user_id)"
    );
    $flock->execute([
        'farm_id' => $farmIds[0],
        'code' => 'CAL-' . $suffix,
        'user_id' => $userIds[0],
    ]);
    $flockId = (int) $pdo->lastInsertId();
    $pdo->commit();

    $validInput = [
        'title' => 'Contrôle de routine',
        'description' => 'Observation à effectuer',
        'planned_on' => '2026-10-12',
        'priority' => 'high',
    ];
    $assert(FlockStageService::validate($validInput)['errors'] === [], 'accepte des données d’étape valides');
    $expectFailure(
        static fn () => FlockStageService::create(
            $pdo,
            $userIds[0],
            $farmIds[0],
            $flockId,
            array_merge($validInput, ['planned_on' => '2026-02-30'])
        ),
        'rejette une date de calendrier impossible'
    );
    $expectFailure(
        static fn () => FlockStageService::create(
            $pdo,
            $userIds[0],
            $farmIds[0],
            $flockId,
            array_merge($validInput, ['planned_on' => '2026-09-30'])
        ),
        'refuse une date antérieure au début de l’élevage'
    );

    $stageId = FlockStageService::create($pdo, $userIds[0], $farmIds[0], $flockId, $validInput);
    $assert($stageId > 0, 'crée une étape avec son historique initial');
    $summary = FlockStageService::summaryForFarm($pdo, $userIds[0], $farmIds[0], $today);
    $assert($summary[$flockId]['progress'] === 0, 'une étape définie mais non terminée donne une progression de 0 %');
    $assert($summary[$flockId]['overdue_count'] === 0, 'ne marque pas une date future comme en retard');

    FlockStageService::update(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $stageId,
        array_merge($validInput, ['title' => 'Contrôle actualisé'])
    );
    FlockStageService::reschedule(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $stageId,
        '2026-10-13',
        'Indisponibilité de l’équipe'
    );
    FlockStageService::changeStatus($pdo, $userIds[0], $farmIds[0], $stageId, 'start', $today);
    FlockStageService::addObservation(
        $pdo,
        $userIds[1],
        $farmIds[0],
        $stageId,
        'Observation ajoutée par un opérateur autorisé'
    );
    FlockStageService::changeStatus(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $stageId,
        'complete',
        $today,
        '2026-10-11'
    );

    $stages = FlockStageService::listForFarm($pdo, $userIds[0], $farmIds[0], $today, $flockId);
    $assert(count($stages) === 1, 'liste uniquement les étapes de l’élevage sélectionné');
    $assert($stages[0]['status'] === 'completed', 'termine une étape et stocke son statut réel');
    $assert($stages[0]['completed_on'] === '2026-10-11', 'enregistre la date réelle de réalisation');
    $assert(count($stages[0]['observations']) === 1, 'conserve les observations et leur auteur');
    $assert(count($stages[0]['history']) === 5, 'journalise la création, modification, report et transitions');
    $assert(
        FlockStageService::summaryForFarm($pdo, $userIds[0], $farmIds[0], $today)[$flockId]['progress'] === 100,
        'actualise la progression immédiatement après terminaison'
    );
    FlockStageService::changeStatus($pdo, $userIds[0], $farmIds[0], $stageId, 'reopen', $today);
    $assert(
        FlockStageService::summaryForFarm($pdo, $userIds[0], $farmIds[0], $today)[$flockId]['progress'] === 0,
        'met à jour la progression après réouverture'
    );

    $expectFailure(
        static fn () => FlockStageService::changeStatus(
            $pdo,
            $userIds[0],
            $farmIds[0],
            $stageId,
            'complete',
            $today,
            '2026-10-12'
        ),
        'refuse une date de réalisation future'
    );
    $expectFailure(
        static fn () => FlockStageService::changeStatus(
            $pdo,
            $userIds[0],
            $farmIds[0],
            $stageId,
            'complete',
            $today,
            '2026-09-30'
        ),
        'refuse une réalisation datée avant le début de l’élevage'
    );
    $expectFailure(
        static fn () => FlockStageService::update(
            $pdo,
            $userIds[1],
            $farmIds[0],
            $stageId,
            $validInput
        ),
        'interdit à un opérateur de modifier le calendrier'
    );
    $expectFailure(
        static fn () => FlockStageService::listForFarm(
            $pdo,
            $userIds[2],
            $farmIds[0],
            $today
        ),
        'isole les données d’une autre exploitation'
    );
    $expectFailure(
        static fn () => FarmAccess::requirePermission($pdo, $userIds[1], $farmIds[0], 'flock.manage'),
        'refuse la permission de gestion au rôle opérateur'
    );

    $closeFlock = $pdo->prepare(
        "UPDATE flocks SET status = 'closed' WHERE farm_id = :farm_id AND id = :flock_id"
    );
    $closeFlock->execute(['farm_id' => $farmIds[0], 'flock_id' => $flockId]);
    $expectFailure(
        static fn () => FlockStageService::addObservation(
            $pdo,
            $userIds[1],
            $farmIds[0],
            $stageId,
            'Ne doit pas être ajoutée après archivage'
        ),
        'rend le calendrier archivé non modifiable'
    );
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($userIds !== [] && $farmIds !== []) {
        $pdo->beginTransaction();
        foreach ($farmIds as $farmId) {
            $pdo->prepare('DELETE FROM flock_stage_history WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
            $pdo->prepare('DELETE FROM flock_stage_observations WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
            $pdo->prepare('DELETE FROM flock_stages WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
            $pdo->prepare('DELETE FROM flocks WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
            $pdo->prepare('DELETE FROM farm_members WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
            $pdo->prepare('DELETE FROM farms WHERE id = :farm_id')->execute(['farm_id' => $farmId]);
        }
        foreach ($userIds as $userId) {
            $pdo->prepare('DELETE FROM users WHERE id = :user_id')->execute(['user_id' => $userId]);
        }
        $pdo->commit();
    }
}

printf("OK — %d assertions du calendrier d’élevage.\n", $passed);
