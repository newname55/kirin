<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/knowledge/seika.php';
require_once dirname(__DIR__) . '/knowledge/stores.php';
require_once dirname(__DIR__) . '/knowledge/cast_aliases.php';
require_once dirname(__DIR__) . '/clients/wbss_client.php';
require_once dirname(__DIR__) . '/store.php';

function twin_load_cast_aliases(): array
{
    static $aliases = null;
    if ($aliases === null) {
        $aliases = require dirname(__DIR__) . '/knowledge/cast_aliases.php';
        if (!is_array($aliases)) {
            $aliases = [];
        }
    }
    return $aliases;
}

function twin_katakana_to_hiragana(string $str): string
{
    return (string) preg_replace_callback(
        '/[ァ-ヶ]/u',
        static fn($m) => mb_chr(mb_ord($m[0], 'UTF-8') - 0x60, 'UTF-8'),
        $str
    );
}

function twin_normalize_halfwidth_katakana(string $str): string
{
    if (!function_exists('mb_convert_kana')) {
        return $str;
    }
    return mb_convert_kana($str, 'KVas', 'UTF-8');
}

function twin_line_nudge(array $context = []): string
{
    // セッション内の LINE 誘導済み回数を確認し、2回以上は省略
    $lineGuidedCount = (int) ($context['line_guided_count'] ?? 0);
    if ($lineGuidedCount >= 2) {
        return '';
    }
    $patterns = [
        '気になることがあればLINEでお気軽にご確認ください♪',
        '当日の出勤変更もあるので、LINEで確認しておくと安心ですよ。',
        '人数や時間が決まっていれば、LINEで事前にお伝えいただくとスムーズです♪',
    ];
    return $patterns[array_rand($patterns)];
}

function twin_contains_keyword(string $message, string $keyword): bool
{
    if (function_exists('mb_stripos')) {
        return mb_stripos($message, $keyword, 0, 'UTF-8') !== false;
    }

    return stripos($message, $keyword) !== false;
}

function twin_recent_intents(array $context): array
{
    $intents = $context['recent_user_intents'] ?? [];
    if (!is_array($intents)) {
        return [];
    }

    $normalized = [];
    foreach ($intents as $intent) {
        $intent = trim((string) $intent);
        if ($intent !== '') {
            $normalized[] = $intent;
        }
    }

    return $normalized;
}

function twin_has_recent_schedule_context(array $context): bool
{
    foreach (twin_recent_intents($context) as $intent) {
        if (in_array($intent, ['attendance', 'cast_schedule'], true)) {
            return true;
        }
    }

    return false;
}

function twin_context_value(array $context, string $key, string $fallback = ''): string
{
    $value = $context[$key] ?? $fallback;
    if (is_string($value) || is_numeric($value)) {
        $value = trim((string) $value);
        if ($value !== '') {
            return $value;
        }
    }

    return $fallback;
}

function twin_is_affirmative_message(string $message): bool
{
    $normalized = trim($message);
    $normalized = preg_replace('/[[:space:]\x{3000}]+/u', '', $normalized) ?? $normalized;
    $normalized = preg_replace('/[?？!！。、。．・,，\(\)（）【】\[\]「」『』]/u', '', $normalized) ?? $normalized;

    return (bool) preg_match('/^(はい|うん|そう|そうです|そうだよ|行きます|行く予定|行くよ|お願いします|おねがいします|その子|会いたい|会いに行く|行ってみる|行ってみます|ぜひ)$/u', $normalized);
}

function twin_detect_cast_schedule_query(string $message, array $context = []): bool
{
    $message = trim($message);
    if ($message === '') {
        return false;
    }

    $castName = twin_extract_cast_name($message);

    // v0.8.6: 禁止語リスト強化（メッセージ全体をチェック）
    $excludeWords = [
        'VIP', 'vip', 'Vip',
        'ドリンク', '料金', '金額', 'いくら', 'セット', '何人', '何円', '総額', '会計',
        'どんな子', '女の子', 'キャスト', '多い', '雰囲気', '場所', '営業時間', '営業',
        '混雑', '忙しい', '予約', '岡山', 'キャバクラ', '周辺', '開いてる',
        '出勤人数', '何名', '何人出勤', '不安', '緊張', '初めて', 'はじめて', '初心者',
        '一人', 'ひとり', '1人', '2人', '3人', '4人', '5人', '人で',
        '場所', 'アクセス', '住所', '雰囲気', 'ふんいき',
    ];

    // v0.8.6: メッセージが長い場合は人名の問い合わせとは見なさない（20文字超）
    if (mb_strlen($message, 'UTF-8') > 20) {
        return false;
    }

    // メッセージ全体に禁止語が含まれる場合は cast_schedule にしない
    foreach ($excludeWords as $word) {
        if (mb_stripos($message, $word, 0, 'UTF-8') !== false) {
            return false;
        }
    }

    // 除外条件: castNameが空・長すぎる・数字含む・除外語含む
    if ($castName === '') {
        return false;
    }
    if (mb_strlen($castName, 'UTF-8') >= 10) {
        return false;
    }
    // v0.7.3: castName の最大長を6文字に制限
    if (mb_strlen($castName, 'UTF-8') > 6) {
        return false;
    }
    if (preg_match('/[0-9０-９]/u', $castName)) {
        return false;
    }
    foreach ($excludeWords as $word) {
        if (mb_strpos($castName, $word, 0, 'UTF-8') !== false) {
            return false;
        }
    }

    $hasCastName = $castName !== '' && $castName !== $message;
    $hasScheduleVerb = (bool) preg_match('/(出勤してる|出勤してます|出勤|出てる|出てます|いる|います|居る|居ます|してる)/u', $message);
    $hasQuestion = (bool) preg_match('/[?？]$/u', $message);
    $hasShortFollowUp = (bool) preg_match('/^[ぁ-んァ-ヶ一-龠々]{1,20}(?:さん|ちゃん|くん|君)?(?:は|って)?[?？]?$/u', $message);

    if ($hasCastName && $hasScheduleVerb) {
        return true;
    }

    // 敬称（さん/ちゃん/くん）付き短文＋「は？」は文脈なしでも許可
    $hasTitleForm = (bool) preg_match('/^[ぁ-んァ-ヶ一-龠々]{1,6}(?:さん|ちゃん|くん|君)(?:は|って)?[?？]?$/u', $message);
    if ($hasCastName && $hasTitleForm) {
        return true;
    }

    // 「〇〇は？」形式（敬称なし）は出勤確認文脈がある場合のみ許可
    if ($hasCastName && $hasQuestion && $hasShortFollowUp && !$hasScheduleVerb) {
        return twin_has_recent_schedule_context($context);
    }

    if ($hasCastName && $hasQuestion && twin_has_recent_schedule_context($context)) {
        return true;
    }

    return false;
}

