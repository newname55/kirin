<?php

declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once CREW_PRIVATE_ROOT . '/app/db.php';
require_once CREW_PRIVATE_ROOT . '/app/admin_common.php';
require_once CREW_PRIVATE_ROOT . '/app/response_engine.php';
require_once CREW_PRIVATE_ROOT . '/app/question_ranking.php';
require_once CREW_PRIVATE_ROOT . '/app/ai_character_settings.php';
require_once CREW_PRIVATE_ROOT . '/app/store.php';
require_once CREW_PRIVATE_ROOT . '/app/knowledge/stores.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function twin_minutes_seconds(int $seconds): string
{
    $seconds = max(0, $seconds);
    $minutes = intdiv($seconds, 60);
    $rest = $seconds % 60;
    return sprintf('%d分%02d秒', $minutes, $rest);
}

function twin_fetch_single_metric(PDO $pdo, string $sql): float
{
    $value = $pdo->query($sql)->fetchColumn();
    return $value === false || $value === null ? 0.0 : (float) $value;
}

function twin_normalize_rows(array $rows, string $keyField, string $countField): array
{
    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'key' => (string) ($row[$keyField] ?? ''),
            'count' => (int) ($row[$countField] ?? 0),
        ];
    }
    return $result;
}

function twin_bar_width(int $count, int $maxCount): string
{
    if ($maxCount <= 0) {
        return '0%';
    }

    return sprintf('%.1f%%', max(8, ($count / $maxCount) * 100));
}


function twin_response_mode_label(string $mode): string
{
    return match ($mode) {
        'rule'          => '安定モード',
        'openai'        => 'AI会話モード',
        'hybrid'        => 'ハイブリッドモード',
        'fallback_rule' => '安定モードへ自動切替',
        default         => $mode,
    };
}

function twin_wbss_call_label(string $value): string
{
    return match ($value) {
        'attendance_success' => '出勤取得 成功',
        'attendance_empty' => '出勤取得 空',
        'attendance_error' => '出勤取得 エラー',
        'cast_schedule_success' => '個別出勤 成功',
        'cast_schedule_not_working' => '個別出勤 予定なし',
        'cast_schedule_not_found' => '個別出勤 未確認',
        'cast_schedule_error' => '個別出勤 エラー',
        default => $value,
    };
}

function twin_wbss_call_class(string $value): string
{
    return match ($value) {
        'attendance_success', 'cast_schedule_success'    => 'wbss-success',
        'cast_schedule_not_found'                        => 'wbss-unknown',
        'cast_schedule_not_working'                      => 'wbss-off',
        'attendance_error', 'cast_schedule_error'        => 'wbss-error',
        'attendance_empty'                               => 'wbss-off',
        default                                          => '',
    };
}

function twin_admin_mode_description(string $mode): string
{
    return match ($mode) {
        'rule' => '固定ルール・店舗情報・出勤連携情報を使って返答します。安定して動き、AI利用料はかかりません。',
        'openai' => 'OpenAI APIを使って自然な会話を生成します。AI利用料が発生します。',
        'hybrid' => '料金・営業時間・出勤などの事実情報は安定モード、雑談や不安相談はAI会話モードで返す予定の方式です。v0.7で本格実装予定。',
        default => '',
    };
}

if (isset($_GET['logout'])) {
    twin_admin_logout();
    header('Location: /crew-onboarding/admin.php');
    exit;
}

$config = twin_config();
$adminCredentialsLocked = twin_admin_requires_env_credentials($config) && !twin_admin_has_env_credentials();


$adminNotice = '';
$adminWarning = '';

if (twin_admin_is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_settings') {
    $pdo = twin_db();
    $csrfToken = (string) ($_POST['csrf_token'] ?? '');

    if (!twin_admin_verify_csrf_token($csrfToken)) {
        header('Location: /crew-onboarding/admin.php?settings_error=csrf');
        exit;
    }

    $responseMode = twin_normalize_response_mode_value((string) ($_POST['response_mode'] ?? ''));
    if ($responseMode === '') {
        header('Location: /crew-onboarding/admin.php?settings_error=invalid_mode');
        exit;
    }

    $openaiModel = twin_normalize_openai_model_value((string) ($_POST['openai_model'] ?? ''));
    if ($openaiModel === '') {
        $openaiModel = 'gpt-4.1-mini';
    }

    twin_app_settings_upsert($pdo, 'response_mode', $responseMode);
    twin_app_settings_upsert($pdo, 'openai_model', $openaiModel);
    twin_admin_log_event($pdo, 'admin_setting_changed', 'response_mode:' . $responseMode . ';openai_model:' . $openaiModel);

    header('Location: /crew-onboarding/admin.php?settings_saved=1');
    exit;
}

// v0.9.1: AIキャラクター設定 保存
if (twin_admin_is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_wbss_env') {
    $pdo = twin_db();
    if (!twin_admin_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        $adminWarning = 'CSRFトークンが不正です。';
    } else {
        $wbssEnvVal = trim((string) ($_POST['wbss_env'] ?? ''));
        if (in_array($wbssEnvVal, twin_wbss_allowed_envs(), true)) {
            twin_app_settings_upsert($pdo, 'wbss_env', $wbssEnvVal);
            $adminNotice = 'WBSS接続先を「' . twin_wbss_env_label($wbssEnvVal) . '」に切り替えました。次回の出勤API呼び出しから反映されます。';
        } else {
            $adminWarning = 'WBSS接続先の値が不正です。';
        }
    }
}

if (twin_admin_is_logged_in() && $_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'save_character') {
    $pdo = twin_db();
    if (!twin_admin_verify_csrf_token((string) ($_POST['csrf_token'] ?? ''))) {
        header('Location: /crew-onboarding/admin.php?char_error=csrf');
        exit;
    }

    $data = [
        'id'              => (int) ($_POST['character_id'] ?? 0),
        'store_id'        => twin_current_store_key(),
        'ai_name'         => trim((string) ($_POST['ai_name'] ?? '')),
        'ai_title'        => trim((string) ($_POST['ai_title'] ?? '')),
        'greeting_message'=> trim((string) ($_POST['greeting_message'] ?? '')),
        'is_active'       => (int) (isset($_POST['is_active'])),
        'character_image_path' => null,
        'logo_image_path'      => null,
    ];

    // 既存の画像パスを引き継ぐ
    if ($data['id'] > 0) {
        $existing = $pdo->prepare('SELECT character_image_path, logo_image_path FROM ai_character_settings WHERE id = ?');
        $existing->execute([$data['id']]);
        $existingRow = $existing->fetch() ?: [];
        $data['character_image_path'] = $existingRow['character_image_path'] ?? null;
        $data['logo_image_path']      = $existingRow['logo_image_path'] ?? null;
    }

    // キャラクター画像アップロード
    if (!empty($_FILES['character_image']['tmp_name']) && (int) ($_FILES['character_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $uploaded = twin_ai_character_upload_image($_FILES['character_image'], 'character');
        if ($uploaded !== null) {
            $data['character_image_path'] = $uploaded;
        } else {
            header('Location: /crew-onboarding/admin.php?char_error=image_type#ai-character');
            exit;
        }
    }

    // ロゴ画像アップロード
    if (!empty($_FILES['logo_image']['tmp_name']) && (int) ($_FILES['logo_image']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $uploaded = twin_ai_character_upload_image($_FILES['logo_image'], 'logo');
        if ($uploaded !== null) {
            $data['logo_image_path'] = $uploaded;
        } else {
            header('Location: /crew-onboarding/admin.php?char_error=logo_type#ai-character');
            exit;
        }
    }

    if ($data['greeting_message'] === '') {
        header('Location: /crew-onboarding/admin.php?char_error=empty_greeting#ai-character');
        exit;
    }

    $ok = twin_ai_character_save($pdo, $data);
    header('Location: /crew-onboarding/admin.php?' . ($ok ? 'char_saved=1' : 'char_error=save') . '#ai-character');
    exit;
}

if (isset($_GET['settings_saved'])) {
    $adminNotice = '応答モードを保存しました。';
}

if (isset($_GET['settings_error'])) {
    $adminWarning = match ((string) $_GET['settings_error']) {
        'csrf' => 'CSRF トークンの確認に失敗しました。',
        'invalid_mode' => '応答モードの値が不正です。',
        default => '設定を保存できませんでした。',
    };
}

if (isset($_GET['char_saved'])) {
    $adminNotice = 'AIキャラクター設定を保存しました。';
}
if (isset($_GET['char_error'])) {
    $adminWarning = match ((string) $_GET['char_error']) {
        'csrf'          => 'CSRF トークンの確認に失敗しました。',
        'image_type'    => 'キャラクター画像はJPEG/PNG/GIF/WebP（2MB以内）を選択してください。',
        'logo_type'     => 'ロゴ画像はJPEG/PNG/GIF/WebP（2MB以内）を選択してください。',
        'empty_greeting'=> '初回あいさつ文を入力してください。',
        default         => 'AIキャラクター設定の保存に失敗しました。',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'login') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($adminCredentialsLocked) {
        twin_admin_set_login_error('公開環境の管理者認証が未設定です。ADMIN_USERNAME / ADMIN_PASSWORD_HASH を設定してください。');
        header('Location: /crew-onboarding/admin.php');
        exit;
    }

    $verifiedUser = twin_admin_verify_credentials($config, $username, $password);
    if ($verifiedUser !== null) {
        session_regenerate_id(true);
        $_SESSION['twin_admin_authenticated'] = true;
        $_SESSION['twin_admin_username']      = $verifiedUser;
        twin_admin_clear_login_error();
        error_log('[TWIN admin] login success: ' . $verifiedUser . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '-'));
        header('Location: /crew-onboarding/admin.php');
        exit;
    }

    error_log('[TWIN admin] login failed: username=' . ($username !== '' ? $username : '(empty)') . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? '-'));
    twin_admin_set_login_error('ID またはパスワードが違います。');
    header('Location: /crew-onboarding/admin.php');
    exit;
}

if (!twin_admin_is_logged_in()) {
    $loginError = twin_admin_login_error();
    twin_admin_clear_login_error();
    ?>
    <!doctype html>
    <html lang="ja">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex,nofollow">
        <title>入店前コンシェルジュ運営システム Login</title>
        <style>
            :root {
                --bg: #0f1115;
                --panel: #171a21;
                --line: #2a303b;
                --text: #edf1f7;
                --muted: #96a0ad;
                --accent: #d7b46a;
                --danger: #f2a7a7;
            }
            * { box-sizing: border-box; }
            body {
                margin: 0;
                min-height: 100vh;
                display: grid;
                place-items: center;
                background: linear-gradient(180deg, #101319 0%, #08090c 100%);
                color: var(--text);
                font-family: -apple-system, BlinkMacSystemFont, "Hiragino Sans", "Yu Gothic", Meiryo, sans-serif;
                padding: 20px;
            }
            .card {
                width: min(100%, 24rem);
                padding: 24px;
                border: 1px solid var(--line);
                border-radius: 14px;
                background: rgba(23, 26, 33, 0.96);
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35);
            }
            h1 { margin: 0 0 8px; font-size: 26px; }
            p { margin: 0 0 18px; color: var(--muted); line-height: 1.6; }
            label { display: block; font-size: 13px; margin: 14px 0 6px; color: var(--muted); }
            input {
                width: 100%;
                min-height: 44px;
                border: 1px solid var(--line);
                border-radius: 10px;
                padding: 10px 12px;
                background: #0d1014;
                color: var(--text);
                font: inherit;
            }
            button {
                width: 100%;
                min-height: 46px;
                margin-top: 18px;
                border: 0;
                border-radius: 999px;
                background: linear-gradient(135deg, #f2dc9d, #d7b46a);
                color: #171107;
                font-weight: 700;
                cursor: pointer;
            }
            .error {
                margin: 0 0 8px;
                color: var(--danger);
                font-size: 14px;
            }
            .note {
                margin-top: 14px;
                font-size: 12px;
                color: var(--muted);
            }
        </style>
    </head>
    <body>
        <main class="card">
            <h1>入店前コンシェルジュ運営システム 管理画面</h1>
            <p>管理画面に入るための簡易ログインです。</p>
            <?php if ($loginError !== ''): ?>
                <div class="error"><?= e($loginError) ?></div>
            <?php endif; ?>
            <form method="post" action="/crew-onboarding/admin.php">
                <input type="hidden" name="action" value="login">
                <label for="username">ID</label>
                <input id="username" name="username" type="text" autocomplete="username" required>
                <label for="password">パスワード</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>
                <button type="submit">ログイン</button>
            </form>
            <div class="note">本番では必ず ID / パスワード を変更してください。</div>
        </main>
    </body>
    </html>
    <?php
    exit;
}

$pdo = twin_db();
$seikaKnowledge = twin_load_seika_knowledge();
$currentResponseMode = twin_response_mode_from_config();

// WBSS接続先の現在値（DB設定 → デフォルト 'prod'）
$currentWbssEnv = 'prod';
try {
    $dbWbssEnv = twin_app_settings_fetch_many($pdo, ['wbss_env'])['wbss_env'] ?? '';
    if (in_array($dbWbssEnv, twin_wbss_allowed_envs(), true)) {
        $currentWbssEnv = $dbWbssEnv;
    }
} catch (\Throwable $e) {
    // app_settings 未適用時は prod のまま
}
$currentWbssUrl = twin_wbss_env_urls()[$currentWbssEnv];

// v0.9.2: AIキャラクター設定読み込み（store_key ごとに分離）
$adminStoreKey     = twin_current_store_key();

$adminStoreConfig  = twin_store_config($adminStoreKey);
$characterSettings = [];
$activeCharacter   = twin_ai_character_default();
try {
    $characterSettings = twin_ai_character_load_all($pdo, $adminStoreKey);
    $activeCharacter   = twin_ai_character_load_active($pdo, $adminStoreKey);
} catch (Throwable $e) {
    // migration未適用時もページ表示を継続
}
$currentOpenaiModel  = twin_normalize_openai_model_value(
    twin_app_settings_fetch_one($config['db'], 'openai_model') ?? $config['openai_model'] ?? 'gpt-4.1-mini'
) ?: 'gpt-4.1-mini';
$wbssBaseUrl = (string) WBSS_API_BASE_URL;
$wbssApiKeyStatus = trim((string) WBSS_TWIN_API_KEY) !== '' ? '設定済み' : '未設定';

$overview = [
    'sessions' => (int) $pdo->query("SELECT COUNT(*) FROM chat_sessions WHERE session_token <> 'admin-settings'")->fetchColumn(),
    'messages' => (int) $pdo->query(
        "SELECT COUNT(*)
         FROM chat_messages m
         INNER JOIN chat_sessions cs ON cs.id = m.session_id
         WHERE cs.session_token <> 'admin-settings'"
    )->fetchColumn(),
    'avg_messages' => twin_fetch_single_metric($pdo, "SELECT COALESCE(AVG(message_count), 0) FROM chat_sessions WHERE message_count IS NOT NULL AND session_token <> 'admin-settings'"),
    'avg_duration_seconds' => (int) round(twin_fetch_single_metric($pdo, "SELECT COALESCE(AVG(session_duration_seconds), 0) FROM chat_sessions WHERE session_duration_seconds IS NOT NULL AND session_token <> 'admin-settings'")),
];

$responseModes = [
    'rule' => 0,
    'openai' => 0,
    'fallback_rule' => 0,
];
$responseModeStmt = $pdo->query(
    "SELECT COALESCE(NULLIF(event_value, ''), 'fallback_rule') AS response_mode, COUNT(*) AS cnt
     FROM event_logs
     WHERE event_name = 'response_mode'
     GROUP BY COALESCE(NULLIF(event_value, ''), 'fallback_rule')"
);
foreach ($responseModeStmt->fetchAll() as $row) {
    $mode = (string) $row['response_mode'];
    if (array_key_exists($mode, $responseModes)) {
        $responseModes[$mode] = (int) $row['cnt'];
    }
}
$responseModeTotal = array_sum($responseModes);

$ctaRows = $pdo->query(
    "SELECT event_value AS cta_key,
            SUM(CASE WHEN event_name = 'cta_view' THEN 1 ELSE 0 END) AS view_count,
            SUM(CASE WHEN event_name = 'cta_click' THEN 1 ELSE 0 END) AS click_count
     FROM event_logs
     WHERE event_name IN ('cta_view', 'cta_click')
     GROUP BY event_value
     ORDER BY event_value ASC"
)->fetchAll();

$ctaMap = [
    'line'             => ['label' => 'LINE応募 通常CTA',       'view' => 0, 'click' => 0],
    'price'            => ['label' => '時給 通常CTA',            'view' => 0, 'click' => 0],
    'instagram'        => ['label' => 'Instagram 通常CTA',      'view' => 0, 'click' => 0],
    'fixed_line'       => ['label' => 'LINE応募 固定CTA',        'view' => 0, 'click' => 0],
    'fixed_price'      => ['label' => '時給 固定CTA',            'view' => 0, 'click' => 0],
    'fixed_instagram'  => ['label' => 'Instagram 固定CTA',      'view' => 0, 'click' => 0],
];
foreach ($ctaRows as $row) {
    $key = (string) ($row['cta_key'] ?? '');
    if (isset($ctaMap[$key])) {
        $ctaMap[$key]['view'] = (int) ($row['view_count'] ?? 0);
        $ctaMap[$key]['click'] = (int) ($row['click_count'] ?? 0);
    }
}

// ── 質問分析: recruit_finished（問診完了トリガー）のみ除外 ──────────────
$intentRows = $pdo->query(
    "SELECT COALESCE(NULLIF(m.intent, ''), 'other') AS intent_key, COUNT(*) AS cnt
     FROM chat_messages m
     INNER JOIN chat_sessions cs ON cs.id = m.session_id
     WHERE m.sender = 'user'
       AND cs.session_token <> 'admin-settings'
       AND (m.intent <> 'recruit_finished' OR m.intent IS NULL)
     GROUP BY COALESCE(NULLIF(m.intent, ''), 'other')
     ORDER BY cnt DESC, intent_key ASC"
)->fetchAll();
$intentAll = twin_normalize_rows($intentRows, 'intent_key', 'cnt');
$intentTop10 = array_slice($intentAll, 0, 10);

$questionRows = $pdo->query(
    "SELECT COALESCE(NULLIF(m.intent, ''), 'other') AS intent_key, COUNT(*) AS cnt
     FROM chat_messages m
     INNER JOIN chat_sessions cs ON cs.id = m.session_id
     WHERE m.sender = 'user'
       AND cs.session_token <> 'admin-settings'
       AND (m.intent <> 'recruit_finished' OR m.intent IS NULL)
     GROUP BY COALESCE(NULLIF(m.intent, ''), 'other')
     ORDER BY cnt DESC, intent_key ASC
     LIMIT 10"
)->fetchAll();

$recentRows = $pdo->query(
    "SELECT m.message, COALESCE(NULLIF(m.intent, ''), 'other') AS intent_key, m.created_at
     FROM chat_messages m
     INNER JOIN chat_sessions cs ON cs.id = m.session_id
     WHERE m.sender = 'user'
       AND cs.session_token <> 'admin-settings'
       AND (m.intent <> 'recruit_finished' OR m.intent IS NULL)
     ORDER BY m.id DESC
     LIMIT 20"
)->fetchAll();

// ── 問診回答分析: crew_applicants から集計 ─────────────────────
function twin_crew_dist(PDO $pdo, string $col, array $map): array
{
    $rows = $pdo->query(
        "SELECT `{$col}` AS val, COUNT(*) AS cnt
         FROM crew_applicants
         WHERE completed_at IS NOT NULL AND `{$col}` IS NOT NULL
         GROUP BY `{$col}`
         ORDER BY cnt DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    $result = [];
    foreach ($rows as $r) {
        $key = (string) $r['val'];
        $result[] = ['label' => $map[$key] ?? $key, 'count' => (int) $r['cnt']];
    }
    return $result;
}

$interviewStats = [
    'experience'  => twin_crew_dist($pdo, 'experience',  ['none' => '未経験', 'some' => '経験少し', 'yes' => '経験者']),
    'days'        => twin_crew_dist($pdo, 'days_per_week', ['1_2' => '週1〜2日', '3_4' => '週3〜4日', '5_plus' => '週5日以上']),
    'alcohol'     => twin_crew_dist($pdo, 'alcohol',      ['yes' => '飲める', 'some' => '少し飲める', 'no' => '飲めない']),
    'bring_trial' => twin_crew_dist($pdo, 'bring_trial',  ['yes' => 'できる', 'maybe' => 'たぶんできる', 'no' => '難しい']),
    'bring_now'   => twin_crew_dist($pdo, 'bring_now',    ['yes' => 'できる', 'some' => '少しなら', 'no' => '難しい']),
    'referrals'   => twin_crew_dist($pdo, 'referrals',    ['0' => '0組', '1_2' => '週1〜2組', '3_5' => '週3〜5組', '6_plus' => '週6組以上']),
    'grade'       => twin_crew_dist($pdo, 'priority_grade', ['A' => 'Grade A', 'B' => 'Grade B', 'C' => 'Grade C', 'D' => 'Grade D']),
];

$questionWindow = twin_question_ranking_window_config((string) ($_GET['question_window'] ?? '24h'));
$questionWindowDays = $questionWindow['days'];
$questionWindowSince = $questionWindow['since'] ?? null;
$questionWindowLabel = $questionWindow['label'];
$realQuestionRanking = twin_build_question_ranking($pdo, $questionWindowDays, $questionWindowSince);
$weeklyImprovementTop5 = twin_build_weekly_improvement_top5($realQuestionRanking);
$realQuestionRankingMax = 0;
foreach ($realQuestionRanking as $row) {
    $realQuestionRankingMax = max($realQuestionRankingMax, (int) $row['count']);
}

$maxIntentCount = 0;
foreach ($intentAll as $row) {
    $maxIntentCount = max($maxIntentCount, (int) $row['count']);
}

$maxQuestionCount = 0;
foreach ($questionRows as $row) {
    $maxQuestionCount = max($maxQuestionCount, (int) $row['cnt']);
}

$wbssRows = $pdo->query(
    "SELECT COALESCE(NULLIF(SUBSTRING_INDEX(event_value, ':', 1), ''), 'unknown') AS wbss_key,
            COUNT(*) AS cnt
     FROM event_logs
     WHERE event_name = 'wbss_api_call'
     GROUP BY COALESCE(NULLIF(SUBSTRING_INDEX(event_value, ':', 1), ''), 'unknown')
     ORDER BY cnt DESC, wbss_key ASC"
)->fetchAll();
$wbssCalls = twin_normalize_rows($wbssRows, 'wbss_key', 'cnt');
$wbssTotal = 0;
foreach ($wbssCalls as $row) {
    $wbssTotal += (int) $row['count'];
}

$wbssRecentRows = $pdo->query(
    "SELECT event_value, created_at
     FROM event_logs
     WHERE event_name = 'wbss_api_call'
     ORDER BY id DESC
     LIMIT 20"
)->fetchAll();

$castNameRows = $pdo->query(
    "SELECT event_value, created_at
     FROM event_logs
     WHERE event_name = 'cast_name_detected'
     ORDER BY id DESC
     LIMIT 20"
)->fetchAll();

$castNameTotal = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM event_logs
     WHERE event_name = 'cast_name_detected'"
)->fetchColumn();

$contextRows = $pdo->query(
    "SELECT COALESCE(NULLIF(event_value, ''), 'none') AS context_key, COUNT(*) AS cnt
     FROM event_logs
     WHERE event_name = 'conversation_context'
     GROUP BY COALESCE(NULLIF(event_value, ''), 'none')
     ORDER BY cnt DESC, context_key ASC"
)->fetchAll();
$contextMap = twin_normalize_rows($contextRows, 'context_key', 'cnt');
$contextTotal = 0;
foreach ($contextMap as $row) {
    $contextTotal += (int) $row['count'];
}

$contextRecentRows = $pdo->query(
    "SELECT event_value, created_at
     FROM event_logs
     WHERE event_name = 'conversation_context'
     ORDER BY id DESC
     LIMIT 20"
)->fetchAll();

$maxResponseModeCount = max($responseModes);

// ===== v0.6.8: other intent分析 =====

$otherMessages = $pdo->query(
    "SELECT m.session_id, m.message, m.created_at
     FROM chat_messages m
     INNER JOIN chat_sessions cs ON cs.id = m.session_id
     WHERE m.sender = 'user'
       AND (m.intent = 'other' OR m.intent IS NULL OR m.intent = '')
       AND cs.session_token <> 'admin-settings'
     ORDER BY m.id DESC
     LIMIT 50"
)->fetchAll();

// 頻出ワードランキング
$otherWordExclude = ['はい', 'うん', 'そう', 'ありがとう', 'です', 'ます', 'これ', 'それ', 'あれ', 'どれ', 'かな', 'ですか'];
$wordCounts = [];
foreach ($otherMessages as $row) {
    $text = (string) $row['message'];
    // スペース区切りと連続文字列（2文字以上）を抽出
    $parts = preg_split('/[\s　]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
    foreach ((array) $parts as $part) {
        $part = trim((string) $part);
        if (mb_strlen($part) >= 2 && !in_array($part, $otherWordExclude, true)) {
            $wordCounts[$part] = ($wordCounts[$part] ?? 0) + 1;
        }
    }
    // また文中の2文字以上の連続文字列も抽出（日本語向け）
    if (preg_match_all('/[\p{L}\p{N}]{2,}/u', $text, $matches)) {
        foreach ($matches[0] as $word) {
            $word = (string) $word;
            if (!in_array($word, $otherWordExclude, true)) {
                $wordCounts[$word] = ($wordCounts[$word] ?? 0) + 1;
            }
        }
    }
}
arsort($wordCounts);
$topWords = array_slice($wordCounts, 0, 20, true);

// 新intent候補
$suggestedIntentPatterns = [
    'anxiety'   => ['不安', '怖い', '緊張', '初キャバ', '初めてで不安'],
    'drink'     => ['飲めない', 'お酒弱い', 'ノンアル', 'ソフトドリンク'],
    'age'       => ['年齢', '何歳', '若い', '年上', '客層'],
    'payment'   => ['カード', 'クレカ', '現金', 'PayPay', '支払い'],
    'dress_code'=> ['服装', 'スーツ', '私服', 'ドレスコード'],
    'parking'   => ['駐車場', '車', '代行', 'タクシー'],
];

$suggestedIntents = [];
foreach ($suggestedIntentPatterns as $intentName => $keywords) {
    $matched = [];
    foreach ($otherMessages as $row) {
        $text = (string) $row['message'];
        foreach ($keywords as $kw) {
            if (mb_strpos($text, $kw) !== false) {
                $matched[] = $text;
                break;
            }
        }
    }
    if ($matched) {
        $suggestedIntents[$intentName] = [
            'count'    => count($matched),
            'examples' => array_slice($matched, 0, 5),
        ];
    }
}

// ===== v0.6.8: 離脱候補セッション =====

$dropoffSessions = $pdo->query(
    "SELECT cs.id AS session_id, cs.started_at,
            COUNT(m.id) AS user_msg_count,
            MAX(m.created_at) AS last_msg_at
     FROM chat_sessions cs
     INNER JOIN chat_messages m ON m.session_id = cs.id AND m.sender = 'user'
     WHERE cs.session_token <> 'admin-settings'
       AND cs.id NOT IN (
           SELECT DISTINCT session_id FROM event_logs WHERE event_name = 'cta_click'
       )
     GROUP BY cs.id, cs.started_at
     HAVING user_msg_count BETWEEN 1 AND 2
     ORDER BY cs.id DESC
     LIMIT 30"
)->fetchAll();

$dropoffDetails = [];
foreach ($dropoffSessions as $ds) {
    $sid = (int) $ds['session_id'];
    $lastMsgRow = $pdo->prepare(
        "SELECT message, COALESCE(NULLIF(intent, ''), 'other') AS intent FROM chat_messages
         WHERE session_id = ? AND sender = 'user' ORDER BY id DESC LIMIT 1"
    );
    $lastMsgRow->execute([$sid]);
    $lastMsg = $lastMsgRow->fetch();
    $dropoffDetails[] = [
        'session_id'   => $sid,
        'started_at'   => (string) $ds['started_at'],
        'user_msg_count'=> (int) $ds['user_msg_count'],
        'last_message' => $lastMsg ? (string) $lastMsg['message'] : '',
        'last_intent'  => $lastMsg ? (string) $lastMsg['intent'] : '',
    ];
}

// ===== v0.6.8: LINE誘導候補 =====

$lineIntents = ['price', 'price_estimate', 'attendance', 'cast_schedule', 'reservation', 'budget', 'business_hours'];
$lineIntentPlaceholders = implode(',', array_fill(0, count($lineIntents), '?'));

$lineOpportunitySessions = $pdo->prepare(
    "SELECT cs.id AS session_id, cs.started_at
     FROM chat_sessions cs
     INNER JOIN chat_messages m ON m.session_id = cs.id AND m.sender = 'user'
     WHERE cs.session_token <> 'admin-settings'
       AND m.intent IN ({$lineIntentPlaceholders})
       AND cs.id NOT IN (
           SELECT DISTINCT session_id FROM event_logs WHERE event_name = 'cta_click' AND event_value = 'line'
       )
     GROUP BY cs.id, cs.started_at
     ORDER BY cs.id DESC
     LIMIT 20"
);
$lineOpportunitySessions->execute($lineIntents);
$lineOpportunityRows = $lineOpportunitySessions->fetchAll();

$lineOpportunityDetails = [];
foreach ($lineOpportunityRows as $row) {
    $sid = (int) $row['session_id'];
    $intentsInSession = $pdo->prepare(
        "SELECT DISTINCT COALESCE(NULLIF(intent, ''), 'other') AS intent FROM chat_messages
         WHERE session_id = ? AND sender = 'user'"
    );
    $intentsInSession->execute([$sid]);
    $intents = array_column($intentsInSession->fetchAll(), 'intent');
    $lastUserMsg = $pdo->prepare(
        "SELECT message FROM chat_messages WHERE session_id = ? AND sender = 'user' ORDER BY id DESC LIMIT 1"
    );
    $lastUserMsg->execute([$sid]);
    $lastMsg = $lastUserMsg->fetchColumn();
    $lineOpportunityDetails[] = [
        'session_id'  => $sid,
        'started_at'  => (string) $row['started_at'],
        'intents'     => $intents,
        'last_message'=> $lastMsg !== false ? (string) $lastMsg : '',
    ];
}

// ===== v0.6.8: 返答改善候補 =====

$responseImprovements = [];

// A: 同一セッションで同じTWIN返答が連続
$dupRows = $pdo->query(
    "SELECT m1.session_id, m1.message AS twin_message, m1.created_at
     FROM chat_messages m1
     INNER JOIN chat_messages m2
       ON m2.session_id = m1.session_id
       AND m2.id > m1.id
       AND m2.sender = 'twin'
       AND m2.message = m1.message
     WHERE m1.sender = 'twin'
       AND m1.session_id IN (SELECT id FROM chat_sessions WHERE session_token <> 'admin-settings')
     GROUP BY m1.session_id, m1.message, m1.created_at
     ORDER BY m1.session_id DESC, m1.created_at DESC
     LIMIT 20"
)->fetchAll();
foreach ($dupRows as $row) {
    $responseImprovements[] = [
        'session_id'   => (int) $row['session_id'],
        'type'         => 'A',
        'type_label'   => '同じTWIN返答が繰り返されています',
        'message'      => (string) $row['twin_message'],
        'note'         => '短期記憶またはcontext分岐の改善が必要です',
    ];
}

// B: ユーザーが「はい」と答えた後に同じ返答が続く
$haiRows = $pdo->query(
    "SELECT m1.session_id, m1.message AS user_msg, m2.message AS twin_reply1, m2.created_at
     FROM chat_messages m1
     INNER JOIN chat_messages m2 ON m2.session_id = m1.session_id AND m2.id = (
         SELECT MIN(id) FROM chat_messages WHERE session_id = m1.session_id AND id > m1.id AND sender = 'twin'
     )
     INNER JOIN chat_messages m3 ON m3.id = (
         SELECT MIN(id) FROM chat_messages WHERE session_id = m1.session_id AND id > m2.id AND sender = 'twin'
     )
     WHERE m1.sender = 'user' AND m1.message IN ('はい', 'うん', 'そう', 'はい！', 'はい。')
       AND m2.message = m3.message
       AND m1.session_id IN (SELECT id FROM chat_sessions WHERE session_token <> 'admin-settings')
     GROUP BY m1.session_id, m1.message, m2.message, m2.created_at
     ORDER BY m1.session_id DESC
     LIMIT 10"
)->fetchAll();
foreach ($haiRows as $row) {
    $responseImprovements[] = [
        'session_id'   => (int) $row['session_id'],
        'type'         => 'B',
        'type_label'   => '「はい」の後に同じ返答が繰り返されています',
        'message'      => (string) $row['twin_reply1'],
        'note'         => '肯定の返答後の分岐ロジックを見直してください',
    ];
}

// C: other intent が連続している（3回以上）
$otherConsecutiveRows = $pdo->query(
    "SELECT session_id, COUNT(*) AS other_count
     FROM chat_messages
     WHERE sender = 'user'
       AND (intent = 'other' OR intent IS NULL OR intent = '')
       AND session_id IN (SELECT id FROM chat_sessions WHERE session_token <> 'admin-settings')
     GROUP BY session_id
     HAVING other_count >= 3
     ORDER BY other_count DESC
     LIMIT 15"
)->fetchAll();
foreach ($otherConsecutiveRows as $row) {
    $responseImprovements[] = [
        'session_id'   => (int) $row['session_id'],
        'type'         => 'C',
        'type_label'   => 'other intent が ' . $row['other_count'] . ' 回連続しています',
        'message'      => '',
        'note'         => 'intent未検出の発言が続いており、intent追加または返答改善が必要です',
    ];
}

// ===== v0.6.9: KPI計算 =====

// 全ユーザー発言数（admin-settings除外済み）
$totalUserMessages = (int) $pdo->query(
    "SELECT COUNT(*) FROM chat_messages m
     INNER JOIN chat_sessions cs ON cs.id = m.session_id
     WHERE m.sender = 'user' AND cs.session_token <> 'admin-settings'"
)->fetchColumn();

// LINE CTAクリック数
$lineClickCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM event_logs WHERE event_name = 'cta_click' AND event_value = 'line'"
)->fetchColumn();

// LINE CTR = LINEクリック数 / 総セッション数 × 100
$lineCtr = $overview['sessions'] > 0 ? round(($lineClickCount / $overview['sessions']) * 100, 1) : 0.0;

// v0.8.7: LINE CTA表示数 (line_cta_shown イベント) と CTA表示率
$lineCtaShownCount = (int) $pdo->query(
    "SELECT COUNT(DISTINCT session_id) FROM event_logs WHERE event_name = 'line_cta_shown'"
)->fetchColumn();
$lineCtaShownRate = $overview['sessions'] > 0 ? round(($lineCtaShownCount / $overview['sessions']) * 100, 1) : 0.0;

// other件数
$otherCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM chat_messages m
     INNER JOIN chat_sessions cs ON cs.id = m.session_id
     WHERE m.sender = 'user'
       AND (m.intent = 'other' OR m.intent IS NULL OR m.intent = '')
       AND cs.session_token <> 'admin-settings'"
)->fetchColumn();

// other率（全期間）
$otherRate = $totalUserMessages > 0 ? round(($otherCount / $totalUserMessages) * 100, 1) : 0.0;

// v0.8.6: other率（直近24時間）— 古いログに引っ張られないよう期間ベースの値も算出
$totalUserMessages24h = (int) $pdo->query(
    "SELECT COUNT(*) FROM chat_messages m
     INNER JOIN chat_sessions cs ON cs.id = m.session_id
     WHERE m.sender = 'user' AND cs.session_token <> 'admin-settings'
       AND m.created_at >= NOW() - INTERVAL 24 HOUR"
)->fetchColumn();
$otherCount24h = (int) $pdo->query(
    "SELECT COUNT(*) FROM chat_messages m
     INNER JOIN chat_sessions cs ON cs.id = m.session_id
     WHERE m.sender = 'user'
       AND (m.intent = 'other' OR m.intent IS NULL OR m.intent = '')
       AND cs.session_token <> 'admin-settings'
       AND m.created_at >= NOW() - INTERVAL 24 HOUR"
)->fetchColumn();
$otherRate24h = $totalUserMessages24h > 0 ? round(($otherCount24h / $totalUserMessages24h) * 100, 1) : 0.0;
$hasRecent24hMessages = $totalUserMessages24h > 0;
// 改善提案・スコアの基準は直近24hにデータがあればそれを優先
$otherRatePrimary = $hasRecent24hMessages ? $otherRate24h : $otherRate;

// 出勤質問率（attendance + cast_schedule）
$attendanceCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM chat_messages m
     INNER JOIN chat_sessions cs ON cs.id = m.session_id
     WHERE m.sender = 'user'
       AND m.intent IN ('attendance', 'cast_schedule')
       AND cs.session_token <> 'admin-settings'"
)->fetchColumn();
$attendanceRate = $totalUserMessages > 0 ? round(($attendanceCount / $totalUserMessages) * 100, 1) : 0.0;

