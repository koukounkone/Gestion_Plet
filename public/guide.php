<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\FarmAccess;
use MonElevage\Database\Connection;
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
if ($hasFarmId && ($requestedFarmId === false || $requestedFarmId === null)) {
    http_response_code(404);
    exit('Exploitation introuvable.');
}

$selectedFarm = null;
foreach ($memberships as $membership) {
    if ($hasFarmId
        ? (int) $membership['id'] === $requestedFarmId
        : $selectedFarm === null) {
        $selectedFarm = $membership;
    }
}
if ($hasFarmId && $selectedFarm === null) {
    http_response_code(404);
    exit('Exploitation introuvable.');
}

$currentFarmId = is_array($selectedFarm) ? (int) $selectedFarm['id'] : null;
$currentFarmName = is_array($selectedFarm)
    ? (string) $selectedFarm['name']
    : 'Aucune exploitation active';
$canViewFlocks = $currentFarmId !== null
    && FarmAccess::allows($pdo, $userId, $currentFarmId, 'flock.view');
$canViewStock = $currentFarmId !== null
    && FarmAccess::allows($pdo, $userId, $currentFarmId, 'stock.view');
$canViewSales = $currentFarmId !== null
    && FarmAccess::allows($pdo, $userId, $currentFarmId, 'sale.view');
$canViewHealth = $currentFarmId !== null
    && FarmAccess::allows($pdo, $userId, $currentFarmId, 'health.view');
$canViewFinancials = $currentFarmId !== null
    && FarmAccess::allows($pdo, $userId, $currentFarmId, 'expense.view');
$canViewReports = $currentFarmId !== null
    && FarmAccess::allows($pdo, $userId, $currentFarmId, 'report.view');
$dashboard = is_array($selectedFarm)
    ? [
        'farm' => $selectedFarm,
        'role' => FarmAccess::role($pdo, $userId, $currentFarmId),
    ]
    : null;
$farmSwitchAction = 'guide.php';
$nameQuery = $pdo->prepare(
    "SELECT full_name FROM users WHERE id = :user_id AND status = 'active' LIMIT 1"
);
$nameQuery->execute(['user_id' => $userId]);
$currentUserName = (string) $nameQuery->fetchColumn();
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
$topbarDate = (new DateTimeImmutable())->format('d/m/Y');
$pageKey = 'user-guide';
$isLiveDashboard = true;
$isPrivateApp = true;
$page = [
    'title' => 'Guide d’utilisation',
    'section' => 'BIEN DÉMARRER',
    'description' => 'Des explications simples pour utiliser les fonctions réellement disponibles dans Mon Élevage.',
];
$liveViewPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Pages'
    . DIRECTORY_SEPARATOR . 'guide-content.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Layouts'
    . DIRECTORY_SEPARATOR . 'app.php';
