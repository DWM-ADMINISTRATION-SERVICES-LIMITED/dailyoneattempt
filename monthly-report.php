<?php
/**
 * Monthly Report Check
 *
 * Runs daily. Checks the previous month's review status:
 *   - If all reviews complete and report not yet sent → send report to Thomas
 *   - If reviews outstanding → send reminder to Tina with links
 *
 * Usage:
 *   php monthly-report.php              # check previous month
 *   php monthly-report.php 2026-03      # check specific month
 *   php monthly-report.php 2026-03 --force  # send report even if reviews incomplete
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/supabase.php';

if (file_exists(__DIR__ . '/config.php')) {
    require __DIR__ . '/config.php';
} else {
    define('SMTP_HOST',            getenv('SMTP_HOST'));
    define('SMTP_PORT',            (int) getenv('SMTP_PORT'));
    define('SMTP_USER',            getenv('SMTP_USER'));
    define('SMTP_PASS',            getenv('SMTP_PASS'));
    define('EMAIL_FROM',           getenv('EMAIL_FROM'));
    define('EMAIL_FROM_NAME',      getenv('EMAIL_FROM_NAME'));
    define('EMAIL_TO',             getenv('EMAIL_TO'));
    define('EMAIL_CC',             getenv('EMAIL_CC'));
    define('SUPABASE_URL',         getenv('SUPABASE_URL'));
    define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY'));
    define('PAGES_URL',            getenv('PAGES_URL'));
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function log_msg($msg) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
}

// ── Determine target month ──
if (isset($argv[1]) && preg_match('/^\d{4}-\d{2}$/', $argv[1])) {
    $targetMonth = $argv[1];
} else {
    $targetMonth = date('Y-m', strtotime('first day of last month'));
}

$forceReport = in_array('--force', $argv ?? []);

[$year, $month] = explode('-', $targetMonth);
$monthName = date('F Y', mktime(0, 0, 0, (int)$month, 1, (int)$year));

$supabaseUrl = defined('SUPABASE_URL') ? SUPABASE_URL : getenv('SUPABASE_URL');
$serviceKey  = defined('SUPABASE_SERVICE_KEY') ? SUPABASE_SERVICE_KEY : getenv('SUPABASE_SERVICE_KEY');
$pagesUrl    = defined('PAGES_URL') ? PAGES_URL : getenv('PAGES_URL');

log_msg("Checking report status for $monthName ($targetMonth)");

// ── Step 1: Has the report already been sent? ──
$sent = supabaseGet($supabaseUrl, $serviceKey, 'reports_sent', 'month=eq.' . $targetMonth . '&limit=1');
if (!empty($sent['data'])) {
    log_msg("Report for $targetMonth already sent on " . $sent['data'][0]['sent_at'] . ". Nothing to do.");
    exit(0);
}

// ── Step 2: Fetch sessions for the month ──
$startDate = "$targetMonth-01";
$endMonth = (int)$month === 12 ? '01' : str_pad((int)$month + 1, 2, '0', STR_PAD_LEFT);
$endYear = (int)$month === 12 ? (int)$year + 1 : (int)$year;
$endDate = "$endYear-$endMonth-01";

$sessionsResult = supabaseGet($supabaseUrl, $serviceKey, 'review_sessions',
    "report_date=gte.$startDate&report_date=lt.$endDate&select=*&order=report_date.asc");
if ($sessionsResult['error'] || empty($sessionsResult['data'])) {
    log_msg("No sessions found for $targetMonth. Nothing to do.");
    exit(0);
}
$sessions = $sessionsResult['data'];

// ── Step 3: Check review completion ──
// Sessions with total_rows > 0 need to be fully reviewed (completed_at set)
$sessionsNeedingReview = array_filter($sessions, fn($s) => (int)$s['total_rows'] > 0);
$outstandingSessions = array_filter($sessionsNeedingReview, fn($s) => $s['completed_at'] === null);
$allReviewed = empty($outstandingSessions);

log_msg(count($sessionsNeedingReview) . " sessions need review, " . count($outstandingSessions) . " outstanding.");

if ($allReviewed || $forceReport) {
    if ($forceReport && !$allReviewed) {
        log_msg("Force flag set - sending report despite " . count($outstandingSessions) . " outstanding reviews.");
    }
    // ── All reviewed: send the monthly report to Thomas ──
    sendMonthlyReport($targetMonth, $monthName, $sessions, $supabaseUrl, $serviceKey, $pagesUrl);

    // Mark as sent
    supabasePost($supabaseUrl, $serviceKey, 'reports_sent', ['month' => $targetMonth]);
    log_msg("Report marked as sent.");
} else {
    // ── Outstanding reviews: send reminder to Tina ──
    sendReviewReminder($targetMonth, $monthName, $outstandingSessions, $pagesUrl);
}

log_msg("Done.");
exit(0);

// ══════════════════════════════════════════════════════════════
// Functions
// ══════════════════════════════════════════════════════════════

function sendMonthlyReport($targetMonth, $monthName, $sessions, $supabaseUrl, $serviceKey, $pagesUrl) {
    log_msg("All reviews complete. Generating monthly report...");

    [$year, $month] = explode('-', $targetMonth);
    $startDate = "$targetMonth-01";
    $endMonth = (int)$month === 12 ? '01' : str_pad((int)$month + 1, 2, '0', STR_PAD_LEFT);
    $endYear = (int)$month === 12 ? (int)$year + 1 : (int)$year;
    $endDate = "$endYear-$endMonth-01";

    $sessionIds = array_map(fn($s) => $s['id'], $sessions);
    $idList = implode(',', $sessionIds);

    $attempts = supabaseGet($supabaseUrl, $serviceKey, 'attempts', "session_id=in.($idList)&select=*");
    $attempts = $attempts['data'] ?? [];

    $agentStats = supabaseGet($supabaseUrl, $serviceKey, 'daily_agent_stats',
        "report_date=gte.$startDate&report_date=lt.$endDate&select=*");
    $agentStats = $agentStats['data'] ?? [];

    // Previous month for comparison
    $prevMonth = date('Y-m', strtotime("$targetMonth-01 -1 month"));
    $prevStart = "$prevMonth-01";
    $prevSessions = supabaseGet($supabaseUrl, $serviceKey, 'review_sessions',
        "report_date=gte.$prevStart&report_date=lt.$startDate&select=*");
    $prevSessions = $prevSessions['data'] ?? [];
    $prevAttempts = [];
    if (!empty($prevSessions)) {
        $prevIds = implode(',', array_map(fn($s) => $s['id'], $prevSessions));
        $prevAttempts = supabaseGet($supabaseUrl, $serviceKey, 'attempts', "session_id=in.($prevIds)&select=*");
        $prevAttempts = $prevAttempts['data'] ?? [];
    }

    // ── Compute stats ──
    $totalCalls = array_sum(array_map(fn($s) => $s['total_calls'] ?? 0, $sessions));
    $daysWithData = count($sessions);
    $apparentOneAttempts = count($attempts);
    $genuine = count(array_filter($attempts, fn($a) => $a['is_genuine'] === true));
    $notGenuine = count(array_filter($attempts, fn($a) => $a['is_genuine'] === false));
    $unreviewed = count(array_filter($attempts, fn($a) => $a['is_genuine'] === null));

    $apparentRate = $totalCalls > 0 ? round($apparentOneAttempts / $totalCalls * 100, 2) : 0;
    $genuineRate = $apparentOneAttempts > 0 ? round($genuine / $apparentOneAttempts * 100, 1) : 0;

    $prevTotalCalls = array_sum(array_map(fn($s) => $s['total_calls'] ?? 0, $prevSessions));
    $prevApparent = count($prevAttempts);
    $prevGenuine = count(array_filter($prevAttempts, fn($a) => $a['is_genuine'] === true));
    $hasPrevData = !empty($prevSessions);

    // Per-agent aggregation
    $agentTotals = [];
    foreach ($agentStats as $s) {
        $name = $s['fullname'];
        if ($name === '(unassigned)' || $name === '') continue;
        $agentTotals[$name] = ($agentTotals[$name] ?? 0) + $s['total_calls'];
    }

    $agentReviews = [];
    foreach ($attempts as $a) {
        $name = $a['fullname'];
        if (!isset($agentReviews[$name])) $agentReviews[$name] = ['apparent' => 0, 'genuine' => 0, 'notGenuine' => 0];
        $agentReviews[$name]['apparent']++;
        if ($a['is_genuine'] === true) $agentReviews[$name]['genuine']++;
        elseif ($a['is_genuine'] === false) $agentReviews[$name]['notGenuine']++;
    }

    // ── Build narrative ──
    $paragraphs = [];

    // Opening summary
    $paragraphs[] = "In <b>$monthName</b>, a total of <b>" . number_format($totalCalls) . " calls</b> "
        . "were made across <b>$daysWithData working days</b>. "
        . "Of these, <b>$apparentOneAttempts</b> were flagged as apparent single attempts "
        . "(<b>{$apparentRate}%</b> of all calls).";

    // Genuine breakdown
    if ($apparentOneAttempts > 0) {
        $paragraphs[] = "Following review, <b>$genuine</b> of the $apparentOneAttempts flagged attempts "
            . "were confirmed as genuine single attempts (<b>{$genuineRate}%</b>), "
            . "while <b>$notGenuine</b> were found to be not genuine."
            . ($unreviewed > 0 ? " <b>$unreviewed</b> remain awaiting review." : "");
    }

    // Month-on-month comparison
    if ($hasPrevData && $prevTotalCalls > 0) {
        $prevMonthName = date('F', strtotime("$prevMonth-01"));
        $callsDelta = $totalCalls - $prevTotalCalls;
        $callsPct = round(abs($callsDelta) / $prevTotalCalls * 100, 1);
        $callsDir = $callsDelta > 0 ? 'up' : ($callsDelta < 0 ? 'down' : 'unchanged');

        $apparentDelta = $apparentOneAttempts - $prevApparent;
        $genuineDelta = $genuine - $prevGenuine;

        $comp = "Compared to $prevMonthName, total calls were <b>$callsDir {$callsPct}%</b>";

        if ($apparentDelta !== 0) {
            $appDir = $apparentDelta > 0 ? 'increased' : 'decreased';
            $comp .= ", apparent one attempts $appDir from $prevApparent to $apparentOneAttempts";
        }

        if ($genuineDelta !== 0) {
            $genDir = $genuineDelta > 0 ? 'rose' : 'fell';
            $comp .= ", and genuine one attempts $genDir from $prevGenuine to $genuine";
        }

        $paragraphs[] = $comp . ".";
    }

    // Agent highlights
    $agentInsights = [];

    $offenders = array_filter($agentReviews, fn($r) => $r['genuine'] > 0);
    arsort($offenders);
    if (!empty($offenders)) {
        $worstName = array_key_first($offenders);
        $worstCount = $offenders[$worstName]['genuine'];
        $worstTotal = $agentTotals[$worstName] ?? 0;

        if (count($offenders) === 1) {
            $agentInsights[] = "<b>$worstName</b> was the only agent with genuine single attempts this month ($worstCount)"
                . ($worstTotal > 0 ? " out of " . number_format($worstTotal) . " total calls" : "") . ".";
        } else {
            $names = [];
            $i = 0;
            foreach ($offenders as $name => $data) {
                if ($i >= 3) break;
                $names[] = "<b>$name</b> ($data[genuine])";
                $i++;
            }
            $agentInsights[] = count($offenders) . " agents had genuine single attempts. "
                . "The highest were: " . implode(', ', $names) . ".";
        }
    }

    $cleanAgents = [];
    foreach ($agentTotals as $name => $calls) {
        $rev = $agentReviews[$name] ?? null;
        if (!$rev || $rev['genuine'] === 0) {
            $cleanAgents[$name] = $calls;
        }
    }
    arsort($cleanAgents);
    if (!empty($cleanAgents) && !empty($offenders)) {
        $topClean = array_slice($cleanAgents, 0, 3, true);
        $cleanNames = [];
        foreach ($topClean as $name => $calls) {
            $cleanNames[] = "<b>$name</b> (" . number_format($calls) . " calls)";
        }
        $agentInsights[] = "Agents with the most calls and zero genuine single attempts: "
            . implode(', ', $cleanNames) . ".";
    }

    foreach ($agentReviews as $name => $data) {
        if ($data['apparent'] >= 3 && $data['genuine'] === 0) {
            $agentInsights[] = "<b>$name</b> had $data[apparent] flagged attempts but none were confirmed as genuine.";
            break;
        }
    }

    if (!empty($agentInsights)) {
        $paragraphs[] = implode(' ', $agentInsights);
    }

    // Peak days
    $dayStats = [];
    foreach ($sessions as $s) {
        $day = date('jS M', strtotime($s['report_date']));
        $dayStats[] = ['date' => $day, 'total_rows' => $s['total_rows']];
    }
    usort($dayStats, fn($a, $b) => $b['total_rows'] - $a['total_rows']);
    $peakDays = array_filter(array_slice($dayStats, 0, 3), fn($d) => $d['total_rows'] > 0);
    if (count($peakDays) > 1) {
        $dayList = array_map(fn($d) => "<b>{$d['date']}</b> ({$d['total_rows']})", $peakDays);
        $paragraphs[] = "The days with the most flagged attempts were: " . implode(', ', $dayList) . ".";
    }

    // Report link
    if ($pagesUrl) {
        $reportLink = rtrim($pagesUrl, '/') . "/report.html?month=$targetMonth";
        $paragraphs[] = "The full interactive report is available here: <a href=\"$reportLink\">View Report</a>";
    }

    // ── Build and send email ──
    $body = "Hi Thomas,<br><br>"
        . "Please find below the monthly one-attempt summary for $monthName.<br><br>"
        . implode("<br><br>", $paragraphs)
        . "<br><br>Kind regards,<br><br>Ryan Lancaster<br><b>Dialler Manager<br>DWM Administration Services</b>";

    log_msg("Sending monthly report to Thomas...");
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
        $mail->addAddress(EMAIL_CC);  // Thomas is the primary recipient for monthly reports
        $mail->addCC(EMAIL_FROM);     // Ryan CC'd
        $mail->addBCC(EMAIL_FROM);

        $mail->Subject = "Monthly One Attempt Report - $monthName";
        $mail->isHTML(true);
        $mail->Body = $body;

        $mail->send();
        log_msg("Monthly report email sent successfully.");
    } catch (Exception $e) {
        log_msg("EMAIL ERROR: " . $mail->ErrorInfo);
        exit(1);
    }
}

function sendReviewReminder($targetMonth, $monthName, $outstandingSessions, $pagesUrl) {
    $count = count($outstandingSessions);
    log_msg("$count sessions still outstanding. Sending reminder to Tina...");

    // Build list of outstanding days with review links
    $dayLines = [];
    foreach ($outstandingSessions as $s) {
        $day = date('l jS F', strtotime($s['report_date']));
        $rows = (int) $s['total_rows'];
        $reviewed = (int) $s['reviewed_count'];
        $remaining = $rows - $reviewed;
        $link = rtrim($pagesUrl, '/') . '/review.html?token=' . $s['token'];
        $dayLines[] = "- <a href=\"$link\">$day</a> ($remaining of $rows still to review)";
    }

    $body = "Hi Tina,<br><br>"
        . "The monthly report for <b>$monthName</b> is waiting on the following reviews to be completed:<br><br>"
        . implode("<br>", $dayLines)
        . "<br><br>Please review these at your earliest convenience so the monthly report can be generated.<br><br>"
        . "Kind regards,<br><br>Ryan Lancaster<br><b>Dialler Manager<br>DWM Administration Services</b>";

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
        $mail->addAddress(EMAIL_TO);  // Tina is the primary recipient for reminders
        $mail->addBCC(EMAIL_FROM);

        $mail->Subject = "Outstanding Reviews - $monthName";
        $mail->isHTML(true);
        $mail->Body = $body;

        $mail->send();
        log_msg("Reminder email sent to Tina.");
    } catch (Exception $e) {
        log_msg("EMAIL ERROR: " . $mail->ErrorInfo);
        exit(1);
    }
}
