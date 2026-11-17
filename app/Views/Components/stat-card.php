<?php
declare(strict_types=1);

$escape = static fn (string $value): string => htmlspecialchars(
    $value,
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
?>
<article class="card stat-card">
    <div class="stat-top">
        <span class="stat-label"><?= $escape($metric['label']) ?></span>
        <span class="stat-icon" aria-hidden="true"><?= $escape($metric['icon']) ?></span>
    </div>
    <strong class="stat-value"><?= $escape($metric['value']) ?></strong>
    <span class="stat-foot"><?= $escape($metric['note']) ?></span>
</article>
