<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Auth\FarmAccess;
use MonElevage\Database\Connection;
use MonElevage\Stock\StockService;
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
$canViewStock = FarmAccess::allows($pdo, $userId, $farmId, 'stock.view');
if (!$canViewStock) {
    http_response_code(403);
    exit('Accès au catalogue et aux stocks refusé.');
}
$canWrite = FarmAccess::allows($pdo, $userId, $farmId, 'stock.manage');
$errors = [];
$products = StockService::products($pdo, $userId, $farmId);
$referenceCategoryQuery = $pdo->query(
    "SELECT label FROM system_reference_items
     WHERE reference_type = 'product_category' AND status = 'active'
     ORDER BY position, label, id"
);
$productCategoryLabels = $referenceCategoryQuery->fetchAll(PDO::FETCH_COLUMN);
$suppliers = StockService::suppliers($pdo, $userId, $farmId);
$activeSuppliers = StockService::activeSuppliers($pdo, $userId, $farmId);
$flocks = FarmAccess::allows($pdo, $userId, $farmId, 'flock.view')
    ? StockService::activeFlocks($pdo, $userId, $farmId)
    : [];
$lots = StockService::lots($pdo, $userId, $farmId);
$movementLots = StockService::lots($pdo, $userId, $farmId, null, true);
$lowStock = StockService::lowStock($pdo, $userId, $farmId);
$expiryAlerts = StockService::expiryAlerts($pdo, $userId, $farmId, $today);
$purchases = StockService::purchases($pdo, $userId, $farmId);
$inventories = StockService::inventories($pdo, $userId, $farmId);

$tab = is_string($_GET['tab'] ?? null) ? $_GET['tab'] : 'catalog';
$allowedTabs = ['catalog', 'suppliers', 'purchases', 'movements', 'inventory'];
if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'catalog';
}
$fromDate = is_string($_GET['from'] ?? null) ? trim($_GET['from']) : '';
$toDate = is_string($_GET['to'] ?? null) ? trim($_GET['to']) : '';
$validDate = static function (string $value): bool {
    if ($value === '') {
        return true;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};
$historyProductId = filter_var($_GET['product_id'] ?? null, FILTER_VALIDATE_INT);
$historyProductId = is_int($historyProductId) && $historyProductId > 0
    ? $historyProductId
    : null;
$productIds = array_map(static fn (array $product): int => (int) $product['id'], $products);
if ($historyProductId !== null && !in_array($historyProductId, $productIds, true)) {
    http_response_code(404);
    exit('Produit introuvable.');
}
if (!$validDate($fromDate) || !$validDate($toDate)
    || ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate)) {
    http_response_code(422);
    $fromDate = '';
    $toDate = '';
    $errors[] = 'La période de l’historique est invalide.';
}

$flash = AuthSession::takeFlash();
$editProductId = filter_var($_GET['edit_product'] ?? null, FILTER_VALIDATE_INT);
$editSupplierId = filter_var($_GET['edit_supplier'] ?? null, FILTER_VALIDATE_INT);
$editProduct = null;
$editSupplier = null;
if (is_int($editProductId) && $editProductId > 0) {
    try {
        $editProduct = StockService::product($pdo, $userId, $farmId, $editProductId);
        $tab = 'catalog';
    } catch (RuntimeException) {
        http_response_code(404);
        exit('Produit introuvable.');
    }
}
if (is_int($editSupplierId) && $editSupplierId > 0) {
    foreach ($suppliers as $supplier) {
        if ((int) $supplier['id'] === $editSupplierId) {
            $editSupplier = $supplier;
            $tab = 'suppliers';
            break;
        }
    }
    if ($editSupplier === null) {
        http_response_code(404);
        exit('Fournisseur introuvable.');
    }
}

