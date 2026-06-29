# Project TWIN v1.0 監査結果

作成日: 2026-06-23

監査対象:

- `docs/TWIN_V1_CHECKLIST.md`
- 現在のコードベース

前提:

- 初回作成時は調査とドキュメント作成のみ。
- 2026-06-23 に v1.0 公開前 Critical 項目の一部を実装対応した。

## 2026-06-23 対応状況追記

v1.0公開前 Critical 項目のうち、最小変更で安全性を上げる修正を実施した。

- 管理画面の外部公開対策: セッションCookieに `HttpOnly` / `SameSite=Lax` / HTTPS時 `Secure` を設定。`admin.php` に no-store cache header を追加。`TWIN_REQUIRE_SECURE_ADMIN=1` の公開環境では `ADMIN_USERNAME` / `ADMIN_PASSWORD_HASH` 未設定時にログイン不可とした。
- 個人情報マスク: `app/privacy.php` を追加し、電話番号、メールアドレス、明示的な LINE ID、住所らしき文字列、決済情報らしき連番をマスク。新規会話ログ保存前、イベントログ保存時、OpenAI送信前、CSV/analysis_json エクスポート時に適用した。
- 店舗別ログ: `chat_sessions`、`chat_messages`、`event_logs`、`conversion_events` に `store_key` を保存できるようにした。既存DBにカラムがない環境でも壊れないよう、コード側はカラム存在時のみ保存する。
- DB再構築: `database/schema.sql` を現行構成に合わせて更新し、`ai_usage_logs`、`ai_character_settings`、`store_key` 系カラムを含めた。既存DB向けに `database/migrations/009_add_store_key_to_logs.sql` を追加した。

残課題:

- `database/migrations/009_add_store_key_to_logs.sql` は本番・検証DBへ未適用。
- 管理画面の Basic 認証またはIP制限はサーバー設定として別途必要。
- `creole` 正式店舗情報は未入力。
- 個人情報マスクは明確なパターンのみ対象。本名判定や完全な住所判定は過剰検出を避けるため未対応。
- 既存DB内に過去保存された個人情報がある場合、今回の保存前マスクだけでは過去データは自動修正されない。

## 店舗別設定

状態: 一部完了

理由:

- `app/store.php` で `TWIN_STORE_KEY`、管理画面セッション、`config.local.php`、デフォルト `seika` の順に `store_key` を解決している。
- `app/knowledge/stores.php` に `seika` / `creole` の設定は存在する。
- `public/index.php`、`app/engines/rule_engine.php`、`app/engines/openai_engine.php`、`public/admin.php` は概ね `twin_current_store_key()` / `twin_store_value()` を参照している。
- ただし `creole` の住所、料金、URL が未設定または `example.com` のまま。
- `public/assets/js/chat.js` に `TWIN SEIKA` console label と `twin_seika_session_token` が残っている。表示上の誤案内ではないが、複数店舗展開時にセッション共有・ログ調査が紛らわしい。
- `public/index.php` の `aria-label="TWIN SEIKA チャット"`、デフォルト画像 `twin-seika.jpeg`、一部 fallback 文言に星華固定が残る。
- `app/engines/openai_engine.php` は汎用店舗ナレッジを使うが、fallback の AI名・肩書きが `TWIN SEIKA` / `クラブ星華の来店前チャットボット` のまま。

関連ファイル:

- `app/store.php`
- `app/knowledge/stores.php`
- `app/engines/rule_engine.php`
- `app/engines/openai_engine.php`
- `public/index.php`
- `public/admin.php`
- `public/assets/js/chat.js`
- `docs/AI_HANDOFF.md`
- `docs/UPDATE_HISTORY.md`

修正内容:

- `app/knowledge/stores.php` の `creole` 設定を正式情報へ更新する。
- `public/assets/js/chat.js` の `TWIN SEIKA` label と `twin_seika_session_token` は 2026-06-23 対応で store_key 依存へ変更済み。
- `public/index.php` の `aria-label`、fallback AI名、fallback 肩書き、fallback あいさつ、default avatar を店舗設定に寄せる。
- `app/engines/openai_engine.php` の fallback AI名・肩書きを現在店舗の `default_ai_name` / `default_role_label` から作る。
- `rg "Club 星華|星華|TWIN SEIKA|seika|okayama-seika|club_.seika"` の結果を、意図的な seika 店舗設定と修正対象に分類する。
- 新店舗追加時の手順を `twin_valid_store_keys()` 追加だけに依存しないよう、設定追加チェックリスト化する。

優先度: High

## DBマイグレーション整理

状態: 一部完了

理由:

- `database/migrations/002`、`003`、`006`、`007`、`008` は存在する。
- `ai_character_settings` migration はある。
- `database/schema.sql` は 2026-06-23 対応で `ai_usage_logs`、`ai_character_settings`、`store_key` 系カラムを含む形へ更新済み。
- `database/migrations/009_add_store_key_to_logs.sql` を追加し、既存DBへ `store_key` を追加できる状態にした。
- ただし migration の本番・検証DB適用、rollback 方針、適用済み確認SQL、Xserver 移設時の初回構築手順はまだ不足している。
- `008_create_ai_character_settings.sql` の初期データは `store_id='default'` で、現行の `seika` / `creole` 店舗別ロードとはずれがある。

関連ファイル:

- `database/schema.sql`
- `database/migrations/002_add_intent_to_chat_messages.sql`
- `database/migrations/003_add_conversation_analytics.sql`
- `database/migrations/006_create_app_settings.sql`
- `database/migrations/007_create_ai_usage_logs.sql`
- `database/migrations/008_create_ai_character_settings.sql`
- `app/chat_api.php`
- `app/ai_character_settings.php`
- `public/admin.php`
- `public/admin_export.php`
- `docs/DEPLOY_XSERVER.md`
- `docs/SYSTEM_SPEC.md`

修正内容:

- `database/migrations/009_add_store_key_to_logs.sql` を検証DB・本番DBへ適用する。
- `admin_export.php` と管理画面集計を店舗別に絞り込めるようにする。
- migration 適用順、確認SQL、バックアップ、rollback 手順を docs に追加する。
- migration 適用後、`chat_sessions`、`chat_messages`、`event_logs`、`conversion_events` に `store_key` が保存されることを確認する。

優先度: Critical

## 管理画面の権限

状態: 一部完了

理由:

- `public/admin.php` はセッションログインを持ち、`password_verify()` を使っている。
- `app/config.php` は `ADMIN_USERNAME` / `ADMIN_PASSWORD_HASH` の環境変数上書きに対応している。
- 設定保存とAIキャラクター保存には CSRF 検証がある。
- `public/admin_export.php` は `twin_admin_require_login()` を呼ぶため、未ログインのエクスポートは防げる。
- `public/admin.php` は `X-Robots-Tag: noindex, nofollow` を送る。
- ただし、デフォルト管理者IDと password hash がコードに残っている。公開サーバーで環境変数未設定だと既定値で動く。
- `app/admin_common.php` は 2026-06-23 対応で `HttpOnly` / `SameSite=Lax` / HTTPS時 `Secure` を設定済み。
- Basic 認証・IP制限は README / SYSTEM_SPEC で推奨されているが、Project TWIN 側の実装・設置確認はない。
- `TWIN_REQUIRE_SECURE_ADMIN=1` の公開環境では環境変数の管理者認証情報が必須になった。ただし Dev タブ、OpenAI診断、AI利用額、analysis_json ダウンロードは本番表示条件なしで出ている。

関連ファイル:

- `public/admin.php`
- `public/admin_export.php`
- `app/admin_common.php`
- `app/config.php`
- `docs/SYSTEM_SPEC.md`
- `README.md`

修正内容:

- 公開環境では `ADMIN_USERNAME` / `ADMIN_PASSWORD_HASH` を必須にし、コード内デフォルト認証情報で公開できないようにする。
- Xserver 側で Basic 認証またはIP制限を必須手順化し、設定ファイルまたは設置メモに反映する。
- Dev タブ、OpenAI診断、AI利用額、analysis_json は本番フラグで非表示または管理者上位権限に限定する。
- ログアウト時の cookie 削除も Cookie 属性込みで行う。
- 未ログインで `admin.php` / `admin_export.php` / POST 設定保存にアクセスする確認手順を追加する。

優先度: Critical

## エラーログ

状態: 一部完了

理由:

- `app/db.php` に `twin_log()` があり、`storage/logs/app.log` へ出力する。
- OpenAI、WBSS、AIキャラクター、設定読み込みなどは `error_log()` または `twin_log()` で追える。
- `docs/SYSTEM_SPEC.md` にログ出力先とローテーション未実装の注意がある。
- ただしログローテーション、保存期間、削除手順は未実装。
- OpenAI / WBSS / DB の例外メッセージをそのままログや `event_logs.event_value` に残す箇所がある。
- `event_logs` には `openai_error` / `openai_fallback` の詳細メッセージ、`cast_name_detected` の raw 発言などが入り得る。
- 本番の PHP error_log の場所、Xserver での確認手順、秘密情報マスク方針が未確定。

関連ファイル:

- `app/db.php`
- `app/response_engine.php`
- `app/engines/openai_engine.php`
- `app/clients/wbss_client.php`
- `app/ai_character_settings.php`
- `app/settings.php`
- `app/chat_api.php`
- `public/admin.php`
- `docs/SYSTEM_SPEC.md`
- `docs/DEPLOY_XSERVER.md`

修正内容:

- `event_logs` と主要な `twin_log()` の例外メッセージは 2026-06-23 対応でマスクを通すようにした。残る `error_log()` 直書き箇所は追加確認する。
- `event_logs.event_value` に保存する OpenAI / WBSS エラーは種別コード中心にし、詳細は安全にマスクしたログへ分離する。
- `cast_name_detected` の `raw=` にユーザー発言を保存する設計を見直す。
- Xserver の PHP error_log 確認方法を `docs/DEPLOY_XSERVER.md` へ追記する。
- `storage/logs/` の権限、ローテーション、保存期間、削除手順を v1.0 手順に追加する。
- ログ書き込み失敗時に画面へ詳細エラーを出さないことを確認する。

優先度: High

## OpenAI失敗時の挙動

状態: 一部完了

理由:

- `app/response_engine.php` は `openai` / `hybrid` で例外時に `twin_rule_engine_response()` へ fallback し、`response_mode='fallback_rule'` を返す。
- `app/engines/openai_engine.php` は `openai_request`、`openai_success`、`openai_error` を記録する。
- 管理画面は OpenAI 診断として成功・失敗・fallback・usage 保存件数を表示する。
- 料金、営業時間、場所、LINE、Instagram、出勤などは rule engine で一定対応できる。
- ただし失敗ケース別の実動作テスト証跡がない。
- fallback 時のユーザー文言は rule engine の intent 応答に依存するため、OpenAI が落ちたことを自然に案内する専用文言ではない。
- `openai_error` / `openai_fallback` に生の例外メッセージが保存される。
- `hybrid` 表示に「v0.7予定」と残っており、運用画面上の到達点と実装状態がずれている。

関連ファイル:

- `app/response_engine.php`
- `app/engines/openai_engine.php`
- `app/engines/rule_engine.php`
- `app/chat_api.php`
- `app/question_ranking.php`
- `public/admin.php`
- `database/migrations/007_create_ai_usage_logs.sql`

修正内容:

- OpenAI API Key 未設定、401、429、500、タイムアウト、空返答のテストを実施し、結果を docs に残す。
- fallback 時に `reply` が空にならず、ユーザーに自然な案内が出ることを自動または手動で確認する。
- `openai_error` / `openai_fallback` の保存値をエラー種別コードへ寄せ、生メッセージ保存を避ける。
- `hybrid` の管理画面表記を現在の実装状態に合わせる。
- OpenAI なしでも固定CTAと LINE 導線が必ず維持されることを確認する。
- OpenAI へ渡す現在発言と recent transcript は 2026-06-23 対応でマスクを通すようにした。

優先度: High

## 個人情報を保存しない設計確認

状態: 一部完了

理由:

- `app/privacy.php` を追加し、2026-06-23 対応後の新規ユーザー発言は保存前に明確な個人情報パターンをマスクする。
- `chat_sessions` は `ip_address` と `user_agent` を保存している。
- `public/admin.php` と `public/admin_export.php` は会話ログ、イベントログ、analysis_json を管理者向けに表示・出力する。
- 電話番号、メールアドレス、明示的な LINE ID、住所らしき文字列、決済情報らしき連番は保存前・OpenAI送信前・エクスポート時にマスクする。
- ただし本名判定、身分証情報の詳細判定、過去保存データの自動マスクは未対応。
- 誤入力された個人情報を管理画面から削除する機能、保存期間、削除依頼時の手順がない。
- OpenAI へ直近会話を送るため、個人情報をユーザーが入力した場合は外部APIへ送信される可能性がある。

関連ファイル:

- `app/chat_api.php`
- `app/engines/openai_engine.php`
- `database/schema.sql`
- `public/admin.php`
- `public/admin_export.php`
- `app/question_ranking.php`
- `README.md`
- `docs/SYSTEM_SPEC.md`
- `docs/TWIN_V1_CHECKLIST.md`

修正内容:

- 保存してよいデータと保存しないデータを仕様として確定する。
- 保存しないデータ: 本名、電話番号、LINE ID、住所、決済情報、身分証情報。
- 過去保存済みログの棚卸しと必要に応じたマスク・削除を行う。
- 誤保存時の削除手順または管理画面からの削除機能を用意する。
- ログ保存期間と削除依頼対応を docs に明記する。
- `ip_address` と `user_agent` を保存継続するか、匿名化・短期保存にするか判断する。

優先度: Critical

# v1.0公開判定

公開前に対応必須

理由:

- `creole` 正式店舗情報が未入力で、公開時に `example.com` や未設定文言が出る可能性がある。
- `database/schema.sql` と `store_key` 保存コードは更新済みだが、既存DBへの `009` migration 適用確認が未完了。
- 管理画面はログイン保護とCookie属性強化済みだが、外部公開前に Basic 認証またはIP制限、公開環境での環境変数認証設定が必要。
- 個人情報マスクは明確なパターンのみ対応済みだが、過去ログ、本名、身分証情報の詳細判定、削除運用は未完了。
- OpenAI fallback は実装済みだが、失敗ケース別の公開前テストとエラー詳細マスクが未完了。

公開判断:

- 今すぐ公開可能: 不可
- 軽微修正後公開可能: 不可
- 公開前に対応必須: 該当
