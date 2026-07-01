<?php
/** AdvisorOS — Business-owner logout. */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/owner_auth.php';

if (is_post()) { csrf_check(); }
owner_logout();
set_flash('success', 'You have been signed out.');
redirect('index.php');
