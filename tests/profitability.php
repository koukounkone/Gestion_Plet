<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Database\Connection;
use MonElevage\Finance\FinanceService;
use MonElevage\Sales\SalesService;
use MonElevage\Stock\StockService;

$pdo = Connection::get($config);
$suffix = strtoupper(bin2hex(random_bytes(4)));
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$today = $now->setTime(0, 0);
$from = $today->format('Y-m-d');
$to = $from;
$farmId = null;
$userId = null;
$accountantUserId = null;
$passed = 0;
$assert = static function (bool $condition, string $message) use (&$passed): void {
    if (!$condition) {
        throw new RuntimeException('Échec du test : ' . $message);
    }
    $passed++;
};

try {
    $createUser = $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, status, email_verified_at)
         VALUES ('Rentabilité test', :email, 'test-hash', 'active', UTC_TIMESTAMP())"
    );
    $createUser->execute(['email' => 'profit-' . strtolower($suffix) . '@example.test']);
    $userId = (int) $pdo->lastInsertId();

    $createFarm = $pdo->prepare(
        "INSERT INTO farms (owner_user_id, name, currency_code, timezone)
         VALUES (:user_id, :name, 'XOF', 'UTC')"
    );
    $createFarm->execute(['user_id' => $userId, 'name' => 'Ferme rentabilité ' . $suffix]);
    $farmId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO farm_members (farm_id, user_id, role, status)
         VALUES (:farm_id, :user_id, 'owner', 'active')"
    )->execute(['farm_id' => $farmId, 'user_id' => $userId]);
    $accountant = $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, status, email_verified_at)
         VALUES ('Comptable rentabilité test', :email, 'test-hash', 'active', UTC_TIMESTAMP())"
    );
    $accountant->execute(['email' => 'profit-accountant-' . strtolower($suffix) . '@example.test']);
    $accountantUserId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO farm_members (farm_id, user_id, role, status)
         VALUES (:farm_id, :user_id, 'accountant', 'active')"
    )->execute(['farm_id' => $farmId, 'user_id' => $accountantUserId]);

    $readOnlyReport = FinanceService::profitability($pdo, $accountantUserId, $farmId, $from, $to);
    $assert(
        $readOnlyReport['revenue_total'] === 0,
        'autorise la consultation du bilan au rôle comptable sans permission de modification'
    );

    $report = static fn (?int $flockId = null): array => FinanceService::profitability(
        $pdo,
        $userId,
        $farmId,
        $from,
        $to,
        $flockId
    );

    $empty = $report();
    $assert(
        $empty['revenue_total'] === 0 && $empty['cash_collected'] === 0
            && $empty['receivables'] === 0 && $empty['economic_costs'] === 0
            && $empty['estimated_profit'] === 0 && $empty['margin_percent'] === null
            && $empty['cost_per_animal_sold'] === null,
        'retourne des indicateurs explicites quand aucune donnée n’existe'
    );

    $createFlock = $pdo->prepare(
        "INSERT INTO flocks
            (farm_id, code, name, flock_type, poultry_type, production_type,
             currency_code, started_on, initial_count, status, created_by_user_id)
         VALUES
            (:farm_id, :code, 'Bande rentabilité', 'broiler', 'chicken', 'meat',
             'XOF', :started_on, 20, 'active', :user_id)"
    );
    $createFlock->execute([
        'farm_id' => $farmId,
        'code' => 'PROFIT-' . $suffix,
        'started_on' => $today->modify('-20 days')->format('Y-m-d'),
        'user_id' => $userId,
    ]);
    $flockId = (int) $pdo->lastInsertId();

    FinanceService::saveExpense($pdo, $userId, $farmId, [
        'incurred_on' => $from,
        'amount' => '500',
        'category' => 'feed',
        'expense_type' => 'charge',
        'description' => 'Dépenses avant vente',
        'flock_id' => (string) $flockId,
    ], $today);
    $lossBeforeSales = $report($flockId);
    $assert(
        $lossBeforeSales['revenue_total'] === 0
            && $lossBeforeSales['economic_costs'] === 500
            && $lossBeforeSales['estimated_profit'] === -500
            && $lossBeforeSales['margin_percent'] === null,
        'affiche une perte lorsque des dépenses existent sans vente'
    );

    $products = [];
    foreach ([
        ['Feed ' . $suffix, 'FEED-' . $suffix, 'Feed', 'consumable', 'kg'],
        ['Eggs ' . $suffix, 'EGG-' . $suffix, 'Eggs', 'consumable', 'unit'],
        ['Equipment ' . $suffix, 'EQUIP-' . $suffix, 'Equipment', 'equipment', 'unit'],
        ['Uncosted ' . $suffix, 'UNKNOWN-' . $suffix, 'Other', 'consumable', 'unit'],
    ] as [$name, $sku, $category, $type, $unit]) {
        $products[] = StockService::saveProduct($pdo, $userId, $farmId, [
            'name' => $name,
            'sku' => $sku,
            'category' => $category,
            'product_type' => $type,
            'unit_code' => $unit,
            'default_unit_price' => '100',
            'minimum_stock' => '0',
        ]);
    }
    $supplierId = StockService::saveSupplier($pdo, $userId, $farmId, [
        'name' => 'Fournisseur rentabilité ' . $suffix,
    ]);
    StockService::savePurchase($pdo, $userId, $farmId, [
        'supplier_id' => (string) $supplierId,
        'flock_id' => (string) $flockId,
        'purchased_on' => $from,
        'reference' => 'PROFIT-' . $suffix,
        'items' => [
            ['product_id' => (string) $products[0], 'quantity' => '10', 'unit_price' => '100'],
            ['product_id' => (string) $products[1], 'quantity' => '10', 'unit_price' => '100'],
            ['product_id' => (string) $products[2], 'quantity' => '1', 'unit_price' => '700'],
        ],
    ], 'XOF', $today);
    StockService::recordMovement($pdo, $userId, $farmId, [
        'product_id' => (string) $products[0],
        'flock_id' => (string) $flockId,
        'movement_type' => 'consumption',
        'quantity' => '2',
        'occurred_on' => $from,
        'reason' => 'Aliment consommé pour les tests',
    ], $today);
    StockService::receiveStock($pdo, $userId, $farmId, [
        'product_id' => (string) $products[3],
        'quantity' => '3',
        'received_on' => $from,
        'expires_on' => '',
        'lot_code' => 'NO-COST-' . $suffix,
        'reason' => 'Réception sans coût de test',
    ], $today);

    $createMortality = $pdo->prepare(
        "INSERT INTO animal_movements
            (farm_id, flock_id, movement_type, quantity_delta, occurred_at, reason, created_by_user_id)
         VALUES (:farm_id, :flock_id, 'mortality', -2, :occurred_at, 'Mortalité de test', :user_id)"
    );
    $createMortality->execute([
        'farm_id' => $farmId,
        'flock_id' => $flockId,
        'occurred_at' => $now->format('Y-m-d H:i:s'),
        'user_id' => $userId,
    ]);

    FinanceService::saveExpense($pdo, $userId, $farmId, [
        'incurred_on' => $from,
        'amount' => '300',
        'category' => 'health',
        'expense_type' => 'charge',
        'description' => 'Soins de la bande',
        'flock_id' => (string) $flockId,
    ], $today);
    FinanceService::saveExpense($pdo, $userId, $farmId, [
        'incurred_on' => $from,
        'amount' => '1000',
        'category' => 'equipment',
        'expense_type' => 'investment',
        'description' => 'Matériel réutilisable',
        'flock_id' => (string) $flockId,
    ], $today);

    $clientId = SalesService::saveClient($pdo, $userId, $farmId, ['name' => 'Client rentabilité']);
    $animalSaleId = SalesService::createSale($pdo, $userId, $farmId, [
        'client_id' => (string) $clientId,
        'sold_on' => $from,
        'lines' => [[
            'source_type' => 'animal',
            'flock_id' => (string) $flockId,
            'quantity' => '3',
            'unit_price' => '1000',
        ]],
    ], 'XOF', $now);
    $productSaleId = SalesService::createSale($pdo, $userId, $farmId, [
        'client_id' => (string) $clientId,
        'sold_on' => $from,
        'lines' => [[
            'source_type' => 'product',
            'product_id' => (string) $products[1],
            'flock_id' => (string) $flockId,
            'quantity' => '2',
            'unit_price' => '500',
        ]],
    ], 'XOF', $now);
    SalesService::recordPayment($pdo, $userId, $farmId, $productSaleId, [
        'amount' => '200',
        'method' => 'cash',
        'reference' => 'ACOMPTE-TEST',
    ], $now);

    $budgetId = FinanceService::saveBudget($pdo, $userId, $farmId, [
        'name' => 'Budget rentabilité test',
        'flock_id' => (string) $flockId,
        'starts_on' => $from,
        'ends_on' => $to,
        'lines' => [
            ['financial_type' => 'cash', 'category' => 'feed', 'planned_amount' => '1000'],
            ['financial_type' => 'consumed', 'category' => 'Feed', 'planned_amount' => '1000'],
            ['financial_type' => 'consumed', 'category' => 'health', 'planned_amount' => '500'],
            ['financial_type' => 'investment', 'category' => 'equipment', 'planned_amount' => '1500'],
        ],
    ]);

    $profitability = $report($flockId);
    $assert(
        $profitability['revenue_total'] === 4000
            && $profitability['cash_collected'] === 200
            && $profitability['receivables'] === 3800,
        'sépare recettes constatées, encaissements de la période et créances restantes'
    );
    $assert(
        $profitability['expenses_total'] === 4500
            && $profitability['cash_outflow'] === 4500
            && $profitability['direct_investments'] === 1000
            && $profitability['equipment_purchases'] === 700
            && $profitability['reusable_investments'] === 1700,
        'inclut les achats payés en trésorerie et distingue les investissements'
    );
    $assert(
        $profitability['direct_consumed_costs'] === 800
            && $profitability['stock_consumed_cost'] === 200
            && $profitability['product_cost_of_sales'] === 200
            && $profitability['economic_costs'] === 1200,
        'compte les charges directes, les consommations et le coût produit vendu sans recompter les achats'
    );
    $assert(
        $profitability['remaining_stock_value'] === 2300
            && $profitability['remaining_consumables_value'] === 1600
            && $profitability['remaining_equipment_value'] === 700,
        'valorise séparément consommables et matériel réutilisable encore en stock'
    );
    $assert(
        $profitability['animals_sold'] === 3
            && $profitability['mortality_count'] === 2
            && $profitability['flock_cost_for_animals'] === 1000
            && $profitability['cost_per_animal_sold'] === 333,
        'intègre les ventes, mortalités et coût affecté par animal vendu'
    );
    $assert(
        $profitability['estimated_profit'] === 2800
            && $profitability['margin_percent'] === 70.0,
        'calcule un bénéfice positif et sa marge économique'
    );
    $assert(
        $profitability['budget_comparison']['name'] === 'Budget rentabilité test'
            && $profitability['budget_comparison']['totals']['cash']['planned'] === 1000
            && $profitability['budget_comparison']['totals']['cash']['actual'] === 500
            && $profitability['budget_comparison']['totals']['consumed']['planned'] === 1500
            && $profitability['budget_comparison']['totals']['consumed']['actual'] === 500
            && $profitability['budget_comparison']['totals']['investment']['planned'] === 1500
            && $profitability['budget_comparison']['totals']['investment']['actual'] === 1000,
        'compare les lignes budgétaires au réalisé de même nature et catégorie'
    );
    $farmReport = $report();
    $assert(
        $farmReport['unvalued_stock_quantity'] === 3.0
            && !$farmReport['is_complete'],
        'signale les stocks dont aucun coût d’achat n’est disponible'
    );

    SalesService::voidSale($pdo, $userId, $farmId, $animalSaleId, 'Annulation test', $now);
    SalesService::voidSale($pdo, $userId, $farmId, $productSaleId, 'Annulation test', $now);
    $loss = $report($flockId);
    $assert(
        $loss['revenue_total'] === 0 && $loss['estimated_profit'] === -1000
            && $loss['margin_percent'] === null && $loss['mortality_count'] === 2,
        'reflète les remboursements et conserve une perte après les ventes annulées'
    );

    $expectInvalidPeriod = false;
    try {
        FinanceService::profitability($pdo, $userId, $farmId, '2026-02-31', $to);
    } catch (RuntimeException) {
        $expectInvalidPeriod = true;
    }
    $assert($expectInvalidPeriod, 'rejette une période invalide plutôt que de retourner un rapport trompeur');
} finally {
    if ($farmId !== null) {
        $pdo->prepare(
            'DELETE FROM stock_movements WHERE farm_id = :farm_id AND reversal_of_id IS NOT NULL'
        )->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM stock_movements WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare(
            'DELETE FROM animal_movements WHERE farm_id = :farm_id AND reversal_of_id IS NOT NULL'
        )->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM animal_movements WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM sale_payments WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM sale_lines WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM sales WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM clients WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM financial_budget_lines WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM financial_budgets WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM purchase_items WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM purchases WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM stock_lots WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM suppliers WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM products WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM expenses WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM flocks WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM farm_members WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM farms WHERE id = :farm_id')->execute(['farm_id' => $farmId]);
    }
    if ($userId !== null) {
        $pdo->prepare('DELETE FROM users WHERE id = :user_id')->execute(['user_id' => $userId]);
    }
    if ($accountantUserId !== null) {
        $pdo->prepare('DELETE FROM users WHERE id = :user_id')->execute(['user_id' => $accountantUserId]);
    }
}

printf("OK — %d assertions du bilan de rentabilité.\n", $passed);
