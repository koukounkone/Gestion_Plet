<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Database\Connection;
use MonElevage\Stock\StockService;

$pdo = Connection::get($config);
$suffix = strtoupper(bin2hex(random_bytes(4)));
$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
$farmIds = [];
$userIds = [];
$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('Échec du test : ' . $message);
    }
    $passed++;
};
$expectFailure = static function (callable $operation, string $message) use ($assert): void {
    try {
        $operation();
    } catch (RuntimeException) {
        $assert(true, $message);
        return;
    }
    $assert(false, $message);
};

try {
    $createUser = $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, status, email_verified_at)
         VALUES (:name, :email, 'test-hash', 'active', UTC_TIMESTAMP())"
    );
    foreach (['Catalogue stock', 'Compte privé stock', 'Opérateur stock'] as $index => $name) {
        $createUser->execute([
            'name' => $name,
            'email' => 'stock-' . strtolower($suffix) . '-' . $index . '@example.test',
        ]);
        $userIds[] = (int) $pdo->lastInsertId();
    }

    $createFarm = $pdo->prepare(
        "INSERT INTO farms (owner_user_id, name, currency_code, timezone)
         VALUES (:user_id, :name, 'XOF', 'UTC')"
    );
    $createFarm->execute(['user_id' => $userIds[0], 'name' => 'Ferme stock ' . $suffix]);
    $farmIds[] = (int) $pdo->lastInsertId();
    $createFarm->execute(['user_id' => $userIds[1], 'name' => 'Ferme stock privée ' . $suffix]);
    $farmIds[] = (int) $pdo->lastInsertId();

    $membership = $pdo->prepare(
        'INSERT INTO farm_members (farm_id, user_id, role, status)
         VALUES (:farm_id, :user_id, :role, \'active\')'
    );
    $membership->execute(['farm_id' => $farmIds[0], 'user_id' => $userIds[0], 'role' => 'owner']);
    $membership->execute(['farm_id' => $farmIds[1], 'user_id' => $userIds[1], 'role' => 'owner']);
    $membership->execute(['farm_id' => $farmIds[0], 'user_id' => $userIds[2], 'role' => 'operator']);

    $createFlock = $pdo->prepare(
        "INSERT INTO flocks
            (farm_id, code, name, flock_type, poultry_type, production_type,
             currency_code, started_on, initial_count, status, created_by_user_id)
         VALUES
            (:farm_id, :code, :name, 'broiler', 'chicken', 'meat',
             'XOF', :started_on, 100, 'active', :user_id)"
    );
    $createFlock->execute([
        'farm_id' => $farmIds[0],
        'code' => 'STOCK-' . $suffix,
        'name' => 'Élevage test stock',
        'started_on' => $today->modify('-10 days')->format('Y-m-d'),
        'user_id' => $userIds[0],
    ]);
    $flockId = (int) $pdo->lastInsertId();

    $assert(StockService::validateProduct([
        'name' => 'Aliment test',
        'sku' => 'feed-' . $suffix,
        'category' => 'Aliment',
        'product_type' => 'consumable',
        'unit_code' => 'kg',
        'default_unit_price' => '2',
        'minimum_stock' => '5',
    ])['errors'] === [], 'valide un produit consommable');
    $assert(StockService::validateProduct([
        'name' => '',
        'sku' => 'bad sku',
        'category' => '',
        'product_type' => 'invalid',
        'unit_code' => ' ',
        'default_unit_price' => '-1',
        'minimum_stock' => '-1',
    ])['errors'] !== [], 'rejette les champs produit invalides');
    $assert(StockService::validateSupplier([
        'name' => 'Fournisseur test',
        'email' => 'not-an-email',
    ])['errors'] !== [], 'rejette une adresse fournisseur invalide');

    $feedId = StockService::saveProduct($pdo, $userIds[0], $farmIds[0], [
        'name' => 'Aliment ' . $suffix,
        'sku' => 'FEED-' . $suffix,
        'category' => 'Aliment',
        'product_type' => 'consumable',
        'unit_code' => 'kg',
        'default_unit_price' => '2',
        'minimum_stock' => '5',
    ]);
    $equipmentId = StockService::saveProduct($pdo, $userIds[0], $farmIds[0], [
        'name' => 'Mangeoire ' . $suffix,
        'sku' => 'EQUIP-' . $suffix,
        'category' => 'Matériel',
        'product_type' => 'equipment',
        'unit_code' => 'unit',
        'default_unit_price' => '100',
        'minimum_stock' => '0',
    ]);
    $expiredProductId = StockService::saveProduct($pdo, $userIds[0], $farmIds[0], [
        'name' => 'Produit périssable ' . $suffix,
        'sku' => 'EXP-' . $suffix,
        'category' => 'Aliment',
        'product_type' => 'consumable',
        'unit_code' => 'kg',
        'default_unit_price' => '1',
        'minimum_stock' => '0',
    ]);
    $supplierId = StockService::saveSupplier($pdo, $userIds[0], $farmIds[0], [
        'name' => 'Fournisseur ' . $suffix,
        'email' => 'vendor-' . strtolower($suffix) . '@example.test',
    ]);
    $assert($feedId > 0 && $equipmentId > 0 && $supplierId > 0, 'crée les produits et le fournisseur de l’exploitation');

    $purchaseInput = [
        'supplier_id' => (string) $supplierId,
        'flock_id' => (string) $flockId,
        'purchased_on' => $today->format('Y-m-d'),
        'reference' => 'FAC-' . $suffix,
        'items' => [
            [
                'product_id' => (string) $feedId,
                'quantity' => '5',
                'unit_price' => '2',
                'expires_on' => $today->modify('+20 days')->format('Y-m-d'),
            ],
            [
                'product_id' => (string) $feedId,
                'quantity' => '5',
                'unit_price' => '2',
                'expires_on' => $today->modify('+10 days')->format('Y-m-d'),
            ],
            [
                'product_id' => (string) $equipmentId,
                'quantity' => '2',
                'unit_price' => '100',
                'expires_on' => '',
            ],
        ],
    ];
    $assert(
        StockService::validatePurchase($purchaseInput, $today)['errors'] === [],
        'valide un achat multi-lignes'
    );
    $purchaseId = StockService::savePurchase(
        $pdo,
        $userIds[0],
        $farmIds[0],
        $purchaseInput,
        'XOF',
        $today
    );
    $purchaseQuery = $pdo->prepare(
        'SELECT p.total_amount, p.expense_id, e.amount, COUNT(pi.id) AS item_count
         FROM purchases p
         INNER JOIN expenses e ON e.farm_id = p.farm_id AND e.id = p.expense_id
         INNER JOIN purchase_items pi ON pi.farm_id = p.farm_id AND pi.purchase_id = p.id
         WHERE p.farm_id = :farm_id AND p.id = :purchase_id
         GROUP BY p.id, p.total_amount, p.expense_id, e.amount'
    );
    $purchaseQuery->execute(['farm_id' => $farmIds[0], 'purchase_id' => $purchaseId]);
    $purchaseRow = $purchaseQuery->fetch();
    $assert(
        is_array($purchaseRow) && (float) $purchaseRow['total_amount'] === 220.0
            && (float) $purchaseRow['amount'] === 220.0 && (int) $purchaseRow['item_count'] === 3,
        'enregistre l’achat, ses trois lignes et une dépense totale cohérente'
    );
    $flockReceiptCount = $pdo->prepare(
        "SELECT COUNT(*) FROM stock_movements
         WHERE farm_id = :farm_id AND flock_id = :flock_id
           AND purchase_item_id IS NOT NULL AND movement_type = 'receipt'"
    );
    $flockReceiptCount->execute(['farm_id' => $farmIds[0], 'flock_id' => $flockId]);
    $assert((int) $flockReceiptCount->fetchColumn() === 3, 'lie les réceptions à l’élevage destinataire');

    $purchaseCount = $pdo->prepare('SELECT COUNT(*) FROM purchases WHERE farm_id = :farm_id');
    $purchaseCount->execute(['farm_id' => $farmIds[0]]);
    $beforeRejectedPurchase = (int) $purchaseCount->fetchColumn();
    $badPurchase = $purchaseInput;
    $badPurchase['items'][2]['product_id'] = '999999999';
    $expectFailure(
        static fn () => StockService::savePurchase(
            $pdo,
            $userIds[0],
            $farmIds[0],
            $badPurchase,
            'XOF',
            $today
        ),
        'refuse un article hors catalogue dans une transaction'
    );
    $purchaseCount->execute(['farm_id' => $farmIds[0]]);
    $assert((int) $purchaseCount->fetchColumn() === $beforeRejectedPurchase, 'annule achat/dépense/lots/mouvements si une ligne échoue');

    $duplicateSku = $pdo->prepare(
        "SELECT id FROM products WHERE farm_id = :farm_id AND sku = :sku LIMIT 1"
    );
    $duplicateSku->execute(['farm_id' => $farmIds[0], 'sku' => 'FEED-' . $suffix]);
    $expectFailure(
        static fn () => StockService::saveProduct($pdo, $userIds[0], $farmIds[0], [
            'name' => 'Référence dupliquée',
            'sku' => 'FEED-' . $suffix,
            'category' => 'Aliment',
            'product_type' => 'consumable',
            'unit_code' => 'kg',
            'default_unit_price' => '2',
            'minimum_stock' => '0',
        ]),
        'refuse une référence SKU déjà utilisée'
    );
    $expectFailure(
        static fn () => StockService::saveProduct($pdo, $userIds[0], $farmIds[0], [
            'name' => 'Produit périssable ' . $suffix,
            'sku' => 'FEED-' . $suffix,
            'category' => 'Aliment',
            'product_type' => 'consumable',
            'unit_code' => 'kg',
            'default_unit_price' => '1',
            'minimum_stock' => '0',
        ], $expiredProductId),
        'refuse un SKU dupliqué pendant la modification'
    );
    $expectFailure(
        static fn () => StockService::saveProduct($pdo, $userIds[0], $farmIds[0], [
            'name' => 'Aliment ' . $suffix,
            'sku' => 'FEED-' . $suffix,
            'category' => 'Aliment',
            'product_type' => 'equipment',
            'unit_code' => 'kg',
            'default_unit_price' => '2',
            'minimum_stock' => '5',
        ], $feedId),
        'protège la distinction consommable/équipement lorsque le produit a de l’historique'
    );
    $expectFailure(
        static fn () => StockService::saveProduct($pdo, $userIds[0], $farmIds[0], [
            'name' => 'Autre ferme',
            'sku' => 'OTHER-' . $suffix,
            'category' => 'Aliment',
            'product_type' => 'consumable',
            'unit_code' => 'kg',
        ], $feedId + 999999),
        'n’autorise pas la modification d’un produit inexistant'
    );

    $lots = StockService::lots($pdo, $userIds[0], $farmIds[0], $feedId);
    $assert(count($lots) === 2, 'crée un lot traçable par ligne d’achat');
    $movementResult = StockService::recordMovement(
        $pdo,
        $userIds[0],
        $farmIds[0],
        [
            'product_id' => (string) $feedId,
            'flock_id' => (string) $flockId,
            'movement_type' => 'consumption',
            'quantity' => '6',
            'occurred_on' => $today->format('Y-m-d'),
            'reason' => 'Ration de test',
        ],
        $today
    );
    $allocationQuery = $pdo->prepare(
        "SELECT l.expires_on, SUM(-sm.quantity_delta) AS consumed
         FROM stock_movements sm
         INNER JOIN stock_lots l ON l.farm_id = sm.farm_id AND l.id = sm.lot_id
         WHERE sm.farm_id = :farm_id AND sm.product_id = :product_id
           AND sm.movement_type = 'consumption'
         GROUP BY l.id, l.expires_on ORDER BY l.expires_on"
    );
    $allocationQuery->execute(['farm_id' => $farmIds[0], 'product_id' => $feedId]);
    $allocations = $allocationQuery->fetchAll();
    $assert(
        $movementResult === 2 && count($allocations) === 2
            && (float) $allocations[0]['consumed'] === 5.0
            && (float) $allocations[1]['consumed'] === 1.0,
        'alloue une consommation au lot le plus proche de sa péremption (FEFO)'
    );
    $expectFailure(
        static fn () => StockService::recordMovement(
            $pdo,
            $userIds[0],
            $farmIds[0],
            [
                'product_id' => (string) $feedId,
                'flock_id' => (string) $flockId,
                'movement_type' => 'consumption',
                'quantity' => '100',
                'occurred_on' => $today->format('Y-m-d'),
                'reason' => 'Test stock négatif',
            ],
            $today
        ),
        'refuse une consommation qui rendrait le stock négatif'
    );

    $expiredReceiptDate = $today->modify('-20 days')->format('Y-m-d');
    $expiredDate = $today->modify('-5 days')->format('Y-m-d');
    StockService::receiveStock($pdo, $userIds[0], $farmIds[0], [
        'product_id' => (string) $expiredProductId,
        'quantity' => '4',
        'received_on' => $expiredReceiptDate,
        'expires_on' => $expiredDate,
        'lot_code' => 'OLD-' . $suffix,
        'reason' => 'Lot périmé de test',
    ], $today);
    $expectFailure(
        static fn () => StockService::recordMovement(
            $pdo,
            $userIds[0],
            $farmIds[0],
            [
                'product_id' => (string) $expiredProductId,
                'flock_id' => (string) $flockId,
                'movement_type' => 'consumption',
                'quantity' => '1',
                'occurred_on' => $today->format('Y-m-d'),
                'reason' => 'Refus lot périmé',
            ],
            $today
        ),
        'interdit la consommation d’un lot périmé'
    );
    $expiredAlerts = StockService::expiryAlerts($pdo, $userIds[0], $farmIds[0], $today);
    $assert(count(array_filter($expiredAlerts, static fn (array $alert): bool =>
        (int) $alert['product_id'] === $expiredProductId && $alert['expired'])) === 1,
        'signale le lot périmé encore disponible');

    $equipmentLots = StockService::lots($pdo, $userIds[0], $farmIds[0], $equipmentId);
    $equipmentLotId = (int) $equipmentLots[0]['id'];
    StockService::recordMovement($pdo, $userIds[0], $farmIds[0], [
        'product_id' => (string) $equipmentId,
        'flock_id' => (string) $flockId,
        'movement_type' => 'equipment_issue',
        'quantity' => '2',
        'occurred_on' => $today->format('Y-m-d'),
        'reason' => 'Affectation mangeoire',
    ], $today);
    $assert(
        StockService::lots($pdo, $userIds[0], $farmIds[0], $equipmentId) === []
            && count(StockService::lots($pdo, $userIds[0], $farmIds[0], $equipmentId, true)) === 1,
        'conserve le lot épuisé disponible pour la restitution ultérieure'
    );
    StockService::recordMovement($pdo, $userIds[0], $farmIds[0], [
        'product_id' => (string) $equipmentId,
        'flock_id' => (string) $flockId,
        'lot_id' => (string) $equipmentLotId,
        'movement_type' => 'equipment_return',
        'quantity' => '1',
        'occurred_on' => $today->format('Y-m-d'),
        'reason' => 'Retour mangeoire',
    ], $today);
    StockService::recordMovement($pdo, $userIds[0], $farmIds[0], [
        'product_id' => (string) $equipmentId,
        'flock_id' => (string) $flockId,
        'lot_id' => (string) $equipmentLotId,
        'movement_type' => 'equipment_return',
        'quantity' => '1',
        'occurred_on' => $today->format('Y-m-d'),
        'reason' => 'Second retour mangeoire',
    ], $today);
    $expectFailure(
        static fn () => StockService::recordMovement($pdo, $userIds[0], $farmIds[0], [
            'product_id' => (string) $equipmentId,
            'flock_id' => (string) $flockId,
            'lot_id' => (string) $equipmentLotId,
            'movement_type' => 'equipment_return',
            'quantity' => '1',
            'occurred_on' => $today->format('Y-m-d'),
            'reason' => 'Retour en trop',
        ], $today),
        'limite les retours au matériel réellement sorti'
    );

    $inventoryId = StockService::recordInventory($pdo, $userIds[0], $farmIds[0], [
        'product_id' => (string) $feedId,
        'counted_quantity' => '3',
        'counted_on' => $today->format('Y-m-d'),
        'reason' => 'Comptage physique avec écart',
    ], $today);
    $inventoryQuery = $pdo->prepare(
        'SELECT expected_quantity, counted_quantity, variance_quantity
         FROM inventory_counts WHERE farm_id = :farm_id AND id = :id'
    );
    $inventoryQuery->execute(['farm_id' => $farmIds[0], 'id' => $inventoryId]);
    $inventory = $inventoryQuery->fetch();
    $assert(
        is_array($inventory) && (float) $inventory['expected_quantity'] === 4.0
            && (float) $inventory['counted_quantity'] === 3.0
            && (float) $inventory['variance_quantity'] === -1.0,
        'enregistre un inventaire négatif comme ajustement documenté'
    );
    StockService::recordInventory($pdo, $userIds[0], $farmIds[0], [
        'product_id' => (string) $feedId,
        'counted_quantity' => '5',
        'counted_on' => $today->format('Y-m-d'),
        'reason' => 'Deuxième comptage de contrôle',
    ], $today);
    $expectFailure(
        static fn () => StockService::recordInventory($pdo, $userIds[0], $farmIds[0], [
            'product_id' => (string) $feedId,
            'counted_quantity' => '5',
            'counted_on' => $today->format('Y-m-d'),
            'reason' => '',
        ], $today),
        'exige un motif et rejette un inventaire invalide'
    );

    $lowStockProducts = StockService::lowStock($pdo, $userIds[0], $farmIds[0]);
    $assert(
        count(array_filter($lowStockProducts, static fn (array $product): bool =>
            (int) $product['id'] === $feedId)) === 1,
        'déclenche l’alerte lorsque le stock atteint exactement le seuil minimum'
    );
    $assert(count(StockService::history($pdo, $userIds[0], $farmIds[0])) > 0, 'expose un historique de mouvements');
    $assert(count(StockService::purchases($pdo, $userIds[0], $farmIds[0])) === 1, 'expose les achats enregistrés');
    $assert(count(StockService::inventories($pdo, $userIds[0], $farmIds[0])) === 2, 'expose les inventaires successifs');
    $assert(count(StockService::products($pdo, $userIds[2], $farmIds[0])) === 3, 'autorise la lecture à un rôle opérateur');
    $expectFailure(
        static fn () => StockService::recordInventory($pdo, $userIds[2], $farmIds[0], [
            'product_id' => (string) $feedId,
            'counted_quantity' => '5',
            'counted_on' => $today->format('Y-m-d'),
            'reason' => 'Tentative opérateur',
        ], $today),
        'refuse l’écriture à un rôle opérateur'
    );
    $expectFailure(
        static fn () => StockService::products($pdo, $userIds[1], $farmIds[0]),
        'isole le catalogue des utilisateurs étrangers à l’exploitation'
    );
    $expectFailure(
        static fn () => StockService::saveProduct($pdo, $userIds[1], $farmIds[0], [
            'name' => 'Intrusion',
            'sku' => 'INTRUSION-' . $suffix,
            'category' => 'Test',
            'product_type' => 'consumable',
            'unit_code' => 'kg',
            'default_unit_price' => '1',
            'minimum_stock' => '0',
        ]),
        'refuse la création dans une autre exploitation'
    );

    StockService::archiveProduct($pdo, $userIds[0], $farmIds[0], $expiredProductId);
    $archived = StockService::product($pdo, $userIds[0], $farmIds[0], $expiredProductId);
    $assert($archived['status'] === 'archived', 'archive un produit sans supprimer son historique');
} finally {
    foreach ($farmIds as $farmId) {
        $pdo->prepare('DELETE FROM stock_movements WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM purchase_items WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM purchases WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM inventory_counts WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM stock_lots WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM suppliers WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM products WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM expenses WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM flocks WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM farm_members WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM farms WHERE id = :farm_id')->execute(['farm_id' => $farmId]);
    }
    foreach ($userIds as $userId) {
        $pdo->prepare('DELETE FROM users WHERE id = :user_id')->execute(['user_id' => $userId]);
    }
}

printf("OK — %d assertions du catalogue et des stocks.\n", $passed);
