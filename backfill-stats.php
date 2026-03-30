<?php
/**
 * One-off backfill script: fetch raw CSVs from MaxContact for each day
 * in a given month and store total_calls + per-agent stats in Supabase.
 *
 * Usage: php backfill-stats.php 2026-03
 */

require __DIR__ . '/config.php';
require __DIR__ . '/supabase.php';

// Reuse fetchMaxContactCSV from cron.php without running its main logic
// — inline the function since cron.php executes on require
function fetchMC($dateStr) {
    $startDate = $dateStr . ' 00:00';
    $dt = DateTime::createFromFormat('d/m/Y', $dateStr);
    $dt->modify('+1 day');
    $endDate = $dt->format('d/m/Y') . ' 00:00';

    $ch = curl_init(MC_BASE_URL . '/Home/Login');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_HEADER => true, CURLOPT_SSL_VERIFYPEER => true]);
    $resp = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $respHeaders = substr($resp, 0, $headerSize);
    curl_close($ch);

    $cookies = '';
    if (preg_match('/ASP\.NET_SessionId=([^;\s]+)/', $respHeaders, $sm)) {
        $cookies = 'ASP.NET_SessionId=' . $sm[1];
    }

    $ch = curl_init(MC_BASE_URL . '/Home/Login');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['Login' => MC_USERNAME, 'Password' => MC_PASSWORD]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'X-Requested-With: XMLHttpRequest', 'Cookie: ' . $cookies],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $respHeaders = substr($resp, 0, curl_getinfo($ch, CURLINFO_HEADER_SIZE));
    curl_close($ch);

    if (preg_match('/UserCookie=([^;\s]+)/', $respHeaders, $um)) $cookies .= '; UserCookie=' . $um[1];
    if (preg_match('/ASP\.NET_SessionId=([^;\s]+)/', $respHeaders, $sm2)) $cookies = preg_replace('/ASP\.NET_SessionId=[^;]+/', 'ASP.NET_SessionId=' . $sm2[1], $cookies);

    $params = http_build_query([
        'startDate' => $startDate, 'endDate' => $endDate, 'identity' => '', 'userID' => 0,
        'campaignID' => MC_CAMPAIGN_ID, 'listID' => 0, 'reference' => '', 'isSuccess' => 'false',
        'isAssociated' => 'false', 'isHotKey' => 'false', 'isThreadFiltered' => 'false', 'csatRating' => 0,
        'leadID' => 0, 'leadPhoneID' => 0, 'resultCodeID' => 0, 'recLengthSearch' => 1,
        'recScaleSearch' => 0, 'recLength1' => 0, 'recLength2' => 0, 'channelId' => 0,
        'teamID' => 0, 'name' => '', 'name2' => '',
    ]);

    $ch = curl_init(MC_BASE_URL . '/RecordHistory/Export?' . $params);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => ['Cookie: ' . $cookies],
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_TIMEOUT => 120,
    ]);
    $csvData = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($csvData) || stripos($csvData, 'historyid') === false) {
        return [null, "Fetch failed (HTTP $httpCode)"];
    }
    return [$csvData, null];
}

// ── Main ──
$month = $argv[1] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    echo "Usage: php backfill-stats.php YYYY-MM\n";
    exit(1);
}

[$year, $mon] = explode('-', $month);
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, (int)$mon, (int)$year);

echo "Backfilling stats for $month ($daysInMonth days)\n\n";

for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateISO = sprintf('%s-%02d', $month, $d);
    $dateMC = sprintf('%02d/%s/%s', $d, $mon, $year);
    $dow = date('w', strtotime($dateISO));

    // Skip Sundays (no dialling)
    if ($dow == 0) {
        echo "[$dateISO] Sunday — skipped\n";
        continue;
    }

    // Skip future dates
    if ($dateISO > date('Y-m-d')) {
        echo "[$dateISO] Future — skipped\n";
        continue;
    }

    // Check if stats already exist for this date
    $supabaseUrl = SUPABASE_URL;
    $serviceKey = SUPABASE_SERVICE_KEY;
    $existing = supabaseGet($supabaseUrl, $serviceKey, 'review_sessions', 'report_date=eq.' . $dateISO . '&select=id,total_calls&limit=1');
    if (!empty($existing['data']) && $existing['data'][0]['total_calls'] !== null) {
        echo "[$dateISO] Already has stats (total_calls=" . $existing['data'][0]['total_calls'] . ") — skipped\n";
        continue;
    }

    echo "[$dateISO] Fetching from MaxContact... ";
    [$rawCsv, $fetchErr] = fetchMC($dateMC);
    if ($fetchErr) {
        echo "ERROR: $fetchErr\n";
        continue;
    }

    $lineCount = count(array_filter(explode("\n", $rawCsv), 'trim')) - 1;
    echo "$lineCount raw rows. ";

    // Ensure a session exists (create one if this was a 0-row day with no session)
    if (empty($existing['data'])) {
        $token = bin2hex(random_bytes(16));
        supabasePost($supabaseUrl, $serviceKey, 'review_sessions', [
            'token' => $token, 'report_date' => $dateISO, 'total_rows' => 0,
        ]);
        echo "Created session. ";
    }

    $statsErr = storeCallStats($dateISO, $rawCsv);
    if ($statsErr) {
        echo "STATS ERROR: $statsErr\n";
    } else {
        echo "Done.\n";
    }
}

echo "\nBackfill complete.\n";
