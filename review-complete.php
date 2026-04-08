<?php
/**
 * Review Complete – Confirmation Email
 *
 * Runs periodically. Finds review sessions that have been completed
 * (completed_at is set) but no confirmation email has been sent yet
 * (confirmation_sent_at is null), then sends a summary email and
 * marks them as notified.
 *
 * Usage:
 *   php review-complete.php
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

$supabaseUrl = defined('SUPABASE_URL') ? SUPABASE_URL : getenv('SUPABASE_URL');
$serviceKey  = defined('SUPABASE_SERVICE_KEY') ? SUPABASE_SERVICE_KEY : getenv('SUPABASE_SERVICE_KEY');
$pagesUrl    = defined('PAGES_URL') ? PAGES_URL : getenv('PAGES_URL');

log_msg("Checking for newly completed reviews...");

// Find sessions that are completed but haven't had a confirmation email sent
// Only look at sessions with actual attempts (total_rows > 0)
$result = supabaseGet($supabaseUrl, $serviceKey, 'review_sessions',
    'completed_at=not.is.null&confirmation_sent_at=is.null&total_rows=gt.0&select=*&order=report_date.asc');

if ($result['error']) {
    log_msg("ERROR fetching sessions: " . $result['error']);
    exit(1);
}

$sessions = $result['data'] ?? [];

if (empty($sessions)) {
    log_msg("No newly completed reviews to notify about. Done.");
    exit(0);
}

log_msg(count($sessions) . " completed session(s) awaiting confirmation email.");

foreach ($sessions as $session) {
    $sessionId  = $session['id'];
    $reportDate = $session['report_date'];
    $displayDate = date('l jS F Y', strtotime($reportDate));
    $shortDate   = date('d/m/Y', strtotime($reportDate));

    log_msg("Processing session for $reportDate...");

    // Fetch attempts for this session
    $attemptsResult = supabaseGet($supabaseUrl, $serviceKey, 'attempts',
        "session_id=eq.$sessionId&select=*");

    if ($attemptsResult['error']) {
        log_msg("ERROR fetching attempts for $reportDate: " . $attemptsResult['error']);
        continue;
    }

    $attempts   = $attemptsResult['data'] ?? [];
    $total      = count($attempts);
    $genuine    = count(array_filter($attempts, fn($a) => $a['is_genuine'] === true));
    $notGenuine = count(array_filter($attempts, fn($a) => $a['is_genuine'] === false));

    // Build rejection reason breakdown if any not-genuine
    $reasonLines = '';
    if ($notGenuine > 0) {
        $reasons = [];
        foreach ($attempts as $a) {
            if ($a['is_genuine'] === false && !empty($a['rejection_reason'])) {
                $reason = $a['rejection_reason'];
                $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
            }
        }
        arsort($reasons);
        $reasonParts = [];
        foreach ($reasons as $reason => $count) {
            $reasonParts[] = htmlspecialchars($reason) . " ($count)";
        }
        $reasonLines = "<br><br><b>Rejection reasons:</b><br>" . implode('<br>', $reasonParts);
    }

    // Build report link
    $reportLink = '';
    if ($pagesUrl) {
        $month = date('Y-m', strtotime($reportDate));
        $link = rtrim($pagesUrl, '/') . "/report.html?month=$month";
        $reportLink = "<br><br><a href=\"$link\">View Monthly Report</a>";
    }

    $body = "Hi Ryan,<br><br>"
        . "The review for <b>$displayDate</b> has been completed.<br><br>"
        . "<b>Summary:</b><br>"
        . "Total flagged attempts: <b>$total</b><br>"
        . "Genuine: <b>$genuine</b><br>"
        . "Not genuine: <b>$notGenuine</b>"
        . $reasonLines
        . $reportLink
        . "<br><br>Kind regards,<br><br>Ryan Lancaster<br><b>Technical Product Manager<br>DWM Administration Services</b>";

    // Send confirmation email
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
        $mail->addAddress(EMAIL_FROM);  // Ryan only

        $mail->Subject = "Review Complete - Daily One Attempt $shortDate";
        $mail->isHTML(true);
        $mail->Body = $body;

        $mail->send();
        log_msg("Confirmation email sent for $reportDate.");
    } catch (Exception $e) {
        log_msg("EMAIL ERROR for $reportDate: " . $mail->ErrorInfo);
        continue;
    }

    // Mark confirmation as sent
    $patchResult = supabasePatch($supabaseUrl, $serviceKey, 'review_sessions',
        "id=eq.$sessionId", ['confirmation_sent_at' => date('c')]);

    if ($patchResult['error']) {
        log_msg("WARNING: Email sent but failed to mark confirmation_sent_at for $reportDate: " . $patchResult['error']);
    } else {
        log_msg("Session $reportDate marked as notified.");
    }
}

log_msg("Done.");
exit(0);
