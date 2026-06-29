# crew-onboarding

> **Project TWIN 全体の設計思想・開発方針については**
> `../AI_HANDOFF.md`
> を参照してください。
>
> この README は crew-onboarding アプリ固有の説明です。

> Project TWIN を構成するアプリのひとつです。
> 求人に興味を持つ応募希望者（未経験者含む）を対象に、応募前の不安・疑問を AI が解消し、LINE 応募へ誘導します。
> 会話データの収集と分析を通じて、採用品質の継続的な改善を目的とします。
> 他のアプリ（guest-concierge など）とは独立して開発・運用します。

PHP + MariaDB 構成。応答は `rule` と `openai` を切り替えられ、失敗時は自動で `fallback_rule` に落ちます。

---

## アプリの位置づけ

crew-onboarding は `kirin_website` の一部ではありません。

Project TWIN の独立したアプリです。麒麟（Kirin）が最初の実運用店舗ですが、将来的に他店舗への横展開を想定した構成にします。

---

## 対応範囲

- 仕事内容・働き方の説明
- 給与・時給・バック体系
- 勤務時間・シフトの柔軟性
- 未経験者の不安解消
- プライバシー・身バレへの配慮説明
- LINE 応募 CTA

---

## ディレクトリ

```text
crew-onboarding/
├── public/
│   ├── index.php       ← チャット画面
│   ├── admin.php       ← 管理画面
│   └── uploads/
├── app/
│   ├── config.php
│   ├── config.local.php  ← Git 管理外（本番設定）
│   ├── chat_api.php
│   ├── response_engine.php
│   ├── knowledge/      ← 知識データ（ロジックに混ぜない）
│   └── engines/
│       ├── rule_engine.php
│       └── openai_engine.php
├── database/
│   ├── schema.sql
│   └── migrations/
├── docs/
│   ├── AI_HANDOFF.md
│   └── UPDATE_HISTORY.md
├── storage/logs/
├── README.md
└── INSTALL.md
```

---

## バージョン管理

アプリ全体の表示バージョンは `app/config.php` の `APP_VERSION` で一元管理します。

---

## 管理画面ログイン

`app/config.php` の `ADMIN_PASSWORD_HASH` を確認してください。
本番環境では `config.local.php` か環境変数で上書きしてください。

---

## 開発・運用フロー

### リポジトリ構成

| 環境 | パス | 役割 |
|---|---|---|
| Macローカル | `/Users/newname/webproject/project_twin/crew-onboarding` | **正（編集・commit・push）** |
| 本番サーバー | デプロイ先（git pull のみ） | |
| GitHub | `newname55/project_twin`（crew-onboarding サブディレクトリ） | 中央リポジトリ |

### 基本フロー

```bash
# 1. Macローカルで編集・コミット
cd /Users/newname/webproject/project_twin/crew-onboarding
git add <files>
git commit -m "..."
git push origin main

# 2. 本番サーバーへ反映（pull のみ、直接編集禁止）
git pull --ff-only
```

### ルール

- **本番サーバーで直接ファイルを編集しない**
- 修正はMacローカルで行い、commit/push後にサーバーでgit pullする
- `app/config.local.php` / `storage/` / APIキー類はGit管理外

---

## テスト

`app/engines/recruit_engine.php` の parser やペルソナ応答を変更した後は、必ず以下の順番で2種類のテストを実行してください。

### 1. parser 単体テスト（DB不要・高速）

```bash
php scripts/test_parsers.php
```

`parse_alcohol` / `parse_bring` / `parse_days` / `parse_experience` / `parse_referrals` / `parse_hourly` を直接呼び、40ケースの OK/NG を表示します。

**期待結果:** `全件 OK: 40/40`

### 2. ペルソナ総合テスト（DB必要・Raspi4で実行）

```bash
php scripts/run_persona_tests.php --clean
```

10人のペルソナが chat API に順番にメッセージを送り、問診完了・Grade・score・経験 を確認します。

**期待結果:** `Grade 一致: 10/10`・全ペルソナが「問診: 完了」

| ペルソナ | 想定 Grade | score | 確認ポイント |
|---|---|---|---|
| P01 あかり | C | 55 | 未経験ルート |
| P02 みく   | D | 15 | 飲めない・呼客なし |
| P03 さつき | A | 100 | 経験者ルート |
| P04 ゆい   | B | 60 | 経験者・掛け持ち |
| P05 れな   | A | 80 | 未経験・呼客あり |
| P06 なな   | D | 38 | 副業感覚・出勤少 |
| P07 はる   | D | 15 | 大学生・飲めない |
| P08 こと   | A | 100 | 経験者・呼客強め |
| P09 まい   | B | 65 | experience=some |
| P10 りん   | C | 43 | 育児中・ブランク |

### テスト失敗時の対処

1. **parser 単体テストが NG** → `app/engines/recruit_engine.php` のキーワードを修正してから再実行。ペルソナテストは parser が通ってから。
2. **ペルソナテストが「未完了」** → 該当ペルソナのメッセージが engine の step 順と不一致の可能性。`docs/TEST_PERSONAS.md` の step 注釈を確認。
3. **Grade が不一致** → score の計算ロジックは `twin_recruit_calc_score()` を確認。詳細な SQL 検証は `docs/TEST_SQL.md` を参照。

### 関連ファイル

| ファイル | 役割 |
|---|---|
| `scripts/test_parsers.php` | parser 単体テスト（40ケース） |
| `scripts/run_persona_tests.php` | ペルソナ総合テスト（10人） |
| `docs/TEST_PERSONAS.md` | 各ペルソナの定義・メッセージ・想定結果 |
| `docs/TEST_SQL.md` | DB 上の結果を直接確認する SQL 集 |

---

## 設定

`app/config.local.php` を新規作成します（Git 管理外）。

```php
<?php
return [
    'store_key'      => 'kirin',

    'openai_api_key' => 'sk-proj-xxxxxxxx',

    'db' => [
        'host'     => 'localhost',
        'database' => 'your_db_name',
        'username' => 'your_db_user',
        'password' => 'your_db_password',
    ],

    'admin_users' => [
        'your_admin_id' => '$2y$12$xxxxxxxx',
    ],
];
```

---

## 本番移行時の注意点

- DBパスワードを初期値のまま使わないでください
- LINE応募URLは正式URLに差し替えてください
- OpenAI API キーはコミット禁止です
- 知識データ（`app/knowledge/`）の内容は公開前に確認してください
- `public/admin.php` は本番では IP 制限または Basic Auth の追加を検討してください

---

## ログ

PHP例外などのアプリログは `storage/logs/app.log` に出力します。

---

## このアプリの目的

crew-onboarding の目的は、単に求人向けチャットボットを提供することではありません。

応募希望者が抱える不安や疑問を AI が解消し、その会話データを継続的に蓄積・分析することで、採用率や応募体験を改善していくことを目的としています。

このアプリで得られた知見は、Project TWIN 全体の運営ノウハウや知識資産として活用され、将来的な他店舗への横展開や AI の品質向上にも活かされます。

チャットはユーザーとの接点であり、会話データこそが Project TWIN の最も重要な資産です。