<?php
/**
 * Daily One Attempt — automated CLI runner
 *
 * Fetches today's CSV from MaxContact (run after dialling ends at 20:30),
 * processes it, and emails the result. Runs Mon–Sat.
 *
 * Usage:
 *   php cron.php              # today's data
 *   php cron.php 2026-02-20   # specific date (YYYY-MM-DD)
 *
 * Schedule examples:
 *   Linux cron:     0 21 * * 1-6  /usr/bin/php /path/to/cron.php
 *   Windows Task:   schtasks /create /tn "DailyOneAttempt" /tr "php C:\path\to\cron.php" /sc weekly /d MON,TUE,WED,THU,FRI,SAT /st 21:00
 */

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/supabase.php';

// Load config from file if available, otherwise from environment variables
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
    define('SUPABASE_URL',       getenv('SUPABASE_URL'));
    define('SUPABASE_SERVICE_KEY', getenv('SUPABASE_SERVICE_KEY'));
    define('PAGES_URL',           getenv('PAGES_URL'));
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Determine target date ──
if (isset($argv[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[1])) {
    $targetDate = new DateTime($argv[1]);
} else {
    // Default to today — run after 20:30 to capture the full day's data
    $targetDate = new DateTime();
}

$mcDate = $targetDate->format('d/m/Y');
$displayDate = $mcDate;
$dateStr = $targetDate->format('dmy');

log_msg("Target date: $mcDate");

// ── Fetch from MaxContact ──
log_msg("Fetching CSV from MaxContact...");
[$csvData, $fetchError] = fetchMaxContactCSV($mcDate);
if ($fetchError) {
    log_msg("FETCH ERROR: $fetchError");
    exit(1);
}
log_msg("Fetched " . strlen($csvData) . " bytes");

// ── Process CSV ──
log_msg("Processing CSV...");
$processResult = processCLI($csvData);
if (is_string($processResult)) {
    log_msg("PROCESS ERROR: $processResult");
    exit(1);
}

[$csvContent, $rowCount] = $processResult;
$filename = 'dailyoneattempt' . $dateStr . '.csv';
log_msg("Processing complete — $rowCount rows in result");

// ── Create review session in Supabase ──
$reviewUrl = null;
if ($rowCount > 0) {
    log_msg("Creating review session in Supabase...");
    $reportDateISO = $targetDate->format('Y-m-d');
    [$reviewUrl, $sbError] = createReviewSession($reportDateISO, $csvContent, $rowCount);
    if ($sbError) {
        log_msg("SUPABASE WARNING: $sbError (email will still be sent)");
    } else {
        log_msg("Review session created — $reviewUrl");
    }
}

// ── Send email ──
if ($rowCount === 0) {
    log_msg("No rows — sending 'nothing to report' email...");
    sendEmail($displayDate, null, null, true, null);
} else {
    log_msg("Sending email with attachment ($filename)...");
    sendEmail($displayDate, $csvContent, $filename, false, $reviewUrl);
}

log_msg("Done.");
exit(0);

// ──────────────────────────────────────────────────────────────
// Functions
// ──────────────────────────────────────────────────────────────

function log_msg($msg) {
    $ts = date('Y-m-d H:i:s');
    echo "[$ts] $msg\n";
}

function processCLI($csvString) {
    $lines = explode("\n", $csvString);
    $header = str_getcsv(array_shift($lines));
    $header = array_map('trim', $header);

    $keepCols = ['startdatetime', 'fullname', 'resultcodedescription', 'phonenumber', 'disconnector'];
    $colIndexes = [];
    foreach ($keepCols as $col) {
        $idx = array_search($col, $header);
        if ($idx === false) {
            return "Missing required column: $col";
        }
        $colIndexes[$col] = $idx;
    }

    $rows = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $rows[] = str_getcsv($line);
    }

    $phoneCounts = [];
    foreach ($rows as $row) {
        $phone = trim($row[$colIndexes['phonenumber']] ?? '');
        $phoneCounts[$phone] = ($phoneCounts[$phone] ?? 0) + 1;
    }

    $allowedResults = ['Immediate Hang Up', 'No Answer'];
    $result = [];
    foreach ($rows as $row) {
        $desc = trim($row[$colIndexes['resultcodedescription']] ?? '');
        $phone = trim($row[$colIndexes['phonenumber']] ?? '');
        if (in_array($desc, $allowedResults) && $phoneCounts[$phone] === 1) {
            $result[] = $row;
        }
    }

    // Remove invalid UK phone numbers
    $result = array_filter($result, function ($row) use ($colIndexes) {
        $phone = trim($row[$colIndexes['phonenumber']] ?? '');
        return preg_match('/^0\d{10}$/', $phone);
    });

    $output = fopen('php://temp', 'r+');
    fputcsv($output, $keepCols);
    foreach ($result as $row) {
        $outRow = [];
        foreach ($keepCols as $col) {
            $outRow[] = $row[$colIndexes[$col]] ?? '';
        }
        fputcsv($output, $outRow);
    }
    rewind($output);
    $csvContent = stream_get_contents($output);
    fclose($output);

    return [$csvContent, count($result)];
}

