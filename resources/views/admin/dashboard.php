<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$sections = [
    ['Users', '/admin/users', $counts['users']],
    ['Groups', '/admin/groups', $counts['groups']],
    ['Roles', '/admin/roles', $counts['roles']],
    ['Spaces', '/', $counts['spaces']],
    ['Pages', '/', $counts['pages']],
    ['Broken links', '/admin/broken-links', $counts['broken_links']],
];
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">System</div>
            <h1>Administration</h1>
            <p class="muted">Identity, access and Wiki integrity management.</p>
        </div>
    </div>
    <div class="stat-grid">
        <?php foreach ($sections as [$label, $url, $count]): ?>
            <a class="card stat-card" href="<?= $e($url) ?>">
                <span><?= $e($label) ?></span>
                <strong><?= (int) $count ?></strong>
            </a>
        <?php endforeach; ?>
    </div>
</section>
