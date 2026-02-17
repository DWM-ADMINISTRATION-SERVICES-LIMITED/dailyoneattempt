<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];

    if ($file['error'] !== UPLOAD_ERR_OK || pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
        $error = 'Please upload a valid CSV file.';
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        $header = fgetcsv($handle);

        if ($header === false) {
            $error = 'CSV file is empty.';
            fclose($handle);
        } else {
            $header = array_map('trim', $header);

            $keepCols = ['startdatetime', 'fullname', 'resultcodedescription', 'phonenumber', 'disconnector'];
            $colIndexes = [];
            foreach ($keepCols as $col) {
                $idx = array_search($col, $header);
                if ($idx === false) {
                    $error = "Missing required column: $col";
                    fclose($handle);
                    break;
                }
                $colIndexes[$col] = $idx;
            }

            if (!isset($error)) {
                $rows = [];
                while (($row = fgetcsv($handle)) !== false) {
                    $rows[] = $row;
                }
                fclose($handle);

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
                if (!empty($rows[0][$colIndexes['startdatetime']])) {
                    $raw = trim($rows[0][$colIndexes['startdatetime']]);
                    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $raw, $m)) {
                        $dateStr = $m[1] . $m[2] . substr($m[3], 2); // DDMMYY
                    }
                }
                $outputFilename = 'dailyoneattempt' . $dateStr . '.csv';

                // Build display date (DD/MM/YYYY) for email subject
                $displayDate = '';
                if (!empty($m)) {
                    $displayDate = $m[1] . '/' . $m[2] . '/' . $m[3];
                }

                // Store in session for download
                session_start();
                $_SESSION['csv_output'] = $csvContent;
                $_SESSION['csv_filename'] = $outputFilename;
                $_SESSION['csv_display_date'] = $displayDate;
                $_SESSION['csv_ready'] = true;
                $_SESSION['row_count'] = count($result);
            }
        }
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
            $mail->Body    = '';

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
        .card { background: #fff; border-radius: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.1); padding: 40px; max-width: 480px; width: 100%; }
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
        .error { background: #fee; color: #c0392b; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
        .success { background: #eafaf1; color: #27ae60; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
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
        </div>
        <hr style="margin: 20px 0; border: none; border-top: 1px solid #eee;">
    <?php endif; ?>

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
