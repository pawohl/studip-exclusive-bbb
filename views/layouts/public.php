<!DOCTYPE html>
<html lang="<?= htmlReady(substr($_SESSION['_language'] ?? 'de_DE', 0, 2)) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <? if (!empty($auto_refresh)): ?>
        <meta http-equiv="refresh" content="<?= (int) $auto_refresh ?>">
    <? endif ?>
    <title><?= htmlReady(PageLayout::getTitle()) ?></title>
    <link rel="stylesheet" href="<?= htmlReady($stylesheet) ?>">
</head>
<body>
    <main class="exbbb-card">
        <? if ($logo): ?>
            <img class="exbbb-logo" src="<?= htmlReady($logo) ?>" alt="">
        <? endif ?>
        <?= $content_for_layout ?>
    </main>
</body>
</html>
