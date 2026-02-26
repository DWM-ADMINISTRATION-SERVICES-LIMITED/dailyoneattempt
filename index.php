<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// ── Process raw CSV content and store results in session ──
function processCSV($csvString) {
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

    // Identify duplicate phone numbers across the FULL dataset
    $phoneCounts = [];
    foreach ($rows as $row) {
        $phone = trim($row[$colIndexes['phonenumber']] ?? '');
        $phoneCounts[$phone] = ($phoneCounts[$phone] ?? 0) + 1;
    }

    // Filter: only keep rows with allowed resultcodedescription values
    // and remove any row whose phone number appeared more than once
    $allowedResults = ['Immediate Hang Up', 'No Answer'];
    $result = [];
    foreach ($rows as $row) {
        $desc = trim($row[$colIndexes['resultcodedescription']] ?? '');
        $phone = trim($row[$colIndexes['phonenumber']] ?? '');
        if (in_array($desc, $allowedResults) && $phoneCounts[$phone] === 1) {
            $result[] = $row;
        }
    }

    // Build output CSV in memory
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

    // Extract date from first row's startdatetime (DD/MM/YYYY ...)
    $dateStr = '';
    $displayDate = '';
    if (!empty($rows[0][$colIndexes['startdatetime']])) {
        $raw = trim($rows[0][$colIndexes['startdatetime']]);
        if (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $raw, $m)) {
            $dateStr = $m[1] . $m[2] . substr($m[3], 2);
            $displayDate = $m[1] . '/' . $m[2] . '/' . $m[3];
        }
    }

    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['csv_output'] = $csvContent;
    $_SESSION['csv_filename'] = 'dailyoneattempt' . $dateStr . '.csv';
    $_SESSION['csv_display_date'] = $displayDate;
    $_SESSION['csv_ready'] = true;
    $_SESSION['row_count'] = count($result);
    return null; // no error
}

// ── Fetch CSV from MaxContact ──
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

    // Capture ASP.NET session cookie
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

    // Capture UserCookie from login response
    if (preg_match('/UserCookie=([^;\s]+)/', $respHeaders, $um)) {
        $cookies .= '; UserCookie=' . $um[1];
    }
    // Capture any updated session cookie
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

// ── Handle file upload ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    if ($file['error'] !== UPLOAD_ERR_OK || pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
        $error = 'Please upload a valid CSV file.';
    } else {
        $raw = file_get_contents($file['tmp_name']);
        $error = processCSV($raw);
    }
}

// ── Handle MaxContact fetch ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fetch_date'])) {
    $fetchDate = trim($_POST['fetch_date']); // YYYY-MM-DD from date input
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fetchDate, $dm)) {
        $mcDate = $dm[3] . '/' . $dm[2] . '/' . $dm[1]; // DD/MM/YYYY
        [$csvData, $fetchError] = fetchMaxContactCSV($mcDate);
        if ($fetchError) {
            $error = $fetchError;
        } else {
            $error = processCSV($csvData);
        }
    } else {
        $error = 'Invalid date selected.';
    }
}

// Handle download
if (isset($_GET['download'])) {
    session_start();
    if (!empty($_SESSION['csv_output'])) {
        header('Content-Type: text/csv');
        $fname = $_SESSION['csv_filename'] ?? 'processed.csv';
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        echo $_SESSION['csv_output'];
        exit;
    }
}

