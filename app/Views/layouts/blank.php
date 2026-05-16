<?php /** Minimal layout: login / error pages. */ ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'FAOS') ?> · FAOS BOS</title>
<link rel="stylesheet" href="<?= base_url('/assets/css/app.css') ?>">
</head>
<body>
<?= $content ?>
</body>
</html>
