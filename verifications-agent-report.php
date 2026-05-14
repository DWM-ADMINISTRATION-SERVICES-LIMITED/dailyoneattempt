<?php
/**
 * Verifications Weekly Round-Up - agent-facing version
 *
 * Runs Monday mornings. Fetches the previous Mon-Sat from MaxContact,
 * builds an agent-friendly HTML report with leaderboards, and emails
 * it to the agent distribution list.
 *
 * Usage:
 *   php verifications-agent-report.php                          # previous Mon-Sat
 *   php verifications-agent-report.php 2026-04-20 2026-04-25    # specific range
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/maxcontact.php';

if (file_exists(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';
} else {
    define('SMTP_HOST',      getenv('SMTP_HOST'));
    define('SMTP_PORT',      (int) getenv('SMTP_PORT'));
    define('SMTP_USER',      getenv('SMTP_USER'));
    define('SMTP_PASS',      getenv('SMTP_PASS'));
    define('EMAIL_FROM',     getenv('EMAIL_FROM'));
    define('EMAIL_FROM_NAME', getenv('EMAIL_FROM_NAME'));
    define('MC_BASE_URL',    getenv('MC_BASE_URL'));
    define('MC_USERNAME',    getenv('MC_USERNAME'));
    define('MC_PASSWORD',    getenv('MC_PASSWORD'));
    define('MC_CAMPAIGN_ID', (int) getenv('MC_CAMPAIGN_ID'));
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Recipients ──
// In test mode the email only goes to Ryan. When ready, set the agent
// distribution list address in AGENT_DIST and flip TEST_MODE to false.
const TEST_MODE  = false;
const AGENT_DIST = 'verification@dwmas.co.uk';

// Agents to exclude from leaderboards/team totals (non-calling staff)
const EXCLUDED_AGENTS = ['Tina Tigere'];

const SUCCESS_CODES = ['DuelES', 'DuelSale', 'ElecSale', 'ESsale', 'FuelES', 'GasSale'];
const CANCEL_CODES  = ['VoidBadExp', 'VoidChgMnd', 'VoidDDDate', 'VoidDDQues', 'VoidDeadLn', 'VoidDebt', 'VoidLangBr', 'VoidNoCon', 'VoidNoDMC', 'VoidSwitch', 'VoidWrgDet', 'Vulnerable'];

function log_msg($msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

// ── Determine date range ──
if (isset($argv[1], $argv[2]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[2])) {
    $start = new DateTime($argv[1]);
    $end   = new DateTime($argv[2]);
} else {
    $end = new DateTime();
    $end->modify('previous saturday');
    $start = clone $end;
    $start->modify('-5 days');
}

log_msg("Range: " . $start->format('d/m/Y') . " to " . $end->format('d/m/Y'));

// ── Fetch CSVs day-by-day ──
$header  = null;
$allRows = [];

for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
    $dateStr = $d->format('d/m/Y');
    log_msg("Fetching $dateStr...");
    [$csv, $err] = fetchMaxContactCSV($dateStr);
    if ($err) {
        log_msg("FETCH ERROR for $dateStr: $err");
        continue;
    }
    $lines = preg_split('/\r?\n/', trim($csv));
    if (empty($lines)) continue;
    $headerLine = array_shift($lines);
    if ($header === null) $header = str_getcsv($headerLine);
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $allRows[] = str_getcsv($line);
    }
}

log_msg("Total rows: " . count($allRows));

if (empty($allRows) || $header === null) {
    log_msg("No data - aborting.");
    exit(0);
}

// ── Column indices ──
$idx = [
    'fullname'              => array_search('fullname', $header),
    'resultcode'            => array_search('resultcode', $header),
    'resultcodedescription' => array_search('resultcodedescription', $header),
];

foreach ($idx as $k => $v) {
    if ($v === false) {
        log_msg("ERROR: Missing column '$k' in CSV");
        exit(1);
    }
}

// ── Aggregate per agent (excluding non-calling staff) ──
$agents = [];
$cancelReasons = [];

foreach ($allRows as $row) {
    $agent  = trim($row[$idx['fullname']] ?? '');
    if ($agent === '' || in_array($agent, EXCLUDED_AGENTS, true)) continue;

    $rc     = trim($row[$idx['resultcode']] ?? '');
    $rcDesc = trim($row[$idx['resultcodedescription']] ?? '');

    if (!isset($agents[$agent])) {
        $agents[$agent] = ['total' => 0, 'successes' => 0, 'cancels' => 0];
    }
    $agents[$agent]['total']++;

    if (in_array($rc, SUCCESS_CODES, true)) {
        $agents[$agent]['successes']++;
    } elseif (in_array($rc, CANCEL_CODES, true)) {
        $agents[$agent]['cancels']++;
        $reasonLabel = $rcDesc !== '' ? $rcDesc : $rc;
        $cancelReasons[$reasonLabel] = ($cancelReasons[$reasonLabel] ?? 0) + 1;
    }
}

// ── Fetch occupancy report (per-agent wrap time) ──
log_msg("Fetching occupancy report...");
$occupancyStart = $start->format('Y-m-d') . 'T00:00:00';
$occupancyEnd   = (clone $end)->modify('+1 day')->format('Y-m-d') . 'T00:00:00';
[$occupancy, $occErr] = fetchOccupancyReport($occupancyStart, $occupancyEnd, [(string) MC_CAMPAIGN_ID]);
if ($occErr) {
    log_msg("OCCUPANCY WARNING: $occErr (proceeding without wrap leaderboard)");
    $occupancy = [];
}

// ── Team totals ──
$team = ['total' => 0, 'successes' => 0, 'cancels' => 0];
foreach ($agents as $data) {
    $team['total']     += $data['total'];
    $team['successes'] += $data['successes'];
    $team['cancels']   += $data['cancels'];
}

// Flatten to rows for leaderboards
$rows = [];
foreach ($agents as $name => $data) {
    $rate     = $data['total'] > 0 ? $data['successes'] / $data['total'] : 0;
    $wrap     = isset($occupancy[$name]['wrap'])   ? parseHmsTime($occupancy[$name]['wrap'])   : 0;
    $logOn    = isset($occupancy[$name]['log_on']) ? parseHmsTime($occupancy[$name]['log_on']) : 0;
    $wrapPct  = $logOn > 0 ? $wrap / $logOn : 0;
    $rows[] = [
        'name'           => $name,
        'total'          => $data['total'],
        'successes'      => $data['successes'],
        'cancels'        => $data['cancels'],
        'rate'           => $rate,
        'wrap_seconds'   => $wrap,
        'log_on_seconds' => $logOn,
        'wrap_pct'       => $wrapPct,
    ];
}

// ── Build leaderboards ──
$lbVerifications = $rows;
usort($lbVerifications, fn($a, $b) => $b['successes'] <=> $a['successes'] ?: $b['rate'] <=> $a['rate']);

$lbRate = $rows;
usort($lbRate, fn($a, $b) => $b['rate'] <=> $a['rate'] ?: $b['successes'] <=> $a['successes']);

$lbCalls = $rows;
usort($lbCalls, fn($a, $b) => $b['total'] <=> $a['total']);

// Wrap-time leaderboard: ranked by wrap as % of log-on time (so part-timers aren't unfairly advantaged)
$lbWrap = array_filter($rows, fn($r) => $r['log_on_seconds'] > 0 && $r['wrap_seconds'] > 0);
usort($lbWrap, fn($a, $b) => $a['wrap_pct'] <=> $b['wrap_pct']);

// ── Build email HTML ──
$html = buildHtml($lbVerifications, $lbRate, $lbCalls, $lbWrap, $team, $cancelReasons, $start, $end);

// ── Send ──
sendEmail($html, $start, $end);

log_msg("Done.");
exit(0);

// ══════════════════════════════════════════════════════════════
// Helpers
// ══════════════════════════════════════════════════════════════

function pct($num, $denom) {
    if ($denom <= 0) return '0.0%';
    return number_format($num / $denom * 100, 1) . '%';
}

function rankColour($rank) {
    if ($rank === 1) return '#f9d71c';  // gold
    if ($rank === 2) return '#c0c0c0';  // silver
    if ($rank === 3) return '#cd7f32';  // bronze
    return '#e0e0e0';
}

function renderLeaderboard($title, $items, $valueFn) {
    $h = "<h3 style=\"margin-top:32px;font-size:1rem;color:#1a1a2e\">$title</h3>";
    $h .= "<table cellpadding=\"6\" cellspacing=\"0\" style=\"border-collapse:collapse;font-size:14px;width:100%;max-width:520px\">";
    $rank = 0;
    foreach ($items as $row) {
        $rank++;
        $colour = rankColour($rank);
        $value = $valueFn($row);
        $rankDisplay = $rank <= 3
            ? "<span style=\"display:inline-block;width:28px;height:28px;line-height:28px;text-align:center;background:$colour;border-radius:50%;font-weight:700;color:#1a1a2e\">$rank</span>"
            : "<span style=\"display:inline-block;width:28px;text-align:center;color:#666;font-weight:600\">$rank</span>";

        $bg = $rank <= 3 ? 'background:#fafafa;' : '';
        $h .= "<tr style=\"$bg\">";
        $h .= "<td style=\"padding:8px;width:40px;vertical-align:middle\">$rankDisplay</td>";
        $h .= "<td style=\"padding:8px;vertical-align:middle\"><b>" . htmlspecialchars($row['name']) . "</b></td>";
        $h .= "<td style=\"padding:8px;text-align:right;vertical-align:middle;font-weight:600\">$value</td>";
        $h .= "</tr>";
    }
    $h .= "</table>";
    return $h;
}

function buildHtml($lbVerifications, $lbRate, $lbCalls, $lbWrap, $team, $cancelReasons, $start, $end) {
    $rangeStr   = $start->format('d/m/Y') . ' to ' . $end->format('d/m/Y');
    $teamRate   = pct($team['successes'], $team['total']);

    $h  = "<div style=\"font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;color:#1a1a2e;line-height:1.5\">";
    $h .= "<p>Hi team,</p>";
    $h .= "<p>Here's how the verifications team got on for the week of <b>$rangeStr</b>.</p>";
    $h .= "<p>Together you made <b>" . number_format($team['total']) . " calls</b> and secured "
        . "<b>{$team['successes']} verifications</b> at a team success rate of <b>$teamRate</b>. Nice work!</p>";

    $h .= renderLeaderboard('Most Verifications', $lbVerifications, fn($r) => $r['successes']);
    $h .= renderLeaderboard('Best Verification Rate', $lbRate, fn($r) => pct($r['successes'], $r['total']) . " ({$r['successes']}/{$r['total']})");
    $h .= renderLeaderboard('Most Calls', $lbCalls, fn($r) => number_format($r['total']));
    if (!empty($lbWrap)) {
        $h .= renderLeaderboard('Best Wrap Time', $lbWrap, fn($r) =>
            number_format($r['wrap_pct'] * 100, 1) . '% (' . formatHms($r['wrap_seconds']) . ')'
        );
    }

    // Cancellation breakdown
    $h .= "<h3 style=\"margin-top:32px;font-size:1rem;color:#1a1a2e\">Cancellation Breakdown</h3>";
    if (empty($cancelReasons)) {
        $h .= "<p style=\"color:#666\">No cancellations recorded this week - fantastic!</p>";
    } else {
        arsort($cancelReasons);
        $maxCount = max($cancelReasons);
        $totalCancels = array_sum($cancelReasons);

        $h .= "<table cellpadding=\"6\" cellspacing=\"0\" style=\"border-collapse:collapse;font-size:13px;width:100%;max-width:700px\">";
        foreach ($cancelReasons as $reason => $count) {
            $barWidth = $maxCount > 0 ? round(($count / $maxCount) * 280) : 0;
            $label = $count . ' (' . pct($count, $totalCancels) . ')';
            $h .= "<tr>";
            $h .= "<td style=\"padding:4px 8px;width:240px;vertical-align:middle\">" . htmlspecialchars($reason) . "</td>";
            $h .= "<td style=\"padding:4px 8px;width:300px;vertical-align:middle\">";
            $h .= "<div style=\"display:inline-block;background:#e74c3c;height:18px;width:{$barWidth}px;border-radius:3px;vertical-align:middle\"></div>";
            $h .= "</td>";
            $h .= "<td style=\"padding:4px 8px;vertical-align:middle;white-space:nowrap\">$label</td>";
            $h .= "</tr>";
        }
        $h .= "</table>";
    }

    $h .= "<p style=\"margin-top:24px\">Keep up the great work - let's see what next week brings!</p>";
    $h .= "<p>Kind regards,<br><br>Ryan Lancaster<br><b>Technical Product Manager<br>DWM Administration Services</b></p>";
    $h .= "</div>";

    return $h;
}

function sendEmail($html, $start, $end) {
    $rangeStr = $start->format('d/m/Y') . ' to ' . $end->format('d/m/Y');

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->setFrom(EMAIL_FROM, EMAIL_FROM_NAME);

        if (TEST_MODE) {
            $mail->addAddress(EMAIL_FROM);  // Ryan only
        } else {
            if (AGENT_DIST === '') {
                log_msg("ERROR: AGENT_DIST is empty but TEST_MODE is off. Aborting.");
                exit(1);
            }
            $mail->addAddress(AGENT_DIST);
        }

        $mail->Subject = "Verifications Weekly Round-Up - $rangeStr";
        $mail->isHTML(true);
        $mail->Body = $html;

        $mail->send();
        log_msg("Email sent successfully" . (TEST_MODE ? ' (test mode - Ryan only)' : ''));
    } catch (Exception $e) {
        log_msg("EMAIL ERROR: " . $mail->ErrorInfo);
        exit(1);
    }
}
