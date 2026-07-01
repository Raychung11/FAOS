<?php
/** AdvisorOS — Logout. */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in()) {
    audit_log('logout', 'auth', (int) current_user()['id'], 'User signed out');
}
logout_user();
start_secure_session();
set_flash('info', 'You have been signed out.');
redirect('login.php');
