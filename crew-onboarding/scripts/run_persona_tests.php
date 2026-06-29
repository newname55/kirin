<?php

/**
 * crew-onboarding ペルソナテストスクリプト
 *
 * 使い方（Raspi4 上で実行）:
 *   php scripts/run_persona_tests.php
 *   php scripts/run_persona_tests.php --persona P03       # 1件だけ実行
 *   php scripts/run_persona_tests.php --clean             # テストセッションを事前削除してから実行
 *   php scripts/run_persona_tests.php --dry-run           # DB書き込みなしで intent だけ確認
 *
 * 注意:
 *   - このスクリプトは開発用 Raspi4 (twin_crew_dev または twin DB) を対象とする
 *   - 本番 Raspi5 では実行しないこと
 *   - Raspi4: php scripts/run_persona_tests.php
 */

declare(strict_types=1);

define('TWIN_CLI_TEST', true);

// ── ブートストラップ ──────────────────────────────────────────────
$appDir = dirname(__DIR__) . '/app';

// settings.php は config.php が require するため先に読む
require_once $appDir . '/settings.php';
// db.php: twin_config() / twin_db() / twin_db_column_exists() / twin_safe_log_value()
require_once $appDir . '/db.php';
// privacy.php: twin_mask_personal_data()
require_once $appDir . '/privacy.php';
// store.php: twin_current_store_key()
require_once $appDir . '/store.php';
// response_engine.php: twin_generate_response() + 全エンジン (rule / openai / recruit)
require_once $appDir . '/response_engine.php';

// ── CLI ヘルパー ─────────────────────────────────────────────────
function cli_color(string $text, string $color): string
{
    $colors = ['red' => "\033[31m", 'green' => "\033[32m", 'yellow' => "\033[33m",
                'cyan' => "\033[36m", 'bold' => "\033[1m", 'reset' => "\033[0m"];
    return ($colors[$color] ?? '') . $text . $colors['reset'];
}

function cli_out(string $line): void { echo $line . "\n"; }

// ── chat_api.php から必要な関数を再実装（HTTP コンテキスト不要版） ──

function pt_get_or_create_session(PDO $pdo, string $token, string $storeKey): int
{
    $stmt = $pdo->prepare('SELECT id FROM chat_sessions WHERE session_token = :token LIMIT 1');
    $stmt->execute(['token' => $token]);
    $row = $stmt->fetch();
    if ($row) {
        return (int) $row['id'];
    }

    $columns = ['session_token', 'started_at', 'user_agent', 'ip_address'];
    $values  = [':session_token', 'NOW()', ':user_agent', ':ip_address'];
    $params  = ['session_token' => $token, 'user_agent' => 'PersonaTestBot/1.0', 'ip_address' => '127.0.0.1'];

    if (twin_db_column_exists($pdo, 'chat_sessions', 'store_key')) {
        $columns[] = 'store_key';
        $values[]  = ':store_key';
        $params['store_key'] = $storeKey;
    }

    $pdo->prepare('INSERT INTO chat_sessions (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ')')
        ->execute($params);

    return (int) $pdo->lastInsertId();
}

function pt_log_event(PDO $pdo, int $sessionId, string $eventName, ?string $eventValue, string $storeKey): void
{
    $columns = ['session_id', 'event_name', 'event_value', 'created_at'];
    $values  = [':session_id', ':event_name', ':event_value', 'NOW()'];
    $params  = ['session_id' => $sessionId, 'event_name' => $eventName, 'event_value' => $eventValue];

    if (twin_db_column_exists($pdo, 'event_logs', 'store_key')) {
        $columns[] = 'store_key';
        $values[]  = ':store_key';
        $params['store_key'] = $storeKey;
    }

    $pdo->prepare('INSERT INTO event_logs (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ')')
        ->execute($params);
}

function pt_save_user_message(PDO $pdo, int $sessionId, string $message, string $intent, string $storeKey): void
{
    $columns = ['session_id', 'sender', 'message', 'intent', 'created_at'];
    $values  = [':session_id', ':sender', ':message', ':intent', 'NOW()'];
    $params  = ['session_id' => $sessionId, 'sender' => 'user',
                'message' => twin_mask_personal_data($message), 'intent' => $intent];

    if (twin_db_column_exists($pdo, 'chat_messages', 'store_key')) {
        $columns[] = 'store_key';
        $values[]  = ':store_key';
        $params['store_key'] = $storeKey;
    }

    $pdo->prepare('INSERT INTO chat_messages (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ')')
        ->execute($params);
}

