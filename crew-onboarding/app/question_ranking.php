<?php

declare(strict_types=1);

function twin_question_ranking_label(string $intent): string
{
    $intent = trim($intent);
    if ($intent === '') {
        $intent = 'other';
    }

    if (function_exists('twin_intent_label')) {
        $label = twin_intent_label($intent);
        if ($label !== '未分類' || $intent === 'other') {
            return $label;
        }
    }

    $labels = [
        'price' => '料金',
        'price_estimate' => '料金概算',
        'attendance' => '出勤',
        'cast_schedule' => '個別出勤',
        'business_hours' => '営業時間',
        'reservation' => '予約',
        'group_visit' => 'グループ来店',
        'arrival_time' => '来店時間',
        'crowd' => '混雑確認',
        'vip' => 'VIP',
        'drink_price' => 'ドリンク料金',
        'cast_type' => 'キャストタイプ',
        'recommend_cast' => 'キャスト推薦',
        'first_visit' => '初めて',
        'anxiety' => '初来店の不安',
        'alone' => '一人',
        'friends' => '友達',
        'budget' => '予算',
        'location' => '場所',
        'atmosphere' => '雰囲気',
        'champagne' => 'シャンパン',
        'recruit' => '求人',
        'nomination' => '指名',
        'other' => '未分類',
    ];

    return $labels[$intent] ?? $intent;
}

function twin_question_ranking_priority(string $intent, float $ratio): string
{
    $intent = trim($intent) !== '' ? trim($intent) : 'other';

    if ($intent === 'other') {
        return 'high';
    }

    if (in_array($intent, ['price', 'price_estimate', 'attendance', 'cast_schedule'], true) && $ratio >= 10.0) {
        return 'high';
    }

    if ($ratio >= 20.0) {
        return 'high';
    }

    if ($ratio >= 10.0) {
        return 'medium';
    }

    return 'low';
}

function twin_question_ranking_priority_label(string $priority): string
{
    return match ($priority) {
        'high' => '高',
        'medium' => '中',
        'low' => '低',
        default => $priority,
    };
}

function twin_question_ranking_priority_class(string $priority): string
{
    return match ($priority) {
        'high' => 'priority-high',
        'medium' => 'priority-medium',
        'low' => 'priority-low',
        default => '',
    };
}

// v0.8.9: v0.8.6以降のログを対象にするための基準日時（intent精度修正が入ったバージョン）
const TWIN_V086_SINCE = '2026-06-22 00:00:00';

function twin_question_ranking_window_options(): array
{
    return [
        '24h'       => ['label' => '過去24時間', 'days' => 1],
        '7d'        => ['label' => '過去7日', 'days' => 7],
        '30d'       => ['label' => '過去30日', 'days' => 30],
        'v086since' => ['label' => 'v0.8.6以降', 'days' => null, 'since' => TWIN_V086_SINCE],
        'all'       => ['label' => '全期間', 'days' => null],
    ];
}

function twin_question_ranking_window_config(string $value): array
{
    $value = strtolower(trim($value));
    $options = twin_question_ranking_window_options();

    return $options[$value] ?? $options['24h'];
}

function twin_question_ranking_where_clause(?int $days, string $column = 'm.created_at', ?string $since = null): string
{
    if ($since !== null) {
        return " AND {$column} >= '" . addslashes($since) . "'";
    }

    if ($days === null) {
        return '';
    }

    return " AND {$column} >= DATE_SUB(NOW(), INTERVAL {$days} DAY)";
}

