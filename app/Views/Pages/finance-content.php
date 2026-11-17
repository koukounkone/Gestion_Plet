<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$money = static fn (int|float|string $amount): string => number_format((float) $amount, 0, ',', ' ') . ' FCFA';
$typeLabels = [
    'cash' => 'Trésorerie',
    'consumed' => 'Charges consommées',
    'investment' => 'Investissements réutilisables',
];
$expenseTypeLabels = [
    'consumable' => 'Consommable',
    'charge' => 'Charge',
    'investment' => 'Matériel réutilisable',
    'stock_purchase' => 'Achat de stock',
];
$expenseId = is_array($editingExpense)
    ? (int) $editingExpense['id']
    : (is_int($expenseEditId ?? null) ? $expenseEditId : null);
$budgetId = is_array($editingBudget)
    ? (int) $editingBudget['id']
    : (is_int($budgetEditId ?? null) ? $budgetEditId : null);
$oldReceipt = is_array($editingExpense) ? $editingExpense : null;
$currentExpenseValues = is_array($expenseValues ?? null) ? $expenseValues : [];
$currentBudgetValues = is_array($budgetValues ?? null) ? $budgetValues : [];
$formAction = (string) ($financeFormAction ?? 'finance.php?farm_id=' . $farmId);
$profitChartData = [
    'labels' => ['Recettes comptabilisées', 'Coûts économiques', 'Bénéfice / perte estimé'],
    'values' => [
        (int) $profitability['revenue_total'],
        (int) $profitability['economic_costs'],
        (int) $profitability['estimated_profit'],
    ],
];
$cashChartData = [
    'labels' => ['Encaissements nets', 'Dépenses enregistrées', 'Flux net de période'],
    'values' => [
        (int) $profitability['cash_collected'],
        (int) $profitability['cash_outflow'],
        (int) $profitability['net_cash_flow'],
    ],
];
$investmentChartData = [
    'labels' => ['Investissements directs', 'Achats de matériel en stock'],
    'values' => [
        (int) $profitability['direct_investments'],
        (int) $profitability['equipment_purchases'],
    ],
];
$encodeChart = static fn (array $data): string => htmlspecialchars(
    (string) json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $error): ?><p><?= $escape((string) $error) ?></p><?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if (is_array($flash) && isset($flash['message'])): ?>
    <div class="alert alert-success" role="status"><?= $escape((string) $flash['message']) ?></div>
<?php endif; ?>

<div class="finance-accounting-note" role="note">
    <strong>Trois vues comptables distinctes, à ne pas additionner.</strong>
    La trésorerie compte chaque paiement une fois. Les charges consommées incluent les
    consommables sortis du stock au coût d’achat. Les équipements sont affichés comme
    investissements lorsqu’ils sont achetés ou enregistrés directement.
</div>

<section class="finance-summary-grid" aria-label="Synthèse financière sur la période">
    <article class="card finance-summary-card">
        <span class="finance-summary-label">Sorties de trésorerie</span>
        <strong><?= $money($summary['cash_outflow']) ?></strong>
        <small>Achats réglés et dépenses enregistrées</small>
    </article>
    <article class="card finance-summary-card">
        <span class="finance-summary-label">Charges consommées</span>
        <strong><?= $money($summary['consumed_charges']) ?></strong>
        <small>Charges directes et consommables utilisés</small>
    </article>
    <article class="card finance-summary-card">
        <span class="finance-summary-label">Investissements réutilisables</span>
        <strong><?= $money($summary['reusable_investments']) ?></strong>
        <small>Équipements achetés et matériels déclarés</small>
    </article>
</section>

