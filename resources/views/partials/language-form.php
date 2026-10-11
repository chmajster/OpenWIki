<form method="post" action="/language" class="language-form">
    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
    <input type="hidden" name="back" value="<?= $e($_SERVER['REQUEST_URI'] ?? '/') ?>">
    <label class="sr-only" for="interface-language"><?= $e($t('Interface language')) ?></label>
    <select name="locale" id="interface-language"><option value="pl" <?= $locale === 'pl' ? 'selected' : '' ?>>Polski</option><option value="en" <?= $locale === 'en' ? 'selected' : '' ?>>English</option></select>
    <button class="button button--ghost" type="submit"><?= $e($t('Apply')) ?></button>
</form>
