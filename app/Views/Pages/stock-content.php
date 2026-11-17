<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$baseUrl = 'stock.php?farm_id=' . $farmId;
$value = static function (array $values, string $key, mixed $fallback = ''): string {
    $result = $values[$key] ?? $fallback;
    return is_string($result) || is_numeric($result) ? (string) $result : '';
};
$number = static fn (float|int|string $amount, int $digits = 3): string =>
    number_format((float) $amount, $digits, ',', ' ');
$movementNames = [
    'opening' => 'Stock initial',
    'receipt' => 'Réception',
    'return' => 'Retour',
    'consumption' => 'Consommation',
    'sale' => 'Vente',
    'loss' => 'Perte',
    'adjustment' => 'Ajustement d’inventaire',
    'equipment_issue' => 'Équipement sorti',
    'equipment_return' => 'Équipement restitué',
];
$productEditId = is_array($editProduct) ? (int) $editProduct['id'] : null;
$supplierEditId = is_array($editSupplier) ? (int) $editSupplier['id'] : null;
$items = is_array($purchaseValues['items'] ?? null) ? $purchaseValues['items'] : [];
if ($items === []) {
    $items = [['product_id' => '', 'quantity' => '', 'unit_price' => '', 'expires_on' => '']];
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

<div class="stock-summary-grid" aria-label="Alertes de stock">
    <article class="card stock-summary-card">
        <span>Produits actifs</span>
        <strong><?= number_format(count(array_filter($products, static fn (array $p): bool => $p['status'] === 'active')), 0, ',', ' ') ?></strong>
        <small>Consommables et équipements suivis séparément</small>
    </article>
    <article class="card stock-summary-card<?= $lowStock === [] ? '' : ' stock-alert-card' ?>">
        <span>Stocks bas</span>
        <strong><?= number_format(count($lowStock), 0, ',', ' ') ?></strong>
        <small><?= $lowStock === [] ? 'Aucun seuil atteint' : 'Au seuil minimum ou en dessous' ?></small>
    </article>
    <article class="card stock-summary-card<?= $expiryAlerts === [] ? '' : ' stock-alert-card' ?>">
        <span>Lots à surveiller</span>
        <strong><?= number_format(count($expiryAlerts), 0, ',', ' ') ?></strong>
        <small>Expirés ou à expiration dans les 30 jours</small>
    </article>
</div>

<?php if ($lowStock !== [] || $expiryAlerts !== []): ?>
    <section class="stock-alert-list" aria-label="Alertes détaillées">
        <?php foreach (array_slice($lowStock, 0, 5) as $product): ?>
            <div class="alert alert-warning" role="status">
                <strong>Stock bas — <?= $escape((string) $product['name']) ?> :</strong>
                <?= $number($product['current_stock']) ?> <?= $escape((string) $product['unit_code']) ?>
                (seuil <?= $number($product['minimum_stock']) ?>).
            </div>
        <?php endforeach; ?>
        <?php foreach (array_slice($expiryAlerts, 0, 5) as $lot): ?>
            <div class="<?= $lot['expired'] ? 'alert alert-error' : 'alert alert-warning' ?>" role="status">
                <strong><?= $lot['expired'] ? 'Lot expiré' : 'Péremption proche' ?> — <?= $escape((string) $lot['product_name']) ?><?= $lot['lot_code'] === null ? '' : ' · lot ' . $escape((string) $lot['lot_code']) ?> :</strong>
                <?= $number($lot['quantity_available']) ?> <?= $escape((string) $lot['unit_code']) ?>,
                date <?= $escape((string) $lot['expires_on']) ?>.
            </div>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<nav class="stock-tabs" aria-label="Sections des produits et stocks">
    <?php foreach ([
        'catalog' => 'Catalogue et lots',
        'suppliers' => 'Fournisseurs',
        'purchases' => 'Achats',
        'movements' => 'Mouvements',
        'inventory' => 'Inventaires',
    ] as $key => $label): ?>
        <a class="button <?= $tab === $key ? 'button-primary' : 'button-secondary' ?>"
           href="<?= $escape($baseUrl . '&tab=' . $key) ?>"
           <?= $tab === $key ? 'aria-current="page"' : '' ?>><?= $escape($label) ?></a>
    <?php endforeach; ?>
</nav>

<?php if ($tab === 'catalog'): ?>
    <?php if ($canWrite): ?>
        <section class="card stock-form-card">
            <div class="card-heading"><div>
                <h2><?= $productEditId === null ? 'Créer un produit' : 'Modifier le produit' ?></h2>
                <p>Les produits sont archivés plutôt que supprimés afin de conserver leur historique.</p>
            </div></div>
            <form method="post" action="<?= $escape($baseUrl . '&tab=catalog') ?>" class="stock-form stock-product-form">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="save_product">
                <input type="hidden" name="product_id" value="<?= $productEditId ?? '' ?>">
                <label class="field"><span>Nom</span>
                    <input type="text" name="name" maxlength="160" required value="<?= $escape($value($productValues, 'name')) ?>">
                </label>
                <label class="field"><span>Référence / SKU (facultatif)</span>
                    <input type="text" name="sku" maxlength="64" value="<?= $escape($value($productValues, 'sku')) ?>">
                </label>
                <label class="field"><span>Catégorie</span>
                    <input type="text" name="category" maxlength="60" list="product-categories" placeholder="Aliment, santé, nettoyage, matériel…" required value="<?= $escape($value($productValues, 'category')) ?>">
                    <datalist id="product-categories">
                        <?php foreach ($productCategoryLabels as $categoryLabel): ?>
                            <option value="<?= $escape((string) $categoryLabel) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </label>
                <label class="field"><span>Nature</span>
                    <select name="product_type" required>
                        <option value="consumable" <?= $value($productValues, 'product_type') === 'consumable' ? 'selected' : '' ?>>Consommable</option>
                        <option value="equipment" <?= $value($productValues, 'product_type') === 'equipment' ? 'selected' : '' ?>>Équipement réutilisable</option>
                    </select>
                </label>
                <label class="field"><span>Unité</span>
                    <input type="text" name="unit_code" maxlength="20" placeholder="kg, L, sac, unité…" required value="<?= $escape($value($productValues, 'unit_code')) ?>">
                </label>
                <label class="field"><span>Prix unitaire de référence (<?= $escape((string) $selectedFarm['currency_code']) ?>)</span>
                    <input type="number" name="default_unit_price" min="0" max="1000000000" step="0.0001" required value="<?= $escape($value($productValues, 'default_unit_price', 0)) ?>">
                </label>
                <label class="field"><span>Seuil minimum d’alerte</span>
                    <input type="number" name="minimum_stock" min="0" max="1000000000" step="0.001" required value="<?= $escape($value($productValues, 'minimum_stock', 0)) ?>">
                </label>
                <label class="field stock-field-wide"><span>Notes (facultatif)</span>
                    <textarea name="notes" rows="2" maxlength="4000"><?= $escape($value($productValues, 'notes')) ?></textarea>
                </label>
                <div class="stock-form-actions stock-field-wide">
                    <button class="button button-primary" type="submit"><?= $productEditId === null ? 'Créer le produit' : 'Enregistrer les modifications' ?></button>
                    <?php if ($productEditId !== null): ?><a class="button button-secondary" href="<?= $escape($baseUrl . '&tab=catalog') ?>">Annuler</a><?php endif; ?>
                </div>
            </form>
        </section>

        <section class="card stock-form-card">
            <div class="card-heading"><div>
                <h2>Réception manuelle ou stock initial</h2>
                <p>Pour les achats avec fournisseur et facture, utilisez plutôt le formulaire Achats.</p>
            </div></div>
            <form method="post" action="<?= $escape($baseUrl . '&tab=catalog') ?>" class="stock-form">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="receive_stock">
                <label class="field"><span>Produit</span><select name="product_id" required data-receipt-product>
                    <option value="">Choisir un produit actif</option>
                    <?php foreach ($products as $product): if ($product['status'] !== 'active') { continue; } ?>
                        <option value="<?= (int) $product['id'] ?>"><?= $escape((string) $product['name']) ?> · <?= $escape((string) $product['unit_code']) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label class="field"><span>Quantité reçue</span><input type="number" name="quantity" min="0.001" max="1000000000" step="0.001" required></label>
                <label class="field"><span>Date de réception</span><input type="date" name="received_on" max="<?= $today->format('Y-m-d') ?>" required value="<?= $today->format('Y-m-d') ?>"></label>
                <label class="field"><span>Date de péremption (consommable seulement)</span><input type="date" name="expires_on" min="<?= $today->format('Y-m-d') ?>" data-receipt-expiry></label>
                <label class="field"><span>Numéro de lot (facultatif)</span><input type="text" name="lot_code" maxlength="100"></label>
                <label class="field"><span>Justification</span><input type="text" name="reason" maxlength="500" minlength="3" required placeholder="Stock de départ, retour fournisseur…"></label>
                <div class="stock-form-actions stock-field-wide"><button class="button button-primary" type="submit">Enregistrer la réception</button></div>
            </form>
        </section>
    <?php endif; ?>

    <section class="card table-card stock-table-card">
        <div class="card-heading"><div><h2>Catalogue et stock actuel</h2><p>Stock calculé par somme du registre des mouvements ; prix exprimés en <?= $escape((string) $selectedFarm['currency_code']) ?>.</p></div></div>
        <?php if ($products === []): ?>
            <div class="dashboard-empty stock-empty"><h3>Aucun produit</h3><p>Ajoutez un produit pour enregistrer une réception ou un achat.</p></div>
        <?php else: ?>
            <div class="table-scroll" tabindex="0" role="region" aria-label="Catalogue des produits">
                <table class="data-table stock-table">
                    <thead><tr><th>Produit</th><th>Catégorie</th><th>Nature</th><th>Unité</th><th>Prix de référence</th><th>Stock</th><th>Seuil</th><th>État</th><th>Actions</th></tr></thead>
                    <tbody><?php foreach ($products as $product): ?>
                        <tr>
                            <td><strong><?= $escape((string) $product['name']) ?></strong><small><?= $product['sku'] === null ? 'Sans référence' : $escape((string) $product['sku']) ?></small></td>
                            <td><?= $escape((string) $product['category']) ?></td>
                            <td><?= $product['product_type'] === 'equipment' ? 'Réutilisable' : 'Consommable' ?></td>
                            <td><?= $escape((string) $product['unit_code']) ?></td>
                            <td><?= $number($product['default_unit_price'], 4) ?> <?= $escape((string) $selectedFarm['currency_code']) ?></td>
                            <td><strong><?= $number($product['current_stock']) ?></strong> <?= $escape((string) $product['unit_code']) ?></td>
                            <td><?= $number($product['minimum_stock']) ?> <?= $escape((string) $product['unit_code']) ?></td>
                            <td><?= $product['status'] !== 'active'
                                ? '<span class="stock-status stock-status-muted">Archivé</span>'
                                : ($product['low_stock']
                                    ? '<span class="stock-status stock-status-warning">Stock bas</span>'
                                    : '<span class="stock-status stock-status-ok">Disponible</span>') ?></td>
                            <td class="stock-action-cell">
                                <?php if ($canWrite): ?>
                                    <a class="table-action" href="<?= $escape($baseUrl . '&tab=catalog&edit_product=' . (int) $product['id']) ?>">Modifier</a>
                                    <?php if ($product['status'] === 'active'): ?>
                                        <form method="post" action="<?= $escape($baseUrl . '&tab=catalog') ?>" data-confirm="Archiver ce produit ? Son historique et ses lots seront conservés.">
                                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                            <input type="hidden" name="action" value="archive_product">
                                            <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                                            <button class="table-action" type="submit">Archiver</button>
                                        </form>
                                    <?php endif; ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card table-card stock-table-card">
        <div class="card-heading"><div><h2>Lots en stock</h2><p>Les consommations de consommables sont prélevées d’abord sur les lots dont la péremption est la plus proche.</p></div></div>
        <?php if ($lots === []): ?>
            <div class="performance-empty-state">Aucun lot disponible. Les produits sans mouvement restent à zéro.</div>
        <?php else: ?>
            <div class="table-scroll" tabindex="0" role="region" aria-label="Lots actuellement en stock">
                <table class="data-table stock-table">
                    <thead><tr><th>Produit</th><th>Lot</th><th>Origine</th><th>Reçu le</th><th>Expire le</th><th>Disponible</th></tr></thead>
                    <tbody><?php foreach ($lots as $lot): ?>
                        <tr><td><?= $escape((string) $lot['product_name']) ?></td>
                            <td><?= $lot['lot_code'] === null ? 'Sans numéro' : $escape((string) $lot['lot_code']) ?></td>
                            <td><?= $escape((string) $lot['origin']) ?></td>
                            <td><?= $escape((string) $lot['received_on']) ?></td>
                            <td><?= $lot['expires_on'] === null ? 'Non renseignée' : $escape((string) $lot['expires_on']) ?></td>
                            <td><strong><?= $number($lot['quantity_available']) ?></strong> <?= $escape((string) $lot['unit_code']) ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php elseif ($tab === 'suppliers'): ?>
    <?php if ($canWrite): ?>
        <section class="card stock-form-card">
            <div class="card-heading"><div><h2><?= $supplierEditId === null ? 'Ajouter un fournisseur' : 'Modifier le fournisseur' ?></h2><p>Les achats restent liés au fournisseur même après archivage.</p></div></div>
            <form method="post" action="<?= $escape($baseUrl . '&tab=suppliers') ?>" class="stock-form">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="save_supplier">
                <input type="hidden" name="supplier_id" value="<?= $supplierEditId ?? '' ?>">
                <label class="field"><span>Nom / entreprise</span><input name="name" maxlength="160" required value="<?= $escape($value($supplierValues, 'name')) ?>"></label>
                <label class="field"><span>Contact</span><input name="contact_name" maxlength="120" value="<?= $escape($value($supplierValues, 'contact_name')) ?>"></label>
                <label class="field"><span>Téléphone</span><input name="phone" maxlength="40" value="<?= $escape($value($supplierValues, 'phone')) ?>"></label>
                <label class="field"><span>E-mail</span><input type="email" name="email" maxlength="254" value="<?= $escape($value($supplierValues, 'email')) ?>"></label>
                <label class="field stock-field-wide"><span>Adresse</span><input name="address" maxlength="500" value="<?= $escape($value($supplierValues, 'address')) ?>"></label>
                <label class="field stock-field-wide"><span>Notes</span><textarea name="notes" rows="2" maxlength="4000"><?= $escape($value($supplierValues, 'notes')) ?></textarea></label>
                <div class="stock-form-actions stock-field-wide"><button class="button button-primary" type="submit"><?= $supplierEditId === null ? 'Ajouter le fournisseur' : 'Enregistrer les modifications' ?></button>
                    <?php if ($supplierEditId !== null): ?><a class="button button-secondary" href="<?= $escape($baseUrl . '&tab=suppliers') ?>">Annuler</a><?php endif; ?></div>
            </form>
        </section>
    <?php endif; ?>
    <section class="card table-card stock-table-card">
        <div class="card-heading"><div><h2>Fournisseurs</h2><p><?= count($suppliers) ?> fournisseur(s)</p></div></div>
        <?php if ($suppliers === []): ?><div class="performance-empty-state">Aucun fournisseur enregistré.</div>
        <?php else: ?>
            <div class="table-scroll" tabindex="0" role="region" aria-label="Fournisseurs">
                <table class="data-table stock-table">
                    <thead><tr><th>Fournisseur</th><th>Contact</th><th>Téléphone</th><th>E-mail</th><th>Achats</th><th>État</th><th>Actions</th></tr></thead>
                    <tbody><?php foreach ($suppliers as $supplier): ?>
                        <tr><td><strong><?= $escape((string) $supplier['name']) ?></strong><small><?= $supplier['address'] === null ? '' : $escape((string) $supplier['address']) ?></small></td>
                            <td><?= $supplier['contact_name'] === null ? '—' : $escape((string) $supplier['contact_name']) ?></td>
                            <td><?= $supplier['phone'] === null ? '—' : $escape((string) $supplier['phone']) ?></td>
                            <td><?= $supplier['email'] === null ? '—' : $escape((string) $supplier['email']) ?></td>
                            <td><?= number_format((int) $supplier['purchase_count'], 0, ',', ' ') ?></td>
                            <td><?= $supplier['status'] === 'active' ? 'Actif' : 'Archivé' ?></td>
                            <td class="stock-action-cell"><?php if ($canWrite): ?>
                                <a class="table-action" href="<?= $escape($baseUrl . '&tab=suppliers&edit_supplier=' . (int) $supplier['id']) ?>">Modifier</a>
                                <?php if ($supplier['status'] === 'active'): ?><form method="post" action="<?= $escape($baseUrl . '&tab=suppliers') ?>" data-confirm="Archiver ce fournisseur ? Les achats liés resteront conservés.">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>"><input type="hidden" name="action" value="archive_supplier"><input type="hidden" name="supplier_id" value="<?= (int) $supplier['id'] ?>"><button class="table-action" type="submit">Archiver</button>
                                </form><?php endif; ?>
                            <?php else: ?>—<?php endif; ?></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
<?php elseif ($tab === 'purchases'): ?>
    <?php if ($canWrite): ?>
        <section class="card stock-form-card">
            <div class="card-heading"><div><h2>Enregistrer un achat</h2><p>L’achat crée, dans une seule transaction, l’achat, la dépense, les lots et les entrées de stock.</p></div></div>
            <?php if ($activeSuppliers === [] || count(array_filter($products, static fn (array $p): bool => $p['status'] === 'active')) === 0): ?>
                <div class="performance-empty-state">Ajoutez au moins un fournisseur actif et un produit avant de saisir un achat.</div>
            <?php else: ?>
                <form method="post" action="<?= $escape($baseUrl . '&tab=purchases') ?>" class="stock-form stock-purchase-form">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>"><input type="hidden" name="action" value="save_purchase">
                    <label class="field"><span>Fournisseur</span><select name="supplier_id" required><option value="">Choisir</option><?php foreach ($activeSuppliers as $supplier): ?><option value="<?= (int) $supplier['id'] ?>" <?= (string) $supplier['id'] === $value($purchaseValues, 'supplier_id') ? 'selected' : '' ?>><?= $escape((string) $supplier['name']) ?></option><?php endforeach; ?></select></label>
                    <label class="field"><span>Date d’achat</span><input type="date" name="purchased_on" max="<?= $today->format('Y-m-d') ?>" required value="<?= $escape($value($purchaseValues, 'purchased_on', $today->format('Y-m-d'))) ?>"></label>
                    <label class="field"><span>Référence facture (facultatif)</span><input name="reference" maxlength="100" value="<?= $escape($value($purchaseValues, 'reference')) ?>"></label>
                    <label class="field"><span>Élevage destinataire (facultatif)</span><select name="flock_id"><option value="">Exploitation entière</option><?php foreach ($flocks as $flock): ?><option value="<?= (int) $flock['id'] ?>" <?= (string) $flock['id'] === $value($purchaseValues, 'flock_id') ? 'selected' : '' ?>><?= $escape((string) $flock['name']) ?></option><?php endforeach; ?></select></label>
                    <div class="stock-field-wide">
                        <div class="stock-line-heading"><strong>Articles achetés</strong><button class="button button-secondary button-small" type="button" data-add-purchase-line>Ajouter une ligne</button></div>
                        <div class="stock-purchase-lines" data-purchase-lines>
                            <?php foreach ($items as $index => $item): ?>
                                <div class="stock-purchase-line" data-purchase-line>
                                    <label class="field"><span>Produit</span><select name="items[<?= (int) $index ?>][product_id]" required data-field="product_id" data-product-select><option value="">Choisir</option><?php foreach ($products as $product): if ($product['status'] !== 'active') { continue; } ?><option value="<?= (int) $product['id'] ?>" data-type="<?= $escape((string) $product['product_type']) ?>" data-price="<?= $escape((string) $product['default_unit_price']) ?>" <?= (string) $product['id'] === (string) ($item['product_id'] ?? '') ? 'selected' : '' ?>><?= $escape((string) $product['name']) ?> · <?= $escape((string) $product['unit_code']) ?></option><?php endforeach; ?></select></label>
                                    <label class="field"><span>Quantité</span><input type="number" name="items[<?= (int) $index ?>][quantity]" data-field="quantity" min="0.001" max="1000000000" step="0.001" required value="<?= $escape((string) ($item['quantity'] ?? '')) ?>"></label>
                                    <label class="field"><span>Prix unitaire (<?= $escape((string) $selectedFarm['currency_code']) ?>)</span><input type="number" name="items[<?= (int) $index ?>][unit_price]" data-field="unit_price" min="0.0001" max="1000000000" step="0.0001" required value="<?= $escape((string) ($item['unit_price'] ?? '')) ?>" data-price-input></label>
                                    <label class="field"><span>Péremption</span><input type="date" name="items[<?= (int) $index ?>][expires_on]" data-field="expires_on" value="<?= $escape((string) ($item['expires_on'] ?? '')) ?>" data-expiry-input></label>
                                    <button class="button button-secondary button-small" type="button" data-remove-purchase-line aria-label="Supprimer cette ligne">Retirer</button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <label class="field stock-field-wide"><span>Notes (facultatif)</span><textarea name="notes" rows="2" maxlength="4000"><?= $escape($value($purchaseValues, 'notes')) ?></textarea></label>
                    <p class="stock-help stock-field-wide">Les lignes sont arrondies au centime pour le total de dépense. Une péremption ne peut être appliquée qu’aux consommables.</p>
                    <div class="stock-form-actions stock-field-wide"><button class="button button-primary" type="submit">Enregistrer l’achat et le stock</button></div>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <section class="card table-card stock-table-card">
        <div class="card-heading"><div><h2>Achats enregistrés</h2><p>Maximum 200 achats récents. Le coût est également inscrit dans les dépenses.</p></div></div>
        <?php if ($purchases === []): ?><div class="performance-empty-state">Aucun achat enregistré.</div>
        <?php else: ?><div class="table-scroll" tabindex="0" role="region" aria-label="Achats enregistrés"><table class="data-table stock-table">
            <thead><tr><th>Date</th><th>Fournisseur</th><th>Référence</th><th>Élevage</th><th>Articles</th><th>Total</th></tr></thead>
            <tbody><?php foreach ($purchases as $purchase): ?><tr>
                <td><?= $escape((string) $purchase['purchased_on']) ?></td>
                <td><?= $escape((string) $purchase['supplier_name']) ?></td>
                <td><?= $purchase['reference'] === null ? '—' : $escape((string) $purchase['reference']) ?></td>
                <td><?= $purchase['flock_name'] === null ? 'Toute l’exploitation' : $escape((string) $purchase['flock_name']) ?></td>
                <td><?= number_format((int) $purchase['item_count'], 0, ',', ' ') ?></td>
                <td><strong><?= $number($purchase['total_amount'], 2) ?> <?= $escape((string) $purchase['currency_code']) ?></strong></td>
            </tr><?php endforeach; ?></tbody>
        </table></div><?php endif; ?>
    </section>
<?php elseif ($tab === 'movements'): ?>
    <?php if ($canWrite): ?>
        <section class="card stock-form-card">
            <div class="card-heading"><div><h2>Enregistrer une consommation, sortie ou restitution</h2><p>Les consommations sont liées à un élevage ; le stock ne peut pas passer sous zéro.</p></div></div>
            <form method="post" action="<?= $escape($baseUrl . '&tab=movements') ?>" class="stock-form">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>"><input type="hidden" name="action" value="record_movement">
                <label class="field"><span>Produit</span><select name="product_id" required data-movement-product><option value="">Choisir</option><?php foreach ($products as $product): if ($product['status'] !== 'active') { continue; } ?><option value="<?= (int) $product['id'] ?>" data-type="<?= $escape((string) $product['product_type']) ?>"><?= $escape((string) $product['name']) ?> · <?= $escape((string) $product['unit_code']) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Type de mouvement</span><select name="movement_type" required data-movement-type>
                    <option value="consumption">Consommation (consommables)</option><option value="loss">Perte / destruction</option>
                    <option value="equipment_issue" data-equipment-only>Mise à disposition (équipement)</option>
                    <option value="equipment_return" data-equipment-only>Restitution (équipement)</option>
                </select></label>
                <label class="field"><span>Quantité</span><input type="number" name="quantity" min="0.001" max="1000000000" step="0.001" required></label>
                <label class="field"><span>Élevage associé</span><select name="flock_id" required data-flock-select><option value="">Choisir si consommation ou équipement</option><?php foreach ($flocks as $flock): ?><option value="<?= (int) $flock['id'] ?>"><?= $escape((string) $flock['name']) ?> · <?= $escape((string) $flock['code']) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Lot (obligatoire pour restituer un équipement)</span><select name="lot_id" data-lot-select><option value="">Allocation FEFO automatique</option><?php foreach ($movementLots as $lot): ?><option value="<?= (int) $lot['id'] ?>" data-product-id="<?= (int) $lot['product_id'] ?>"><?= $escape((string) $lot['product_name']) ?> · <?= $lot['lot_code'] === null ? 'sans numéro' : $escape((string) $lot['lot_code']) ?> · <?= $number($lot['quantity_available']) ?> <?= $escape((string) $lot['unit_code']) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Date du mouvement</span><input type="date" name="occurred_on" max="<?= $today->format('Y-m-d') ?>" required value="<?= $today->format('Y-m-d') ?>"></label>
                <label class="field stock-field-wide"><span>Motif</span><input name="reason" maxlength="500" minlength="3" required placeholder="Ration distribuée, casse constatée, matériel attribué…"></label>
                <div class="stock-form-actions stock-field-wide"><button class="button button-primary" type="submit">Enregistrer le mouvement</button></div>
            </form>
        </section>
    <?php endif; ?>
    <section class="card table-card stock-table-card">
        <div class="card-heading"><div><h2>Historique des mouvements</h2><p><?= count($history) ?> mouvement(s) affiché(s), maximum 500 ; le stock actuel est la somme de tous les mouvements.</p></div></div>
        <form class="stock-filter-form" method="get" action="stock.php">
            <input type="hidden" name="farm_id" value="<?= $farmId ?>"><input type="hidden" name="tab" value="movements">
            <label class="field"><span>Produit</span><select name="product_id"><option value="">Tous les produits</option><?php foreach ($products as $product): ?><option value="<?= (int) $product['id'] ?>" <?= (int) $product['id'] === (int) ($historyProductId ?? 0) ? 'selected' : '' ?>><?= $escape((string) $product['name']) ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Du</span><input type="date" name="from" value="<?= $escape($fromDate) ?>"></label>
            <label class="field"><span>Au</span><input type="date" name="to" value="<?= $escape($toDate) ?>"></label>
            <button class="button button-secondary" type="submit">Filtrer</button>
        </form>
        <?php if ($history === []): ?><div class="performance-empty-state">Aucun mouvement ne correspond aux filtres.</div>
        <?php else: ?><div class="table-scroll" tabindex="0" role="region" aria-label="Historique des mouvements"><table class="data-table stock-table">
            <thead><tr><th>Date</th><th>Produit</th><th>Type</th><th>Quantité</th><th>Lot / péremption</th><th>Élevage</th><th>Fournisseur</th><th>Motif</th></tr></thead>
            <tbody><?php foreach ($history as $movement): ?><tr>
                <td><?= $escape((string) $movement['occurred_at']) ?></td>
                <td><?= $escape((string) $movement['product_name']) ?></td>
                <td><?= $escape($movementNames[$movement['movement_type']] ?? (string) $movement['movement_type']) ?></td>
                <td class="<?= (float) $movement['quantity_delta'] < 0 ? 'stock-negative' : 'stock-positive' ?>"><?= (float) $movement['quantity_delta'] > 0 ? '+' : '' ?><?= $number($movement['quantity_delta']) ?> <?= $escape((string) $movement['unit_code']) ?></td>
                <td><?= $movement['lot_code'] === null ? 'Non attribué' : $escape((string) $movement['lot_code']) ?><?= $movement['expires_on'] === null ? '' : ' · exp. ' . $escape((string) $movement['expires_on']) ?></td>
                <td><?= $movement['flock_name'] === null ? '—' : $escape((string) $movement['flock_name']) ?></td>
                <td><?= $movement['supplier_name'] === null ? '—' : $escape((string) $movement['supplier_name']) ?></td>
                <td><?= $movement['reason'] === null ? '—' : $escape((string) $movement['reason']) ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div><?php endif; ?>
    </section>
<?php elseif ($tab === 'inventory'): ?>
    <?php if ($canWrite): ?>
        <section class="card stock-form-card">
            <div class="card-heading"><div><h2>Effectuer un inventaire</h2><p>Comparez le stock théorique au comptage physique ; l’écart autorisé est enregistré avec son motif et modifie le registre.</p></div></div>
            <form method="post" action="<?= $escape($baseUrl . '&tab=inventory') ?>" class="stock-form">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>"><input type="hidden" name="action" value="record_inventory">
                <label class="field"><span>Produit</span><select name="product_id" required data-inventory-product><option value="">Choisir</option><?php foreach ($products as $product): if ($product['status'] !== 'active') { continue; } ?><option value="<?= (int) $product['id'] ?>" data-type="<?= $escape((string) $product['product_type']) ?>"><?= $escape((string) $product['name']) ?> · stock <?= $number($product['current_stock']) ?> <?= $escape((string) $product['unit_code']) ?></option><?php endforeach; ?></select></label>
                <label class="field"><span>Quantité réellement comptée</span><input type="number" name="counted_quantity" min="0" max="1000000000" step="0.001" required></label>
                <label class="field"><span>Date du comptage</span><input type="date" name="counted_on" max="<?= $today->format('Y-m-d') ?>" required value="<?= $today->format('Y-m-d') ?>"></label>
                <label class="field"><span>Péremption (si un ajustement crée un lot consommable)</span><input type="date" name="expires_on"></label>
                <label class="field stock-field-wide"><span>Motif / référence du comptage</span><input name="reason" maxlength="500" minlength="3" required placeholder="Inventaire périodique, contrôle après perte…"></label>
                <p class="stock-help stock-field-wide">Un écart négatif est réparti sur les lots disponibles, y compris les lots expirés (comptage physique). Un écart positif crée un lot d’ajustement. Les deux sont réservés aux rôles autorisés.</p>
                <div class="stock-form-actions stock-field-wide"><button class="button button-primary" type="submit">Enregistrer et ajuster</button></div>
            </form>
        </section>
    <?php endif; ?>
    <section class="card table-card stock-table-card">
        <div class="card-heading"><div><h2>Historique des inventaires</h2><p>Les comptages et les écarts restent consultables après ajustement.</p></div></div>
        <?php if ($inventories === []): ?><div class="performance-empty-state">Aucun inventaire enregistré.</div>
        <?php else: ?><div class="table-scroll" tabindex="0" role="region" aria-label="Historique des inventaires"><table class="data-table stock-table">
            <thead><tr><th>Date</th><th>Produit</th><th>Stock théorique</th><th>Compté physiquement</th><th>Écart</th><th>Motif</th></tr></thead>
            <tbody><?php foreach ($inventories as $inventory): ?><tr>
                <td><?= $escape((string) $inventory['counted_on']) ?></td>
                <td><?= $escape((string) $inventory['product_name']) ?></td>
                <td><?= $number($inventory['expected_quantity']) ?> <?= $escape((string) $inventory['unit_code']) ?></td>
                <td><?= $number($inventory['counted_quantity']) ?> <?= $escape((string) $inventory['unit_code']) ?></td>
                <td class="<?= (float) $inventory['variance_quantity'] < 0 ? 'stock-negative' : ((float) $inventory['variance_quantity'] > 0 ? 'stock-positive' : '') ?>"><?= (float) $inventory['variance_quantity'] > 0 ? '+' : '' ?><?= $number($inventory['variance_quantity']) ?></td>
                <td><?= $escape((string) $inventory['reason']) ?></td>
            </tr><?php endforeach; ?></tbody>
        </table></div><?php endif; ?>
    </section>
<?php endif; ?>

<template id="purchase-line-template">
    <div class="stock-purchase-line" data-purchase-line>
        <label class="field"><span>Produit</span><select data-field="product_id" required data-product-select><option value="">Choisir</option><?php foreach ($products as $product): if ($product['status'] !== 'active') { continue; } ?><option value="<?= (int) $product['id'] ?>" data-type="<?= $escape((string) $product['product_type']) ?>" data-price="<?= $escape((string) $product['default_unit_price']) ?>"><?= $escape((string) $product['name']) ?> · <?= $escape((string) $product['unit_code']) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span>Quantité</span><input data-field="quantity" type="number" min="0.001" max="1000000000" step="0.001" required></label>
        <label class="field"><span>Prix unitaire (<?= $escape((string) $selectedFarm['currency_code']) ?>)</span><input data-field="unit_price" type="number" min="0.0001" max="1000000000" step="0.0001" required data-price-input></label>
        <label class="field"><span>Péremption</span><input data-field="expires_on" type="date" data-expiry-input></label>
        <button class="button button-secondary button-small" type="button" data-remove-purchase-line aria-label="Supprimer cette ligne">Retirer</button>
    </div>
</template>