<section class="card finance-filter-card" aria-label="Filtres des opérations">
    <form method="get" action="finance.php" class="finance-filter-form">
        <input type="hidden" name="farm_id" value="<?= $farmId ?>">
        <label class="field">
            <span>Du</span>
            <input type="date" name="from" value="<?= $escape($fromDate) ?>" required>
        </label>
        <label class="field">
            <span>Au</span>
            <input type="date" name="to" value="<?= $escape($toDate) ?>" required>
        </label>
        <label class="field">
            <span>Élevage</span>
            <select name="flock_id">
                <option value="">Tous les élevages</option>
                <?php foreach ($flocks as $flock): ?>
                    <option value="<?= (int) $flock['id'] ?>"
                        <?= $selectedFlockId === (int) $flock['id'] ? 'selected' : '' ?>>
                        <?= $escape((string) $flock['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="button button-primary" type="submit">Appliquer</button>
    </form>
</section>

<section class="finance-section" aria-labelledby="profitability-title">
    <div class="dashboard-section-heading">
        <div>
            <p class="eyebrow">BILAN FINANCIER · <?= $escape($fromDate) ?> — <?= $escape($toDate) ?></p>
            <h2 id="profitability-title">Rentabilité et coût de revient</h2>
            <p><?= $selectedFlockId === null ? 'Toute l’exploitation' : 'Élevage sélectionné' ?> · bilan économique estimatif en FCFA</p>
        </div>
    </div>

    <?php if (!$profitability['is_complete']): ?>
        <div class="alert alert-warning profitability-incomplete" role="status">
            <strong>Résultat partiel — des coûts ne peuvent pas être valorisés.</strong>
            <ul>
                <?php foreach ($profitability['incomplete_reasons'] as $reason): ?>
                    <li><?= $escape((string) $reason) ?></li>
                <?php endforeach; ?>
            </ul>
            <p>Les quantités sans coût sont exclues des montants estimés ; le résultat peut donc surestimer le bénéfice.</p>
        </div>
    <?php endif; ?>

    <div class="finance-summary-grid profitability-summary-grid" aria-label="Indicateurs de rentabilité">
        <article class="card finance-summary-card">
            <span class="finance-summary-label">Recettes comptabilisées</span>
            <strong><?= $money($profitability['revenue_total']) ?></strong>
            <small>Ventes confirmées, hors ventes annulées</small>
        </article>
        <article class="card finance-summary-card">
            <span class="finance-summary-label">Encaissements nets de la période</span>
            <strong><?= $money($profitability['cash_collected']) ?></strong>
            <small>Paiements reçus moins remboursements enregistrés</small>
        </article>
        <article class="card finance-summary-card">
            <span class="finance-summary-label">Créances restantes</span>
            <strong><?= $money($profitability['receivables']) ?></strong>
            <small>Solde dû sur les ventes de la période</small>
        </article>
        <article class="card finance-summary-card">
            <span class="finance-summary-label">Dépenses enregistrées</span>
            <strong><?= $money($profitability['expenses_total']) ?></strong>
            <small>Achats et dépenses saisis pendant la période</small>
        </article>
        <article class="card finance-summary-card">
            <span class="finance-summary-label">Coûts économiques estimés</span>
            <strong><?= $money($profitability['economic_costs']) ?></strong>
            <small>Charges directes + stock consommé + coût des produits vendus</small>
        </article>
        <article class="card finance-summary-card">
            <span class="finance-summary-label">Stocks restants au <?= $escape($toDate) ?></span>
            <strong><?= $money($profitability['remaining_stock_value']) ?></strong>
            <small>Valeur connue · consommables <?= $money($profitability['remaining_consumables_value']) ?>
                · matériel <?= $money($profitability['remaining_equipment_value']) ?></small>
        </article>
        <article class="card finance-summary-card">
            <span class="finance-summary-label">Coût affecté par animal vendu</span>
            <strong><?= $profitability['cost_per_animal_sold'] === null
                ? 'Indisponible'
                : $money($profitability['cost_per_animal_sold']) ?></strong>
            <small><?= number_format((int) $profitability['animals_sold'], 0, ',', ' ') ?> animal(aux) vendu(s)
                · mortalités constatées : <?= number_format((int) $profitability['mortality_count'], 0, ',', ' ') ?></small>
        </article>
        <article class="card finance-summary-card profitability-result-card<?= $profitability['estimated_profit'] < 0 ? ' is-loss' : '' ?>">
            <span class="finance-summary-label">Bénéfice / perte estimé(e)</span>
            <strong><?= $money($profitability['estimated_profit']) ?></strong>
            <small>Marge :
                <?= $profitability['margin_percent'] === null
                    ? 'non calculable sans recette'
                    : number_format((float) $profitability['margin_percent'], 2, ',', ' ') . ' %' ?>
            </small>
        </article>
    </div>

    <div class="profitability-chart-grid">
        <article class="card profitability-chart-card">
            <div class="card-heading">
                <div><h3>Résultat économique</h3><p>Recettes comparées aux coûts inclus dans le résultat.</p></div>
            </div>
            <div class="profitability-chart-wrap">
                <canvas data-profitability-chart="economic" data-chart="<?= $encodeChart($profitChartData) ?>"
                        role="img" aria-label="Graphique des recettes, coûts économiques et résultat estimé"></canvas>
            </div>
        </article>
        <article class="card profitability-chart-card">
            <div class="card-heading">
                <div><h3>Flux de trésorerie</h3><p>Encaissements et dépenses enregistrés sur la période.</p></div>
            </div>
            <div class="profitability-chart-wrap">
                <canvas data-profitability-chart="cash" data-chart="<?= $encodeChart($cashChartData) ?>"
                        role="img" aria-label="Graphique des encaissements, dépenses et flux net"></canvas>
            </div>
        </article>
        <article class="card profitability-chart-card">
            <div class="card-heading">
                <div><h3>Investissements réutilisables</h3><p>Montants classés en actifs et non en coûts consommés.</p></div>
            </div>
            <div class="profitability-chart-wrap">
                <canvas data-profitability-chart="investment" data-chart="<?= $encodeChart($investmentChartData) ?>"
                        role="img" aria-label="Graphique des investissements directs et achats de matériel réutilisable"></canvas>
            </div>
        </article>
    </div>

    <div class="profitability-explanation-grid">
        <article class="card profitability-explanation">
            <h3>Formules et règles de lecture</h3>
            <ul>
                <li><strong>Résultat estimé</strong> = recettes des ventes confirmées − charges directes − coût des consommations de stock − coût d’achat des produits vendus.</li>
                <li><strong>Marge</strong> = résultat estimé ÷ recettes × 100 ; sans recette, elle n’est pas calculable.</li>
                <li><strong>Flux net de période</strong> = encaissements nets reçus pendant la période − dépenses enregistrées pendant cette période.</li>
                <li><strong>Coût par animal vendu</strong> = coûts directs et consommations affectés aux élevages ÷ animaux vendus. C’est un indicateur de période, pas un coût historique complet ; il n’est pas ajouté une seconde fois au résultat.</li>
                <li>Les mortalités sont présentées séparément ; leurs coûts restent dans les charges de l’élevage et ne sont pas déduits une seconde fois.</li>
                <li>Les achats de stock sont comptés en trésorerie à l’achat, puis en coût économique à la consommation ou à la vente. Le stock non consommé et le matériel réutilisable restent des actifs et ne sont pas soustraits du bénéfice.</li>
            </ul>
            <p class="profitability-cash-note">Flux net de trésorerie : <strong><?= $money($profitability['net_cash_flow']) ?></strong>
                · investissements réutilisables hors résultat : <strong><?= $money($profitability['reusable_investments']) ?></strong></p>
        </article>

        <article class="card profitability-explanation">
            <h3>Comparaison au budget initial</h3>
            <?php $comparison = $profitability['budget_comparison']; ?>
            <?php if (!is_array($comparison)): ?>
                <p class="finance-no-lines">Aucun budget actif couvrant toute la période sélectionnée pour ce périmètre. Les budgets et leurs périodes peuvent être gérés plus bas.</p>
            <?php else: ?>
                <p class="profitability-budget-title">
                    <?= $escape($comparison['name']) ?> · <?= $escape($comparison['starts_on']) ?> — <?= $escape($comparison['ends_on']) ?>
                </p>
                <div class="table-scroll">
                    <table class="finance-table profitability-budget-table">
                        <thead><tr><th>Nature</th><th>Catégorie</th><th>Prévu</th><th>Réalisé</th><th>Restant</th></tr></thead>
                        <tbody>
                        <?php foreach ($comparison['lines'] as $line): ?>
                            <?php $categoryLabel = $expenseCategories[$line['category']] ?? ($line['category'] === 'stock_purchase' ? 'Achat de stock' : $line['category']); ?>
                            <tr>
                                <td><?= $escape($typeLabels[$line['financial_type']] ?? $line['financial_type']) ?></td>
                                <td><?= $escape((string) $categoryLabel) ?></td>
                                <td><?= $money($line['planned_amount']) ?></td>
                                <td><?= $money($line['actual_amount']) ?></td>
                                <td class="<?= (int) $line['remaining_amount'] < 0 ? 'finance-over-budget' : '' ?>"><?= $money($line['remaining_amount']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="finance-budget-footnote">Le budget est comparé par nature financière et catégorie ; ses flux, charges et investissements ne sont jamais additionnés entre eux.</p>
            <?php endif; ?>
        </article>
    </div>

    <article class="card profitability-detail-card">
        <div class="card-heading">
            <div><h3>Tableau comparatif détaillé</h3><p>Les valeurs de stock sont arrêtées à la fin de la période.</p></div>
        </div>
        <div class="table-scroll">
            <table class="finance-table profitability-detail-table">
                <thead><tr><th>Indicateur</th><th>Montant / quantité</th><th>Traitement dans le calcul</th></tr></thead>
                <tbody>
                    <tr><td>Recettes des ventes</td><td><?= $money($profitability['revenue_total']) ?></td><td>Résultat économique, à la date de vente</td></tr>
                    <tr><td>Encaissements nets</td><td><?= $money($profitability['cash_collected']) ?></td><td>Trésorerie uniquement ; peut concerner des ventes antérieures</td></tr>
                    <tr><td>Créances des ventes de la période</td><td><?= $money($profitability['receivables']) ?></td><td>Solde encore dû, tous paiements connus pris en compte</td></tr>
                    <tr><td>Coûts consommés de stock</td><td><?= $money($profitability['stock_consumed_cost']) ?></td><td>Consommations valorisées au coût du lot</td></tr>
                    <tr><td>Coût d’achat des produits vendus</td><td><?= $money($profitability['product_cost_of_sales']) ?></td><td>Coût des lots débités, hors ventes annulées</td></tr>
                    <tr><td>Investissements réutilisables directs</td><td><?= $money($profitability['direct_investments']) ?></td><td>Trésorerie / actif, exclus des coûts économiques</td></tr>
                    <tr><td>Achats de matériel réutilisable en stock</td><td><?= $money($profitability['equipment_purchases']) ?></td><td>Déjà compris dans les dépenses enregistrées ; valeur restante présentée séparément</td></tr>
                    <tr><td>Stock non valorisé</td><td><?= number_format((float) $profitability['unvalued_stock_quantity'], 3, ',', ' ') ?> unité(s)</td><td>Coût d’achat inconnu ; exclu de la valeur estimée</td></tr>
                    <tr><td>Consommations / ventes sans coût connu</td><td><?= number_format((float) $profitability['unvalued_cost_quantity'], 3, ',', ' ') ?> unité(s)</td><td>Résultat économique incomplet, quantité exclue du coût</td></tr>
                </tbody>
            </table>
        </div>
    </article>
</section>

<?php if ($canManage): ?>
<section class="finance-workspace-grid" aria-label="Saisie financière">
    <section class="card finance-form-card" id="expense-form">
        <div class="card-heading">
            <div>
                <h2><?= $expenseId === null ? 'Enregistrer une dépense' : 'Modifier une dépense' ?></h2>
                <p>Montants entiers en FCFA. Une dépense liée à un achat de stock se gère depuis le catalogue.</p>
            </div>
        </div>
        <form method="post" action="<?= $escape($formAction) ?>#expense-form"
              enctype="multipart/form-data" class="finance-form">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="save_expense">
            <?php if ($expenseId !== null): ?>
                <input type="hidden" name="expense_id" value="<?= $expenseId ?>">
            <?php endif; ?>
            <div class="finance-form-grid">
                <label class="field">
                    <span>Date de la dépense</span>
                    <input type="date" name="incurred_on" max="<?= $today->format('Y-m-d') ?>"
                           value="<?= $escape((string) ($currentExpenseValues['incurred_on'] ?? $today->format('Y-m-d'))) ?>"
                           required>
                </label>
                <label class="field">
                    <span>Montant (FCFA)</span>
                    <input type="number" name="amount" min="1" max="9999999999999" step="1"
                           inputmode="numeric" value="<?= $escape((string) ($currentExpenseValues['amount'] ?? '')) ?>"
                           required>
                </label>
                <label class="field">
                    <span>Catégorie</span>
                    <select name="category" required>
                        <?php foreach ($expenseCategories as $key => $label): ?>
                            <option value="<?= $escape($key) ?>"
                                <?= ($currentExpenseValues['category'] ?? 'feed') === $key ? 'selected' : '' ?>>
                                <?= $escape($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span>Nature comptable</span>
                    <select name="expense_type" required>
                        <?php foreach (['consumable', 'charge', 'investment'] as $type): ?>
                            <option value="<?= $type ?>"
                                <?= ($currentExpenseValues['expense_type'] ?? 'charge') === $type ? 'selected' : '' ?>>
                                <?= $escape($expenseTypeLabels[$type]) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span>Élevage concerné</span>
                    <select name="flock_id">
                        <option value="">Dépense générale</option>
                        <?php foreach ($flocks as $flock): ?>
                            <option value="<?= (int) $flock['id'] ?>"
                                <?= (string) ($currentExpenseValues['flock_id'] ?? '') === (string) $flock['id'] ? 'selected' : '' ?>>
                                <?= $escape((string) $flock['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field finance-field-wide">
                    <span>Description</span>
                    <textarea name="description" rows="3" maxlength="500" required><?= $escape((string) ($currentExpenseValues['description'] ?? '')) ?></textarea>
                </label>
                <label class="field finance-field-wide">
                    <span>Justificatif (PDF, JPEG ou PNG, 5 Mio maximum)</span>
                    <input type="file" name="receipt" accept="application/pdf,image/jpeg,image/png">
                </label>
            </div>
            <?php if ($oldReceipt !== null && is_string($oldReceipt['receipt_file_name'] ?? null)): ?>
                <div class="finance-receipt-current">
                    <span>Justificatif actuel : <?= $escape($oldReceipt['receipt_file_name']) ?></span>
                    <a href="finance.php?farm_id=<?= $farmId ?>&amp;download=<?= (int) $oldReceipt['id'] ?>">Télécharger</a>
                    <label><input type="checkbox" name="remove_receipt" value="1"> Retirer le justificatif actuel</label>
                </div>
            <?php endif; ?>
            <div class="finance-form-actions">
                <button class="button button-primary" type="submit">
                    <?= $expenseId === null ? 'Enregistrer la dépense' : 'Sauvegarder les modifications' ?>
                </button>
                <?php if ($expenseId !== null): ?>
                    <a class="button button-secondary" href="finance.php?farm_id=<?= $farmId ?>">Annuler</a>
                <?php endif; ?>
            </div>
        </form>
    </section>

    <section class="card finance-form-card" id="budget-form">
        <div class="card-heading">
            <div>
                <h2><?= $budgetId === null ? 'Budget prévisionnel' : 'Modifier le budget' ?></h2>
                <p>Les postes sont comparés par catégorie et nature comptable.</p>
            </div>
        </div>
        <form method="post" action="<?= $escape($formAction) ?>#budget-form"
              class="finance-form" data-finance-budget-form>
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="save_budget">
            <?php if ($budgetId !== null): ?>
                <input type="hidden" name="budget_id" value="<?= $budgetId ?>">
            <?php endif; ?>
            <div class="finance-form-grid">
                <label class="field finance-field-wide">
                    <span>Nom du budget</span>
                    <input type="text" name="name" maxlength="160" required
                           value="<?= $escape((string) ($currentBudgetValues['name'] ?? '')) ?>">
                </label>
                <label class="field">
                    <span>Début</span>
                    <input type="date" name="starts_on" required
                           value="<?= $escape((string) ($currentBudgetValues['starts_on'] ?? $monthStart)) ?>">
                </label>
                <label class="field">
                    <span>Fin</span>
                    <input type="date" name="ends_on" required
                           value="<?= $escape((string) ($currentBudgetValues['ends_on'] ?? $today->modify('last day of this month')->format('Y-m-d'))) ?>">
                </label>
                <label class="field finance-field-wide">
                    <span>Périmètre</span>
                    <select name="flock_id">
                        <option value="">Toute l’exploitation</option>
                        <?php foreach ($flocks as $flock): ?>
                            <option value="<?= (int) $flock['id'] ?>"
                                <?= (string) ($currentBudgetValues['flock_id'] ?? '') === (string) $flock['id'] ? 'selected' : '' ?>>
                                <?= $escape((string) $flock['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <div class="finance-budget-lines-heading">
                <strong>Postes prévus</strong>
                <button class="button button-secondary button-small" type="button" data-budget-add>Ajouter un poste</button>
            </div>
            <div class="finance-budget-lines" data-budget-lines>
                <?php $budgetLines = is_array($currentBudgetValues['lines'] ?? null) ? $currentBudgetValues['lines'] : []; ?>
                <?php foreach ($budgetLines as $lineIndex => $line): ?>
                    <?php if (!is_array($line)) { continue; } ?>
                    <div class="finance-budget-line" data-budget-line>
                        <label class="field">
                            <span>Nature</span>
                            <select name="lines[<?= (int) $lineIndex ?>][financial_type]" required>
                                <?php foreach ($typeLabels as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= ($line['financial_type'] ?? 'cash') === $key ? 'selected' : '' ?>>
                                        <?= $escape($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field">
                            <span>Catégorie</span>
                            <select name="lines[<?= (int) $lineIndex ?>][category]" required>
                                <?php foreach ($budgetCategories as $category): ?>
                                    <?php $categoryLabel = $expenseCategories[$category] ?? ($category === 'stock_purchase' ? 'Achat de stock' : $category); ?>
                                    <option value="<?= $escape((string) $category) ?>"
                                        <?= ($line['category'] ?? 'feed') === $category ? 'selected' : '' ?>>
                                        <?= $escape((string) $categoryLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field">
                            <span>Montant (FCFA)</span>
                            <input type="number" name="lines[<?= (int) $lineIndex ?>][planned_amount]" min="1" max="9999999999999"
                                   step="1" inputmode="numeric" required
                                   value="<?= $escape((string) ($line['planned_amount'] ?? '')) ?>">
                        </label>
                        <button class="button button-secondary button-small" type="button"
                                data-budget-remove aria-label="Retirer ce poste">Retirer</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <template data-budget-line-template>
                <div class="finance-budget-line" data-budget-line>
                    <label class="field">
                        <span>Nature</span>
                        <select name="lines[__INDEX__][financial_type]" required>
                            <?php foreach ($typeLabels as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $escape($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">
                        <span>Catégorie</span>
                        <select name="lines[__INDEX__][category]" required>
                            <?php foreach ($budgetCategories as $category): ?>
                                <?php $categoryLabel = $expenseCategories[$category] ?? ($category === 'stock_purchase' ? 'Achat de stock' : $category); ?>
                                <option value="<?= $escape((string) $category) ?>"><?= $escape((string) $categoryLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">
                        <span>Montant (FCFA)</span>
                        <input type="number" name="lines[__INDEX__][planned_amount]" min="1" max="9999999999999"
                               step="1" inputmode="numeric" required>
                    </label>
                    <button class="button button-secondary button-small" type="button"
                            data-budget-remove aria-label="Retirer ce poste">Retirer</button>
                </div>
            </template>
            <div class="finance-form-actions">
                <button class="button button-primary" type="submit">
                    <?= $budgetId === null ? 'Enregistrer le budget' : 'Sauvegarder le budget' ?>
                </button>
                <?php if ($budgetId !== null): ?>
                    <a class="button button-secondary" href="finance.php?farm_id=<?= $farmId ?>">Annuler</a>
                <?php endif; ?>
            </div>
        </form>
    </section>
</section>
<?php endif; ?>

<section class="dashboard-section finance-section" aria-labelledby="budget-list-title">
    <div class="dashboard-section-heading">
        <div>
            <p class="eyebrow">PRÉVISIONNEL ET RÉALISÉ</p>
            <h2 id="budget-list-title">Budgets actifs</h2>
        </div>
    </div>
    <?php if ($budgets === []): ?>
        <div class="card dashboard-empty">
            <h3>Aucun budget actif</h3>
            <p>Créez un prévisionnel pour comparer les montants planifiés aux flux réels.</p>
        </div>
    <?php else: ?>
        <div class="finance-budget-grid">
            <?php foreach ($budgets as $budget): ?>
                <article class="card finance-budget-card">
                    <div class="finance-budget-heading">
                        <div>
                            <h3><?= $escape((string) $budget['name']) ?></h3>
                            <p><?= $escape((string) $budget['starts_on']) ?> — <?= $escape((string) $budget['ends_on']) ?>
                                · <?= $escape((string) ($budget['flock_name'] ?? 'Toute l’exploitation')) ?></p>
                        </div>
                        <?php if ($canManage): ?>
                            <div class="finance-inline-actions">
                                <a class="button button-secondary button-small"
                                   href="finance.php?farm_id=<?= $farmId ?>&amp;edit_budget=<?= (int) $budget['id'] ?>#budget-form">Modifier</a>
                                <form method="post" action="<?= $escape($formAction) ?>"
                                      data-confirm="Archiver ce budget ? Les lignes et comparaisons seront conservées.">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="action" value="archive_budget">
                                    <input type="hidden" name="budget_id" value="<?= (int) $budget['id'] ?>">
                                    <button class="button button-secondary button-small" type="submit">Archiver</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($budget['lines'] === []): ?>
                        <p class="finance-no-lines">Ce budget ne contient aucun poste.</p>
                    <?php else: ?>
                        <div class="table-scroll">
                            <table class="finance-table">
                                <thead><tr><th>Nature</th><th>Catégorie</th><th>Prévu</th><th>Réalisé</th><th>Écart restant</th></tr></thead>
                                <tbody>
                                <?php foreach ($budget['lines'] as $line): ?>
                                    <?php $categoryLabel = $expenseCategories[$line['category']] ?? ($line['category'] === 'stock_purchase' ? 'Achat de stock' : $line['category']); ?>
                                    <tr>
                                        <td><?= $escape($typeLabels[$line['financial_type']] ?? $line['financial_type']) ?></td>
                                        <td><?= $escape((string) $categoryLabel) ?></td>
                                        <td><?= $money($line['planned_amount']) ?></td>
                                        <td><?= $money($line['actual_amount']) ?></td>
                                        <td class="<?= (int) $line['remaining_amount'] < 0 ? 'finance-over-budget' : '' ?>">
                                            <?= $money($line['remaining_amount']) ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="finance-budget-footnote">
                            Les totaux sont séparés par nature financière. Les trois vues ne sont pas cumulées entre elles.
                        </p>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="dashboard-section finance-section" aria-labelledby="expense-history-title">
    <div class="dashboard-section-heading">
        <div>
            <p class="eyebrow">JOURNAL FINANCIER</p>
            <h2 id="expense-history-title">Dépenses de la période</h2>
            <p>Historique limité aux 500 opérations les plus récentes dans la période sélectionnée.</p>
        </div>
    </div>
    <div class="card table-card">
        <?php if ($expenses === []): ?>
            <div class="dashboard-empty">
                <h3>Aucune dépense sur cette période</h3>
                <p>Essayez une autre période ou enregistrez une dépense pour commencer le suivi.</p>
            </div>
        <?php else: ?>
            <div class="table-scroll" tabindex="0" aria-label="Historique des dépenses, défilement horizontal possible">
                <table class="finance-table finance-expense-table">
                    <thead><tr><th>Date</th><th>Description</th><th>Élevage</th><th>Catégorie / nature</th><th>Montant</th><th>Statut</th><th>Justificatif</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($expenses as $expense): ?>
                        <tr>
                            <td><?= $escape((string) date('d/m/Y', strtotime((string) $expense['incurred_on']))) ?></td>
                            <td>
                                <strong><?= $escape((string) $expense['description']) ?></strong>
                                <?php if ($expense['purchase_id'] !== null): ?>
                                    <small>Achat de stock <?= $escape((string) ($expense['purchase_reference'] ?? '')) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= $escape((string) ($expense['flock_name'] ?? 'Général')) ?></td>
                            <td>
                                <span><?= $escape($expenseCategories[$expense['category']] ?? (string) $expense['category']) ?></span>
                                <small><?= $escape($expenseTypeLabels[$expense['expense_type']] ?? $expense['expense_type']) ?></small>
                            </td>
                            <td class="finance-amount"><?= $money($expense['amount']) ?></td>
                            <td><span class="badge <?= $expense['status'] === 'posted' ? 'badge-success' : 'badge-neutral' ?>">
                                <?= $expense['status'] === 'posted' ? 'Enregistrée' : 'Annulée' ?>
                            </span></td>
                            <td>
                                <?php if ($expense['receipt_storage_key'] !== null && $expense['status'] === 'posted'): ?>
                                    <a href="finance.php?farm_id=<?= $farmId ?>&amp;download=<?= (int) $expense['id'] ?>">Télécharger</a>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <?php if ($canManage && $expense['status'] === 'posted' && $expense['purchase_id'] === null): ?>
                                    <div class="finance-row-actions">
                                        <a class="button button-secondary button-small"
                                           href="finance.php?farm_id=<?= $farmId ?>&amp;edit_expense=<?= (int) $expense['id'] ?>#expense-form">Modifier</a>
                                        <details class="finance-void-details">
                                            <summary class="button button-secondary button-small">Annuler</summary>
                                            <form method="post" action="<?= $escape($formAction) ?>"
                                                  class="finance-void-form" data-confirm="Annuler cette dépense ? L’opération restera visible dans l’historique.">
                                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                <input type="hidden" name="action" value="void_expense">
                                                <input type="hidden" name="expense_id" value="<?= (int) $expense['id'] ?>">
                                                <label class="field">
                                                    <span>Motif obligatoire</span>
                                                    <input type="text" name="void_reason" minlength="3" maxlength="500" required>
                                                </label>
                                                <button class="button button-secondary button-small" type="submit">Confirmer l’annulation</button>
                                            </form>
                                        </details>
                                    </div>
                                <?php elseif ($expense['purchase_id'] !== null): ?>
                                    <span class="finance-immutable">Géré dans les stocks</span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
