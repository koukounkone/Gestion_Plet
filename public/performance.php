<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\FarmAccess;
use MonElevage\Database\Connection;
use MonElevage\Performance\PerformanceService;
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
$today = new DateTimeImmutable('today', $farmTimezone);
$canViewFlocks = FarmAccess::allows($pdo, $userId, $farmId, 'flock.view');
if (!$canViewFlocks) {
    http_response_code(403);
    exit('Accès aux performances des élevages refusé.');
}
$canWrite = FarmAccess::allows($pdo, $userId, $farmId, 'followup.write');
$canManage = FarmAccess::allows($pdo, $userId, $farmId, 'flock.manage');
$flocks = PerformanceService::flocks($pdo, $userId, $farmId);
$flockId = filter_var($_GET['flock_id'] ?? null, FILTER_VALIDATE_INT);
$flockId = is_int($flockId) && $flockId > 0 ? $flockId : null;
if ($flockId === null && $flocks !== []) {
    foreach ($flocks as $flock) {
        if (in_array($flock['status'], ['active', 'paused'], true)) {
            $flockId = (int) $flock['id'];
            break;
        }
    }
    $flockId ??= (int) $flocks[0]['id'];
}
if ($flockId !== null && !in_array($flockId, array_map(
    static fn (array $flock): int => (int) $flock['id'],
    $flocks
), true)) {
    http_response_code(404);
    exit('Élevage introuvable.');
}

$errors = [];
$flash = AuthSession::takeFlash();
$fromDefault = $today->modify('-30 days')->format('Y-m-d');
$selectedFlock = null;
foreach ($flocks as $candidate) {
    if ((int) $candidate['id'] === (int) ($flockId ?? 0)) {
        $selectedFlock = $candidate;
        if ($fromDefault < (string) $candidate['started_on']) {
            $fromDefault = (string) $candidate['started_on'];
        }
        break;
    }
}
$fromDate = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : $fromDefault;
$toDate = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : $today->format('Y-m-d');
$validDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
if (!$validDate($fromDate) || !$validDate($toDate) || $fromDate > $toDate
    || $fromDate > $today->format('Y-m-d') || $toDate > $today->format('Y-m-d')
    || (is_array($selectedFlock) && $fromDate < (string) $selectedFlock['started_on'])) {
    http_response_code(422);
    $errors[] = 'La période de filtrage est invalide.';
    $fromDate = $fromDefault;
    $toDate = $today->format('Y-m-d');
}

$weighingEditId = filter_var($_GET['edit_weight'] ?? null, FILTER_VALIDATE_INT);
$weighingEditId = is_int($weighingEditId) && $weighingEditId > 0 ? $weighingEditId : null;
$eggEditId = filter_var($_GET['edit_eggs'] ?? null, FILTER_VALIDATE_INT);
$eggEditId = is_int($eggEditId) && $eggEditId > 0 ? $eggEditId : null;
$targetEditId = filter_var($_GET['edit_target'] ?? null, FILTER_VALIDATE_INT);
$targetEditId = is_int($targetEditId) && $targetEditId > 0 ? $targetEditId : null;
$editWeight = null;
$editEgg = null;
$editTarget = null;
if ($flockId !== null && $targetEditId !== null) {
    if (!$canManage) {
        http_response_code(403);
        exit('La gestion des objectifs est réservée aux responsables autorisés.');
    }
}
try {
    if ($flockId !== null && $weighingEditId !== null) {
        $editWeight = PerformanceService::findWeighing(
            $pdo,
            $userId,
            $farmId,
            $flockId,
            $weighingEditId
        );
    }
    if ($flockId !== null && $eggEditId !== null) {
        $editEgg = PerformanceService::findEggRecord(
            $pdo,
            $userId,
            $farmId,
            $flockId,
            $eggEditId
        );
    }
    if ($flockId !== null && $targetEditId !== null) {
        $editTarget = PerformanceService::findTarget(
            $pdo,
            $userId,
            $farmId,
            $flockId,
            $targetEditId
        );
    }
} catch (RuntimeException) {
    http_response_code(404);
    exit('Le relevé ou l’objectif demandé est introuvable.');
}

