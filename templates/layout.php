<?php
/** @var string $title @var string $content @var string $assetVersion @var list<string> $styles */
$styles = $styles ?? ['/assets/css/app.css'];
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?></title>
<link rel="icon" href="/assets/img/favicon.svg" type="image/svg+xml">
<?php foreach ($styles as $href): ?>
<link rel="stylesheet" href="<?= e($href) ?>?v=<?= e($assetVersion) ?>">
<?php endforeach ?>
</head>
<body>
<?= $content ?>
</body>
</html>
