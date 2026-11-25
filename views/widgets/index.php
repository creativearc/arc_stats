<?php
/**
 * @var array $reasons  reason => ['count' => n, 'pages' => [['url', 'link'], ...]]
 * @var array $rich     issue  => same shape
 *
 * Example pages link to Google's own inspection page for that URL when we
 * have one, so the reviewer lands on Google's explanation, not just the page.
 */
$path = function (string $url): string {
    $p = parse_url($url);
    $out = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');

    return $out === '' ? '/' : $out;
};

$groups = [
    'Why pages are not indexed' => $reasons,
    'Rich result issues'        => $rich,
];
?>
<?php foreach ($groups as $heading => $items): ?>
    <?php if (!$items) continue; ?>
    <div class="arc-stats__gap"></div>
    <p class="arc-stats__range"><?= htmlspecialchars($heading) ?></p>
    <ul class="arc-stats__reasons">
        <?php foreach ($items as $reason => $group): ?>
            <li>
                <div class="arc-stats__reason">
                    <span><?= htmlspecialchars($reason) ?></span>
                    <span class="num"><?= number_format($group['count']) ?></span>
                </div>
                <div class="arc-stats__examples">
                    <?php foreach ($group['pages'] as $i => $page): ?>
                        <?= $i ? ' · ' : '' ?><a href="<?= htmlspecialchars($page['link'] ?: $page['url']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($path($page['url'])) ?></a>
                    <?php endforeach; ?>
                    <?php if ($group['count'] > count($group['pages'])): ?>
                        · +<?= number_format($group['count'] - count($group['pages'])) ?> more
                    <?php endif; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endforeach; ?>
