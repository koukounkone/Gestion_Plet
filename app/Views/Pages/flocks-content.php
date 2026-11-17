<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$value = static function (string $key, mixed $fallback = '') use ($formValues): string {
    $current = $formValues[$key] ?? $fallback;
    return is_string($current) || is_numeric($current) ? (string) $current : '';
};
$formatMoney = static function (mixed $amount): string {
    if (!is_string($amount) && !is_numeric($amount)) {
        return '—';
    }
    $parts = explode('.', (string) $amount, 2);
    $whole = preg_replace('/\B(?=(\d{3})+(?!\d))/', ' ', $parts[0]) ?? $parts[0];
    return $whole . ',' . str_pad(substr($parts[1] ?? '00', 0, 2), 2, '0');
};
$typeLabels = \MonElevage\Flocks\FlockService::types($pdo, true);
$availableTypeLabels = \MonElevage\Flocks\FlockService::types($pdo);
if (($view ?? '') === 'edit' && isset($formValues['flock_type'], $typeLabels[$formValues['flock_type']])) {
    $availableTypeLabels[$formValues['flock_type']] = $typeLabels[$formValues['flock_type']];
}
$statusLabels = [
    'draft' => 'Brouillon',
    'active' => 'En cours',
    'paused' => 'En pause',
    'closed' => 'Archivé',
];
$statusClasses = [
    'draft' => 'badge-neutral',
    'active' => 'badge-success',
    'paused' => 'badge-warning',
    'closed' => 'badge-neutral',
];
$listUrl = 'flocks.php?farm_id=' . $farmId;
?>
<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $error): ?>
            <p><?= $escape((string) $error) ?></p>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if (is_array($flash) && isset($flash['message'])): ?>
    <div class="alert alert-success" role="status"><?= $escape((string) $flash['message']) ?></div>
<?php endif; ?>

<?php if ($view === 'list'): ?>
    <section class="card flock-list-section" aria-labelledby="flock-list-title">
        <div class="card-heading flock-list-heading">
            <div>
                <h2 id="flock-list-title">Élevages de l’exploitation</h2>
                <p><?= count($flocks) ?> élevage(s), y compris les élevages archivés</p>
            </div>
            <?php if ($canManageFlocks): ?>
                <a class="button button-primary" href="<?= $escape($listUrl . '&view=new') ?>">Créer un élevage</a>
            <?php endif; ?>
        </div>
        <?php if ($flocks === []): ?>
            <div class="flock-empty-state">
                <span class="empty-icon" aria-hidden="true">♧</span>
                <h3>Aucun élevage enregistré</h3>
                <p>Créez le premier élevage de cette exploitation. Les données seront visibles sur le tableau de bord.</p>
                <?php if ($canManageFlocks): ?>
                    <a class="button button-primary" href="<?= $escape($listUrl . '&view=new') ?>">Créer le premier élevage</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="flock-management-grid">
                <?php foreach ($flocks as $item): ?>
                    <article class="card flock-management-card">
                        <div class="flock-management-card-heading">
                            <div>
                                <p class="flock-code"><?= $escape((string) $item['code']) ?></p>
                                <h3><?= $escape((string) $item['name']) ?></h3>
                                <p><?= $escape($typeLabels[$item['flock_type']] ?? 'Volaille') ?></p>
                            </div>
                            <span class="badge <?= $escape($statusClasses[$item['status']] ?? 'badge-neutral') ?>">
                                <?= $escape($statusLabels[$item['status']] ?? (string) $item['status']) ?>
                            </span>
                        </div>
                        <dl class="flock-management-facts">
                            <div><dt>Effectif actuel</dt><dd><?= number_format($item['live_count'], 0, ',', ' ') ?></dd></div>
                            <div><dt>Effectif initial</dt><dd><?= number_format($item['initial_count'], 0, ',', ' ') ?></dd></div>
                            <div><dt>Début</dt><dd><?= $escape((new DateTimeImmutable((string) $item['started_on']))->format('d/m/Y')) ?></dd></div>
                            <?php if ($canViewFinancials): ?>
                                <div><dt>Budget</dt><dd>
                                    <?= $item['budget_amount'] === null
                                        ? 'Non défini'
                                        : $formatMoney($item['budget_amount']) . ' ' . $escape((string) $item['currency_code']) ?>
                                </dd></div>
                            <?php endif; ?>
                        </dl>
                        <a class="button button-secondary" href="<?= $escape($listUrl . '&view=show&id=' . $item['id']) ?>">
                            Consulter la fiche
                        </a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

