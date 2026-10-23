<?php $hasVisibleReactions = array_sum($reactionSummary['counts'] ?? []) > 0; ?>
<?php if ($canReact || $hasVisibleReactions): ?>
    <?php if ($canReact): ?><form class="reaction-bar" method="post" action="<?= $e($reactionAction) ?>" aria-label="<?= $e($t('Reactions')) ?>"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><?php else: ?><div class="reaction-bar" aria-label="<?= $e($t('Reactions')) ?>"><?php endif; ?>
        <?php foreach (\OpenWiki\Wiki\ReactionService::EMOJIS as $reactionEmoji): $reactionCount = (int) ($reactionSummary['counts'][$reactionEmoji] ?? 0); $isSelectedReaction = ($reactionSummary['selected'] ?? null) === $reactionEmoji; ?>
            <?php if ($canReact): ?>
                <button class="reaction-chip<?= $isSelectedReaction ? ' is-selected' : '' ?>" type="submit" name="emoji" value="<?= $e($reactionEmoji) ?>" aria-pressed="<?= $isSelectedReaction ? 'true' : 'false' ?>" aria-label="<?= $e($t('React with')) ?> <?= $e($reactionEmoji) ?>, <?= $reactionCount ?>" title="<?= $e($t('React with')) ?> <?= $e($reactionEmoji) ?>">
                    <span aria-hidden="true"><?= $e($reactionEmoji) ?></span><span class="reaction-chip__count"><?= $reactionCount ?></span>
                </button>
            <?php elseif ($reactionCount > 0): ?>
                <span class="reaction-chip<?= $isSelectedReaction ? ' is-selected' : '' ?>" aria-label="<?= $e($reactionEmoji) ?>, <?= $reactionCount ?>"><span aria-hidden="true"><?= $e($reactionEmoji) ?></span><span class="reaction-chip__count"><?= $reactionCount ?></span></span>
            <?php endif; ?>
        <?php endforeach; ?>
    <?php if ($canReact): ?></form><?php else: ?></div><?php endif; ?>
<?php endif; ?>
