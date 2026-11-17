<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\FarmAccess;
use MonElevage\Database\Connection;
use MonElevage\Sales\SalesService;
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
$canViewSales = FarmAccess::allows($pdo, $userId, $farmId, 'sale.view');
if (!$canViewSales) {
    http_response_code(403);
    exit('Accès aux ventes refusé.');
}
$canManageSales = FarmAccess::allows($pdo, $userId, $farmId, 'sale.manage');
$canManagePayments = FarmAccess::allows($pdo, $userId, $farmId, 'payment.manage');
if ((string) $selectedFarm['currency_code'] !== 'XOF') {
    http_response_code(422);
    exit('Les ventes sont enregistrées en FCFA (XOF). Aucune conversion automatique ne sera effectuée ; configurez cette exploitation en XOF avant de continuer.');
}

$monthStart = $today->modify('first day of this month')->format('Y-m-d');
$fromDate = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : $monthStart;
$toDate = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : $today->format('Y-m-d');
$isValidDate = static function (string $value): bool {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
$errors = [];
if (!$isValidDate($fromDate) || !$isValidDate($toDate) || $fromDate > $toDate) {
    http_response_code(422);
    $errors[] = 'La période du filtre est invalide.';
    $fromDate = $monthStart;
    $toDate = $today->format('Y-m-d');
}
$flash = AuthSession::takeFlash();
$clientValues = [
    'name' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
    'notes' => '',
];
$saleValues = [
    'client_id' => '',
    'notes' => '',
    'lines' => [[
        'source_type' => 'product',
        'flock_id' => '',
        'product_id' => '',
        'quantity' => '',
        'unit_price' => '',
    ]],
];
$editingClient = null;
if (isset($_GET['edit_client'])) {
    $clientId = filter_var($_GET['edit_client'], FILTER_VALIDATE_INT);
    if (!is_int($clientId) || $clientId < 1 || !$canManageSales) {
        http_response_code($canManageSales ? 404 : 403);
        exit('Client introuvable ou modification refusée.');
    }
    try {
        $editingClient = SalesService::findClient($pdo, $userId, $farmId, $clientId);
        $clientValues = $editingClient;
    } catch (RuntimeException) {
        http_response_code(404);
        exit('Client introuvable ou archivé.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthSession::verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $errors[] = 'Le formulaire a expiré ou ne peut pas être vérifié. Rechargez la page.';
    } else {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        try {
            if ($action === 'save_client') {
                if (!$canManageSales) {
                    http_response_code(403);
                    throw new RuntimeException('Vous ne disposez pas des droits pour gérer les clients.');
                }
                $clientValues = $_POST;
                $rawId = $_POST['client_id'] ?? '';
                $clientId = $rawId === '' ? null : filter_var($rawId, FILTER_VALIDATE_INT);
                if ($rawId !== '' && (!is_int($clientId) || $clientId < 1)) {
                    throw new RuntimeException('Identifiant client invalide.');
                }
                SalesService::saveClient($pdo, $userId, $farmId, $_POST, $clientId);
                AuthSession::flash('success', $clientId === null
                    ? 'Le client a été créé.'
                    : 'Les coordonnées du client ont été mises à jour.');
            } elseif ($action === 'archive_client') {
                $clientId = filter_var($_POST['client_id'] ?? null, FILTER_VALIDATE_INT);
                if (!is_int($clientId) || $clientId < 1) {
                    throw new RuntimeException('Identifiant client invalide.');
                }
                SalesService::archiveClient($pdo, $userId, $farmId, $clientId);
                AuthSession::flash('success', 'Le client a été archivé ; ses ventes restent conservées.');
            } elseif ($action === 'create_sale') {
                if (!$canManageSales) {
                    http_response_code(403);
                    throw new RuntimeException('Vous ne disposez pas des droits pour créer une commande.');
                }
                $saleValues = $_POST;
                $saleValues['lines'] = is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [];
                SalesService::createSale(
                    $pdo,
                    $userId,
                    $farmId,
                    $_POST,
                    'XOF',
                    $now
                );
                AuthSession::flash('success', 'La commande est enregistrée comme vente ; le stock et l’effectif ont été mis à jour.');
            } elseif ($action === 'record_payment') {
                $saleId = filter_var($_POST['sale_id'] ?? null, FILTER_VALIDATE_INT);
                if (!is_int($saleId) || $saleId < 1) {
                    throw new RuntimeException('Vente à régler invalide.');
                }
                SalesService::recordPayment($pdo, $userId, $farmId, $saleId, $_POST, $now);
                AuthSession::flash('success', 'Le paiement a été enregistré.');
            } elseif ($action === 'update_delivery') {
                $saleId = filter_var($_POST['sale_id'] ?? null, FILTER_VALIDATE_INT);
                if (!is_int($saleId) || $saleId < 1) {
                    throw new RuntimeException('Vente à mettre à jour invalide.');
                }
                SalesService::updateDeliveryStatus(
                    $pdo,
                    $userId,
                    $farmId,
                    $saleId,
                    is_string($_POST['delivery_status'] ?? null)
                        ? $_POST['delivery_status']
                        : ''
                );
                AuthSession::flash('success', 'Le statut de livraison a été mis à jour.');
            } elseif ($action === 'void_sale') {
                $saleId = filter_var($_POST['sale_id'] ?? null, FILTER_VALIDATE_INT);
                if (!is_int($saleId) || $saleId < 1) {
                    throw new RuntimeException('Vente à annuler invalide.');
                }
                SalesService::voidSale(
                    $pdo,
                    $userId,
                    $farmId,
                    $saleId,
                    is_string($_POST['void_reason'] ?? null) ? $_POST['void_reason'] : '',
                    $now
                );
                AuthSession::flash('success', 'La vente a été annulée ; les animaux, stocks et paiements ont été régularisés avec une trace.');
            } else {
                http_response_code(400);
                throw new RuntimeException('Action commerciale inconnue.');
            }
            header(
                'Location: sales.php?farm_id=' . $farmId . '&from='
                    . rawurlencode($fromDate) . '&to=' . rawurlencode($toDate),
                true,
                303
            );
            exit;
        } catch (PDOException $exception) {
            error_log(sprintf('Sales operation failed: %s', $exception->getMessage()));
            http_response_code(500);
            $errors[] = 'L’opération commerciale n’a pas pu être enregistrée. Consultez le journal local.';
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

$clients = SalesService::clients($pdo, $userId, $farmId);
$activeClients = SalesService::activeClients($pdo, $userId, $farmId);
$products = SalesService::products($pdo, $userId, $farmId);
$flocks = SalesService::flocks($pdo, $userId, $farmId);
$sales = SalesService::sales($pdo, $userId, $farmId, $fromDate, $toDate);
$summary = SalesService::summary($pdo, $userId, $farmId, $fromDate, $toDate);
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
$farmSwitchAction = 'sales.php';
$isLiveDashboard = true;
$isPrivateApp = true;
$pageKey = 'sales';
$page = [
    'title' => 'Commandes et ventes',
    'section' => 'GESTION COMMERCIALE',
    'description' => 'Gérez les clients, les commandes, les ventes, les paiements et les reçus de l’exploitation.',
];
$topbarDate = $now->format('d/m/Y');
$extraScripts = ['assets/js/sales.js'];
$liveViewPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Pages'
    . DIRECTORY_SEPARATOR . 'sales-content.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Layouts'
    . DIRECTORY_SEPARATOR . 'app.php';
