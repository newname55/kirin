<?php

declare(strict_types=1);

/**
 * 店舗設定一元管理 (v0.9.2)
 *
 * 店舗を追加するにはこのファイルに配列エントリを追加し、
 * twin_valid_store_keys() (app/store.php) にキーを追加する。
 *
 * 既存の seika.php は互換性維持のため残す。
 * 新規コードはすべて twin_store_config() を通じて取得する。
 */

if (!function_exists('twin_store_config')) {
    function twin_store_config(string $storeKey = 'seika'): array
    {
        static $stores = null;
        if ($stores === null) {
            $stores = twin_all_store_configs();
        }
        return $stores[$storeKey] ?? $stores['seika'];
    }
}

if (!function_exists('twin_store_value')) {
    function twin_store_value(string $storeKey, string $key, string $fallback = ''): string
    {
        $cfg = twin_store_config($storeKey);
        $v = $cfg[$key] ?? $fallback;
        if (is_array($v)) {
            $v = implode('、', array_map(static fn($i) => trim((string) $i), $v));
        }
        $v = trim((string) $v);
        return $v !== '' ? $v : $fallback;
    }
}

if (!function_exists('twin_all_store_configs')) {
    function twin_all_store_configs(): array
    {
        return [

            // ── 星華 ────────────────────────────────────────────────
            'seika' => [
                'store_key'         => 'seika',
                'store_name'        => 'Club 星華',
                'display_name'      => 'TWIN SEIKA',
                'area'              => '岡山市北区中央町',
                'address'           => '〒700-0836 岡山県岡山市北区中央町4-101 KTNビル2F',
                'business_hours'    => '20:00〜LAST',
                'closed_days'       => '毎週日曜日',
                'line_url'          => 'https://line.me/R/ti/p/%40nuu4414x',
                'instagram_url'     => 'https://www.instagram.com/club_.seika/',
                'price_url'         => 'https://okayama-seika.com/#system',
                'site_url'          => 'https://okayama-seika.com/',
                'tel'               => '086-235-5588',
                'default_ai_name'   => 'TWIN SEIKA',
                'default_role_label'=> 'CLUB SEIKA DIGITAL HOSTESS',
                'default_greeting'  => 'こんばんは。TWIN SEIKAです。初めての方も安心して楽しめるように、料金や雰囲気など何でも聞いてくださいね。',
                'price_summary'     => "SYSTEM 50分\n20:00〜20:30 6,600円\n20:30〜LAST 7,700円\n指名料 1,650円\n女性のお客様 3,850円\nVIP ROOM 1set 1席 11,000円",
                'price_notes'       => '表示価格はすべて消費税10％込みの総額です。サービス料はありません。フリードリンクにはハウスボトルが含まれます。',
                'tax'               => '消費税10％込み',
                'service_charge'    => 'なし',
                'nomination_fee'    => '1,650円',
                'female_customer_fee'=> '4,400円',
                'vip_room_fee'      => '1set 1席 11,000円',
                'atmosphere'        => '岡山・中央町でゆったり楽しめる大型キャバクラです。岡山最大級クラスの広々フロア、団体様歓迎、VIP ROOM完備、在籍キャスト多数が特徴で、落ち着いた雰囲気から華やかな雰囲気まで楽しめます。',
                'beginner_message'  => '初めてのご来店でも安心してお楽しみいただけるよう、料金やご案内の流れをわかりやすくまとめています。明朗会計で、店内の雰囲気も事前に確認しやすい案内になっています。',
                'access'            => '公式サイトの店舗情報では、岡山市北区中央町4-101 KTNビル2Fとして案内されています。Google Mapリンクも掲載されています。',
                'recruit_summary'   => 'キャスト・スタッフ募集中。体験時給 5,000円以上可、即日支給・未経験歓迎。フロアキャストは体験時給 最大5,000円可 / 入店時給 3,000円〜、18歳以上（高校生不可）、20:00〜LASTの間で相談可能です。',
                'recruit_benefits'  => '日払い相談、送り、衣装レンタル、ヘアメイク、体験入店、未経験歓迎',
                'wbss_store_key'    => 'seika',
            ],

            // ── 麒麟（crew-onboarding 初期実装店舗）──────────────────
            'kirin' => [
                'store_key'          => 'kirin',
                'store_name'         => 'CLUB 麒麟',
                'display_name'       => 'CREW KIRIN',
                'area'               => '岡山市北区柳町',
                'address'            => '700-0904 岡山県 岡山市北区柳町 一丁目14-12 西川100ビル8階',
                'business_hours'     => '20:00〜LAST',
                'closed_days'        => '月曜日',
                'line_url'           => 'https://line.me/R/ti/p/@371frwet', // 要更新
                'instagram_url'      => 'https://www.instagram.com/clubkirin',       // 要更新
                'price_url'          => 'https://clubkirin.com/system.html',
                'site_url'           => 'https://clubkirin.com/',
                'tel'                => '0120-000-494',
                'default_ai_name'    => 'CREW KIRIN',
                'default_role_label' => 'CLUB 麒麟 入店前コンシェルジュ',
                'default_greeting'   => 'こんにちは！CREW KIRINです。体験入店・給与・勤務時間・未経験の不安など、気になることは何でも聞いてください。',
                'price_summary'     => "SYSTEM 50分\n20:00〜20:30 6,600円\n20:30〜LAST 7,700円\n指名料 1,650円\n女性のお客様 3,850円\nVIP ROOM 1set 1席 11,000円",
                'price_notes'       => '表示価格はすべて消費税10％込みの総額です。サービス料はありません。フリードリンクにはハウスボトルが含まれます。',
                'tax'               => '消費税10％込み',
                'service_charge'    => 'なし',
                'nomination_fee'    => '1,650円',
                'female_customer_fee'=> '3,850円',
                'vip_room_fee'      => '1set 1席 11,000円',
                'atmosphere'         => '（雰囲気情報未設定）',
                'beginner_message'   => '未経験の方でも安心してご相談いただけます。体験入店から始めて、自分のペースで無理なくスタートできます。',
                'access'             => '（アクセス情報未設定）',
                'recruit_summary'    => '体験入店歓迎・未経験歓迎。給与・シフト・プライバシー配慮など、まずはLINEでお気軽にご相談ください。',
                'recruit_benefits'   => '体験入店、日払い相談、シフト自由、未経験歓迎、身バレ配慮',
                'wbss_store_key'     => '',
            ],

            // ── CREOLE ──────────────────────────────────────────────
            'creole' => [
                'store_key'         => 'creole',
                'store_name'        => 'CREOLE',
                'display_name'      => 'TWIN CREOLE',
                'area'              => '岡山市北区',
                'address'           => '（住所未設定）',
                'business_hours'    => '20:00〜LAST',
                'closed_days'       => '未設定',
                'line_url'          => 'https://line.me/R/ti/p/%40creole',  // 要更新
                'instagram_url'     => 'https://www.instagram.com/creole/',   // 要更新
                'price_url'         => 'https://creole.example.com/#system',  // 要更新
                'site_url'          => 'https://creole.example.com/',          // 要更新
                'tel'               => '',
                'default_ai_name'   => 'TWIN CREOLE',
                'default_role_label'=> 'CREOLE DIGITAL HOSTESS',
                'default_greeting'  => 'こんばんは。TWIN CREOLEです。料金や雰囲気など、気になることは何でもどうぞ♪',
                'price_summary'     => '（料金未設定）',
                'price_notes'       => '',
                'tax'               => '消費税10％込み',
                'service_charge'    => 'なし',
                'nomination_fee'    => '未設定',
                'female_customer_fee'=> '未設定',
                'vip_room_fee'      => '未設定',
                'atmosphere'        => '（雰囲気情報未設定）',
                'beginner_message'  => '初めてのご来店でも安心してお楽しみいただけます。',
                'access'            => '（アクセス情報未設定）',
                'recruit_summary'   => '（求人情報未設定）',
                'recruit_benefits'  => '',
                'wbss_store_key'    => 'creole',
            ],

        ];
    }
}

