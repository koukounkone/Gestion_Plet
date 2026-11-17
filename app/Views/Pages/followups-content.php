<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$inputValue = static function (string $key, mixed $fallback = '') use ($formValues): string {
    $value = $formValues[$key] ?? $fallback;
    return is_string($value) || is_numeric($value) ? (string) $value : '';
};
$selectedFlock = null;
foreach ($flocks as $candidate) {
    if ((int) $candidate['id'] === (int) ($flockId ?? 0)) {
        $selectedFlock = $candidate;
        break;
    }
}
$formDate = $inputValue('followup_date', $today->format('Y-m-d'));
$parsedFormDate = DateTimeImmutable::createFromFormat('!Y-m-d', $formDate, $farmTimezone);
$openingCount = is_array($selectedFlock)
    && $parsedFormDate !== false
    && $parsedFormDate->format('Y-m-d') === $formDate
    ? \MonElevage\Followups\DailyFollowupService::openingCount(
        $pdo,
        $userId,
        $farmId,
        (int) $selectedFlock['id'],
        $formDate,
        $farmTimezone
    )
    : null;
$canEditSelected = $canWrite
    && is_array($selectedFlock)
    && $selectedFlock['status'] !== 'closed';
$baseUrl = 'followups.php?farm_id=' . $farmId;
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
    Les mouvements saisis ici alimentent le calcul de l’effectif. Les animaux malades restent
    dans l’effectif vivant. Si une catégorie de mouvements est déjà inscrite séparément au
    registre, indiquez son total journalier exact : elle ne sera pas comptée une seconde fois.
    Cela s’applique notamment aux ventes déjà enregistrées dans le module Ventes.
</div>

