<?php
declare(strict_types=1);

namespace MonElevage\Training;

use PDO;
use RuntimeException;

final class TrainingService
{
    private const STATUSES = ['draft', 'published', 'archived'];

    public static function isAdministrator(PDO $pdo, int $userId): bool
    {
        $statement = $pdo->prepare(
            "SELECT is_admin FROM users WHERE id = :user_id AND status = 'active' LIMIT 1"
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn() === 1;
    }

    public static function requireAdministrator(PDO $pdo, int $userId): void
    {
        if (!self::isAdministrator($pdo, $userId)) {
            throw new RuntimeException('Accès réservé aux administrateurs.');
        }
    }

    public static function learnerCatalog(PDO $pdo, int $userId): array
    {
        $statement = $pdo->prepare(
            "SELECT m.id AS module_id, m.slug, m.title AS module_title,
                    m.summary, m.position AS module_position,
                    l.id AS lesson_id, l.title AS lesson_title, l.objectives,
                    l.content, l.validated_advice, l.checklist, l.references_text,
                    l.position AS lesson_position, p.completed_at
             FROM training_modules m
             LEFT JOIN training_lessons l
               ON l.module_id = m.id AND l.status = 'published'
             LEFT JOIN training_progress p
               ON p.lesson_id = l.id AND p.user_id = :user_id
             WHERE m.status = 'published'
             ORDER BY m.position, m.id, l.position, l.id"
        );
        $statement->execute(['user_id' => $userId]);
        $modules = [];
        foreach ($statement->fetchAll() as $row) {
            $moduleId = (int) $row['module_id'];
            if (!isset($modules[$moduleId])) {
                $modules[$moduleId] = [
                    'id' => $moduleId,
                    'slug' => (string) $row['slug'],
                    'title' => (string) $row['module_title'],
                    'summary' => (string) $row['summary'],
                    'lessons' => [],
                    'lesson_count' => 0,
                    'completed_count' => 0,
                ];
            }
            if ($row['lesson_id'] !== null) {
                $lesson = self::lessonFromRow($row);
                $modules[$moduleId]['lessons'][] = $lesson;
                $modules[$moduleId]['lesson_count']++;
                if ($lesson['completed_at'] !== null) {
                    $modules[$moduleId]['completed_count']++;
                }
            }
        }
        foreach ($modules as &$module) {
            $module['progress'] = $module['lesson_count'] === 0
                ? null
                : (int) floor($module['completed_count'] * 100 / $module['lesson_count']);
        }
        unset($module);

        return array_values($modules);
    }

    public static function adminCatalog(PDO $pdo, int $userId): array
    {
        self::requireAdministrator($pdo, $userId);
        $statement = $pdo->query(
            'SELECT m.id AS module_id, m.slug, m.title AS module_title, m.summary,
                    m.position AS module_position, m.status AS module_status,
                    l.id AS lesson_id, l.title AS lesson_title, l.objectives,
                    l.content, l.validated_advice, l.checklist, l.references_text,
                    l.position AS lesson_position, l.status AS lesson_status
             FROM training_modules m
             LEFT JOIN training_lessons l ON l.module_id = m.id
             ORDER BY m.position, m.id, l.position, l.id'
        );
        $modules = [];
        foreach ($statement->fetchAll() as $row) {
            $moduleId = (int) $row['module_id'];
            if (!isset($modules[$moduleId])) {
                $modules[$moduleId] = [
                    'id' => $moduleId,
                    'slug' => (string) $row['slug'],
                    'title' => (string) $row['module_title'],
                    'summary' => (string) $row['summary'],
                    'position' => (int) $row['module_position'],
                    'status' => (string) $row['module_status'],
                    'lessons' => [],
                ];
            }
            if ($row['lesson_id'] !== null) {
                $lesson = self::lessonFromRow($row);
                $lesson['status'] = (string) $row['lesson_status'];
                $lesson['position'] = (int) $row['lesson_position'];
                $modules[$moduleId]['lessons'][] = $lesson;
            }
        }

        return array_values($modules);
    }

    public static function markLessonCompleted(
        PDO $pdo,
        int $userId,
        int $lessonId,
        bool $completed
    ): void {
        if ($lessonId < 1) {
            throw new RuntimeException('Leçon introuvable.');
        }
        $pdo->beginTransaction();
        try {
            $lesson = $pdo->prepare(
                "SELECT l.id
                 FROM training_lessons l
                 INNER JOIN training_modules m ON m.id = l.module_id
                 WHERE l.id = :lesson_id
                   AND l.status = 'published'
                   AND m.status = 'published'
                 FOR UPDATE"
            );
            $lesson->execute(['lesson_id' => $lessonId]);
            if ($lesson->fetchColumn() === false) {
                throw new RuntimeException('Cette leçon n’est pas disponible.');
            }

            if ($completed) {
                $statement = $pdo->prepare(
                    'INSERT INTO training_progress (user_id, lesson_id, completed_at)
                     VALUES (:user_id, :lesson_id, UTC_TIMESTAMP())
                     ON DUPLICATE KEY UPDATE completed_at = VALUES(completed_at)'
                );
            } else {
                $statement = $pdo->prepare(
                    'DELETE FROM training_progress WHERE user_id = :user_id AND lesson_id = :lesson_id'
                );
            }
            $statement->execute(['user_id' => $userId, 'lesson_id' => $lessonId]);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function saveModule(
        PDO $pdo,
        int $userId,
        array $input,
        ?int $moduleId = null
    ): int {
        self::requireAdministrator($pdo, $userId);
        $module = self::validateModule($input);
        if ($module['errors'] !== []) {
            throw new RuntimeException(implode(' ', $module['errors']));
        }
        $values = $module['values'];
        if ($values['status'] === 'published'
            && !self::hasPublishedLesson($pdo, $moduleId, $values['slug'])) {
            throw new RuntimeException('Publiez au moins une leçon avant de publier le module.');
        }
        if (self::slugExists($pdo, $values['slug'], $moduleId)) {
            throw new RuntimeException('Ce code de module est déjà utilisé.');
        }
        if ($moduleId === null) {
            $statement = $pdo->prepare(
                'INSERT INTO training_modules
                    (slug, title, summary, position, status, published_at,
                     created_by_user_id, updated_by_user_id)
                 VALUES
                    (:slug, :title, :summary, :position, :status,
                     IF(:published = \'published\', UTC_TIMESTAMP(), NULL),
                     :created_by_user_id, :updated_by_user_id)'
            );
            $statement->execute($values + [
                'published' => $values['status'],
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ]);

            return (int) $pdo->lastInsertId();
        }

        $statement = $pdo->prepare(
            'UPDATE training_modules
             SET slug = :slug, title = :title, summary = :summary, position = :position,
                 status = :status,
                 published_at = IF(:published = \'published\',
                                   COALESCE(published_at, UTC_TIMESTAMP()), NULL),
                 updated_by_user_id = :user_id
             WHERE id = :module_id'
        );
        $statement->execute($values + [
            'published' => $values['status'],
            'user_id' => $userId,
            'module_id' => $moduleId,
        ]);
        if ($statement->rowCount() === 0 && !self::moduleExists($pdo, $moduleId)) {
            throw new RuntimeException('Module introuvable.');
        }

        return $moduleId;
    }

    public static function saveLesson(
        PDO $pdo,
        int $userId,
        array $input,
        ?int $lessonId = null
    ): int {
        self::requireAdministrator($pdo, $userId);
        $lesson = self::validateLesson($input);
        if ($lesson['errors'] !== []) {
            throw new RuntimeException(implode(' ', $lesson['errors']));
        }
        $values = $lesson['values'];
        if (!self::moduleExists($pdo, $values['module_id'])) {
            throw new RuntimeException('Module introuvable.');
        }
        if ($lessonId !== null) {
            $moduleQuery = $pdo->prepare(
                'SELECT module_id FROM training_lessons WHERE id = :lesson_id LIMIT 1'
            );
            $moduleQuery->execute(['lesson_id' => $lessonId]);
            $existingModule = $moduleQuery->fetchColumn();
            if ($existingModule === false) {
                throw new RuntimeException('Leçon introuvable.');
            }
            if ((int) $existingModule !== $values['module_id']) {
                throw new RuntimeException('Une leçon existante ne peut pas être déplacée vers un autre module.');
            }
        }
        if (self::lessonTitleExists($pdo, $values['module_id'], $values['title'], $lessonId)) {
            throw new RuntimeException('Ce titre de leçon existe déjà dans le module.');
        }

        if ($lessonId === null) {
            $statement = $pdo->prepare(
                'INSERT INTO training_lessons
                    (module_id, title, objectives, content, validated_advice,
                     checklist, references_text, position, status, published_at,
                     created_by_user_id, updated_by_user_id)
                 VALUES
                    (:module_id, :title, :objectives, :content, :validated_advice,
                     :checklist, :references_text, :position, :status,
                    IF(:published = \'published\', UTC_TIMESTAMP(), NULL),
                    :created_by_user_id, :updated_by_user_id)'
            );
            $statement->execute($values + [
                'published' => $values['status'],
                'created_by_user_id' => $userId,
                'updated_by_user_id' => $userId,
            ]);

            return (int) $pdo->lastInsertId();
        }

        $statement = $pdo->prepare(
            'UPDATE training_lessons
             SET title = :title, objectives = :objectives, content = :content,
                 validated_advice = :validated_advice, checklist = :checklist,
                 references_text = :references_text, position = :position,
                 status = :status,
                 published_at = IF(:published = \'published\',
                                   COALESCE(published_at, UTC_TIMESTAMP()), NULL),
                 updated_by_user_id = :user_id
             WHERE id = :lesson_id AND module_id = :module_id'
        );
        $statement->execute($values + [
            'published' => $values['status'],
            'user_id' => $userId,
            'lesson_id' => $lessonId,
        ]);
        if ($statement->rowCount() === 0 && !self::lessonExists($pdo, $lessonId)) {
            throw new RuntimeException('Leçon introuvable.');
        }

        return $lessonId;
    }

    public static function validateModule(array $input): array
    {
        $slug = self::text($input, 'slug');
        $title = self::text($input, 'title');
        $summary = self::text($input, 'summary');
        $position = self::position($input['position'] ?? null);
        $status = self::text($input, 'status');
        $errors = [];
        if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) || strlen($slug) > 80) {
            $errors[] = 'Le code du module doit utiliser des minuscules, chiffres et tirets.';
        }
        if ($title === '' || mb_strlen($title, 'UTF-8') > 160) {
            $errors[] = 'Le titre du module est obligatoire (160 caractères maximum).';
        }
        if ($summary === '' || mb_strlen($summary, 'UTF-8') > 1000) {
            $errors[] = 'Le résumé du module est obligatoire (1 000 caractères maximum).';
        }
        if ($position === null) {
            $errors[] = 'L’ordre du module doit être un entier entre 0 et 65 535.';
        }
        if (!in_array($status, self::STATUSES, true)) {
            $errors[] = 'Le statut du module est invalide.';
        }

        return [
            'errors' => $errors,
            'values' => [
                'slug' => $slug,
                'title' => $title,
                'summary' => $summary,
                'position' => $position,
                'status' => $status,
            ],
        ];
    }

    public static function validateLesson(array $input): array
    {
        $moduleId = filter_var($input['module_id'] ?? null, FILTER_VALIDATE_INT);
        $position = self::position($input['position'] ?? null);
        $status = self::text($input, 'status');
        $fields = [
            'title' => [200, true],
            'objectives' => [12000, true],
            'content' => [60000, true],
            'validated_advice' => [12000, true],
            'checklist' => [12000, true],
            'references_text' => [8000, true],
        ];
        $values = [];
        $errors = [];
        foreach ($fields as $field => [$maxLength, $required]) {
            $value = self::text($input, $field);
            if (($required && $value === '') || mb_strlen($value, 'UTF-8') > $maxLength) {
                $errors[] = sprintf(
                    '%s est obligatoire et limité à %s caractères.',
                    self::fieldLabel($field),
                    number_format($maxLength, 0, ',', ' ')
                );
            }
            $values[$field] = $value;
        }
        if (!is_int($moduleId) || $moduleId < 1) {
            $errors[] = 'Choisissez un module existant.';
        }
        if ($position === null) {
            $errors[] = 'L’ordre de la leçon doit être un entier entre 0 et 65 535.';
        }
        if (!in_array($status, self::STATUSES, true)) {
            $errors[] = 'Le statut de la leçon est invalide.';
        }
        foreach (preg_split('/\R/u', $values['references_text']) ?: [] as $reference) {
            $reference = trim($reference);
            if ($reference !== ''
                && (!filter_var($reference, FILTER_VALIDATE_URL)
                    || !in_array(strtolower((string) parse_url($reference, PHP_URL_SCHEME)), ['https', 'http'], true))) {
                $errors[] = 'Chaque référence doit être une URL HTTP ou HTTPS valide.';
                break;
            }
        }
        $values['module_id'] = is_int($moduleId) ? $moduleId : null;
        $values['position'] = $position;
        $values['status'] = $status;

        return ['errors' => $errors, 'values' => $values];
    }

    private static function lessonFromRow(array $row): array
    {
        return [
            'id' => (int) $row['lesson_id'],
            'title' => (string) $row['lesson_title'],
            'objectives' => (string) $row['objectives'],
            'content' => (string) $row['content'],
            'validated_advice' => (string) $row['validated_advice'],
            'checklist' => (string) $row['checklist'],
            'references_text' => (string) $row['references_text'],
            'completed_at' => $row['completed_at'] ?? null,
        ];
    }

    private static function text(array $input, string $key): string
    {
        return is_string($input[$key] ?? null) ? trim($input[$key]) : '';
    }

    private static function position(mixed $value): ?int
    {
        $position = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($position) && $position >= 0 && $position <= 65535
            ? $position
            : null;
    }

    private static function fieldLabel(string $field): string
    {
        return [
            'title' => 'Le titre',
            'objectives' => 'Les objectifs',
            'content' => 'Le contenu',
            'validated_advice' => 'Les conseils',
            'checklist' => 'La checklist',
            'references_text' => 'Les références',
        ][$field] ?? $field;
    }

    private static function hasPublishedLesson(PDO $pdo, ?int $moduleId, string $slug): bool
    {
        if ($moduleId !== null) {
            $statement = $pdo->prepare(
                "SELECT COUNT(*) FROM training_lessons
                 WHERE module_id = :module_id AND status = 'published'"
            );
            $statement->execute(['module_id' => $moduleId]);
        } else {
            $statement = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM training_lessons l
                 INNER JOIN training_modules m ON m.id = l.module_id
                 WHERE m.slug = :slug AND l.status = 'published'"
            );
            $statement->execute(['slug' => $slug]);
        }

        return (int) $statement->fetchColumn() > 0;
    }

    private static function moduleExists(PDO $pdo, int $moduleId): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM training_modules WHERE id = :module_id');
        $statement->execute(['module_id' => $moduleId]);

        return (int) $statement->fetchColumn() > 0;
    }

    private static function lessonExists(PDO $pdo, int $lessonId): bool
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM training_lessons WHERE id = :lesson_id');
        $statement->execute(['lesson_id' => $lessonId]);

        return (int) $statement->fetchColumn() > 0;
    }

    private static function slugExists(PDO $pdo, string $slug, ?int $moduleId): bool
    {
        $sql = 'SELECT COUNT(*) FROM training_modules WHERE slug = :slug';
        $parameters = ['slug' => $slug];
        if ($moduleId !== null) {
            $sql .= ' AND id <> :module_id';
            $parameters['module_id'] = $moduleId;
        }
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn() > 0;
    }

    private static function lessonTitleExists(
        PDO $pdo,
        int $moduleId,
        string $title,
        ?int $lessonId
    ): bool {
        $sql = 'SELECT COUNT(*) FROM training_lessons
                WHERE module_id = :module_id AND title = :title';
        $parameters = ['module_id' => $moduleId, 'title' => $title];
        if ($lessonId !== null) {
            $sql .= ' AND id <> :lesson_id';
            $parameters['lesson_id'] = $lessonId;
        }
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn() > 0;
    }
}
