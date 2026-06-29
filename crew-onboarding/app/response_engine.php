<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/privacy.php';
require_once __DIR__ . '/engines/rule_engine.php';
require_once __DIR__ . '/engines/openai_engine.php';
require_once __DIR__ . '/engines/recruit_engine.php';

function twin_response_log_event(array $context, string $eventName, ?string $eventValue = null): void
{
    $sessionId = (int) ($context['session_id'] ?? 0);
    if ($sessionId <= 0) {
        return;
    }

    try {
        $pdo = twin_db();
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
            $params['store_key'] = function_exists('twin_current_store_key') ? twin_current_store_key() : 'seika';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO event_logs (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $values) . ')'
        );
        $stmt->execute($params);
    } catch (Throwable $e) {
        twin_log('response_engine_event_log_failed', [
            'event_name' => $eventName,
            'message' => $e->getMessage(),
        ]);
    }
}

function twin_response_mode_from_config(): string
{
    $config = twin_config();
    $mode = strtolower(trim((string) ($config['response_mode'] ?? 'rule')));

    return in_array($mode, ['rule', 'openai', 'hybrid'], true) ? $mode : 'rule';
}

/**
 * v0.7.3: hybridモードでruleエンジンを使うintentリスト
 * これ以外のintentはOpenAIに回す（hybridモード時のみ）
 */
function twin_hybrid_rule_intents(): array
{
    return [
        'price', 'price_estimate', 'vip', 'drink_price',
        'business_hours', 'location', 'attendance', 'cast_schedule',
        'group_visit', 'arrival_time', 'crowd', 'reservation',
    ];
}

function twin_generate_response(string $message, array $context = []): array
{
    // crew-onboarding は常に問診型採用チャット（recruit engine）で処理する
    $sessionState = $context['session_state'] ?? [];
    return twin_recruit_engine_response($message, $sessionState, $context);

    // 以下は将来モード切替のために残す（現在は到達しない）
    $intent = twin_detect_intent($message, $context);
    $mode = twin_response_mode_from_config();

    // 出勤確認系は常にruleエンジンで処理
    if (in_array($intent, ['attendance', 'cast_schedule'], true)) {
        $response = twin_rule_engine_response($message, $intent, $context);
        if (!isset($response['response_mode'])) {
            $response['response_mode'] = 'rule';
        }

        return $response;
    }

    if ($mode === 'openai') {
        try {
            $response = twin_openai_engine_response($message, array_merge($context, ['intent' => $intent]));

            if (trim((string) ($response['reply'] ?? '')) === '') {
                throw new RuntimeException('OpenAI returned an empty reply.');
            }

            $response['intent'] = $intent;
            $response['response_mode'] = 'openai';

            return $response;
        } catch (Throwable $e) {
            twin_response_log_event($context, 'openai_fallback', $e->getMessage());
            twin_log('response_engine_fallback', [
                'message' => twin_safe_log_value($e->getMessage(), 240),
                'intent' => $intent,
            ]);

            $response = twin_rule_engine_response($message, $intent, $context);
            $response['response_mode'] = 'fallback_rule';

            return $response;
        }
    }

    // v0.7.3: hybridモード — ruleで返すintentはruleエンジン、それ以外はOpenAI
    if ($mode === 'hybrid') {
        if (in_array($intent, twin_hybrid_rule_intents(), true)) {
            $response = twin_rule_engine_response($message, $intent, $context);
            if (!isset($response['response_mode'])) {
                $response['response_mode'] = 'rule';
            }
            return $response;
        }

        // OpenAIに回す（anxiety, cast_type, recommend_cast, atmosphere, repeat_visitor, general_chat, other など）
        try {
            $response = twin_openai_engine_response($message, array_merge($context, ['intent' => $intent]));

            if (trim((string) ($response['reply'] ?? '')) === '') {
                throw new RuntimeException('OpenAI returned an empty reply.');
            }

            $response['intent'] = $intent;
            $response['response_mode'] = 'hybrid';

            return $response;
        } catch (Throwable $e) {
            twin_response_log_event($context, 'openai_fallback', $e->getMessage());
            twin_log('response_engine_fallback', [
                'message' => twin_safe_log_value($e->getMessage(), 240),
                'intent' => $intent,
                'mode' => 'hybrid',
            ]);

            $response = twin_rule_engine_response($message, $intent, $context);
            $response['response_mode'] = 'fallback_rule';

            return $response;
        }
    }

    return twin_rule_engine_response($message, $intent, $context);
}