function fetchMaxContactCSV($dateStr) {
    $startDate = $dateStr . ' 00:00';
    $dt = DateTime::createFromFormat('d/m/Y', $dateStr);
    $dt->modify('+1 day');
    $endDate = $dt->format('d/m/Y') . ' 00:00';

    // Step 1: GET login page to grab session cookie
    $ch = curl_init(MC_BASE_URL . '/Home/Login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADER         => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $respHeaders = substr($resp, 0, $headerSize);
    curl_close($ch);

    $cookies = '';
    if (preg_match('/ASP\.NET_SessionId=([^;\s]+)/', $respHeaders, $sm)) {
        $cookies = 'ASP.NET_SessionId=' . $sm[1];
    }

    // Step 2: JSON POST login
    $ch = curl_init(MC_BASE_URL . '/Home/Login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'Login'    => MC_USERNAME,
            'Password' => MC_PASSWORD,
        ]),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json, text/plain, */*',
            'X-Requested-With: XMLHttpRequest',
            'Cookie: ' . $cookies,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $respHeaders = substr($resp, 0, $headerSize);
    curl_close($ch);

    if (preg_match('/UserCookie=([^;\s]+)/', $respHeaders, $um)) {
        $cookies .= '; UserCookie=' . $um[1];
    }
    if (preg_match('/ASP\.NET_SessionId=([^;\s]+)/', $respHeaders, $sm2)) {
        $cookies = preg_replace('/ASP\.NET_SessionId=[^;]+/', 'ASP.NET_SessionId=' . $sm2[1], $cookies);
    }

    if ($httpCode !== 302 && $httpCode !== 200) {
        return [null, "Login failed with HTTP $httpCode."];
    }

    // Step 3: Fetch the CSV export
    $params = http_build_query([
        'startDate'         => $startDate,
        'endDate'           => $endDate,
        'identity'          => '',
        'userID'            => 0,
        'campaignID'        => MC_CAMPAIGN_ID,
        'listID'            => 0,
        'reference'         => '',
        'isSuccess'         => 'false',
        'isAssociated'      => 'false',
        'isHotKey'          => 'false',
        'isThreadFiltered'  => 'false',
        'csatRating'        => 0,
        'leadID'            => 0,
        'leadPhoneID'       => 0,
        'resultCodeID'      => 0,
        'recLengthSearch'   => 1,
        'recScaleSearch'    => 0,
        'recLength1'        => 0,
        'recLength2'        => 0,
        'channelId'         => 0,
        'teamID'            => 0,
        'name'              => '',
        'name2'             => '',
    ]);

    $ch = curl_init(MC_BASE_URL . '/RecordHistory/Export?' . $params);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Cookie: ' . $cookies],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 120,
    ]);
    $csvData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return [null, "cURL error: $curlError"];
    }
    if ($httpCode !== 200) {
        return [null, "MaxContact returned HTTP $httpCode — login may have failed."];
    }
    if (empty($csvData) || stripos($csvData, 'historyid') === false) {
        return [null, "Response did not contain expected CSV data — login may have failed."];
    }

    return [$csvData, null];
}

function sendEmail($displayDate, $csvContent, $filename, $nothingToReport, $reviewUrl) {
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
        $mail->addAddress(EMAIL_TO);
        $mail->addCC(EMAIL_CC);
        $mail->addBCC(EMAIL_FROM);

        $mail->Subject = 'Daily One Attempt ' . $displayDate;
        $mail->isHTML(true);

        $signature = 'Kind regards,<br><br>Ryan Lancaster<br><b>Dialler Manager<br>DWM Administration Services</b>';

        if ($nothingToReport) {
            $mail->Body = "Hi Tina,<br><br>Nothing to report today.<br><br>$signature";
        } else {
            $reviewLine = $reviewUrl
                ? "Please review these attempts here: <a href=\"$reviewUrl\">Review Attempts</a><br><br>"
                : '';
            $mail->Body = "Hi Tina,<br><br>Please see attached.<br><br>{$reviewLine}{$signature}";
            $mail->addStringAttachment($csvContent, $filename, 'base64', 'text/csv');
        }

        $mail->send();
        log_msg("Email sent successfully.");
    } catch (Exception $e) {
        log_msg("EMAIL ERROR: " . $mail->ErrorInfo);
        exit(1);
    }
}
