<?php $e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
<section class="content-container card panel"><h1>Nie zapisano decyzji</h1><p role="alert"><?= $e($message) ?></p><a class="button" href="<?= $e($back) ?>">Otwórz bieżącą rewizję</a></section>
