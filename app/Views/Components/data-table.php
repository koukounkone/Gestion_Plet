<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
?>
<article class="card table-card">
    <div class="card-heading">
        <div>
            <h2><?= $escape($tableTitle) ?></h2>
            <p>Exemples fictifs — non enregistrés</p>
        </div>
        <span class="record-count"><?= count($tableRows) ?> lignes d’exemple</span>
    </div>
    <div class="table-scroll" role="region" aria-label="<?= $escape($tableTitle) ?>"
         tabindex="0">
        <table class="data-table">
            <thead>
            <tr>
                <?php foreach ($tableColumns as $column): ?>
                    <th scope="col"><?= $escape($column) ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($tableRows as $row): ?>
                <tr>
                    <?php foreach ($row as $cell): ?>
                        <td>
                            <?php if (is_array($cell) && isset($cell['label'], $cell['tone'])): ?>
                                <span class="badge badge-<?= $escape($cell['tone']) ?>">
                                    <?= $escape($cell['label']) ?>
                                </span>
                            <?php else: ?>
                                <?= $escape((string) $cell) ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</article>
