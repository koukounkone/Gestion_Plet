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
$farmId = filter_var($_GET['farm_id'] ?? null, FILTER_VALIDATE_INT);
$saleId = filter_var($_GET['sale_id'] ?? null, FILTER_VALIDATE_INT);
if (!is_int($farmId) || $farmId < 1 || !is_int($saleId) || $saleId < 1) {
    http_response_code(404);
    exit('Reçu introuvable.');
}
try {
    $sale = SalesService::findSale($pdo, $userId, $farmId, $saleId);
} catch (RuntimeException) {
    http_response_code(404);
    exit('Reçu introuvable ou accès refusé.');
}
$farmQuery = $pdo->prepare(
    'SELECT f.name, f.currency_code, f.timezone, u.full_name AS owner_name
     FROM farms f INNER JOIN users u ON u.id = f.owner_user_id WHERE f.id = :farm_id'
);
$farmQuery->execute(['farm_id' => $farmId]);
$farm = $farmQuery->fetch();
if (!is_array($farm)) {
    http_response_code(404);
    exit('Exploitation introuvable.');
}
$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$currency = (string) $sale['currency_code'];
$formatAmount = static fn (int|float|string $amount): string =>
    number_format((float) $amount, 0, ',', ' ') . ' ' . $currency;
$deliveryLabels = [
    'pending' => 'À préparer',
    'preparing' => 'En préparation',
    'shipped' => 'Expédiée',
    'delivered' => 'Livrée',
    'cancelled' => 'Annulée',
];
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; base-uri 'self'; frame-ancestors 'self'");
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reçu vente #<?= (int) $sale['id'] ?> — <?= $escape((string) $farm['name']) ?></title>
    <link rel="stylesheet" href="assets/css/app.css">
    <script src="assets/js/print.js" defer></script>
</head>
<body class="receipt-page">
<main class="receipt-sheet">
    <div class="receipt-actions">
        <a class="button button-secondary" href="sales.php?farm_id=<?= $farmId ?>">Retour aux ventes</a>
        <button class="button button-primary" type="button" data-print>Imprimer / enregistrer en PDF</button>
    </div>
    <header class="receipt-heading">
        <div>
            <p class="eyebrow">REÇU DE VENTE</p>
            <h1><?= $escape((string) $farm['name']) ?></h1>
            <p><?= $escape((string) $farm['owner_name']) ?></p>
        </div>
        <div class="receipt-number">
            <strong>Reçu n° <?= (int) $sale['id'] ?></strong>
            <span>Date : <?= $escape((new DateTimeImmutable((string) $sale['sold_at'], new DateTimeZone('UTC')))
                    ->setTimezone(new DateTimeZone((string) $farm['timezone']))->format('d/m/Y H:i')) ?></span>
            <span>Livraison : <?= $escape($deliveryLabels[$sale['delivery_status']] ?? $sale['delivery_status']) ?></span>
        </div>
    </header>
    <?php if ($sale['status'] === 'void'): ?>
        <div class="receipt-cancelled">VENTE ANNULÉE — <?= $escape((string) $sale['void_reason']) ?></div>
    <?php endif; ?>
    <section class="receipt-customer">
        <strong>Client</strong>
        <span><?= $escape((string) $sale['client_name_snapshot']) ?></span>
        <?php if ($sale['client_phone'] !== null): ?><span><?= $escape((string) $sale['client_phone']) ?></span><?php endif; ?>
        <?php if ($sale['client_email'] !== null): ?><span><?= $escape((string) $sale['client_email']) ?></span><?php endif; ?>
        <?php if ($sale['client_address'] !== null): ?><span><?= $escape((string) $sale['client_address']) ?></span><?php endif; ?>
    </section>
    <div class="table-scroll">
        <table class="data-table receipt-table">
            <thead><tr><th>Article / élevage</th><th>Quantité</th><th>Prix unitaire</th><th>Total</th></tr></thead>
            <tbody>
            <?php foreach ($sale['lines'] as $line): ?>
                <?php $label = $line['source_type'] === 'animal'
                    ? (string) ($line['flock_name'] ?? $line['description'])
                    : (string) ($line['product_name'] ?? $line['description']); ?>
                <tr>
                    <td><?= $escape($label) ?><?= $line['source_type'] === 'product'
                        ? ' · ' . $escape((string) $line['unit_code'])
                        : ' · animaux' ?></td>
                    <td><?= number_format((float) $line['quantity'], 3, ',', ' ') ?></td>
                    <td><?= $formatAmount($line['unit_price']) ?></td>
                    <td><?= $formatAmount($line['line_total']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <dl class="receipt-totals">
        <div><dt>Montant total</dt><dd><?= $formatAmount($sale['total_amount']) ?></dd></div>
        <div><dt>Montant payé</dt><dd><?= $formatAmount($sale['paid_amount']) ?></dd></div>
        <div><dt>Solde restant</dt><dd><?= $formatAmount($sale['balance_due']) ?></dd></div>
    </dl>
    <?php if ($sale['payments'] !== []): ?>
        <section class="receipt-payments">
            <h2>Paiements enregistrés</h2>
            <?php foreach ($sale['payments'] as $payment): ?>
                <p><?= $escape($payment['direction'] === 'received' ? 'Reçu' : 'Remboursé') ?>
                    le <?= $escape((new DateTimeImmutable((string) $payment['paid_at'], new DateTimeZone('UTC')))
                        ->setTimezone(new DateTimeZone((string) $farm['timezone']))->format('d/m/Y H:i')) ?>
                    · <?= $formatAmount($payment['amount']) ?> · <?= $escape((string) $payment['method']) ?>
                    <?= $payment['reference'] === null ? '' : ' · ' . $escape((string) $payment['reference']) ?></p>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
    <?php if ($sale['notes'] !== null && $sale['notes'] !== ''): ?>
        <p class="receipt-notes"><strong>Notes :</strong> <?= $escape((string) $sale['notes']) ?></p>
    <?php endif; ?>
    <footer class="receipt-footer">Merci pour votre achat · <?= $escape((string) $farm['name']) ?></footer>
</main>
</body>
</html>
