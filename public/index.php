<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Database\Connection;
use MonElevage\Auth\FarmAccess;
use MonElevage\Dashboard\DashboardService;
use MonElevage\Support\AuthSession;

AuthSession::start();
$pdo = Connection::get($config);
$userId = AuthSession::userId($pdo);
if ($userId === null) {
    header('Location: auth.php?view=login', true, 303);
    exit;
}

$memberships = FarmAccess::memberships($pdo, $userId);
$hasFarmIdParameter = array_key_exists('farm_id', $_GET);
$selectedFarmId = filter_var($_GET['farm_id'] ?? null, FILTER_VALIDATE_INT);
$selectedFarm = null;
if ($hasFarmIdParameter && ($selectedFarmId === false || $selectedFarmId === null)) {
    http_response_code(404);
    exit('Exploitation introuvable.');
}
foreach ($memberships as $membership) {
    if (($selectedFarmId === false || $selectedFarmId === null)
        ? $selectedFarm === null
        : (int) $membership['id'] === $selectedFarmId) {
        $selectedFarm = $membership;
        if ($selectedFarmId === false || $selectedFarmId === null) {
            break;
        }
    }
}
if ($selectedFarm === null && ($memberships !== [] || $hasFarmIdParameter)) {
    http_response_code(404);
    exit('Exploitation introuvable.');
}

$errors = [];
$flash = AuthSession::takeFlash();
$canManageCycles = false;
$canViewFinancials = false;
$canViewSales = false;
$canViewFlocks = false;
$dashboard = null;
if (is_array($selectedFarm)) {
    $farmId = (int) $selectedFarm['id'];
    $role = FarmAccess::role($pdo, $userId, $farmId);
    if (!FarmAccess::allows($pdo, $userId, $farmId, 'farm.view')) {
        http_response_code(403);
        exit('Accès au tableau de bord refusé.');
    }
    $canManageCycles = FarmAccess::allows($pdo, $userId, $farmId, 'flock.manage');
    $canViewFlocks = FarmAccess::allows($pdo, $userId, $farmId, 'flock.view');
    $canViewFinancials = FarmAccess::allows($pdo, $userId, $farmId, 'expense.view');
    $canViewSales = FarmAccess::allows($pdo, $userId, $farmId, 'sale.view');
    $canWriteFollowups = FarmAccess::allows($pdo, $userId, $farmId, 'followup.write');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!AuthSession::verifyCsrf($_POST['csrf_token'] ?? null)) {
            http_response_code(403);
            $errors[] = 'Le formulaire a expiré ou ne peut pas être vérifié. Rechargez la page.';
        } elseif (!$canManageCycles) {
            http_response_code(403);
            $errors[] = 'Vous ne disposez pas des droits pour modifier les durées prévisionnelles.';
        } elseif (($_POST['action'] ?? null) !== 'save_cycle_setting') {
            http_response_code(400);
            $errors[] = 'Action non reconnue.';
        } else {
            $days = filter_var(
                $_POST['target_cycle_days'] ?? null,
                FILTER_VALIDATE_INT
            );
            $poultryType = $_POST['poultry_type'] ?? null;
            $productionType = $_POST['production_type'] ?? null;
            if (!is_int($days) || !is_string($poultryType) || !is_string($productionType)) {
                $errors[] = 'Saisissez une durée entière et un type d’élevage valides.';
                http_response_code(422);
            } else {
                try {
                    DashboardService::saveCycleSetting(
                        $pdo,
                        $farmId,
                        $userId,
                        $poultryType,
                        $productionType,
                        $days
                    );
                    AuthSession::flash(
                        'success',
                        'La durée de cycle estimative a été enregistrée.'
                    );
                    header(
                        'Location: ./?farm_id=' . $farmId . '#flocks',
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

    $timezone = new DateTimeZone((string) $selectedFarm['timezone']);
    $today = new DateTimeImmutable('today', $timezone);
    $dashboard = DashboardService::load(
        $pdo,
        $farmId,
        $today,
        $canViewFinancials,
        $userId,
        $canViewFlocks,
        $canViewSales
    );
    $dashboard['can_manage_cycles'] = $canManageCycles;
    $dashboard['can_write_followups'] = $canWriteFollowups;
    $dashboard['role'] = $role;
    $dashboard['today'] = $today;
}

$userStatement = $pdo->prepare(
    "SELECT full_name FROM users WHERE id = :user_id AND status = 'active' LIMIT 1"
);
$userStatement->execute(['user_id' => $userId]);
$currentUserName = (string) $userStatement->fetchColumn();
$nameParts = preg_split('/\s+/u', trim($currentUserName), -1, PREG_SPLIT_NO_EMPTY) ?: [];
$userInitials = mb_strtoupper(
    mb_substr($nameParts[0] ?? 'U', 0, 1, 'UTF-8')
        . mb_substr($nameParts[1] ?? '', 0, 1, 'UTF-8'),
    'UTF-8'
);
$csrfToken = htmlspecialchars(
    AuthSession::csrfToken(),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$page = [
    'title' => 'Tableau de bord',
    'section' => 'VUE D’ENSEMBLE',
    'description' => is_array($selectedFarm)
        ? 'Suivez les données enregistrées pour ' . (string) $selectedFarm['name'] . '.'
        : 'Vue d’ensemble de vos exploitations autorisées.',
];
$pageKey = 'dashboard';
$isLiveDashboard = true;
$topbarDate = (new DateTimeImmutable(
    'now',
    new DateTimeZone((string) ($selectedFarm['timezone'] ?? 'UTC'))
))->format('d/m/Y');
$dashboardViewPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Pages'
    . DIRECTORY_SEPARATOR . 'dashboard-content.php';

if (is_array($dashboard)) {
    $cycleGroups = [];
    foreach ($dashboard['flocks'] as $flock) {
        $key = $flock['poultry_type'] . ':' . $flock['production_type'];
        $cycleGroups[$key] ??= [
            'poultry_type' => $flock['poultry_type'],
            'production_type' => $flock['production_type'],
            'target_cycle_days' => $flock['target_cycle_days'],
        ];
    }
    $dashboard['cycle_groups'] = array_values($cycleGroups);
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Layouts'
    . DIRECTORY_SEPARATOR . 'app.php';
