<?php
declare(strict_types=1);

namespace MonElevage\Flocks;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;
use MonElevage\Auth\FarmAccess;

final class FlockService
{
    private const TYPES = [
        'broiler' => 'Poulets de chair',
        'layer' => 'Pondeuses',
        'cockerel' => 'Coquelets',
        'free_range' => 'Poulets fermiers',
        'quail' => 'Cailles',
        'turkey' => 'Dindes',
        'duck' => 'Canards',
        'guinea_fowl' => 'Pintades',
        'goose' => 'Oies',
        'pigeon' => 'Pigeons',
        'breeder' => 'Reproducteurs',
    ];

    public static function types(?PDO $pdo = null, bool $includeArchived = false): array
    {
        if ($pdo === null) {
            return self::TYPES;
        }
        $query = $pdo->prepare(
            'SELECT code, label, status FROM system_reference_items
             WHERE reference_type = \'flock_type\'
             ORDER BY position, label, id'
        );
        $query->execute();
        $types = [];
        $rows = $query->fetchAll();
        foreach ($rows as $row) {
            if (!$includeArchived && $row['status'] !== 'active') {
                continue;
            }
            $types[(string) $row['code']] = (string) $row['label'];
        }
        return $rows === [] ? self::TYPES : $types;
    }

    public static function validate(
        array $input,
        string $currencyCode,
        ?DateTimeImmutable $today = null,
        ?array $availableTypes = null
    ): array {
        $errors = [];
        $name = self::text($input['name'] ?? null);
        $code = strtoupper(self::text($input['code'] ?? null));
        $type = self::text($input['flock_type'] ?? null);
        $supplier = self::text($input['supplier_name'] ?? null);
        $location = self::text($input['location'] ?? null);
        $startedOn = self::text($input['started_on'] ?? null);
        $countRaw = $input['initial_count'] ?? null;
        $count = filter_var($countRaw, FILTER_VALIDATE_INT);

        if ($name === '' || mb_strlen($name, 'UTF-8') > 120) {
            $errors[] = 'Le nom est obligatoire et limité à 120 caractères.';
        }
        if (!preg_match('/^[A-Z0-9][A-Z0-9_-]{0,39}$/D', $code)) {
            $errors[] = 'Le code doit contenir 1 à 40 lettres majuscules, chiffres, tirets ou tirets bas.';
        }
        if (!isset(($availableTypes ?? self::TYPES)[$type])) {
            $errors[] = 'Choisissez un type d’élevage valide.';
        }
        if (!is_int($count) || $count < 1) {
            $errors[] = 'L’effectif initial doit être un entier supérieur à zéro.';
        }
        $startDate = self::parseDate($startedOn);
        $currentDate = $today ?? new DateTimeImmutable('today', new DateTimeZone('UTC'));
        if ($startDate === null || $startedOn > $currentDate->format('Y-m-d')) {
            $errors[] = 'La date de début doit être une date valide qui n’est pas dans le futur.';
        }
        if (mb_strlen($supplier, 'UTF-8') > 160) {
            $errors[] = 'Le nom du fournisseur est limité à 160 caractères.';
        }
        if (mb_strlen($location, 'UTF-8') > 255) {
            $errors[] = 'La localisation est limitée à 255 caractères.';
        }

        $initialCost = self::amount($input['initial_cost'] ?? null, 'Le coût initial', $errors, false);
        $budget = self::amount($input['budget_amount'] ?? null, 'Le budget', $errors, true);
        if (!preg_match('/^[A-Z]{3}$/D', $currencyCode)) {
            $errors[] = 'La devise de l’exploitation est invalide.';
        }

        return [
            'errors' => $errors,
            'values' => [
                'name' => $name,
                'code' => $code,
                'flock_type' => $type,
                'started_on' => $startedOn,
                'initial_count' => is_int($count) ? $count : null,
                'supplier_name' => $supplier !== '' ? $supplier : null,
                'initial_cost' => $initialCost,
                'budget_amount' => $budget,
                'location' => $location !== '' ? $location : null,
                'currency_code' => $currencyCode,
            ],
        ];
    }

