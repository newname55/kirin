<?php

declare(strict_types=1);

/**
 * StoreSiteParser
 *
 * 店舗公式サイトから営業情報・料金情報・URLを自動取得する。
 * 優先順位: JSON-LD → Open Graph → HTML本文 → footer → 正規表現
 *
 * 全店舗共通の汎用 Parser。HTML 構造に依存しすぎない設計にする。
 */
class StoreSiteParser
{
    /** @var array<string, string> */
    private static array $errors = [];

    /**
     * 指定 URL のサイトを解析して店舗設定候補を返す。
     *
     * @return array{
     *   business_hours: string|null,
     *   closed_days: string|null,
     *   address: string|null,
     *   area: string|null,
     *   tel: string|null,
     *   price_summary: string|null,
     *   nomination_fee: string|null,
     *   vip_room_fee: string|null,
     *   service_charge: string|null,
     *   site_url: string|null,
     *   line_url: string|null,
     *   instagram_url: string|null,
     *   twitter_url: string|null,
     *   tiktok_url: string|null,
     *   google_map_url: string|null,
     *   _errors: array<string>,
     *   _source: array<string, string>,
     * }
     */
    public static function parse(string $url, int $timeoutSec = 10): array
    {
        self::$errors = [];

        $html = self::fetch($url, $timeoutSec);
        if ($html === null) {
            return self::emptyResult(['fetch_failed' => 'サイトの取得に失敗しました: ' . $url]);
        }

        // ページ全体 + footer セクションを分離
        $footerHtml = self::extractFooter($html);

        $result = [
            'business_hours' => null,
            'closed_days'    => null,
            'address'        => null,
            'area'           => null,
            'tel'            => null,
            'price_summary'  => null,
            'nomination_fee' => null,
            'vip_room_fee'   => null,
            'service_charge' => null,
            'site_url'       => rtrim($url, '/'),
            'line_url'       => null,
            'instagram_url'  => null,
            'twitter_url'    => null,
            'tiktok_url'     => null,
            'google_map_url' => null,
        ];

        // ソース追跡（どの方法で取得したか）
        $sources = [];

        // ① JSON-LD
        $jsonld = self::parseJsonLd($html);
        self::mergeFrom($result, $sources, $jsonld, 'json-ld');

        // ② Open Graph / meta
        $og = self::parseOpenGraph($html);
        self::mergeFrom($result, $sources, $og, 'og');

        // ③ 本文・footer を正規表現で解析
        $regex = self::parseByRegex($html, $footerHtml, $url);
        self::mergeFrom($result, $sources, $regex, 'regex');

        // ④ リンク解析（SNS URL）
        $links = self::parseLinks($html, $url);
        self::mergeFrom($result, $sources, $links, 'link');

        $result['_errors'] = self::$errors;
        $result['_source'] = $sources;

        return $result;
    }

    // ── フェッチ ────────────────────────────────────────────────────────

