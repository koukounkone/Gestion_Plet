<?php
declare(strict_types=1);

namespace MonElevage\Reports;

use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;
use MonElevage\Auth\FarmAccess;
use MonElevage\Finance\FinanceService;

final class ReportService
{
    public static function flocks(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'report.view');
        $statement = $pdo->prepare(
            'SELECT id, name, code, started_on, status
             FROM flocks
             WHERE farm_id = :farm_id
             ORDER BY started_on DESC, name, id'
        );
        $statement->execute(['farm_id' => $farmId]);
        return $statement->fetchAll();
    }

    public static function previewData(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $flockId,
        string $from,
        string $to
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'report.view');
        if (!self::validDate($from) || !self::validDate($to) || $from > $to) {
            throw new RuntimeException('Les dates du rapport sont invalides.');
        }
        $data = self::reportData($pdo, $userId, $farmId, $flockId, $from, $to);
        if ($from < (string) $data['flock']['started_on']) {
            throw new RuntimeException('La période ne peut pas commencer avant la date de début de l’élevage.');
        }
        return $data;
    }

    public static function generate(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $flockId,
        string $from,
        string $to,
        string $observation,
        DateTimeImmutable $generatedAt
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'report.view');
        if (!self::validDate($from) || !self::validDate($to) || $from > $to
            || $to > $generatedAt->format('Y-m-d')) {
            throw new RuntimeException('Les dates du rapport sont invalides.');
        }
        $observation = trim($observation);
        if (mb_strlen($observation, 'UTF-8') > 3000) {
            throw new RuntimeException('Les observations ne peuvent pas dépasser 3 000 caractères.');
        }

        $filePath = null;
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT id FROM farms WHERE id = :farm_id FOR UPDATE');
            $lock->execute(['farm_id' => $farmId]);
            if ($lock->fetchColumn() === false) {
                throw new RuntimeException('Exploitation introuvable.');
            }

            $data = self::previewData($pdo, $userId, $farmId, $flockId, $from, $to);
            $data['observation'] = $observation;
            $data['generated_at'] = $generatedAt->format('Y-m-d H:i:s');

            $versionQuery = $pdo->prepare(
                'SELECT COALESCE(MAX(version_number), 0) + 1
                 FROM farm_reports
                 WHERE farm_id = :farm_id AND created_by_user_id = :user_id
                   AND flock_id = :flock_id AND starts_on = :starts_on AND ends_on = :ends_on'
            );
            $versionQuery->execute([
                'farm_id' => $farmId,
                'user_id' => $userId,
                'flock_id' => $flockId,
                'starts_on' => $from,
                'ends_on' => $to,
            ]);
            $version = (int) $versionQuery->fetchColumn();
            $data['version_number'] = $version;

            $pdf = ReportPdfRenderer::render($data);
            $storageKey = bin2hex(random_bytes(32));
            $filePath = self::storagePath($storageKey);
            self::writePrivateFile($filePath, $pdf);

            $insert = $pdo->prepare(
                'INSERT INTO farm_reports
                    (farm_id, created_by_user_id, flock_id, starts_on, ends_on,
                     version_number, observation, storage_key, file_size_bytes, generated_at)
                 VALUES
                    (:farm_id, :user_id, :flock_id, :starts_on, :ends_on,
                     :version, :observation, :storage_key, :file_size, :generated_at)'
            );
            $insert->execute([
                'farm_id' => $farmId,
                'user_id' => $userId,
                'flock_id' => $flockId,
                'starts_on' => $from,
                'ends_on' => $to,
                'version' => $version,
                'observation' => $observation !== '' ? $observation : null,
                'storage_key' => $storageKey,
                'file_size' => strlen($pdf),
                'generated_at' => $data['generated_at'],
            ]);
            $reportId = (int) $pdo->lastInsertId();
            $pdo->commit();

            return [
                'id' => $reportId,
                'version_number' => $version,
                'starts_on' => $from,
                'ends_on' => $to,
                'file_size_bytes' => strlen($pdf),
                'generated_at' => $data['generated_at'],
            ];
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($filePath !== null && is_file($filePath) && !unlink($filePath)) {
                error_log('Unable to remove an unregistered private report file.');
            }
            throw $exception;
        }
    }

    public static function listReports(
        PDO $pdo,
        int $userId,
        int $farmId,
        ?int $flockId = null,
        ?string $from = null,
        ?string $to = null
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'report.view');
        if (($from !== null && !self::validDate($from))
            || ($to !== null && !self::validDate($to))
            || ($from !== null && $to !== null && $from > $to)) {
            throw new RuntimeException('Les filtres de période des rapports sont invalides.');
        }
        $conditions = [
            'r.farm_id = :farm_id',
            'r.created_by_user_id = :user_id',
        ];
        $parameters = ['farm_id' => $farmId, 'user_id' => $userId];
        if ($flockId !== null) {
            $conditions[] = 'r.flock_id = :flock_id';
            $parameters['flock_id'] = $flockId;
        }
        if ($from !== null) {
            $conditions[] = 'r.ends_on >= :from_date';
            $parameters['from_date'] = $from;
        }
        if ($to !== null) {
            $conditions[] = 'r.starts_on <= :to_date';
            $parameters['to_date'] = $to;
        }
        $statement = $pdo->prepare(
            'SELECT r.id, r.flock_id, f.name AS flock_name, r.starts_on, r.ends_on,
                    r.version_number, r.file_size_bytes, r.generated_at
             FROM farm_reports r
             INNER JOIN flocks f ON f.farm_id = r.farm_id AND f.id = r.flock_id
             WHERE ' . implode(' AND ', $conditions) . '
             ORDER BY r.generated_at DESC, r.id DESC
             LIMIT 100'
        );
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    public static function downloadableFile(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $reportId
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'report.view');
        $statement = $pdo->prepare(
            'SELECT id, starts_on, ends_on, version_number, storage_key, file_size_bytes
             FROM farm_reports
             WHERE id = :id AND farm_id = :farm_id AND created_by_user_id = :user_id
             LIMIT 1'
        );
        $statement->execute([
            'id' => $reportId,
            'farm_id' => $farmId,
            'user_id' => $userId,
        ]);
        $report = $statement->fetch();
        if (!is_array($report)) {
            throw new RuntimeException('Rapport introuvable ou accès refusé.');
        }
        $path = self::storagePath((string) $report['storage_key']);
        if (!is_file($path) || filesize($path) !== (int) $report['file_size_bytes']) {
            throw new RuntimeException('Le fichier PDF privé est indisponible.');
        }
        $report['path'] = $path;
        return $report;
    }

    private static function reportData(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $flockId,
        string $from,
        string $to
    ): array {
        $flockQuery = $pdo->prepare(
            'SELECT f.id, f.code, f.name, f.flock_type, f.poultry_type, f.production_type,
                    f.breed, f.started_on, f.initial_count, f.supplier_name, f.location,
                    f.status, f.notes, farm.name AS farm_name, farm.currency_code,
                    farm.timezone
             FROM flocks f
             INNER JOIN farms farm ON farm.id = f.farm_id
             WHERE f.farm_id = :farm_id AND f.id = :flock_id
             LIMIT 1'
        );
        $flockQuery->execute(['farm_id' => $farmId, 'flock_id' => $flockId]);
        $flock = $flockQuery->fetch();
        if (!is_array($flock)) {
            throw new RuntimeException('Élevage introuvable dans cette exploitation.');
        }
        if ((string) $flock['currency_code'] !== 'XOF') {
            throw new RuntimeException('Les rapports financiers sont disponibles uniquement pour une exploitation en FCFA (XOF).');
        }

        $populationQuery = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN occurred_at < CONCAT(:before_from, ' 00:00:00')
                                  THEN quantity_delta ELSE 0 END), 0) AS movement_before,
                COALESCE(SUM(CASE WHEN occurred_at < DATE_ADD(:through_to, INTERVAL 1 DAY)
                                  THEN quantity_delta ELSE 0 END), 0) AS movement_through,
                COALESCE(SUM(CASE WHEN occurred_at >= CONCAT(:mortality_from, ' 00:00:00')
                                       AND occurred_at < DATE_ADD(:mortality_to, INTERVAL 1 DAY)
                                       AND movement_type = 'mortality'
                                       AND quantity_delta < 0
                                       AND NOT EXISTS (
                                           SELECT 1 FROM animal_movements reversal
                                           WHERE reversal.farm_id = animal_movements.farm_id
                                             AND reversal.reversal_of_id = animal_movements.id
                                       )
                                  THEN -quantity_delta ELSE 0 END), 0) AS mortality_count
             FROM animal_movements
             WHERE farm_id = :farm_id AND flock_id = :flock_id
               AND occurred_at < DATE_ADD(:movement_end, INTERVAL 1 DAY)"
        );
        $populationQuery->execute([
            'before_from' => $from,
            'through_to' => $to,
            'mortality_from' => $from,
            'mortality_to' => $to,
            'farm_id' => $farmId,
            'flock_id' => $flockId,
            'movement_end' => $to,
        ]);
        $population = $populationQuery->fetch();
        $initialCount = max(0, (int) $flock['initial_count'] + (int) $population['movement_before']);
        $finalCount = max(0, (int) $flock['initial_count'] + (int) $population['movement_through']);

        $weightQuery = $pdo->prepare(
            "SELECT measured_on AS measured_date,
                    total_weight_g / sample_size AS average_weight_g,
                    'Pesée' AS source
             FROM flock_weighings
             WHERE farm_id = :weighing_farm_id AND flock_id = :weighing_flock_id
               AND measured_on BETWEEN :weighing_from AND :weighing_to
             UNION ALL
             SELECT followup_date AS measured_date, average_weight_g,
                    'Suivi quotidien' AS source
             FROM daily_followups
             WHERE farm_id = :followup_farm_id AND flock_id = :followup_flock_id
               AND followup_date BETWEEN :followup_from AND :followup_to
               AND average_weight_g IS NOT NULL
             ORDER BY measured_date, source"
        );
        $weightQuery->execute([
            'weighing_farm_id' => $farmId,
            'weighing_flock_id' => $flockId,
            'weighing_from' => $from,
            'weighing_to' => $to,
            'followup_farm_id' => $farmId,
            'followup_flock_id' => $flockId,
            'followup_from' => $from,
            'followup_to' => $to,
        ]);
        $weights = $weightQuery->fetchAll();
        $weightChange = count($weights) >= 2
            ? (float) $weights[count($weights) - 1]['average_weight_g']
                - (float) $weights[0]['average_weight_g']
            : null;

        $feedQuery = $pdo->prepare(
            'SELECT followup_date, feed_consumed_kg
             FROM daily_followups
             WHERE farm_id = :farm_id AND flock_id = :flock_id
               AND followup_date BETWEEN :from_date AND :to_date
               AND feed_consumed_kg IS NOT NULL
             ORDER BY followup_date'
        );
        $feedQuery->execute([
            'farm_id' => $farmId,
            'flock_id' => $flockId,
            'from_date' => $from,
            'to_date' => $to,
        ]);
        $feedRows = $feedQuery->fetchAll();
        $feedTotal = array_sum(array_map(
            static fn (array $row): float => (float) $row['feed_consumed_kg'],
            $feedRows
        ));

        $stockFeedQuery = $pdo->prepare(
            "SELECT pr.name, pr.unit_code, SUM(ABS(sm.quantity_delta)) AS consumed_quantity
             FROM stock_movements sm
             INNER JOIN products pr ON pr.farm_id = sm.farm_id AND pr.id = sm.product_id
             WHERE sm.farm_id = :farm_id AND sm.flock_id = :flock_id
               AND sm.movement_type = 'consumption' AND pr.product_type = 'consumable'
               AND sm.occurred_at >= CONCAT(:from_date, ' 00:00:00')
               AND sm.occurred_at < DATE_ADD(:to_date, INTERVAL 1 DAY)
             GROUP BY pr.id, pr.name, pr.unit_code
             ORDER BY pr.name"
        );
        $stockFeedQuery->execute([
            'farm_id' => $farmId,
            'flock_id' => $flockId,
            'from_date' => $from,
            'to_date' => $to,
        ]);
        $stockFeedRows = $stockFeedQuery->fetchAll();

        $canViewHealth = FarmAccess::allows($pdo, $userId, $farmId, 'health.view');
        $healthRows = [];
        if ($canViewHealth) {
            $healthQuery = $pdo->prepare(
                'SELECT event_type, observed_on, affected_count, symptoms, details,
                        professional_name, prescription_details, next_due_on
                 FROM health_records
                 WHERE farm_id = :farm_id AND flock_id = :flock_id
                   AND observed_on BETWEEN :from_date AND :to_date
                 ORDER BY observed_on, id'
            );
            $healthQuery->execute([
                'farm_id' => $farmId,
                'flock_id' => $flockId,
                'from_date' => $from,
                'to_date' => $to,
            ]);
            $healthRows = $healthQuery->fetchAll();
        }

        $purchaseQuery = $pdo->prepare(
            'SELECT pu.id AS purchase_id, pu.purchased_on, pu.reference,
                    su.name AS supplier_name, pr.name AS product_name,
                    pr.product_type, pr.unit_code, pi.quantity, pi.unit_price,
                    pi.line_total
             FROM purchases pu
             INNER JOIN suppliers su ON su.farm_id = pu.farm_id AND su.id = pu.supplier_id
             INNER JOIN purchase_items pi ON pi.farm_id = pu.farm_id AND pi.purchase_id = pu.id
             INNER JOIN products pr ON pr.farm_id = pi.farm_id AND pr.id = pi.product_id
             WHERE pu.farm_id = :farm_id AND pu.flock_id = :flock_id
               AND pu.currency_code = :currency
               AND pu.purchased_on BETWEEN :from_date AND :to_date
             ORDER BY pu.purchased_on, pu.id, pi.id'
        );
        $purchaseQuery->execute([
            'farm_id' => $farmId,
            'flock_id' => $flockId,
            'currency' => 'XOF',
            'from_date' => $from,
            'to_date' => $to,
        ]);
        $purchases = $purchaseQuery->fetchAll();

        $expenseQuery = $pdo->prepare(
            "SELECT e.category, SUM(e.amount) AS amount
             FROM expenses e
             WHERE e.farm_id = :farm_id AND e.flock_id = :flock_id
               AND e.status = 'posted' AND e.currency_code = 'XOF'
               AND e.incurred_on BETWEEN :from_date AND :to_date
               AND NOT EXISTS (
                   SELECT 1 FROM purchases pu
                   WHERE pu.farm_id = e.farm_id AND pu.expense_id = e.id
               )
             GROUP BY e.category
             ORDER BY e.category"
        );
        $expenseQuery->execute([
            'farm_id' => $farmId,
            'flock_id' => $flockId,
            'from_date' => $from,
            'to_date' => $to,
        ]);
        $expenses = $expenseQuery->fetchAll();

        $salesQuery = $pdo->prepare(
            "SELECT s.id, s.sold_at, s.client_name_snapshot, s.status,
                    sl.source_type, sl.description, sl.quantity, sl.line_total,
                    COALESCE(pr.name, sl.description, 'Produit avicole') AS item_name,
                    (SELECT COALESCE(SUM(all_lines.line_total), 0)
                     FROM sale_lines all_lines
                     WHERE all_lines.farm_id = s.farm_id AND all_lines.sale_id = s.id) AS sale_total,
                    (SELECT COALESCE(SUM(flock_lines.line_total), 0)
                     FROM sale_lines flock_lines
                     WHERE flock_lines.farm_id = s.farm_id AND flock_lines.sale_id = s.id
                       AND flock_lines.flock_id = :target_flock_id) AS flock_total,
                    (SELECT COALESCE(SUM(CASE WHEN pay.direction = 'received'
                                              THEN pay.amount ELSE -pay.amount END), 0)
                     FROM sale_payments pay
                     WHERE pay.farm_id = s.farm_id AND pay.sale_id = s.id
                       AND pay.status = 'posted') AS net_paid
             FROM sales s
             INNER JOIN sale_lines sl ON sl.farm_id = s.farm_id AND sl.sale_id = s.id
             LEFT JOIN products pr ON pr.farm_id = sl.farm_id AND pr.id = sl.product_id
             WHERE s.farm_id = :farm_id AND sl.flock_id = :flock_id
               AND s.sold_at >= CONCAT(:from_date, ' 00:00:00')
               AND s.sold_at < DATE_ADD(:to_date, INTERVAL 1 DAY)
             ORDER BY s.sold_at, s.id, sl.id"
        );
        $salesQuery->execute([
            'farm_id' => $farmId,
            'flock_id' => $flockId,
            'target_flock_id' => $flockId,
            'from_date' => $from,
            'to_date' => $to,
        ]);
        $saleRows = $salesQuery->fetchAll();
        $salesById = [];
        foreach ($saleRows as $row) {
            $saleId = (int) $row['id'];
            if (!isset($salesById[$saleId])) {
                $flockTotal = (int) $row['flock_total'];
                $saleTotal = (int) $row['sale_total'];
                $allocatedPaid = $saleTotal > 0
                    ? (int) round((float) $row['net_paid'] * $flockTotal / $saleTotal)
                    : 0;
                $salesById[$saleId] = [
                    'sold_at' => (string) $row['sold_at'],
                    'client_name' => (string) $row['client_name_snapshot'],
                    'status' => (string) $row['status'],
                    'amount' => $flockTotal,
                    'receivable' => $row['status'] === 'posted'
                        ? max(0, $flockTotal - $allocatedPaid)
                        : 0,
                    'items' => [],
                ];
            }
            $salesById[$saleId]['items'][] = [
                'name' => (string) $row['item_name'],
                'source_type' => (string) $row['source_type'],
                'quantity' => (float) $row['quantity'],
                'line_total' => (int) $row['line_total'],
            ];
        }

        $finance = FinanceService::profitability($pdo, $userId, $farmId, $from, $to, $flockId);

        return [
            'farm' => [
                'name' => (string) $flock['farm_name'],
                'timezone' => (string) $flock['timezone'],
            ],
            'flock' => $flock,
            'period' => ['from' => $from, 'to' => $to],
            'headcount' => [
                'initial' => $initialCount,
                'final' => $finalCount,
                'mortality' => (int) $population['mortality_count'],
            ],
            'growth' => ['weights' => $weights, 'change_g' => $weightChange],
            'feed' => [
                'daily_total_kg' => $feedTotal,
                'daily_rows' => $feedRows,
                'stock_rows' => $stockFeedRows,
            ],
            'health' => ['available' => $canViewHealth, 'records' => $healthRows],
            'purchases' => $purchases,
            'expenses' => $expenses,
            'category_labels' => FinanceService::categories($pdo),
            'sales' => array_values($salesById),
            'finance' => $finance,
        ];
    }

    private static function writePrivateFile(string $path, string $pdf): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le répertoire privé des rapports.');
        }
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Impossible de créer le fichier PDF privé.');
        }
        try {
            $length = strlen($pdf);
            $written = 0;
            while ($written < $length) {
                $count = fwrite($handle, substr($pdf, $written));
                if ($count === false || $count === 0) {
                    throw new RuntimeException('Échec de l’écriture du fichier PDF.');
                }
                $written += $count;
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Échec de la finalisation du fichier PDF.');
            }
        } catch (Throwable $exception) {
            fclose($handle);
            if (is_file($path) && !unlink($path)) {
                error_log('Unable to remove a partial private report PDF.');
            }
            throw $exception;
        }
        fclose($handle);
    }

    private static function storagePath(string $key): string
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) {
            throw new RuntimeException('Référence de fichier de rapport invalide.');
        }
        return MON_ELEVAGE_ROOT . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'reports' . DIRECTORY_SEPARATOR . $key . '.pdf';
    }

    private static function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value;
    }
}
