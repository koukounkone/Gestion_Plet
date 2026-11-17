<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$baseUrl = 'performance.php?farm_id=' . $farmId;
$selectedFlock = null;
foreach ($flocks as $candidate) {
    if ((int) $candidate['id'] === (int) ($flockId ?? 0)) {
        $selectedFlock = $candidate;
        break;
    }
}
$inputValue = static function (array $values, string $key, mixed $fallback = ''): string {
    $value = $values[$key] ?? $fallback;
    return is_string($value) || is_numeric($value) ? (string) $value : '';
};
$formatNumber = static fn (float|int $number, int $decimals = 1): string =>
    number_format((float) $number, $decimals, ',', ' ');
$targetMetric = is_string($targetValues['metric'] ?? null) ? $targetValues['metric'] : '';
$targetMetricMaximum = match ($targetMetric) {
    'average_weight_g' => 100000,
    'daily_gain_g' => 10000,
    'laying_rate_percent' => 100,
    default => 100000,
};
$canSetOpeningStock = true;
$firstEggRecordId = null;
$firstEggRecordDate = null;
if ($flockId !== null && is_array($report) && $report['is_layer']) {
    $firstEggQuery = $pdo->prepare(
        'SELECT id, record_date FROM flock_egg_records
         WHERE farm_id = :farm_id AND flock_id = :flock_id
         ORDER BY record_date, id LIMIT 1'
    );
    $firstEggQuery->execute(['farm_id' => $farmId, 'flock_id' => $flockId]);
    $firstEgg = $firstEggQuery->fetch();
    if (is_array($firstEgg)) {
        $firstEggRecordId = (int) $firstEgg['id'];
        $firstEggRecordDate = (string) $firstEgg['record_date'];
        $eggFormDate = is_string($eggValues['record_date'] ?? null)
            ? $eggValues['record_date']
            : '';
        $canSetOpeningStock = ($editEgg !== null && (int) $editEgg['id'] === $firstEggRecordId)
            || ($editEgg === null && $eggFormDate !== '' && $eggFormDate < $firstEggRecordDate);
    }
}
$weightChartData = is_array($report) ? [
    'labels' => array_map(static fn (array $row): string => (string) $row['date'], $report['weights']),
    'weights' => array_map(static fn (array $row): float => (float) $row['average_weight_g'], $report['weights']),
    'targets' => array_map(
        static fn (array $row): ?float => $row['target_value'] === null ? null : (float) $row['target_value'],
        $report['weights']
    ),
] : ['labels' => [], 'weights' => [], 'targets' => []];
$eggChartData = is_array($report) ? [
    'labels' => array_map(static fn (array $row): string => (string) $row['record_date'], $report['eggs']),
    'rates' => array_map(
        static fn (array $row): ?float => $row['laying_rate_percent'] === null
            ? null
            : (float) $row['laying_rate_percent'],
        $report['eggs']
    ),
    'targets' => array_map(
        static fn (array $row): ?float => $row['target_value'] === null ? null : (float) $row['target_value'],
        $report['eggs']
    ),
] : ['labels' => [], 'rates' => [], 'targets' => []];
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

<div class="dashboard-data-note" role="note">
    Les graphiques utilisent les pesées dédiées et, lorsqu’il existe, le poids moyen réellement saisi
    dans le suivi quotidien (sans créer de mesure). Les objectifs sont facultatifs et ne sont jamais
    préremplis : un responsable doit consigner leur référence et leur période d’application.
</div>

<?php if ($flocks === []): ?>
    <section class="card performance-empty">
        <h2>Aucun élevage disponible</h2>
        <p>Créez d’abord un élevage pour y enregistrer des pesées et consulter ses performances.</p>
        <a class="button button-primary" href="flocks.php?farm_id=<?= $farmId ?>">Gérer les élevages</a>
    </section>
