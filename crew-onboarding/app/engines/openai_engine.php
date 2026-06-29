<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/knowledge/seika.php';
require_once dirname(__DIR__) . '/knowledge/stores.php';
require_once dirname(__DIR__) . '/privacy.php';
require_once dirname(__DIR__) . '/store.php';

function twin_openai_event_value(string $value, int $maxLength = 160): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    return function_exists('mb_strimwidth')
        ? mb_strimwidth($value, 0, $maxLength, '…', 'UTF-8')
        : substr($value, 0, $maxLength);
}

function twin_openai_log_event(array $context, string $eventName, ?string $eventValue = null): void
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

        if (function_exists('twin_db_column_exists') && twin_db_column_exists($pdo, 'event_logs', 'store_key')) {
            $columns[] = 'store_key';
            $values[] = ':store_key';
            $params['store_key'] = twin_current_store_key();
        }

        $stmt = $pdo->prepare(
            'INSERT INTO event_logs (' . implode(', ', $columns) . ')
             VALUES (' . implode(', ', $values) . ')'
        );
        $stmt->execute($params);
    } catch (Throwable $e) {
        error_log('[TWIN openai] failed to log ' . $eventName . ': ' . $e->getMessage());
    }
}

function twin_openai_system_prompt(array $knowledge = [], array $character = []): string
{
    $knowledgeBlock = function_exists('twin_store_knowledge_prompt_block')
        ? twin_store_knowledge_prompt_block($knowledge)
        : twin_seika_knowledge_prompt_block($knowledge);

    // v0.9.1: キャラクター設定が有効なら AI名・肩書きをプロンプトに反映
    $aiName  = trim((string) ($character['ai_name'] ?? '')) ?: 'TWIN SEIKA';
    $aiTitle = trim((string) ($character['ai_title'] ?? '')) ?: 'クラブ星華の来店前チャットボット';

    return <<<PROMPT
あなたは「{$aiName}」です。{$aiTitle}です。

人格: 20代女性・丁寧・親しみやすい・高級感あり・押し売りしない
返答: 2〜3文・最後に自然な質問を返す・絵文字は控えめ

目的: 初来店の不安を和らげ、LINE予約へつなげる

制約:
- 実在キャスト本人のふりをしない
- 断言せず、不明なことはLINEで確認を促す
- 料金・営業時間・場所は公式情報を優先

公式情報：
{$knowledgeBlock}
PROMPT;
}

