<?php
/**
 * @var string $caption
 * @var array  $columns  [label, label, ...] — first is the text column, the rest numeric
 * @var array  $rows     [[text, formatted, formatted, ...], ...]
 * @var array  $weights  raw value per row driving the bar (same order as $rows)
 * @var string $empty    optional message when there are no rows
 */
$max = $weights ? max(max($weights), 1) : 1;
?>
<?php if ($caption !== ''): ?>
    <p class="arc-stats__range"><?= htmlspecialchars($caption) ?></p>
<?php endif; ?>
<?php if (!$rows): ?>
    <p class="arc-stats__msg"><?= htmlspecialchars($empty ?? lang('as_no_data')) ?></p>
<?php else: ?>
    <table class="arc-stats__table">
        <thead>
            <tr>
                <?php foreach ($columns as $i => $label): ?>
                    <th<?= $i ? ' class="num"' : '' ?>><?= htmlspecialchars($label) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r => $row): ?>
                <tr>
                    <?php foreach ($row as $i => $cell): ?>
                        <?php if ($i === 0): ?>
                            <td class="arc-stats__bar" style="--w: <?= round(($weights[$r] ?? 0) / $max * 100, 1) ?>%"><span><?= htmlspecialchars($cell) ?></span></td>
                        <?php else: ?>
                            <td class="num"><?= htmlspecialchars($cell) ?></td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
