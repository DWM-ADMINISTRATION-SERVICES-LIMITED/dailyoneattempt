<?php
/**
 * Verifications Weekly Productivity Report
 *
 * Runs Monday mornings. Fetches the previous Mon-Sat from MaxContact,
 * aggregates per-agent metrics, and emails an HTML report.
 *
 * Usage:
 *   php verifications-report.php                          # previous Mon-Sat
 *   php verifications-report.php 2026-04-20 2026-04-25    # specific range
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
    define('EMAIL_TO',       getenv('EMAIL_TO'));
    define('EMAIL_CC',       getenv('EMAIL_CC'));
    define('MC_BASE_URL',    getenv('MC_BASE_URL'));
    define('MC_USERNAME',    getenv('MC_USERNAME'));
    define('MC_PASSWORD',    getenv('MC_PASSWORD'));
    define('MC_CAMPAIGN_ID', (int) getenv('MC_CAMPAIGN_ID'));
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Toggle to false to also email Tom and Tina ──
const TEST_MODE = false;

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
$header = null;
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
    log_msg("No data — aborting.");
    exit(0);
}

// ── Column indices ──
$idx = [
    'fullname'              => array_search('fullname', $header),
    'resultcode'            => array_search('resultcode', $header),
    'resultcodedescription' => array_search('resultcodedescription', $header),
    'startdatetime'         => array_search('startdatetime', $header),
    'recordinglengthstr'    => array_search('recordinglengthstr', $header),
];

foreach ($idx as $k => $v) {
    if ($v === false) {
        log_msg("ERROR: Missing column '$k' in CSV");
        exit(1);
    }
}

// ── Aggregate per agent ──
$agents = [];
$cancelReasons = [];

foreach ($allRows as $row) {
    $agent   = trim($row[$idx['fullname']] ?? '');
    if ($agent === '') continue;

    $rc      = trim($row[$idx['resultcode']] ?? '');
    $rcDesc  = trim($row[$idx['resultcodedescription']] ?? '');
    $startDt = trim($row[$idx['startdatetime']] ?? '');
    $dur     = trim($row[$idx['recordinglengthstr']] ?? '');

    $datePart = '';
    if (preg_match('#^(\d{2}/\d{2}/\d{4})#', $startDt, $m)) {
        $datePart = $m[1];
    }

    if (!isset($agents[$agent])) {
        $agents[$agent] = ['days' => [], 'total' => 0, 'successes' => 0, 'cancels' => 0, 'seconds' => 0];
    }

    $agents[$agent]['total']++;
    if ($datePart) $agents[$agent]['days'][$datePart] = true;
    $agents[$agent]['seconds'] += parseDuration($dur);

    if (in_array($rc, SUCCESS_CODES, true)) {
        $agents[$agent]['successes']++;
    } elseif (in_array($rc, CANCEL_CODES, true)) {
        $agents[$agent]['cancels']++;
        $reasonLabel = $rcDesc !== '' ? $rcDesc : $rc;
        $cancelReasons[$reasonLabel] = ($cancelReasons[$reasonLabel] ?? 0) + 1;
    }
}

// ── Team totals ──
$team = ['days' => 0, 'total' => 0, 'successes' => 0, 'cancels' => 0, 'seconds' => 0];
foreach ($agents as $data) {
    $team['days']      += count($data['days']);
    $team['total']     += $data['total'];
    $team['successes'] += $data['successes'];
    $team['cancels']   += $data['cancels'];
    $team['seconds']   += $data['seconds'];
}

// Sort agents by total calls descending
$rows = [];
foreach ($agents as $name => $data) {
    $rows[] = [
        'name'      => $name,
        'days'      => count($data['days']),
        'total'     => $data['total'],
        'successes' => $data['successes'],
        'cancels'   => $data['cancels'],
        'seconds'   => $data['seconds'],
    ];
}
usort($rows, fn($a, $b) => $b['total'] - $a['total']);

// ── Build email HTML ──
$html = buildHtml($rows, $team, $cancelReasons, $start, $end);

// ── Send ──
sendEmail($html, $start, $end);

log_msg("Done.");
exit(0);

// ══════════════════════════════════════════════════════════════
// Helpers
// ══════════════════════════════════════════════════════════════

function buildNarrative($rows, $team, $cancelReasons) {
    $paragraphs = [];

    // Overview
    $successRate = pct($team['successes'], $team['total']);
    $cancelRate  = pct($team['cancels'],   $team['total']);
    $teamRatio   = ratio($team['successes'], $team['cancels']);
    $callTime    = formatDuration($team['seconds']);
    $agentCount  = count($rows);

    $paragraphs[] = "Across <b>$agentCount agents</b> and <b>{$team['days']} agent-days</b>, the team handled "
        . "<b>" . number_format($team['total']) . " calls</b>, totalling <b>$callTime</b> of call time. "
        . "Of these, <b>{$team['successes']}</b> were verified ($successRate) and "
        . "<b>{$team['cancels']}</b> were cancelled ($cancelRate), giving an overall "
        . "success-to-cancellation ratio of <b>$teamRatio</b>.";

    // Top performer insights (only if 2+ agents)
    if ($agentCount >= 2) {
        // Top by raw successes
        $topByCount = $rows;
        usort($topByCount, fn($a, $b) => $b['successes'] - $a['successes']);
        $topVerifier = $topByCount[0];

        // Top by conversion rate (min 20 calls to qualify)
        $eligible = array_filter($rows, fn($r) => $r['total'] >= 20);
        $topByRate = null;
        if (!empty($eligible)) {
            usort($eligible, function ($a, $b) {
                $aRate = $a['total'] > 0 ? $a['successes'] / $a['total'] : 0;
                $bRate = $b['total'] > 0 ? $b['successes'] / $b['total'] : 0;
                return $bRate <=> $aRate;
            });
            $topByRate = $eligible[0];
        }

        if ($topVerifier['successes'] > 0) {
            $insight = "<b>{$topVerifier['name']}</b> led the week with "
                . "<b>{$topVerifier['successes']} verifications</b> "
                . "(" . pct($topVerifier['successes'], $topVerifier['total']) . " of {$topVerifier['total']} calls)";

            if ($topByRate && $topByRate['name'] !== $topVerifier['name']) {
                $rate = pct($topByRate['successes'], $topByRate['total']);
                $insight .= ", while <b>{$topByRate['name']}</b> had the strongest verification rate at <b>$rate</b> "
                    . "({$topByRate['successes']} of {$topByRate['total']} calls)";
            }
            $paragraphs[] = $insight . ".";
        }
    }

    // Cancellation insight
    if (!empty($cancelReasons) && $team['cancels'] > 0) {
        arsort($cancelReasons);
        $topReason = array_key_first($cancelReasons);
        $topCount  = $cancelReasons[$topReason];
        $topPct    = pct($topCount, $team['cancels']);

        if (count($cancelReasons) === 1) {
            $paragraphs[] = "All cancellations this week were due to <b>" . htmlspecialchars($topReason) . "</b>.";
        } else {
            $paragraphs[] = "The leading cancellation reason was <b>" . htmlspecialchars($topReason) . "</b> "
                . "($topCount, $topPct of all cancellations).";
        }
    }

    return $paragraphs;
}

function parseDuration($str) {
    if ($str === '' || !preg_match_all('/(\d+)\s*([hms])/i', $str, $matches, PREG_SET_ORDER)) return 0;
    $sec = 0;
    foreach ($matches as $m) {
        $unit = strtolower($m[2]);
        if ($unit === 'h') $sec += (int)$m[1] * 3600;
        elseif ($unit === 'm') $sec += (int)$m[1] * 60;
        elseif ($unit === 's') $sec += (int)$m[1];
    }
    return $sec;
}

function formatDuration($seconds) {
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return "{$h}h:{$m}m:{$s}s";
}

function pct($num, $denom) {
    if ($denom <= 0) return '0.0%';
    return number_format($num / $denom * 100, 1) . '%';
}

function ratio($s, $c) {
    if ($s === 0 && $c === 0) return '—';
    if ($c === 0) return '∞';
    return number_format($s / $c, 2) . ':1';
}

function buildHtml($rows, $team, $cancelReasons, $start, $end) {
    $rangeStr = $start->format('d/m/Y') . ' to ' . $end->format('d/m/Y');

    $h = "<div style=\"font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;color:#1a1a2e\">";
    $h .= "<p>Hi both,</p>";
    $h .= "<p>Please find below the verifications team's productivity for the week of <b>$rangeStr</b>.</p>";

    foreach (buildNarrative($rows, $team, $cancelReasons) as $para) {
        $h .= "<p>$para</p>";
    }

    $h .= "<h3 style=\"margin-top:24px;font-size:1rem\">Agent Performance</h3>";
    $h .= "<table cellpadding=\"8\" cellspacing=\"0\" style=\"border-collapse:collapse;font-size:13px;width:100%;max-width:860px\">";
    $h .= "<thead><tr style=\"background:#f8f9fa;text-align:left\">";
    foreach (['Agent', 'Dialling Days', 'Total Calls', 'Successes', 'Cancels', 'S:C Ratio', 'Total Call Time'] as $col) {
        $h .= "<th style=\"border-bottom:2px solid #e0e0e0;padding:8px\">$col</th>";
    }
    $h .= "</tr></thead><tbody>";

    foreach ($rows as $r) {
        $sCell = $r['successes'] . ' (' . pct($r['successes'], $r['total']) . ')';
        $cCell = $r['cancels']   . ' (' . pct($r['cancels'],   $r['total']) . ')';
        $rCell = ratio($r['successes'], $r['cancels']) . " ({$r['successes']}:{$r['cancels']})";
        $tCell = formatDuration($r['seconds']);
        $h .= "<tr>";
        $h .= "<td style=\"border-bottom:1px solid #f0f0f0;padding:8px\"><b>" . htmlspecialchars($r['name']) . "</b></td>";
        $h .= "<td style=\"border-bottom:1px solid #f0f0f0;padding:8px\">{$r['days']}</td>";
        $h .= "<td style=\"border-bottom:1px solid #f0f0f0;padding:8px\">{$r['total']}</td>";
        $h .= "<td style=\"border-bottom:1px solid #f0f0f0;padding:8px;color:#27ae60\">$sCell</td>";
        $h .= "<td style=\"border-bottom:1px solid #f0f0f0;padding:8px;color:#e74c3c\">$cCell</td>";
        $h .= "<td style=\"border-bottom:1px solid #f0f0f0;padding:8px\">$rCell</td>";
        $h .= "<td style=\"border-bottom:1px solid #f0f0f0;padding:8px\">$tCell</td>";
        $h .= "</tr>";
    }

    // Team totals row
    $tsCell = $team['successes'] . ' (' . pct($team['successes'], $team['total']) . ')';
    $tcCell = $team['cancels']   . ' (' . pct($team['cancels'],   $team['total']) . ')';
    $trCell = ratio($team['successes'], $team['cancels']) . " ({$team['successes']}:{$team['cancels']})";
    $ttCell = formatDuration($team['seconds']);
    $h .= "<tr style=\"background:#f8f9fa;font-weight:700\">";
    $h .= "<td style=\"padding:8px;border-top:2px solid #e0e0e0\">Team Total</td>";
    $h .= "<td style=\"padding:8px;border-top:2px solid #e0e0e0\">{$team['days']}</td>";
    $h .= "<td style=\"padding:8px;border-top:2px solid #e0e0e0\">{$team['total']}</td>";
    $h .= "<td style=\"padding:8px;border-top:2px solid #e0e0e0;color:#27ae60\">$tsCell</td>";
    $h .= "<td style=\"padding:8px;border-top:2px solid #e0e0e0;color:#e74c3c\">$tcCell</td>";
    $h .= "<td style=\"padding:8px;border-top:2px solid #e0e0e0\">$trCell</td>";
    $h .= "<td style=\"padding:8px;border-top:2px solid #e0e0e0\">$ttCell</td>";
    $h .= "</tr>";

    $h .= "</tbody></table>";

    // Cancellation breakdown
    $h .= "<h3 style=\"margin-top:32px;font-size:1rem\">Cancellation Breakdown</h3>";
    if (empty($cancelReasons)) {
        $h .= "<p style=\"color:#666\">No cancellations recorded this week.</p>";
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

    $h .= "<p style=\"margin-top:24px\">Kind regards,<br><br>Ryan Lancaster<br><b>Technical Product Manager<br>DWM Administration Services</b></p>";
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
        $mail->addAddress(EMAIL_FROM);  // Ryan
        if (!TEST_MODE) {
            $mail->addCC(EMAIL_TO);     // Tina
            $mail->addCC(EMAIL_CC);     // Tom
        }

        $mail->Subject = "Verifications Weekly Productivity Report - $rangeStr";
        $mail->isHTML(true);
        $mail->Body = $html;

        $mail->send();
        log_msg("Email sent successfully" . (TEST_MODE ? ' (test mode — Ryan only)' : ''));
    } catch (Exception $e) {
        log_msg("EMAIL ERROR: " . $mail->ErrorInfo);
        exit(1);
    }
}