// 料金質問率
$priceCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM chat_messages m
     INNER JOIN chat_sessions cs ON cs.id = m.session_id
     WHERE m.sender = 'user'
       AND m.intent IN ('price', 'price_estimate')
       AND cs.session_token <> 'admin-settings'"
)->fetchColumn();
$priceRate = $totalUserMessages > 0 ? round(($priceCount / $totalUserMessages) * 100, 1) : 0.0;

// ===== v0.6.9: 採用導線診断スコア (v0.8.7: システム品質スコアに再定義) =====
$intentCoverScore = (int) max(0, floor(100 - $otherRate));

// v0.8.7: LINE CTA表示率スコア（CTRではなく表示率で評価。WBSS確定前の暫定値）
$lineCtaScore = $lineCtaShownRate >= 60 ? 100 : ($lineCtaShownRate >= 40 ? 80 : ($lineCtaShownRate >= 20 ? 60 : ($lineCtaShownRate > 0 ? 40 : 20)));

$improvementCount = count($responseImprovements);
$naturalScore = $improvementCount === 0 ? 100 : ($improvementCount <= 3 ? 80 : ($improvementCount <= 10 ? 60 : 40));

// v0.8.7: 重み付き合成スコア
// intent精度35% + 会話自然度25% + LINE CTA表示率10% + 出勤連携25% + OpenAI5%
// ※出勤連携・OpenAIはWBSS集計後に確定。暫定値はintent/自然度/CTA表示率の3指標のみ
$healthScoreBase = (int) round(
    $intentCoverScore * 0.35
    + $naturalScore * 0.25
    + $lineCtaScore * 0.10
);

// other率の色クラス（直近24h優先）
$otherRateClass = $otherRatePrimary < 10 ? 'kpi-good' : ($otherRatePrimary < 20 ? 'kpi-warning' : 'kpi-danger');

// KPI: 問診完了率・LINEタップ率・グレード別LINE率
$applicantTotal   = (int) $pdo->query("SELECT COUNT(*) FROM crew_applicants WHERE completed_at IS NOT NULL")->fetchColumn();
$lineAppliedTotal = (int) $pdo->query("SELECT COUNT(*) FROM crew_applicants WHERE line_applied_at IS NOT NULL")->fetchColumn();
$lineAppliedRate  = $applicantTotal > 0 ? round($lineAppliedTotal / $applicantTotal * 100, 1) : 0.0;

$gradeLineRows = $pdo->query(
    "SELECT priority_grade,
            COUNT(*) AS total,
            SUM(line_applied_at IS NOT NULL) AS lined
     FROM crew_applicants
     WHERE completed_at IS NOT NULL AND priority_grade IS NOT NULL
     GROUP BY priority_grade
     ORDER BY priority_grade ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// 改善提案用: 採用フロー状況
$recruitSessionCount = (int) $pdo->query(
    "SELECT COUNT(DISTINCT m.session_id)
     FROM chat_messages m
     INNER JOIN chat_sessions cs ON cs.id = m.session_id
     WHERE m.intent LIKE 'recruit_%'
       AND cs.session_token <> 'admin-settings'"
)->fetchColumn();
$pendingInterview = (int) $pdo->query(
    "SELECT COUNT(*) FROM crew_applicants
     WHERE line_applied_at IS NOT NULL AND interview_at IS NULL AND hired_at IS NULL AND rejected_at IS NULL"
)->fetchColumn();
$pendingDecision = (int) $pdo->query(
    "SELECT COUNT(*) FROM crew_applicants
     WHERE interview_at IS NOT NULL AND hired_at IS NULL AND rejected_at IS NULL"
)->fetchColumn();
$pendingWbss = (int) $pdo->query(
    "SELECT COUNT(*) FROM crew_applicants
     WHERE hired_at IS NOT NULL AND hired_employee_id IS NULL"
)->fetchColumn();

// 改善提案用: 経験・出勤・呼客の分布（簡易版）
$_expDist = $pdo->query(
    "SELECT experience AS val, COUNT(*) AS cnt FROM crew_applicants
     WHERE completed_at IS NOT NULL AND experience IS NOT NULL GROUP BY experience"
)->fetchAll(PDO::FETCH_ASSOC);
$experienceDistForSuggestion = [];
foreach ($_expDist as $_r) { $experienceDistForSuggestion[(string) $_r['val']] = (int) $_r['cnt']; }

$_bringDist = $pdo->query(
    "SELECT bring_trial AS val, COUNT(*) AS cnt FROM crew_applicants
     WHERE completed_at IS NOT NULL AND bring_trial IS NOT NULL GROUP BY bring_trial"
)->fetchAll(PDO::FETCH_ASSOC);
$bringTrialDistForSuggestion = [];
foreach ($_bringDist as $_r) { $bringTrialDistForSuggestion[(string) $_r['val']] = (int) $_r['cnt']; }

$_daysDist = $pdo->query(
    "SELECT days_per_week AS val, COUNT(*) AS cnt FROM crew_applicants
     WHERE completed_at IS NOT NULL AND days_per_week IS NOT NULL GROUP BY days_per_week"
)->fetchAll(PDO::FETCH_ASSOC);
$daysDistForSuggestion = [];
foreach ($_daysDist as $_r) { $daysDistForSuggestion[(string) $_r['val']] = (int) $_r['cnt']; }

// ===== 改善提案（採用コンシェルジュ向け） =====
$improvements = [];
$periodLabel = $hasRecent24hMessages ? '（直近24h）' : '（全期間）';

// 問診未分類率（other率）
if ($otherRatePrimary >= 20) {
    $improvements[] = ['priority' => 1, 'text' => '未分類率が' . $otherRatePrimary . '%' . $periodLabel . ' → 問診外の自由入力への対応を追加'];
}

// LINE応募タップ率
$lineAppliedRateForKpi = $applicantTotal > 0 ? round($lineAppliedTotal / $applicantTotal * 100, 1) : 0.0;
if ($lineAppliedRateForKpi < 20) {
    $improvements[] = ['priority' => 2, 'text' => 'LINE応募タップ率が' . $lineAppliedRateForKpi . '% → 完了メッセージのLINE誘導文を強化'];
}

// 問診完了数が少ない
if ($applicantTotal < 5) {
    $improvements[] = ['priority' => 2, 'text' => '問診完了数が' . $applicantTotal . '件 → 途中離脱の原因を直近質問ログで確認'];
}

// 返答改善候補
if ($improvementCount > 3) {
    $improvements[] = ['priority' => 3, 'text' => '返答改善候補が' . $improvementCount . '件 → 問診中の不自然な返答を確認'];
}

// GradeA/BのLINEタップ率を個別チェック
$gradeABTotal = 0;
$gradeABLined = 0;
foreach ($gradeLineRows as $gr) {
    if (in_array($gr['priority_grade'], ['A', 'B'], true)) {
        $gradeABTotal += (int) $gr['total'];
        $gradeABLined += (int) $gr['lined'];
    }
}
if ($gradeABTotal >= 3 && ($gradeABTotal > 0 && ($gradeABLined / $gradeABTotal) < 0.5)) {
    $abRate = round($gradeABLined / $gradeABTotal * 100, 1);
    $improvements[] = ['priority' => 2, 'text' => 'A/BグレードのLINEタップ率が' . $abRate . '% → 優良応募者の離脱防止を優先'];
}

usort($improvements, fn($a, $b) => $a['priority'] - $b['priority']);
$top3Improvements = array_slice($improvements, 0, 3);

// ===== v0.7: 応募者評価 =====
$recommendCastCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM event_logs WHERE event_name IN ('recommend_cast_executed', 'recommend_cast_prompted')"
)->fetchColumn();

// 推薦後にLINEクリックしたセッション数
$recommendCastLineClickCount = (int) $pdo->query(
    "SELECT COUNT(DISTINCT e2.session_id)
     FROM event_logs e1
     JOIN event_logs e2 ON e2.session_id = e1.session_id
       AND e2.event_name = 'cta_click'
       AND e2.event_value = 'line'
       AND e2.created_at > e1.created_at
     WHERE e1.event_name = 'recommend_cast_executed'"
)->fetchColumn();

$recommendCastCtr = $recommendCastCount > 0
    ? round($recommendCastLineClickCount / $recommendCastCount * 100, 1)
    : 0.0;

// ===== v0.6.9: LINEクリック前intent分析 =====
$linePreClickIntents = $pdo->query(
    "SELECT m.intent, COUNT(*) as cnt
     FROM event_logs e
     JOIN chat_messages m ON m.session_id = e.session_id
       AND m.sender = 'user'
       AND m.created_at = (
         SELECT MAX(m2.created_at) FROM chat_messages m2
         WHERE m2.session_id = e.session_id
           AND m2.sender = 'user'
           AND m2.created_at <= e.created_at
       )
     WHERE e.event_name = 'cta_click'
       AND e.event_value = 'line'
     GROUP BY m.intent
     ORDER BY cnt DESC
     LIMIT 10"
)->fetchAll();

// ===== v0.7.1: AI利用額集計 =====
$aiUsage = ['today_jpy' => 0, 'month_jpy' => 0, 'total_jpy' => 0, 'total_tokens' => 0, 'total_calls' => 0, 'avg_cost_jpy' => 0, 'by_model' => []];
try {
    $aiTodayRow  = $pdo->query("SELECT COALESCE(SUM(estimated_cost_jpy),0) as cost, COALESCE(SUM(total_tokens),0) as tokens FROM ai_usage_logs WHERE DATE(created_at) = CURDATE()")->fetch();
    $aiMonthRow  = $pdo->query("SELECT COALESCE(SUM(estimated_cost_jpy),0) as cost FROM ai_usage_logs WHERE DATE_FORMAT(created_at,'%Y-%m') = DATE_FORMAT(NOW(),'%Y-%m')")->fetch();
    $aiTotalRow  = $pdo->query("SELECT COALESCE(SUM(estimated_cost_jpy),0) as cost, COALESCE(SUM(total_tokens),0) as tokens, COUNT(*) as calls FROM ai_usage_logs")->fetch();
    $aiModels    = $pdo->query("SELECT model, COUNT(*) as calls, COALESCE(SUM(total_tokens),0) as tokens, COALESCE(SUM(estimated_cost_jpy),0) as cost FROM ai_usage_logs GROUP BY model ORDER BY cost DESC")->fetchAll();
    $aiUsage = [
        'today_jpy'   => (float)($aiTodayRow['cost'] ?? 0),
        'month_jpy'   => (float)($aiMonthRow['cost'] ?? 0),
        'total_jpy'   => (float)($aiTotalRow['cost'] ?? 0),
        'total_tokens'=> (int)($aiTotalRow['tokens'] ?? 0),
        'total_calls' => (int)($aiTotalRow['calls'] ?? 0),
        'avg_cost_jpy'=> (int)($aiTotalRow['calls'] ?? 0) > 0 ? (float)($aiTotalRow['cost'] ?? 0) / (int)($aiTotalRow['calls']) : 0,
        'by_model'    => $aiModels,
    ];
} catch (\Throwable $e) {
    // テーブル未作成の場合は0表示
}

// v0.7.3: ai_usage_logs 件数・直近5件
$aiUsageCount = 0;
$aiUsageRecent = [];
try {
    $aiUsageCount = (int)$pdo->query("SELECT COUNT(*) FROM ai_usage_logs")->fetchColumn();
    $aiUsageRecent = $pdo->query(
        "SELECT model, prompt_tokens, completion_tokens, total_tokens, estimated_cost_jpy, created_at
         FROM ai_usage_logs ORDER BY created_at DESC LIMIT 5"
    )->fetchAll();
} catch (\Throwable $e) {}

// v0.8.1: OpenAI診断（health score は WBSS集計後に計算するため後続に移動）
$openaiDiagnostics = twin_build_openai_diagnostics($pdo);

// ===== v0.6.10: 公開前チェックリスト =====
$wbssCheckUrl = trim((string) ($config['wbss_api_base_url'] ?? ''));
$wbssKey      = trim((string) ($config['wbss_twin_api_key'] ?? ''));
$wbssOk = ($wbssCheckUrl !== '' && $wbssKey !== '');
if (!$wbssOk) {
    try {
        $wbssSuccessCount = (int) $pdo->query(
            "SELECT COUNT(*) FROM event_logs WHERE event_name = 'wbss_api_call'
             AND (event_value LIKE '%attendance_success%' OR event_value LIKE '%cast_schedule_success%')
             AND created_at >= NOW() - INTERVAL 7 DAY"
        )->fetchColumn();
        $wbssOk = $wbssSuccessCount > 0;
    } catch (\Throwable $e) {}
}
// ===== v0.7.2: AI利用額 usage未記録チェック =====
$openaiSessionCount = 0;
try {
    $openaiSessionCount = (int) $pdo->query(
        "SELECT COUNT(DISTINCT session_id) FROM event_logs WHERE event_name = 'response_mode' AND event_value = 'openai'"
    )->fetchColumn();
} catch (\Throwable $e) {}
$hasUnrecordedUsage = $openaiSessionCount > 0 && $aiUsage['total_calls'] === 0;

$wbssSuccessCount = 0;
$wbssErrorCount = 0;
$wbssNotFoundCount = 0;
foreach ($wbssCalls as $row) {
    $key = (string) ($row['key'] ?? '');
    $count = (int) ($row['count'] ?? 0);
    if (in_array($key, ['attendance_success', 'cast_schedule_success', 'attendance_empty', 'cast_schedule_not_working'], true)) {
        $wbssSuccessCount += $count;
    } elseif (in_array($key, ['attendance_error', 'cast_schedule_error'], true)) {
        $wbssErrorCount += $count;
    } elseif ($key === 'cast_schedule_not_found') {
        $wbssNotFoundCount += $count;
    }
}
// cast_schedule_not_found は API障害とは別扱い（分母に含めない）
$wbssScoreTotal = $wbssSuccessCount + $wbssErrorCount;

// v0.8.6: 直近24時間のWBSSエラー数（初期設定・古い開発エラーを除外して現状把握）
$wbssErrorCount24h = 0;
try {
    $wbssErrorCount24h = (int) $pdo->query(
        "SELECT COUNT(*) FROM event_logs
         WHERE event_name = 'wbss_api_call'
           AND (event_value LIKE 'attendance_error%' OR event_value LIKE 'cast_schedule_error%')
           AND created_at >= NOW() - INTERVAL 24 HOUR"
    )->fetchColumn();
} catch (\Throwable $e) {}
$wbssErrorCount7d = 0;
try {
    $wbssErrorCount7d = (int) $pdo->query(
        "SELECT COUNT(*) FROM event_logs
         WHERE event_name = 'wbss_api_call'
           AND (event_value LIKE 'attendance_error%' OR event_value LIKE 'cast_schedule_error%')
           AND created_at >= NOW() - INTERVAL 7 DAY"
    )->fetchColumn();
} catch (\Throwable $e) {}

$wbssRecentErrorCount = 0;
foreach ($wbssRecentRows as $row) {
    $value = (string) ($row['event_value'] ?? '');
    $statusKey = strpos($value, ':') !== false ? explode(':', $value, 2)[0] : $value;
    if (in_array($statusKey, ['attendance_error', 'cast_schedule_error', 'attendance_empty', 'cast_schedule_not_found', 'cast_schedule_not_working'], true)) {
        $wbssRecentErrorCount++;
    }
}

$wbssResponseTimes = [];
try {
    $wbssTimingRows = $pdo->query(
        "SELECT event_value FROM event_logs
         WHERE event_name = 'wbss_api_call'
           AND event_value LIKE '%ms'"
    )->fetchAll();
    foreach ($wbssTimingRows as $row) {
        $value = (string) ($row['event_value'] ?? '');
        if (preg_match('/:(\d+)ms$/', $value, $match)) {
            $wbssResponseTimes[] = (int) $match[1];
        }
    }
} catch (\Throwable $e) {}
$wbssAvgResponseMs = $wbssResponseTimes ? (int) round(array_sum($wbssResponseTimes) / count($wbssResponseTimes)) : 0;
// 成功率: cast_schedule_not_found を分母に含めない
$wbssSuccessRate = $wbssScoreTotal > 0 ? round(($wbssSuccessCount / $wbssScoreTotal) * 100, 1) : 0.0;
$wbssStatusLabel = $wbssOk ? '接続正常' : '要確認';
$wbssStatusClass = $wbssOk ? 'kpi-good' : 'kpi-danger';

// v0.8.6: WBSS 改善提案は直近24時間のエラーのみで判定（初期設定・古い開発エラーを除外）
if ($wbssErrorCount24h > 0) {
    $improvements[] = ['priority' => 4, 'text' => '出勤連携エラーが直近24hで' . $wbssErrorCount24h . '件 → APIキー・URL・タイムアウト確認'];
}
if ($wbssNotFoundCount >= 5) {
    $improvements[] = ['priority' => 5, 'text' => 'キャスト名未一致が' . $wbssNotFoundCount . '件 → キャスト名の表記ゆれ・抽出ロジック改善'];
}
usort($improvements, fn($a, $b) => $a['priority'] - $b['priority']);
$top3Improvements = array_slice($improvements, 0, 3);

// v0.8.4: WBSS集計後に health score を計算（wbssSuccessRate が確定してから渡す）
// v0.8.7: line_cta_shown_rate を追加
$healthScoreBreakdown = twin_build_health_score_breakdown([
    'other_rate' => $otherRate,
    'line_ctr' => $lineCtr,
    'line_cta_shown_rate' => $lineCtaShownRate,
    'response_improvements_count' => $improvementCount,
    'wbss_success_rate' => $wbssSuccessRate,
    'wbss_score_total' => $wbssScoreTotal,
    'wbss_success_count' => $wbssSuccessCount,
    'wbss_error_count' => $wbssErrorCount,
    'wbss_not_found_count' => $wbssNotFoundCount,
    'price_rate' => $priceRate,
], $openaiDiagnostics);

// v0.8.7: WBSS・OpenAI確定後に総合スコアを重み付き合成
// intent精度35% + 出勤連携25% + 会話自然度25% + LINE CTA表示率10% + OpenAI5%
$attendanceScoreFinal = 0;
$openaiScoreFinal = 0;
foreach ($healthScoreBreakdown as $_card) {
    if ($_card['key'] === 'attendance_accuracy') {
        $attendanceScoreFinal = (int) $_card['score'] >= 0 ? (int) $_card['score'] : 75; // 未計測は75点扱い
    }
    if ($_card['key'] === 'openai_connection') {
        $openaiScoreFinal = (int) $_card['score'];
    }
}
$healthScore = (int) round(
    $intentCoverScore * 0.35
    + $attendanceScoreFinal * 0.25
    + $naturalScore * 0.25
    + $lineCtaScore * 0.10
    + $openaiScoreFinal * 0.05
);

$priceEstimateCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM chat_messages m
     INNER JOIN chat_sessions cs ON cs.id = m.session_id
     WHERE m.sender = 'user'
       AND m.intent = 'price_estimate'
       AND cs.session_token <> 'admin-settings'"
)->fetchColumn();

$castNotFoundCount = $wbssNotFoundCount;

// v0.8.6: Intent精度 TOP課題の集計
$castScheduleCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM chat_messages m
     INNER JOIN chat_sessions cs ON cs.id = m.session_id
     WHERE m.sender = 'user' AND m.intent = 'cast_schedule'
       AND cs.session_token <> 'admin-settings'"
)->fetchColumn();
$castScheduleNotFoundRate = $castScheduleCount > 0
    ? round(($wbssNotFoundCount / $castScheduleCount) * 100, 1)
    : 0.0;
// v0.8.6: other は直近24hにデータがあれば24h値を優先表示
$otherIssueCount = $hasRecent24hMessages ? $otherCount24h : $otherCount;
$otherIssueTotal = $hasRecent24hMessages ? $totalUserMessages24h : $totalUserMessages;
$otherIssueLabel = $hasRecent24hMessages ? 'other（未分類・直近24h）' : 'other（未分類・全期間）';
$intentAccuracyIssues = [
    ['label' => 'cast_schedule 未一致', 'count' => $wbssNotFoundCount, 'total' => $castScheduleCount, 'rate' => $castScheduleNotFoundRate, 'hint' => 'キャスト名表記ゆれ・禁止語追加'],
    ['label' => 'price_estimate 件数', 'count' => $priceEstimateCount, 'total' => $totalUserMessages, 'rate' => $totalUserMessages > 0 ? round($priceEstimateCount / $totalUserMessages * 100, 1) : 0.0, 'hint' => '料金概算トリガーの精度確認'],
    ['label' => $otherIssueLabel, 'count' => $otherIssueCount, 'total' => $otherIssueTotal, 'rate' => $otherRatePrimary, 'hint' => 'intent追加・キーワード拡充'],
];

