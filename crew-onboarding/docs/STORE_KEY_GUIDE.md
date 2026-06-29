# store_key 運用ガイド

Project TWIN における `store_key` の役割と、チャット画面・管理画面での挙動をまとめます。

---

## store_key とは

店舗を識別するキーです。現在の有効値は `seika`（星華）と `creole`（クレオール）の2つです。

---

## チャット画面（index.php）の store_key

**一般ユーザーが見る公開チャット画面の店舗は、管理画面のセッション切替ではなく、環境設定で固定します。**

### 決定優先順位

| 優先度 | 設定箇所 | 備考 |
|---|---|---|
| 1 | 環境変数 `TWIN_STORE_KEY` | 本番固定。設定があれば管理画面切替も無効になる |
| 2 | `app/config.local.php` の `'store_key'` | デプロイ環境ごとに設定する推奨方法 |
| 3 | なし → デフォルト | `'seika'` が使われる |

`index.php` は `session_start()` を呼ばないため、管理画面の店舗切替（`$_SESSION['twin_admin_store_key']`）はチャット画面に反映されません。これは仕様です。

### CREOLE 公開時の設定例

```php
// app/config.local.php（XServer CREOLE 環境）
return [
    'store_key' => 'creole',
    'db_host'   => 'localhost',
    'db_name'   => 'xs110262_creole',
    // ...
];
```

---

## 管理画面（admin.php）の店舗切替

**管理画面の「設定・分析対象」切替は、分析データや設定の対象店舗を変えるためのものです。公開チャットの店舗は切り替わりません。**

- 管理画面にログインしてセッションが有効な間だけ有効
- 管理者が複数店舗の設定や分析を1つの画面で確認するためのスイッチ
- XServer など本番環境では `TWIN_STORE_KEY` 環境変数で固定するため切替ボタンは無効化される

---

## 複数店舗の関係整理

```
本番 XServer (creole環境)
  config.local.php: store_key = 'creole'
    ↓
  index.php → chatEnvStoreKey = 'creole' → TWIN CREOLE が表示される ✅
  admin.php → 管理者は「クレオール」で起動（切替可能）

開発 raspi4 (seika環境)
  config.local.php: store_key = 'seika' または 未設定（デフォルト）
    ↓
  index.php → chatEnvStoreKey = 'seika' → TWIN SEIKA が表示される ✅
  admin.php → 管理者は「星華」で起動（切替して両店舗を確認可能）
```

---

## 管理画面 UI の見方

管理画面トップバーに以下が表示されます。

```
設定・分析対象:  [ 星華 ] [ クレオール ]
公開チャット環境: seika
```

管理対象と公開チャット環境が異なる場合（例: クレオールを分析中だが公開チャットは星華）は橙色で警告が表示されます：

```
⚠️ 公開チャット: seika / 管理対象: creole
```

これは管理上の注意表示であり、チャット画面は `config.local.php` の設定に従って正しく動作しています。

---

## store_key が関係するファイル

| ファイル | 役割 |
|---|---|
| `app/store.php` | `twin_current_store_key()` 定義。優先順位ロジック |
| `app/knowledge/stores.php` | 店舗ごとの設定（名前・料金・URL等）|
| `app/config.local.php` | デプロイ環境の設定（store_key を書く） |
| `public/index.php` | チャット画面。`session_start()` なし → セッション切替を無視 |
| `public/admin.php` | 管理画面。`session_start()` あり → 切替ボタンで管理対象を変更可能 |