function twin_detect_intent(string $message, array $context = []): string
{
    $conversationContext = twin_context_value($context, 'conversation_context', '');
    if ($conversationContext !== '' && twin_is_affirmative_message($message)) {
        return match ($conversationContext) {
            'waiting_cast_confirmation' => 'cast_confirmation_yes',
            'waiting_first_visit_answer' => 'first_visit_yes',
            'waiting_alone_answer' => 'alone_yes',
            'waiting_reservation_answer' => 'reservation_yes',
            'waiting_group_visit_answer' => 'group_visit_yes',
            'waiting_arrival_time_answer' => 'arrival_time_yes',
            'waiting_crowd_answer' => 'crowd_yes',
            default => '',
        } ?: 'other';
    }

    $rules = [
        'greeting'       => ['こんばんは', 'こんにちは', 'はじめまして', '初めまして', 'お疲れ', 'おつかれ', 'お疲れさま', 'おつかれさま', 'おはよう', 'おはようございます', 'どうも', 'やっほー'],
        'anxiety'        => ['不安', '緊張する', '緊張するんだけど', '緊張してる', '怖い', '心配', '初めてで不安', '一人で緊張', '入りにくい', '大丈夫かな'],
        'vip'            => ['VIP', 'vip', 'VIPルーム', '個室'],
        'drink_price'    => ['ドリンク代', 'ドリンク料金', '追加ドリンク', 'ハウスボトル', 'ドリンク'],
        'price'          => ['料金', '値段', 'いくら'],
        // arrival_time / crowd / repeat_visitor は business_hours / reservation より先に評価する
        'arrival_time'   => ['時ごろ', '時頃', '時くらい', 'からかな', '頃かな', '時ぐらい', '21時', '22時', '20時', '19時', '23時', '9時', '10時', '8時', '11時', '時間ごろ', '時間帯', '今から行', '今から伺', '今から向か', '今から大丈夫', '頃行く', '頃に行', 'くらいに行', 'ぐらいに行'],
        'crowd'          => ['忙しい', '混んでる', '混雑', '空いている', '空いてる', '賑わってる', '込んでる', '混み具合', '今日混む', '今日忙しい', '空いてる？', '賑わってる', '混んでる？', '込んでる？', '空いてますか', '席空いてる', '席空いてますか'],
        'repeat_visitor' => ['何回も来てる', '来たことある', '行ったことある', '前に行った', '前にも行った', '前にも行っ', '前にも来た', '常連', '何度か', '久しぶり', 'また行く', '行ったことがある', '何回も', 'また来たよ', 'また来た', '久しぶりです', '2回目', '3回目', '何度も'],
        'business_hours' => ['何時', '営業時間', '営業し', '営業中', '営業してる', '開いてる', '今日営業', '今日行ける', '今から', 'ラスト'],
        'reservation'    => ['予約', 'LINE', '電話', '席'],
        'group_visit'    => ['2人', '3人', '4人', '5人', '複数人', '友達', '友人', '同僚', 'みんな', '一緒に', 'グループ'],
        'area_question'  => ['岡山のキャバクラ', 'キャバクラ多い', '中央町のキャバクラ', '周辺の店', '岡山 キャバ', '岡山キャバ'],
        'recommend_cast' => ['おすすめ', 'どの子がいい', 'どの子', '誰がいい', '誰がおすすめ', 'どのキャスト', '初めて向き', '話しやすい子', '落ち着いた子', '盛り上げてくれる子', 'どんな子が多い', 'どんな女の子が多い', 'どんな子', 'どんな女の子', 'どんなキャスト', '女の子多い', 'キャスト多い', '可愛い子'],
        'cast_type'      => [],
        'cast_schedule'  => [],
        'attendance'     => ['今日の出勤', '出勤人数', '今日何人', '今日は何人', '今日誰', '何人出勤', '何人いる', '何人出てる', '誰がいる', 'キャストいる', '女の子いる', '本日出勤', '明日の出勤', '明日誰', '明日何人', '明日出勤', '明日の予定', '明日の女の子', 'あしたの出勤', 'あした誰', '出勤'],
        'first_visit'    => ['初めて', 'はじめて', '初回', '初心者'],
        'alone'          => ['一人', 'ひとり', '1人', 'ソロ'],
        'friends'        => ['友達', '二人', '複数', '団体', '飲み会'],
        'nomination'     => ['指名', 'お気に入り', 'キャスト'],
        'location'       => ['場所', 'アクセス', 'どこ', '地図', '住所', '中央町', '岡山駅'],
        'atmosphere'     => ['雰囲気', 'ふんいき', '空気感', '店内', 'どんな感じ'],
        'champagne'      => ['シャンパン', 'champagne', 'シャンパーニュ'],
        'recruit'        => ['求人', '働く', 'バイト', 'アルバイト', '採用', '体験入店'],
    ];

    // price_estimate: 人数＋セット数の組み合わせ OR 料金系＋数量の組み合わせ → price より先に評価
    // 不安・緊張・初めて・一人・どんな子・出勤 などが含まれる場合は除外。
    $priceEstimateExclude = ['不安', '緊張', '初めて', '一人', 'ひとり', 'どんな女の子', 'どんな子', '出勤', '何人出勤', '何人いる', '今日何人', '怖い', '心配'];
    $hasPriceExclude = false;
    foreach ($priceEstimateExclude as $ex) {
        if (twin_contains_keyword($message, $ex)) {
            $hasPriceExclude = true;
            break;
        }
    }
    if (!$hasPriceExclude) {
        $hasPeopleAndSets = (bool) preg_match('/\d+\s*(?:人|名)/u', $message) && (bool) preg_match('/\d+\s*セット/u', $message);
        $hasPriceWithQty = (bool) preg_match('/(?:料金|金額|いくら|何円|会計|総額|予算)/u', $message)
            && (bool) preg_match('/\d+/u', $message);
        if ($hasPeopleAndSets || $hasPriceWithQty) {
            return 'price_estimate';
        }
    }

    // v1.1.4: 「明日 + 出勤系ワード」は cast_schedule より先に attendance として判定
    // キャスト名が抽出できた場合のみ cast_schedule ルーティングへ流す
    if (preg_match('/明日|あした|あす/u', $message)) {
        if (preg_match('/出勤|誰|何人|何名|女の子|予定|出てる|出てます/u', $message)) {
            if (twin_extract_cast_name($message) === '') {
                return 'attendance';
            }
        }
    }

    foreach ($rules as $intent => $keywords) {
        if ($intent === 'cast_schedule') {
            if (twin_detect_cast_schedule_query($message, $context)) {
                return 'cast_schedule';
            }
            continue;
        }
        if ($intent === 'price_estimate') {
            continue; // already handled above
        }
        foreach ($keywords as $keyword) {
            if (twin_contains_keyword($message, $keyword)) {
                return $intent;
            }
        }
    }

    return 'other';
}