// v0.8.5: キャスト名未一致ランキング（cast_name_detected ログから cast= 部分を抽出）
$castNotFoundRanking = [];
try {
    $castDetectedRows = $pdo->query(
        "SELECT el.event_value AS detected_value,
                (SELECT m.message FROM chat_messages m
                 WHERE m.session_id = el.session_id AND m.sender = 'user'
                   AND m.created_at <= el.created_at
                 ORDER BY m.created_at DESC LIMIT 1) AS raw_message
         FROM event_logs el
         WHERE el.event_name = 'cast_name_detected'
           AND EXISTS (
               SELECT 1 FROM event_logs el2
               WHERE el2.session_id = el.session_id
                 AND el2.event_name = 'wbss_api_call'
                 AND el2.event_value LIKE 'cast_schedule_not_found%'
                 AND ABS(TIMESTAMPDIFF(SECOND, el2.created_at, el.created_at)) < 10
           )
         ORDER BY el.id DESC
         LIMIT 200"
    )->fetchAll();

    $castNotFoundNames = [];
    foreach ($castDetectedRows as $row) {
        $detectedValue = (string) ($row['detected_value'] ?? '');
        $rawMsg = (string) ($row['raw_message'] ?? '');
        // cast=名前,raw=... 形式から名前を取り出す
        if (str_starts_with($detectedValue, 'cast=')) {
            $castPart = substr($detectedValue, 5);
            $name = (string) explode(',', $castPart, 2)[0];
        } else {
            $name = $detectedValue;
        }
        if ($name === '') {
            continue;
        }
        if (!isset($castNotFoundNames[$name])) {
            $castNotFoundNames[$name] = ['count' => 0, 'samples' => []];
        }
        $castNotFoundNames[$name]['count']++;
        if (count($castNotFoundNames[$name]['samples']) < 3 && $rawMsg !== '') {
            $castNotFoundNames[$name]['samples'][] = $rawMsg;
        }
    }
    arsort($castNotFoundNames);
    $aliases = require CREW_PRIVATE_ROOT . '/app/knowledge/cast_aliases.php';
    // 除外語パターン（キャスト名ではないことが明らかな語）
    $excludeWordList = [
        'VIP', 'vip', 'ドリンク', '料金', '金額', '何人', 'セット', '初めて', '一人',
        '緊張', '不安', 'いくら', '岡山', 'キャバクラ', 'キャバ', 'お店', '指名',
        'ボトル', 'フリー', '女の子', 'お姉さん', '店内', 'フロア', '入口', '雰囲気',
    ];
    foreach ($castNotFoundNames as $name => $data) {
        $nameLen = mb_strlen($name, 'UTF-8');
        $aliasTarget = $aliases[$name] ?? null;
        // 明らかに質問文全体 or 長文 or 数字混じり → 誤抽出
        $isTooLong = $nameLen >= 8;
        $hasDigits = (bool) preg_match('/[0-9０-９]/u', $name);
        // 質問文パターン（疑問符・動詞末尾・複数文節）
        $isQuestion = (bool) preg_match('/[？?。いくら何人セット使った場合ありますいませ]/u', $name);
        $probablyNotName = $isTooLong || $hasDigits || $isQuestion || ($nameLen >= 6 && (bool) preg_match('/[ぁ-ん]{3,}/u', $name));
        $isExcludeWord = in_array($name, $excludeWordList, true)
            || (bool) preg_match('/^(?:VIP|vip|ドリンク|料金|金額|セット|いくら|岡山|キャバ|お店|ボトル|フリー|女の子)/u', $name);

        // 分類: 誤抽出 > 除外語 > alias登録済み > alias候補 > 保留
        if ($probablyNotName && !$isExcludeWord) {
            $category = 'misextract';
            $reason = '誤抽出候補（質問文・長文・数字混じり）';
            $action = '除外語に追加するか抽出ロジックを確認';
            $registrationHint = 'excludeWords または無視';
        } elseif ($isExcludeWord) {
            $category = 'exclude';
            $reason = '除外語候補（キャスト名ではない語）';
            $action = 'excludeWords に追加';
            $registrationHint = 'app/knowledge/seika.php の excludeWords';
        } elseif ($aliasTarget !== null) {
            $category = 'alias_registered';
            $reason = 'alias登録済み → ' . $aliasTarget . ' に変換';
            $action = 'alias適用済み（WBSSで一致しなければキャスト名を確認）';
            $registrationHint = '対応済み';
        } elseif (!$probablyNotName && $nameLen >= 2) {
            $category = 'alias';
            $reason = 'alias登録候補（人名らしい表記）';
            $action = 'cast_aliases.php にキャスト名の正規名を登録';
            $registrationHint = 'app/knowledge/cast_aliases.php';
        } else {
            $category = 'pending';
            $reason = '保留（判断できない）';
            $action = '手動で確認してください';
            $registrationHint = '要確認';
        }

        $castNotFoundRanking[] = [
            'name'              => $name,
            'count'             => $data['count'],
            'samples'           => $data['samples'],
            'reason'            => $reason,
            'action'            => $action,
            'registration_hint' => $registrationHint,
            'category'          => $category,
            'is_alias_candidate'  => $category === 'alias',
            'is_exclude_candidate' => $category === 'exclude',
        ];
    }
} catch (\Throwable $e) {
    error_log('[TWIN cast_not_found_ranking] error: ' . $e->getMessage());
}

$linePreIntentTotal = 0;
foreach ($linePreClickIntents as $row) {
    $linePreIntentTotal += (int) ($row['cnt'] ?? 0);
}

$replyImprovementSummary = twin_build_reply_improvement_summary($responseImprovements);
$improvementSuggestions = twin_build_improvement_suggestions([
    // 採用フロー
    'recruit_session_count'   => $recruitSessionCount,
    'applicant_total'         => $applicantTotal,
    'line_applied_total'      => $lineAppliedTotal,
    'grade_line_rows'         => $gradeLineRows,
    'experience_dist'         => $experienceDistForSuggestion,
    'bring_trial_dist'        => $bringTrialDistForSuggestion,
    'days_dist'               => $daysDistForSuggestion,
    'pending_interview'       => $pendingInterview,
    'pending_decision'        => $pendingDecision,
    'pending_wbss'            => $pendingWbss,
    // システム品質
    'other_rate'              => $otherRate,
    'response_improvements_count' => $improvementCount,
    'ai_usage_count'          => $aiUsageCount,
    'has_unrecorded_usage'    => $hasUnrecordedUsage,
    'openai_diagnostics'      => $openaiDiagnostics,
    'line_pre_intent_rows'    => $linePreClickIntents,
    'line_pre_intent_total'   => $linePreIntentTotal,
]);

$operationsSummary = [
    'health_score' => $healthScore,
    'health_class' => $healthScore >= 70 ? 'kpi-good' : ($healthScore >= 50 ? 'kpi-warning' : 'kpi-danger'),
    'line_ctr' => $lineCtr,
    'line_ctr_class' => $lineCtr >= 10 ? 'kpi-good' : ($lineCtr >= 5 ? 'kpi-warning' : 'kpi-danger'),
    'other_rate' => $otherRatePrimary,
    'other_rate_all' => $otherRate,
    'other_rate_is_24h' => $hasRecent24hMessages,
    'other_rate_class' => $otherRatePrimary < 10 ? 'kpi-good' : ($otherRatePrimary < 20 ? 'kpi-warning' : 'kpi-danger'),
    'ai_today' => $aiUsage['today_jpy'],
    'ai_month' => $aiUsage['month_jpy'],
    'ai_total' => $aiUsage['total_jpy'],
    'ai_usage_count' => $aiUsageCount,
    'has_unrecorded_usage' => $hasUnrecordedUsage,
    'wbss_status_label' => $wbssStatusLabel,
    'wbss_status_class' => $wbssStatusClass,
    'wbss_success_rate' => $wbssSuccessRate,
    'wbss_avg_response_ms' => $wbssAvgResponseMs,
    'wbss_recent_error_count' => $wbssRecentErrorCount,
    'response_mode_label' => twin_response_mode_label($currentResponseMode),
    'openai_diagnostics' => $openaiDiagnostics,
    'health_breakdown' => $healthScoreBreakdown,
];

// ===== 応募者一覧 =====
$applicantRows = $pdo->query(
    "SELECT
        a.id, a.session_id, a.experience, a.estimated_wage,
        a.candidate_score, a.priority_grade,
        a.completed_at, a.line_applied_at,
        a.hired_at, a.outcome_sales_rank, a.outcome_retention,
        a.created_at
     FROM crew_applicants a
     ORDER BY a.created_at DESC
     LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);

// 3か月後結果 未入力アラート件数
$outcomeAlertCount = (int) $pdo->query(
    "SELECT COUNT(*) FROM crew_applicants
     WHERE hired_at IS NOT NULL
       AND hired_at <= NOW() - INTERVAL 90 DAY
       AND (outcome_sales_rank IS NULL OR outcome_sales_rank = ''
            OR outcome_retention IS NULL OR outcome_retention = '')"
)->fetchColumn();

$checklist = [
    ['label' => '管理画面 noindex 設定', 'status' => true, 'auto' => true],
    ['label' => '管理画面ログイン設定', 'status' => !empty($config['admin_username']), 'auto' => true],
    ['label' => '出勤連携設定', 'status' => $wbssOk, 'auto' => true],
    ['label' => 'AI接続設定', 'status' => !empty($config['openai_api_key']), 'auto' => true],
    ['label' => '店舗ナレッジ確認', 'status' => null, 'auto' => false],
    ['label' => 'LINE URL確認', 'status' => null, 'auto' => false],
    ['label' => '時給・待遇確認', 'status' => null, 'auto' => false],
    ['label' => '出勤API確認', 'status' => null, 'auto' => false],
];
?>

