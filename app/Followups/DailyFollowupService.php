<?php
declare(strict_types=1);

namespace MonElevage\Followups;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;
use MonElevage\Auth\FarmAccess;

final class DailyFollowupService
{
    private const MOVEMENT_TYPES = [
        'entries_count' => ['entry', 1],
        'mortality_count' => ['mortality', -1],
        'sales_count' => ['sale', -1],
        'other_exits_count' => ['loss', -1],
    ];

    public static function flocks(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.view');
        $statement = $pdo->prepare(
            'SELECT id, code, name, started_on, initial_count, status
             FROM flocks
             WHERE farm_id = :farm_id
             ORDER BY FIELD(status, \'active\', \'paused\', \'draft\', \'closed\'),
                      started_on DESC, id DESC'
        );
        $statement->execute(['farm_id' => $farmId]);
        return $statement->fetchAll();
    }

    public static function history(
        PDO $pdo,
        int $userId,
        int $farmId,
        ?int $flockId,
        ?string $fromDate,
        ?string $toDate,
        DateTimeZone $timezone
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.view');
        $sql = 'SELECT d.*, f.name AS flock_name, f.code AS flock_code,
                       f.status AS flock_status, f.initial_count AS flock_initial_count
                FROM daily_followups d
                INNER JOIN flocks f ON f.farm_id = d.farm_id AND f.id = d.flock_id
                WHERE d.farm_id = :farm_id';
        $parameters = ['farm_id' => $farmId];
        if ($flockId !== null) {
            $sql .= ' AND d.flock_id = :flock_id';
            $parameters['flock_id'] = $flockId;
        }
        if ($fromDate !== null) {
            $sql .= ' AND d.followup_date >= :from_date';
            $parameters['from_date'] = $fromDate;
        }
        if ($toDate !== null) {
            $sql .= ' AND d.followup_date <= :to_date';
            $parameters['to_date'] = $toDate;
        }
        $sql .= ' ORDER BY d.followup_date DESC, d.id DESC LIMIT 500';
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
        if ($rows === []) {
            return [];
        }
        $flockIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['flock_id'],
            $rows
        )));
        $idPlaceholders = [];
        $idParameters = [];
        foreach ($flockIds as $index => $id) {
            $key = 'history_flock_' . $index;
            $idPlaceholders[] = ':' . $key;
            $idParameters[$key] = $id;
        }
        $openingByDate = [];
        foreach (array_unique(array_column($rows, 'followup_date')) as $date) {
            $localStart = new DateTimeImmutable((string) $date . ' 00:00:00', $timezone);
            $utcStart = $localStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $openingQuery = $pdo->prepare(
                'SELECT flock_id, SUM(quantity_delta) AS prior_delta
                 FROM animal_movements
                 WHERE farm_id = :farm_id
                   AND flock_id IN (' . implode(', ', $idPlaceholders) . ')
                   AND occurred_at < :utc_start
                 GROUP BY flock_id'
            );
            $openingQuery->execute($idParameters + [
                'farm_id' => $farmId,
                'utc_start' => $utcStart,
            ]);
            $openingByDate[(string) $date] = [];
            foreach ($openingQuery->fetchAll() as $opening) {
                $openingByDate[(string) $date][(int) $opening['flock_id']] =
                    (int) $opening['prior_delta'];
            }
        }
        foreach ($rows as &$row) {
            $row['opening_count'] = (int) $row['flock_initial_count']
                + ($openingByDate[(string) $row['followup_date']][(int) $row['flock_id']] ?? 0);
        }
        unset($row);
        return $rows;
    }

    public static function find(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $followupId
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.view');
        $statement = $pdo->prepare(
            'SELECT d.*, f.name AS flock_name, f.code AS flock_code
             FROM daily_followups d
             INNER JOIN flocks f ON f.farm_id = d.farm_id AND f.id = d.flock_id
             WHERE d.farm_id = :farm_id AND d.id = :followup_id
             LIMIT 1'
        );
        $statement->execute(['farm_id' => $farmId, 'followup_id' => $followupId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Le suivi quotidien est introuvable.');
        }
        return $row;
    }

    public static function openingCount(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $flockId,
        string $date,
        DateTimeZone $timezone
    ): int {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.view');
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        if ($day === false || $day->format('Y-m-d') !== $date) {
            throw new RuntimeException('Date de suivi invalide.');
        }
        $utcStart = $day->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $statement = $pdo->prepare(
            'SELECT f.initial_count + COALESCE((
                SELECT SUM(m.quantity_delta)
                FROM animal_movements m
                WHERE m.farm_id = f.farm_id
                  AND m.flock_id = f.id
                  AND m.occurred_at < :utc_start
            ), 0)
             FROM flocks f
             WHERE f.farm_id = :farm_id AND f.id = :flock_id'
        );
        $statement->execute([
            'utc_start' => $utcStart,
            'farm_id' => $farmId,
            'flock_id' => $flockId,
        ]);
        $value = $statement->fetchColumn();
        if ($value === false) {
            throw new RuntimeException('Élevage introuvable.');
        }
        return (int) $value;
    }

    public static function save(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $flockId,
        array $input,
        ?int $followupId,
        DateTimeImmutable $today
    ): int {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'followup.write');
        $validated = self::validate($input, $today);
        if ($validated['errors'] !== []) {
            throw new RuntimeException(implode(' ', $validated['errors']));
        }
        $values = $validated['values'];
        $timezone = new DateTimeZone($today->getTimezone()->getName());
        $dayStart = new DateTimeImmutable($values['followup_date'] . ' 00:00:00', $timezone);
        $dayEnd = $dayStart->modify('+1 day');
        $utcStart = $dayStart->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $utcEnd = $dayEnd->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $occurredAt = $dayStart->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $pdo->beginTransaction();
        try {
            $flockQuery = $pdo->prepare(
                'SELECT initial_count, started_on, status
                 FROM flocks
                 WHERE farm_id = :farm_id AND id = :flock_id
                 FOR UPDATE'
            );
            $flockQuery->execute(['farm_id' => $farmId, 'flock_id' => $flockId]);
            $flock = $flockQuery->fetch();
            if (!is_array($flock)
                || !in_array($flock['status'], ['active', 'paused'], true)
                || (string) $flock['started_on'] > $values['followup_date']) {
                throw new RuntimeException('Cet élevage ne peut pas recevoir un suivi à cette date.');
            }

            $existingQuery = $pdo->prepare(
                'SELECT id
                 FROM daily_followups
                 WHERE farm_id = :farm_id
                   AND flock_id = :flock_id
                   AND followup_date = :followup_date
                 FOR UPDATE'
            );
            $existingQuery->execute([
                'farm_id' => $farmId,
                'flock_id' => $flockId,
                'followup_date' => $values['followup_date'],
            ]);
            $existingForDate = $existingQuery->fetchColumn();
            if ($followupId !== null && (int) $existingForDate !== $followupId) {
                throw new RuntimeException('Le suivi sélectionné n’existe plus pour cette date.');
            }
            if ($followupId === null && $existingForDate !== false) {
                throw new RuntimeException(
                    'Un suivi existe déjà pour cet élevage et cette date. Modifiez-le depuis l’historique.'
                );
            }

            $openingQuery = $pdo->prepare(
                'SELECT COALESCE(SUM(quantity_delta), 0)
                 FROM animal_movements
                 WHERE farm_id = :farm_id AND flock_id = :flock_id
                   AND occurred_at < :utc_start'
            );
            $openingQuery->execute([
                'farm_id' => $farmId,
                'flock_id' => $flockId,
                'utc_start' => $utcStart,
            ]);
            $openingCount = (int) $flock['initial_count'] + (int) $openingQuery->fetchColumn();
            if ($openingCount < 0) {
                throw new RuntimeException('Les mouvements antérieurs produisent un effectif initial négatif. Corrigez-les avant ce suivi.');
            }

            $externalQuery = $pdo->prepare(
                'SELECT
                    COALESCE(SUM(quantity_delta), 0) AS net_delta,
                    COALESCE(SUM(CASE
                        WHEN movement_type IN (\'entry\', \'transfer\')
                             AND quantity_delta > 0
                        THEN quantity_delta ELSE 0 END), 0) AS entries_count,
                    COALESCE(SUM(CASE
                        WHEN movement_type = \'mortality\'
                             AND NOT EXISTS (
                                 SELECT 1 FROM animal_movements reversal
                                 WHERE reversal.farm_id = animal_movements.farm_id
                                   AND reversal.reversal_of_id = animal_movements.id
                             )
                        THEN -quantity_delta ELSE 0 END), 0) AS mortality_count,
                    COALESCE(SUM(CASE
                        WHEN movement_type = \'sale\'
                             AND NOT EXISTS (
                                 SELECT 1 FROM animal_movements reversal
                                 WHERE reversal.farm_id = animal_movements.farm_id
                                   AND reversal.reversal_of_id = animal_movements.id
                             )
                        THEN -quantity_delta ELSE 0 END), 0) AS sales_count,
                    COALESCE(SUM(CASE
                        WHEN (movement_type = \'loss\' OR
                              (movement_type = \'transfer\' AND quantity_delta < 0))
                             AND NOT EXISTS (
                                 SELECT 1 FROM animal_movements reversal
                                 WHERE reversal.farm_id = animal_movements.farm_id
                                   AND reversal.reversal_of_id = animal_movements.id
                             )
                        THEN -quantity_delta ELSE 0 END), 0) AS other_exits_count
                 FROM animal_movements
                 WHERE farm_id = :farm_id
                   AND flock_id = :flock_id
                   AND occurred_at >= :utc_start
                   AND occurred_at < :utc_end
                   AND daily_followup_id IS NULL'
            );
            $externalQuery->execute([
                'farm_id' => $farmId,
                'flock_id' => $flockId,
                'utc_start' => $utcStart,
                'utc_end' => $utcEnd,
            ]);
            $externalMovements = $externalQuery->fetch();
            $reportedFields = [
                'entries_count' => 'entries_count',
                'mortality_count' => 'mortality_count',
                'sales_count' => 'sales_count',
                'other_exits_count' => 'other_exits_count',
            ];
            $movementCountsToRecord = [];
            foreach ($reportedFields as $inputField => $externalField) {
                $externalCount = (int) $externalMovements[$externalField];
                if ($externalCount > 0 && $values[$inputField] !== $externalCount) {
                    throw new RuntimeException(
                        'Des mouvements de cette catégorie sont déjà enregistrés séparément ce jour. Le nombre saisi doit correspondre pour éviter un double comptage.'
                    );
                }
                $movementCountsToRecord[$inputField] =
                    $externalCount > 0 ? 0 : $values[$inputField];
            }
            $closingCount = $openingCount
                + (int) $externalMovements['net_delta']
                + $movementCountsToRecord['entries_count']
                - $movementCountsToRecord['mortality_count']
                - $movementCountsToRecord['sales_count']
                - $movementCountsToRecord['other_exits_count'];
            if ($closingCount < 0) {
                throw new RuntimeException(
                    'Les sorties dépassent l’effectif disponible. Vérifiez les mouvements antérieurs et les quantités du jour.'
                );
            }
            if ($values['sick_count'] > $closingCount) {
                throw new RuntimeException('Le nombre de sujets malades ne peut pas dépasser l’effectif vivant en fin de journée.');
            }

            if ($followupId === null) {
                $insert = $pdo->prepare(
                    'INSERT INTO daily_followups
                        (farm_id, flock_id, followup_date, observed_count,
                         entries_count, sick_count, mortality_count, sales_count,
                         other_exits_count, average_weight_g, feed_consumed_kg,
                         water_consumed_liters, temperature_celsius, humidity_percent,
                         notes, created_by_user_id)
                     VALUES
                        (:farm_id, :flock_id, :followup_date, :observed_count,
                         :entries_count, :sick_count, :mortality_count, :sales_count,
                         :other_exits_count, :average_weight_g, :feed_consumed_kg,
                         :water_consumed_liters, :temperature_celsius, :humidity_percent,
                         :notes, :user_id)'
                );
                $insert->execute($values + [
                    'farm_id' => $farmId,
                    'flock_id' => $flockId,
                    'observed_count' => $closingCount,
                    'user_id' => $userId,
                ]);
                $followupId = (int) $pdo->lastInsertId();
            } else {
                $update = $pdo->prepare(
                    'UPDATE daily_followups
                     SET observed_count = :observed_count,
                         entries_count = :entries_count,
                         sick_count = :sick_count,
                         mortality_count = :mortality_count,
                         sales_count = :sales_count,
                         other_exits_count = :other_exits_count,
                         average_weight_g = :average_weight_g,
                         feed_consumed_kg = :feed_consumed_kg,
                         water_consumed_liters = :water_consumed_liters,
                         temperature_celsius = :temperature_celsius,
                         humidity_percent = :humidity_percent,
                         notes = :notes
                     WHERE farm_id = :farm_id AND id = :followup_id'
                );
                $updateValues = $values;
                unset($updateValues['followup_date']);
                $update->execute($updateValues + [
                    'observed_count' => $closingCount,
                    'farm_id' => $farmId,
                    'followup_id' => $followupId,
                ]);
            }

            foreach (self::MOVEMENT_TYPES as $field => [$movementType, $direction]) {
                self::syncMovement(
                    $pdo,
                    $farmId,
                    $flockId,
                    $followupId,
                    $userId,
                    $movementType,
                    $movementCountsToRecord[$field] * $direction,
                    $occurredAt,
                    $values['notes']
                );
            }
            self::recomputeFollowingCounts(
                $pdo,
                $farmId,
                $flockId,
                $values['followup_date'],
                $timezone
            );
            $pdo->commit();
            return $followupId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof \PDOException && (int) $exception->getCode() === 23000) {
                throw new RuntimeException(
                    'Un suivi existe déjà pour cet élevage et cette date, ou un mouvement est en conflit.'
                );
            }
            throw $exception;
        }
    }

    public static function validate(array $input, DateTimeImmutable $today): array
    {
        $errors = [];
        $date = is_string($input['followup_date'] ?? null)
            ? trim($input['followup_date'])
            : '';
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $date) {
            $errors[] = 'Saisissez une date de suivi valide.';
        } elseif ($parsedDate > $today->setTime(0, 0)) {
            $errors[] = 'Un suivi ne peut pas être enregistré à une date future.';
        }

        $values = ['followup_date' => $date];
        foreach ([
            'entries_count' => 'Entrées',
            'sick_count' => 'Malades observés',
            'mortality_count' => 'Morts',
            'sales_count' => 'Ventes',
            'other_exits_count' => 'Autres sorties',
        ] as $field => $label) {
            $raw = $input[$field] ?? '0';
            $value = filter_var($raw, FILTER_VALIDATE_INT);
            if (!is_int($value) || $value < 0 || $value > 2147483647) {
                $errors[] = $label . ' doit être un nombre entier positif ou nul.';
                $value = 0;
            }
            $values[$field] = $value;
        }

        foreach ([
            'average_weight_g' => ['Poids moyen', 100000, true],
            'feed_consumed_kg' => ['Aliment consommé', 1000000, false],
            'water_consumed_liters' => ['Eau consommée', 1000000, false],
            'temperature_celsius' => ['Température', 70, false],
            'humidity_percent' => ['Humidité', 100, false],
        ] as $field => [$label, $maximum, $strictlyPositive]) {
            $raw = is_string($input[$field] ?? null) || is_numeric($input[$field] ?? null)
                ? trim((string) $input[$field])
                : '';
            if ($raw === '') {
                $values[$field] = null;
                continue;
            }
            if (!preg_match('/^-?\d+(?:[.,]\d{1,3})?$/D', $raw)) {
                $errors[] = $label . ' doit être une valeur numérique valide.';
                $values[$field] = null;
                continue;
            }
            $number = (float) str_replace(',', '.', $raw);
            $minimum = $field === 'temperature_celsius' ? -50 : 0;
            if (($strictlyPositive && $number <= 0)
                || (!$strictlyPositive && $field !== 'temperature_celsius' && $number < 0)
                || $number < $minimum
                || $number > $maximum) {
                $errors[] = match ($field) {
                    'average_weight_g' => 'Le poids moyen doit être strictement positif et ne pas dépasser 100 000 g.',
                    'temperature_celsius' => 'La température doit être comprise entre -50 et 70 °C.',
                    'humidity_percent' => 'L’humidité doit être comprise entre 0 et 100 %.',
                    default => $label . ' doit être une valeur positive ou nulle dans la limite autorisée.',
                };
            }
            $values[$field] = number_format($number, 3, '.', '');
        }

        $notes = is_string($input['notes'] ?? null) ? trim($input['notes']) : '';
        if (mb_strlen($notes, 'UTF-8') > 5000) {
            $errors[] = 'Les observations sont limitées à 5 000 caractères.';
        }
        $values['notes'] = $notes !== '' ? $notes : null;
        return ['errors' => $errors, 'values' => $values];
    }

    private static function syncMovement(
        PDO $pdo,
        int $farmId,
        int $flockId,
        int $followupId,
        int $userId,
        string $movementType,
        int $quantityDelta,
        string $occurredAt,
        ?string $reason
    ): void {
        if ($quantityDelta === 0) {
            $delete = $pdo->prepare(
                'DELETE FROM animal_movements
                 WHERE farm_id = :farm_id
                   AND daily_followup_id = :followup_id
                   AND movement_type = :movement_type'
            );
            $delete->execute([
                'farm_id' => $farmId,
                'followup_id' => $followupId,
                'movement_type' => $movementType,
            ]);
            return;
        }
        $statement = $pdo->prepare(
            'INSERT INTO animal_movements
                (farm_id, flock_id, movement_type, quantity_delta, occurred_at,
                 daily_followup_id, reason, created_by_user_id)
             VALUES
                (:farm_id, :flock_id, :movement_type, :quantity_delta, :occurred_at,
                 :followup_id, :reason, :user_id)
             ON DUPLICATE KEY UPDATE
                quantity_delta = VALUES(quantity_delta),
                occurred_at = VALUES(occurred_at),
                reason = VALUES(reason)'
        );
        $movementReason = $reason === null
            ? null
            : mb_substr($reason, 0, 500, 'UTF-8');
        $statement->execute([
            'farm_id' => $farmId,
            'flock_id' => $flockId,
            'movement_type' => $movementType,
            'quantity_delta' => $quantityDelta,
            'occurred_at' => $occurredAt,
            'followup_id' => $followupId,
            'reason' => $movementReason,
            'user_id' => $userId,
        ]);
    }

    private static function recomputeFollowingCounts(
        PDO $pdo,
        int $farmId,
        int $flockId,
        string $fromDate,
        DateTimeZone $timezone
    ): void {
        $followups = $pdo->prepare(
            'SELECT id, followup_date, sick_count
             FROM daily_followups
             WHERE farm_id = :farm_id
               AND flock_id = :flock_id
               AND followup_date >= :from_date
             ORDER BY followup_date, id'
        );
        $followups->execute([
            'farm_id' => $farmId,
            'flock_id' => $flockId,
            'from_date' => $fromDate,
        ]);
        $calculate = $pdo->prepare(
            'SELECT f.initial_count + COALESCE(SUM(m.quantity_delta), 0)
             FROM flocks f
             LEFT JOIN animal_movements m
               ON m.farm_id = f.farm_id
              AND m.flock_id = f.id
              AND m.occurred_at < :utc_end
             WHERE f.farm_id = :farm_id AND f.id = :flock_id
             GROUP BY f.id'
        );
        $update = $pdo->prepare(
            'UPDATE daily_followups
             SET observed_count = :observed_count
             WHERE farm_id = :farm_id AND id = :followup_id'
        );
        foreach ($followups->fetchAll() as $followup) {
            $localEnd = new DateTimeImmutable(
                (string) $followup['followup_date'] . ' 00:00:00',
                $timezone
            );
            $utcEnd = $localEnd->modify('+1 day')
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
            $calculate->execute([
                'utc_end' => $utcEnd,
                'farm_id' => $farmId,
                'flock_id' => $flockId,
            ]);
            $closingCount = (int) $calculate->fetchColumn();
            if ($closingCount < 0 || (int) $followup['sick_count'] > $closingCount) {
                throw new RuntimeException(
                    'Cette correction rend incohérent un suivi ultérieur. Corrigez d’abord les mouvements ou observations des jours suivants.'
                );
            }
            $update->execute([
                'observed_count' => $closingCount,
                'farm_id' => $farmId,
                'followup_id' => (int) $followup['id'],
            ]);
        }
    }
}