$weighingValues = is_array($editWeight) ? $editWeight : [
    'measured_on' => $today->format('Y-m-d'),
    'sample_size' => '',
    'total_weight_g' => '',
    'notes' => '',
];
$eggValues = is_array($editEgg) ? $editEgg : [
    'record_date' => $today->format('Y-m-d'),
    'opening_stock_count' => 0,
    'collected_count' => '',
    'broken_count' => 0,
    'sold_count' => 0,
    'notes' => '',
];
$targetValues = is_array($editTarget) ? $editTarget : [
    'metric' => 'average_weight_g',
    'target_value' => '',
    'valid_from' => $today->format('Y-m-d'),
    'valid_until' => '',
    'source_title' => '',
    'source_notes' => '',
    'source_url' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthSession::verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $errors[] = 'Le formulaire a expiré ou ne peut pas être vérifié. Rechargez la page.';
    } elseif ($flockId === null || !$canWrite) {
        http_response_code(403);
        $errors[] = 'Vous ne disposez pas des droits nécessaires pour enregistrer ces mesures.';
    } else {
        $postedFlockId = filter_var($_POST['flock_id'] ?? null, FILTER_VALIDATE_INT);
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        if (!is_int($postedFlockId) || $postedFlockId !== $flockId) {
            http_response_code(400);
            $errors[] = 'L’élevage sélectionné ne correspond pas à la requête.';
        } else {
            try {
                if ($action === 'save_weighing') {
                    $weighingValues = $_POST;
                    $postedId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT);
                    $postedId = is_int($postedId) && $postedId > 0 ? $postedId : null;
                    PerformanceService::saveWeighing(
                        $pdo,
                        $userId,
                        $farmId,
                        $flockId,
                        $_POST,
                        $postedId,
                        $today
                    );
                    AuthSession::flash('success', $postedId === null
                        ? 'La pesée a été enregistrée.'
                        : 'La pesée a été corrigée.');
                } elseif ($action === 'save_eggs') {
                    $eggValues = $_POST;
                    $postedId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT);
                    $postedId = is_int($postedId) && $postedId > 0 ? $postedId : null;
                    PerformanceService::saveEggRecord(
                        $pdo,
                        $userId,
                        $farmId,
                        $flockId,
                        $_POST,
                        $postedId,
                        $today
                    );
                    AuthSession::flash('success', $postedId === null
                        ? 'Le relevé de ponte et son solde de stock ont été enregistrés.'
                        : 'Le relevé de ponte a été corrigé ; les soldes suivants ont été recalculés.');
                } elseif ($action === 'save_target') {
                    if (!$canManage) {
                        http_response_code(403);
                        throw new RuntimeException('Seuls les responsables autorisés peuvent gérer les objectifs.');
                    }
                    $targetValues = $_POST;
                    $postedId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT);
                    $postedId = is_int($postedId) && $postedId > 0 ? $postedId : null;
                    PerformanceService::saveTarget(
                        $pdo,
                        $userId,
                        $farmId,
                        $flockId,
                        $_POST,
                        $postedId,
                        $today
                    );
                    AuthSession::flash('success', $postedId === null
                        ? 'L’objectif documenté a été enregistré.'
                        : 'L’objectif documenté a été mis à jour.');
                } else {
                    http_response_code(400);
                    throw new RuntimeException('Action de performance inconnue.');
                }
                header(
                    'Location: performance.php?farm_id=' . $farmId
                        . '&flock_id=' . $flockId
                        . '&from=' . rawurlencode($fromDate)
                        . '&to=' . rawurlencode($toDate),
                    true,
                    303
                );
                exit;
            } catch (RuntimeException $exception) {
                if (str_contains($exception->getMessage(), 'Permission insuffisante')) {
                    http_response_code(403);
                } elseif (http_response_code() < 400) {
                    http_response_code(422);
                }
                $errors[] = $exception->getMessage();
            }
        }
    }
}

$report = null;
if ($flockId !== null) {
    $report = PerformanceService::report(
        $pdo,
        $userId,
        $farmId,
        $flockId,
        $fromDate,
        $toDate,
        $farmTimezone
    );
}

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
$farmSwitchAction = 'performance.php';
$isLiveDashboard = true;
$isPrivateApp = true;
$pageKey = 'performance';
$page = [
    'title' => 'Croissance et performances',
    'section' => 'MESURES ET PRODUCTION',
    'description' => 'Consignez les pesées et, pour les pondeuses, les relevés d’œufs à partir de données réellement observées.',
];
$topbarDate = $today->format('d/m/Y');
$liveViewPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Pages'
    . DIRECTORY_SEPARATOR . 'performance-content.php';
$extraScripts = ['assets/vendor/chart.umd.js', 'assets/js/performance.js'];

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Layouts'
    . DIRECTORY_SEPARATOR . 'app.php';
