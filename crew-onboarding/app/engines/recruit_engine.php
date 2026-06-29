<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/store.php';
require_once dirname(__DIR__) . '/knowledge/stores.php';

// ─────────────────────────────────────────────
// パーサー（各ステップの選択肢をキーワードマッチで判定）
// ─────────────────────────────────────────────

function twin_recruit_contains(string $message, array $keywords): bool
{
    foreach ($keywords as $kw) {
        if (mb_stripos($message, $kw, 0, 'UTF-8') !== false) {
            return true;
        }
    }
    return false;
}

function twin_recruit_parse_experience(string $message): ?string
{
    if (twin_recruit_contains($message, ['未経験', 'なし', 'ない', '初めて', 'ゼロ', '①', '1番', '一番', 'no', 'ゼロ'])) {
        return 'none';
    }
    if (twin_recruit_contains($message, ['少し', 'ちょっと', '少々', 'すこし', 'ガールズバー', 'ガルバ', '②', '2番', '二番', 'あるけど', 'あるけれど', '少ある', '少ない'])) {
        return 'some';
    }
    // 「ある」「あり」「経験」「はい」より後に評価（② が先にマッチしないよう）
    if (twin_recruit_contains($message, ['経験あり', '経験者', '働いてた', '働いていた', '経験がある', 'あります', 'あり', '経験', 'はい', '③', '3番', '三番'])) {
        return 'yes';
    }
    return null;
}

function twin_recruit_parse_days(string $message): ?string
{
    if (twin_recruit_contains($message, ['5日', '週5', '週6', '週7', 'ほぼ毎日', '毎日', '5以上', '③', '3番', '三番', '5日以上'])) {
        return '5_plus';
    }
    if (twin_recruit_contains($message, ['3日', '4日', '週3', '週4', '3〜4', '②', '2番', '二番'])) {
        return '3_4';
    }
    if (twin_recruit_contains($message, ['1日', '2日', '週1', '週2', '1〜2', '①', '1番', '一番', 'たまに', '少なめ'])) {
        return '1_2';
    }
    return null;
}

function twin_recruit_parse_alcohol(string $message): ?string
{
    if (twin_recruit_contains($message, ['飲めない', '飲めません', '飲まない', '飲みません', '下戸', '苦手', '無理', 'ダメ', 'だめ', 'ng', 'NG', '③', '3番', '三番', 'できない'])) {
        return 'no';
    }
    if (twin_recruit_contains($message, ['少しなら', 'すこしなら', '少しは', '少しだけ', '少量', 'ちょっとなら', '②', '2番', '二番'])) {
        return 'some';
    }
    if (twin_recruit_contains($message, ['飲める', '飲めます', '飲みます', '好き', 'いける', '問題ない', 'ok', 'OK', '①', '1番', '一番', '大丈夫'])) {
        return 'yes';
    }
    return null;
}

function twin_recruit_parse_bring(string $message): ?string
{
    if (twin_recruit_contains($message, ['呼べない', '呼べません', '誘えません', '難しい', 'むずかしい', '厳しい', 'きびしい', '無理', 'ムリ', '③', '3番', '三番', 'いない', 'いません', '呼べなさそう'])) {
        return 'no';
    }
    if (twin_recruit_contains($message, ['もしかしたら', 'たぶん', 'かもしれ', '少しなら', '②', '2番', '二番', 'わからない', 'わかんない', 'かも'])) {
        return 'maybe';
    }
    if (twin_recruit_contains($message, ['呼べる', '呼べます', '呼べそう', '連れてこれ', '連れてこられ', '来てもらえる', '友達を連れ', '友達が来', '友達を呼', '①', '1番', '一番', 'ok', 'OK', '大丈夫', 'あります'])) {
        return 'yes';
    }
    return null;
}

function twin_recruit_parse_genre(string $message): ?string
{
    if (twin_recruit_contains($message, ['キャバクラ', 'キャバ', 'キャバ嬢', '①', '1番', '一番'])) {
        return 'cabaret';
    }
    if (twin_recruit_contains($message, ['ラウンジ', '②', '2番', '二番'])) {
        return 'lounge';
    }
    if (twin_recruit_contains($message, ['スナック', '③', '3番', '三番'])) {
        return 'snack';
    }
    if (twin_recruit_contains($message, ['ガールズバー', 'ガルバ', '④', '4番', '四番'])) {
        return 'girls_bar';
    }
    if (twin_recruit_contains($message, ['その他', 'クラブ', 'バー', 'ホール', '上記以外', '⑤', '5番', '五番', '他', '別の', 'ホスト'])) {
        return 'other';
    }
    return null;
}

