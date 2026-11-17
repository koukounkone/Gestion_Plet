<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Database\Connection;
use MonElevage\Sales\SalesService;
use MonElevage\Stock\StockService;

$pdo = Connection::get($config);
$suffix = strtoupper(bin2hex(random_bytes(4)));
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$today = $now->setTime(0, 0);
$farmId = null;
$privateFarmId = null;
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
$saleInput = static function (int $clientId, string $type, string $quantity, string $price, int $sourceId) use ($today): array {
    return [
        'client_id' => (string) $clientId,
        'sold_on' => $today->format('Y-m-d'),
        'notes' => '',
        'lines' => [[
            'source_type' => $type,
            'flock_id' => $type === 'animal' ? (string) $sourceId : '',
            'product_id' => $type === 'product' ? (string) $sourceId : '',
            'quantity' => $quantity,
            'unit_price' => $price,
        ]],
    ];
};

try {
    $createUser = $pdo->prepare(
        "INSERT INTO users (full_name, email, password_hash, status, email_verified_at)
         VALUES (:name, :email, 'test-hash', 'active', UTC_TIMESTAMP())"
    );
    foreach (['Ventes owner', 'Ventes outsider', 'Ventes comptable', 'Ventes opérateur'] as $index => $name) {
        $createUser->execute([
            'name' => $name,
            'email' => 'sales-' . strtolower($suffix) . '-' . $index . '@example.test',
        ]);
        $userIds[] = (int) $pdo->lastInsertId();
    }

    $createFarm = $pdo->prepare(
        "INSERT INTO farms (owner_user_id, name, currency_code, timezone)
         VALUES (:user_id, :name, 'XOF', 'UTC')"
    );
    $createFarm->execute(['user_id' => $userIds[0], 'name' => 'Ferme commerciale ' . $suffix]);
    $farmId = (int) $pdo->lastInsertId();
    $createFarm->execute(['user_id' => $userIds[1], 'name' => 'Ferme privée ventes ' . $suffix]);
    $privateFarmId = (int) $pdo->lastInsertId();

    $membership = $pdo->prepare(
        "INSERT INTO farm_members (farm_id, user_id, role, status)
         VALUES (:farm_id, :user_id, :role, 'active')"
    );
    $membership->execute(['farm_id' => $farmId, 'user_id' => $userIds[0], 'role' => 'owner']);
    $membership->execute(['farm_id' => $farmId, 'user_id' => $userIds[2], 'role' => 'accountant']);
    $membership->execute(['farm_id' => $farmId, 'user_id' => $userIds[3], 'role' => 'operator']);
    $membership->execute(['farm_id' => $privateFarmId, 'user_id' => $userIds[1], 'role' => 'owner']);

    $createFlock = $pdo->prepare(
        "INSERT INTO flocks
            (farm_id, code, name, flock_type, poultry_type, production_type,
             currency_code, started_on, initial_count, status, created_by_user_id)
         VALUES
            (:farm_id, :code, :name, 'broiler', 'chicken', 'meat',
             'XOF', :started_on, 10, 'active', :user_id)"
    );
    $createFlock->execute([
        'farm_id' => $farmId,
        'code' => 'SALE-' . $suffix,
        'name' => 'Poulets test ventes',
        'started_on' => $today->modify('-10 days')->format('Y-m-d'),
        'user_id' => $userIds[0],
    ]);
    $flockId = (int) $pdo->lastInsertId();

    $productId = StockService::saveProduct($pdo, $userIds[0], $farmId, [
        'name' => 'Œufs test ' . $suffix,
        'sku' => 'EGG-' . $suffix,
        'category' => 'Œufs',
        'product_type' => 'consumable',
        'unit_code' => 'unit',
        'default_unit_price' => '500',
        'minimum_stock' => '0',
    ]);
    foreach ([10, 10] as $lotIndex => $lotQuantity) {
        StockService::receiveStock($pdo, $userIds[0], $farmId, [
            'product_id' => (string) $productId,
            'quantity' => (string) $lotQuantity,
            'received_on' => $today->format('Y-m-d'),
            'expires_on' => '',
            'lot_code' => 'EGG-' . $suffix . '-' . $lotIndex,
            'reason' => 'Réception test vente',
        ], $today);
    }

    $clientId = SalesService::saveClient($pdo, $userIds[0], $farmId, [
        'name' => 'Client test ' . $suffix,
        'phone' => '+225 01020304',
        'email' => 'client-' . strtolower($suffix) . '@example.test',
        'address' => 'Abidjan',
        'notes' => '',
    ]);
    $assert($clientId > 0, 'crée un client dans l’exploitation');
    SalesService::saveClient($pdo, $userIds[0], $farmId, [
        'name' => 'Client modifié ' . $suffix,
        'phone' => '+225 01020304',
        'email' => 'client-' . strtolower($suffix) . '@example.test',
        'address' => 'Abidjan',
        'notes' => '',
    ], $clientId);
    $assert(
        SalesService::findClient($pdo, $userIds[0], $farmId, $clientId)['name']
            === 'Client modifié ' . $suffix,
        'modifie les coordonnées du client'
    );
    $assert(
        SalesService::validateClient(['name' => '', 'email' => 'incorrect'])['errors'] !== [],
        'rejette les coordonnées client invalides'
    );
    $invalidAnimal = $saleInput($clientId, 'animal', '1.5', '1000', $flockId);
    $assert(
        SalesService::validateSale($invalidAnimal, $today)['errors'] !== [],
        'refuse une quantité fractionnaire d’animaux'
    );

    $animalSaleId = SalesService::createSale(
        $pdo,
        $userIds[0],
        $farmId,
        $saleInput($clientId, 'animal', '2', '1000', $flockId),
        'XOF',
        $now
    );
    SalesService::recordPayment($pdo, $userIds[0], $farmId, $animalSaleId, [
        'amount' => '2000',
        'method' => 'cash',
        'reference' => 'CASH-ANIMAL',
    ], $now);
    SalesService::updateDeliveryStatus($pdo, $userIds[0], $farmId, $animalSaleId, 'shipped');
    SalesService::updateDeliveryStatus($pdo, $userIds[0], $farmId, $animalSaleId, 'delivered');
    $animalBalance = $pdo->prepare(
        'SELECT f.initial_count + COALESCE(SUM(m.quantity_delta), 0)
         FROM flocks f LEFT JOIN animal_movements m
           ON m.farm_id = f.farm_id AND m.flock_id = f.id
         WHERE f.farm_id = :farm_id AND f.id = :flock_id
         GROUP BY f.id, f.initial_count'
    );
    $animalBalance->execute(['farm_id' => $farmId, 'flock_id' => $flockId]);
    $assert((int) $animalBalance->fetchColumn() === 8, 'débite et trace les animaux vendus');
    $animalSalesCount = $pdo->prepare('SELECT COUNT(*) FROM sales WHERE farm_id = :farm_id');
    $animalSalesCount->execute(['farm_id' => $farmId]);
    $beforeInsufficientAnimals = (int) $animalSalesCount->fetchColumn();
    $expectFailure(
        static fn () => SalesService::createSale(
            $pdo,
            $userIds[0],
            $farmId,
            $saleInput($clientId, 'animal', '9', '1000', $flockId),
            'XOF',
            $now
        ),
        'refuse une vente supérieure à l’effectif disponible'
    );
    $animalSalesCount->execute(['farm_id' => $farmId]);
    $assert(
        (int) $animalSalesCount->fetchColumn() === $beforeInsufficientAnimals,
        'annule toute la transaction après un échec d’effectif'
    );

    $partialSaleId = SalesService::createSale(
        $pdo,
        $userIds[0],
        $farmId,
        $saleInput($clientId, 'product', '12', '500', $productId),
        'XOF',
        $now
    );
    SalesService::recordPayment($pdo, $userIds[0], $farmId, $partialSaleId, [
        'amount' => '2000',
        'method' => 'mobile_money',
        'reference' => 'MOMO-PARTIEL',
    ], $now);
    $partialSale = SalesService::findSale($pdo, $userIds[0], $farmId, $partialSaleId);
    $assert(
        (int) $partialSale['total_amount'] === 6000
            && (int) $partialSale['paid_amount'] === 2000
            && (int) $partialSale['balance_due'] === 4000,
        'calcule total, paiement partiel et solde en FCFA'
    );
    $saleLineId = (int) $partialSale['lines'][0]['id'];
    $multiLotMovement = $pdo->prepare(
        "SELECT COUNT(DISTINCT lot_id) FROM stock_movements
         WHERE farm_id = :farm_id AND sale_line_id = :line_id AND movement_type = 'sale'"
    );
    $multiLotMovement->execute(['farm_id' => $farmId, 'line_id' => $saleLineId]);
    $assert((int) $multiLotMovement->fetchColumn() === 2, 'répartit une vente entre plusieurs lots sans perdre la traçabilité');

    $unpaidSaleId = SalesService::createSale(
        $pdo,
        $userIds[0],
        $farmId,
        $saleInput($clientId, 'product', '2', '500', $productId),
        'XOF',
        $now
    );
    $roundingSaleId = SalesService::createSale(
        $pdo,
        $userIds[0],
        $farmId,
        $saleInput($clientId, 'product', '1.002', '499', $productId),
        'XOF',
        $now
    );
    $roundedSale = SalesService::findSale($pdo, $userIds[0], $farmId, $roundingSaleId);
    $assert(
        (int) $roundedSale['total_amount'] === 500,
        'arrondit le montant de ligne au FCFA le plus proche'
    );

    $animalSalesCount->execute(['farm_id' => $farmId]);
    $beforeInsufficientStock = (int) $animalSalesCount->fetchColumn();
    $expectFailure(
        static fn () => SalesService::createSale(
            $pdo,
            $userIds[0],
            $farmId,
            $saleInput($clientId, 'product', '100', '500', $productId),
            'XOF',
            $now
        ),
        'refuse une vente supérieure au stock disponible'
    );
    $animalSalesCount->execute(['farm_id' => $farmId]);
    $assert(
        (int) $animalSalesCount->fetchColumn() === $beforeInsufficientStock,
        'annule la commande et ses écritures si le stock est insuffisant'
    );

    $summary = SalesService::summary(
        $pdo,
        $userIds[0],
        $farmId,
        $today->format('Y-m-d'),
        $today->format('Y-m-d')
    );
    $assert(
        (int) $summary['sale_count'] === 4
            && (int) $summary['total_amount'] === 9500
            && (int) $summary['paid_amount'] === 4000
            && (int) $summary['balance_due'] === 5500,
        'agrège ventes payées, partiellement payées et impayées sans erreur de total'
    );
    $oldDate = $today->modify('-1 day')->format('Y-m-d');
    $emptySummary = SalesService::summary($pdo, $userIds[0], $farmId, $oldDate, $oldDate);
    $assert(
        (int) $emptySummary['sale_count'] === 0 && (int) $emptySummary['total_amount'] === 0,
        'filtre les ventes sur la période demandée'
    );
    $assert(
        count(SalesService::sales($pdo, $userIds[2], $farmId, $today->format('Y-m-d'), $today->format('Y-m-d'))) === 4,
        'autorise le rôle comptable à consulter les ventes'
    );
    SalesService::recordPayment($pdo, $userIds[2], $farmId, $unpaidSaleId, [
        'amount' => '300',
        'method' => 'bank_transfer',
        'reference' => 'COMPTE-TEST',
    ], $now);
    $expectFailure(
        static fn () => SalesService::recordPayment($pdo, $userIds[2], $farmId, $unpaidSaleId, [
            'amount' => '701',
            'method' => 'cash',
            'reference' => '',
        ], $now),
        'refuse un paiement supérieur au solde restant'
    );
    $expectFailure(
        static fn () => SalesService::createSale(
            $pdo,
            $userIds[2],
            $farmId,
            $saleInput($clientId, 'product', '1', '500', $productId),
            'XOF',
            $now
        ),
        'interdit au comptable de créer une vente'
    );
    $expectFailure(
        static fn () => SalesService::sales($pdo, $userIds[3], $farmId, $today->format('Y-m-d'), $today->format('Y-m-d')),
        'interdit la consultation des ventes au rôle opérateur'
    );
    $expectFailure(
        static fn () => SalesService::summary($pdo, $userIds[1], $farmId, $today->format('Y-m-d'), $today->format('Y-m-d')),
        'isole les ventes d’une autre exploitation'
    );
    $expectFailure(
        static fn () => SalesService::recordPayment($pdo, $userIds[1], $farmId, $unpaidSaleId, [
            'amount' => '1',
            'method' => 'cash',
            'reference' => '',
        ], $now),
        'interdit à un utilisateur externe de percevoir un paiement'
    );

    SalesService::voidSale($pdo, $userIds[0], $farmId, $partialSaleId, 'Annulation test partielle', $now);
    $cancelledPartial = SalesService::findSale($pdo, $userIds[0], $farmId, $partialSaleId);
    $assert(
        $cancelledPartial['status'] === 'void'
            && $cancelledPartial['delivery_status'] === 'cancelled'
            && (int) $cancelledPartial['paid_amount'] === 0
            && count(array_filter(
                $cancelledPartial['payments'],
                static fn (array $payment): bool => $payment['direction'] === 'refunded'
            )) === 1,
        'annule la vente partiellement payée et trace son remboursement'
    );
    $returnMovements = $pdo->prepare(
        "SELECT COUNT(*) FROM stock_movements
         WHERE farm_id = :farm_id AND movement_type = 'return'
           AND reversal_of_id IN (
               SELECT id FROM stock_movements WHERE farm_id = :inner_farm_id AND sale_line_id IN (
                   SELECT id FROM sale_lines WHERE farm_id = :line_farm_id AND sale_id = :sale_id
               ) AND movement_type = 'sale'
           )"
    );
    $returnMovements->execute([
        'farm_id' => $farmId,
        'inner_farm_id' => $farmId,
        'line_farm_id' => $farmId,
        'sale_id' => $partialSaleId,
    ]);
    $assert((int) $returnMovements->fetchColumn() === 2, 'restaure les lots par des mouvements de contrepassation traçables');
    SalesService::voidSale($pdo, $userIds[0], $farmId, $animalSaleId, 'Correction du reçu test', $now);
    $animalBalance->execute(['farm_id' => $farmId, 'flock_id' => $flockId]);
    $assert((int) $animalBalance->fetchColumn() === 10, 'restaure l’effectif lors de l’annulation de la vente');
    $expectFailure(
        static fn () => SalesService::voidSale($pdo, $userIds[0], $farmId, $animalSaleId, 'Annulation répétée', $now),
        'interdit la seconde annulation d’une vente'
    );
    SalesService::voidSale($pdo, $userIds[0], $farmId, $roundingSaleId, 'Arrondi vérifié', $now);

    $finalSummary = SalesService::summary(
        $pdo,
        $userIds[0],
        $farmId,
        $today->format('Y-m-d'),
        $today->format('Y-m-d')
    );
    $assert(
        (int) $finalSummary['sale_count'] === 1
            && (int) $finalSummary['total_amount'] === 1000
            && (int) $finalSummary['paid_amount'] === 300
            && (int) $finalSummary['balance_due'] === 700,
        'exclut les ventes annulées des recettes tout en conservant les encaissements nets'
    );
    SalesService::archiveClient($pdo, $userIds[0], $farmId, $clientId);
    $assert(
        SalesService::clients($pdo, $userIds[0], $farmId)[0]['archived_at'] !== null,
        'archive le client sans supprimer son historique de ventes'
    );
    $expectFailure(
        static fn () => SalesService::findClient($pdo, $userIds[0], $farmId, $clientId),
        'interdit de réutiliser un client archivé'
    );
} finally {
    if ($farmId !== null) {
        $pdo->prepare(
            'DELETE FROM stock_movements
             WHERE farm_id = :farm_id AND reversal_of_id IS NOT NULL'
        )->execute(['farm_id' => $farmId]);
        $pdo->prepare(
            'DELETE FROM stock_movements WHERE farm_id = :farm_id'
        )->execute(['farm_id' => $farmId]);
        $pdo->prepare(
            'DELETE FROM animal_movements
             WHERE farm_id = :farm_id AND reversal_of_id IS NOT NULL'
        )->execute(['farm_id' => $farmId]);
        $pdo->prepare(
            'DELETE FROM animal_movements WHERE farm_id = :farm_id'
        )->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM sale_payments WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM sale_lines WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM sales WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM clients WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM stock_lots WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM products WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM flocks WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM farm_members WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM farms WHERE id = :farm_id')->execute(['farm_id' => $farmId]);
    }
    if ($privateFarmId !== null) {
        $pdo->prepare('DELETE FROM farm_members WHERE farm_id = :farm_id')->execute(['farm_id' => $privateFarmId]);
        $pdo->prepare('DELETE FROM farms WHERE id = :farm_id')->execute(['farm_id' => $privateFarmId]);
    }
    foreach ($userIds as $userId) {
        $pdo->prepare('DELETE FROM users WHERE id = :user_id')->execute(['user_id' => $userId]);
    }
}

printf("OK — %d assertions du module commercial.\n", $passed);
