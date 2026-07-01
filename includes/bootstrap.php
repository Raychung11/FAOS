<?php
/**
 * AdvisorOS — Application bootstrap.
 * Single entry point required by every page. Loads configuration, the
 * database connection, shared libraries and starts a hardened session.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_config.php';   // pulls in config.php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/tenant.php';
require_once __DIR__ . '/permissions.php';
require_once __DIR__ . '/audit_log.php';

start_secure_session();