function twin_question_ranking_suggestions(string $intent): array
{
    $intent = trim($intent) !== '' ? trim($intent) : 'other';

    $map = [
        'price' => [
            '料金概算の回答精度を上げる',
            '料金回答後にLINE誘導を追加する',
            '料金ページCTAを目立たせる',
        ],
        'price_estimate' => [
            '料金概算の回答精度を上げる',
            '料金回答後にLINE誘導を追加する',
            '料金ページCTAを目立たせる',
        ],
        'cast_schedule' => [
            'キャスト名の表記ゆれを強化する',
            '出勤確認後にLINE予約導線を出す',
            '指名希望かどうかを自然に聞く',
        ],
        'attendance' => [
            '出勤人数回答後に気になるキャストを聞く',
            '出勤情報の当日変更注意を入れる',
            'LINE確認導線を強化する',
        ],
        'first_visit' => [
            '初来店の不安を減らす返答を増やす',
            '一人来店・料金・雰囲気への導線を入れる',
            '店舗サイトに「初めての方へ」FAQを追加する',
        ],
        'anxiety' => [
            '初来店の不安を減らす返答を増やす',
            '一人来店・料金・雰囲気への導線を入れる',
            '店舗サイトに「初めての方へ」FAQを追加する',
        ],
        'alone' => [
            '一人来店でも安心できる説明を追加する',
            'スタッフ案内や席案内の説明を入れる',
        ],
        'group_visit' => [
            '人数確認後にLINE予約を促す',
            '複数名向けの席案内文を整える',
        ],
        'arrival_time' => [
            '来店時間確認後に空席確認導線を出す',
            '何名かを聞く流れへつなげる',
        ],
        'crowd' => [
            '混雑確認はLINE確認へ誘導する',
            '将来的にWBSS混雑情報と連携する',
        ],
        'vip' => [
            'VIP料金と通常席との差分を分かりやすくする',
            'VIP利用時はLINE確認を促す',
        ],
        'drink_price' => [
            'フリードリンク範囲を明確にする',
            '追加ドリンクや指名時の料金変動を説明する',
        ],
        'cast_type' => [
            '本日出勤中のキャストからおすすめできる導線を作る',
            'ユーザーの好みを聞く質問を追加する',
            '将来的にWBSSキャスト属性と連携する',
        ],
        'recommend_cast' => [
            '本日出勤中のキャストからおすすめできる導線を作る',
            'ユーザーの好みを聞く質問を追加する',
            '将来的にWBSSキャスト属性と連携する',
        ],
        'other' => [
            '未分類発言を確認し、新intent候補に反映する',
            'other率を20%以下に下げる',
            '頻出ワードから辞書追加する',
        ],
    ];

    return $map[$intent] ?? [
        '頻出ワードからintent辞書を追加する',
        '代表質問をもとにFAQを補強する',
    ];
}

function twin_question_ranking_example_rows(PDO $pdo, string $intent, int $limit = 3, ?int $days = 7, ?string $since = null): array
{
    $intent = trim($intent) !== '' ? trim($intent) : 'other';
    $windowSql = twin_question_ranking_where_clause($days, 'm.created_at', $since);
    $stmt = $pdo->prepare(
        "SELECT m.message, COUNT(*) AS cnt, MAX(m.created_at) AS last_seen
         FROM chat_messages m
         INNER JOIN chat_sessions cs ON cs.id = m.session_id
         WHERE m.sender = 'user'
           AND cs.session_token <> 'admin-settings'
           AND (m.intent <> 'recruit_finished' OR m.intent IS NULL)
           AND COALESCE(NULLIF(m.intent, ''), 'other') = :intent
           AND 1 = 1 {$windowSql}
         GROUP BY m.message
         ORDER BY cnt DESC, last_seen DESC, message ASC
         LIMIT {$limit}"
    );
    $stmt->execute(['intent' => $intent]);

    return array_map(static fn (array $row): array => [
        'message' => (string) $row['message'],
        'count' => (int) $row['cnt'],
    ], $stmt->fetchAll());
}

function twin_build_question_ranking(PDO $pdo, ?int $days = 7, ?string $since = null): array
{
    $windowSql = twin_question_ranking_where_clause($days, 'm.created_at', $since);
    $sql = "
        SELECT COALESCE(NULLIF(m.intent, ''), 'other') AS intent_key, COUNT(*) AS cnt
        FROM chat_messages m
        INNER JOIN chat_sessions cs ON cs.id = m.session_id
        WHERE m.sender = 'user'
          AND cs.session_token <> 'admin-settings'
          AND (m.intent <> 'recruit_finished' OR m.intent IS NULL)
          AND 1 = 1 {$windowSql}
        GROUP BY COALESCE(NULLIF(m.intent, ''), 'other')
        ORDER BY cnt DESC, intent_key ASC
    ";
    $stmt = $pdo->query($sql);
    $rows = $stmt ? $stmt->fetchAll() : [];

    $total = 0;
    foreach ($rows as $row) {
        $total += (int) ($row['cnt'] ?? 0);
    }

    $ranking = [];
    foreach ($rows as $row) {
        $intent = (string) ($row['intent_key'] ?? 'other');
        $count = (int) ($row['cnt'] ?? 0);
        $ratio = $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
        $priority = twin_question_ranking_priority($intent, $ratio);
        $examples = twin_question_ranking_example_rows($pdo, $intent, 3, $days, $since);
        $suggestions = twin_question_ranking_suggestions($intent);

        $ranking[] = [
            'intent' => $intent,
            'label' => twin_question_ranking_label($intent),
            'count' => $count,
            'ratio' => $ratio,
            'examples' => array_map(static fn (array $example): string => (string) $example['message'], $examples),
            'suggestions' => $suggestions,
            'priority' => $priority,
            'priority_label' => twin_question_ranking_priority_label($priority),
            'priority_class' => twin_question_ranking_priority_class($priority),
        ];
    }

    usort(
        $ranking,
        static function (array $a, array $b): int {
            $priorityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
            $pa = $priorityOrder[$a['priority']] ?? 9;
            $pb = $priorityOrder[$b['priority']] ?? 9;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            if ($a['count'] !== $b['count']) {
                return $b['count'] <=> $a['count'];
            }

            return strcmp((string) $a['intent'], (string) $b['intent']);
        }
    );

    return $ranking;
}

