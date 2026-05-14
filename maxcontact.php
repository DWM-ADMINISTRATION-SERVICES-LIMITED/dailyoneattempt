<?php
/**
 * MaxContact API client.
 *
 * Shared between cron.php (daily one-attempt report),
 * verifications-report.php (weekly productivity report), and
 * verifications-agent-report.php (weekly agent-facing report).
 *
 * Requires the following constants to be defined:
 *   MC_BASE_URL, MC_USERNAME, MC_PASSWORD, MC_CAMPAIGN_ID
 */

/**
 * Log in to the MaxContact Manager Portal and return session cookies.
 *
 * @return array [cookieHeader, error] — cookieHeader is null on failure
 */
function mcLogin() {
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

    return [$cookies, null];
}

/**
 * Fetch the raw RecordHistory CSV export for a single day.
 *
 * @param string $dateStr Date in d/m/Y format (e.g. "20/02/2026")
 * @return array [csvData, error]
 */
function fetchMaxContactCSV($dateStr) {
    [$cookies, $err] = mcLogin();
    if ($err) return [null, $err];

    $startDate = $dateStr . ' 00:00';
    $dt = DateTime::createFromFormat('d/m/Y', $dateStr);
    $dt->modify('+1 day');
    $endDate = $dt->format('d/m/Y') . ' 00:00';

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

    if ($curlError) return [null, "cURL error: $curlError"];
    if ($httpCode !== 200) return [null, "MaxContact returned HTTP $httpCode."];
    if (empty($csvData) || stripos($csvData, 'historyid') === false) {
        return [null, "Response did not contain expected CSV data."];
    }

    return [$csvData, null];
}

/**
 * Fetch the "Occupancy Summary by Campaign" Telerik report.
 *
 * Returns per-agent time breakdown: log_on, man_hours, break, productive,
 * talk, hold, ready, preview, not_ready, ringing, conferencing, wrap,
 * callback, interacting, other (all as HH:MM:SS strings).
 *
 * @param string $startISO    Start datetime, e.g. "2026-05-05T00:00:00"
 * @param string $endISO      End datetime, e.g. "2026-05-12T00:00:00"
 * @param array  $campaignIds Campaign IDs as strings, e.g. ['238']
 * @return array [agentData, error]
 */
function fetchOccupancyReport($startISO, $endISO, $campaignIds) {
    [$cookies, $err] = mcLogin();
    if ($err) return [null, $err];

    $base = MC_BASE_URL . '/api/reportwebapi';

    // 1. Register client
    [$h, $body] = mcReportApiCall($base . '/clients', $cookies, 'POST', '{}');
    if ($h !== 200) return [null, "Telerik client registration failed: HTTP $h $body"];
    $clientId = json_decode($body, true)['clientId'] ?? null;
    if (!$clientId) return [null, "No clientId in response: $body"];

    // 2. Create instance
    $instanceBody = json_encode([
        'report' => 'OccupancySummarybyCampaign.trdx',
        'parameterValues' => [
            'startDate'   => $startISO,
            'endDate'     => $endISO,
            'campaignIds' => array_values(array_map('strval', $campaignIds)),
            'Culture'     => ['en-GB'],
        ],
    ]);
    [$h, $body] = mcReportApiCall($base . "/clients/$clientId/instances", $cookies, 'POST', $instanceBody);
    if ($h !== 201) return [null, "Telerik instance creation failed: HTTP $h $body"];
    $instId = json_decode($body, true)['instanceId'] ?? null;
    if (!$instId) return [null, "No instanceId in response: $body"];

    // 3. Request CSV document
    [$h, $body] = mcReportApiCall($base . "/clients/$clientId/instances/$instId/documents", $cookies, 'POST', json_encode(['format' => 'CSV', 'deviceInfo' => new stdClass()]));
    if ($h !== 202) return [null, "Telerik document request failed: HTTP $h $body"];
    $docId = json_decode($body, true)['documentId'] ?? null;
    if (!$docId) return [null, "No documentId in response: $body"];

    // 4. Poll for ready (up to 60 seconds)
    $ready = false;
    for ($i = 0; $i < 30; $i++) {
        [$h, $body] = mcReportApiCall($base . "/clients/$clientId/instances/$instId/documents/$docId/info", $cookies);
        if ($h !== 200 && $h !== 202) return [null, "Polling failed: HTTP $h $body"];
        $info = json_decode($body, true);
        if (!empty($info['documentReady'])) { $ready = true; break; }
        sleep(2);
    }
    if (!$ready) return [null, "Document never became ready"];

    // 5. Download CSV
    [$h, $csv] = mcReportApiCall($base . "/clients/$clientId/instances/$instId/documents/$docId", $cookies);
    if ($h !== 200) return [null, "Download failed: HTTP $h"];

    return [parseOccupancyCsv($csv), null];
}

