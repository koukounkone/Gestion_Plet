<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$formatNumber = static fn (int|float $value): string => number_format(
    $value,
    0,
    ',',
    ' '
);
$formatMoney = static function (string|float $value): string {
    $amount = (string) $value;
    [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '00');
    $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $whole) ?? $whole;
    return $whole . ',' . str_pad(substr($fraction, 0, 2), 2, '0');
};
$formatDate = static fn (DateTimeInterface $value): string => $value->format('d/m/Y');
$poultryLabels = [
    'chicken' => 'Poulet',
    'turkey' => 'Dinde',
    'duck' => 'Canard',
    'other' => 'Autre volaille',
];
$productionLabels = [
    'meat' => 'Chair',
    'eggs' => 'Œufs',
    'breeding' => 'Reproduction',
    'other' => 'Autre production',
];
$activityLabels = [
    'active' => 'En cours',
    'paused' => 'En pause',
];
$flockTypeLabels = \MonElevage\Flocks\FlockService::types();
?>
<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $error): ?>
            <p><?= $escape((string) $error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if (is_array($flash) && isset($flash['message'])): ?>
    <div class="alert alert-success" role="status">
        <?= $escape((string) $flash['message']) ?>
    </div>
<?php endif; ?>

<?php if (!is_array($dashboard)): ?>
    <section class="card dashboard-empty-farms">
        <span class="empty-icon" aria-hidden="true">+</span>
        <h2>Aucune exploitation active</h2>
        <p>Ce compte ne possède actuellement aucune adhésion active à afficher.</p>
        <a class="button button-secondary" href="profile.php">Consulter mon profil</a>
    </section>
<?php else: ?>
    <div class="dashboard-data-note" role="note">
        Les chiffres proviennent des opérations enregistrées dans cette exploitation.
        La progression reflète les étapes enregistrées et terminées. Les dates de vente restent des
        estimations configurables, pas des mesures de croissance ni des conseils zootechniques.
    </div>

    <section class="metrics-grid dashboard-metrics" aria-label="Indicateurs de l’exploitation">
        <?php if ($dashboard['can_view_flocks'] ?? true): ?>
        <article class="card stat-card">
            <div class="stat-top">
                <span class="stat-label">Élevages en cours</span>
                <span class="stat-icon" aria-hidden="true">♧</span>
            </div>
            <strong class="stat-value"><?= $formatNumber($dashboard['summary']['ongoing_flock_count']) ?></strong>
            <span class="stat-foot">Élevages actifs ou en pause</span>
        </article>
        <article class="card stat-card">
            <div class="stat-top">
                <span class="stat-label">Effectifs vivants</span>
                <span class="stat-icon" aria-hidden="true">↗</span>
            </div>
            <strong class="stat-value"><?= $formatNumber($dashboard['summary']['live_count']) ?></strong>
            <span class="stat-foot">Calculés depuis les mouvements enregistrés</span>
        </article>
        <article class="card stat-card">
            <div class="stat-top">
                <span class="stat-label">Mortalités enregistrées</span>
                <span class="stat-icon stat-icon-danger" aria-hidden="true">!</span>
            </div>
            <strong class="stat-value"><?= $formatNumber($dashboard['summary']['mortality_count']) ?></strong>
            <span class="stat-foot">Sur les élevages en cours, hors corrections</span>
        </article>
        <article class="card stat-card">
            <div class="stat-top">
                <span class="stat-label">Maladies signalées</span>
                <span class="stat-icon stat-icon-warning" aria-hidden="true">i</span>
            </div>
            <strong class="stat-value"><?= $formatNumber($dashboard['summary']['latest_sick_count']) ?></strong>
            <span class="stat-foot">Signalements du dernier suivi par élevage ; ce n’est pas un diagnostic</span>
        </article>
        <?php endif; ?>
        <?php if ($dashboard['financials'] !== null): ?>
            <article class="card stat-card stat-card-financial">
                <div class="stat-top">
                    <span class="stat-label">Dépenses cumulées</span>
                    <span class="stat-icon" aria-hidden="true">−</span>
                </div>
                <?php if ($dashboard['financials']['expenses'] === []): ?>
                    <strong class="stat-value">0</strong>
                    <span class="stat-foot">Aucune dépense comptabilisée</span>
                <?php else: ?>
                    <?php foreach ($dashboard['financials']['expenses'] as $expense): ?>
                        <strong class="stat-value stat-value-money">
                            <?= $formatMoney($expense['total_amount']) ?>
                            <span><?= $escape($expense['currency_code']) ?></span>
                        </strong>
                    <?php endforeach; ?>
                    <span class="stat-foot">
                        <?= $formatNumber(array_sum(array_column(
                            $dashboard['financials']['expenses'],
                            'expense_count'
                        ))) ?> dépense(s) comptabilisée(s), devises séparées
                    </span>
                <?php endif; ?>
            </article>
            <article class="card stat-card stat-card-financial">
                <div class="stat-top">
                    <span class="stat-label">Budget restant</span>
                    <span class="stat-icon" aria-hidden="true">◷</span>
                </div>
                <?php if (($dashboard['financials']['previsional_budgets'] ?? []) !== []): ?>
                    <?php $financialTypeLabels = [
                        'cash' => 'Trésorerie',
                        'consumed' => 'Charges consommées',
                        'investment' => 'Investissements réutilisables',
                    ]; ?>
                    <?php foreach ($dashboard['financials']['previsional_budgets'] as $budget): ?>
                        <strong class="stat-value stat-value-text"><?= $escape($budget['name']) ?></strong>
                        <span class="stat-foot">
                            <?= $escape($budget['starts_on']) ?> — <?= $escape($budget['ends_on']) ?>
                            · <?= $escape($budget['flock_name'] ?? 'Toute l’exploitation') ?>
                        </span>
                        <?php foreach ($budget['totals'] as $type => $totals): ?>
                            <span class="stat-foot">
                                <?= $escape($financialTypeLabels[$type] ?? $type) ?> :
                                reste <?= $formatMoney($totals['remaining']) ?> FCFA
                                sur <?= $formatMoney($totals['planned']) ?> FCFA
                                (réalisé <?= $formatMoney($totals['actual']) ?> FCFA)
                            </span>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                <?php elseif (!$dashboard['financials']['budget_available']): ?>
                    <strong class="stat-value stat-value-text">Non défini</strong>
                    <span class="stat-foot">Aucun budget d’élevage actif ou en pause n’a été défini</span>
                <?php else: ?>
                    <?php foreach ($dashboard['financials']['budgets'] as $budget): ?>
                        <strong class="stat-value stat-value-money">
                            <?= $formatMoney($budget['remaining_amount']) ?>
                            <span><?= $escape($budget['currency_code']) ?></span>
                        </strong>
                        <span class="stat-foot">
                            Reste sur <?= $formatMoney($budget['total_budget']) ?> <?= $escape($budget['currency_code']) ?> de budget
                            (<?= $formatNumber($budget['flock_count']) ?> élevage(s), dépenses imputées dans la même devise)
                        </span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </article>
        <?php endif; ?>
    </section>

    <?php if ($dashboard['can_view_flocks'] ?? true): ?>
    <section class="dashboard-columns dashboard-chart-row">
        <article class="card chart-card dashboard-mortality-chart" aria-labelledby="mortality-chart-title">
            <div class="card-heading">
                <div>
                    <h2 id="mortality-chart-title">Mortalités enregistrées</h2>
                    <p>Événements réellement saisis au cours des six derniers mois</p>
                </div>
                <span class="period-chip">6 mois</span>
            </div>
            <?php if ($dashboard['mortality_trend'] === []): ?>
                <p class="dashboard-empty chart-empty">
                    Aucune mortalité enregistrée sur la période. Cela ne signifie pas qu’une
                    absence de saisie vaut absence de mortalité.
                </p>
            <?php else: ?>
                <div class="mortality-bars" role="img" aria-label="Nombre de mortalités enregistrées par mois">
                    <?php $maxMortality = max($dashboard['mortality_trend']); ?>
                    <?php foreach ($dashboard['mortality_trend'] as $month => $count): ?>
                        <?php
                        $monthDate = DateTimeImmutable::createFromFormat('!Y-m', $month);
                        $monthLabel = $monthDate === false
                            ? $month
                            : $monthDate->format('m/Y');
                        $barHeight = max(5, (int) round($count * 100 / $maxMortality));
                        ?>
                        <div class="mortality-bar-column">
                            <strong><?= $formatNumber($count) ?></strong>
                            <svg class="mortality-bar" viewBox="0 0 25 100" role="img"
                                 aria-label="<?= $formatNumber($count) ?> mortalité(s) en <?= $escape($monthLabel) ?>">
                                <rect x="0" y="<?= 100 - $barHeight ?>" width="25" height="<?= $barHeight ?>" rx="5"></rect>
                            </svg>
                            <small><?= $escape($monthLabel) ?></small>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>

        <article class="card dashboard-alert-card" id="alerts">
            <div class="card-heading">
                <div>
                    <h2>Alertes & données manquantes</h2>
                    <p>Points à vérifier pour cette exploitation</p>
                </div>
            </div>
            <ul class="dashboard-alert-list">
                <?php foreach ($dashboard['cycle_alerts'] as $alert): ?>
                    <li class="<?= !empty($alert['data_inconsistency']) ? 'is-danger' : 'is-warning' ?>">
                        <span aria-hidden="true"><?= !empty($alert['data_inconsistency']) ? '!' : '◷' ?></span>
                        <div>
                            <strong>
                                <?= !empty($alert['data_inconsistency'])
                                    ? 'Effectif à vérifier'
                                    : 'Durée estimative à configurer' ?>
                            </strong>
                            <small>
                                <?= $escape($alert['flock_name']) ?>
                                <?php if (isset($alert['observed_count'])): ?>
                                    · observation <?= $formatNumber($alert['observed_count']) ?>,
                                    mouvements <?= $formatNumber($alert['movement_count']) ?>
                                <?php elseif (isset($alert['movement_count'])): ?>
                                    · total de mouvements <?= $formatNumber($alert['movement_count']) ?>
                                <?php endif; ?>
                            </small>
                        </div>
                    </li>
                <?php endforeach; ?>
                <?php foreach ($dashboard['forecast_dates'] as $flock): ?>
                    <?php if ($flock['is_overdue']): ?>
                        <li class="is-warning">
                            <span aria-hidden="true">!</span>
                            <div>
                                <strong>Échéance estimée dépassée</strong>
                                <small><?= $escape($flock['name']) ?> · <?= $formatDate($flock['expected_sale_date']) ?></small>
                            </div>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>
                <li class="is-info">
                    <span aria-hidden="true">i</span>
                    <div>
                        <strong>Signalements de maladie non disponibles</strong>
                        <small>Les notes libres ne sont pas interprétées comme un diagnostic.</small>
                    </div>
                </li>
                <?php if ($dashboard['financials'] !== null): ?>
                    <li class="is-info">
                        <span aria-hidden="true">i</span>
                        <div>
                            <strong>Budgets d’élevage non définis</strong>
                            <small>Ajoutez un budget sur une fiche d’élevage pour suivre le montant restant.</small>
                        </div>
                    </li>
                <?php endif; ?>
            </ul>
        </article>
    </section>

    <section class="dashboard-section" id="flocks">
        <div class="dashboard-section-heading">
            <div>
                <p class="eyebrow">SUIVI DES BANDES</p>
                <h2>Élevages en cours</h2>
                <p>Progression fondée sur les étapes réellement créées et terminées pour chaque élevage.</p>
            </div>
            <div class="dashboard-flock-actions">
                <span class="record-count"><?= $formatNumber(count($dashboard['flocks'])) ?> élevage(s)</span>
                <a class="button button-secondary" href="flocks.php?farm_id=<?= (int) $dashboard['farm']['id'] ?>">Gérer les élevages</a>
                <?php if ($dashboard['can_manage_cycles']): ?>
                    <a class="button button-primary" href="flocks.php?farm_id=<?= (int) $dashboard['farm']['id'] ?>&amp;view=new">Créer un élevage</a>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($dashboard['flocks'] === []): ?>
            <div class="card dashboard-empty">
                <span class="empty-icon" aria-hidden="true">♧</span>
                <h3>Aucun élevage en cours</h3>
                <p>Les élevages actifs ou en pause apparaîtront ici dès qu’ils seront enregistrés.</p>
            </div>
        <?php else: ?>
            <div class="flock-card-grid">
                <?php foreach ($dashboard['flocks'] as $flock): ?>
                    <?php
                    $currentStep = 'Aucune étape en cours';
                    foreach ($flock['steps'] as $step) {
                        if ($step['state'] === 'current') {
                            $currentStep = $step['label'];
                            break;
                        }
                    }
                    ?>
                    <article class="card flock-dashboard-card">
                        <div class="flock-card-heading">
                            <div class="flock-card-title">
                                <span class="flock-mark" aria-hidden="true">♧</span>
                                <div>
                                    <h3><?= $escape($flock['name']) ?></h3>
                                    <p><?= $escape($flock['code']) ?> ·
                                                        <?= $escape($flockTypeLabels[$flock['flock_type']] ?? 'Volaille') ?>
                                                    </p>
                                </div>
                            </div>
                            <span class="badge <?= $flock['status'] === 'paused' ? 'badge-warning' : 'badge-success' ?>">
                                <?= $escape($activityLabels[$flock['status']] ?? $flock['status']) ?>
                            </span>
                        </div>
                        <div class="flock-metrics">
                            <div><strong><?= $formatNumber($flock['live_count']) ?></strong><span>effectif calculé</span></div>
                            <div><strong><?= $formatNumber($flock['mortality_count']) ?></strong><span>mortalités nettes enregistrées</span></div>
                            <div><strong><?= $flock['latest_sick_count'] === null ? '—' : $formatNumber($flock['latest_sick_count']) ?></strong><span>malades au dernier suivi<?= $flock['latest_followup_date'] === null ? '' : ' · ' . $escape((new DateTimeImmutable($flock['latest_followup_date']))->format('d/m')) ?></span></div>
                            <div><strong><?= $formatNumber($flock['age_days']) ?> j</strong><span>depuis le démarrage</span></div>
                        </div>
                        <?php if ($flock['progress'] !== null): ?>
                            <div class="flock-progress-heading">
                                <span>Étapes terminées : <?= $formatNumber($flock['completed_stage_count']) ?> / <?= $formatNumber($flock['applicable_stage_count']) ?> · en cours : <strong><?= $escape($currentStep) ?></strong></span>
                                <strong><?= $formatNumber($flock['progress']) ?> %</strong>
                            </div>
                            <progress class="flock-progress-track"
                                      aria-label="Progression réelle des étapes de <?= $escape($flock['name']) ?>"
                                      max="100" value="<?= $flock['progress'] ?>"><?= $flock['progress'] ?> %</progress>
                            <ol class="flock-stage-list">
                                <?php foreach ($flock['steps'] as $step): ?>
                                    <li class="stage-<?= $escape($step['state']) ?><?= $step['overdue'] ? ' stage-overdue' : '' ?>">
                                        <span class="stage-indicator" aria-hidden="true">
                                            <?= $step['state'] === 'completed' ? '✓' : ($step['overdue'] ? '!' : ($step['state'] === 'current' ? '•' : '')) ?>
                                        </span>
                                        <span><?= $escape($step['label']) ?> · <?= $formatDate($step['date']) ?><?= $step['overdue'] ? ' · en retard' : '' ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ol>
                        <?php else: ?>
                            <div class="flock-estimate-empty">
                                <strong>Progression non disponible</strong>
                                <span>Aucune étape enregistrée. Ajoutez les étapes applicables à cet élevage.</span>
                            </div>
                        <?php endif; ?>
                        <?php if ($flock['status'] === 'active' && $flock['expected_sale_date'] !== null): ?>
                            <div class="flock-forecast">
                                <span>Date de vente estimée · distincte de la progression</span>
                                <strong class="<?= $flock['is_overdue'] ? 'text-warning' : '' ?>">
                                    <?= $formatDate($flock['expected_sale_date']) ?>
                                    <?= $flock['is_overdue'] ? ' · estimation dépassée' : '' ?>
                                </strong>
                            </div>
                        <?php endif; ?>
                        <a class="flock-dashboard-link"
                           href="flocks.php?farm_id=<?= (int) $dashboard['farm']['id'] ?>&amp;view=show&amp;id=<?= (int) $flock['id'] ?>">
                            Consulter la fiche d’élevage
                        </a>
                        <a class="flock-dashboard-link"
                           href="flocks.php?farm_id=<?= (int) $dashboard['farm']['id'] ?>&amp;view=calendar&amp;id=<?= (int) $flock['id'] ?>">
                            Gérer le calendrier
                        </a>
                        <?php if ($dashboard['can_write_followups'] && $flock['status'] === 'active'): ?>
                            <a class="flock-dashboard-link"
                              href="followups.php?farm_id=<?= (int) $dashboard['farm']['id'] ?>&amp;flock_id=<?= (int) $flock['id'] ?>">
                               Enregistrer le suivi quotidien
                            </a>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php else: ?>
        <div class="admin-permission-note" role="note">
            Les informations d’élevage ne sont pas affichées car votre compte ne dispose pas de la permission correspondante.
        </div>
    <?php endif; ?>

    <section class="dashboard-columns dashboard-lower-row">
        <article class="card" id="next-steps">
            <div class="card-heading">
                <div>
                    <h2>Prochaines étapes</h2>
                    <p>Échéances d’étapes enregistrées, y compris les retards</p>
                </div>
            </div>
            <?php if ($dashboard['upcoming_steps'] === []): ?>
                <p class="dashboard-empty">Aucune étape prévisionnelle disponible.</p>
            <?php else: ?>
                <ul class="upcoming-list">
                    <?php foreach ($dashboard['upcoming_steps'] as $step): ?>
                        <li>
                            <span class="upcoming-date<?= $step['overdue'] ? ' is-overdue' : '' ?>"><?= $formatDate($step['date']) ?></span>
                            <span><strong><?= $escape($step['label']) ?><?= $step['overdue'] ? ' · en retard' : '' ?></strong><small><?= $escape($step['flock_name']) ?></small></span>
                            <a class="button button-secondary" href="flocks.php?farm_id=<?= (int) $dashboard['farm']['id'] ?>&amp;view=calendar&amp;id=<?= (int) $step['flock_id'] ?>">Calendrier</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
        <article class="card">
            <div class="card-heading">
                <div>
                    <h2>Prochaines dates estimées de vente</h2>
                    <p>Prévisions selon les durées définies par l’exploitation</p>
                </div>
            </div>
            <?php if ($dashboard['forecast_dates'] === []): ?>
                <p class="dashboard-empty">Aucune date estimée : configurez d’abord la durée du cycle d’un élevage actif.</p>
            <?php else: ?>
                <ul class="upcoming-list">
                    <?php foreach (array_slice($dashboard['forecast_dates'], 0, 6) as $flock): ?>
                        <li>
                            <span class="upcoming-date<?= $flock['is_overdue'] ? ' is-overdue' : '' ?>">
                                <?= $formatDate($flock['expected_sale_date']) ?>
                            </span>
                            <span><strong><?= $escape($flock['name']) ?></strong><small><?= $flock['target_cycle_days'] ?> jours · estimation</small></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </article>
    </section>

    <?php if ($dashboard['can_manage_cycles'] && $dashboard['cycle_groups'] !== []): ?>
        <section class="dashboard-section" id="cycle-settings">
            <div class="dashboard-section-heading">
                <div>
                    <p class="eyebrow">PARAMÈTRES PRÉVISIONNELS</p>
                    <h2>Durées indicatives des cycles</h2>
                    <p>Définissez vos propres références pour chaque type présent dans les élevages en cours.</p>
                </div>
            </div>
            <div class="cycle-settings-grid">
                <?php foreach ($dashboard['cycle_groups'] as $group): ?>
                    <form class="card cycle-setting-form" method="post"
                          action="./?farm_id=<?= (int) $dashboard['farm']['id'] ?>#cycle-settings">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="save_cycle_setting">
                        <input type="hidden" name="poultry_type" value="<?= $escape($group['poultry_type']) ?>">
                        <input type="hidden" name="production_type" value="<?= $escape($group['production_type']) ?>">
                        <label class="field">
                            <?= $escape($poultryLabels[$group['poultry_type']] ?? 'Volaille') ?> ·
                            <?= $escape($productionLabels[$group['production_type']] ?? 'Production') ?>
                            <input type="number" name="target_cycle_days" min="1" max="2000"
                                   required value="<?= $group['target_cycle_days'] === null
                                       ? ''
                                       : (int) $group['target_cycle_days'] ?>"
                                   placeholder="Jours" inputmode="numeric">
                        </label>
                        <button class="button button-secondary" type="submit">
                            <?= $group['target_cycle_days'] === null ? 'Configurer' : 'Mettre à jour' ?>
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
            <p class="calculation-note">
                Cette durée sert uniquement à estimer la date de vente. Elle ne crée pas
                d’étapes et n’entre pas dans le calcul de la progression réelle.
            </p>
        </section>
    <?php endif; ?>

    <?php if ($dashboard['financials'] !== null): ?>
        <section class="dashboard-section" id="finances">
            <div class="dashboard-section-heading">
                <div>
                    <p class="eyebrow">FINANCES</p>
                    <h2>Dépenses par devise</h2>
                    <p>Seules les dépenses comptabilisées sont incluses ; les devises ne sont jamais additionnées entre elles.</p>
                </div>
            </div>
            <?php if ($dashboard['financials']['expenses'] === []): ?>
                <div class="card dashboard-empty"><p>Aucune dépense comptabilisée pour cette exploitation.</p></div>
            <?php else: ?>
                <div class="metrics-grid metrics-grid-three">
                    <?php foreach ($dashboard['financials']['expenses'] as $expense): ?>
                        <article class="card stat-card">
                            <span class="stat-label"><?= $escape($expense['currency_code']) ?></span>
                            <strong class="stat-value stat-value-money"><?= $formatMoney($expense['total_amount']) ?></strong>
                            <span class="stat-foot"><?= $formatNumber($expense['expense_count']) ?> dépense(s) non annulée(s)</span>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($dashboard['can_view_flocks'] ?? true): ?>
    <section class="dashboard-section" id="operations">
        <div class="dashboard-section-heading">
            <div>
                <p class="eyebrow">JOURNAL DE L’EXPLOITATION</p>
                <h2>Dernières opérations</h2>
                <p>Événements conservés dans cette exploitation.</p>
            </div>
        </div>
        <?php if ($dashboard['operations'] === []): ?>
            <div class="card dashboard-empty"><p>Aucune opération enregistrée pour le moment.</p></div>
        <?php else: ?>
            <div class="card table-card">
                <div class="table-scroll" tabindex="0" role="region" aria-label="Dernières opérations">
                    <table class="data-table">
                        <thead><tr><th scope="col">Date</th><th scope="col">Opération</th><th scope="col">Élevage / client</th><th scope="col">Détail</th></tr></thead>
                        <tbody>
                        <?php foreach ($dashboard['operations'] as $operation): ?>
                            <?php
                            $operationDate = new DateTimeImmutable((string) $operation['operation_at']);
                            ?>
                            <tr>
                                <td><?= $formatDate($operationDate) ?></td>
                                <td><?= $escape((string) $operation['operation']) ?></td>
                                <td><?= $escape((string) $operation['flock_name']) ?></td>
                                <td class="operation-detail"><?= $escape((string) $operation['detail']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
<?php endif; ?>