function twin_detect_secondary_intents(string $message, string $primaryIntent): array
{
    $candidates = [
        'anxiety'      => ['不安', '緊張', '怖い', '心配'],
        'first_visit'  => ['初めて', 'はじめて', '初回'],
        'alone'        => ['一人', 'ひとり', '1人'],
        'cast_type'    => ['どんな子', 'どんな女の子', 'どんなキャスト'],
        'price'        => ['料金', 'いくら', '金額'],
        'recommend_cast' => ['おすすめ', '誰がいい', '話しやすい', '初めて向き'],
    ];

    $secondary = [];
    foreach ($candidates as $intent => $keywords) {
        if ($intent === $primaryIntent) {
            continue;
        }
        foreach ($keywords as $kw) {
            if (twin_contains_keyword($message, $kw)) {
                $secondary[] = $intent;
                break;
            }
        }
    }
    return $secondary;
}

function twin_intent_label(string $intent): string
{
    return match ($intent) {
        'attendance' => '出勤',
        'cast_schedule' => '個別出勤',
        'cast_confirmation_yes' => '個別出勤返答',
        'anxiety' => '不安・緊張',
        'first_visit_yes' => '初めて返答',
        'alone_yes' => '一人返答',
        'reservation_yes' => '予約返答',
        'greeting' => '挨拶',
        'first_visit' => '初めて',
        'business_hours' => '営業時間',
        'reservation' => '予約',
        'friends' => '友達',
        'after_party' => '二次会',
        'budget' => '予算',
        'price' => '料金',
        'alone' => '一人',
        'nomination' => '指名',
        'location' => '場所',
        'atmosphere' => '雰囲気',
        'champagne' => 'シャンパン',
        'recruit' => '求人',
        'recruit_experience' => '経験（問診）',
        'recruit_alcohol' => 'お酒（問診）',
        'recruit_days' => '出勤日数（問診）',
        'recruit_referrals' => '指名客数（問診）',
        'recruit_bring_now' => '今すぐ呼べる客（問診）',
        'recruit_bring_trial' => '体験日の呼客（問診）',
        'recruit_genre' => '前職ジャンル（問診）',
        'recruit_finished' => '問診完了',
        'group_visit' => 'グループ来店',
        'group_visit_yes' => 'グループ来店返答',
        'arrival_time' => '来店時間',
        'arrival_time_yes' => '来店時間返答',
        'crowd' => '混雑確認',
        'crowd_yes' => '混雑確認返答',
        'recommend_cast' => 'キャスト推薦',
        'price_estimate' => '料金概算',
        'vip'            => 'VIP',
        'drink_price'    => 'ドリンク料金',
        'cast_type'      => 'キャストタイプ',
        'area_question'  => 'エリア質問',
        'general_chat'   => '雑談',
        'repeat_visitor' => '再来店',
        'other' => '未分類',
        default => '未分類',
    };
}

function twin_rule_knowledge(): array
{
    return twin_store_config(twin_current_store_key());
}

function twin_rule_value(string $key, string $fallback = ''): string
{
    return twin_store_value(twin_current_store_key(), $key, $fallback);
}

function twin_rule_join(array $sentences): string
{
    $filtered = [];

    foreach ($sentences as $sentence) {
        $sentence = trim((string) $sentence);
        if ($sentence === '') {
            continue;
        }
        $filtered[] = $sentence;
    }

    return implode("\n", $filtered);
}

