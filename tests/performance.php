<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Database\Connection;
use MonElevage\Performance\PerformanceService;

$pdo = Connection::get($config);
$suffix = bin2hex(random_bytes(5));
$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$dateOne = $today->modify('-8 days')->format('Y-m-d');
$dateTwo = $today->modify('-6 days')->format('Y-m-d');
$dateThree = $today->modify('-5 days')->format('Y-m-d');
$dateFour = $today->modify('-4 days')->format('Y-m-d');
$userIds = [];
$farmIds = [];
$flockIds = [];
$weighingIds = [];
$eggIds = [];
$targetIds = [];
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
    foreach (['Performances', 'Compte tiers', 'Opérateur performances'] as $index => $name) {
        $createUser->execute([
            'name' => $name,
            'email' => 'performance-' . $suffix . '-' . $index . '@example.test',
        ]);
        $userIds[] = (int) $pdo->lastInsertId();
    }

    $createFarm = $pdo->prepare(
        "INSERT INTO farms (owner_user_id, name, currency_code, timezone)
         VALUES (:user_id, :name, 'XOF', 'UTC')"
    );
    $createFarm->execute(['user_id' => $userIds[0], 'name' => 'Ferme performances ' . $suffix]);
    $farmIds[] = (int) $pdo->lastInsertId();
    $createFarm->execute(['user_id' => $userIds[1], 'name' => 'Ferme privée ' . $suffix]);
    $farmIds[] = (int) $pdo->lastInsertId();

    $membership = $pdo->prepare(
        'INSERT INTO farm_members (farm_id, user_id, role, status)
         VALUES (:farm_id, :user_id, :role, \'active\')'
    );
    $membership->execute(['farm_id' => $farmIds[0], 'user_id' => $userIds[0], 'role' => 'owner']);
    $membership->execute(['farm_id' => $farmIds[1], 'user_id' => $userIds[1], 'role' => 'owner']);
    $membership->execute(['farm_id' => $farmIds[0], 'user_id' => $userIds[2], 'role' => 'operator']);

    $createFlock = $pdo->prepare(
        'INSERT INTO flocks
            (farm_id, code, name, flock_type, poultry_type, production_type,
             currency_code, started_on, initial_count, status, created_by_user_id)
         VALUES
            (:farm_id, :code, :name, :type, \'chicken\', :production,
             \'XOF\', :started_on, 100, \'active\', :user_id)'
    );
    $createFlock->execute([
        'farm_id' => $farmIds[0],
        'code' => 'PERF-' . strtoupper($suffix),
        'name' => 'Pondeuses performances',
        'type' => 'layer',
        'production' => 'eggs',
        'started_on' => $today->modify('-10 days')->format('Y-m-d'),
        'user_id' => $userIds[0],
    ]);
    $flockIds[] = (int) $pdo->lastInsertId();
    $createFlock->execute([
        'farm_id' => $farmIds[0],
        'code' => 'MEAT-' . strtoupper($suffix),
        'name' => 'Poulets de chair performances',
        'type' => 'broiler',
        'production' => 'meat',
        'started_on' => $today->modify('-10 days')->format('Y-m-d'),
        'user_id' => $userIds[0],
    ]);
    $flockIds[] = (int) $pdo->lastInsertId();

    $validWeighing = [
        'measured_on' => $dateOne,
        'sample_size' => '10',
        'total_weight_g' => '10000',
        'notes' => 'Pesée initiale contrôlée',
    ];
    $assert(
        PerformanceService::validateWeighing(
            $validWeighing,
            $today->modify('-10 days')->format('Y-m-d'),
            $today
        )['errors'] === [],
        'accepte une pesée valide et en calcule la moyenne à partir du poids total et du nombre pesé'
    );
    $assert(
        PerformanceService::validateWeighing(
            array_merge($validWeighing, ['sample_size' => '0']),
            $today->modify('-10 days')->format('Y-m-d'),
            $today
        )['errors'] !== [],
        'rejette un échantillon vide'
    );
    $assert(
        PerformanceService::validateWeighing(
            array_merge($validWeighing, ['measured_on' => $today->modify('+1 day')->format('Y-m-d')]),
            $today->modify('-10 days')->format('Y-m-d'),
            $today
        )['errors'] !== [],
        'refuse une pesée future'
    );

    $weighingIds[] = PerformanceService::saveWeighing(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        $validWeighing,
        null,
        $today
    );
    $weighingIds[] = PerformanceService::saveWeighing(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        [
            'measured_on' => $dateTwo,
            'sample_size' => '20',
            'total_weight_g' => '24000',
            'notes' => '',
        ],
        null,
        $today
    );
    $expectFailure(
        static fn () => PerformanceService::saveWeighing(
            $pdo,
            $userIds[0],
            $farmIds[0],
            $flockIds[0],
            $validWeighing,
            null,
            $today
        ),
        'refuse une seconde pesée le même jour pour le même élevage'
    );
    PerformanceService::saveWeighing(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        [
            'measured_on' => $dateTwo,
            'sample_size' => '20',
            'total_weight_g' => '26000',
            'notes' => 'Correction du poids total',
        ],
        $weighingIds[1],
        $today
    );

    $legacyWeight = $pdo->prepare(
        'INSERT INTO daily_followups
            (farm_id, flock_id, followup_date, observed_count, average_weight_g,
             created_by_user_id)
         VALUES (:farm_id, :flock_id, :date, 100, 1250, :user_id)'
    );
    $legacyWeight->execute([
        'farm_id' => $farmIds[0],
        'flock_id' => $flockIds[0],
        'date' => $dateFour,
        'user_id' => $userIds[0],
    ]);

    $targetValues = [
        'metric' => 'average_weight_g',
        'target_value' => '1000',
        'valid_from' => $dateOne,
        'valid_until' => '',
        'source_title' => 'Référence technique vérifiée',
        'source_notes' => 'Valeur choisie à partir de la documentation applicable à cette bande.',
        'source_url' => 'https://example.test/reference',
    ];
    $assert(
        PerformanceService::validateTarget(
            $targetValues,
            $today->modify('-10 days')->format('Y-m-d'),
            $today,
            true
        )['errors'] === [],
        'accepte un objectif avec sa référence, son contexte et sa période'
    );
    $assert(
        PerformanceService::validateTarget(
            array_merge($targetValues, ['source_url' => 'javascript:alert(1)']),
            $today->modify('-10 days')->format('Y-m-d'),
            $today,
            true
        )['errors'] !== [],
        'rejette un lien source non HTTP(S)'
    );
    $targetIds[] = PerformanceService::saveTarget(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        $targetValues,
        null,
        $today
    );
    $targetIds[] = PerformanceService::saveTarget(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        array_merge($targetValues, [
            'metric' => 'daily_gain_g',
            'target_value' => '20',
            'source_title' => 'Référence de croissance',
        ]),
        null,
        $today
    );
    $targetIds[] = PerformanceService::saveTarget(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        array_merge($targetValues, [
            'metric' => 'laying_rate_percent',
            'target_value' => '75',
            'source_title' => 'Référence de collecte',
        ]),
        null,
        $today
    );
    $expectFailure(
        static fn () => PerformanceService::saveTarget(
            $pdo,
            $userIds[0],
            $farmIds[0],
            $flockIds[0],
            $targetValues,
            null,
            $today
        ),
        'refuse des périodes de référence qui se chevauchent pour un même indicateur'
    );
    $expectFailure(
        static fn () => PerformanceService::saveTarget(
            $pdo,
            $userIds[2],
            $farmIds[0],
            $flockIds[0],
            $targetValues,
            null,
            $today
        ),
        'empêche un opérateur de gérer les objectifs'
    );

    $weightReport = PerformanceService::report(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        $dateOne,
        $dateFour,
        new DateTimeZone('UTC')
    );
    $assert(count($weightReport['weights']) === 3, 'filtre les pesées et conserve une vraie mesure historique du suivi quotidien');
    $assert(abs((float) $weightReport['weights'][0]['average_weight_g'] - 1000) < 0.01, 'calcule la moyenne : 10 000 g / 10 animaux = 1 000 g/tête');
    $assert(abs((float) $weightReport['weights'][1]['average_weight_g'] - 1300) < 0.01, 'une correction met à jour le poids moyen de la pesée existante');
    $assert(abs((float) $weightReport['daily_gain_g'] - (-25)) < 0.01, 'calcule le gain entre les deux dernières mesures : (1 250 - 1 300) / 2 jours');
    $assert($weightReport['weights'][0]['target_value'] === 1000.0, 'applique uniquement l’objectif couvrant la date observée');
    $assert($weightReport['daily_gain_target'] === 20.0, 'compare le gain mesuré à un objectif documenté applicable');
    $singleMeasureReport = PerformanceService::report(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        $dateOne,
        $dateOne,
        new DateTimeZone('UTC')
    );
    $assert($singleMeasureReport['daily_gain_g'] === null, 'laisse le gain/jour indisponible lorsqu’une seule mesure existe');

    $eggValuesOne = [
        'record_date' => $dateThree,
        'opening_stock_count' => '5',
        'collected_count' => '100',
        'broken_count' => '2',
        'sold_count' => '30',
        'notes' => 'Première journée de stock',
    ];
    $eggIds[] = PerformanceService::saveEggRecord(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        $eggValuesOne,
        null,
        $today
    );
    $eggIds[] = PerformanceService::saveEggRecord(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        [
            'record_date' => $dateFour,
            'opening_stock_count' => '0',
            'collected_count' => '20',
            'broken_count' => '1',
            'sold_count' => '50',
            'notes' => '',
        ],
        null,
        $today
    );
    PerformanceService::saveEggRecord(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        [
            'record_date' => $dateFour,
            'opening_stock_count' => '0',
            'collected_count' => '20',
            'broken_count' => '80',
            'sold_count' => '0',
            'notes' => 'Œufs cassés provenant aussi du stock antérieur',
        ],
        $eggIds[1],
        $today
    );
    $olderStockBreakage = PerformanceService::report(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        $dateFour,
        $dateFour,
        new DateTimeZone('UTC')
    );
    $assert((int) $olderStockBreakage['eggs'][0]['closing_stock_count'] === 13, 'autorise les œufs cassés prélevés sur le stock antérieur disponible');
    PerformanceService::saveEggRecord(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        [
            'record_date' => $dateFour,
            'opening_stock_count' => '0',
            'collected_count' => '20',
            'broken_count' => '1',
            'sold_count' => '50',
            'notes' => '',
        ],
        $eggIds[1],
        $today
    );
    $pdo->prepare(
        "INSERT INTO animal_movements
            (farm_id, flock_id, movement_type, quantity_delta, occurred_at,
             reason, created_by_user_id)
         VALUES (:farm_id, :flock_id, 'mortality', -5, :occurred_at,
                 'Mouvement de test', :user_id)"
    )->execute([
        'farm_id' => $farmIds[0],
        'flock_id' => $flockIds[0],
        'occurred_at' => $dateFour . ' 12:00:00',
        'user_id' => $userIds[0],
    ]);

    $eggReport = PerformanceService::report(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        $dateThree,
        $dateFour,
        new DateTimeZone('UTC')
    );
    $assert((int) $eggReport['eggs'][0]['closing_stock_count'] === 73, 'calcule le stock : 5 + 100 collectés - 2 cassés - 30 vendus');
    $assert((int) $eggReport['eggs'][1]['opening_stock_count'] === 73, 'reporte le stock d’ouverture au jour suivant');
    $assert((int) $eggReport['eggs'][1]['closing_stock_count'] === 42, 'autorise les ventes d’œufs provenant du stock antérieur');
    $assert((int) $eggReport['eggs'][1]['live_count'] === 95, 'calcule l’effectif vivant de fin de journée depuis l’effectif initial et les mouvements');
    $assert(abs((float) $eggReport['eggs'][0]['laying_rate_percent'] - 100) < 0.01, 'calcule le ratio descriptif collecte / effectif vivant × 100');
    $assert($eggReport['eggs'][0]['target_value'] === 75.0, 'associe à la ponte uniquement un objectif documenté applicable');

    $expectFailure(
        static fn () => PerformanceService::saveEggRecord(
            $pdo,
            $userIds[0],
            $farmIds[0],
            $flockIds[0],
            array_merge($eggValuesOne, ['record_date' => $dateThree]),
            null,
            $today
        ),
        'refuse les relevés de ponte en doublon'
    );
    $expectFailure(
        static fn () => PerformanceService::saveEggRecord(
            $pdo,
            $userIds[0],
            $farmIds[0],
            $flockIds[0],
            array_merge($eggValuesOne, ['broken_count' => '106']),
            null,
            $today
        ),
        'refuse des œufs cassés au-delà du stock d’ouverture et des collectes'
    );
    $expectFailure(
        static fn () => PerformanceService::saveEggRecord(
            $pdo,
            $userIds[0],
            $farmIds[0],
            $flockIds[0],
            array_merge($eggValuesOne, ['collected_count' => '10']),
            $eggIds[0],
            $today
        ),
        'annule une correction historique qui rend le stock négatif'
    );
    $preservedStock = $pdo->prepare(
        'SELECT closing_stock_count FROM flock_egg_records
         WHERE farm_id = :farm_id AND flock_id = :flock_id AND id = :id'
    );
    $preservedStock->execute([
        'farm_id' => $farmIds[0],
        'flock_id' => $flockIds[0],
        'id' => $eggIds[0],
    ]);
    $assert((int) $preservedStock->fetchColumn() === 73, 'préserve les soldes enregistrés après une correction rejetée');

    PerformanceService::saveEggRecord(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        array_merge($eggValuesOne, ['collected_count' => '80']),
        $eggIds[0],
        $today
    );
    $correctedReport = PerformanceService::report(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        $dateThree,
        $dateFour,
        new DateTimeZone('UTC')
    );
    $assert((int) $correctedReport['eggs'][0]['closing_stock_count'] === 53, 'corrige le solde du jour modifié');
    $assert((int) $correctedReport['eggs'][1]['opening_stock_count'] === 53, 'recalcule le solde d’ouverture des jours suivants après correction');

    $emptyReport = PerformanceService::report(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[0],
        $today->modify('-1 day')->format('Y-m-d'),
        $today->format('Y-m-d'),
        new DateTimeZone('UTC')
    );
    $assert($emptyReport['weights'] === [] && $emptyReport['eggs'] === [], 'retourne un état de données vides sans statistiques inventées');
    $meatReport = PerformanceService::report(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $flockIds[1],
        $dateOne,
        $dateFour,
        new DateTimeZone('UTC')
    );
    $assert(!$meatReport['is_layer'] && $meatReport['eggs'] === [] && $meatReport['targets'] === [], 'ne crée ni relevé de ponte ni objectif implicite pour un élevage de chair');
    $operatorReport = PerformanceService::report(
        $pdo,
        $userIds[2],
        $farmIds[0],
        $flockIds[0],
        $dateOne,
        $dateFour,
        new DateTimeZone('UTC')
    );
    $assert(count($operatorReport['weights']) === 3, 'autorise la consultation aux membres disposant du droit de lecture');
    $expectFailure(
        static fn () => PerformanceService::report(
            $pdo,
            $userIds[1],
            $farmIds[0],
            $flockIds[0],
            $dateOne,
            $dateFour,
            new DateTimeZone('UTC')
        ),
        'isole les mesures d’une exploitation des autres utilisateurs'
    );
    $expectFailure(
        static fn () => PerformanceService::saveEggRecord(
            $pdo,
            $userIds[0],
            $farmIds[0],
            $flockIds[1],
            $eggValuesOne,
            null,
            $today
        ),
        'réserve les relevés d’œufs au type pondeuses'
    );
} finally {
    foreach ($farmIds as $farmId) {
        $pdo->prepare('DELETE FROM flock_egg_records WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM flock_weighings WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM flock_performance_targets WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM animal_movements WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM daily_followups WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM flocks WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM farm_members WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM farms WHERE id = :farm_id')->execute(['farm_id' => $farmId]);
    }
    foreach ($userIds as $userId) {
        $pdo->prepare('DELETE FROM users WHERE id = :user_id')->execute(['user_id' => $userId]);
    }
}

printf("OK — %d assertions du module de performances.\n", $passed);
