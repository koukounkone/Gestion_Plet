<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Database\Connection;
use MonElevage\Support\AuthSession;
use MonElevage\Training\TrainingService;

AuthSession::start();
$pdo = Connection::get($config);
$userId = AuthSession::userId($pdo);
if ($userId === null) {
    header('Location: auth.php?view=login', true, 303);
    exit;
}
try {
    TrainingService::requireAdministrator($pdo, $userId);
} catch (RuntimeException) {
    http_response_code(403);
    exit('Accès réservé aux administrateurs.');
}

$errors = [];
$flash = AuthSession::takeFlash();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthSession::verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $errors[] = 'Le formulaire a expiré ou ne peut pas être vérifié. Rechargez la page.';
    } else {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        try {
            if ($action === 'module_save') {
                $moduleId = filter_var($_POST['module_id'] ?? null, FILTER_VALIDATE_INT);
                $moduleId = is_int($moduleId) && $moduleId > 0 ? $moduleId : null;
                $savedId = TrainingService::saveModule($pdo, $userId, $_POST, $moduleId);
                AuthSession::flash('success', 'Le module a été enregistré.');
                header('Location: admin-training.php#module-' . $savedId, true, 303);
                exit;
            }
            if ($action === 'lesson_save') {
                $lessonId = filter_var($_POST['lesson_id'] ?? null, FILTER_VALIDATE_INT);
                $lessonId = is_int($lessonId) && $lessonId > 0 ? $lessonId : null;
                $savedId = TrainingService::saveLesson($pdo, $userId, $_POST, $lessonId);
                $moduleId = filter_var($_POST['module_id'] ?? null, FILTER_VALIDATE_INT);
                AuthSession::flash('success', 'La leçon a été enregistrée.');
                header(
                    'Location: admin-training.php#module-' . (is_int($moduleId) ? $moduleId : 0)
                        . '-lesson-' . $savedId,
                    true,
                    303
                );
                exit;
            }
            throw new RuntimeException('Action de gestion inconnue.');
        } catch (RuntimeException $exception) {
            http_response_code(422);
            $errors[] = $exception->getMessage();
        }
    }
}

$modules = TrainingService::adminCatalog($pdo, $userId);
$csrfToken = htmlspecialchars(
    AuthSession::csrfToken(),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$userQuery = $pdo->prepare('SELECT full_name FROM users WHERE id = :user_id');
$userQuery->execute(['user_id' => $userId]);
$currentUserName = (string) $userQuery->fetchColumn();
$nameParts = preg_split('/\s+/u', trim($currentUserName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$userInitials = mb_strtoupper(
    mb_substr($nameParts[0] ?? 'A', 0, 1, 'UTF-8')
        . mb_substr($nameParts[1] ?? '', 0, 1, 'UTF-8'),
    'UTF-8'
);
$memberships = [];
$currentFarmName = 'Administration du guide';
$topbarDate = (new DateTimeImmutable())->format('d/m/Y');
$pageKey = 'training-admin';
$isLiveDashboard = true;
$isTraining = true;
$isTrainingAdmin = true;
$isPrivateApp = true;
$page = [
    'title' => 'Gestion du guide',
    'section' => 'ADMINISTRATION',
    'description' => 'Rédigez, révisez et publiez les modules et leurs leçons.',
];
$liveViewPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Pages'
    . DIRECTORY_SEPARATOR . 'admin-training-content.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Layouts'
    . DIRECTORY_SEPARATOR . 'app.php';