function twin_rule_result_with_wbss(string $reply, string $intent, string $eventValue): array
{
    return [
        'reply' => $reply,
        'intent' => $intent,
        'response_mode' => 'rule',
        'wbss_api_event_name' => 'wbss_api_call',
        'wbss_api_event_value' => $eventValue,
    ];
}

function twin_wbss_data(array $result): array
{
    $data = $result['data'] ?? $result;
    return is_array($data) ? $data : [];
}

function twin_wbss_string(array $data, array $keys, string $fallback = ''): string
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }

        $value = $data[$key];
        if (is_string($value) || is_numeric($value)) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }
    }

    return $fallback;
}

function twin_wbss_int(array $data, array $keys): ?int
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }

        $value = $data[$key];
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
    }

    return null;
}

function twin_wbss_bool(array $data, array $keys): ?bool
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }

        $value = $data[$key];
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes', 'working', 'on'], true)) {
                return true;
            }
            if (in_array($normalized, ['0', 'false', 'no', 'off'], true)) {
                return false;
            }
        }
    }

    return null;
}

function twin_wbss_array(array $data, array $keys): array
{
    foreach ($keys as $key) {
        if (!array_key_exists($key, $data) || !is_array($data[$key])) {
            continue;
        }

        return array_values($data[$key]);
    }

    return [];
}

function twin_wbss_name_candidates(array $items): array
{
    $names = [];

    foreach ($items as $item) {
        if (is_string($item) || is_numeric($item)) {
            $name = trim((string) $item);
        } elseif (is_array($item)) {
            $name = twin_wbss_string($item, ['name', 'cast_name', 'kana', 'nickname']);
        } else {
            $name = '';
        }

        $name = trim((string) preg_replace('/[ 　,、。｡．・\-]+/u', '', $name));
        if ($name !== '') {
            $names[] = $name;
        }
    }

    return array_values(array_unique($names));
}

function twin_wbss_extract_count(array $data): int
{
    $count = twin_wbss_int($data, ['count', 'attendance_count', 'working_count', 'total', 'num']);
    if ($count !== null) {
        return max(0, $count);
    }

    $casts = twin_wbss_name_candidates(twin_wbss_array($data, ['casts', 'casts', 'names', 'cast_list', 'staff']));
    return count($casts);
}

function twin_extract_cast_name(string $message): string
{
    $original = trim($message);

    // 半角カタカナ → 全角 → ひらがな に正規化
    $candidate = twin_normalize_halfwidth_katakana($original);
    $candidate = twin_katakana_to_hiragana($candidate);

    // 空白・記号除去
    $candidate = preg_replace('/[[:space:]\x{3000}]+/u', '', $candidate) ?? $candidate;
    $candidate = preg_replace('/[?？!！。、。．・,，\(\)（）【】\[\]「」『』☆★♡♥💕✨🌸]/u', '', $candidate) ?? $candidate;

    // 出勤確認語・敬称・助詞・日付語を除去（長いものを先に）
    $candidate = str_replace(
        ['出勤してる', '出勤してます', '出勤してるの', '出勤予定', '出勤中', '出てます', '出てる', '出勤', 'してる', 'しています', 'います', '居ます', 'いる', '居る', 'ですか', 'なの', 'って', '明日', 'あした', 'あす', '今日', '本日', 'さん', 'ちゃん', 'くん', '君', '様', 'さま', '氏', 'は', 'が', 'の'],
        '',
        $candidate
    );
    $candidate = preg_replace('/(?:です|ね|よ)+$/u', '', $candidate) ?? $candidate;
    $candidate = trim($candidate);

    if ($candidate === '') {
        return '';
    }

    // 1文字は人名として認めない（「子」「誰」など汎用語の残骸を除去）
    if (mb_strlen($candidate, 'UTF-8') < 2) {
        return '';
    }

    // 汎用的な除外語にマッチしたら空を返す
    if (preg_match('/^(何人|何名|誰|誰か|何時|明日|あした|あす|今日|本日|今|今から|まだ|出勤|出てる|出てます|いる|います|居る|居ます|場所|営業時間|料金|予算|雰囲気|初めて|一人|指名|予約|line|電話|求人|友達|団体|二次会|シャンパン)$/iu', $candidate)) {
        return '';
    }

    // alias適用（正規化後のキーで照合）
    $aliases = twin_load_cast_aliases();
    if (isset($aliases[$candidate])) {
        $candidate = (string) $aliases[$candidate];
    }

    // alias適用前のゆれ（さん/ちゃん付きのまま）も照合
    $originalNorm = twin_katakana_to_hiragana(twin_normalize_halfwidth_katakana(trim($message)));
    $originalNorm = preg_replace('/[?？!！。、\s\x{3000}]+/u', '', $originalNorm) ?? $originalNorm;
    if ($candidate !== '' && isset($aliases[$originalNorm])) {
        $candidate = (string) $aliases[$originalNorm];
    }

    return $candidate;
}

function twin_wbss_today_date(?string $date = null): string
{
    return $date !== null && $date !== '' ? $date : date('Y-m-d');
}

// v1.1.4: メッセージから「今日」「明日」を判定して日付文字列を返す
function twin_detect_attendance_date(string $message): string
{
    if (preg_match('/明日|あした|あす/u', $message)) {
        return 'tomorrow';
    }
    return 'today';
}

function twin_resolve_attendance_date(string $attendanceDate): string
{
    if ($attendanceDate === 'tomorrow') {
        return date('Y-m-d', strtotime('+1 day'));
    }
    return date('Y-m-d');
}

