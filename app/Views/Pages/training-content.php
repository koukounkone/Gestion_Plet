<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$modulesToShow = is_array($selectedModule) ? [$selectedModule] : $modules;
$allLessons = array_sum(array_column($modules, 'lesson_count'));
$allCompleted = array_sum(array_column($modules, 'completed_count'));
$overallProgress = $allLessons === 0
    ? null
    : (int) floor($allCompleted * 100 / $allLessons);
$checklistItems = static fn (string $value): array => array_values(array_filter(
    array_map('trim', preg_split('/\R/u', $value) ?: []),
    static fn (string $item): bool => $item !== ''
));
$textLines = static fn (string $value): array => array_values(array_filter(
    array_map('trim', preg_split('/\R/u', $value) ?: []),
    static fn (string $item): bool => $item !== ''
));
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
    <strong>Repères de formation, pas conseils vétérinaires.</strong>
    Les exigences dépendent du pays, du bâtiment et des animaux. Les contenus ne remplacent
    pas les instructions officielles, l’avis d’un vétérinaire ni celui d’un professionnel avicole.
</div>

<section class="card training-progress-card" aria-labelledby="training-progress-title">
    <div class="card-heading">
        <div>
            <h2 id="training-progress-title">Votre progression</h2>
            <p>Les leçons terminées sont enregistrées sur votre compte.</p>
        </div>
        <?php if ($overallProgress !== null): ?>
            <span class="badge badge-success"><?= $overallProgress ?> %</span>
        <?php endif; ?>
    </div>
    <div class="training-progress-body">
        <?php if ($overallProgress === null): ?>
            <p>Aucune leçon publiée pour le moment.</p>
        <?php else: ?>
            <strong><?= $allCompleted ?> / <?= $allLessons ?> leçons terminées</strong>
            <progress class="flock-progress-track training-progress-track"
                      aria-label="Progression globale de formation"
                      max="100" value="<?= $overallProgress ?>"><?= $overallProgress ?> %</progress>
        <?php endif; ?>
    </div>
</section>

<?php if ($modules === []): ?>
    <div class="card dashboard-empty">
        <h2>Aucun module publié pour le moment</h2>
        <p>Les contenus apparaîtront ici après leur validation et leur publication.</p>
    </div>
<?php elseif (!is_array($selectedModule)): ?>
    <section class="training-module-grid" aria-label="Modules de formation">
        <?php foreach ($modules as $module): ?>
            <a class="card training-module-card" href="training.php?module=<?= rawurlencode($module['slug']) ?>">
                <div class="training-module-heading">
                    <span class="training-module-icon" aria-hidden="true">◎</span>
                    <?php if ($module['progress'] !== null): ?>
                        <span class="badge <?= $module['progress'] === 100 ? 'badge-success' : 'badge-neutral' ?>">
                            <?= $module['progress'] ?> %
                        </span>
                    <?php endif; ?>
                </div>
                <h2><?= $escape($module['title']) ?></h2>
                <p><?= $escape($module['summary']) ?></p>
                <span class="training-module-foot">
                    <?= $module['completed_count'] ?> / <?= $module['lesson_count'] ?> leçon(s) terminée(s)
                    <span aria-hidden="true">→</span>
                </span>
            </a>
        <?php endforeach; ?>
    </section>
<?php else: ?>
    <p class="training-back-link"><a class="button button-secondary" href="training.php">← Tous les modules</a></p>
    <section class="training-lessons" aria-label="Leçons de <?= $escape($selectedModule['title']) ?>">
        <?php if ($selectedModule['lessons'] === []): ?>
            <div class="card dashboard-empty">
                <h2>Aucune leçon publiée dans ce module</h2>
                <p>Revenez plus tard ou consultez les autres modules disponibles.</p>
            </div>
        <?php endif; ?>
        <?php foreach ($selectedModule['lessons'] as $index => $lesson): ?>
            <?php
            $completed = $lesson['completed_at'] !== null;
            $objectives = $textLines($lesson['objectives']);
            $checklist = $checklistItems($lesson['checklist']);
            $references = $textLines($lesson['references_text']);
            ?>
            <article class="card training-lesson-card" id="lesson-<?= $lesson['id'] ?>">
                <div class="training-lesson-heading">
                    <span class="training-lesson-number">Leçon <?= $index + 1 ?></span>
                    <span class="badge <?= $completed ? 'badge-success' : 'badge-neutral' ?>">
                        <?= $completed ? 'Terminée' : 'À suivre' ?>
                    </span>
                </div>
                <h2><?= $escape($lesson['title']) ?></h2>
                <div class="training-lesson-section">
                    <h3>Objectifs</h3>
                    <ul><?php foreach ($objectives as $objective): ?><li><?= $escape($objective) ?></li><?php endforeach; ?></ul>
                </div>
                <div class="training-lesson-content">
                    <h3>Contenu pédagogique</h3>
                    <p><?= nl2br($escape($lesson['content'])) ?></p>
                </div>
                <aside class="training-advice">
                    <h3>Conseils prudents</h3>
                    <p><?= nl2br($escape($lesson['validated_advice'])) ?></p>
                </aside>
                <div class="training-lesson-section">
                    <h3>Checklist</h3>
                    <ul class="training-checklist">
                        <?php foreach ($checklist as $item): ?><li><?= $escape($item) ?></li><?php endforeach; ?>
                    </ul>
                </div>
                <div class="training-references">
                    <h3>Sources de référence à consulter</h3>
                    <ul>
                        <?php foreach ($references as $reference): ?>
                            <li><a href="<?= $escape($reference) ?>" target="_blank" rel="noopener noreferrer"><?= $escape($reference) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                    <p>Vérifiez les versions en vigueur et les règles de votre pays avant toute décision pratique.</p>
                </div>
                <form method="post" action="training.php?module=<?= rawurlencode($selectedModule['slug']) ?>#lesson-<?= $lesson['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                    <input type="hidden" name="lesson_id" value="<?= $lesson['id'] ?>">
                    <input type="hidden" name="module_slug" value="<?= $escape($selectedModule['slug']) ?>">
                    <button class="button <?= $completed ? 'button-secondary' : 'button-primary' ?>"
                            type="submit" name="action" value="<?= $completed ? 'reopen' : 'complete' ?>">
                        <?= $completed ? 'Retirer des leçons terminées' : 'Marquer comme terminée' ?>
                    </button>
                    <?php if ($completed): ?>
                        <small>Terminée le <?= $escape((new DateTimeImmutable((string) $lesson['completed_at']))->format('d/m/Y H:i')) ?></small>
                    <?php endif; ?>
                </form>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
