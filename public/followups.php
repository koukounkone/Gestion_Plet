<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\FarmAccess;
use MonElevage\Database\Connection;
use MonElevage\Followups\DailyFollowupService;
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
    exit('Accès au suivi des élevages refusé.');
}
$canWrite = FarmAccess::allows($pdo, $userId, $farmId, 'followup.write');

$errors = [];
$flash = AuthSession::takeFlash();
$flocks = DailyFollowupService::flocks($pdo, $userId, $farmId);
$flockId = filter_var($_GET['flock_id'] ?? null, FILTER_VALIDATE_INT);
$flockId = is_int($flockId) && $flockId > 0 ? $flockId : null;
$followupId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT);
$followupId = is_int($followupId) && $followupId > 0 ? $followupId : null;
$selectedRecord = null;
if ($followupId !== null) {
    try {
        $selectedRecord = DailyFollowupService::find($pdo, $userId, $farmId, $followupId);
        $flockId = (int) $selectedRecord['flock_id'];
    } catch (RuntimeException) {
        http_response_code(404);
        exit('Le suivi quotidien est introuvable.');
    }
}
if ($flockId !== null && !in_array($flockId, array_map(
    static fn (array $flock): int => (int) $flock['id'],
    $flocks
), true)) {
    http_response_code(404);
    exit('Élevage introuvable.');
}

$fromDate = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
$toDate = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
$validDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $value === '' || ($date !== false && $date->format('Y-m-d') === $value);
};
if (!$validDate($fromDate) || !$validDate($toDate)
    || ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate)) {
    http_response_code(422);
    $errors[] = 'La période de filtrage est invalide.';
    $fromDate = '';
    $toDate = '';
}

$formValues = is_array($selectedRecord) ? $selectedRecord : [
    'followup_date' => $today->format('Y-m-d'),
    'entries_count' => 0,
    'sick_count' => 0,
    'mortality_count' => 0,
    'sales_count' => 0,
    'other_exits_count' => 0,
    'average_weight_g' => '',
    'feed_consumed_kg' => '',
    'water_consumed_liters' => '',
    'temperature_celsius' => '',
    'humidity_percent' => '',
    'notes' => '',
];
$editId = is_array($selectedRecord) ? (int) $selectedRecord['id'] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthSession::verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $errors[] = 'Le formulaire a expiré ou ne peut pas être vérifié. Rechargez la page.';
    } elseif (!$canWrite) {
        http_response_code(403);
        $errors[] = 'Vous ne disposez pas des droits pour enregistrer un suivi.';
    } else {
        $postedFlockId = filter_var($_POST['flock_id'] ?? null, FILTER_VALIDATE_INT);
        $postedFollowupId = filter_var($_POST['followup_id'] ?? null, FILTER_VALIDATE_INT);
        $postedFollowupId = is_int($postedFollowupId) && $postedFollowupId > 0
            ? $postedFollowupId
            : null;
        $editId = $postedFollowupId;
        if (!is_int($postedFlockId) || $postedFlockId < 1) {
            http_response_code(422);
            $errors[] = 'Choisissez un élevage valide.';
        } else {
            $formValues = $_POST;
            $flockId = $postedFlockId;
            try {
                $savedId = DailyFollowupService::save(
                    $pdo,
                    $userId,
                    $farmId,
                    $postedFlockId,
                    $_POST,
                    $postedFollowupId,
                    $today
                );
                $saved = DailyFollowupService::find($pdo, $userId, $farmId, $savedId);
                AuthSession::flash(
                    'success',
                    $postedFollowupId === null
                        ? 'Le suivi quotidien a été enregistré.'
                        : 'Le suivi quotidien a été corrigé. Les effectifs ont été recalculés.'
                );
                header(
                    'Location: followups.php?farm_id=' . $farmId
                        . '&flock_id=' . $postedFlockId
                        . '&edit=' . $savedId
                        . '&from=' . rawurlencode((string) $saved['followup_date'])
                        . '&to=' . rawurlencode((string) $saved['followup_date']),
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

$history = DailyFollowupService::history(
    $pdo,
    $userId,
    $farmId,
    $flockId,
    $fromDate !== '' ? $fromDate : null,
    $toDate !== '' ? $toDate : null,
    $farmTimezone
);
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
$farmSwitchAction = 'followups.php';
$isLiveDashboard = true;
$isPrivateApp = true;
$pageKey = 'followups';
$page = [
    'title' => 'Suivi quotidien',
    'section' => 'OBSERVATIONS ET EFFECTIFS',
    'description' => 'Enregistrez les observations journalières et consultez leur historique.',
];
$topbarDate = (new DateTimeImmutable('now', $farmTimezone))->format('d/m/Y');
$liveViewPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Pages'
    . DIRECTORY_SEPARATOR . 'followups-content.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Layouts'
    . DIRECTORY_SEPARATOR . 'app.php';
