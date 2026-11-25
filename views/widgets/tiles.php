<?php
/**
 * @var array  $tiles [['label' => string, 'value' => string, 'change' => ['text','class']], ...]
 * @var string $caption
 */
?>
<p class="arc-stats__range"><?= htmlspecialchars($caption) ?></p>
<div class="arc-stats__tiles">
    <?php foreach ($tiles as $tile): ?>
        <div class="arc-stats__tile">
            <div class="arc-stats__label"><?= htmlspecialchars($tile['label']) ?></div>
            <div class="arc-stats__value"><?= htmlspecialchars($tile['value']) ?></div>
            <div class="arc-stats__change arc-stats__change--<?= $tile['change']['class'] ?>"><?= $tile['change']['text'] ?: '&nbsp;' ?></div>
        </div>
    <?php endforeach; ?>
</div>
