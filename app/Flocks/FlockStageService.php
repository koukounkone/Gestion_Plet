<?php
declare(strict_types=1);

namespace MonElevage\Flocks;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;
use MonElevage\Auth\FarmAccess;

final class FlockStageService
{
    private const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public static function priorities(): array
    {
        return [
            'low' => 'Faible',
            'normal' => 'Normale',
            'high' => 'Élevée',
            'urgent' => 'Urgente',
        ];
    }

    public static function validate(array $input): array
    {
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        $description = is_string($input['description'] ?? null)
            ? trim($input['description'])
            : '';
        $plannedOn = is_string($input['planned_on'] ?? null)
            ? trim($input['planned_on'])
            : '';
        $priority = is_string($input['priority'] ?? null)
            ? trim($input['priority'])
            : '';
        $errors = [];
        if ($title === '' || mb_strlen($title, 'UTF-8') > 160) {
            $errors[] = 'Le titre est obligatoire et limité à 160 caractères.';
        }
        if (mb_strlen($description, 'UTF-8') > 10000) {
            $errors[] = 'La description est limitée à 10 000 caractères.';
        }
        if (self::parseDate($plannedOn) === null) {
            $errors[] = 'Saisissez une date prévue réelle au format AAAA-MM-JJ.';
        }
        if (!in_array($priority, self::PRIORITIES, true)) {
            $errors[] = 'Choisissez une priorité valide.';
        }

        return [
            'errors' => $errors,
            'values' => [
                'title' => $title,
                'description' => $description !== '' ? $description : null,
                'planned_on' => $plannedOn,
                'priority' => $priority,
            ],
        ];
    }

    public static function listForFarm(
        PDO $pdo,
        int $userId,
        int $farmId,
        DateTimeImmutable $today,
        ?int $flockId = null
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.view');
        $sql = 'SELECT s.id, s.farm_id, s.flock_id, s.title, s.description,
                       s.planned_on, s.completed_on, s.status, s.priority,
                       s.created_at, s.updated_at, f.name AS flock_name,
                       f.code AS flock_code, f.flock_type, f.status AS flock_status,
                       CASE WHEN s.status <> \'completed\' AND s.planned_on < :today
                            THEN 1 ELSE 0 END AS is_overdue
                FROM flock_stages s
                INNER JOIN flocks f
                  ON f.farm_id = s.farm_id AND f.id = s.flock_id
                WHERE s.farm_id = :farm_id';
        $parameters = [
            'today' => $today->format('Y-m-d'),
            'farm_id' => $farmId,
        ];
        if ($flockId !== null) {
            $sql .= ' AND s.flock_id = :flock_id';
            $parameters['flock_id'] = $flockId;
        }
        $sql .= " ORDER BY
                    CASE WHEN s.status <> 'completed' AND s.planned_on < :overdue_today
                         THEN 0 WHEN s.status = 'in_progress' THEN 1
                         WHEN s.status = 'planned' THEN 2 ELSE 3 END,
                    s.planned_on, s.position, s.id";
        $parameters['overdue_today'] = $today->format('Y-m-d');
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $stages = $statement->fetchAll();
        foreach ($stages as &$stage) {
            $stage['id'] = (int) $stage['id'];
            $stage['flock_id'] = (int) $stage['flock_id'];
            $stage['is_overdue'] = (bool) $stage['is_overdue'];
            $stage['observations'] = self::observations(
                $pdo,
                $farmId,
                (int) $stage['id']
            );
            $stage['history'] = self::history($pdo, $farmId, (int) $stage['id']);
        }
        unset($stage);

        return $stages;
    }