function twin_openai_engine_response(string $message, array $context = []): array
{
    $config = twin_config();
    $apiKey = trim((string) ($config['openai_api_key'] ?? ''));

    try {
        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY is not configured.');
        }

        if (!function_exists('curl_init')) {
            throw new RuntimeException('cURL extension is not available.');
        }

        $model = (string) ($config['openai_model'] ?? 'gpt-4.1-mini');
        $timeout = max(1, (int) ($config['openai_timeout_seconds'] ?? 8));
        $intent = (string) ($context['intent'] ?? twin_detect_intent($message, $context));
        $knowledge = twin_store_config(twin_current_store_key());
        // v0.9.1: アクティブなキャラクター設定を取得（DBエラー時はデフォルト値にフォールバック）
        $character = [];
        try {
            if (!function_exists('twin_ai_character_load_active')) {
                require_once dirname(__DIR__) . '/ai_character_settings.php';
            }
            $character = twin_ai_character_load_active(twin_db(), twin_current_store_key());
        } catch (Throwable $e) {
            error_log('[TWIN openai] character load failed: ' . $e->getMessage());
        }
        $conversationContext = trim((string) ($context['conversation_context'] ?? ''));
        $lastCastName = trim((string) ($context['last_cast_name'] ?? ''));
        $lastCastStatus = trim((string) ($context['last_cast_status'] ?? ''));
        $recentMessages = $context['recent_messages'] ?? [];
        $recentTranscript = [];
        if (is_array($recentMessages)) {
            foreach ($recentMessages as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $sender = (string) ($row['sender'] ?? '');
                $content = twin_mask_personal_data(trim((string) ($row['message'] ?? '')));
                if ($content === '') {
                    continue;
                }
                $recentTranscript[] = strtoupper($sender ?: 'UNKNOWN') . ': ' . $content;
            }
        }

        $contextLines = [];
        if ($conversationContext !== '') {
            $contextLines[] = '会話コンテキスト: ' . $conversationContext;
        }
        if ($lastCastName !== '') {
            $contextLines[] = '直前のキャスト名: ' . $lastCastName;
        }
        if ($lastCastStatus !== '') {
            $contextLines[] = '直前のキャスト状況: ' . $lastCastStatus;
        }
        if ($recentTranscript) {
            $contextLines[] = '直近会話: ' . implode(' / ', array_slice($recentTranscript, -5));
        }
        $contextBlock = $contextLines ? implode("\n", $contextLines) . "\n\n" : '';

        twin_openai_log_event($context, 'openai_request', twin_openai_event_value('model=' . $model . '; intent=' . $intent));

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => twin_openai_system_prompt($knowledge, $character)],
                [
                    'role' => 'user',
                    'content' => $contextBlock . "ユーザーの発言: " . twin_mask_personal_data($message) . "\n推定intent: {$intent}\n\n回答ルール:\n- 2〜4文程度で返答する\n- 一方的に説明しすぎず、最後は自然に質問を返す\n- 公式情報にないことは断定しない\n- 料金、営業時間、場所は公式情報を優先する\n- 不明なことは公式ページまたはLINEで確認してもらう",
                ],
            ],
            'temperature' => 0.7,
            'max_tokens' => 220,
        ];

        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        if ($ch === false) {
            throw new RuntimeException('Failed to initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(3, $timeout),
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $statusCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if ($raw === false || $errno !== 0) {
            throw new RuntimeException('OpenAI request failed: ' . ($error ?: ('errno ' . $errno)));
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('OpenAI returned invalid JSON.');
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $apiMessage = $decoded['error']['message'] ?? 'OpenAI HTTP error.';
            throw new RuntimeException($apiMessage);
        }

        $reply = trim((string) ($decoded['choices'][0]['message']['content'] ?? ''));
        if ($reply === '') {
            throw new RuntimeException('OpenAI returned an empty reply.');
        }

        twin_openai_log_event($context, 'openai_success', twin_openai_event_value('status=' . $statusCode . '; intent=' . $intent));

        // v0.7.3: ai_usage_logs への記録（失敗してもAPIレスポンスには影響させない）
        try {
            $usageData = $decoded['usage'] ?? null;
            if (is_array($usageData)) {
                $promptTokens     = (int) ($usageData['prompt_tokens'] ?? 0);
                $completionTokens = (int) ($usageData['completion_tokens'] ?? 0);
                $totalTokens      = (int) ($usageData['total_tokens'] ?? 0);
                $modelCosts       = twin_openai_model_costs();
                $modelRates       = $modelCosts[$model] ?? $modelCosts['gpt-4.1-mini'];
                $inputRate        = (float) $modelRates['input'];
                $outputRate       = (float) $modelRates['output'];
                $usdJpyRate       = (float) ($config['usd_jpy_rate'] ?? 150.0);
                $costUsd          = ($promptTokens / 1_000_000 * $inputRate) + ($completionTokens / 1_000_000 * $outputRate);
                $costJpy          = $costUsd * $usdJpyRate;
                $sessionId        = (int) ($context['session_id'] ?? 0);

                // v0.7.3: session_id が 0 の場合は警告ログ
                if ($sessionId === 0) {
                    error_log('TWIN ai_usage_logs warning: session_id is 0 or missing in context');
                }

                $pdo = twin_db();
                $stmt = $pdo->prepare(
                    "INSERT INTO ai_usage_logs (session_id, model, prompt_tokens, completion_tokens, total_tokens, estimated_cost_usd, estimated_cost_jpy)
                     VALUES (:session_id, :model, :prompt_tokens, :completion_tokens, :total_tokens, :estimated_cost_usd, :estimated_cost_jpy)"
                );
                $stmt->execute([
                    ':session_id'        => $sessionId,
                    ':model'             => $model,
                    ':prompt_tokens'     => $promptTokens,
                    ':completion_tokens' => $completionTokens,
                    ':total_tokens'      => $totalTokens,
                    ':estimated_cost_usd'=> round($costUsd, 8),
                    ':estimated_cost_jpy'=> round($costJpy, 4),
                ]);
                twin_openai_log_event($context, 'openai_usage_saved', twin_openai_event_value('tokens=' . $totalTokens . '; session=' . $sessionId));
                // v0.7.3: 保存成功ログ（デバッグ用。不要になったらコメントアウト可）
                error_log("TWIN ai_usage_logs saved: tokens={$totalTokens} session={$sessionId}");
            }
        } catch (\Throwable $e) {
            error_log('TWIN ai_usage_logs insert error: ' . $e->getMessage());
        }

        return [
            'reply' => $reply,
            'intent' => $intent,
            'response_mode' => 'openai',
        ];
    } catch (\Throwable $e) {
        twin_openai_log_event($context, 'openai_error', twin_openai_event_value($e->getMessage()));
        throw $e;
    }
}