function twin_recruit_parse_hourly(string $message): ?int
{
    // 範囲表記 (3500〜4000) は先頭の数値を採用する
    $part  = preg_split('/[〜～]/u', $message)[0];
    $clean = str_replace([',', '，', '円', '時給', '¥', '￥', 'から'], '', $part);
    if (preg_match('/(\d{3,5})/', $clean, $m)) {
        $val = (int) $m[1];
        if ($val >= 500 && $val <= 20000) {
            return $val;
        }
    }
    return null;
}

function twin_recruit_parse_referrals(string $message): ?string
{
    if (twin_recruit_contains($message, ['6組', '7組', '8組', '9組', '10組', '6人以上', '④', '4番', '四番', '6組以上', '6〜'])) {
        return '6_plus';
    }
    if (twin_recruit_contains($message, ['3組', '4組', '5組', '③', '3番', '三番', '3〜5', '3〜'])) {
        return '3_5';
    }
    if (twin_recruit_contains($message, ['1組', '2組', '1人', '2人', '②', '2番', '二番', '1〜2', '1〜'])) {
        return '1_2';
    }
    if (twin_recruit_contains($message, ['0組', '呼んでない', '呼んでいない', 'なかった', 'なし', '0人', 'ゼロ', '①', '1番', '一番', 'いなかった', 'ない'])) {
        return '0';
    }
    return null;
}

function twin_recruit_parse_bring_now(string $message): ?string
{
    if (twin_recruit_contains($message, ['難しい', 'むずかしい', '厳しい', '無理', '今は難しい', '③', '3番', '三番', 'いない', '難', 'なし'])) {
        return 'no';
    }
    if (twin_recruit_contains($message, ['少しなら', 'すこしなら', '少しは', '②', '2番', '二番', 'かもしれ', 'わからない'])) {
        return 'some';
    }
    if (twin_recruit_contains($message, ['呼べる', '呼べます', '大丈夫', 'ok', 'OK', '①', '1番', '一番', 'あります', '呼べそう'])) {
        return 'yes';
    }
    return null;
}

// ─────────────────────────────────────────────
// ステップの質問文
// ─────────────────────────────────────────────

function twin_recruit_question(string $step): string
{
    return match ($step) {
        'ask_experience' =>
            "夜のお仕事の経験はありますか？\n\n①未経験\n②少しだけある\n③経験あり",
        'novice_ask_days' =>
            "週に何日くらい出られそうですか？\n\n①週1〜2日\n②週3〜4日\n③週5日以上",
        'novice_ask_alcohol' =>
            "お酒は飲めますか？（無理に飲む必要はないので、正直に教えてください）\n\n①飲める\n②少しなら飲める\n③飲めない",
        'novice_ask_bring' =>
            "体験入店の日に、お知り合いやお友達をお店に呼べそうですか？\n\n①呼べる\n②もしかしたら呼べる\n③今は難しい",
        'exp_ask_genre' =>
            "どんなお店で働いていましたか？\n\n①キャバクラ\n②ラウンジ\n③スナック\n④ガールズバー\n⑤その他",
        'exp_ask_prev_hourly' =>
            "以前もらっていた時給を教えてください。\n（「3500円」「わからない」など、だいたいで大丈夫です）",
        'exp_ask_referrals' =>
            "週に指名でお客様を何組くらい呼んでいましたか？\n\n①0組\n②1〜2組\n③3〜5組\n④6組以上",
        'exp_ask_bring_now' =>
            "今でも、以前のお客様を呼べそうですか？\n\n①呼べる\n②少しなら呼べる\n③今は難しい",
        'exp_ask_bring_trial' =>
            "体験入店の日にお客様を呼べそうですか？\n\n①呼べる\n②もしかしたら呼べる\n③呼べない",
        'exp_ask_days' =>
            "週に何日くらい出られそうですか？\n\n①週1〜2日\n②週3〜4日\n③週5日以上",
        'exp_ask_alcohol' =>
            "お酒は飲めますか？\n\n①飲める\n②少しなら飲める\n③飲めない",
        default => '',
    };
}

// ─────────────────────────────────────────────
// 時給目安の計算
// ─────────────────────────────────────────────

