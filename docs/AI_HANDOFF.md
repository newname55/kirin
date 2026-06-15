# AI HANDOFF: club 麒麟 Kirin website

## 現在の状態

- リポジトリ: `/Users/newname/webproject/kirin_website`
- ブランチ: `main`
- 直近の大きな変更は、トップ導線の整理、`first-guide.html` の LP 化、`recruit.html` の新規追加、画像の WebP 化です。
- さらに直近で、トップページを「高級感60% / 親しみやすさ40%」に寄せたリニューアルを実施済み。詳細は `docs/2026-06-15_top_page_renewal_friendly_luxury.md` を参照。

## 表現ルール（必読・厳守）

- 「お好みの席へ案内」「自由に席を選べる」は **使用禁止**。
- 正しい表現は **「入店時スタッフがお席にご案内します」**。新規セクション・FAQ・構造化データを書くときは必ずこの表現に統一する。
- 店内写真は既存画像をそのまま使用。加工・生成・改変はしない。

## 主要ページ

- `index.html`
  - トップページ
  - 「初めての方へ」と「求人情報」への導線を追加済み
  - Hero: H1は「岡山・柳町のキャバクラ / club 麒麟」の2階層構成 + キャッチコピー
  - Concept直下に「初めての麒麟」漫画風セクション（`guide-flow1〜3.webp` 使用）を追加済み
  - Concept内に4枚のカード（`.concept-points`）で麒麟の強みを紹介
  - FAQセクションとFAQPage構造化データは6問（初めて/一人/カード/指名/団体/求人）で同期済み
    - 「クレジットカードは使えますか？」の回答は「VISA・Mastercard・JCB・American Express・Diners Club」対応を明記
  - LINE予約は実施していないため、`.contact-strip` の「LINEで予約」ボタンと `.price-box` の「電話・LINE受付」表記は削除済み（電話のみの導線に変更）。`recruit.html` の求人問い合わせ用LINEリンク（`@371frwet`）は対象外で残置
  - `</body>` 直前にモバイル下部固定CTAバー（`.mobile-cta-bar`、TEL/GUIDE/RECRUIT）あり
- `first-guide.html`
  - 麒麟のコンセプト説明 LP
  - 漫画調の流れ画像は `assets/img/guide-flow1.webp` 〜 `guide-flow5.webp`（`index.html` の「初めての麒麟」セクションでも1〜3を再利用中）
  - モバイル下部固定CTAバーあり
- `recruit.html`
  - 求人ページ
  - 体験時給、入店時給、応募導線、掲載先リンクを掲載
  - モバイル下部固定CTAバーあり
- `system.html`
  - 料金システムページ
  - モバイル下部固定CTAバーあり
- `privacy.html`
  - プライバシーポリシー

## 画像とアセット

- 流れ画像は PNG から WebP に差し替え済み
- 更新済み主要画像:
  - `assets/img/hero.webp`
  - `assets/img/interior.webp`
  - `assets/img/glass.webp`
  - `assets/img/guide-flow1.webp` 〜 `guide-flow5.webp`
- 旧流れ PNG は削除済み
- `assets/img/guide-title.png` と `assets/img/store_info.jpg` は現状使用中
- `assets/img/cast-placeholders.webp` は `assets/css/style.css` から参照あり

## ナビ・導線

- トップのヘッダーとフッターに `first-guide.html` と `recruit.html` の導線あり
- `sitemap.xml` に両ページを追加済み

## ここから触るなら

- 見た目調整は `assets/css/style.css`
- トップ導線は `index.html`
- コンセプト LP は `first-guide.html`
- 求人周りは `recruit.html`

## 注意点

- 既存のヘッダー・フッター・高級感のトーンは維持する
- `cast-placeholders.webp` は未使用画像ではないので削除しない
- 画像差し替えの際は、HTML の `src` と `width` / `height` をあわせて確認する
- `.hero__logo`（club / 麒麟 / -Kirin- の大きな装飾ブロック）は `index.html` から削除済みだが、`recruit.html` でまだ使用中なのでCSSは残している
- LINEは「準備中」のため、ヘッダー（コミット `0af41a3`）・モバイル下部CTAバーともにLINE導線は未設置。公式LINE稼働後に追加する

## 直近の実装メモ

- LP は静的企業ページではなく、特設コンテンツ寄りの構成
- 「麒麟の最大の特徴」「普通のキャバクラと何が違う？」「ご来店の流れ」の見出し重複は、上位ラベルを短くして整理済み
- 流れの漫画画像は 5 枚の個別カードで縦読みしやすくした