function twin_build_openai_diagnostics(PDO $pdo): array
{
    $requestCount = 0;
    $successCount = 0;
    $failureCount = 0;
    $fallbackCount = 0;
    $usageSavedCount = 0;
    $lastUsageAt = null;
    $lastErrorMessage = null;
    $responseModeOpenaiSessions = 0;

    try {
        $requestCount = (int) $pdo->query("SELECT COUNT(*) FROM event_logs WHERE event_name = 'openai_request'")->fetchColumn();
        $successCount = (int) $pdo->query("SELECT COUNT(*) FROM event_logs WHERE event_name = 'openai_success'")->fetchColumn();
        $failureCount = (int) $pdo->query("SELECT COUNT(*) FROM event_logs WHERE event_name = 'openai_error'")->fetchColumn();
        $fallbackCount = (int) $pdo->query("SELECT COUNT(*) FROM event_logs WHERE event_name = 'openai_fallback'")->fetchColumn();
        $usageSavedCount = (int) $pdo->query("SELECT COUNT(*) FROM ai_usage_logs")->fetchColumn();
        $lastUsageAt = $pdo->query("SELECT MAX(created_at) FROM ai_usage_logs")->fetchColumn();
        $lastErrorMessage = $pdo->query(
            "SELECT event_value
             FROM event_logs
             WHERE event_name = 'openai_error'
             ORDER BY id DESC
             LIMIT 1"
        )->fetchColumn();
        $responseModeOpenaiSessions = (int) $pdo->query(
            "SELECT COUNT(DISTINCT session_id)
             FROM event_logs
             WHERE event_name = 'response_mode'
               AND event_value = 'openai'"
        )->fetchColumn();
    } catch (Throwable $e) {
        error_log('[TWIN question_ranking] openai diagnostics lookup failed: ' . $e->getMessage());
    }

    $lastErrorMessage = is_string($lastErrorMessage) ? trim($lastErrorMessage) : '';
    if ($lastErrorMessage !== '') {
        $lastErrorMessage = mb_strimwidth($lastErrorMessage, 0, 120, '…', 'UTF-8');
    }

    $openaiConnectionScore = 0;
    if ($requestCount > 0 && $usageSavedCount > 0) {
        $openaiConnectionScore = (int) round(min(100, ($successCount / max(1, $requestCount)) * 100));
    }

    return [
        'request_count' => $requestCount,
        'success_count' => $successCount,
        'failure_count' => $failureCount,
        'fallback_count' => $fallbackCount,
        'usage_saved_count' => $usageSavedCount,
        'last_usage_at' => is_string($lastUsageAt) && trim($lastUsageAt) !== '' ? (string) $lastUsageAt : null,
        'last_error_message' => $lastErrorMessage !== '' ? $lastErrorMessage : null,
        'response_mode_openai_sessions' => $responseModeOpenaiSessions,
        'openai_connection_score' => $openaiConnectionScore,
    ];
}

