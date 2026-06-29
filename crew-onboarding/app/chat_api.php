<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/response_engine.php';
require_once __DIR__ . '/privacy.php';
require_once __DIR__ . '/store.php';

session_name('twin_chat_state');
session_start();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function twin_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function twin_client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45);
}

function twin_get_or_create_session(PDO $pdo, string $token, string $storeKey = 'seika'): int
{
    $stmt = $pdo->prepare('SELECT id FROM chat_sessions WHERE session_token = :session_token LIMIT 1');
    $stmt->execute(['session_token' => $token]);
    $row = $stmt->fetch();

    if ($row) {
        return (int) $row['id'];
    }

    $columns = ['session_token', 'started_at', 'user_agent', 'ip_address'];
    $values = [':session_token', 'NOW()', ':user_agent', ':ip_address'];
    $params = [
        'session_token' => $token,
        'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500),
        'ip_address' => twin_client_ip(),
    ];

    if (twin_db_column_exists($pdo, 'chat_sessions', 'store_key')) {
        $columns[] = 'store_key';
        $values[] = ':store_key';
        $params['store_key'] = $storeKey;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO chat_sessions (' . implode(', ', $columns) . ')
         VALUES (' . implode(', ', $values) . ')'
    );
    $stmt->execute($params);

    $sessionId = (int) $pdo->lastInsertId();
    twin_log_event($pdo, $sessionId, 'chat_start', null, $storeKey);

    return $sessionId;
}

function twin_log_event(PDO $pdo, int $sessionId, string $eventName, ?string $eventValue, string $storeKey = 'seika'): void
{
    $columns = ['session_id', 'event_name', 'event_value', 'created_at'];
    $values = [':session_id', ':event_name', ':event_value', 'NOW()'];
    $params = [
        'session_id' => $sessionId,
        'event_name' => $eventName,
        'event_value' => twin_safe_log_value($eventValue, 1000),
    ];

    if (twin_db_column_exists($pdo, 'event_logs', 'store_key')) {
        $columns[] = 'store_key';
        $values[] = ':store_key';
        $params['store_key'] = $storeKey;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO event_logs (' . implode(', ', $columns) . ')
         VALUES (' . implode(', ', $values) . ')'
    );
    $stmt->execute($params);
}

function twin_save_message(PDO $pdo, int $sessionId, string $sender, string $message, string $storeKey = 'seika'): void
{
    $columns = ['session_id', 'sender', 'message', 'intent', 'created_at'];
    $values = [':session_id', ':sender', ':message', ':intent', 'NOW()'];
    $params = [
        'session_id' => $sessionId,
        'sender' => $sender,
        'message' => $message,
        'intent' => null,
    ];

    if (twin_db_column_exists($pdo, 'chat_messages', 'store_key')) {
        $columns[] = 'store_key';
        $values[] = ':store_key';
        $params['store_key'] = $storeKey;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO chat_messages (' . implode(', ', $columns) . ')
         VALUES (' . implode(', ', $values) . ')'
    );
    $stmt->execute($params);
}

function twin_save_user_message(PDO $pdo, int $sessionId, string $message, string $intent, string $storeKey = 'seika'): void
{
    $columns = ['session_id', 'sender', 'message', 'intent', 'created_at'];
    $values = [':session_id', ':sender', ':message', ':intent', 'NOW()'];
    $params = [
        'session_id' => $sessionId,
        'sender' => 'user',
        'message' => twin_mask_personal_data($message),
        'intent' => $intent,
    ];

    if (twin_db_column_exists($pdo, 'chat_messages', 'store_key')) {
        $columns[] = 'store_key';
        $values[] = ':store_key';
        $params['store_key'] = $storeKey;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO chat_messages (' . implode(', ', $columns) . ')
         VALUES (' . implode(', ', $values) . ')'
    );
    $stmt->execute($params);
}

function twin_recent_user_intents(PDO $pdo, int $sessionId, int $limit = 4): array
{
    $limit = max(1, $limit);
    $stmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(intent, ''), 'other') AS intent_key
         FROM chat_messages
         WHERE session_id = :session_id AND sender = 'user'
         ORDER BY id DESC
         LIMIT {$limit}"
    );
    $stmt->execute(['session_id' => $sessionId]);

    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!is_array($rows)) {
        return [];
    }

    return array_values(array_reverse(array_filter(array_map(static fn ($value) => trim((string) $value), $rows))));
}

