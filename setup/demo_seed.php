<?php
/**
 * AdvisorOS — Demo data seeder.
 *
 * Populates the demo tenant (slug "demo-advisory") with one fully
 * fleshed-out client plus related records across every module so each
 * feature can be tried immediately. Idempotent: detects the demo client
 * by NRIC "DEMO-CLIENT-001" and skips if it already exists.
 *
 * CLI:  php setup/demo_seed.php
 * Web:  /setup/demo_seed.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/db_config.php';
require_once __DIR__ . '/../includes/functions.php';

$isCli = PHP_SAPI === 'cli';
$out = static function (string $l) use ($isCli): void {
    echo $isCli ? $l . "\n" : nl2br(htmlspecialchars($l, ENT_QUOTES)) . "<br>\n";
};
if (!$isCli) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre style="font-family:monospace;background:#0B1F3A;color:#cdd6e4;padding:20px">';
}

try {
    $pdo = db();

    // --- Resolve demo tenant + key users -------------------------
    $tenantId = (int) $pdo->query(
        "SELECT id FROM tenants WHERE slug='demo-advisory' AND deleted_at IS NULL"
    )->fetchColumn();
    if (!$tenantId) {
        throw new RuntimeException(
            'Demo tenant not found. Run setup/install.php first.'
        );
    }

    $userId = static function (string $email) use ($pdo): ?int {
        $s = $pdo->prepare('SELECT id FROM users WHERE email=? AND deleted_at IS NULL');
        $s->execute([$email]);
        $v = $s->fetchColumn();
        return $v ? (int) $v : null;
    };
    $adminId   = $userId('admin@demo-advisory.test');
    $advisorId = $userId('advisor@demo-advisory.test');
    if (!$advisorId) {
        throw new RuntimeException('Demo advisor user missing. Re-run setup/install.php.');
    }
    $creator = $adminId ?: $advisorId;

    // --- Idempotency guard ---------------------------------------
    $exists = $pdo->prepare(
        "SELECT id FROM clients WHERE tenant_id=? AND nric_passport='DEMO-CLIENT-001'"
    );
    $exists->execute([$tenantId]);
    if ($cid = (int) $exists->fetchColumn()) {
        $out('Demo data already present (client #' . $cid . '). Nothing to do.');
        $out('Delete that client in phpMyAdmin to reseed, then run this again.');
        if (!$isCli) { echo '</pre>'; }
        exit;
    }

    $pdo->beginTransaction();

    // --- Client-portal user --------------------------------------
    $clientRoleId = (int) $pdo->query("SELECT id FROM roles WHERE code='client'")->fetchColumn();
    $portalUserId = $userId('client@demo-advisory.test');
    if (!$portalUserId) {
        $pdo->prepare(
            'INSERT INTO users (tenant_id,role_id,name,email,password_hash,status,created_by)
             VALUES (?,?,?,?,?,"active",?)'
        )->execute([$tenantId,$clientRoleId,'Sarah Lim (Demo Client)',
            'client@demo-advisory.test',
            password_hash('Admin@12345', PASSWORD_DEFAULT), $creator]);
        $portalUserId = (int) $pdo->lastInsertId();
    }

    // --- Client ---------------------------------------------------
    $pdo->prepare(
        'INSERT INTO clients
          (tenant_id,full_name,nric_passport,dob,gender,marital_status,phone,email,
           address,occupation,employer,industry,dependents,spouse_name,children_info,
           risk_appetite,financial_goals,advisor_id,portal_user_id,last_review_date,
           next_review_date,status,created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,"active",?)'
    )->execute([
        $tenantId,'Sarah Lim','DEMO-CLIENT-001','1988-06-14','female','married',
        '+60123456789','client@demo-advisory.test','12 Jalan Bahagia, 50000 Kuala Lumpur',
        'Senior Engineer','TechWorks Sdn Bhd','Technology',2,'David Lim',
        '2 children, ages 6 and 9','balanced',
        "Retire comfortably by age 60; fund children's tertiary education; "
        . "maintain 6-month emergency buffer.",
        $advisorId,$portalUserId,
        date('Y-m-d', strtotime('-11 months')),
        date('Y-m-d', strtotime('+20 days')),   // review due soon
        $creator,
    ]);
    $clientId = (int) $pdo->lastInsertId();

    // --- Financial snapshot (+ health score) ---------------------
    $fin = [
        'monthly_income'=>14000,'monthly_expenses'=>8200,'total_assets'=>520000,
        'total_liabilities'=>180000,'insurance_coverage'=>600000,
        'investments_value'=>95000,'emergency_fund'=>38000,'retirement_target'=>2500000,
    ];
    $score = financial_health_score($fin);
    $pdo->prepare(
        'INSERT INTO financial_profiles
          (tenant_id,client_id,monthly_income,monthly_expenses,total_assets,
           total_liabilities,insurance_coverage,investments_value,emergency_fund,
           retirement_target,health_score,snapshot_date,created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,CURDATE(),?)'
    )->execute([$tenantId,$clientId,...array_values($fin),$score,$creator]);

    // --- Borrowing capability inputs (settings k/v) --------------
    $pdo->prepare(
        'INSERT INTO settings (tenant_id,setting_key,setting_value)
         VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)'
    )->execute([$tenantId,'cap:' . $clientId, json_encode([
        'monthly_debt'=>2500.0,'avg_rate'=>4.2,'remaining_years'=>22.0,
        'dsr_cap'=>60.0,'new_rate'=>4.5,'new_years'=>10.0,
        'refi_rate'=>3.4,'lump_sum'=>20000.0,
    ])]);

    // --- Risk profile --------------------------------------------
    $pdo->prepare(
        'INSERT INTO risk_profiles
          (tenant_id,client_id,answers,score,classification,advisor_notes,
           assessed_on,created_by)
         VALUES (?,?,?,?,?,?,?,?)'
    )->execute([$tenantId,$clientId,
        // Option indexes matching the risk questionnaire (all "moderate"
        // -> balanced, score 50). Keeps the pre-fill + bands consistent.
        json_encode(['experience'=>2,'horizon'=>2,'tolerance'=>2,'liquidity'=>2,
                     'income'=>2,'objective'=>2,'knowledge'=>2,'volatility'=>2]),
        50,'balanced','Comfortable with moderate volatility for long-term growth.',
        date('Y-m-d', strtotime('-1 month')),$creator]);

    // --- Products + policies -------------------------------------
    $pdo->prepare(
        'INSERT INTO products (tenant_id,name,category,provider,is_active,created_by)
         VALUES (?,?,?,?,1,?)'
    )->execute([$tenantId,'SecureLife Term 30','insurance','Great Eastern',$creator]);
    $prodId = (int) $pdo->lastInsertId();

    $policies = [
        ['insurance','Great Eastern','GE-TERM-88231',420.00,'monthly',600000,
         '-3 years','+45 days','David Lim','active'],
        ['investment_linked','Prudential','PRU-ILP-55012',300.00,'monthly',150000,
         '-2 years','+8 months','Estate','active'],
        ['medical_card','AIA','AIA-MED-77410',180.00,'monthly',1000000,
         '-4 years','-10 days','—','active'],   // renewal overdue (alert)
    ];
    foreach ($policies as $p) {
        $pdo->prepare(
            'INSERT INTO policies
              (tenant_id,client_id,product_id,category,provider,policy_number,premium,
               payment_frequency,coverage_amount,start_date,renewal_date,beneficiary,
               status,created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([$tenantId,$clientId,
            $p[0]==='insurance'?$prodId:null,$p[0],$p[1],$p[2],$p[3],$p[4],$p[5],
            date('Y-m-d', strtotime($p[6])),date('Y-m-d', strtotime($p[7])),
            $p[8],$p[9],$creator]);
    }

    // --- Annual review (one completed in history) ----------------
    $pdo->prepare(
        'INSERT INTO annual_reviews
          (tenant_id,client_id,advisor_id,review_date,next_review_date,checklist,
           financial_changes,policy_changes,goal_updates,risk_review,recommendation,
           client_acknowledged,acknowledged_at,status,created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,1,?,?,?)'
    )->execute([$tenantId,$clientId,$advisorId,
        date('Y-m-d', strtotime('-11 months')),
        date('Y-m-d', strtotime('+20 days')),
        json_encode(['contact_updated'=>true,'financial_updated'=>true,
            'policies_reviewed'=>true,'goals_reviewed'=>true,'risk_reviewed'=>true,
            'beneficiary_check'=>true,'docs_complete'=>false]),
        'Income increased ~8%; expenses stable.',
        'No changes; medical card renewal upcoming.',
        'Education funding goal brought forward by 1 year.',
        'Risk tolerance unchanged — balanced.',
        'Increase ILP top-up by RM150/month; review protection at next cycle.',
        date('Y-m-d H:i:s', strtotime('-11 months +2 days')),
        'completed',$creator]);

    // --- Appointments --------------------------------------------
    foreach ([
        ['annual_review','Annual Review Meeting','+20 days','scheduled'],
        ['policy_review','Medical Card Renewal Discussion','+3 days','scheduled'],
        ['first_consultation','Initial Consultation','-11 months','completed'],
    ] as $a) {
        $pdo->prepare(
            'INSERT INTO appointments
              (tenant_id,client_id,advisor_id,type,title,scheduled_at,location,
               notes,status,created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([$tenantId,$clientId,$advisorId,$a[0],$a[1],
            date('Y-m-d 10:00:00', strtotime($a[2])),'Office / Video',
            'Auto-generated demo appointment.',$a[3],$creator]);
    }

    // --- Follow-ups ----------------------------------------------
    foreach ([
        ['Send medical card renewal quote','-2 days','high','pending'],
        ['Prepare annual review pack','+10 days','medium','pending'],
        ['Share education-fund projection','-1 month','low','done'],
    ] as $f) {
        $pdo->prepare(
            'INSERT INTO follow_ups
              (tenant_id,client_id,assigned_to,title,description,due_date,priority,
               status,completed_at,created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([$tenantId,$clientId,$advisorId,$f[0],
            'Demo follow-up task.',date('Y-m-d', strtotime($f[1])),$f[2],$f[3],
            $f[3]==='done'?date('Y-m-d H:i:s', strtotime('-25 days')):null,$creator]);
    }

    // --- Advisory case -------------------------------------------
    $pdo->prepare(
        'INSERT INTO advisory_cases
          (tenant_id,client_id,advisor_id,type,objective,current_issue,
           recommendations,priority,status,notes,created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([$tenantId,$clientId,$advisorId,'retirement_planning',
        'Achieve RM2.5M retirement corpus by age 60',
        'Current trajectory falls ~18% short of target.',
        'Increase monthly investment; review asset allocation annually.',
        'high','in_review','Demo advisory case.',$creator]);

    // --- Leads ----------------------------------------------------
    $pdo->prepare(
        'INSERT INTO leads
          (tenant_id,name,phone,email,source,product_interest,budget_range,
           assigned_to,status,follow_up_date,notes,created_by)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
    )->execute([$tenantId,'Marcus Tan','+60198887766','marcus@example.com',
        'Referral','Retirement planning','RM500-1000/mo',$advisorId,'qualified',
        date('Y-m-d', strtotime('+5 days')),'Referred by Sarah Lim.',$creator]);

    // --- Document (real file so download works) -------------------
    @mkdir(APP_ROOT . '/uploads/documents', 0775, true);
    $stored = 't' . $tenantId . '_demo_consent.txt';
    @file_put_contents(APP_ROOT . '/uploads/documents/' . $stored,
        "DEMO CONSENT FORM\nClient: Sarah Lim\nThis is a placeholder demo document.\n");
    $pdo->prepare(
        'INSERT INTO documents
          (tenant_id,client_id,category,original_name,stored_name,mime_type,
           file_size,version,uploaded_by,created_by)
         VALUES (?,?,?,?,?,?,?,1,?,?)'
    )->execute([$tenantId,$clientId,'consent_form','consent_form.txt',$stored,
        'text/plain',
        (int) @filesize(APP_ROOT . '/uploads/documents/' . $stored),
        $advisorId,$creator]);

    // --- Commission ----------------------------------------------
    $pdo->prepare(
        'INSERT INTO commissions
          (tenant_id,advisor_id,client_id,sale_amount,premium,commission,
           override_amount,status,created_by)
         VALUES (?,?,?,?,?,?,?,?,?)'
    )->execute([$tenantId,$advisorId,$clientId,5040.00,5040.00,1260.00,
        252.00,'pending',$creator]);

    // --- Proposal -------------------------------------------------
    $proposal = [
        'executive_summary' => 'Sarah is financially stable with a strong income '
            . 'but has a modest retirement-funding gap and an upcoming medical '
            . 'card renewal that needs attention.',
        'current_gaps' => "Retirement trajectory ~18% below target.\n"
            . 'Medical card renewal overdue.',
        'recommendations' => 'Top up the investment-linked plan by RM150/month '
            . 'and renew the medical card promptly.',
        'action_plan' => "1. Renew medical card within 14 days.\n"
            . "2. Increase ILP contribution.\n3. Review at the annual meeting.",
        'snapshot' => [
            'client' => ['full_name'=>'Sarah Lim','nric_passport'=>'DEMO-CLIENT-001',
                'dob'=>'1988-06-14','occupation'=>'Senior Engineer',
                'employer'=>'TechWorks Sdn Bhd','marital_status'=>'married',
                'dependents'=>2],
            'financial' => $fin + ['health_score'=>$score],
            'risk' => ['classification'=>'balanced','score'=>50,
                'assessed_on'=>date('Y-m-d', strtotime('-1 month'))],
            'goals' => "Retire by 60; fund children's education.",
        ],
        'generated_at' => date('Y-m-d H:i:s'),
        'advisor_name' => 'Demo Advisor',
    ];
    $pdo->prepare(
        'INSERT INTO proposals
          (tenant_id,client_id,advisor_id,title,content,status,created_by)
         VALUES (?,?,?,?,?,?,?)'
    )->execute([$tenantId,$clientId,$advisorId,
        'Advisory Proposal — Sarah Lim',
        json_encode($proposal, JSON_UNESCAPED_UNICODE),'draft',$creator]);

    $pdo->commit();

    $out('=============================================================');
    $out(' Demo data seeded successfully for tenant "demo-advisory".');
    $out('-------------------------------------------------------------');
    $out(' Client: Sarah Lim  (financial health score ' . $score . '/100)');
    $out(' Records: snapshot, risk, 3 policies, 1 annual review,');
    $out('          3 appointments, 3 follow-ups, 1 case, 1 lead,');
    $out('          1 document, 1 commission, 1 proposal.');
    $out('');
    $out(' Try it as the advisor:  advisor@demo-advisory.test / Admin@12345');
    $out(' Client portal login:    client@demo-advisory.test  / Admin@12345');
    $out('=============================================================');
    $out(' SECURITY: delete the setup/ directory when finished testing.');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
    http_response_code(500);
    $out('DEMO SEED FAILED: ' . $e->getMessage());
}

if (!$isCli) { echo '</pre>'; }
