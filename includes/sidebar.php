<?php
/**
 * AdvisorOS — Role-aware navigation sidebar.
 * Items are filtered by the current user's role; the platform area is
 * exclusive to super admins, all other roles operate within a tenant.
 */

declare(strict_types=1);

$me      = current_user();
$role    = $me['role_code'];
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');

/**
 * Nav schema: [section => [ [label, file, icon, permission|null], ... ]]
 */
$nav = [];

if ($role === 'super_admin') {
    $nav['Platform'] = [
        ['Dashboard',      'superadmin/dashboard.php',     '▣', null],
        ['Tenants',        'superadmin/tenants.php',       '▤', 'platform.manage'],
        ['Subscriptions',  'superadmin/subscriptions.php', '◷', 'platform.manage'],
        ['Revenue',        'superadmin/revenue.php',       '$', 'platform.manage'],
        ['Tax Rates',      'superadmin/tax-rates.php',     '%', 'platform.manage'],
        ['Access Requests','superadmin/signups.php',       '✉', 'platform.manage'],
        ['Audit Trail',    'superadmin/audit.php',         '◈', 'audit.view'],
    ];
} elseif ($role === 'compliance_officer') {
    $nav['Compliance'] = [
        ['Dashboard',   'compliance/dashboard.php', '▣', null],
        ['Audit Trail', 'compliance/audit.php',     '◈', 'audit.view'],
        ['Clients',     'advisor/clients.php',      '◐', 'clients.view'],
        ['Documents',   'advisor/documents.php',    '▦', 'documents.manage'],
    ];
} elseif ($role === 'client') {
    $nav['My Portal'] = [
        ['Overview',     'client/portal.php',          '▣', null],
        ['Tax Planning', 'client/solution.php?key=tax','%', null],
        ['My Documents', 'client/documents.php',       '▦', null],
        ['Appointments', 'client/appointments.php',    '◷', null],
    ];
} else {
    // tenant_admin, agency_leader, financial_advisor
    $home = $role === 'tenant_admin' ? 'tenant/dashboard.php' : 'advisor/dashboard.php';
    $nav['Servicing'] = [
        ['Dashboard',      $home,                        '▣', null],
        ['Leads',          'advisor/leads.php',          '◎', 'leads.view'],
        ['Clients',        'advisor/clients.php',        '◐', 'clients.view'],
        ['Annual Reviews', 'advisor/reviews.php',        '★', 'reviews.manage'],
        ['Policies',       'advisor/policies.php',       '▤', 'policies.manage'],
        ['Appointments',   'advisor/appointments.php',   '◷', 'appointments.manage'],
        ['Follow-ups',     'advisor/followups.php',      '✓', 'appointments.manage'],
        ['Advisory Cases', 'advisor/cases.php',          '◫', 'cases.manage'],
        ['Proposals',      'advisor/proposals.php',      '◰', 'proposals.manage'],
        ['Skill Library',  'advisor/skills.php',         '◆', 'proposals.manage'],
        ['AI Assistant',   'advisor/ai-assistant.php',   '✦', 'clients.view'],
        ['Documents',      'advisor/documents.php',      '▦', 'documents.manage'],
    ];
    if ($role === 'tenant_admin') {
        $nav['Administration'] = [
            ['Users',         'tenant/users.php',    '◍', 'users.manage'],
            ['Commissions',   'tenant/commissions.php','$', 'commissions.manage'],
            ['Billing',       'tenant/billing.php',  '◉', 'tenant.manage'],
            ['Company',       'tenant/settings.php', '⚙', 'tenant.manage'],
            ['Audit Trail',   'tenant/audit.php',    '◈', 'audit.view'],
        ];
    }
}
?>
<aside class="sidebar" id="sidebar">
  <div class="brand"><?= e(APP_NAME) ?><span>OS</span></div>
  <nav class="nav-group">
    <?php foreach ($nav as $section => $items): ?>
      <div class="nav-label"><?= e($section) ?></div>
      <?php foreach ($items as [$label, $file, $icon, $perm]): ?>
        <?php if ($perm !== null && !has_permission($perm)) { continue; } ?>
        <?php $active = $current === basename(strtok($file, '?')) ? ' active' : ''; ?>
        <a class="nav-item<?= $active ?>" href="<?= e(url($file)) ?>">
          <span class="ic"><?= $icon ?></span><span><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div style="padding:14px 18px;border-top:1px solid rgba(255,255,255,.08);font-size:12px;color:#6b7c97">
    <?= e($brand['name'] ?? APP_NAME) ?>
  </div>
</aside>
