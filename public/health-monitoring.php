<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\FarmAccess;
use MonElevage\Database\Connection;
use MonElevage\Health\HealthMonitoringService;
use MonElevage\Support\AuthSession;

AuthSession::start();
$pdo = Connection::get($config);
$userId = AuthSession::userId($pdo);
if ($userId === null) {
    header('Location: auth.php?view=login', true, 303);
    exit;
}

$memberships = FarmAccess::memberships($pdo, $userId);
$requestedFarmId = filter_var($_GET['farm_id'] ?? null, FILTER_VALIDATE_INT);
$hasFarmId = array_key_exists('farm_id', $_GET);
if ($hasFarmId && (!is_int($requestedFarmId) || $requestedFarmId < 1)) {
    http_response_code(404);
    exit('Exploitation introuvable.');
}
$selectedFarm = null;
foreach ($memberships as $membership) {
    if ((!$hasFarmId && $selectedFarm === null)
        || (int) $membership['id'] === $requestedFarmId) {
        $selectedFarm = $membership;
        if ($hasFarmId) {
            break;
        }
    }
}
if (!is_array($selectedFarm)) {
    http_response_code(404);
    exit('Exploitation introuvable.');
}

$farmId = (int) $selectedFarm['id'];
$farmTimezone = new DateTimeZone((string) $selectedFarm['timezone']);
$now = new DateTimeImmutable('now', $farmTimezone);
$today = $now->setTime(0, 0);
$canViewHealth = FarmAccess::allows($pdo, $userId, $farmId, 'health.view');
if (!$canViewHealth) {
    http_response_code(403);
    exit('Accès au suivi sanitaire refusé.');
}
$canManage = FarmAccess::allows($pdo, $userId, $farmId, 'health.manage');
$errors = [];
$flash = AuthSession::takeFlash();
$recordValues = [
    'event_type' => 'symptom',
    'observed_on' => $today->format('Y-m-d'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthSession::verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $errors[] = 'Le formulaire a expiré ou ne peut pas être vérifié. Rechargez la page.';
    } else {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        try {
            if ($action === 'save_record') {
                if (!$canManage) {
                    http_response_code(403);
                    throw new RuntimeException('Vous ne disposez pas des droits pour enregistrer un événement sanitaire.');
                }
                $recordValues = $_POST;
                HealthMonitoringService::saveRecord(
                    $pdo,
                    $userId,
                    $farmId,
                    $_POST,
                    $today
                );
                AuthSession::flash('success', 'L’observation ou l’intervention a été enregistrée.');
            } elseif ($action === 'save_weather_settings') {
                if (!$canManage) {
                    http_response_code(403);
                    throw new RuntimeException('Vous ne disposez pas des droits pour modifier la localisation météo.');
                }
                HealthMonitoringService::saveWeatherSettings($pdo, $userId, $farmId, $_POST);
                AuthSession::flash('success', 'La localisation météo de l’exploitation a été enregistrée.');
            } elseif ($action === 'mark_notification_read') {
                $notificationId = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT);
                if (!is_int($notificationId) || $notificationId < 1) {
                    throw new RuntimeException('Notification à mettre à jour invalide.');
                }
                HealthMonitoringService::markNotificationRead(
                    $pdo,
                    $userId,
                    $farmId,
                    $notificationId
                );
            } else {
                http_response_code(400);
                throw new RuntimeException('Action sanitaire inconnue.');
            }
            header('Location: health-monitoring.php?farm_id=' . $farmId, true, 303);
            exit;
        } catch (RuntimeException $exception) {
            if (http_response_code() < 400) {
                http_response_code(422);
            }
            $errors[] = $exception->getMessage();
        } catch (PDOException $exception) {
            error_log(sprintf('Health monitoring operation failed: %s', $exception->getMessage()));
            http_response_code(500);
            $errors[] = 'L’opération n’a pas pu être enregistrée. Consultez le journal local.';
        }
    }
}

$weatherSettings = HealthMonitoringService::weatherSettings($pdo, $userId, $farmId);
$weather = HealthMonitoringService::weather($pdo, $userId, $farmId, $now);
$alerts = HealthMonitoringService::alerts($pdo, $userId, $farmId, $today, $weather);
HealthMonitoringService::syncNotifications($pdo, $userId, $farmId, $alerts);
$notifications = HealthMonitoringService::notifications($pdo, $userId, $farmId);
$records = HealthMonitoringService::records($pdo, $userId, $farmId);
$flocks = HealthMonitoringService::flocks($pdo, $userId, $farmId);
$userQuery = $pdo->prepare(
    "SELECT full_name FROM users WHERE id = :user_id AND status = 'active' LIMIT 1"
);
$userQuery->execute(['user_id' => $userId]);
$currentUserName = (string) $userQuery->fetchColumn();
$nameParts = preg_split('/\s+/u', trim($currentUserName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$userInitials = mb_strtoupper(
    mb_substr($nameParts[0] ?? 'U', 0, 1, 'UTF-8')
        . mb_substr($nameParts[1] ?? '', 0, 1, 'UTF-8'),
    'UTF-8'
);
$csrfToken = htmlspecialchars(AuthSession::csrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$currentFarmName = (string) $selectedFarm['name'];
$currentFarmId = $farmId;
$currentFarmRole = FarmAccess::role($pdo, $userId, $farmId);
$farmSwitchAction = 'health-monitoring.php';
$isLiveDashboard = true;
$isPrivateApp = true;
$pageKey = 'health-monitoring';
$page = [
    'title' => 'Santé et alertes',
    'section' => 'PRÉVENTION ET VIGILANCE',
    'description' => 'Consignez les observations et interventions, consultez la météo extérieure et suivez les alertes de l’exploitation.',
];
$topbarDate = $now->format('d/m/Y');
$liveViewPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Pages'
    . DIRECTORY_SEPARATOR . 'health-monitoring-content.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Layouts'
    . DIRECTORY_SEPARATOR . 'app.php';
