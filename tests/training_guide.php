<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Database\Connection;
use MonElevage\Training\TrainingService;

$pdo = Connection::get($config);
$suffix = bin2hex(random_bytes(5));
$userIds = [];
$moduleId = 0;
$lessonId = 0;
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
    $pdo->beginTransaction();
    foreach ([['Administrateur guide', 1], ['Éleveur guide', 0], ['Autre éleveur', 0]] as [$name, $isAdmin]) {
        $user = $pdo->prepare(
            "INSERT INTO users
                (full_name, email, password_hash, status, is_admin, email_verified_at)
             VALUES (:name, :email, 'test-hash', 'active', :is_admin, UTC_TIMESTAMP())"
        );
        $user->execute([
            'name' => $name,
            'email' => 'training-' . $suffix . '-' . count($userIds) . '@example.test',
            'is_admin' => $isAdmin,
        ]);
        $userIds[] = (int) $pdo->lastInsertId();
    }
    $pdo->commit();

    $adminId = $userIds[0];
    $learnerId = $userIds[1];
    $otherLearnerId = $userIds[2];
    $assert(TrainingService::isAdministrator($pdo, $adminId), 'reconnaît un administrateur actif');
    $assert(!TrainingService::isAdministrator($pdo, $learnerId), 'ne confond pas un compte éleveur avec un administrateur');
    $expectFailure(
        static fn () => TrainingService::adminCatalog($pdo, $learnerId),
        'interdit à un éleveur de consulter la gestion des contenus'
    );

    $moduleInput = [
        'slug' => 'module-test-' . $suffix,
        'title' => 'Module de test',
        'summary' => 'Résumé pédagogique de test.',
        'position' => '1',
        'status' => 'draft',
    ];
    $expectFailure(
        static fn () => TrainingService::saveModule($pdo, $learnerId, $moduleInput),
        'refuse la création de module sans permission administrateur'
    );
    $moduleId = TrainingService::saveModule($pdo, $adminId, $moduleInput);
    $expectFailure(
        static fn () => TrainingService::saveModule(
            $pdo,
            $adminId,
            array_merge($moduleInput, ['slug' => 'module-test-' . $suffix, 'status' => 'published']),
            $moduleId
        ),
        'refuse de publier un module sans leçon publiée'
    );

    $lessonInput = [
        'module_id' => (string) $moduleId,
        'title' => 'Leçon publique de test',
        'objectives' => "Comprendre l’objectif\nEnregistrer une action",
        'content' => 'Contenu pédagogique de test sans recommandation vétérinaire.',
        'validated_advice' => 'Suivre les sources officielles locales.',
        'checklist' => "Vérifier le registre\nNoter la date",
        'references_text' => 'https://www.fao.org/poultry-production-products/en/',
        'position' => '1',
        'status' => 'published',
    ];
    $lessonId = TrainingService::saveLesson($pdo, $adminId, $lessonInput);
    $module = TrainingService::saveModule(
        $pdo,
        $adminId,
        array_merge($moduleInput, ['status' => 'published']),
        $moduleId
    );
    $assert($module === $moduleId, 'publie le module après publication de sa leçon');

    $draftLesson = TrainingService::saveLesson(
        $pdo,
        $adminId,
        array_merge($lessonInput, [
            'title' => 'Leçon brouillon de test',
            'status' => 'draft',
            'position' => '2',
        ])
    );
    $catalog = TrainingService::learnerCatalog($pdo, $learnerId);
    $testModule = current(array_filter(
        $catalog,
        static fn (array $module): bool => $module['id'] === $moduleId
    ));
    $assert(is_array($testModule), 'affiche un module publié à un utilisateur connecté');
    $assert($testModule['lesson_count'] === 1, 'n’affiche que les leçons publiées');
    $assert($testModule['lessons'][0]['title'] === 'Leçon publique de test', 'affiche le contenu de la leçon publiée');
    $assert($testModule['completed_count'] === 0 && $testModule['progress'] === 0, 'initialise la progression utilisateur à zéro');

    TrainingService::markLessonCompleted($pdo, $learnerId, $lessonId, true);
    $catalog = TrainingService::learnerCatalog($pdo, $learnerId);
    $testModule = current(array_filter(
        $catalog,
        static fn (array $module): bool => $module['id'] === $moduleId
    ));
    $assert($testModule['completed_count'] === 1 && $testModule['progress'] === 100, 'enregistre et calcule la progression du compte');
    $otherCatalog = TrainingService::learnerCatalog($pdo, $otherLearnerId);
    $otherModule = current(array_filter(
        $otherCatalog,
        static fn (array $module): bool => $module['id'] === $moduleId
    ));
    $assert($otherModule['completed_count'] === 0, 'isole la progression entre deux comptes');
    TrainingService::markLessonCompleted($pdo, $learnerId, $lessonId, false);
    $catalog = TrainingService::learnerCatalog($pdo, $learnerId);
    $testModule = current(array_filter(
        $catalog,
        static fn (array $module): bool => $module['id'] === $moduleId
    ));
    $assert($testModule['completed_count'] === 0, 'permet de corriger sa propre progression');

    $expectFailure(
        static fn () => TrainingService::markLessonCompleted($pdo, $learnerId, $draftLesson, true),
        'ne permet pas de terminer une leçon brouillon'
    );
    $expectFailure(
        static fn () => TrainingService::saveLesson(
            $pdo,
            $adminId,
            array_merge($lessonInput, [
                'title' => 'Référence invalide',
                'references_text' => 'javascript:alert(1)',
            ])
        ),
        'rejette les références non HTTP(S)'
    );
    $expectFailure(
        static fn () => TrainingService::saveLesson(
            $pdo,
            $adminId,
            array_merge($lessonInput, ['module_id' => '999999999'])
        ),
        'refuse un module inexistant'
    );

    TrainingService::saveModule(
        $pdo,
        $adminId,
        array_merge($moduleInput, ['status' => 'archived']),
        $moduleId
    );
    $catalog = TrainingService::learnerCatalog($pdo, $learnerId);
    $assert(
        !in_array($moduleId, array_column($catalog, 'id'), true),
        'masque un module archivé du guide public'
    );
    $adminCatalog = TrainingService::adminCatalog($pdo, $adminId);
    $assert(
        count(array_filter($adminCatalog, static fn (array $module): bool => $module['id'] === $moduleId)) === 1,
        'conserve les contenus archivés dans la gestion administrateur'
    );
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($moduleId > 0) {
        $pdo->beginTransaction();
        $pdo->prepare(
            'DELETE p FROM training_progress p
             INNER JOIN training_lessons l ON l.id = p.lesson_id
             WHERE l.module_id = :module_id'
        )->execute(['module_id' => $moduleId]);
        $pdo->prepare('DELETE FROM training_lessons WHERE module_id = :module_id')
            ->execute(['module_id' => $moduleId]);
        $pdo->prepare('DELETE FROM training_modules WHERE id = :module_id')
            ->execute(['module_id' => $moduleId]);
    }
    foreach ($userIds as $userId) {
        $pdo->prepare('DELETE FROM users WHERE id = :user_id')->execute(['user_id' => $userId]);
    }
    if ($pdo->inTransaction()) {
        $pdo->commit();
    }
}

printf("OK — %d assertions du guide de formation.\n", $passed);
