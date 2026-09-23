<?php
$e = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<section class="content-container">
    <div class="page-heading">
        <div>
            <div class="eyebrow">Administration</div>
            <h1>Broken links</h1>
            <p class="muted">Wiki references that currently do not resolve to an existing page.</p>
        </div>
    </div>

    <section class="card panel">
        <div class="panel__header"><h2>Unresolved references</h2><span class="count"><?= count($links) ?></span></div>
        <?php if ($links === []): ?>
            <div class="empty-state">No broken wiki links.</div>
        <?php else: ?>
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr><th>Space</th><th>Source page</th><th>Missing target</th><th>Detected</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($links as $link): ?>
                        <tr>
                            <td><?= $e($link['space_name']) ?> <span class="space-key"><?= $e($link['space_key']) ?></span></td>
                            <td>
                                <a href="/spaces/<?= rawurlencode($link['space_key']) ?>/pages/<?= rawurlencode($link['source_slug']) ?>">
                                    <?= $e($link['source_title']) ?>
                                </a>
                            </td>
                            <td><code>[[<?= $e($link['target_reference']) ?>]]</code></td>
                            <td><?= $e($link['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>
