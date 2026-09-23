<?php use function OpenSendForm\Admin\h; ?>
<?php use function OpenSendForm\Admin\asset; ?>
<?php use function OpenSendForm\Admin\appbar; ?>
<!DOCTYPE html>
<html lang="en" data-palette="github">
<head>
    <?php /* First in <head>, blocking: applies the stored theme before first
             paint (shares the admin design system). */ ?>
    <script src="<?= h(asset('/assets/theme-init.js')) ?>"></script>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>osf - <?= h($title ?? 'Setup') ?></title>
    <link rel="stylesheet" href="<?= h(asset('/assets/tokens.css')) ?>">
    <link rel="stylesheet" href="<?= h(asset('/assets/admin.css')) ?>">
</head>
<body>
<?php /* The ONE header component, chrome-only variant. Row 2 carries the
         installer's step label in place of the (absent) tab strip. */ ?>
<?= appbar(['variant' => 'chrome-only', 'stepLabel' => $stepLabel ?? '']) ?>
<main class="container">
    <?php foreach (($flashes ?? []) as $message): ?>
        <p class="osf-flash osf-flash--error" role="alert"><?= h($message) ?></p>
    <?php endforeach; ?>
    <?= $content /* already-escaped view output */ ?>
</main>
<script src="<?= h(asset('/assets/admin.js')) ?>" defer></script>
<script src="<?= h(asset('/assets/install.js')) ?>" defer></script>
</body>
</html>
