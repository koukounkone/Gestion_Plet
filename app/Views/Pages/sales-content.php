<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$value = static function (array $values, string $key, mixed $fallback = ''): string {
    $result = $values[$key] ?? $fallback;
    return is_string($result) || is_numeric($result) ? (string) $result : '';
};
$money = static fn (int|float|string $amount): string =>
    number_format((float) $amount, 0, ',', ' ') . ' FCFA';
$deliveryLabels = [
    'pending' => 'À préparer',
    'preparing' => 'En préparation',
    'shipped' => 'Expédiée',
    'delivered' => 'Livrée',
    'cancelled' => 'Annulée',
];
$paymentLabels = [
    'cash' => 'Espèces',
    'bank_transfer' => 'Virement',
    'mobile_money' => 'Mobile money',
    'other' => 'Autre',
];
$baseUrl = 'sales.php?farm_id=' . $farmId;
$clientFormId = is_array($editingClient)
    ? (int) $editingClient['id']
    : (filter_var($_POST['client_id'] ?? null, FILTER_VALIDATE_INT) ?: null);
$saleLines = is_array($saleValues['lines'] ?? null) ? $saleValues['lines'] : [];
if ($saleLines === []) {
    $saleLines = [[
        'source_type' => 'product',
        'flock_id' => '',
        'product_id' => '',
        'quantity' => '',
        'unit_price' => '',
    ]];
}
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $error): ?><p><?= $escape((string) $error) ?></p><?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if (is_array($flash) && isset($flash['message'])): ?>
    <div class="alert alert-success" role="status"><?= $escape((string) $flash['message']) ?></div>
<?php endif; ?>

<div class="dashboard-data-note" role="note">
    Une commande est enregistrée comme vente confirmée : les quantités quittent alors le stock
    ou l’effectif. Une annulation restaure les quantités par des mouvements de contrepassation
    et rembourse automatiquement les paiements déjà reçus.
</div>

<section class="sales-summary-grid" aria-label="Résumé des ventes">
    <article class="card sales-summary-card">
        <span>Ventes confirmées</span>
        <strong><?= number_format((int) $summary['sale_count'], 0, ',', ' ') ?></strong>
        <small>Sur la période sélectionnée</small>
    </article>
    <article class="card sales-summary-card">
        <span>Recettes de vente</span>
        <strong><?= $money($summary['total_amount']) ?></strong>
        <small>Ventes annulées exclues</small>
    </article>
    <article class="card sales-summary-card">
        <span>Montants encaissés</span>
        <strong><?= $money($summary['paid_amount']) ?></strong>
        <small>Paiements nets des remboursements</small>
    </article>
    <article class="card sales-summary-card<?= (int) $summary['balance_due'] > 0 ? ' sales-summary-warning' : '' ?>">
        <span>Soldes restant dus</span>
        <strong><?= $money($summary['balance_due']) ?></strong>
        <small>Livraisons ouvertes : <?= number_format((int) $summary['open_deliveries'], 0, ',', ' ') ?></small>
    </article>
</section>