    public static function listForFarm(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.view');
        $statement = $pdo->prepare(
            'SELECT f.id, f.code, f.name, f.flock_type, f.started_on,
                    f.initial_count, f.status, f.currency_code, f.budget_amount,
                    COALESCE(movement_summary.quantity_delta, 0) AS quantity_delta
             FROM flocks f
             LEFT JOIN (
                 SELECT farm_id, flock_id, SUM(quantity_delta) AS quantity_delta
                 FROM animal_movements
                 WHERE farm_id = :movement_farm_id
                 GROUP BY farm_id, flock_id
             ) movement_summary
               ON movement_summary.farm_id = f.farm_id
              AND movement_summary.flock_id = f.id
             WHERE f.farm_id = :farm_id
             ORDER BY FIELD(f.status, \'active\', \'paused\', \'draft\', \'closed\'),
                      f.started_on DESC, f.id DESC'
        );
        $statement->execute([
            'movement_farm_id' => $farmId,
            'farm_id' => $farmId,
        ]);
        $flocks = $statement->fetchAll();
        foreach ($flocks as &$flock) {
            $flock['id'] = (int) $flock['id'];
            $flock['initial_count'] = (int) $flock['initial_count'];
            $flock['live_count'] = $flock['initial_count'] + (int) $flock['quantity_delta'];
            $flock['budget_amount'] = $flock['budget_amount'] === null
                ? null
                : (string) $flock['budget_amount'];
        }
        unset($flock);

        return $flocks;
    }

    public static function find(PDO $pdo, int $userId, int $farmId, int $flockId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.view');
        $statement = $pdo->prepare(
            'SELECT f.*,
                    f.initial_count + COALESCE(movement_summary.quantity_delta, 0) AS live_count
             FROM flocks f
             LEFT JOIN (
                 SELECT farm_id, flock_id, SUM(quantity_delta) AS quantity_delta
                 FROM animal_movements
                 WHERE farm_id = :movement_farm_id
                 GROUP BY farm_id, flock_id
             ) movement_summary
               ON movement_summary.farm_id = f.farm_id
              AND movement_summary.flock_id = f.id
             WHERE f.farm_id = :farm_id AND f.id = :flock_id
             LIMIT 1'
        );
        $statement->execute([
            'movement_farm_id' => $farmId,
            'farm_id' => $farmId,
            'flock_id' => $flockId,
        ]);
        $flock = $statement->fetch();
        if (!is_array($flock)) {
            throw new RuntimeException('Élevage introuvable.');
        }
        $flock['id'] = (int) $flock['id'];
        $flock['initial_count'] = (int) $flock['initial_count'];
        $flock['live_count'] = (int) $flock['live_count'];

        $events = $pdo->prepare(
            'SELECT event_type, resulting_status, event_at, closing_count,
                    closing_revenue, closing_expenses, closing_result, currency_code, reason
             FROM flock_lifecycle_events
             WHERE farm_id = :farm_id AND flock_id = :flock_id
             ORDER BY event_at DESC, id DESC'
        );
        $events->execute(['farm_id' => $farmId, 'flock_id' => $flockId]);
        $flock['history'] = $events->fetchAll();

        return $flock;
    }

    public static function create(PDO $pdo, int $userId, int $farmId, array $input): int
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.manage');
        $farm = self::farmSettings($pdo, $farmId);
        $validated = self::validate(
            $input,
            $farm['currency_code'],
            new DateTimeImmutable('today', new DateTimeZone($farm['timezone'])),
            self::types($pdo)
        );
        self::throwValidationErrors($validated['errors']);
        $values = $validated['values'];

        try {
            $pdo->beginTransaction();
            self::assertCodeAvailable($pdo, $farmId, $values['code']);
            $insert = $pdo->prepare(
                "INSERT INTO flocks
                    (farm_id, code, name, flock_type, poultry_type, production_type,
                     currency_code, started_on, initial_count, supplier_name, initial_cost,
                     budget_amount, location, status, created_by_user_id)
                 VALUES
                       (:farm_id, :code, :name, :flock_type, :poultry_type, :production_type,
                     :currency_code, :started_on, :initial_count, :supplier_name,
                     :initial_cost, :budget_amount, :location, 'draft', :user_id)"
            );
            $insert->execute([
                'farm_id' => $farmId,
                'code' => $values['code'],
                'name' => $values['name'],
                'flock_type' => $values['flock_type'],
                'poultry_type' => self::poultryType($values['flock_type']),
                'production_type' => self::productionType($values['flock_type']),
                'currency_code' => $farm['currency_code'],
                'started_on' => $values['started_on'],
                'initial_count' => $values['initial_count'],
                'supplier_name' => $values['supplier_name'],
                'initial_cost' => $values['initial_cost'],
                'budget_amount' => $values['budget_amount'],
                'location' => $values['location'],
                'user_id' => $userId,
            ]);
            $flockId = (int) $pdo->lastInsertId();
            self::recordLifecycle($pdo, $farmId, $flockId, 'created', 'draft', $userId);
            $activate = $pdo->prepare(
                "UPDATE flocks SET status = 'active'
                 WHERE farm_id = :farm_id AND id = :flock_id AND status = 'draft'"
            );
            $activate->execute(['farm_id' => $farmId, 'flock_id' => $flockId]);
            self::recordLifecycle($pdo, $farmId, $flockId, 'activated', 'active', $userId);
            $pdo->commit();

            return $flockId;
        } catch (PDOException $exception) {
            self::rollback($pdo);
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new RuntimeException('Ce code d’élevage existe déjà dans cette exploitation.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            self::rollback($pdo);
            throw $exception;
        }
    }

    public static function update(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $flockId,
        array $input
    ): void {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.manage');
        $farm = self::farmSettings($pdo, $farmId);

        try {
            $pdo->beginTransaction();
            $current = self::lockFlock($pdo, $farmId, $flockId);
            if ($current['status'] === 'closed') {
                throw new RuntimeException('Un élevage clôturé est archivé et ne peut plus être modifié.');
            }
            $validated = self::validate(
                $input,
                (string) $current['currency_code'],
                new DateTimeImmutable('today', new DateTimeZone($farm['timezone'])),
                self::editableTypes($pdo, (string) $current['flock_type'])
            );
            self::throwValidationErrors($validated['errors']);
            $values = $validated['values'];
            self::assertCodeAvailable($pdo, $farmId, $values['code'], $flockId);
            $hasHistory = self::hasOperationalHistory($pdo, $farmId, $flockId);
            if ($hasHistory
                && ($current['flock_type'] !== $values['flock_type']
                    || $current['started_on'] !== $values['started_on']
                    || (int) $current['initial_count'] !== $values['initial_count'])) {
                throw new RuntimeException(
                    'Le type, la date de début et l’effectif initial ne peuvent plus changer après l’enregistrement d’opérations.'
                );
            }
            $update = $pdo->prepare(
                'UPDATE flocks
                 SET code = :code, name = :name, flock_type = :flock_type,
                     poultry_type = :poultry_type, production_type = :production_type,
                     started_on = :started_on,
                     initial_count = :initial_count, supplier_name = :supplier_name,
                     initial_cost = :initial_cost, budget_amount = :budget_amount,
                     location = :location
                 WHERE farm_id = :farm_id AND id = :flock_id'
            );
            $update->execute([
                'code' => $values['code'],
                'name' => $values['name'],
                'flock_type' => $values['flock_type'],
                'poultry_type' => self::poultryType($values['flock_type']),
                'production_type' => self::productionType($values['flock_type']),
                'started_on' => $values['started_on'],
                'initial_count' => $values['initial_count'],
                'supplier_name' => $values['supplier_name'],
                'initial_cost' => $values['initial_cost'],
                'budget_amount' => $values['budget_amount'],
                'location' => $values['location'],
                'farm_id' => $farmId,
                'flock_id' => $flockId,
            ]);
            $pdo->commit();
        } catch (PDOException $exception) {
            self::rollback($pdo);
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new RuntimeException('Ce code d’élevage existe déjà dans cette exploitation.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            self::rollback($pdo);
            throw $exception;
        }
    }

    public static function changeStatus(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $flockId,
        string $action,
        ?string $reason = null
    ): void {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.manage');
        if (!in_array($action, ['pause', 'resume', 'close'], true)) {
            throw new RuntimeException('Action de cycle invalide.');
        }
        $reason = trim($reason ?? '');
        if (mb_strlen($reason, 'UTF-8') > 500) {
            throw new RuntimeException('Le motif de clôture est limité à 500 caractères.');
        }
        if ($action === 'close' && $reason === '') {
            throw new RuntimeException('Indiquez un motif de clôture ou d’archivage.');
        }

        try {
            $pdo->beginTransaction();
            $flock = self::lockFlock($pdo, $farmId, $flockId);
            $transitions = [
                'pause' => ['active', 'paused', 'paused'],
                'resume' => ['paused', 'active', 'resumed'],
                'close' => [['active', 'paused'], 'closed', 'closed'],
            ];
            [$expected, $newStatus, $eventType] = $transitions[$action];
            if (is_array($expected) ? !in_array($flock['status'], $expected, true) : $flock['status'] !== $expected) {
                throw new RuntimeException('Cette action n’est pas disponible pour le statut actuel de l’élevage.');
            }

            $closure = null;
            if ($action === 'close') {
                $closure = self::closureSummary($pdo, $farmId, $flockId, (string) $flock['currency_code']);
                if ($closure['closing_count'] < 0) {
                    throw new RuntimeException('L’effectif calculé est incohérent ; corrigez les mouvements avant la clôture.');
                }
            }
            $update = $pdo->prepare(
                'UPDATE flocks SET status = :status
                 WHERE farm_id = :farm_id AND id = :flock_id'
            );
            $update->execute([
                'status' => $newStatus,
                'farm_id' => $farmId,
                'flock_id' => $flockId,
            ]);
            self::recordLifecycle(
                $pdo,
                $farmId,
                $flockId,
                $eventType,
                $newStatus,
                $userId,
                $closure,
                $reason !== '' ? $reason : null
            );
            $pdo->commit();
        } catch (Throwable $exception) {
            self::rollback($pdo);
            throw $exception;
        }
    }

    public static function deleteIfEmpty(PDO $pdo, int $userId, int $farmId, int $flockId): void
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.manage');

        try {
            $pdo->beginTransaction();
            $flock = self::lockFlock($pdo, $farmId, $flockId);

            $meaningfulHistory = $pdo->prepare(
                'SELECT COUNT(*) FROM flock_lifecycle_events
                 WHERE farm_id = :farm_id AND flock_id = :flock_id
                   AND event_type NOT IN (\'created\', \'activated\')'
            );
            $meaningfulHistory->execute(['farm_id' => $farmId, 'flock_id' => $flockId]);
            if ((int) $meaningfulHistory->fetchColumn() > 0) {
                throw new RuntimeException(
                    'Cet élevage possède un historique de cycle. Pour conserver ces données, clôturez et archivez plutôt l’élevage.'
                );
            }

            $deleteHistory = $pdo->prepare(
                'DELETE FROM flock_lifecycle_events WHERE farm_id = :farm_id AND flock_id = :flock_id'
            );
            $deleteHistory->execute(['farm_id' => $farmId, 'flock_id' => $flockId]);

            $deleteFlock = $pdo->prepare(
                'DELETE FROM flocks WHERE farm_id = :farm_id AND id = :flock_id'
            );
            $deleteFlock->execute(['farm_id' => $farmId, 'flock_id' => $flockId]);
            if ($deleteFlock->rowCount() !== 1) {
                throw new RuntimeException('Élevage introuvable.');
            }

            $audit = $pdo->prepare(
                'INSERT INTO audit_logs
                    (farm_id, actor_user_id, action, resource_type, resource_id, details)
                 VALUES (:farm_id, :actor_id, \'flock.deleted\', \'flock\', :resource_id, :details)'
            );
            $audit->execute([
                'farm_id' => $farmId,
                'actor_id' => $userId,
                'resource_id' => $flockId,
                'details' => json_encode(
                    ['status' => $flock['status']],
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
                ),
            ]);
            $pdo->commit();
        } catch (PDOException $exception) {
            self::rollback($pdo);
            if ((int) ($exception->errorInfo[1] ?? 0) === 1451) {
                throw new RuntimeException(
                    'Cet élevage possède déjà des opérations ou un historique associé. '
                    . 'Pour conserver ces données, clôturez et archivez plutôt l’élevage.'
                );
            }
            throw $exception;
        } catch (Throwable $exception) {
            self::rollback($pdo);
            throw $exception;
        }
    }

    private static function farmSettings(PDO $pdo, int $farmId): array
    {
        $statement = $pdo->prepare(
            'SELECT currency_code, timezone FROM farms WHERE id = :farm_id LIMIT 1'
        );
        $statement->execute(['farm_id' => $farmId]);
        $farm = $statement->fetch();
        if (!is_array($farm)) {
            throw new RuntimeException('Exploitation introuvable.');
        }

        return [
            'currency_code' => (string) $farm['currency_code'],
            'timezone' => (string) $farm['timezone'],
        ];
    }

    private static function editableTypes(PDO $pdo, string $currentType): array
    {
        $types = self::types($pdo);
        $allTypes = self::types($pdo, true);
        if (!isset($types[$currentType]) && isset($allTypes[$currentType])) {
            $types[$currentType] = $allTypes[$currentType];
        }
        return $types;
    }

    private static function assertCodeAvailable(
        PDO $pdo,
        int $farmId,
        string $code,
        ?int $exceptFlockId = null
    ): void {
        $statement = $pdo->prepare(
            'SELECT id FROM flocks
             WHERE farm_id = :farm_id AND code = :code
               AND (:except_id IS NULL OR id <> :except_flock_id)
             LIMIT 1'
        );
        $statement->execute([
            'farm_id' => $farmId,
            'code' => $code,
            'except_id' => $exceptFlockId,
            'except_flock_id' => $exceptFlockId,
        ]);
        if ($statement->fetchColumn() !== false) {
            throw new RuntimeException('Ce code d’élevage existe déjà dans cette exploitation.');
        }
    }

    private static function hasOperationalHistory(PDO $pdo, int $farmId, int $flockId): bool
    {
        $statement = $pdo->prepare(
            'SELECT
                EXISTS(SELECT 1 FROM animal_movements WHERE farm_id = :movement_farm_id AND flock_id = :movement_flock_id)
                OR EXISTS(SELECT 1 FROM daily_followups WHERE farm_id = :followup_farm_id AND flock_id = :followup_flock_id)
                OR EXISTS(SELECT 1 FROM sale_lines WHERE farm_id = :sale_farm_id AND flock_id = :sale_flock_id)
             AS has_history'
        );
        $statement->execute([
            'movement_farm_id' => $farmId,
            'movement_flock_id' => $flockId,
            'followup_farm_id' => $farmId,
            'followup_flock_id' => $flockId,
            'sale_farm_id' => $farmId,
            'sale_flock_id' => $flockId,
        ]);

        return (bool) $statement->fetchColumn();
    }

    private static function closureSummary(
        PDO $pdo,
        int $farmId,
        int $flockId,
        string $currencyCode
    ): array {
        $statement = $pdo->prepare(
            "SELECT
                (SELECT initial_count + COALESCE(SUM(m.quantity_delta), 0)
                 FROM flocks f
                 LEFT JOIN animal_movements m
                   ON m.farm_id = f.farm_id AND m.flock_id = f.id
                 WHERE f.farm_id = :count_farm_id AND f.id = :count_flock_id
                 GROUP BY f.initial_count) AS closing_count,
                CAST(COALESCE((
                    SELECT SUM(sl.line_total)
                    FROM sale_lines sl
                    INNER JOIN sales s ON s.farm_id = sl.farm_id AND s.id = sl.sale_id
                    WHERE sl.farm_id = :sale_farm_id AND sl.flock_id = :sale_flock_id
                      AND s.status = 'posted' AND s.currency_code = :sale_currency
                ), 0) AS DECIMAL(15,2)) AS closing_revenue,
                CAST(COALESCE((
                    SELECT SUM(e.amount)
                    FROM expenses e
                    WHERE e.farm_id = :expense_farm_id AND e.flock_id = :expense_flock_id
                      AND e.status = 'posted' AND e.currency_code = :expense_currency
                ), 0) AS DECIMAL(15,2)) AS closing_expenses"
        );
        $statement->execute([
            'count_farm_id' => $farmId,
            'count_flock_id' => $flockId,
            'sale_farm_id' => $farmId,
            'sale_flock_id' => $flockId,
            'sale_currency' => $currencyCode,
            'expense_farm_id' => $farmId,
            'expense_flock_id' => $flockId,
            'expense_currency' => $currencyCode,
        ]);
        $summary = $statement->fetch();
        if (!is_array($summary)) {
            throw new RuntimeException('Impossible de calculer le résumé de clôture.');
        }
        $difference = $pdo->prepare(
            'SELECT CAST(:revenue - :expenses AS DECIMAL(15,2))'
        );
        $difference->execute([
            'revenue' => $summary['closing_revenue'],
            'expenses' => $summary['closing_expenses'],
        ]);

        return [
            'closing_count' => (int) $summary['closing_count'],
            'closing_revenue' => (string) $summary['closing_revenue'],
            'closing_expenses' => (string) $summary['closing_expenses'],
            'closing_result' => (string) $difference->fetchColumn(),
            'currency_code' => $currencyCode,
        ];
    }

    private static function recordLifecycle(
        PDO $pdo,
        int $farmId,
        int $flockId,
        string $eventType,
        string $status,
        int $userId,
        ?array $closure = null,
        ?string $reason = null
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO flock_lifecycle_events
                (farm_id, flock_id, event_type, resulting_status, closing_count,
                 closing_revenue, closing_expenses, closing_result, currency_code,
                 reason, actor_user_id)
             VALUES
                (:farm_id, :flock_id, :event_type, :status, :closing_count,
                 :closing_revenue, :closing_expenses, :closing_result, :currency_code,
                 :reason, :user_id)'
        );
        $statement->execute([
            'farm_id' => $farmId,
            'flock_id' => $flockId,
            'event_type' => $eventType,
            'status' => $status,
            'closing_count' => $closure['closing_count'] ?? null,
            'closing_revenue' => $closure['closing_revenue'] ?? null,
            'closing_expenses' => $closure['closing_expenses'] ?? null,
            'closing_result' => $closure['closing_result'] ?? null,
            'currency_code' => $closure['currency_code'] ?? null,
            'reason' => $reason,
            'user_id' => $userId,
        ]);
    }

    private static function lockFlock(PDO $pdo, int $farmId, int $flockId): array
    {
        $statement = $pdo->prepare(
            'SELECT id, flock_type, started_on, initial_count, status, currency_code
             FROM flocks
             WHERE farm_id = :farm_id AND id = :flock_id
             FOR UPDATE'
        );
        $statement->execute(['farm_id' => $farmId, 'flock_id' => $flockId]);
        $flock = $statement->fetch();
        if (!is_array($flock)) {
            throw new RuntimeException('Élevage introuvable.');
        }

        return $flock;
    }

    private static function productionType(string $flockType): string
    {
        return match ($flockType) {
            'layer' => 'eggs',
            'breeder' => 'breeding',
            default => 'meat',
        };
    }

    private static function poultryType(string $flockType): string
    {
        return match ($flockType) {
            'turkey' => 'turkey',
            'duck' => 'duck',
            'broiler', 'layer', 'cockerel', 'free_range', 'breeder' => 'chicken',
            default => $flockType,
        };
    }

    private static function throwValidationErrors(array $errors): void
    {
        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private static function parseDate(string $value): ?DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }

    private static function amount(
        mixed $raw,
        string $label,
        array &$errors,
        bool $mustBePositive
    ): ?string {
        if ($raw === null || $raw === '') {
            if ($mustBePositive) {
                return null;
            }
            return null;
        }
        if (!is_string($raw) && !is_int($raw)) {
            $errors[] = $label . ' doit être un montant valide.';
            return null;
        }
        $value = trim((string) $raw);
        if (!preg_match('/^(?:0|[1-9]\d{0,12})(?:\.\d{1,2})?$/D', $value)) {
            $errors[] = $label . ' doit être positif ou nul, avec au plus deux décimales.';
            return null;
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $normalized = $whole . '.' . str_pad($fraction, 2, '0');
        if ($mustBePositive && preg_match('/^0\.0{2}$/D', $normalized)) {
            $errors[] = $label . ' doit être supérieur à zéro.';
            return null;
        }

        return $normalized;
    }

    private static function rollback(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