<?php if ($canWrite && $flocks !== []): ?>
    <section class="card followup-form-card" aria-labelledby="followup-form-title">
        <div class="card-heading">
            <div>
                <h2 id="followup-form-title"><?= $editId === null ? 'Enregistrer le suivi du jour' : 'Corriger le suivi enregistré' ?></h2>
                <p>Une seule fiche par élevage et par date. Vous pouvez corriger une fiche depuis l’historique.</p>
            </div>
            <?php if ($editId !== null): ?>
                <a class="button button-secondary" href="<?= $escape($baseUrl . '&flock_id=' . (int) $flockId) ?>">Nouveau suivi</a>
            <?php endif; ?>
        </div>
        <form class="followup-form" method="post" action="<?= $escape($baseUrl . ($editId !== null ? '&edit=' . $editId : '')) ?>">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="followup_id" value="<?= $editId === null ? '' : $editId ?>">
            <?php if ($editId !== null): ?>
                <input type="hidden" name="flock_id" value="<?= (int) $flockId ?>">
                <input type="hidden" name="followup_date" value="<?= $escape($inputValue('followup_date')) ?>">
            <?php endif; ?>
            <div class="followup-form-grid">
                <?php if ($editId === null): ?>
                    <label class="field">
                        <span>Élevage</span>
                        <select name="flock_id" required>
                            <option value="">Choisir un élevage</option>
                            <?php foreach ($flocks as $item): ?>
                                <?php if (in_array($item['status'], ['active', 'paused'], true)): ?>
                                    <option value="<?= (int) $item['id'] ?>" <?= (int) $item['id'] === (int) ($flockId ?? 0) ? 'selected' : '' ?>>
                                        <?= $escape((string) $item['name']) ?> · <?= $escape((string) $item['code']) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field">
                        <span>Date du suivi</span>
                        <input type="date" name="followup_date" max="<?= $today->format('Y-m-d') ?>"
                               value="<?= $escape($inputValue('followup_date', $today->format('Y-m-d'))) ?>" required>
                    </label>
                <?php else: ?>
                    <div class="followup-readonly-field"><span>Élevage</span><strong><?= $escape((string) $selectedFlock['name']) ?></strong></div>
                    <div class="followup-readonly-field"><span>Date du suivi</span>                    <strong><?= $escape($formDate) ?></strong></div>
                <?php endif; ?>
                <div class="followup-readonly-field">
                    <span>Effectif initial du jour</span>
                    <strong><?= $openingCount === null ? '—' : number_format($openingCount, 0, ',', ' ') . ' animaux' ?></strong>
                </div>
            </div>

            <fieldset class="followup-fieldset">
                <legend>Mouvements du jour</legend>
                <div class="followup-form-grid followup-count-grid">
                    <?php foreach ([
                        'entries_count' => ['Entrées d’animaux', 'Animaux ajoutés à l’élevage'],
                        'sick_count' => ['Malades observés', 'Restent comptés vivants'],
                        'mortality_count' => ['Morts', 'Sorties par mortalité'],
                        'sales_count' => ['Ventes', 'Ne pas recompter les ventes déjà enregistrées'],
                        'other_exits_count' => ['Autres sorties', 'Pertes ou sorties hors ventes'],
                    ] as $field => [$label, $help]): ?>
                        <label class="field">
                            <span><?= $escape($label) ?></span>
                            <input type="number" name="<?= $escape($field) ?>" min="0" step="1" max="2147483647"
                                   value="<?= $escape($inputValue($field, 0)) ?>" required>
                            <small><?= $escape($help) ?></small>
                        </label>
                    <?php endforeach; ?>
                </div>
            </fieldset>

            <fieldset class="followup-fieldset">
                <legend>Mesures facultatives</legend>
                <div class="followup-form-grid">
                    <label class="field"><span>Aliment consommé (kg)</span>
                        <input type="number" name="feed_consumed_kg" min="0" max="1000000" step="0.001"
                               value="<?= $escape($inputValue('feed_consumed_kg')) ?>" placeholder="Non mesuré">
                    </label>
                    <label class="field"><span>Eau consommée (litres)</span>
                        <input type="number" name="water_consumed_liters" min="0" max="1000000" step="0.001"
                               value="<?= $escape($inputValue('water_consumed_liters')) ?>" placeholder="Non mesurée">
                    </label>
                    <label class="field"><span>Température (°C)</span>
                        <input type="number" name="temperature_celsius" min="-50" max="70" step="0.01"
                               value="<?= $escape($inputValue('temperature_celsius')) ?>" placeholder="Non mesurée">
                    </label>
                    <label class="field"><span>Humidité (%)</span>
                        <input type="number" name="humidity_percent" min="0" max="100" step="0.01"
                               value="<?= $escape($inputValue('humidity_percent')) ?>" placeholder="Non mesurée">
                    </label>
                    <label class="field"><span>Poids moyen (g)</span>
                        <input type="number" name="average_weight_g" min="0.01" max="100000" step="0.01"
                               value="<?= $escape($inputValue('average_weight_g')) ?>" placeholder="Non mesuré">
                    </label>
                </div>
            </fieldset>
            <label class="field followup-notes-field">
                <span>Observations</span>
                <textarea name="notes" rows="4" maxlength="5000" placeholder="Observations utiles, sans diagnostic médical non confirmé"><?= $escape($inputValue('notes')) ?></textarea>
            </label>
            <?php if ($canEditSelected): ?>
                <div class="followup-actions">
                    <button class="button button-primary" type="submit">
                        <?= $editId === null ? 'Enregistrer le suivi' : 'Enregistrer les corrections' ?>
                    </button>
                </div>
            <?php else: ?>
                <p class="followup-readonly-notice">Cet élevage est archivé ou n’est pas sélectionné ; le formulaire ne peut pas être enregistré.</p>
            <?php endif; ?>
        </form>
    </section>
<?php elseif ($flocks === []): ?>
    <section class="card dashboard-empty-farms">
        <span class="empty-icon" aria-hidden="true">♧</span>
        <h2>Aucun élevage enregistré</h2>
        <p>Créez un élevage avant d’ajouter des observations quotidiennes.</p>
        <a class="button button-secondary" href="flocks.php?farm_id=<?= $farmId ?>">Consulter les élevages</a>
    </section>
<?php endif; ?>

