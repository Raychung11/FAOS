<?php
/** AdvisorOS — Entry point. Routes to the role dashboard or login. */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

if (is_logged_in() || attempt_remember_login()) {
    redirect(role_home(current_user()['role_code']));
}
redirect('login.php');