/**
 * Internal helper for Telerik Reports REST API calls.
 */
function mcReportApiCall($url, $cookies, $method = 'GET', $body = null) {
    $ch = curl_init($url);
    $headers = ['Cookie: ' . $cookies, 'Accept: application/json'];
    if ($body !== null) $headers[] = 'Content-Type: application/json';
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CUSTOMREQUEST  => $method,
    ];
    if ($body !== null) $opts[CURLOPT_POSTFIELDS] = $body;
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$http, $resp];
}

/**
 * Parse the Telerik OccupancySummary CSV into per-agent metrics.
 *
 * The CSV is wide and irregular (Telerik dumps each textbox as a column),
 * but each row contains a "User" marker followed by the agent name, then
 * alternating labels and HH:MM:SS values. We just scan each row for the
 * labels we care about.
 *
 * @return array [agentName => [metricSlug => 'HH:MM:SS', ...]]
 */
function parseOccupancyCsv($csv) {
    $labels = [
        'Log On Time'           => 'log_on',
        'Man Hours'             => 'man_hours',
        'Break Time'            => 'break',
        'Productive Time'       => 'productive',
        'Talk Time'             => 'talk',
        'Hold Time'             => 'hold',
        'Ready Time'            => 'ready',
        'Preview Time'          => 'preview',
        'Not Ready Time'        => 'not_ready',
        'Ringing Time '         => 'ringing',  // trailing space is intentional - matches the report
        'Conferencing Time'     => 'conferencing',
        'Wrap Time'             => 'wrap',
        'Managing Callback Time' => 'callback',
        'Interacting TIme '     => 'interacting',  // typo and trailing space match the report
        'Other Time'            => 'other',
    ];

    $agents = [];
    $lines = preg_split('/\r?\n/', trim($csv));
    array_shift($lines);  // skip header row

    foreach ($lines as $line) {
        $cells = str_getcsv($line);
        $userIdx = array_search('User', $cells, true);
        if ($userIdx === false) continue;
        $name = trim($cells[$userIdx + 1] ?? '');
        if ($name === '' || $name === 'Total') continue;

        $data = [];
        $n = count($cells);
        for ($i = $userIdx + 2; $i < $n; $i++) {
            if (isset($labels[$cells[$i]])) {
                $slug = $labels[$cells[$i]];
                $data[$slug] = $cells[$i + 2] ?? '';
            }
        }
        $agents[$name] = $data;
    }

    return $agents;
}

/**
 * Parse "HH:MM:SS" into total seconds.
 */
function parseHmsTime($str) {
    if (!preg_match('/^(\d+):(\d+):(\d+)$/', trim($str), $m)) return 0;
    return (int)$m[1] * 3600 + (int)$m[2] * 60 + (int)$m[3];
}

/**
 * Format seconds as "Xh:Ym:Zs".
 */
function formatHms($seconds) {
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return "{$h}h:{$m}m:{$s}s";
}
