<?php
declare(strict_types=1);

namespace MonElevage\Stock;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;
use MonElevage\Auth\FarmAccess;

final class StockService
{
    private const MAX_QUANTITY = 1000000000.0;
    private const MAX_UNIT_PRICE = 1000000000.0;
    private const MAX_AMOUNT = 9999999999999.99;

    public static function validateProduct(array $input): array
    {
        $errors = [];
        $name = self::text($input['name'] ?? null);
        $sku = strtoupper(self::text($input['sku'] ?? null));
        $category = self::text($input['category'] ?? null);
        $type = self::text($input['product_type'] ?? null);
        $unit = self::text($input['unit_code'] ?? null);
        $minimum = self::decimal($input['minimum_stock'] ?? '0', 3);
        $price = self::decimal($input['default_unit_price'] ?? '0', 4);
        $notes = self::text($input['notes'] ?? null);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
            $errors[] = 'Le nom du produit est obligatoire (160 caractères maximum).';
        }
        if ($sku !== '' && !preg_match('/^[A-Z0-9][A-Z0-9._-]{0,63}$/D', $sku)) {
            $errors[] = 'La référence doit utiliser uniquement des lettres, chiffres, points, tirets ou tirets bas.';
        }
        if ($category === '' || mb_strlen($category, 'UTF-8') > 60) {
            $errors[] = 'La catégorie est obligatoire (60 caractères maximum).';
        }
        if (!in_array($type, ['consumable', 'equipment'], true)) {
            $errors[] = 'Choisissez consommable ou équipement réutilisable.';
        }
        if (!preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $unit)) {
            $errors[] = 'L’unité doit contenir 1 à 20 lettres, chiffres, tirets ou tirets bas.';
        }
        if ($minimum === null || $minimum < 0 || $minimum > self::MAX_QUANTITY) {
            $errors[] = 'Le seuil d’alerte doit être un nombre positif ou nul.';
        }
        if ($price === null || $price < 0 || $price > self::MAX_UNIT_PRICE) {
            $errors[] = 'Le prix unitaire de référence doit être un nombre positif ou nul.';
        }
        if (mb_strlen($notes, 'UTF-8') > 4000) {
            $errors[] = 'Les notes du produit sont limitées à 4 000 caractères.';
        }

        return [
            'errors' => $errors,
            'values' => [
                'name' => $name,
                'sku' => $sku !== '' ? $sku : null,
                'category' => $category,
                'product_type' => $type,
                'unit_code' => $unit,
                'minimum_stock' => $minimum,
                'default_unit_price' => $price,
                'notes' => $notes !== '' ? $notes : null,
            ],
        ];
    }

    public static function validateSupplier(array $input): array
    {
        $errors = [];
        $name = self::text($input['name'] ?? null);
        $contact = self::text($input['contact_name'] ?? null);
        $phone = self::text($input['phone'] ?? null);
        $email = self::text($input['email'] ?? null);
        $address = self::text($input['address'] ?? null);
        $notes = self::text($input['notes'] ?? null);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 160) {
            $errors[] = 'Le nom du fournisseur est obligatoire (160 caractères maximum).';
        }
        if (mb_strlen($contact, 'UTF-8') > 120 || mb_strlen($phone, 'UTF-8') > 40
            || mb_strlen($address, 'UTF-8') > 500 || mb_strlen($notes, 'UTF-8') > 4000) {
            $errors[] = 'Les coordonnées du fournisseur dépassent une longueur autorisée.';
        }
        if ($email !== '' && (mb_strlen($email, 'UTF-8') > 254
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
            $errors[] = 'L’adresse e-mail du fournisseur est invalide.';
        }
        return [
            'errors' => $errors,
            'values' => [
                'name' => $name,
                'contact_name' => $contact !== '' ? $contact : null,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'address' => $address !== '' ? $address : null,
                'notes' => $notes !== '' ? $notes : null,
            ],
        ];
    }

    public static function validatePurchase(
        array $input,
        DateTimeImmutable $today,
        string $currencyCode = 'XOF'
    ): array
    {
        $errors = [];
        $supplierId = filter_var($input['supplier_id'] ?? null, FILTER_VALIDATE_INT);
        $flockRaw = $input['flock_id'] ?? '';
        $flockId = $flockRaw === '' ? null : filter_var($flockRaw, FILTER_VALIDATE_INT);
        $date = self::date($input['purchased_on'] ?? null);
        $reference = self::text($input['reference'] ?? null);
        $notes = self::text($input['notes'] ?? null);
        $items = $input['items'] ?? null;
        if (!is_int($supplierId) || $supplierId < 1) {
            $errors[] = 'Choisissez un fournisseur valide.';
        }
        if ($flockRaw !== '' && (!is_int($flockId) || $flockId < 1)) {
            $errors[] = 'Choisissez un élevage valide ou laissez ce champ vide.';
        }
        if ($date === null || $date > $today->format('Y-m-d')) {
            $errors[] = 'La date d’achat doit être valide et ne peut pas être dans le futur.';
        }
        if (mb_strlen($reference, 'UTF-8') > 100 || mb_strlen($notes, 'UTF-8') > 4000) {
            $errors[] = 'La référence ou les notes de l’achat dépassent la longueur autorisée.';
        }
        if (!is_array($items) || $items === [] || count($items) > 30) {
            $errors[] = 'Un achat doit contenir entre 1 et 30 lignes.';
            $items = [];
        }
        $validatedItems = [];
        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                $errors[] = 'Chaque ligne d’achat doit contenir des champs valides.';
                continue;
            }
            $productId = filter_var($item['product_id'] ?? null, FILTER_VALIDATE_INT);
            $quantity = self::decimal($item['quantity'] ?? null, 3);
            $unitPrice = self::decimal($item['unit_price'] ?? null, 4);
            $expiresText = self::text($item['expires_on'] ?? null);
            $expiresOn = $expiresText === '' ? null : self::date($expiresText);
            if (!is_int($productId) || $productId < 1) {
                $errors[] = sprintf('Ligne %d : choisissez un produit valide.', $index + 1);
            }
            if ($quantity === null || $quantity <= 0 || $quantity > self::MAX_QUANTITY) {
                $errors[] = sprintf('Ligne %d : la quantité doit être positive et au plus égale à 1 000 000 000.', $index + 1);
            }
            if ($unitPrice === null || $unitPrice <= 0 || $unitPrice > self::MAX_UNIT_PRICE) {
                $errors[] = sprintf('Ligne %d : le prix unitaire doit être positif.', $index + 1);
            }
            if ($expiresText !== '' && ($expiresOn === null
                || ($date !== null && $expiresOn < $date))) {
                $errors[] = sprintf('Ligne %d : la péremption doit être une date valide postérieure ou égale à l’achat.', $index + 1);
            }
            if (is_int($productId) && $productId > 0
                && $quantity !== null && $quantity > 0
                && $unitPrice !== null && $unitPrice > 0
                && ($expiresText === '' || $expiresOn !== null)) {
                $lineTotal = round($quantity * $unitPrice, $currencyCode === 'XOF' ? 0 : 2);
                if (!is_finite($lineTotal) || $lineTotal <= 0 || $lineTotal > 9999999999999.99) {
                    $errors[] = sprintf('Ligne %d : le montant calculé dépasse la limite.', $index + 1);
                } else {
                    $validatedItems[] = [
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'line_total' => $lineTotal,
                        'expires_on' => $expiresOn,
                    ];
                }
            }
        }
        $total = round(array_sum(array_column($validatedItems, 'line_total')), 2);
        if ($total <= 0 || $total > self::MAX_AMOUNT) {
            $errors[] = 'Le montant total de l’achat est invalide.';
        }
        return [
            'errors' => $errors,
            'values' => [
                'supplier_id' => is_int($supplierId) ? $supplierId : null,
                'flock_id' => is_int($flockId) ? $flockId : null,
                'purchased_on' => $date ?? '',
                'reference' => $reference !== '' ? $reference : null,
                'notes' => $notes !== '' ? $notes : null,
                'items' => $validatedItems,
                'total_amount' => $total,
            ],
        ];
    }

    public static function validateMovement(array $input, DateTimeImmutable $today): array
    {
        $errors = [];
        $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
        $flockRaw = $input['flock_id'] ?? '';
        $flockId = $flockRaw === '' ? null : filter_var($flockRaw, FILTER_VALIDATE_INT);
        $lotRaw = $input['lot_id'] ?? '';
        $lotId = $lotRaw === '' ? null : filter_var($lotRaw, FILTER_VALIDATE_INT);
        $type = self::text($input['movement_type'] ?? null);
        $quantity = self::decimal($input['quantity'] ?? null, 3);
        $date = self::date($input['occurred_on'] ?? null);
        $reason = self::text($input['reason'] ?? null);
        if (!is_int($productId) || $productId < 1) {
            $errors[] = 'Choisissez un produit valide.';
        }
        if ($flockRaw !== '' && (!is_int($flockId) || $flockId < 1)) {
            $errors[] = 'Choisissez un élevage valide.';
        }
        if ($lotRaw !== '' && (!is_int($lotId) || $lotId < 1)) {
            $errors[] = 'Choisissez un lot valide.';
        }
        if (!in_array($type, ['consumption', 'loss', 'equipment_issue', 'equipment_return'], true)) {
            $errors[] = 'Choisissez une sortie, consommation ou restitution valide.';
        }
        if ($quantity === null || $quantity <= 0 || $quantity > self::MAX_QUANTITY) {
            $errors[] = 'La quantité doit être positive et inférieure à 1 000 000 000.';
        }
        if ($date === null || $date > $today->format('Y-m-d')) {
            $errors[] = 'La date du mouvement est invalide ou future.';
        }
        if (mb_strlen($reason, 'UTF-8') < 3 || mb_strlen($reason, 'UTF-8') > 500) {
            $errors[] = 'Indiquez un motif de 3 à 500 caractères.';
        }
        if (in_array($type, ['consumption', 'equipment_issue'], true)
            && (!is_int($flockId) || $flockId < 1)) {
            $errors[] = 'Associez la consommation ou la mise à disposition à un élevage.';
        }
        if ($type === 'equipment_return' && (!is_int($lotId) || $lotId < 1)) {
            $errors[] = 'Choisissez le lot d’équipement à restituer.';
        }
        if ($type === 'equipment_return' && (!is_int($flockId) || $flockId < 1)) {
            $errors[] = 'Associez la restitution à l’élevage qui avait reçu l’équipement.';
        }
        return [
            'errors' => $errors,
            'values' => [
                'product_id' => is_int($productId) ? $productId : null,
                'flock_id' => is_int($flockId) ? $flockId : null,
                'lot_id' => is_int($lotId) ? $lotId : null,
                'movement_type' => $type,
                'quantity' => $quantity,
                'occurred_on' => $date ?? '',
                'reason' => $reason,
            ],
        ];
    }

    public static function validateInventory(array $input, DateTimeImmutable $today): array
    {
        $errors = [];
        $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
        $counted = self::decimal($input['counted_quantity'] ?? null, 3);
        $date = self::date($input['counted_on'] ?? null);
        $reason = self::text($input['reason'] ?? null);
        $expiryRaw = self::text($input['expires_on'] ?? null);
        $expiry = $expiryRaw === '' ? null : self::date($expiryRaw);
        if (!is_int($productId) || $productId < 1) {
            $errors[] = 'Choisissez un produit valide.';
        }
        if ($counted === null || $counted < 0 || $counted > self::MAX_QUANTITY) {
            $errors[] = 'La quantité réellement comptée doit être positive ou nulle.';
        }
        if ($date === null || $date > $today->format('Y-m-d')) {
            $errors[] = 'La date d’inventaire est invalide ou future.';
        }
        if (mb_strlen($reason, 'UTF-8') < 3 || mb_strlen($reason, 'UTF-8') > 500) {
            $errors[] = 'Le motif d’inventaire est obligatoire (3 à 500 caractères).';
        }
        if ($expiryRaw !== '' && ($expiry === null || ($date !== null && $expiry < $date))) {
            $errors[] = 'La péremption du lot d’ajustement doit être valide et ne pas précéder l’inventaire.';
        }
        return [
            'errors' => $errors,
            'values' => [
                'product_id' => is_int($productId) ? $productId : null,
                'counted_quantity' => $counted,
                'counted_on' => $date ?? '',
                'reason' => $reason,
                'expires_on' => $expiry,
            ],
        ];
    }

    public static function saveProduct(
        PDO $pdo,
        int $userId,
        int $farmId,
        array $input,
        ?int $productId = null
    ): int {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'stock.manage');
        $validated = self::validateProduct($input);
        self::requireValid($validated);
        $values = $validated['values'];
        if ($productId !== null) {
            $pdo->beginTransaction();
            try {
                $current = $pdo->prepare(
                    'SELECT product_type FROM products
                     WHERE farm_id = :farm_id AND id = :id FOR UPDATE'
                );
                $current->execute(['farm_id' => $farmId, 'id' => $productId]);
                $currentType = $current->fetchColumn();
                if ($currentType === false) {
                    throw new RuntimeException('Le produit à modifier est introuvable.');
                }
                if ($currentType !== $values['product_type']) {
                    foreach (['stock_lots', 'stock_movements'] as $table) {
                        $history = $pdo->prepare(
                            'SELECT 1 FROM ' . $table . '
                             WHERE farm_id = :farm_id AND product_id = :product_id LIMIT 1'
                        );
                        $history->execute(['farm_id' => $farmId, 'product_id' => $productId]);
                        if ($history->fetchColumn() !== false) {
                            throw new RuntimeException(
                                'La nature du produit ne peut pas être modifiée après la création de lots ou de mouvements.'
                            );
                        }
                    }
                }
                $statement = $pdo->prepare(
                    'UPDATE products
                     SET sku = :sku, name = :name, category = :category, product_type = :product_type,
                         unit_code = :unit_code, default_unit_price = :default_unit_price,
                         minimum_stock = :minimum_stock, notes = :notes
                     WHERE farm_id = :farm_id AND id = :id'
                );
                $statement->execute($values + ['farm_id' => $farmId, 'id' => $productId]);
                if ($statement->rowCount() === 0 && !self::productExists($pdo, $farmId, $productId)) {
                    throw new RuntimeException('Le produit à modifier est introuvable.');
                }
                $pdo->commit();
            } catch (PDOException $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                    throw new RuntimeException('Cette référence produit existe déjà dans l’exploitation.');
                }
                throw $exception;
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
            return $productId;
        }
        try {
            $statement = $pdo->prepare(
                "INSERT INTO products
                    (farm_id, sku, name, category, product_type, unit_code, default_unit_price,
                     minimum_stock, notes, created_by_user_id)
                 VALUES
                    (:farm_id, :sku, :name, :category, :product_type, :unit_code, :default_unit_price,
                     :minimum_stock, :notes, :user_id)"
            );
            $statement->execute($values + ['farm_id' => $farmId, 'user_id' => $userId]);
            return (int) $pdo->lastInsertId();
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new RuntimeException('Cette référence produit existe déjà dans l’exploitation.');
            }
            throw $exception;
        }
    }

    public static function saveSupplier(
        PDO $pdo,
        int $userId,
        int $farmId,
        array $input,
        ?int $supplierId = null
    ): int {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'stock.manage');
        $validated = self::validateSupplier($input);
        self::requireValid($validated);
        if ($supplierId !== null) {
            $statement = $pdo->prepare(
                "UPDATE suppliers
                 SET name = :name, contact_name = :contact_name, phone = :phone,
                     email = :email, address = :address, notes = :notes, status = 'active'
                 WHERE farm_id = :farm_id AND id = :id"
            );
            $statement->execute($validated['values'] + ['farm_id' => $farmId, 'id' => $supplierId]);
            if ($statement->rowCount() === 0 && !self::supplierExists($pdo, $farmId, $supplierId)) {
                throw new RuntimeException('Le fournisseur à modifier est introuvable.');
            }
            return $supplierId;
        }
        $statement = $pdo->prepare(
            "INSERT INTO suppliers
                (farm_id, name, contact_name, phone, email, address, notes, created_by_user_id)
             VALUES
                (:farm_id, :name, :contact_name, :phone, :email, :address, :notes, :user_id)"
        );
        $statement->execute($validated['values'] + ['farm_id' => $farmId, 'user_id' => $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function savePurchase(
        PDO $pdo,
        int $userId,
        int $farmId,
        array $input,
        string $currencyCode,
        DateTimeImmutable $today
    ): int {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'stock.manage');
        $validated = self::validatePurchase($input, $today, $currencyCode);
        self::requireValid($validated);
        $values = $validated['values'];
        $pdo->beginTransaction();
        try {
            $supplier = $pdo->prepare(
                "SELECT id FROM suppliers
                 WHERE farm_id = :farm_id AND id = :id AND status = 'active' FOR UPDATE"
            );
            $supplier->execute(['farm_id' => $farmId, 'id' => $values['supplier_id']]);
            if ($supplier->fetchColumn() === false) {
                throw new RuntimeException('Le fournisseur est introuvable ou archivé.');
            }
            if ($values['flock_id'] !== null) {
                self::lockFlock($pdo, $farmId, $values['flock_id']);
            }

            $productIds = array_values(array_unique(array_column($values['items'], 'product_id')));
            sort($productIds, SORT_NUMERIC);
            $productMap = [];
            foreach ($productIds as $id) {
                $productMap[$id] = self::lockProduct($pdo, $farmId, $id, true);
            }
            foreach ($values['items'] as $item) {
                if ($productMap[$item['product_id']]['status'] !== 'active') {
                    throw new RuntimeException('Un produit de cet achat est archivé.');
                }
                if ($productMap[$item['product_id']]['product_type'] === 'equipment'
                    && $item['expires_on'] !== null) {
                    throw new RuntimeException('Un équipement réutilisable ne doit pas recevoir de date de péremption.');
                }
            }

            $purchase = $pdo->prepare(
                'INSERT INTO purchases
                    (farm_id, supplier_id, flock_id, purchased_on, reference, currency_code,
                     total_amount, notes, created_by_user_id)
                 VALUES
                    (:farm_id, :supplier_id, :flock_id, :purchased_on, :reference, :currency_code,
                     :total_amount, :notes, :user_id)'
            );
            $purchase->execute([
                'farm_id' => $farmId,
                'supplier_id' => $values['supplier_id'],
                'flock_id' => $values['flock_id'],
                'purchased_on' => $values['purchased_on'],
                'reference' => $values['reference'],
                'currency_code' => $currencyCode,
                'total_amount' => $values['total_amount'],
                'notes' => $values['notes'],
                'user_id' => $userId,
            ]);
            $purchaseId = (int) $pdo->lastInsertId();

            $expense = $pdo->prepare(
                "INSERT INTO expenses
                    (farm_id, flock_id, incurred_on, category, expense_type, description,
                     amount, currency_code, created_by_user_id)
                 VALUES
                    (:farm_id, :flock_id, :incurred_on, 'stock_purchase', 'stock_purchase',
                     :description, :amount, :currency_code, :user_id)"
            );
            $expense->execute([
                'farm_id' => $farmId,
                'flock_id' => $values['flock_id'],
                'incurred_on' => $values['purchased_on'],
                'description' => 'Achat fournisseur #' . $values['supplier_id']
                    . ($values['reference'] === null ? '' : ' · ' . $values['reference']),
                'amount' => $values['total_amount'],
                'currency_code' => $currencyCode,
                'user_id' => $userId,
            ]);
            $expenseId = (int) $pdo->lastInsertId();
            $pdo->prepare(
                'UPDATE purchases SET expense_id = :expense_id
                 WHERE farm_id = :farm_id AND id = :purchase_id'
            )->execute([
                'expense_id' => $expenseId,
                'farm_id' => $farmId,
                'purchase_id' => $purchaseId,
            ]);

            $itemInsert = $pdo->prepare(
                'INSERT INTO purchase_items
                    (farm_id, purchase_id, product_id, quantity, unit_price, line_total, expires_on)
                 VALUES
                    (:farm_id, :purchase_id, :product_id, :quantity, :unit_price, :line_total, :expires_on)'
            );
            $lotInsert = $pdo->prepare(
                "INSERT INTO stock_lots
                    (farm_id, product_id, lot_code, received_on, expires_on,
                     received_quantity, origin, notes, created_by_user_id)
                 VALUES
                    (:farm_id, :product_id, :lot_code, :received_on, :expires_on,
                     :quantity, 'purchase', :notes, :user_id)"
            );
            $movementInsert = $pdo->prepare(
                "INSERT INTO stock_movements
                    (farm_id, product_id, lot_id, flock_id, movement_type, quantity_delta, occurred_at,
                     expense_id, purchase_item_id, reason, created_by_user_id)
                 VALUES
                    (:farm_id, :product_id, :lot_id, :flock_id, 'receipt', :quantity, :occurred_at,
                     :expense_id, :purchase_item_id, :reason, :user_id)"
            );
            foreach ($values['items'] as $index => $item) {
                $itemInsert->execute([
                    'farm_id' => $farmId,
                    'purchase_id' => $purchaseId,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'line_total' => $item['line_total'],
                    'expires_on' => $item['expires_on'],
                ]);
                $itemId = (int) $pdo->lastInsertId();
                $lotInsert->execute([
                    'farm_id' => $farmId,
                    'product_id' => $item['product_id'],
                    'lot_code' => 'ACH-' . $purchaseId . '-' . ($index + 1),
                    'received_on' => $values['purchased_on'],
                    'expires_on' => $item['expires_on'],
                    'quantity' => $item['quantity'],
                    'notes' => $values['reference'],
                    'user_id' => $userId,
                ]);
                $lotId = (int) $pdo->lastInsertId();
                $pdo->prepare(
                    'UPDATE purchase_items SET lot_id = :lot_id
                     WHERE farm_id = :farm_id AND id = :item_id'
                )->execute(['lot_id' => $lotId, 'farm_id' => $farmId, 'item_id' => $itemId]);
                $movementInsert->execute([
                    'farm_id' => $farmId,
                    'product_id' => $item['product_id'],
                    'lot_id' => $lotId,
                    'flock_id' => $values['flock_id'],
                    'quantity' => $item['quantity'],
                    'occurred_at' => $values['purchased_on'] . ' 12:00:00',
                    'expense_id' => $expenseId,
                    'purchase_item_id' => $itemId,
                    'reason' => 'Réception achat #' . $purchaseId,
                    'user_id' => $userId,
                ]);
                $pdo->prepare(
                    'UPDATE products SET default_unit_price = :price
                     WHERE farm_id = :farm_id AND id = :product_id'
                )->execute([
                    'price' => $item['unit_price'],
                    'farm_id' => $farmId,
                    'product_id' => $item['product_id'],
                ]);
            }
            $pdo->commit();
            return $purchaseId;
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ((int) ($exception->errorInfo[1] ?? 0) === 1062) {
                throw new RuntimeException('Une référence d’achat ou de produit existe déjà.');
            }
            throw $exception;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function receiveStock(
        PDO $pdo,
        int $userId,
        int $farmId,
        array $input,
        DateTimeImmutable $today
    ): int {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'stock.manage');
        $productId = filter_var($input['product_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = self::decimal($input['quantity'] ?? null, 3);
        $date = self::date($input['received_on'] ?? null);
        $expiryRaw = self::text($input['expires_on'] ?? null);
        $expiry = $expiryRaw === '' ? null : self::date($expiryRaw);
        $lotCode = self::text($input['lot_code'] ?? null);
        $reason = self::text($input['reason'] ?? null);
        $errors = [];
        if (!is_int($productId) || $productId < 1) {
            $errors[] = 'Choisissez un produit valide.';
        }
        if ($quantity === null || $quantity <= 0 || $quantity > self::MAX_QUANTITY) {
            $errors[] = 'La quantité reçue doit être positive.';
        }
        if ($date === null || $date > $today->format('Y-m-d')) {
            $errors[] = 'La date de réception est invalide ou future.';
        }
        if ($expiryRaw !== '' && ($expiry === null || ($date !== null && $expiry < $date))) {
            $errors[] = 'La péremption doit être une date valide postérieure ou égale à la réception.';
        }
        if ($lotCode !== '' && mb_strlen($lotCode, 'UTF-8') > 100) {
            $errors[] = 'Le numéro de lot est limité à 100 caractères.';
        }
        if (mb_strlen($reason, 'UTF-8') < 3 || mb_strlen($reason, 'UTF-8') > 500) {
            $errors[] = 'Indiquez un motif de réception de 3 à 500 caractères.';
        }
        self::requireValid(['errors' => $errors]);

        $pdo->beginTransaction();
        try {
            $product = self::lockProduct($pdo, $farmId, $productId, true);
            if ($product['status'] !== 'active') {
                throw new RuntimeException('Un produit archivé ne peut pas recevoir de stock.');
            }
            if ($product['product_type'] === 'equipment' && $expiry !== null) {
                throw new RuntimeException('Un équipement réutilisable ne doit pas recevoir de date de péremption.');
            }
            $lot = self::createLot(
                $pdo,
                $farmId,
                $userId,
                $productId,
                $lotCode !== '' ? $lotCode : null,
                $date,
                $expiry,
                $quantity,
                'manual_receipt',
                $reason
            );
            self::insertMovement(
                $pdo,
                $farmId,
                $userId,
                $productId,
                $lot,
                'receipt',
                $quantity,
                $date . ' 12:00:00',
                $reason
            );
            $pdo->commit();
            return $lot;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function recordMovement(
        PDO $pdo,
        int $userId,
        int $farmId,
        array $input,
        DateTimeImmutable $today
    ): int {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'stock.manage');
        $validated = self::validateMovement($input, $today);
        self::requireValid($validated);
        $values = $validated['values'];
        $pdo->beginTransaction();
        try {
            $product = self::lockProduct($pdo, $farmId, $values['product_id'], true);
            if ($product['status'] !== 'active') {
                throw new RuntimeException('Le produit archivé ne peut pas recevoir de nouveau mouvement.');
            }

            $type = $values['movement_type'];
            if (in_array($type, ['consumption'], true)
                && $product['product_type'] !== 'consumable') {
                throw new RuntimeException('Un équipement réutilisable ne peut pas être consommé ; enregistrez sa mise à disposition.');
            }
            if (in_array($type, ['equipment_issue', 'equipment_return'], true)
                && $product['product_type'] !== 'equipment') {
                throw new RuntimeException('Cette action est réservée aux équipements réutilisables.');
            }
            if ($values['flock_id'] !== null) {
                self::lockFlock($pdo, $farmId, $values['flock_id']);
            }

            if ($type === 'equipment_return') {
                $lot = self::lot($pdo, $farmId, $values['product_id'], $values['lot_id'], true);
                $issuedQuery = $pdo->prepare(
                    "SELECT COALESCE(SUM(-quantity_delta), 0)
                     FROM stock_movements
                     WHERE farm_id = :farm_id AND product_id = :product_id
                       AND lot_id = :lot_id AND flock_id = :flock_id
                       AND movement_type = 'equipment_issue'"
                );
                $issuedQuery->execute([
                    'farm_id' => $farmId,
                    'product_id' => $values['product_id'],
                    'lot_id' => $values['lot_id'],
                    'flock_id' => $values['flock_id'],
                ]);
                $returnedQuery = $pdo->prepare(
                    "SELECT COALESCE(SUM(quantity_delta), 0)
                     FROM stock_movements
                     WHERE farm_id = :farm_id AND product_id = :product_id
                       AND lot_id = :lot_id AND flock_id = :flock_id
                       AND movement_type = 'equipment_return'"
                );
                $returnedQuery->execute([
                    'farm_id' => $farmId,
                    'product_id' => $values['product_id'],
                    'lot_id' => $values['lot_id'],
                    'flock_id' => $values['flock_id'],
                ]);
                $outstanding = (float) $issuedQuery->fetchColumn() - (float) $returnedQuery->fetchColumn();
                if ($values['quantity'] > $outstanding) {
                    throw new RuntimeException('La quantité restituée dépasse le matériel actuellement sorti de ce lot.');
                }
                self::insertMovement(
                    $pdo,
                    $farmId,
                    $userId,
                    $values['product_id'],
                    (int) $lot['id'],
                    'equipment_return',
                    $values['quantity'],
                    $values['occurred_on'] . ' 12:00:00',
                    $values['reason'],
                    $values['flock_id']
                );
                $pdo->commit();
                return 1;
            }

            $allocations = self::allocateOutflow(
                $pdo,
                $farmId,
                $values['product_id'],
                $values['quantity'],
                $values['occurred_on'],
                $type === 'loss',
                $type !== 'equipment_issue'
            );
            $movementType = $type === 'equipment_issue' ? 'equipment_issue'
                : ($type === 'loss' ? 'loss' : 'consumption');
            foreach ($allocations as [$lotId, $allocated]) {
                self::insertMovement(
                    $pdo,
                    $farmId,
                    $userId,
                    $values['product_id'],
                    $lotId,
                    $movementType,
                    -$allocated,
                    $values['occurred_on'] . ' 12:00:00',
                    $values['reason'],
                    $values['flock_id']
                );
            }
            $pdo->commit();
            return count($allocations);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function recordSaleMovement(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $productId,
        int $saleLineId,
        float $quantity,
        string $occurredAt,
        string $reason,
        ?int $flockId
    ): void {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'stock.manage');
        if (!$pdo->inTransaction()) {
            throw new RuntimeException('Une vente de stock doit être enregistrée dans sa transaction commerciale.');
        }
        if ($saleLineId < 1 || !is_finite($quantity) || $quantity <= 0) {
            throw new RuntimeException('La ligne de vente ou sa quantité est invalide.');
        }
        $product = self::lockProduct($pdo, $farmId, $productId, true);
        if ($product['status'] !== 'active') {
            throw new RuntimeException('Un produit archivé ne peut pas être vendu.');
        }
        if ($flockId !== null) {
            self::lockFlock($pdo, $farmId, $flockId);
        }
        $allocations = self::allocateOutflow(
            $pdo,
            $farmId,
            $productId,
            $quantity,
            substr($occurredAt, 0, 10),
            false
        );
        foreach ($allocations as [$lotId, $allocated]) {
            self::insertMovement(
                $pdo,
                $farmId,
                $userId,
                $productId,
                $lotId,
                'sale',
                -$allocated,
                $occurredAt,
                $reason,
                $flockId,
                null,
                null,
                null,
                $saleLineId
            );
        }
    }

    public static function recordInventory(
        PDO $pdo,
        int $userId,
        int $farmId,
        array $input,
        DateTimeImmutable $today
    ): int {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'stock.manage');
        $validated = self::validateInventory($input, $today);
        self::requireValid($validated);
        $values = $validated['values'];
        $pdo->beginTransaction();
        try {
            $product = self::lockProduct($pdo, $farmId, $values['product_id'], true);
            $expected = self::productBalance($pdo, $farmId, $values['product_id']);
            if ($expected < -0.00001) {
                throw new RuntimeException('Le stock théorique est déjà négatif ; corrigez les mouvements avant l’inventaire.');
            }
            $variance = round($values['counted_quantity'] - $expected, 3);
            $count = $pdo->prepare(
                'INSERT INTO inventory_counts
                    (farm_id, product_id, counted_on, expected_quantity,
                     counted_quantity, variance_quantity, reason, created_by_user_id)
                 VALUES
                    (:farm_id, :product_id, :counted_on, :expected, :counted, :variance, :reason, :user_id)'
            );
            $count->execute([
                'farm_id' => $farmId,
                'product_id' => $values['product_id'],
                'counted_on' => $values['counted_on'],
                'expected' => $expected,
                'counted' => $values['counted_quantity'],
                'variance' => $variance,
                'reason' => $values['reason'],
                'user_id' => $userId,
            ]);
            $inventoryId = (int) $pdo->lastInsertId();
            if ($variance > 0) {
                if ($product['product_type'] === 'equipment' && $values['expires_on'] !== null) {
                    throw new RuntimeException('Un équipement réutilisable ne doit pas recevoir de date de péremption.');
                }
                $lot = self::createLot(
                    $pdo,
                    $farmId,
                    $userId,
                    $values['product_id'],
                    'INV-' . $inventoryId,
                    $values['counted_on'],
                    $values['expires_on'],
                    $variance,
                    'adjustment',
                    'Écart d’inventaire #' . $inventoryId
                );
                self::insertMovement(
                    $pdo,
                    $farmId,
                    $userId,
                    $values['product_id'],
                    $lot,
                    'adjustment',
                    $variance,
                    $values['counted_on'] . ' 12:00:00',
                    $values['reason'],
                    null,
                    null,
                    $inventoryId
                );
            } elseif ($variance < 0) {
                $allocations = self::allocateOutflow(
                    $pdo,
                    $farmId,
                    $values['product_id'],
                    abs($variance),
                    $values['counted_on'],
                    true
                );
                foreach ($allocations as [$lotId, $allocated]) {
                    self::insertMovement(
                        $pdo,
                        $farmId,
                        $userId,
                        $values['product_id'],
                        $lotId,
                        'adjustment',
                        -$allocated,
                        $values['counted_on'] . ' 12:00:00',
                        $values['reason'],
                        null,
                        null,
                        $inventoryId
                    );
                }
            }
            $pdo->commit();
            return $inventoryId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public static function archiveProduct(PDO $pdo, int $userId, int $farmId, int $productId): void
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'stock.manage');
        $statement = $pdo->prepare(
            "UPDATE products SET status = 'archived'
             WHERE farm_id = :farm_id AND id = :id AND status = 'active'"
        );
        $statement->execute(['farm_id' => $farmId, 'id' => $productId]);
        if ($statement->rowCount() === 0 && !self::productExists($pdo, $farmId, $productId)) {
            throw new RuntimeException('Le produit à archiver est introuvable.');
        }
    }

    public static function archiveSupplier(PDO $pdo, int $userId, int $farmId, int $supplierId): void
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'stock.manage');
        $statement = $pdo->prepare(
            "UPDATE suppliers SET status = 'archived'
             WHERE farm_id = :farm_id AND id = :id AND status = 'active'"
        );
        $statement->execute(['farm_id' => $farmId, 'id' => $supplierId]);
        if ($statement->rowCount() === 0 && !self::supplierExists($pdo, $farmId, $supplierId)) {
            throw new RuntimeException('Le fournisseur à archiver est introuvable.');
        }
    }

    public static function products(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'stock.view');
        $statement = $pdo->prepare(
            'SELECT p.id, p.sku, p.name, p.category, p.product_type, p.unit_code,
                    p.default_unit_price, p.minimum_stock, p.status, p.notes,
                    COALESCE(SUM(sm.quantity_delta), 0) AS current_stock
             FROM products p
             LEFT JOIN stock_movements sm
               ON sm.farm_id = p.farm_id AND sm.product_id = p.id
             WHERE p.farm_id = :farm_id
             GROUP BY p.id, p.sku, p.name, p.category, p.product_type, p.unit_code,
                      p.default_unit_price, p.minimum_stock, p.status, p.notes
             ORDER BY p.status, p.category, p.name, p.id'
        );
        $statement->execute(['farm_id' => $farmId]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['current_stock'] = (float) $row['current_stock'];
            $row['low_stock'] = $row['current_stock'] <= (float) $row['minimum_stock'];
        }
        unset($row);
        return $rows;
    }

    public static function product(
        PDO $pdo,
        int $userId,
        int $farmId,
        int $productId
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'stock.view');
        $statement = $pdo->prepare(
            'SELECT id, sku, name, category, product_type, unit_code, default_unit_price,
                    minimum_stock, status, notes
             FROM products WHERE farm_id = :farm_id AND id = :id'
        );
        $statement->execute(['farm_id' => $farmId, 'id' => $productId]);
        $product = $statement->fetch();
        if (!is_array($product)) {
            throw new RuntimeException('Produit introuvable.');
        }
        return $product;
    }

    public static function suppliers(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'stock.view');
        $statement = $pdo->prepare(
            'SELECT s.id, s.name, s.contact_name, s.phone, s.email, s.address,
                    s.notes, s.status, COUNT(p.id) AS purchase_count
             FROM suppliers s
             LEFT JOIN purchases p ON p.farm_id = s.farm_id AND p.supplier_id = s.id
             WHERE s.farm_id = :farm_id
             GROUP BY s.id, s.name, s.contact_name, s.phone, s.email, s.address, s.notes, s.status
             ORDER BY s.status, s.name, s.id'
        );
        $statement->execute(['farm_id' => $farmId]);
        return $statement->fetchAll();
    }

    public static function activeSuppliers(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'stock.view');
        $statement = $pdo->prepare(
            "SELECT id, name FROM suppliers
             WHERE farm_id = :farm_id AND status = 'active'
             ORDER BY name, id"
        );
        $statement->execute(['farm_id' => $farmId]);
        return $statement->fetchAll();
    }

    public static function activeFlocks(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'flock.view');
        $statement = $pdo->prepare(
            "SELECT id, name, code FROM flocks
             WHERE farm_id = :farm_id AND status IN ('active', 'paused')
             ORDER BY name, id"
        );
        $statement->execute(['farm_id' => $farmId]);
        return $statement->fetchAll();
    }

    public static function lots(
        PDO $pdo,
        int $userId,
        int $farmId,
        ?int $productId = null,
        bool $includeEmpty = false
    ): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'stock.view');
        $sql = 'SELECT l.id, l.product_id, l.lot_code, l.received_on, l.expires_on,
                       l.origin, p.name AS product_name, p.unit_code,
                       COALESCE(SUM(sm.quantity_delta), 0) AS quantity_available
                FROM stock_lots l
                INNER JOIN products p ON p.farm_id = l.farm_id AND p.id = l.product_id
                LEFT JOIN stock_movements sm
                  ON sm.farm_id = l.farm_id AND sm.product_id = l.product_id AND sm.lot_id = l.id
                WHERE l.farm_id = :farm_id';
        $parameters = ['farm_id' => $farmId];
        if ($productId !== null) {
            $sql .= ' AND l.product_id = :product_id';
            $parameters['product_id'] = $productId;
        }
        $sql .= ' GROUP BY l.id, l.product_id, l.lot_code, l.received_on, l.expires_on,
                          l.origin, p.name, p.unit_code';
        if (!$includeEmpty) {
            $sql .= ' HAVING quantity_available > 0';
        }
        $sql .= ' ORDER BY l.expires_on IS NULL, l.expires_on, l.received_on, l.id';
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['quantity_available'] = (float) $row['quantity_available'];
        }
        unset($row);
        return $rows;
    }

    public static function lowStock(PDO $pdo, int $userId, int $farmId): array
    {
        return array_values(array_filter(
            self::products($pdo, $userId, $farmId),
            static fn (array $product): bool => $product['status'] === 'active' && $product['low_stock']
        ));
    }

    public static function expiryAlerts(
        PDO $pdo,
        int $userId,
        int $farmId,
        DateTimeImmutable $today
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'stock.view');
        $endDate = $today->modify('+30 days')->format('Y-m-d');
        $statement = $pdo->prepare(
            'SELECT l.id, l.product_id, l.lot_code, l.expires_on,
                    p.name AS product_name, p.unit_code,
                    COALESCE(SUM(sm.quantity_delta), 0) AS quantity_available
             FROM stock_lots l
             INNER JOIN products p ON p.farm_id = l.farm_id AND p.id = l.product_id
             LEFT JOIN stock_movements sm
               ON sm.farm_id = l.farm_id AND sm.product_id = l.product_id AND sm.lot_id = l.id
             WHERE l.farm_id = :farm_id
               AND l.expires_on IS NOT NULL AND l.expires_on <= :end_date
             GROUP BY l.id, l.product_id, l.lot_code, l.expires_on, p.name, p.unit_code
             HAVING quantity_available > 0
             ORDER BY l.expires_on, p.name, l.id'
        );
        $statement->execute(['farm_id' => $farmId, 'end_date' => $endDate]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['quantity_available'] = (float) $row['quantity_available'];
            $row['expired'] = (string) $row['expires_on'] < $today->format('Y-m-d');
        }
        unset($row);
        return $rows;
    }

    public static function purchases(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'stock.view');
        $statement = $pdo->prepare(
            'SELECT p.id, p.purchased_on, p.reference, p.total_amount, p.currency_code,
                    p.notes, s.name AS supplier_name, f.name AS flock_name,
                    COUNT(pi.id) AS item_count
             FROM purchases p
             INNER JOIN suppliers s ON s.farm_id = p.farm_id AND s.id = p.supplier_id
             LEFT JOIN flocks f ON f.farm_id = p.farm_id AND f.id = p.flock_id
             LEFT JOIN purchase_items pi ON pi.farm_id = p.farm_id AND pi.purchase_id = p.id
             WHERE p.farm_id = :farm_id
             GROUP BY p.id, p.purchased_on, p.reference, p.total_amount,
                      p.currency_code, p.notes, s.name, f.name
             ORDER BY p.purchased_on DESC, p.id DESC
             LIMIT 200'
        );
        $statement->execute(['farm_id' => $farmId]);
        return $statement->fetchAll();
    }

    public static function history(
        PDO $pdo,
        int $userId,
        int $farmId,
        ?int $productId = null,
        ?string $fromDate = null,
        ?string $toDate = null
    ): array {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'stock.view');
        $sql = 'SELECT sm.id, sm.product_id, sm.lot_id, sm.movement_type,
                       sm.quantity_delta, sm.occurred_at, sm.reason,
                       p.name AS product_name, p.unit_code, f.name AS flock_name,
                       s.name AS supplier_name, l.lot_code, l.expires_on
                FROM stock_movements sm
                INNER JOIN products p ON p.farm_id = sm.farm_id AND p.id = sm.product_id
                LEFT JOIN flocks f ON f.farm_id = sm.farm_id AND f.id = sm.flock_id
                LEFT JOIN stock_lots l ON l.farm_id = sm.farm_id AND l.id = sm.lot_id
                LEFT JOIN purchase_items pi ON pi.farm_id = sm.farm_id AND pi.id = sm.purchase_item_id
                LEFT JOIN purchases pu ON pu.farm_id = pi.farm_id AND pu.id = pi.purchase_id
                LEFT JOIN suppliers s ON s.farm_id = pu.farm_id AND s.id = pu.supplier_id
                WHERE sm.farm_id = :farm_id';
        $parameters = ['farm_id' => $farmId];
        if ($productId !== null) {
            $sql .= ' AND sm.product_id = :product_id';
            $parameters['product_id'] = $productId;
        }
        if ($fromDate !== null) {
            $sql .= ' AND sm.occurred_at >= :from_date';
            $parameters['from_date'] = $fromDate . ' 00:00:00';
        }
        if ($toDate !== null) {
            $sql .= ' AND sm.occurred_at < :to_date';
            $parameters['to_date'] = (new DateTimeImmutable($toDate))
                ->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        $sql .= ' ORDER BY sm.occurred_at DESC, sm.id DESC LIMIT 500';
        $statement = $pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll();
    }

    public static function inventories(PDO $pdo, int $userId, int $farmId): array
    {
        FarmAccess::requirePermission($pdo, $userId, $farmId, 'stock.view');
        $statement = $pdo->prepare(
            'SELECT i.id, i.product_id, i.counted_on, i.expected_quantity,
                    i.counted_quantity, i.variance_quantity, i.reason,
                    p.name AS product_name, p.unit_code
             FROM inventory_counts i
             INNER JOIN products p ON p.farm_id = i.farm_id AND p.id = i.product_id
             WHERE i.farm_id = :farm_id
             ORDER BY i.counted_on DESC, i.id DESC
             LIMIT 200'
        );
        $statement->execute(['farm_id' => $farmId]);
        return $statement->fetchAll();
    }

    private static function allocateOutflow(
        PDO $pdo,
        int $farmId,
        int $productId,
        float $required,
        string $date,
        bool $includeExpired,
        bool $allowLegacy = true
    ): array {
        $balance = self::productBalance($pdo, $farmId, $productId);
        if ($balance + 0.00001 < $required) {
            throw new RuntimeException(sprintf(
                'Stock insuffisant : %.3f disponible, %.3f demandé. Le stock ne peut pas devenir négatif.',
                $balance,
                $required
            ));
        }
        $allocations = [];
        $remaining = $required;
        if ($allowLegacy) {
            $legacyQuery = $pdo->prepare(
                'SELECT COALESCE(SUM(quantity_delta), 0)
                 FROM stock_movements
                 WHERE farm_id = :farm_id AND product_id = :product_id AND lot_id IS NULL'
            );
            $legacyQuery->execute(['farm_id' => $farmId, 'product_id' => $productId]);
            $legacy = max(0.0, (float) $legacyQuery->fetchColumn());
            if ($legacy > 0) {
                $take = min($remaining, $legacy);
                $allocations[] = [null, $take];
                $remaining -= $take;
            }
        }
        $lotSql = 'SELECT l.id, l.expires_on,
                          COALESCE(SUM(sm.quantity_delta), 0) AS available
                   FROM stock_lots l
                   LEFT JOIN stock_movements sm
                     ON sm.farm_id = l.farm_id AND sm.product_id = l.product_id AND sm.lot_id = l.id
                   WHERE l.farm_id = :farm_id AND l.product_id = :product_id
                     AND l.received_on <= :received_by
                   GROUP BY l.id, l.expires_on
                   HAVING available > 0';
        if (!$includeExpired) {
            $lotSql .= ' AND (l.expires_on IS NULL OR l.expires_on >= :expiry_date)';
        }
        $lotSql .= ' ORDER BY l.expires_on IS NULL, l.expires_on, l.received_on, l.id';
        $lots = $pdo->prepare($lotSql);
        $parameters = [
            'farm_id' => $farmId,
            'product_id' => $productId,
            'received_by' => $date,
        ];
        if (!$includeExpired) {
            $parameters['expiry_date'] = $date;
        }
        $lots->execute($parameters);
        foreach ($lots->fetchAll() as $lot) {
            if ($remaining <= 0.000001) {
                break;
            }
            $available = (float) $lot['available'];
            $take = min($remaining, $available);
            if ($take > 0) {
                $allocations[] = [(int) $lot['id'], $take];
                $remaining -= $take;
            }
        }
        if ($remaining > 0.00001) {
            throw new RuntimeException(sprintf(
                'Stock utilisable insuffisant : %.3f unité(s) sont expirées ou sans lot utilisable.',
                $remaining
            ));
        }
        return $allocations;
    }

    private static function productBalance(PDO $pdo, int $farmId, int $productId): float
    {
        $statement = $pdo->prepare(
            'SELECT COALESCE(SUM(quantity_delta), 0)
             FROM stock_movements
             WHERE farm_id = :farm_id AND product_id = :product_id'
        );
        $statement->execute(['farm_id' => $farmId, 'product_id' => $productId]);
        return (float) $statement->fetchColumn();
    }

    private static function createLot(
        PDO $pdo,
        int $farmId,
        int $userId,
        int $productId,
        ?string $lotCode,
        string $receivedOn,
        ?string $expiresOn,
        float $quantity,
        string $origin,
        string $notes
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO stock_lots
                (farm_id, product_id, lot_code, received_on, expires_on,
                 received_quantity, origin, notes, created_by_user_id)
             VALUES
                (:farm_id, :product_id, :lot_code, :received_on, :expires_on,
                 :quantity, :origin, :notes, :user_id)'
        );
        $statement->execute([
            'farm_id' => $farmId,
            'product_id' => $productId,
            'lot_code' => $lotCode,
            'received_on' => $receivedOn,
            'expires_on' => $expiresOn,
            'quantity' => $quantity,
            'origin' => $origin,
            'notes' => $notes !== '' ? mb_substr($notes, 0, 500, 'UTF-8') : null,
            'user_id' => $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private static function insertMovement(
        PDO $pdo,
        int $farmId,
        int $userId,
        int $productId,
        ?int $lotId,
        string $type,
        float $delta,
        string $occurredAt,
        string $reason,
        ?int $flockId = null,
        ?int $expenseId = null,
        ?int $inventoryId = null,
        ?int $purchaseItemId = null,
        ?int $saleLineId = null
    ): int {
        $statement = $pdo->prepare(
            'INSERT INTO stock_movements
                (farm_id, product_id, lot_id, flock_id, movement_type, quantity_delta,
                 occurred_at, expense_id, purchase_item_id, inventory_count_id, sale_line_id,
                 reason, created_by_user_id)
             VALUES
                (:farm_id, :product_id, :lot_id, :flock_id, :movement_type, :quantity_delta,
                 :occurred_at, :expense_id, :purchase_item_id, :inventory_count_id, :sale_line_id,
                 :reason, :user_id)'
        );
        $statement->execute([
            'farm_id' => $farmId,
            'product_id' => $productId,
            'lot_id' => $lotId,
            'flock_id' => $flockId,
            'movement_type' => $type,
            'quantity_delta' => $delta,
            'occurred_at' => $occurredAt,
            'expense_id' => $expenseId,
            'purchase_item_id' => $purchaseItemId,
            'inventory_count_id' => $inventoryId,
            'sale_line_id' => $saleLineId,
            'reason' => $reason,
            'user_id' => $userId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private static function lockProduct(
        PDO $pdo,
        int $farmId,
        int $productId,
        bool $lock
    ): array {
        $statement = $pdo->prepare(
            'SELECT id, product_type, status, unit_code
             FROM products WHERE farm_id = :farm_id AND id = :id' . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['farm_id' => $farmId, 'id' => $productId]);
        $product = $statement->fetch();
        if (!is_array($product)) {
            throw new RuntimeException('Produit introuvable dans cette exploitation.');
        }
        return $product;
    }

    private static function lockFlock(PDO $pdo, int $farmId, int $flockId): void
    {
        $statement = $pdo->prepare(
            "SELECT id FROM flocks
             WHERE farm_id = :farm_id AND id = :flock_id
               AND status IN ('active', 'paused')
             FOR UPDATE"
        );
        $statement->execute(['farm_id' => $farmId, 'flock_id' => $flockId]);
        if ($statement->fetchColumn() === false) {
            throw new RuntimeException('L’élevage associé est introuvable, clôturé ou inaccessible.');
        }
    }

    private static function lot(
        PDO $pdo,
        int $farmId,
        int $productId,
        int $lotId,
        bool $lock
    ): array {
        $statement = $pdo->prepare(
            'SELECT id, expires_on FROM stock_lots
             WHERE farm_id = :farm_id AND product_id = :product_id AND id = :id'
                . ($lock ? ' FOR UPDATE' : '')
        );
        $statement->execute(['farm_id' => $farmId, 'product_id' => $productId, 'id' => $lotId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('Le lot demandé est introuvable pour ce produit.');
        }
        return $row;
    }

    private static function productExists(PDO $pdo, int $farmId, int $productId): bool
    {
        $statement = $pdo->prepare('SELECT 1 FROM products WHERE farm_id = :farm_id AND id = :id');
        $statement->execute(['farm_id' => $farmId, 'id' => $productId]);
        return $statement->fetchColumn() !== false;
    }

    private static function supplierExists(PDO $pdo, int $farmId, int $supplierId): bool
    {
        $statement = $pdo->prepare('SELECT 1 FROM suppliers WHERE farm_id = :farm_id AND id = :id');
        $statement->execute(['farm_id' => $farmId, 'id' => $supplierId]);
        return $statement->fetchColumn() !== false;
    }

    private static function requireValid(array $validated): void
    {
        if ($validated['errors'] !== []) {
            throw new RuntimeException(implode(' ', $validated['errors']));
        }
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    private static function decimal(mixed $value, int $scale): ?float
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        $fraction = $scale > 0 ? '(?:\.\d{1,' . $scale . '})?' : '';
        $string = (string) $value;
        if (!preg_match('/^\d{1,12}' . $fraction . '$/D', $string)) {
            return null;
        }
        $number = (float) $string;
        return is_finite($number) ? $number : null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
