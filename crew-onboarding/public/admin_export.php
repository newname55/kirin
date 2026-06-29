<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/db.php';
require_once dirname(__DIR__) . '/app/admin_common.php';
require_once dirname(__DIR__) . '/app/settings.php';
require_once dirname(__DIR__) . '/app/question_ranking.php';
require_once dirname(__DIR__) . '/app/privacy.php';

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function twin_export_csv_value(mixed $value): string
{
    $value = twin_mask_personal_data((string) $value);
    if ($value !== '' && preg_match('/^[=+\-@]/u', $value)) {
        $value = "'" . $value;
    }

    return $value;
}

function twin_export_csv(array $header, iterable $rows, string $filename): never
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');

    $out = fopen('php://output', 'wb');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($rows as $row) {
        $sanitized = [];
        foreach ($header as $column) {
            $sanitized[] = twin_export_csv_value($row[$column] ?? '');
        }
        fputcsv($out, $sanitized);
    }
    fclose($out);
    exit;
}

function twin_export_error(string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

function twin_export_message_rows(PDO $pdo, array $sessionIds): array
{
    if (!$sessionIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT m.session_id, m.created_at, m.sender, COALESCE(NULLIF(m.intent, ''), 'other') AS intent, m.message
         FROM chat_messages m
         WHERE m.session_id IN ({$placeholders})
         ORDER BY m.session_id DESC, m.id ASC"
    );
    $stmt->execute(array_values($sessionIds));

    $rows = $stmt->fetchAll();
    foreach ($rows as &$row) {
        $row['message'] = twin_mask_personal_data((string) ($row['message'] ?? ''));
    }
    unset($row);

    return $rows;
}

function twin_export_session_rows(PDO $pdo, array $sessionIds): array
{
    if (!$sessionIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT id, started_at
         FROM chat_sessions
         WHERE id IN ({$placeholders})
         ORDER BY started_at DESC, id DESC"
    );
    $stmt->execute(array_values($sessionIds));

    return $stmt->fetchAll();
}

function twin_export_recent_session_ids(PDO $pdo, int $limit = 100): array
{
    $stmt = $pdo->prepare(
        "SELECT id
         FROM chat_sessions
         WHERE session_token <> 'admin-settings'
         ORDER BY started_at DESC, id DESC
         LIMIT {$limit}"
    );
    $stmt->execute();

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function twin_export_intent_summary(PDO $pdo, array $sessionIds): array
{
    if (!$sessionIds) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($sessionIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(m.intent, ''), 'other') AS intent_key, COUNT(*) AS cnt
         FROM chat_messages m
         INNER JOIN chat_sessions cs ON cs.id = m.session_id
         WHERE cs.id IN ({$placeholders}) AND m.sender = 'user'
         GROUP BY COALESCE(NULLIF(m.intent, ''), 'other')
         ORDER BY cnt DESC, intent_key ASC"
    );
    $stmt->execute(array_values($sessionIds));

    return $stmt->fetchAll();
}

twin_admin_require_login();

$pdo = twin_db();
$type = strtolower(trim((string) ($_GET['type'] ?? '')));
$recentSessionIds = twin_export_recent_session_ids($pdo, 100);

if ($type === '') {
    twin_export_error('type を指定してください。', 400);
}

// v0.7.1: AI利用額集計（analysis_json 用）
$aiUsage = ['today_jpy' => 0, 'month_jpy' => 0, 'total_jpy' => 0, 'total_tokens' => 0, 'total_calls' => 0];
try {
    $aiTodayRow = $pdo->query("SELECT COALESCE(SUM(estimated_cost_jpy),0) as cost FROM ai_usage_logs WHERE DATE(created_at) = CURDATE()")->fetch();
    $aiMonthRow = $pdo->query("SELECT COALESCE(SUM(estimated_cost_jpy),0) as cost FROM ai_usage_logs WHERE DATE_FORMAT(created_at,'%Y-%m') = DATE_FORMAT(NOW(),'%Y-%m')")->fetch();
    $aiTotalRow = $pdo->query("SELECT COALESCE(SUM(estimated_cost_jpy),0) as cost, COALESCE(SUM(total_tokens),0) as tokens, COUNT(*) as calls FROM ai_usage_logs")->fetch();
    $aiUsage = [
        'today_jpy'    => (float)($aiTodayRow['cost'] ?? 0),
        'month_jpy'    => (float)($aiMonthRow['cost'] ?? 0),
        'total_jpy'    => (float)($aiTotalRow['cost'] ?? 0),
        'total_tokens' => (int)($aiTotalRow['tokens'] ?? 0),
        'total_calls'  => (int)($aiTotalRow['calls'] ?? 0),
    ];
} catch (\Throwable $e) {
    // テーブル未作成の場合は0
}

$openaiDiagnostics = twin_build_openai_diagnostics($pdo);

if ($type === 'analysis_json') {
    $sessions = twin_export_session_rows($pdo, $recentSessionIds);
    $messages = twin_export_message_rows($pdo, $recentSessionIds);
    $topIntents = twin_export_intent_summary($pdo, $recentSessionIds);
    $realQuestionRanking = twin_build_question_ranking($pdo, 7);
    $weeklyImprovementTop5 = twin_build_weekly_improvement_top5($realQuestionRanking);
    $sessionMap = [];
    foreach ($sessions as $session) {
        $sessionMap[(int) $session['id']] = [
            'session_id' => (int) $session['id'],
            'started_at' => (string) $session['started_at'],
            'messages' => [],
        ];
    }
    foreach ($messages as $message) {
        $sessionId = (int) $message['session_id'];
        if (!isset($sessionMap[$sessionId])) {
            continue;
        }
        $sessionMap[$sessionId]['messages'][] = [
            'sender' => (string) $message['sender'],
            'intent' => (string) $message['intent'],
            'message' => (string) $message['message'],
            'created_at' => (string) $message['created_at'],
        ];
    }

    $summaryMessages = 0;
    foreach ($sessionMap as $session) {
        $summaryMessages += count($session['messages']);
    }

    // v0.6.8: other_messages
    $otherMsgRows = $pdo->query(
        "SELECT m.session_id, m.message, m.created_at
         FROM chat_messages m
         INNER JOIN chat_sessions cs ON cs.id = m.session_id
         WHERE m.sender = 'user'
           AND (m.intent = 'other' OR m.intent IS NULL OR m.intent = '')
           AND cs.session_token <> 'admin-settings'
         ORDER BY m.id DESC
         LIMIT 50"
    )->fetchAll();
    $otherMessages = array_map(static function (array $row): array {
        return [
            'session_id' => (int) $row['session_id'],
            'message' => twin_mask_personal_data((string) $row['message']),
            'created_at' => (string) $row['created_at'],
        ];
    }, $otherMsgRows);

    // v0.6.8: suggested_intents
    $suggestedIntentPatterns = [
        'anxiety'    => ['不安', '怖い', '緊張', '初キャバ', '初めてで不安'],
        'drink'      => ['飲めない', 'お酒弱い', 'ノンアル', 'ソフトドリンク'],
        'age'        => ['年齢', '何歳', '若い', '年上', '客層'],
        'payment'    => ['カード', 'クレカ', '現金', 'PayPay', '支払い'],
        'dress_code' => ['服装', 'スーツ', '私服', 'ドレスコード'],
        'parking'    => ['駐車場', '車', '代行', 'タクシー'],
    ];
    $suggestedIntents = [];
    foreach ($suggestedIntentPatterns as $intentName => $keywords) {
        $matched = [];
        foreach ($otherMsgRows as $row) {
            $text = (string) $row['message'];
            foreach ($keywords as $kw) {
                if (mb_strpos($text, $kw) !== false) {
                    $matched[] = $text;
                    break;
                }
            }
        }
        if ($matched) {
            $suggestedIntents[] = [
                'intent' => $intentName,
                'count' => count($matched),
                'examples' => array_slice($matched, 0, 5),
            ];
        }
    }

    // v0.6.8: dropoff_sessions
    $dropoffRows = $pdo->query(
        "SELECT cs.id AS session_id, cs.started_at, COUNT(m.id) AS user_msg_count
         FROM chat_sessions cs
         INNER JOIN chat_messages m ON m.session_id = cs.id AND m.sender = 'user'
         WHERE cs.session_token <> 'admin-settings'
           AND cs.ended_at IS NULL
           AND cs.id NOT IN (
               SELECT DISTINCT session_id FROM event_logs WHERE event_name = 'cta_click'
           )
         GROUP BY cs.id, cs.started_at
         HAVING user_msg_count BETWEEN 1 AND 2
         ORDER BY cs.id DESC
         LIMIT 30"
    )->fetchAll();
    $dropoffSessions = array_map(static function (array $row): array {
        return [
            'session_id' => (int) $row['session_id'],
            'started_at' => (string) $row['started_at'],
            'user_msg_count' => (int) $row['user_msg_count'],
        ];
    }, $dropoffRows);

    // v0.6.8: line_opportunity_sessions
    $lineIntents = ['price', 'attendance', 'cast_schedule', 'reservation', 'budget', 'business_hours'];
    $lineIntentPh = implode(',', array_fill(0, count($lineIntents), '?'));
    $lineStmt = $pdo->prepare(
        "SELECT cs.id AS session_id, cs.started_at
         FROM chat_sessions cs
         INNER JOIN chat_messages m ON m.session_id = cs.id AND m.sender = 'user'
         WHERE cs.session_token <> 'admin-settings'
           AND m.intent IN ({$lineIntentPh})
           AND cs.id NOT IN (
               SELECT DISTINCT session_id FROM event_logs WHERE event_name = 'cta_click' AND event_value = 'line'
           )
         GROUP BY cs.id, cs.started_at
         ORDER BY cs.id DESC
         LIMIT 20"
    );
    $lineStmt->execute($lineIntents);
    $lineOpportunityRows = $lineStmt->fetchAll();
    $lineOpportunitySessions = array_map(static function (array $row): array {
        return [
            'session_id' => (int) $row['session_id'],
            'started_at' => (string) $row['started_at'],
        ];
    }, $lineOpportunityRows);

    // v0.6.8: response_improvement_candidates
    $dupRows = $pdo->query(
        "SELECT m1.session_id, m1.message AS twin_message
         FROM chat_messages m1
         INNER JOIN chat_messages m2
           ON m2.session_id = m1.session_id AND m2.id > m1.id AND m2.sender = 'twin' AND m2.message = m1.message
         WHERE m1.sender = 'twin'
           AND m1.session_id IN (SELECT id FROM chat_sessions WHERE session_token <> 'admin-settings')
         GROUP BY m1.session_id, m1.message
         LIMIT 10"
    )->fetchAll();
    $responseImprovements = [];
    foreach ($dupRows as $row) {
        $responseImprovements[] = [
            'session_id' => (int) $row['session_id'],
            'type' => 'A',
            'description' => '同じTWIN返答が繰り返されています',
            'message' => (string) $row['twin_message'],
        ];
    }
    $otherRepeatRows = $pdo->query(
        "SELECT session_id, COUNT(*) AS other_count
         FROM chat_messages
         WHERE sender = 'user' AND (intent = 'other' OR intent IS NULL OR intent = '')
           AND session_id IN (SELECT id FROM chat_sessions WHERE session_token <> 'admin-settings')
         GROUP BY session_id
         HAVING other_count >= 3
         ORDER BY other_count DESC
         LIMIT 10"
    )->fetchAll();
    foreach ($otherRepeatRows as $row) {
        $responseImprovements[] = [
            'session_id' => (int) $row['session_id'],
            'type' => 'C',
            'description' => 'other intent が ' . $row['other_count'] . ' 回連続',
            'message' => '',
        ];
    }

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

    $wbssErrorCount = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM event_logs
         WHERE event_name = 'wbss_api_call'
           AND (
               event_value LIKE 'attendance_error%'
               OR event_value LIKE 'cast_schedule_error%'
           )"
    )->fetchColumn();

    $castNotFoundCount = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM event_logs
         WHERE event_name = 'wbss_api_call'
           AND event_value LIKE 'cast_schedule_not_found%'"
    )->fetchColumn();

    $totalUserMessages = (int) $pdo->query(
        "SELECT COUNT(*) FROM chat_messages m
         INNER JOIN chat_sessions cs ON cs.id = m.session_id
         WHERE m.sender = 'user' AND cs.session_token <> 'admin-settings'"
    )->fetchColumn();

    $priceEstimateCount = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM chat_messages m
         INNER JOIN chat_sessions cs ON cs.id = m.session_id
         WHERE m.sender = 'user'
           AND m.intent = 'price_estimate'
           AND cs.session_token <> 'admin-settings'"
    )->fetchColumn();
    $priceRate = $totalUserMessages > 0 ? round(($priceEstimateCount / $totalUserMessages) * 100, 1) : 0.0;

    $otherCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM chat_messages m
         INNER JOIN chat_sessions cs ON cs.id = m.session_id
         WHERE m.sender = 'user'
           AND (m.intent = 'other' OR m.intent IS NULL OR m.intent = '')
           AND cs.session_token <> 'admin-settings'"
    )->fetchColumn();

    $lineClickCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM event_logs WHERE event_name = 'cta_click' AND event_value = 'line'"
    )->fetchColumn();
    $wbssTotalCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM event_logs WHERE event_name = 'wbss_api_call'"
    )->fetchColumn();
    $wbssSuccessCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM event_logs
         WHERE event_name = 'wbss_api_call'
           AND (
               event_value LIKE 'attendance_success%'
               OR event_value LIKE 'cast_schedule_success%'
           )"
    )->fetchColumn();
    $wbssSuccessRate = $wbssTotalCount > 0 ? round(($wbssSuccessCount / $wbssTotalCount) * 100, 1) : 0.0;

    $sessionCount = count($sessionMap);
    $lineCtr = $sessionCount > 0 ? round(($lineClickCount / $sessionCount) * 100, 1) : 0.0;
    $otherRate = $totalUserMessages > 0 ? round(($otherCount / $totalUserMessages) * 100, 1) : 0.0;
    $intentCoverScore = (int) max(0, floor(100 - $otherRate));
    $lineGuideScore = $lineCtr >= 20 ? 100 : ($lineCtr >= 10 ? 70 : ($lineCtr >= 5 ? 40 : 20));
    $improvementCount = count($responseImprovements);
    $naturalScore = $improvementCount === 0 ? 100 : ($improvementCount <= 3 ? 80 : ($improvementCount <= 10 ? 60 : 40));
    $healthScore = (int) round(($intentCoverScore + $lineGuideScore + $naturalScore) / 3);
    $healthScoreBreakdown = twin_build_health_score_breakdown([
        'other_rate' => $otherRate,
        'line_ctr' => $lineCtr,
        'response_improvements_count' => $improvementCount,
        'wbss_success_rate' => $wbssSuccessRate,
        'price_rate' => $priceRate,
    ], $openaiDiagnostics);

    $replyImprovementSummary = twin_build_reply_improvement_summary($responseImprovements);
    $linePreIntentSummary = [];
    $linePreIntentTotal = 0;
    foreach ($linePreClickIntents as $row) {
        $intent = (string) ($row['intent'] ?? 'other');
        $count = (int) ($row['cnt'] ?? 0);
        $linePreIntentTotal += $count;
        $linePreIntentSummary[] = [
            'intent' => $intent,
            'label' => twin_question_ranking_label($intent),
            'count' => $count,
            'ratio' => 0.0,
        ];
    }
    foreach ($linePreIntentSummary as &$row) {
        $row['ratio'] = $linePreIntentTotal > 0 ? round(($row['count'] / $linePreIntentTotal) * 100, 1) : 0.0;
    }
    unset($row);

    $openaiSessionCount = (int) $pdo->query(
        "SELECT COUNT(DISTINCT session_id)
         FROM event_logs
         WHERE event_name = 'response_mode'
           AND event_value = 'openai'"
    )->fetchColumn();
    $wbssOk = $wbssErrorCount === 0;
    $responseMode = twin_app_response_mode($pdo, twin_config());
    $operationsSummary = [
        'health_score' => $healthScore,
        'line_ctr' => $lineCtr,
        'other_rate' => $otherRate,
        'ai_today' => $aiUsage['today_jpy'],
        'ai_month' => $aiUsage['month_jpy'],
        'wbss_status' => $wbssOk ? '接続正常' : '要確認',
        'response_mode' => $responseMode,
        'response_mode_label' => match ($responseMode) {
            'rule' => '安定モード',
            'openai' => 'AI会話モード',
            'hybrid' => 'ハイブリッドモード',
            default => $responseMode,
        },
        'usage_unrecorded' => $openaiSessionCount > 0 && $aiUsage['total_calls'] === 0,
    ];

    // 改善提案用: 採用フロー集計
    $_expDistEx = $pdo->query("SELECT experience AS val, COUNT(*) AS cnt FROM crew_applicants WHERE completed_at IS NOT NULL AND experience IS NOT NULL GROUP BY experience")->fetchAll(PDO::FETCH_ASSOC);
    $_expDistMap = [];
    foreach ($_expDistEx as $_r) { $_expDistMap[(string) $_r['val']] = (int) $_r['cnt']; }
    $_gradeLineEx = $pdo->query("SELECT priority_grade, COUNT(*) AS total, SUM(line_applied_at IS NOT NULL) AS lined FROM crew_applicants WHERE completed_at IS NOT NULL AND priority_grade IS NOT NULL GROUP BY priority_grade ORDER BY priority_grade")->fetchAll(PDO::FETCH_ASSOC);

    $improvementSuggestions = twin_build_improvement_suggestions([
        'recruit_session_count' => (int) $pdo->query("SELECT COUNT(DISTINCT m.session_id) FROM chat_messages m INNER JOIN chat_sessions cs ON cs.id = m.session_id WHERE m.intent LIKE 'recruit_%' AND cs.session_token <> 'admin-settings'")->fetchColumn(),
        'applicant_total'       => (int) $pdo->query("SELECT COUNT(*) FROM crew_applicants WHERE completed_at IS NOT NULL")->fetchColumn(),
        'line_applied_total'    => (int) $pdo->query("SELECT COUNT(*) FROM crew_applicants WHERE line_applied_at IS NOT NULL")->fetchColumn(),
        'grade_line_rows'       => $_gradeLineEx,
        'experience_dist'       => $_expDistMap,
        'bring_trial_dist'      => [],
        'days_dist'             => [],
        'pending_interview'     => (int) $pdo->query("SELECT COUNT(*) FROM crew_applicants WHERE line_applied_at IS NOT NULL AND interview_at IS NULL AND hired_at IS NULL AND rejected_at IS NULL")->fetchColumn(),
        'pending_decision'      => (int) $pdo->query("SELECT COUNT(*) FROM crew_applicants WHERE interview_at IS NOT NULL AND hired_at IS NULL AND rejected_at IS NULL")->fetchColumn(),
        'pending_wbss'          => (int) $pdo->query("SELECT COUNT(*) FROM crew_applicants WHERE hired_at IS NOT NULL AND hired_employee_id IS NULL")->fetchColumn(),
        'other_rate'            => $otherRate,
        'response_improvements_count' => $improvementCount,
        'ai_usage_count'        => (int) ($aiUsage['total_calls'] ?? 0),
        'has_unrecorded_usage'  => $openaiSessionCount > 0 && $aiUsage['total_calls'] === 0,
        'openai_diagnostics'    => $openaiDiagnostics,
        'line_pre_intent_rows'  => $linePreClickIntents,
        'line_pre_intent_total' => $linePreIntentTotal,
    ]);

    $payload = [
        'exported_at' => date('c'),
        'version' => APP_VERSION,
        'summary' => [
            'sessions' => count($sessionMap),
            'messages' => $summaryMessages,
            'analysis_window_days' => 7,
            'top_intents' => array_map(static function (array $row): array {
                return [
                    'intent' => (string) $row['intent_key'],
                    'count' => (int) $row['cnt'],
                ];
            }, $topIntents),
        ],
        'operations_summary' => $operationsSummary,
        'improvement_suggestions' => $improvementSuggestions,
        'conversations' => array_values($sessionMap),
        'real_question_ranking' => $realQuestionRanking,
        'twin_health_score' => [
            'score' => $healthScore,
            'intent_cover_score' => $intentCoverScore,
            'line_guide_score' => $lineGuideScore,
            'natural_score' => $naturalScore,
        ],
        'line_pre_intent_summary' => $linePreIntentSummary,
        'reply_improvement_summary' => $replyImprovementSummary,
        'weekly_improvement_top5' => $weeklyImprovementTop5,
        'openai_diagnostics' => $openaiDiagnostics,
        'health_score_breakdown' => $healthScoreBreakdown,
        'other_messages' => $otherMessages,
        'suggested_intents' => $suggestedIntents,
        'dropoff_sessions' => $dropoffSessions,
        'line_opportunity_sessions' => $lineOpportunitySessions,
        'response_improvement_candidates' => $responseImprovements,
        'ai_usage_summary' => [
            'today_jpy'    => $aiUsage['today_jpy'],
            'month_jpy'    => $aiUsage['month_jpy'],
            'total_jpy'    => $aiUsage['total_jpy'],
            'total_tokens' => $aiUsage['total_tokens'],
            'total_calls'  => $aiUsage['total_calls'],
        ],
    ];

    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="twin_analysis_' . date('Ymd_His') . '.json"');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if ($type === 'conversations') {
    $rows = twin_export_message_rows($pdo, $recentSessionIds);
    twin_export_csv(
        ['session_id', 'created_at', 'sender', 'intent', 'message'],
        $rows,
        'twin_conversations_' . date('Ymd_His') . '.csv'
    );
}

