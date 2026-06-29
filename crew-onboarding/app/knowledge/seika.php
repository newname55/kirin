<?php

declare(strict_types=1);

if (!function_exists('twin_load_seika_knowledge')) {
    function twin_load_seika_knowledge(): array
    {
        static $knowledge = null;

        if (is_array($knowledge)) {
            return $knowledge;
        }

        $knowledge = [
            'store_name' => 'Club 星華',
            'site_title' => '岡山・中央町のキャバクラ Club 星華｜大型フロア・団体様歓迎',
            'hours' => '20:00〜LAST',
            'closed_day' => '毎週日曜日',
            'address' => '〒700-0836 岡山県岡山市北区中央町4-101 KTNビル2F',
            'area' => '岡山市北区中央町',
            'price_summary' => "SYSTEM 50分\n20:00〜20:30 6,600円\n20:30〜LAST 7,700円\n指名料 1,650円\n女性のお客様 3,850円\nVIP ROOM 1set 1席 11,000円",
            'price_notes' => '表示価格はすべて消費税10％込みの総額です。サービス料はありません。フリードリンクにはハウスボトルが含まれます。',
            'tax' => '消費税10％込み',
            'service_charge' => 'なし',
            'nomination_fee' => '1,650円',
            'female_customer_fee' => '3,850円',
            'vip_room_fee' => '1set 1席 11,000円',
            'atmosphere' => '岡山・中央町でゆったり楽しめる大型キャバクラです。岡山最大級クラスの広々フロア、団体様歓迎、VIP ROOM完備、在籍キャスト多数が特徴で、落ち着いた雰囲気から華やかな雰囲気まで楽しめます。',
            'beginner_message' => '初めてのご来店でも安心してお楽しみいただけるよう、料金やご案内の流れをわかりやすくまとめています。明朗会計で、店内の雰囲気も事前に確認しやすい案内になっています。',
            'access' => '公式サイトの店舗情報では、岡山市北区中央町4-101 KTNビル2Fとして案内されています。Google Mapリンクも掲載されています。',
            'line_url' => 'https://line.me/R/ti/p/%40nuu4414x',
            'instagram_url' => 'https://www.instagram.com/club_.seika/',
            'price_url' => 'https://okayama-seika.com/#system',
            'tel' => '086-235-5588',
            'recruit_summary' => 'キャスト・スタッフ募集中。体験時給 5,000円以上可、即日支給・未経験歓迎。フロアキャストは体験時給 最大5,000円可 / 入店時給 3,000円〜、18歳以上（高校生不可）、20:00〜LASTの間で相談可能です。',
            'recruit_benefits' => '日払い相談、送り、衣装レンタル、ヘアメイク、体験入店、未経験歓迎',
            'official_url' => 'https://okayama-seika.com/',
        ];

        return $knowledge;
    }
}

if (!function_exists('twin_seika_knowledge_value')) {
    function twin_seika_knowledge_value(array $knowledge, string $key, string $fallback = ''): string
    {
        $value = $knowledge[$key] ?? $fallback;

        if ($value === null) {
            return $fallback;
        }

        if (is_array($value)) {
            $value = implode('、', array_map(static fn ($item) => trim((string) $item), $value));
        }

        $value = trim((string) $value);

        return $value === '' ? $fallback : $value;
    }
}

if (!function_exists('twin_seika_knowledge_prompt_block')) {
    function twin_seika_knowledge_prompt_block(array $knowledge): string
    {
        $lines = [];

        $map = [
            'store_name' => '店舗名',
            'hours' => '営業時間',
            'closed_day' => '定休日',
            'address' => '所在地',
            'area' => 'エリア',
            'price_summary' => '料金',
            'price_notes' => '料金補足',
            'tax' => '税',
            'service_charge' => 'サービス料',
            'nomination_fee' => '指名料',
            'female_customer_fee' => '女性のお客様',
            'vip_room_fee' => 'VIP ROOM',
            'atmosphere' => '雰囲気',
            'beginner_message' => '初めての方向け',
            'access' => 'アクセス',
            'line_url' => 'LINE URL',
            'instagram_url' => 'Instagram URL',
            'price_url' => '料金ページURL',
            'tel' => 'TEL',
            'recruit_summary' => '求人要約',
        ];

        foreach ($map as $key => $label) {
            $value = twin_seika_knowledge_value($knowledge, $key);
            if ($value === '') {
                continue;
            }
            $lines[] = sprintf('%s: %s', $label, $value);
        }

        return implode("\n", $lines);
    }
}

return twin_load_seika_knowledge();
