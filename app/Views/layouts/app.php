<?php
use App\Core\Auth;
$u = Auth::user();
$can = fn (string $p) => Auth::can($p);
$path = (new App\Core\Request())->path();
$nav = fn (string $href, string $label) =>
    '<a class="' . ($path === $href ? 'active' : '') . '" href="' . base_url($href) . '">' . $label . '</a>';
?><!doctype html>
<html lang="en" data-theme="">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title ?? 'FAOS BOS') ?> · FAOS</title>
<link rel="stylesheet" href="<?= base_url('/assets/css/app.css') ?>">
<script>window.__CSRF__=<?= json_encode(App\Core\Csrf::token()) ?>;window.__CURRENCY__=<?= json_encode(App\Core\Config::get('CURRENCY_SYMBOL','RM')) ?>;</script>
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand">FAOS BOS<small>QR Kiosk + Central Kitchen</small></div>
    <nav class="nav">
      <?php if ($can('dashboard.worker')): ?>
        <div class="grp">Operations</div>
        <?= $nav('/worker', '🧾 Worker Dashboard') ?>
      <?php endif; ?>
      <?php if ($can('sales.scan')): ?><?= $nav('/sales', '📷 QR Sales') ?><?php endif; ?>
      <?php if ($can('stock.scan')): ?><?= $nav('/stock', '📦 QR Stock') ?><?php endif; ?>
      <?php if ($can('dashboard.outlet')): ?>
        <?= $nav('/outlet', $u['role_code'] === 'restaurant_manager' ? '🍽️ Restaurant Dashboard' : '🏪 Outlet Dashboard') ?>
      <?php endif; ?>
      <?php if ($can('dashboard.hq') || $can('finance.view')): ?>
        <div class="grp">Management</div>
      <?php endif; ?>
      <?php if ($can('dashboard.hq')): ?><?= $nav('/hq', '📊 HQ Dashboard') ?><?php endif; ?>
      <?php if ($can('finance.view')): ?><?= $nav('/finance', '💰 Finance') ?><?php endif; ?>
      <?php if ($can('finance.view')): ?><?= $nav('/bank-recon', '🏦 Bank Reconciliation') ?><?php endif; ?>
      <?php if ($can('ai.view')): ?><?= $nav('/ai', '🤖 AI Forecasting') ?><?php endif; ?>
      <?php if ($can('reports.view')): ?><?= $nav('/reports', '📈 Reports') ?><?php endif; ?>
      <?php if ($can('reconciliation.manage')): ?><?= $nav('/reconciliation', '🔁 Reconciliation') ?><?php endif; ?>
      <?php if ($can('qr.print')): ?><?= $nav('/qr-labels', '🏷️ QR Labels') ?><?php endif; ?>
      <?php if ($can('procurement.manage') || $can('kitchen.manage') || $can('stock.manage')): ?>
        <div class="grp">Supply Chain</div>
        <?php if ($can('procurement.manage')): ?><?= $nav('/procurement', '🚚 Procurement') ?><?php endif; ?>
        <?php if ($can('kitchen.manage')): ?><?= $nav('/production', '🍳 Central Kitchen') ?><?php endif; ?>
        <?php if ($can('stock.manage')): ?><?= $nav('/replenishment', '📦 Replenishment') ?><?php endif; ?>
      <?php endif; ?>
      <?php if ($can('masterdata.manage')): ?>
        <div class="grp">Master Data</div>
        <?= $nav('/master/products', '🍔 Products') ?>
        <?= $nav('/master/outlets', '📍 Outlets') ?>
        <?= $nav('/master/kiosks', '🛒 Kiosks') ?>
        <?= $nav('/master/suppliers', '🚚 Suppliers') ?>
        <?= $nav('/master/recipes', '📋 Recipes') ?>
      <?php endif; ?>
    </nav>
  </aside>
  <div class="main">
    <header class="topbar">
      <button class="btn-icon menu-toggle" aria-label="Menu">☰</button>
      <h1><?= e($title ?? 'Dashboard') ?></h1>
      <button class="btn-icon" data-action="theme" title="Toggle theme">◐</button>
      <span class="muted" style="font-size:13px"><?= e($u['full_name']) ?> · <?= e($u['role_name']) ?></span>
      <form method="post" action="<?= base_url('/logout') ?>" style="margin:0">
        <?= csrf_field() ?>
        <button class="btn gray" style="padding:7px 12px">Logout</button>
      </form>
    </header>
    <main class="content">
      <?= $content ?>
    </main>
  </div>
</div>
<script>window.__CSRF__=<?= json_encode(App\Core\Csrf::token()) ?>;</script>
<script src="<?= base_url('/assets/js/app.js') ?>"></script>
<script src="<?= base_url('/assets/js/scanner.js') ?>"></script>
<?= $scripts ?? '' ?>
</body>
</html>