// Handle email send (with attachment)
if (isset($_GET['send_email'])) {
    session_start();
    if (!empty($_SESSION['csv_output'])) {
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

            $displayDate = $_SESSION['csv_display_date'] ?? '';
            $mail->Subject = 'Daily One Attempt ' . $displayDate;
            $mail->isHTML(true);
            $mail->Body = 'Hi Tina,<br><br>Please see attached.<br><br>Kind regards,<br><br>Ryan Lancaster<br><b>Dialler Manager<br>DWM Administration Services</b>';

            $fname = $_SESSION['csv_filename'] ?? 'processed.csv';
            $mail->addStringAttachment($_SESSION['csv_output'], $fname, 'base64', 'text/csv');

            $mail->send();
            $_SESSION['email_sent'] = true;
        } catch (Exception $e) {
            $_SESSION['email_error'] = $mail->ErrorInfo;
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

// Handle "nothing to report" email (no attachment)
if (isset($_GET['send_nothing'])) {
    session_start();
    if (!empty($_SESSION['csv_ready'])) {
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

            $displayDate = $_SESSION['csv_display_date'] ?? '';
            $mail->Subject = 'Daily One Attempt ' . $displayDate;
            $mail->isHTML(true);
            $mail->Body = 'Hi Tina,<br><br>Nothing to report today.<br><br>Kind regards,<br><br>Ryan Lancaster<br><b>Dialler Manager<br>DWM Administration Services</b>';

            $mail->send();
            $_SESSION['email_sent'] = true;
        } catch (Exception $e) {
            $_SESSION['email_error'] = $mail->ErrorInfo;
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Daily One Attempt</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.1); padding: 40px; max-width: 720px; width: 100%; }
        h1 { font-size: 1.5rem; margin-bottom: 8px; color: #1a1a2e; }
        .subtitle { color: #666; font-size: 0.9rem; margin-bottom: 24px; }
        .upload-area { border: 2px dashed #ccc; border-radius: 8px; padding: 32px; text-align: center; margin-bottom: 20px; transition: border-color 0.2s; }
        .upload-area:hover { border-color: #4a6cf7; }
        .upload-area input[type="file"] { margin: 12px 0; }
        .btn { display: inline-block; padding: 10px 24px; border-radius: 6px; border: none; font-size: 0.95rem; cursor: pointer; text-decoration: none; transition: background 0.2s; }
        .btn-primary { background: #4a6cf7; color: #fff; }
        .btn-primary:hover { background: #3a5ce5; }
        .btn-success { background: #27ae60; color: #fff; }
        .btn-success:hover { background: #219a52; }
        .btn-email { background: #e67e22; color: #fff; }
        .btn-email:hover { background: #d35400; }
        .btn-wrap { text-align: center; }
        .btn-wrap .btn { margin: 4px 6px; }
        .btn-fetch { background: #2c3e50; color: #fff; }
        .btn-fetch:hover { background: #1a252f; }
        .fetch-area { border: 2px solid #2c3e50; border-radius: 8px; padding: 20px; text-align: center; margin-bottom: 8px; }
        .fetch-area p { font-weight: 600; margin-bottom: 10px; }
        .fetch-area input[type="date"] { padding: 6px 10px; border-radius: 4px; border: 1px solid #ccc; font-size: 0.95rem; }
        .btn-preview { background: #8e44ad; color: #fff; }
        .btn-preview:hover { background: #7d3c98; }
        .error { background: #fee; color: #c0392b; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
        .success { background: #eafaf1; color: #27ae60; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
        .preview-table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 0.85rem; }
        .preview-table th, .preview-table td { border: 1px solid #ddd; padding: 6px 10px; text-align: left; }
        .preview-table th { background: #f5f6fa; font-weight: 600; }
        .preview-table tr:nth-child(even) { background: #fafafa; }
        .preview-wrap { display: none; overflow-x: auto; margin-bottom: 16px; }
    </style>
</head>
<body>
<div class="card">
    <h1>Daily One Attempt</h1>
    <p class="subtitle">Upload a call history CSV to filter and deduplicate records.</p>

    <?php if (!empty($error)): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($_SESSION['email_sent'])): ?>
        <div class="success">Email sent successfully.</div>
        <?php unset($_SESSION['email_sent']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['email_error'])): ?>
        <div class="error">Email failed: <?= htmlspecialchars($_SESSION['email_error']) ?></div>
        <?php unset($_SESSION['email_error']); ?>
    <?php endif; ?>

    <?php if (!empty($_SESSION['csv_ready']) && (int)$_SESSION['row_count'] === 0): ?>
        <div class="success">
            Processing complete — no rows remain. Nothing to report.
        </div>
        <div class="btn-wrap">
            <a href="?send_nothing=1" class="btn btn-email" onclick="return confirm('Send &quot;nothing to report&quot; email?')">Send Nothing to Report</a>
        </div>
        <hr style="margin: 20px 0; border: none; border-top: 1px solid #eee;">
    <?php elseif (!empty($_SESSION['csv_ready'])): ?>
        <div class="success">
            Processing complete — <?= (int)$_SESSION['row_count'] ?> rows in result.
        </div>
        <div class="btn-wrap">
            <a href="?download=1" class="btn btn-success">Download CSV</a>
            <a href="?send_email=1" class="btn btn-email" onclick="return confirm('Send email to recipients?')">Send Email</a>
            <button type="button" class="btn btn-preview" onclick="var p=document.getElementById('preview');p.style.display=p.style.display==='none'?'block':'none'">Preview</button>
        </div>
        <div id="preview" class="preview-wrap">
            <?php
            $csvRows = array_map('str_getcsv', explode("\n", trim($_SESSION['csv_output'])));
            if (!empty($csvRows)):
                $headers = array_shift($csvRows);
            ?>
            <table class="preview-table">
                <thead><tr><?php foreach ($headers as $h): ?><th><?= htmlspecialchars($h) ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                <?php foreach ($csvRows as $r): if (empty(array_filter($r))) continue; ?>
                    <tr><?php foreach ($r as $cell): ?><td><?= htmlspecialchars($cell) ?></td><?php endforeach; ?></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
        <hr style="margin: 20px 0; border: none; border-top: 1px solid #eee;">
    <?php endif; ?>

    <form method="POST">
        <div class="fetch-area">
            <p>Fetch from MaxContact</p>
            <label for="fetch_date">Date:</label>
            <input type="date" id="fetch_date" name="fetch_date" value="<?= date('Y-m-d', strtotime('-1 day')) ?>" required>
            <div class="btn-wrap" style="margin-top: 12px;">
                <button type="submit" class="btn btn-fetch">Fetch &amp; Process</button>
            </div>
        </div>
    </form>

    <div style="text-align: center; color: #999; margin: 16px 0; font-size: 0.85rem;">— or upload manually —</div>

    <form method="POST" enctype="multipart/form-data">
        <div class="upload-area">
            <p>Select a CSV file to process</p>
            <input type="file" name="csv_file" accept=".csv" required>
        </div>
        <div class="btn-wrap"><button type="submit" class="btn btn-primary">Process CSV</button></div>
    </form>
</div>
</body>
</html>