function twin_rule_attendance_response(array $context = [], string $attendanceDate = 'today'): array
{
    $isTomorrow = ($attendanceDate === 'tomorrow');
    $dateStr     = twin_resolve_attendance_date($attendanceDate);
    $dateLabel   = $isTomorrow ? '明日' : '本日';
    $eventSuffix = $isTomorrow ? '_tomorrow' : '_today';

    $result    = twin_wbss_fetch_attendance(twin_rule_value('wbss_store_key', 'seika'), $dateStr);
    $elapsedMs = (int) ($result['elapsed_ms'] ?? 0);

    if (!($result['ok'] ?? false)) {
        $reply = "すみません、今は出勤情報を確認できませんでした。\n当日の出勤は変わることもあるので、LINEで確認していただくと確実です。";

        return twin_rule_result_with_wbss($reply, 'attendance', 'attendance_error' . $eventSuffix . ':' . $elapsedMs . 'ms');
    }

    $data  = twin_wbss_data($result);
    $count = twin_wbss_extract_count($data);
    $names = twin_wbss_name_candidates(twin_wbss_array($data, ['casts', 'casts', 'names', 'cast_list', 'staff']));
    $names = array_slice($names, 0, 5);

    if ($count <= 0) {
        if ($isTomorrow) {
            $reply = "明日の出勤予定はまだ登録されていないようです。\n予定が更新される場合がありますので、LINEで確認していただくと確実です。";
        } else {
            $reply = "本日の出勤予定はまだ登録されていないようです。\n当日の状況は変わることがあるので、LINEで確認していただくと安心です。\nご来店は今日をお考えですか？";
        }

        return twin_rule_result_with_wbss($reply, 'attendance', 'attendance_empty' . $eventSuffix . ':' . $elapsedMs . 'ms');
    }

    $sentences = ["{$dateLabel}は{$count}名出勤予定です。"];
    if ($names) {
        shuffle($names);
        $displayNames = array_slice($names, 0, 3);
        $nameParts = [];
        foreach ($displayNames as $n) {
            $trimmed = trim((string) $n);
            if ($trimmed !== '') {
                $nameParts[] = $trimmed . 'さん';
            }
        }
        if ($nameParts) {
            $nameStr = implode('、', $nameParts);
            $etc = ($count >= 4) ? 'など' : '';
            $sentences[] = "現在の予定では、{$nameStr}{$etc}が出勤予定です。";
        }
    }
    $sentences[] = '出勤状況は変更になる場合がありますので、ご来店前はLINEで確認していただくと安心です。';
    if (!$isTomorrow) {
        $sentences[] = '気になるキャストさんはいらっしゃいますか？';
        $nudge = twin_line_nudge($context);
        if ($nudge !== '') {
            $sentences[] = $nudge;
        }
    }

    return twin_rule_result_with_wbss(twin_rule_join($sentences), 'attendance', 'attendance_success' . $eventSuffix . ':' . $elapsedMs . 'ms');
}

function twin_rule_cast_schedule_response(string $message, array $context = []): array
{
    $castName = twin_extract_cast_name($message);
    $castNameDetectedValue = 'cast=' . $castName . ',raw=' . mb_strimwidth($message, 0, 80, '…', 'UTF-8');
    if ($castName === '') {
        $reply = "すみません、キャスト名が少し読み取れませんでした。\nももさんのようにお名前を教えてもらえますか？";

        return twin_rule_result_with_wbss($reply, 'cast_schedule', 'cast_schedule_error:0ms') + [
            'cast_name_detected' => $castNameDetectedValue,
        ];
    }

    // v1.1.4: 「明日かなさんは？」など明日指定に対応
    $attendanceDate = twin_detect_attendance_date($message);
    $isTomorrow     = ($attendanceDate === 'tomorrow');
    $dateStr        = twin_resolve_attendance_date($attendanceDate);
    $dateLabel      = $isTomorrow ? '明日' : '本日';
    $eventSuffix    = $isTomorrow ? '_tomorrow' : '_today';

    $result    = twin_wbss_fetch_cast_schedule(twin_rule_value('wbss_store_key', 'seika'), $castName, $dateStr);
    $elapsedMs = (int) ($result['elapsed_ms'] ?? 0);

    if (!($result['ok'] ?? false)) {
        $reply = "すみません、今は出勤情報を確認できませんでした。\n当日の出勤はLINEで確認していただくと確実です。";

        return twin_rule_result_with_wbss($reply, 'cast_schedule', 'cast_schedule_error' . $eventSuffix . ':' . $elapsedMs . 'ms') + [
            'cast_name_detected' => $castNameDetectedValue,
        ];
    }

    $data      = twin_wbss_data($result);
    $found     = twin_wbss_bool($data, ['found', 'cast_found', 'exists']);
    $isWorking = twin_wbss_bool($data, ['is_working', 'working', 'on_duty']);

    if ($found === false || ($isWorking === null && $found === null && twin_wbss_string($data, ['status']) === 'not_found')) {
        $reply = "すみません、そのキャストさんは確認できませんでした。\nお名前の表記が違う場合もあるので、LINEで確認していただくと確実です。";

        return twin_rule_result_with_wbss($reply, 'cast_schedule', 'cast_schedule_not_found' . $eventSuffix . ':' . $elapsedMs . 'ms') + [
            'cast_name_detected' => $castNameDetectedValue,
        ];
    }

    $nameDisplay = $castName . 'さん';
    if ($isWorking === true) {
        $nudge = $isTomorrow ? '' : twin_line_nudge($context);
        $reply = "{$nameDisplay}は{$dateLabel}出勤予定です。" . ($nudge !== '' ? "\n" . $nudge : '') . "\n{$nameDisplay}に会いに行く予定ですか？";

        return twin_rule_result_with_wbss($reply, 'cast_schedule', 'cast_schedule_success' . $eventSuffix . ':' . $elapsedMs . 'ms') + [
            'cast_name_detected' => $castNameDetectedValue,
        ];
    }

    if ($isWorking === false) {
        $reply = "{$nameDisplay}は{$dateLabel}は出勤予定ではないようです。\nただ、当日の変更もあるので、気になる場合はLINEで確認していただくと安心です。\n別のキャストさんの出勤も確認しますか？";

        return twin_rule_result_with_wbss($reply, 'cast_schedule', 'cast_schedule_not_working' . $eventSuffix . ':' . $elapsedMs . 'ms') + [
            'cast_name_detected' => $castNameDetectedValue,
        ];
    }

    $reply = "{$nameDisplay}の{$dateLabel}の出勤情報を今はうまく確認できませんでした。\n気になる場合はLINEで確認していただくと確実です。";

    return twin_rule_result_with_wbss($reply, 'cast_schedule', 'cast_schedule_error' . $eventSuffix . ':' . $elapsedMs . 'ms') + [
        'cast_name_detected' => $castNameDetectedValue,
    ];
}