    private static function fetch(string $url, int $timeout): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout'        => $timeout,
                'user_agent'     => 'Mozilla/5.0 (compatible; TWIN-SiteParser/1.0)',
                'follow_location'=> 1,
                'max_redirects'  => 5,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $html = @file_get_contents($url, false, $ctx);
        if ($html === false) {
            self::$errors['fetch'] = "file_get_contents 失敗: {$url}";
            return null;
        }
        // 文字コード変換（Shift-JIS など）
        if (preg_match('/charset=["\']?([^"\';\s>]+)/i', $html, $m)) {
            $enc = strtoupper(trim($m[1]));
            if (!in_array($enc, ['UTF-8', 'UTF8'], true)) {
                $converted = @mb_convert_encoding($html, 'UTF-8', $enc);
                if ($converted !== false) {
                    $html = $converted;
                }
            }
        }
        return $html;
    }

    // ── footer 抽出 ─────────────────────────────────────────────────────

    private static function extractFooter(string $html): string
    {
        if (preg_match('/<footer[\s>].*?<\/footer>/si', $html, $m)) {
            return $m[0];
        }
        // footer タグがなければ末尾 20% を使う
        return substr($html, (int) (strlen($html) * 0.8));
    }

    // ── JSON-LD ─────────────────────────────────────────────────────────

    private static function parseJsonLd(string $html): array
    {
        $result = [];
        preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $matches);
        foreach ($matches[1] as $raw) {
            $data = @json_decode(trim($raw), true);
            if (!is_array($data)) {
                continue;
            }
            // Graph 配列展開
            $nodes = $data['@graph'] ?? [$data];
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $type = (string) ($node['@type'] ?? '');

                // LocalBusiness / NightClub / BarOrPub 等
                if (preg_match('/LocalBusiness|NightClub|Bar|Restaurant|EntertainmentBusiness/i', $type)) {
                    self::extractFromLocalBusiness($node, $result);
                }
            }
        }
        return $result;
    }

    /** @param array<mixed> $node */
    private static function extractFromLocalBusiness(array $node, array &$result): void
    {
        // 住所
        $addr = $node['address'] ?? null;
        if (is_array($addr)) {
            $parts = array_filter([
                $addr['postalCode'] ?? '',
                $addr['addressRegion'] ?? '',
                $addr['addressLocality'] ?? '',
                $addr['streetAddress'] ?? '',
            ], fn($v) => is_string($v) && $v !== '');
            if ($parts) {
                $result['address'] ??= implode(' ', $parts);
                // エリア（市区町村）
                $locality = trim((string) ($addr['addressLocality'] ?? ''));
                if ($locality !== '') {
                    $result['area'] ??= $locality;
                }
            }
        } elseif (is_string($addr) && $addr !== '') {
            $result['address'] ??= $addr;
        }

        // 電話
        $tel = (string) ($node['telephone'] ?? '');
        if ($tel !== '') {
            $result['tel'] ??= self::normalizeTel($tel);
        }

        // 営業時間（openingHours / openingHoursSpecification）
        $oh = $node['openingHours'] ?? ($node['openingHoursSpecification'] ?? null);
        if (is_string($oh) && $oh !== '') {
            $result['business_hours'] ??= self::normalizeHours($oh);
        } elseif (is_array($oh)) {
            $texts = [];
            foreach ($oh as $spec) {
                if (is_string($spec)) {
                    $texts[] = $spec;
                } elseif (is_array($spec)) {
                    $open  = (string) ($spec['opens']  ?? '');
                    $close = (string) ($spec['closes'] ?? '');
                    if ($open !== '' && $close !== '') {
                        $texts[] = "{$open}〜{$close}";
                    }
                }
            }
            if ($texts) {
                $result['business_hours'] ??= implode(' / ', $texts);
            }
        }

        // URL
        $siteUrl = (string) ($node['url'] ?? '');
        if ($siteUrl !== '') {
            $result['site_url'] ??= rtrim($siteUrl, '/');
        }

        // sameAs（SNSリンク）
        $sameAs = $node['sameAs'] ?? [];
        if (is_string($sameAs)) {
            $sameAs = [$sameAs];
        }
        foreach ((array) $sameAs as $sa) {
            self::classifySocialUrl((string) $sa, $result);
        }
    }

    // ── Open Graph / meta ───────────────────────────────────────────────

    private static function parseOpenGraph(string $html): array
    {
        $result = [];

        // og:url → site_url
        if (preg_match('/<meta[^>]+property=["\']og:url["\'][^>]+content=["\']([^"\']+)["\'][^>]*>/i', $html, $m)) {
            $result['site_url'] = rtrim(trim($m[1]), '/');
        }
        // OGP URL が content= の前にある場合
        if (!isset($result['site_url']) &&
            preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+property=["\']og:url["\'][^>]*>/i', $html, $m)) {
            $result['site_url'] = rtrim(trim($m[1]), '/');
        }

        return $result;
    }

    // ── 正規表現による本文解析 ─────────────────────────────────────────

    private static function parseByRegex(string $html, string $footerHtml, string $baseUrl): array
    {
        $result = [];
        $text   = self::stripTags($html);
        $footerText = self::stripTags($footerHtml);

        // 電話番号
        if (!isset($result['tel'])) {
            foreach ([$text, $footerText] as $src) {
                if (preg_match('/(?:tel[：:・]?\s*|電話[：:]?\s*)?(0\d{1,4}[-–—]\d{1,4}[-–—]\d{4})/u', $src, $m)) {
                    $result['tel'] = self::normalizeTel($m[1]);
                    break;
                }
            }
        }

        // 営業時間（優先: 「営業時間：HH:mm〜LAST」形式）
        if (!isset($result['business_hours'])) {
            $patterns = [
                '/営業時間[：:・\s]*([0-9０-９]{1,2}[:：][0-9０-９]{2}[^。\n]*(?:LAST|ラスト|閉店))/u',
                '/OPEN[：:\s]*([0-9０-９]{1,2}[:：][0-9０-９]{2})\s*[〜~]\s*(CLOSE|LAST|[0-9０-９]{1,2}[:：][0-9０-９]{2})/ui',
                '/([0-9０-９]{1,2}[:：][0-9０-９]{2})\s*[〜~]\s*(LAST|ラスト)/ui',
                '/([2０-２][0-9０-９][:：][0-9０-９]{2})\s*[〜~]\s*(翌[0-9０-９]{1,2}[:：][0-9０-９]{2})/u',
            ];
            foreach ($patterns as $pat) {
                if (preg_match($pat, $text, $m)) {
                    $result['business_hours'] = self::normalizeHours(trim($m[0]));
                    break;
                }
            }
        }

        // 定休日
        if (!isset($result['closed_days'])) {
            if (preg_match('/定休日[：:・\s]*([^\n。、]{2,20})/u', $text, $m)) {
                $result['closed_days'] = trim($m[1]);
            }
        }

        // 住所（〒 から始まるパターン）
        if (!isset($result['address'])) {
            foreach ([$footerText, $text] as $src) {
                if (preg_match('/〒[0-9０-９]{3}-[0-9０-９]{4}\s*[^\n]{5,60}/u', $src, $m)) {
                    $result['address'] = trim($m[0]);
                    // エリア推測（都道府県 + 市区町村）
                    if (preg_match('/(?:岡山県|大阪府|東京都|神奈川県|愛知県|福岡県)\s*([^\s\d]{2,10}[市区町村])/u', $m[0], $am)) {
                        $result['area'] ??= trim($am[0]);
                    }
                    break;
                }
            }
        }

        // 指名料
        if (!isset($result['nomination_fee'])) {
            if (preg_match('/指名料[：:・\s]*([0-9,０-９，]{1,8}円)/u', $text, $m)) {
                $result['nomination_fee'] = trim($m[1]);
            }
        }

        // VIP 料金
        if (!isset($result['vip_room_fee'])) {
            if (preg_match('/VIP[^\n]{0,20}([0-9,０-９，]{2,8}円)/ui', $text, $m)) {
                $result['vip_room_fee'] = trim($m[0]);
            }
        }

        // サービス料
        if (!isset($result['service_charge'])) {
            if (preg_match('/サービス料[：:\s]*([^\n。]{1,20})/u', $text, $m)) {
                $result['service_charge'] = trim($m[1]);
            }
        }

        // 料金概要（セット料金のブロックを抽出）
        if (!isset($result['price_summary'])) {
            $priceBlock = self::extractPriceBlock($text);
            if ($priceBlock !== null) {
                $result['price_summary'] = $priceBlock;
            }
        }

        // Google Map URL（iframe embed）
        if (!isset($result['google_map_url'])) {
            if (preg_match('/https:\/\/www\.google\.com\/maps\/embed[^"\')\s]*/i', $html, $m)) {
                $result['google_map_url'] = $m[0];
            } elseif (preg_match('/https:\/\/maps\.google\.com[^"\')\s]*/i', $html, $m)) {
                $result['google_map_url'] = $m[0];
            }
        }

        return $result;
    }

    // ── リンク解析（SNS URL） ──────────────────────────────────────────

    private static function parseLinks(string $html, string $baseUrl): array
    {
        $result = [];
        preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches);
        foreach ($matches[1] as $href) {
            $abs = self::toAbsolute($href, $baseUrl);
            self::classifySocialUrl($abs, $result);
        }
        return $result;
    }

    // ── ヘルパー ────────────────────────────────────────────────────────

    private static function classifySocialUrl(string $url, array &$result): void
    {
        if ($url === '') {
            return;
        }
        if (str_contains($url, 'line.me') || str_contains($url, 'lin.ee')) {
            $result['line_url'] ??= $url;
        } elseif (str_contains($url, 'instagram.com')) {
            $result['instagram_url'] ??= rtrim($url, '/');
        } elseif (str_contains($url, 'twitter.com') || str_contains($url, 'x.com')) {
            // 自店舗アカウントのみ（タイムライン等を除外）
            if (preg_match('#(?:twitter|x)\.com/(?!share|intent|hashtag)([^/?#\s]+)#i', $url)) {
                $result['twitter_url'] ??= $url;
            }
        } elseif (str_contains($url, 'tiktok.com')) {
            $result['tiktok_url'] ??= $url;
        } elseif (str_contains($url, 'google.com/maps') || str_contains($url, 'maps.google')) {
            $result['google_map_url'] ??= $url;
        }
    }

    private static function stripTags(string $html): string
    {
        // script / style を除去してからタグ除去、連続空白を正規化
        $html = preg_replace('/<(?:script|style)[^>]*>.*?<\/(?:script|style)>/si', '', $html) ?? $html;
        $html = strip_tags($html);
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return (string) preg_replace('/[ \t]+/', ' ', $html);
    }

    private static function normalizeHours(string $raw): string
    {
        $raw = trim($raw);
        // 全角数字 → 半角
        $raw = mb_convert_kana($raw, 'n', 'UTF-8');
        // 余分なラベルを取り除く
        $raw = (string) preg_replace('/^(?:営業時間|OPEN|CLOSE)[：:\s]*/ui', '', $raw);
        return trim($raw);
    }

    private static function normalizeTel(string $raw): string
    {
        $raw = mb_convert_kana(trim($raw), 'n', 'UTF-8');
        return (string) preg_replace('/[^\d\-]/', '', $raw);
    }

    private static function extractPriceBlock(string $text): ?string
    {
        // 「システム」「SYSTEM」「セット」「set」を含む行の前後を抽出
        $lines = explode("\n", $text);
        $found = [];
        $inBlock = false;
        $blank = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                if ($inBlock) {
                    $blank++;
                    if ($blank >= 3) {
                        break;
                    }
                }
                continue;
            }
            $blank = 0;
            if (!$inBlock && preg_match('/(?:システム|SYSTEM|セット|set|料金|PRICE|SYSTEM|飲み放題)/ui', $line)) {
                $inBlock = true;
            }
            if ($inBlock) {
                if (preg_match('/[0-9,円分時〜〜]/u', $line) || preg_match('/指名|VIP|サービス|ドリンク|ボトル/u', $line)) {
                    $found[] = $line;
                    if (count($found) >= 15) {
                        break;
                    }
                }
            }
        }
        if (count($found) < 2) {
            return null;
        }
        return implode("\n", array_unique($found));
    }

    private static function toAbsolute(string $href, string $baseUrl): string
    {
        $href = trim($href);
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
            return '';
        }
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }
        $parsed = parse_url($baseUrl);
        $scheme = ($parsed['scheme'] ?? 'https') . '://';
        $host   = $parsed['host'] ?? '';
        if (str_starts_with($href, '//')) {
            return $scheme . ltrim($href, '/');
        }
        if (str_starts_with($href, '/')) {
            return $scheme . $host . $href;
        }
        $path = rtrim($parsed['path'] ?? '/', '/');
        return $scheme . $host . $path . '/' . $href;
    }

    /** @param array<string> $additionalErrors */
    private static function emptyResult(array $additionalErrors = []): array
    {
        return [
            'business_hours' => null,
            'closed_days'    => null,
            'address'        => null,
            'area'           => null,
            'tel'            => null,
            'price_summary'  => null,
            'nomination_fee' => null,
            'vip_room_fee'   => null,
            'service_charge' => null,
            'site_url'       => null,
            'line_url'       => null,
            'instagram_url'  => null,
            'twitter_url'    => null,
            'tiktok_url'     => null,
            'google_map_url' => null,
            '_errors'        => array_merge(self::$errors, $additionalErrors),
            '_source'        => [],
        ];
    }

    /** @param array<mixed> $from */
    private static function mergeFrom(array &$result, array &$sources, array $from, string $label): void
    {
        foreach ($from as $k => $v) {
            if (str_starts_with((string) $k, '_')) {
                continue;
            }
            if (!isset($result[$k]) || $result[$k] === null || $result[$k] === '') {
                if ($v !== null && $v !== '') {
                    $result[$k] = $v;
                    $sources[$k] = $label;
                }
            }
        }
    }
}