function twin_recruit_calc_estimate(array $state): array
{
    $experience     = (string) ($state['recruit_experience'] ?? 'none');
    $days           = (string) ($state['recruit_days'] ?? '1_2');
    $alcohol        = (string) ($state['recruit_alcohol'] ?? 'no');
    $bringCustomer  = (string) ($state['recruit_bring_customer'] ?? 'no');

    if ($experience === 'none' || $experience === 'some') {
        $bonus = 0;
        if ($days === '3_4')    $bonus += 200;
        if ($days === '5_plus') $bonus += 350;
        if ($alcohol === 'yes') $bonus += 150;
        if ($alcohol === 'some') $bonus += 75;
        if ($bringCustomer === 'yes')   $bonus += 350;
        if ($bringCustomer === 'maybe') $bonus += 150;

        $total = 3500 + $bonus;
        if ($total >= 4000) {
            return ['label' => '3,500〜4,000円前後'];
        }
        return ['label' => '3,500円前後'];
    }

    // 経験者
    $referrals  = (string) ($state['recruit_referrals'] ?? '0');
    $bringNow   = (string) ($state['recruit_bring_now'] ?? 'no');
    $prevHourly = (int) ($state['recruit_prev_hourly'] ?? 0);

    $bonus = 0;
    if ($referrals === '1_2')   $bonus += 300;
    if ($referrals === '3_5')   $bonus += 600;
    if ($referrals === '6_plus') $bonus += 900;

    if ($bringNow === 'yes')  $bonus += 300;
    if ($bringNow === 'some') $bonus += 150;

    if ($bringCustomer === 'yes')   $bonus += 400;
    if ($bringCustomer === 'maybe') $bonus += 200;

    if ($days === '3_4')    $bonus += 200;
    if ($days === '5_plus') $bonus += 350;

    if ($prevHourly >= 4500) $bonus += 300;
    elseif ($prevHourly >= 4000) $bonus += 150;

    $total = 4000 + $bonus;

    if ($total >= 5500) {
        return ['label' => '5,000円以上も相談可'];
    }
    if ($total >= 4700) {
        return ['label' => '4,500〜5,000円前後'];
    }
    if ($total >= 4300) {
        return ['label' => '4,000〜4,500円前後'];
    }
    return ['label' => '4,000円前後'];
}

// ─────────────────────────────────────────────
// 内部スコアリング（管理画面専用・応募者には非表示）
// ─────────────────────────────────────────────

/**
 * 問診回答からCandidateScoreと採用優先度を算出する。
 *
 * スコアリング根拠:
 *  未経験ルート: 出勤日数・お酒・体験日呼客を主軸に 0〜100
 *  経験者ルート: 指名実績・今の呼客力・体験日呼客を主軸に 0〜100
 *
 * 優先度グレード: A=80+, B=60-79, C=40-59, D<40
 * 店長の判断材料として提供するもので、採用を決めるものではない。
 */