function twin_build_health_score_breakdown(array $stats, array $openaiDiagnostics = []): array
{
    $otherRate = (float) ($stats['other_rate'] ?? 0);
    $lineCtr = (float) ($stats['line_ctr'] ?? 0);
    $lineCtaShownRate = (float) ($stats['line_cta_shown_rate'] ?? 0);
    $improvementCount = (int) ($stats['response_improvements_count'] ?? 0);
    $wbssSuccessRate = (float) ($stats['wbss_success_rate'] ?? 0);
    $priceRate = (float) ($stats['price_rate'] ?? 0);

    $wbssScoreTotal = (int) ($stats['wbss_score_total'] ?? 0);
    $wbssSuccessCount = (int) ($stats['wbss_success_count'] ?? 0);
    $wbssErrorCount = (int) ($stats['wbss_error_count'] ?? 0);
    $wbssNotFoundCount = (int) ($stats['wbss_not_found_count'] ?? 0);

    $intentScore = (int) max(0, floor(100 - $otherRate));
    // v0.8.7: LINE CTA表示率で評価（CTRではなくシステムが導線を表示できているかを評価）
    $lineScore = $lineCtaShownRate >= 60 ? 100 : ($lineCtaShownRate >= 40 ? 80 : ($lineCtaShownRate >= 20 ? 60 : ($lineCtaShownRate > 0 ? 40 : 20)));
    $naturalScore = $improvementCount === 0 ? 100 : ($improvementCount <= 3 ? 80 : ($improvementCount <= 10 ? 60 : 40));
    // 出勤精度: success / (success + error) * 100。cast_schedule_not_found は分母に含めない
    $attendanceScore = $wbssScoreTotal === 0
        ? -1
        : (int) round(min(100, max(50, $wbssSuccessRate)));
    $attendanceNote = $wbssScoreTotal === 0
        ? '未計測'
        : ('WBSS成功率 ' . number_format($wbssSuccessRate, 1) . '% / 成功 ' . $wbssSuccessCount . '件 / エラー ' . $wbssErrorCount . '件'
            . ($wbssNotFoundCount > 0 ? ' / キャスト名未一致 ' . $wbssNotFoundCount . '件' : ''));

    $priceScore = $priceRate <= 0 ? 0 : (int) round(min(100, max(50, 65 + ($priceRate * 2.0))));
    $openaiScore = (int) ($openaiDiagnostics['openai_connection_score'] ?? 0);

    return [
        [
            'key' => 'intent_precision',
            'label' => 'intent精度',
            'score' => $intentScore,
            'note' => 'other率 ' . number_format($otherRate, 1) . '%',
        ],
        [
            'key' => 'line_guide',
            'label' => 'LINE CTA表示率',
            'score' => $lineScore,
            'note' => 'CTA表示率 ' . number_format($lineCtaShownRate, 1) . '% / LINE CTR ' . number_format($lineCtr, 1) . '%',
        ],
        [
            'key' => 'conversation_naturalness',
            'label' => '会話自然度',
            'score' => $naturalScore,
            'note' => '改善候補 ' . $improvementCount . '件',
        ],
        [
            'key' => 'attendance_accuracy',
            'label' => '出勤精度',
            'score' => $attendanceScore,
            'note' => $attendanceNote,
        ],
        [
            'key' => 'price_accuracy',
            'label' => '料金精度',
            'score' => $priceScore,
            'note' => '料金質問率 ' . number_format($priceRate, 1) . '%',
        ],
        [
            'key' => 'openai_connection',
            'label' => 'OpenAI接続',
            'score' => $openaiScore,
            'note' => 'usage保存 ' . number_format((int) ($openaiDiagnostics['usage_saved_count'] ?? 0)) . '件',
        ],
    ];
}

function twin_build_weekly_improvement_top5(array $ranking): array
{
    $top5 = [];
    foreach ($ranking as $row) {
        if (count($top5) >= 5) {
            break;
        }

        $intent = (string) ($row['intent'] ?? 'other');
        $label = (string) ($row['label'] ?? $intent);
        $count = (int) ($row['count'] ?? 0);
        $ratio = (float) ($row['ratio'] ?? 0);
        $priority = (string) ($row['priority'] ?? 'low');
        $suggestions = $row['suggestions'] ?? [];
        $mainSuggestion = is_array($suggestions) && !empty($suggestions) ? (string) $suggestions[0] : '改善提案を確認してください';

        if ($intent === 'other') {
            $text = sprintf('未分類発言が多いため、intent辞書を追加する（%d件 / %.1f%%）', $count, $ratio);
        } else {
            $text = sprintf('%sが多いため、%s（%d件 / %.1f%%）', $label, $mainSuggestion, $count, $ratio);
        }

        $top5[] = [
            'rank' => count($top5) + 1,
            'intent' => $intent,
            'label' => $label,
            'count' => $count,
            'ratio' => $ratio,
            'priority' => $priority,
            'priority_label' => twin_question_ranking_priority_label($priority),
            'text' => $text,
        ];
    }

    return $top5;
}

function twin_build_reply_improvement_summary(array $responseImprovements): array
{
    $groups = [];
    foreach ($responseImprovements as $row) {
        $type = (string) ($row['type'] ?? 'other');
        if (!isset($groups[$type])) {
            $groups[$type] = [
                'type' => $type,
                'type_label' => (string) ($row['type_label'] ?? $type),
                'count' => 0,
                'representative_reply' => '',
                'improvement_note' => (string) ($row['note'] ?? ''),
            ];
        }
        $groups[$type]['count']++;
        if ($groups[$type]['representative_reply'] === '' && trim((string) ($row['message'] ?? '')) !== '') {
            $groups[$type]['representative_reply'] = (string) $row['message'];
        }
    }

    $suggestionMap = [
        'A' => '短期記憶・同一返答抑制を見直す',
        'B' => '肯定返答後の分岐と context 解除条件を調整する',
        'C' => 'intent辞書を追加し、other率を下げる',
    ];

    foreach ($groups as $type => &$group) {
        $group['suggested_fix'] = $suggestionMap[$type] ?? '返答ロジックを見直す';
        $group['priority'] = $type === 'C' ? 'high' : ($group['count'] >= 3 ? 'medium' : 'low');
        $group['priority_label'] = twin_question_ranking_priority_label($group['priority']);
    }
    unset($group);

    $summary = array_values($groups);
    usort($summary, static function (array $a, array $b): int {
        if ($a['count'] !== $b['count']) {
            return $b['count'] <=> $a['count'];
        }
        return strcmp((string) $a['type'], (string) $b['type']);
    });

    return array_slice($summary, 0, 5);
}