    public static function summaryForFarm(
        PDO $pdo,
        int $userId,
        int $farmId,
        DateTimeImmutable $today
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.view');
        $statement = $pdo->prepare(
            "SELECT flock_id, COUNT(*) AS applicable_count,
                    SUM(status = 'completed') AS completed_count,
                    SUM(status = 'in_progress') AS current_count,
                    SUM(status <> 'completed' AND planned_on < :today) AS overdue_count
             FROM flock_stages
             WHERE farm_id = :farm_id
             GROUP BY flock_id"
        );
        $statement->execute([
            'today' => $today->format('Y-m-d'),
            'farm_id' => $farmId,
        ]);
        $summaries = [];
        foreach ($statement->fetchAll() as $row) {
            $total = (int) $row['applicable_count'];
            $completed = (int) $row['completed_count'];
            $summaries[(int) $row['flock_id']] = [
                'applicable_count' => $total,
                'completed_count' => $completed,
                'current_count' => (int) $row['current_count'],
                'overdue_count' => (int) $row['overdue_count'],
                'progress' => $total === 0 ? null : (int) floor($completed * 100 / $total),
            ];
        }

        return $summaries;
    }

    public static function create(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $flockId,
        array $input
    ): int {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.manage');
        $values = self::validatedValues($input);
        $pdo->beginTransaction();
        try {
            self::assertFlockWritable($pdo, $farmId, $flockId, $values['planned_on']);
            $statement = $pdo->prepare(
                "INSERT INTO flock_stages
                    (farm_id, flock_id, title, description, planned_on, priority, created_by_user_id)
                 VALUES
                    (:farm_id, :flock_id, :title, :description, :planned_on, :priority, :user_id)"
            );
            $statement->execute([
                'farm_id' => $farmId,
                'flock_id' => $flockId,
                'title' => $values['title'],
                'description' => $values['description'],
                'planned_on' => $values['planned_on'],
                'priority' => $values['priority'],
                'user_id' => $userId,
            ]);
            $stageId = (int) $pdo->lastInsertId();
            self::recordHistory(
                $pdo,
                $farmId,
                $stageId,
                'created',
                null,
                $values['planned_on'],
                null,
                'planned',
                $userId
            );
            $pdo->commit();

            return $stageId;
        } catch (Throwable $exception) {
            self::rollback($pdo);
            throw $exception;
        }
    }

    public static function update(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $stageId,
        array $input
    ): void {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.manage');
        $values = self::validatedValues($input);
        $pdo->beginTransaction();
        try {
            $stage = self::lockStage($pdo, $farmId, $stageId);
            self::assertFlockWritable($pdo, $farmId, (int) $stage['flock_id'], $values['planned_on']);
            if ($stage['status'] === 'completed') {
                throw new RuntimeException('Une étape terminée ne peut plus être modifiée.');
            }
            $update = $pdo->prepare(
                'UPDATE flock_stages
                 SET title = :title, description = :description,
                     planned_on = :planned_on, priority = :priority
                 WHERE farm_id = :farm_id AND id = :stage_id'
            );
            $update->execute([
                'title' => $values['title'],
                'description' => $values['description'],
                'planned_on' => $values['planned_on'],
                'priority' => $values['priority'],
                'farm_id' => $farmId,
                'stage_id' => $stageId,
            ]);
            $event = $stage['planned_on'] !== $values['planned_on']
                ? 'rescheduled'
                : 'updated';
            self::recordHistory(
                $pdo,
                $farmId,
                $stageId,
                $event,
                (string) $stage['planned_on'],
                $values['planned_on'],
                (string) $stage['status'],
                (string) $stage['status'],
                $userId
            );
            $pdo->commit();
        } catch (Throwable $exception) {
            self::rollback($pdo);
            throw $exception;
        }
    }

