<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$statusLabels = ['draft' => 'Brouillon', 'published' => 'Publié', 'archived' => 'Archivé'];
$statusClasses = ['draft' => 'badge-neutral', 'published' => 'badge-success', 'archived' => 'badge-warning'];
?>
<?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
        <?php foreach ($errors as $error): ?><p><?= $escape($error) ?></p><?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if (is_array($flash) && isset($flash['message'])): ?>
    <div class="alert alert-success" role="status"><?= $escape((string) $flash['message']) ?></div>
<?php endif; ?>

<div class="training-caution" role="note">
    Vérifiez les sources et les règles locales avant publication. Le guide ne doit pas
    présenter de diagnostic, médicament, dosage ou protocole vétérinaire générique.
</div>
<section class="card training-admin-create" aria-labelledby="new-module-title">
    <div class="card-heading">
        <div><h2 id="new-module-title">Créer un module</h2><p>Un module doit contenir au moins une leçon pour être publié.</p></div>
    </div>
    <form class="training-admin-form" method="post" action="admin-training.php">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="module_save">
        <div class="training-admin-grid">
            <label class="field"><span>Code URL</span>
                <input name="slug" maxlength="80" pattern="[a-z0-9]+(-[a-z0-9]+)*" required placeholder="exemple-module">
            </label>
            <label class="field"><span>Titre</span><input name="title" maxlength="160" required></label>
            <label class="field"><span>Ordre</span><input type="number" name="position" min="0" max="65535" value="10" required></label>
            <label class="field"><span>Statut</span>
                <select name="status"><option value="draft">Brouillon</option><option value="published">Publié</option></select>
            </label>
            <label class="field field-wide"><span>Résumé</span><textarea name="summary" rows="2" maxlength="1000" required></textarea></label>
        </div>
        <button class="button button-primary" type="submit">Créer le module</button>
    </form>
</section>

