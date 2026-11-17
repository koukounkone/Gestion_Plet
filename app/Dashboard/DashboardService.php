<?php
declare(strict_types=1);

namespace MonElevage\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use MonElevage\Finance\FinanceService;

final class DashboardService
{
    private const POULTRY_TYPES = [
        'chicken',
        'turkey',
        'duck',
        'quail',
        'guinea_fowl',
        'goose',
        'pigeon',
        'other',
    ];
    private const PRODUCTION_TYPES = ['meat', 'eggs', 'breeding', 'other'];

    public static function load(
        PDO $pdo,
        int $farmId,
        DateTimeImmutable $today,
        bool $canViewFinancials,
        ?int $userId = null,
        bool $canViewFlocks = true,
        bool $canViewSales = true
    ): array {
        $farmStatement = $pdo->prepare(
            'SELECT id, name, currency_code, timezone
             FROM farms
             WHERE id = :farm_id
             LIMIT 1'
        );
        $farmStatement->execute(['farm_id' => $farmId]);
        $farm = $farmStatement->fetch();
        if (!is_array($farm)) {
            throw new RuntimeException('Exploitation introuvable.');
        }

        if (!$canViewFlocks) {
            $result = [
                'farm' => $farm,
                'flocks' => [],
                'summary' => [
                    'ongoing_flock_count' => 0,
                    'live_count' => 0,
                    'mortality_count' => 0,
                    'latest_sick_count' => 0,
                ],
                'forecast_dates' => [],
                'upcoming_steps' => [],
                'cycle_alerts' => [],
                'mortality_trend' => [],
                'operations' => [],
                'health_reporting_available' => false,
                'can_manage_cycles' => false,
                'can_view_flocks' => false,
                'financials' => null,
            ];
            if ($canViewFinancials) {
                $result['financials'] = self::financials($pdo, $farmId);
                $result['financials']['previsional_budgets'] = $userId === null
                    ? []
                    : FinanceService::budgets($pdo, $userId, $farmId);
            }
            return $result;
        }

        $flockStatement = $pdo->prepare(
            "SELECT f.id, f.code, f.name, f.flock_type, f.currency_code,
                    f.budget_amount, f.poultry_type, f.production_type,
                    f.started_on, f.initial_count, f.status,
                    COALESCE(c.target_cycle_days, defaults.target_cycle_days) AS target_cycle_days,
                    COALESCE(movement_summary.quantity_delta, 0) AS quantity_delta,
                    COALESCE(movement_summary.mortality_count, 0) AS mortality_count,
                    COALESCE(followup_summary.followup_count, 0) AS followup_count,
                    (SELECT d.observed_count
                     FROM daily_followups d
                     WHERE d.farm_id = f.farm_id
                       AND d.flock_id = f.id
                       AND d.observed_count IS NOT NULL
                     ORDER BY d.followup_date DESC
                     LIMIT 1) AS latest_observed_count,
                    (SELECT d.sick_count
                     FROM daily_followups d
                     WHERE d.farm_id = f.farm_id
                      AND d.flock_id = f.id
                     ORDER BY d.followup_date DESC
                     LIMIT 1) AS latest_sick_count,
                    (SELECT d.followup_date
                     FROM daily_followups d
                     WHERE d.farm_id = f.farm_id
                      AND d.flock_id = f.id
                     ORDER BY d.followup_date DESC
                     LIMIT 1) AS latest_followup_date,
                    COALESCE(sale_summary.sale_count, 0) AS sale_count
             FROM flocks f
             LEFT JOIN farm_cycle_settings c
               ON c.farm_id = f.farm_id
              AND c.poultry_type = f.poultry_type
              AND c.production_type = f.production_type
             LEFT JOIN system_calendar_defaults defaults
               ON defaults.poultry_type = f.poultry_type
              AND defaults.production_type = f.production_type
             LEFT JOIN (
                 SELECT m.farm_id, m.flock_id,
                        SUM(m.quantity_delta) AS quantity_delta,
                        SUM(CASE WHEN m.movement_type = 'mortality'
                            AND NOT EXISTS (
                                SELECT 1
                                FROM animal_movements reversal
                                WHERE reversal.farm_id = m.farm_id
                                  AND reversal.reversal_of_id = m.id
                            )
                            THEN -m.quantity_delta ELSE 0 END) AS mortality_count
                 FROM animal_movements m
                 WHERE m.farm_id = :movement_farm_id
                 GROUP BY m.farm_id, m.flock_id
             ) movement_summary
               ON movement_summary.farm_id = f.farm_id
              AND movement_summary.flock_id = f.id
             LEFT JOIN (
                 SELECT farm_id, flock_id, COUNT(*) AS followup_count
                 FROM daily_followups
                 WHERE farm_id = :followup_farm_id
                 GROUP BY farm_id, flock_id
             ) followup_summary
               ON followup_summary.farm_id = f.farm_id
              AND followup_summary.flock_id = f.id
             LEFT JOIN (
                 SELECT sl.farm_id, sl.flock_id, COUNT(DISTINCT s.id) AS sale_count
                 FROM sale_lines sl
                 INNER JOIN sales s
                   ON s.farm_id = sl.farm_id
                  AND s.id = sl.sale_id
                  AND s.status = 'posted'
                 WHERE sl.farm_id = :sale_farm_id
                   AND sl.source_type = 'animal'
                 GROUP BY sl.farm_id, sl.flock_id
             ) sale_summary
               ON sale_summary.farm_id = f.farm_id
              AND sale_summary.flock_id = f.id
             WHERE f.farm_id = :farm_id
               AND f.status IN ('active', 'paused')
             ORDER BY f.started_on, f.id"
        );
        $flockStatement->execute([
            'movement_farm_id' => $farmId,
            'followup_farm_id' => $farmId,
            'sale_farm_id' => $farmId,
            'farm_id' => $farmId,
        ]);

        $flocks = [];
        $totalLiveCount = 0;
        $totalMortalityCount = 0;
        $totalLatestSickCount = 0;
        $forecastDates = [];
        $upcomingSteps = [];
        $cycleAlerts = [];
        $mortalityTrend = [];
        $stageStatement = $pdo->prepare(
            "SELECT s.id, s.flock_id, s.title, s.planned_on, s.completed_on,
                    s.status, s.priority, f.name AS flock_name
             FROM flock_stages s
             INNER JOIN flocks f ON f.farm_id = s.farm_id AND f.id = s.flock_id
             WHERE s.farm_id = :farm_id
               AND f.status IN ('active', 'paused')
             ORDER BY s.planned_on, s.id"
        );
        $stageStatement->execute(['farm_id' => $farmId]);
        $stagesByFlock = [];
        foreach ($stageStatement->fetchAll() as $stageRow) {
            $flockId = (int) $stageRow['flock_id'];
            $isOverdueStage = $stageRow['status'] !== 'completed'
                && (string) $stageRow['planned_on'] < $today->format('Y-m-d');
            $stage = [
                'id' => (int) $stageRow['id'],
                'label' => (string) $stageRow['title'],
                'date' => new DateTimeImmutable((string) $stageRow['planned_on'], new DateTimeZone('UTC')),
                'completed_on' => $stageRow['completed_on'] === null
                    ? null
                    : new DateTimeImmutable((string) $stageRow['completed_on'], new DateTimeZone('UTC')),
                'status' => (string) $stageRow['status'],
                'priority' => (string) $stageRow['priority'],
                'state' => $stageRow['status'] === 'completed'
                    ? 'completed'
                    : ($stageRow['status'] === 'in_progress' ? 'current' : 'upcoming'),
                'overdue' => $isOverdueStage,
            ];
            $stagesByFlock[$flockId][] = $stage;
            if ($stageRow['status'] !== 'completed') {
                $upcomingSteps[] = [
                    'flock_id' => $flockId,
                    'flock_name' => (string) $stageRow['flock_name'],
                    'stage_id' => (int) $stageRow['id'],
                    'label' => (string) $stageRow['title'],
                    'date' => $stage['date'],
                    'status' => (string) $stageRow['status'],
                    'priority' => (string) $stageRow['priority'],
                    'overdue' => $isOverdueStage,
                ];
            }
        }

        foreach ($flockStatement->fetchAll() as $row) {
            $startedOn = new DateTimeImmutable((string) $row['started_on'], new DateTimeZone('UTC'));
            $liveCount = (int) $row['initial_count'] + (int) $row['quantity_delta'];
            $mortalityCount = (int) $row['mortality_count'];
            $latestSickCount = $row['latest_sick_count'] === null
                ? null
                : (int) $row['latest_sick_count'];
            $targetDays = $row['target_cycle_days'] === null
                ? null
                : (int) $row['target_cycle_days'];
            $ageDays = max(0, (int) $startedOn->diff($today)->format('%r%a'));
            $isPaused = $row['status'] === 'paused';
            $stages = $stagesByFlock[(int) $row['id']] ?? [];
            $completedStages = count(array_filter(
                $stages,
                static fn (array $stage): bool => $stage['status'] === 'completed'
            ));
            $progress = $stages === []
                ? null
                : (int) floor($completedStages * 100 / count($stages));
            $expectedSaleDate = $targetDays === null || $isPaused
                ? null
                : $startedOn->modify(sprintf('+%d days', $targetDays));
            $isOverdue = $expectedSaleDate !== null
                && $expectedSaleDate < $today->setTime(0, 0)
                && !$isPaused;

            if ($targetDays === null) {
                $cycleAlerts[] = [
                    'flock_name' => (string) $row['name'],
                    'poultry_type' => (string) $row['poultry_type'],
                    'production_type' => (string) $row['production_type'],
                ];
            }
            if ($liveCount < 0) {
                $cycleAlerts[] = [
                    'flock_name' => (string) $row['name'],
                    'data_inconsistency' => true,
                ];
            }
            if ($row['latest_observed_count'] !== null
                && (string) $row['latest_followup_date'] === $today->format('Y-m-d')
                && (int) $row['latest_observed_count'] !== $liveCount) {
                $cycleAlerts[] = [
                    'flock_name' => (string) $row['name'],
                    'data_inconsistency' => true,
                    'movement_count' => $liveCount,
                    'observed_count' => (int) $row['latest_observed_count'],
                ];
            }

            $flock = [
                'id' => (int) $row['id'],
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'flock_type' => (string) $row['flock_type'],
                'currency_code' => (string) $row['currency_code'],
                'budget_amount' => $row['budget_amount'] === null
                    ? null
                    : (string) $row['budget_amount'],
                'poultry_type' => (string) $row['poultry_type'],
                'production_type' => (string) $row['production_type'],
                'status' => (string) $row['status'],
                'started_on' => $startedOn,
                'age_days' => $ageDays,
                'live_count' => $liveCount,
                'mortality_count' => $mortalityCount,
                'latest_sick_count' => $latestSickCount,
                'latest_followup_date' => $row['latest_followup_date'] === null
                    ? null
                    : (string) $row['latest_followup_date'],
                'followup_count' => (int) $row['followup_count'],
                'sale_count' => (int) $row['sale_count'],
                'target_cycle_days' => $targetDays,
                'progress' => $progress,
                'completed_stage_count' => $completedStages,
                'applicable_stage_count' => count($stages),
                'expected_sale_date' => $expectedSaleDate,
                'is_overdue' => $isOverdue,
                'steps' => $stages,
            ];
            $flocks[] = $flock;
            $totalLiveCount += $liveCount;
            $totalMortalityCount += $mortalityCount;
            $totalLatestSickCount += $latestSickCount ?? 0;

            if ($expectedSaleDate !== null && !$isPaused) {
                $forecastDates[] = $flock;
            }
        }

        $firstTrendDay = $today->modify('first day of this month')->modify('-5 months');
        $trendStatement = $pdo->prepare(
            "SELECT DATE_FORMAT(m.occurred_at, '%Y-%m') AS month_key,
                    SUM(-m.quantity_delta) AS mortality_count
             FROM animal_movements m
             INNER JOIN flocks f
               ON f.farm_id = m.farm_id AND f.id = m.flock_id
             WHERE m.farm_id = :farm_id
               AND f.status IN ('active', 'paused')
               AND m.movement_type = 'mortality'
               AND m.occurred_at >= :first_day
               AND NOT EXISTS (
                   SELECT 1
                   FROM animal_movements reversal
                   WHERE reversal.farm_id = m.farm_id
                     AND reversal.reversal_of_id = m.id
               )
             GROUP BY DATE_FORMAT(m.occurred_at, '%Y-%m')
             ORDER BY month_key"
        );
        $trendStatement->execute([
            'farm_id' => $farmId,
            'first_day' => $firstTrendDay->format('Y-m-d 00:00:00'),
        ]);
        foreach ($trendStatement->fetchAll() as $row) {
            $mortalityTrend[(string) $row['month_key']] = (int) $row['mortality_count'];
        }

        $operationQueries = [
            "SELECT m.occurred_at AS operation_at,
                        CASE m.movement_type
                            WHEN 'entry' THEN 'Entrée d’animaux'
                            WHEN 'mortality' THEN 'Mortalité enregistrée'
                            WHEN 'loss' THEN 'Perte enregistrée'
                            WHEN 'sale' THEN 'Vente d’animaux'
                            WHEN 'transfer' THEN 'Transfert'
                            ELSE 'Ajustement d’effectif'
                        END AS operation,
                        f.name AS flock_name,
                        CONCAT(ABS(m.quantity_delta), ' animal(aux)') AS detail
                 FROM animal_movements m
                 INNER JOIN flocks f
                   ON f.farm_id = m.farm_id AND f.id = m.flock_id
                 WHERE m.farm_id = :movement_farm_id
                   AND m.daily_followup_id IS NULL",
        ];
        $operationParameters = ['movement_farm_id' => $farmId];
        if ($canViewFinancials) {
            $operationQueries[] =
                "SELECT CONCAT(e.incurred_on, ' 00:00:00'),
                        'Dépense enregistrée',
                        COALESCE(f.name, 'Exploitation'),
                        e.description
                 FROM expenses e
                 LEFT JOIN flocks f ON f.farm_id = e.farm_id AND f.id = e.flock_id
                 WHERE e.farm_id = :expense_farm_id AND e.status = 'posted'";
            $operationParameters['expense_farm_id'] = $farmId;
        }
        if ($canViewSales) {
            $operationQueries[] =
                "SELECT s.sold_at,
                        'Vente enregistrée',
                        COALESCE(c.name, 'Client non renseigné'),
                        CONCAT(COUNT(sl.id), ' ligne(s)')
                 FROM sales s
                 LEFT JOIN clients c ON c.farm_id = s.farm_id AND c.id = s.client_id
                 LEFT JOIN sale_lines sl ON sl.farm_id = s.farm_id AND sl.sale_id = s.id
                 WHERE s.farm_id = :sales_farm_id AND s.status = 'posted'
                 GROUP BY s.id, s.sold_at, c.name";
            $operationParameters['sales_farm_id'] = $farmId;
        }
        $operationQueries[] =
                "SELECT CONCAT(d.followup_date, ' 00:00:00'),
                        'Suivi quotidien enregistré',
                        f.name,
                        COALESCE(NULLIF(d.notes, ''), 'Observation quotidienne')
                 FROM daily_followups d
                 INNER JOIN flocks f ON f.farm_id = d.farm_id AND f.id = d.flock_id
                 WHERE d.farm_id = :followup_farm_id";
        $operationParameters['followup_farm_id'] = $farmId;
        $operationQueries[] =
                "SELECT e.event_at,
                        CASE e.event_type
                            WHEN 'created' THEN 'Élevage créé'
                            WHEN 'activated' THEN 'Élevage activé'
                            WHEN 'paused' THEN 'Élevage mis en pause'
                            WHEN 'resumed' THEN 'Élevage repris'
                            WHEN 'closed' THEN 'Élevage clôturé'
                            ELSE 'Élevage rouvert'
                        END,
                        f.name,
                        e.resulting_status
                 FROM flock_lifecycle_events e
                 INNER JOIN flocks f ON f.farm_id = e.farm_id AND f.id = e.flock_id
                 WHERE e.farm_id = :lifecycle_farm_id";
        $operationParameters['lifecycle_farm_id'] = $farmId;
        $operationStatement = $pdo->prepare(
            'SELECT operation_at, operation, flock_name, detail
             FROM (' . implode(' UNION ALL ', $operationQueries) . ') operations
             ORDER BY operation_at DESC
             LIMIT 8'
        );
        $operationStatement->execute($operationParameters);
        $operations = $operationStatement->fetchAll();

        usort(
            $forecastDates,
            static fn (array $left, array $right): int =>
                $left['expected_sale_date'] <=> $right['expected_sale_date']
        );
        usort(
            $upcomingSteps,
            static fn (array $left, array $right): int =>
                ($right['overdue'] <=> $left['overdue'])
                ?: ($left['date'] <=> $right['date'])
        );
        ksort($mortalityTrend);

        $result = [
            'farm' => $farm,
            'flocks' => $flocks,
            'summary' => [
                'ongoing_flock_count' => count($flocks),
                'live_count' => $totalLiveCount,
                'mortality_count' => $totalMortalityCount,
                'latest_sick_count' => $totalLatestSickCount,
            ],
            'forecast_dates' => $forecastDates,
            'upcoming_steps' => array_slice($upcomingSteps, 0, 6),
            'cycle_alerts' => $cycleAlerts,
            'mortality_trend' => $mortalityTrend,
            'operations' => $operations,
            'health_reporting_available' => false,
            'can_manage_cycles' => false,
            'can_view_flocks' => true,
            'financials' => null,
        ];

        if ($canViewFinancials) {
            $result['financials'] = self::financials($pdo, $farmId);
            $result['financials']['previsional_budgets'] = $userId === null
                ? []
                : FinanceService::budgets($pdo, $userId, $farmId);
        }

        return $result;
    }

