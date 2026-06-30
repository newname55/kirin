<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

/**
 * admin_store_parse.php
 *
 * 店舗サイトを解析して設定候補を返す AJAX エンドポイント。
 * POST /twin/admin_store_parse.php
 *   url       : 解析対象 URL
 *   store_key : 比較対象の store_key（現在値取得用）
 *
 * レスポンス: JSON
 */

require_once CREW_PRIVATE_ROOT . '/app/admin_common.php';
require_once CREW_PRIVATE_ROOT . '/app/config.php';
require_once CREW_PRIVATE_ROOT . '/app/knowledge/stores.php';
require_once CREW_PRIVATE_ROOT . '/app/services/StoreSiteParser.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex');

// 管理者認証
if (!twin_admin_is_logged_in()) {
    http_response_code(401);
    echo json_encode(['error' => '認証が必要です'], JSON_UNESCAPED_UNICODE);
    exit;
}

// POST のみ
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST のみ受け付けます'], JSON_UNESCAPED_UNICODE);
    exit;
}

// CSRF トークン検証
$csrfToken = (string) ($_POST['csrf_token'] ?? '');
if ($csrfToken === '' || !twin_admin_verify_csrf_token($csrfToken)) {
    error_log('[admin_store_parse] CSRF mismatch: received=' . substr($csrfToken, 0, 8) . '...'
        . ' session=' . substr((string) ($_SESSION['twin_admin_csrf_token'] ?? ''), 0, 8) . '...');
    http_response_code(403);
    echo json_encode(['error' => 'CSRF トークンが無効です'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawUrl   = trim((string) ($_POST['url'] ?? ''));
$storeKey = trim((string) ($_POST['store_key'] ?? 'seika'));

// URL バリデーション（http/https のみ許可）
if ($rawUrl === '') {
    http_response_code(400);
    echo json_encode(['error' => 'url が未指定です'], JSON_UNESCAPED_UNICODE);
    exit;
}
$parsed = parse_url($rawUrl);
if (!isset($parsed['scheme']) || !in_array($parsed['scheme'], ['http', 'https'], true)) {
    http_response_code(400);
    echo json_encode(['error' => '有効な URL を指定してください（http/https）'], JSON_UNESCAPED_UNICODE);
    exit;
}
// プライベート IP レンジへのアクセスを禁止（SSRF 対策）
$host = $parsed['host'] ?? '';
if (self_is_private_host($host)) {
    http_response_code(400);
    echo json_encode(['error' => 'ローカルホストへのアクセスは禁止されています'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 現在の店舗設定を取得（比較用）
$currentConfig = twin_store_config($storeKey);

// サイト解析
$fetched = StoreSiteParser::parse($rawUrl);

// 比較対象フィールド定義（label付き）
$fields = [
    'business_hours' => '営業時間',
    'closed_days'    => '定休日',
    'address'        => '所在地',
    'area'           => 'エリア',
    'tel'            => '電話番号',
    'price_summary'  => '料金概要',
    'nomination_fee' => '指名料',
    'vip_room_fee'   => 'VIP料金',
    'service_charge' => 'サービス料',
    'site_url'       => '公式サイト URL',
    'line_url'       => 'LINE URL',
    'instagram_url'  => 'Instagram URL',
    'twitter_url'    => 'X(Twitter) URL',
    'tiktok_url'     => 'TikTok URL',
    'google_map_url' => 'Google Map URL',
];

$diff = [];
foreach ($fields as $key => $label) {
    $current = trim((string) ($currentConfig[$key] ?? ''));
    $fetched_val = trim((string) ($fetched[$key] ?? ''));
    $source  = (string) ($fetched['_source'][$key] ?? '');

    $diff[$key] = [
        'label'   => $label,
        'current' => $current !== '' ? $current : null,
        'fetched' => $fetched_val !== '' ? $fetched_val : null,
        'source'  => $source,
        'changed' => ($fetched_val !== '' && $fetched_val !== $current),
    ];
}

echo json_encode([
    'ok'     => true,
    'url'    => $rawUrl,
    'diff'   => $diff,
    'errors' => $fetched['_errors'] ?? [],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;

// ── SSRF 対策 ──────────────────────────────────────────────────────────

function self_is_private_host(string $host): bool
{
    if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
        return true;
    }
    $ip = gethostbyname($host);
    if ($ip === $host) {
        return false; // 解決失敗（通常は外部ホスト）
    }
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}
