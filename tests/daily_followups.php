<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Dashboard\DashboardService;
use MonElevage\Database\Connection;
use MonElevage\Followups\DailyFollowupService;

$pdo = Connection::get($config);
$suffix = bin2hex(random_bytes(5));
$today = new DateTimeImmutable('2026-10-11', new DateTimeZone('UTC'));
$timezone = new DateTimeZone('UTC');
$userIds = [];
$farmIds = [];
$flockIds = [];
$followupIds = [];
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
        "INSERT INTO users (full_name, email, password_hash, status, email_verified_at)
         VALUES (:name, :email, 'test-hash', 'active', UTC_TIMESTAMP())"
    );
    foreach (['Suivi quotidien', 'Compte isolé'] as $index => $name) {
        $createUser->execute([
            'name' => $name,
            'email' => 'daily-followup-' . $suffix . '-' . $index . '@example.test',
        ]);
        $userIds[] = (int) $pdo->lastInsertId();
    }

    $createFarm = $pdo->prepare(
        'INSERT INTO farms (owner_user_id, name, currency_code, timezone)
         VALUES (:user_id, :name, \'XOF\', \'UTC\')'
    );
    $createFarm->execute(['user_id' => $userIds[0], 'name' => 'Ferme suivi ' . $suffix]);
    $farmIds[] = (int) $pdo->lastInsertId();
    $createFarm->execute(['user_id' => $userIds[1], 'name' => 'Ferme privée ' . $suffix]);
    $farmIds[] = (int) $pdo->lastInsertId();

    $membership = $pdo->prepare(
        "INSERT INTO farm_members (farm_id, user_id, role, status)
         VALUES (:farm_id, :user_id, 'owner', 'active')"
    );
    $membership->execute(['farm_id' => $farmIds[0], 'user_id' => $userIds[0]]);
    $membership->execute(['farm_id' => $farmIds[1], 'user_id' => $userIds[1]]);

    $createFlock = $pdo->prepare(
        "INSERT INTO flocks
            (farm_id, code, name, flock_type, poultry_type, production_type,
             currency_code, started_on, initial_count, status, created_by_user_id)
         VALUES
            (:farm_id, :code, :name, 'broiler', 'chicken', 'meat',
             'XOF', '2026-10-01', 100, 'active', :user_id)"
    );
    $createFlock->execute([
        'farm_id' => $farmIds[0],
        'code' => 'DAILY-' . strtoupper($suffix),
        'name' => 'Bande test quotidienne',
        'user_id' => $userIds[0],
    ]);
    $flockIds[] = (int) $pdo->lastInsertId();

    $dayOne = [
        'followup_date' => '2026-10-10',
        'entries_count' => '5',
        'sick_count' => '3',
        'mortality_count' => '2',
        'sales_count' => '4',
        'other_exits_count' => '1',
        'feed_consumed_kg' => '15.250',
        'water_consumed_liters' => '30',
        'temperature_celsius' => '27.5',
        'humidity_percent' => '60',
        'average_weight_g' => '1500',
        'notes' => 'Observation du premier jour',
    ];
    $validated = DailyFollowupService::validate($dayOne, $today);
    $assert($validated['errors'] === [], 'valide les observations et mouvements d’un jour');
    $dayOneId = DailyFollowupService::save(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        $dayOne,
        null,
        $today
    );
    $followupIds[] = $dayOneId;
    $dayOneRow = DailyFollowupService::find($pdo, $userIds[0], $farmIds[0], $dayOneId);
    $assert((int) $dayOneRow['observed_count'] === 98, 'calcule 100 + 5 entrées - 2 morts - 4 ventes - 1 autre sortie');
    $assert((int) $dayOneRow['sick_count'] === 3, 'les sujets malades restent inclus dans l’effectif vivant');
    $movementQuery = $pdo->prepare(
        'SELECT COUNT(*), SUM(quantity_delta)
         FROM animal_movements WHERE farm_id = :farm_id AND daily_followup_id = :followup_id'
    );
    $movementQuery->execute(['farm_id' => $farmIds[0], 'followup_id' => $dayOneId]);
    $dayOneMovements = $movementQuery->fetch(PDO::FETCH_NUM);
    $assert((int) $dayOneMovements[0] === 4 && (int) $dayOneMovements[1] === -2, 'crée un seul mouvement par catégorie utile et un solde unique');

    $dayTwo = [
        'followup_date' => '2026-10-11',
        'entries_count' => '2',
        'sick_count' => '2',
        'mortality_count' => '1',
        'sales_count' => '0',
        'other_exits_count' => '0',
        'feed_consumed_kg' => '',
        'water_consumed_liters' => '',
        'temperature_celsius' => '',
        'humidity_percent' => '',
        'average_weight_g' => '',
        'notes' => 'Deuxième jour consécutif',
    ];
    $externalEntry = $pdo->prepare(
        "INSERT INTO animal_movements
            (farm_id, flock_id, movement_type, quantity_delta, occurred_at,
             reason, created_by_user_id)
         VALUES (:farm_id, :flock_id, 'entry', 2, '2026-10-11 08:00:00',
                 'Entrée déjà inscrite au registre', :user_id)"
    );
    $externalEntry->execute([
        'farm_id' => $farmIds[0],
        'flock_id' => $flockIds[0],
        'user_id' => $userIds[0],
    ]);
    $expectFailure(
        static fn () => DailyFollowupService::save(
            $pdo,
            $userIds[0],
            $farmIds[0],
            $flockIds[0],
            array_merge($dayTwo, ['entries_count' => '3']),
            null,
            $today
        ),
        'refuse de recompter un mouvement déjà enregistré avec une quantité différente'
    );
    $dayTwoId = DailyFollowupService::save(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        $dayTwo,
        null,
        $today
    );
    $followupIds[] = $dayTwoId;
    $dayTwoRow = DailyFollowupService::find($pdo, $userIds[0], $farmIds[0], $dayTwoId);
    $assert((int) $dayTwoRow['observed_count'] === 99, 'reprend l’effectif final du jour précédent comme ouverture');

    $correctedDayOne = array_merge($dayOne, [
        'mortality_count' => '1',
        'notes' => 'Correction du suivi antérieur',
    ]);
    DailyFollowupService::save(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        $correctedDayOne,
        $dayOneId,
        $today
    );
    $dayTwoRow = DailyFollowupService::find($pdo, $userIds[0], $farmIds[0], $dayTwoId);
    $assert((int) $dayTwoRow['observed_count'] === 100, 'recalcule automatiquement les fins des jours suivants après correction historique');

    $expectFailure(
        static fn () => DailyFollowupService::save(
            $pdo,
            $userIds[0],
            $farmIds[0],
            $flockIds[0],
            $dayTwo,
            null,
            $today
        ),
        'refuse un doublon pour le même élevage et la même date'
    );
    $invalidCounts = array_merge($dayTwo, [
        'entries_count' => '-1',
        'sick_count' => '999',
        'temperature_celsius' => '71',
        'humidity_percent' => '101',
        'average_weight_g' => '0',
    ]);
    $assert(
        DailyFollowupService::validate($invalidCounts, $today)['errors'] !== [],
        'rejette les comptes et mesures impossibles'
    );
    $futureDay = array_merge($dayTwo, ['followup_date' => '2026-10-12']);
    $assert(
        DailyFollowupService::validate($futureDay, $today)['errors'] !== [],
        'refuse une date future'
    );

    $correctedDayTwo = array_merge($dayTwo, [
        'mortality_count' => '2',
        'notes' => 'Correction de la mortalité du jour',
    ]);
    DailyFollowupService::save(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        $correctedDayTwo,
        $dayTwoId,
        $today
    );
    $movementQuery->execute(['farm_id' => $farmIds[0], 'followup_id' => $dayTwoId]);
    $dayTwoMovements = $movementQuery->fetch(PDO::FETCH_NUM);
    $assert((int) $dayTwoMovements[0] === 1 && (int) $dayTwoMovements[1] === -2, 'corrige les mouvements quotidiens en place sans créer de doublons');
    $registeredEntries = $pdo->prepare(
        "SELECT COALESCE(SUM(quantity_delta), 0)
         FROM animal_movements
         WHERE farm_id = :farm_id AND flock_id = :flock_id
           AND movement_type = 'entry'
           AND occurred_at >= '2026-10-11 00:00:00'
           AND occurred_at < '2026-10-12 00:00:00'"
    );
    $registeredEntries->execute(['farm_id' => $farmIds[0], 'flock_id' => $flockIds[0]]);
    $assert((int) $registeredEntries->fetchColumn() === 2, 'ne compte qu’une fois les entrées déjà consignées séparément');

    $impossibleCorrection = array_merge($correctedDayTwo, [
        'mortality_count' => '200',
        'sick_count' => '0',
    ]);
    $expectFailure(
        static fn () => DailyFollowupService::save(
            $pdo,
            $userIds[0],
            $farmIds[0],
            $flockIds[0],
            $impossibleCorrection,
            $dayTwoId,
            $today
        ),
        'annule une correction qui produit un effectif final négatif'
    );
    $dayTwoRow = DailyFollowupService::find($pdo, $userIds[0], $farmIds[0], $dayTwoId);
    $assert((int) $dayTwoRow['observed_count'] === 99, 'conserve le suivi précédent après une correction invalide');

    $history = DailyFollowupService::history(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        '2026-10-10',
        '2026-10-11',
        $timezone
    );
    $assert(count($history) === 2, 'filtre l’historique par élevage et intervalle de dates');
    $assert((int) $history[0]['opening_count'] === 99, 'recalcule l’effectif d’ouverture dans l’historique');
    $expectFailure(
        static fn () => DailyFollowupService::history(
            $pdo,
            $userIds[1],
            $farmIds[0],
            null,
            null,
            null,
            $timezone
        ),
        'isole les suivis d’une exploitation des comptes non autorisés'
    );

    $dashboard = DashboardService::load($pdo, $farmIds[0], $today, false);
    $assert($dashboard['summary']['live_count'] === 99, 'met à jour les effectifs vivants du tableau de bord');
    $assert($dashboard['summary']['mortality_count'] === 3, 'actualise les mortalités à partir des mouvements quotidiens');
    $assert($dashboard['summary']['latest_sick_count'] === 2, 'actualise les signalements de sujets malades');
    $assert($dashboard['flocks'][0]['followup_count'] === 2, 'compte les suivis enregistrés dans la fiche du tableau de bord');
} finally {
    if ($followupIds !== []) {
        $deleteMovements = $pdo->prepare(
            'DELETE FROM animal_movements
             WHERE farm_id = ? AND daily_followup_id IN ('
                . implode(', ', array_fill(0, count($followupIds), '?')) . ')'
        );
        $deleteMovements->execute([$farmIds[0], ...$followupIds]);
        $deleteFollowups = $pdo->prepare(
            'DELETE FROM daily_followups WHERE farm_id = ? AND id IN ('
                . implode(', ', array_fill(0, count($followupIds), '?')) . ')'
        );
        $deleteFollowups->execute([$farmIds[0], ...$followupIds]);
    }
    if ($flockIds !== []) {
        $pdo->prepare('DELETE FROM animal_movements WHERE farm_id = :farm_id AND flock_id = :flock_id')
            ->execute(['farm_id' => $farmIds[0], 'flock_id' => $flockIds[0]]);
        $pdo->prepare('DELETE FROM flocks WHERE farm_id = :farm_id AND id = :flock_id')
            ->execute(['farm_id' => $farmIds[0], 'flock_id' => $flockIds[0]]);
    }
    if ($farmIds !== []) {
        $pdo->prepare('DELETE FROM farm_members WHERE farm_id IN (?, ?)')
            ->execute([$farmIds[0], $farmIds[1]]);
        $pdo->prepare('DELETE FROM farms WHERE id IN (?, ?)')
            ->execute([$farmIds[0], $farmIds[1]]);
    }
    foreach ($userIds as $userId) {
        $pdo->prepare('DELETE FROM users WHERE id = :user_id')->execute(['user_id' => $userId]);
    }
}

printf("OK — %d assertions du suivi quotidien.\n", $passed);