function twin_recent_messages(PDO $pdo, int $sessionId, int $limit = 5): array
{
    $limit = max(1, $limit);
    $stmt = $pdo->prepare(
        "SELECT sender, message, COALESCE(NULLIF(intent, ''), 'other') AS intent_key, created_at
         FROM chat_messages
         WHERE session_id = :session_id
         ORDER BY id DESC
         LIMIT {$limit}"
    );
    $stmt->execute(['session_id' => $sessionId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        return [];
    }

    return array_values(array_reverse($rows));
}

function twin_chat_state_token(string $token): string
{
    return 'token:' . $token;
}

function twin_chat_state_get(string $token): array
{
    $bucket = $_SESSION['twin_chat_state'][twin_chat_state_token($token)] ?? [];
    return is_array($bucket) ? $bucket : [];
}

function twin_chat_state_set(string $token, array $state): void
{
    $_SESSION['twin_chat_state'][twin_chat_state_token($token)] = $state;
}

function twin_detect_conversation_context(array $recentMessages, array $state): array
{
    $conversationContext = twin_chat_state_get_value($state, 'last_context', '');
    $lastCastName = twin_chat_state_get_value($state, 'last_cast_name', '');
    $lastCastStatus = twin_chat_state_get_value($state, 'last_cast_status', '');

    $lastTwinReply = '';
    for ($i = count($recentMessages) - 1; $i >= 0; $i--) {
        $row = $recentMessages[$i] ?? [];
        if (!is_array($row)) {
            continue;
        }
        if ((string) ($row['sender'] ?? '') === 'twin') {
            $lastTwinReply = trim((string) ($row['message'] ?? ''));
            break;
        }
    }

    // コンテキストリセットキーワード：明確な新しい質問が来たら context を解除する
    $contextResetKeywords = ['料金', '値段', 'いくら', 'ドリンク', 'VIP', 'どんな子', '営業時間', '何時', 'セット', '金額', '予約', '場所', 'アクセス', '雰囲気', '求人', 'おすすめ', '出勤'];
    $userMessage = '';
    for ($i = count($recentMessages) - 1; $i >= 0; $i--) {
        $row = $recentMessages[$i] ?? [];
        if (!is_array($row)) {
            continue;
        }
        if ((string) ($row['sender'] ?? '') === 'user') {
            $userMessage = trim((string) ($row['message'] ?? ''));
            break;
        }
    }
    $shouldResetContext = false;
    foreach ($contextResetKeywords as $kw) {
        if (mb_strpos($userMessage, $kw, 0, 'UTF-8') !== false) {
            $shouldResetContext = true;
            break;
        }
    }

    if (!$shouldResetContext && $lastTwinReply !== '') {
        // waiting_cast_confirmation: キャスト出勤確認の返答のみに限定
        // 「確認していただくと安心です」は crowd/arrival_time でも使うため除外
        if (preg_match('/に会いに行く予定ですか|別のキャストさんの出勤も確認しますか|(?:出勤予定です|出勤予定ではないようです|確認できませんでした)(?!.*安心)/u', $lastTwinReply)) {
            $conversationContext = 'waiting_cast_confirmation';
        } elseif (preg_match('/初めてのお客様も多いので安心してください|最初は少し緊張されますか/u', $lastTwinReply)) {
            $conversationContext = 'waiting_first_visit_answer';
        } elseif (preg_match('/お一人で来られる方もいらっしゃいますよ|お一人で来られる予定ですか/u', $lastTwinReply)) {
            $conversationContext = 'waiting_alone_answer';
        } elseif (preg_match('/何名様くらいでお考えですか/u', $lastTwinReply)) {
            $conversationContext = 'waiting_reservation_answer';
        } elseif (preg_match('/皆さん初めてのご来店ですか/u', $lastTwinReply)) {
            $conversationContext = 'waiting_group_visit_answer';
        } elseif (preg_match('/何名様でご来店予定ですか/u', $lastTwinReply)) {
            $conversationContext = 'waiting_arrival_time_answer';
        } elseif (preg_match('/何時頃のご来店を考えていますか/u', $lastTwinReply)) {
            $conversationContext = 'waiting_crowd_answer';
        } else {
            // 上記パターンに一致しない場合はコンテキストをリセット
            $conversationContext = '';
        }
    } else {
        // リセットキーワードあり or 返答なしの場合はコンテキストクリア
        $conversationContext = '';
    }

    return [
        'conversation_context' => $conversationContext,
        'last_cast_name' => $lastCastName,
        'last_cast_status' => $lastCastStatus,
    ];
}

function twin_chat_state_get_value(array $state, string $key, string $fallback = ''): string
{
    $value = $state[$key] ?? $fallback;
    if (is_string($value) || is_numeric($value)) {
        $value = trim((string) $value);
        if ($value !== '') {
            return $value;
        }
    }

    return $fallback;
}

function twin_chat_state_from_response(array $previousState, array $response): array
{
    // recruit engine が返す状態を優先的にマージ
    if (!empty($response['recruit_state']) && is_array($response['recruit_state'])) {
        return array_merge($previousState, $response['recruit_state']);
    }

    $intent = (string) ($response['intent'] ?? '');
    $reply = (string) ($response['reply'] ?? '');
    $castNameRaw = trim((string) ($response['cast_name_detected'] ?? ''));
    // cast=名前,raw=元メッセージ 形式の場合は cast= 以降を取り出す
    if (str_starts_with($castNameRaw, 'cast=')) {
        $castPart = substr($castNameRaw, 5);
        $castName = (string) explode(',', $castPart, 2)[0];
    } else {
        $castName = $castNameRaw;
    }
    $wbssValue = trim((string) ($response['wbss_api_event_value'] ?? ''));
    $status = '';

    if ($wbssValue !== '') {
        $status = explode(':', $wbssValue, 2)[0];
    }

    $state = $previousState;

    if ($castName !== '') {
        $state['last_cast_name'] = $castName;
    }

    if ($status !== '' && str_starts_with($status, 'cast_schedule_')) {
        $state['last_cast_status'] = substr($status, strlen('cast_schedule_'));
    }

    // LINE 誘導済みカウンタ更新（返答に LINE 誘導が含まれていた場合）
    $lineKeywords = ['LINE', 'ライン'];
    $replyHasLine = false;
    foreach ($lineKeywords as $kw) {
        if (mb_strpos($reply, $kw, 0, 'UTF-8') !== false) {
            $replyHasLine = true;
            break;
        }
    }
    if ($replyHasLine || !empty($response['show_line_cta'])) {
        $state['line_guided_count'] = (int) ($state['line_guided_count'] ?? 0) + 1;
    }

    if ($intent === 'cast_schedule') {
        $state['last_context'] = 'waiting_cast_confirmation';
    } elseif ($intent === 'cast_confirmation_yes') {
        $state['last_context'] = 'waiting_alone_answer';
    } elseif ($intent === 'first_visit_yes') {
        $state['last_context'] = '';
    } elseif ($intent === 'alone_yes') {
        $state['last_context'] = '';
    } elseif ($intent === 'reservation_yes') {
        $state['last_context'] = '';
    } elseif ($intent === 'group_visit') {
        $state['last_context'] = 'waiting_group_visit_answer';
    } elseif ($intent === 'group_visit_yes') {
        $state['last_context'] = '';
    } elseif ($intent === 'arrival_time') {
        $state['last_context'] = 'waiting_arrival_time_answer';
    } elseif ($intent === 'arrival_time_yes') {
        $state['last_context'] = '';
    } elseif ($intent === 'crowd') {
        $state['last_context'] = 'waiting_crowd_answer';
    } elseif ($intent === 'crowd_yes') {
        $state['last_context'] = '';
    } elseif ($intent === 'recommend_cast') {
        $state['last_context'] = '';
    } elseif (preg_match('/初めてですか|お一人で来られる予定ですか|何名様くらいでお考えですか/u', $reply)) {
        $state['last_context'] = 'waiting_first_visit_answer';
    } else {
        $state['last_context'] = '';
    }

    if ($state['last_context'] === '') {
        unset($state['last_context']);
    }

    return $state;
}

function twin_context_state_value(array $state): string
{
    return twin_chat_state_get_value($state, 'last_context', '');
}

function twin_finish_session(PDO $pdo, int $sessionId): void
{
    $stmt = $pdo->prepare('SELECT started_at FROM chat_sessions WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $sessionId]);
    $session = $stmt->fetch();

    if (!$session) {
        return;
    }

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM chat_messages
         WHERE session_id = :session_id AND sender = 'user'"
    );
    $countStmt->execute(['session_id' => $sessionId]);
    $messageCount = (int) $countStmt->fetchColumn();

    $finishedAt = date('Y-m-d H:i:s');
    $durationSeconds = max(0, strtotime($finishedAt) - strtotime((string) $session['started_at']));

    $updateStmt = $pdo->prepare(
        'UPDATE chat_sessions
         SET ended_at = COALESCE(ended_at, :finished_at),
             message_count = :message_count,
             session_duration_seconds = :session_duration_seconds
         WHERE id = :id'
    );
    $updateStmt->execute([
        'finished_at' => $finishedAt,
        'message_count' => $messageCount,
        'session_duration_seconds' => $durationSeconds,
        'id' => $sessionId,
    ]);
}

/**
 * v0.8.6: 回答直後に LINE 確認導線を出す対象 intent
 */
function twin_line_cta_intents(): array
{
    return [
        'price', 'price_estimate', 'drink_price',
        'attendance', 'cast_schedule',
        'group_visit', 'vip', 'recommend_cast',
        'first_visit', 'anxiety', 'alone',
        'crowd', 'arrival_time',
    ];
}

/**
 * v0.8.6: intent カテゴリに応じた自然な LINE 確認文を返す
 */
function twin_line_cta_message(string $intent): string
{
    switch ($intent) {
        case 'attendance':
        case 'cast_schedule':
        case 'recommend_cast':
            return '出勤状況は変更になる場合がありますので、ご来店前はLINEで確認していただくと安心です。';
        case 'price':
        case 'price_estimate':
        case 'drink_price':
        case 'vip':
            return '人数やお時間によって変わる場合がありますので、詳しいご予算はLINEでも確認できます。';
        case 'first_visit':
        case 'anxiety':
        case 'alone':
            return '初めての方も多いのでご安心ください。気になることはLINEでもお気軽にご相談できます。';
        case 'crowd':
        case 'arrival_time':
            return 'お席状況は時間帯で変わるため、ご来店前にLINEで確認いただくと安心です。';
        case 'group_visit':
            return 'ご人数やお時間が決まりましたら、LINEでお知らせいただくとお席のご準備がスムーズです。';
        default:
            return '気になることはLINEでお気軽にご相談ください。';
    }
}

/**
 * 問診完了時に crew_applicants へ INSERT（同一セッションの2回目以降は UPDATE）。
 * recruit_state のキー名は recruit_engine.php のステート設計に依存。
 */
function twin_upsert_crew_applicant(PDO $pdo, int $sessionId, string $storeKey, array $state, array $assessment, array $scoring): void
{
    // assessment は engine が構造化した確定値。state より優先して使う
    $prevHourlyRaw = $assessment['recruit_prev_hourly'] ?? $state['recruit_prev_hourly'] ?? null;
    $prevHourly = ($prevHourlyRaw !== null && (int) $prevHourlyRaw > 0) ? (int) $prevHourlyRaw : null;

    $stmt = $pdo->prepare('SELECT id FROM crew_applicants WHERE session_id = :session_id LIMIT 1');
    $stmt->execute(['session_id' => $sessionId]);
    $existing = $stmt->fetch();

    $params = [
        'store_key'      => $storeKey,
        'experience'     => $assessment['recruit_experience'] ?? $state['recruit_experience'] ?? null,
        'genre'          => $assessment['recruit_genre']      ?? $state['recruit_genre']      ?? null,
        'prev_hourly'    => $prevHourly,
        'referrals'      => $assessment['recruit_referrals']     ?? $state['recruit_referrals']     ?? null,
        'bring_now'      => $assessment['recruit_bring_now']     ?? $state['recruit_bring_now']     ?? null,
        'bring_trial'    => $assessment['recruit_bring_customer'] ?? $state['recruit_bring_customer'] ?? null,
        'days_per_week'  => $assessment['recruit_days']    ?? $state['recruit_days']    ?? null,
        'alcohol'        => $assessment['recruit_alcohol'] ?? $state['recruit_alcohol'] ?? null,
        'estimated_wage' => $assessment['recruit_estimate'] ?? null,
        'candidate_score' => $scoring['candidate_score'],
        'priority_grade'  => $scoring['priority_grade'],
        'score_detail'    => json_encode($scoring['score_detail'], JSON_UNESCAPED_UNICODE),
        'completed_at'    => date('Y-m-d H:i:s'), // 診断結果+内部評価+LINE CTA が揃った時刻
    ];

    if ($existing) {
        $params['id'] = (int) $existing['id'];
        $pdo->prepare(
            'UPDATE crew_applicants SET
                store_key=:store_key, experience=:experience, genre=:genre,
                prev_hourly=:prev_hourly, referrals=:referrals, bring_now=:bring_now,
                bring_trial=:bring_trial, days_per_week=:days_per_week, alcohol=:alcohol,
                estimated_wage=:estimated_wage, completed_at=:completed_at,
                candidate_score=:candidate_score, priority_grade=:priority_grade,
                score_detail=:score_detail
             WHERE id=:id'
        )->execute($params);
    } else {
        $params['session_id'] = $sessionId;
        $pdo->prepare(
            'INSERT INTO crew_applicants
                (session_id, store_key, experience, genre, prev_hourly, referrals,
                 bring_now, bring_trial, days_per_week, alcohol, estimated_wage,
                 completed_at, candidate_score, priority_grade, score_detail)
             VALUES
                (:session_id, :store_key, :experience, :genre, :prev_hourly, :referrals,
                 :bring_now, :bring_trial, :days_per_week, :alcohol, :estimated_wage,
                 :completed_at, :candidate_score, :priority_grade, :score_detail)'
        )->execute($params);
    }
}

function twin_normalize_cta_event_name(string $eventName, ?string $eventValue): array
{
    $legacyMap = [
        'line_clicked' => ['cta_click', 'line'],
        'price_clicked' => ['cta_click', 'price'],
        'instagram_clicked' => ['cta_click', 'instagram'],
        'cta_click_line' => ['cta_click', 'line'],
        'cta_click_price' => ['cta_click', 'price'],
        'cta_click_instagram' => ['cta_click', 'instagram'],
        'cta_view_line' => ['cta_view', 'line'],
        'cta_view_price' => ['cta_view', 'price'],
        'cta_view_instagram' => ['cta_view', 'instagram'],
    ];

    if (isset($legacyMap[$eventName])) {
        return $legacyMap[$eventName];
    }

    return [$eventName, $eventValue];
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        twin_json(['ok' => false, 'error' => 'POSTで送信してください。'], 405);
    }

    $raw = file_get_contents('php://input') ?: '';
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        twin_json(['ok' => false, 'error' => '送信内容を読み取れませんでした。'], 400);
    }

    $action = (string) ($input['action'] ?? 'message');
    $token = (string) ($input['session_token'] ?? '');

    if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) {
        twin_json(['ok' => false, 'error' => 'セッションを開始できませんでした。'], 400);
    }

    $pdo = twin_db();
    $storeKey = twin_current_store_key();
    $sessionId = twin_get_or_create_session($pdo, $token, $storeKey);

    if ($action === 'event') {
        $allowedEvents = ['trial_finished', 'cta_view', 'cta_click', 'line_clicked', 'instagram_clicked', 'price_clicked', 'cta_click_line', 'cta_click_price', 'cta_click_instagram', 'cta_view_line', 'cta_view_price', 'cta_view_instagram'];
        $eventName = (string) ($input['event_name'] ?? '');
        $eventValue = isset($input['event_value']) ? (string) $input['event_value'] : null;

        if (!in_array($eventName, $allowedEvents, true)) {
            twin_json(['ok' => false, 'error' => 'イベントを記録できませんでした。'], 400);
        }

        [$eventName, $eventValue] = twin_normalize_cta_event_name($eventName, $eventValue);
        twin_log_event($pdo, $sessionId, $eventName, $eventValue, $storeKey);

        if ($eventName === 'trial_finished') {
            twin_finish_session($pdo, $sessionId);
        } elseif ($eventName === 'cta_click' && $eventValue === 'line') {
            // LINE タップ = 応募意思とみなし crew_applicants に記録
            $pdo->prepare(
                'UPDATE crew_applicants
                 SET line_applied_at = COALESCE(line_applied_at, NOW())
                 WHERE session_id = :session_id'
            )->execute(['session_id' => $sessionId]);
        }
        if ($eventName === 'cta_click' && in_array((string) $eventValue, ['line', 'price', 'instagram'], true)) {
            $columns = ['session_id', 'conversion_type', 'created_at'];
            $values = [':session_id', ':conversion_type', 'NOW()'];
            $params = [
                'session_id' => $sessionId,
                'conversion_type' => (string) $eventValue,
            ];
            if (twin_db_column_exists($pdo, 'conversion_events', 'store_key')) {
                $columns[] = 'store_key';
                $values[] = ':store_key';
                $params['store_key'] = $storeKey;
            }
            $conversionStmt = $pdo->prepare(
                'INSERT INTO conversion_events (' . implode(', ', $columns) . ')
                 VALUES (' . implode(', ', $values) . ')'
            );
            $conversionStmt->execute($params);
        }

        twin_json(['ok' => true]);
    }

    $message = trim((string) ($input['message'] ?? ''));
    $messageLength = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);

    if ($message === '') {
        twin_json(['ok' => false, 'error' => 'メッセージを入力してください。'], 422);
    }

    if ($messageLength > 500) {
        twin_json(['ok' => false, 'error' => '500文字以内で入力してください。'], 422);
    }

    $stmt = $pdo->prepare(
        "SELECT created_at
         FROM chat_messages
         WHERE session_id = :session_id AND sender = 'user'
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute(['session_id' => $sessionId]);
    $lastMessage = $stmt->fetch();

    if ($lastMessage && strtotime((string) $lastMessage['created_at']) > time() - 1) {
        twin_json(['ok' => false, 'error' => '少しだけ間を空けて送信してください。'], 429);
    }

    $recentUserIntents = twin_recent_user_intents($pdo, $sessionId, 4);
    $recentMessages = twin_recent_messages($pdo, $sessionId, 5);
    $sessionState = twin_chat_state_get($token);
    $conversationState = twin_detect_conversation_context($recentMessages, $sessionState);
    $responseContext = [
        'session_id' => $sessionId,
        'recent_user_intents' => $recentUserIntents,
        'recent_messages' => $recentMessages,
        'conversation_context' => $conversationState['conversation_context'],
        'last_cast_name' => $conversationState['last_cast_name'],
        'last_cast_status' => $conversationState['last_cast_status'],
        'line_guided_count' => (int) ($sessionState['line_guided_count'] ?? 0),
        'session_state' => $sessionState, // recruit engine が step 状態を読むために必要
    ];

    $response = twin_generate_response($message, $responseContext);
    $intent = (string) ($response['intent'] ?? twin_detect_intent($message));
    $reply = (string) ($response['reply'] ?? '');
    $responseMode = (string) ($response['response_mode'] ?? 'rule');
    $wbssApiEventName = (string) ($response['wbss_api_event_name'] ?? '');
    $wbssApiEventValue = isset($response['wbss_api_event_value']) ? (string) $response['wbss_api_event_value'] : null;
    $castNameDetected = isset($response['cast_name_detected']) ? trim((string) $response['cast_name_detected']) : '';
    $recommendCastExecuted = !empty($response['recommend_cast_executed']);
    $recommendCastPrompted = !empty($response['recommend_cast_prompted']);

    if ($reply === '') {
        twin_json(['ok' => false, 'error' => '応答を生成できませんでした。'], 500);
    }

    // v0.8.6: 該当 intent の回答直後に LINE 確認導線を出す（1セッション最大2回まで）
    $lineGuidedBefore = (int) ($sessionState['line_guided_count'] ?? 0);
    $showLineCta = in_array($intent, twin_line_cta_intents(), true) && $lineGuidedBefore < 2;
    $lineCta = null;
    if ($showLineCta) {
        $lineConfig = twin_config();
        $lineCta = [
            'show' => true,
            'message' => twin_line_cta_message($intent),
            'url' => (string) ($lineConfig['links']['line'] ?? ''),
            'label' => 'LINEで相談・予約',
        ];
    }
    // CTA を表示した場合も誘導済みとしてカウントするため response に印を付ける
    $response['show_line_cta'] = $showLineCta;

    $pdo->beginTransaction();
    twin_save_user_message($pdo, $sessionId, $message, $intent, $storeKey);
    twin_log_event($pdo, $sessionId, 'conversation_context', $conversationState['conversation_context'] !== '' ? $conversationState['conversation_context'] : 'none', $storeKey);
    twin_log_event($pdo, $sessionId, 'intent_detected', $intent, $storeKey);
    // v0.8.6: 複合intent（secondary）を検出してログ保存
    $secondaryIntents = twin_detect_secondary_intents($message, $intent);
    if ($secondaryIntents) {
        twin_log_event($pdo, $sessionId, 'secondary_intents', implode(',', $secondaryIntents), $storeKey);
    }
    twin_log_event($pdo, $sessionId, 'response_mode', $responseMode, $storeKey);
    if ($castNameDetected !== '') {
        twin_log_event($pdo, $sessionId, 'cast_name_detected', $castNameDetected, $storeKey);
    }
    if ($wbssApiEventName !== '' && $wbssApiEventValue !== null) {
        twin_log_event($pdo, $sessionId, $wbssApiEventName, $wbssApiEventValue, $storeKey);
    }
    if ($recommendCastExecuted) {
        twin_log_event($pdo, $sessionId, 'recommend_cast_executed', null, $storeKey);
    }
    if ($recommendCastPrompted) {
        twin_log_event($pdo, $sessionId, 'recommend_cast_prompted', null, $storeKey);
    }
    if ($showLineCta) {
        twin_log_event($pdo, $sessionId, 'line_cta_shown', $intent, $storeKey);
    }
    twin_log_event($pdo, $sessionId, 'message_sent', null, $storeKey);
    twin_save_message($pdo, $sessionId, 'twin', $reply, $storeKey);
    $pdo->commit();

    // 問診完了時: event_logs + crew_applicants に保存
    if (!empty($response['recruit_complete']) && !empty($response['recruit_assessment'])) {
        $assessment = $response['recruit_assessment'];
        $assessmentJson = json_encode($assessment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        twin_log_event($pdo, $sessionId, 'recruit_assessment', $assessmentJson, $storeKey);
        twin_log_event($pdo, $sessionId, 'recruit_estimate', (string) ($assessment['recruit_estimate'] ?? ''), $storeKey);

        // assessment を優先ソースとしてスコア計算（session state より信頼できる）
        $scoringState = array_merge($response['recruit_state'] ?? [], $assessment);
        $scoring = twin_recruit_calc_score($scoringState);
        twin_upsert_crew_applicant($pdo, $sessionId, $storeKey, $response['recruit_state'] ?? [], $assessment, $scoring);
    }

    twin_chat_state_set($token, twin_chat_state_from_response($sessionState, $response));

    twin_json([
        'ok' => true,
        'reply' => $reply,
        'intent' => $intent,
        'response_mode' => $responseMode,
        'intent_label' => twin_intent_label($intent),
        'conversation_context' => $conversationState['conversation_context'] !== '' ? $conversationState['conversation_context'] : null,
        'cast_name_detected' => $castNameDetected !== '' ? $castNameDetected : null,
        'reply_html' => htmlspecialchars($reply, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
        'line_cta' => $lineCta,
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    twin_log('chat_api_error', ['message' => twin_safe_log_value($e->getMessage(), 240)]);
    twin_json(['ok' => false, 'error' => 'ただいま少し混み合っています。時間をおいてもう一度お試しください。'], 500);
}