<?php elseif ($view === 'calendar' && is_array($flock)): ?>
    <?php
    $priorityLabels = \MonElevage\Flocks\FlockStageService::priorities();
    $overdueStages = array_filter($stages, static fn (array $stage): bool => $stage['is_overdue']);
    $currentStages = array_filter(
        $stages,
        static fn (array $stage): bool => !$stage['is_overdue'] && $stage['status'] === 'in_progress'
    );
    $upcomingStages = array_filter(
        $stages,
        static fn (array $stage): bool => !$stage['is_overdue'] && $stage['status'] === 'planned'
    );
    $completedStages = array_filter($stages, static fn (array $stage): bool => $stage['status'] === 'completed');
    $completedCount = count($completedStages);
    $progress = $stages === [] ? null : (int) floor($completedCount * 100 / count($stages));
    $canWriteObservations = \MonElevage\Auth\FarmAccess::allows(
        $pdo,
        $userId,
        $farmId,
        'followup.write'
    );
    $calendarUrl = $listUrl . '&view=calendar&id=' . (int) $flock['id'];
    $readOnly = $flock['status'] === 'closed';
    ?>
    <section class="card stage-calendar-summary" aria-labelledby="stage-calendar-title">
        <div class="card-heading">
            <div>
                <h2 id="stage-calendar-title">Calendrier de <?= $escape((string) $flock['name']) ?></h2>
                <p>Les étapes sont définies par l’exploitation. Aucune durée ou prescription n’est préremplie.</p>
            </div>
            <a class="button button-secondary" href="<?= $escape($listUrl . '&view=show&id=' . (int) $flock['id']) ?>">
                Retour à la fiche
            </a>
        </div>
        <div class="stage-progress">
            <?php if ($progress === null): ?>
                <strong>Progression non disponible</strong>
                <span>Aucune étape applicable n’a été créée. Ajoutez des étapes pour suivre la progression réelle.</span>
            <?php else: ?>
                <strong><?= $progress ?> %</strong>
                <span><?= $completedCount ?> étape(s) terminée(s) sur <?= count($stages) ?> applicable(s) — terminées ÷ total × 100, arrondi à l’entier inférieur.</span>
                <progress class="flock-progress-track"
                          aria-label="Progression réelle du calendrier"
                          max="100" value="<?= $progress ?>"><?= $progress ?> %</progress>
            <?php endif; ?>
        </div>
    </section>

    <?php if (!$readOnly && $canManageFlocks): ?>
        <section class="card stage-create-panel" aria-labelledby="stage-create-title">
            <div class="card-heading">
                <div>
                    <h2 id="stage-create-title">Ajouter une étape</h2>
                    <p>Choisissez les tâches, dates et niveaux de priorité adaptés à cet élevage.</p>
                </div>
            </div>
            <form class="flock-form" method="post" action="<?= $escape($calendarUrl) ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="action" value="stage_create">
                <div class="flock-form-grid">
                    <label class="field">
                        <span>Titre <b aria-hidden="true">*</b></span>
                        <input type="text" name="title" maxlength="160" required>
                    </label>
                    <label class="field">
                        <span>Date prévue <b aria-hidden="true">*</b></span>
                        <input type="date" name="planned_on" min="<?= $escape((string) $flock['started_on']) ?>" required>
                    </label>
                    <label class="field">
                        <span>Priorité <b aria-hidden="true">*</b></span>
                        <select name="priority" required>
                            <?php foreach ($priorityLabels as $priority => $label): ?>
                                <option value="<?= $escape($priority) ?>" <?= $priority === 'normal' ? 'selected' : '' ?>>
                                    <?= $escape($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label class="field field-wide">
                        <span>Description</span>
                        <textarea name="description" rows="3" maxlength="10000"></textarea>
                    </label>
                </div>
                <div class="flock-form-actions">
                    <button class="button button-primary" type="submit">Ajouter l’étape</button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <?php
    $stageGroups = [
        ['title' => 'En retard', 'items' => $overdueStages, 'empty' => 'Aucun retard.'],
        ['title' => 'Étapes actuelles', 'items' => $currentStages, 'empty' => 'Aucune étape en cours.'],
        ['title' => 'À venir', 'items' => $upcomingStages, 'empty' => 'Aucune échéance à venir.'],
        ['title' => 'Terminées', 'items' => $completedStages, 'empty' => 'Aucune étape terminée.'],
    ];
    ?>
    <?php foreach ($stageGroups as $group): ?>
        <section class="card stage-group" aria-label="<?= $escape($group['title']) ?>">
            <div class="card-heading">
                <div>
                    <h2><?= $escape($group['title']) ?></h2>
                    <p><?= count($group['items']) ?> étape(s)</p>
                </div>
            </div>
            <?php if ($group['items'] === []): ?>
                <p class="dashboard-empty"><?= $escape($group['empty']) ?></p>
            <?php else: ?>
                <div class="stage-list">
                    <?php foreach ($group['items'] as $stage): ?>
                        <article class="stage-item<?= $stage['is_overdue'] ? ' stage-item-overdue' : '' ?>">
                            <div class="stage-item-heading">
                                <div>
                                    <h3><?= $escape((string) $stage['title']) ?></h3>
                                    <p><?= $escape($priorityLabels[$stage['priority']] ?? (string) $stage['priority']) ?> · prévue le
                                        <?= $escape((new DateTimeImmutable((string) $stage['planned_on']))->format('d/m/Y')) ?>
                                        <?php if ($stage['completed_on'] !== null): ?>
                                            · réalisée le <?= $escape((new DateTimeImmutable((string) $stage['completed_on']))->format('d/m/Y')) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <span class="badge <?= $stage['is_overdue'] ? 'badge-warning' : ($stage['status'] === 'completed' ? 'badge-success' : 'badge-neutral') ?>">
                                    <?= $stage['is_overdue'] ? 'En retard' : match ($stage['status']) {
                                        'in_progress' => 'En cours',
                                        'completed' => 'Terminée',
                                        default => 'À venir',
                                    } ?>
                                </span>
                            </div>
                            <?php if (is_string($stage['description']) && $stage['description'] !== ''): ?>
                                <p class="stage-description"><?= nl2br($escape($stage['description'])) ?></p>
                            <?php endif; ?>

                            <?php if (!$readOnly && $stage['status'] !== 'completed' && $canManageFlocks): ?>
                                <form class="stage-edit-form" method="post" action="<?= $escape($calendarUrl) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="action" value="stage_update">
                                    <input type="hidden" name="stage_id" value="<?= (int) $stage['id'] ?>">
                                    <label class="field"><span>Titre</span>
                                        <input name="title" maxlength="160" required value="<?= $escape((string) $stage['title']) ?>">
                                    </label>
                                    <label class="field"><span>Date prévue</span>
                                        <input type="date" name="planned_on" min="<?= $escape((string) $flock['started_on']) ?>" required
                                               value="<?= $escape((string) $stage['planned_on']) ?>">
                                    </label>
                                    <label class="field"><span>Priorité</span>
                                        <select name="priority">
                                            <?php foreach ($priorityLabels as $priority => $label): ?>
                                                <option value="<?= $escape($priority) ?>" <?= $stage['priority'] === $priority ? 'selected' : '' ?>>
                                                    <?= $escape($label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="field field-wide"><span>Description</span>
                                        <textarea name="description" rows="2" maxlength="10000"><?= $escape((string) ($stage['description'] ?? '')) ?></textarea>
                                    </label>
                                    <button class="button button-secondary" type="submit">Enregistrer</button>
                                </form>
                                <div class="stage-actions">
                                    <?php if ($stage['status'] === 'planned'): ?>
                                        <form method="post" action="<?= $escape($calendarUrl) ?>">
                                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                            <input type="hidden" name="action" value="stage_start">
                                            <input type="hidden" name="stage_id" value="<?= (int) $stage['id'] ?>">
                                            <button class="button button-secondary" type="submit">Commencer</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?= $escape($calendarUrl) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="action" value="stage_reschedule">
                                        <input type="hidden" name="stage_id" value="<?= (int) $stage['id'] ?>">
                                        <label class="field"><span>Reporter au</span>
                                            <input type="date" name="planned_on" min="<?= $escape((string) $flock['started_on']) ?>" required>
                                        </label>
                                        <label class="field"><span>Motif</span>
                                            <input type="text" name="reason" maxlength="500" required>
                                        </label>
                                        <button class="button button-secondary" type="submit">Reporter</button>
                                    </form>
                                    <form method="post" action="<?= $escape($calendarUrl) ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="action" value="stage_complete">
                                        <input type="hidden" name="stage_id" value="<?= (int) $stage['id'] ?>">
                                        <label class="field"><span>Date de réalisation</span>
                                            <input type="date" name="completed_on" min="<?= $escape((string) $flock['started_on']) ?>"
                                                   max="<?= (new DateTimeImmutable('today', new DateTimeZone((string) $selectedFarm['timezone'])))->format('Y-m-d') ?>"
                                                   value="<?= (new DateTimeImmutable('today', new DateTimeZone((string) $selectedFarm['timezone'])))->format('Y-m-d') ?>" required>
                                        </label>
                                        <button class="button button-primary" type="submit">Marquer terminée</button>
                                    </form>
                                </div>
                            <?php elseif (!$readOnly && $stage['status'] === 'completed' && $canManageFlocks): ?>
                                <form method="post" action="<?= $escape($calendarUrl) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="action" value="stage_reopen">
                                    <input type="hidden" name="stage_id" value="<?= (int) $stage['id'] ?>">
                                    <button class="button button-secondary" type="submit">Rouvrir l’étape</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($canWriteObservations && !$readOnly): ?>
                                <form class="stage-observation-form" method="post" action="<?= $escape($calendarUrl) ?>">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="action" value="stage_observation">
                                    <input type="hidden" name="stage_id" value="<?= (int) $stage['id'] ?>">
                                    <label class="field"><span>Ajouter une observation</span>
                                        <textarea name="observation" rows="2" maxlength="4000" required></textarea>
                                    </label>
                                    <button class="button button-secondary" type="submit">Enregistrer l’observation</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($stage['observations'] !== []): ?>
                                <ul class="stage-observations">
                                    <?php foreach ($stage['observations'] as $observation): ?>
                                        <li><strong><?= $escape((string) $observation['author_name']) ?> ·
                                            <?= $escape((new DateTimeImmutable((string) $observation['created_at']))->format('d/m/Y H:i')) ?></strong>
                                            <p><?= nl2br($escape((string) $observation['observation'])) ?></p>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                            <?php if ($stage['history'] !== []): ?>
                                <details class="stage-history">
                                    <summary>Historique (<?= count($stage['history']) ?>)</summary>
                                    <ul>
                                        <?php foreach ($stage['history'] as $event): ?>
                                            <li><?= $escape((string) $event['actor_name']) ?> ·
                                                <?= $escape((new DateTimeImmutable((string) $event['created_at']))->format('d/m/Y H:i')) ?> —
                                                <?= $escape(match ($event['event_type']) {
                                                    'created' => 'création',
                                                    'updated' => 'modification',
                                                    'rescheduled' => 'report',
                                                    'started' => 'début',
                                                    'completed' => 'réalisation',
                                                    'reopened' => 'réouverture',
                                                    default => (string) $event['event_type'],
                                                }) ?>
                                                <?php if (is_string($event['reason']) && $event['reason'] !== ''): ?>
                                                    (<?= $escape($event['reason']) ?>)
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>

<?php elseif ($view === 'new' || $view === 'edit'): ?>
    <?php $editing = $view === 'edit'; ?>
    <?php $formCurrencyCode = $editing ? (string) $flock['currency_code'] : (string) $selectedFarm['currency_code']; ?>
    <section class="card flock-form-card" aria-labelledby="flock-form-title">
        <div class="card-heading">
            <div>
                <h2 id="flock-form-title"><?= $editing ? 'Informations de l’élevage' : 'Nouvel élevage' ?></h2>
                <p>Les champs marqués d’un astérisque sont obligatoires.</p>
            </div>
        </div>
        <form class="flock-form" method="post"
              action="<?= $escape($listUrl . '&view=' . $view . ($editing ? '&id=' . (int) $flockId : '')) ?>">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
            <div class="flock-form-grid">
                <label class="field">
                    <span>Nom de l’élevage <b aria-hidden="true">*</b></span>
                    <input type="text" name="name" maxlength="120" required
                           value="<?= $escape($value('name')) ?>" autocomplete="off">
                </label>
                <label class="field">
                    <span>Code unique dans l’exploitation <b aria-hidden="true">*</b></span>
                    <input type="text" name="code" maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9_\x2D]{0,39}" required
                           value="<?= $escape($value('code')) ?>" autocomplete="off">
                    <small>Lettres, chiffres, tiret ou tiret bas ; les minuscules sont converties en majuscules.</small>
                </label>
                <label class="field">
                    <span>Type d’élevage <b aria-hidden="true">*</b></span>
                    <select name="flock_type" required>
                        <?php foreach ($availableTypeLabels as $type => $label): ?>
                            <option value="<?= $escape($type) ?>" <?= $value('flock_type', 'broiler') === $type ? 'selected' : '' ?>>
                                <?= $escape($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label class="field">
                    <span>Date de début <b aria-hidden="true">*</b></span>
                    <input type="date" name="started_on" required max="<?= (new DateTimeImmutable('today', new DateTimeZone((string) $selectedFarm['timezone'])))->format('Y-m-d') ?>"
                           value="<?= $escape($value('started_on')) ?>">
                </label>
                <label class="field">
                    <span>Nombre initial d’animaux <b aria-hidden="true">*</b></span>
                    <input type="number" name="initial_count" min="1" max="2147483647" step="1" required
                           value="<?= $escape($value('initial_count')) ?>">
                </label>
                <label class="field">
                    <span>Fournisseur</span>
                    <input type="text" name="supplier_name" maxlength="160"
                           value="<?= $escape($value('supplier_name')) ?>">
                </label>
                <label class="field">
                    <span>Coût initial (<?= $escape($formCurrencyCode) ?>)</span>
                    <input type="number" name="initial_cost" min="0" max="9999999999999.99" step="0.01"
                           value="<?= $escape($value('initial_cost')) ?>">
                    <small>Information d’achat conservée sur la fiche ; elle ne crée pas une dépense comptable.</small>
                </label>
                <label class="field">
                    <span>Budget prévu (<?= $escape($formCurrencyCode) ?>)</span>
                    <input type="number" name="budget_amount" min="0.01" max="9999999999999.99" step="0.01"
                           value="<?= $escape($value('budget_amount')) ?>">
                    <small>Facultatif ; utilisez la devise de l’exploitation.</small>
                </label>
                <label class="field field-wide">
                    <span>Localisation de l’élevage</span>
                    <input type="text" name="location" maxlength="255"
                           value="<?= $escape($value('location')) ?>" placeholder="Ex. bâtiment 2, parc nord">
                </label>
            </div>
            <div class="flock-form-actions">
                <a class="button button-secondary" href="<?= $escape($listUrl . ($editing ? '&view=show&id=' . (int) $flockId : '')) ?>">
                    Annuler
                </a>
                <button class="button button-primary" type="submit">
                    <?= $editing ? 'Enregistrer les modifications' : 'Créer l’élevage' ?>
                </button>
            </div>
        </form>
    </section>

<?php elseif (is_array($flock)): ?>
    <section class="card flock-detail-card" aria-labelledby="flock-detail-title">
        <div class="flock-detail-heading">
            <div>
                <p class="flock-code"><?= $escape((string) $flock['code']) ?> · <?= $escape($typeLabels[$flock['flock_type']] ?? 'Volaille') ?></p>
                <h2 id="flock-detail-title"><?= $escape((string) $flock['name']) ?></h2>
            </div>
            <span class="badge <?= $escape($statusClasses[$flock['status']] ?? 'badge-neutral') ?>">
                <?= $escape($statusLabels[$flock['status']] ?? (string) $flock['status']) ?>
            </span>
        </div>
        <dl class="flock-detail-facts">
            <div><dt>Effectif initial</dt><dd><?= number_format($flock['initial_count'], 0, ',', ' ') ?></dd></div>
            <div><dt>Effectif calculé</dt><dd><?= number_format($flock['live_count'], 0, ',', ' ') ?></dd></div>
            <div><dt>Date de début</dt><dd><?= $escape((new DateTimeImmutable((string) $flock['started_on']))->format('d/m/Y')) ?></dd></div>
            <div><dt>Fournisseur</dt><dd><?= $escape((string) ($flock['supplier_name'] ?: 'Non renseigné')) ?></dd></div>
            <?php if ($canViewFinancials): ?>
                <div><dt>Coût initial</dt><dd>
                    <?= $flock['initial_cost'] === null
                        ? 'Non renseigné'
                        : $formatMoney($flock['initial_cost']) . ' ' . $escape((string) $flock['currency_code']) ?>
                </dd></div>
                <div><dt>Budget</dt><dd>
                    <?= $flock['budget_amount'] === null
                        ? 'Non défini'
                        : $formatMoney($flock['budget_amount']) . ' ' . $escape((string) $flock['currency_code']) ?>
                </dd></div>
            <?php endif; ?>
            <div class="flock-detail-fact-wide"><dt>Localisation</dt><dd><?= $escape((string) ($flock['location'] ?: 'Non renseignée')) ?></dd></div>
        </dl>
        <div class="flock-detail-actions">
            <a class="button button-secondary" href="<?= $escape($listUrl) ?>">Retour à la liste</a>
            <a class="button button-secondary" href="<?= $escape($listUrl . '&view=calendar&id=' . $flock['id']) ?>">Calendrier et étapes</a>
            <?php if ($canManageFlocks && $flock['status'] !== 'closed'): ?>
                <a class="button button-secondary" href="<?= $escape($listUrl . '&view=edit&id=' . $flock['id']) ?>">Modifier</a>
                <?php if ($flock['status'] === 'active'): ?>
                    <form method="post" action="<?= $escape($listUrl . '&view=show&id=' . $flock['id']) ?>">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="flock_id" value="<?= $flock['id'] ?>">
                        <button class="button button-secondary" type="submit" name="action" value="pause">Suspendre</button>
                    </form>
                <?php elseif ($flock['status'] === 'paused'): ?>
                    <form method="post" action="<?= $escape($listUrl . '&view=show&id=' . $flock['id']) ?>">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="flock_id" value="<?= $flock['id'] ?>">
                        <button class="button button-secondary" type="submit" name="action" value="resume">Reprendre</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($canManageFlocks): ?>
        <section class="card flock-close-panel" aria-labelledby="flock-delete-title">
            <div class="card-heading">
                <div>
                    <h2 id="flock-delete-title">Supprimer cet élevage</h2>
                    <p>Possible uniquement si aucune opération ni donnée historique n’y est liée. Les données liées ne seront jamais supprimées.</p>
                </div>
            </div>
            <form class="flock-form" method="post"
                  data-confirm="Voulez-vous vraiment supprimer définitivement cet élevage ? Cette action est irréversible. La suppression sera refusée si des opérations ou un historique y sont liés."
                  action="<?= $escape($listUrl . '&view=show&id=' . $flock['id']) ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <button class="button button-danger" type="submit" name="action" value="delete">
                    Supprimer l’élevage
                </button>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($canManageFlocks && $flock['status'] !== 'closed'): ?>
        <section class="card flock-close-panel" aria-labelledby="flock-close-title">
            <div class="card-heading">
                <div>
                    <h2 id="flock-close-title">Clôturer et archiver</h2>
                    <p>La clôture conserve les données et enregistre un résumé de cycle dans la devise de l’élevage.</p>
                </div>
            </div>
            <form class="flock-form flock-close-form" method="post"
                  data-confirm="Confirmer la clôture ? L’élevage sera archivé et ne pourra plus être modifié."
                  action="<?= $escape($listUrl . '&view=show&id=' . $flock['id']) ?>">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="flock_id" value="<?= $flock['id'] ?>">
                <label class="field">
                    <span>Motif de clôture <b aria-hidden="true">*</b></span>
                    <input type="text" name="reason" maxlength="500" required
                           placeholder="Ex. fin du cycle, arrêt de l’activité">
                </label>
                <button class="button button-primary" type="submit" name="action" value="close">
                    Clôturer et archiver
                </button>
            </form>
        </section>
    <?php endif; ?>

    <section class="card flock-history-section" aria-labelledby="flock-history-title">
        <div class="card-heading">
            <div>
                <h2 id="flock-history-title">Historique du cycle</h2>
                <p>Les changements de statut sont journalisés et ne suppriment aucune opération.</p>
            </div>
        </div>
        <?php if ($flock['history'] === []): ?>
            <p class="dashboard-empty">Aucun événement de cycle enregistré.</p>
        <?php else: ?>
            <ol class="flock-history-list">
                <?php foreach ($flock['history'] as $event): ?>
                    <li>
                        <time datetime="<?= $escape((string) $event['event_at']) ?>">
                            <?= $escape((new DateTimeImmutable((string) $event['event_at']))->format('d/m/Y H:i')) ?>
                        </time>
                        <div>
                            <strong><?= $escape(match ($event['event_type']) {
                                'created' => 'Création',
                                'activated' => 'Mise en activité',
                                'paused' => 'Suspension',
                                'resumed' => 'Reprise',
                                'closed' => 'Clôture et archivage',
                                default => (string) $event['event_type'],
                            }) ?></strong>
                            <?php if ($event['event_type'] === 'closed'): ?>
                                <p>Effectif restant : <?= number_format((int) $event['closing_count'], 0, ',', ' ') ?>.</p>
                                <?php if ($canViewFinancials): ?>
                                    <p>Résultat enregistré : <?= $formatMoney($event['closing_result']) ?>
                                        <?= $escape((string) $event['currency_code']) ?>.</p>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (is_string($event['reason']) && $event['reason'] !== ''): ?>
                                <p><?= $escape($event['reason']) ?></p>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>
    </section>
<?php endif; ?>