/**
 * 採用コンシェルジュ向け改善提案を生成する（ルールベース）。
 *
 * $stats キー一覧:
 *   recruit_session_count    int   問診を開始したセッション数（recruit_* intent が 1 件以上あるセッション）
 *   applicant_total          int   問診完了数（crew_applicants.completed_at IS NOT NULL）
 *   line_applied_total       int   LINE応募タップ数
 *   grade_line_rows          array priority_grade / total / lined の配列
 *   experience_dist          array ['none'=>N, 'some'=>N, 'yes'=>N]
 *   bring_trial_dist         array ['yes'=>N, 'maybe'=>N, 'no'=>N]
 *   days_dist                array ['5_plus'=>N, '3_4'=>N, '1_2'=>N]
 *   pending_interview        int   line_applied_at IS NOT NULL AND interview_at IS NULL
 *   pending_decision         int   interview_at IS NOT NULL AND hired_at IS NULL AND rejected_at IS NULL
 *   pending_wbss             int   hired_at IS NOT NULL AND hired_employee_id IS NULL
 *   other_rate               float 未分類 intent 率（全体）
 *   response_improvements_count int 返答改善候補件数
 *   ai_usage_count           int   OpenAI 利用回数
 *   has_unrecorded_usage     bool  usage 未保存フラグ
 *   openai_diagnostics       array OpenAI 診断データ
 *   line_pre_intent_rows     array LINE クリック直前 intent 分布
 *   line_pre_intent_total    int   LINE クリック直前 intent 件数合計
 */
