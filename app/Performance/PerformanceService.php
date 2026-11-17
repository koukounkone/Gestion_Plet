<?php
declare(strict_types=1);

namespace MonElevage\Performance;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;
use MonElevage\Auth\FarmAccess;

final class PerformanceService
{
    private const MAX_COUNT = 1000000;
    private const METRICS = [
        'average_weight_g' => 'Poids moyen',
        'daily_gain_g' => 'Gain moyen par jour',
        'laying_rate_percent' => 'Collecte d’œufs / effectif vivant',
    ];

    public static function flocks(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.view');
        $statement = $pdo->prepare(
            'SELECT id, code, name, flock_type, started_on, initial_count, status
             FROM flocks
             WHERE farm_id = :farm_id
             ORDER BY FIELD(status, \'active\', \'paused\', \'draft\', \'closed\'),
                      started_on DESC, id DESC'
        );
        $statement->execute(['farm_id' => $farmId]);
        return $statement->fetchAll();
    }

    public static function validateWeighing(
        array $input,
        string $startedOn,
        DateTimeImmutable $today
    ): array {
        $errors = [];
        $date = self::date($input['measured_on'] ?? null);
        $sampleSize = filter_var($input['sample_size'] ?? null, FILTER_VALIDATE_INT);
        $totalWeight = self::decimal($input['total_weight_g'] ?? null);
        $notes = self::text($input['notes'] ?? null);

        if ($date === null || $date < $startedOn || $date > $today->format('Y-m-d')) {
            $errors[] = 'La date de pesée doit être comprise entre le début de l’élevage et aujourd’hui.';
        }
        if (!is_int($sampleSize) || $sampleSize < 1 || $sampleSize > 10000) {
            $errors[] = 'L’échantillon doit contenir entre 1 et 10 000 animaux.';
        }
        if ($totalWeight === null || $totalWeight <= 0
            || (is_int($sampleSize) && $sampleSize > 0 && $totalWeight / $sampleSize > 100000)) {
            $errors[] = 'Le poids total doit être positif et produire une moyenne maximale de 100 000 g.';
        }
        if (mb_strlen($notes, 'UTF-8') > 2000) {
            $errors[] = 'Les observations sont limitées à 2 000 caractères.';
        }

        return [
            'errors' => $errors,
            'values' => [
                'measured_on' => $date ?? '',
                'sample_size' => is_int($sampleSize) ? $sampleSize : null,
                'total_weight_g' => $totalWeight,
                'notes' => $notes,
            ],
        ];
    }

    public static function validateEggRecord(
        array $input,
        string $startedOn,
        DateTimeImmutable $today
    ): array {
        $errors = [];
        $date = self::date($input['record_date'] ?? null);
        $counts = [];
        foreach (['opening_stock_count', 'collected_count', 'broken_count', 'sold_count'] as $field) {
            $value = filter_var($input[$field] ?? 0, FILTER_VALIDATE_INT);
            $counts[$field] = is_int($value) ? $value : null;
            if (!is_int($value) || $value < 0 || $value > self::MAX_COUNT) {
                $errors[] = 'Les quantités d’œufs doivent être des entiers compris entre 0 et 1 000 000.';
            }
        }
        $notes = self::text($input['notes'] ?? null);
        if ($date === null || $date < $startedOn || $date > $today->format('Y-m-d')) {
            $errors[] = 'La date du relevé doit être comprise entre le début de l’élevage et aujourd’hui.';
        }
        if (mb_strlen($notes, 'UTF-8') > 2000) {
            $errors[] = 'Les observations sont limitées à 2 000 caractères.';
        }

        return [
            'errors' => $errors,
            'values' => [
                'record_date' => $date ?? '',
                'opening_stock_count' => $counts['opening_stock_count'],
                'collected_count' => $counts['collected_count'],
                'broken_count' => $counts['broken_count'],
                'sold_count' => $counts['sold_count'],
                'notes' => $notes,
            ],
        ];
    }

    public static function validateTarget(
        array $input,
        string $startedOn,
        DateTimeImmutable $today,
        bool $isLayer
    ): array {
        $errors = [];
        $metric = self::text($input['metric'] ?? null);
        $value = self::decimal($input['target_value'] ?? null);
        $validFrom = self::date($input['valid_from'] ?? null);
        $validUntilText = self::text($input['valid_until'] ?? null);
        $validUntil = $validUntilText === '' ? null : self::date($validUntilText);
        $sourceTitle = self::text($input['source_title'] ?? null);
        $sourceNotes = self::text($input['source_notes'] ?? null);
        $sourceUrl = self::text($input['source_url'] ?? null);

        if (!isset(self::METRICS[$metric]) || (!$isLayer && $metric === 'laying_rate_percent')) {
            $errors[] = 'Choisissez un indicateur applicable à cet élevage.';
        }
        $maximum = match ($metric) {
            'average_weight_g' => 100000,
            'daily_gain_g' => 10000,
            'laying_rate_percent' => 100,
            default => 0,
        };
        if ($value === null || $value <= 0 || $value > $maximum) {
            $errors[] = 'La valeur cible doit être positive et cohérente avec l’unité de l’indicateur.';
        }
        if ($validFrom === null || $validFrom < $startedOn
            || $validFrom > $today->format('Y-m-d')) {
            $errors[] = 'La date de début d’application doit être comprise dans la période de l’élevage.';
        }
        if ($validUntilText !== '' && ($validUntil === null
            || ($validFrom !== null && $validUntil < $validFrom))) {
            $errors[] = 'La date de fin doit être valide et postérieure ou égale au début.';
        }
        if ($sourceTitle === '' || mb_strlen($sourceTitle, 'UTF-8') > 255) {
            $errors[] = 'Indiquez le titre ou l’origine documentée de l’objectif (255 caractères maximum).';
        }
        if (mb_strlen($sourceNotes, 'UTF-8') < 10 || mb_strlen($sourceNotes, 'UTF-8') > 4000) {
            $errors[] = 'Précisez la référence et le contexte de l’objectif (10 à 4 000 caractères).';
        }
        if ($sourceUrl !== '') {
            $parts = parse_url($sourceUrl);
            if (!filter_var($sourceUrl, FILTER_VALIDATE_URL)
                || !is_array($parts)
                || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
                || mb_strlen($sourceUrl, 'UTF-8') > 2048) {
                $errors[] = 'Le lien de référence doit être une URL HTTP ou HTTPS valide.';
            }
        }

        return [
            'errors' => $errors,
            'values' => [
                'metric' => $metric,
                'target_value' => $value,
                'valid_from' => $validFrom ?? '',
                'valid_until' => $validUntil,
                'source_title' => $sourceTitle,
                'source_notes' => $sourceNotes,
                'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
            ],
        ];
    }

    public static function saveWeighing(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $flockId,
        array $input,
        ?int $weighingId,
        DateTimeImmutable $today
    ): int {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'followup.write');
        $flock = self::flock($pdo, $farmId, $flockId, false);
        $validated = self::validateWeighing($input, (string) $flock['started_on'], $today);
        self::requireValid($validated);
        $values = $validated['values'];
        $pdo->beginTransaction();
        try {
            self::flock($pdo, $farmId, $flockId, true);
            if ($weighingId !== null) {
                $statement = $pdo->prepare(
                    'UPDATE flock_weighings
                     SET measured_on = :measured_on, sample_size = :sample_size,
                         total_weight_g = :total_weight_g, notes = :notes
                     WHERE farm_id = :farm_id AND flock_id = :flock_id AND id = :id'
                );
                $statement->execute([
                    'measured_on' => $values['measured_on'],
                    'sample_size' => $values['sample_size'],
                    'total_weight_g' => $values['total_weight_g'],
                    'notes' => $values['notes'] !== '' ? $values['notes'] : null,
                    'farm_id' => $farmId,
                    'flock_id' => $flockId,
                    'id' => $weighingId,
                ]);
                if ($statement->rowCount() === 0 && !self::exists(
                    $pdo,
                    'flock_weighings',
                    $farmId,
                    $flockId,
                    $weighingId
                )) {
                    throw new RuntimeException('La pesée à corriger est introuvable.');
                }
                $savedId = $weighingId;
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO flock_weighings
                        (farm_id, flock_id, measured_on, sample_size, total_weight_g,
                         notes, created_by_user_id)
                     VALUES
                        (:farm_id, :flock_id, :measured_on, :sample_size, :total_weight_g,
                         :notes, :user_id)'
                );
                $statement->execute([
                    'farm_id' => $farmId,
                    'flock_id' => $flockId,
                    'measured_on' => $values['measured_on'],
                    'sample_size' => $values['sample_size'],
                    'total_weight_g' => $values['total_weight_g'],
                    'notes' => $values['notes'] !== '' ? $values['notes'] : null,
                    'user_id' => $userId,
                ]);
                $savedId = (int) $pdo->lastInsertId();
            }
            $pdo->commit();
            return $savedId;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new RuntimeException('Une pesée existe déjà pour cet élevage et cette date.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function saveEggRecord(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $flockId,
        array $input,
        ?int $recordId,
        DateTimeImmutable $today
    ): int {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'followup.write');
        $flock = self::flock($pdo, $farmId, $flockId, false);
        if ($flock['flock_type'] !== 'layer' || $flock['production_type'] !== 'eggs') {
            throw new RuntimeException('Le relevé de ponte est réservé aux élevages de pondeuses.');
        }
        $validated = self::validateEggRecord($input, (string) $flock['started_on'], $today);
        self::requireValid($validated);
        $values = $validated['values'];
        $pdo->beginTransaction();
        try {
            self::flock($pdo, $farmId, $flockId, true);
            $lock = $pdo->prepare(
                'SELECT id, record_date, opening_stock_count, collected_count,
                        broken_count, sold_count, notes
                 FROM flock_egg_records
                 WHERE farm_id = :farm_id AND flock_id = :flock_id
                 ORDER BY record_date, id
                 FOR UPDATE'
            );
            $lock->execute(['farm_id' => $farmId, 'flock_id' => $flockId]);
            $records = $lock->fetchAll();
            $editingRecord = null;
            foreach ($records as $record) {
                if ($recordId !== null && (int) $record['id'] === $recordId) {
                    $editingRecord = $record;
                }
            }
            if ($recordId !== null && $editingRecord === null) {
                throw new RuntimeException('Le relevé d’œufs à corriger est introuvable.');
            }
            foreach ($records as $record) {
                if ((string) $record['record_date'] === $values['record_date']
                    && (int) $record['id'] !== $recordId) {
                    throw new RuntimeException('Un relevé d’œufs existe déjà pour cet élevage et cette date.');
                }
            }

            $initialStock = (int) ($records[0]['opening_stock_count'] ?? 0);
            if ($editingRecord !== null
                && (int) $editingRecord['id'] === (int) ($records[0]['id'] ?? 0)) {
                $initialStock = (int) $values['opening_stock_count'];
            }
            if ($recordId === null && ($records === []
                || $values['record_date'] < (string) $records[0]['record_date'])) {
                $initialStock = (int) $values['opening_stock_count'];
            }

            $proposed = [];
            foreach ($records as $record) {
                if ($recordId !== null && (int) $record['id'] === $recordId) {
                    $proposed[] = [
                        'id' => $recordId,
                        'record_date' => $values['record_date'],
                        'collected_count' => $values['collected_count'],
                        'broken_count' => $values['broken_count'],
                        'sold_count' => $values['sold_count'],
                        'notes' => $values['notes'] !== '' ? $values['notes'] : null,
                        'posted' => true,
                    ];
                } else {
                    $proposed[] = [
                        'id' => (int) $record['id'],
                        'record_date' => (string) $record['record_date'],
                        'collected_count' => (int) $record['collected_count'],
                        'broken_count' => (int) $record['broken_count'],
                        'sold_count' => (int) $record['sold_count'],
                        'notes' => $record['notes'],
                        'posted' => false,
                    ];
                }
            }
            if ($recordId === null) {
                $proposed[] = [
                    'id' => null,
                    'record_date' => $values['record_date'],
                    'collected_count' => $values['collected_count'],
                    'broken_count' => $values['broken_count'],
                    'sold_count' => $values['sold_count'],
                    'notes' => $values['notes'] !== '' ? $values['notes'] : null,
                    'posted' => true,
                ];
            }
            usort($proposed, static fn (array $a, array $b): int =>
                [$a['record_date'], $a['id'] ?? PHP_INT_MAX]
                    <=> [$b['record_date'], $b['id'] ?? PHP_INT_MAX]
            );

            $balance = $initialStock;
            foreach ($proposed as &$record) {
                $opening = $balance;
                $broken = (int) $record['broken_count'];
                $collected = (int) $record['collected_count'];
                $closing = $opening + $collected - $broken - (int) $record['sold_count'];
                if ($broken > $opening + $collected) {
                    throw new RuntimeException('Les œufs cassés ne peuvent dépasser le stock disponible en début de journée et les collectes du jour.');
                }
                if ($closing < 0 || $closing > self::MAX_COUNT) {
                    throw new RuntimeException(sprintf(
                        'Le stock d’œufs deviendrait invalide au %s. Corrigez les collectes, pertes ou ventes.',
                        (string) $record['record_date']
                    ));
                }
                $record['opening_stock_count'] = $opening;
                $record['closing_stock_count'] = $closing;
                $balance = $closing;
            }
            unset($record);

            $update = $pdo->prepare(
                'UPDATE flock_egg_records
                 SET record_date = :record_date, opening_stock_count = :opening_count,
                     collected_count = :collected_count, broken_count = :broken_count,
                     sold_count = :sold_count, closing_stock_count = :closing_count,
                     notes = :notes
                 WHERE farm_id = :farm_id AND flock_id = :flock_id AND id = :id'
            );
            $insert = $pdo->prepare(
                'INSERT INTO flock_egg_records
                    (farm_id, flock_id, record_date, opening_stock_count, collected_count,
                     broken_count, sold_count, closing_stock_count, notes, created_by_user_id)
                 VALUES
                    (:farm_id, :flock_id, :record_date, :opening_count, :collected_count,
                     :broken_count, :sold_count, :closing_count, :notes, :user_id)'
            );
            $savedId = $recordId;
            foreach ($proposed as $record) {
                $parameters = [
                    'record_date' => $record['record_date'],
                    'opening_count' => $record['opening_stock_count'],
                    'collected_count' => $record['collected_count'],
                    'broken_count' => $record['broken_count'],
                    'sold_count' => $record['sold_count'],
                    'closing_count' => $record['closing_stock_count'],
                    'notes' => $record['notes'],
                    'farm_id' => $farmId,
                    'flock_id' => $flockId,
                ];
                if ($record['posted'] && $record['id'] === null) {
                    $insert->execute($parameters + ['user_id' => $userId]);
                    $savedId = (int) $pdo->lastInsertId();
                } else {
                    $update->execute($parameters + ['id' => $record['id']]);
                }
            }
            $pdo->commit();
            return (int) $savedId;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function saveTarget(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $flockId,
        array $input,
        ?int $targetId,
        DateTimeImmutable $today
    ): int {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.manage');
        $flock = self::flock($pdo, $farmId, $flockId, false);
        $isLayer = $flock['flock_type'] === 'layer' && $flock['production_type'] === 'eggs';
        $validated = self::validateTarget($input, (string) $flock['started_on'], $today, $isLayer);
        self::requireValid($validated);
        $values = $validated['values'];
        $pdo->beginTransaction();
        try {
            self::flock($pdo, $farmId, $flockId, true);
            $validThrough = $values['valid_until'] ?? '9999-12-31';
            $overlapSql = 'SELECT COUNT(*) FROM flock_performance_targets
                           WHERE farm_id = :farm_id AND flock_id = :flock_id
                             AND metric = :metric
                             AND valid_from <= :new_end
                             AND (valid_until IS NULL OR valid_until >= :new_start)';
            $parameters = [
                'farm_id' => $farmId,
                'flock_id' => $flockId,
                'metric' => $values['metric'],
                'new_end' => $validThrough,
                'new_start' => $values['valid_from'],
            ];
            if ($targetId !== null) {
                $overlapSql .= ' AND id <> :excluded_id';
                $parameters['excluded_id'] = $targetId;
            }
            $overlap = $pdo->prepare($overlapSql);
            $overlap->execute($parameters);
            if ((int) $overlap->fetchColumn() > 0) {
                throw new RuntimeException('Les périodes de deux objectifs du même indicateur ne peuvent pas se chevaucher.');
            }

            if ($targetId !== null) {
                $statement = $pdo->prepare(
                    'UPDATE flock_performance_targets
                     SET metric = :metric, target_value = :target_value, valid_from = :valid_from,
                         valid_until = :valid_until, source_title = :source_title,
                         source_notes = :source_notes, source_url = :source_url
                     WHERE farm_id = :farm_id AND flock_id = :flock_id AND id = :id'
                );
                $statement->execute($values + [
                    'farm_id' => $farmId,
                    'flock_id' => $flockId,
                    'id' => $targetId,
                ]);
                if ($statement->rowCount() === 0 && !self::exists(
                    $pdo,
                    'flock_performance_targets',
                    $farmId,
                    $flockId,
                    $targetId
                )) {
                    throw new RuntimeException('L’objectif à corriger est introuvable.');
                }
                $savedId = $targetId;
            } else {
                $statement = $pdo->prepare(
                    'INSERT INTO flock_performance_targets
                        (farm_id, flock_id, metric, target_value, valid_from, valid_until,
                         source_title, source_notes, source_url, created_by_user_id)
                     VALUES
                        (:farm_id, :flock_id, :metric, :target_value, :valid_from, :valid_until,
                         :source_title, :source_notes, :source_url, :user_id)'
                );
                $statement->execute($values + [
                    'farm_id' => $farmId,
                    'flock_id' => $flockId,
                    'user_id' => $userId,
                ]);
                $savedId = (int) $pdo->lastInsertId();
            }
            $pdo->commit();
            return $savedId;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new RuntimeException('Un objectif de cet indicateur commence déjà à cette date.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function findWeighing(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $flockId,
        int $id
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.view');
        $statement = $pdo->prepare(
            'SELECT id, measured_on, sample_size, total_weight_g, notes
             FROM flock_weighings
             WHERE farm_id = :farm_id AND flock_id = :flock_id AND id = :id'
        );
        $statement->execute(['farm_id' => $farmId, 'flock_id' => $flockId, 'id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('La pesée est introuvable.');
        }
        return $row;
    }

    public static function findEggRecord(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $flockId,
        int $id
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.view');
        $statement = $pdo->prepare(
            'SELECT id, record_date, opening_stock_count, collected_count,
                    broken_count, sold_count, closing_stock_count, notes
             FROM flock_egg_records
             WHERE farm_id = :farm_id AND flock_id = :flock_id AND id = :id'
        );
        $statement->execute(['farm_id' => $farmId, 'flock_id' => $flockId, 'id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Le relevé d’œufs est introuvable.');
        }
        return $row;
    }

    public static function findTarget(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $flockId,
        int $id
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.manage');
        $statement = $pdo->prepare(
            'SELECT id, metric, target_value, valid_from, valid_until,
                    source_title, source_notes, source_url
             FROM flock_performance_targets
             WHERE farm_id = :farm_id AND flock_id = :flock_id AND id = :id'
        );
        $statement->execute(['farm_id' => $farmId, 'flock_id' => $flockId, 'id' => $id]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('L’objectif est introuvable.');
        }
        return $row;
    }

    public static function report(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $flockId,
        string $fromDate,
        string $toDate,
        DateTimeZone $timezone
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.view');
        $flock = self::flock($pdo, $farmId, $flockId, false);
        if ($fromDate > $toDate || $fromDate < (string) $flock['started_on']) {
            throw new RuntimeException('La période doit être cohérente avec les dates de l’élevage.');
        }
        $weights = self::weightRows($pdo, $farmId, $flockId, $fromDate, $toDate);
        $previous = self::weightRows(
            $pdo,
            $farmId,
            $flockId,
            (string) $flock['started_on'],
            $fromDate,
            true
        );
        $comparisonRows = $previous;
        foreach ($weights as $weight) {
            $comparisonRows[] = $weight;
        }
        usort($comparisonRows, static fn (array $a, array $b): int =>
            [$a['date'], $a['source']] <=> [$b['date'], $b['source']]
        );
        $dailyGain = null;
        if (count($comparisonRows) >= 2) {
            $first = $comparisonRows[count($comparisonRows) - 2];
            $last = $comparisonRows[count($comparisonRows) - 1];
            $days = (int) (new DateTimeImmutable($first['date']))
                ->diff(new DateTimeImmutable($last['date']))->format('%a');
            if ($days > 0) {
                $dailyGain = ($last['average_weight_g'] - $first['average_weight_g']) / $days;
            }
        }

        $isLayer = $flock['flock_type'] === 'layer' && $flock['production_type'] === 'eggs';
        $eggRows = $isLayer
            ? self::eggRows($pdo, $farmId, $flockId, $fromDate, $toDate)
            : [];
        if ($eggRows !== []) {
            self::addLiveCounts($pdo, $farmId, $flock, $eggRows, $fromDate, $toDate, $timezone);
        }
        $targets = self::targets($pdo, $farmId, $flockId, $fromDate, $toDate);
        foreach ($weights as &$weight) {
            $target = self::targetFor($targets, 'average_weight_g', (string) $weight['date']);
            $weight['target_value'] = $target === null ? null : (float) $target['target_value'];
        }
        unset($weight);
        foreach ($eggRows as &$egg) {
            $egg['laying_rate_percent'] = $egg['live_count'] > 0
                ? ((int) $egg['collected_count'] / (int) $egg['live_count']) * 100
                : null;
            $target = self::targetFor($targets, 'laying_rate_percent', (string) $egg['record_date']);
            $egg['target_value'] = $target === null ? null : (float) $target['target_value'];
        }
        unset($egg);

        $latestWeight = $weights !== [] ? $weights[count($weights) - 1] : null;
        $latestEgg = $eggRows !== [] ? $eggRows[count($eggRows) - 1] : null;
        $gainTarget = $latestWeight === null
            ? null
            : self::targetFor($targets, 'daily_gain_g', (string) $latestWeight['date']);
        return [
            'flock' => $flock,
            'weights' => $weights,
            'eggs' => $eggRows,
            'targets' => $targets,
            'latest_weight' => $latestWeight,
            'daily_gain_g' => $dailyGain,
            'daily_gain_target' => $gainTarget === null ? null : (float) $gainTarget['target_value'],
            'latest_egg' => $latestEgg,
            'is_layer' => $isLayer,
            'metrics' => self::METRICS,
        ];
    }

    private static function weightRows(
        PDO $pdo,
        int $farmId,
        int $flockId,
        string $fromDate,
        string $toDate,
        bool $latestOnly = false
    ): array {
        $limit = $latestOnly ? ' LIMIT 1' : '';
        $query = $pdo->prepare(
            'SELECT measures.id, measures.measured_on AS date, measures.sample_size,
                    measures.total_weight_g, measures.source
             FROM (
                 SELECT w.id, w.measured_on, w.sample_size, w.total_weight_g, \'weighing\' AS source
                 FROM flock_weighings w
                 WHERE w.farm_id = :weighing_farm AND w.flock_id = :weighing_flock
                   AND w.measured_on >= :weighing_from
                   AND w.measured_on ' . ($latestOnly ? '<' : '<=') . ' :weighing_to
                 UNION ALL
                 SELECT d.id, d.followup_date, NULL, d.average_weight_g, \'daily\' AS source
                 FROM daily_followups d
                 WHERE d.farm_id = :daily_farm AND d.flock_id = :daily_flock
                   AND d.followup_date >= :daily_from
                   AND d.followup_date ' . ($latestOnly ? '<' : '<=') . ' :daily_to
                   AND d.average_weight_g IS NOT NULL
                   AND NOT EXISTS (
                       SELECT 1 FROM flock_weighings w2
                       WHERE w2.farm_id = d.farm_id AND w2.flock_id = d.flock_id
                         AND w2.measured_on = d.followup_date
                   )
             ) measures
             ORDER BY measures.measured_on ' . ($latestOnly ? 'DESC' : 'ASC') . ', measures.source
             ' . $limit
        );
        $query->execute([
            'weighing_farm' => $farmId,
            'weighing_flock' => $flockId,
            'weighing_from' => $latestOnly ? '1000-01-01' : $fromDate,
            'weighing_to' => $toDate,
            'daily_farm' => $farmId,
            'daily_flock' => $flockId,
            'daily_from' => $latestOnly ? '1000-01-01' : $fromDate,
            'daily_to' => $toDate,
        ]);
        $rows = [];
        foreach ($query->fetchAll() as $row) {
            $average = $row['source'] === 'daily'
                ? (float) $row['total_weight_g']
                : (float) $row['total_weight_g'] / (int) $row['sample_size'];
            $rows[] = [
                'date' => (string) $row['date'],
                'id' => (int) $row['id'],
                'sample_size' => $row['sample_size'] === null ? null : (int) $row['sample_size'],
                'average_weight_g' => $average,
                'source' => (string) $row['source'],
            ];
        }
        if ($latestOnly) {
            return $rows;
        }
        usort($rows, static fn (array $a, array $b): int =>
            [$a['date'], $a['source']] <=> [$b['date'], $b['source']]
        );
        return $rows;
    }

    private static function eggRows(
        PDO $pdo,
        int $farmId,
        int $flockId,
        string $fromDate,
        string $toDate
    ): array {
        $statement = $pdo->prepare(
            'SELECT id, record_date, opening_stock_count, collected_count, broken_count,
                    sold_count, closing_stock_count, notes
             FROM flock_egg_records
             WHERE farm_id = :farm_id AND flock_id = :flock_id
               AND record_date >= :from_date AND record_date <= :to_date
             ORDER BY record_date, id'
        );
        $statement->execute([
            'farm_id' => $farmId,
            'flock_id' => $flockId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
        ]);
        return $statement->fetchAll();
    }

    private static function addLiveCounts(
        PDO $pdo,
        int $farmId,
        array $flock,
        array &$eggRows,
        string $fromDate,
        string $toDate,
        DateTimeZone $timezone
    ): void {
        $rangeStartUtc = (new DateTimeImmutable($fromDate . ' 00:00:00', $timezone))
            ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $rangeEndUtc = (new DateTimeImmutable($toDate . ' 00:00:00', $timezone))
            ->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $openingQuery = $pdo->prepare(
            'SELECT COALESCE(SUM(quantity_delta), 0)
             FROM animal_movements
             WHERE farm_id = :farm_id AND flock_id = :flock_id AND occurred_at < :range_start'
        );
        $openingQuery->execute([
            'farm_id' => $farmId,
            'flock_id' => $flock['id'],
            'range_start' => $rangeStartUtc,
        ]);
        $liveCount = (int) $flock['initial_count'] + (int) $openingQuery->fetchColumn();
        $movementQuery = $pdo->prepare(
            'SELECT occurred_at, quantity_delta
             FROM animal_movements
             WHERE farm_id = :farm_id AND flock_id = :flock_id
               AND occurred_at >= :range_start AND occurred_at < :range_end
             ORDER BY occurred_at, id'
        );
        $movementQuery->execute([
            'farm_id' => $farmId,
            'flock_id' => $flock['id'],
            'range_start' => $rangeStartUtc,
            'range_end' => $rangeEndUtc,
        ]);
        $movements = $movementQuery->fetchAll();
        $movementIndex = 0;
        foreach ($eggRows as &$egg) {
            $dayEndUtc = (new DateTimeImmutable((string) $egg['record_date'] . ' 00:00:00', $timezone))
                ->modify('+1 day')->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            while (isset($movements[$movementIndex])
                && (string) $movements[$movementIndex]['occurred_at'] < $dayEndUtc) {
                $liveCount += (int) $movements[$movementIndex]['quantity_delta'];
                $movementIndex++;
            }
            $egg['live_count'] = $liveCount;
        }
        unset($egg);
    }

    private static function targets(
        PDO $pdo,
        int $farmId,
        int $flockId,
        string $fromDate,
        string $toDate
    ): array {
        $statement = $pdo->prepare(
            'SELECT id, metric, target_value, valid_from, valid_until,
                    source_title, source_notes, source_url
             FROM flock_performance_targets
             WHERE farm_id = :farm_id AND flock_id = :flock_id
               AND valid_from <= :to_date
               AND (valid_until IS NULL OR valid_until >= :from_date)
             ORDER BY metric, valid_from, id'
        );
        $statement->execute([
            'farm_id' => $farmId,
            'flock_id' => $flockId,
            'to_date' => $toDate,
            'from_date' => $fromDate,
        ]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['target_value'] = (float) $row['target_value'];
        }
        unset($row);
        return $rows;
    }

    private static function targetFor(array $targets, string $metric, string $date): ?array
    {
        foreach ($targets as $target) {
            if ($target['metric'] === $metric
                && (string) $target['valid_from'] <= $date
                && ($target['valid_until'] === null || (string) $target['valid_until'] >= $date)) {
                return $target;
            }
        }
        return null;
    }

    private static function flock(PDO $pdo, int $farmId, int $flockId, bool $lock): array
    {
        $statement = $pdo->prepare(
            'SELECT id, flock_type, production_type, started_on, initial_count
             FROM flocks
             WHERE farm_id = :farm_id AND id = :flock_id' . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['farm_id' => $farmId, 'flock_id' => $flockId]);
        $flock = $statement->fetch();
        if (!is_array($flock)) {
            throw new RuntimeException('Élevage introuvable.');
        }
        return $flock;
    }

    private static function exists(
        PDO $pdo,
        string $table,
        int $farmId,
        int $flockId,
        int $id
    ): bool {
        if (!in_array($table, ['flock_weighings', 'flock_performance_targets'], true)) {
            throw new RuntimeException('Type de relevé non reconnu.');
        }
        $statement = $pdo->prepare(
            'SELECT 1 FROM ' . $table . '
             WHERE farm_id = :farm_id AND flock_id = :flock_id AND id = :id'
        );
        $statement->execute(['farm_id' => $farmId, 'flock_id' => $flockId, 'id' => $id]);
        return $statement->fetchColumn() !== false;
    }

    private static function requireValid(array $validated): void
    {
        if ($validated['errors'] !== []) {
            throw new RuntimeException(implode(' ', $validated['errors']));
        }
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private static function decimal(mixed $value): ?float
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        $string = (string) $value;
        if (!preg_match('/^\d{1,12}(?:\.\d{1,3})?$/D', $string)) {
            return null;
        }
        $number = (float) $string;
        return is_finite($number) ? $number : null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
