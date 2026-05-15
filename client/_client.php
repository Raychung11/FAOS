<?php
/** AdvisorOS — Resolve the client record bound to the logged-in portal user. */
declare(strict_types=1);

function portal_client(): array
{
    require_login();
    require_role('client');
    $tid = require_tenant();
    $st = db()->prepare(
        'SELECT * FROM clients WHERE portal_user_id=? AND tenant_id=? AND deleted_at IS NULL'
    );
    $st->execute([(int) current_user()['id'], $tid]);
    $c = $st->fetch();
    if (!$c) {
        http_response_code(403);
        exit('Your portal is not yet linked to a client profile. Please contact your advisor.');
    }
    return $c;
}