function pt_save_twin_message(PDO $pdo, int $sessionId, string $message, string $storeKey): void
{
    $columns = ['session_id', 'sender', 'message', 'intent', 'created_at'];
    $values  = [':session_id', ':sender', ':message', ':intent', 'NOW()'];
    $params  = ['session_id' => $sessionId, 'sender' => 'twin', 'message' => $message, 'intent' => null];

    if (twin_db_column_exists($pdo, 'chat_messages', 'store_key')) {
        $columns[] = 'store_key';
        $values[]  = ':store_key';
        $params['store_key'] = $storeKey;
    }

    $pdo->prepare('INSERT INTO chat_messages (' . implode(',', $columns) . ') VALUES (' . implode(',', $values) . ')')
        ->execute($params);
}

function pt_recent_user_intents(PDO $pdo, int $sessionId, int $limit = 4): array
{
    $stmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(intent,''),'other') AS intent_key
         FROM chat_messages WHERE session_id = :sid AND sender = 'user'
         ORDER BY id DESC LIMIT {$limit}"
    );
    $stmt->execute(['sid' => $sessionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    return array_values(array_reverse(array_filter(array_map('trim', $rows))));
}

function pt_recent_messages(PDO $pdo, int $sessionId, int $limit = 5): array
{
    $stmt = $pdo->prepare(
        "SELECT sender, message, COALESCE(NULLIF(intent,''),'other') AS intent_key, created_at
         FROM chat_messages WHERE session_id = :sid
         ORDER BY id DESC LIMIT {$limit}"
    );
    $stmt->execute(['sid' => $sessionId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_values(array_reverse($rows));
}

function pt_upsert_crew_applicant(PDO $pdo, int $sessionId, string $storeKey, array $state, array $assessment, array $scoring): void
{
    $prevHourlyRaw = $assessment['recruit_prev_hourly'] ?? $state['recruit_prev_hourly'] ?? null;
    $prevHourly    = ($prevHourlyRaw !== null && (int) $prevHourlyRaw > 0) ? (int) $prevHourlyRaw : null;

    $stmt = $pdo->prepare('SELECT id FROM crew_applicants WHERE session_id = :sid LIMIT 1');
    $stmt->execute(['sid' => $sessionId]);
    $existing = $stmt->fetch();

    $params = [
        'store_key'       => $storeKey,
        'experience'      => $assessment['recruit_experience']    ?? $state['recruit_experience']    ?? null,
        'genre'           => $assessment['recruit_genre']         ?? $state['recruit_genre']         ?? null,
        'prev_hourly'     => $prevHourly,
        'referrals'       => $assessment['recruit_referrals']     ?? $state['recruit_referrals']     ?? null,
        'bring_now'       => $assessment['recruit_bring_now']     ?? $state['recruit_bring_now']     ?? null,
        'bring_trial'     => $assessment['recruit_bring_customer'] ?? $state['recruit_bring_customer'] ?? null,
        'days_per_week'   => $assessment['recruit_days']          ?? $state['recruit_days']          ?? null,
        'alcohol'         => $assessment['recruit_alcohol']       ?? $state['recruit_alcohol']       ?? null,
        'estimated_wage'  => $assessment['recruit_estimate']      ?? null,
        'candidate_score' => $scoring['candidate_score'],
        'priority_grade'  => $scoring['priority_grade'],
        'score_detail'    => json_encode($scoring['score_detail'], JSON_UNESCAPED_UNICODE),
        'completed_at'    => date('Y-m-d H:i:s'),
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

// セッション状態（$_SESSION の代替、メモリ内のみ）
$_ptSessionStates = [];

function pt_state_get(string $token): array
{
    global $_ptSessionStates;
    return $_ptSessionStates[$token] ?? [];
}

function pt_state_set(string $token, array $state): void
{
    global $_ptSessionStates;
    $_ptSessionStates[$token] = $state;
}

function pt_state_from_response(array $prev, array $response): array
{
    if (!empty($response['recruit_state']) && is_array($response['recruit_state'])) {
        return array_merge($prev, $response['recruit_state']);
    }
    return $prev;
}

// ── ペルソナ定義（TEST_PERSONAS.md に対応） ─────────────────────────
//
// 重要: メッセージは engine の step 順に並べること。
//
// 未経験ルート step 順:
//   ask_experience → novice_ask_days → novice_ask_alcohol → novice_ask_bring → complete
//
// 経験者ルート step 順:
//   ask_experience → exp_ask_genre → exp_ask_prev_hourly → exp_ask_referrals
//   → exp_ask_bring_now → exp_ask_bring_trial → exp_ask_days → exp_ask_alcohol → complete
//
// ★ step 順と無関係なメッセージ（不安・自由質問）は complete 後に配置する。
//
// 想定スコア（参考）:
//   novice: bring_trial(yes=40/maybe=20/no=0) + days(5+=35/3_4=25/1_2=15) + alcohol(yes=15/some=10/no=0) + exp_bonus(some=10)
//   experienced: referrals(6+=42/3_5=30/1_2=15/0=0) + bring_now(yes=18/some=8/no=0)
//              + bring_trial(yes=18/maybe=8/no=0) + days(5+=15/3_4=10/1_2=5) + hourly(>=4500→7/>=4000→4/>=3000→2)

function pt_personas(): array
{
    return [

        // ── P01 あかり（未経験・ポジティブ）─────────────────────────────
        // Novice: experience=none, days=3_4, alcohol=some, bring=maybe
        // Score: 20(maybe) + 25(3_4) + 10(some) + 0 = 55 → Grade C
        'P01' => [
            'name'           => 'あかり（未経験・ポジティブ）',
            'expected_grade' => 'C',
            'expected_score_min' => 40,
            'expected_score_max' => 59,
            'messages' => [
                // 1. ask_experience: greeting → null → stays
                'こんにちは、求人に興味があって来ました',
                // 2. ask_experience: 'ない' → none
                '未経験です、キャバは全くないです',
                // 3. novice_ask_days: '週3か4' → 3_4
                '週3か4日希望です',
                // 4. novice_ask_alcohol: '少しなら' → some
                '少しなら飲めます',
                // 5. novice_ask_bring: 'たぶん' → maybe → complete ✅
                'たぶん呼べると思います',
                // 6. finished: intent 確認用（other になるはず）
                '身バレしないか不安です',
            ],
        ],

        // ── P02 みく（未経験・不安強め）──────────────────────────────────
        // Novice: experience=none, days=1_2, alcohol=no, bring=no
        // Score: 0 + 15(1_2) + 0 + 0 = 15 → Grade D
        'P02' => [
            'name'           => 'みく（未経験・不安強め）',
            'expected_grade' => 'D',
            'expected_score_min' => 0,
            'expected_score_max' => 39,
            'messages' => [
                // 1. ask_experience: '未経験' → none
                '未経験です、初めて求人を見ました',
                // 2. novice_ask_days: '週1か2' → 1_2
                '週1か2日でお試ししたいです',
                // 3. novice_ask_alcohol: '飲めない' → no
                'お酒は飲めません',
                // 4. novice_ask_bring: '難しい' → no → complete ✅
                '呼ぶのは難しいです',
                // 5. finished
                '怖いお客さんが来たらどうするの?',
            ],
        ],

        // ── P03 さつき（経験者・即戦力）─────────────────────────────────
        // Experienced: experience=yes, genre=cabaret, hourly=5000, referrals=6+,
        //              bring_now=yes, bring_trial=yes, days=5+, alcohol=yes
        // Score: 42 + 18 + 18 + 15 + 7 = 100 → Grade A
        'P03' => [
            'name'           => 'さつき（経験者・即戦力）',
            'expected_grade' => 'A',
            'expected_score_min' => 80,
            'expected_score_max' => 100,
            'messages' => [
                // 1. ask_experience: '経験があります' → yes
                'キャバクラは3年経験があります',
                // 2. exp_ask_genre: 'キャバクラ' → cabaret
                'キャバクラです',
                // 3. exp_ask_prev_hourly: '5000円' → 5000
                '時給は5000円でした',
                // 4. exp_ask_referrals: '6組以上' → 6_plus
                '週に6組以上は指名がありました',
                // 5. exp_ask_bring_now: '呼べます' → yes
                '今すぐ7〜8組は呼べます',
                // 6. exp_ask_bring_trial: '呼べます' → yes
                '体験日も呼べます',
                // 7. exp_ask_days: '週5以上' → 5_plus
                '週5以上入れます',
                // 8. exp_ask_alcohol: '飲めます' → yes → complete ✅
                'お酒は飲めます',
                // 9. finished
                '時給と指名料の取り分を教えてください',
            ],
        ],

        // ── P04 ゆい（経験者・条件重視）─────────────────────────────────
        // Experienced: hourly=4000, referrals=3_5, bring_now=some, bring_trial=maybe, days=3_4, alcohol=yes
        // Score: 30 + 8 + 8 + 10 + 4 = 60 → Grade B
        'P04' => [
            'name'           => 'ゆい（経験者・条件重視）',
            'expected_grade' => 'B',
            'expected_score_min' => 60,
            'expected_score_max' => 79,
            'messages' => [
                // 1. ask_experience: 'あります' → yes
                '2年経験があります、今も在籍してます',
                // 2. exp_ask_genre: 'キャバクラ'
                'キャバクラです',
                // 3. exp_ask_prev_hourly: '4000円' → 4000
                '時給は4000円でした',
                // 4. exp_ask_referrals: '3〜5組' → 3_5
                '週に3〜5組は指名がありました',
                // 5. exp_ask_bring_now: '少しなら' → some
                '少しなら今も呼べます',
                // 6. exp_ask_bring_trial: 'たぶん' → maybe
                'たぶん体験日も呼べます',
                // 7. exp_ask_days: '週3か4' → 3_4
                '週3か4日です',
                // 8. exp_ask_alcohol: '飲めます' → yes → complete ✅
                '飲めます',
                // 9. finished
                '掛け持ちは大丈夫ですか',
            ],
        ],

        // ── P05 れな（未経験・フレンドリー）────────────────────────────────
        // Novice: experience=none, days=3_4, alcohol=yes, bring=yes
        // Score: 40(yes) + 25(3_4) + 15(yes) + 0 = 80 → Grade A
        // ※TEST_PERSONAS.md は B と記載していたが計算上 A
        'P05' => [
            'name'           => 'れな（未経験・フレンドリー）',
            'expected_grade' => 'A',
            'expected_score_min' => 80,
            'expected_score_max' => 100,
            'messages' => [
                // 1. ask_experience: '未経験' → none
                '未経験です、友達がやってて気になってました',
                // 2. novice_ask_days: '週3か4' → 3_4
                '週3か4日です',
                // 3. novice_ask_alcohol: '飲めます' → yes
                'お酒はかなり飲めます',
                // 4. novice_ask_bring: '呼べます' → yes → complete ✅
                '友達を2〜3人呼べます',
                // 5. finished
                '衣装は自分で用意するんですか?',
            ],
        ],

        // ── P06 なな（経験者・副業感覚）────────────────────────────────
        // Experienced: hourly=3000, referrals=1_2, bring_now=some, bring_trial=maybe, days=1_2, alcohol=some
        // Score: 15 + 8 + 8 + 5 + 2 = 38 → Grade D
        // ※TEST_PERSONAS.md は C と記載していたが計算上 D
        'P06' => [
            'name'           => 'なな（経験者・副業感覚）',
            'expected_grade' => 'D',
            'expected_score_min' => 0,
            'expected_score_max' => 39,
            'messages' => [
                // 1. ask_experience: '経験があります' → yes
                '5年経験があります、ブランクがあって',
                // 2. exp_ask_genre: 'キャバクラ'
                'キャバクラです',
                // 3. exp_ask_prev_hourly: '3000円' → 3000
                '時給は3000円でした',
                // 4. exp_ask_referrals: '1〜2組' → 1_2
                '週に1〜2組は指名がありました',
                // 5. exp_ask_bring_now: '少しなら' → some
                '少しなら今も呼べます',
                // 6. exp_ask_bring_trial: 'たぶん' → maybe
                'たぶん体験日も呼べます',
                // 7. exp_ask_days: '週1か2' → 1_2
                '週1か2日だけ入りたいです',
                // 8. exp_ask_alcohol: '少しなら' → some → complete ✅
                '少しなら飲めます',
                // 9. finished
                'ブランクがあっても大丈夫ですか',
            ],
        ],

        // ── P07 はる（未経験・大学生）──────────────────────────────────
        // Novice: experience=none, days=1_2, alcohol=no, bring=no
        // Score: 0 + 15(1_2) + 0 + 0 = 15 → Grade D
        // check: 「学校・テスト・大学」が other になるか
        'P07' => [
            'name'                => 'はる（未経験・大学生）',
            'expected_grade'      => 'D',
            'expected_score_min'  => 0,
            'expected_score_max'  => 39,
            'check_other_intents' => ['学校', 'テスト', '大学'],
            'messages' => [
                // 1. ask_experience: '未経験' → none
                '未経験です、大学生でバイト感覚で考えてます',
                // 2. novice_ask_days: '週1か2' → 1_2
                '週1か2日でお願いしたいです',
                // 3. novice_ask_alcohol: '飲めない' → no
                'お酒は飲めません',
                // 4. novice_ask_bring: '難しい' → no → complete ✅
                '呼ぶのは難しいです',
                // 5. finished: other 確認用（学校・テスト・大学）
                '大学のテストが忙しいとき休めますか?',
                '学校が被ったら出勤できないこともあるんですが大丈夫ですか?',
            ],
        ],

        // ── P08 こと（経験者・呼客強め）────────────────────────────────
        // Experienced: hourly=5000, referrals=6+, bring_now=yes, bring_trial=yes, days=5+, alcohol=yes
        // Score: 42 + 18 + 18 + 15 + 7 = 100 → Grade A
        'P08' => [
            'name'           => 'こと（経験者・呼客強め）',
            'expected_grade' => 'A',
            'expected_score_min' => 80,
            'expected_score_max' => 100,
            'messages' => [
                // 1. ask_experience: '経験があります' → yes
                '4年キャバクラの経験があります',
                // 2. exp_ask_genre: 'キャバクラ'
                'キャバクラです',
                // 3. exp_ask_prev_hourly: '5000円' → 5000
                '時給は5000円でした',
                // 4. exp_ask_referrals: '6組以上' → 6_plus
                '週に6組以上指名がありました',
                // 5. exp_ask_bring_now: '呼べます' → yes
                '今すぐ5〜6組は呼べます',
                // 6. exp_ask_bring_trial: '呼べます' → yes
                '体験日も呼べます',
                // 7. exp_ask_days: '週5以上' → 5_plus
                '週5日以上入れます',
                // 8. exp_ask_alcohol: '飲めます' → yes → complete ✅
                'お酒は飲めます',
                // 9. finished
                '体験日の条件と最低保証について教えてください',
            ],
        ],

        // ── P09 まい（ガールズバー経験・慎重派）──────────────────────────
        // Novice(experience=some): days=3_4, alcohol=some, bring=maybe
        // Score: 20(maybe) + 25(3_4) + 10(some) + 10(exp_bonus:some) = 65 → Grade B
        // ※TEST_PERSONAS.md は C と記載していたが計算上 B
        // check: experience = 'some' になるか
        'P09' => [
            'name'             => 'まい（ガールズバー経験・慎重派）',
            'expected_grade'   => 'B',
            'expected_score_min' => 60,
            'expected_score_max' => 79,
            'check_experience' => 'some',
            'messages' => [
                // 1. ask_experience: '少しだけ' → some (novice route)
                'キャバは少しだけ経験があります、ガールズバーが1年あります',
                // 2. novice_ask_days: '週3か4' → 3_4
                '週3か4日希望です',
                // 3. novice_ask_alcohol: '少しなら' → some
                '少しなら飲めます',
                // 4. novice_ask_bring: 'たぶん' → maybe → complete ✅
                'たぶん呼べると思います',
                // 5. finished: intent 確認用
                'ガールズバーとキャバクラって何が違うんですか?',
                'ヘルプはありますか?',
            ],
        ],

        // ── P10 りん（経験者・育児中・ブランクあり）────────────────────────
        // Experienced: hourly=3500, referrals=1_2, bring_now=some, bring_trial=maybe, days=3_4, alcohol=yes
        // Score: 15 + 8 + 8 + 10 + 2 = 43 → Grade C
        // check: 「子供・育児・急な欠席」が other になるか
        'P10' => [
            'name'                => 'りん（経験者・育児中・ブランクあり）',
            'expected_grade'      => 'C',
            'expected_score_min'  => 40,
            'expected_score_max'  => 59,
            'check_other_intents' => ['子供', '育児', '急な欠席'],
            'messages' => [
                // 1. ask_experience: '経験があります' → yes
                '2年経験があります、育児でブランクがありました',
                // 2. exp_ask_genre: 'キャバクラ'
                'キャバクラです',
                // 3. exp_ask_prev_hourly: '3500円' → 3500
                '時給は3500円でした',
                // 4. exp_ask_referrals: '1〜2組' → 1_2
                '週に1〜2組は常連さんがいます',
                // 5. exp_ask_bring_now: '少しなら' → some
                '少しなら今も呼べます',
                // 6. exp_ask_bring_trial: 'たぶん' → maybe
                'たぶん体験日も呼べます',
                // 7. exp_ask_days: '週3か4' → 3_4
                '週3か4日です',
                // 8. exp_ask_alcohol: '飲めます' → yes → complete ✅
                '飲めます',
                // 9. finished: other 確認用（子供・育児・急な欠席）
                '子供がいるので急な欠席が心配なんですが大丈夫ですか?',
                '育児中でも無理なく働けますか?',
            ],
        ],
    ];
}

// ── テスト実行 ───────────────────────────────────────────────────

function pt_run_persona(
    string $personaId,
    array $persona,
    bool $dryRun,
    bool $cleanFirst
): array {
    $storeKey  = 'kirin';
    $tokenSeed = 'persona_test_' . strtolower($personaId) . '_' . date('Ymd');
    $token     = hash('sha256', $tokenSeed);

    $pdo = twin_db();

    // --clean: 既存テストセッションを削除
    if ($cleanFirst) {
        $stmt = $pdo->prepare('SELECT id FROM chat_sessions WHERE session_token = :token LIMIT 1');
        $stmt->execute(['token' => $token]);
        $existingSession = $stmt->fetch();
        if ($existingSession) {
            $sid = (int) $existingSession['id'];
            $pdo->prepare('DELETE FROM crew_applicants WHERE session_id = :sid')->execute(['sid' => $sid]);
            $pdo->prepare('DELETE FROM chat_messages  WHERE session_id = :sid')->execute(['sid' => $sid]);
            $pdo->prepare('DELETE FROM event_logs     WHERE session_id = :sid')->execute(['sid' => $sid]);
            $pdo->prepare('DELETE FROM chat_sessions  WHERE id = :sid')->execute(['sid' => $sid]);
        }
    }

    if ($dryRun) {
        // dry-run: DB書き込みなし、intent のみ確認
        $intents = [];
        foreach ($persona['messages'] as $msg) {
            $intent    = twin_detect_intent($msg);
            $intents[] = ['message' => $msg, 'intent' => $intent];
        }
        return ['dry_run' => true, 'intents' => $intents];
    }

    $sessionId = pt_get_or_create_session($pdo, $token, $storeKey);
    $state     = pt_state_get($token);

    $allIntents  = [];
    $completed   = false;
    $lineCtaShown = false;
    $scoring     = null;

    foreach ($persona['messages'] as $index => $message) {
        // rate limit 回避のため小さなスリープ（同一秒を避ける）
        if ($index > 0) {
            usleep(200000); // 0.2s
        }

        $recentUserIntents = pt_recent_user_intents($pdo, $sessionId, 4);
        $recentMessages    = pt_recent_messages($pdo, $sessionId, 5);

        // conversation_context の簡易検出
        $conversationContext = '';
        $lastTwinReply = '';
        foreach (array_reverse($recentMessages) as $rm) {
            if (($rm['sender'] ?? '') === 'twin') {
                $lastTwinReply = trim((string) ($rm['message'] ?? ''));
                break;
            }
        }
        // recruit engine の step 状態をそのまま渡す
        $responseContext = [
            'session_id'           => $sessionId,
            'recent_user_intents'  => $recentUserIntents,
            'recent_messages'      => $recentMessages,
            'conversation_context' => $conversationContext,
            'last_cast_name'       => '',
            'last_cast_status'     => '',
            'line_guided_count'    => (int) ($state['line_guided_count'] ?? 0),
            'session_state'        => $state,
        ];

        $response = twin_generate_response($message, $responseContext);
        $intent   = (string) ($response['intent'] ?? twin_detect_intent($message));
        $reply    = (string) ($response['reply'] ?? '');

        $allIntents[] = ['message' => $message, 'intent' => $intent];

        $pdo->beginTransaction();
        pt_save_user_message($pdo, $sessionId, $message, $intent, $storeKey);
        pt_log_event($pdo, $sessionId, 'intent_detected', $intent, $storeKey);

        // LINE CTA 確認
        $lineCTAIntents = defined('TWIN_LINE_CTA_INTENTS')
            ? explode(',', TWIN_LINE_CTA_INTENTS)
            : (function_exists('twin_line_cta_intents') ? twin_line_cta_intents() : []);
        if (in_array($intent, $lineCTAIntents, true)) {
            pt_log_event($pdo, $sessionId, 'line_cta_shown', $intent, $storeKey);
            $lineCtaShown = true;
        }

        if ($reply !== '') {
            pt_save_twin_message($pdo, $sessionId, $reply, $storeKey);
        }

        $pdo->commit();

        // 問診完了処理
        if (!empty($response['recruit_complete']) && !empty($response['recruit_assessment'])) {
            $assessment   = $response['recruit_assessment'];
            $scoringState = array_merge($response['recruit_state'] ?? [], $assessment);
            $scoring      = twin_recruit_calc_score($scoringState);
            pt_upsert_crew_applicant($pdo, $sessionId, $storeKey, $response['recruit_state'] ?? [], $assessment, $scoring);
            pt_log_event($pdo, $sessionId, 'recruit_assessment', json_encode($assessment, JSON_UNESCAPED_UNICODE), $storeKey);
            $completed = true;
        }

        $state = pt_state_from_response($state, $response);
        pt_state_set($token, $state);
    }

    // DB から最終結果を取得
    $row = $pdo->prepare('SELECT candidate_score, priority_grade, experience, line_applied_at FROM crew_applicants WHERE session_id = :sid LIMIT 1');
    $row->execute(['sid' => $sessionId]);
    $dbResult = $row->fetch(PDO::FETCH_ASSOC) ?: [];

    // other になった発話を特定
    $otherMessages = array_filter($allIntents, fn ($r) => $r['intent'] === 'other');

    return [
        'dry_run'      => false,
        'session_id'   => $sessionId,
        'token'        => $token,
        'completed'    => $completed,
        'scoring'      => $scoring,
        'db'           => $dbResult,
        'line_cta_shown' => $lineCtaShown,
        'intents'      => $allIntents,
        'other_messages' => array_values($otherMessages),
    ];
}

// ── 引数パース ───────────────────────────────────────────────────

$opts = getopt('', ['persona:', 'clean', 'dry-run']);
$filterPersona = isset($opts['persona']) ? strtoupper((string) $opts['persona']) : null;
$cleanFirst    = isset($opts['clean']);
$dryRun        = isset($opts['dry-run']);

$personas = pt_personas();
if ($filterPersona !== null && !isset($personas[$filterPersona])) {
    cli_out(cli_color("ペルソナ {$filterPersona} が見つかりません。P01〜P10 で指定してください。", 'red'));
    exit(1);
}

if ($filterPersona !== null) {
    $personas = [$filterPersona => $personas[$filterPersona]];
}

// ── 実行 ─────────────────────────────────────────────────────────

$mode = $dryRun ? cli_color('[DRY-RUN]', 'yellow') : ($cleanFirst ? cli_color('[CLEAN+RUN]', 'cyan') : '[RUN]');
cli_out(cli_color("═══════════════════════════════════════════════════════", 'bold'));
cli_out(cli_color(" crew-onboarding ペルソナテスト {$mode}", 'bold'));
cli_out(cli_color("═══════════════════════════════════════════════════════", 'bold'));
cli_out('');

$results  = [];
$failures = [];

foreach ($personas as $id => $persona) {
    cli_out(cli_color("▶ {$id}: {$persona['name']}", 'cyan'));

    try {
        $result = pt_run_persona($id, $persona, $dryRun, $cleanFirst);
        $results[$id] = $result;
    } catch (\Throwable $e) {
        $failures[$id] = $e->getMessage();
        cli_out('  ' . cli_color('ERROR: ' . $e->getMessage(), 'red'));
        continue;
    }

    if ($result['dry_run']) {
        foreach ($result['intents'] as $r) {
            $color = $r['intent'] === 'other' ? 'yellow' : 'green';
            cli_out(sprintf('  %-40s → %s', mb_substr($r['message'], 0, 40), cli_color($r['intent'], $color)));
        }
        cli_out('');
        continue;
    }

    $db         = $result['db'];
    $grade      = (string) ($db['priority_grade'] ?? '?');
    $scoreNum   = isset($db['candidate_score']) ? (int) $db['candidate_score'] : null;
    $score      = $scoreNum !== null ? (string) $scoreNum : '?';
    $experience = (string) ($db['experience'] ?? '?');
    $lineTapped = !empty($db['line_applied_at']) ? 'あり' : 'なし';

    // メモリ上のスコアを fallback として使う（DB に保存前でも確認できるように）
    if ($score === '?' && !empty($result['scoring'])) {
        $scoreNum = (int) ($result['scoring']['candidate_score'] ?? 0);
        $score    = $scoreNum . '(mem)';
        $grade    = (string) ($result['scoring']['priority_grade'] ?? '?');
    }

    $isCompleted   = $result['completed'];
    $completedLabel = $isCompleted ? cli_color('完了', 'green') : cli_color('未完了 ← step 順確認要', 'red');
    $gradeOk       = ($grade === ($persona['expected_grade'] ?? '?'));
    $gradeLabel    = $gradeOk ? cli_color("Grade {$grade}", 'green') : cli_color("Grade {$grade} ← 想定: {$persona['expected_grade']}", 'red');

    // score 範囲チェック
    $scoreMin = (int) ($persona['expected_score_min'] ?? 0);
    $scoreMax = (int) ($persona['expected_score_max'] ?? 100);
    $scoreInRange = $scoreNum !== null && $scoreNum >= $scoreMin && $scoreNum <= $scoreMax;
    $scoreLabel = $scoreInRange
        ? cli_color("score {$score}", 'green')
        : cli_color("score {$score} ← 想定:{$scoreMin}〜{$scoreMax}", 'yellow');

    cli_out("  問診: {$completedLabel} / {$gradeLabel} / {$scoreLabel} / 経験: {$experience} / LINE: {$lineTapped}");

    // intents 一覧
    foreach ($result['intents'] as $r) {
        $color = $r['intent'] === 'other' ? 'yellow' : '';
        $label = cli_color($r['intent'], $color ?: 'reset');
        cli_out(sprintf('    %-42s → %s', mb_substr($r['message'], 0, 42), $label));
    }

    // P03/P08: Grade A 確認
    if (in_array($id, ['P03', 'P08'], true)) {
        $scoreNum = (int) $score;
        $msg = "  [CHECK] Grade A 確認: Grade={$grade}, score={$scoreNum}";
        cli_out($scoreNum >= 80 && $grade === 'A'
            ? cli_color($msg . ' ✅', 'green')
            : cli_color($msg . ' ❌ 要確認', 'red'));
    }

    // experience=some 確認 (P09)
    if (isset($persona['check_experience'])) {
        $exp = $experience;
        $expected = $persona['check_experience'];
        $msg = "  [CHECK] experience={$expected} 確認: experience={$exp}";
        cli_out($exp === $expected
            ? cli_color($msg . ' ✅', 'green')
            : cli_color($msg . ' ❌ 要確認（experience=' . $exp . '）', 'red'));
    }

    // other に落ちた発話の確認 (P07, P10)
    if (!empty($persona['check_other_intents'])) {
        $otherMsgs = $result['other_messages'] ?? [];
        foreach ($persona['check_other_intents'] as $keyword) {
            $found = false;
            foreach ($otherMsgs as $om) {
                if (mb_strpos($om['message'], $keyword) !== false) {
                    $found = true;
                    break;
                }
            }
            $msg = "  [CHECK] 「{$keyword}」が other に落ちるか確認";
            cli_out($found
                ? cli_color($msg . ' ✅（other → intent追加候補）', 'yellow')
                : cli_color($msg . ' → other以外に分類された（分類済み）', 'green'));
        }
    }

    cli_out('');
}

// ── サマリー ─────────────────────────────────────────────────────

if (!$dryRun) {
    cli_out(cli_color("═══════════════════════════════════════════════════════", 'bold'));
    cli_out(cli_color(" 結果サマリー", 'bold'));
    cli_out(cli_color("═══════════════════════════════════════════════════════", 'bold'));
    cli_out(sprintf('  %-6s %-26s %-8s %-7s %-8s %-6s %s',
        'ID', 'ペルソナ', 'Grade', 'Score', '経験', 'LINE', '完了'));
    cli_out('  ' . str_repeat('─', 72));

    $gradeMatch = 0;
    foreach ($results as $id => $result) {
        $persona = pt_personas()[$id] ?? [];
        $db      = $result['db'];
        $grade   = (string) ($db['priority_grade'] ?? '?');
        $score   = (string) ($db['candidate_score'] ?? '?');
        $exp     = (string) ($db['experience'] ?? '?');
        $line    = !empty($db['line_applied_at']) ? 'あり' : 'なし';
        $done    = $result['completed'] ? '✅' : '⚠';
        $exp_ok  = ($grade === ($persona['expected_grade'] ?? '?'));
        if ($exp_ok) {
            $gradeMatch++;
        }
        $gradeStr = $exp_ok ? cli_color($grade, 'green') : cli_color("{$grade}←{$persona['expected_grade']}", 'red');
        cli_out(sprintf('  %-6s %-26s %-8s %-7s %-8s %-6s %s',
            $id,
            mb_substr($persona['name'] ?? '', 0, 26),
            $gradeStr,
            $score,
            $exp,
            $line,
            $done
        ));
    }

    cli_out('  ' . str_repeat('─', 72));
    $total = count($results);
    cli_out("  Grade 一致: {$gradeMatch}/{$total}");

    if (!empty($failures)) {
        cli_out('');
        cli_out(cli_color("  エラー発生:", 'red'));
        foreach ($failures as $id => $msg) {
            cli_out("  {$id}: {$msg}");
        }
    }

    cli_out('');
    cli_out("  管理画面確認: http://raspi4.local/twin/crew/admin.php#applicants");
    cli_out("  SQL確認:       crew-onboarding/docs/TEST_SQL.md を参照");
    cli_out('');
}
