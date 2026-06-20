# AI HANDOFF: club 麒麟 Kirin website

## 現在の状態

- リポジトリ: `/Users/newname/webproject/kirin_website`
- ブランチ: `main`
- 直近の大きな変更は、現行サイト（clubkirin.com）との機能比較を踏まえた改善実装（2026-06-20）。詳細は `docs/2026-06-20_improvements.md` を参照。

## 表現ルール（必読・厳守）

- 「お好みの席へ案内」「自由に席を選べる」は **使用禁止**。
- 正しい表現は **「入店時スタッフがお席にご案内します」**。新規セクション・FAQ・構造化データを書くときは必ずこの表現に統一する。
- 店内写真は既存画像をそのまま使用。加工・生成・改変はしない。

## 主要ページ

- `index.html`
  - トップページ（シングルページ構成 + 各サブページへのリンク）
  - Hero: **3枚スライダー**（hero.webp / interior.webp / glass.webp、5秒自動切替）
  - Concept直下に「初めての麒麟」漫画風セクション（`guide-flow1〜3.webp` 使用）
  - Concept内に4枚のカード（`.concept-points`）で麒麟の強みを紹介
  - FAQセクションとFAQPage構造化データは6問で同期済み
  - キャストカードは各個別ページ（`cast-*.html`）にリンク済み
  - Instagramフィード: **LightWidget**（ウィジェットID: `fda134fa48075f18a39ebae8f25afdfc`、6列グリッド表示）
  - SNSセクション: Instagram + TikTok リンク + LightWidgetフィード
  - 掲載サイト: ナイツネット・そら街ナイトワーク・体入ショコラ・dqn・体入エミリー・**ポケパラ**・**ヨルコム**
  - お問い合わせは電話のみ（フォームなし）
  - `</body>` 直前にモバイル下部固定CTAバー（TEL/GUIDE/RECRUIT）

- `cast-list.html`
  - キャスト一覧ページ（index.htmlの「キャスト一覧を見る」ボタンのリンク先）
  - 5名のカードを表示、各個別ページへリンク

- `cast-asuka.html` / `cast-kanon.html` / `cast-rio.html` / `cast-runa.html` / `cast-yuki.html`
  - 各キャストのプロフィールページ
  - 写真・名前・ローマ字・タイプ・一言・来店予約TELボタンを掲載
  - 「← キャスト一覧へ戻る」で index.html#cast に戻る

- `first-guide.html`
  - 麒麟のコンセプト説明 LP
  - 漫画調の流れ画像は `guide-flow1.webp` 〜 `guide-flow5.webp`

- `recruit.html`
  - 求人ページ
  - 体験時給、入店時給、応募導線、掲載先リンクを掲載
  - 求人問い合わせ用LINEリンク（`@371frwet`）あり（「LINE予約」とは別）

- `system.html`
  - 料金システムページ

- `privacy.html`
  - プライバシーポリシー

## 画像とアセット

- `assets/img/hero.webp` — Heroスライダー1枚目
- `assets/img/interior.webp` — Heroスライダー2枚目
- `assets/img/glass.webp` — Heroスライダー3枚目
- `assets/img/guide-flow1.webp` 〜 `guide-flow5.webp` — 漫画風フロー画像
- `assets/img/guide-title.png` / `assets/img/store_info.jpg` — 使用中
- `assets/img/cast/cast-asuka.webp` — あすか写真
- `assets/img/cast/cast-kanon.webp` — かのん写真
- `assets/img/cast/cast-rio.webp` — りお写真
- `assets/img/cast/cast-runa.webp` — るな写真
- `assets/img/cast/cast-yuki.webp` — ゆき写真
- `assets/img/cast/cast-hana.webp` / `cast-nattsu.webp` — 未使用（削除不要）
- `assets/img/cast-placeholders.webp` — `assets/css/style.css` から `.link-card--cast::before` で参照中。削除不要。

## ナビ・導線

- ヘッダーナビ: TOP / SYSTEM / CAST / ACCESS / GUIDE / RECRUIT
- フッターナビ: TOP / 料金システム / キャスト / アクセス / 初めての方へ / 求人情報 / お問い合わせ / プライバシーポリシー
- モバイル下部固定CTAバー: TEL / 初めての方へ(GUIDE) / 求人情報(RECRUIT)
- `sitemap.xml` に主要ページ記載済み

## LINEについて

- LINE予約は実施していないためヘッダー・CTAバーに LINE 導線なし
- 求人応募用LINE（`@371frwet`）は `recruit.html` にのみ存在
- LINE公式アカウント稼働後はCTAバーのGUIDEボタンをLINE導線に差し替え推奨
- `.line-cta` の CSS スタイルは `recruit.html` で使用中のため残置

## Instagram フィード（LightWidget）

- `index.html` の SNSセクション内に埋め込み済み
- ウィジェットID: `fda134fa48075f18a39ebae8f25afdfc`（6列グリッド）
- LightWidget の設定変更 → 「Create」→ 新しいiframeのsrc属性のIDだけ差し替えればOK
- `<script src="https://cdn.lightwidget.com/widgets/lightwidget.js">` はページ内1本のみ（重複注意）

## ここから触るなら

- 見た目調整: `assets/css/style.css`
- トップ導線: `index.html`
- ヒーロースライダーの画像変更: `index.html` の `.hero-slide` の `style` 属性の `url(...)` を差し替え
- スライダーの切替間隔変更: `assets/js/main.js` の `setInterval` の `5000`（ミリ秒）を変更
- キャスト追加: `cast-*.html` を新規作成 → `index.html` と `cast-list.html` のキャストリストに追加
- Instagramフィード更新: LightWidgetダッシュボードでウィジェットを編集 → 新IDをiframeのsrcに反映
- コンセプト LP: `first-guide.html`
- 求人周り: `recruit.html`

## 注意点

- `cast-placeholders.webp` は未使用画像ではないので削除しない
- `guide-flow1〜5.webp` は `index.html`（1〜3枚目）と `first-guide.html`（全5枚）で共用
- 画像差し替えの際は HTML の `src` と `width` / `height` をあわせて確認する
- `.hero__logo` CSS は `recruit.html` で使用中のため残置
- LightWidget の `<script>` タグはページ内1本のみにする（重複するとフィードが崩れる場合あり）