function twin_rule_recommend_cast_response(): array
{
    $result = twin_wbss_fetch_attendance(twin_rule_value('wbss_store_key', 'seika'), twin_wbss_today_date());
    $elapsedMs = (int) ($result['elapsed_ms'] ?? 0);

    $linePromptReply = "初めてでしたら、話しやすいタイプのキャストさんが安心だと思います。\n本日出勤中のキャストさんから、雰囲気に合いそうな方をご案内できますよ。\nLINEで事前にご相談いただくと、お好みに合わせてご紹介しやすいです✨\n落ち着いて話したい感じですか？それとも明るく楽しみたい感じですか？";

    if (!($result['ok'] ?? false)) {
        return [
            'reply' => $linePromptReply,
            'intent' => 'recommend_cast',
            'response_mode' => 'rule',
            'wbss_api_event_name' => 'wbss_api_call',
            'wbss_api_event_value' => 'attendance_error:' . $elapsedMs . 'ms',
            'recommend_cast_executed' => true,
            'recommend_cast_prompted' => true,
        ];
    }

    $data = twin_wbss_data($result);
    $names = twin_wbss_name_candidates(twin_wbss_array($data, ['casts', 'names', 'cast_list', 'staff']));

    if (count($names) === 0) {
        return [
            'reply' => $linePromptReply,
            'intent' => 'recommend_cast',
            'response_mode' => 'rule',
            'wbss_api_event_name' => 'wbss_api_call',
            'wbss_api_event_value' => 'attendance_empty:' . $elapsedMs . 'ms',
            'recommend_cast_executed' => true,
            'recommend_cast_prompted' => true,
        ];
    }

    $picks = array_slice($names, 0, 3);
    $lines = ['本日出勤中のキャストさんから、雰囲気に合いそうな方をご案内できます✨'];
    foreach ($picks as $name) {
        $lines[] = "・{$name}さん";
    }
    $lines[] = "などが在籍しています。";
    $lines[] = "落ち着いて話したい感じですか？それとも明るく楽しみたい感じですか？";
    $lines[] = "LINEで事前にお伝えいただけると、より詳しくご案内できますよ。";

    return [
        'reply' => implode("\n", $lines),
        'intent' => 'recommend_cast',
        'response_mode' => 'rule',
        'wbss_api_event_name' => 'wbss_api_call',
        'wbss_api_event_value' => 'attendance_success:' . $elapsedMs . 'ms',
        'recommend_cast_executed' => true,
        'recommend_cast_prompted' => true,
    ];
}