<!doctype html>
<html lang="ja">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>入店前コンシェルジュ運営システム</title>
    <!-- テーマ即時適用: body描画前に data-theme を設定してFOUCを防ぐ -->
    <script>
    (function(){
        var t = localStorage.getItem('twin_admin_theme') || 'auto';
        var d = t === 'auto' ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : t;
        document.documentElement.setAttribute('data-theme', d);
    })();
    </script>
    <style>
        :root {
            color-scheme: dark;
            --bg: #0f1115;
            --panel: #171a21;
            --panel-soft: #1b2028;
            --line: #2c3440;
            --text: #eef2f7;
            --muted: #9aa6b2;
            --accent: #d7b46a;
            --blue: #8fb3ff;
            --green: #74d391;
            --orange: #ffbf8f;
        }
        /* ライトテーマ — macOS風 */
        [data-theme="light"] {
            color-scheme: light;
            --bg: #ececec;
            --panel: #ffffff;
            --panel-soft: #f5f5f5;
            --line: #d1d1d6;
            --text: #1c1c1e;
            --muted: #6c6c70;
            --accent: #007aff;
            --blue: #007aff;
            --green: #34c759;
            --orange: #ff9500;
        }
        [data-theme="light"] body {
            background: #ececec;
        }
        [data-theme="light"] .tabs {
            background: rgba(255,255,255,0.82);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border-color: rgba(0,0,0,0.10);
        }
        [data-theme="light"] .tabs button,
        [data-theme="light"] .tabs a {
            background: rgba(0,0,0,0.04);
            border-color: rgba(0,0,0,0.08);
            color: #1c1c1e;
        }
        [data-theme="light"] .tabs button:hover,
        [data-theme="light"] .tabs a:hover,
        [data-theme="light"] .tabs button.is-active,
        [data-theme="light"] .tabs a.is-active {
            border-color: #007aff;
            color: #007aff;
            background: rgba(0,122,255,0.08);
            box-shadow: inset 0 0 0 1px rgba(0,122,255,0.20);
        }
        [data-theme="light"] .section {
            background: rgba(255,255,255,0.78);
            border-color: rgba(0,0,0,0.08);
        }
        [data-theme="light"] .section summary {
            color: #1c1c1e;
        }
        [data-theme="light"] .card {
            background: #ffffff;
            border-color: rgba(0,0,0,0.08);
        }
        [data-theme="light"] .label {
            color: #6c6c70;
        }
        [data-theme="light"] .value {
            color: #1c1c1e;
        }
        [data-theme="light"] .field select,
        [data-theme="light"] .field textarea {
            background: #ffffff;
            color: #1c1c1e;
            border-color: rgba(0,0,0,0.15);
        }
        [data-theme="light"] .button {
            background: rgba(0,0,0,0.05);
            color: #1c1c1e;
            border-color: rgba(0,0,0,0.12);
        }
        [data-theme="light"] .button:hover {
            background: rgba(0,0,0,0.09);
        }
        [data-theme="light"] .button.primary {
            background: #007aff;
            color: #fff;
            border-color: #007aff;
        }
        [data-theme="light"] table th {
            background: rgba(0,0,0,0.04);
            color: #6c6c70;
            border-color: rgba(0,0,0,0.08);
        }
        [data-theme="light"] table td {
            border-color: rgba(0,0,0,0.06);
            color: #1c1c1e;
        }
        [data-theme="light"] table tr:hover td {
            background: rgba(0,0,0,0.02);
        }
        [data-theme="light"] .pill {
            background: rgba(0,0,0,0.06);
            color: #3a3a3c;
        }
        [data-theme="light"] .pill.blue  { background: rgba(0,122,255,0.10);  color: #0055cc; }
        [data-theme="light"] .pill.green { background: rgba(52,199,89,0.12);  color: #1a7a35; }
        [data-theme="light"] .pill.orange{ background: rgba(255,149,0,0.12);  color: #b85c00; }
        [data-theme="light"] .notice     { background: rgba(52,199,89,0.08);  color: #1a5c2a; border-color: rgba(52,199,89,0.30); }
        [data-theme="light"] .warning    { background: rgba(255,149,0,0.08);  color: #7a3c00; border-color: rgba(255,149,0,0.30); }
        [data-theme="light"] .bar-fill   { background: linear-gradient(90deg, #007aff, #4da3ff); }
        [data-theme="light"] .bar-fill.green  { background: linear-gradient(90deg, #34c759, #7ddc96); }
        [data-theme="light"] .bar-fill.orange { background: linear-gradient(90deg, #ff9500, #ffb340); }
        [data-theme="light"] .section-title { color: #1c1c1e; }
        [data-theme="light"] .section-summary-meta { color: #6c6c70; }
        [data-theme="light"] .muted, [data-theme="light"] p.meta { color: #6c6c70; }
        [data-theme="light"] h1 { color: #1c1c1e; }
        [data-theme="light"] .theme-btn.is-active {
            border-color: #007aff;
            color: #007aff;
            background: rgba(0,122,255,0.10);
        }
        /* 設定セクション（応答モード）のダーク背景を上書き */
        [data-theme="light"] details.section[id="settings"] > summary,
        [data-theme="light"] details.section[id="settings"] {
            background: #ffffff;
            color: #1c1c1e;
        }
        [data-theme="light"] .mode-card {
            background: #f5f5f7;
            border-color: rgba(0,0,0,0.10);
            color: #1c1c1e;
        }
        [data-theme="light"] .mode-card .mode-name {
            color: #007aff;
        }
        [data-theme="light"] .mode-card p {
            color: #3a3a3c;
        }
        [data-theme="light"] .small.muted {
            color: #6c6c70;
        }
        /* セクション summary（折りたたみヘッダー）の黒背景を上書き */
        [data-theme="light"] .section > summary,
        [data-theme="light"] .section[open] > summary,
        [data-theme="light"] .section summary,
        [data-theme="light"] .section[open] summary {
            background: transparent !important;
            color: #1c1c1e !important;
            border-bottom-color: rgba(0,0,0,0.08) !important;
        }
        [data-theme="light"] .section {
            background: rgba(255,255,255,0.78) !important;
            border-color: rgba(0,0,0,0.08) !important;
        }
        [data-theme="light"] .section-card summary,
        [data-theme="light"] .section-card[open] summary,
        [data-theme="light"] summary.section-summary {
            background: transparent !important;
            color: #1c1c1e;
        }
        [data-theme="light"] .section-title {
            color: #1c1c1e;
        }
        [data-theme="light"] .section-summary-meta {
            color: #6c6c70;
        }
        [data-theme="light"] .section-summary-meta strong {
            color: #3a3a3c;
        }
        [data-theme="light"] .section-caret {
            color: #6c6c70;
        }
        /* overview-line などのダーク行 */
        [data-theme="light"] .overview-line {
            background: rgba(0,0,0,0.03);
        }
        /* テーマ切替ボタン */
        .theme-switcher {
            display: flex;
            gap: 4px;
            align-items: center;
        }
        .theme-btn {
            padding: 5px 10px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: transparent;
            color: var(--muted);
            font-size: 12px;
            cursor: pointer;
            transition: border-color .15s, color .15s, background .15s;
        }
        .theme-btn.is-active {
            border-color: var(--accent);
            color: var(--accent);
            background: rgba(215,180,106,0.10);
        }
        html {
            scroll-behavior: smooth;
            scroll-padding-top: 108px;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px;
            background: linear-gradient(180deg, #101319 0%, #08090c 100%);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Hiragino Sans", "Yu Gothic", Meiryo, sans-serif;
        }
        .wrap { max-width: 1180px; margin: 0 auto; }
        .topbar {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: baseline;
            margin-bottom: 18px;
        }
        h1 { margin: 0; font-size: 28px; }
        .meta { margin: 8px 0 0; color: var(--muted); }
        .nav-links a {
            color: var(--accent);
            text-decoration: none;
            margin-left: 12px;
            font-size: 13px;
        }
        .nav-links .btn-logout {
            display: inline-block;
            margin-left: 10px;
            padding: 5px 14px;
            border: 1px solid rgba(242,167,167,.5);
            border-radius: 6px;
            color: var(--danger);
            font-size: 12px;
            text-decoration: none;
            transition: background .15s;
        }
        .nav-links .btn-logout:hover {
            background: rgba(242,167,167,.12);
        }
        .tabs {
            position: sticky;
            top: 12px;
            z-index: 20;
            display: flex;
            flex-wrap: nowrap;
            gap: 8px;
            margin: 0 0 18px;
            padding: 10px;
            border: 1px solid var(--line);
            border-radius: 14px;
            background: rgba(23, 26, 33, 0.75);
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: thin;
        }
        .tabs button,
        .tabs a {
            flex: 0 0 auto;
            appearance: none;
            color: var(--text);
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #11151b;
            font-size: 13px;
            cursor: pointer;
            transition: border-color .15s ease, color .15s ease, background .15s ease, box-shadow .15s ease;
        }
        .tabs button:hover,
        .tabs a:hover,
        .tabs button.is-active,
        .tabs a.is-active {
            border-color: var(--accent);
            color: var(--accent);
            background: rgba(215, 180, 106, 0.08);
            box-shadow: inset 0 0 0 1px rgba(215, 180, 106, 0.18);
        }
        .tab-panel[hidden] {
            display: none !important;
        }
        .tab-panel {
            display: none;
        }
        .tab-panel.is-active {
            display: block;
        }
        .notice, .warning {
            margin: 0 0 12px;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1px solid var(--line);
            background: rgba(16, 19, 25, 0.85);
            line-height: 1.6;
        }
        .notice { color: #d9f4e2; border-color: rgba(116, 211, 145, 0.35); }
        .warning { color: #ffe3c5; border-color: rgba(255, 191, 143, 0.35); }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            align-items: end;
        }
        .field label { display: block; margin-bottom: 6px; color: var(--muted); font-size: 13px; }
        .field select, .field textarea {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #0f1318;
            color: var(--text);
            padding: 10px 12px;
            font: inherit;
        }
        .field textarea { min-height: 170px; resize: vertical; }
        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 12px;
        }
        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: #11151b;
            color: var(--text);
            text-decoration: none;
            font-size: 13px;
        }
        .button.primary {
            background: linear-gradient(135deg, #f2dc9d, #d7b46a);
            color: #1d1303;
            border-color: transparent;
            font-weight: 700;
        }
        .mode-guide {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin: 14px 0 18px;
        }
        .mode-card {
            border: 1px solid var(--line);
            border-radius: 12px;
            background: rgba(15, 19, 24, 0.95);
            padding: 14px;
        }
        .mode-card .mode-name {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            color: var(--accent);
            font-weight: 700;
        }
        .mode-card p {
            margin: 0;
            color: var(--muted);
            line-height: 1.65;
            font-size: 13px;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }
        .card, .section {
            background: rgba(23, 26, 33, 0.96);
            border: 1px solid var(--line);
            border-radius: 14px;
        }
        .card { padding: 16px; min-height: 110px; }
        .section-card { padding: 16px; }
        .section-card summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            cursor: pointer;
            list-style: none;
        }
        .section-card summary::-webkit-details-marker { display: none; }
        .section-card[open] summary { margin-bottom: 12px; }
        .section {
            margin-top: 16px;
            overflow: hidden;
        }
        .section summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 16px;
            cursor: pointer;
            list-style: none;
        }
        .section summary::-webkit-details-marker { display: none; }
        .section-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            font-weight: 700;
        }
        .section-note {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }
        .section-caret {
            flex: 0 0 auto;
            color: var(--muted);
            transition: transform .18s ease;
        }
        .section[open] .section-caret { transform: rotate(90deg); }
        .section[open] summary {
            border-bottom: 1px solid var(--line);
            background: rgba(18, 22, 28, 0.92);
        }
        .section-body { padding: 16px; }
        .label {
            display: block;
            color: var(--muted);
            font-size: 13px;
            margin-bottom: 8px;
        }
        .value {
            font-size: 30px;
            font-weight: 700;
            color: var(--accent);
            font-variant-numeric: tabular-nums;
        }
        .section h2 {
            margin: 0 0 12px;
            font-size: 18px;
        }
        .section-grid {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 12px;
        }
        .subgrid {
            display: grid;
            gap: 12px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 10px;
            border-bottom: 1px solid var(--line);
            text-align: left;
            vertical-align: top;
        }
        th {
            color: var(--muted);
            font-size: 13px;
            font-weight: 600;
        }
        td { font-size: 14px; }
        .pill {
            display: inline-block;
            min-width: 72px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(215, 180, 106, 0.14);
            color: var(--accent);
            font-size: 12px;
            font-weight: 700;
            text-align: center;
        }
        .pill.blue { background: rgba(143, 179, 255, 0.14); color: var(--blue); }
        .pill.green { background: rgba(116, 211, 145, 0.14); color: var(--green); }
        .pill.orange { background: rgba(255, 191, 143, 0.14); color: var(--orange); }
        .pill.priority-high { background: rgba(248, 113, 113, 0.18); color: #f87171; border: 1px solid rgba(248, 113, 113, 0.35); }
        .pill.priority-medium { background: rgba(255, 191, 143, 0.18); color: #ffbf8f; border: 1px solid rgba(255, 191, 143, 0.35); }
        .pill.priority-low { background: rgba(116, 211, 145, 0.16); color: #74d391; border: 1px solid rgba(116, 211, 145, 0.32); }
        /* WBSS ステータスバッジ */
        .pill.wbss-success { background: rgba(52,199,89,0.18);  color: #4ade80; border: 1px solid rgba(52,199,89,0.35); }
        .pill.wbss-unknown { background: rgba(251,191,36,0.18); color: #fbbf24; border: 1px solid rgba(251,191,36,0.35); }
        .pill.wbss-off     { background: rgba(156,163,175,0.18);color: #9ca3af; border: 1px solid rgba(156,163,175,0.30); }
        .pill.wbss-error   { background: rgba(239,68,68,0.18);  color: #f87171; border: 1px solid rgba(239,68,68,0.35); }
        /* ライトモード上書き */
        [data-theme="light"] .pill.wbss-success { background: rgba(52,199,89,0.12);  color: #1a7a35; border-color: rgba(52,199,89,0.30); }
        [data-theme="light"] .pill.wbss-unknown { background: rgba(234,179,8,0.12);  color: #854d0e; border-color: rgba(234,179,8,0.35); }
        [data-theme="light"] .pill.wbss-off     { background: rgba(107,114,128,0.10);color: #4b5563; border-color: rgba(107,114,128,0.25); }
        [data-theme="light"] .pill.wbss-error   { background: rgba(239,68,68,0.10);  color: #b91c1c; border-color: rgba(239,68,68,0.30); }
        [data-theme="light"] .pill.priority-high { background: rgba(239,68,68,0.10); color: #b91c1c; border-color: rgba(239,68,68,0.30); }
        [data-theme="light"] .pill.priority-medium { background: rgba(245,158,11,0.10); color: #b45309; border-color: rgba(245,158,11,0.30); }
        [data-theme="light"] .pill.priority-low { background: rgba(34,197,94,0.10); color: #15803d; border-color: rgba(34,197,94,0.28); }
        .metric {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 0 0;
        }
        .metric .big {
            font-size: 24px;
            font-weight: 700;
        }
        .metric .small {
            color: var(--muted);
            font-size: 13px;
        }
        .bar-row {
            display: grid;
            grid-template-columns: 84px 1fr 70px;
            gap: 10px;
            align-items: center;
            margin: 10px 0;
        }
        .bar-track {
            height: 12px;
            background: #0f1318;
            border: 1px solid var(--line);
            border-radius: 999px;
            overflow: hidden;
        }
        .bar-fill {
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, rgba(215, 180, 106, 0.9), rgba(244, 216, 145, 0.95));
        }
        .bar-fill.blue { background: linear-gradient(90deg, rgba(143, 179, 255, 0.9), rgba(181, 203, 255, 0.95)); }
        .bar-fill.green { background: linear-gradient(90deg, rgba(116, 211, 145, 0.9), rgba(160, 231, 184, 0.95)); }
        .bar-fill.orange { background: linear-gradient(90deg, rgba(255, 191, 143, 0.9), rgba(255, 213, 181, 0.95)); }
        .bar-value { color: var(--muted); text-align: right; font-variant-numeric: tabular-nums; }
        .muted { color: var(--muted); }
        .compact-list { display: grid; gap: 10px; }
        .question-item {
            display: grid;
            grid-template-columns: 52px 1fr 88px;
            gap: 10px;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid var(--line);
        }
        .question-item:last-child { border-bottom: 0; }
        .rank {
            color: var(--accent);
            font-weight: 700;
            text-align: center;
        }
        .question-text { word-break: break-word; }
        .message {
            white-space: pre-wrap;
            word-break: break-word;
        }
        .overview-line {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
        }
        .table-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .table-scroll table {
            min-width: 720px;
        }
        .empty {
            color: var(--muted);
            padding: 12px 0;
        }
        .mode-description-line {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
            margin-top: 8px;
        }
        .section-summary-meta {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--muted);
            font-size: 12px;
        }
        .section-summary-meta strong {
            color: var(--text);
            font-weight: 600;
        }
        .ops-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 12px;
        }
        .health-breakdown-grid,
        .diagnostic-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 12px;
        }
        .health-breakdown-card,
        .diagnostic-card {
            border: 1px solid var(--line);
            border-radius: 14px;
            background: rgba(15,19,24,0.78);
            padding: 14px;
            display: grid;
            gap: 8px;
        }
        .health-breakdown-score,
        .diagnostic-value {
            font-size: 24px;
            font-weight: 700;
            line-height: 1.1;
            font-variant-numeric: tabular-nums;
            color: var(--accent);
        }
        .health-breakdown-note,
        .diagnostic-note {
            font-size: 12px;
            color: var(--muted);
            line-height: 1.5;
            word-break: break-word;
        }
        .diagnostic-label {
            font-size: 12px;
            color: var(--muted);
            font-weight: 600;
        }
        .ops-card {
            background: rgba(15,19,24,0.82);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 14px;
        }
        .ops-card .ops-label {
            font-size: 12px;
            color: var(--muted);
            margin-bottom: 8px;
        }
        .ops-card .ops-value {
            font-size: 26px;
            font-weight: 700;
            line-height: 1.1;
            font-variant-numeric: tabular-nums;
        }
        .ops-card .ops-sub {
            margin-top: 6px;
            font-size: 12px;
            color: var(--muted);
            line-height: 1.5;
        }
        .ops-card.good .ops-value { color: #74d391; }
        .ops-card.warning .ops-value { color: #ffbf8f; }
        .ops-card.danger .ops-value { color: #f87171; }
        .ops-card.accent .ops-value { color: var(--accent); }
        .ops-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid var(--line);
            font-size: 12px;
            color: var(--muted);
            margin-top: 8px;
        }
        .action-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 12px;
        }
        .action-card {
            border: 1px solid var(--line);
            border-radius: 14px;
            background: rgba(15,19,24,0.78);
            padding: 14px;
            display: grid;
            gap: 8px;
        }
        .action-card.priority-high { border-color: rgba(248, 113, 113, 0.35); }
        .action-card.priority-medium { border-color: rgba(255, 191, 143, 0.35); }
        .action-card.priority-low { border-color: rgba(116, 211, 145, 0.3); }
        .action-card h3 {
            margin: 0;
            font-size: 16px;
            line-height: 1.35;
        }
        .action-card .meta-line {
            font-size: 12px;
            color: var(--muted);
            line-height: 1.5;
        }
        .action-card .item-label {
            font-size: 12px;
            color: var(--accent);
            font-weight: 700;
            margin-top: 4px;
        }
        .action-card .link {
            font-size: 12px;
            color: var(--blue);
            text-decoration: none;
        }
        .action-card .link:hover { text-decoration: underline; }
        .section-anchor-link {
            font-size: 12px;
            color: var(--blue);
            text-decoration: none;
        }
        .section-anchor-link:hover { text-decoration: underline; }
        .ranking-card-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 12px;
        }
        .ranking-card {
            border: 1px solid var(--line);
            border-radius: 14px;
            background: rgba(15,19,24,0.78);
            padding: 14px;
        }
        .ranking-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
        }
        .ranking-head .title {
            font-weight: 700;
        }
        .ranking-bar {
            display: grid;
            grid-template-columns: 1fr 60px;
            gap: 10px;
            align-items: center;
            margin-top: 8px;
        }
        .ranking-bar .track {
            height: 12px;
            border: 1px solid var(--line);
            background: #0f1318;
            border-radius: 999px;
            overflow: hidden;
        }
        .ranking-bar .fill {
            height: 100%;
            background: linear-gradient(90deg, rgba(215, 180, 106, 0.9), rgba(244, 216, 145, 0.95));
        }
        .reply-card-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }
        .reply-card {
            border: 1px solid var(--line);
            border-radius: 14px;
            background: rgba(15,19,24,0.78);
            padding: 14px;
        }
        .reply-card .reply-count {
            font-size: 24px;
            font-weight: 700;
            color: var(--accent);
            margin-bottom: 6px;
        }
        .reply-card .reply-label {
            font-weight: 700;
            margin-bottom: 6px;
        }
        .reply-card .reply-text {
            white-space: pre-wrap;
            word-break: break-word;
            color: var(--muted);
            line-height: 1.55;
            font-size: 13px;
        }
        .reply-card details {
            margin-top: 10px;
        }
        .period-switcher {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin: 8px 0 12px;
        }
        .period-switcher .switch-label {
            font-size: 12px;
            color: var(--muted);
            font-weight: 600;
        }
        .period-switcher a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid var(--line);
            color: var(--text);
            text-decoration: none;
            font-size: 12px;
            background: rgba(15,19,24,0.6);
        }
        .period-switcher a.is-active {
            background: rgba(215,180,106,0.18);
            border-color: rgba(215,180,106,0.45);
            color: var(--accent);
            font-weight: 700;
        }
        @media (max-width: 1100px) {
            .grid, .overview-line, .mode-guide, .ops-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .health-breakdown-grid, .diagnostic-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .section-grid { grid-template-columns: 1fr; }
            .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .action-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .reply-card-grid { grid-template-columns: 1fr; }
        }
        @media (max-width: 860px) {
            body { padding: 14px; }
            .grid, .overview-line, .section-grid, .mode-guide, .ops-grid, .action-grid, .health-breakdown-grid, .diagnostic-grid { grid-template-columns: 1fr; }
            .topbar { flex-direction: column; align-items: flex-start; }
            .question-item { grid-template-columns: 40px 1fr; }
            .question-item .bar-value { grid-column: 2; text-align: left; }
            .tabs { top: 8px; padding: 8px; }
            .kpi-grid { grid-template-columns: 1fr; }
        }
        /* v0.6.9: KPIカード */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }
        .kpi-card {
            background: rgba(23, 26, 33, 0.96);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 16px;
        }
        .kpi-value {
            font-size: 28px;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            color: var(--accent);
        }
        .kpi-good  .kpi-value { color: #74d391; }
        .kpi-warning .kpi-value { color: #ffbf8f; }
        .kpi-danger .kpi-value { color: #f87171; }
        [data-theme="light"] .kpi-card {
            background: #ffffff;
            border-color: rgba(0,0,0,0.08);
        }
        [data-theme="light"] .kpi-good  .kpi-value { color: #1a7a35; }
        [data-theme="light"] .kpi-warning .kpi-value { color: #b85c00; }
        [data-theme="light"] .kpi-danger .kpi-value { color: #b91c1c; }
        html[data-theme="light"] .ops-card,
        html[data-theme="light"] .action-card,
        html[data-theme="light"] .ranking-card,
        html[data-theme="light"] .reply-card,
        html[data-theme="light"] .health-breakdown-card,
        html[data-theme="light"] .diagnostic-card {
            background: #ffffff !important;
            border-color: rgba(0,0,0,0.08) !important;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05) !important;
        }
        html[data-theme="light"] .ops-card .ops-label,
        html[data-theme="light"] .ops-card .ops-sub,
        html[data-theme="light"] .action-card .meta-line,
        html[data-theme="light"] .reply-card .reply-text,
        html[data-theme="light"] .ranking-card .muted,
        html[data-theme="light"] .ranking-card .meta-line {
            color: #5c5f66 !important;
        }
        html[data-theme="light"] .ops-card .ops-value,
        html[data-theme="light"] .action-card h3,
        html[data-theme="light"] .reply-card .reply-label,
        html[data-theme="light"] .ranking-head .title,
        html[data-theme="light"] .health-breakdown-card .diagnostic-label {
            color: #1c1c1e !important;
        }
        html[data-theme="light"] .ops-card.good .ops-value { color: #1f8a4c !important; }
        html[data-theme="light"] .ops-card.warning .ops-value { color: #b85c00 !important; }
        html[data-theme="light"] .ops-card.danger .ops-value { color: #b91c1c !important; }
        html[data-theme="light"] .ops-card.accent .ops-value { color: #0a84ff !important; }
        html[data-theme="light"] .ops-badge {
            background: rgba(0,0,0,0.04) !important;
            border-color: rgba(0,0,0,0.10) !important;
            color: #4b4f57 !important;
        }
        html[data-theme="light"] .action-card .link,
        html[data-theme="light"] .section-anchor-link {
            color: #0a84ff !important;
        }
        html[data-theme="light"] .ranking-card .track {
            background: #edf0f4 !important;
            border-color: rgba(0,0,0,0.08) !important;
        }
        html[data-theme="light"] .health-breakdown-score,
        html[data-theme="light"] .diagnostic-value {
            color: #1c1c1e !important;
        }
        html[data-theme="light"] .health-breakdown-note,
        html[data-theme="light"] .diagnostic-note {
            color: #5c5f66 !important;
        }
        html[data-theme="light"] .period-switcher a {
            background: #ffffff !important;
            border-color: rgba(0,0,0,0.08) !important;
            color: #1c1c1e !important;
        }
        html[data-theme="light"] .period-switcher a.is-active {
            background: rgba(10,132,255,0.10) !important;
            border-color: rgba(10,132,255,0.28) !important;
            color: #0a84ff !important;
        }
        /* v0.6.9: 健康診断スコア */
        .health-score-wrap {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-top: 12px;
        }
        .health-score-big {
            font-size: 56px;
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            color: var(--accent);
            line-height: 1;
        }
        .health-score-detail {
            display: grid;
            gap: 6px;
            flex: 1;
            min-width: 200px;
        }
        .health-score-row {
            display: flex;
            justify-content: space-between;
            font-size: 13px;
            color: var(--muted);
        }
        .health-score-row span:last-child {
            font-weight: 700;
            color: var(--text);
        }
        /* v0.6.9: 改善TOP3 */
        .improvement-list {
            display: grid;
            gap: 10px;
            margin-top: 12px;
        }
        .improvement-item {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 10px 12px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: rgba(15,19,24,0.7);
            font-size: 14px;
        }
        .improvement-item.priority-high { border-color: rgba(248, 113, 113, 0.35); }
        .improvement-item.priority-medium { border-color: rgba(255, 191, 143, 0.35); }
        .improvement-item.priority-low { border-color: rgba(116, 211, 145, 0.3); }
        .improvement-rank {
            flex: 0 0 auto;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: var(--accent);
            color: #1d1303;
            font-weight: 700;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        [data-theme="light"] .improvement-item {
            background: #f5f5f7;
            border-color: rgba(0,0,0,0.09);
        }
        .ranking-examples {
            margin: 8px 0 0;
            padding-left: 18px;
            color: var(--muted);
            line-height: 1.55;
        }
        .ranking-examples li {
            margin: 2px 0;
            word-break: break-word;
        }
        /* システム設定サブタブ */
        .sys-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            padding: 12px 16px 0;
            border-bottom: 1px solid var(--line);
            background: var(--panel);
            border-radius: 14px 14px 0 0;
        }
        .sys-tab-btn {
            padding: 7px 14px;
            border: 1px solid transparent;
            border-bottom: none;
            border-radius: 8px 8px 0 0;
            background: none;
            color: var(--muted);
            font-size: 13px;
            cursor: pointer;
            white-space: nowrap;
            transition: color .15s, background .15s;
        }
        .sys-tab-btn:hover { color: var(--text); background: rgba(255,255,255,.04); }
        .sys-tab-btn.is-active {
            color: var(--text);
            background: var(--panel-soft);
            border-color: var(--line);
            margin-bottom: -1px;
            padding-bottom: 8px;
        }
        .sys-tab-btn .dev-badge {
            display: inline-block;
            font-size: 9px;
            padding: 1px 5px;
            border-radius: 4px;
            background: rgba(255,149,0,.15);
            color: var(--orange);
            margin-left: 4px;
            vertical-align: middle;
        }
        .sys-panel { padding: 16px; }
        .sys-panel[hidden] { display: none; }
        /* AIキャラクター カード */
        .char-active-card {
            display: flex;
            align-items: center;
            gap: 16px;
            padding: 16px;
            background: var(--panel-soft);
            border: 1px solid var(--line);
            border-radius: 12px;
            margin-bottom: 14px;
        }
        .char-avatar {
            flex: 0 0 64px;
            width: 64px; height: 64px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid var(--accent);
            background: var(--line);
        }
        .char-avatar-placeholder {
            flex: 0 0 64px;
            width: 64px; height: 64px;
            border-radius: 50%;
            background: var(--line);
            display: flex; align-items: center; justify-content: center;
            font-size: 28px;
        }
        .char-info { flex: 1; min-width: 0; }
        .char-name { font-size: 20px; font-weight: 700; margin-bottom: 2px; }
        .char-title { font-size: 12px; color: var(--muted); margin-bottom: 6px; }
        .char-greeting { font-size: 13px; color: var(--text); opacity: .8; white-space: pre-line; word-break: break-word; }
        .char-status { flex: 0 0 auto; text-align: center; }
        [data-theme="light"] .sys-tabs { background: #f5f5f7; }
        [data-theme="light"] .sys-tab-btn.is-active { background: #fff; }
        [data-theme="light"] .char-active-card { background: #fff; border-color: rgba(0,0,0,.10); }
        @media (max-width: 600px) {
            .sys-tabs { gap: 2px; padding: 8px 8px 0; }
            .sys-tab-btn { padding: 6px 10px; font-size: 12px; }
            .char-active-card { flex-wrap: wrap; }
        }

        /* サブタブ（質問傾向分析 / 応募導線分析 / 出勤分析 — システム設定と統一） */
        .sub-tab-nav-wrap { padding: 0; background: none; border: none; box-shadow: none; margin-bottom: 0; }
        .sub-tabs {
            display: flex; flex-wrap: wrap; gap: 4px;
            padding: 12px 16px 0;
            border-bottom: 1px solid var(--line);
            background: var(--panel);
            border-radius: 14px 14px 0 0;
            margin-bottom: 0;
        }
        .sub-tab-btn {
            padding: 7px 14px;
            border: 1px solid transparent;
            border-bottom: none;
            border-radius: 8px 8px 0 0;
            background: none;
            color: var(--muted);
            font-size: 13px;
            cursor: pointer;
            white-space: nowrap;
            transition: color .15s, background .15s;
        }
        .sub-tab-btn:hover { color: var(--text); background: rgba(255,255,255,.04); }
        .sub-tab-btn.is-active {
            color: var(--text);
            background: var(--panel-soft);
            border-color: var(--line);
            margin-bottom: -1px;
            padding-bottom: 8px;
        }
        [data-theme="light"] .sub-tabs { background: #f5f5f7; }
        [data-theme="light"] .sub-tab-btn.is-active { background: #fff; border-color: rgba(0,0,0,.12); }
        .sub-tab-hidden { display: none !important; }
        /* 会話品質ネストサブタブ */
        .qual-sub-tabs {
            display: flex; flex-wrap: wrap; gap: 4px;
            padding: 10px 12px 0;
            border-bottom: 1px solid var(--line);
            background: var(--panel-soft);
            border-radius: 10px 10px 0 0;
            margin-bottom: 12px;
        }
        .qual-sub-btn {
            padding: 5px 12px;
            border: 1px solid transparent;
            border-bottom: none;
            border-radius: 6px 6px 0 0;
            background: none;
            color: var(--muted);
            font-size: 12px;
            cursor: pointer;
            white-space: nowrap;
            transition: color .15s, background .15s;
        }
        .qual-sub-btn:hover { color: var(--text); background: rgba(255,255,255,.04); }
        .qual-sub-btn.is-active {
            color: var(--text);
            background: var(--bg);
            border-color: var(--line);
            margin-bottom: -1px;
            padding-bottom: 6px;
        }
        [data-theme="light"] .qual-sub-tabs { background: #f0f0f2; }
        [data-theme="light"] .qual-sub-btn.is-active { background: #fff; }
        .qual-sub-hidden { display: none !important; }
        @media (max-width: 600px) {
            .sub-tab-btn { padding: 6px 10px; font-size: 12px; }
            .qual-sub-btn { padding: 4px 8px; font-size: 11px; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="topbar">
            <div>
                <h1>入店前コンシェルジュ運営システム</h1>
                <p class="meta">応募前ヒアリング・体験入店導線・応募者評価を確認する画面です。</p>
                <p class="meta">現在の応答モード: <?= e($currentResponseMode) ?></p>
                <p class="meta">Project TWIN v<?= e(APP_VERSION) ?></p>
            </div>
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <div class="theme-switcher">
                    <button class="theme-btn" data-theme-val="auto" onclick="setTheme('auto')">自動</button>
                    <button class="theme-btn" data-theme-val="light" onclick="setTheme('light')">ライト</button>
                    <button class="theme-btn" data-theme-val="dark" onclick="setTheme('dark')">ダーク</button>
                </div>
                <div class="nav-links">
                    <a href="/crew-onboarding/">← チャットへ戻る</a>
                    <a href="/crew-onboarding/admin.php?logout=1" class="btn-logout">ログアウト</a>
                </div>
            </div>
        </div>

        <nav class="tabs" aria-label="管理画面ナビゲーション">
            <button type="button" class="tab-btn is-active" data-tab="dashboard">ダッシュボード</button>
            <button type="button" class="tab-btn" data-tab="conversation">質問傾向分析</button>
            <button type="button" class="tab-btn" data-tab="conversion">応募導線分析</button>
            <button type="button" class="tab-btn" data-tab="wbss">応募者分析</button>
            <button type="button" class="tab-btn" data-tab="applicants">応募者一覧</button>
            <button type="button" class="tab-btn" data-tab="ai">AI改善提案</button>
            <button type="button" class="tab-btn" data-tab="system">システム設定</button>
        </nav>

        <?php if ($adminNotice !== ''): ?>
            <div class="notice"><?= e($adminNotice) ?></div>
        <?php endif; ?>
        <?php if ($adminWarning !== ''): ?>
            <div class="warning"><?= e($adminWarning) ?></div>
        <?php endif; ?>

        <details class="section tab-panel is-active" data-panel="dashboard" id="overview" open>
            <summary>
                <span class="section-title">概要 &amp; KPI</span>
                <span class="section-summary-meta"><strong>Overview</strong><span>全体の稼働指標と改善ダッシュボード</span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <div class="card" style="margin-bottom: 12px;">
                    <span class="label">Operations Summary</span>
                    <div class="ops-grid">
                        <div class="ops-card <?= e($operationsSummary['health_class']) ?>">
                            <div class="ops-label">採用導線診断 システム品質</div>
                            <div class="ops-value"><?= e((string) $operationsSummary['health_score']) ?>点</div>
                            <div class="ops-sub">intent精度・出勤連携・会話自然度・CTA表示率の重み付き合計</div>
                        </div>
                        <div class="ops-card <?= e($operationsSummary['line_ctr_class']) ?>">
                            <div class="ops-label">応募導線KPI: LINE CTR</div>
                            <div class="ops-value"><?= e(number_format($operationsSummary['line_ctr'], 1)) ?>%</div>
                            <div class="ops-sub">クリック数 ÷ セッション数 / CTA表示率 <?= e(number_format($lineCtaShownRate, 1)) ?>%</div>
                        </div>
                        <div class="ops-card <?= e($operationsSummary['other_rate_class']) ?>">
                            <div class="ops-label">other率<?= $operationsSummary['other_rate_is_24h'] ? '（直近24h）' : '（全期間）' ?></div>
                            <div class="ops-value"><?= e(number_format($operationsSummary['other_rate'], 1)) ?>%</div>
                            <div class="ops-sub">未分類 intent の割合<?= $operationsSummary['other_rate_is_24h'] ? '（全期間: ' . e(number_format($operationsSummary['other_rate_all'], 1)) . '%）' : '' ?></div>
                        </div>
                        <div class="ops-card accent">
                            <div class="ops-label">AI利用額 今日 / 今月</div>
                            <div class="ops-value">¥<?= e(number_format($operationsSummary['ai_today'], 2)) ?></div>
                            <div class="ops-sub">今月 ¥<?= e(number_format($operationsSummary['ai_month'], 2)) ?></div>
                            <?php if ($operationsSummary['has_unrecorded_usage']): ?>
                                <div class="ops-badge">usage未記録あり</div>
                            <?php endif; ?>
                        </div>
                        <div class="ops-card <?= e($operationsSummary['wbss_status_class']) ?>">
                            <div class="ops-label">出勤連携状態</div>
                            <div class="ops-value"><?= e($operationsSummary['wbss_status_label']) ?></div>
                            <div class="ops-sub">成功率 <?= e(number_format($operationsSummary['wbss_success_rate'], 1)) ?>% / 平均 <?= e((string) $operationsSummary['wbss_avg_response_ms']) ?>ms</div>
                        </div>
                        <div class="ops-card accent">
                            <div class="ops-label">現在の応答方式</div>
                            <div class="ops-value"><?= e($operationsSummary['response_mode_label']) ?></div>
                            <div class="ops-sub">rule / openai / hybrid の切替結果</div>
                        </div>
                    </div>
                </div>

                <div class="card" style="margin-bottom: 12px;">
                    <span class="label">今週の改善TOP3</span>
                    <?php if (!$improvementSuggestions): ?>
                        <div class="empty">改善候補はまだありません。</div>
                    <?php else: ?>
                        <div class="action-grid">
                            <?php foreach (array_slice($improvementSuggestions, 0, 3) as $item): ?>
                                <div class="action-card <?= e('priority-' . ($item['priority'] ?? 'low')) ?>">
                                    <div class="ops-badge">優先度 <?= e(twin_question_ranking_priority_label((string) $item['priority'])) ?></div>
                                    <h3><?= e((string) $item['title']) ?></h3>
                                    <div class="meta-line"><strong>現在:</strong> <?= e((string) $item['current']) ?></div>
                                    <div class="meta-line"><strong>理由:</strong> <?= e((string) $item['reason']) ?></div>
                                    <div class="meta-line"><strong>推奨アクション:</strong> <?= e((string) $item['action']) ?></div>
                                    <div class="meta-line"><strong>期待効果:</strong> <?= e((string) $item['effect']) ?></div>
                                    <div class="meta-line"><strong>目安工数:</strong> <?= e((string) $item['effort']) ?></div>
                                    <a class="link" href="<?= e((string) $item['link']) ?>">関連セクションへ</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- 基本KPIカード（既存4枚） -->
                <section class="grid" style="margin-bottom: 12px;">
                    <div class="card">
                        <span class="label">総セッション数</span>
                        <div class="value"><?= e((string) $overview['sessions']) ?></div>
                    </div>
                    <div class="card">
                        <span class="label">総メッセージ数</span>
                        <div class="value"><?= e((string) $overview['messages']) ?></div>
                    </div>
                    <div class="card">
                        <span class="label">平均会話数</span>
                        <div class="value"><?= e(number_format($overview['avg_messages'], 1)) ?></div>
                    </div>
                    <div class="card">
                        <span class="label">平均滞在時間</span>
                        <div class="value"><?= e(twin_minutes_seconds($overview['avg_duration_seconds'])) ?></div>
                    </div>
                </section>

                <!-- v0.6.9: KPIカード（改善ダッシュボード） -->
                <section class="kpi-grid">
                    <div class="kpi-card">
                        <span class="label">LINE CTR（クリック/セッション）</span>
                        <div class="kpi-value"><?= e(number_format($lineCtr, 1)) ?>%</div>
                        <div class="muted" style="font-size:12px;margin-top:4px;">LINEクリック <?= e((string) $lineClickCount) ?>件 / セッション <?= e((string) $overview['sessions']) ?>件</div>
                    </div>
                    <div class="kpi-card <?= e($otherRateClass) ?>">
                        <span class="label">other率（未分類intent率）</span>
                        <div class="kpi-value"><?= e(number_format($otherRate, 1)) ?>%</div>
                        <div class="muted" style="font-size:12px;margin-top:4px;">other <?= e((string) $otherCount) ?>件 / 全発言 <?= e((string) $totalUserMessages) ?>件</div>
                    </div>
                    <div class="kpi-card">
                        <span class="label">出勤質問率（attendance + cast_schedule）</span>
                        <div class="kpi-value"><?= e(number_format($attendanceRate, 1)) ?>%</div>
                        <div class="muted" style="font-size:12px;margin-top:4px;"><?= e((string) $attendanceCount) ?>件</div>
                    </div>
                    <div class="kpi-card">
                        <span class="label">時給質問率（price intent）</span>
                        <div class="kpi-value"><?= e(number_format($priceRate, 1)) ?>%</div>
                        <div class="muted" style="font-size:12px;margin-top:4px;"><?= e((string) $priceCount) ?>件</div>
                    </div>
                </section>

                <!-- 採用導線診断（1カード＋詳細折りたたみ） -->
                <?php
                    $healthGood = [];
                    $healthBad  = [];
                    foreach ($healthScoreBreakdown as $hrow) {
                        $hs = (int) ($hrow['score'] ?? -1);
                        if ($hs < 0) continue;
                        if ($hs >= 75) $healthGood[] = $hrow['label'];
                        elseif ($hs < 50) $healthBad[] = $hrow['label'];
                    }
                    $healthClass = $healthScore >= 80 ? 'kpi-good' : ($healthScore >= 60 ? 'kpi-warning' : 'kpi-danger');
                    $todayTodo = [];
                    if ($otherRatePrimary >= 15) $todayTodo[] = 'other率が高い — intent追加を検討';
                    if ($wbssSuccessRate < 70)   $todayTodo[] = 'WBSS精度が低い — alias/除外語を整理';
                    if ($improvementCount > 0)   $todayTodo[] = '改善センターに ' . $improvementCount . '件の改善候補';
                ?>
                <div class="card" style="margin-bottom:12px;">
                    <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                        <div>
                            <div style="font-size:11px;color:var(--muted);margin-bottom:2px;">採用導線診断</div>
                            <div class="health-score-big <?= e($healthClass) ?>" style="font-size:36px;"><?= e((string) $healthScore) ?><span style="font-size:16px;font-weight:400;">点</span></div>
                        </div>
                        <div style="flex:1;min-width:180px;">
                            <?php if ($healthGood): ?>
                                <div style="font-size:12px;color:var(--green);margin-bottom:4px;">✅ 良好: <?= e(implode(' / ', $healthGood)) ?></div>
                            <?php endif; ?>
                            <?php if ($healthBad): ?>
                                <div style="font-size:12px;color:var(--orange);margin-bottom:4px;">⚠️ 要改善: <?= e(implode(' / ', $healthBad)) ?></div>
                            <?php endif; ?>
                            <?php if ($todayTodo): ?>
                                <div style="font-size:12px;color:var(--text);margin-top:4px;font-weight:600;">今日やること:</div>
                                <?php foreach ($todayTodo as $t): ?><div style="font-size:12px;color:var(--muted);">→ <?= e($t) ?></div><?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <details style="margin-top:12px;">
                        <summary style="font-size:12px;color:var(--muted);cursor:pointer;">▼ 内訳・Intent精度TOP課題</summary>
                        <div class="health-breakdown-grid" style="margin-top:10px;">
                            <?php foreach ($healthScoreBreakdown as $row): ?>
                                <?php
                                    $score = (int) ($row['score'] ?? 0);
                                    $isMeasured = $score >= 0;
                                    $cardClass = !$isMeasured ? 'kpi-warning' : ($score >= 80 ? 'kpi-good' : ($score >= 50 ? 'kpi-warning' : 'kpi-danger'));
                                    $scoreDisplay = !$isMeasured ? '未計測' : (string) $score;
                                ?>
                                <div class="health-breakdown-card <?= e($cardClass) ?>">
                                    <div class="diagnostic-label"><?= e((string) $row['label']) ?></div>
                                    <div class="health-breakdown-score"><?= e($scoreDisplay) ?></div>
                                    <div class="health-breakdown-note"><?= e((string) ($row['note'] ?? '')) ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($intentAccuracyIssues): ?>
                            <div class="table-scroll" style="margin-top:10px;">
                                <table>
                                    <thead><tr><th>Intent精度 課題</th><th>件数</th><th>率</th><th>改善ヒント</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($intentAccuracyIssues as $issue): ?>
                                            <?php $rateClass = ((float)$issue['rate']) >= 20 ? 'kpi-danger' : (((float)$issue['rate']) >= 10 ? 'kpi-warning' : 'kpi-good'); ?>
                                            <tr>
                                                <td><strong><?= e((string) $issue['label']) ?></strong></td>
                                                <td><?= e((string) $issue['count']) ?>件</td>
                                                <td><span class="pill <?= e($rateClass) ?>"><?= e(number_format((float)$issue['rate'], 1)) ?>%</span></td>
                                                <td class="muted"><?= e((string) $issue['hint']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </details>
                </div>
            </div>
        </details>

        <details class="section tab-panel is-active" data-panel="dashboard" id="improvements" open>
            <summary>
                <span class="section-title">改善センター</span>
                <span class="section-summary-meta"><strong>TODO</strong><span>次に何を直せばいいか一目で分かる</span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <?php if (!$improvementSuggestions): ?>
                    <div style="padding:16px;text-align:center;color:var(--green);">✅ 現在、改善が必要な項目はありません</div>
                <?php else: ?>
                    <?php
                        $starMap = ['high' => '★★★★★', 'medium' => '★★★★☆', 'low' => '★★★☆☆'];
                        $colorMap = ['high' => 'kpi-danger', 'medium' => 'kpi-warning', 'low' => ''];
                    ?>
                    <div style="display:flex;flex-direction:column;gap:12px;">
                    <?php foreach ($improvementSuggestions as $item): ?>
                        <?php
                            $prio = (string) ($item['priority'] ?? 'low');
                            $stars = $starMap[$prio] ?? '★★☆☆☆';
                            $colorClass = $colorMap[$prio] ?? '';
                        ?>
                        <div style="background:var(--panel-soft);border:1px solid var(--line);border-radius:12px;padding:14px 16px;display:grid;grid-template-columns:auto 1fr auto;gap:12px;align-items:start;">
                            <div style="text-align:center;min-width:60px;">
                                <div style="font-size:11px;color:var(--muted);margin-bottom:2px;">改善優先度</div>
                                <div style="font-size:13px;color:var(--accent);letter-spacing:1px;"><?= e($stars) ?></div>
                            </div>
                            <div>
                                <div style="font-weight:700;font-size:15px;margin-bottom:6px;"><?= e((string) $item['title']) ?></div>
                                <div style="font-size:13px;color:var(--muted);margin-bottom:4px;"><strong>現状:</strong> <span class="<?= e($colorClass) ?>"><?= e((string) $item['current']) ?></span></div>
                                <div style="font-size:13px;color:var(--muted);margin-bottom:2px;"><strong>原因:</strong> <?= e((string) $item['reason']) ?></div>
                                <div style="font-size:13px;color:var(--text);margin-top:6px;padding:6px 10px;background:var(--bg);border-radius:6px;border-left:3px solid var(--accent);">
                                    <strong>対応:</strong> <?= e((string) $item['action']) ?>
                                </div>
                            </div>
                            <div style="text-align:right;min-width:90px;">
                                <div style="font-size:11px;color:var(--muted);">工数</div>
                                <div style="font-size:13px;font-weight:600;"><?= e((string) $item['effort']) ?></div>
                                <div style="margin-top:6px;font-size:11px;color:var(--muted);">期待効果</div>
                                <div style="font-size:11px;color:var(--green);"><?= e((string) $item['effect']) ?></div>
                                <a class="link" href="<?= e((string) $item['link']) ?>" style="display:block;margin-top:6px;font-size:11px;">詳細→</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </details>

        <div class="tab-panel sub-tab-nav-wrap" data-panel="conversation">
            <div class="sub-tabs" id="conv-sub-tabs" role="tablist" aria-label="質問傾向分析サブタブ">
                <button class="sub-tab-btn is-active" data-conv-sub="summary" role="tab" aria-selected="true" type="button">サマリー</button>
                <button class="sub-tab-btn" data-conv-sub="interview" role="tab" aria-selected="false" type="button">問診回答分析</button>
                <button class="sub-tab-btn" data-conv-sub="ranking" role="tab" aria-selected="false" type="button">質問ランキング</button>
                <button class="sub-tab-btn" data-conv-sub="recent" role="tab" aria-selected="false" type="button">直近質問</button>
                <button class="sub-tab-btn" data-conv-sub="unclassified" role="tab" aria-selected="false" type="button">未分類分析</button>
                <button class="sub-tab-btn" data-conv-sub="improvement" role="tab" aria-selected="false" type="button">改善提案</button>
            </div>
        </div>

        <details class="section tab-panel" data-panel="conversation" data-conv-sub="summary" id="conv-summary" open>
            <summary>
                <span class="section-title">今日の状況</span>
                <span class="section-summary-meta"><strong>Today</strong><span>状況・問題・成果を一目で確認</span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <!-- 今日の状況 -->
                <div class="card" style="margin-bottom:12px;">
                    <span class="label" style="font-size:12px;letter-spacing:.05em;">📊 今日の状況</span>
                    <div class="overview-line" style="margin-top:10px;">
                        <div>
                            <div class="small muted">セッション数</div>
                            <div class="big"><?= e((string) $overview['sessions']) ?></div>
                        </div>
                        <div>
                            <div class="small muted">LINE CTR</div>
                            <div class="big <?= $lineCtr >= 10 ? 'kpi-good' : ($lineCtr >= 5 ? 'kpi-warning' : 'kpi-danger') ?>"><?= e(number_format($lineCtr, 1)) ?>%</div>
                        </div>
                        <div>
                            <div class="small muted">出勤精度</div>
                            <div class="big <?= $wbssSuccessRate >= 80 ? 'kpi-good' : ($wbssSuccessRate >= 60 ? 'kpi-warning' : 'kpi-danger') ?>"><?= e(number_format($wbssSuccessRate, 1)) ?>%</div>
                        </div>
                        <div>
                            <div class="small muted">other率</div>
                            <div class="big <?= e($otherRateClass) ?>"><?= e(number_format($otherRatePrimary, 1)) ?>%</div>
                        </div>
                    </div>
                </div>
                <!-- 今日の問題 -->
                <?php
                    $todayProblems = [];
                    if ($castNotFoundCount >= 3) $todayProblems[] = ['label' => 'cast_schedule_not_found', 'value' => $castNotFoundCount . '件', 'class' => 'kpi-danger'];
                    if ($otherRatePrimary >= 15) $todayProblems[] = ['label' => 'other率 高い', 'value' => number_format($otherRatePrimary, 1) . '%', 'class' => 'kpi-warning'];
                    if (count($dropoffDetails) >= 5) $todayProblems[] = ['label' => '離脱候補', 'value' => count($dropoffDetails) . '件', 'class' => 'kpi-warning'];
                    if ($wbssErrorCount24h > 0) $todayProblems[] = ['label' => 'WBSSエラー', 'value' => $wbssErrorCount24h . '件', 'class' => 'kpi-danger'];
                ?>
                <div class="card" style="margin-bottom:12px;">
                    <span class="label" style="font-size:12px;letter-spacing:.05em;">🔴 今日の問題</span>
                    <?php if (!$todayProblems): ?>
                        <div style="margin-top:8px;color:var(--green);font-size:13px;">✅ 現在、顕著な問題は検出されていません。</div>
                    <?php else: ?>
                        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;">
                        <?php foreach ($todayProblems as $p): ?>
                            <div style="background:var(--panel-soft);border:1px solid var(--line);border-radius:8px;padding:8px 14px;min-width:120px;">
                                <div style="font-size:11px;color:var(--muted);"><?= e($p['label']) ?></div>
                                <div style="font-size:20px;font-weight:700;" class="<?= e($p['class']) ?>"><?= e($p['value']) ?></div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- 今日の成果 -->
                <?php
                    $todayGood = [];
                    if ($lineCtr >= 10) $todayGood[] = 'LINE CTR ' . number_format($lineCtr, 1) . '% ✅ 目標達成';
                    if ($wbssSuccessRate >= 80) $todayGood[] = '出勤精度 ' . number_format($wbssSuccessRate, 1) . '% ✅ 良好';
                    if ($otherRatePrimary < 10) $todayGood[] = 'other率 ' . number_format($otherRatePrimary, 1) . '% ✅ 目標内';
                ?>
                <div class="card">
                    <span class="label" style="font-size:12px;letter-spacing:.05em;">🟢 今日の成果</span>
                    <?php if (!$todayGood): ?>
                        <div style="margin-top:8px;color:var(--muted);font-size:13px;">まだ目標達成項目はありません。</div>
                    <?php else: ?>
                        <ul style="margin:8px 0 0;padding:0 0 0 16px;font-size:13px;line-height:2;">
                            <?php foreach ($todayGood as $g): ?><li style="color:var(--green);"><?= e($g) ?></li><?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </details>

        <!-- ══ 問診回答分析 ════════════════════════════════════════════ -->
        <details class="section tab-panel sub-tab-hidden" data-panel="conversation" data-conv-sub="interview" id="interview-stats" open>
            <summary>
                <span class="section-title">問診回答分析</span>
                <span class="section-summary-meta">応募者属性の分布 / crew_applicants から集計</span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <?php if (empty($interviewStats['experience']) && empty($interviewStats['grade'])): ?>
                    <p class="empty" style="padding:1rem">問診完了データがまだありません。</p>
                <?php else: ?>
                <?php
                $distSections = [
                    'experience'  => '経験',
                    'grade'       => '採用優先度（Grade）',
                    'days'        => '出勤希望日数',
                    'alcohol'     => '飲酒可否',
                    'bring_trial' => '体験日 呼客',
                    'bring_now'   => '今の呼客力',
                    'referrals'   => '指名実績',
                ];
                foreach ($distSections as $key => $sectionTitle):
                    $rows = $interviewStats[$key] ?? [];
                    if (empty($rows)) continue;
                    $maxCnt = max(array_column($rows, 'count'));
                ?>
                <div style="margin-bottom:1.4rem">
                    <div style="font-size:0.8rem;font-weight:700;color:var(--muted);letter-spacing:0.06em;margin-bottom:0.5rem"><?= e($sectionTitle) ?></div>
                    <?php foreach ($rows as $r): ?>
                        <?php $w = $maxCnt > 0 ? max(8, round($r['count'] / $maxCnt * 100)) : 0; ?>
                        <div style="display:flex;align-items:center;gap:0.6rem;margin-bottom:0.35rem">
                            <div style="width:7rem;font-size:0.82rem;color:var(--text);text-align:right;flex-shrink:0"><?= e($r['label']) ?></div>
                            <div style="flex:1;background:rgba(215,180,106,0.08);border-radius:4px;overflow:hidden">
                                <div style="height:1.2rem;width:<?= e((string) $w) ?>%;background:rgba(215,180,106,0.42);border-radius:4px;transition:width 0.3s"></div>
                            </div>
                            <div style="width:2.5rem;font-size:0.82rem;color:var(--gold-bright);font-variant-numeric:tabular-nums;flex-shrink:0"><?= e((string) $r['count']) ?>件</div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </details>
        <!-- ════════════════════════════════════════════════════════════ -->

        <details class="section tab-panel" data-panel="conversation" data-conv-sub="ranking" id="question-ranking" open>
            <summary>
                <span class="section-title">質問ランキング</span>
                <span class="section-summary-meta"><strong>Ranking</strong><span>本当に聞かれる質問 / <?= e($questionWindowLabel) ?></span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <div class="period-switcher">
                    <span class="switch-label">期間</span>
                    <?php foreach (twin_question_ranking_window_options() as $key => $option): ?>
                        <?php $isActive = ($key === ($_GET['question_window'] ?? '24h')); ?>
                        <a class="<?= $isActive ? 'is-active' : '' ?>" href="/crew-onboarding/admin.php?question_window=<?= e($key) ?>#question-ranking"><?= e((string) $option['label']) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php if ($questionWindowSince === null && $questionWindowDays === null): ?>
                    <div class="muted" style="font-size:12px;margin-bottom:8px;">⚠️ 全期間表示中: 修正前ログを含む場合、代表質問が現在の分類と一致しないことがあります。「v0.8.6以降」フィルタの利用を推奨します。</div>
                <?php elseif ($questionWindowSince !== null): ?>
                    <div class="muted" style="font-size:12px;margin-bottom:8px;">v0.8.6以降（<?= e($questionWindowSince) ?>〜）のログを表示中。intent精度修正後のデータです。</div>
                <?php endif; ?>
                <div class="section-grid">
                    <div class="subgrid">
                        <div class="card">
                            <span class="label">本当に聞かれる質問 TOP5</span>
                            <?php if (!$realQuestionRanking): ?>
                                <div class="empty">まだデータがありません。</div>
                            <?php else: ?>
                                <div style="display:flex;flex-direction:column;gap:8px;margin-top:10px;">
                                <?php foreach (array_slice($realQuestionRanking, 0, 5) as $index => $row): ?>
                                    <?php
                                        $ex1 = (string) ($row['examples'][0] ?? '');
                                        $act1 = (string) ($row['suggestions'][0] ?? '');
                                        $priorityClass = twin_question_ranking_priority_class((string) $row['priority']);
                                    ?>
                                    <div style="display:grid;grid-template-columns:28px 1fr auto;gap:8px;align-items:center;background:var(--panel-soft);border-radius:8px;padding:10px 12px;border:1px solid var(--line);">
                                        <span style="font-size:18px;font-weight:700;color:var(--accent);text-align:center;"><?= e((string) ($index + 1)) ?></span>
                                        <div>
                                            <div style="font-weight:600;font-size:14px;"><?= e((string) $row['label']) ?></div>
                                            <?php if ($ex1 !== ''): ?><div style="font-size:12px;color:var(--muted);margin-top:2px;">「<?= e($ex1) ?>」</div><?php endif; ?>
                                            <?php if ($act1 !== ''): ?><div style="font-size:11px;color:var(--orange);margin-top:4px;">→ <?= e($act1) ?></div><?php endif; ?>
                                        </div>
                                        <div style="text-align:right;white-space:nowrap;">
                                            <div style="font-size:15px;font-weight:700;"><?= e((string) $row['count']) ?></div>
                                            <div style="font-size:11px;color:var(--muted);"><?= e(number_format((float) $row['ratio'], 1)) ?>%</div>
                                            <span class="pill <?= e($priorityClass) ?>" style="font-size:10px;margin-top:3px;display:block;"><?= e((string) $row['priority_label']) ?></span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                                <!-- 詳細: コンパクトテーブル -->
                                <?php $rest = array_slice($realQuestionRanking, 5); ?>
                                <?php if ($rest): ?>
                                <details style="margin-top:10px;" open>
                                    <summary style="font-size:12px;color:var(--muted);cursor:pointer;padding:4px 0;">▼ 詳細 (6位以降 <?= count($rest) ?>件)</summary>
                                    <div class="table-scroll" style="margin-top:8px;">
                                        <table>
                                            <thead><tr><th>#</th><th>分類</th><th>件数</th><th>割合</th><th>代表質問</th><th>優先度</th></tr></thead>
                                            <tbody>
                                            <?php foreach ($rest as $idx => $row): ?>
                                                <?php $priorityClass = twin_question_ranking_priority_class((string) $row['priority']); ?>
                                                <tr>
                                                    <td><?= e((string) ($idx + 6)) ?></td>
                                                    <td><?= e((string) $row['label']) ?></td>
                                                    <td><?= e((string) $row['count']) ?></td>
                                                    <td><?= e(number_format((float) $row['ratio'], 1)) ?>%</td>
                                                    <td class="message" style="font-size:11px;"><?= e((string) ($row['examples'][0] ?? '')) ?></td>
                                                    <td><span class="pill <?= e($priorityClass) ?>"><?= e((string) $row['priority_label']) ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </details>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </details>

        <!-- 出勤分析サブタブ nav -->
        <div class="tab-panel sub-tab-nav-wrap" data-panel="wbss">
            <div class="sub-tabs" id="wbss-sub-tabs" role="tablist" aria-label="出勤分析サブタブ">
                <button class="sub-tab-btn is-active" data-wbss-sub="summary" role="tab" aria-selected="true" type="button">サマリー</button>
                <button class="sub-tab-btn" data-wbss-sub="stats" role="tab" aria-selected="false" type="button">成功失敗</button>
                <button class="sub-tab-btn" data-wbss-sub="notfound" role="tab" aria-selected="false" type="button">抽出エラー分析</button>
                <button class="sub-tab-btn" data-wbss-sub="exclude" role="tab" aria-selected="false" type="button">除外語候補</button>
                <button class="sub-tab-btn" data-wbss-sub="alias" role="tab" aria-selected="false" type="button">alias候補</button>
                <button class="sub-tab-btn" data-wbss-sub="detail" role="tab" aria-selected="false" type="button">詳細ログ</button>
            </div>
        </div>

        <!-- 出勤分析: サマリー -->
        <details class="section tab-panel" data-panel="wbss" data-wbss-sub="summary" id="wbss-summary" open>
            <summary>
                <span class="section-title">出勤連携 サマリー</span>
                <span class="section-summary-meta"><strong>出勤連携</strong><span>出勤情報の確認状況</span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <div class="overview-line" style="margin-bottom: 12px;">
                    <div>
                        <div class="small muted">接続状態</div>
                        <div class="big"><?= e($wbssStatusLabel) ?></div>
                    </div>
                    <div>
                        <div class="small muted">呼び出し数</div>
                        <div class="big"><?= e((string) $wbssTotal) ?></div>
                    </div>
                    <div>
                        <div class="small muted">成功率</div>
                        <div class="big"><?= e(number_format($wbssSuccessRate, 1)) ?>%</div>
                    </div>
                    <div>
                        <div class="small muted">平均レスポンス</div>
                        <div class="big"><?= e((string) $wbssAvgResponseMs) ?>ms</div>
                    </div>
                    <div>
                        <div class="small muted">エラー（直近24h）</div>
                        <div class="big <?= $wbssErrorCount24h > 0 ? 'kpi-danger' : 'kpi-good' ?>"><?= e((string) $wbssErrorCount24h) ?></div>
                    </div>
                    <div>
                        <div class="small muted">エラー（全期間）</div>
                        <div class="big"><?= e((string) $wbssErrorCount) ?></div>
                    </div>
                </div>
                <div class="section-note" style="margin-bottom: 12px;">
                    ※改善提案は直近24時間のエラーのみで判定します。全期間の値には初期設定・開発テスト時の古いエラーが含まれる場合があります（直近7日: <?= e((string) $wbssErrorCount7d) ?>件）。
                </div>
                <?php
                    $wbssRelatedSuggestions = array_filter($improvementSuggestions ?? [], function($item) {
                        return (isset($item['link']) && strpos((string)$item['link'], '#wbss') !== false)
                            || (isset($item['priority']) && $item['priority'] === 'high');
                    });
                    $wbssRelatedSuggestions = array_slice(array_values($wbssRelatedSuggestions), 0, 2);
                ?>
                <?php if ($wbssRelatedSuggestions): ?>
                    <div class="action-grid" style="margin-top:8px;">
                        <?php foreach ($wbssRelatedSuggestions as $item): ?>
                            <div class="action-card <?= e('priority-' . ($item['priority'] ?? 'low')) ?>">
                                <div class="ops-badge">優先度 <?= e(twin_question_ranking_priority_label((string) $item['priority'])) ?></div>
                                <h3><?= e((string) $item['title']) ?></h3>
                                <div class="meta-line"><strong>推奨アクション:</strong> <?= e((string) $item['action']) ?></div>
                                <a class="link" href="<?= e((string) $item['link']) ?>">関連セクションへ</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </details>

        <!-- 出勤分析: 成功失敗 -->
        <details class="section tab-panel" data-panel="wbss" data-wbss-sub="stats" id="wbss-stats" open>
            <summary>
                <span class="section-title">出勤連携 成功失敗</span>
                <span class="section-summary-meta"><strong>成功失敗</strong><span>出勤連携集計バーチャート</span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <div class="card">
                    <span class="label">出勤連携 集計</span>
                    <?php if (!$wbssCalls): ?>
                        <div class="empty">まだ出勤連携APIの呼び出しがありません。</div>
                    <?php else: ?>
                        <?php foreach ($wbssCalls as $row): ?>
                            <?php
                                $key = (string) $row['key'];
                                $count = (int) $row['count'];
                                $barWidth = twin_bar_width($count, max(1, $wbssTotal));
                            ?>
                            <div class="bar-row">
                                <div class="pill <?= e(twin_wbss_call_class($key)) ?>"><?= e(twin_wbss_call_label($key)) ?></div>
                                <div class="bar-track">
                                    <div class="bar-fill" style="width: <?= e($barWidth) ?>;"></div>
                                </div>
                                <div class="bar-value"><?= e((string) $count) ?></div>
                            </div>
                            <div class="muted" style="margin-top: -4px; margin-bottom: 8px; font-size: 12px;"><?= e($key) ?></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </details>

        <!-- 出勤分析: キャスト名抽出エラー分析 -->
        <details class="section tab-panel" data-panel="wbss" data-wbss-sub="notfound" id="wbss-notfound" open>
            <summary>
                <span class="section-title">キャスト名抽出エラー分析</span>
                <span class="section-summary-meta"><strong>抽出エラー</strong><span>WBSS未一致の原因分類</span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <?php if (!$castNotFoundRanking): ?>
                    <div class="empty">キャスト名未一致データはありません。</div>
                <?php else: ?>
                    <?php
                        $byCategory = ['misextract' => [], 'exclude' => [], 'alias' => [], 'alias_registered' => [], 'pending' => []];
                        foreach ($castNotFoundRanking as $r) {
                            $cat = $r['category'] ?? 'pending';
                            if (!isset($byCategory[$cat])) $cat = 'pending';
                            $byCategory[$cat][] = $r;
                        }
                        $catMeta = [
                            'misextract'       => ['label' => '誤抽出候補', 'color' => 'kpi-danger',  'desc' => '質問文・長文・数字混じり。除外語または抽出ロジックで対応。'],
                            'exclude'          => ['label' => '除外語候補', 'color' => 'kpi-warning', 'desc' => 'キャスト名でない語。app/knowledge/seika.php の excludeWords に追加。'],
                            'alias'            => ['label' => 'alias候補',  'color' => '',            'desc' => '人名らしい表記。app/knowledge/cast_aliases.php に正規名を登録。'],
                            'alias_registered' => ['label' => 'alias登録済み', 'color' => 'kpi-good', 'desc' => 'aliasは登録済みだがWBSSで未一致。キャスト在籍状況を確認。'],
                            'pending'          => ['label' => '保留',       'color' => '',            'desc' => '判断できない。手動で確認してください。'],
                        ];
                    ?>
                    <?php foreach ($byCategory as $cat => $rows): ?>
                        <?php if (!$rows) continue; ?>
                        <?php $meta = $catMeta[$cat]; ?>
                        <div style="margin-bottom:16px;">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <span class="pill <?= e($meta['color']) ?>"><?= e($meta['label']) ?></span>
                                <span style="font-size:11px;color:var(--muted);"><?= e($meta['desc']) ?></span>
                            </div>
                            <div class="table-scroll">
                                <table>
                                    <thead>
                                        <tr><th>抽出語</th><th>件数</th><th>代表発言</th><th>推奨対応</th><th>登録先</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($rows as $row): ?>
                                            <tr>
                                                <td><strong><?= e((string) $row['name']) ?></strong></td>
                                                <td><?= e((string) $row['count']) ?>件</td>
                                                <td class="message">
                                                    <?php foreach (array_slice($row['samples'], 0, 2) as $s): ?>
                                                        <div style="font-size:11px;">「<?= e((string) $s) ?>」</div>
                                                    <?php endforeach; ?>
                                                </td>
                                                <td class="message" style="font-size:12px;"><?= e((string) $row['action']) ?></td>
                                                <td style="font-size:11px;color:var(--muted);"><code><?= e((string) $row['registration_hint']) ?></code></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </details>

        <!-- 出勤分析: 除外語候補 -->
        <details class="section tab-panel" data-panel="wbss" data-wbss-sub="exclude" id="wbss-exclude" open>
            <summary>
                <span class="section-title">除外語候補</span>
                <span class="section-summary-meta"><strong>exclude</strong><span>キャスト名以外の除外推奨ワード</span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <?php
                    $excludeCandidates = array_values(array_filter($castNotFoundRanking ?? [], function($r) { return !empty($r['is_exclude_candidate']); }));
                ?>
                <?php if (!$excludeCandidates): ?>
                    <div class="empty">除外語候補はありません。</div>
                <?php else: ?>
                    <div class="muted" style="font-size:12px;margin-bottom:8px;">
                        システムがキャスト名以外と判定したワード。知識ベースに除外登録することで精度が向上します。<br>
                        推奨登録先: <code>app/knowledge/cast_exclude_words.php</code>（または類似ファイル）
                    </div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>候補ワード</th>
                                    <th>件数</th>
                                    <th>代表発言</th>
                                    <th>判定理由</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($excludeCandidates as $row): ?>
                                    <tr>
                                        <td><strong><?= e((string) $row['name']) ?></strong></td>
                                        <td><?= e((string) $row['count']) ?>件</td>
                                        <td class="message">
                                            <?php foreach (array_slice($row['samples'], 0, 3) as $s): ?>
                                                <div>「<?= e((string) $s) ?>」</div>
                                            <?php endforeach; ?>
                                        </td>
                                        <td class="muted" style="font-size:12px;"><?= e((string) ($row['reason'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </details>

        <!-- 出勤分析: alias候補 -->
        <details class="section tab-panel" data-panel="wbss" data-wbss-sub="alias" id="wbss-alias" open>
            <summary>
                <span class="section-title">alias候補</span>
                <span class="section-summary-meta"><strong>alias</strong><span>alias登録推奨キャスト名一覧</span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <?php
                    $aliasCandidates = array_values(array_filter($castNotFoundRanking ?? [], function($r) { return !empty($r['is_alias_candidate']); }));
                ?>
                <?php if (!$aliasCandidates): ?>
                    <div class="empty">alias候補はありません。</div>
                <?php else: ?>
                    <div class="muted" style="font-size:12px;margin-bottom:8px;">
                        推奨登録先: <code>app/knowledge/cast_aliases.php</code>
                    </div>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>候補</th>
                                    <th>件数</th>
                                    <th>代表発言</th>
                                    <th>推奨登録先</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($aliasCandidates as $row): ?>
                                    <tr>
                                        <td><strong><?= e((string) $row['name']) ?></strong></td>
                                        <td><?= e((string) $row['count']) ?>件</td>
                                        <td class="message">
                                            <?php foreach ($row['samples'] as $s): ?>
                                                <div>「<?= e((string) $s) ?>」</div>
                                            <?php endforeach; ?>
                                        </td>
                                        <td class="muted" style="font-size:12px;"><code>app/knowledge/cast_aliases.php</code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </details>

        <!-- 出勤分析: 詳細ログ -->
        <details class="section tab-panel" data-panel="wbss" data-wbss-sub="detail" id="wbss-detail" open>
            <summary>
                <span class="section-title">詳細ログ</span>
                <span class="section-summary-meta"><strong>詳細</strong><span>出勤連携 直近20件</span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <div class="card">
                    <span class="label">出勤連携 直近20件</span>
                    <?php if (!$wbssRecentRows): ?>
                        <div class="empty">まだ出勤連携APIの呼び出しがありません。</div>
                    <?php else: ?>
                        <div class="table-scroll" style="margin-top:8px;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>日時</th>
                                        <th>結果</th>
                                        <th>詳細</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($wbssRecentRows as $row): ?>
                                        <?php
                                            $value = (string) ($row['event_value'] ?? '');
                                            $statusKey = strpos($value, ':') !== false ? explode(':', $value, 2)[0] : $value;
                                            $detail = strpos($value, ':') !== false ? explode(':', $value, 2)[1] : '';
                                        ?>
                                        <tr>
                                            <td style="white-space:nowrap;"><?= e((string) $row['created_at']) ?></td>
                                            <td><div class="pill <?= e(twin_wbss_call_class($statusKey)) ?>"><?= e(twin_wbss_call_label($statusKey)) ?></div></td>
                                            <td class="message"><?= e(trim($detail)) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </details>

        <div class="tab-panel sub-tab-nav-wrap" data-panel="conversion">
            <div class="sub-tabs" id="convrs-sub-tabs" role="tablist" aria-label="応募導線分析サブタブ">
                <button class="sub-tab-btn is-active" data-convrs-sub="cta" role="tab" aria-selected="true" type="button">CTA概要</button>
                <button class="sub-tab-btn" data-convrs-sub="line-lead" role="tab" aria-selected="false" type="button">LINE直前分析</button>
                <button class="sub-tab-btn" data-convrs-sub="line-opp" role="tab" aria-selected="false" type="button">LINE誘導候補</button>
                <button class="sub-tab-btn" data-convrs-sub="cast" role="tab" aria-selected="false" type="button">応募者評価</button>
                <button class="sub-tab-btn" data-convrs-sub="quality" role="tab" aria-selected="false" type="button">会話品質</button>
            </div>
        </div>

        <details class="section tab-panel" data-panel="conversion" data-convrs-sub="cta" id="conversation-summary" open>
            <summary>
                <span class="section-title">会話サマリー</span>
                <span class="section-summary-meta"><strong>Analytics</strong><span>応答方式と会話状況</span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <div class="section-grid">
                    <div class="subgrid">
                        <div class="card">
                            <span class="label">応答方式 分布</span>
                            <?php if ($responseModeTotal === 0): ?>
                                <div class="empty">まだ応答モードの記録がありません。</div>
                            <?php else: ?>
                                <?php foreach (['rule', 'openai', 'fallback_rule'] as $mode): ?>
                                    <?php
                                        $count = (int) $responseModes[$mode];
                                        $pct = $responseModeTotal > 0 ? ($count / $responseModeTotal) * 100 : 0;
                                        $barClass = $mode === 'openai' ? 'blue' : ($mode === 'fallback_rule' ? 'orange' : '');
                                    ?>
                                    <div class="bar-row">
                                        <div class="pill<?= $barClass ? ' ' . $barClass : '' ?>"><?= e(twin_response_mode_label($mode)) ?></div>
                                        <div class="bar-track">
                                            <div class="bar-fill<?= $barClass ? ' ' . $barClass : '' ?>" style="width: <?= e(sprintf('%.1f%%', $pct)) ?>;"></div>
                                        </div>
                                        <div class="bar-value"><?= e((string) round($pct, 1)) ?>%</div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="card">
                            <span class="label">会話コンテキスト</span>
                            <div class="overview-line" style="margin-bottom: 12px;">
                                <div>
                                    <div class="small muted">総件数</div>
                                    <div class="big"><?= e((string) $contextTotal) ?></div>
                                </div>
                                <div>
                                    <div class="small muted">直近20件</div>
                                    <div class="big"><?= e((string) count($contextRecentRows)) ?></div>
                                </div>
                            </div>
                            <?php if (!$contextMap): ?>
                                <div class="empty">まだ会話コンテキストの記録がありません。</div>
                            <?php else: ?>
                                <?php foreach ($contextMap as $row): ?>
                                    <?php
                                        $key = (string) $row['key'];
                                        $count = (int) $row['count'];
                                        $barWidth = twin_bar_width($count, max(1, $contextTotal));
                                    ?>
                                    <div class="bar-row">
                                        <div class="pill"><?= e($key) ?></div>
                                        <div class="bar-track">
                                            <div class="bar-fill blue" style="width: <?= e($barWidth) ?>;"></div>
                                        </div>
                                        <div class="bar-value"><?= e((string) $count) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <?php if ($contextRecentRows): ?>
                                <div class="table-scroll" style="margin-top: 12px;">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>日時</th>
                                                <th>状態</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($contextRecentRows as $row): ?>
                                                <tr>
                                                    <td><?= e((string) $row['created_at']) ?></td>
                                                    <td><?= e((string) $row['event_value']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="card">
                            <span class="label">会話メモ</span>
                            <div class="metric">
                                <div>
                                    <div class="small">平均会話数はユーザー発言ベースで集計しています。</div>
                                    <div class="small">平均滞在時間は終了済みセッションの開始から終了までです。</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="subgrid">
                        <details class="card section-card" id="cta">
                            <summary class="section-summary" style="padding: 0; border: 0;">
                                <span class="section-title" style="font-size: 16px;">応募導線分析</span>
                                <span class="section-summary-meta"><span>表示数 / クリック数 / CTR</span></span>
                                <span class="section-caret">›</span>
                            </summary>
                            <div class="section-body" style="padding: 12px 0 0;">
                                <?php foreach ($ctaMap as $key => $item): ?>
                                    <?php
                                        $view = (int) $item['view'];
                                        $click = (int) $item['click'];
                                        $ctr = $view > 0 ? ($click / $view) * 100 : 0;
                                        $barClass = in_array($key, ['line', 'fixed_line'], true) ? 'green' : (in_array($key, ['price', 'fixed_price'], true) ? 'orange' : 'blue');
                                    ?>
                                    <div class="bar-row">
                                        <div class="pill<?= ' ' . $barClass ?>"><?= e($item['label']) ?></div>
                                        <div class="bar-track">
                                            <div class="bar-fill<?= ' ' . $barClass ?>" style="width: <?= e(sprintf('%.1f%%', min(100, $ctr))) ?>;"></div>
                                        </div>
                                        <div class="bar-value"><?= e(number_format($ctr, 1)) ?>%</div>
                                    </div>
                                    <div class="overview-line" style="margin-bottom: 12px;">
                                        <div>
                                            <div class="small muted">表示</div>
                                            <div class="big"><?= e((string) $view) ?></div>
                                        </div>
                                        <div>
                                            <div class="small muted">クリック</div>
                                            <div class="big"><?= e((string) $click) ?></div>
                                        </div>
                                        <div>
                                            <div class="small muted">CTR</div>
                                            <div class="big"><?= e(number_format($ctr, 1)) ?>%</div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>

                                <!-- v0.6.9: LINEクリック前intent分析 -->
                                <div style="margin-top: 16px; border-top: 1px solid var(--line); padding-top: 14px;">
                                    <span class="label">LINEクリック前の質問傾向（直前のユーザー発言）</span>
                                    <?php if (!$linePreClickIntents): ?>
                                        <div class="empty">まだLINEクリックデータがありません。</div>
                                    <?php else: ?>
                                        <?php
                                            $maxPreClickCount = max(array_column($linePreClickIntents, 'cnt'));
                                        ?>
                                        <?php foreach ($linePreClickIntents as $row): ?>
                                            <?php
                                                $intentKey = (string) ($row['intent'] ?? 'other');
                                                if ($intentKey === '' || $intentKey === null) $intentKey = 'other';
                                                $cnt = (int) $row['cnt'];
                                                $bw = twin_bar_width($cnt, max(1, $maxPreClickCount));
                                            ?>
                                            <div class="bar-row">
                                                <div class="pill green"><?= e(twin_intent_label($intentKey)) ?></div>
                                                <div class="bar-track">
                                                    <div class="bar-fill green" style="width: <?= e($bw) ?>;"></div>
                                                </div>
                                                <div class="bar-value"><?= e((string) $cnt) ?></div>
                                            </div>
                                        <?php endforeach; ?>
                                        <div class="muted" style="font-size:12px;margin-top:8px;">LINEクリック直前にユーザーが送ったintent。この流れを強化すると応募導線が改善されます。</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </details>
                    </div>
                </div>
            </div>
        </details>

        <details class="section tab-panel" data-panel="conversion" data-convrs-sub="cast" id="recommend-cast" open>
            <summary>
                <span class="section-title">応募者評価</span>
                <span class="section-summary-meta"><strong>v0.7</strong><span>推薦実行・LINE誘導</span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <div class="section-grid">
                    <div class="subgrid">
                        <div class="card">
                            <div class="kpi-row">
                                <div class="kpi-item">
                                    <span class="kpi-num"><?= e((string) $recommendCastCount) ?></span>
                                    <span class="label">推薦実行回数</span>
                                </div>
                                <div class="kpi-item">
                                    <span class="kpi-num"><?= e((string) $recommendCastLineClickCount) ?></span>
                                    <span class="label">推薦後LINEクリック数</span>
                                </div>
                                <div class="kpi-item">
                                    <span class="kpi-num"><?= e((string) $recommendCastCtr) ?>%</span>
                                    <span class="label">推薦後CTR</span>
                                </div>
                            </div>
                            <p class="muted" style="font-size:12px;margin-top:8px;">推薦後CTR = 推薦後にLINEクリックしたセッション数 ÷ 推薦実行回数。データがない場合は0件表示。</p>
                        </div>
                    </div>
                </div>
            </div>
        </details>

        <!-- LINE直前分析 -->
        <details class="section tab-panel" data-panel="conversion" data-convrs-sub="line-lead" id="line-lead-panel" open>
            <summary>
                <span class="section-title">LINE直前分析</span>
                <span class="section-summary-meta"><strong>LINE直前</strong><span>LINEクリック直前の質問傾向</span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <div class="notice" style="margin-bottom: 12px;">どのintentの後にLINE誘導を強めるべきか分かります。</div>
                <div class="card">
                    <span class="label">LINEクリック前の質問傾向（直前のユーザー発言）</span>
                    <?php if (!$linePreClickIntents): ?>
                        <div class="empty">まだLINEクリックデータがありません。</div>
                    <?php else: ?>
                        <?php
                            $maxPreClickCount2 = max(array_column($linePreClickIntents, 'cnt'));
                        ?>
                        <?php foreach ($linePreClickIntents as $row): ?>
                            <?php
                                $intentKey2 = (string) ($row['intent'] ?? 'other');
                                if ($intentKey2 === '' || $intentKey2 === null) $intentKey2 = 'other';
                                $cnt2 = (int) $row['cnt'];
                                $bw2 = twin_bar_width($cnt2, max(1, $maxPreClickCount2));
                            ?>
                            <div class="bar-row">
                                <div class="pill green"><?= e(twin_intent_label($intentKey2)) ?></div>
                                <div class="bar-track">
                                    <div class="bar-fill green" style="width: <?= e($bw2) ?>;"></div>
                                </div>
                                <div class="bar-value"><?= e((string) $cnt2) ?></div>
                            </div>
                        <?php endforeach; ?>
                        <div class="muted" style="font-size:12px;margin-top:8px;">LINEクリック直前にユーザーが送ったintent。この流れを強化すると応募導線が改善されます。</div>
                    <?php endif; ?>
                </div>
            </div>
        </details>

        <!-- LINE誘導候補 -->
        <details class="section tab-panel" data-panel="conversion" data-convrs-sub="line-opp" id="line-opp-panel" open>
            <summary>
                <span class="section-title">LINE誘導候補</span>
                <span class="section-summary-meta"><strong>LINE誘導候補</strong><span>ヒアリング完了後にLINEクリックなし</span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <div class="notice" style="margin-bottom: 12px;">この会話ではLINE誘導を強めてもよい可能性があります。</div>
                <?php if (!$lineOpportunityDetails): ?>
                    <div class="empty">LINE誘導候補セッションはありません。</div>
                <?php else: ?>
                    <div class="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>セッション番号</th>
                                    <th>含まれる質問分類</th>
                                    <th>最後のユーザー発言</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($lineOpportunityDetails as $row): ?>
                                    <tr>
                                        <td><?= e((string) $row['session_id']) ?></td>
                                        <td>
                                            <?php foreach ($row['intents'] as $intent): ?>
                                                <span class="pill blue" style="margin: 2px;"><?= e(twin_intent_label($intent)) ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                        <td class="message"><?= e($row['last_message']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </details>

        <details class="section tab-panel" data-panel="conversation" data-conv-sub="ranking recent" id="intent" open>
            <summary>
                <span class="section-title">質問傾向分析</span>
                <span class="section-summary-meta"><strong>質問傾向</strong><span>応募者が実際に知りたがっていることを分析します</span></span>
                <span class="section-caret">›</span>
            </summary>
                <div class="section-body">
                <div class="section-grid">
                    <div class="subgrid">
                        <div class="card">
                            <span class="label">質問傾向 TOP10</span>
                            <?php if (!$intentTop10): ?>
                                <div class="empty">まだデータがありません。</div>
                            <?php else: ?>
                                <?php foreach ($intentTop10 as $index => $row): ?>
                                    <?php
                                        $count = (int) $row['count'];
                                        $pct = $overview['messages'] > 0 ? ($count / $overview['messages']) * 100 : 0;
                                        $barWidth = twin_bar_width($count, $maxIntentCount);
                                    ?>
                                    <div class="question-item">
                                        <div class="rank"><?= e((string) ($index + 1)) ?></div>
                                        <div>
                                            <div class="question-text"><?= e(twin_intent_label((string) $row['key'])) ?></div>
                                            <div class="bar-track" style="margin-top: 8px;">
                                                <div class="bar-fill" style="width: <?= e($barWidth) ?>;"></div>
                                            </div>
                                        </div>
                                        <div class="bar-value"><?= e(number_format($pct, 1)) ?>%</div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="subgrid">
                        <div class="card">
                            <span class="label">よく聞かれる内容</span>
                            <?php if (!$questionRows): ?>
                                <div class="empty">まだデータがありません。</div>
                            <?php else: ?>
                                <?php foreach ($questionRows as $index => $row): ?>
                                    <?php
                                        $intentKey = (string) $row['intent_key'];
                                        $count = (int) $row['cnt'];
                                    ?>
                                    <div class="question-item">
                                        <div class="rank"><?= e((string) ($index + 1)) ?></div>
                                        <div class="question-text"><?= e(twin_intent_label($intentKey)) ?></div>
                                        <div class="bar-value"><?= e((string) $count) ?>回</div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="card">
                            <span class="label">最近の重要会話</span>

                            <!-- 離脱候補会話 -->
                            <div style="margin-top:12px;">
                                <div style="font-size:12px;font-weight:600;color:var(--orange);margin-bottom:6px;">⚠️ 離脱候補 (<?= count($dropoffDetails) ?>件)</div>
                                <?php if (!$dropoffDetails): ?>
                                    <div class="empty" style="font-size:12px;">離脱候補はありません。</div>
                                <?php else: ?>
                                    <div style="display:flex;flex-direction:column;gap:6px;">
                                    <?php foreach (array_slice($dropoffDetails, 0, 5) as $row): ?>
                                        <div style="background:var(--panel-soft);border-radius:8px;padding:8px 12px;border:1px solid var(--line);border-left:3px solid var(--orange);">
                                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:3px;">
                                                <span style="font-size:11px;color:var(--muted);"><?= e($row['started_at']) ?></span>
                                                <span class="pill" style="font-size:10px;"><?= e(twin_intent_label($row['last_intent'])) ?></span>
                                                <span style="font-size:11px;color:var(--muted);"><?= e((string) $row['user_msg_count']) ?>発言で離脱</span>
                                            </div>
                                            <div style="font-size:12px;word-break:break-word;">「<?= e($row['last_message']) ?>」</div>
                                        </div>
                                    <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- 応募直前会話（LINE誘導候補） -->
                            <div style="margin-top:14px;">
                                <div style="font-size:12px;font-weight:600;color:var(--blue);margin-bottom:6px;">💡 応募直前会話 (<?= count($lineOpportunityDetails) ?>件) — LINEクリックせずに終了</div>
                                <?php if (!$lineOpportunityDetails): ?>
                                    <div class="empty" style="font-size:12px;">応募直前会話はありません。</div>
                                <?php else: ?>
                                    <div style="display:flex;flex-direction:column;gap:6px;">
                                    <?php foreach (array_slice($lineOpportunityDetails, 0, 5) as $row): ?>
                                        <div style="background:var(--panel-soft);border-radius:8px;padding:8px 12px;border:1px solid var(--line);border-left:3px solid var(--blue);">
                                            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:3px;">
                                                <span style="font-size:11px;color:var(--muted);"><?= e($row['started_at']) ?></span>
                                                <?php foreach (array_slice($row['intents'], 0, 3) as $intent): ?>
                                                    <span class="pill" style="font-size:10px;"><?= e(twin_intent_label($intent)) ?></span>
                                                <?php endforeach; ?>
                                            </div>
                                            <div style="font-size:12px;word-break:break-word;">「<?= e($row['last_message']) ?>」</div>
                                        </div>
                                    <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- 生ログ折りたたみ（個人情報系は低優先表示） -->
                            <details style="margin-top:14px;" open>
                                <summary style="font-size:12px;color:var(--muted);cursor:pointer;">▼ 生ログ（直近20件 / 個人情報入力は薄く表示）</summary>
                                <?php if (!$recentRows): ?>
                                    <div class="empty" style="margin-top:8px;font-size:12px;">まだユーザー質問がありません。</div>
                                <?php else: ?>
                                    <div style="display:flex;flex-direction:column;gap:5px;margin-top:8px;">
                                    <?php foreach ($recentRows as $row): ?>
                                        <?php
                                            $intentKey = (string) ($row['intent_key'] ?? 'other');
                                            $msg = (string) $row['message'];
                                            // 個人情報系メッセージ（マスク済みを含む）は分析価値低 → 薄く
                                            $isPersonal = (bool) preg_match('/\[(?:電話番号|メールアドレス|LINE ID|住所|決済情報)\]|^\d{10,11}$|@.+\./u', $msg);
                                            $isImportant = !$isPersonal && in_array($intentKey, ['attendance','cast_schedule','price','price_estimate','other'], true);
                                            $opacity = $isPersonal ? 'opacity:0.45;' : '';
                                        ?>
                                        <div style="background:var(--panel-soft);border-radius:6px;padding:7px 10px;border:1px solid var(--line);<?= $isImportant ? 'border-left:2px solid var(--accent);' : '' ?><?= $opacity ?>">
                                            <span style="font-size:10px;color:var(--muted);"><?= e((string) $row['created_at']) ?></span>
                                            <span class="pill" style="font-size:10px;margin:0 4px;"><?= e(twin_intent_label($intentKey)) ?></span>
                                            <?php if ($isPersonal): ?><span style="font-size:10px;color:var(--muted);">[個人情報]</span><?php endif; ?>
                                            <span style="font-size:12px;"><?= e($msg) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </details>
                        </div>
                    </div>
                </div>

                <!-- v0.6.8: その他の中身 -->
                <details class="section" style="margin-top: 16px;" id="other-analysis" open>
                    <summary>
                        <span class="section-title" style="font-size: 16px;">未分類の発言</span>
                        <span class="section-summary-meta"><span>直近50件・頻出ワードTOP20</span></span>
                        <span class="section-caret">›</span>
                    </summary>
                    <div class="section-body">
                        <div class="section-grid">
                            <div>
                                <span class="label">未分類の発言 直近50件</span>
                                <?php if (!$otherMessages): ?>
                                    <div class="empty">未分類の発言はありません。</div>
                                <?php else: ?>
                                    <div class="table-scroll">
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>日時</th>
                                                    <th>発言内容</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($otherMessages as $row): ?>
                                                    <tr>
                                                        <td><?= e((string) $row['created_at']) ?></td>
                                                        <td class="message"><?= e((string) $row['message']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <span class="label">頻出ワード TOP20</span>
                                <?php if (!$topWords): ?>
                                    <div class="empty">頻出ワードがありません。</div>
                                <?php else: ?>
                                    <?php $maxWordCount = max($topWords); ?>
                                    <?php $wordRank = 0; ?>
                                    <?php foreach ($topWords as $word => $count): ?>
                                        <?php $wordRank++; ?>
                                        <div class="question-item">
                                            <div class="rank"><?= e((string) $wordRank) ?></div>
                                            <div>
                                                <div class="question-text"><?= e((string) $word) ?></div>
                                                <div class="bar-track" style="margin-top: 6px;">
                                                    <div class="bar-fill blue" style="width: <?= e(twin_bar_width($count, $maxWordCount)) ?>;"></div>
                                                </div>
                                            </div>
                                            <div class="bar-value"><?= e((string) $count) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </details>

                <!-- v0.6.8: 新intent候補 -->
                <details class="section" style="margin-top: 12px;" id="suggested-intents" open>
                    <summary>
                        <span class="section-title" style="font-size: 16px;">新しい質問分類候補</span>
                        <span class="section-summary-meta"><span>other 発言から検出されたキーワード</span></span>
                        <span class="section-caret">›</span>
                    </summary>
                    <div class="section-body">
                        <?php if (!$suggestedIntents): ?>
                            <div class="empty">新しい質問分類候補が検出されませんでした。</div>
                        <?php else: ?>
                            <div class="table-scroll">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>候補分類</th>
                                            <th>件数</th>
                                            <th>該当発言例（最大5件）</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($suggestedIntents as $intentName => $data): ?>
                                            <tr>
                                                <td><span class="pill orange"><?= e($intentName) ?></span></td>
                                                <td><?= e((string) $data['count']) ?></td>
                                                <td>
                                                    <?php foreach ($data['examples'] as $ex): ?>
                                                        <div class="message" style="margin-bottom: 4px; font-size: 13px; color: var(--muted);">- <?= e($ex) ?></div>
                                                    <?php endforeach; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>
            </div>
        </details>

        <details class="section tab-panel" data-panel="conversation" data-conv-sub="improvement unclassified" style="margin-top: 16px;" id="real-question-ranking" open>
            <summary>
                <span class="section-title" style="font-size: 16px;">未分類分析</span>
                <span class="section-summary-meta"><strong><?= e($questionWindowLabel) ?></strong><span>未分類発言・頻出ワード・新intent候補</span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <div class="muted" style="font-size: 12px; margin-bottom: 10px; padding: 8px 12px; background: var(--panel); border-radius: 6px; border: 1px solid var(--line);">
                    集計期間: <strong><?= e($questionWindowLabel) ?></strong>　※過去ログには intent 修正前の誤判定が含まれる場合があります。最新のデータを確認するには「過去24時間」を選択してください。
                </div>

                <div class="card">
                        <span class="label">質問傾向ランキング詳細</span>
                    <div class="period-switcher">
                        <span class="switch-label">期間</span>
                        <?php foreach (twin_question_ranking_window_options() as $key => $option): ?>
                            <?php $isActive = ($questionWindowDays === $option['days']); ?>
                            <a class="<?= $isActive ? 'is-active' : '' ?>" href="/crew-onboarding/admin.php?question_window=<?= e($key) ?>#real-question-ranking"><?= e((string) $option['label']) ?></a>
                        <?php endforeach; ?>
                    </div>
                    <?php if (!$realQuestionRanking): ?>
                        <div class="empty">まだ本当に聞かれる質問ランキングを作れるだけのデータがありません。</div>
                    <?php else: ?>
                                <div class="table-scroll">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>順位</th>
                                                <th>質問分類</th>
                                                <th>質問分類名</th>
                                                <th>件数</th>
                                                <th>割合</th>
                                                <th>代表的な質問例</th>
                                                <th>改善提案</th>
                                                <th>優先度</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($realQuestionRanking as $index => $row): ?>
                                                <?php
                                                    $examples = $row['examples'] ?? [];
                                                    $suggestions = $row['suggestions'] ?? [];
                                                    $priorityClass = twin_question_ranking_priority_class((string) $row['priority']);
                                                ?>
                                                <tr>
                                                    <td data-label="順位"><?= e((string) ($index + 1)) ?></td>
                                                    <td data-label="質問分類"><span class="pill"><?= e((string) $row['intent']) ?></span></td>
                                                    <td data-label="質問分類名"><?= e((string) $row['label']) ?></td>
                                                    <td data-label="件数"><?= e((string) $row['count']) ?></td>
                                                    <td data-label="割合"><?= e(number_format((float) $row['ratio'], 1)) ?>%</td>
                                                    <td data-label="代表的な質問例">
                                                        <?php if (!$examples): ?>
                                                            <span class="muted">-</span>
                                                        <?php else: ?>
                                                            <ul class="ranking-examples">
                                                                <?php foreach (array_slice($examples, 0, 3) as $example): ?>
                                                                    <li><?= e((string) $example) ?></li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td data-label="改善提案">
                                                        <?php if (!$suggestions): ?>
                                                            <span class="muted">-</span>
                                                        <?php else: ?>
                                                            <ul class="ranking-examples">
                                                                <?php foreach ($suggestions as $suggestion): ?>
                                                                    <li><?= e((string) $suggestion) ?></li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td data-label="優先度"><span class="pill <?= e($priorityClass) ?>"><?= e((string) $row['priority_label']) ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </details>

        <!-- v0.6.8: 会話分析（離脱・LINE・返答改善） -->
                <details class="section tab-panel" data-panel="conversion" data-convrs-sub="quality" id="conversation-quality" open>
            <summary>
                <span class="section-title">会話品質</span>
                <span class="section-summary-meta"><strong>会話品質</strong><span>離脱・返答改善の分析</span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">

                <!-- 会話品質ネストサブタブ -->
                <div class="qual-sub-tabs" id="qual-sub-tabs" role="tablist" aria-label="会話品質サブタブ">
                    <button class="qual-sub-btn is-active" data-qual-sub="summary" role="tab" aria-selected="true" type="button">サマリー</button>
                    <button class="qual-sub-btn" data-qual-sub="dropoff" role="tab" aria-selected="false" type="button">離脱候補</button>
                    <button class="qual-sub-btn" data-qual-sub="improvement" role="tab" aria-selected="false" type="button">返答改善</button>
                </div>

                <!-- サマリー -->
                <div class="qual-sub-panel" data-qual-sub="summary">
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;padding:12px;background:var(--panel-soft);border-radius:8px;border:1px solid var(--line);">
                        <div style="text-align:center;min-width:80px;">
                            <div class="small muted">改善候補</div>
                            <div class="big <?= $improvementCount > 3 ? 'kpi-warning' : 'kpi-good' ?>"><?= e((string) $improvementCount) ?>件</div>
                        </div>
                        <div style="text-align:center;min-width:80px;">
                            <div class="small muted">離脱候補</div>
                            <div class="big <?= count($dropoffDetails) > 5 ? 'kpi-warning' : '' ?>"><?= e((string) count($dropoffDetails)) ?>件</div>
                        </div>
                    </div>
                    <?php if ($improvementCount > 0): ?>
                    <div class="card">
                        <span class="label">主な改善ポイント</span>
                        <div style="margin-top:8px;font-size:13px;color:var(--muted);">
                            返答改善タブで詳細を確認してください。重複返答・other連続などのパターンが <?= e((string) $improvementCount) ?>件 検出されています。
                        </div>
                        <button class="button" type="button" style="margin-top:8px;" onclick="(function(){var b=document.querySelector('#qual-sub-tabs [data-qual-sub=improvement]');if(b)b.click();})()">→ 返答改善を見る</button>
                    </div>
                    <?php else: ?>
                    <div class="empty">現在、改善候補はありません。</div>
                    <?php endif; ?>
                </div>

                <!-- 離脱候補サブパネル -->
                <div class="qual-sub-panel qual-sub-hidden" data-qual-sub="dropoff">
                <details class="section" style="margin-top: 0;" id="dropoff-sessions" open>
                    <summary>
                        <span class="section-title" style="font-size: 16px;">離脱候補セッション</span>
                        <span class="section-summary-meta"><span>ユーザー発言1〜2件かつ応募導線クリックなし</span></span>
                        <span class="section-caret">›</span>
                    </summary>
                    <div class="section-body">
                        <?php if (!$dropoffDetails): ?>
                            <div class="empty">離脱候補セッションはありません。</div>
                        <?php else: ?>
                            <div class="table-scroll">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>セッション番号</th>
                                            <th>開始日時</th>
                                            <th>発言数</th>
                                            <th>最後の発言</th>
                                            <th>最後の質問分類</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dropoffDetails as $row): ?>
                                            <tr>
                                                <td><?= e((string) $row['session_id']) ?></td>
                                                <td><?= e($row['started_at']) ?></td>
                                                <td><?= e((string) $row['user_msg_count']) ?></td>
                                                <td class="message"><?= e($row['last_message']) ?></td>
                                                <td><span class="pill"><?= e(twin_intent_label($row['last_intent'])) ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>

                </div><!-- /qual-sub-panel[dropoff] -->

                <!-- LINE誘導候補は line-opp-panel サブタブに移動済み -->
                <!-- 返答改善サブパネル -->
                <!-- dummy comment to preserve structure -->
                <details class="section" style="margin-top: 12px; display:none;" id="line-opportunity">
                    <summary>
                        <span class="section-title" style="font-size: 16px;">LINE誘導候補</span>
                        <span class="section-summary-meta"><span>ヒアリング完了後にLINEクリックなし</span></span>
                        <span class="section-caret">›</span>
                    </summary>
                    <div class="section-body">
                        <div class="notice" style="margin-bottom: 12px;">この会話ではLINE誘導を強めてもよい可能性があります。</div>
                        <?php if (!$lineOpportunityDetails): ?>
                            <div class="empty">LINE誘導候補セッションはありません。</div>
                        <?php else: ?>
                            <div class="table-scroll">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>セッション番号</th>
                                            <th>含まれる質問分類</th>
                                            <th>最後のユーザー発言</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($lineOpportunityDetails as $row): ?>
                                            <tr>
                                                <td><?= e((string) $row['session_id']) ?></td>
                                                <td>
                                                    <?php foreach ($row['intents'] as $intent): ?>
                                                        <span class="pill blue" style="margin: 2px;"><?= e(twin_intent_label($intent)) ?></span>
                                                    <?php endforeach; ?>
                                                </td>
                                                <td class="message"><?= e($row['last_message']) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>

                <div class="qual-sub-panel qual-sub-hidden" data-qual-sub="improvement">
                <!-- 返答改善候補 -->
                <details class="section" style="margin-top: 0;" id="response-improvements" open>
                    <summary>
                        <span class="section-title" style="font-size: 16px;">返答改善候補</span>
                        <span class="section-summary-meta"><span>重複返答・other連続などのパターン検出</span></span>
                        <span class="section-caret">›</span>
                    </summary>
                    <div class="section-body">
                        <?php if (!$replyImprovementSummary): ?>
                            <div class="empty">返答改善候補はありません。</div>
                        <?php else: ?>
                            <div class="reply-card-grid">
                                <?php foreach (array_slice($replyImprovementSummary, 0, 5) as $row): ?>
                                    <div class="reply-card">
                                        <div class="ops-badge">件数 <?= e((string) $row['count']) ?></div>
                                        <div class="reply-count"><?= e((string) $row['count']) ?>件</div>
                                        <div class="reply-label"><?= e((string) $row['type_label']) ?></div>
                                        <div class="meta-line"><strong>代表返答:</strong></div>
                                        <div class="reply-text"><?= e((string) $row['representative_reply']) ?></div>
                                        <div class="meta-line"><strong>改善メモ:</strong> <?= e((string) $row['improvement_note']) ?></div>
                                        <div class="meta-line"><strong>推奨修正:</strong> <?= e((string) $row['suggested_fix']) ?></div>
                                        <details>
                                            <summary class="section-note">詳細ログ</summary>
                                            <div class="section-note" style="margin-top: 8px;">type: <?= e((string) $row['type']) ?> / 優先度: <?= e((string) $row['priority_label']) ?></div>
                                        </details>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </details>
                </div><!-- /qual-sub-panel[improvement] -->
            </div>
        </details>

        <!-- ═══ 応募者一覧 ═══════════════════════════════════════════ -->
        <details class="section tab-panel" data-panel="applicants" id="applicants-kpi" open>
            <summary>
                <span class="section-title">KPI サマリー</span>
                <span class="section-summary-meta">問診完了率・LINEタップ率・グレード別</span>
                <span class="section-caret">▸</span>
            </summary>
            <div style="padding:1rem 0.5rem;display:flex;flex-wrap:wrap;gap:1rem;">
                <div class="kpi-card">
                    <div class="kpi-label">問診完了数</div>
                    <div class="kpi-value"><?= e((string) $applicantTotal) ?></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">LINE タップ数</div>
                    <div class="kpi-value"><?= e((string) $lineAppliedTotal) ?></div>
                </div>
                <div class="kpi-card">
                    <div class="kpi-label">LINE タップ率</div>
                    <div class="kpi-value <?= $lineAppliedRate >= 30 ? 'kpi-good' : ($lineAppliedRate >= 15 ? 'kpi-warning' : 'kpi-danger') ?>"><?= e((string) $lineAppliedRate) ?>%</div>
                </div>
            </div>
            <?php if ($gradeLineRows): ?>
            <table style="margin:0 0.5rem 1rem;width:calc(100% - 1rem)">
                <thead><tr><th>グレード</th><th>完了数</th><th>LINEタップ</th><th>タップ率</th></tr></thead>
                <tbody>
                <?php foreach ($gradeLineRows as $gr): ?>
                    <?php
                    $grTotal = (int) $gr['total'];
                    $grLined = (int) $gr['lined'];
                    $grRate  = $grTotal > 0 ? round($grLined / $grTotal * 100, 1) : 0.0;
                    ?>
                    <tr>
                        <td><strong><?= e($gr['priority_grade'] ?? '') ?></strong></td>
                        <td><?= e((string) $grTotal) ?></td>
                        <td><?= e((string) $grLined) ?></td>
                        <td><?= e((string) $grRate) ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </details>

        <details class="section tab-panel" data-panel="applicants" id="applicants-list" open>
            <summary>
                <span class="section-title">応募者一覧</span>
                <span class="section-summary-meta">直近50件 / LINEに来た人との照合用</span>
                <span class="section-caret">▸</span>
            </summary>
            <?php if ($outcomeAlertCount > 0): ?>
            <div style="display:flex;align-items:center;gap:0.5rem;margin:0.6rem 1rem 0;padding:0.5rem 0.75rem;
                        background:rgba(240,184,165,0.12);border:1px solid rgba(240,184,165,0.35);
                        border-radius:0.4rem;font-size:0.82rem;color:var(--danger)">
                <span style="font-weight:700">⚠</span>
                3か月後結果 未入力: <strong><?= $outcomeAlertCount ?>件</strong>
                <span style="color:var(--muted);font-size:0.75rem">— 採用後90日以上経過・売上ランクまたは定着状況が未記録</span>
            </div>
            <?php endif; ?>
            <?php if (empty($applicantRows)): ?>
                <p style="padding:1rem;color:var(--muted)">まだデータがありません。問診を完走すると記録されます。</p>
            <?php else: ?>
            <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>日時</th>
                        <th>経験</th>
                        <th>推定時給</th>
                        <th>Score</th>
                        <th>Grade</th>
                        <th>LINE</th>
                        <th>詳細</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($applicantRows as $row): ?>
                    <?php
                    $expLabel = match ($row['experience'] ?? '') {
                        'none' => '未経験', 'some' => '経験少', 'yes' => '経験者', default => '—'
                    };
                    $gradeClass = match ($row['priority_grade'] ?? '') {
                        'A' => 'color:#50d67a', 'B' => 'color:#f4d891',
                        'C' => 'color:#f0b8a5', 'D' => 'color:#888', default => ''
                    };
                    $lineLabel = $row['line_applied_at'] ? '✓ ' . substr((string) $row['line_applied_at'], 5, 11) : '—';
                    $detailUrl = e('/crew-onboarding/admin_applicant.php?id=' . (int) $row['id']);

                    // 3か月後結果 未入力チェック
                    $hiredAt = $row['hired_at'] ?? null;
                    $needsOutcome = $hiredAt !== null
                        && (strtotime((string) $hiredAt) <= strtotime('-90 days'))
                        && (($row['outcome_sales_rank'] ?? '') === '' || $row['outcome_sales_rank'] === null
                            || ($row['outcome_retention'] ?? '') === '' || $row['outcome_retention'] === null);
                    ?>
                    <tr<?= $needsOutcome ? ' style="background:rgba(240,184,165,0.06)"' : '' ?>>
                        <td style="white-space:nowrap;font-size:0.82rem"><?= e(substr((string) $row['created_at'], 0, 16)) ?></td>
                        <td><?= e($expLabel) ?></td>
                        <td style="font-size:0.82rem"><?= e((string) ($row['estimated_wage'] ?? '—')) ?></td>
                        <td><?= e((string) ($row['candidate_score'] ?? '—')) ?></td>
                        <td style="font-weight:700;<?= $gradeClass ?>"><?= e((string) ($row['priority_grade'] ?? '—')) ?></td>
                        <td style="font-size:0.82rem"><?= e($lineLabel) ?></td>
                        <td>
                            <?php if ($needsOutcome): ?>
                                <span style="display:inline-block;font-size:0.68rem;font-weight:700;
                                             padding:1px 5px;border-radius:3px;margin-right:4px;
                                             background:rgba(240,184,165,0.18);color:var(--danger);
                                             border:1px solid rgba(240,184,165,0.4);white-space:nowrap">
                                    3か月後 未入力
                                </span>
                            <?php endif; ?>
                            <a href="<?= $detailUrl ?>" style="color:var(--gold);font-size:0.8rem">詳細</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </details>
        <!-- ════════════════════════════════════════════════════════ -->

        <details class="section tab-panel" data-panel="system" id="logs">
            <summary>
                <span class="section-title">ログダウンロード</span>
                <span class="section-summary-meta"><strong>Export</strong><span>会話・イベント・出勤連携・応募導線</span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <div class="actions">
                    <a class="button primary" href="/crew-onboarding/admin_export.php?type=conversations">会話ログCSV</a>
                    <a class="button" href="/crew-onboarding/admin_export.php?type=events">イベントログCSV</a>
                    <a class="button" href="/crew-onboarding/admin_export.php?type=wbss">出勤連携CSV</a>
                    <a class="button" href="/crew-onboarding/admin_export.php?type=cta">応募導線CSV</a>
                </div>
            </div>
        </details>

        <!-- システム設定: サブタブ構造 -->
        <div class="section tab-panel" data-panel="system" id="system-settings-wrap" style="overflow:visible;">
            <div class="sys-tabs" role="tablist" aria-label="システム設定">
                <button type="button" class="sys-tab-btn is-active" data-sys-tab="store-settings" role="tab" aria-selected="true">店舗設定</button>
                <button type="button" class="sys-tab-btn" data-sys-tab="ai-character" role="tab" aria-selected="false">AIキャラクター</button>
                <button type="button" class="sys-tab-btn" data-sys-tab="response-mode" role="tab" aria-selected="false">応答モード<span class="dev-badge">Dev</span></button>
                <button type="button" class="sys-tab-btn" data-sys-tab="wbss-env" role="tab" aria-selected="false">WBSS接続先<span class="dev-badge">Dev</span></button>
                <button type="button" class="sys-tab-btn" data-sys-tab="pre-launch" role="tab" aria-selected="false">公開前チェック</button>
                <button type="button" class="sys-tab-btn" data-sys-tab="ai-cost" role="tab" aria-selected="false">AI利用額<span class="dev-badge">Dev</span></button>
                <button type="button" class="sys-tab-btn" data-sys-tab="openai-diag" role="tab" aria-selected="false">OpenAI診断<span class="dev-badge">Dev</span></button>
            </div>

            <!-- 店舗設定（読み取り専用） -->
            <div class="sys-panel" id="sysp-store-settings" role="tabpanel">
                <?php
                    // 未設定チェック用ヘルパー
                    $sv = fn(string $k): string => trim(twin_store_value($adminStoreKey, $k));
                    $disp = fn(string $k): string => $sv($k) !== '' ? twin_store_value($adminStoreKey, $k) : '（未設定）';
                    $warns = [];
                    if ($sv('line_url') === '')      $warns[] = 'LINE URL が未設定です';
                    if ($sv('instagram_url') === '') $warns[] = 'Instagram URL が未設定です';
                    if ($sv('price_url') === '')     $warns[] = '料金ページURL が未設定です';
                    if ($sv('business_hours') === '') $warns[] = '営業時間 が未設定です';
                    if ($sv('price_summary') === '') $warns[] = '料金概要 が未設定です';
                ?>
                <?php if ($warns): ?>
                    <div style="background:var(--panel-soft);border:1px solid var(--orange);border-radius:8px;padding:10px 14px;margin-bottom:12px;">
                        <div style="font-size:12px;font-weight:700;color:var(--orange);margin-bottom:4px;">⚠️ 未設定項目があります</div>
                        <?php foreach ($warns as $w): ?>
                            <div style="font-size:12px;color:var(--orange);">・<?= e($w) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="card" style="margin-bottom:12px;">
                    <span class="label" style="display:block;margin-bottom:4px;">基本情報</span>
                    <p class="section-note" style="margin-bottom:10px;">読み取り専用。編集は <code>app/knowledge/stores.php</code> で行ってください。</p>
                    <div class="table-scroll">
                        <table>
                            <tbody>
                                <tr><th style="white-space:nowrap;">store_key</th><td><code><?= e($adminStoreKey) ?></code></td></tr>
                                <tr><th style="white-space:nowrap;">店舗名</th><td><?= e($disp('store_name')) ?></td></tr>
                                <tr><th style="white-space:nowrap;">AI名</th><td><?= e($sv('default_ai_name') !== '' ? twin_store_value($adminStoreKey, 'default_ai_name') : $disp('default_ai_name')) ?></td></tr>
                                <tr><th style="white-space:nowrap;">AI肩書き</th><td><?= e($disp('default_role_label')) ?></td></tr>
                                <tr><th style="white-space:nowrap;">応答モード</th><td><?= e(twin_response_mode_label($currentResponseMode)) ?> <span class="pill" style="font-size:11px;"><?= e($currentResponseMode) ?></span></td></tr>
                                <tr><th style="white-space:nowrap;">出勤連携 key</th><td><code><?= e($sv('wbss_store_key') !== '' ? twin_store_value($adminStoreKey, 'wbss_store_key') : '（未設定）') ?></code></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card" style="margin-bottom:12px;">
                    <span class="label" style="display:block;margin-bottom:10px;">営業情報</span>
                    <div class="table-scroll">
                        <table>
                            <tbody>
                                <tr><th style="white-space:nowrap;">営業時間</th><td><?= e($disp('business_hours')) ?></td></tr>
                                <tr><th style="white-space:nowrap;">定休日</th><td><?= e($disp('closed_days')) ?></td></tr>
                                <tr><th style="white-space:nowrap;">エリア</th><td><?= e($disp('area')) ?></td></tr>
                                <tr><th style="white-space:nowrap;">所在地</th><td><?= e($disp('address')) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="card" style="margin-bottom:12px;">
                    <span class="label" style="display:block;margin-bottom:10px;">料金情報</span>
                    <div class="table-scroll">
                        <table>
                            <tbody>
                                <tr><th style="white-space:nowrap;">指名料</th><td><?= e($disp('nomination_fee')) ?></td></tr>
                                <tr><th style="white-space:nowrap;">VIP料金</th><td><?= e($disp('vip_room_fee')) ?></td></tr>
                                <tr><th style="white-space:nowrap;">サービス料</th><td><?= e($disp('service_charge')) ?></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top:10px;">
                        <div class="label" style="font-size:11px;margin-bottom:4px;">料金概要</div>
                        <?php if ($sv('price_summary') !== ''): ?>
                            <pre style="background:var(--panel-soft);border:1px solid var(--line);border-radius:6px;padding:10px 12px;font-size:12px;white-space:pre-wrap;word-break:break-word;margin:0;"><?= e(twin_store_value($adminStoreKey, 'price_summary')) ?></pre>
                        <?php else: ?>
                            <div style="font-size:12px;color:var(--orange);">（未設定）</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card" style="margin-bottom:12px;">
                    <span class="label" style="display:block;margin-bottom:10px;">URLリンク</span>
                    <div class="table-scroll">
                        <table>
                            <tbody>
                                <tr>
                                    <th style="white-space:nowrap;">LINE URL</th>
                                    <td><?php if ($sv('line_url') !== ''): ?><a href="<?= e(twin_store_value($adminStoreKey, 'line_url')) ?>" target="_blank" rel="noopener" class="link"><?= e(twin_store_value($adminStoreKey, 'line_url')) ?></a><?php else: ?><span style="color:var(--orange);">（未設定）</span><?php endif; ?></td>
                                </tr>
                                <tr>
                                    <th style="white-space:nowrap;">Instagram URL</th>
                                    <td><?php if ($sv('instagram_url') !== ''): ?><a href="<?= e(twin_store_value($adminStoreKey, 'instagram_url')) ?>" target="_blank" rel="noopener" class="link"><?= e(twin_store_value($adminStoreKey, 'instagram_url')) ?></a><?php else: ?><span style="color:var(--orange);">（未設定）</span><?php endif; ?></td>
                                </tr>
                                <tr>
                                    <th style="white-space:nowrap;">料金ページ</th>
                                    <td><?php if ($sv('price_url') !== ''): ?><a href="<?= e(twin_store_value($adminStoreKey, 'price_url')) ?>" target="_blank" rel="noopener" class="link"><?= e(twin_store_value($adminStoreKey, 'price_url')) ?></a><?php else: ?><span style="color:var(--orange);">（未設定）</span><?php endif; ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- ── サイトから取得 ──────────────────────────────────── -->
                <div class="card" id="store-parse-card">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:12px;">
                        <span class="label">サイトから自動取得</span>
                        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                            <input type="url" id="sp-url-input"
                                   value="<?= e($sv('site_url') !== '' ? twin_store_value($adminStoreKey, 'site_url') : '') ?>"
                                   placeholder="https://clubkirin.com"
                                   style="font-size:12px;padding:5px 8px;border:1px solid var(--line);border-radius:6px;background:var(--panel-soft);color:var(--text);width:220px;">
                            <button type="button" id="sp-fetch-btn"
                                    style="font-size:12px;padding:5px 14px;border-radius:6px;background:var(--accent);color:#fff;border:none;cursor:pointer;font-weight:600;">
                                サイトから取得
                            </button>
                        </div>
                    </div>
                    <p class="section-note" style="margin-bottom:0;">取得結果は確認してから手動で <code>app/knowledge/stores.php</code> に反映してください。既存値は自動更新されません。</p>

                    <!-- ローディング -->
                    <div id="sp-loading" style="display:none;text-align:center;padding:16px;font-size:13px;color:var(--muted);">
                        解析中...
                    </div>

                    <!-- エラー -->
                    <div id="sp-error" style="display:none;margin-top:10px;padding:10px 12px;background:rgba(220,53,69,0.08);border:1px solid rgba(220,53,69,0.3);border-radius:6px;font-size:12px;color:var(--danger);"></div>

                    <!-- 差分テーブル -->
                    <div id="sp-result" style="display:none;margin-top:14px;">
                        <div style="font-size:12px;color:var(--muted);margin-bottom:8px;">
                            🟢 取得できた項目を表示しています。<strong>変更あり</strong>の行をコピーして <code>stores.php</code> に反映してください。
                        </div>
                        <div class="table-scroll">
                            <table id="sp-diff-table" style="font-size:12px;">
                                <thead>
                                    <tr>
                                        <th style="white-space:nowrap;width:100px;">項目</th>
                                        <th style="white-space:nowrap;width:80px;">取得元</th>
                                        <th>現在値</th>
                                        <th>取得値</th>
                                        <th style="white-space:nowrap;width:70px;">変更</th>
                                    </tr>
                                </thead>
                                <tbody id="sp-diff-body"></tbody>
                            </table>
                        </div>

                        <!-- コピー用スニペット -->
                        <div style="margin-top:14px;">
                            <div style="font-size:12px;font-weight:700;margin-bottom:6px;">stores.php 反映スニペット <span style="font-weight:400;color:var(--muted);">（変更あり項目のみ）</span></div>
                            <pre id="sp-snippet" style="background:var(--panel-soft);border:1px solid var(--line);border-radius:6px;padding:10px 12px;font-size:11px;white-space:pre-wrap;word-break:break-all;margin:0;max-height:300px;overflow:auto;"></pre>
                            <button type="button" id="sp-copy-btn"
                                    style="margin-top:8px;font-size:12px;padding:4px 12px;border-radius:6px;background:var(--panel-soft);border:1px solid var(--line);color:var(--text);cursor:pointer;">
                                コピー
                            </button>
                        </div>
                    </div>
                </div>
                <!-- ── /サイトから取得 ─────────────────────────────────── -->
            </div>

            <!-- AIキャラクター -->
            <div class="sys-panel" id="sysp-ai-character" role="tabpanel" hidden>
                <?php
                    $editChar = (int) ($activeCharacter['id'] ?? 0) > 0 ? $activeCharacter : twin_ai_character_default();
                    $hasActiveChar = (int) ($activeCharacter['id'] ?? 0) > 0;
                ?>
                <!-- アクティブキャラクター表示カード -->
                <div class="char-active-card">
                    <?php if (!empty($activeCharacter['character_image_path'])): ?>
                        <img class="char-avatar" src="<?= e((string) $activeCharacter['character_image_path']) ?>" alt="<?= e((string) $activeCharacter['ai_name']) ?>">
                    <?php else: ?>
                        <div class="char-avatar-placeholder">🤖</div>
                    <?php endif; ?>
                    <div class="char-info">
                        <div class="char-name"><?= e($hasActiveChar ? (string) $activeCharacter['ai_name'] : 'TWIN SEIKA') ?></div>
                        <div class="char-title"><?= e($hasActiveChar ? (string) $activeCharacter['ai_title'] : 'デフォルト設定') ?></div>
                        <div class="char-greeting"><?= e(mb_strimwidth($hasActiveChar ? (string) $activeCharacter['greeting_message'] : '', 0, 80, '…', 'UTF-8')) ?></div>
                    </div>
                    <div class="char-status">
                        <?php if ($hasActiveChar && (int) $activeCharacter['is_active']): ?>
                            <span class="pill green">有効</span>
                        <?php else: ?>
                            <span class="pill">デフォルト</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- 編集フォーム (details で開閉) -->
                <details class="section-card" style="margin-bottom:12px;" <?= (isset($_GET['char_saved']) || isset($_GET['char_error'])) ? 'open' : '' ?>>
                    <summary class="section-summary" style="cursor:pointer;padding:12px 16px;list-style:none;display:flex;align-items:center;justify-content:space-between;border-radius:10px;background:var(--panel-soft);border:1px solid var(--line);">
                        <span style="font-weight:600;">✏️ 編集する</span>
                        <span class="section-caret" style="color:var(--muted);">›</span>
                    </summary>
                    <div style="padding:16px;border:1px solid var(--line);border-top:none;border-radius:0 0 10px 10px;background:var(--panel);">
                        <form method="post" action="/crew-onboarding/admin.php" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="save_character">
                            <input type="hidden" name="csrf_token" value="<?= e(twin_admin_csrf_token()) ?>">
                            <input type="hidden" name="character_id" value="<?= (int) ($editChar['id'] ?? 0) ?>">

                            <div class="field" style="margin-bottom:10px;">
                                <label for="char_ai_name">AI名 <span class="muted">(例: TWIN SEIKA)</span></label>
                                <input type="text" id="char_ai_name" name="ai_name" value="<?= e((string) ($editChar['ai_name'] ?? 'TWIN SEIKA')) ?>" maxlength="100" style="width:100%;padding:0.5rem 0.75rem;background:var(--panel-soft);border:1px solid var(--line);border-radius:6px;color:var(--text);">
                            </div>

                            <div class="field" style="margin-bottom:10px;">
                                <label for="char_ai_title">AI役職・肩書き <span class="muted">(例: CLUB SEIKA DIGITAL HOSTESS)</span></label>
                                <input type="text" id="char_ai_title" name="ai_title" value="<?= e((string) ($editChar['ai_title'] ?? '')) ?>" maxlength="200" style="width:100%;padding:0.5rem 0.75rem;background:var(--panel-soft);border:1px solid var(--line);border-radius:6px;color:var(--text);">
                            </div>

                            <div class="field" style="margin-bottom:10px;">
                                <label for="char_greeting">初回あいさつ文 <span style="color:var(--orange);">*必須</span></label>
                                <textarea id="char_greeting" name="greeting_message" rows="3" maxlength="500" style="width:100%;padding:0.5rem 0.75rem;background:var(--panel-soft);border:1px solid var(--line);border-radius:6px;color:var(--text);resize:vertical;"><?= e((string) ($editChar['greeting_message'] ?? '')) ?></textarea>
                            </div>

                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:10px;">
                                <div class="field">
                                    <label for="char_image">キャラクター画像 <span class="muted">(JPEG/PNG/GIF/WebP・2MB以内)</span></label>
                                    <?php if (!empty($editChar['character_image_path'])): ?>
                                        <div style="margin-bottom:6px;"><img src="<?= e((string) $editChar['character_image_path']) ?>" alt="現在の画像" style="width:56px;height:56px;object-fit:cover;border-radius:50%;border:1px solid var(--line);"></div>
                                    <?php endif; ?>
                                    <input type="file" id="char_image" name="character_image" accept="image/jpeg,image/png,image/gif,image/webp" style="color:var(--text);width:100%;">
                                </div>
                                <div class="field">
                                    <label for="char_logo">ロゴ画像 <span class="muted">(任意)</span></label>
                                    <?php if (!empty($editChar['logo_image_path'])): ?>
                                        <div style="margin-bottom:6px;"><img src="<?= e((string) $editChar['logo_image_path']) ?>" alt="現在のロゴ" style="max-height:32px;border:1px solid var(--line);padding:2px;"></div>
                                    <?php endif; ?>
                                    <input type="file" id="char_logo" name="logo_image" accept="image/jpeg,image/png,image/gif,image/webp" style="color:var(--text);width:100%;">
                                </div>
                            </div>

                            <div class="field" style="margin-bottom:14px;">
                                <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                                    <input type="checkbox" name="is_active" value="1" <?= (int) ($editChar['is_active'] ?? 0) ? 'checked' : '' ?>>
                                    <span>この設定を有効にする（チャット画面・OpenAIプロンプトに反映）</span>
                                </label>
                            </div>

                            <button type="submit" class="button primary" style="min-width:140px;">保存する</button>
                        </form>
                    </div>
                </details>

                <?php if (count($characterSettings) > 1): ?>
                    <details class="section-card">
                        <summary class="section-summary" style="cursor:pointer;padding:10px 14px;list-style:none;display:flex;align-items:center;justify-content:space-between;border-radius:10px;background:var(--panel-soft);border:1px solid var(--line);">
                            <span class="muted" style="font-size:13px;">設定履歴 (<?= count($characterSettings) ?>件)</span>
                            <span class="section-caret" style="color:var(--muted);">›</span>
                        </summary>
                        <div style="padding:12px;border:1px solid var(--line);border-top:none;border-radius:0 0 10px 10px;background:var(--panel);">
                            <div class="table-scroll">
                                <table>
                                    <thead><tr><th>ID</th><th>AI名</th><th>状態</th><th>更新日時</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($characterSettings as $cs): ?>
                                        <tr>
                                            <td><?= (int) $cs['id'] ?></td>
                                            <td><?= e((string) $cs['ai_name']) ?></td>
                                            <td><?= (int) $cs['is_active'] ? '<span class="pill green">有効</span>' : '<span class="pill">無効</span>' ?></td>
                                            <td><?= e((string) $cs['updated_at']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </details>
                <?php endif; ?>
            </div>

            <!-- 応答モード -->
            <div class="sys-panel" id="sysp-response-mode" role="tabpanel" hidden>
                <div class="overview-line" style="margin-bottom:12px;">
                    <div>
                        <div class="small muted">現在の応答方式</div>
                        <div class="big"><?= e(twin_response_mode_label($currentResponseMode)) ?></div>
                    </div>
                    <div>
                        <div class="small muted">AI接続</div>
                        <div class="big"><?= e(trim((string) $config['openai_api_key']) !== '' ? '設定済み' : '未設定') ?></div>
                    </div>
                    <div>
                        <div class="small muted">hybrid</div>
                        <div class="big">v0.7予定</div>
                    </div>
                    <div>
                        <div class="small muted">保存先</div>
                        <div class="big">app_settings</div>
                    </div>
                </div>

                <div class="mode-guide" style="margin-bottom:16px;">
                    <?php foreach (['rule', 'openai', 'hybrid'] as $mode): ?>
                        <div class="mode-card">
                            <div class="mode-name"><?= e(twin_response_mode_label($mode)) ?></div>
                            <p><?= e(twin_admin_mode_description($mode)) ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($currentResponseMode === 'openai' && trim((string) $config['openai_api_key']) === ''): ?>
                    <div class="warning">OpenAI モードですが、`OPENAI_API_KEY` が未設定です。保存はできますが、会話は失敗時に rule へフォールバックします。</div>
                <?php endif; ?>

                <form method="post" action="/crew-onboarding/admin.php">
                    <input type="hidden" name="action" value="save_settings">
                    <input type="hidden" name="csrf_token" value="<?= e(twin_admin_csrf_token()) ?>">
                    <div class="form-grid">
                        <div class="field">
                            <label for="response_mode">応答方式</label>
                            <select id="response_mode" name="response_mode">
                                <?php foreach (['rule' => '安定モード', 'openai' => 'AI会話モード', 'hybrid' => 'ハイブリッドモード'] as $value => $label): ?>
                                    <option value="<?= e($value) ?>"<?= $currentResponseMode === $value ? ' selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="openai_model">AIモデル</label>
                            <?php $modelCosts = twin_openai_model_costs(); ?>
                            <select id="openai_model" name="openai_model">
                                <?php foreach ($modelCosts as $modelId => $modelInfo): ?>
                                    <option value="<?= e($modelId) ?>"<?= $currentOpenaiModel === $modelId ? ' selected' : '' ?>>
                                        <?= e($modelInfo['label']) ?> — input $<?= $modelInfo['input'] ?>/1M / output $<?= $modelInfo['output'] ?>/1M
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="muted" style="margin-top:4px;font-size:12px;">料金はモデル別に自動計算されます。</div>
                        </div>
                        <div class="field">
                            <label>保存前の確認</label>
                            <div class="muted">変更すると次のチャット応答から反映されます。</div>
                        </div>
                        <div class="field">
                            <button type="submit" class="button primary" style="width:100%;">設定を保存</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- WBSS接続先 -->
            <div class="sys-panel" id="sysp-wbss-env" role="tabpanel" hidden>
                <?php
                    // 接続テスト（GET ?test_wbss=1 のとき実行）
                    $wbssTestResult = null;
                    if (twin_admin_is_logged_in() && isset($_GET['test_wbss'])) {
                        require_once CREW_PRIVATE_ROOT . '/app/clients/wbss_client.php';
                        $testStart = microtime(true);
                        $testRes = twin_wbss_fetch_attendance($adminStoreKey);
                        $testMs = (int) round((microtime(true) - $testStart) * 1000);
                        $wbssTestResult = [
                            'ok'      => (bool) ($testRes['ok'] ?? false),
                            'status'  => (int) ($testRes['http_status'] ?? 0),
                            'ms'      => $testMs,
                            'error'   => (string) ($testRes['error'] ?? ''),
                            'url'     => $currentWbssUrl,
                        ];
                    }
                ?>
                <div class="card" style="margin-bottom:14px;">
                    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
                        <span class="label">WBSS接続先</span>
                        <span class="pill <?= $currentWbssEnv === 'prod' ? 'green' : 'orange' ?>">
                            <?= e(twin_wbss_env_label($currentWbssEnv)) ?>
                        </span>
                        <span style="font-size:12px;color:var(--muted);">現在の base URL: <code><?= e($currentWbssUrl) ?></code></span>
                    </div>

                    <?php if ($wbssTestResult !== null): ?>
                        <div style="margin-bottom:12px;padding:8px 12px;border-radius:8px;border:1px solid var(--line);background:var(--panel-soft);font-size:12px;">
                            <?php if ($wbssTestResult['ok']): ?>
                                <span style="color:var(--green);">✅ 接続テスト成功</span>
                            <?php else: ?>
                                <span style="color:var(--orange);">❌ 接続テスト失敗</span>
                                <?php if ($wbssTestResult['error'] !== ''): ?>
                                    <span style="color:var(--muted);margin-left:8px;"><?= e($wbssTestResult['error']) ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <span style="color:var(--muted);margin-left:8px;">HTTP <?= e((string) $wbssTestResult['status']) ?> / <?= e((string) $wbssTestResult['ms']) ?>ms</span>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="/crew-onboarding/admin.php">
                        <input type="hidden" name="action" value="save_wbss_env">
                        <input type="hidden" name="csrf_token" value="<?= e(twin_admin_csrf_token()) ?>">
                        <div style="display:flex;align-items:flex-end;gap:10px;flex-wrap:wrap;margin-bottom:12px;">
                            <div class="field" style="margin:0;">
                                <label for="wbss_env" style="font-size:12px;">接続先を切り替え</label>
                                <select id="wbss_env" name="wbss_env" style="margin-top:4px;padding:6px 10px;background:var(--panel-soft);border:1px solid var(--line);border-radius:6px;color:var(--text);font-size:13px;">
                                    <?php foreach (twin_wbss_allowed_envs() as $envKey): ?>
                                        <option value="<?= e($envKey) ?>"<?= $currentWbssEnv === $envKey ? ' selected' : '' ?>>
                                            <?= e(twin_wbss_env_label($envKey)) ?> — <?= e(twin_wbss_env_urls()[$envKey]) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="button primary">保存</button>
                        </div>
                        <div class="muted" style="font-size:11px;">保存後、次回の出勤API呼び出しから反映されます。APIキーは表示しません。</div>
                    </form>

                    <div style="margin-top:12px;">
                        <a href="/crew-onboarding/admin.php?sys=wbss-env&test_wbss=1#system" class="button" style="font-size:12px;">今すぐ接続テスト</a>
                    </div>
                </div>

                <div class="card">
                    <span class="label" style="display:block;margin-bottom:8px;">環境一覧</span>
                    <div class="table-scroll">
                        <table>
                            <thead><tr><th>env_key</th><th>環境名</th><th>base URL</th></tr></thead>
                            <tbody>
                                <?php foreach (twin_wbss_env_urls() as $envKey => $envUrl): ?>
                                    <tr>
                                        <td><code><?= e($envKey) ?></code></td>
                                        <td><?= e(twin_wbss_env_label($envKey)) ?></td>
                                        <td style="font-size:11px;"><code><?= e($envUrl) ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 公開前チェック -->
            <div class="sys-panel" id="sysp-pre-launch" role="tabpanel" hidden>
                <div class="label" style="margin-bottom:10px;">公開前チェックリスト</div>
                <div style="display:flex;flex-direction:column;gap:8px;">
                    <?php foreach ($checklist as $item): ?>
                        <?php
                            if ($item['status'] === true) {
                                $icon = '✓'; $color = 'var(--green, #6fcf97)'; $text = '確認済み';
                            } elseif ($item['status'] === false) {
                                $icon = '✗'; $color = 'var(--danger, #f2a7a7)'; $text = '未設定';
                            } else {
                                $icon = '!'; $color = '#f0a500'; $text = '要確認';
                            }
                        ?>
                        <div style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--panel);border-radius:8px;border:1px solid var(--line);">
                            <span style="color:<?= $color ?>;font-weight:bold;font-size:16px;width:20px;text-align:center;"><?= $icon ?></span>
                            <span style="flex:1;font-size:14px;"><?= e($item['label']) ?></span>
                            <span style="color:<?= $color ?>;font-size:12px;"><?= e($text) ?><?= $item['auto'] ? '' : ' (手動確認)' ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- AI利用額 -->
            <div class="sys-panel" id="sysp-ai-cost" role="tabpanel" hidden>
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
                    <span class="pill" style="font-size:11px;padding:2px 8px;"><?= e(twin_openai_model_label($currentOpenaiModel)) ?></span>
                    <span>今日 <strong>¥<?= number_format($aiUsage['today_jpy'], 2) ?></strong></span>
                    <span>今月 <strong>¥<?= number_format($aiUsage['month_jpy'], 2) ?></strong></span>
                    <span>累計 <strong>¥<?= number_format($aiUsage['total_jpy'], 2) ?></strong></span>
                </div>
                <p class="section-note" style="margin-top:0;margin-bottom:14px;">この金額はOpenAI APIレスポンスのtoken usageをもとにした概算です。実際の請求額とは異なる場合があります。</p>
                <?php if ($hasUnrecordedUsage): ?>
                    <div class="warning">AI会話モードの記録はありますが、usage情報は未記録です。実装前のログ、またはAPIレスポンスusage未取得の可能性があります。</div>
                <?php endif; ?>
                <p class="section-note" style="margin-bottom:10px;">usage記録件数: <strong><?= number_format($aiUsageCount) ?>件</strong></p>
                <?php if (!empty($aiUsageRecent)): ?>
                    <p style="font-size:12px;color:var(--muted);margin-bottom:4px;">直近5件</p>
                    <div class="table-scroll" style="margin-bottom:16px;">
                        <table style="font-size:12px;">
                            <thead>
                                <tr style="border-bottom:1px solid var(--line);color:var(--muted);">
                                    <th style="text-align:left;padding:4px 6px;">日時</th>
                                    <th style="text-align:left;padding:4px 6px;">モデル</th>
                                    <th style="text-align:right;padding:4px 6px;">プロンプト</th>
                                    <th style="text-align:right;padding:4px 6px;">出力</th>
                                    <th style="text-align:right;padding:4px 6px;">合計</th>
                                    <th style="text-align:right;padding:4px 6px;">概算額</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($aiUsageRecent as $ur): ?>
                                    <tr style="border-bottom:1px solid var(--line);">
                                        <td style="padding:4px 6px;"><?= e((string)($ur['created_at'] ?? '')) ?></td>
                                        <td style="padding:4px 6px;"><?= e((string)($ur['model'] ?? '')) ?></td>
                                        <td style="text-align:right;padding:4px 6px;"><?= number_format((int)($ur['prompt_tokens'] ?? 0)) ?></td>
                                        <td style="text-align:right;padding:4px 6px;"><?= number_format((int)($ur['completion_tokens'] ?? 0)) ?></td>
                                        <td style="text-align:right;padding:4px 6px;"><?= number_format((int)($ur['total_tokens'] ?? 0)) ?></td>
                                        <td style="text-align:right;padding:4px 6px;color:var(--accent);">¥<?= number_format((float)($ur['estimated_cost_jpy'] ?? 0), 4) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
                <?php if ($aiUsage['total_calls'] === 0): ?>
                    <p class="section-note">AI会話モードを使用していない場合、コストは発生しません。</p>
                <?php else: ?>
                    <div class="section-grid" style="grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;margin-bottom:18px;">
                        <div class="card" style="padding:14px;text-align:center;">
                            <div style="font-size:11px;color:var(--muted);margin-bottom:6px;">今日の概算額</div>
                            <div style="font-size:22px;font-weight:700;color:var(--accent);">¥<?= number_format($aiUsage['today_jpy'], 2) ?></div>
                        </div>
                        <div class="card" style="padding:14px;text-align:center;">
                            <div style="font-size:11px;color:var(--muted);margin-bottom:6px;">今月の概算額</div>
                            <div style="font-size:22px;font-weight:700;color:var(--accent);">¥<?= number_format($aiUsage['month_jpy'], 2) ?></div>
                        </div>
                        <div class="card" style="padding:14px;text-align:center;">
                            <div style="font-size:11px;color:var(--muted);margin-bottom:6px;">累計概算額</div>
                            <div style="font-size:22px;font-weight:700;color:var(--accent);">¥<?= number_format($aiUsage['total_jpy'], 2) ?></div>
                        </div>
                        <div class="card" style="padding:14px;text-align:center;">
                            <div style="font-size:11px;color:var(--muted);margin-bottom:6px;">総トークン数</div>
                            <div style="font-size:22px;font-weight:700;"><?= number_format($aiUsage['total_tokens']) ?></div>
                        </div>
                    </div>
                    <?php if (!empty($aiUsage['by_model'])): ?>
                        <div class="table-scroll">
                            <table style="font-size:13px;">
                                <thead>
                                    <tr style="border-bottom:1px solid var(--line);color:var(--muted);">
                                        <th style="text-align:left;padding:6px 8px;">モデル</th>
                                        <th style="text-align:right;padding:6px 8px;">呼び出し回数</th>
                                        <th style="text-align:right;padding:6px 8px;">トークン数</th>
                                        <th style="text-align:right;padding:6px 8px;">概算額</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($aiUsage['by_model'] as $modelRow): ?>
                                        <tr style="border-bottom:1px solid var(--line);">
                                            <td style="padding:6px 8px;"><?= e((string)($modelRow['model'] ?? '')) ?></td>
                                            <td style="text-align:right;padding:6px 8px;"><?= number_format((int)($modelRow['calls'] ?? 0)) ?></td>
                                            <td style="text-align:right;padding:6px 8px;"><?= number_format((int)($modelRow['tokens'] ?? 0)) ?></td>
                                            <td style="text-align:right;padding:6px 8px;color:var(--accent);">¥<?= number_format((float)($modelRow['cost'] ?? 0), 2) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- OpenAI診断 -->
            <div class="sys-panel" id="sysp-openai-diag" role="tabpanel" hidden>
                <div class="diagnostic-grid">
                    <div class="diagnostic-card">
                        <div class="diagnostic-label">OpenAI呼び出し回数</div>
                        <div class="diagnostic-value"><?= e(number_format((int) $openaiDiagnostics['request_count'])) ?>回</div>
                        <div class="diagnostic-note">response_mode=openai / hybrid で OpenAI エンジンに入った回数です。</div>
                    </div>
                    <div class="diagnostic-card">
                        <div class="diagnostic-label">成功回数</div>
                        <div class="diagnostic-value"><?= e(number_format((int) $openaiDiagnostics['success_count'])) ?>回</div>
                        <div class="diagnostic-note">OpenAI から正常な返信を受け取れた回数です。</div>
                    </div>
                    <div class="diagnostic-card">
                        <div class="diagnostic-label">失敗回数</div>
                        <div class="diagnostic-value"><?= e(number_format((int) $openaiDiagnostics['failure_count'])) ?>回</div>
                        <div class="diagnostic-note">APIキー未設定・タイムアウト・HTTPエラーなどを含みます。</div>
                    </div>
                    <div class="diagnostic-card">
                        <div class="diagnostic-label">fallback回数</div>
                        <div class="diagnostic-value"><?= e(number_format((int) $openaiDiagnostics['fallback_count'])) ?>回</div>
                        <div class="diagnostic-note">OpenAI失敗後に rule へ切り替わった回数です。</div>
                    </div>
                    <div class="diagnostic-card">
                        <div class="diagnostic-label">usage保存件数</div>
                        <div class="diagnostic-value"><?= e(number_format((int) $openaiDiagnostics['usage_saved_count'])) ?>件</div>
                        <div class="diagnostic-note">ai_usage_logs に保存できた件数です。</div>
                    </div>
                    <div class="diagnostic-card">
                        <div class="diagnostic-label">最終OpenAI利用日時</div>
                        <div class="diagnostic-value"><?= e($openaiDiagnostics['last_usage_at'] ? (string) $openaiDiagnostics['last_usage_at'] : '-') ?></div>
                        <div class="diagnostic-note">最後に usage が保存された日時です。</div>
                    </div>
                </div>
                <div class="diagnostic-card" style="margin-top:12px;">
                    <div class="diagnostic-label">最終エラーメッセージ</div>
                    <div class="diagnostic-note"><?= e($openaiDiagnostics['last_error_message'] ? (string) $openaiDiagnostics['last_error_message'] : 'エラー記録なし') ?></div>
                </div>
                <?php if ((int) $openaiDiagnostics['request_count'] > 0 && (int) $openaiDiagnostics['usage_saved_count'] === 0): ?>
                    <div class="warning" style="margin-top:12px;">OpenAI呼び出しはありますが usage が保存されていません。`openai_engine.php` の usage 記録を確認してください。</div>
                <?php endif; ?>
                <?php if ((int) $openaiDiagnostics['request_count'] === 0 && (int) $openaiDiagnostics['response_mode_openai_sessions'] > 0): ?>
                    <div class="warning" style="margin-top:12px;">AI会話モードの記録はありますが、OpenAI呼び出しが 0 回です。`response_engine.php` の分岐を確認してください。</div>
                <?php endif; ?>
            </div>
        </div><!-- /system-settings-wrap -->

        <!-- 改善センター -->
        <details class="section tab-panel" data-panel="ai" id="improvement-center" open>
            <summary>
                <span class="section-title">改善センター</span>
                <span class="section-summary-meta"><strong>改善センター</strong><span>今すぐ直すべきポイント（全件）</span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <?php if (!$improvementSuggestions): ?>
                    <div class="empty">現在、改善が必要な項目はありません ✅</div>
                <?php else: ?>
                    <div class="action-grid">
                        <?php foreach ($improvementSuggestions as $item): ?>
                            <?php
                                $priKey = (string) ($item['priority'] ?? 'low');
                                $priBadgeClass = $priKey === 'high' ? 'kpi-danger' : ($priKey === 'medium' ? 'kpi-warning' : 'muted');
                            ?>
                            <div class="action-card <?= e('priority-' . $priKey) ?>">
                                <div class="ops-badge <?= e($priBadgeClass) ?>">優先度 <?= e(twin_question_ranking_priority_label($priKey)) ?></div>
                                <h3><?= e((string) $item['title']) ?></h3>
                                <div class="meta-line"><strong>現在の数値:</strong> <?= e((string) $item['current']) ?></div>
                                <div class="meta-line"><strong>問題:</strong> <?= e((string) $item['reason']) ?></div>
                                <div class="meta-line"><strong>推奨アクション:</strong> <?= e((string) $item['action']) ?></div>
                                <div class="meta-line"><strong>期待効果:</strong> <?= e((string) $item['effect']) ?></div>
                                <div class="meta-line"><strong>想定工数:</strong> <?= e((string) $item['effort']) ?></div>
                                <a class="link" href="<?= e((string) $item['link']) ?>">関連セクションへ</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </details>

        <details class="section tab-panel" data-panel="ai" id="ai-analysis">
            <summary>
                <span class="section-title">AI改善提案</span>
                <span class="section-summary-meta"><strong>AI改善提案</strong><span>analysis_json の活用ガイド</span></span>
                <span class="section-caret">›</span>
            </summary>
            <div class="section-body">
                <p class="section-note" style="margin-top: 0; margin-bottom: 14px;">
                    analysis_jsonは問診ログ・質問分類・セッション情報をAI改善提案用にまとめたJSONです。ダウンロード後、下のプロンプトと一緒にChatGPTへ渡すことで、応募者の不安、LINE応募につながる会話パターン、問診改善案を分析できます。
                </p>
                <div class="actions">
                    <a class="button primary" href="/crew-onboarding/admin_export.php?type=analysis_json">analysis_json ダウンロード</a>
                </div>
                <div class="field" style="margin-top: 12px;">
                    <label for="aiAnalysisPrompt">AI改善提案用プロンプトをコピー</label>
                    <textarea id="aiAnalysisPrompt" readonly>あなたは夜業界向け求人・採用改善アナリストです。
Project TWINの問診ログJSONを分析し、以下を出してください。

1. 応募者が不安に感じていることTOP10
2. LINE応募につながりそうな会話パターン
3. 問診途中で離脱しそうなパターン
4. 質問分類の改善案
5. 問診フローの返答改善案
6. FAQに追加すべき項目
7. 採用ページに追加すべきコンテンツ
8. 次に改善すべきKPI
9. 未分類の発言から追加すべき質問分類（具体的なキーワードと設計案を含む）
10. LINE誘導を強めるべき会話パターン（どの質問分類・どのタイミングで誘導すべきか）
11. 離脱候補セッションの原因仮説（短すぎる会話の理由と対策）
12. 返答が不自然な箇所と修正案（重複返答・other連続・「はい」後の繰り返しなど）
13. Project TWINを採用後フォロー・WBSS連携へ発展させる提案
14. KPIカードの数値（LINE CTR・other率・出勤希望質問率・時給質問率）をもとに改善優先度を判断する
15. 採用導線診断スコアの低い項目（intentカバー率・LINE誘導・会話自然度）を重点的に見る
16. LINEクリック前の質問傾向から応募導線を改善する（どの質問分類の後にLINE誘導すべきか）
17. 未分類率を下げるための質問分類追加案を出す（具体的なキーワードと設計案を含む）
18. 経験者ルート・未経験ルートそれぞれの問診完了率を分析し、改善案を提案する
19. candidate_score・priority_gradeと実際のLINEタップ率の相関を分析する
20. LINE応募への導線を強化する具体的な会話設計案を出す
21. 本当に聞かれる質問ランキングを重視し、件数の多い質問分類から改善優先度を決める
22. 代表質問からFAQ追加案を作り、改善TOP5を実装順に並べる
23. 不安・緊張系の発言（anxiety）を重視し、体験入店ハードルを下げる応答改善案を出す
24. 問診ステップ別の離脱率を分析し、回答率を上げる改善案を提案する
25. 給与・時給（price_estimate）の誤判定がないか確認し、不安・緊張系の発言が誤って分類されていないか指摘する
26. 未分類率を下げる具体策を出し、辞書追加候補を優先度順に整理する
27. 時給推定（price / price_estimate）と出勤希望（attendance）の改善は、LINE応募への導線も含めて提案する
28. 体験入店呼客（bring_trial / bring_customer）の回答傾向と、LINE応募率への影響を分析する

出力は実務向けに、優先順位つきでまとめてください。</textarea>
                </div>
            </div>
        </details>
    </div>
    <script>
    (function () {
        const tabs = Array.from(document.querySelectorAll('.tab-btn'));
        const panels = Array.from(document.querySelectorAll('.wrap > .tab-panel[data-panel]'));
        if (!tabs.length || !panels.length) return;

        const tabToPanel = {
            dashboard: 'dashboard',
            conversation: 'conversation',
            conversion: 'conversion',
            wbss: 'wbss',
            ai: 'ai',
            system: 'system',
            overview: 'dashboard',
            improvements: 'dashboard',
            'question-ranking': 'conversation',
            intent: 'conversation',
            'real-question-ranking': 'conversation',
            'other-analysis': 'conversation',
            'suggested-intents': 'conversation',
            'conversation-quality': 'conversion',
            'conversation-summary': 'conversion',
            cta: 'conversion',
            'line-opportunity': 'conversion',
            'dropoff-sessions': 'conversion',
            'response-improvements': 'ai',
            'recommend-cast': 'conversion',
            wbss: 'wbss',
            'ai-cost': 'system',
            'openai-diagnostics': 'system',
            'ai-analysis': 'ai',
            'ai-character': 'system',
            'wbss-env': 'system',
            'response-mode': 'system',
            logs: 'system',
            settings: 'system',
        };

        const panelNames = ['dashboard', 'conversation', 'conversion', 'wbss', 'applicants', 'ai', 'system'];
        const defaultPanel = 'dashboard';

        const resolvePanel = (hash) => {
            const raw = (hash || '').replace(/^#/, '').trim();
            if (!raw) {
                return defaultPanel;
            }
            return tabToPanel[raw] || (panelNames.includes(raw) ? raw : defaultPanel);
        };

        const setActivePanel = (panelName, updateHash = false) => {
            const activePanel = panelNames.includes(panelName) ? panelName : defaultPanel;
            tabs.forEach((tab) => {
                tab.classList.toggle('is-active', tab.dataset.tab === activePanel);
                tab.setAttribute('aria-selected', tab.dataset.tab === activePanel ? 'true' : 'false');
            });
            panels.forEach((panel) => {
                const isActive = panel.dataset.panel === activePanel;
                panel.classList.toggle('is-active', isActive);
                panel.hidden = !isActive;
            });
            if (updateHash) {
                history.replaceState(null, '', '#' + activePanel);
            }
        };

        const sync = () => {
            const rawHash = window.location.hash;
            const panelName = resolvePanel(rawHash);
            setActivePanel(panelName, !rawHash || !panelNames.includes(rawHash.replace(/^#/, '')) && tabToPanel[rawHash.replace(/^#/, '')] === undefined);
            if (!rawHash || !panelNames.includes(rawHash.replace(/^#/, ''))) {
                history.replaceState(null, '', '#' + panelName);
            }
        };

        tabs.forEach((tab) => {
            tab.addEventListener('click', () => {
                const panelName = tab.dataset.tab || defaultPanel;
                if (window.location.hash !== '#' + panelName) {
                    window.location.hash = panelName;
                } else {
                    sync();
                }
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });

        window.addEventListener('hashchange', sync);
        sync();
    })();
    </script>
    <script>
    // システム設定サブタブ
    (function () {
        const wrap = document.getElementById('system-settings-wrap');
        if (!wrap) return;
        const btns = Array.from(wrap.querySelectorAll('.sys-tab-btn'));
        const panels = Array.from(wrap.querySelectorAll('.sys-panel'));
        const SK = 'twin_sys_tab';

        function activateSysTab(id) {
            btns.forEach(b => {
                const active = b.dataset.sysTab === id;
                b.classList.toggle('is-active', active);
                b.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach(p => {
                p.hidden = p.id !== 'sysp-' + id;
            });
            try { sessionStorage.setItem(SK, id); } catch(e) {}
        }

        btns.forEach(btn => {
            btn.addEventListener('click', () => activateSysTab(btn.dataset.sysTab));
        });

        // 保存後（char_saved/char_error GETパラメーター）は ai-character を開く
        const params = new URLSearchParams(window.location.search);
        const saved = params.get('char_saved') || params.get('char_error');
        const testWbss = params.get('test_wbss');
        const sysParam = params.get('sys');
        const validSysIds = btns.map(b => b.dataset.sysTab);
        const initial = saved ? 'ai-character'
            : testWbss ? 'wbss-env'
            : (sysParam && validSysIds.includes(sysParam)) ? sysParam
            : (function() {
                try {
                    const v = sessionStorage.getItem(SK);
                    return validSysIds.includes(v) ? v : 'store-settings';
                } catch(e) { return 'store-settings'; }
            })();
        activateSysTab(initial);
    })();
    </script>
    <script>
    // 質問傾向分析サブタブ
    (function () {
        const SK_CONV = 'twin_conv_sub';
        const btns = Array.from(document.querySelectorAll('#conv-sub-tabs .sub-tab-btn'));
        const panels = Array.from(document.querySelectorAll('.tab-panel[data-conv-sub]'));
        if (!btns.length) return;

        function activateConvSub(id) {
            btns.forEach(b => {
                const active = b.dataset.convSub === id;
                b.classList.toggle('is-active', active);
                b.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach(p => {
                const subs = (p.dataset.convSub || '').split(' ');
                p.classList.toggle('sub-tab-hidden', !subs.includes(id));
            });
            try { sessionStorage.setItem(SK_CONV, id); } catch(e) {}
        }

        btns.forEach(b => b.addEventListener('click', () => activateConvSub(b.dataset.convSub)));

        const validConvIds = btns.map(b => b.dataset.convSub);
        const initial = (function() {
            try {
                const v = sessionStorage.getItem(SK_CONV);
                return validConvIds.includes(v) ? v : 'summary';
            } catch(e) { return 'summary'; }
        })();
        activateConvSub(initial);
    })();
    </script>
    <script>
    // 応募導線分析サブタブ
    (function () {
        const SK_CONVRS = 'twin_convrs_sub';
        const btns = Array.from(document.querySelectorAll('#convrs-sub-tabs .sub-tab-btn'));
        const panels = Array.from(document.querySelectorAll('.tab-panel[data-convrs-sub]'));
        if (!btns.length) return;

        function activateConvrsSub(id) {
            btns.forEach(b => {
                const active = b.dataset.convrsSub === id;
                b.classList.toggle('is-active', active);
                b.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach(p => {
                const subs = (p.dataset.convrsSub || '').split(' ');
                p.classList.toggle('sub-tab-hidden', !subs.includes(id));
            });
            try { sessionStorage.setItem(SK_CONVRS, id); } catch(e) {}
        }

        btns.forEach(b => b.addEventListener('click', () => activateConvrsSub(b.dataset.convrsSub)));

        const initial = (function() {
            try { return sessionStorage.getItem(SK_CONVRS) || 'cta'; } catch(e) { return 'cta'; }
        })();
        activateConvrsSub(initial);
    })();
    </script>
    <script>
    // 出勤分析サブタブ
    (function () {
        const SK_WBSS = 'twin_wbss_sub';
        const btns = Array.from(document.querySelectorAll('#wbss-sub-tabs .sub-tab-btn'));
        const panels = Array.from(document.querySelectorAll('.tab-panel[data-wbss-sub]'));
        if (!btns.length) return;

        function activateWbssSub(id) {
            btns.forEach(b => {
                const active = b.dataset.wbssSub === id;
                b.classList.toggle('is-active', active);
                b.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach(p => {
                const subs = (p.dataset.wbssSub || '').split(' ');
                p.classList.toggle('sub-tab-hidden', !subs.includes(id));
            });
            try { sessionStorage.setItem(SK_WBSS, id); } catch(e) {}
        }

        btns.forEach(b => b.addEventListener('click', () => activateWbssSub(b.dataset.wbssSub)));

        const initial = (function() {
            try { return sessionStorage.getItem(SK_WBSS) || 'summary'; } catch(e) { return 'summary'; }
        })();
        activateWbssSub(initial);
    })();
    </script>
    <script>
    // 会話品質ネストサブタブ
    (function () {
        const SK_QUAL = 'twin_qual_sub';
        const btns = Array.from(document.querySelectorAll('#qual-sub-tabs .qual-sub-btn'));
        const panels = Array.from(document.querySelectorAll('.qual-sub-panel[data-qual-sub]'));
        if (!btns.length) return;

        function activateQualSub(id) {
            btns.forEach(b => {
                const active = b.dataset.qualSub === id;
                b.classList.toggle('is-active', active);
                b.setAttribute('aria-selected', active ? 'true' : 'false');
            });
            panels.forEach(p => {
                p.classList.toggle('qual-sub-hidden', p.dataset.qualSub !== id);
            });
            try { sessionStorage.setItem(SK_QUAL, id); } catch(e) {}
        }

        btns.forEach(b => b.addEventListener('click', () => activateQualSub(b.dataset.qualSub)));

        const validIds = btns.map(b => b.dataset.qualSub);
        const initial = (function() {
            try {
                const v = sessionStorage.getItem(SK_QUAL);
                return validIds.includes(v) ? v : 'summary';
            } catch(e) { return 'summary'; }
        })();
        activateQualSub(initial);
    })();
    </script>
    <script>
    // テーマ切替
    function applyTheme(theme) {
        const html = document.documentElement;
        const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (theme === 'auto') {
            html.setAttribute('data-theme', prefersDark ? 'dark' : 'light');
        } else {
            html.setAttribute('data-theme', theme);
        }
        document.querySelectorAll('.theme-btn').forEach(btn => {
            btn.classList.toggle('is-active', btn.dataset.themeVal === theme);
        });
    }
    function setTheme(theme) {
        localStorage.setItem('twin_admin_theme', theme);
        applyTheme(theme);
    }
    (function() {
        const saved = localStorage.getItem('twin_admin_theme') || 'auto';
        applyTheme(saved);
        // システムのカラースキーム変更に追従（autoモード時）
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function() {
            if ((localStorage.getItem('twin_admin_theme') || 'auto') === 'auto') applyTheme('auto');
        });
    })();

    // ── StoreSiteParser UI ──────────────────────────────────────────────
    (function() {
        const btn      = document.getElementById('sp-fetch-btn');
        const urlInput = document.getElementById('sp-url-input');
        const loading  = document.getElementById('sp-loading');
        const errDiv   = document.getElementById('sp-error');
        const result   = document.getElementById('sp-result');
        const diffBody = document.getElementById('sp-diff-body');
        const snippet  = document.getElementById('sp-snippet');
        const copyBtn  = document.getElementById('sp-copy-btn');
        const csrfToken = <?= json_encode(twin_admin_csrf_token()) ?>;
        const storeKey  = <?= json_encode($adminStoreKey) ?>;

        if (!btn) return;

        btn.addEventListener('click', async function() {
            const url = (urlInput ? urlInput.value : '').trim();
            if (!url) {
                alert('URLを入力してください');
                return;
            }

            // reset
            errDiv.style.display   = 'none';
            result.style.display   = 'none';
            loading.style.display  = 'block';
            btn.disabled = true;
            btn.textContent = '解析中...';

            const fd = new FormData();
            fd.append('url', url);
            fd.append('store_key', storeKey);
            fd.append('csrf_token', csrfToken);

            try {
                const resp = await fetch('admin_store_parse.php', { method: 'POST', body: fd });
                const data = await resp.json();

                loading.style.display = 'none';

                if (!data.ok) {
                    errDiv.textContent = data.error || '解析に失敗しました';
                    errDiv.style.display = 'block';
                    return;
                }

                // エラーメッセージ（取得できなかった項目）
                const errs = data.errors || {};
                if (Object.keys(errs).length > 0) {
                    errDiv.innerHTML = '⚠ 一部の取得に失敗しました: ' +
                        Object.values(errs).map(e => '<br>・' + e).join('');
                    errDiv.style.display = 'block';
                }

                // 差分テーブル生成
                diffBody.innerHTML = '';
                const changedKeys = {};
                for (const [key, info] of Object.entries(data.diff || {})) {
                    if (info.fetched === null) continue; // 取得できなかった項目はスキップ

                    const tr = document.createElement('tr');
                    const changed = info.changed;
                    if (changed) changedKeys[key] = info;

                    const badge = info.source
                        ? `<span style="font-size:10px;background:var(--panel-soft);border:1px solid var(--line);border-radius:4px;padding:1px 5px;">${info.source}</span>`
                        : '';
                    const diffBadge = changed
                        ? '<span style="font-size:10px;background:#d4edda;color:#155724;border-radius:4px;padding:1px 6px;font-weight:700;">変更あり</span>'
                        : '<span style="font-size:10px;color:var(--muted);">同じ</span>';

                    tr.style.background = changed ? 'rgba(40,167,69,0.06)' : '';
                    tr.innerHTML = `
                        <td style="white-space:nowrap;font-weight:${changed ? '600' : '400'}">${info.label}</td>
                        <td>${badge}</td>
                        <td style="color:var(--muted);word-break:break-all;">${info.current != null ? esc(info.current) : '<em style="color:var(--muted)">未設定</em>'}</td>
                        <td style="word-break:break-all;">${esc(info.fetched)}</td>
                        <td>${diffBadge}</td>
                    `;
                    diffBody.appendChild(tr);
                }

                // PHP スニペット生成
                const lines = Object.entries(changedKeys).map(([k, info]) => {
                    const val = info.fetched.replace(/'/g, "\\'").replace(/\n/g, "\\n");
                    return `    '${k}' => '${val}',`;
                });
                if (lines.length > 0) {
                    snippet.textContent = '// stores.php の該当 store_key ブロックに貼り付けてください:\n' + lines.join('\n');
                } else {
                    snippet.textContent = '// 変更のある項目はありませんでした';
                }

                result.style.display = 'block';

            } catch (e) {
                loading.style.display = 'none';
                errDiv.textContent = 'ネットワークエラー: ' + e.message;
                errDiv.style.display = 'block';
            } finally {
                btn.disabled = false;
                btn.textContent = 'サイトから取得';
            }
        });

        // コピーボタン
        if (copyBtn) {
            copyBtn.addEventListener('click', function() {
                if (!snippet.textContent) return;
                navigator.clipboard.writeText(snippet.textContent).then(function() {
                    copyBtn.textContent = 'コピーしました ✓';
                    setTimeout(function() { copyBtn.textContent = 'コピー'; }, 2000);
                });
            });
        }

        function esc(str) {
            return String(str)
                .replace(/&/g,'&amp;')
                .replace(/</g,'&lt;')
                .replace(/>/g,'&gt;')
                .replace(/"/g,'&quot;');
        }
    })();
    // ── /StoreSiteParser UI ─────────────────────────────────────────────
    </script>
</body>
</html>