<section class="card followup-history-card" aria-labelledby="followup-history-title">
    <div class="card-heading">
        <div><h2 id="followup-history-title">Historique des suivis</h2><p><?= count($history) ?> fiche(s) affichée(s), maximum 500</p></div>
    </div>
    <form class="followup-filter-form" method="get" action="followups.php">
        <input type="hidden" name="farm_id" value="<?= $farmId ?>">
        <label class="field"><span>Élevage</span>
            <select name="flock_id">
                <option value="">Tous les élevages</option>
                <?php foreach ($flocks as $item): ?>
                    <option value="<?= (int) $item['id'] ?>" <?= (int) $item['id'] === (int) ($flockId ?? 0) ? 'selected' : '' ?>>
                        <?= $escape((string) $item['name']) ?> · <?= $escape((string) $item['code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="field"><span>Du</span><input type="date" name="from" value="<?= $escape($fromDate) ?>"></label>
        <label class="field"><span>Au</span><input type="date" name="to" value="<?= $escape($toDate) ?>"></label>
        <button class="button button-secondary" type="submit">Filtrer</button>
    </form>
    <?php if ($history === []): ?>
        <div class="dashboard-empty followup-empty">
            <h3>Aucun suivi ne correspond aux filtres</h3>
            <p>Choisissez un autre élevage ou une autre période, ou enregistrez le premier suivi.</p>
        </div>
    <?php else: ?>
        <div class="table-scroll" tabindex="0" aria-label="Historique des suivis quotidiens">
            <table class="data-table followup-table">
                <thead><tr>
                    <th>Date</th><th>Élevage</th><th>Effectif initial</th><th>Entrées</th>
                    <th>Malades</th><th>Morts</th><th>Ventes</th><th>Autres sorties</th>
                    <th>Effectif final calculé</th><th>Mesures</th><th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($history as $row): ?>
                    <tr>
                        <td><?= $escape((new DateTimeImmutable((string) $row['followup_date']))->format('d/m/Y')) ?></td>
                        <td><strong><?= $escape((string) $row['flock_name']) ?></strong><small><?= $escape((string) $row['flock_code']) ?></small></td>
                        <td><?= number_format((int) $row['opening_count'], 0, ',', ' ') ?></td>
                        <td><?= number_format((int) $row['entries_count'], 0, ',', ' ') ?></td>
                        <td><?= number_format((int) $row['sick_count'], 0, ',', ' ') ?></td>
                        <td><?= number_format((int) $row['mortality_count'], 0, ',', ' ') ?></td>
                        <td><?= number_format((int) $row['sales_count'], 0, ',', ' ') ?></td>
                        <td><?= number_format((int) $row['other_exits_count'], 0, ',', ' ') ?></td>
                        <td><strong><?= number_format((int) ($row['observed_count'] ?? 0), 0, ',', ' ') ?></strong></td>
                        <td>
                            <?= $row['feed_consumed_kg'] === null ? '' : 'Aliment ' . $escape((string) $row['feed_consumed_kg']) . ' kg · ' ?>
                            <?= $row['water_consumed_liters'] === null ? '' : 'Eau ' . $escape((string) $row['water_consumed_liters']) . ' L' ?>
                            <?= $row['average_weight_g'] === null ? '' : ' · Poids ' . $escape((string) $row['average_weight_g']) . ' g' ?>
                            <?= $row['temperature_celsius'] === null ? '' : ' · ' . $escape((string) $row['temperature_celsius']) . ' °C' ?>
                            <?= $row['humidity_percent'] === null ? '' : ' · ' . $escape((string) $row['humidity_percent']) . ' %' ?>
                            <?php if ($row['notes'] !== null && $row['notes'] !== ''): ?><small><?= $escape((string) $row['notes']) ?></small><?php endif; ?>
                        </td>
                        <td>
                            <?php if ($canWrite && $row['flock_status'] !== 'closed'): ?>
                                <a class="button button-secondary button-small"
                                   href="<?= $escape($baseUrl . '&edit=' . (int) $row['id']) ?>">Corriger</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
