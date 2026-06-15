# AI HANDOFF: club 麒麟 Kirin website

## 現在の状態

- リポジトリ: `/Users/newname/webproject/kirin_website`
- ブランチ: `main`
- 直近の大きな変更は、トップ導線の整理、`first-guide.html` の LP 化、`recruit.html` の新規追加、画像の WebP 化です。

## 主要ページ

- `index.html`
  - トップページ
  - 「初めての方へ」と「求人情報」への導線を追加済み
- `first-guide.html`
  - 麒麟のコンセプト説明 LP
  - 漫画調の流れ画像は `assets/img/guide-flow1.webp` 〜 `guide-flow5.webp`
- `recruit.html`
  - 求人ページ
  - 体験時給、入店時給、応募導線、掲載先リンクを掲載
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

## 直近の実装メモ

- LP は静的企業ページではなく、特設コンテンツ寄りの構成
- 「麒麟の最大の特徴」「普通のキャバクラと何が違う？」「ご来店の流れ」の見出し重複は、上位ラベルを短くして整理済み
- 流れの漫画画像は 5 枚の個別カードで縦読みしやすくした