/**
 * 店舗設定配列からOpenAIプロンプト用テキストブロックを生成する。
 * seika.php の twin_seika_knowledge_prompt_block() と同等の汎用版。
 */
if (!function_exists('twin_store_knowledge_prompt_block')) {
    function twin_store_knowledge_prompt_block(array $cfg): string
    {
        $map = [
            'store_name'          => '店舗名',
            'business_hours'      => '営業時間',
            'closed_days'         => '定休日',
            'address'             => '所在地',
            'area'                => 'エリア',
            'price_summary'       => '料金',
            'price_notes'         => '料金補足',
            'tax'                 => '税',
            'service_charge'      => 'サービス料',
            'nomination_fee'      => '指名料',
            'female_customer_fee' => '女性のお客様',
            'vip_room_fee'        => 'VIP ROOM',
            'atmosphere'          => '雰囲気',
            'beginner_message'    => '初心者へのメッセージ',
            'access'              => 'アクセス',
            'line_url'            => 'LINE URL',
            'instagram_url'       => 'Instagram URL',
            'price_url'           => '料金URL',
            'tel'                 => '電話番号',
            'recruit_summary'     => '求人情報',
        ];
        $lines = [];
        foreach ($map as $key => $label) {
            $v = trim((string) ($cfg[$key] ?? ''));
            if ($v !== '') {
                $lines[] = "{$label}: {$v}";
            }
        }
        return implode("\n", $lines);
    }
}
