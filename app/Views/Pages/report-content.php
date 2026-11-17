<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$dateLabel = static function (string $value): string {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date === false ? $value : $date->format('d/m/Y');
};
$formAction = 'reports.php?farm_id=' . $currentFarmId;
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $error): ?><p><?= $escape((string) $error) ?></p><?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if (is_array($flash) && isset($flash['message'])): ?>
    <div class="alert alert-success" role="status"><?= $escape((string) $flash['message']) ?></div>
<?php endif; ?>

<section class="card report-generator-card" aria-labelledby="report-generator-title">
    <div class="card-heading">
        <div>
            <h2 id="report-generator-title">Générer un rapport PDF</h2>
            <p>Le document rassemble les données enregistrées de l’élevage sur la période choisie.</p>
        </div>
    </div>
    <?php if ($flocks === []): ?>
        <p class="report-empty">Aucun élevage n’est disponible pour générer un rapport.</p>
    <?php else: ?>
        <form method="post" action="<?= $escape($formAction) ?>" class="report-form">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <label class="field">
                <span>Élevage</span>
                <select name="flock_id" required>
                    <option value="">Choisir un élevage</option>
                    <?php foreach ($flocks as $flock): ?>
                        <option value="<?= (int) $flock['id'] ?>"
                            <?= $selectedFlockId === (int) $flock['id'] ? 'selected' : '' ?>>
                            <?= $escape((string) $flock['name']) ?> · <?= $escape((string) $flock['code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>Du</span>
                <input type="date" name="from" value="<?= $escape($fromDate) ?>"
                       max="<?= $today->format('Y-m-d') ?>" required>
            </label>
            <label class="field">
                <span>Au</span>
                <input type="date" name="to" value="<?= $escape($toDate) ?>"
                       max="<?= $today->format('Y-m-d') ?>" required>
            </label>
            <label class="field report-observation">
                <span>Observations à inclure (facultatif, 3 000 caractères maximum)</span>
                <textarea name="observation" rows="4" maxlength="3000"><?= $escape($observation) ?></textarea>
            </label>
            <div class="report-form-actions">
                <button class="button button-primary" type="submit">Générer le PDF</button>
                <span>Chaque génération crée une version distincte, conservée dans votre historique privé.</span>
            </div>
        </form>
    <?php endif; ?>
</section>

<section class="dashboard-section report-history-section" aria-labelledby="report-history-title">
    <div class="dashboard-section-heading">
        <div>
            <p class="eyebrow">DOCUMENTS PRIVÉS</p>
            <h2 id="report-history-title">Historique de mes rapports</h2>
        </div>
    </div>
    <form method="get" action="reports.php" class="card report-filter-form">
        <input type="hidden" name="farm_id" value="<?= $currentFarmId ?>">
        <label class="field">
            <span>Rapports chevauchant la période</span>
            <div class="report-date-range">
                <input type="date" name="history_from" value="<?= $escape($historyFrom ?? '') ?>"
                       aria-label="Début de la période de filtre">
                <input type="date" name="history_to" value="<?= $escape($historyTo ?? '') ?>"
                       aria-label="Fin de la période de filtre">
            </div>
        </label>
        <label class="field">
            <span>Élevage</span>
            <select name="history_flock_id">
                <option value="">Tous mes élevages</option>
                <?php foreach ($flocks as $flock): ?>
                    <option value="<?= (int) $flock['id'] ?>"
                        <?= $historyFlockId === (int) $flock['id'] ? 'selected' : '' ?>>
                        <?= $escape((string) $flock['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="button button-secondary" type="submit">Filtrer</button>
    </form>

    <?php if ($history === []): ?>
        <div class="card report-empty">Aucun rapport ne correspond à ces filtres.</div>
    <?php else: ?>
        <div class="card report-history-card">
            <div class="table-scroll">
                <table class="finance-table report-history-table">
                    <thead>
                    <tr><th>Élevage</th><th>Période</th><th>Version</th><th>Généré le</th><th>Taille</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $report): ?>
                        <tr>
                            <td><strong><?= $escape((string) $report['flock_name']) ?></strong></td>
                            <td><?= $escape($dateLabel((string) $report['starts_on'])) ?> — <?= $escape($dateLabel((string) $report['ends_on'])) ?></td>
                            <td>v<?= (int) $report['version_number'] ?></td>
                            <td><?= $escape((new DateTimeImmutable((string) $report['generated_at'], $timezone))->setTimezone($timezone)->format('d/m/Y H:i')) ?></td>
                            <td><?= number_format((int) $report['file_size_bytes'] / 1024, 1, ',', ' ') ?> Ko</td>
                            <td class="report-actions">
                                <a class="button button-secondary button-small"
                                   href="reports.php?farm_id=<?= $currentFarmId ?>&amp;view_report=<?= (int) $report['id'] ?>"
                                   target="_blank" rel="noopener">
                                    Consulter
                                </a>
                                <a class="button button-secondary button-small"
                                   href="reports.php?farm_id=<?= $currentFarmId ?>&amp;download=<?= (int) $report['id'] ?>">
                                    Télécharger
                                </a>
                                <a class="button button-secondary button-small"
                                   href="reports.php?farm_id=<?= $currentFarmId ?>&amp;from=<?= rawurlencode((string) $report['starts_on']) ?>&amp;to=<?= rawurlencode((string) $report['ends_on']) ?>&amp;flock_id=<?= (int) $report['flock_id'] ?>#report-generator-title">
                                    Nouvelle version
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($history) === 100): ?>
                <p class="report-history-footnote">Les 100 rapports les plus récents correspondant aux filtres sont affichés.</p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