    public static function changeStatus(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $stageId,
        string $action,
        DateTimeImmutable $today,
        ?string $completedOn = null
    ): void {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.manage');
        $transitions = [
            'start' => ['planned', 'in_progress', 'started'],
            'complete' => [['planned', 'in_progress'], 'completed', 'completed'],
            'reopen' => ['completed', 'planned', 'reopened'],
        ];
        if (!isset($transitions[$action])) {
            throw new RuntimeException('Action d’étape invalide.');
        }
        [$allowedStatuses, $newStatus, $event] = $transitions[$action];
        $completedDate = $action === 'complete'
            ? self::parseDate($completedOn ?? $today->format('Y-m-d'))
            : null;
        if ($action === 'complete'
            && ($completedDate === null
                || $completedDate->format('Y-m-d') > $today->format('Y-m-d'))) {
            throw new RuntimeException('La date de réalisation doit être une date valide qui n’est pas dans le futur.');
        }

        $pdo->beginTransaction();
        try {
            $stage = self::lockStage($pdo, $farmId, $stageId);
            self::assertFlockWritable(
                $pdo,
                $farmId,
                (int) $stage['flock_id'],
                (string) $stage['planned_on']
            );
            if ($completedDate !== null
                && $completedDate->format('Y-m-d') < (string) $stage['started_on']) {
                throw new RuntimeException('La date de réalisation ne peut pas précéder le début de l’élevage.');
            }
            $statusIsAllowed = is_array($allowedStatuses)
                ? in_array($stage['status'], $allowedStatuses, true)
                : $stage['status'] === $allowedStatuses;
            if (!$statusIsAllowed) {
                throw new RuntimeException('Cette action n’est pas disponible pour le statut actuel de l’étape.');
            }
            $update = $pdo->prepare(
                'UPDATE flock_stages
                 SET status = :status, completed_on = :completed_on
                 WHERE farm_id = :farm_id AND id = :stage_id'
            );
            $update->execute([
                'status' => $newStatus,
                'completed_on' => $completedDate?->format('Y-m-d'),
                'farm_id' => $farmId,
                'stage_id' => $stageId,
            ]);
            self::recordHistory(
                $pdo,
                $farmId,
                $stageId,
                $event,
                (string) $stage['planned_on'],
                (string) $stage['planned_on'],
                (string) $stage['status'],
                $newStatus,
                $userId
            );
            $pdo->commit();
        } catch (Throwable $exception) {
            self::rollback($pdo);
            throw $exception;
        }
    }

    public static function reschedule(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $stageId,
        string $newDate,
        string $reason
    ): void {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.manage');
        $date = self::parseDate($newDate);
        $reason = trim($reason);
        if ($date === null) {
            throw new RuntimeException('La nouvelle date prévue est invalide.');
        }
        if ($reason === '' || mb_strlen($reason, 'UTF-8') > 500) {
            throw new RuntimeException('Indiquez un motif de report (500 caractères maximum).');
        }
        $pdo->beginTransaction();
        try {
            $stage = self::lockStage($pdo, $farmId, $stageId);
            self::assertFlockWritable($pdo, $farmId, (int) $stage['flock_id'], $newDate);
            if ($stage['status'] === 'completed') {
                throw new RuntimeException('Une étape terminée ne peut pas être reportée.');
            }
            if ($stage['planned_on'] === $newDate) {
                throw new RuntimeException('Choisissez une date différente pour reporter cette étape.');
            }
            $update = $pdo->prepare(
                'UPDATE flock_stages SET planned_on = :planned_on
                 WHERE farm_id = :farm_id AND id = :stage_id'
            );
            $update->execute([
                'planned_on' => $newDate,
                'farm_id' => $farmId,
                'stage_id' => $stageId,
            ]);
            self::recordHistory(
                $pdo,
                $farmId,
                $stageId,
                'rescheduled',
                (string) $stage['planned_on'],
                $newDate,
                (string) $stage['status'],
                (string) $stage['status'],
                $userId,
                $reason
            );
            $pdo->commit();
        } catch (Throwable $exception) {
            self::rollback($pdo);
            throw $exception;
        }
    }

