<?php
/**
 * Supabase helper functions for posting review sessions and attempts.
 *
 * Used by both cron.php (CLI) and index.php (web UI) to push processed
 * CSV rows to Supabase after emailing. Returns a review URL for Tina.
 */

/**
 * Create a review session and insert all attempts into Supabase.
 *
 * @param string $reportDate  Date in YYYY-MM-DD format
 * @param string $csvContent  Processed CSV string (with header row)
 * @param int    $rowCount    Number of data rows
 * @return array [reviewUrl, error] — reviewUrl is null on failure
 */
function createReviewSession($reportDate, $csvContent, $rowCount) {
    $supabaseUrl = defined('SUPABASE_URL') ? SUPABASE_URL : getenv('SUPABASE_URL');
    $serviceKey  = defined('SUPABASE_SERVICE_KEY') ? SUPABASE_SERVICE_KEY : getenv('SUPABASE_SERVICE_KEY');
    $pagesUrl    = defined('PAGES_URL') ? PAGES_URL : getenv('PAGES_URL');

    if (!$supabaseUrl || !$serviceKey || !$pagesUrl) {
        return [null, 'Supabase config missing (SUPABASE_URL, SUPABASE_SERVICE_KEY, or PAGES_URL)'];
    }

    // Check if a session already exists for this date
    $existing = supabaseGet($supabaseUrl, $serviceKey, 'review_sessions', 'report_date=eq.' . $reportDate . '&select=token&limit=1');
    if (!empty($existing['data'])) {
        $existingToken = $existing['data'][0]['token'];
        $reviewUrl = rtrim($pagesUrl, '/') . '/review.html?token=' . $existingToken;
        return [$reviewUrl, null];
    }

    // Generate a unique token
    $token = bin2hex(random_bytes(16));

    // Insert review session
    $sessionData = [
        'token'       => $token,
        'report_date' => $reportDate,
        'total_rows'  => $rowCount,
    ];

    $result = supabasePost($supabaseUrl, $serviceKey, 'review_sessions', $sessionData);
    if ($result['error']) {
        return [null, 'Failed to create review session: ' . $result['error']];
    }

    $sessionId = $result['data'][0]['id'] ?? null;
    if (!$sessionId) {
        return [null, 'No session ID returned from Supabase'];
    }

    // Parse CSV rows into attempt records
    $lines = explode("\n", trim($csvContent));
    $header = str_getcsv(array_shift($lines));
    $header = array_map('trim', $header);

    $colMap = array_flip($header);
    $attempts = [];

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;
        $row = str_getcsv($line);

        $attempts[] = [
            'session_id'    => $sessionId,
            'startdatetime' => $row[$colMap['startdatetime']] ?? '',
            'fullname'      => $row[$colMap['fullname']] ?? '',
            'resultcode'    => $row[$colMap['resultcodedescription']] ?? '',
            'phonenumber'   => $row[$colMap['phonenumber']] ?? '',
            'disconnector'  => $row[$colMap['disconnector']] ?? '',
        ];
    }

    // Batch insert attempts
    if (!empty($attempts)) {
        $result = supabasePost($supabaseUrl, $serviceKey, 'attempts', $attempts);
        if ($result['error']) {
            return [null, 'Failed to insert attempts: ' . $result['error']];
        }
    }

    $reviewUrl = rtrim($pagesUrl, '/') . '/review.html?token=' . $token;
    return [$reviewUrl, null];
}

/**
 * GET data from a Supabase table via REST API.
 */
function supabaseGet($baseUrl, $serviceKey, $table, $query) {
    $url = rtrim($baseUrl, '/') . '/rest/v1/' . $table . '?' . $query;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'apikey: ' . $serviceKey,
            'Authorization: Bearer ' . $serviceKey,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || $httpCode < 200 || $httpCode >= 300) {
        return ['data' => null, 'error' => $curlError ?: "HTTP $httpCode"];
    }

    return ['data' => json_decode($response, true), 'error' => null];
}

/**
 * POST data to a Supabase table via REST API.
 */
function supabasePost($baseUrl, $serviceKey, $table, $data) {
    $url = rtrim($baseUrl, '/') . '/rest/v1/' . $table;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: ' . $serviceKey,
            'Authorization: Bearer ' . $serviceKey,
            'Prefer: return=representation',
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['data' => null, 'error' => "cURL: $curlError"];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        return ['data' => null, 'error' => "HTTP $httpCode: $response"];
    }

    return ['data' => json_decode($response, true), 'error' => null];
}
