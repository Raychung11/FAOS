<?php
/**
 * AdvisorOS — Authenticated layout: opening shell.
 * Usage:
 *   $pageTitle = 'Dashboard';
 *   require __DIR__ . '/../includes/header.php';
 *   ... page content ...
 *   require __DIR__ . '/../includes/footer.php';
 */

declare(strict_types=1);

if (!is_logged_in()) {
    redirect('login.php');
}

$pageTitle = $pageTitle ?? 'Dashboard';
$brand     = function_exists('tenant_brand') ? tenant_brand() : ['name' => APP_NAME, 'primary' => '#0B1F3A', 'accent' => '#C9A227'];
$me        = current_user();
$initials  = strtoupper(substr(trim($me['name']), 0, 1) ?: 'A');
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
<style>
  :root { --navy: <?= e($brand['primary']) ?>; --gold: <?= e($brand['accent']) ?>; }
</style>
</head>
<body>
<div class="app">
  <?php require __DIR__ . '/sidebar.php'; ?>
  <div class="main">
    <header class="topbar">
      <div style="display:flex;align-items:center;gap:14px">
        <button id="sidebarToggle" class="btn-os ghost sm" style="display:none" aria-label="Menu">☰</button>
        <div class="page-title"><?= e($pageTitle) ?></div>
      </div>
      <div class="user-chip">
        <div class="avatar"><?= e($initials) ?></div>
        <div style="line-height:1.2">
          <div style="font-weight:600;font-size:13px"><?= e($me['name']) ?></div>
          <div class="role-tag"><?= e($me['role_name']) ?></div>
        </div>
        <a class="btn-os ghost sm" href="<?= e(url('logout.php')) ?>" style="margin-left:8px">Sign out</a>
      </div>
    </header>
    <main class="content">
      <?php foreach (get_flashes() as $f): ?>
        <div class="alert-os <?= e($f['type']) ?>" data-auto><?= e($f['message']) ?></div>
      <?php endforeach; ?>
