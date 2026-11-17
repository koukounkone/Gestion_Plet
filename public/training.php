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

$errors = [];
$flash = AuthSession::takeFlash();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthSession::verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $errors[] = 'Le formulaire a expiré ou ne peut pas être vérifié. Rechargez la page.';
    } else {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        $lessonId = filter_var($_POST['lesson_id'] ?? null, FILTER_VALIDATE_INT);
        if (!in_array($action, ['complete', 'reopen'], true)
            || !is_int($lessonId)
            || $lessonId < 1) {
            http_response_code(400);
            $errors[] = 'Action ou leçon invalide.';
        } else {
            try {
                TrainingService::markLessonCompleted(
                    $pdo,
                    $userId,
                    $lessonId,
                    $action === 'complete'
                );
                AuthSession::flash(
                    'success',
                    $action === 'complete'
                        ? 'Leçon marquée comme terminée.'
                        : 'Leçon retirée de votre progression.'
                );
                $moduleSlug = is_string($_POST['module_slug'] ?? null)
                    ? $_POST['module_slug']
                    : '';
                header(
                    'Location: training.php' . ($moduleSlug !== ''
                        ? '?module=' . rawurlencode($moduleSlug)
                        : ''),
                    true,
                    303
                );
                exit;
            } catch (RuntimeException $exception) {
                http_response_code(422);
                $errors[] = $exception->getMessage();
            }
        }
    }
}

$modules = TrainingService::learnerCatalog($pdo, $userId);
$selectedSlug = is_string($_GET['module'] ?? null) ? $_GET['module'] : '';
$selectedModule = null;
if ($selectedSlug !== '') {
    foreach ($modules as $module) {
        if ($module['slug'] === $selectedSlug) {
            $selectedModule = $module;
            break;
        }
    }
    if ($selectedModule === null) {
        http_response_code(404);
        exit('Module introuvable.');
    }
}
$csrfToken = htmlspecialchars(
    AuthSession::csrfToken(),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$currentUserName = '';
$userQuery = $pdo->prepare('SELECT full_name FROM users WHERE id = :user_id');
$userQuery->execute(['user_id' => $userId]);
$currentUserName = (string) $userQuery->fetchColumn();
$nameParts = preg_split('/\s+/u', trim($currentUserName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$userInitials = mb_strtoupper(
    mb_substr($nameParts[0] ?? 'U', 0, 1, 'UTF-8')
        . mb_substr($nameParts[1] ?? '', 0, 1, 'UTF-8'),
    'UTF-8'
);
$memberships = [];
$currentFarmName = 'Guide de formation';
$topbarDate = (new DateTimeImmutable())->format('d/m/Y');
$pageKey = 'training';
$isLiveDashboard = true;
$isTraining = true;
$isTrainingAdmin = TrainingService::isAdministrator($pdo, $userId);
$isPrivateApp = true;
$page = [
    'title' => $selectedModule['title'] ?? 'Guide de formation',
    'section' => 'APPRENDRE ET PROGRESSER',
    'description' => 'Des repères pratiques et des ressources pour organiser votre élevage.',
];
$liveViewPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Pages'
    . DIRECTORY_SEPARATOR . 'training-content.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self' https://www.fao.org https://www.woah.org; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Layouts'
    . DIRECTORY_SEPARATOR . 'app.php';
