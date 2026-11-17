<?php
declare(strict_types=1);

namespace MonElevage\Sales;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;
use MonElevage\Auth\FarmAccess;
use MonElevage\Stock\StockService;

final class SalesService
{
    private const MAX_AMOUNT = 9999999999999;
    private const MAX_QUANTITY = 1000000000;
    private const DELIVERY_STATUSES = ['pending', 'preparing', 'shipped', 'delivered'];
    private const PAYMENT_METHODS = ['cash', 'bank_transfer', 'mobile_money', 'other'];

    public static function clients(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'sale.view');
        $statement = $pdo->prepare(
            'SELECT c.id, c.name, c.phone, c.email, c.address, c.notes,
                    c.archived_at, COUNT(s.id) AS sale_count
             FROM clients c
             LEFT JOIN sales s ON s.farm_id = c.farm_id AND s.client_id = c.id
             WHERE c.farm_id = :farm_id
             GROUP BY c.id, c.name, c.phone, c.email, c.address, c.notes, c.archived_at
             ORDER BY c.archived_at IS NOT NULL, c.name, c.id'
        );
        $statement->execute(['farm_id' => $farmId]);
        return $statement->fetchAll();
    }

    public static function activeClients(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'sale.view');
        $statement = $pdo->prepare(
            'SELECT id, name, phone, email
             FROM clients WHERE farm_id = :farm_id AND archived_at IS NULL
             ORDER BY name, id'
        );
        $statement->execute(['farm_id' => $farmId]);
        return $statement->fetchAll();
    }

    public static function findClient(PDO $pdo, int $userId, int $farmId, int $clientId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'sale.manage');
        $statement = $pdo->prepare(
            'SELECT id, name, phone, email, address, notes
             FROM clients
             WHERE farm_id = :farm_id AND id = :id AND archived_at IS NULL
             LIMIT 1'
        );
        $statement->execute(['farm_id' => $farmId, 'id' => $clientId]);
        $client = $statement->fetch();
        if (!is_array($client)) {
            throw new RuntimeException('Client introuvable ou archivé.');
        }
        return $client;
    }

    public static function products(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'sale.view');
        return array_values(array_filter(
            StockService::products($pdo, $userId, $farmId),
            static fn (array $product): bool =>
                $product['status'] === 'active' && (float) $product['current_stock'] > 0
        ));
    }

    public static function flocks(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'sale.view');
        $statement = $pdo->prepare(
            "SELECT f.id, f.code, f.name, f.initial_count,
                    COALESCE(SUM(m.quantity_delta), 0) AS movement_total
             FROM flocks f
             LEFT JOIN animal_movements m
               ON m.farm_id = f.farm_id AND m.flock_id = f.id
             WHERE f.farm_id = :farm_id AND f.status IN ('active', 'paused')
             GROUP BY f.id, f.code, f.name, f.initial_count
             ORDER BY f.name, f.id"
        );
        $statement->execute(['farm_id' => $farmId]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['available_count'] = (int) $row['initial_count'] + (int) $row['movement_total'];
        }
        unset($row);
        return $rows;
    }

    public static function validateClient(array $input): array
    {
        $name = self::text($input['name'] ?? null);
        $phone = self::text($input['phone'] ?? null);
        $email = self::text($input['email'] ?? null);
        $address = self::text($input['address'] ?? null);
        $notes = self::text($input['notes'] ?? null);
        $errors = [];
        if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
            $errors[] = 'Le nom du client est obligatoire (160 caractères maximum).';
        }
        if (mb_strlen($phone, 'UTF-8') > 40) {
            $errors[] = 'Le téléphone est limité à 40 caractères.';
        }
        if ($email !== '' && (mb_strlen($email, 'UTF-8') > 254
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors[] = 'Saisissez une adresse e-mail valide.';
        }
        if (mb_strlen($address, 'UTF-8') > 500 || mb_strlen($notes, 'UTF-8') > 5000) {
            $errors[] = 'L’adresse est limitée à 500 caractères et les notes à 5 000 caractères.';
        }
        return [
            'errors' => $errors,
            'values' => [
                'name' => $name,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'address' => $address !== '' ? $address : null,
                'notes' => $notes !== '' ? $notes : null,
            ],
        ];
    }

    public static function saveClient(
        PDO $pdo,
        int $userId,
        int $farmId,
        array $input,
        ?int $clientId = null
    ): int {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'sale.manage');
        $validated = self::validateClient($input);
        self::requireValid($validated);
        $values = $validated['values'];
        if ($clientId === null) {
            $statement = $pdo->prepare(
                'INSERT INTO clients
                    (farm_id, name, phone, email, address, notes, created_by_user_id)
                 VALUES
                    (:farm_id, :name, :phone, :email, :address, :notes, :user_id)'
            );
            $statement->execute($values + ['farm_id' => $farmId, 'user_id' => $userId]);
            return (int) $pdo->lastInsertId();
        }
        $statement = $pdo->prepare(
            'UPDATE clients SET name = :name, phone = :phone, email = :email,
                    address = :address, notes = :notes
             WHERE farm_id = :farm_id AND id = :id AND archived_at IS NULL'
        );
        $statement->execute($values + ['farm_id' => $farmId, 'id' => $clientId]);
        if ($statement->rowCount() === 0) {
            $exists = $pdo->prepare(
                'SELECT id FROM clients
                 WHERE farm_id = :farm_id AND id = :id AND archived_at IS NULL'
            );
            $exists->execute(['farm_id' => $farmId, 'id' => $clientId]);
            if ($exists->fetchColumn() === false) {
                throw new RuntimeException('Client introuvable ou archivé.');
            }
        }
        return $clientId;
    }

    public static function archiveClient(PDO $pdo, int $userId, int $farmId, int $clientId): void
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'sale.manage');
        $statement = $pdo->prepare(
            'UPDATE clients SET archived_at = UTC_TIMESTAMP()
             WHERE farm_id = :farm_id AND id = :id AND archived_at IS NULL'
        );
        $statement->execute(['farm_id' => $farmId, 'id' => $clientId]);
        if ($statement->rowCount() === 0) {
            throw new RuntimeException('Client introuvable ou déjà archivé.');
        }
    }

    public static function validateSale(array $input, DateTimeImmutable $today): array
    {
        $errors = [];
        $clientId = filter_var($input['client_id'] ?? null, FILTER_VALIDATE_INT);
        $soldOn = self::date($input['sold_on'] ?? null);
        $notes = self::text($input['notes'] ?? null);
        $lines = $input['lines'] ?? null;
        if (!is_int($clientId) || $clientId < 1) {
            $errors[] = 'Choisissez un client actif.';
        }
        if ($soldOn === null || $soldOn !== $today->format('Y-m-d')) {
            $errors[] = 'La commande doit être enregistrée à la date du jour de l’exploitation.';
        }
        if (mb_strlen($notes, 'UTF-8') > 1000) {
            $errors[] = 'Les notes sont limitées à 1 000 caractères.';
        }
        if (!is_array($lines) || $lines === [] || count($lines) > 30) {
            $errors[] = 'Une vente doit contenir entre 1 et 30 lignes.';
            $lines = [];
        }
        $normalizedLines = [];
        foreach ($lines as $index => $line) {
            if (!is_array($line)) {
                $errors[] = sprintf('Ligne %d : données invalides.', $index + 1);
                continue;
            }
            $type = self::text($line['source_type'] ?? null);
            $quantityRaw = self::text($line['quantity'] ?? null);
            $quantity = self::quantity($quantityRaw, $type === 'animal' ? 0 : 3);
            $priceRaw = self::text($line['unit_price'] ?? null);
            $unitPrice = preg_match('/^[0-9]{1,13}$/D', $priceRaw) === 1
                ? (int) $priceRaw
                : null;
            $flockRaw = self::text($line['flock_id'] ?? null);
            $productRaw = self::text($line['product_id'] ?? null);
            $flockId = $flockRaw === '' ? null : filter_var($flockRaw, FILTER_VALIDATE_INT);
            $productId = $productRaw === '' ? null : filter_var($productRaw, FILTER_VALIDATE_INT);
            if (!in_array($type, ['animal', 'product'], true)) {
                $errors[] = sprintf('Ligne %d : choisissez un type de vente valide.', $index + 1);
            }
            if ($quantity === null || $quantity <= 0 || $quantity > self::MAX_QUANTITY) {
                $errors[] = sprintf('Ligne %d : la quantité est invalide.', $index + 1);
            }
            if ($unitPrice === null || $unitPrice < 1 || $unitPrice > self::MAX_AMOUNT) {
                $errors[] = sprintf('Ligne %d : le prix unitaire doit être un montant positif en FCFA.', $index + 1);
            }
            if ($type === 'animal' && (!is_int($flockId) || $flockId < 1 || $quantity !== null
                && floor($quantity) !== $quantity)) {
                $errors[] = sprintf('Ligne %d : choisissez un élevage et une quantité entière d’animaux.', $index + 1);
            }
            if ($type === 'product' && (!is_int($productId) || $productId < 1)) {
                $errors[] = sprintf('Ligne %d : choisissez un produit du catalogue.', $index + 1);
            }
            if ($flockRaw !== '' && (!is_int($flockId) || $flockId < 1)) {
                $errors[] = sprintf('Ligne %d : élevage invalide.', $index + 1);
            }
            if ($type === 'product' && $productRaw !== '' && (!is_int($productId) || $productId < 1)) {
                $errors[] = sprintf('Ligne %d : produit invalide.', $index + 1);
            }
            if (in_array($type, ['animal', 'product'], true)
                && $quantity !== null && $quantity > 0
                && $unitPrice !== null && $unitPrice > 0) {
                $normalizedLines[] = [
                    'source_type' => $type,
                    'flock_id' => $type === 'animal' && is_int($flockId) ? $flockId
                        : ($type === 'product' && is_int($flockId) ? $flockId : null),
                    'product_id' => $type === 'product' && is_int($productId) ? $productId : null,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                ];
            }
        }
        return [
            'errors' => $errors,
            'values' => [
                'client_id' => is_int($clientId) ? $clientId : null,
                'sold_on' => $soldOn ?? '',
                'notes' => $notes !== '' ? $notes : null,
                'lines' => $normalizedLines,
            ],
        ];
    }

    public static function createSale(
        PDO $pdo,
        int $userId,
        int $farmId,
        array $input,
        string $currencyCode,
        DateTimeImmutable $now
    ): int {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'sale.manage');
        $validated = self::validateSale($input, $now);
        self::requireValid($validated);
        if (!preg_match('/^[A-Z]{3}$/D', $currencyCode)) {
            throw new RuntimeException('La devise de l’exploitation est invalide.');
        }
        $values = $validated['values'];
        $saleAt = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $pdo->beginTransaction();
        try {
            $clientQuery = $pdo->prepare(
                'SELECT name FROM clients
                 WHERE farm_id = :farm_id AND id = :client_id AND archived_at IS NULL
                 FOR UPDATE'
            );
            $clientQuery->execute(['farm_id' => $farmId, 'client_id' => $values['client_id']]);
            $clientName = $clientQuery->fetchColumn();
            if (!is_string($clientName)) {
                throw new RuntimeException('Le client choisi est introuvable ou archivé.');
            }
            $saleInsert = $pdo->prepare(
                "INSERT INTO sales
                    (farm_id, client_id, client_name_snapshot, sold_at, currency_code,
                     status, delivery_status, notes, created_by_user_id)
                 VALUES
                    (:farm_id, :client_id, :client_name, :sold_at, :currency_code,
                     'posted', 'pending', :notes, :user_id)"
            );
            $saleInsert->execute([
                'farm_id' => $farmId,
                'client_id' => $values['client_id'],
                'client_name' => $clientName,
                'sold_at' => $saleAt,
                'currency_code' => $currencyCode,
                'notes' => $values['notes'],
                'user_id' => $userId,
            ]);
            $saleId = (int) $pdo->lastInsertId();
            foreach ($values['lines'] as $line) {
                $description = null;
                if ($line['source_type'] === 'animal') {
                    $flockQuery = $pdo->prepare(
                        "SELECT id, name, initial_count FROM flocks
                         WHERE farm_id = :farm_id AND id = :flock_id
                           AND status IN ('active', 'paused') FOR UPDATE"
                    );
                    $flockQuery->execute([
                        'farm_id' => $farmId,
                        'flock_id' => $line['flock_id'],
                    ]);
                    $flock = $flockQuery->fetch();
                    if (!is_array($flock)) {
                        throw new RuntimeException('L’élevage de la vente est introuvable, clôturé ou inaccessible.');
                    }
                    $balanceQuery = $pdo->prepare(
                        'SELECT COALESCE(SUM(quantity_delta), 0)
                         FROM animal_movements
                         WHERE farm_id = :farm_id AND flock_id = :flock_id'
                    );
                    $balanceQuery->execute([
                        'farm_id' => $farmId,
                        'flock_id' => $line['flock_id'],
                    ]);
                    $available = (int) $flock['initial_count'] + (int) $balanceQuery->fetchColumn();
                    if ($available < $line['quantity']) {
                        throw new RuntimeException(sprintf(
                            'Effectif insuffisant dans « %s » : %d animal(aux) disponibles, %d demandé(s).',
                            $flock['name'],
                            $available,
                            $line['quantity']
                        ));
                    }
                    $description = (string) $flock['name'];
                } else {
                    $productQuery = $pdo->prepare(
                        "SELECT name, unit_code FROM products
                         WHERE farm_id = :farm_id AND id = :product_id AND status = 'active'
                         FOR UPDATE"
                    );
                    $productQuery->execute([
                        'farm_id' => $farmId,
                        'product_id' => $line['product_id'],
                    ]);
                    $product = $productQuery->fetch();
                    if (!is_array($product)) {
                        throw new RuntimeException('Le produit vendu est introuvable ou archivé.');
                    }
                    $description = (string) $product['name'] . ' (' . $product['unit_code'] . ')';
                }

                $lineInsert = $pdo->prepare(
                    'INSERT INTO sale_lines
                        (farm_id, sale_id, source_type, flock_id, product_id,
                         quantity, unit_price, description)
                     VALUES
                        (:farm_id, :sale_id, :source_type, :flock_id, :product_id,
                         :quantity, :unit_price, :description)'
                );
                $lineInsert->execute([
                    'farm_id' => $farmId,
                    'sale_id' => $saleId,
                    'source_type' => $line['source_type'],
                    'flock_id' => $line['flock_id'],
                    'product_id' => $line['product_id'],
                    'quantity' => number_format($line['quantity'], 3, '.', ''),
                    'unit_price' => $line['unit_price'],
                    'description' => $description,
                ]);
                $lineId = (int) $pdo->lastInsertId();
                if ($line['source_type'] === 'animal') {
                    $movement = $pdo->prepare(
                        "INSERT INTO animal_movements
                            (farm_id, flock_id, movement_type, quantity_delta, occurred_at,
                             sale_line_id, reason, created_by_user_id)
                         VALUES
                            (:farm_id, :flock_id, 'sale', :quantity_delta, :occurred_at,
                             :sale_line_id, :reason, :user_id)"
                    );
                    $movement->execute([
                        'farm_id' => $farmId,
                        'flock_id' => $line['flock_id'],
                        'quantity_delta' => -(int) $line['quantity'],
                        'occurred_at' => $saleAt,
                        'sale_line_id' => $lineId,
                        'reason' => 'Vente #' . $saleId . ' au client ' . $clientName,
                        'user_id' => $userId,
                    ]);
                } else {
                    StockService::recordSaleMovement(
                        $pdo,
                        $userId,
                        $farmId,
                        (int) $line['product_id'],
                        $lineId,
                        $line['quantity'],
                        $saleAt,
                        'Vente #' . $saleId . ' au client ' . $clientName,
                        $line['flock_id']
                    );
                }
            }
            $total = self::saleTotal($pdo, $farmId, $saleId);
            if ($total < 1 || $total > self::MAX_AMOUNT) {
                throw new RuntimeException('Le montant total de la vente dépasse la limite autorisée.');
            }
            $pdo->commit();
            return $saleId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function recordPayment(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $saleId,
        array $input,
        DateTimeImmutable $now
    ): int {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'payment.manage');
        $amountRaw = self::text($input['amount'] ?? null);
        $amount = preg_match('/^[0-9]{1,13}$/D', $amountRaw) === 1 ? (int) $amountRaw : null;
        $method = self::text($input['method'] ?? null);
        $reference = self::text($input['reference'] ?? null);
        if ($saleId < 1 || $amount === null || $amount < 1 || $amount > self::MAX_AMOUNT) {
            throw new RuntimeException('Le montant du paiement doit être un entier positif en FCFA.');
        }
        if (!in_array($method, self::PAYMENT_METHODS, true)) {
            throw new RuntimeException('Choisissez un moyen de paiement valide.');
        }
        if (mb_strlen($reference, 'UTF-8') > 120) {
            throw new RuntimeException('La référence du paiement est limitée à 120 caractères.');
        }
        $pdo->beginTransaction();
        try {
            $saleQuery = $pdo->prepare(
                "SELECT id, currency_code FROM sales
                 WHERE farm_id = :farm_id AND id = :sale_id AND status = 'posted'
                 FOR UPDATE"
            );
            $saleQuery->execute(['farm_id' => $farmId, 'sale_id' => $saleId]);
            $sale = $saleQuery->fetch();
            if (!is_array($sale)) {
                throw new RuntimeException('La vente est introuvable ou annulée.');
            }
            $total = self::saleTotal($pdo, $farmId, $saleId);
            $paid = self::netPayments($pdo, $farmId, $saleId);
            if ($amount > $total - $paid) {
                throw new RuntimeException(sprintf(
                    'Le paiement dépasse le solde restant de %s %s.',
                    number_format(max(0, $total - $paid), 0, ',', ' '),
                    $sale['currency_code']
                ));
            }
            $insert = $pdo->prepare(
                "INSERT INTO sale_payments
                    (farm_id, sale_id, direction, amount, paid_at, method,
                     reference, status, created_by_user_id)
                 VALUES (:farm_id, :sale_id, 'received', :amount, :paid_at, :method,
                         :reference, 'posted', :user_id)"
            );
            $insert->execute([
                'farm_id' => $farmId,
                'sale_id' => $saleId,
                'amount' => $amount,
                'paid_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'method' => $method,
                'reference' => $reference !== '' ? $reference : null,
                'user_id' => $userId,
            ]);
            $paymentId = (int) $pdo->lastInsertId();
            $pdo->commit();
            return $paymentId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function updateDeliveryStatus(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $saleId,
        string $status
    ): void {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'sale.manage');
        if (!in_array($status, self::DELIVERY_STATUSES, true)) {
            throw new RuntimeException('Choisissez un statut de livraison valide.');
        }
        $statement = $pdo->prepare(
            "UPDATE sales SET delivery_status = :delivery_status
             WHERE farm_id = :farm_id AND id = :id AND status = 'posted'"
        );
        $statement->execute(['delivery_status' => $status, 'farm_id' => $farmId, 'id' => $saleId]);
        if ($statement->rowCount() === 0) {
            $exists = $pdo->prepare(
                "SELECT id FROM sales WHERE farm_id = :farm_id AND id = :id AND status = 'posted'"
            );
            $exists->execute(['farm_id' => $farmId, 'id' => $saleId]);
            if ($exists->fetchColumn() === false) {
                throw new RuntimeException('Vente introuvable ou annulée.');
            }
        }
    }

    public static function voidSale(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $saleId,
        string $reason,
        DateTimeImmutable $now
    ): void {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'sale.manage');
        $reason = trim($reason);
        if ($saleId < 1 || mb_strlen($reason, 'UTF-8') < 3 || mb_strlen($reason, 'UTF-8') > 500) {
            throw new RuntimeException('Indiquez un motif d’annulation de 3 à 500 caractères.');
        }
        $pdo->beginTransaction();
        try {
            $saleQuery = $pdo->prepare(
                "SELECT id FROM sales
                 WHERE farm_id = :farm_id AND id = :sale_id AND status = 'posted'
                 FOR UPDATE"
            );
            $saleQuery->execute(['farm_id' => $farmId, 'sale_id' => $saleId]);
            if ($saleQuery->fetchColumn() === false) {
                throw new RuntimeException('Vente introuvable ou déjà annulée.');
            }
            $paymentBalance = self::netPayments($pdo, $farmId, $saleId);
            if ($paymentBalance > 0) {
                $refund = $pdo->prepare(
                    "INSERT INTO sale_payments
                        (farm_id, sale_id, direction, amount, paid_at, method,
                         reference, status, created_by_user_id)
                     VALUES
                        (:farm_id, :sale_id, 'refunded', :amount, :paid_at, 'other',
                         :reference, 'posted', :user_id)"
                );
                $refund->execute([
                    'farm_id' => $farmId,
                    'sale_id' => $saleId,
                    'amount' => $paymentBalance,
                    'paid_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                    'reference' => 'Remboursement après annulation',
                    'user_id' => $userId,
                ]);
            }

            $animalMovements = $pdo->prepare(
                'SELECT id, flock_id, quantity_delta, occurred_at
                 FROM animal_movements
                 WHERE farm_id = :farm_id AND sale_line_id IN (
                     SELECT id FROM sale_lines WHERE farm_id = :line_farm_id AND sale_id = :sale_id
                 ) AND movement_type = \'sale\' AND reversal_of_id IS NULL
                 FOR UPDATE'
            );
            $animalMovements->execute([
                'farm_id' => $farmId,
                'line_farm_id' => $farmId,
                'sale_id' => $saleId,
            ]);
            $restoreAnimals = $pdo->prepare(
                "INSERT INTO animal_movements
                    (farm_id, flock_id, movement_type, quantity_delta, occurred_at,
                     reversal_of_id, reason, created_by_user_id)
                 VALUES (:farm_id, :flock_id, 'adjustment', :quantity_delta, :occurred_at,
                         :reversal_of_id, :reason, :user_id)"
            );
            foreach ($animalMovements->fetchAll() as $movement) {
                $restoreAnimals->execute([
                    'farm_id' => $farmId,
                    'flock_id' => $movement['flock_id'],
                    'quantity_delta' => abs((int) $movement['quantity_delta']),
                    'occurred_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                    'reversal_of_id' => $movement['id'],
                    'reason' => 'Annulation vente #' . $saleId . ' : ' . $reason,
                    'user_id' => $userId,
                ]);
            }

            $stockMovements = $pdo->prepare(
                "SELECT id, product_id, lot_id, flock_id, quantity_delta
                 FROM stock_movements
                 WHERE farm_id = :farm_id AND sale_line_id IN (
                     SELECT id FROM sale_lines WHERE farm_id = :line_farm_id AND sale_id = :sale_id
                 ) AND movement_type = 'sale' AND reversal_of_id IS NULL
                 FOR UPDATE"
            );
            $stockMovements->execute([
                'farm_id' => $farmId,
                'line_farm_id' => $farmId,
                'sale_id' => $saleId,
            ]);
            $restoreStock = $pdo->prepare(
                "INSERT INTO stock_movements
                    (farm_id, product_id, lot_id, flock_id, movement_type, quantity_delta,
                     occurred_at, reversal_of_id, reason, created_by_user_id)
                 VALUES (:farm_id, :product_id, :lot_id, :flock_id, 'return', :quantity_delta,
                         :occurred_at, :reversal_of_id, :reason, :user_id)"
            );
            foreach ($stockMovements->fetchAll() as $movement) {
                $restoreStock->execute([
                    'farm_id' => $farmId,
                    'product_id' => $movement['product_id'],
                    'lot_id' => $movement['lot_id'],
                    'flock_id' => $movement['flock_id'],
                    'quantity_delta' => abs((float) $movement['quantity_delta']),
                    'occurred_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                    'reversal_of_id' => $movement['id'],
                    'reason' => 'Annulation vente #' . $saleId . ' : ' . $reason,
                    'user_id' => $userId,
                ]);
            }
            $void = $pdo->prepare(
                "UPDATE sales
                 SET status = 'void', delivery_status = 'cancelled',
                     voided_at = :voided_at, voided_by_user_id = :user_id,
                     void_reason = :reason
                 WHERE farm_id = :farm_id AND id = :sale_id AND status = 'posted'"
            );
            $void->execute([
                'voided_at' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'user_id' => $userId,
                'reason' => $reason,
                'farm_id' => $farmId,
                'sale_id' => $saleId,
            ]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function summary(
        PDO $pdo,
        int $userId,
        int $farmId,
        string $from,
        string $to
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'sale.view');
        $statement = $pdo->prepare(
            "SELECT COUNT(*) AS sale_count,
                    COALESCE(SUM(amounts.total_amount), 0) AS total_amount,
                    COALESCE(SUM(amounts.paid_amount), 0) AS paid_amount,
                    COALESCE(SUM(amounts.balance_due), 0) AS balance_due,
                    COALESCE(SUM(s.delivery_status NOT IN ('delivered', 'cancelled')), 0) AS open_deliveries
             FROM sales s
             INNER JOIN (
                 SELECT s2.farm_id, s2.id,
                        COALESCE(line_totals.total_amount, 0) AS total_amount,
                        COALESCE(payment_totals.paid_amount, 0) AS paid_amount,
                        GREATEST(COALESCE(line_totals.total_amount, 0) - COALESCE(payment_totals.paid_amount, 0), 0) AS balance_due
                 FROM sales s2
                 LEFT JOIN (
                     SELECT farm_id, sale_id, SUM(line_total) AS total_amount
                     FROM sale_lines GROUP BY farm_id, sale_id
                 ) line_totals ON line_totals.farm_id = s2.farm_id AND line_totals.sale_id = s2.id
                 LEFT JOIN (
                     SELECT farm_id, sale_id,
                            SUM(CASE direction WHEN 'received' THEN amount ELSE -amount END) AS paid_amount
                     FROM sale_payments WHERE status = 'posted'
                     GROUP BY farm_id, sale_id
                 ) payment_totals ON payment_totals.farm_id = s2.farm_id AND payment_totals.sale_id = s2.id
                 WHERE s2.farm_id = :inner_farm_id AND s2.status = 'posted'
             ) amounts ON amounts.farm_id = s.farm_id AND amounts.id = s.id
             WHERE s.farm_id = :farm_id AND s.status = 'posted'
               AND s.sold_at >= CONCAT(:from_date, ' 00:00:00')
               AND s.sold_at < DATE_ADD(:to_date, INTERVAL 1 DAY)"
        );
        $statement->execute([
            'inner_farm_id' => $farmId,
            'farm_id' => $farmId,
            'from_date' => $from,
            'to_date' => $to,
        ]);
        $summary = $statement->fetch();
        return is_array($summary) ? $summary : [
            'sale_count' => 0,
            'total_amount' => 0,
            'paid_amount' => 0,
            'balance_due' => 0,
            'open_deliveries' => 0,
        ];
    }

    public static function sales(PDO $pdo, int $userId, int $farmId, string $from, string $to): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'sale.view');
        $statement = $pdo->prepare(
            "SELECT s.id, s.client_id, s.client_name_snapshot, s.sold_at, s.currency_code,
                    s.status, s.delivery_status, s.void_reason,
                    COALESCE(line_totals.total_amount, 0) AS total_amount,
                    COALESCE(payment_totals.paid_amount, 0) AS paid_amount,
                    GREATEST(COALESCE(line_totals.total_amount, 0) - COALESCE(payment_totals.paid_amount, 0), 0) AS balance_due,
                    COALESCE(line_totals.line_count, 0) AS line_count
             FROM sales s
             LEFT JOIN (
                 SELECT farm_id, sale_id, SUM(line_total) AS total_amount, COUNT(*) AS line_count
                 FROM sale_lines GROUP BY farm_id, sale_id
             ) line_totals ON line_totals.farm_id = s.farm_id AND line_totals.sale_id = s.id
             LEFT JOIN (
                 SELECT farm_id, sale_id,
                        SUM(CASE direction WHEN 'received' THEN amount ELSE -amount END) AS paid_amount
                 FROM sale_payments WHERE status = 'posted'
                 GROUP BY farm_id, sale_id
             ) payment_totals ON payment_totals.farm_id = s.farm_id AND payment_totals.sale_id = s.id
             WHERE s.farm_id = :farm_id
               AND s.sold_at >= CONCAT(:from_date, ' 00:00:00')
               AND s.sold_at < DATE_ADD(:to_date, INTERVAL 1 DAY)
             ORDER BY s.sold_at DESC, s.id DESC
             LIMIT 500"
        );
        $statement->execute(['farm_id' => $farmId, 'from_date' => $from, 'to_date' => $to]);
        return $statement->fetchAll();
    }

    public static function findSale(PDO $pdo, int $userId, int $farmId, int $saleId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'sale.view');
        $statement = $pdo->prepare(
            "SELECT s.*, c.phone AS client_phone, c.email AS client_email,
                    c.address AS client_address,
                    COALESCE(line_totals.total_amount, 0) AS total_amount,
                    COALESCE(payment_totals.paid_amount, 0) AS paid_amount,
                    GREATEST(COALESCE(line_totals.total_amount, 0) - COALESCE(payment_totals.paid_amount, 0), 0) AS balance_due
             FROM sales s
             LEFT JOIN clients c ON c.farm_id = s.farm_id AND c.id = s.client_id
             LEFT JOIN (
                 SELECT farm_id, sale_id, SUM(line_total) AS total_amount
                 FROM sale_lines GROUP BY farm_id, sale_id
             ) line_totals ON line_totals.farm_id = s.farm_id AND line_totals.sale_id = s.id
             LEFT JOIN (
                 SELECT farm_id, sale_id,
                        SUM(CASE direction WHEN 'received' THEN amount ELSE -amount END) AS paid_amount
                 FROM sale_payments WHERE status = 'posted'
                 GROUP BY farm_id, sale_id
             ) payment_totals ON payment_totals.farm_id = s.farm_id AND payment_totals.sale_id = s.id
             WHERE s.farm_id = :farm_id AND s.id = :sale_id
             LIMIT 1"
        );
        $statement->execute(['farm_id' => $farmId, 'sale_id' => $saleId]);
        $sale = $statement->fetch();
        if (!is_array($sale)) {
            throw new RuntimeException('Commande ou vente introuvable.');
        }
        $lineQuery = $pdo->prepare(
            'SELECT sl.id, sl.source_type, sl.flock_id, sl.product_id,
                    sl.quantity, sl.unit_price, sl.line_total, sl.description,
                    f.name AS flock_name, p.name AS product_name, p.unit_code
             FROM sale_lines sl
             LEFT JOIN flocks f ON f.farm_id = sl.farm_id AND f.id = sl.flock_id
             LEFT JOIN products p ON p.farm_id = sl.farm_id AND p.id = sl.product_id
             WHERE sl.farm_id = :farm_id AND sl.sale_id = :sale_id
             ORDER BY sl.id'
        );
        $lineQuery->execute(['farm_id' => $farmId, 'sale_id' => $saleId]);
        $sale['lines'] = $lineQuery->fetchAll();
        $paymentQuery = $pdo->prepare(
            'SELECT id, direction, amount, paid_at, method, reference, status,
                    void_reason, voided_at
             FROM sale_payments
             WHERE farm_id = :farm_id AND sale_id = :sale_id
             ORDER BY paid_at, id'
        );
        $paymentQuery->execute(['farm_id' => $farmId, 'sale_id' => $saleId]);
        $sale['payments'] = $paymentQuery->fetchAll();
        return $sale;
    }

    private static function saleTotal(PDO $pdo, int $farmId, int $saleId): int
    {
        $statement = $pdo->prepare(
            'SELECT COALESCE(SUM(line_total), 0)
             FROM sale_lines WHERE farm_id = :farm_id AND sale_id = :sale_id'
        );
        $statement->execute(['farm_id' => $farmId, 'sale_id' => $saleId]);
        return (int) $statement->fetchColumn();
    }

    private static function netPayments(PDO $pdo, int $farmId, int $saleId): int
    {
        $statement = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE direction WHEN 'received' THEN amount ELSE -amount END), 0)
             FROM sale_payments
             WHERE farm_id = :farm_id AND sale_id = :sale_id AND status = 'posted'"
        );
        $statement->execute(['farm_id' => $farmId, 'sale_id' => $saleId]);
        return (int) $statement->fetchColumn();
    }

    private static function requireValid(array $validated): void
    {
        if ($validated['errors'] !== []) {
            throw new RuntimeException(implode(' ', $validated['errors']));
        }
    }

    private static function quantity(string $value, int $scale): ?float
    {
        $fraction = $scale > 0 ? '(?:\.\d{1,' . $scale . '})?' : '';
        if (preg_match('/^[0-9]{1,12}' . $fraction . '$/D', $value) !== 1) {
            return null;
        }
        $quantity = (float) $value;
        return is_finite($quantity) ? $quantity : null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) || is_numeric($value) ? trim((string) $value) : '';
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }
}
