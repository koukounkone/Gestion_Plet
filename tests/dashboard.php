<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\FarmAccess;
use MonElevage\Dashboard\DashboardService;
use MonElevage\Database\Connection;

$pdo = Connection::get($config);
$today = new DateTimeImmutable('2026-10-11', new DateTimeZone('UTC'));
$suffix = bin2hex(random_bytes(5));
$email = 'dashboard-test-' . $suffix . '@example.test';
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

$userId = 0;
$farmIds = [];
$flockIds = [];
$pdo->beginTransaction();

try {
    $user = $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, status, email_verified_at)
         VALUES ('Dashboard Test', :email, 'test-hash', 'active', UTC_TIMESTAMP())"
    );
    $user->execute(['email' => $email]);
    $userId = (int) $pdo->lastInsertId();

    foreach (['Ferme dashboard', 'Ferme vide', 'Ferme calendrier global'] as $farmName) {
        $farm = $pdo->prepare(
            'INSERT INTO farms (owner_user_id, name, currency_code, timezone)
             VALUES (:user_id, :name, :currency_code, :timezone)'
        );
        $farm->execute([
            'user_id' => $userId,
            'name' => $farmName,
            'currency_code' => 'XOF',
            'timezone' => 'UTC',
        ]);
        $farmIds[] = (int) $pdo->lastInsertId();
    }

    $membership = $pdo->prepare(
        "INSERT INTO farm_members (farm_id, user_id, role, status)
         VALUES (:farm_id, :user_id, :role, 'active')"
    );
    $membership->execute(['farm_id' => $farmIds[0], 'user_id' => $userId, 'role' => 'owner']);
    $membership->execute(['farm_id' => $farmIds[1], 'user_id' => $userId, 'role' => 'operator']);
    $membership->execute(['farm_id' => $farmIds[2], 'user_id' => $userId, 'role' => 'operator']);

    $cycleSetting = $pdo->prepare(
        'INSERT INTO farm_cycle_settings
            (farm_id, poultry_type, production_type, target_cycle_days, updated_by_user_id)
         VALUES (:farm_id, :poultry_type, :production_type, :target_cycle_days, :user_id)'
    );
    $cycleSetting->execute([
        'farm_id' => $farmIds[0],
        'poultry_type' => 'chicken',
        'production_type' => 'meat',
        'target_cycle_days' => 40,
        'user_id' => $userId,
    ]);

    $createFlock = $pdo->prepare(
        "INSERT INTO flocks
            (farm_id, code, name, poultry_type, production_type, started_on,
             initial_count, status, created_by_user_id)
         VALUES
            (:farm_id, :code, :name, :poultry_type, :production_type,
             :started_on, :initial_count, :status, :user_id)"
    );
    $flockData = [
        ['A-001', 'Bande active A', 'chicken', 'meat', '2026-09-21', 100, 'active'],
        ['A-002', 'Bande active B', 'chicken', 'meat', '2026-10-01', 50, 'active'],
        ['A-003', 'Bande en pause', 'turkey', 'meat', '2026-10-06', 20, 'paused'],
    ];
    foreach ($flockData as [$code, $name, $poultry, $production, $startedOn, $count, $status]) {
        $createFlock->execute([
            'farm_id' => $farmIds[0],
            'code' => $code . '-' . $suffix,
            'name' => $name,
            'poultry_type' => $poultry,
            'production_type' => $production,
            'started_on' => $startedOn,
            'initial_count' => $count,
            'status' => $status,
            'user_id' => $userId,
        ]);
        $flockIds[] = (int) $pdo->lastInsertId();
    }
    $createFlock->execute([
        'farm_id' => $farmIds[2],
        'code' => 'B-004-' . $suffix,
        'name' => 'Bande avec calendrier global',
        'poultry_type' => 'chicken',
        'production_type' => 'meat',
        'started_on' => '2026-10-01',
        'initial_count' => 10,
        'status' => 'active',
        'user_id' => $userId,
    ]);
    $flockIds[] = (int) $pdo->lastInsertId();

    $movement = $pdo->prepare(
        'INSERT INTO animal_movements
            (farm_id, flock_id, movement_type, quantity_delta, occurred_at, reversal_of_id, reason, created_by_user_id)
         VALUES
            (:farm_id, :flock_id, :movement_type, :quantity_delta, :occurred_at, :reversal_of_id, :reason, :user_id)'
    );
    $movement->execute([
        'farm_id' => $farmIds[0],
        'flock_id' => $flockIds[0],
        'movement_type' => 'mortality',
        'quantity_delta' => -5,
        'occurred_at' => '2026-10-02 08:00:00',
        'reversal_of_id' => null,
        'reason' => 'Test de mortalité corrigé',
        'user_id' => $userId,
    ]);
    $reversedMortalityId = (int) $pdo->lastInsertId();
    $movement->execute([
        'farm_id' => $farmIds[0],
        'flock_id' => $flockIds[0],
        'movement_type' => 'adjustment',
        'quantity_delta' => 5,
        'occurred_at' => '2026-10-03 08:00:00',
        'reversal_of_id' => $reversedMortalityId,
        'reason' => 'Correction du test',
        'user_id' => $userId,
    ]);
    $movement->execute([
        'farm_id' => $farmIds[0],
        'flock_id' => $flockIds[0],
        'movement_type' => 'mortality',
        'quantity_delta' => -3,
        'occurred_at' => '2026-10-04 08:00:00',
        'reversal_of_id' => null,
        'reason' => 'Mortalité test',
        'user_id' => $userId,
    ]);
    $movement->execute([
        'farm_id' => $farmIds[0],
        'flock_id' => $flockIds[0],
        'movement_type' => 'entry',
        'quantity_delta' => 2,
        'occurred_at' => '2026-10-05 08:00:00',
        'reversal_of_id' => null,
        'reason' => 'Complément de test',
        'user_id' => $userId,
    ]);
    $movement->execute([
        'farm_id' => $farmIds[0],
        'flock_id' => $flockIds[1],
        'movement_type' => 'entry',
        'quantity_delta' => 5,
        'occurred_at' => '2026-10-06 08:00:00',
        'reversal_of_id' => null,
        'reason' => 'Entrée test',
        'user_id' => $userId,
    ]);

    $followup = $pdo->prepare(
        'INSERT INTO daily_followups
            (farm_id, flock_id, followup_date, observed_count, notes, created_by_user_id)
         VALUES (:farm_id, :flock_id, :followup_date, :observed_count, :notes, :user_id)'
    );
    $followup->execute([
        'farm_id' => $farmIds[0],
        'flock_id' => $flockIds[0],
        'followup_date' => '2026-10-10',
        'observed_count' => 99,
        'notes' => 'Observation de test',
        'user_id' => $userId,
    ]);

    $expense = $pdo->prepare(
        "INSERT INTO expenses
            (farm_id, flock_id, incurred_on, category, description, amount,
             currency_code, status, created_by_user_id, voided_at, voided_by_user_id, void_reason)
         VALUES
            (:farm_id, :flock_id, :incurred_on, 'feed', :description, :amount,
             :currency_code, :status, :user_id, :voided_at, :voided_by_user_id, :void_reason)"
    );
    $expense->execute([
        'farm_id' => $farmIds[0],
        'flock_id' => $flockIds[0],
        'incurred_on' => '2026-10-07',
        'description' => 'Aliment test comptabilisé',
        'amount' => 1200,
        'currency_code' => 'XOF',
        'status' => 'posted',
        'user_id' => $userId,
        'voided_at' => null,
        'voided_by_user_id' => null,
        'void_reason' => null,
    ]);
    $expense->execute([
        'farm_id' => $farmIds[0],
        'flock_id' => null,
        'incurred_on' => '2026-10-08',
        'description' => 'Dépense étrangère comptabilisée',
        'amount' => 500,
        'currency_code' => 'USD',
        'status' => 'posted',
        'user_id' => $userId,
        'voided_at' => null,
        'voided_by_user_id' => null,
        'void_reason' => null,
    ]);
    $expense->execute([
        'farm_id' => $farmIds[0],
        'flock_id' => $flockIds[0],
        'incurred_on' => '2026-10-09',
        'description' => 'Dépense test annulée',
        'amount' => 700,
        'currency_code' => 'XOF',
        'status' => 'void',
        'user_id' => $userId,
        'voided_at' => '2026-10-09 10:00:00',
        'voided_by_user_id' => $userId,
        'void_reason' => 'Annulation de test',
    ]);

    $client = $pdo->prepare(
        'INSERT INTO clients (farm_id, name, created_by_user_id)
         VALUES (:farm_id, :name, :user_id)'
    );
    $client->execute([
        'farm_id' => $farmIds[0],
        'name' => 'Client dashboard test',
        'user_id' => $userId,
    ]);
    $clientId = (int) $pdo->lastInsertId();
    $sale = $pdo->prepare(
        "INSERT INTO sales (farm_id, client_id, sold_at, currency_code, status, created_by_user_id)
         VALUES (:farm_id, :client_id, '2026-10-09 13:00:00', 'XOF', 'posted', :user_id)"
    );
    $sale->execute([
        'farm_id' => $farmIds[0],
        'client_id' => $clientId,
        'user_id' => $userId,
    ]);
    $saleId = (int) $pdo->lastInsertId();
    $saleLine = $pdo->prepare(
        "INSERT INTO sale_lines
            (farm_id, sale_id, source_type, flock_id, quantity, unit_price, description)
         VALUES (:farm_id, :sale_id, 'animal', :flock_id, 2, 10, 'Vente de test')"
    );
    $saleLine->execute([
        'farm_id' => $farmIds[0],
        'sale_id' => $saleId,
        'flock_id' => $flockIds[0],
    ]);

    $stage = $pdo->prepare(
        'INSERT INTO flock_stages
            (farm_id, flock_id, title, planned_on, completed_on, status, priority, created_by_user_id)
         VALUES
            (:farm_id, :flock_id, :title, :planned_on, :completed_on, :status, :priority, :user_id)'
    );
    foreach ([
        ['Étape terminée', '2026-10-02', '2026-10-03', 'completed', 'normal'],
        ['Étape en cours', '2026-10-11', null, 'in_progress', 'high'],
        ['Échéance dépassée', '2026-10-10', null, 'planned', 'urgent'],
    ] as [$title, $plannedOn, $completedOn, $status, $priority]) {
        $stage->execute([
            'farm_id' => $farmIds[0],
            'flock_id' => $flockIds[0],
            'title' => $title,
            'planned_on' => $plannedOn,
            'completed_on' => $completedOn,
            'status' => $status,
            'priority' => $priority,
            'user_id' => $userId,
        ]);
    }

    $dashboard = DashboardService::load($pdo, $farmIds[0], $today, true);
    $assert($dashboard['summary']['ongoing_flock_count'] === 3, 'compte les élevages actifs et en pause');
    $assert($dashboard['summary']['live_count'] === 174, 'calcule les effectifs depuis les mouvements nets');
    $assert($dashboard['summary']['mortality_count'] === 3, 'exclut une mortalité corrigée du total net');
    $assert($dashboard['flocks'][0]['live_count'] === 99, 'calcule le premier effectif vivant');
    $assert($dashboard['flocks'][0]['progress'] === 33, 'calcule la progression uniquement depuis les étapes terminées');
    $assert(
        $dashboard['flocks'][0]['expected_sale_date']->format('Y-m-d') === '2026-10-31',
        'calcule la date estimative depuis le démarrage et le cycle'
    );
    $assert($dashboard['flocks'][0]['steps'][2]['state'] === 'current', 'identifie l’étape explicitement en cours');
    $assert($dashboard['flocks'][0]['steps'][1]['overdue'], 'identifie une étape passée non terminée comme en retard');
    $assert($dashboard['upcoming_steps'][0]['overdue'], 'place les retards avant les prochaines échéances');
    $assert($dashboard['flocks'][1]['progress'] === null, 'ne simule pas de progression sans calendrier enregistré');
    $calendarDefault = $pdo->query(
        "SELECT target_cycle_days FROM system_calendar_defaults
         WHERE poultry_type = 'chicken' AND production_type = 'meat'"
    )->fetchColumn();
    $dashboardWithoutFarmOverride = DashboardService::load($pdo, $farmIds[2], $today, true);
    $assert(
        $dashboardWithoutFarmOverride['flocks'][0]['target_cycle_days'] === (int) $calendarDefault,
        'utilise le calendrier global sans durée spécifique à la ferme'
    );
    $assert($dashboard['flocks'][2]['progress'] === null, 'ne fait pas progresser un élevage en pause');
    $assert($dashboard['flocks'][2]['expected_sale_date'] === null, 'ne projette pas une vente pour un élevage en pause');
    $assert(
        $dashboard['financials']['expenses'] === [
            ['currency_code' => 'USD', 'total_amount' => '500.00', 'expense_count' => 1],
            ['currency_code' => 'XOF', 'total_amount' => '1200.00', 'expense_count' => 1],
        ],
        'additionne les dépenses comptabilisées par devise et exclut les dépenses annulées'
    );
    $assert(
        $dashboard['financials']['budget_available'] === false
            && $dashboard['financials']['budgets'] === [],
        'indique qu’aucun budget d’élevage n’est défini dans les fixtures'
    );
    $assert($dashboard['flocks'][0]['sale_count'] === 1, 'compte une vente confirmée pour l’élevage');
    $assert(
        count(array_filter(
            $dashboard['operations'],
            static fn (array $operation): bool => $operation['operation'] === 'Vente enregistrée'
        )) === 1,
        'montre une vente dans les dernières opérations'
    );
    $assert(
        count(array_filter(
            $dashboard['operations'],
            static fn (array $operation): bool => $operation['detail'] === 'Dépense test annulée'
        )) === 0,
        'exclut les opérations financières annulées du journal'
    );

    $emptyDashboard = DashboardService::load($pdo, $farmIds[1], $today, false);
    $assert($emptyDashboard['flocks'] === [], 'gère une exploitation sans élevage');
    $assert($emptyDashboard['summary']['live_count'] === 0, 'affiche un effectif nul pour une exploitation vide');
    $assert($emptyDashboard['forecast_dates'] === [], 'ne crée aucune prévision sans élevage');
    $assert($emptyDashboard['financials'] === null, 'ne charge pas les finances pour un rôle sans permission');
    $assert(
        FarmAccess::allows($pdo, $userId, $farmIds[1], 'flock.view'),
        'reconnaît la permission de consultation de l’opérateur'
    );
    $expectFailure(
        static fn () => FarmAccess::requirePermission($pdo, $userId, $farmIds[1], 'expense.view'),
        'refuse les données financières à l’opérateur'
    );

    $forecastOnly = DashboardService::load($pdo, $farmIds[0], $today, false);
    $assert($forecastOnly['financials'] === null, 'omet les montants pour une vue sans permission financière');
    $assert(
        $forecastOnly['operations'] !== []
            && count(array_filter(
                $forecastOnly['operations'],
                static fn (array $operation): bool => $operation['operation'] === 'Dépense enregistrée'
            )) === 0,
        'masque les opérations financières au rôle non financier'
    );
    $expectFailure(
        static fn () => DashboardService::saveCycleSetting(
            $pdo,
            $farmIds[0],
            $userId,
            'chicken',
            'meat',
            0
        ),
        'rejette une durée de cycle invalide'
    );
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

printf("OK — %d assertions du tableau de bord.\n", $passed);
