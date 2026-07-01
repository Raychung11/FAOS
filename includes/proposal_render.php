<?php
/**
 * AdvisorOS — Proposal document renderer.
 * Produces a self-contained, print-optimised HTML document from a
 * stored proposal (point-in-time snapshot + advisor narrative). The
 * same markup is used for on-screen view, browser print-to-PDF and
 * DomPDF server-side rendering, so the output is always identical.
 */

declare(strict_types=1);

/** True when DomPDF is installed (optional Phase 2 dependency). */
function dompdf_available(): bool
{
    $autoload = APP_ROOT . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }
    return class_exists(\Dompdf\Dompdf::class);
}

/**
 * @param array $p     proposals row (content holds JSON snapshot+narrative)
 * @param array $brand tenant_brand() result
 */
function render_proposal_html(array $p, array $brand): string
{
    $data = json_decode((string) ($p['content'] ?? ''), true) ?: [];
    $snap = $data['snapshot'] ?? [];
    $cl   = $snap['client']    ?? [];
    $fin  = $snap['financial'] ?? null;
    $risk = $snap['risk']      ?? null;

    $primary = $brand['primary'] ?? '#0B1F3A';
    $accent  = $brand['accent']  ?? '#C9A227';
    $company = $brand['name']    ?? APP_NAME;

    $sec = static function (string $title, ?string $body): string {
        $body = trim((string) $body);
        if ($body === '') {
            $body = '<span class="muted">Not provided.</span>';
        } else {
            $body = nl2br(e($body));
        }
        return '<section><h2>' . e($title) . '</h2><div class="body">'
             . $body . '</div></section>';
    };

    $row = static fn (string $k, $v): string =>
        '<tr><td class="k">' . e($k) . '</td><td>' . e((string) ($v ?? '—')) . '</td></tr>';

    // --- Profile table ---
    $profile = '<table class="kv">'
        . $row('Full name', $cl['full_name'] ?? '—')
        . $row('NRIC / Passport', $cl['nric_passport'] ?? '—')
        . $row('Date of birth', !empty($cl['dob']) ? fmt_date($cl['dob']) : '—')
        . $row('Occupation', $cl['occupation'] ?? '—')
        . $row('Employer', $cl['employer'] ?? '—')
        . $row('Marital status', isset($cl['marital_status']) ? label($cl['marital_status']) : '—')
        . $row('Dependents', $cl['dependents'] ?? '0')
        . '</table>';

    // --- Financial snapshot ---
    if ($fin) {
        $financial = '<table class="kv">'
            . $row('Monthly income', money($fin['monthly_income'] ?? 0))
            . $row('Monthly expenses', money($fin['monthly_expenses'] ?? 0))
            . $row('Total assets', money($fin['total_assets'] ?? 0))
            . $row('Total liabilities', money($fin['total_liabilities'] ?? 0))
            . $row('Insurance coverage', money($fin['insurance_coverage'] ?? 0))
            . $row('Investments', money($fin['investments_value'] ?? 0))
            . $row('Emergency fund', money($fin['emergency_fund'] ?? 0))
            . $row('Retirement target', money($fin['retirement_target'] ?? 0))
            . '</table>'
            . '<p class="score">Financial Health Score: <strong>'
            . (int) ($fin['health_score'] ?? 0) . ' / 100</strong></p>';
    } else {
        $financial = '<span class="muted">No financial snapshot was on record '
                   . 'when this proposal was generated.</span>';
    }

    // --- Risk ---
    if ($risk) {
        $riskBlock = '<table class="kv">'
            . $row('Classification', label($risk['classification'] ?? null))
            . $row('Score', $risk['score'] ?? '—')
            . $row('Assessed on', !empty($risk['assessed_on']) ? fmt_date($risk['assessed_on']) : '—')
            . '</table>';
    } else {
        $riskBlock = '<span class="muted">No formal risk assessment on record.</span>';
    }

    // Pre-render narrative sections (closure calls are not valid inside
    // heredoc interpolation, so build them here).
    $secExec   = $sec('1. Executive Summary', $data['executive_summary'] ?? '');
    $goalsTxt  = trim((string) ($snap['goals'] ?? ''));
    $secGoals  = $goalsTxt !== '' ? nl2br(e($goalsTxt))
                                  : '<span class="muted">Not provided.</span>';
    $secGaps   = $sec('6. Current Gaps', $data['current_gaps'] ?? '');
    $secRecs   = $sec('7. Recommendations', $data['recommendations'] ?? '');
    $secAction = $sec('8. Action Plan', $data['action_plan'] ?? '');

    $genAt    = e((string) ($data['generated_at'] ?? $p['created_at'] ?? date('Y-m-d H:i:s')));
    $advisor  = e((string) ($data['advisor_name'] ?? ''));
    $title    = e($p['title'] ?? 'Advisory Proposal');
    $clientNm = e((string) ($cl['full_name'] ?? 'the client'));

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{$title}</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: "Helvetica Neue", Arial, sans-serif; color:#1c2433;
         margin:0; padding:0; font-size:13px; line-height:1.55; }
  .page { max-width: 840px; margin: 0 auto; padding: 40px; }
  .cover { background: {$primary}; color:#fff; padding:42px 40px;
           border-bottom:5px solid {$accent}; }
  .cover .co { letter-spacing:.16em; text-transform:uppercase;
               font-size:12px; color:{$accent}; }
  .cover h1 { margin:10px 0 6px; font-size:28px; }
  .cover .meta { color:#cdd6e4; font-size:12px; }
  section { margin: 26px 0; }
  h2 { color:{$primary}; font-size:16px; border-bottom:2px solid {$accent};
       padding-bottom:6px; margin:0 0 12px; }
  .body { white-space: normal; }
  table.kv { width:100%; border-collapse:collapse; }
  table.kv td { padding:7px 10px; border-bottom:1px solid #e7ebf1; vertical-align:top; }
  table.kv td.k { color:#6b7686; width:38%; }
  .score { margin-top:12px; font-size:14px; }
  .muted { color:#6b7686; }
  .disclaimer { margin-top:34px; background:#f3e9c8; border:1px solid #e6d18f;
                color:#6b551a; padding:14px 16px; font-size:11.5px; border-radius:6px; }
  .foot { margin-top:30px; padding-top:14px; border-top:1px solid #e7ebf1;
          color:#9aa4b4; font-size:11px; }
  @media print { .noprint { display:none !important; } .page{ padding:0 } }
</style>
</head>
<body>
  <div class="cover">
    <div class="co">{$company}</div>
    <h1>{$title}</h1>
    <div class="meta">Prepared for {$clientNm} &middot; generated {$genAt}
      &middot; advisor: {$advisor}</div>
  </div>
  <div class="page">
    {$secExec}
    <section><h2>2. Client Profile</h2><div class="body">{$profile}</div></section>
    <section><h2>3. Financial Snapshot</h2><div class="body">{$financial}</div></section>
    <section><h2>4. Financial Goals</h2><div class="body">{$secGoals}</div></section>
    <section><h2>5. Risk Profile</h2><div class="body">{$riskBlock}</div></section>
    {$secGaps}
    {$secRecs}
    {$secAction}

    <div class="disclaimer">
      This is not financial advice. Final recommendations must be reviewed
      and approved by a licensed financial advisor. This document is a
      servicing aid prepared by {$company} and does not constitute a
      contract or a guarantee of any financial outcome.
    </div>
    <div class="foot">{$company} &middot; AdvisorOS &middot; Confidential —
      prepared for the named client only.</div>
  </div>
</body>
</html>
HTML;
}
