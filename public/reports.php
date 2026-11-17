<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\FarmAccess;
use MonElevage\Database\Connection;
use MonElevage\Reports\ReportService;
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
if (!FarmAccess::allows($pdo, $userId, $farmId, 'report.view')) {
    http_response_code(403);
    exit('Accès aux rapports refusé.');
}
$timezone = new DateTimeZone((string) $selectedFarm['timezone']);
$today = new DateTimeImmutable('today', $timezone);
$flocks = ReportService::flocks($pdo, $userId, $farmId);
$flockIds = array_map(static fn (array $flock): int => (int) $flock['id'], $flocks);
$errors = [];
$monthStart = $today->modify('first day of this month')->format('Y-m-d');
$fromDate = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : $monthStart;
$toDate = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : $today->format('Y-m-d');
$selectedFlockId = filter_var($_GET['flock_id'] ?? null, FILTER_VALIDATE_INT);
$selectedFlockId = is_int($selectedFlockId) && in_array($selectedFlockId, $flockIds, true)
    ? $selectedFlockId
    : null;
$historyFlockId = filter_var($_GET['history_flock_id'] ?? null, FILTER_VALIDATE_INT);
$historyFlockId = is_int($historyFlockId) && in_array($historyFlockId, $flockIds, true)
    ? $historyFlockId
    : null;
$historyFrom = is_string($_GET['history_from'] ?? null) && $_GET['history_from'] !== ''
    ? trim($_GET['history_from'])
    : null;
$historyTo = is_string($_GET['history_to'] ?? null) && $_GET['history_to'] !== ''
    ? trim($_GET['history_to'])
    : null;
$validDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

if (isset($_GET['download']) || isset($_GET['view_report'])) {
    $isInlineView = !isset($_GET['download']);
    $reportId = filter_var(
        $_GET[$isInlineView ? 'view_report' : 'download'],
        FILTER_VALIDATE_INT
    );
    if (!is_int($reportId) || $reportId < 1) {
        http_response_code(404);
        exit('Rapport introuvable ou accès refusé.');
    }
    try {
        $report = ReportService::downloadableFile($pdo, $userId, $farmId, $reportId);
    } catch (RuntimeException) {
        http_response_code(404);
        exit('Rapport introuvable ou accès refusé.');
    }
    header('Content-Type: application/pdf');
    header('Content-Length: ' . (string) filesize($report['path']));
    header('Content-Disposition: ' . ($isInlineView ? 'inline' : 'attachment')
        . '; filename="rapport-elevage-'
        . (int) $report['id'] . '-v' . (int) $report['version_number'] . '.pdf"');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    readfile($report['path']);
    exit;
}

$observation = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthSession::verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $errors[] = 'Le formulaire a expiré ou ne peut pas être vérifié. Rechargez la page.';
    } else {
        $rawFlockId = filter_var($_POST['flock_id'] ?? null, FILTER_VALIDATE_INT);
        $postFrom = is_string($_POST['from'] ?? null) ? trim($_POST['from']) : '';
        $postTo = is_string($_POST['to'] ?? null) ? trim($_POST['to']) : '';
        $observation = is_string($_POST['observation'] ?? null) ? $_POST['observation'] : '';
        $selectedFlockId = is_int($rawFlockId) && in_array($rawFlockId, $flockIds, true)
            ? $rawFlockId
            : null;
        $fromDate = $postFrom;
        $toDate = $postTo;
        if ($selectedFlockId === null || !$validDate($postFrom) || !$validDate($postTo)
            || $postFrom > $postTo || $postTo > $today->format('Y-m-d')) {
            $errors[] = 'Sélectionnez un élevage et une période passée ou en cours valide.';
        } else {
            try {
                $generated = ReportService::generate(
                    $pdo,
                    $userId,
                    $farmId,
                    $selectedFlockId,
                    $postFrom,
                    $postTo,
                    $observation,
                    new DateTimeImmutable('now', $timezone)
                );
                AuthSession::flash(
                    'success',
                    'Le PDF est généré et téléchargeable (version ' . $generated['version_number'] . ').'
                );
                header('Location: reports.php?farm_id=' . $farmId
                    . '&from=' . rawurlencode($postFrom)
                    . '&to=' . rawurlencode($postTo)
                    . '&flock_id=' . $selectedFlockId,
                    true,
                    303
                );
                exit;
            } catch (RuntimeException $exception) {
                $errors[] = $exception->getMessage();
            }
        }
    }
}

if (!$validDate($fromDate) || !$validDate($toDate) || $fromDate > $toDate) {
    $errors[] = 'La période de génération est invalide.';
    $fromDate = $monthStart;
    $toDate = $today->format('Y-m-d');
}
if (($historyFrom !== null && !$validDate($historyFrom))
    || ($historyTo !== null && !$validDate($historyTo))
    || ($historyFrom !== null && $historyTo !== null && $historyFrom > $historyTo)) {
    $errors[] = 'Les filtres de l’historique sont invalides.';
    $historyFrom = null;
    $historyTo = null;
}
$history = ReportService::listReports(
    $pdo,
    $userId,
    $farmId,
    $historyFlockId,
    $historyFrom,
    $historyTo
);
$flash = AuthSession::takeFlash();
$userQuery = $pdo->prepare(
    'SELECT full_name FROM users WHERE id = :user_id AND status = :status LIMIT 1'
);
$userQuery->execute(['user_id' => $userId, 'status' => 'active']);
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
$farmSwitchAction = 'reports.php';
$isLiveDashboard = true;
$isPrivateApp = true;
$canViewReports = true;
$pageKey = 'reports';
$page = [
    'title' => 'Rapports PDF',
    'section' => 'RAPPORTS',
    'description' => 'Générez et consultez les rapports privés de vos élevages.',
];
$topbarDate = $today->format('d/m/Y');
$liveViewPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Pages'
    . DIRECTORY_SEPARATOR . 'report-content.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Layouts'
    . DIRECTORY_SEPARATOR . 'app.php';
