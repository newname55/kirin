# crew-onboarding AI Hand-off

このファイルは crew-onboarding アプリに関する引き継ぎ情報です。
Project TWIN 全体の設計思想は `project_twin/AI_HANDOFF.md` を参照してください。

## アプリの位置づけ

crew-onboarding は Project TWIN の求人・入店前コンシェルジュアプリです。
`kirin_website` の一部ではなく、Project TWIN の独立したアプリです。
guest-concierge など他アプリとはロジックを共有しません。

## 最初の実装店舗

麒麟（Kirin）が最初の実運用店舗です。
`store_key` は `kirin`。
アプリを麒麟専用にハードコードせず、`app/config.php` / `app/knowledge/` に集約します。

## 現在の到達点

- guest-concierge のアーキテクチャをベースに新規構築中。
- `store_key` ベースの店舗設定を初期から採用。
- 知識データは `app/knowledge/` に分離し、ロジックにハードコードしない。

## 未完了項目

- 麒麟の正式店舗情報入力（LINE応募URL・給与・勤務条件など）。
- AI キャラクター設定（求人向けパーソナリティ）。
- OpenAI API Key 設定。
- 本番デプロイ先の確定。