<section class="training-admin-modules" aria-label="Modules et leçons">
    <?php if ($modules === []): ?>
        <div class="card dashboard-empty">
            <h2>Aucun module enregistré</h2>
            <p>Créez un module ci-dessus pour commencer à préparer les contenus.</p>
        </div>
    <?php endif; ?>
    <?php foreach ($modules as $module): ?>
        <article class="card training-admin-module" id="module-<?= $module['id'] ?>">
            <div class="card-heading">
                <div>
                    <p class="training-admin-slug"><?= $escape($module['slug']) ?></p>
                    <h2><?= $escape($module['title']) ?></h2>
                    <p><?= count($module['lessons']) ?> leçon(s)</p>
                </div>
                <span class="badge <?= $escape($statusClasses[$module['status']] ?? 'badge-neutral') ?>">
                    <?= $escape($statusLabels[$module['status']] ?? $module['status']) ?>
                </span>
            </div>
            <details class="training-admin-editor">
                <summary>Modifier le module</summary>
                <form class="training-admin-form" method="post" action="admin-training.php#module-<?= $module['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="module_save">
                    <input type="hidden" name="module_id" value="<?= $module['id'] ?>">
                    <div class="training-admin-grid">
                        <label class="field"><span>Code URL</span>
                            <input name="slug" maxlength="80" pattern="[a-z0-9]+(-[a-z0-9]+)*" required value="<?= $escape($module['slug']) ?>">
                        </label>
                        <label class="field"><span>Titre</span><input name="title" maxlength="160" required value="<?= $escape($module['title']) ?>"></label>
                        <label class="field"><span>Ordre</span><input type="number" name="position" min="0" max="65535" required value="<?= $module['position'] ?>"></label>
                        <label class="field"><span>Statut</span>
                            <select name="status">
                                <?php foreach ($statusLabels as $status => $label): ?>
                                    <option value="<?= $escape($status) ?>" <?= $module['status'] === $status ? 'selected' : '' ?>><?= $escape($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="field field-wide"><span>Résumé</span>
                            <textarea name="summary" rows="2" maxlength="1000" required><?= $escape($module['summary']) ?></textarea>
                        </label>
                    </div>
                    <button class="button button-secondary" type="submit">Enregistrer le module</button>
                </form>
            </details>

            <?php foreach ($module['lessons'] as $lesson): ?>
                <details class="training-admin-editor training-admin-lesson" id="module-<?= $module['id'] ?>-lesson-<?= $lesson['id'] ?>">
                    <summary>
                        <span><?= $escape($lesson['title']) ?></span>
                        <span class="badge <?= $escape($statusClasses[$lesson['status']] ?? 'badge-neutral') ?>">
                            <?= $escape($statusLabels[$lesson['status']] ?? $lesson['status']) ?>
                        </span>
                    </summary>
                    <form class="training-admin-form" method="post" action="admin-training.php#module-<?= $module['id'] ?>-lesson-<?= $lesson['id'] ?>">
                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                        <input type="hidden" name="action" value="lesson_save">
                        <input type="hidden" name="module_id" value="<?= $module['id'] ?>">
                        <input type="hidden" name="lesson_id" value="<?= $lesson['id'] ?>">
                        <div class="training-admin-grid">
                            <label class="field field-wide"><span>Titre</span><input name="title" maxlength="200" required value="<?= $escape($lesson['title']) ?>"></label>
                            <label class="field"><span>Ordre</span><input type="number" name="position" min="0" max="65535" required value="<?= $lesson['position'] ?>"></label>
                            <label class="field"><span>Statut</span>
                                <select name="status">
                                    <?php foreach ($statusLabels as $status => $label): ?>
                                        <option value="<?= $escape($status) ?>" <?= $lesson['status'] === $status ? 'selected' : '' ?>><?= $escape($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="field field-wide"><span>Objectifs (un par ligne)</span><textarea name="objectives" rows="3" maxlength="12000" required><?= $escape($lesson['objectives']) ?></textarea></label>
                            <label class="field field-wide"><span>Contenu pédagogique</span><textarea name="content" rows="7" maxlength="60000" required><?= $escape($lesson['content']) ?></textarea></label>
                            <label class="field field-wide"><span>Conseils vérifiés et limites</span><textarea name="validated_advice" rows="4" maxlength="12000" required><?= $escape($lesson['validated_advice']) ?></textarea></label>
                            <label class="field field-wide"><span>Checklist (un point par ligne)</span><textarea name="checklist" rows="4" maxlength="12000" required><?= $escape($lesson['checklist']) ?></textarea></label>
                            <label class="field field-wide"><span>Références HTTP(S), une par ligne</span><textarea name="references_text" rows="3" maxlength="8000" required><?= $escape($lesson['references_text']) ?></textarea></label>
                        </div>
                        <button class="button button-secondary" type="submit">Enregistrer la leçon</button>
                    </form>
                </details>
            <?php endforeach; ?>

            <details class="training-admin-editor training-admin-new-lesson">
                <summary>Ajouter une leçon</summary>
                <form class="training-admin-form" method="post" action="admin-training.php#module-<?= $module['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="action" value="lesson_save">
                    <input type="hidden" name="module_id" value="<?= $module['id'] ?>">
                    <div class="training-admin-grid">
                        <?php foreach ([
                            'title' => 'Titre',
                            'objectives' => 'Objectifs (un par ligne)',
                            'content' => 'Contenu pédagogique',
                            'validated_advice' => 'Conseils vérifiés et limites',
                            'checklist' => 'Checklist (un point par ligne)',
                            'references_text' => 'Références HTTP(S), une par ligne',
                        ] as $field => $label): ?>
                            <label class="field <?= $field === 'title' ? 'field-wide' : 'field-wide' ?>">
                                <span><?= $escape($label) ?></span>
                                <?php if ($field === 'title'): ?>
                                    <input name="<?= $escape($field) ?>" maxlength="200" required>
                                <?php else: ?>
                                    <textarea name="<?= $escape($field) ?>" rows="<?= $field === 'content' ? 7 : 3 ?>"
                                              maxlength="<?= $field === 'content' ? 60000 : ($field === 'references_text' ? 8000 : 12000) ?>" required></textarea>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                        <label class="field"><span>Ordre</span><input type="number" name="position" min="0" max="65535" value="10" required></label>
                        <label class="field"><span>Statut</span>
                            <select name="status"><option value="draft">Brouillon</option><option value="published">Publié</option></select>
                        </label>
                    </div>
                    <button class="button button-primary" type="submit">Créer la leçon</button>
                </form>
            </details>
        </article>
    <?php endforeach; ?>
    <?php if ($modules === []): ?>
        <div class="card dashboard-empty"><h2>Aucun module</h2><p>Créez un module pour commencer à structurer le guide.</p></div>
    <?php endif; ?>
</section>
