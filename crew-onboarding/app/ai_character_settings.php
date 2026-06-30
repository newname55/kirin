<?php

declare(strict_types=1);

/**
 * AIキャラクター設定モデル (v0.9.1)
 *
 * ai_character_settings テーブルの読み書きを担当する。
 * store_id ごとの is_active=1 レコードがフロントに反映される。
 */

function twin_ai_character_default(string $storeId = 'seika'): array
{
    require_once __DIR__ . '/knowledge/stores.php';
    $cfg = twin_store_config($storeId);
    return [
        'id'                   => 0,
        'store_id'             => $storeId,
        'ai_name'              => $cfg['default_ai_name']    ?? 'CREW KIRIN',
        'ai_title'             => $cfg['default_role_label'] ?? '',
        'greeting_message'     => $cfg['default_greeting']   ?? '',
        'character_image_path' => null,
        'logo_image_path'      => null,
        'is_active'            => 0,
    ];
}

/**
 * 有効なキャラクター設定を1件返す。なければデフォルト値を返す。
 */
function twin_ai_character_load_active(PDO $pdo, string $storeId = 'seika'): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM ai_character_settings
             WHERE store_id = :store_id AND is_active = 1
             ORDER BY updated_at DESC
             LIMIT 1"
        );
        $stmt->execute(['store_id' => $storeId]);
        $row = $stmt->fetch();
        if (is_array($row) && isset($row['id'])) {
            return $row;
        }
    } catch (Throwable $e) {
        error_log('[TWIN character] load_active error: ' . $e->getMessage());
    }

    return twin_ai_character_default($storeId);
}

/**
 * store_id に紐づくすべての設定を返す（管理画面一覧用）。
 */
function twin_ai_character_load_all(PDO $pdo, string $storeId = 'seika'): array
{
    try {
        $stmt = $pdo->prepare(
            "SELECT * FROM ai_character_settings
             WHERE store_id = :store_id
             ORDER BY is_active DESC, updated_at DESC"
        );
        $stmt->execute(['store_id' => $storeId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('[TWIN character] load_all error: ' . $e->getMessage());
    }

    return [];
}

/**
 * 設定を upsert する。$id=0 なら INSERT、>0 なら UPDATE。
 * is_active=1 にする場合は同じ store_id の他レコードを 0 に落とす。
 */
function twin_ai_character_save(PDO $pdo, array $data): bool
{
    $id       = (int) ($data['id'] ?? 0);
    $storeId  = trim((string) ($data['store_id'] ?? 'seika')) ?: 'seika';
    $aiName   = trim((string) ($data['ai_name'] ?? 'TWIN SEIKA')) ?: 'TWIN SEIKA';
    $aiTitle  = trim((string) ($data['ai_title'] ?? 'CLUB SEIKA DIGITAL HOSTESS'));
    $greeting = trim((string) ($data['greeting_message'] ?? ''));
    $charImg  = $data['character_image_path'] ?? null;
    $logoImg  = $data['logo_image_path'] ?? null;
    $isActive = (int) (bool) ($data['is_active'] ?? 0);

    if ($greeting === '') {
        return false;
    }

    try {
        $pdo->beginTransaction();

        if ($isActive === 1) {
            // 同じ store_id の他レコードを非アクティブに
            $stmt = $pdo->prepare(
                "UPDATE ai_character_settings SET is_active = 0
                 WHERE store_id = :store_id" . ($id > 0 ? ' AND id <> :id' : '')
            );
            $params = ['store_id' => $storeId];
            if ($id > 0) {
                $params['id'] = $id;
            }
            $stmt->execute($params);
        }

        if ($id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE ai_character_settings
                 SET ai_name = :ai_name, ai_title = :ai_title,
                     greeting_message = :greeting_message,
                     character_image_path = :character_image_path,
                     logo_image_path = :logo_image_path,
                     is_active = :is_active
                 WHERE id = :id AND store_id = :store_id"
            );
            $stmt->execute([
                'ai_name'              => $aiName,
                'ai_title'             => $aiTitle,
                'greeting_message'     => $greeting,
                'character_image_path' => $charImg,
                'logo_image_path'      => $logoImg,
                'is_active'            => $isActive,
                'id'                   => $id,
                'store_id'             => $storeId,
            ]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO ai_character_settings
                     (store_id, ai_name, ai_title, greeting_message, character_image_path, logo_image_path, is_active)
                 VALUES
                     (:store_id, :ai_name, :ai_title, :greeting_message, :character_image_path, :logo_image_path, :is_active)"
            );
            $stmt->execute([
                'store_id'             => $storeId,
                'ai_name'              => $aiName,
                'ai_title'             => $aiTitle,
                'greeting_message'     => $greeting,
                'character_image_path' => $charImg,
                'logo_image_path'      => $logoImg,
                'is_active'            => $isActive,
            ]);
        }

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[TWIN character] save error: ' . $e->getMessage());
        return false;
    }
}

/**
 * 画像ファイルアップロードを検証し、uploads/characters/ に保存する。
 * 成功時は web 公開パスを返す。失敗時は null。
 */
function twin_ai_character_upload_image(array $file, string $fieldName = 'character'): ?string
{
    if (!isset($file['tmp_name']) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowedMime, true)) {
        return null;
    }

    $maxBytes = 2 * 1024 * 1024; // 2MB
    if ((int) ($file['size'] ?? 0) > $maxBytes) {
        return null;
    }

    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        default      => 'jpg',
    };

    $uploadDir = (defined('CREW_PUBLIC_ROOT') ? CREW_PUBLIC_ROOT : dirname(__DIR__) . '/public') . '/uploads/characters/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        return null;
    }

    $filename = $fieldName . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destPath = $uploadDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        return null;
    }

    return '/crew-onboarding/uploads/characters/' . $filename;
}