function twin_build_improvement_suggestions(array $stats): array
{
    $items = [];

    // ── 採用数値 ────────────────────────────────────────────────
    $recruitSessionCount = (int) ($stats['recruit_session_count'] ?? 0);
    $applicantTotal      = (int) ($stats['applicant_total'] ?? 0);
    $lineAppliedTotal    = (int) ($stats['line_applied_total'] ?? 0);
    $gradeLineRows       = (array) ($stats['grade_line_rows'] ?? []);
    $experienceDist      = (array) ($stats['experience_dist'] ?? []);
    $bringTrialDist      = (array) ($stats['bring_trial_dist'] ?? []);
    $daysDist            = (array) ($stats['days_dist'] ?? []);
    $pendingInterview    = (int) ($stats['pending_interview'] ?? 0);
    $pendingDecision     = (int) ($stats['pending_decision'] ?? 0);
    $pendingWbss         = (int) ($stats['pending_wbss'] ?? 0);

    // ── システム品質 ─────────────────────────────────────────────
    $otherRate           = (float) ($stats['other_rate'] ?? 0);
    $improvementCount    = (int) ($stats['response_improvements_count'] ?? 0);
    $aiUsageCount        = (int) ($stats['ai_usage_count'] ?? 0);
    $hasUnrecordedUsage  = (bool) ($stats['has_unrecorded_usage'] ?? false);
    $openaiDiagnostics   = (array) ($stats['openai_diagnostics'] ?? []);
    $linePreIntentRows   = (array) ($stats['line_pre_intent_rows'] ?? []);
    $linePreIntentTotal  = (int) ($stats['line_pre_intent_total'] ?? 0);

    $openaiRequestCount    = (int) ($openaiDiagnostics['request_count'] ?? 0);
    $openaiUsageSavedCount = (int) ($openaiDiagnostics['usage_saved_count'] ?? 0);
    $openaiConnectionScore = (int) ($openaiDiagnostics['openai_connection_score'] ?? 0);
    $openaiFallbackCount   = (int) ($openaiDiagnostics['fallback_count'] ?? 0);
    $openaiModeSessions    = (int) ($openaiDiagnostics['response_mode_openai_sessions'] ?? 0);

    // ══════════════════════════════════════════════════════════════
    // ① 問診完了率
    // ══════════════════════════════════════════════════════════════
    if ($recruitSessionCount >= 3) {
        $completionRate = round($applicantTotal / $recruitSessionCount * 100, 1);
        if ($completionRate < 50.0) {
            $items[] = [
                'title'   => '問診途中の離脱を減らす',
                'priority' => 'high',
                'current' => '完了率 ' . number_format($completionRate, 1) . '%（' . $applicantTotal . '/' . $recruitSessionCount . '件）',
                'reason'  => '問診途中で離脱する応募者が多く見られます。',
                'action'  => '質問数・順番・文面を見直してください。最初の質問（経験）のハードルを下げることを推奨します。',
                'effect'  => '問診完了数が増え、AI評価・LINE誘導の母数が増加します。',
                'effort'  => '30〜60分',
                'link'    => '#interview-stats',
            ];
        } elseif ($completionRate < 70.0) {
            $items[] = [
                'title'   => '問診完了率を改善する',
                'priority' => 'medium',
                'current' => '完了率 ' . number_format($completionRate, 1) . '%（' . $applicantTotal . '/' . $recruitSessionCount . '件）',
                'reason'  => '完了率が70%未満です。一定数が途中離脱しています。',
                'action'  => '直近質問ログで最後に記録された intent を確認し、離脱が集中するステップを特定してください。',
                'effect'  => '問診データが増え、Grade精度が向上します。',
                'effort'  => '30分',
                'link'    => '#interview-stats',
            ];
        }
    }

    // ══════════════════════════════════════════════════════════════
    // ② LINE応募率
    // ══════════════════════════════════════════════════════════════
    if ($applicantTotal >= 3) {
        $lineRate = round($lineAppliedTotal / $applicantTotal * 100, 1);
        if ($lineRate < 20.0) {
            $items[] = [
                'title'   => 'LINE応募への誘導を強化する',
                'priority' => 'high',
                'current' => 'LINE応募率 ' . number_format($lineRate, 1) . '%（' . $lineAppliedTotal . '/' . $applicantTotal . '件）',
                'reason'  => '問診は完了していますがLINE応募に繋がっていません。',
                'action'  => '完了メッセージのCTA文言を改善してください。「今すぐLINEで応募」ボタンを目立たせることを推奨します。',
                'effect'  => '応募転換率が向上し、採用候補者数が増加します。',
                'effort'  => '30分',
                'link'    => '#cta',
            ];
        } elseif ($lineRate < 40.0) {
            $items[] = [
                'title'   => 'LINE応募率を40%以上に引き上げる',
                'priority' => 'medium',
                'current' => 'LINE応募率 ' . number_format($lineRate, 1) . '%',
                'reason'  => 'LINE応募率がまだ伸びしろがあります。',
                'action'  => '完了画面の文言・ボタン位置・訴求内容を見直してください。',
                'effect'  => '面接機会の増加',
                'effort'  => '30分',
                'link'    => '#cta',
            ];
        }
    }

    // ══════════════════════════════════════════════════════════════
    // ③ A・Bグレード応募者の離脱
    // ══════════════════════════════════════════════════════════════
    $gradeABTotal = 0;
    $gradeABLined = 0;
    foreach ($gradeLineRows as $gr) {
        if (in_array((string) ($gr['priority_grade'] ?? ''), ['A', 'B'], true)) {
            $gradeABTotal += (int) ($gr['total'] ?? 0);
            $gradeABLined += (int) ($gr['lined'] ?? 0);
        }
    }
    if ($gradeABTotal >= 2) {
        $abLineRate = round($gradeABLined / $gradeABTotal * 100, 1);
        if ($abLineRate < 50.0) {
            $items[] = [
                'title'   => '採用可能性の高い応募者の離脱を防ぐ',
                'priority' => 'high',
                'current' => 'A/BグレードLINE率 ' . number_format($abLineRate, 1) . '%（' . $gradeABLined . '/' . $gradeABTotal . '件）',
                'reason'  => 'AIが採用可能性が高いと評価した応募者がLINE応募に至っていません。',
                'action'  => '応募導線を優先的に改善してください。CTA文言に体験入店・個別相談の訴求を追加することを推奨します。',
                'effect'  => '採用コスト削減、面接の質向上',
                'effort'  => '30〜45分',
                'link'    => '#applicants',
            ];
        }
    }

    // ══════════════════════════════════════════════════════════════
    // ④ 未経験応募者が多い
    // ══════════════════════════════════════════════════════════════
    $expNone = (int) ($experienceDist['none'] ?? 0);
    $expSome = (int) ($experienceDist['some'] ?? 0);
    $expYes  = (int) ($experienceDist['yes'] ?? 0);
    $expTotal = $expNone + $expSome + $expYes;
    if ($expTotal >= 5) {
        $noneRate = round(($expNone + $expSome) / $expTotal * 100, 1);
        if ($noneRate >= 60.0) {
            $items[] = [
                'title'   => '未経験応募者向けコンテンツを充実させる',
                'priority' => 'medium',
                'current' => '未経験・経験少し ' . number_format($noneRate, 1) . '%（' . ($expNone + $expSome) . '/' . $expTotal . '件）',
                'reason'  => '未経験応募者が多くなっています。不安解消が最重要課題です。',
                'action'  => '「未経験でもできる理由」「体験入店の流れ」「先輩キャストの声」など不安解消コンテンツを充実させてください。',
                'effect'  => '未経験者の問診完了率・LINE応募率の向上',
                'effort'  => '60〜90分',
                'link'    => '#interview-stats',
            ];
        }
    }

    // ══════════════════════════════════════════════════════════════
    // ⑤ 経験者応募が多い
    // ══════════════════════════════════════════════════════════════
    if ($expTotal >= 5) {
        $yesRate = round($expYes / $expTotal * 100, 1);
        if ($yesRate >= 60.0) {
            $items[] = [
                'title'   => '経験者向け待遇説明の精度を上げる',
                'priority' => 'medium',
                'current' => '経験者 ' . number_format($yesRate, 1) . '%（' . $expYes . '/' . $expTotal . '件）',
                'reason'  => '経験者応募が多くなっています。時給査定・バック体系の情報が決め手になります。',
                'action'  => '時給査定の基準、バック体系、指名料の仕組みをより詳しく説明するよう問診を改善してください。',
                'effect'  => '経験者のLINE応募率向上',
                'effort'  => '45分',
                'link'    => '#interview-stats',
            ];
        }
    }

    // ══════════════════════════════════════════════════════════════
    // ⑥ 呼客あり応募者が多い
    // ══════════════════════════════════════════════════════════════
    $bringYes   = (int) ($bringTrialDist['yes'] ?? 0);
    $bringMaybe = (int) ($bringTrialDist['maybe'] ?? 0);
    $bringNo    = (int) ($bringTrialDist['no'] ?? 0);
    $bringTotal = $bringYes + $bringMaybe + $bringNo;
    if ($bringTotal >= 3) {
        $bringRate = round(($bringYes + $bringMaybe) / $bringTotal * 100, 1);
        if ($bringRate >= 50.0) {
            $items[] = [
                'title'   => '呼客可能応募者の体験入店を優先案内する',
                'priority' => 'low',
                'current' => '呼客できる・できそう ' . number_format($bringRate, 1) . '%（' . ($bringYes + $bringMaybe) . '/' . $bringTotal . '件）',
                'reason'  => '呼客が期待できる応募者が増えています。',
                'action'  => '面接・体験入店を優先的に案内してください。LINEで個別対応を早める対応を推奨します。',
                'effect'  => '売上貢献度の高い採用の実現',
                'effort'  => '対応工数次第',
                'link'    => '#applicants',
            ];
        }
    }

    // ══════════════════════════════════════════════════════════════
    // ⑦ 週4以上希望者が多い
    // ══════════════════════════════════════════════════════════════
    $days5plus = (int) ($daysDist['5_plus'] ?? 0);
    $days34    = (int) ($daysDist['3_4'] ?? 0);
    $days12    = (int) ($daysDist['1_2'] ?? 0);
    $daysTotal = $days5plus + $days34 + $days12;
    if ($daysTotal >= 3) {
        $highDaysRate = round(($days5plus + $days34) / $daysTotal * 100, 1);
        if ($highDaysRate >= 50.0) {
            $items[] = [
                'title'   => '面接までのリードタイムを短縮する',
                'priority' => 'low',
                'current' => '週3日以上希望 ' . number_format($highDaysRate, 1) . '%（' . ($days5plus + $days34) . '/' . $daysTotal . '件）',
                'reason'  => '出勤意欲の高い応募者が多く見られます。',
                'action'  => 'LINE応募後の返信スピードを上げ、面接日程を早めに設定してください。',
                'effect'  => '高頻度出勤者の確保',
                'effort'  => '対応工数次第',
                'link'    => '#applicants',
            ];
        }
    }

    // ══════════════════════════════════════════════════════════════
    // ⑧ 面接未設定（LINE応募済み → interview_at なし）
    // ══════════════════════════════════════════════════════════════
    if ($pendingInterview >= 1) {
        $items[] = [
            'title'   => 'LINE応募済みの面接日程を設定する',
            'priority' => 'high',
            'current' => '面接未設定 ' . $pendingInterview . '件',
            'reason'  => 'LINE応募済みなのに面接日が未設定の応募者がいます。時間が経つほど温度が下がります。',
            'action'  => '管理画面「応募者一覧」から対象者を確認し、面接日程を調整してください。',
            'effect'  => '応募者の離脱防止',
            'effort'  => '即日対応推奨',
            'link'    => '#applicants',
        ];
    }

    // ══════════════════════════════════════════════════════════════
    // ⑨ 採用待ち（面接済み → 判定なし）
    // ══════════════════════════════════════════════════════════════
    if ($pendingDecision >= 1) {
        $items[] = [
            'title'   => '面接後の採用判定を進める',
            'priority' => 'medium',
            'current' => '判定待ち ' . $pendingDecision . '件',
            'reason'  => '面接後の採用・不採用が記録されていない応募者があります。',
            'action'  => '管理画面「応募者一覧」から対象者を確認し、採用フローを進めてください。',
            'effect'  => '採用データの整合性確保',
            'effort'  => '15分',
            'link'    => '#applicants',
        ];
    }

    // ══════════════════════════════════════════════════════════════
    // ⑩ WBSS未連携（採用済み → hired_employee_id なし）
    // ══════════════════════════════════════════════════════════════
    if ($pendingWbss >= 1) {
        $items[] = [
            'title'   => '採用済み応募者のWBSS社員IDを紐付ける',
            'priority' => 'medium',
            'current' => 'WBSS未連携 ' . $pendingWbss . '件',
            'reason'  => '採用済み応募者がWBSSへ未登録です。出勤データとの連携ができません。',
            'action'  => '管理画面「応募者詳細」から社員IDを入力してください。',
            'effect'  => '採用→出勤→売上のデータ連携が完成します。',
            'effort'  => '5〜10分/人',
            'link'    => '#applicants',
        ];
    }

    // ══════════════════════════════════════════════════════════════
    // システム品質（問診精度・AI品質）
    // ══════════════════════════════════════════════════════════════
    if ($otherRate >= 20.0) {
        $items[] = [
            'title'   => '未分類の自由入力に対応する',
            'priority' => 'medium',
            'current' => 'other率 ' . number_format($otherRate, 1) . '%',
            'reason'  => '応募者の自由入力がAIに正しく分類されていません。',
            'action'  => '「直近質問」タブでother発言を確認し、対応するintentを追加してください。',
            'effect'  => '問診精度・会話の自然さが向上します。',
            'effort'  => '30〜60分',
            'link'    => '#question-ranking',
        ];
    }

    if ($improvementCount > 3) {
        $items[] = [
            'title'   => '返答の自然さを改善する',
            'priority' => 'low',
            'current' => '返答改善候補 ' . $improvementCount . '件',
            'reason'  => '繰り返し返答や不自然な応答パターンが残っています。',
            'action'  => '返答品質セクションを確認し、はい後分岐・重複返答を修正してください。',
            'effect'  => '問診の完了率・好感度が向上します。',
            'effort'  => '45〜90分',
            'link'    => '#conversation-quality',
        ];
    }

    if (!empty($linePreIntentRows) && $linePreIntentTotal > 0) {
        $topRow    = $linePreIntentRows[0];
        $topIntent = (string) ($topRow['intent'] ?? 'other');
        $topCount  = (int) ($topRow['cnt'] ?? 0);
        $ratio     = round($topCount / $linePreIntentTotal * 100, 1);
        if ($ratio >= 35.0) {
            $items[] = [
                'title'   => 'LINE応募直前の会話パターンを活かす',
                'priority' => 'low',
                'current' => twin_question_ranking_label($topIntent) . ' ' . number_format($ratio, 1) . '%',
                'reason'  => 'LINE応募に進む前の会話が特定の質問に偏っています。',
                'action'  => 'その質問への返答直後に自然なLINE誘導を差し込んでください。',
                'effect'  => 'LINE応募率のさらなる改善',
                'effort'  => '30分',
                'link'    => '#cta',
            ];
        }
    }

    if ($openaiConnectionScore === 0 && ($openaiRequestCount > 0 || $openaiFallbackCount > 0 || $aiUsageCount > 0 || $openaiModeSessions > 0)) {
        $items[] = [
            'title'   => 'OpenAI利用状況を確認する',
            'priority' => 'high',
            'current' => 'OpenAI接続 0',
            'reason'  => 'OpenAI呼び出し状況が見えにくく、利用額が0円のままです。',
            'action'  => 'OpenAI診断セクションで呼び出し回数・成功回数・fallback回数を確認してください。',
            'effect'  => 'AI会話の実利用と課金把握ができるようになります。',
            'effort'  => '15分',
            'link'    => '#openai-diagnostics',
        ];
    }

    if ($hasUnrecordedUsage || (($openaiRequestCount > 0 || $openaiModeSessions > 0) && $openaiUsageSavedCount === 0)) {
        $items[] = [
            'title'   => 'usage保存処理を確認する',
            'priority' => 'medium',
            'current' => 'usage保存 0件',
            'reason'  => 'OpenAI会話はあるのに usage だけ残っていない可能性があります。',
            'action'  => 'openai_engine の usage 保存処理と ai_usage_logs を確認してください。',
            'effect'  => 'AIコスト把握の精度向上',
            'effort'  => '15分',
            'link'    => '#openai-diagnostics',
        ];
    }

    usort($items, static function (array $a, array $b): int {
        $priorityOrder = ['high' => 0, 'medium' => 1, 'low' => 2];
        $pa = $priorityOrder[$a['priority']] ?? 9;
        $pb = $priorityOrder[$b['priority']] ?? 9;
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }
        return strcmp((string) $a['title'], (string) $b['title']);
    });

    return $items;
}