$productValues = is_array($editProduct) ? $editProduct : [
    'name' => '',
    'sku' => '',
    'category' => '',
    'product_type' => 'consumable',
    'unit_code' => 'kg',
    'default_unit_price' => '0',
    'minimum_stock' => '0',
    'notes' => '',
];
$supplierValues = is_array($editSupplier) ? $editSupplier : [
    'name' => '',
    'contact_name' => '',
    'phone' => '',
    'email' => '',
    'address' => '',
    'notes' => '',
];
$purchaseValues = [
    'supplier_id' => '',
    'flock_id' => '',
    'purchased_on' => $today->format('Y-m-d'),
    'reference' => '',
    'notes' => '',
    'items' => [[
        'product_id' => '',
        'quantity' => '',
        'unit_price' => '',
        'expires_on' => '',
    ]],
];
$receiptValues = [
    'product_id' => '',
    'quantity' => '',
    'received_on' => $today->format('Y-m-d'),
    'expires_on' => '',
    'lot_code' => '',
    'reason' => '',
];
$movementValues = [
    'product_id' => '',
    'flock_id' => '',
    'lot_id' => '',
    'movement_type' => 'consumption',
    'quantity' => '',
    'occurred_on' => $today->format('Y-m-d'),
    'reason' => '',
];
$inventoryValues = [
    'product_id' => '',
    'counted_quantity' => '',
    'counted_on' => $today->format('Y-m-d'),
    'reason' => '',
    'expires_on' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!AuthSession::verifyCsrf($_POST['csrf_token'] ?? null)) {
        http_response_code(403);
        $errors[] = 'Le formulaire a expiré ou ne peut pas être vérifié. Rechargez la page.';
    } elseif (!$canWrite) {
        http_response_code(403);
        $errors[] = 'Vous ne disposez pas des droits pour modifier le catalogue ou les stocks.';
    } else {
        $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
        try {
            if ($action === 'save_product') {
                $productValues = $_POST;
                $postedId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
                $postedId = is_int($postedId) && $postedId > 0 ? $postedId : null;
                StockService::saveProduct($pdo, $userId, $farmId, $_POST, $postedId);
                AuthSession::flash('success', $postedId === null
                    ? 'Le produit a été créé.'
                    : 'Le produit a été modifié.');
                $tab = 'catalog';
            } elseif ($action === 'archive_product') {
                $postedId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
                if (!is_int($postedId) || $postedId < 1) {
                    throw new RuntimeException('Produit à archiver invalide.');
                }
                StockService::archiveProduct($pdo, $userId, $farmId, $postedId);
                AuthSession::flash('success', 'Le produit a été archivé ; ses mouvements restent conservés.');
                $tab = 'catalog';
            } elseif ($action === 'save_supplier') {
                $supplierValues = $_POST;
                $postedId = filter_var($_POST['supplier_id'] ?? null, FILTER_VALIDATE_INT);
                $postedId = is_int($postedId) && $postedId > 0 ? $postedId : null;
                StockService::saveSupplier($pdo, $userId, $farmId, $_POST, $postedId);
                AuthSession::flash('success', $postedId === null
                    ? 'Le fournisseur a été créé.'
                    : 'Le fournisseur a été modifié.');
                $tab = 'suppliers';
            } elseif ($action === 'archive_supplier') {
                $postedId = filter_var($_POST['supplier_id'] ?? null, FILTER_VALIDATE_INT);
                if (!is_int($postedId) || $postedId < 1) {
                    throw new RuntimeException('Fournisseur à archiver invalide.');
                }
                StockService::archiveSupplier($pdo, $userId, $farmId, $postedId);
                AuthSession::flash('success', 'Le fournisseur a été archivé ; les achats historiques restent liés.');
                $tab = 'suppliers';
            } elseif ($action === 'save_purchase') {
                $purchaseValues = $_POST;
                StockService::savePurchase(
                    $pdo,
                    $userId,
                    $farmId,
                    $_POST,
                    (string) $selectedFarm['currency_code'],
                    $today
                );
                AuthSession::flash('success', 'L’achat, la dépense, les lots et les entrées de stock ont été enregistrés ensemble.');
                $tab = 'purchases';
            } elseif ($action === 'receive_stock') {
                $receiptValues = $_POST;
                StockService::receiveStock($pdo, $userId, $farmId, $_POST, $today);
                AuthSession::flash('success', 'La réception et son lot de stock ont été enregistrés.');
                $tab = 'catalog';
            } elseif ($action === 'record_movement') {
                $movementValues = $_POST;
                StockService::recordMovement($pdo, $userId, $farmId, $_POST, $today);
                AuthSession::flash('success', 'Le mouvement de stock a été enregistré.');
                $tab = 'movements';
            } elseif ($action === 'record_inventory') {
                $inventoryValues = $_POST;
                StockService::recordInventory($pdo, $userId, $farmId, $_POST, $today);
                AuthSession::flash('success', 'L’inventaire est enregistré et son écart a été ajusté dans le registre.');
                $tab = 'inventory';
            } else {
                http_response_code(400);
                throw new RuntimeException('Action de stock inconnue.');
            }
            header('Location: stock.php?farm_id=' . $farmId . '&tab=' . rawurlencode($tab), true, 303);
            exit;
        } catch (RuntimeException $exception) {
            if (str_contains($exception->getMessage(), 'Permission insuffisante')) {
                http_response_code(403);
            } elseif (http_response_code() < 400) {
                http_response_code(422);
            }
            $errors[] = $exception->getMessage();
        } catch (PDOException $exception) {
            error_log(sprintf('Stock operation failed: %s', $exception->getMessage()));
            http_response_code(500);
            $errors[] = 'L’opération n’a pas pu être enregistrée. Aucun stock partiel n’a été conservé ; consultez le journal local.';
        }
    }
}

$history = StockService::history(
    $pdo,
    $userId,
    $farmId,
    $historyProductId,
    $fromDate !== '' ? $fromDate : null,
    $toDate !== '' ? $toDate : null
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
$farmSwitchAction = 'stock.php';
$isLiveDashboard = true;
$isPrivateApp = true;
$pageKey = 'stock';
$page = [
    'title' => 'Produits et stocks',
    'section' => 'APPROVISIONNEMENTS',
    'description' => 'Gérez le catalogue, les fournisseurs, les achats, les consommations et les inventaires.',
];
$topbarDate = $today->format('d/m/Y');
$liveViewPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Pages'
    . DIRECTORY_SEPARATOR . 'stock-content.php';
$extraScripts = ['assets/js/stock.js'];

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
header('X-Content-Type-Options: nosniff');
require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'Views' . DIRECTORY_SEPARATOR . 'Layouts'
    . DIRECTORY_SEPARATOR . 'app.php';