    public static function saveCycleSetting(
        PDO $pdo,
        int $farmId,
        int $userId,
        string $poultryType,
        string $productionType,
        int $targetCycleDays
    ): void {
        if (!in_array($poultryType, self::POULTRY_TYPES, true)
            || !in_array($productionType, self::PRODUCTION_TYPES, true)) {
            throw new RuntimeException('Type d’élevage invalide.');
        }
        if ($targetCycleDays < 1 || $targetCycleDays > 2000) {
            throw new RuntimeException('La durée estimée doit être comprise entre 1 et 2 000 jours.');
        }

        $statement = $pdo->prepare(
            'INSERT INTO farm_cycle_settings
                (farm_id, poultry_type, production_type, target_cycle_days, updated_by_user_id)
             VALUES
                (:farm_id, :poultry_type, :production_type, :target_cycle_days, :user_id)
             ON DUPLICATE KEY UPDATE
                target_cycle_days = VALUES(target_cycle_days),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = UTC_TIMESTAMP()'
        );
        $statement->execute([
            'farm_id' => $farmId,
            'poultry_type' => $poultryType,
            'production_type' => $productionType,
            'target_cycle_days' => $targetCycleDays,
            'user_id' => $userId,
        ]);
    }

    private static function financials(PDO $pdo, int $farmId): array
    {
        $statement = $pdo->prepare(
            "SELECT currency_code, SUM(amount) AS total_amount, COUNT(*) AS expense_count
             FROM expenses
             WHERE farm_id = :farm_id AND status = 'posted'
             GROUP BY currency_code
             ORDER BY currency_code"
        );
        $statement->execute(['farm_id' => $farmId]);
        $expenses = [];
        foreach ($statement->fetchAll() as $row) {
            $expenses[] = [
                'currency_code' => (string) $row['currency_code'],
                'total_amount' => (string) $row['total_amount'],
                'expense_count' => (int) $row['expense_count'],
            ];
        }

        $budgetStatement = $pdo->prepare(
            "SELECT f.currency_code,
                    SUM(f.budget_amount) AS total_budget,
                    SUM(COALESCE(expense_summary.spent_amount, 0)) AS spent_amount,
                    SUM(f.budget_amount - COALESCE(expense_summary.spent_amount, 0)) AS remaining_amount,
                    COUNT(*) AS flock_count
             FROM flocks f
             LEFT JOIN (
                 SELECT farm_id, flock_id, currency_code, SUM(amount) AS spent_amount
                 FROM expenses
                 WHERE farm_id = :expense_farm_id AND status = 'posted'
                 GROUP BY farm_id, flock_id, currency_code
             ) expense_summary
               ON expense_summary.farm_id = f.farm_id
              AND expense_summary.flock_id = f.id
              AND expense_summary.currency_code = f.currency_code
             WHERE f.farm_id = :farm_id
               AND f.status IN ('active', 'paused')
               AND f.budget_amount IS NOT NULL
             GROUP BY f.currency_code
             ORDER BY f.currency_code"
        );
        $budgetStatement->execute([
            'expense_farm_id' => $farmId,
            'farm_id' => $farmId,
        ]);
        $budgets = [];
        foreach ($budgetStatement->fetchAll() as $row) {
            $budgets[] = [
                'currency_code' => (string) $row['currency_code'],
                'total_budget' => (string) $row['total_budget'],
                'spent_amount' => (string) $row['spent_amount'],
                'remaining_amount' => (string) $row['remaining_amount'],
                'flock_count' => (int) $row['flock_count'],
            ];
        }

        return [
            'expenses' => $expenses,
            'budgets' => $budgets,
            'budget_available' => $budgets !== [],
        ];
    }
}