    public static function addObservation(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $stageId,
        string $observation
    ): void {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'followup.write');
        $observation = trim($observation);
        if ($observation === '' || mb_strlen($observation, 'UTF-8') > 4000) {
            throw new RuntimeException('L’observation est obligatoire et limitée à 4 000 caractères.');
        }
        $pdo->beginTransaction();
        try {
            $stage = self::lockStage($pdo, $farmId, $stageId);
            self::assertFlockWritable(
                $pdo,
                $farmId,
                (int) $stage['flock_id'],
                (string) $stage['planned_on']
            );
            $statement = $pdo->prepare(
                'INSERT INTO flock_stage_observations
                    (farm_id, stage_id, observation, created_by_user_id)
                 VALUES (:farm_id, :stage_id, :observation, :user_id)'
            );
            $statement->execute([
                'farm_id' => $farmId,
                'stage_id' => $stageId,
                'observation' => $observation,
                'user_id' => $userId,
            ]);
            $pdo->commit();
        } catch (Throwable $exception) {
            self::rollback($pdo);
            throw $exception;
        }
    }

    private static function validatedValues(array $input): array
    {
        $validated = self::validate($input);
        if ($validated['errors'] !== []) {
            throw new RuntimeException(implode(' ', $validated['errors']));
        }

        return $validated['values'];
    }

    private static function lockStage(PDO $pdo, int $farmId, int $stageId): array
    {
        $statement = $pdo->prepare(
            'SELECT s.id, s.flock_id, s.planned_on, s.status, f.started_on
             FROM flock_stages s
             INNER JOIN flocks f ON f.farm_id = s.farm_id AND f.id = s.flock_id
             WHERE s.farm_id = :farm_id AND s.id = :stage_id
             FOR UPDATE'
        );
        $statement->execute(['farm_id' => $farmId, 'stage_id' => $stageId]);
        $stage = $statement->fetch();
        if (!is_array($stage)) {
            throw new RuntimeException('Étape introuvable.');
        }

        return $stage;
    }

    private static function assertFlockWritable(
        PDO $pdo,
        int $farmId,
        int $flockId,
        string $plannedOn
    ): void {
        $statement = $pdo->prepare(
            'SELECT started_on, status FROM flocks
             WHERE farm_id = :farm_id AND id = :flock_id
             LIMIT 1
             FOR UPDATE'
        );
        $statement->execute(['farm_id' => $farmId, 'flock_id' => $flockId]);
        $flock = $statement->fetch();
        if (!is_array($flock)) {
            throw new RuntimeException('Élevage introuvable.');
        }
        if ($flock['status'] === 'closed') {
            throw new RuntimeException('Le calendrier d’un élevage archivé est en lecture seule.');
        }
        if ($plannedOn < $flock['started_on']) {
            throw new RuntimeException('La date prévue ne peut pas précéder le début de l’élevage.');
        }
    }

    private static function observations(PDO $pdo, int $farmId, int $stageId): array
    {
        $statement = $pdo->prepare(
            'SELECT o.observation, o.created_at, u.full_name AS author_name
             FROM flock_stage_observations o
             INNER JOIN users u ON u.id = o.created_by_user_id
             WHERE o.farm_id = :farm_id AND o.stage_id = :stage_id
             ORDER BY o.created_at DESC, o.id DESC'
        );
        $statement->execute(['farm_id' => $farmId, 'stage_id' => $stageId]);

        return $statement->fetchAll();
    }

    private static function history(PDO $pdo, int $farmId, int $stageId): array
    {
        $statement = $pdo->prepare(
            'SELECT h.event_type, h.old_planned_on, h.new_planned_on,
                    h.old_status, h.new_status, h.reason, h.created_at,
                    u.full_name AS actor_name
             FROM flock_stage_history h
             INNER JOIN users u ON u.id = h.actor_user_id
             WHERE h.farm_id = :farm_id AND h.stage_id = :stage_id
             ORDER BY h.created_at DESC, h.id DESC'
        );
        $statement->execute(['farm_id' => $farmId, 'stage_id' => $stageId]);

        return $statement->fetchAll();
    }

    private static function recordHistory(
        PDO $pdo,
        int $farmId,
        int $stageId,
        string $event,
        ?string $oldDate,
        ?string $newDate,
        ?string $oldStatus,
        ?string $newStatus,
        int $userId,
        ?string $reason = null
    ): void {
        $statement = $pdo->prepare(
            'INSERT INTO flock_stage_history
                (farm_id, stage_id, event_type, old_planned_on, new_planned_on,
                 old_status, new_status, reason, actor_user_id)
             VALUES
                (:farm_id, :stage_id, :event_type, :old_planned_on, :new_planned_on,
                 :old_status, :new_status, :reason, :user_id)'
        );
        $statement->execute([
            'farm_id' => $farmId,
            'stage_id' => $stageId,
            'event_type' => $event,
            'old_planned_on' => $oldDate,
            'new_planned_on' => $newDate,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'reason' => $reason,
            'user_id' => $userId,
        ]);
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

    private static function rollback(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
}