if ($type === 'events') {
    $stmt = $pdo->prepare(
        "SELECT session_id, created_at, event_name, event_value
         FROM event_logs
         ORDER BY created_at DESC, id DESC"
    );
    $stmt->execute();
    twin_export_csv(
        ['session_id', 'created_at', 'event_name', 'event_value'],
        $stmt->fetchAll(),
        'twin_events_' . date('Ymd_His') . '.csv'
    );
}

if ($type === 'wbss') {
    $stmt = $pdo->prepare(
        "SELECT session_id, created_at, event_value
         FROM event_logs
         WHERE event_name = 'wbss_api_call'
           AND session_id IN (
               SELECT id FROM chat_sessions WHERE session_token <> 'admin-settings'
           )
         ORDER BY created_at DESC, id DESC"
    );
    $stmt->execute();
    twin_export_csv(
        ['session_id', 'created_at', 'event_value'],
        $stmt->fetchAll(),
        'twin_wbss_' . date('Ymd_His') . '.csv'
    );
}

if ($type === 'cta') {
    $stmt = $pdo->prepare(
        "SELECT session_id, created_at, event_name, event_value
         FROM event_logs
         WHERE event_name IN ('cta_view', 'cta_click')
           AND session_id IN (
               SELECT id FROM chat_sessions WHERE session_token <> 'admin-settings'
           )
         ORDER BY created_at DESC, id DESC"
    );
    $stmt->execute();
    twin_export_csv(
        ['session_id', 'created_at', 'event_name', 'event_value'],
        $stmt->fetchAll(),
        'twin_cta_' . date('Ymd_His') . '.csv'
    );
}

twin_export_error('type が不正です。', 400);
