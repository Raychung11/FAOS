<?php
/** AdvisorOS — Public (unauthenticated) layout shell. */
declare(strict_types=1);
$pageTitle = $pageTitle ?? 'Sign in';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body>
<div class="auth-wrap">
  <div class="auth-brand">
    <h1><?= e(APP_NAME) ?></h1>
    <p>The wealth advisory platform for business owners in Malaysia.
       Personal &amp; business wealth, valuation, equity structure,
       succession and tax in one place.</p>
    <ul>
      <li>Never miss a client annual review again</li>
      <li>Track policies, renewals and servicing in one place</li>
      <li>Compliance-ready audit trail on every action</li>
      <li>Multi-tenant, secure and built for advisory firms</li>
    </ul>
  </div>
  <div class="auth-form">
    <div class="auth-card">
      <?php foreach (get_flashes() as $f): ?>
        <div class="alert-os <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
      <?php endforeach; ?>