<?php if ($canManageSales): ?>
    <section class="card sales-panel" aria-labelledby="sale-form-heading">
        <div class="card-heading">
            <div>
                <h2 id="sale-form-heading">Créer une commande / vente</h2>
                <p>Les poulets sont suivis par élevage ; les œufs et autres produits avicoles sont sélectionnés dans le catalogue et débités du stock.</p>
            </div>
        </div>
        <?php if ($activeClients === []): ?>
            <p class="sales-empty">Créez d’abord un client dans la section « Clients ».</p>
        <?php elseif ($products === [] && $flocks === []): ?>
            <p class="sales-empty">Aucun produit en stock ni élevage actif avec des animaux n’est disponible pour une vente.</p>
        <?php else: ?>
            <form method="post" action="<?= $escape($baseUrl) ?>" class="sales-form" data-sale-form>
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="create_sale">
                <label class="field">
                    <span>Client</span>
                    <select name="client_id" required>
                        <option value="">Choisir un client</option>
                        <?php foreach ($activeClients as $client): ?>
                            <option value="<?= (int) $client['id'] ?>"
                                <?= $value($saleValues, 'client_id') === (string) $client['id'] ? 'selected' : '' ?>>
                                <?= $escape((string) $client['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div class="sales-lines-heading">
                    <strong>Articles vendus</strong>
                    <button class="button button-secondary button-small" type="button" data-add-sale-line>Ajouter un article</button>
                </div>
                <div class="sales-lines" data-sale-lines>
                    <?php foreach ($saleLines as $index => $line): ?>
                        <?php
                        $line = is_array($line) ? $line : [];
                        $sourceType = $value($line, 'source_type', 'product');
                        ?>
                        <div class="sales-line" data-sale-line>
                            <label class="field"><span>Type</span>
                                <select name="lines[<?= (int) $index ?>][source_type]" data-source-type required>
                                    <option value="product" <?= $sourceType === 'product' ? 'selected' : '' ?>>Produit avicole / œufs</option>
                                    <option value="animal" <?= $sourceType === 'animal' ? 'selected' : '' ?>>Poulets / animaux</option>
                                </select>
                            </label>
                            <label class="field" data-flock-field data-product-flock>
                                <span>Élevage lié</span>
                                <select name="lines[<?= (int) $index ?>][flock_id]">
                                    <option value="">Aucun / choisir l’élevage</option>
                                    <?php foreach ($flocks as $flock): ?>
                                        <option value="<?= (int) $flock['id'] ?>"
                                            <?= $value($line, 'flock_id') === (string) $flock['id'] ? 'selected' : '' ?>>
                                            <?= $escape((string) $flock['name']) ?> · <?= (int) $flock['available_count'] ?> animal(aux)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="field" data-product-field>
                                <span>Produit · stock disponible</span>
                                <select name="lines[<?= (int) $index ?>][product_id]">
                                    <option value="">Choisir un produit</option>
                                    <?php foreach ($products as $product): ?>
                                        <option value="<?= (int) $product['id'] ?>"
                                            <?= $value($line, 'product_id') === (string) $product['id'] ? 'selected' : '' ?>>
                                            <?= $escape((string) $product['name']) ?> ·
                                            <?= number_format((float) $product['current_stock'], 3, ',', ' ') ?>
                                            <?= $escape((string) $product['unit_code']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="field"><span>Quantité</span>
                                <input name="lines[<?= (int) $index ?>][quantity]" data-sale-key="quantity" type="number" min="0.001"
                                       step="0.001" max="1000000000"
                                       value="<?= $escape($value($line, 'quantity')) ?>" required>
                            </label>
                            <label class="field"><span>Prix unitaire (FCFA)</span>
                                <input name="lines[<?= (int) $index ?>][unit_price]" type="number" min="1"
                                       step="1" max="9999999999999"
                                       value="<?= $escape($value($line, 'unit_price')) ?>" required>
                            </label>
                            <button class="button button-secondary button-small sales-remove-line"
                                    type="button" data-remove-sale-line aria-label="Retirer cet article">Retirer</button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <template id="sale-line-template">
                    <div class="sales-line" data-sale-line>
                        <label class="field"><span>Type</span>
                            <select data-source-type data-sale-key="source_type" required>
                                <option value="product">Produit avicole / œufs</option>
                                <option value="animal">Poulets / animaux</option>
                            </select>
                        </label>
                        <label class="field" data-flock-field data-product-flock>
                            <span>Élevage lié</span>
                            <select data-sale-key="flock_id">
                                <option value="">Aucun / choisir l’élevage</option>
                                <?php foreach ($flocks as $flock): ?>
                                    <option value="<?= (int) $flock['id'] ?>"><?= $escape((string) $flock['name']) ?> · <?= (int) $flock['available_count'] ?> animal(aux)</option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field" data-product-field>
                            <span>Produit · stock disponible</span>
                            <select data-sale-key="product_id">
                                <option value="">Choisir un produit</option>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?= (int) $product['id'] ?>"><?= $escape((string) $product['name']) ?> · <?= number_format((float) $product['current_stock'], 3, ',', ' ') ?> <?= $escape((string) $product['unit_code']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field"><span>Quantité</span>
                            <input type="number" data-sale-key="quantity" min="0.001" step="0.001" max="1000000000" required>
                        </label>
                        <label class="field"><span>Prix unitaire (FCFA)</span>
                            <input type="number" data-sale-key="unit_price" min="1" step="1" max="9999999999999" required>
                        </label>
                        <button class="button button-secondary button-small sales-remove-line"
                                type="button" data-remove-sale-line aria-label="Retirer cet article">Retirer</button>
                    </div>
                </template>
                <label class="field sales-field-wide"><span>Notes de commande (facultatif)</span>
                    <textarea name="notes" rows="2" maxlength="1000"><?= $escape($value($saleValues, 'notes')) ?></textarea>
                </label>
                <div class="sales-field-wide">
                    <button class="button button-primary" type="submit">Enregistrer la commande et mettre à jour les quantités</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
<?php endif; ?>

<section class="card sales-panel" aria-labelledby="sales-history-heading">
    <div class="card-heading">
        <div>
            <h2 id="sales-history-heading">Commandes, ventes et paiements</h2>
            <p>Les recettes et soldes excluent les ventes annulées ; chaque paiement reste traçable.</p>
        </div>
    </div>
    <form method="get" action="sales.php" class="sales-filter-form">
        <input type="hidden" name="farm_id" value="<?= $farmId ?>">
        <label class="field"><span>Du</span>
            <input type="date" name="from" value="<?= $escape($fromDate) ?>" required>
        </label>
        <label class="field"><span>Au</span>
            <input type="date" name="to" value="<?= $escape($toDate) ?>" required>
        </label>
        <button class="button button-secondary" type="submit">Filtrer</button>
    </form>
    <?php if ($sales === []): ?>
        <p class="sales-empty">Aucune commande sur cette période.</p>
    <?php else: ?>
        <div class="table-scroll">
            <table class="data-table sales-table">
                <thead><tr><th>Date / reçu</th><th>Client</th><th>Montant</th><th>Payé</th><th>Solde</th><th>Règlement</th><th>Livraison</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($sales as $sale): ?>
                    <?php
                    $isVoided = $sale['status'] === 'void';
                    $paid = (int) $sale['paid_amount'];
                    $balance = (int) $sale['balance_due'];
                    $paymentStatus = $isVoided ? 'Annulée' : ($balance === 0 ? 'Payée' : ($paid > 0 ? 'Partiellement payée' : 'Impayée'));
                    ?>
                    <tr>
                        <td><?= $escape((new DateTimeImmutable((string) $sale['sold_at'], new DateTimeZone('UTC')))
                                ->setTimezone($farmTimezone)->format('d/m/Y H:i')) ?>
                            <small>#<?= (int) $sale['id'] ?> · <?= (int) $sale['line_count'] ?> article(s)</small>
                        </td>
                        <td><?= $escape((string) $sale['client_name_snapshot']) ?></td>
                        <td><?= $money($sale['total_amount']) ?></td>
                        <td><?= $money($sale['paid_amount']) ?></td>
                        <td><?= $money($sale['balance_due']) ?></td>
                        <td><span class="sales-status<?= $isVoided ? ' is-cancelled' : '' ?>"><?= $escape($paymentStatus) ?></span></td>
                        <td>
                            <?php if ($canManageSales && !$isVoided): ?>
                                <form method="post" action="<?= $escape($baseUrl) ?>" class="sales-inline-form">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="action" value="update_delivery">
                                    <input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
                                    <select name="delivery_status" aria-label="Statut de livraison de la vente #<?= (int) $sale['id'] ?>">
                                        <?php foreach ($deliveryLabels as $status => $label): ?>
                                            <?php if ($status === 'cancelled') { continue; } ?>
                                            <option value="<?= $escape($status) ?>" <?= $sale['delivery_status'] === $status ? 'selected' : '' ?>><?= $escape($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="button button-secondary button-small" type="submit">OK</button>
                                </form>
                            <?php else: ?>
                                <span class="sales-status"><?= $escape($deliveryLabels[$sale['delivery_status']] ?? $sale['delivery_status']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="sales-actions">
                            <a class="table-action" href="sales-receipt.php?farm_id=<?= $farmId ?>&amp;sale_id=<?= (int) $sale['id'] ?>">Reçu</a>
                            <?php if ($canManagePayments && !$isVoided && $balance > 0): ?>
                                <details class="sales-action-details">
                                    <summary>Encaisser</summary>
                                    <form method="post" action="<?= $escape($baseUrl) ?>" class="sales-payment-form">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="action" value="record_payment">
                                        <input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
                                        <label class="field"><span>Montant en FCFA</span>
                                            <input name="amount" type="number" min="1" max="<?= $balance ?>" step="1" required>
                                        </label>
                                        <label class="field"><span>Moyen</span>
                                            <select name="method" required>
                                                <?php foreach ($paymentLabels as $method => $label): ?>
                                                    <option value="<?= $escape($method) ?>"><?= $escape($label) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                        <label class="field"><span>Référence (facultatif)</span>
                                            <input name="reference" maxlength="120">
                                        </label>
                                        <button class="button button-primary button-small" type="submit">Enregistrer le paiement</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                            <?php if ($canManageSales && !$isVoided): ?>
                                <details class="sales-action-details">
                                    <summary>Annuler</summary>
                                    <form method="post" action="<?= $escape($baseUrl) ?>" class="sales-payment-form">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="action" value="void_sale">
                                        <input type="hidden" name="sale_id" value="<?= (int) $sale['id'] ?>">
                                        <label class="field"><span>Motif (obligatoire)</span>
                                            <input name="void_reason" minlength="3" maxlength="500" required>
                                        </label>
                                        <button class="button button-secondary button-small" type="submit">Confirmer l’annulation</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php if ($canManageSales): ?>
    <section class="sales-clients-grid">
        <article class="card sales-panel" aria-labelledby="client-form-heading">
            <div class="card-heading">
                <div>
                    <h2 id="client-form-heading"><?= $clientFormId === null ? 'Créer un client' : 'Modifier un client' ?></h2>
                    <p>Les clients utilisés dans une vente sont archivés, jamais supprimés.</p>
                </div>
            </div>
            <form method="post" action="<?= $escape($baseUrl) ?>" class="sales-client-form">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="save_client">
                <input type="hidden" name="client_id" value="<?= $clientFormId ?? '' ?>">
                <label class="field"><span>Nom du client</span>
                    <input name="name" maxlength="160" value="<?= $escape($value($clientValues, 'name')) ?>" required>
                </label>
                <label class="field"><span>Téléphone</span>
                    <input name="phone" maxlength="40" value="<?= $escape($value($clientValues, 'phone')) ?>">
                </label>
                <label class="field"><span>E-mail</span>
                    <input name="email" type="email" maxlength="254" value="<?= $escape($value($clientValues, 'email')) ?>">
                </label>
                <label class="field"><span>Adresse</span>
                    <input name="address" maxlength="500" value="<?= $escape($value($clientValues, 'address')) ?>">
                </label>
                <label class="field sales-field-wide"><span>Notes</span>
                    <textarea name="notes" rows="2" maxlength="5000"><?= $escape($value($clientValues, 'notes')) ?></textarea>
                </label>
                <div class="sales-field-wide sales-form-actions">
                    <?php if ($clientFormId !== null): ?><a class="button button-secondary" href="<?= $escape($baseUrl) ?>">Annuler</a><?php endif; ?>
                    <button class="button button-primary" type="submit"><?= $clientFormId === null ? 'Créer le client' : 'Enregistrer' ?></button>
                </div>
            </form>
        </article>
        <article class="card sales-panel" aria-labelledby="clients-heading">
            <div class="card-heading"><div>
                <h2 id="clients-heading">Clients</h2>
                <p><?= number_format(count($clients), 0, ',', ' ') ?> fiche(s) client</p>
            </div></div>
            <?php if ($clients === []): ?>
                <p class="sales-empty">Aucun client enregistré.</p>
            <?php else: ?>
                <ul class="sales-client-list">
                    <?php foreach ($clients as $client): ?>
                        <li>
                            <div>
                                <strong><?= $escape((string) $client['name']) ?></strong>
                                <small><?= $client['phone'] === null ? '' : $escape((string) $client['phone']) ?>
                                    <?= $client['email'] === null ? '' : ' · ' . $escape((string) $client['email']) ?></small>
                                <small><?= (int) $client['sale_count'] ?> vente(s)<?= $client['archived_at'] === null ? '' : ' · archivé' ?></small>
                            </div>
                            <?php if ($client['archived_at'] === null): ?>
                                <div class="sales-client-actions">
                                    <a class="table-action" href="<?= $escape($baseUrl . '&edit_client=' . (int) $client['id']) ?>">Modifier</a>
                                    <form method="post" action="<?= $escape($baseUrl) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="action" value="archive_client">
                                        <input type="hidden" name="client_id" value="<?= (int) $client['id'] ?>">
                                        <button class="table-action" type="submit">Archiver</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
    </section>
<?php endif; ?>