<?php else: ?>
    <form class="card performance-filter" method="get" action="performance.php">
        <input type="hidden" name="farm_id" value="<?= $farmId ?>">
        <label class="field">
            <span>Élevage</span>
            <select name="flock_id" required>
                <?php foreach ($flocks as $flock): ?>
                    <option value="<?= (int) $flock['id'] ?>" <?= (int) $flock['id'] === (int) $flockId ? 'selected' : '' ?>>
                        <?= $escape((string) $flock['name']) ?> · <?= $escape((string) $flock['code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field"><span>Du</span>
            <input type="date" name="from" value="<?= $escape($fromDate) ?>" max="<?= $today->format('Y-m-d') ?>" required>
        </label>
        <label class="field"><span>Au</span>
            <input type="date" name="to" value="<?= $escape($toDate) ?>" max="<?= $today->format('Y-m-d') ?>" required>
        </label>
        <button class="button button-primary" type="submit">Afficher la période</button>
    </form>

    <?php if (is_array($selectedFlock) && is_array($report)): ?>
        <section class="metrics-grid performance-metrics" aria-label="Indicateurs de la période">
            <article class="card stat-card">
                <div class="stat-top"><span class="stat-label">Dernier poids moyen de la période</span><span class="stat-icon">⚖</span></div>
                <strong class="stat-value"><?= $report['latest_weight'] === null
                    ? '—'
                    : $formatNumber((float) $report['latest_weight']['average_weight_g']) . ' g' ?></strong>
                <span class="stat-foot"><?= $report['latest_weight'] === null
                    ? 'Aucune pesée enregistrée sur cette période'
                    : 'Mesuré le ' . $escape((string) $report['latest_weight']['date']) ?></span>
            </article>
            <article class="card stat-card">
                <div class="stat-top"><span class="stat-label">Gain moyen entre les deux dernières mesures</span><span class="stat-icon">↗</span></div>
                <strong class="stat-value"><?= $report['daily_gain_g'] === null
                    ? '—'
                    : $formatNumber((float) $report['daily_gain_g']) . ' g/j' ?></strong>
                <span class="stat-foot"><?= $report['daily_gain_g'] === null
                    ? 'Deux dates de pesée distinctes sont nécessaires'
                    : 'Variation des moyennes divisée par les jours écoulés'
                        . ($report['daily_gain_target'] === null
                            ? ' · aucun objectif documenté'
                            : ' · objectif documenté : ' . $formatNumber((float) $report['daily_gain_target'])
                                . ' g/j ; ' . ((float) $report['daily_gain_g'] >= $report['daily_gain_target']
                                    ? 'atteint ou dépassé'
                                    : 'en dessous')) ?></span>
            </article>
            <?php if ($report['is_layer']): ?>
                <article class="card stat-card">
                    <div class="stat-top"><span class="stat-label">Collecte d’œufs / effectif vivant</span><span class="stat-icon">🥚</span></div>
                    <strong class="stat-value"><?= $report['latest_egg'] === null
                        ? '—'
                        : number_format((int) $report['latest_egg']['collected_count'], 0, ',', ' ') . ' œufs' ?></strong>
                    <span class="stat-foot"><?= $report['latest_egg'] === null
                        ? 'Aucun relevé sur cette période'
                        : ($report['latest_egg']['laying_rate_percent'] === null
                            ? 'Ratio non calculable : effectif vivant indisponible'
                            : $formatNumber((float) $report['latest_egg']['laying_rate_percent'], 2)
                                    . ' % · œufs collectés / effectif vivant · ' . $escape((string) $report['latest_egg']['record_date'])) ?></span>
                </article>
            <?php else: ?>
                <article class="card stat-card">
                    <div class="stat-top"><span class="stat-label">Pesées enregistrées</span><span class="stat-icon">▤</span></div>
                    <strong class="stat-value"><?= number_format(count($report['weights']), 0, ',', ' ') ?></strong>
                    <span class="stat-foot">Mesures observées sur la période sélectionnée</span>
                </article>
            <?php endif; ?>
        </section>

        <div class="performance-chart-grid">
            <section class="card performance-chart-card">
                <div class="card-heading">
                    <div><h2>Évolution du poids moyen</h2><p>g par animal · les objectifs apparaissent uniquement si une référence documentée couvre la date.</p></div>
                </div>
                <?php if ($report['weights'] === []): ?>
                    <div class="performance-empty-state">Aucune pesée réelle à afficher pour cette période.</div>
                <?php else: ?>
                    <div class="performance-chart-wrap">
                        <canvas data-performance-chart="weights" data-chart="<?= $encodeChart($weightChartData) ?>"
                                role="img" aria-label="Graphique de l’évolution du poids moyen en grammes"></canvas>
                    </div>
                <?php endif; ?>
            </section>

            <?php if ($report['is_layer']): ?>
                <section class="card performance-chart-card">
                    <div class="card-heading">
                        <div><h2>Collecte d’œufs / effectif vivant</h2><p>Indicateur descriptif : œufs collectés ÷ effectif vivant en fin de journée × 100. Ce n’est pas un taux de ponte par femelle.</p></div>
                    </div>
                    <?php if ($report['eggs'] === []): ?>
                        <div class="performance-empty-state">Aucun relevé de ponte réel à afficher pour cette période.</div>
                    <?php else: ?>
                        <div class="performance-chart-wrap">
                            <canvas data-performance-chart="eggs" data-chart="<?= $encodeChart($eggChartData) ?>"
                                    role="img" aria-label="Graphique des œufs collectés par sujet vivant, en pourcentage"></canvas>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </div>

        <div class="performance-form-grid">
            <?php if ($canWrite): ?>
                <section class="card performance-form-card">
                    <div class="card-heading"><div>
                        <h2><?= $editWeight === null ? 'Enregistrer une pesée' : 'Corriger la pesée' ?></h2>
                        <p>Une mesure par élevage et par date. Indiquez le poids total de l’échantillon.</p>
                    </div></div>
                    <form method="post" action="<?= $escape($baseUrl . '&flock_id=' . $flockId . '&from=' . rawurlencode($fromDate) . '&to=' . rawurlencode($toDate)) ?>" class="performance-form">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="save_weighing">
                        <input type="hidden" name="flock_id" value="<?= $flockId ?>">
                        <input type="hidden" name="record_id" value="<?= $editWeight === null ? '' : (int) $editWeight['id'] ?>">
                        <label class="field"><span>Date de pesée</span>
                            <input type="date" name="measured_on" min="<?= $escape((string) $selectedFlock['started_on']) ?>"
                                   max="<?= $today->format('Y-m-d') ?>" required value="<?= $escape($inputValue($weighingValues, 'measured_on', $today->format('Y-m-d'))) ?>">
                        </label>
                        <label class="field"><span>Animaux pesés (têtes)</span>
                            <input type="number" name="sample_size" min="1" max="10000" step="1" required
                                   value="<?= $escape($inputValue($weighingValues, 'sample_size')) ?>">
                        </label>
                        <label class="field"><span>Poids total de l’échantillon (g)</span>
                            <input type="number" name="total_weight_g" min="0.001" max="1000000000" step="0.001" required
                                   value="<?= $escape($inputValue($weighingValues, 'total_weight_g')) ?>">
                            <small>Moyenne calculée : poids total (g) ÷ nombre d’animaux pesés.</small>
                        </label>
                        <label class="field"><span>Observations (facultatif)</span>
                            <textarea name="notes" rows="2" maxlength="2000"><?= $escape($inputValue($weighingValues, 'notes')) ?></textarea>
                        </label>
                        <div class="performance-form-actions">
                            <button class="button button-primary" type="submit"><?= $editWeight === null ? 'Enregistrer la pesée' : 'Enregistrer la correction' ?></button>
                            <?php if ($editWeight !== null): ?><a class="button button-secondary" href="<?= $escape($baseUrl . '&flock_id=' . $flockId) ?>">Annuler</a><?php endif; ?>
                        </div>
                    </form>
                </section>

                <?php if ($report['is_layer']): ?>
                    <section class="card performance-form-card">
                        <div class="card-heading"><div>
                            <h2><?= $editEgg === null ? 'Relevé quotidien des œufs' : 'Corriger le relevé de ponte' ?></h2>
                            <p>Les ventes peuvent provenir du stock d’œufs des jours précédents.</p>
                        </div></div>
                        <form method="post" action="<?= $escape($baseUrl . '&flock_id=' . $flockId . '&from=' . rawurlencode($fromDate) . '&to=' . rawurlencode($toDate)) ?>" class="performance-form">
                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                            <input type="hidden" name="action" value="save_eggs">
                            <input type="hidden" name="flock_id" value="<?= $flockId ?>">
                            <input type="hidden" name="record_id" value="<?= $editEgg === null ? '' : (int) $editEgg['id'] ?>">
                            <label class="field"><span>Date du relevé</span>
                                <input type="date" name="record_date" min="<?= $escape((string) $selectedFlock['started_on']) ?>"
                                       max="<?= $today->format('Y-m-d') ?>" required value="<?= $escape($inputValue($eggValues, 'record_date', $today->format('Y-m-d'))) ?>">
                            </label>
                            <?php if ($canSetOpeningStock): ?>
                                <label class="field"><span>Stock initial avant la première journée enregistrée (œufs)</span>
                                    <input type="number" name="opening_stock_count" min="0" max="1000000" step="1"
                                           value="<?= $escape($inputValue($eggValues, 'opening_stock_count', 0)) ?>">
                                    <small>À saisir seulement si un stock existait avant le début de l’historique dans le site.</small>
                                </label>
                            <?php else: ?>
                                <input type="hidden" name="opening_stock_count" value="0">
                            <?php endif; ?>
                            <label class="field"><span>Œufs collectés (œufs)</span>
                                <input type="number" name="collected_count" min="0" max="1000000" step="1" required
                                       value="<?= $escape($inputValue($eggValues, 'collected_count')) ?>">
                            </label>
                            <label class="field"><span>Œufs cassés (œufs)</span>
                                <input type="number" name="broken_count" min="0" max="1000000" step="1" required
                                       value="<?= $escape($inputValue($eggValues, 'broken_count', 0)) ?>">
                            </label>
                            <label class="field"><span>Œufs vendus (œufs)</span>
                                <input type="number" name="sold_count" min="0" max="1000000" step="1" required
                                       value="<?= $escape($inputValue($eggValues, 'sold_count', 0)) ?>">
                            </label>
                            <label class="field"><span>Observations (facultatif)</span>
                                <textarea name="notes" rows="2" maxlength="2000"><?= $escape($inputValue($eggValues, 'notes')) ?></textarea>
                            </label>
                            <p class="performance-form-help">Les œufs cassés peuvent provenir du stock d’ouverture ou des collectes du jour. Le solde est recalculé : stock d’ouverture + collecte − œufs cassés − ventes. Une correction historique recalcule les soldes suivants ; l’enregistrement est refusé si un solde devient négatif.</p>
                            <div class="performance-form-actions">
                                <button class="button button-primary" type="submit"><?= $editEgg === null ? 'Enregistrer les œufs' : 'Enregistrer la correction' ?></button>
                                <?php if ($editEgg !== null): ?><a class="button button-secondary" href="<?= $escape($baseUrl . '&flock_id=' . $flockId) ?>">Annuler</a><?php endif; ?>
                            </div>
                        </form>
                    </section>
                <?php endif; ?>
            <?php else: ?>
                <div class="dashboard-data-note" role="note">Votre rôle permet de consulter les performances, mais pas d’ajouter ou corriger les mesures.</div>
            <?php endif; ?>
        </div>

        <?php if ($canManage): ?>
            <section class="card performance-form-card performance-target-card">
                <div class="card-heading"><div>
                    <h2><?= $editTarget === null ? 'Ajouter un objectif documenté' : 'Modifier l’objectif documenté' ?></h2>
                    <p>Les objectifs dépendent du type d’élevage, de la souche, de la période et des références locales : aucun chiffre n’est proposé par défaut.</p>
                </div></div>
                <form method="post" action="<?= $escape($baseUrl . '&flock_id=' . $flockId . '&from=' . rawurlencode($fromDate) . '&to=' . rawurlencode($toDate)) ?>" class="performance-form performance-target-form">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="save_target">
                    <input type="hidden" name="flock_id" value="<?= $flockId ?>">
                    <input type="hidden" name="record_id" value="<?= $editTarget === null ? '' : (int) $editTarget['id'] ?>">
                    <label class="field"><span>Indicateur</span>
                        <select name="metric" required>
                            <option value="average_weight_g" <?= $targetValues['metric'] === 'average_weight_g' ? 'selected' : '' ?>>Poids moyen (g/tête)</option>
                            <option value="daily_gain_g" <?= $targetValues['metric'] === 'daily_gain_g' ? 'selected' : '' ?>>Gain moyen (g/jour)</option>
                            <?php if ($report['is_layer']): ?>
                                <option value="laying_rate_percent" <?= $targetMetric === 'laying_rate_percent' ? 'selected' : '' ?>>Collecte d’œufs / effectif vivant (%)</option>
                            <?php endif; ?>
                        </select>
                    </label>
                    <label class="field"><span>Valeur cible</span>
                        <input type="number" name="target_value" min="0.001" step="0.001" max="<?= $targetMetricMaximum ?>"
                               value="<?= $escape($inputValue($targetValues, 'target_value')) ?>" required>
                    </label>
                    <label class="field"><span>Applicable à partir du</span>
                        <input type="date" name="valid_from" min="<?= $escape((string) $selectedFlock['started_on']) ?>" max="<?= $today->format('Y-m-d') ?>"
                               value="<?= $escape($inputValue($targetValues, 'valid_from', $today->format('Y-m-d'))) ?>" required>
                    </label>
                    <label class="field"><span>Jusqu’au (facultatif)</span>
                        <input type="date" name="valid_until" min="<?= $escape((string) $selectedFlock['started_on']) ?>"
                               value="<?= $escape($inputValue($targetValues, 'valid_until')) ?>">
                    </label>
                    <label class="field"><span>Titre / origine de la référence</span>
                        <input type="text" name="source_title" maxlength="255" required value="<?= $escape($inputValue($targetValues, 'source_title')) ?>">
                    </label>
                    <label class="field"><span>Lien source (facultatif)</span>
                        <input type="url" name="source_url" maxlength="2048" placeholder="https://…" value="<?= $escape($inputValue($targetValues, 'source_url')) ?>">
                    </label>
                    <label class="field field-wide"><span>Référence et contexte</span>
                        <textarea name="source_notes" rows="3" minlength="10" maxlength="4000" required><?= $escape($inputValue($targetValues, 'source_notes')) ?></textarea>
                        <small>Indiquez la source consultée et son applicabilité à cette bande. N’enregistrez pas une prescription comme objectif universel.</small>
                    </label>
                    <div class="performance-form-actions field-wide">
                        <button class="button button-primary" type="submit"><?= $editTarget === null ? 'Enregistrer l’objectif' : 'Mettre à jour l’objectif' ?></button>
                        <?php if ($editTarget !== null): ?><a class="button button-secondary" href="<?= $escape($baseUrl . '&flock_id=' . $flockId) ?>">Annuler</a><?php endif; ?>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="card table-card performance-table-card">
            <div class="card-heading"><div><h2>Historique des pesées</h2><p>La moyenne est calculée pour chaque échantillon ; les valeurs issues des anciennes fiches quotidiennes restent identifiées.</p></div></div>
            <?php if ($report['weights'] === []): ?>
                <div class="performance-empty-state">Aucune pesée n’a été enregistrée pour cette période. Les graphiques restent vides.</div>
            <?php else: ?>
                <div class="table-scroll" tabindex="0" role="region" aria-label="Historique des pesées">
                    <table class="data-table">
                        <thead><tr><th>Date</th><th>Échantillon</th><th>Poids moyen</th><th>Objectif applicable</th><th>Origine</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach (array_reverse($report['weights']) as $weight): ?>
                            <tr>
                                <td><?= $escape((string) $weight['date']) ?></td>
                                <td><?= $weight['sample_size'] === null ? 'Non précisé (historique)' : number_format((int) $weight['sample_size'], 0, ',', ' ') . ' têtes' ?></td>
                                <td><strong><?= $formatNumber((float) $weight['average_weight_g']) ?> g/tête</strong></td>
                                <td><?= $weight['target_value'] === null ? 'Aucun objectif documenté' : $formatNumber((float) $weight['target_value']) . ' g/tête' ?></td>
                                <td><?= $weight['source'] === 'daily' ? 'Suivi quotidien historique' : 'Pesée détaillée' ?></td>
                                <td><?php if ($canWrite && $weight['source'] === 'weighing'): ?>
                                    <a class="table-action" href="<?= $escape($baseUrl . '&flock_id=' . $flockId . '&edit_weight=' . (int) $weight['id'] . '&from=' . rawurlencode($fromDate) . '&to=' . rawurlencode($toDate)) ?>">Corriger</a>
                                <?php else: ?>—<?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($report['is_layer']): ?>
            <section class="card table-card performance-table-card">
                <div class="card-heading"><div><h2>Historique des œufs et du stock</h2><p>Un taux descriptif ne remplace pas un taux de ponte par femelle, car le sexe et l’effectif productif ne sont pas enregistrés ici.</p></div></div>
                <?php if ($report['eggs'] === []): ?>
                    <div class="performance-empty-state">Aucun relevé d’œufs n’a été enregistré pour cette période. Aucune production n’est estimée.</div>
                <?php else: ?>
                    <div class="table-scroll" tabindex="0" role="region" aria-label="Historique des œufs">
                        <table class="data-table">
                            <thead><tr><th>Date</th><th>Collectés</th><th>Cassés</th><th>Vendus</th><th>Stock fin</th><th>Effectif vivant fin de jour</th><th>Œufs / sujet vivant</th><th>Objectif</th><th>Action</th></tr></thead>
                            <tbody>
                            <?php foreach (array_reverse($report['eggs']) as $egg): ?>
                                <tr>
                                    <td><?= $escape((string) $egg['record_date']) ?></td>
                                    <td><?= number_format((int) $egg['collected_count'], 0, ',', ' ') ?></td>
                                    <td><?= number_format((int) $egg['broken_count'], 0, ',', ' ') ?></td>
                                    <td><?= number_format((int) $egg['sold_count'], 0, ',', ' ') ?></td>
                                    <td><strong><?= number_format((int) $egg['closing_stock_count'], 0, ',', ' ') ?></strong></td>
                                    <td><?= number_format((int) $egg['live_count'], 0, ',', ' ') ?></td>
                                    <td><?= $egg['laying_rate_percent'] === null ? '—' : $formatNumber((float) $egg['laying_rate_percent'], 2) . ' %' ?></td>
                                    <td><?= $egg['target_value'] === null ? '—' : $formatNumber((float) $egg['target_value'], 2) . ' %' ?></td>
                                    <td><?php if ($canWrite): ?>
                                        <a class="table-action" href="<?= $escape($baseUrl . '&flock_id=' . $flockId . '&edit_eggs=' . (int) $egg['id'] . '&from=' . rawurlencode($fromDate) . '&to=' . rawurlencode($toDate)) ?>">Corriger</a>
                                    <?php else: ?>—<?php endif; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <section class="card table-card performance-table-card">
            <div class="card-heading"><div>
                <h2>Objectifs et références</h2>
                <p>Unités : poids moyen en g/tête ; gain en g/jour ; œufs collectés par sujet vivant en %.</p>
            </div></div>
            <?php if ($report['targets'] === []): ?>
                <div class="performance-empty-state">Aucun objectif documenté. Les comparaisons sont désactivées jusqu’à la saisie d’une référence vérifiable.</div>
            <?php else: ?>
                <div class="table-scroll" tabindex="0" role="region" aria-label="Objectifs documentés">
                    <table class="data-table">
                        <thead><tr><th>Indicateur</th><th>Valeur cible</th><th>Période</th><th>Référence</th><th>Contexte</th><th>Action</th></tr></thead>
                        <tbody>
                        <?php foreach ($report['targets'] as $target): ?>
                            <tr>
                                <td><?= $escape($report['metrics'][$target['metric']] ?? (string) $target['metric']) ?></td>
                                <td><?= $formatNumber((float) $target['target_value'], 3) ?> <?= $target['metric'] === 'laying_rate_percent' ? '%' : ($target['metric'] === 'daily_gain_g' ? 'g/j' : 'g/tête') ?></td>
                                <td><?= $escape((string) $target['valid_from']) ?> — <?= $target['valid_until'] === null ? 'sans date de fin' : $escape((string) $target['valid_until']) ?></td>
                                <td><?php if (is_string($target['source_url']) && $target['source_url'] !== ''): ?>
                                    <a href="<?= $escape($target['source_url']) ?>" target="_blank" rel="noopener noreferrer"><?= $escape((string) $target['source_title']) ?></a>
                                <?php else: ?><?= $escape((string) $target['source_title']) ?><?php endif; ?></td>
                                <td><?= $escape((string) $target['source_notes']) ?></td>
                                <td><?php if ($canManage): ?>
                                    <a class="table-action" href="<?= $escape($baseUrl . '&flock_id=' . $flockId . '&edit_target=' . (int) $target['id'] . '&from=' . rawurlencode($fromDate) . '&to=' . rawurlencode($toDate)) ?>">Modifier</a>
                                <?php else: ?>—<?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>