function twin_recruit_calc_score(array $state): array
{
    $experience   = (string) ($state['recruit_experience'] ?? 'none');
    $days         = (string) ($state['recruit_days'] ?? '1_2');
    $alcohol      = (string) ($state['recruit_alcohol'] ?? 'no');
    $bringCustomer = (string) ($state['recruit_bring_customer'] ?? 'no');

    $detail = [];
    $score  = 0;

    if ($experience === 'none' || $experience === 'some') {
        // ── 未経験ルート（max 100）──────────────────────────
        // 体験日呼客: 最重要（40点）— 初日の売上に直結
        $bringPts = match ($bringCustomer) { 'yes' => 40, 'maybe' => 20, default => 0 };
        // 出勤日数（35点）— 売上への貢献頻度
        $daysPts  = match ($days) { '5_plus' => 35, '3_4' => 25, default => 15 };
        // お酒（15点）
        $alcPts   = match ($alcohol) { 'yes' => 15, 'some' => 10, default => 0 };
        // 経験少しあり（10点）
        $expPts   = ($experience === 'some') ? 10 : 0;

        $detail = [
            'route'       => 'novice',
            'bring_trial' => $bringPts,
            'days'        => $daysPts,
            'alcohol'     => $alcPts,
            'exp_bonus'   => $expPts,
        ];
        $score = $bringPts + $daysPts + $alcPts + $expPts;

    } else {
        // ── 経験者ルート（max 100）──────────────────────────
        $referrals  = (string) ($state['recruit_referrals'] ?? '0');
        $bringNow   = (string) ($state['recruit_bring_now'] ?? 'no');
        $prevHourly = (int) ($state['recruit_prev_hourly'] ?? 0);

        // 指名実績（42点）— 集客力の実績
        $refPts   = match ($referrals) { '6_plus' => 42, '3_5' => 30, '1_2' => 15, default => 0 };
        // 今の呼客力（18点）— 即戦力度
        $nowPts   = match ($bringNow) { 'yes' => 18, 'some' => 8, default => 0 };
        // 体験日呼客（18点）
        $trialPts = match ($bringCustomer) { 'yes' => 18, 'maybe' => 8, default => 0 };
        // 出勤日数（15点）
        $daysPts  = match ($days) { '5_plus' => 15, '3_4' => 10, default => 5 };
        // 前職時給ボーナス（7点）— 市場価値の目安
        $hrPts    = ($prevHourly >= 4500) ? 7 : (($prevHourly >= 4000) ? 4 : (($prevHourly >= 3000) ? 2 : 0));

        $detail = [
            'route'       => 'experienced',
            'referrals'   => $refPts,
            'bring_now'   => $nowPts,
            'bring_trial' => $trialPts,
            'days'        => $daysPts,
            'prev_hourly' => $hrPts,
        ];
        $score = min(100, $refPts + $nowPts + $trialPts + $daysPts + $hrPts);
    }

    $score = max(0, min(100, $score));
    $grade = match (true) {
        $score >= 80 => 'A',
        $score >= 60 => 'B',
        $score >= 40 => 'C',
        default      => 'D',
    };

    return [
        'candidate_score' => $score,
        'priority_grade'  => $grade,
        'score_detail'    => $detail,
    ];
}

// ─────────────────────────────────────────────
// 面談完了メッセージ
// ─────────────────────────────────────────────

function twin_recruit_finish_message(array $estimate): string
{
    $storeKey = twin_current_store_key();
    $lineUrl  = twin_store_value($storeKey, 'line_url', '');
    $tel      = twin_store_value($storeKey, 'tel', '');
    $label    = $estimate['label'];

    $lines = [
        "ここまでありがとうございます♪",
        "",
        "回答内容を見ると、まずは保証時給 {$label} から相談できそうです。",
        "",
        "実際の時給は、体験入店時の雰囲気・出勤日数・お客様の呼びやすさなどを見て最終決定します。",
        "",
        "まずはLINEかお電話で、体験入店の日程を相談してみませんか？",
    ];

    if ($tel !== '') {
        $lines[] = "";
        $lines[] = "📞 {$tel}";
    }

    return implode("\n", $lines);
}

// ─────────────────────────────────────────────
// メインエントリ
// ─────────────────────────────────────────────

