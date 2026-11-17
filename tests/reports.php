<?php
declare(strict_types=1);

require dirname(__DIR__) . DIRECTORY_SEPARATOR . 'app'
    . DIRECTORY_SEPARATOR . 'bootstrap.php';

use MonElevage\Database\Connection;
use MonElevage\Finance\FinanceService;
use MonElevage\Reports\ReportPdfRenderer;
use MonElevage\Reports\ReportService;
use MonElevage\Sales\SalesService;
use MonElevage\Stock\StockService;

$pdo = Connection::get($config);
$suffix = strtoupper(bin2hex(random_bytes(4)));
$now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$today = $now->setTime(0, 0);
$from = $today->modify('-3 days')->format('Y-m-d');
$to = $today->format('Y-m-d');
$farmId = null;
$userId = null;
$readerId = null;
$flockId = null;
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
         VALUES (:name, :email, 'test-hash', 'active', UTC_TIMESTAMP())"
    );
    $createUser->execute([
        'name' => 'Propriétaire rapports',
        'email' => 'report-' . strtolower($suffix) . '@example.test',
    ]);
    $userId = (int) $pdo->lastInsertId();
    $createFarm = $pdo->prepare(
        "INSERT INTO farms (owner_user_id, name, currency_code, timezone)
         VALUES (:user_id, :name, 'XOF', 'UTC')"
    );
    $createFarm->execute(['user_id' => $userId, 'name' => 'Ferme des Œillets ' . $suffix]);
    $farmId = (int) $pdo->lastInsertId();
    $membership = $pdo->prepare(
        "INSERT INTO farm_members (farm_id, user_id, role, status)
         VALUES (:farm_id, :user_id, :role, 'active')"
    );
    $membership->execute(['farm_id' => $farmId, 'user_id' => $userId, 'role' => 'owner']);

    $createUser->execute([
        'name' => 'Lecteur rapports',
        'email' => 'report-reader-' . strtolower($suffix) . '@example.test',
    ]);
    $readerId = (int) $pdo->lastInsertId();
    $membership->execute(['farm_id' => $farmId, 'user_id' => $readerId, 'role' => 'accountant']);

    $createFlock = $pdo->prepare(
        "INSERT INTO flocks
            (farm_id, code, name, flock_type, poultry_type, production_type,
             currency_code, started_on, initial_count, status, created_by_user_id)
         VALUES
            (:farm_id, :code, 'Élevage Côte d’Ivoire', 'broiler', 'chicken', 'meat',
             'XOF', :started_on, 20, 'active', :user_id)"
    );
    $createFlock->execute([
        'farm_id' => $farmId,
        'code' => 'REPORT-' . $suffix,
        'started_on' => $today->modify('-10 days')->format('Y-m-d'),
        'user_id' => $userId,
    ]);
    $flockId = (int) $pdo->lastInsertId();

    $followup = $pdo->prepare(
        'INSERT INTO daily_followups
            (farm_id, flock_id, followup_date, observed_count, average_weight_g,
             feed_consumed_kg, created_by_user_id)
         VALUES (:farm_id, :flock_id, :followup_date, :count, :weight, :feed, :user_id)'
    );
    $followup->execute([
        'farm_id' => $farmId,
        'flock_id' => $flockId,
        'followup_date' => $from,
        'count' => 20,
        'weight' => '500.00',
        'feed' => '2.000',
        'user_id' => $userId,
    ]);
    $followup->execute([
        'farm_id' => $farmId,
        'flock_id' => $flockId,
        'followup_date' => $to,
        'count' => 17,
        'weight' => '620.00',
        'feed' => '3.000',
        'user_id' => $userId,
    ]);

    $pdo->prepare(
        "INSERT INTO animal_movements
            (farm_id, flock_id, movement_type, quantity_delta, occurred_at, reason, created_by_user_id)
         VALUES (:farm_id, :flock_id, 'mortality', -1, :occurred_at, 'Mortalité documentée', :user_id)"
    )->execute([
        'farm_id' => $farmId,
        'flock_id' => $flockId,
        'occurred_at' => $today->modify('-2 days')->format('Y-m-d 12:00:00'),
        'user_id' => $userId,
    ]);
    $pdo->prepare(
        "INSERT INTO health_records
            (farm_id, flock_id, event_type, observed_on, affected_count, symptoms,
             details, created_by_user_id)
         VALUES (:farm_id, :flock_id, 'symptom', :observed_on, 2,
                 'Baisse d’appétit', 'Observation clinique consignée.', :user_id)"
    )->execute([
        'farm_id' => $farmId,
        'flock_id' => $flockId,
        'observed_on' => $to,
        'user_id' => $userId,
    ]);

    FinanceService::saveExpense($pdo, $userId, $farmId, [
        'incurred_on' => $to,
        'amount' => '300',
        'category' => 'feed',
        'expense_type' => 'charge',
        'description' => 'Dépense directe de test',
        'flock_id' => (string) $flockId,
    ], $today);
    $feedId = StockService::saveProduct($pdo, $userId, $farmId, [
        'name' => 'Aliment croissance ' . $suffix,
        'sku' => 'RPT-FEED-' . $suffix,
        'category' => 'feed',
        'product_type' => 'consumable',
        'unit_code' => 'kg',
        'default_unit_price' => '100',
        'minimum_stock' => '0',
    ]);
    $equipmentId = StockService::saveProduct($pdo, $userId, $farmId, [
        'name' => 'Abreuvoir réutilisable ' . $suffix,
        'sku' => 'RPT-EQUIP-' . $suffix,
        'category' => 'equipment',
        'product_type' => 'equipment',
        'unit_code' => 'unit',
        'default_unit_price' => '700',
        'minimum_stock' => '0',
    ]);
    $supplierId = StockService::saveSupplier($pdo, $userId, $farmId, [
        'name' => 'Fournisseur rapports ' . $suffix,
    ]);
    StockService::savePurchase($pdo, $userId, $farmId, [
        'supplier_id' => (string) $supplierId,
        'flock_id' => (string) $flockId,
        'purchased_on' => $from,
        'reference' => 'RPT-' . $suffix,
        'items' => [
            ['product_id' => (string) $feedId, 'quantity' => '5', 'unit_price' => '100'],
            ['product_id' => (string) $equipmentId, 'quantity' => '1', 'unit_price' => '700'],
        ],
    ], 'XOF', $today);

    $clientId = SalesService::saveClient($pdo, $userId, $farmId, ['name' => 'Client à crédit']);
    $saleId = SalesService::createSale($pdo, $userId, $farmId, [
        'client_id' => (string) $clientId,
        'sold_on' => $to,
        'lines' => [[
            'source_type' => 'animal',
            'flock_id' => (string) $flockId,
            'quantity' => '2',
            'unit_price' => '1000',
        ]],
    ], 'XOF', $now);
    SalesService::recordPayment($pdo, $userId, $farmId, $saleId, [
        'amount' => '600',
        'method' => 'cash',
        'reference' => 'REPORT-ACOMPTE',
    ], $now);

    $preview = ReportService::previewData($pdo, $userId, $farmId, $flockId, $from, $to);
    $assert(
        $preview['headcount']['initial'] === 20
            && $preview['headcount']['final'] === 17
            && $preview['headcount']['mortality'] === 1,
        'calcule les effectifs de début et de fin ainsi que les mortalités sans recompter les ventes'
    );
    $assert(
        count($preview['growth']['weights']) === 2
            && $preview['growth']['change_g'] === 120.0
            && $preview['feed']['daily_total_kg'] === 5.0,
        'reprend les mesures de croissance et les quantités d’aliments consignées'
    );
    $assert(
        count($preview['health']['records']) === 1
            && count($preview['purchases']) === 2
            && array_sum(array_map(
                static fn (array $purchase): int => (int) $purchase['line_total'],
                $preview['purchases']
            )) === 1200
            && $preview['finance']['expenses_total'] === 1500
            && count($preview['expenses']) === 1
            && (int) $preview['expenses'][0]['amount'] === 300,
        'inclut le suivi sanitaire, les achats et les dépenses de la période'
    );
    $assert(
        $preview['finance']['revenue_total'] === 2000
            && $preview['finance']['cash_collected'] === 600
            && $preview['finance']['receivables'] === 1400
            && $preview['finance']['reusable_investments'] === 700
            && $preview['finance']['estimated_profit'] === 1700,
        'calcule ventes payées partiellement, créances, investissement et résultat sans double compte'
    );
    $html = ReportPdfRenderer::html(array_merge($preview, [
        'observation' => 'Observations personnalisées : récolte réussie.',
        'generated_at' => $now->format('Y-m-d H:i:s'),
        'version_number' => 1,
    ]));
    $assert(
        str_contains($html, 'récolte réussie')
            && str_contains($html, 'Croissance et alimentation')
            && str_contains($html, '1 700 FCFA')
            && str_contains($html, 'Baisse d’appétit'),
        'conserve les accents et affiche les valeurs attendues dans le document UTF-8'
    );

    $first = ReportService::generate(
        $pdo,
        $userId,
        $farmId,
        $flockId,
        $from,
        $to,
        'Observations personnalisées : récolte réussie.',
        $now
    );
    $firstFile = ReportService::downloadableFile($pdo, $userId, $farmId, $first['id']);
    $pdfBytes = file_get_contents($firstFile['path']);
    $pageCount = is_string($pdfBytes)
        ? preg_match_all('/\/Type\s*\/Page\b/', $pdfBytes)
        : false;
    $assert(
        $first['version_number'] === 1
            && $first['file_size_bytes'] > 1000
            && is_string($pdfBytes)
            && str_starts_with($pdfBytes, '%PDF-')
            && str_contains($pdfBytes, '%%EOF')
            && str_contains($pdfBytes, '/ToUnicode')
            && is_int($pageCount)
            && $pageCount >= 2,
        'génère un véritable PDF téléchargeable de plusieurs pages'
    );
    $second = ReportService::generate(
        $pdo,
        $userId,
        $farmId,
        $flockId,
        $from,
        $to,
        'Nouvelle version.',
        $now->modify('+1 second')
    );
    $assert(
        $second['version_number'] === 2
            && count(ReportService::listReports($pdo, $userId, $farmId, $flockId, $from, $to)) === 2,
        'conserve les versions précédentes et filtre l’historique par élevage et période'
    );
    $assert(
        ReportService::listReports($pdo, $readerId, $farmId) === [],
        'ne montre pas dans l’historique les rapports privés générés par un autre utilisateur'
    );
    $blocked = false;
    try {
        ReportService::downloadableFile($pdo, $readerId, $farmId, $first['id']);
    } catch (RuntimeException) {
        $blocked = true;
    }
    $assert($blocked, 'refuse le téléchargement du rapport privé d’un autre membre de la même exploitation');
    $readerPreview = ReportService::previewData($pdo, $readerId, $farmId, $flockId, $from, $to);
    $assert(
        !$readerPreview['health']['available'] && $readerPreview['health']['records'] === [],
        'ne divulgue pas les dossiers sanitaires aux lecteurs sans permission de santé'
    );
    $invalidPeriod = false;
    try {
        ReportService::previewData($pdo, $userId, $farmId, $flockId, '2026-02-31', $to);
    } catch (RuntimeException) {
        $invalidPeriod = true;
    }
    $assert($invalidPeriod, 'rejette les périodes invalides avant la génération');
} finally {
    if ($farmId !== null) {
        $keyQuery = $pdo->prepare('SELECT storage_key FROM farm_reports WHERE farm_id = :farm_id');
        $keyQuery->execute(['farm_id' => $farmId]);
        foreach ($keyQuery->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $path = MON_ELEVAGE_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR
                . 'reports' . DIRECTORY_SEPARATOR . $key . '.pdf';
            if (is_file($path)) {
                unlink($path);
            }
        }
        $pdo->prepare('DELETE FROM farm_reports WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM stock_movements WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM animal_movements WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM sale_payments WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM sale_lines WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM sales WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM clients WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM health_records WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM daily_followups WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
        $pdo->prepare('DELETE FROM flock_weighings WHERE farm_id = :farm_id')->execute(['farm_id' => $farmId]);
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
    if ($readerId !== null) {
        $pdo->prepare('DELETE FROM users WHERE id = :user_id')->execute(['user_id' => $readerId]);
    }
    if ($userId !== null) {
        $pdo->prepare('DELETE FROM users WHERE id = :user_id')->execute(['user_id' => $userId]);
    }
}

printf("OK — %d assertions du générateur de rapports PDF.\n", $passed);
