<?php
/**
 * MaxContact API client.
 *
 * Shared between cron.php (daily one-attempt report) and
 * verifications-report.php (weekly productivity report).
 *
 * Requires the following constants to be defined:
 *   MC_BASE_URL, MC_USERNAME, MC_PASSWORD, MC_CAMPAIGN_ID
 */

/**
 * Fetch the raw CSV export for a single day from MaxContact.
 *
 * @param string $dateStr Date in d/m/Y format (e.g. "20/02/2026")
 * @return array [csvData, error] — csvData is null on failure
 */
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