function twin_recruit_engine_response(string $message, array $state, array $context = []): array
{
    $step = (string) ($state['recruit_step'] ?? 'ask_experience');

    switch ($step) {

        case 'ask_experience': {
            $parsed = twin_recruit_parse_experience($message);
            if ($parsed === null) {
                return [
                    'reply' => "うまく読み取れなかったかも😅\n①未経験 ②少しある ③経験あり のどれかを教えてください♪",
                    'intent' => 'recruit_experience',
                    'response_mode' => 'recruit',
                    'recruit_state' => ['recruit_step' => 'ask_experience'],
                ];
            }
            $nextStep = ($parsed === 'yes') ? 'exp_ask_genre' : 'novice_ask_days';
            $intro = match ($parsed) {
                'none' => "未経験でも大丈夫ですよ！安心してくださいね♪\n\n",
                'some' => "少しご経験があるんですね✨\n\n",
                'yes'  => "経験があるんですね！心強いです✨\n\n",
                default => '',
            };
            return [
                'reply' => $intro . twin_recruit_question($nextStep),
                'intent' => 'recruit_experience',
                'response_mode' => 'recruit',
                'recruit_state' => ['recruit_step' => $nextStep, 'recruit_experience' => $parsed],
            ];
        }

        case 'novice_ask_days': {
            $parsed = twin_recruit_parse_days($message);
            if ($parsed === null) {
                return [
                    'reply' => "週に何日くらい出られそうですか？\n①週1〜2日 ②週3〜4日 ③週5日以上",
                    'intent' => 'recruit_days',
                    'response_mode' => 'recruit',
                    'recruit_state' => ['recruit_step' => 'novice_ask_days'],
                ];
            }
            return [
                'reply' => "了解です！\n\n" . twin_recruit_question('novice_ask_alcohol'),
                'intent' => 'recruit_days',
                'response_mode' => 'recruit',
                'recruit_state' => ['recruit_step' => 'novice_ask_alcohol', 'recruit_days' => $parsed],
            ];
        }

        case 'novice_ask_alcohol': {
            $parsed = twin_recruit_parse_alcohol($message);
            if ($parsed === null) {
                return [
                    'reply' => "お酒は飲めますか？\n①飲める ②少しなら飲める ③飲めない",
                    'intent' => 'recruit_alcohol',
                    'response_mode' => 'recruit',
                    'recruit_state' => ['recruit_step' => 'novice_ask_alcohol'],
                ];
            }
            $prefix = ($parsed === 'no')
                ? "飲めなくても大丈夫ですよ！無理に飲む必要はありません✨\n\n"
                : "了解です♪\n\n";
            return [
                'reply' => $prefix . twin_recruit_question('novice_ask_bring'),
                'intent' => 'recruit_alcohol',
                'response_mode' => 'recruit',
                'recruit_state' => ['recruit_step' => 'novice_ask_bring', 'recruit_alcohol' => $parsed],
            ];
        }

        case 'novice_ask_bring': {
            $parsed = twin_recruit_parse_bring($message);
            if ($parsed === null) {
                return [
                    'reply' => "体験入店の日にお知り合いを呼べそうですか？\n①呼べる ②もしかしたら ③今は難しい",
                    'intent' => 'recruit_bring',
                    'response_mode' => 'recruit',
                    'recruit_state' => ['recruit_step' => 'novice_ask_bring'],
                ];
            }
            $nextState = array_merge($state, ['recruit_bring_customer' => $parsed, 'recruit_step' => 'finished']);
            $estimate  = twin_recruit_calc_estimate($nextState);
            return [
                'reply' => twin_recruit_finish_message($estimate),
                'intent' => 'recruit_finished',
                'response_mode' => 'recruit',
                'recruit_state' => ['recruit_step' => 'finished', 'recruit_bring_customer' => $parsed],
                'recruit_complete' => true,
                'recruit_assessment' => array_merge($nextState, ['recruit_estimate' => $estimate['label']]),
            ];
        }

        case 'exp_ask_genre': {
            $parsed = twin_recruit_parse_genre($message);
            if ($parsed === null) {
                return [
                    'reply' => "どんなお店でしたか？\n①キャバクラ ②ラウンジ ③スナック ④ガールズバー ⑤その他",
                    'intent' => 'recruit_genre',
                    'response_mode' => 'recruit',
                    'recruit_state' => ['recruit_step' => 'exp_ask_genre'],
                ];
            }
            return [
                'reply' => "了解です！\n\n" . twin_recruit_question('exp_ask_prev_hourly'),
                'intent' => 'recruit_genre',
                'response_mode' => 'recruit',
                'recruit_state' => ['recruit_step' => 'exp_ask_prev_hourly', 'recruit_genre' => $parsed],
            ];
        }

        case 'exp_ask_prev_hourly': {
            $parsed  = twin_recruit_parse_hourly($message);
            $hourly  = $parsed ?? 0;
            $prefix  = ($hourly > 0)
                ? "時給{$hourly}円だったんですね。参考にします！\n\n"
                : "わかりました♪\n\n";
            return [
                'reply' => $prefix . twin_recruit_question('exp_ask_referrals'),
                'intent' => 'recruit_hourly',
                'response_mode' => 'recruit',
                'recruit_state' => ['recruit_step' => 'exp_ask_referrals', 'recruit_prev_hourly' => $hourly],
            ];
        }

        case 'exp_ask_referrals': {
            $parsed = twin_recruit_parse_referrals($message);
            if ($parsed === null) {
                return [
                    'reply' => "週に指名で何組くらい呼んでいましたか？\n①0組 ②1〜2組 ③3〜5組 ④6組以上",
                    'intent' => 'recruit_referrals',
                    'response_mode' => 'recruit',
                    'recruit_state' => ['recruit_step' => 'exp_ask_referrals'],
                ];
            }
            return [
                'reply' => "ありがとうございます！\n\n" . twin_recruit_question('exp_ask_bring_now'),
                'intent' => 'recruit_referrals',
                'response_mode' => 'recruit',
                'recruit_state' => ['recruit_step' => 'exp_ask_bring_now', 'recruit_referrals' => $parsed],
            ];
        }

        case 'exp_ask_bring_now': {
            $parsed = twin_recruit_parse_bring_now($message);
            if ($parsed === null) {
                return [
                    'reply' => "今でも以前のお客様を呼べそうですか？\n①呼べる ②少しなら ③今は難しい",
                    'intent' => 'recruit_bring_now',
                    'response_mode' => 'recruit',
                    'recruit_state' => ['recruit_step' => 'exp_ask_bring_now'],
                ];
            }
            return [
                'reply' => "了解です♪\n\n" . twin_recruit_question('exp_ask_bring_trial'),
                'intent' => 'recruit_bring_now',
                'response_mode' => 'recruit',
                'recruit_state' => ['recruit_step' => 'exp_ask_bring_trial', 'recruit_bring_now' => $parsed],
            ];
        }

        case 'exp_ask_bring_trial': {
            $parsed = twin_recruit_parse_bring($message);
            if ($parsed === null) {
                return [
                    'reply' => "体験入店の日にお客様を呼べそうですか？\n①呼べる ②もしかしたら ③呼べない",
                    'intent' => 'recruit_bring_trial',
                    'response_mode' => 'recruit',
                    'recruit_state' => ['recruit_step' => 'exp_ask_bring_trial'],
                ];
            }
            return [
                'reply' => "わかりました！\n\n" . twin_recruit_question('exp_ask_days'),
                'intent' => 'recruit_bring_trial',
                'response_mode' => 'recruit',
                'recruit_state' => ['recruit_step' => 'exp_ask_days', 'recruit_bring_customer' => $parsed],
            ];
        }

        case 'exp_ask_days': {
            $parsed = twin_recruit_parse_days($message);
            if ($parsed === null) {
                return [
                    'reply' => "週に何日くらい出られそうですか？\n①週1〜2日 ②週3〜4日 ③週5日以上",
                    'intent' => 'recruit_days',
                    'response_mode' => 'recruit',
                    'recruit_state' => ['recruit_step' => 'exp_ask_days'],
                ];
            }
            return [
                'reply' => "了解です♪\n\n" . twin_recruit_question('exp_ask_alcohol'),
                'intent' => 'recruit_days',
                'response_mode' => 'recruit',
                'recruit_state' => ['recruit_step' => 'exp_ask_alcohol', 'recruit_days' => $parsed],
            ];
        }

        case 'exp_ask_alcohol': {
            $parsed = twin_recruit_parse_alcohol($message);
            if ($parsed === null) {
                return [
                    'reply' => "お酒は飲めますか？\n①飲める ②少しなら飲める ③飲めない",
                    'intent' => 'recruit_alcohol',
                    'response_mode' => 'recruit',
                    'recruit_state' => ['recruit_step' => 'exp_ask_alcohol'],
                ];
            }
            $nextState = array_merge($state, ['recruit_alcohol' => $parsed, 'recruit_step' => 'finished']);
            $estimate  = twin_recruit_calc_estimate($nextState);
            return [
                'reply' => twin_recruit_finish_message($estimate),
                'intent' => 'recruit_finished',
                'response_mode' => 'recruit',
                'recruit_state' => ['recruit_step' => 'finished', 'recruit_alcohol' => $parsed],
                'recruit_complete' => true,
                'recruit_assessment' => array_merge($nextState, ['recruit_estimate' => $estimate['label']]),
            ];
        }

        case 'finished': {
            $lineUrl = twin_store_value(twin_current_store_key(), 'line_url', '');
            $tel     = twin_store_value(twin_current_store_key(), 'tel', '');
            $ctaMsg  = "気になることがあれば、LINEやお電話でいつでも聞いてくださいね♪";
            if ($tel !== '') {
                $ctaMsg .= "\n📞 {$tel}";
            }
            return [
                'reply' => $ctaMsg,
                'intent' => 'recruit_finished',
                'response_mode' => 'recruit',
                'recruit_state' => ['recruit_step' => 'finished'],
            ];
        }

        default: {
            return [
                'reply' => "こんにちは！CREW KIRINです♪\n\nまず最初に聞かせてください。\n" . twin_recruit_question('ask_experience'),
                'intent' => 'recruit_experience',
                'response_mode' => 'recruit',
                'recruit_state' => ['recruit_step' => 'ask_experience'],
            ];
        }
    }
}