function twin_rule_reply_for_intent(string $intent, string $message = '', array $context = []): ?array
{
    $storeName = twin_rule_value('store_name', '星華');
    $hours = twin_rule_value('business_hours');
    $closedDay = twin_rule_value('closed_days');
    $address = twin_rule_value('address');
    $area = twin_rule_value('area');
    $priceSummary = twin_rule_value('price_summary');
    $priceNotes = twin_rule_value('price_notes');
    $atmosphere = twin_rule_value('atmosphere');
    $beginnerMessage = twin_rule_value('beginner_message');
    $lineUrl = twin_rule_value('line_url');
    $priceUrl = twin_rule_value('price_url');
    $recruitSummary = twin_rule_value('recruit_summary');
    $lastCastName = twin_context_value($context, 'last_cast_name', '');
    $lastCastStatus = twin_context_value($context, 'last_cast_status', '');

    return match ($intent) {
        'attendance' => twin_rule_attendance_response($context, twin_detect_attendance_date($message)),
        'cast_schedule' => twin_rule_cast_schedule_response($message, $context),
        'cast_confirmation_yes' => [
            'reply' => twin_rule_join([
                "ありがとうございます♪",
                $lastCastName !== '' ? "{$lastCastName}さんをご希望なんですね✨" : 'ありがとうございます♪',
                $lastCastStatus === 'not_working'
                    ? ($lastCastName !== '' ? "{$lastCastName}さんは本日は出勤予定ではありませんが、当日の変更もあるのでLINEで確認していただくと確実です。" : '本日は出勤予定ではありませんが、当日の変更もあるのでLINEで確認していただくと確実です。')
                    : ($lastCastStatus === 'not_found'
                        ? ($lastCastName !== '' ? "{$lastCastName}さんは今は確認できませんでした。表記違いの可能性もあるのでLINEで確認していただくと確実です。" : '今は確認できませんでした。表記違いの可能性もあるのでLINEで確認していただくと確実です。')
                        : '当日の出勤変更もあるので、LINEで確認していただくと安心ですよ。'),
                "ちなみにお一人で来られる予定ですか？",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'first_visit_yes' => [
            'reply' => twin_rule_join([
                "ありがとうございます♪",
                "初めてのお客様も多いので安心してください。",
                "分からないことがあれば、何でも聞いてくださいね。",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'alone_yes' => [
            'reply' => twin_rule_join([
                "ありがとうございます♪",
                "お一人でもゆっくり過ごしやすいようにご案内します。",
                "今日はご来店予定ですか？",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'reservation_yes' => [
            'reply' => twin_rule_join([
                "ありがとうございます♪",
                "ご予約でしたらスムーズにご案内しやすいです。",
                "何名様くらいでお考えですか？",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'greeting' => [
            'reply' => twin_rule_join([
                "こんばんは♪",
                "お話しできて嬉しいです。",
                "{$storeName}は初めてですか？それとも来られたことがありますか？",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'anxiety' => [
            'reply' => twin_rule_join([
                "初めてのお店は少し不安になりますよね。",
                "星華はスタッフがご案内しますので、分からないことがあっても大丈夫ですよ。",
                "ご来店前にLINEで雰囲気を確認しておくのもいいと思います。",
                "お一人でのご来店を考えていますか？",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'first_visit' => [
            'reply' => twin_rule_join([
                "ありがとうございます♪",
                $beginnerMessage !== '' ? $beginnerMessage : '初めてのお客様も多いので安心してください。',
                "最初は少し緊張されますか？",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'business_hours' => [
            'reply' => twin_rule_join([
                $hours !== '' ? "{$storeName}の営業時間は{$hours}です。" : "{$storeName}の営業時間は公式ページでご確認ください。",
                $closedDay !== '' ? "定休日は{$closedDay}です。" : '',
                "当日の空き状況は変わることがあるので、今からならLINEで確認すると安心です。",
                "今日のご来店を考えていますか？",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'reservation' => [
            'reply' => twin_rule_join([
                "ご予約いただくとスムーズにご案内しやすいです。",
                $lineUrl !== '' ? "LINEからもお問い合わせできますよ。" : '',
                "何名様くらいでお考えですか？",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'friends' => [
            'reply' => twin_rule_join([
                "ご友人とのご来店も楽しみやすいと思います。",
                "人数が分かるとお席のご案内もしやすいです。",
                "何名様くらいでお考えですか？",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'after_party' => [
            'reply' => twin_rule_join([
                "飲み会帰りにも立ち寄りやすいと思います。",
                "少し落ち着いて飲み直したい感じですか？",
                "それとも賑やかに楽しみたい感じですか？",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'budget' => [
            'reply' => twin_rule_join([
                "ご予算は先に確認しておくと安心ですね。",
                $priceSummary !== '' ? "基本料金の目安はこちらです。" : '',
                $priceSummary !== '' ? $priceSummary : '',
                "初めてなら料金ページも見ておくのがおすすめです。",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'price' => [
            'reply' => twin_rule_join([
                "星華はセット料金制です。",
                $priceSummary !== '' ? "料金の目安はこちらです。" : '',
                $priceSummary !== '' ? $priceSummary : '',
                $priceNotes !== '' ? $priceNotes : '',
                $priceUrl !== '' ? "詳しい料金は料金ページも確認できます。" : '',
                "人数や時間が決まっていれば、LINEでお伝えいただくとスムーズです。",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'alone' => [
            'reply' => twin_rule_join([
                "お一人で来られる方もいらっしゃいますよ。",
                "スタッフがご案内しますので、初めてでも大丈夫です。",
                "今日はお一人でのご来店を考えていますか？",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'nomination' => [
            'reply' => twin_rule_join([
                "指名なしでも楽しめますよ。",
                "お店で雰囲気を見てから、気になるキャストを指名していただく形でも大丈夫です。",
                "気になるタイプがあれば、そこに合わせてご案内しますね。",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'location' => [
            'reply' => twin_rule_join([
                $address !== '' ? "{$storeName}は{$address}にあります。" : "{$storeName}の所在地は公式ページでご確認ください。",
                $area !== '' ? "エリアでいうと{$area}です。" : '',
                "岡山駅からの行き方も見ますか？",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'atmosphere' => [
            'reply' => twin_rule_join([
                $atmosphere !== '' ? $atmosphere : "{$storeName}は落ち着いた高級感と、親しみやすさのある雰囲気を大切にしています。",
                "にぎやかすぎる場所より、少し落ち着いて飲みたい方に合いやすいと思います。",
                "どんな雰囲気をイメージしていますか？",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'champagne' => [
            'reply' => twin_rule_join([
                "シャンパンは特別な夜にぴったりですね。",
                "星華でも人気があります。",
                "お祝いですか？それとも少し華やかに飲みたい感じですか？",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'recruit' => [
            'reply' => twin_rule_join([
                "求人についてもご案内できます。",
                $recruitSummary !== '' ? $recruitSummary : '未経験の方でも不安を減らせるように説明しますね。',
                "体験入店を考えていますか？",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'group_visit' => [
            'reply' => twin_rule_join([
                "ありがとうございます♪",
                "皆さんでのご来店予定なんですね✨",
                "人数が決まっていれば、LINEで事前にご連絡いただくとお席もスムーズです。",
                "皆さん初めてのご来店ですか？",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
            'conversation_context_next' => 'waiting_group_visit_answer',
        ],
        'group_visit_yes' => [
            'reply' => "ありがとうございます♪\n皆さんでのご来店予定なんですね✨\n初めての方もいらっしゃいますか？",
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'arrival_time' => [
            'reply' => twin_rule_join([
                "ありがとうございます♪",
                "その時間帯のご来店予定なんですね✨",
                "お席の状況もあるので、LINEで事前にご確認いただくとスムーズです。",
                "何名様でご来店予定ですか？",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
            'conversation_context_next' => 'waiting_arrival_time_answer',
        ],
        'arrival_time_yes' => [
            'reply' => "ありがとうございます♪\nご来店時間の目安ありがとうございます✨\nお席の状況はLINEでご確認いただくと確実ですよ。",
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'crowd' => [
            'reply' => twin_rule_join([
                "ありがとうございます♪",
                "日によって状況は変わりますが、週末やイベント日は賑わうことが多いです✨",
                "本日の状況はLINEで確認していただくと確実ですよ。",
                "何時頃のご来店を考えていますか？",
            ]),
            'intent' => $intent,
            'response_mode' => 'rule',
            'conversation_context_next' => 'waiting_crowd_answer',
        ],
        'crowd_yes' => [
            'reply' => "ありがとうございます♪\nLINEで本日の状況をお気軽にご確認ください✨",
            'intent' => $intent,
            'response_mode' => 'rule',
        ],
        'recommend_cast' => twin_rule_recommend_cast_response(),
        'price_estimate' => (static function () use ($message): array {
            preg_match('/(\d+)\s*(?:人|名)/u', $message, $peopleMatch);
            preg_match('/(\d+)\s*セット/u', $message, $setsMatch);
            $people    = isset($peopleMatch[1]) ? (int) $peopleMatch[1] : 0;
            $sets      = isset($setsMatch[1])  ? (int) $setsMatch[1]  : 0;
            $unitPrice = 7700;

            if ($people > 0 && $sets > 0) {
                $total = $unitPrice * $people * $sets;
                $reply = "通常料金の目安で計算すると、{$people}名様で{$sets}セットの場合は\n7,700円 × {$people}名 × {$sets}セット = " . number_format($total) . "円くらいです。\n表示価格は消費税10％込みで、サービス料はありません。\n指名や追加ドリンクがある場合は変わることがあります。\nご来店は何時頃をお考えですか？";
                $eventValue = "people={$people},sets={$sets},total={$total}";
            } else {
                $reply = "料金の目安をお伝えするために、何名様で何セット考えていますか？";
                $eventValue = "people={$people},sets={$sets},total=0";
            }

            return [
                'reply'               => $reply,
                'intent'              => 'price_estimate',
                'response_mode'       => 'rule',
                'wbss_api_event_name' => 'price_estimate_detected',
                'wbss_api_event_value' => $eventValue,
            ];
        })(),
        'vip' => [
            'reply' => twin_rule_join([
                "VIP ROOMは1set 1席 11,000円です。",
                "通常席とは料金が変わりますので、ご来店前にLINEで確認していただけると確実です。",
                "何名様でのご利用をお考えですか？",
            ]),
            'intent' => 'vip',
            'response_mode' => 'rule',
        ],
        'drink_price' => [
            'reply' => twin_rule_join([
                "フリードリンクにはハウスボトルが含まれます。",
                "追加ドリンクや指名などがある場合は料金が変わることがあります。",
                "気になる点はLINEでご確認いただくとスムーズです。",
            ]),
            'intent' => 'drink_price',
            'response_mode' => 'rule',
        ],
        'area_question' => [
            'reply' => twin_rule_join([
                "岡山・中央町エリアにはいろいろなお店があります。",
                "星華は落ち着いた高級感と親しみやすさを大切にしています。",
                "初めてでも入りやすい雰囲気を探されていますか？",
            ]),
            'intent' => 'area_question',
            'response_mode' => 'rule',
        ],
        'cast_type' => [
            'reply' => twin_rule_join([
                "星華はいろいろなタイプのキャストが在籍しています。",
                "落ち着いて話しやすい子、明るく盛り上げてくれる子など、その日の出勤によって雰囲気も変わります。",
                "初めてでしたら、本日出勤中の中から話しやすいタイプをご案内することもできますよ。",
                "どんな雰囲気の子がお好みですか？",
            ]),
            'intent' => 'cast_type',
            'response_mode' => 'rule',
        ],
        'repeat_visitor' => [
            'reply' => twin_rule_join([
                "ありがとうございます♪",
                "以前にも来ていただいているんですね、嬉しいです✨",
                "今日はどなたか気になるキャストさんはいますか？",
                "気になる方がいれば、LINEで出勤確認しておくと確実ですよ。",
            ]),
            'intent' => 'repeat_visitor',
            'response_mode' => 'rule',
        ],
        'other' => null,
        default => null,
    };
}

function twin_rule_engine_response(string $message, ?string $intent = null, array $context = []): array
{
    $intent = $intent ?: twin_detect_intent($message, $context);
    $response = twin_rule_reply_for_intent($intent, $message, $context);

    if (is_array($response)) {
        return $response;
    }

    $storeName = twin_rule_value('store_name', '星華');
    $fallbacks = [
        "そうなんですね♪\nもう少し詳しく聞かせてもらえますか？",
        "ありがとうございます♪\n料金・雰囲気・場所など、気になることから聞いてくださいね。",
        "お店に来る前に少しでも安心してもらえたら嬉しいです。\n初めてのご来店を考えていますか？",
        "{$storeName}のことで気になる点があれば、何でも聞いてくださいね。\n一番知りたいのは料金・営業時間・場所のどれですか？",
    ];

    return [
        'reply' => $fallbacks[random_int(0, count($fallbacks) - 1)],
        'intent' => $intent,
        'response_mode' => 'rule',
    ];
}
