<?php

declare(strict_types=1);

function twin_wbss_normalize_base_url(string $baseUrl): string
{
    return rtrim($baseUrl, '/');
}

function twin_wbss_build_url(string $endpoint, array $query = []): string
{
    $baseUrl = twin_wbss_normalize_base_url(WBSS_API_BASE_URL);
    $endpoint = ltrim($endpoint, '/');
    $url = $baseUrl . '/' . $endpoint;

    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    return $url;
}

function twin_wbss_request(string $endpoint, array $query = []): array
{
    $apiKey = trim((string) WBSS_TWIN_API_KEY);
    if ($apiKey === '') {
        error_log('[WBSS] API key is not configured.');
        return [
            'ok' => false,
            'error' => 'API key is not configured.',
            'http_status' => 0,
            'elapsed_ms' => 0,
        ];
    }

    if (!function_exists('curl_init')) {
        error_log('[WBSS] cURL extension is not available.');
        return [
            'ok' => false,
            'error' => 'cURL extension is not available.',
            'http_status' => 0,
            'elapsed_ms' => 0,
        ];
    }

    $url = twin_wbss_build_url($endpoint, $query);
    $timeout = max(1, (int) WBSS_API_TIMEOUT_SECONDS);
    $started = microtime(true);

    $ch = curl_init($url);
    if ($ch === false) {
        error_log('[WBSS] Failed to initialize cURL.');
        return [
            'ok' => false,
            'error' => 'Failed to initialize cURL.',
            'http_status' => 0,
            'elapsed_ms' => 0,
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPGET => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
        CURLOPT_HTTPHEADER => [
            'X-TWIN-API-KEY: ' . $apiKey,
            'Accept: application/json',
        ],
    ]);

    $raw = curl_exec($ch);
    $curlErrno = curl_errno($ch);
    $curlError = curl_error($ch);
    $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

    $elapsedMs = (int) round((microtime(true) - $started) * 1000);

    if ($raw === false || $curlErrno !== 0) {
        $error = $curlError !== '' ? $curlError : 'Unknown cURL error';
        error_log('[WBSS] Request failed: ' . $error);
        return [
            'ok' => false,
            'error' => $error,
            'http_status' => $httpStatus,
            'elapsed_ms' => $elapsedMs,
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        error_log('[WBSS] Invalid JSON response: ' . $raw);
        return [
            'ok' => false,
            'error' => 'Invalid JSON response.',
            'http_status' => $httpStatus,
            'elapsed_ms' => $elapsedMs,
        ];
    }

    $isNotFoundMessage = static function (string $message): bool {
        $message = trim($message);
        if ($message === '') {
            return false;
        }

        return (bool) preg_match('/(見つかりません|見つかりませんでした|not\s*found|該当.*キャスト)/iu', $message);
    };

    if ($httpStatus < 200 || $httpStatus >= 300) {
        $message = (string) ($decoded['message'] ?? $decoded['error'] ?? 'WBSS HTTP error.');
        if ($isNotFoundMessage($message)) {
            return [
                'ok' => true,
                'http_status' => $httpStatus,
                'elapsed_ms' => $elapsedMs,
                'data' => array_merge($decoded, [
                    'found' => false,
                    'status' => 'not_found',
                    'message' => $message,
                ]),
            ];
        }

        error_log('[WBSS] HTTP error ' . $httpStatus . ': ' . $message);
        return [
            'ok' => false,
            'error' => $message,
            'http_status' => $httpStatus,
            'elapsed_ms' => $elapsedMs,
            'data' => $decoded,
        ];
    }

    if (array_key_exists('ok', $decoded) && !$decoded['ok']) {
        $message = (string) ($decoded['message'] ?? $decoded['error'] ?? 'WBSS API returned ok=false.');
        if ($isNotFoundMessage($message)) {
            return [
                'ok' => true,
                'http_status' => $httpStatus,
                'elapsed_ms' => $elapsedMs,
                'data' => array_merge($decoded, [
                    'found' => false,
                    'status' => 'not_found',
                    'message' => $message,
                ]),
            ];
        }

        error_log('[WBSS] API error: ' . $message);
        return [
            'ok' => false,
            'error' => $message,
            'http_status' => $httpStatus,
            'elapsed_ms' => $elapsedMs,
            'data' => $decoded,
        ];
    }

    return [
        'ok' => true,
        'http_status' => $httpStatus,
        'elapsed_ms' => $elapsedMs,
        'data' => $decoded,
    ];
}

function twin_wbss_fetch_attendance(string $store = 'seika', ?string $date = null): array
{
    $query = ['store' => $store];
    if ($date !== null && $date !== '') {
        $query['date'] = $date;
    }

    return twin_wbss_request('attendance.php', $query);
}

function twin_wbss_fetch_cast_schedule(string $store = 'seika', string $castName = '', ?string $date = null): array
{
    $query = ['store' => $store, 'cast' => $castName];
    if ($date !== null && $date !== '') {
        $query['date'] = $date;
    }

    return twin_wbss_request('cast_schedule.php', $query);
}
