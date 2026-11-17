<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Database\Connection;
use MonElevage\Dashboard\DashboardService;
use MonElevage\Finance\FinanceService;
use MonElevage\Stock\StockService;

$pdo = Connection::get($config);
$suffix = strtoupper(bin2hex(random_bytes(4)));
$today = new DateTimeImmutable('today', new DateTimeZone('UTC'));
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
$expenseInput = static function (string $amount, string $category, string $type, int $flockId, string $description) use ($today): array {
    return [
        'amount' => $amount,
        'category' => $category,
        'expense_type' => $type,
        'description' => $description,
        'incurred_on' => $today->format('Y-m-d'),
        'flock_id' => (string) $flockId,
    ];
};

try {
    foreach (['Finance owner', 'Finance outsider', 'Finance accountant', 'Finance operator'] as $index => $name) {
        $createUser = $pdo->prepare(
            "INSERT INTO users (full_name, email, password_hash, status, email_verified_at)
             VALUES (:name, :email, 'test-hash', 'active', UTC_TIMESTAMP())"
        );
        $createUser->execute([
            'name' => $name,
            'email' => 'finance-' . strtolower($suffix) . '-' . $index . '@example.test',
        ]);
        $userIds[] = (int) $pdo->lastInsertId();
    }

    $createFarm = $pdo->prepare(
        "INSERT INTO farms (owner_user_id, name, currency_code, timezone)
         VALUES (:user_id, :name, 'XOF', 'UTC')"
    );
    $createFarm->execute(['user_id' => $userIds[0], 'name' => 'Ferme finance ' . $suffix]);
    $farmId = (int) $pdo->lastInsertId();
    $createFarm->execute(['user_id' => $userIds[1], 'name' => 'Ferme privée finance ' . $suffix]);
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
             'XOF', :started_on, 100, 'active', :user_id)"
    );
    $createFlock->execute([
        'farm_id' => $farmId,
        'code' => 'FIN-' . $suffix,
        'name' => 'Élevage financier',
        'started_on' => $today->modify('-10 days')->format('Y-m-d'),
        'user_id' => $userIds[0],
    ]);
    $flockId = (int) $pdo->lastInsertId();
    $createFlock->execute([
        'farm_id' => $farmId,
        'code' => 'FIN-OTHER-' . $suffix,
        'name' => 'Autre élevage financier',
        'started_on' => $today->modify('-10 days')->format('Y-m-d'),
        'user_id' => $userIds[0],
    ]);
    $otherFlockId = (int) $pdo->lastInsertId();

    $assert(
        FinanceService::validateExpense(
            $expenseInput('10.00', 'feed', 'charge', $flockId, 'Montant FCFA'),
            $today
        )['errors'] === [],
        'accepte une valeur décimale historique sans fraction de FCFA'
    );
    $assert(
        FinanceService::validateExpense(
            $expenseInput('10.01', 'feed', 'charge', $flockId, 'Fraction invalide'),
            $today
        )['errors'] !== [],
        'refuse une fraction de FCFA'
    );
    $roundedPurchase = StockService::validatePurchase([
        'supplier_id' => '1',
        'purchased_on' => $today->format('Y-m-d'),
        'items' => [[
            'product_id' => '1',
            'quantity' => '1',
            'unit_price' => '1.49',
        ]],
    ], $today, 'XOF');
    $assert(
        $roundedPurchase['errors'] === []
            && $roundedPurchase['values']['items'][0]['line_total'] === 1.0,
        'arrondit chaque ligne d’achat en XOF au franc entier'
    );

    $charge = FinanceService::saveExpense(
        $pdo,
        $userIds[0],
        $farmId,
        $expenseInput('200', 'feed', 'charge', $flockId, 'Consommables directs'),
        $today
    );
    FinanceService::saveExpense(
        $pdo,
        $userIds[0],
        $farmId,
        [
            ...$expenseInput('75', 'labor', 'charge', $otherFlockId, 'Dépense autre élevage'),
            'incurred_on' => $today->modify('-1 day')->format('Y-m-d'),
        ],
        $today
    );
    FinanceService::saveExpense(
        $pdo,
        $userIds[0],
        $farmId,
        $expenseInput('300', 'equipment', 'investment', $flockId, 'Matériel réutilisable'),
        $today
    );

    $feedId = StockService::saveProduct($pdo, $userIds[0], $farmId, [
        'name' => 'Aliment financier ' . $suffix,
        'sku' => 'FIN-FEED-' . $suffix,
        'category' => 'Aliment',
        'product_type' => 'consumable',
        'unit_code' => 'kg',
        'default_unit_price' => '20',
        'minimum_stock' => '0',
    ]);
    $equipmentId = StockService::saveProduct($pdo, $userIds[0], $farmId, [
        'name' => 'Mangeoire financière ' . $suffix,
        'sku' => 'FIN-EQUIP-' . $suffix,
        'category' => 'Matériel',
        'product_type' => 'equipment',
        'unit_code' => 'unit',
        'default_unit_price' => '100',
        'minimum_stock' => '0',
    ]);
    $supplierId = StockService::saveSupplier($pdo, $userIds[0], $farmId, [
        'name' => 'Fournisseur finance ' . $suffix,
    ]);
    StockService::savePurchase($pdo, $userIds[0], $farmId, [
        'supplier_id' => (string) $supplierId,
        'flock_id' => (string) $flockId,
        'purchased_on' => $today->format('Y-m-d'),
        'reference' => 'FIN-' . $suffix,
        'items' => [
            ['product_id' => (string) $feedId, 'quantity' => '10', 'unit_price' => '20'],
            ['product_id' => (string) $equipmentId, 'quantity' => '1', 'unit_price' => '100'],
        ],
    ], 'XOF', $today);
    StockService::recordMovement($pdo, $userIds[0], $farmId, [
        'product_id' => (string) $feedId,
        'flock_id' => (string) $flockId,
        'movement_type' => 'consumption',
        'quantity' => '3',
        'occurred_on' => $today->format('Y-m-d'),
        'reason' => 'Consommation de test',
    ], $today);

    $from = $today->format('Y-m-d');
    $summary = FinanceService::summary($pdo, $userIds[0], $farmId, $from, $from);
    $assert(
        $summary['cash_outflow'] === 800
            && $summary['consumed_charges'] === 260
            && $summary['reusable_investments'] === 400,
        'sépare la trésorerie (achat compté une fois), les charges consommées et les équipements'
    );
    $filteredExpenses = FinanceService::expenses(
        $pdo,
        $userIds[0],
        $farmId,
        $today->modify('-1 day')->format('Y-m-d'),
        $from,
        $flockId
    );
    $assert(
        count($filteredExpenses) === 3
            && array_reduce(
                $filteredExpenses,
                static fn (bool $allMatch, array $expense): bool =>
                    $allMatch && (int) ($expense['flock_id'] ?? 0) === $flockId,
                true
            ),
        'filtre les dépenses par période et par élevage'
    );

    $budgetInput = [
        'name' => 'Budget de test',
        'starts_on' => $from,
        'ends_on' => $from,
        'flock_id' => (string) $flockId,
        'lines' => [
            ['financial_type' => 'cash', 'category' => 'stock_purchase', 'planned_amount' => '300'],
            ['financial_type' => 'consumed', 'category' => 'feed', 'planned_amount' => '200'],
            ['financial_type' => 'consumed', 'category' => 'Aliment', 'planned_amount' => '50'],
            ['financial_type' => 'investment', 'category' => 'equipment', 'planned_amount' => '300'],
            ['financial_type' => 'investment', 'category' => 'Matériel', 'planned_amount' => '90'],
        ],
    ];
    $budgetId = FinanceService::saveBudget($pdo, $userIds[0], $farmId, $budgetInput);
    $budget = FinanceService::budgets($pdo, $userIds[0], $farmId)[0];
    $budgetActuals = [];
    foreach ($budget['lines'] as $line) {
        $budgetActuals[$line['financial_type'] . ':' . $line['category']] = $line['actual_amount'];
    }
    $assert(
        $budgetActuals['cash:stock_purchase'] === 300
            && $budgetActuals['consumed:feed'] === 200
            && $budgetActuals['consumed:Aliment'] === 60
            && $budgetActuals['investment:equipment'] === 300
            && $budgetActuals['investment:Matériel'] === 100,
        'compare les budgets aux réalisés dans la même catégorie et la même dimension'
    );
    $assert(
        $budget['totals']['consumed']['remaining'] === -10
            && $budget['totals']['investment']['remaining'] === -10,
        'signale les dépassements et calcule le restant par dimension'
    );
    $dashboard = DashboardService::load($pdo, $farmId, $today, true, $userIds[0]);
    $assert(
        $dashboard['financials']['previsional_budgets'][0]['id'] === $budgetId
            && $dashboard['financials']['previsional_budgets'][0]['totals']['consumed']['remaining'] === -10,
        'reporte les budgets prévisionnels et leurs dépassements dans le tableau de bord'
    );
    $expectFailure(
        static fn () => FinanceService::saveBudget($pdo, $userIds[0], $farmId, $budgetInput),
        'refuse des périodes de budgets actives qui se chevauchent sur le même périmètre'
    );
    $updatedBudgetInput = $budgetInput;
    $updatedBudgetInput['name'] = 'Budget modifié';
    $updatedBudgetInput['lines'][0]['planned_amount'] = '400';
    $assert(
        FinanceService::saveBudget($pdo, $userIds[0], $farmId, $updatedBudgetInput, $budgetId) === $budgetId
            && FinanceService::budget($pdo, $userIds[0], $farmId, $budgetId)['name'] === 'Budget modifié'
            && FinanceService::budget($pdo, $userIds[0], $farmId, $budgetId)['lines'][0]['planned_amount'] === '400',
        'modifie le budget et ses postes dans une transaction sans changer son identifiant'
    );
    $assert(
        FinanceService::validateBudget([
            ...$budgetInput,
            'lines' => [
                ['financial_type' => 'cash', 'category' => 'feed', 'planned_amount' => '100'],
                ['financial_type' => 'cash', 'category' => 'feed', 'planned_amount' => '100'],
            ],
        ])['errors'] !== [],
        'rejette les postes dupliqués'
    );

    $updated = FinanceService::saveExpense(
        $pdo,
        $userIds[0],
        $farmId,
        $expenseInput('250.00', 'feed', 'charge', $flockId, 'Consommables corrigés'),
        $today,
        null,
        false,
        $charge['id']
    );
    $assert($updated['id'] === $charge['id'], 'modifie une dépense et conserve son identifiant');
    $updatedSummary = FinanceService::summary($pdo, $userIds[0], $farmId, $from, $from);
    $assert(
        $updatedSummary['cash_outflow'] === 850 && $updatedSummary['consumed_charges'] === 310,
        'recalcule les totaux après modification d’une dépense'
    );

    $receiptExpense = FinanceService::saveExpense(
        $pdo,
        $userIds[0],
        $farmId,
        $expenseInput('50', 'other', 'charge', $flockId, 'Justificatif attaché'),
        $today,
        [
            'name' => 'recu.pdf',
            'key' => str_repeat('a', 64),
            'mime_type' => 'application/pdf',
        ]
    );
    $receipt = FinanceService::receipt($pdo, $userIds[0], $farmId, $receiptExpense['id']);
    $assert(
        $receipt['receipt_file_name'] === 'recu.pdf'
            && $receipt['receipt_mime_type'] === 'application/pdf',
        'associe les métadonnées de justificatif à la dépense autorisée'
    );
    $voidReceipt = FinanceService::voidExpense(
        $pdo,
        $userIds[0],
        $farmId,
        $receiptExpense['id'],
        'Doublon de saisie'
    );
    $assert(
        is_array($voidReceipt) && $voidReceipt['key'] === str_repeat('a', 64),
        'annule une dépense jointe et renvoie le fichier privé à retirer'
    );
    $expectFailure(
        static fn () => FinanceService::voidExpense(
            $pdo,
            $userIds[2],
            $farmId,
            $receiptExpense['id'],
            'Annulation non autorisée'
        ),
        'interdit au rôle comptable d’annuler une dépense'
    );
    FinanceService::voidExpense($pdo, $userIds[0], $farmId, $charge['id'], 'Correction de test');
    $expectFailure(
        static fn () => FinanceService::voidExpense($pdo, $userIds[0], $farmId, $charge['id'], 'Deuxième annulation'),
        'ne permet pas de supprimer ou annuler deux fois une écriture'
    );
    $afterVoid = FinanceService::summary($pdo, $userIds[0], $farmId, $from, $from);
    $assert(
        $afterVoid['cash_outflow'] === 600
            && $afterVoid['consumed_charges'] === 60
            && $afterVoid['reusable_investments'] === 400,
        'exclut les opérations annulées du réalisé tout en gardant leur historique'
    );

    $assert(
        count(FinanceService::expenses($pdo, $userIds[2], $farmId, $from, $from)) > 0,
        'autorise la consultation financière au rôle comptable'
    );
    $expectFailure(
        static fn () => FinanceService::saveExpense(
            $pdo,
            $userIds[2],
            $farmId,
            $expenseInput('10', 'feed', 'charge', $flockId, 'Écriture non autorisée'),
            $today
        ),
        'interdit au rôle comptable de modifier les écritures'
    );
    $expectFailure(
        static fn () => FinanceService::expenses($pdo, $userIds[3], $farmId, $from, $from),
        'interdit la consultation financière au rôle opérateur'
    );
    $expectFailure(
        static fn () => FinanceService::summary($pdo, $userIds[1], $farmId, $from, $from),
        'isole les finances de l’exploitation des utilisateurs étrangers'
    );
    $expectFailure(
        static fn () => FinanceService::saveExpense(
            $pdo,
            $userIds[0],
            $farmId,
            $expenseInput('10', 'feed', 'charge', $flockId + 999999, 'Élevage étranger'),
            $today
        ),
        'refuse une dépense associée à un élevage étranger à l’exploitation'
    );

    FinanceService::archiveBudget($pdo, $userIds[0], $farmId, $budgetId);
    $assert(
        FinanceService::budget($pdo, $userIds[0], $farmId, $budgetId)['status'] === 'archived',
        'archive le prévisionnel sans effacer ses lignes'
    );
} finally {
    if ($farmId !== null) {
        $pdo->prepare('DELETE FROM stock_movements WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
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
    if ($privateFarmId !== null) {
        $pdo->prepare('DELETE FROM farm_members WHERE farm_id = :farm_id')->execute(['farm_id' => $privateFarmId]);
        $pdo->prepare('DELETE FROM farms WHERE id = :farm_id')->execute(['farm_id' => $privateFarmId]);
    }
    foreach ($userIds as $userId) {
        $pdo->prepare('DELETE FROM users WHERE id = :user_id')->execute(['user_id' => $userId]);
    }
}

printf("OK — %d assertions du module financier.\n", $passed);
