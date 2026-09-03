<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/core/LicenseGuard.php';
IMersWAStoreProGuard::checkpoint('admin.entry');

if (file_exists(__DIR__ . '/includes/wa_gateway.php')) {
    require_once __DIR__ . '/includes/wa_gateway.php';
}

if (file_exists(__DIR__ . '/includes/commerce_engine.php')) {
    require_once __DIR__ . '/includes/commerce_engine.php';
}

if (file_exists(__DIR__ . '/includes/rajaongkir.php')) {
    require_once __DIR__ . '/includes/rajaongkir.php';
}

require_login();

$user = current_user();

// ROLE GUARD: kasir boleh akses terbatas.
$loginRole = strtolower(trim((string)($user['role'] ?? '')));
$isKasir = in_array($loginRole, ['kasir', 'cashier'], true);
$isAdmin = $loginRole === 'admin';

// Kasir hanya boleh buka Pesanan Masuk untuk aktifkan/selesaikan pesanan web,
// dan Kasir POS. Menu admin lain tetap dikunci.
$view = $_GET['view'] ?? ($isKasir ? 'orders' : 'dashboard');

if ($isKasir && !in_array($view, ['orders'], true)) {
    header('Location: admin.php?view=orders');
    exit;
}

if (!function_exists('brand_initial')) {
    function brand_initial($text): string
    {
        $text = trim((string)$text);

        if ($text === '') {
            return 'A';
        }

        if (function_exists('mb_substr')) {
            return strtoupper(mb_substr($text, 0, 1, 'UTF-8'));
        }

        return strtoupper(substr($text, 0, 1));
    }
}

if (!function_exists('admin_initial')) {
    function admin_initial($text): string
    {
        return brand_initial($text);
    }
}

function safe_scalar(string $sql, array $params = [], $default = 0)
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $default : $value;
    } catch (Throwable $e) {
        return $default;
    }
}

function safe_rows(string $sql, array $params = []): array
{
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

if (!function_exists('admin_generate_product_sku')) {
    function admin_generate_product_sku(string $name): string
    {
        $base = strtoupper(preg_replace('/[^A-Z0-9]+/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name)));

        if ($base === '') {
            $base = 'PRODUK';
        }

        $base = substr($base, 0, 6);
        $attempt = 0;

        do {
            $suffix = strtoupper(substr(md5($name . microtime(true) . random_int(1000, 9999) . $attempt), 0, 5));
            $sku = 'SKU-' . $base . '-' . $suffix;

            try {
                $stmt = db()->prepare('SELECT COUNT(*) FROM products WHERE sku = ?');
                $stmt->execute([$sku]);
                $exists = (int)$stmt->fetchColumn() > 0;
            } catch (Throwable $e) {
                $exists = false;
            }

            $attempt++;
        } while ($exists && $attempt < 5);

        return $sku;
    }
}

if (!function_exists('admin_next_sort_order')) {
    function admin_next_sort_order(string $table): int
    {
        $allowed = ['categories', 'products', 'banners', 'shipping_rates'];

        if (!in_array($table, $allowed, true)) {
            return 1;
        }

        try {
            $stmt = db()->query("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM {$table}");
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 1;
        }
    }
}


if (!function_exists('admin_json_setting_array')) {
    function admin_json_setting_array(string $key): array
    {
        $raw = setting($key, '[]');
        $data = json_decode((string)$raw, true);
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('admin_clean_repeater_rows')) {
    function admin_clean_repeater_rows(array $rows, array $requiredKeys = []): array
    {
        $clean = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = [];
            foreach ($row as $key => $value) {
                $item[$key] = trim((string)$value);
            }

            $ok = true;
            foreach ($requiredKeys as $requiredKey) {
                if (($item[$requiredKey] ?? '') === '') {
                    $ok = false;
                    break;
                }
            }

            if ($ok) {
                $clean[] = $item;
            }
        }

        return $clean;
    }
}

function chart_json($value): string
{
    return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
}

function short_date_id($date): string
{
    if (!$date) return '-';
    return date('d M Y', strtotime((string)$date));
}

function nav_active($viewName, $current): string
{
    return $viewName === $current ? 'active' : '';
}

if (!function_exists('admin_broadcast_clean_phone')) {
    function admin_broadcast_clean_phone($phone): string
    {
        $phone = preg_replace('/\D+/', '', (string)$phone);

        if ($phone === '') {
            return '';
        }

        if (strpos($phone, '0') === 0) {
            return '62' . substr($phone, 1);
        }

        if (strpos($phone, '8') === 0) {
            return '62' . $phone;
        }

        return $phone;
    }
}

if (!function_exists('admin_broadcast_batch_limit')) {
    function admin_broadcast_batch_limit($value = null): int
    {
        $default = (int)setting('broadcast_batch_limit', '20');
        $limit = $value !== null ? (int)$value : $default;

        if ($limit <= 0) {
            $limit = 20;
        }

        return max(1, min(50, $limit));
    }
}

if (!function_exists('admin_broadcast_policy_notice')) {
    function admin_broadcast_policy_notice(): string
    {
        return (string)setting('broadcast_policy_notice', 'Fitur broadcast WhatsApp disediakan sebagai alat bantu promosi. Gunakan secara wajar, jangan spam, jangan mengirim konten ilegal, dan pahami bahwa risiko banned/suspend nomor WhatsApp tetap bisa terjadi dari pihak WhatsApp/gateway. Jika nomor terkena banned, suspend, limit, atau pembatasan karena penggunaan broadcast yang tidak bijak, hal tersebut di luar tanggung jawab penyedia aplikasi.');
    }
}

if (!function_exists('admin_broadcast_target_options')) {
    function admin_broadcast_target_options(): array
    {
        return [
            'all_customers' => 'Semua pembeli/member',
            'unpaid_orders' => 'Order belum bayar',
            'completed_orders' => 'Pembeli order completed',
            'pos_customers' => 'Pembeli dari POS',
            'web_customers' => 'Pembeli dari checkout web',
            'point_customers' => 'Member punya point',
            'inactive_30_days' => 'Tidak order 30 hari',
            'product_buyers' => 'Pembeli produk tertentu',
        ];
    }
}

if (!function_exists('admin_broadcast_unique_recipients')) {
    function admin_broadcast_unique_recipients(array $rows): array
    {
        $unique = [];

        foreach ($rows as $row) {
            $phone = admin_broadcast_clean_phone($row['phone'] ?? $row['buyer_phone'] ?? '');

            if ($phone === '') {
                continue;
            }

            if (!isset($unique[$phone])) {
                $unique[$phone] = [
                    'customer_id' => !empty($row['customer_id']) ? (int)$row['customer_id'] : (!empty($row['id']) ? (int)$row['id'] : null),
                    'name' => trim((string)($row['name'] ?? $row['buyer_name'] ?? '')),
                    'phone' => $phone,
                    'points' => (int)($row['points'] ?? 0),
                    'total_orders' => (int)($row['total_orders'] ?? 0),
                    'total_spent' => (float)($row['total_spent'] ?? 0),
                    'last_order_at' => (string)($row['last_order_at'] ?? ''),
                    'last_order_number' => (string)($row['last_order_number'] ?? ''),
                ];
            }
        }

        return array_values($unique);
    }
}

if (!function_exists('admin_broadcast_recipients')) {
    function admin_broadcast_recipients(string $targetType, array $filters = []): array
    {
        $targetType = strtolower(trim($targetType));
        $rows = [];

        if ($targetType === 'unpaid_orders') {
            $rows = safe_rows("
                SELECT
                    NULL AS customer_id,
                    buyer_name AS name,
                    buyer_phone AS phone,
                    0 AS points,
                    1 AS total_orders,
                    grand_total AS total_spent,
                    created_at AS last_order_at,
                    order_number AS last_order_number
                FROM orders
                WHERE buyer_phone IS NOT NULL
                  AND buyer_phone <> ''
                  AND COALESCE(payment_status, '') <> 'paid'
                  AND COALESCE(order_status, '') NOT IN ('completed','delivered','cancelled','refunded')
                ORDER BY id DESC
            ");

            return admin_broadcast_unique_recipients($rows);
        }

        if ($targetType === 'completed_orders') {
            $rows = safe_rows("
                SELECT
                    c.id AS customer_id,
                    COALESCE(c.name, o.buyer_name) AS name,
                    COALESCE(c.phone, o.buyer_phone) AS phone,
                    COALESCE(c.points, 0) AS points,
                    COALESCE(c.total_orders, 0) AS total_orders,
                    COALESCE(c.total_spent, 0) AS total_spent,
                    COALESCE(c.last_order_at, o.created_at) AS last_order_at,
                    COALESCE(c.last_order_number, o.order_number) AS last_order_number
                FROM orders o
                LEFT JOIN customers c ON c.phone = o.buyer_phone
                WHERE o.buyer_phone IS NOT NULL
                  AND o.buyer_phone <> ''
                  AND (o.payment_status = 'paid' OR o.order_status IN ('completed','delivered'))
                ORDER BY o.id DESC
            ");

            return admin_broadcast_unique_recipients($rows);
        }

        if ($targetType === 'pos_customers') {
            $rows = safe_rows("
                SELECT
                    c.id AS customer_id,
                    COALESCE(c.name, o.buyer_name) AS name,
                    COALESCE(c.phone, o.buyer_phone) AS phone,
                    COALESCE(c.points, 0) AS points,
                    COALESCE(c.total_orders, 0) AS total_orders,
                    COALESCE(c.total_spent, 0) AS total_spent,
                    COALESCE(c.last_order_at, o.created_at) AS last_order_at,
                    COALESCE(c.last_order_number, o.order_number) AS last_order_number
                FROM orders o
                LEFT JOIN customers c ON c.phone = o.buyer_phone
                WHERE o.buyer_phone IS NOT NULL
                  AND o.buyer_phone <> ''
                  AND COALESCE(o.source, '') = 'pos'
                ORDER BY o.id DESC
            ");

            return admin_broadcast_unique_recipients($rows);
        }

        if ($targetType === 'web_customers') {
            $rows = safe_rows("
                SELECT
                    c.id AS customer_id,
                    COALESCE(c.name, o.buyer_name) AS name,
                    COALESCE(c.phone, o.buyer_phone) AS phone,
                    COALESCE(c.points, 0) AS points,
                    COALESCE(c.total_orders, 0) AS total_orders,
                    COALESCE(c.total_spent, 0) AS total_spent,
                    COALESCE(c.last_order_at, o.created_at) AS last_order_at,
                    COALESCE(c.last_order_number, o.order_number) AS last_order_number
                FROM orders o
                LEFT JOIN customers c ON c.phone = o.buyer_phone
                WHERE o.buyer_phone IS NOT NULL
                  AND o.buyer_phone <> ''
                  AND COALESCE(o.source, '') <> 'pos'
                ORDER BY o.id DESC
            ");

            return admin_broadcast_unique_recipients($rows);
        }

        if ($targetType === 'point_customers') {
            $rows = safe_rows("
                SELECT
                    id AS customer_id,
                    name,
                    phone,
                    COALESCE(points, 0) AS points,
                    COALESCE(total_orders, 0) AS total_orders,
                    COALESCE(total_spent, 0) AS total_spent,
                    last_order_at,
                    last_order_number
                FROM customers
                WHERE phone IS NOT NULL
                  AND phone <> ''
                  AND COALESCE(points, 0) > 0
                  AND COALESCE(status, 'active') = 'active'
                ORDER BY points DESC, updated_at DESC
            ");

            return admin_broadcast_unique_recipients($rows);
        }

        if ($targetType === 'inactive_30_days') {
            $rows = safe_rows("
                SELECT
                    id AS customer_id,
                    name,
                    phone,
                    COALESCE(points, 0) AS points,
                    COALESCE(total_orders, 0) AS total_orders,
                    COALESCE(total_spent, 0) AS total_spent,
                    last_order_at,
                    last_order_number
                FROM customers
                WHERE phone IS NOT NULL
                  AND phone <> ''
                  AND COALESCE(status, 'active') = 'active'
                  AND (
                        last_order_at IS NULL
                        OR last_order_at <= DATE_SUB(NOW(), INTERVAL 30 DAY)
                  )
                ORDER BY last_order_at ASC, id DESC
            ");

            return admin_broadcast_unique_recipients($rows);
        }

        if ($targetType === 'product_buyers') {
            $productId = (int)($filters['product_id'] ?? 0);

            if ($productId <= 0) {
                return [];
            }

            $rows = safe_rows("
                SELECT
                    c.id AS customer_id,
                    COALESCE(c.name, o.buyer_name) AS name,
                    COALESCE(c.phone, o.buyer_phone) AS phone,
                    COALESCE(c.points, 0) AS points,
                    COALESCE(c.total_orders, 0) AS total_orders,
                    COALESCE(c.total_spent, 0) AS total_spent,
                    COALESCE(c.last_order_at, o.created_at) AS last_order_at,
                    COALESCE(c.last_order_number, o.order_number) AS last_order_number
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                LEFT JOIN customers c ON c.phone = o.buyer_phone
                WHERE oi.product_id = ?
                  AND o.buyer_phone IS NOT NULL
                  AND o.buyer_phone <> ''
                ORDER BY o.id DESC
            ", [$productId]);

            return admin_broadcast_unique_recipients($rows);
        }

        $rows = safe_rows("
            SELECT
                id AS customer_id,
                name,
                phone,
                COALESCE(points, 0) AS points,
                COALESCE(total_orders, 0) AS total_orders,
                COALESCE(total_spent, 0) AS total_spent,
                last_order_at,
                last_order_number
            FROM customers
            WHERE phone IS NOT NULL
              AND phone <> ''
              AND COALESCE(status, 'active') = 'active'
            ORDER BY updated_at DESC, id DESC
        ");

        return admin_broadcast_unique_recipients($rows);
    }
}

if (!function_exists('admin_broadcast_message')) {
    function admin_broadcast_message(string $template, array $recipient): string
    {
        $name = trim((string)($recipient['name'] ?? ''));
        $name = $name !== '' ? $name : 'Kak';

        $replace = [
            '{nama}' => $name,
            '{whatsapp}' => (string)($recipient['phone'] ?? ''),
            '{point}' => (string)((int)($recipient['points'] ?? 0)),
            '{total_order}' => (string)((int)($recipient['total_orders'] ?? 0)),
            '{total_belanja}' => rupiah((float)($recipient['total_spent'] ?? 0)),
            '{last_order}' => (string)(($recipient['last_order_at'] ?? '') ?: '-'),
            '{last_order_number}' => (string)(($recipient['last_order_number'] ?? '') ?: '-'),
            '{store_name}' => setting('store_name', setting('app_name', 'Toko Online')),
            '{member_url}' => function_exists('commerce_member_area_url') ? commerce_member_area_url() : 'member.php',
        ];

        return strtr($template, $replace);
    }
}

if (!function_exists('admin_broadcast_update_campaign_counts')) {
    function admin_broadcast_update_campaign_counts(int $campaignId): void
    {
        if ($campaignId <= 0 || !admin_table_exists('wa_broadcast_campaigns') || !admin_table_exists('wa_message_queue')) {
            return;
        }

        $queued = (int)safe_scalar("SELECT COUNT(*) FROM wa_message_queue WHERE campaign_id = ?", [$campaignId]);
        $sent = (int)safe_scalar("SELECT COUNT(*) FROM wa_message_queue WHERE campaign_id = ? AND status = 'sent'", [$campaignId]);
        $failed = (int)safe_scalar("SELECT COUNT(*) FROM wa_message_queue WHERE campaign_id = ? AND status = 'failed'", [$campaignId]);
        $pending = (int)safe_scalar("SELECT COUNT(*) FROM wa_message_queue WHERE campaign_id = ? AND status = 'pending'", [$campaignId]);

        $status = $pending > 0 ? 'queued' : 'completed';
        $completedAtSql = $pending > 0 ? 'completed_at = completed_at' : 'completed_at = COALESCE(completed_at, NOW())';

        try {
            db()->prepare("
                UPDATE wa_broadcast_campaigns
                SET queued_count = ?,
                    sent_count = ?,
                    failed_count = ?,
                    status = ?,
                    {$completedAtSql},
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$queued, $sent, $failed, $status, $campaignId]);
        } catch (Throwable $e) {
            // Abaikan kalau ada perbedaan kolom
        }
    }
}


$primary = setting('primary_color', '#10b981');
$secondary = setting('secondary_color', '#0f172a');
$accent = setting('accent_color', '#fb923c');
$bg = setting('background_color', '#f6fbf8');

$card1Start = setting('dashboard_card_1_start', '#38bdf8');
$card1End = setting('dashboard_card_1_end', '#60a5fa');
$card2Start = setting('dashboard_card_2_start', '#a78bfa');
$card2End = setting('dashboard_card_2_end', '#7c3aed');
$card3Start = setting('dashboard_card_3_start', '#f472b6');
$card3End = setting('dashboard_card_3_end', '#ec4899');
$card4Start = setting('dashboard_card_4_start', '#4ade80');
$card4End = setting('dashboard_card_4_end', '#10b981');

$appName = setting('app_name', 'Toko Online');
$storeName = setting('store_name', 'Toko Online');
$appLogo = setting('app_logo', setting('store_logo', ''));
$appInitial = admin_initial($appName ?: $storeName);

if (!function_exists('admin_upload_setting_image')) {
    function admin_upload_setting_image(string $field, string $folder = 'settings'): ?string
    {
        if (empty($_FILES[$field]) || empty($_FILES[$field]['name'])) {
            return null;
        }

        if ($_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload gambar gagal untuk field: ' . $field);
        }

        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'];
        $originalName = (string)$_FILES[$field]['name'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt, true)) {
            throw new RuntimeException('Format gambar tidak didukung. Gunakan JPG, PNG, WEBP, GIF, atau SVG.');
        }

        $baseDir = __DIR__ . '/uploads';
        $targetDir = $baseDir . '/' . trim($folder, '/');

        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0755, true);
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $safeField = preg_replace('/[^a-zA-Z0-9_-]+/', '-', $field);
        $fileName = $safeField . '-' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
        $targetPath = $targetDir . '/' . $fileName;

        if (!move_uploaded_file($_FILES[$field]['tmp_name'], $targetPath)) {
            throw new RuntimeException('Gagal menyimpan file upload.');
        }

        return 'uploads/' . trim($folder, '/') . '/' . $fileName;
    }
}


if (!function_exists('admin_column_exists')) {
    function admin_column_exists(string $table, string $column): bool
    {
        try {
            $stmt = db()->prepare("
                SELECT COUNT(*)
                FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);

            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('admin_table_exists')) {
    function admin_table_exists(string $table): bool
    {
        try {
            $stmt = db()->prepare("
                SELECT COUNT(*)
                FROM information_schema.TABLES
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
            ");
            $stmt->execute([$table]);

            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('admin_save_product_variants')) {
    function admin_save_product_variants(int $productId, array $variants): int
    {
        if ($productId <= 0 || !admin_table_exists('product_variants')) {
            return 0;
        }

        $saved = 0;

        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }

            $type = trim((string)($variant['type'] ?? ''));
            $size = trim((string)($variant['size'] ?? ''));
            $priceRaw = trim((string)($variant['price'] ?? ''));
            $stockRaw = trim((string)($variant['stock'] ?? ''));
            $imageUrl = trim((string)($variant['image_url'] ?? ''));

            if ($type === '' && $size === '' && $priceRaw === '' && $stockRaw === '' && $imageUrl === '') {
                continue;
            }

            $price = $priceRaw !== '' ? admin_parse_money($priceRaw) : null;
            $stock = $stockRaw !== '' ? (int)$stockRaw : null;

            $columns = ['product_id'];
            $values = [$productId];

            // Simpan ke semua kemungkinan kolom supaya aman untuk DB lama dan DB baru.
            foreach (['variant_type', 'type'] as $typeColumn) {
                if (admin_column_exists('product_variants', $typeColumn)) {
                    $columns[] = $typeColumn;
                    $values[] = $type;
                }
            }

            foreach (['variant_size', 'size'] as $sizeColumn) {
                if (admin_column_exists('product_variants', $sizeColumn)) {
                    $columns[] = $sizeColumn;
                    $values[] = $size;
                }
            }

            if (admin_column_exists('product_variants', 'price')) {
                $columns[] = 'price';
                $values[] = $price;
            }

            if (admin_column_exists('product_variants', 'stock')) {
                $columns[] = 'stock';
                $values[] = $stock;
            }

            if (admin_column_exists('product_variants', 'image_url')) {
                $columns[] = 'image_url';
                $values[] = $imageUrl;
            }

            if (admin_column_exists('product_variants', 'is_active')) {
                $columns[] = 'is_active';
                $values[] = 1;
            }

            if (admin_column_exists('product_variants', 'created_at')) {
                $columns[] = 'created_at';
                $values[] = date('Y-m-d H:i:s');
            }

            if (admin_column_exists('product_variants', 'updated_at')) {
                $columns[] = 'updated_at';
                $values[] = date('Y-m-d H:i:s');
            }

            $columnSql = implode(', ', array_map(static function ($column) {
                return '`' . str_replace('`', '``', $column) . '`';
            }, $columns));

            $placeholderSql = implode(', ', array_fill(0, count($columns), '?'));

            $stmt = db()->prepare("INSERT INTO product_variants ({$columnSql}) VALUES ({$placeholderSql})");
            $stmt->execute($values);

            $saved++;
        }

        return $saved;
    }
}


if (!function_exists('admin_save_product_gallery')) {
    function admin_save_product_gallery(int $productId, string $mainImage, $galleryInput): int
    {
        if ($productId <= 0 || !admin_table_exists('product_images')) {
            return 0;
        }

        if (is_array($galleryInput)) {
            $lines = $galleryInput;
        } else {
            $lines = preg_split('/\r\n|\r|\n/', (string)$galleryInput);
        }

        $seen = [];
        $saved = 0;
        $sort = 0;
        $mainImage = trim($mainImage);

        if ($mainImage !== '') {
            $seen[strtolower($mainImage)] = true;
        }

        foreach ($lines as $line) {
            $url = trim((string)$line);
            if ($url === '') {
                continue;
            }

            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            try {
                $stmt = db()->prepare("
                    INSERT INTO product_images
                    (product_id, image_url, alt_text, sort_order, is_primary, is_active, created_at, updated_at)
                    VALUES (?, ?, '', ?, 0, 1, NOW(), NOW())
                ");
                $stmt->execute([$productId, $url, $sort]);
                $saved++;
                $sort++;
            } catch (Throwable $e) {
                // skip bad gallery row supaya produk tetap tersimpan
            }
        }

        return $saved;
    }
}




if (!function_exists('admin_str_limit')) {
    function admin_str_limit($text, int $limit = 80): string
    {
        $text = trim((string)$text);

        if ($text === '') {
            return '';
        }

        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($text, 0, $limit, '...', 'UTF-8');
        }

        return strlen($text) > $limit ? substr($text, 0, max(0, $limit - 3)) . '...' : $text;
    }
}


if (!function_exists('admin_save_bundle_items')) {
    function admin_save_bundle_items(int $bundleProductId, array $items, ?int $userId = null): int
    {
        if ($bundleProductId <= 0 || !admin_table_exists('product_bundle_items')) {
            return 0;
        }

        $saved = 0;
        $sort = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $componentId = (int)($item['component_product_id'] ?? $item['product_id'] ?? 0);
            $qtyRaw = trim((string)($item['qty'] ?? '1'));
            $qty = $qtyRaw !== '' ? (float)$qtyRaw : 1;

            if ($componentId <= 0 || $componentId === $bundleProductId || $qty <= 0) {
                continue;
            }

            $exists = safe_rows('SELECT id FROM products WHERE id = ? LIMIT 1', [$componentId]);
            if (!$exists) {
                continue;
            }

            try {
                $stmt = db()->prepare("
                    INSERT INTO product_bundle_items
                    (bundle_product_id, component_product_id, qty, sort_order, is_active, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())
                ");
                $stmt->execute([
                    $bundleProductId,
                    $componentId,
                    $qty,
                    $sort,
                    $userId,
                ]);

                $saved++;
                $sort++;
            } catch (Throwable $e) {
                // skip bad row supaya simpan produk tetap jalan
            }
        }

        return $saved;
    }
}

if (!function_exists('admin_bundle_items_count')) {
    function admin_bundle_items_count(int $bundleProductId): int
    {
        if ($bundleProductId <= 0 || !admin_table_exists('product_bundle_items')) {
            return 0;
        }

        try {
            $stmt = db()->prepare("SELECT COUNT(*) FROM product_bundle_items WHERE bundle_product_id = ? AND is_active = 1");
            $stmt->execute([$bundleProductId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('admin_upload_digital_file')) {
    function admin_upload_digital_file(string $field, string $folder = 'digital'): ?string
    {
        if (empty($_FILES[$field]) || empty($_FILES[$field]['name'])) {
            return null;
        }

        if ($_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload file digital gagal untuk field: ' . $field);
        }

        $allowedExt = [
            'pdf','zip','rar','7z',
            'doc','docx','xls','xlsx','ppt','pptx',
            'txt','csv','json',
            'jpg','jpeg','png','webp','gif',
            'mp3','wav','mp4','mov','webm'
        ];

        $originalName = (string)$_FILES[$field]['name'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt, true)) {
            throw new RuntimeException('Format file digital tidak didukung.');
        }

        $maxSizeMb = 80;
        if ((int)($_FILES[$field]['size'] ?? 0) > ($maxSizeMb * 1024 * 1024)) {
            throw new RuntimeException('Ukuran file digital maksimal ' . $maxSizeMb . 'MB.');
        }

        $baseDir = __DIR__ . '/uploads';
        $targetDir = $baseDir . '/' . trim($folder, '/');

        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0755, true);
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $safeOriginal = preg_replace('/[^a-zA-Z0-9._-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME));
        $safeOriginal = trim($safeOriginal, '-');
        if ($safeOriginal === '') {
            $safeOriginal = 'file-digital';
        }

        $fileName = $safeOriginal . '-' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
        $targetPath = $targetDir . '/' . $fileName;

        if (!move_uploaded_file($_FILES[$field]['tmp_name'], $targetPath)) {
            throw new RuntimeException('Gagal menyimpan file digital.');
        }

        return 'uploads/' . trim($folder, '/') . '/' . $fileName;
    }
}


if (!function_exists('admin_store_product_image_upload')) {
    function admin_store_product_image_upload(string $originalName, string $tmpName, int $size, int $error): ?string
    {
        if ($error === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Upload gambar produk gagal.');
        }

        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($ext, $allowedExt, true)) {
            throw new RuntimeException('Format gambar produk harus JPG, PNG, WEBP, atau GIF.');
        }

        $maxSizeMb = 6;
        if ($size > ($maxSizeMb * 1024 * 1024)) {
            throw new RuntimeException('Ukuran gambar produk maksimal ' . $maxSizeMb . 'MB.');
        }

        $baseDir = __DIR__ . '/uploads';
        $targetDir = $baseDir . '/products';

        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0755, true);
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $safeOriginal = preg_replace('/[^a-zA-Z0-9._-]+/', '-', pathinfo($originalName, PATHINFO_FILENAME));
        $safeOriginal = trim((string)$safeOriginal, '-');
        if ($safeOriginal === '') {
            $safeOriginal = 'produk';
        }

        $fileName = $safeOriginal . '-' . date('YmdHis') . '-' . substr(md5(uniqid('', true)), 0, 8) . '.' . $ext;
        $targetPath = $targetDir . '/' . $fileName;

        if (!move_uploaded_file($tmpName, $targetPath)) {
            throw new RuntimeException('Gagal menyimpan gambar produk.');
        }

        return 'uploads/products/' . $fileName;
    }
}

if (!function_exists('admin_upload_product_image')) {
    function admin_upload_product_image(string $field): ?string
    {
        if (empty($_FILES[$field]) || empty($_FILES[$field]['name'])) {
            return null;
        }

        return admin_store_product_image_upload(
            (string)$_FILES[$field]['name'],
            (string)$_FILES[$field]['tmp_name'],
            (int)($_FILES[$field]['size'] ?? 0),
            (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE)
        );
    }
}

if (!function_exists('admin_upload_product_gallery_images')) {
    function admin_upload_product_gallery_images(string $field): array
    {
        if (empty($_FILES[$field]) || empty($_FILES[$field]['name']) || !is_array($_FILES[$field]['name'])) {
            return [];
        }

        $paths = [];

        foreach ($_FILES[$field]['name'] as $index => $name) {
            if ($name === '' || (int)($_FILES[$field]['error'][$index] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }

            $paths[] = admin_store_product_image_upload(
                (string)$name,
                (string)($_FILES[$field]['tmp_name'][$index] ?? ''),
                (int)($_FILES[$field]['size'][$index] ?? 0),
                (int)($_FILES[$field]['error'][$index] ?? UPLOAD_ERR_NO_FILE)
            );
        }

        return array_values(array_filter($paths));
    }
}

if (!function_exists('admin_upload_variant_product_image')) {
    function admin_upload_variant_product_image(string $field, int $index): ?string
    {
        if (empty($_FILES[$field]) || empty($_FILES[$field]['name']) || !is_array($_FILES[$field]['name'])) {
            return null;
        }

        $name = (string)($_FILES[$field]['name'][$index] ?? '');
        $error = (int)($_FILES[$field]['error'][$index] ?? UPLOAD_ERR_NO_FILE);

        if ($name === '' || $error === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return admin_store_product_image_upload(
            $name,
            (string)($_FILES[$field]['tmp_name'][$index] ?? ''),
            (int)($_FILES[$field]['size'][$index] ?? 0),
            $error
        );
    }
}

if (!function_exists('admin_prepare_product_media_inputs')) {
    function admin_prepare_product_media_inputs(): array
    {
        $mainImage = trim((string)($_POST['main_image'] ?? ''));
        $uploadedMain = admin_upload_product_image('main_image_upload');

        if ($uploadedMain) {
            $mainImage = $uploadedMain;
        }

        $galleryInput = (string)($_POST['gallery_images'] ?? '');
        $uploadedGallery = admin_upload_product_gallery_images('gallery_image_uploads');

        if ($uploadedGallery) {
            $galleryInput = trim($galleryInput . "\n" . implode("\n", $uploadedGallery));
        }

        $variants = $_POST['variants'] ?? [];
        if (!is_array($variants)) {
            $variants = [];
        }

        foreach (array_keys($variants) as $index) {
            if (!is_numeric($index)) {
                continue;
            }

            $uploadedVariant = admin_upload_variant_product_image('variant_image_uploads', (int)$index);
            if ($uploadedVariant) {
                if (!is_array($variants[$index])) {
                    $variants[$index] = [];
                }

                $variants[$index]['image_url'] = $uploadedVariant;
            }
        }

        return [$mainImage, $galleryInput, $variants];
    }
}


if (!function_exists('admin_save_license_stocks')) {
    function admin_save_license_stocks(int $productId, string $rawCodes): int
    {
        if ($productId <= 0 || trim($rawCodes) === '' || !admin_table_exists('digital_license_stocks')) {
            return 0;
        }

        $lines = preg_split('/\r\n|\r|\n/', $rawCodes);
        $saved = 0;

        foreach ($lines as $line) {
            $code = trim((string)$line);
            if ($code === '') {
                continue;
            }

            try {
                $stmt = db()->prepare("
                    INSERT INTO digital_license_stocks
                    (product_id, code_data, public_label, status, created_at, updated_at)
                    VALUES (?, ?, ?, 'available', NOW(), NOW())
                ");
                $stmt->execute([
                    $productId,
                    $code,
                    admin_str_limit($code, 80),
                ]);

                $saved++;
            } catch (Throwable $e) {
                // skip duplicate/bad row silently so import tetap jalan
            }
        }

        return $saved;
    }
}

if (!function_exists('admin_license_stock_summary')) {
    function admin_license_stock_summary(int $productId): array
    {
        if ($productId <= 0 || !admin_table_exists('digital_license_stocks')) {
            return ['available' => 0, 'assigned' => 0, 'total' => 0];
        }

        try {
            $stmt = db()->prepare("
                SELECT status, COUNT(*) AS total
                FROM digital_license_stocks
                WHERE product_id = ?
                GROUP BY status
            ");
            $stmt->execute([$productId]);
            $rows = $stmt->fetchAll();

            $summary = ['available' => 0, 'assigned' => 0, 'total' => 0];
            foreach ($rows as $row) {
                $status = (string)($row['status'] ?? '');
                $count = (int)($row['total'] ?? 0);
                if (isset($summary[$status])) {
                    $summary[$status] = $count;
                }
                $summary['total'] += $count;
            }

            return $summary;
        } catch (Throwable $e) {
            return ['available' => 0, 'assigned' => 0, 'total' => 0];
        }
    }
}


if (!function_exists('admin_parse_money')) {
    function admin_parse_money($value): float
    {
        $value = trim((string)$value);

        if ($value === '') {
            return 0;
        }

        $value = preg_replace('/[^0-9,.\-]+/', '', $value);

        if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } elseif (strpos($value, ',') !== false) {
            $value = str_replace(',', '.', $value);
        }

        return (float)$value;
    }
}

if (!function_exists('admin_bool_active')) {
    function admin_bool_active($value): int
    {
        $value = strtolower(trim((string)$value));

        if ($value === '') {
            return 1;
        }

        return in_array($value, ['1', 'yes', 'ya', 'y', 'true', 'aktif', 'active', 'on'], true) ? 1 : 0;
    }
}

if (!function_exists('admin_find_or_create_category')) {
    function admin_find_or_create_category(string $name, ?int $parentId = null): ?int
    {
        $name = trim($name);

        if ($name === '') {
            return $parentId;
        }

        $sql = $parentId
            ? 'SELECT id FROM categories WHERE name = ? AND parent_id = ? LIMIT 1'
            : 'SELECT id FROM categories WHERE name = ? AND parent_id IS NULL LIMIT 1';

        $stmt = db()->prepare($sql);
        $params = $parentId ? [$name, $parentId] : [$name];
        $stmt->execute($params);

        $existing = $stmt->fetchColumn();

        if ($existing) {
            return (int)$existing;
        }

        $slugBase = slugify($name);
        $slug = unique_slug('categories', $slugBase ?: 'kategori');
        $sortOrder = admin_next_sort_order('categories');

        $stmt = db()->prepare('INSERT INTO categories (parent_id, name, slug, sort_order, is_active) VALUES (?, ?, ?, ?, 1)');
        $stmt->execute([$parentId, $name, $slug, $sortOrder]);

        return (int)db()->lastInsertId();
    }
}

if (!function_exists('admin_csv_cell')) {
    function admin_csv_cell(array $row, array $headers, string $key, string $default = ''): string
    {
        $index = array_search($key, $headers, true);

        if ($index === false) {
            return $default;
        }

        return trim((string)($row[$index] ?? $default));
    }
}


if (!function_exists('admin_normalize_product_type')) {
    function admin_normalize_product_type(string $type): string
    {
        $type = strtolower(trim($type));

        $map = [
            'fisik' => 'physical',
            'produk_fisik' => 'physical',
            'physical' => 'physical',
            'digital' => 'digital',
            'produk_digital' => 'digital',
            'license' => 'license',
            'lisensi' => 'license',
            'voucher' => 'license',
            'kode' => 'license',
            'kode_akses' => 'license',
            'license_key' => 'license',
            'bundle' => 'bundle',
            'bundling' => 'bundle',
            'service' => 'service',
            'jasa' => 'service',
            'layanan' => 'service',
            'servis' => 'service',
            'maintenance' => 'service',
        ];

        return $map[$type] ?? 'physical';
    }
}

if (!function_exists('admin_normalize_delivery_type')) {
    function admin_normalize_delivery_type(string $delivery, string $productType = 'physical'): string
    {
        $delivery = strtolower(trim($delivery));
        $productType = admin_normalize_product_type($productType);

        $map = [
            '' => 'none',
            'none' => 'none',
            '-' => 'none',
            'file' => 'file',
            'upload_file' => 'file',
            'download' => 'file',
            'external' => 'external_link',
            'external_link' => 'external_link',
            'link' => 'external_link',
            'url' => 'external_link',
            'gdrive' => 'gdrive',
            'google_drive' => 'gdrive',
            'drive' => 'gdrive',
            'canva' => 'canva',
            'canva_template' => 'canva',
            'html' => 'html_content',
            'html_content' => 'html_content',
            'konten' => 'html_content',
            'konten_member' => 'html_content',
            'license_stock' => 'license_stock',
            'license' => 'license_stock',
            'voucher' => 'license_stock',
            'manual' => 'manual',
            'manual_delivery' => 'manual',
        ];

        $delivery = $map[$delivery] ?? 'none';

        if (in_array($productType, ['physical', 'service'], true)) {
            return 'none';
        }

        if ($productType === 'license') {
            return 'license_stock';
        }

        if ($productType === 'digital' && $delivery === 'none') {
            return 'manual';
        }

        return $delivery;
    }
}

if (!function_exists('admin_csv_bool')) {
    function admin_csv_bool(string $value, int $default = 0): int
    {
        $value = strtolower(trim($value));

        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'yes', 'ya', 'y', 'true', 'aktif', 'active', 'on'], true) ? 1 : 0;
    }
}

if (!function_exists('admin_csv_license_lines')) {
    function admin_csv_license_lines(string $raw): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return '';
        }

        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $raw = str_replace('|', "\n", $raw);

        return trim($raw);
    }
}




if (!function_exists('admin_number_clean')) {
    function admin_number_clean($value): string
    {
        $number = (float)$value;
        $text = number_format($number, 2, '.', '');
        return rtrim(rtrim($text, '0'), '.') ?: '0';
    }
}

if (!function_exists('admin_order_item_type_label')) {
    function admin_order_item_type_label(array $item): string
    {
        $type = strtolower((string)($item['product_type'] ?? 'physical'));
        $delivery = strtolower((string)($item['delivery_type'] ?? 'none'));

        if ($type === 'service') {
            return 'Jasa/Service';
        }

        if ($type === 'license' || $delivery === 'license_stock') {
            return 'Lisensi';
        }

        if ($type === 'digital' || ($delivery !== '' && $delivery !== 'none')) {
            return 'Digital';
        }

        if ($type === 'bundle') {
            return 'Bundling';
        }

        return 'Fisik';
    }
}

if (!function_exists('admin_group_bundle_order_items')) {
    function admin_group_bundle_order_items(array $items): array
    {
        $parents = [];
        $children = [];

        foreach ($items as $item) {
            $parentId = (int)($item['bundle_parent_item_id'] ?? 0);
            $isComponent = (int)($item['is_bundle_component'] ?? 0) === 1;

            if ($isComponent || $parentId > 0) {
                if (!isset($children[$parentId])) {
                    $children[$parentId] = [];
                }
                $children[$parentId][] = $item;
                continue;
            }

            $parents[] = $item;
        }

        if (!$parents) {
            $parents = $items;
        }

        return [$parents, $children];
    }
}

if (!function_exists('admin_order_number')) {
    function admin_order_number(array $order): string
    {
        foreach (['order_number', 'invoice_number', 'code', 'order_code'] as $key) {
            if (!empty($order[$key])) {
                return (string)$order[$key];
            }
        }

        return 'ORDER-' . (int)($order['id'] ?? 0);
    }
}

if (!function_exists('admin_order_items')) {
    function admin_order_items(int $orderId): array
    {
        if ($orderId <= 0 || !admin_table_exists('order_items')) {
            return [];
        }

        try {
            $stmt = db()->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC");
            $stmt->execute([$orderId]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('admin_orders_grouped_rows')) {
    function admin_orders_grouped_rows(string $table, array $orderIds, string $orderBy = 'id ASC'): array
    {
        if (!$orderIds || !admin_table_exists($table)) {
            return [];
        }

        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if (!$orderIds) {
            return [];
        }

        $safeOrderBy = preg_replace('/[^a-zA-Z0-9_,. `-]+/', '', $orderBy) ?: 'id ASC';
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

        try {
            $stmt = db()->prepare("SELECT * FROM {$table} WHERE order_id IN ({$placeholders}) ORDER BY {$safeOrderBy}");
            $stmt->execute($orderIds);
            $rows = $stmt->fetchAll();

            $grouped = [];
            foreach ($rows as $row) {
                $grouped[(int)$row['order_id']][] = $row;
            }

            return $grouped;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('admin_safe_update_order_columns')) {
    function admin_safe_update_order_columns(int $orderId, array $updates): bool
    {
        if ($orderId <= 0 || !$updates) {
            return false;
        }

        $sets = [];
        $values = [];

        foreach ($updates as $column => $value) {
            if (!admin_column_exists('orders', (string)$column)) {
                continue;
            }

            $sets[] = "`{$column}` = ?";
            $values[] = $value;
        }

        if (!$sets) {
            return false;
        }

        $values[] = $orderId;

        try {
            $stmt = db()->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $stmt->execute($values);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}


if (!function_exists('admin_order_status_text')) {
    function admin_order_status_text(string $status): string
    {
        $status = strtolower(trim($status));

        $map = [
            'paid' => 'pembayaran sudah kami terima',
            'processing' => 'pesanan sedang diproses',
            'shipped' => 'pesanan sudah masuk proses pengiriman',
            'delivered' => 'pesanan sudah sampai / selesai dikirim',
            'completed' => 'pesanan sudah dikonfirmasi dan masuk proses pengiriman',
        ];

        return $map[$status] ?? 'status pesanan sudah diperbarui';
    }
}

if (!function_exists('admin_default_order_status_template')) {
    function admin_default_order_status_template(): string
    {
        return "Halo *{buyer_name}*,\n\nUpdate pesanan Anda: *{status_text}*.\n\nOrder: *{order_number}*\n\n*Rincian Pesanan*\n--------------------\n{items}\n\n*Pengiriman*\n--------------------\n{shipping_info}\n\n*Total:* {grand_total}\n\n{note_block}\n\nStruk pesanan:\n{receipt_url}\n\nTerima kasih, pesanan Anda akan kami proses sesuai antrean toko.";
    }
}

if (!function_exists('admin_default_order_status_digital_template')) {
    function admin_default_order_status_digital_template(): string
    {
        return "Halo *{buyer_name}*,\n\nPesanan digital Anda sudah kami proses.\n\nOrder: *{order_number}*\n\n*Produk Digital*\n--------------------\n{digital_items}\n\n*Total:* {grand_total}\n\nAkses produk digital Anda tersedia di member area:\n{member_url}\n\nStruk pesanan:\n{receipt_url}\n\nTerima kasih.";
    }
}

if (!function_exists('admin_default_order_status_service_template')) {
    function admin_default_order_status_service_template(): string
    {
        return "Halo *{buyer_name}*,\n\nUpdate pesanan jasa Anda: *{status_text}*.\n\nOrder: *{order_number}*\n\n*Layanan/Jasa*\n--------------------\n{service_items}\n\n*Catatan/Brief*\n--------------------\n{note}\n\n*Total:* {grand_total}\n\nStruk pesanan:\n{receipt_url}\n\nTim kami akan menghubungi Anda untuk proses berikutnya.\n\nTerima kasih.";
    }
}

if (!function_exists('admin_template_replace')) {
    function admin_template_replace(string $template, array $vars): string
    {
        $replacements = [];

        foreach ($vars as $key => $value) {
            $replacements['{' . $key . '}'] = (string)$value;
        }

        return strtr($template, $replacements);
    }
}

if (!function_exists('admin_order_receipt_url')) {
    function admin_order_receipt_url(int $orderId, string $orderNumber): string
    {
        $baseUrl = '';

        if (function_exists('base_url')) {
            $baseUrl = rtrim((string)base_url(), '/');
        }

        if ($baseUrl === '' && function_exists('setting')) {
            $baseUrl = rtrim((string)setting('app_url', ''), '/');
        }

        if ($baseUrl === '') {
            return '';
        }

        return $baseUrl . '/struk.php?id=' . $orderId . '&code=' . rawurlencode($orderNumber);
    }
}

if (!function_exists('admin_send_order_status_wa')) {
    function admin_send_order_status_wa(int $orderId, string $status = 'processing', string $note = ''): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        $rows = safe_rows('SELECT * FROM orders WHERE id = ? LIMIT 1', [$orderId]);
        $order = $rows[0] ?? null;

        if (!$order) {
            return false;
        }

        $phone = (string)($order['buyer_phone'] ?? '');
        if (function_exists('admin_broadcast_clean_phone')) {
            $phone = admin_broadcast_clean_phone($phone);
        } else {
            $phone = preg_replace('/\D+/', '', $phone);
            if (strpos($phone, '0') === 0) {
                $phone = '62' . substr($phone, 1);
            } elseif (strpos($phone, '8') === 0) {
                $phone = '62' . $phone;
            }
        }

        if ($phone === '') {
            return false;
        }

        $status = strtolower(trim($status));
        $buyerName = trim((string)($order['buyer_name'] ?? ''));
        $orderNumber = (string)($order['order_number'] ?? ('#' . $orderId));
        $items = safe_rows('SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC', [$orderId]);

        $itemLines = [];
        $digitalItemLines = [];
        $serviceItemLines = [];
        $hasDigitalItem = false;
        $hasPhysicalItem = false;
        $hasServiceItem = false;

        foreach ($items as $item) {
            $name = (string)($item['product_name'] ?? 'Produk');
            $qty = (int)($item['qty'] ?? 1);
            $subtotal = function_exists('rupiah') ? rupiah($item['subtotal'] ?? 0) : ('Rp ' . number_format((float)($item['subtotal'] ?? 0), 0, ',', '.'));

            $productType = strtolower((string)($item['product_type'] ?? ''));
            $deliveryType = strtolower((string)($item['delivery_type'] ?? ''));
            $accessStatus = strtolower((string)($item['digital_access_status'] ?? ''));

            $isServiceItem = $productType === 'service';
            $isDigitalItem = !$isServiceItem && (
                in_array($productType, ['digital', 'license', 'voucher'], true)
                || in_array($deliveryType, ['download', 'external_link', 'gdrive', 'canva', 'html_content', 'license_stock'], true)
                || ($accessStatus !== '' && !in_array($accessStatus, ['none', 'pending_physical'], true))
            );

            if ($isServiceItem) {
                $hasServiceItem = true;
                $serviceItemLines[] = '- ' . $name . ' x' . $qty;
            } elseif ($isDigitalItem) {
                $hasDigitalItem = true;
                $digitalItemLines[] = '- ' . $name . ' x' . $qty;
            } else {
                $hasPhysicalItem = true;
            }

            $itemLines[] = '- ' . $name . ' x' . $qty . ' = ' . $subtotal;
        }

        if ($hasServiceItem && !$hasPhysicalItem && !$hasDigitalItem) {
            $orderType = 'service';
        } elseif ($hasDigitalItem && !$hasPhysicalItem && !$hasServiceItem) {
            $orderType = 'digital';
        } elseif (($hasDigitalItem || $hasServiceItem) && $hasPhysicalItem) {
            $orderType = 'mixed';
        } else {
            $orderType = 'physical';
        }

        $shippingName = trim((string)($order['shipping_name'] ?? ''));
        $shippingDest = trim((string)($order['shipping_destination_label'] ?? ''));
        $buyerAddress = trim((string)($order['buyer_address'] ?? ''));

        $shippingLines = [];
        if ($shippingName !== '') {
            $shippingLines[] = 'Kurir: *' . $shippingName . '*';
        }

        if ($shippingDest !== '') {
            $shippingLines[] = 'Tujuan: ' . $shippingDest;
        }

        if ($buyerAddress !== '') {
            $shippingLines[] = 'Alamat: ' . $buyerAddress;
        }

        if (!$shippingLines) {
            $shippingLines[] = '-';
        }

        $totalText = function_exists('rupiah') ? rupiah($order['grand_total'] ?? 0) : ('Rp ' . number_format((float)($order['grand_total'] ?? 0), 0, ',', '.'));
        $shippingCostText = function_exists('rupiah') ? rupiah($order['shipping_cost'] ?? 0) : ('Rp ' . number_format((float)($order['shipping_cost'] ?? 0), 0, ',', '.'));
        $subtotalText = function_exists('rupiah') ? rupiah($order['subtotal'] ?? 0) : ('Rp ' . number_format((float)($order['subtotal'] ?? 0), 0, ',', '.'));
        $discountText = function_exists('rupiah') ? rupiah($order['discount_amount'] ?? 0) : ('Rp ' . number_format((float)($order['discount_amount'] ?? 0), 0, ',', '.'));

        $note = trim($note);
        $noteBlock = $note !== '' ? "*Catatan:*\n" . $note : '';

        $memberUrl = '';
        if (function_exists('base_url')) {
            $memberUrl = rtrim((string)base_url(), '/') . '/member.php';
        } elseif (function_exists('setting')) {
            $appUrl = rtrim((string)setting('app_url', ''), '/');
            $memberUrl = $appUrl !== '' ? $appUrl . '/member.php' : '';
        }

        $digitalAccessInfo = $hasDigitalItem
            ? 'Akses digital tersedia di member area. Login menggunakan nomor WhatsApp yang dipakai saat checkout.'
            : '';

        $vars = [
            'buyer_name' => $buyerName !== '' ? $buyerName : 'Kak',
            'buyer_phone' => $phone,
            'order_number' => $orderNumber,
            'status' => $status,
            'status_text' => admin_order_status_text($status),
            'order_type' => $orderType,
            'items' => $itemLines ? implode("\n", $itemLines) : '-',
            'digital_items' => $digitalItemLines ? implode("\n", $digitalItemLines) : ($itemLines ? implode("\n", $itemLines) : '-'),
            'service_items' => $serviceItemLines ? implode("\n", $serviceItemLines) : ($itemLines ? implode("\n", $itemLines) : '-'),
            'digital_access_info' => $digitalAccessInfo,
            'member_url' => $memberUrl,
            'shipping_info' => implode("\n", $shippingLines),
            'shipping_name' => $shippingName,
            'shipping_destination' => $shippingDest,
            'buyer_address' => $buyerAddress,
            'subtotal' => $subtotalText,
            'shipping_cost' => $shippingCostText,
            'discount_amount' => $discountText,
            'grand_total' => $totalText,
            'note' => $note,
            'note_block' => $noteBlock,
            'receipt_url' => admin_order_receipt_url($orderId, $orderNumber),
            'store_name' => function_exists('setting') ? (string)setting('store_name', setting('app_name', 'Toko Online')) : 'Toko Online',
        ];

        if ($orderType === 'digital') {
            $templateKey = 'wa_order_status_digital_template';
            $defaultTemplate = admin_default_order_status_digital_template();
        } elseif ($orderType === 'service') {
            $templateKey = 'wa_order_status_service_template';
            $defaultTemplate = admin_default_order_status_service_template();
        } else {
            $templateKey = 'wa_order_status_update_template';
            $defaultTemplate = admin_default_order_status_template();
        }

        $template = function_exists('setting')
            ? (string)setting($templateKey, $defaultTemplate)
            : $defaultTemplate;

        if (trim($template) === '') {
            $template = $defaultTemplate;
        }

        $message = trim(admin_template_replace($template, $vars));

        if ($message === '') {
            return false;
        }

        if (function_exists('wa_send_message')) {
            try {
                $result = wa_send_message($phone, $message, 'order_status_update', true);
                if (!empty($result['success'])) {
                    return true;
                }
            } catch (Throwable $e) {
                // fallback queue below
            }
        }

        if (function_exists('commerce_enqueue_wa')) {
            try {
                return (bool)commerce_enqueue_wa($phone, $message, 'order_status_update', $orderId);
            } catch (Throwable $e) {
                return false;
            }
        }

        return false;
    }
}

if (!function_exists('admin_engine_complete_order')) {
    function admin_engine_complete_order(int $orderId, ?int $userId = null, string $note = ''): bool
    {
        if (function_exists('commerce_complete_order')) {
            return (bool)commerce_complete_order($orderId, $userId, $note, 'admin');
        }

        if (function_exists('ce_complete_order')) {
            return (bool)ce_complete_order($orderId, $userId, $note);
        }

        try {
            $stmt = db()->prepare("
                UPDATE orders
                SET order_status = 'completed',
                    payment_status = 'paid',
                    completed_at = COALESCE(completed_at, NOW())
                WHERE id = ?
            ");
            $stmt->execute([$orderId]);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('admin_engine_update_order_status')) {
    function admin_engine_update_order_status(int $orderId, string $status, ?int $userId = null, string $note = '', ?string $paymentStatus = null): bool
    {
        $status = strtolower(trim($status));
        if ($status === '') {
            return false;
        }

        if (function_exists('commerce_update_order_status')) {
            return (bool)commerce_update_order_status($orderId, $status, [
                'payment_status' => $paymentStatus,
                'changed_by' => $userId,
                'note' => $note,
                'source' => 'admin',
            ]);
        }

        if (function_exists('ce_update_order_status')) {
            return (bool)ce_update_order_status($orderId, $status, [
                'payment_status' => $paymentStatus,
                'changed_by' => $userId,
                'note' => $note,
            ]);
        }

        $updates = [
            'order_status' => $status,
            'status_updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($paymentStatus !== null && $paymentStatus !== '') {
            $updates['payment_status'] = $paymentStatus;
        }

        if ($status === 'completed') {
            $updates['payment_status'] = 'paid';
            $updates['completed_at'] = date('Y-m-d H:i:s');
        }

        return admin_safe_update_order_columns($orderId, $updates);
    }
}

if (!function_exists('admin_engine_resend_invoice')) {
    function admin_engine_resend_invoice(int $orderId): bool
    {
        if (function_exists('commerce_resend_invoice_wa')) {
            return (bool)commerce_resend_invoice_wa($orderId);
        }

        if (function_exists('ce_resend_invoice_wa')) {
            return (bool)ce_resend_invoice_wa($orderId);
        }

        return false;
    }
}

if (!function_exists('admin_engine_add_internal_note')) {
    function admin_engine_add_internal_note(int $orderId, string $note, ?int $userId = null): bool
    {
        if (function_exists('commerce_add_internal_note')) {
            return (bool)commerce_add_internal_note($orderId, $note, $userId);
        }

        if (function_exists('ce_add_internal_note')) {
            return (bool)ce_add_internal_note($orderId, $note, $userId);
        }

        return admin_safe_update_order_columns($orderId, [
            'internal_note' => trim($note),
        ]);
    }
}



if (($_GET['download'] ?? '') === 'product_csv_template') {
    require_login();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="template_import_produk_digital.csv"');

    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');

    $headers = [
        'sku',
        'nama_produk',
        'kategori',
        'sub_kategori',
        'harga',
        'harga_promo',
        'stok',
        'tipe_stok',
        'satuan',
        'berat_gram',
        'badge',
        'deskripsi',
        'url_gambar',
        'status',
        'tipe_produk',
        'delivery_type',
        'judul_akses',
        'url_akses',
        'path_file',
        'konten_html',
        'instruksi_akses',
        'label_tombol',
        'auto_deliver',
        'expired_hari',
        'alert_stok_kode',
        'stok_kode'
    ];

    fputcsv($out, $headers);

    fputcsv($out, [
        'DIGI-001',
        'Ebook Strategi Jualan WhatsApp',
        'Digital Product',
        'Ebook',
        '99000',
        '49000',
        '',
        'unlimited',
        'akses',
        '',
        'Digital',
        'Ebook PDF premium yang muncul di member area setelah order completed.',
        'https://domainanda.com/uploads/produk/ebook-cover.jpg',
        'aktif',
        'digital',
        'file',
        'Download Ebook Premium',
        '',
        'uploads/digital/ebook-premium.pdf',
        '',
        'Klik tombol download lalu simpan file PDF.',
        'Download Ebook',
        '1',
        '0',
        '',
        ''
    ]);

    fputcsv($out, [
        'CANVA-001',
        'Template Canva Konten Promo',
        'Digital Product',
        'Template',
        '149000',
        '',
        '',
        'unlimited',
        'akses',
        '',
        'Canva',
        'Template Canva siap edit untuk promosi produk.',
        '',
        'aktif',
        'digital',
        'canva',
        'Akses Template Canva',
        'https://www.canva.com/design/CONTOH-LINK-TEMPLATE',
        '',
        '',
        'Klik tombol Gunakan Template lalu duplicate ke akun Canva Anda.',
        'Gunakan Template',
        '1',
        '0',
        '',
        ''
    ]);

    fputcsv($out, [
        'LIC-001',
        'Voucher Premium 30 Hari',
        'Digital Product',
        'Voucher',
        '50000',
        '',
        '',
        'unlimited',
        'kode',
        '',
        'Kode Digital',
        'Kode voucher akan muncul di member area setelah order completed.',
        '',
        'aktif',
        'license',
        'license_stock',
        'Kode Voucher Premium',
        '',
        '',
        '',
        'Salin kode voucher lalu gunakan sesuai panduan produk.',
        'Lihat Kode',
        '1',
        '30',
        '5',
        'VCR-AAA-111|VCR-BBB-222|VCR-CCC-333'
    ]);

    fclose($out);
    exit;
}

if (($_GET['download'] ?? '') === 'products_csv_export') {
    require_admin();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="export_produk_' . date('Ymd_His') . '.csv"');

    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');

    $headers = [
        'id',
        'sku',
        'nama_produk',
        'kategori',
        'sub_kategori',
        'harga',
        'harga_promo',
        'stok',
        'tipe_stok',
        'satuan',
        'berat_gram',
        'badge',
        'deskripsi',
        'url_gambar',
        'status',
        'tipe_produk',
        'delivery_type',
        'judul_akses',
        'url_akses',
        'path_file',
        'konten_html',
        'instruksi_akses',
        'label_tombol',
        'auto_deliver',
        'expired_hari',
        'alert_stok_kode',
        'stok_kode_tersedia',
        'stok_kode_total'
    ];

    fputcsv($out, $headers);

    $licenseCounts = [];
    if (admin_table_exists('digital_license_stocks')) {
        foreach (safe_rows("SELECT product_id, status, COUNT(*) AS total FROM digital_license_stocks GROUP BY product_id, status") as $row) {
            $pid = (int)$row['product_id'];
            if (!isset($licenseCounts[$pid])) {
                $licenseCounts[$pid] = ['available' => 0, 'assigned' => 0, 'total' => 0];
            }

            $status = (string)$row['status'];
            $count = (int)$row['total'];

            if (isset($licenseCounts[$pid][$status])) {
                $licenseCounts[$pid][$status] = $count;
            }

            $licenseCounts[$pid]['total'] += $count;
        }
    }

    $rows = safe_rows("
        SELECT
            p.*,
            c.name AS category_name,
            pc.name AS parent_category_name
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN categories pc ON pc.id = c.parent_id
        ORDER BY p.id DESC
    ");

    foreach ($rows as $p) {
        $productId = (int)$p['id'];
        $category = $p['parent_category_name'] ?: ($p['category_name'] ?? '');
        $subCategory = $p['parent_category_name'] ? ($p['category_name'] ?? '') : '';
        $license = $licenseCounts[$productId] ?? ['available' => 0, 'total' => 0];

        fputcsv($out, [
            $productId,
            $p['sku'] ?? '',
            $p['name'] ?? '',
            $category,
            $subCategory,
            $p['price'] ?? 0,
            $p['sale_price'] ?? '',
            $p['stock'] ?? '',
            $p['stock_type'] ?? 'limited',
            $p['unit'] ?? '',
            $p['weight_gram'] ?? '',
            $p['badge'] ?? '',
            $p['description'] ?? '',
            $p['main_image'] ?? '',
            !empty($p['is_active']) ? 'aktif' : 'nonaktif',
            $p['product_type'] ?? 'physical',
            $p['delivery_type'] ?? 'none',
            $p['delivery_title'] ?? '',
            $p['delivery_url'] ?? '',
            $p['delivery_file_path'] ?? '',
            $p['delivery_content'] ?? '',
            $p['delivery_instruction'] ?? '',
            $p['delivery_button_label'] ?? '',
            $p['digital_auto_deliver'] ?? '',
            $p['access_expires_days'] ?? '',
            $p['license_low_stock_alert'] ?? '',
            (int)$license['available'],
            (int)$license['total'],
        ]);
    }

    fclose($out);
    exit;
}


if (($_GET['download'] ?? '') === 'customers_csv_export') {
    require_admin();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="export_data_pembeli_' . date('Ymd_His') . '.csv"');

    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');

    $headers = [
        'id',
        'customer_code',
        'nama',
        'whatsapp',
        'email',
        'status',
        'source',
        'segment',
        'tags',
        'total_orders',
        'total_completed_orders',
        'total_cancelled_orders',
        'total_spent',
        'points',
        'points_balance',
        'total_points_earned',
        'total_points_redeemed',
        'last_order_at',
        'last_seen_at',
        'last_order_number',
        'note',
        'internal_note',
        'created_at',
        'updated_at'
    ];

    fputcsv($out, $headers);

    $rows = safe_rows("SELECT * FROM customers ORDER BY id DESC");

    foreach ($rows as $c) {
        fputcsv($out, [
            $c['id'] ?? '',
            $c['customer_code'] ?? '',
            $c['name'] ?? '',
            $c['phone'] ?? '',
            $c['email'] ?? '',
            $c['status'] ?? '',
            $c['source'] ?? '',
            $c['segment'] ?? '',
            $c['tags_cache'] ?? '',
            $c['total_orders'] ?? 0,
            $c['total_completed_orders'] ?? 0,
            $c['total_cancelled_orders'] ?? 0,
            $c['total_spent'] ?? 0,
            $c['points'] ?? 0,
            $c['points_balance'] ?? ($c['points'] ?? 0),
            $c['total_points_earned'] ?? 0,
            $c['total_points_redeemed'] ?? 0,
            $c['last_order_at'] ?? '',
            $c['last_seen_at'] ?? '',
            $c['last_order_number'] ?? '',
            $c['note'] ?? '',
            $c['internal_note'] ?? '',
            $c['created_at'] ?? '',
            $c['updated_at'] ?? '',
        ]);
    }

    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    IMersWAStoreProGuard::checkpoint('admin.mutation');
    csrf_check();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'rajaongkir_search_origin_admin') {
            require_admin();

            if (!function_exists('imers_ro_search_destination')) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => 'File includes/rajaongkir.php belum tersedia.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }

            $query = trim((string)($_POST['query'] ?? ''));

            // Untuk search origin di admin, cukup butuh API key. Origin ID belum perlu terisi.
            $result = imers_ro_search_destination($query, 10);

            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        if ($action === 'create_broadcast_campaign') {
            require_admin();

            if (!admin_table_exists('wa_broadcast_campaigns') || !admin_table_exists('wa_message_queue')) {
                throw new RuntimeException('Tabel broadcast belum tersedia. Jalankan database_update_broadcast_leaderboard.sql terlebih dahulu.');
            }

            $title = trim((string)($_POST['title'] ?? ''));
            $targetType = trim((string)($_POST['target_type'] ?? 'all_customers'));
            $messageTemplate = trim((string)($_POST['message_template'] ?? ''));
            $batchLimit = admin_broadcast_batch_limit($_POST['batch_limit'] ?? 20);
            $policyAccepted = !empty($_POST['policy_accepted']);
            $productId = (int)($_POST['product_id'] ?? 0);

            if ($title === '') {
                throw new RuntimeException('Judul campaign wajib diisi.');
            }

            if ($messageTemplate === '') {
                throw new RuntimeException('Isi pesan broadcast wajib diisi.');
            }

            if (!$policyAccepted) {
                throw new RuntimeException('Policy broadcast wajib disetujui sebelum membuat queue.');
            }

            if (!array_key_exists($targetType, admin_broadcast_target_options())) {
                $targetType = 'all_customers';
            }

            $filters = [
                'product_id' => $productId,
            ];

            if ($targetType === 'product_buyers' && $productId <= 0) {
                throw new RuntimeException('Untuk target pembeli produk tertentu, pilih produk terlebih dahulu.');
            }

            $recipients = admin_broadcast_recipients($targetType, $filters);

            if (!$recipients) {
                throw new RuntimeException('Tidak ada penerima valid untuk target tersebut.');
            }

            db()->beginTransaction();

            db()->prepare("
                INSERT INTO wa_broadcast_campaigns
                (title, target_type, target_filters, message_template, policy_notice, policy_accepted, policy_accepted_at, batch_limit, total_recipients, queued_count, status, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 1, NOW(), ?, ?, ?, 'queued', ?, NOW(), NOW())
            ")->execute([
                $title,
                $targetType,
                json_encode($filters, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $messageTemplate,
                admin_broadcast_policy_notice(),
                $batchLimit,
                count($recipients),
                count($recipients),
                (int)($user['id'] ?? 0),
            ]);

            $campaignId = (int)db()->lastInsertId();

            $insertQueue = db()->prepare("
                INSERT INTO wa_message_queue
                (campaign_id, customer_id, phone, message, message_type, status, attempts, scheduled_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, 'broadcast', 'pending', 0, NOW(), NOW(), NOW())
            ");

            foreach ($recipients as $recipient) {
                $insertQueue->execute([
                    $campaignId,
                    $recipient['customer_id'] ?? null,
                    $recipient['phone'],
                    admin_broadcast_message($messageTemplate, $recipient),
                ]);
            }

            db()->commit();

            flash('success', 'Campaign broadcast dibuat. Total queue: ' . count($recipients) . ' penerima. Kirim bertahap maksimal ' . $batchLimit . ' pesan per batch.');
            redirect(admin_url('view=broadcast'));
        }

        if ($action === 'send_broadcast_batch') {
            require_admin();

            if (!function_exists('wa_send_message')) {
                throw new RuntimeException('Function wa_send_message tidak ditemukan. Pastikan includes/wa_gateway.php sudah terupload dan aktif.');
            }

            $campaignId = (int)($_POST['campaign_id'] ?? 0);

            if ($campaignId <= 0) {
                throw new RuntimeException('Campaign tidak valid.');
            }

            $campaign = safe_rows("SELECT * FROM wa_broadcast_campaigns WHERE id = ? LIMIT 1", [$campaignId]);
            $campaign = $campaign[0] ?? null;

            if (!$campaign) {
                throw new RuntimeException('Campaign broadcast tidak ditemukan.');
            }

            $batchLimit = admin_broadcast_batch_limit($campaign['batch_limit'] ?? 20);

            $queueRows = safe_rows("
                SELECT *
                FROM wa_message_queue
                WHERE campaign_id = ?
                  AND status = 'pending'
                ORDER BY id ASC
                LIMIT {$batchLimit}
            ", [$campaignId]);

            if (!$queueRows) {
                admin_broadcast_update_campaign_counts($campaignId);
                flash('success', 'Tidak ada queue pending. Campaign sudah selesai atau semua pesan sudah diproses.');
                redirect(admin_url('view=broadcast'));
            }

            $sent = 0;
            $failed = 0;

            foreach ($queueRows as $queue) {
                $queueId = (int)$queue['id'];
                $phone = admin_broadcast_clean_phone($queue['phone'] ?? '');
                $message = trim((string)($queue['message'] ?? ''));

                if ($phone === '' || $message === '') {
                    db()->prepare("
                        UPDATE wa_message_queue
                        SET status = 'failed',
                            attempts = COALESCE(attempts,0) + 1,
                            last_error = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute(['Nomor atau pesan kosong.', $queueId]);
                    $failed++;
                    continue;
                }

                try {
                    $result = wa_send_message($phone, $message, 'broadcast', true);

                    if (!empty($result['success'])) {
                        db()->prepare("
                            UPDATE wa_message_queue
                            SET status = 'sent',
                                attempts = COALESCE(attempts,0) + 1,
                                sent_at = NOW(),
                                last_error = NULL,
                                updated_at = NOW()
                            WHERE id = ?
                        ")->execute([$queueId]);
                        $sent++;
                    } else {
                        $error = $result['message'] ?? 'Broadcast gagal terkirim.';
                        if (isset($result['raw'])) {
                            $error .= ' Response: ' . json_encode($result['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        }

                        db()->prepare("
                            UPDATE wa_message_queue
                            SET status = 'failed',
                                attempts = COALESCE(attempts,0) + 1,
                                last_error = ?,
                                updated_at = NOW()
                            WHERE id = ?
                        ")->execute([substr($error, 0, 1000), $queueId]);
                        $failed++;
                    }
                } catch (Throwable $e) {
                    db()->prepare("
                        UPDATE wa_message_queue
                        SET status = 'failed',
                            attempts = COALESCE(attempts,0) + 1,
                            last_error = ?,
                            updated_at = NOW()
                        WHERE id = ?
                    ")->execute([substr($e->getMessage(), 0, 1000), $queueId]);
                    $failed++;
                }
            }

            admin_broadcast_update_campaign_counts($campaignId);

            flash('success', 'Batch broadcast diproses. Terkirim: ' . $sent . '. Gagal: ' . $failed . '.');
            redirect(admin_url('view=broadcast'));
        }

        if ($action === 'delete_broadcast_campaign') {
            require_admin();

            $campaignId = (int)($_POST['campaign_id'] ?? 0);

            if ($campaignId > 0) {
                if (admin_table_exists('wa_message_queue')) {
                    db()->prepare("DELETE FROM wa_message_queue WHERE campaign_id = ?")->execute([$campaignId]);
                }

                db()->prepare("DELETE FROM wa_broadcast_campaigns WHERE id = ?")->execute([$campaignId]);
            }

            flash('success', 'Campaign broadcast dihapus.');
            redirect(admin_url('view=broadcast'));
        }

        if ($action === 'save_settings') {
            require_admin();

            $map = [
                'app_name' => 'app',
                'app_logo' => 'app',
                'app_favicon' => 'app',
                'app_url' => 'app',

                'store_name' => 'store',
                'store_description' => 'store',
                'store_logo' => 'store',
                'store_whatsapp' => 'store',
                'store_email' => 'store',
                'store_address' => 'store',

                'rajaongkir_enabled' => 'shipping',
                'rajaongkir_api_key' => 'shipping',
                'rajaongkir_base_url' => 'shipping',
                'rajaongkir_origin_id' => 'shipping',
                'rajaongkir_origin_label' => 'shipping',
                'rajaongkir_couriers' => 'shipping',
                'rajaongkir_price_mode' => 'shipping',
                'rajaongkir_default_weight_gram' => 'shipping',
                'rajaongkir_markup_flat' => 'shipping',
                'rajaongkir_fallback_manual_enabled' => 'shipping',

                'primary_color' => 'theme',
                'secondary_color' => 'theme',
                'accent_color' => 'theme',
                'background_color' => 'theme',

                'dashboard_card_1_start' => 'theme',
                'dashboard_card_1_end' => 'theme',
                'dashboard_card_2_start' => 'theme',
                'dashboard_card_2_end' => 'theme',
                'dashboard_card_3_start' => 'theme',
                'dashboard_card_3_end' => 'theme',
                'dashboard_card_4_start' => 'theme',
                'dashboard_card_4_end' => 'theme',

                'bank_info' => 'payment',
                'qris_image' => 'payment',

                'footer_text' => 'footer',
                'login_footer_text' => 'login',
                'meta_title' => 'seo',
                'meta_description' => 'seo',

                'facebook_pixel_id' => 'tracking',
                'tiktok_pixel_id' => 'tracking',
                'google_analytics_id' => 'tracking',

                'show_terms' => 'legal',
                'terms_text' => 'legal',
                'show_privacy' => 'legal',
                'privacy_text' => 'legal',

                'tax_percent' => 'tax',
                'service_charge' => 'tax',

                'points_enabled' => 'points',
                'points_label' => 'points',
                'points_earn_amount' => 'points',
                'points_earn_value' => 'points',
                'points_currency_value' => 'points',
                'points_min_redeem' => 'points',
                'points_max_redeem_percent' => 'points',
                'points_earn_from_web' => 'points',
                'points_earn_from_pos' => 'points',
                'points_award_when' => 'points',
                'points_redeem_enabled' => 'points',
                'points_expiry_days' => 'points',
                'points_manual_adjustment_enabled' => 'points',

                'storefront_hero_title' => 'storefront',
                'storefront_hero_subtitle' => 'storefront',
                'storefront_hero_start' => 'theme',
                'storefront_hero_end' => 'theme',
                'storefront_hero_accent' => 'theme',

                'show_announcement' => 'storefront',
                'announcement_text' => 'storefront',
                'announcement_link' => 'storefront',
                'announcement_bg_start' => 'theme',
                'announcement_bg_end' => 'theme',
                'announcement_text_color' => 'theme',

                'show_banner_slider' => 'storefront',
                'banner_slider_image' => 'storefront',
                'banner_slider_link' => 'storefront',

                'show_banner_mid' => 'storefront',
                'banner_mid_image' => 'storefront',
                'banner_mid_link' => 'storefront',

                'show_popup' => 'storefront',
                'popup_image' => 'storefront',
                'popup_desc' => 'storefront',
                'popup_btn_text' => 'storefront',
                'popup_btn_link' => 'storefront',

                'about_us_text' => 'storefront',
                'show_blog_section' => 'storefront',

                'wa_enabled' => 'whatsapp',
                'wa_provider' => 'whatsapp',
                'wa_token' => 'whatsapp',
                'wa_sender' => 'whatsapp',
                'wa_admin_number' => 'whatsapp',
                'wa_custom_url' => 'whatsapp',
                'wa_order_buyer_template' => 'whatsapp',
                'wa_order_admin_template' => 'whatsapp',
                'wa_web_order_buyer_template' => 'whatsapp',
                'wa_web_order_admin_template' => 'whatsapp',
                'wa_member_register_template' => 'whatsapp',
                'wa_member_forgot_pin_template' => 'whatsapp',
                'wa_pos_receipt_template' => 'whatsapp',
                'wa_order_status_update_template' => 'whatsapp',
                'wa_order_status_digital_template' => 'whatsapp',
                'wa_order_status_service_template' => 'whatsapp',
            ];

            foreach ($map as $key => $group) {
                if (array_key_exists($key, $_POST)) {
                    set_setting($group, $key, trim((string)$_POST[$key]), in_array($group, ['tax'], true) ? 0 : 1);
                }
            }

            if (isset($_POST['video_playlist']) && is_array($_POST['video_playlist'])) {
                $videos = admin_clean_repeater_rows($_POST['video_playlist'], ['url']);
                set_setting('tutorial', 'video_playlist_json', json_encode($videos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1);
            }

            if (isset($_POST['social_links']) && is_array($_POST['social_links'])) {
                $socials = admin_clean_repeater_rows($_POST['social_links'], ['url']);
                set_setting('social', 'social_links_json', json_encode($socials, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1);
            }

            $uploadMap = [
                'app_logo_file' => ['app', 'app_logo'],
                'app_favicon_file' => ['app', 'app_favicon'],
                'store_logo_file' => ['store', 'store_logo'],
                'qris_image_file' => ['payment', 'qris_image'],
                'banner_slider_image_file' => ['storefront', 'banner_slider_image'],
                'banner_mid_image_file' => ['storefront', 'banner_mid_image'],
                'popup_image_file' => ['storefront', 'popup_image'],
            ];

            foreach ($uploadMap as $fileField => $target) {
                $uploadedPath = admin_upload_setting_image($fileField, 'settings');

                if ($uploadedPath) {
                    set_setting($target[0], $target[1], $uploadedPath, 1);
                }
            }

            flash('success', 'Pengaturan berhasil disimpan.');
            redirect(admin_url('view=settings'));
        }


        if ($action === 'test_whatsapp') {
            require_admin();

            $target = preg_replace('/\D+/', '', (string)($_POST['test_wa_target'] ?? ''));
            $message = trim((string)($_POST['test_wa_message'] ?? ''));

            if ($target === '') {
                throw new RuntimeException('Nomor tujuan test WhatsApp wajib diisi.');
            }

            if (substr($target, 0, 1) === '0') {
                $target = '62' . substr($target, 1);
            }

            if (substr($target, 0, 1) === '8') {
                $target = '62' . $target;
            }

            if ($message === '') {
                $message = 'Test WhatsApp Gateway dari iMersWAStore.';
            }

            if (!function_exists('wa_send_message')) {
                throw new RuntimeException('Function wa_send_message tidak ditemukan. Pastikan includes/wa_gateway.php sudah terupload.');
            }

            $result = wa_send_message($target, $message, 'manual_admin_test', true);

            if (!empty($result['success'])) {
                flash('success', 'Test WhatsApp berhasil dikirim ke ' . $target . '.');
            } else {
                $raw = '';
                if (isset($result['raw'])) {
                    $raw = ' Response: ' . json_encode($result['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }

                throw new RuntimeException(($result['message'] ?? 'Test WhatsApp gagal.') . $raw);
            }

            redirect(admin_url('view=whatsapp'));
        }

        if ($action === 'add_category') {
            require_admin();

            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                throw new RuntimeException('Nama kategori wajib diisi.');
            }

            $slugInput = trim((string)($_POST['slug'] ?? ''));
            $slugBase = slugify($slugInput !== '' ? $slugInput : $name);
            $slug = unique_slug('categories', $slugBase ?: slugify($name));

            $parentId = ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null;

            if ($parentId) {
                $checkParent = db()->prepare('SELECT COUNT(*) FROM categories WHERE id = ?');
                $checkParent->execute([$parentId]);

                if ((int)$checkParent->fetchColumn() <= 0) {
                    $parentId = null;
                }
            }

            $stmt = db()->prepare('INSERT INTO categories (parent_id, name, slug, sort_order, is_active) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([
                $parentId,
                $name,
                $slug,
                ($_POST['sort_order'] ?? '') !== '' ? (int)$_POST['sort_order'] : admin_next_sort_order('categories'),
                !empty($_POST['is_active']) ? 1 : 0,
            ]);

            flash('success', 'Kategori berhasil ditambahkan.');
            redirect(admin_url('view=categories'));
        }

        if ($action === 'edit_category') {
            require_admin();

            $categoryId = (int)($_POST['id'] ?? 0);
            if ($categoryId <= 0) {
                throw new RuntimeException('Kategori tidak valid.');
            }

            $existingCategory = safe_rows('SELECT * FROM categories WHERE id = ? LIMIT 1', [$categoryId]);
            $existingCategory = $existingCategory[0] ?? null;

            if (!$existingCategory) {
                throw new RuntimeException('Kategori tidak ditemukan.');
            }

            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') {
                throw new RuntimeException('Nama kategori wajib diisi.');
            }

            $slugInput = trim((string)($_POST['slug'] ?? ''));
            $slugBase = slugify($slugInput !== '' ? $slugInput : $name);
            if ($slugBase === '') {
                $slugBase = 'kategori';
            }

            $slug = $slugBase;
            $counter = 2;
            while (true) {
                $checkSlug = db()->prepare('SELECT COUNT(*) FROM categories WHERE slug = ? AND id <> ?');
                $checkSlug->execute([$slug, $categoryId]);

                if ((int)$checkSlug->fetchColumn() <= 0) {
                    break;
                }

                $slug = $slugBase . '-' . $counter;
                $counter++;
            }

            $parentId = ($_POST['parent_id'] ?? '') !== '' ? (int)$_POST['parent_id'] : null;

            if ($parentId === $categoryId) {
                $parentId = null;
            }

            if ($parentId) {
                $checkParent = db()->prepare('SELECT id, parent_id FROM categories WHERE id = ? LIMIT 1');
                $checkParent->execute([$parentId]);
                $parentCategory = $checkParent->fetch();

                if (!$parentCategory) {
                    $parentId = null;
                } elseif ((int)($parentCategory['parent_id'] ?? 0) === $categoryId) {
                    $parentId = null;
                }
            }

            $sortOrder = ($_POST['sort_order'] ?? '') !== '' ? (int)$_POST['sort_order'] : (int)($existingCategory['sort_order'] ?? 0);
            $isActive = !empty($_POST['is_active']) ? 1 : 0;

            $stmt = db()->prepare('UPDATE categories SET parent_id = ?, name = ?, slug = ?, sort_order = ?, is_active = ? WHERE id = ?');
            $stmt->execute([
                $parentId,
                $name,
                $slug,
                $sortOrder,
                $isActive,
                $categoryId,
            ]);

            flash('success', 'Kategori berhasil diperbarui.');
            redirect(admin_url('view=categories'));
        }

        if ($action === 'delete_category') {
            require_admin();

            $stmt = db()->prepare('DELETE FROM categories WHERE id = ?');
            $stmt->execute([(int)$_POST['id']]);

            flash('success', 'Kategori dihapus.');
            redirect(admin_url('view=categories'));
        }

        if ($action === 'add_product') {
            require_admin();

            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                throw new RuntimeException('Nama produk wajib diisi.');
            }

            $slug = unique_slug('products', slugify($name));
            $sku = trim($_POST['sku'] ?? '');
            $sku = $sku !== '' ? $sku : admin_generate_product_sku($name);

            $productType = strtolower(trim((string)($_POST['product_type'] ?? 'physical')));
            $allowedProductTypes = ['physical', 'digital', 'license', 'bundle', 'service'];
            if (!in_array($productType, $allowedProductTypes, true)) {
                $productType = 'physical';
            }

            $deliveryType = strtolower(trim((string)($_POST['delivery_type'] ?? 'none')));
            $allowedDeliveryTypes = ['none', 'file', 'external_link', 'gdrive', 'canva', 'html_content', 'license_stock', 'manual'];
            if (!in_array($deliveryType, $allowedDeliveryTypes, true)) {
                $deliveryType = 'none';
            }

            if ($productType === 'physical' || $productType === 'bundle' || $productType === 'service') {
                $deliveryType = 'none';
            }

            if ($productType === 'license') {
                $deliveryType = 'license_stock';
            }

            if ($productType === 'digital' && $deliveryType === 'none') {
                $deliveryType = 'manual';
            }

            $deliveryFilePath = trim((string)($_POST['delivery_file_path'] ?? ''));
            $uploadedDigitalFile = admin_upload_digital_file('delivery_file_upload', 'digital');
            if ($uploadedDigitalFile) {
                $deliveryFilePath = $uploadedDigitalFile;
            }

            [$mainImage, $galleryInput, $variantsInput] = admin_prepare_product_media_inputs();

            $columns = [
                'category_id',
                'name',
                'slug',
                'sku',
                'description',
                'price',
                'sale_price',
                'stock',
                'stock_type',
                'unit',
                'badge',
                'main_image',
                'is_active',
            ];

            $values = [
                ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null,
                $name,
                $slug,
                $sku,
                trim($_POST['description'] ?? ''),
                (float)($_POST['price'] ?? 0),
                ($_POST['sale_price'] ?? '') !== '' ? (float)$_POST['sale_price'] : null,
                $productType === 'service' ? null : (($_POST['stock'] ?? '') !== '' ? (int)$_POST['stock'] : null),
                $productType === 'service' ? 'unlimited' : (($_POST['stock_type'] ?? 'limited') === 'unlimited' ? 'unlimited' : 'limited'),
                trim($_POST['unit'] ?? ''),
                trim($_POST['badge'] ?? ''),
                $mainImage,
                !empty($_POST['is_active']) ? 1 : 0,
            ];

            $optionalProductColumns = [
                'weight_gram' => $productType === 'service' ? null : (($_POST['weight_gram'] ?? '') !== '' ? (int)$_POST['weight_gram'] : null),
                'product_type' => $productType,
                'bundle_stock_mode' => $productType === 'bundle' ? 'components' : 'components',
                'bundle_show_components' => $productType === 'bundle' ? 1 : 0,
                'delivery_type' => $deliveryType,
                'delivery_title' => trim((string)($_POST['delivery_title'] ?? '')),
                'delivery_url' => trim((string)($_POST['delivery_url'] ?? '')),
                'delivery_file_path' => $deliveryFilePath,
                'delivery_content' => trim((string)($_POST['delivery_content'] ?? '')),
                'delivery_instruction' => trim((string)($_POST['delivery_instruction'] ?? '')),
                'delivery_button_label' => trim((string)($_POST['delivery_button_label'] ?? '')) ?: 'Buka Akses',
                'digital_auto_deliver' => !empty($_POST['digital_auto_deliver']) ? 1 : 0,
                'access_expires_days' => ($_POST['access_expires_days'] ?? '') !== '' ? (int)$_POST['access_expires_days'] : 0,
                'license_low_stock_alert' => ($_POST['license_low_stock_alert'] ?? '') !== '' ? (int)$_POST['license_low_stock_alert'] : 5,
            ];

            foreach ($optionalProductColumns as $column => $value) {
                if (admin_column_exists('products', $column)) {
                    $columns[] = $column;
                    $values[] = $value;
                }
            }

            $placeholders = implode(', ', array_fill(0, count($columns), '?'));
            $stmt = db()->prepare('INSERT INTO products (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
            $stmt->execute($values);

            $productId = (int)db()->lastInsertId();
            $variantCount = admin_save_product_variants($productId, $variantsInput);
            $galleryCount = admin_save_product_gallery($productId, $mainImage, $galleryInput);
            $licenseCount = admin_save_license_stocks($productId, (string)($_POST['license_stock_lines'] ?? ''));
            $bundleCount = 0;
            if ($productType === 'bundle') {
                $bundleCount = admin_save_bundle_items($productId, $_POST['bundle_items'] ?? [], (int)($user['id'] ?? 0));
            }

            $message = 'Produk berhasil ditambahkan';
            if ($variantCount > 0) {
                $message .= ' dengan ' . $variantCount . ' varian';
            }
            if (!empty($galleryCount) && $galleryCount > 0) {
                $message .= ($variantCount > 0 ? ' dan ' : ' dengan ') . $galleryCount . ' gambar gallery';
            }
            if ($licenseCount > 0) {
                $message .= (($variantCount > 0 || (!empty($galleryCount) && $galleryCount > 0)) ? ' dan ' : ' dengan ') . $licenseCount . ' stok kode digital';
            }
            if ($bundleCount > 0) {
                $message .= (($variantCount > 0 || (!empty($galleryCount) && $galleryCount > 0) || $licenseCount > 0) ? ' dan ' : ' dengan ') . $bundleCount . ' item bundle';
            }
            $message .= '.';

            flash('success', $message);
            redirect(admin_url('view=products'));
        }


        if ($action === 'import_products_csv') {
            require_admin();

            if (empty($_FILES['csv_file']) || $_FILES['csv_file']['error'] === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('File CSV wajib diupload.');
            }

            if ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException('Upload CSV gagal.');
            }

            $ext = strtolower(pathinfo((string)$_FILES['csv_file']['name'], PATHINFO_EXTENSION));

            if ($ext !== 'csv') {
                throw new RuntimeException('Format file harus .csv');
            }

            $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');

            if (!$handle) {
                throw new RuntimeException('File CSV tidak bisa dibaca.');
            }

            $headers = fgetcsv($handle, 0, ',');

            if (!$headers) {
                fclose($handle);
                throw new RuntimeException('Header CSV tidak ditemukan.');
            }

            if (isset($headers[0])) {
                $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$headers[0]);
            }

            $headers = array_map(function ($header) {
                return strtolower(trim((string)$header));
            }, $headers);

            if (!in_array('nama_produk', $headers, true) || !in_array('harga', $headers, true)) {
                fclose($handle);
                throw new RuntimeException('CSV minimal harus punya kolom nama_produk dan harga.');
            }

            $imported = 0;
            $updated = 0;
            $skipped = 0;
            $licenseImported = 0;
            $lineNumber = 1;

            while (($row = fgetcsv($handle, 0, ',')) !== false) {
                $lineNumber++;

                $name = admin_csv_cell($row, $headers, 'nama_produk');
                $price = admin_parse_money(admin_csv_cell($row, $headers, 'harga'));

                if ($name === '' || $price <= 0) {
                    $skipped++;
                    continue;
                }

                $categoryName = admin_csv_cell($row, $headers, 'kategori');
                $subCategoryName = admin_csv_cell($row, $headers, 'sub_kategori');

                $parentId = $categoryName !== '' ? admin_find_or_create_category($categoryName, null) : null;
                $categoryId = $subCategoryName !== '' ? admin_find_or_create_category($subCategoryName, $parentId) : $parentId;

                $salePriceRaw = admin_csv_cell($row, $headers, 'harga_promo');
                $salePrice = $salePriceRaw !== '' ? admin_parse_money($salePriceRaw) : null;

                $stockRaw = admin_csv_cell($row, $headers, 'stok');
                $stockType = strtolower(admin_csv_cell($row, $headers, 'tipe_stok', 'limited'));
                $stockType = $stockType === 'unlimited' ? 'unlimited' : 'limited';
                $stock = $stockType === 'unlimited' ? null : (($stockRaw !== '') ? (int)$stockRaw : 0);

                $sku = admin_csv_cell($row, $headers, 'sku');
                $sku = $sku !== '' ? $sku : admin_generate_product_sku($name);
                $unit = admin_csv_cell($row, $headers, 'satuan');
                $badge = admin_csv_cell($row, $headers, 'badge');
                $description = admin_csv_cell($row, $headers, 'deskripsi');
                $image = admin_csv_cell($row, $headers, 'url_gambar');
                $status = admin_bool_active(admin_csv_cell($row, $headers, 'status', 'aktif'));

                $productType = admin_normalize_product_type(admin_csv_cell($row, $headers, 'tipe_produk', 'physical'));
                $deliveryType = admin_normalize_delivery_type(admin_csv_cell($row, $headers, 'delivery_type', 'none'), $productType);

                $deliveryFilePath = admin_csv_cell($row, $headers, 'path_file');
                $deliveryTitle = admin_csv_cell($row, $headers, 'judul_akses');
                $deliveryUrl = admin_csv_cell($row, $headers, 'url_akses');
                $deliveryContent = admin_csv_cell($row, $headers, 'konten_html');
                $deliveryInstruction = admin_csv_cell($row, $headers, 'instruksi_akses');
                $deliveryButtonLabel = admin_csv_cell($row, $headers, 'label_tombol', 'Buka Akses');
                $digitalAutoDeliver = admin_csv_bool(admin_csv_cell($row, $headers, 'auto_deliver', '1'), 1);
                $accessExpiresDays = admin_csv_cell($row, $headers, 'expired_hari');
                $licenseLowStockAlert = admin_csv_cell($row, $headers, 'alert_stok_kode');
                $licenseLines = admin_csv_license_lines(admin_csv_cell($row, $headers, 'stok_kode'));
                $weightGram = admin_csv_cell($row, $headers, 'berat_gram');

                $existingProductId = null;
                if ($sku !== '') {
                    $check = db()->prepare('SELECT id FROM products WHERE sku = ? LIMIT 1');
                    $check->execute([$sku]);
                    $existingProductId = $check->fetchColumn() ?: null;
                }

                $baseData = [
                    'category_id' => $categoryId,
                    'name' => $name,
                    'sku' => $sku,
                    'description' => $description,
                    'price' => $price,
                    'sale_price' => $salePrice,
                    'stock' => $stock,
                    'stock_type' => $stockType,
                    'unit' => $unit,
                    'badge' => $badge,
                    'main_image' => $image,
                    'is_active' => $status,
                ];

                $optionalData = [
                    'weight_gram' => $weightGram !== '' ? (int)$weightGram : null,
                    'product_type' => $productType,
                    'delivery_type' => $deliveryType,
                    'delivery_title' => $deliveryTitle,
                    'delivery_url' => $deliveryUrl,
                    'delivery_file_path' => $deliveryFilePath,
                    'delivery_content' => $deliveryContent,
                    'delivery_instruction' => $deliveryInstruction,
                    'delivery_button_label' => $deliveryButtonLabel !== '' ? $deliveryButtonLabel : 'Buka Akses',
                    'digital_auto_deliver' => $digitalAutoDeliver,
                    'access_expires_days' => $accessExpiresDays !== '' ? (int)$accessExpiresDays : 0,
                    'license_low_stock_alert' => $licenseLowStockAlert !== '' ? (int)$licenseLowStockAlert : 5,
                ];

                if ($existingProductId) {
                    $sets = [];
                    $values = [];

                    foreach (array_merge($baseData, $optionalData) as $column => $value) {
                        if (!admin_column_exists('products', $column)) {
                            continue;
                        }

                        $sets[] = "`{$column}` = ?";
                        $values[] = $value;
                    }

                    if (admin_column_exists('products', 'updated_at')) {
                        $sets[] = 'updated_at = NOW()';
                    }

                    if ($sets) {
                        $values[] = (int)$existingProductId;
                        db()->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($values);
                        $productId = (int)$existingProductId;
                        $updated++;
                    } else {
                        $skipped++;
                        continue;
                    }
                } else {
                    $slug = unique_slug('products', slugify($name) ?: 'produk');
                    $columns = array_keys($baseData);
                    $values = array_values($baseData);

                    $columns[] = 'slug';
                    $values[] = $slug;

                    if (admin_column_exists('products', 'sort_order')) {
                        $columns[] = 'sort_order';
                        $values[] = admin_next_sort_order('products');
                    }

                    foreach ($optionalData as $column => $value) {
                        if (admin_column_exists('products', $column)) {
                            $columns[] = $column;
                            $values[] = $value;
                        }
                    }

                    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
                    $sql = 'INSERT INTO products (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
                    db()->prepare($sql)->execute($values);

                    $productId = (int)db()->lastInsertId();
                    $imported++;
                }

                if ($licenseLines !== '') {
                    $licenseImported += admin_save_license_stocks($productId, $licenseLines);
                }
            }

            fclose($handle);

            flash('success', 'Import CSV selesai. Produk baru: ' . $imported . '. Update: ' . $updated . '. Dilewati: ' . $skipped . '. Stok kode masuk: ' . $licenseImported . '.');
            redirect(admin_url('view=products'));
        }

        if ($action === 'edit_product') {
            require_admin();

            $productId = (int)($_POST['id'] ?? 0);
            if ($productId <= 0) {
                throw new RuntimeException('Produk tidak valid.');
            }

            $existingProduct = safe_rows('SELECT * FROM products WHERE id = ? LIMIT 1', [$productId]);
            $existingProduct = $existingProduct[0] ?? null;

            if (!$existingProduct) {
                throw new RuntimeException('Produk tidak ditemukan.');
            }

            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                throw new RuntimeException('Nama produk wajib diisi.');
            }

            $sku = trim($_POST['sku'] ?? '');
            $sku = $sku !== '' ? $sku : ($existingProduct['sku'] ?: admin_generate_product_sku($name));

            $productType = strtolower(trim((string)($_POST['product_type'] ?? 'physical')));
            $allowedProductTypes = ['physical', 'digital', 'license', 'bundle', 'service'];
            if (!in_array($productType, $allowedProductTypes, true)) {
                $productType = 'physical';
            }

            $deliveryType = strtolower(trim((string)($_POST['delivery_type'] ?? 'none')));
            $allowedDeliveryTypes = ['none', 'file', 'external_link', 'gdrive', 'canva', 'html_content', 'license_stock', 'manual'];
            if (!in_array($deliveryType, $allowedDeliveryTypes, true)) {
                $deliveryType = 'none';
            }

            if ($productType === 'physical' || $productType === 'bundle' || $productType === 'service') {
                $deliveryType = 'none';
            }

            if ($productType === 'license') {
                $deliveryType = 'license_stock';
            }

            if ($productType === 'digital' && $deliveryType === 'none') {
                $deliveryType = 'manual';
            }

            $deliveryFilePath = trim((string)($_POST['delivery_file_path'] ?? ''));
            $uploadedDigitalFile = admin_upload_digital_file('delivery_file_upload', 'digital');
            if ($uploadedDigitalFile) {
                $deliveryFilePath = $uploadedDigitalFile;
            }

            [$mainImage, $galleryInput, $variantsInput] = admin_prepare_product_media_inputs();

            $updates = [
                'category_id' => ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null,
                'name' => $name,
                'sku' => $sku,
                'description' => trim($_POST['description'] ?? ''),
                'price' => (float)($_POST['price'] ?? 0),
                'sale_price' => ($_POST['sale_price'] ?? '') !== '' ? (float)$_POST['sale_price'] : null,
                'stock' => $productType === 'service' ? null : (($_POST['stock'] ?? '') !== '' ? (int)$_POST['stock'] : null),
                'stock_type' => $productType === 'service' ? 'unlimited' : (($_POST['stock_type'] ?? 'limited') === 'unlimited' ? 'unlimited' : 'limited'),
                'unit' => trim($_POST['unit'] ?? ''),
                'badge' => trim($_POST['badge'] ?? ''),
                'main_image' => $mainImage,
                'is_active' => !empty($_POST['is_active']) ? 1 : 0,
                'weight_gram' => $productType === 'service' ? null : (($_POST['weight_gram'] ?? '') !== '' ? (int)$_POST['weight_gram'] : null),
                'product_type' => $productType,
                'bundle_stock_mode' => $productType === 'bundle' ? 'components' : 'components',
                'bundle_show_components' => $productType === 'bundle' ? 1 : 0,
                'delivery_type' => $deliveryType,
                'delivery_title' => trim((string)($_POST['delivery_title'] ?? '')),
                'delivery_url' => trim((string)($_POST['delivery_url'] ?? '')),
                'delivery_file_path' => $deliveryFilePath,
                'delivery_content' => trim((string)($_POST['delivery_content'] ?? '')),
                'delivery_instruction' => trim((string)($_POST['delivery_instruction'] ?? '')),
                'delivery_button_label' => trim((string)($_POST['delivery_button_label'] ?? '')) ?: 'Buka Akses',
                'digital_auto_deliver' => !empty($_POST['digital_auto_deliver']) ? 1 : 0,
                'access_expires_days' => ($_POST['access_expires_days'] ?? '') !== '' ? (int)$_POST['access_expires_days'] : 0,
                'license_low_stock_alert' => ($_POST['license_low_stock_alert'] ?? '') !== '' ? (int)$_POST['license_low_stock_alert'] : 5,
            ];

            $sets = [];
            $values = [];

            foreach ($updates as $column => $value) {
                if (!admin_column_exists('products', $column)) {
                    continue;
                }

                $sets[] = "`{$column}` = ?";
                $values[] = $value;
            }

            if (admin_column_exists('products', 'updated_at')) {
                $sets[] = 'updated_at = NOW()';
            }

            if (!$sets) {
                throw new RuntimeException('Tidak ada kolom produk yang bisa diperbarui.');
            }

            $values[] = $productId;
            db()->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($values);

            $variantCount = 0;
            if (admin_table_exists('product_variants')) {
                db()->prepare('DELETE FROM product_variants WHERE product_id = ?')->execute([$productId]);
                $variantCount = admin_save_product_variants($productId, $variantsInput);
            }

            $galleryCount = 0;
            if (admin_table_exists('product_images')) {
                db()->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$productId]);
                $galleryCount = admin_save_product_gallery($productId, $mainImage, $galleryInput);
            }

            $licenseCount = admin_save_license_stocks($productId, (string)($_POST['license_stock_lines'] ?? ''));

            $bundleCount = 0;
            if ($productType === 'bundle' && admin_table_exists('product_bundle_items')) {
                db()->prepare('DELETE FROM product_bundle_items WHERE bundle_product_id = ?')->execute([$productId]);
                $bundleCount = admin_save_bundle_items($productId, $_POST['bundle_items'] ?? [], (int)($user['id'] ?? 0));
            } elseif (admin_table_exists('product_bundle_items')) {
                db()->prepare('DELETE FROM product_bundle_items WHERE bundle_product_id = ?')->execute([$productId]);
            }

            $message = 'Produk berhasil diperbarui';
            if ($variantCount > 0) {
                $message .= ' dengan ' . $variantCount . ' varian';
            }
            if (!empty($galleryCount) && $galleryCount > 0) {
                $message .= ($variantCount > 0 ? ' dan ' : ' dengan ') . $galleryCount . ' gambar gallery';
            }
            if ($licenseCount > 0) {
                $message .= (($variantCount > 0 || (!empty($galleryCount) && $galleryCount > 0)) ? ' dan ' : ' dengan ') . $licenseCount . ' stok kode digital baru';
            }
            if ($bundleCount > 0) {
                $message .= (($variantCount > 0 || (!empty($galleryCount) && $galleryCount > 0) || $licenseCount > 0) ? ' dan ' : ' dengan ') . $bundleCount . ' item bundle';
            }
            $message .= '.';

            flash('success', $message);
            redirect(admin_url('view=products'));
        }

        if ($action === 'delete_product') {
            require_admin();

            $deleteProductId = (int)($_POST['id'] ?? 0);

            if (admin_table_exists('product_images')) {
                db()->prepare('DELETE FROM product_images WHERE product_id = ?')->execute([$deleteProductId]);
            }

            $stmt = db()->prepare('DELETE FROM products WHERE id = ?');
            $stmt->execute([$deleteProductId]);

            flash('success', 'Produk dihapus.');
            redirect(admin_url('view=products'));
        }



        if ($action === 'add_customer') {
            require_admin();

            $name = trim($_POST['name'] ?? '');
            $phone = preg_replace('/\D+/', '', (string)($_POST['phone'] ?? ''));

            if ($phone === '') {
                throw new RuntimeException('Nomor WhatsApp pembeli wajib diisi.');
            }

            if (substr($phone, 0, 1) === '0') {
                $phone = '62' . substr($phone, 1);
            }

            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            $note = trim($_POST['note'] ?? '');

            $stmt = db()->prepare('SELECT id FROM customers WHERE phone = ? LIMIT 1');
            $stmt->execute([$phone]);
            $existingId = $stmt->fetchColumn();

            if ($existingId) {
                $stmt = db()->prepare('
                    UPDATE customers 
                    SET name = CASE WHEN ? <> "" THEN ? ELSE name END,
                        status = ?,
                        note = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ');
                $stmt->execute([$name, $name, $status, $note, (int)$existingId]);
                flash('success', 'Data pembeli diperbarui.');
            } else {
                $stmt = db()->prepare('
                    INSERT INTO customers (name, phone, status, note, source, created_at, updated_at)
                    VALUES (?, ?, ?, ?, "manual", NOW(), NOW())
                ');
                $stmt->execute([$name, $phone, $status, $note]);
                flash('success', 'Data pembeli berhasil ditambahkan.');
            }

            redirect(admin_url('view=customers'));
        }

        if ($action === 'delete_customer') {
            require_admin();

            $stmt = db()->prepare('DELETE FROM customers WHERE id = ?');
            $stmt->execute([(int)($_POST['id'] ?? 0)]);

            flash('success', 'Data pembeli dihapus.');
            redirect(admin_url('view=customers'));
        }

        if ($action === 'change_password') {
            $oldPassword = (string)($_POST['old_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');

            if ($oldPassword === '' || $newPassword === '') {
                throw new RuntimeException('Password lama dan password baru wajib diisi.');
            }

            if (strlen($newPassword) < 6) {
                throw new RuntimeException('Password baru minimal 6 karakter.');
            }

            $stmt = db()->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
            $stmt->execute([(int)($user['id'] ?? 0)]);
            $hash = (string)$stmt->fetchColumn();

            if (!$hash || !password_verify($oldPassword, $hash)) {
                throw new RuntimeException('Password lama salah.');
            }

            $stmt = db()->prepare('UPDATE users SET password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$user['id']]);

            flash('success', 'Password berhasil diperbarui.');
            redirect(admin_url('view=settings'));
        }

        if ($action === 'add_user') {
            require_admin();

            $name = trim($_POST['name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = (string)($_POST['password'] ?? '');

            if ($name === '' || $username === '' || $password === '') {
                throw new RuntimeException('Nama, username, dan password wajib diisi.');
            }

            $stmt = db()->prepare("
                INSERT INTO users (name, username, email, phone, password, role, status)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $name,
                $username,
                trim($_POST['email'] ?? ''),
                trim($_POST['phone'] ?? ''),
                password_hash($password, PASSWORD_DEFAULT),
                ($_POST['role'] ?? 'kasir') === 'admin' ? 'admin' : 'kasir',
                ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active',
            ]);

            flash('success', 'Akun kasir/admin berhasil ditambahkan.');
            redirect(admin_url('view=users'));
        }

        if ($action === 'delete_user') {
            require_admin();

            $deleteId = (int)($_POST['id'] ?? 0);

            if ($deleteId <= 0) {
                throw new RuntimeException('User tidak valid.');
            }

            if ($deleteId === (int)($user['id'] ?? 0)) {
                throw new RuntimeException('Akun yang sedang login tidak boleh dihapus.');
            }

            $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([$deleteId]);

            flash('success', 'User berhasil dihapus.');
            redirect(admin_url('view=users'));
        }

        if ($action === 'add_coupon') {
            require_admin();

            $code = strtoupper(trim($_POST['code'] ?? ''));

            if ($code === '') {
                throw new RuntimeException('Kode kupon wajib diisi.');
            }

            $stmt = db()->prepare("
                INSERT INTO coupons 
                (code, discount_type, discount_value, min_purchase, max_discount, usage_limit, start_at, end_at, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $code,
                ($_POST['discount_type'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed',
                (float)($_POST['discount_value'] ?? 0),
                (float)($_POST['min_purchase'] ?? 0),
                ($_POST['max_discount'] ?? '') !== '' ? (float)$_POST['max_discount'] : null,
                ($_POST['usage_limit'] ?? '') !== '' ? (int)$_POST['usage_limit'] : null,
                ($_POST['start_at'] ?? '') !== '' ? $_POST['start_at'] : null,
                ($_POST['end_at'] ?? '') !== '' ? $_POST['end_at'] : null,
                !empty($_POST['is_active']) ? 1 : 0,
            ]);

            flash('success', 'Kupon berhasil dibuat.');
            redirect(admin_url('view=coupons'));
        }

        if ($action === 'delete_coupon') {
            require_admin();

            $stmt = db()->prepare('DELETE FROM coupons WHERE id = ?');
            $stmt->execute([(int)$_POST['id']]);

            flash('success', 'Kupon dihapus.');
            redirect(admin_url('view=coupons'));
        }

        if ($action === 'add_shipping') {
            require_admin();

            $name = trim($_POST['name'] ?? '');

            if ($name === '') {
                throw new RuntimeException('Nama area/layanan wajib diisi.');
            }

            $stmt = db()->prepare("
                INSERT INTO shipping_rates (name, description, cost, is_active, sort_order)
                VALUES (?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $name,
                trim($_POST['description'] ?? ''),
                (float)($_POST['cost'] ?? 0),
                !empty($_POST['is_active']) ? 1 : 0,
                admin_next_sort_order('shipping_rates'),
            ]);

            flash('success', 'Tarif ongkos kirim berhasil ditambahkan.');
            redirect(admin_url('view=shipping'));
        }

        if ($action === 'delete_shipping') {
            require_admin();

            $stmt = db()->prepare('DELETE FROM shipping_rates WHERE id = ?');
            $stmt->execute([(int)$_POST['id']]);

            flash('success', 'Tarif ongkos kirim dihapus.');
            redirect(admin_url('view=shipping'));
        }

        if ($action === 'add_article') {
            require_admin();

            $title = trim($_POST['title'] ?? '');

            if ($title === '') {
                throw new RuntimeException('Judul artikel wajib diisi.');
            }

            $cover = trim($_POST['cover_image'] ?? '');
            $uploadedCover = admin_upload_setting_image('cover_image_file', 'articles');

            if ($uploadedCover) {
                $cover = $uploadedCover;
            }

            $slug = unique_slug('articles', slugify($title) ?: 'artikel');

            $stmt = db()->prepare("
                INSERT INTO articles 
                (title, slug, excerpt, content, cover_image, meta_title, meta_description, is_published, published_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $title,
                $slug,
                trim($_POST['excerpt'] ?? ''),
                trim($_POST['content'] ?? ''),
                $cover,
                trim($_POST['meta_title'] ?? ''),
                trim($_POST['meta_description'] ?? ''),
                !empty($_POST['is_published']) ? 1 : 0,
                !empty($_POST['is_published']) ? date('Y-m-d H:i:s') : null,
            ]);

            flash('success', 'Artikel berhasil dibuat.');
            redirect(admin_url('view=articles'));
        }

        if ($action === 'delete_article') {
            require_admin();

            $stmt = db()->prepare('DELETE FROM articles WHERE id = ?');
            $stmt->execute([(int)$_POST['id']]);

            flash('success', 'Artikel dihapus.');
            redirect(admin_url('view=articles'));
        }

        if ($action === 'order_complete') {
            if (!$isAdmin && !$isKasir) {
                throw new RuntimeException('Akses ditolak.');
            }

            $orderId = (int)($_POST['id'] ?? 0);
            $note = trim((string)($_POST['note'] ?? ''));

            if ($orderId <= 0) {
                throw new RuntimeException('Order tidak valid.');
            }

            if (!admin_engine_complete_order($orderId, (int)($user['id'] ?? 0), $note)) {
                throw new RuntimeException('Gagal menandai order selesai.');
            }

            $notifSent = admin_send_order_status_wa($orderId, 'completed', $note);

            flash('success', 'Order ditandai selesai. Point dan akses digital akan diproses otomatis jika memenuhi syarat.' . ($notifSent ? ' Notifikasi WhatsApp pembeli dikirim / masuk antrean.' : ' Notifikasi WhatsApp pembeli belum terkirim, cek gateway/nomor pembeli.'));
            redirect(admin_url('view=orders'));
        }

        if ($action === 'order_update_status') {
            if (!$isAdmin && !$isKasir) {
                throw new RuntimeException('Akses ditolak.');
            }

            $orderId = (int)($_POST['id'] ?? 0);
            $newStatus = strtolower(trim((string)($_POST['order_status'] ?? '')));
            $paymentStatus = trim((string)($_POST['payment_status'] ?? ''));
            $note = trim((string)($_POST['note'] ?? ''));

            $allowedStatus = ['pending', 'new', 'paid', 'processing', 'shipped', 'delivered', 'completed', 'cancelled', 'refunded'];
            $allowedPayment = ['', 'unpaid', 'pending', 'paid', 'failed', 'refunded'];

            if ($orderId <= 0) {
                throw new RuntimeException('Order tidak valid.');
            }

            if (!in_array($newStatus, $allowedStatus, true)) {
                throw new RuntimeException('Status order tidak valid.');
            }

            if (!in_array($paymentStatus, $allowedPayment, true)) {
                throw new RuntimeException('Status pembayaran tidak valid.');
            }

            if ($isKasir && !in_array($newStatus, ['paid', 'processing', 'completed'], true)) {
                throw new RuntimeException('Kasir hanya boleh mengubah status ke paid, processing, atau completed.');
            }

            $paymentStatus = $paymentStatus !== '' ? $paymentStatus : null;

            if (!admin_engine_update_order_status($orderId, $newStatus, (int)($user['id'] ?? 0), $note, $paymentStatus)) {
                throw new RuntimeException('Gagal update status order.');
            }

            $notifSent = false;
            if (in_array($newStatus, ['paid', 'processing', 'shipped', 'delivered', 'completed'], true)) {
                $notifSent = admin_send_order_status_wa($orderId, $newStatus, $note);
            }

            flash('success', 'Status order berhasil diperbarui.' . ($notifSent ? ' Notifikasi WhatsApp pembeli dikirim / masuk antrean.' : ''));
            redirect(admin_url('view=orders'));
        }

        if ($action === 'order_add_note') {
            if (!$isAdmin && !$isKasir) {
                throw new RuntimeException('Akses ditolak.');
            }

            $orderId = (int)($_POST['id'] ?? 0);
            $note = trim((string)($_POST['note'] ?? ''));

            if ($orderId <= 0 || $note === '') {
                throw new RuntimeException('Catatan internal wajib diisi.');
            }

            if (!admin_engine_add_internal_note($orderId, $note, (int)($user['id'] ?? 0))) {
                throw new RuntimeException('Gagal menyimpan catatan internal.');
            }

            flash('success', 'Catatan internal order berhasil disimpan.');
            redirect(admin_url('view=orders'));
        }

        if ($action === 'order_resend_invoice') {
            if (!$isAdmin && !$isKasir) {
                throw new RuntimeException('Akses ditolak.');
            }

            $orderId = (int)($_POST['id'] ?? 0);

            if ($orderId <= 0) {
                throw new RuntimeException('Order tidak valid.');
            }

            if (!admin_engine_resend_invoice($orderId)) {
                throw new RuntimeException('Gagal kirim ulang WhatsApp. Pastikan nomor pembeli dan gateway aktif.');
            }

            flash('success', 'Invoice WhatsApp berhasil dikirim ulang / masuk antrean.');
            redirect(admin_url('view=orders'));
        }

        if ($action === 'delete_order') {
            require_admin();

            $stmt = db()->prepare('DELETE FROM orders WHERE id = ?');
            $stmt->execute([(int)$_POST['id']]);

            flash('success', 'Order dihapus.');
            redirect(admin_url('view=orders'));
        }

    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect(admin_url('view=' . urlencode($view)));
    }
}

$success = flash('success');
$error = flash('error');

$stats = [
    'products' => table_count('products'),
    'orders' => table_count('orders'),
    'categories' => table_count('categories'),
    'articles' => table_count('articles'),
];

$revenueTotal = (float)safe_scalar("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE order_status != 'cancelled'");
$pendingOrders = (int)safe_scalar("SELECT COUNT(*) FROM orders WHERE order_status IN ('new','processing') OR payment_status = 'unpaid'");
$paidOrders = (int)safe_scalar("SELECT COUNT(*) FROM orders WHERE payment_status = 'paid' OR order_status = 'completed'");
$totalCustomers = (int)safe_scalar("SELECT COUNT(DISTINCT NULLIF(buyer_phone,'')) FROM orders");
$todayRevenue = (float)safe_scalar("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE DATE(created_at) = CURDATE() AND order_status != 'cancelled'");
$todayOrders = (int)safe_scalar("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE()");
$couponUsed = (int)safe_scalar("SELECT COALESCE(SUM(used_count),0) FROM coupons");

$categories = safe_rows('
    SELECT c.*, p.name AS parent_name 
    FROM categories c
    LEFT JOIN categories p ON p.id = c.parent_id
    ORDER BY 
        CASE WHEN c.parent_id IS NULL THEN c.id ELSE c.parent_id END DESC,
        CASE WHEN c.parent_id IS NULL THEN 0 ELSE 1 END ASC,
        c.sort_order ASC,
        c.id DESC
');
$products = safe_rows('
    SELECT p.*, c.name AS category_name 
    FROM products p 
    LEFT JOIN categories c ON c.id = p.category_id 
    ORDER BY p.id DESC 
    LIMIT 500
');

$productVariantCounts = [];
$productVariantsMap = [];
if (admin_table_exists('product_variants')) {
    foreach (safe_rows('SELECT product_id, COUNT(*) AS total FROM product_variants GROUP BY product_id') as $variantRow) {
        $productVariantCounts[(int)$variantRow['product_id']] = (int)$variantRow['total'];
    }

    foreach (safe_rows('SELECT * FROM product_variants ORDER BY id ASC') as $variantRow) {
        $pid = (int)($variantRow['product_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }

        if (!isset($productVariantsMap[$pid])) {
            $productVariantsMap[$pid] = [];
        }

        $productVariantsMap[$pid][] = [
            'type' => $variantRow['variant_type'] ?? $variantRow['type'] ?? '',
            'size' => $variantRow['variant_size'] ?? $variantRow['size'] ?? '',
            'price' => $variantRow['price'] ?? '',
            'stock' => $variantRow['stock'] ?? '',
            'image_url' => $variantRow['image_url'] ?? '',
        ];
    }
}

$productGalleryMap = [];
$productGalleryTextMap = [];
if (admin_table_exists('product_images')) {
    foreach (safe_rows("
        SELECT *
        FROM product_images
        WHERE COALESCE(is_active, 1) = 1
        ORDER BY product_id ASC, sort_order ASC, id ASC
    ") as $galleryRow) {
        $pid = (int)($galleryRow['product_id'] ?? 0);
        $url = trim((string)($galleryRow['image_url'] ?? $galleryRow['image_path'] ?? ''));

        if ($pid <= 0 || $url === '') {
            continue;
        }

        if (!isset($productGalleryMap[$pid])) {
            $productGalleryMap[$pid] = [];
        }

        $productGalleryMap[$pid][] = [
            'image_url' => $url,
            'alt_text' => $galleryRow['alt_text'] ?? '',
            'sort_order' => $galleryRow['sort_order'] ?? 0,
        ];
    }

    foreach ($productGalleryMap as $pid => $rows) {
        $productGalleryTextMap[$pid] = implode("\n", array_map(static function ($row) {
            return (string)($row['image_url'] ?? '');
        }, $rows));
    }
}

$productBundleItemsMap = [];
if (admin_table_exists('product_bundle_items')) {
    foreach (safe_rows("
        SELECT
            pbi.*,
            p.name AS component_name,
            p.product_type AS component_product_type,
            p.delivery_type AS component_delivery_type
        FROM product_bundle_items pbi
        LEFT JOIN products p ON p.id = pbi.component_product_id
        WHERE pbi.is_active = 1
        ORDER BY pbi.sort_order ASC, pbi.id ASC
    ") as $bundleRow) {
        $bundleId = (int)($bundleRow['bundle_product_id'] ?? 0);

        if ($bundleId <= 0) {
            continue;
        }

        if (!isset($productBundleItemsMap[$bundleId])) {
            $productBundleItemsMap[$bundleId] = [];
        }

        $productBundleItemsMap[$bundleId][] = [
            'component_product_id' => (int)($bundleRow['component_product_id'] ?? 0),
            'qty' => (float)($bundleRow['qty'] ?? 1),
            'component_name' => $bundleRow['component_name'] ?? '',
            'component_product_type' => $bundleRow['component_product_type'] ?? 'physical',
            'component_delivery_type' => $bundleRow['component_delivery_type'] ?? 'none',
        ];
    }
}

$productEditData = [];
foreach ($products as $productRow) {
    $pid = (int)($productRow['id'] ?? 0);

    if ($pid <= 0) {
        continue;
    }

    $productEditData[$pid] = [
        'id' => $pid,
        'category_id' => $productRow['category_id'] ?? '',
        'name' => $productRow['name'] ?? '',
        'sku' => $productRow['sku'] ?? '',
        'unit' => $productRow['unit'] ?? '',
        'price' => $productRow['price'] ?? '',
        'sale_price' => $productRow['sale_price'] ?? '',
        'stock' => $productRow['stock'] ?? '',
        'stock_type' => $productRow['stock_type'] ?? 'limited',
        'weight_gram' => $productRow['weight_gram'] ?? '',
        'product_type' => $productRow['product_type'] ?? 'physical',
        'delivery_type' => $productRow['delivery_type'] ?? 'none',
        'badge' => $productRow['badge'] ?? '',
        'delivery_title' => $productRow['delivery_title'] ?? '',
        'delivery_url' => $productRow['delivery_url'] ?? '',
        'delivery_file_path' => $productRow['delivery_file_path'] ?? '',
        'delivery_button_label' => $productRow['delivery_button_label'] ?? 'Buka Akses',
        'access_expires_days' => $productRow['access_expires_days'] ?? 0,
        'digital_auto_deliver' => isset($productRow['digital_auto_deliver']) ? (int)$productRow['digital_auto_deliver'] : 1,
        'delivery_content' => $productRow['delivery_content'] ?? '',
        'delivery_instruction' => $productRow['delivery_instruction'] ?? '',
        'license_low_stock_alert' => $productRow['license_low_stock_alert'] ?? 5,
        'main_image' => $productRow['main_image'] ?? '',
        'gallery_images' => $productGalleryTextMap[$pid] ?? '',
        'description' => $productRow['description'] ?? '',
        'is_active' => isset($productRow['is_active']) ? (int)$productRow['is_active'] : 1,
        'variants' => $productVariantsMap[$pid] ?? [],
        'bundle_items' => $productBundleItemsMap[$pid] ?? [],
    ];
}

$productLicenseStockCounts = [];
if (admin_table_exists('digital_license_stocks')) {
    foreach (safe_rows("SELECT product_id, status, COUNT(*) AS total FROM digital_license_stocks GROUP BY product_id, status") as $stockRow) {
        $pid = (int)$stockRow['product_id'];
        if (!isset($productLicenseStockCounts[$pid])) {
            $productLicenseStockCounts[$pid] = ['available' => 0, 'assigned' => 0, 'total' => 0];
        }

        $status = (string)$stockRow['status'];
        $count = (int)$stockRow['total'];

        if (isset($productLicenseStockCounts[$pid][$status])) {
            $productLicenseStockCounts[$pid][$status] = $count;
        }

        $productLicenseStockCounts[$pid]['total'] += $count;
    }
}

$orders = safe_rows('SELECT * FROM orders ORDER BY id DESC LIMIT 500');
$orderIds = array_map(static function ($row) {
    return (int)($row['id'] ?? 0);
}, $orders);

$orderItemsMap = admin_orders_grouped_rows('order_items', $orderIds, 'id ASC');
$orderStatusLogsMap = admin_orders_grouped_rows('order_status_logs', $orderIds, 'id DESC');
$orderNotesMap = admin_orders_grouped_rows('order_internal_notes', $orderIds, 'id DESC');
$orderDigitalAccessMap = admin_orders_grouped_rows('order_digital_access', $orderIds, 'id DESC');

$latestOrders = safe_rows('SELECT * FROM orders ORDER BY id DESC LIMIT 10');

$topProducts = safe_rows('
    SELECT product_name, SUM(qty) AS qty_total, SUM(subtotal) AS sales_total 
    FROM order_items 
    GROUP BY product_name 
    ORDER BY qty_total DESC 
    LIMIT 5
');

$orderStatusRows = safe_rows('SELECT order_status, COUNT(*) AS total FROM orders GROUP BY order_status');

$dailyMap = [];
$dailyRows = safe_rows("
    SELECT DATE(created_at) AS date_key, COALESCE(SUM(grand_total),0) AS total 
    FROM orders 
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) 
    AND order_status != 'cancelled' 
    GROUP BY DATE(created_at) 
    ORDER BY date_key ASC
");

foreach ($dailyRows as $row) {
    $dailyMap[$row['date_key']] = (float)$row['total'];
}

$dailyLabels = [];
$dailyValues = [];

for ($i = 6; $i >= 0; $i--) {
    $key = date('Y-m-d', strtotime('-' . $i . ' days'));
    $dailyLabels[] = date('d M', strtotime($key));
    $dailyValues[] = $dailyMap[$key] ?? 0;
}


if (!function_exists('admin_dashboard_range_data')) {
    function admin_dashboard_range_data(string $range): array
    {
        $range = in_array($range, ['today', '7days', '30days'], true) ? $range : 'today';

        if ($range === 'today') {
            $start = date('Y-m-d 00:00:00');
            $end = date('Y-m-d 23:59:59');
            $labels = [];
            $values = array_fill(0, 24, 0);

            for ($hour = 0; $hour <= 23; $hour++) {
                $labels[] = str_pad((string)$hour, 2, '0', STR_PAD_LEFT) . ':00';
            }

            $rows = safe_rows("
                SELECT HOUR(created_at) AS bucket_key, COALESCE(SUM(grand_total),0) AS total
                FROM orders
                WHERE created_at BETWEEN ? AND ?
                AND order_status != 'cancelled'
                GROUP BY HOUR(created_at)
                ORDER BY bucket_key ASC
            ", [$start, $end]);

            foreach ($rows as $row) {
                $idx = (int)$row['bucket_key'];
                if ($idx >= 0 && $idx <= 23) {
                    $values[$idx] = (float)$row['total'];
                }
            }

            $label = 'Hari ini';
            $omzetLabel = 'Omzet Hari Ini';
        } else {
            $days = $range === '7days' ? 7 : 30;
            $startDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
            $endDate = date('Y-m-d');

            $labels = [];
            $values = [];
            $map = [];

            $rows = safe_rows("
                SELECT DATE(created_at) AS bucket_key, COALESCE(SUM(grand_total),0) AS total
                FROM orders
                WHERE DATE(created_at) BETWEEN ? AND ?
                AND order_status != 'cancelled'
                GROUP BY DATE(created_at)
                ORDER BY bucket_key ASC
            ", [$startDate, $endDate]);

            foreach ($rows as $row) {
                $map[$row['bucket_key']] = (float)$row['total'];
            }

            for ($i = $days - 1; $i >= 0; $i--) {
                $key = date('Y-m-d', strtotime('-' . $i . ' days'));
                $labels[] = date('d M', strtotime($key));
                $values[] = $map[$key] ?? 0;
            }

            $label = $range === '7days' ? '7 Hari' : '30 Hari';
            $omzetLabel = $range === '7days' ? 'Omzet 7 Hari' : 'Omzet 30 Hari';
            $start = $startDate . ' 00:00:00';
            $end = $endDate . ' 23:59:59';
        }

        $customers = (int)safe_scalar("
            SELECT COUNT(DISTINCT NULLIF(buyer_phone,''))
            FROM orders
            WHERE created_at BETWEEN ? AND ?
            AND order_status != 'cancelled'
        ", [$start, $end]);

        $paid = (int)safe_scalar("
            SELECT COUNT(*)
            FROM orders
            WHERE created_at BETWEEN ? AND ?
            AND (payment_status = 'paid' OR order_status = 'completed')
            AND order_status != 'cancelled'
        ", [$start, $end]);

        $revenue = (float)safe_scalar("
            SELECT COALESCE(SUM(grand_total),0)
            FROM orders
            WHERE created_at BETWEEN ? AND ?
            AND order_status != 'cancelled'
        ", [$start, $end]);

        return [
            'range' => $range,
            'label' => $label,
            'omzet_label' => $omzetLabel,
            'labels' => $labels,
            'values' => $values,
            'customers' => $customers,
            'paid_orders' => $paid,
            'revenue' => $revenue,
            'revenue_text' => rupiah($revenue),
        ];
    }
}

$dashboardAnalytics = [
    'today' => admin_dashboard_range_data('today'),
    '7days' => admin_dashboard_range_data('7days'),
    '30days' => admin_dashboard_range_data('30days'),
];


$monthlyMap = [];
$monthlyRows = safe_rows("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS month_key, COALESCE(SUM(grand_total),0) AS total 
    FROM orders 
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH) 
    AND order_status != 'cancelled' 
    GROUP BY DATE_FORMAT(created_at, '%Y-%m') 
    ORDER BY month_key ASC
");

foreach ($monthlyRows as $row) {
    $monthlyMap[$row['month_key']] = (float)$row['total'];
}

$monthlyLabels = [];
$monthlyValues = [];

for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime('-' . $i . ' months'));
    $monthlyLabels[] = date('M', strtotime($key . '-01'));
    $monthlyValues[] = $monthlyMap[$key] ?? 0;
}

$topProductLabels = array_map(function ($row) {
    return $row['product_name'];
}, $topProducts);

$topProductValues = array_map(function ($row) {
    return (int)$row['qty_total'];
}, $topProducts);

if (!$topProductLabels) {
    $topProductLabels = ['Belum ada data'];
    $topProductValues = [1];
}

$statusCounts = [
    'new' => 0,
    'processing' => 0,
    'completed' => 0,
    'cancelled' => 0,
];

foreach ($orderStatusRows as $row) {
    if (isset($statusCounts[$row['order_status']])) {
        $statusCounts[$row['order_status']] = (int)$row['total'];
    }
}

$usersList = safe_rows("SELECT id, name, username, email, phone, role, status, last_login_at, created_at FROM users ORDER BY id DESC");
$customersList = safe_rows("SELECT * FROM customers ORDER BY updated_at DESC, id DESC LIMIT 500");
$couponsList = safe_rows("SELECT * FROM coupons ORDER BY id DESC");
$shippingRates = safe_rows("SELECT * FROM shipping_rates ORDER BY sort_order ASC, id DESC");
$articlesList = safe_rows("SELECT * FROM articles ORDER BY id DESC LIMIT 500");

$broadcastEnabled = (string)setting('broadcast_enabled', '1') === '1';
$broadcastBatchLimit = admin_broadcast_batch_limit();
$broadcastPolicyNotice = admin_broadcast_policy_notice();
$broadcastCampaigns = admin_table_exists('wa_broadcast_campaigns') ? safe_rows("
    SELECT *
    FROM wa_broadcast_campaigns
    ORDER BY id DESC
    LIMIT 50
") : [];

$broadcastStats = [
    'campaigns' => admin_table_exists('wa_broadcast_campaigns') ? (int)safe_scalar("SELECT COUNT(*) FROM wa_broadcast_campaigns", [], 0) : 0,
    'pending' => admin_table_exists('wa_message_queue') ? (int)safe_scalar("SELECT COUNT(*) FROM wa_message_queue WHERE message_type = 'broadcast' AND status = 'pending'", [], 0) : 0,
    'sent' => admin_table_exists('wa_message_queue') ? (int)safe_scalar("SELECT COUNT(*) FROM wa_message_queue WHERE message_type = 'broadcast' AND status = 'sent'", [], 0) : 0,
    'failed' => admin_table_exists('wa_message_queue') ? (int)safe_scalar("SELECT COUNT(*) FROM wa_message_queue WHERE message_type = 'broadcast' AND status = 'failed'", [], 0) : 0,
];

$broadcastTargetCounts = [];
foreach (admin_broadcast_target_options() as $targetKey => $targetLabel) {
    $filters = [];
    if ($targetKey === 'product_buyers' && !empty($products[0]['id'])) {
        $filters['product_id'] = (int)$products[0]['id'];
    }
    $broadcastTargetCounts[$targetKey] = count(admin_broadcast_recipients($targetKey, $filters));
}

$pageTitles = [
    'dashboard' => 'Dashboard',
    'users' => 'Manajemen Kasir',
    'products' => 'Katalog Produk',
    'categories' => 'Kategori Produk',
    'coupons' => 'Kupon Diskon',
    'shipping' => 'Ongkos Kirim',
    'articles' => 'Artikel & Blog',
    'orders' => 'Pesanan Masuk',
    'customers' => 'Data Pembeli',
    'broadcast' => 'Broadcast WA',
    'tutorial' => 'Video Panduan',
    'whatsapp' => 'WhatsApp Gateway',
    'settings' => 'Pengaturan Toko',
];

$pageTitle = $pageTitles[$view] ?? 'Dashboard';
IMersWAStoreProGuard::checkpoint('admin.render');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> - <?= e($appName) ?></title>

    <link rel="stylesheet" href="assets/css/style.css?v=dashboard-v3-logo-transparent">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        :root {
            --primary: <?= e($primary) ?>;
            --secondary: <?= e($secondary) ?>;
            --accent: <?= e($accent) ?>;
            --bg: <?= e($bg) ?>;
            --dash-card-1-start: <?= e($card1Start) ?>;
            --dash-card-1-end: <?= e($card1End) ?>;
            --dash-card-2-start: <?= e($card2Start) ?>;
            --dash-card-2-end: <?= e($card2End) ?>;
            --dash-card-3-start: <?= e($card3Start) ?>;
            --dash-card-3-end: <?= e($card3End) ?>;
            --dash-card-4-start: <?= e($card4Start) ?>;
            --dash-card-4-end: <?= e($card4End) ?>;
        }

        /* Logo transparan: jangan kasih background kalau user upload PNG transparan */
        .sidebar-brand .brand-logo,
        .brand-logo {
            background: transparent !important;
            box-shadow: none !important;
            border: 0 !important;
            border-radius: 0 !important;
            padding: 0 !important;
            overflow: visible !important;
        }

        .sidebar-brand .brand-logo img,
        .brand-logo img {
            width: 42px !important;
            height: 42px !important;
            object-fit: contain !important;
            display: block !important;
            border-radius: 0 !important;
            background: transparent !important;
            box-shadow: none !important;
        }

        .brand-logo.logo-fallback {
            width: 42px;
            height: 42px;
            border-radius: 14px !important;
            background: linear-gradient(135deg, var(--primary), var(--accent)) !important;
            color: #fff;
            box-shadow: 0 16px 32px rgba(16, 185, 129, .22) !important;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-family: "Plus Jakarta Sans", "Poppins", sans-serif;
        }

        /* Dynamic dashboard card gradients from admin color picker */
        .metric-card.metric-blue {
            background: linear-gradient(135deg, var(--dash-card-1-start), var(--dash-card-1-end)) !important;
        }

        .metric-card.metric-purple {
            background: linear-gradient(135deg, var(--dash-card-2-start), var(--dash-card-2-end)) !important;
        }

        .metric-card.metric-pink {
            background: linear-gradient(135deg, var(--dash-card-3-start), var(--dash-card-3-end)) !important;
        }

        .metric-card.metric-green {
            background: linear-gradient(135deg, var(--dash-card-4-start), var(--dash-card-4-end)) !important;
        }

        .color-pair-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(160px, 1fr));
            gap: 16px;
            width: 100%;
        }

        .color-pair-box {
            padding: 16px;
            border: 1px solid rgba(148, 163, 184, .22);
            background: rgba(255,255,255,.72);
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, .04);
        }

        .color-pair-box strong {
            display: block;
            margin-bottom: 10px;
            font-size: 13px;
            color: #0f172a;
        }

        .color-pair-box label {
            display: flex !important;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 10px;
            font-size: 12px;
        }

        .color-pair-box input[type="color"] {
            width: 56px;
            height: 36px;
            border: 0;
            padding: 0;
            background: transparent;
            cursor: pointer;
        }

        .gradient-preview {
            height: 42px;
            border-radius: 14px;
            margin-top: 8px;
            box-shadow: inset 0 0 0 1px rgba(255,255,255,.35);
        }

        @media (max-width: 1100px) {
            .color-pair-grid {
                grid-template-columns: repeat(2, minmax(160px, 1fr));
            }
        }

        @media (max-width: 640px) {
            .color-pair-grid {
                grid-template-columns: 1fr;
            }
        }
    
        .auto-field-note {
            grid-column: 1 / -1;
            display: flex;
            flex-direction: column;
            gap: 4px;
            padding: 14px 16px;
            border: 1px dashed rgba(16, 185, 129, .32);
            background: linear-gradient(135deg, rgba(16, 185, 129, .08), rgba(251, 146, 60, .08));
            border-radius: 16px;
            color: #334155;
        }

        .auto-field-note strong {
            color: #0f172a;
            font-size: 13px;
            font-weight: 900;
        }

        .auto-field-note span {
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
        }

    
        .setting-section-note {
            grid-column: 1 / -1;
            padding: 14px 16px;
            border-radius: 16px;
            border: 1px solid rgba(148, 163, 184, .22);
            background: rgba(248, 250, 252, .78);
            color: #64748b;
            font-size: 12px;
            font-weight: 700;
            line-height: 1.6;
        }

        .upload-help {
            display: block;
            margin-top: 6px;
            color: #94a3b8;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.4;
        }

        .setting-divider {
            grid-column: 1 / -1;
            height: 1px;
            background: rgba(148, 163, 184, .20);
            margin: 4px 0;
        }

        .form-grid input[type="file"] {
            padding: 11px;
            background: rgba(248, 250, 252, .86);
            border: 1px dashed rgba(148, 163, 184, .42);
        }

    
        .repeater-wrap {
            grid-column: 1 / -1;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .repeater-row {
            display: grid;
            grid-template-columns: 54px 190px minmax(0, 1fr) 42px;
            gap: 10px;
            align-items: center;
        }

        .repeater-row.video-row {
            grid-template-columns: 54px minmax(0, 1fr) 42px;
        }

        .repeater-number {
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            background: #f1f5f9;
            color: #334155;
            font-weight: 900;
        }

        .repeater-remove {
            height: 42px;
            border: 0;
            border-radius: 12px;
            background: #fff1f2;
            color: #ef4444;
            font-weight: 900;
            cursor: pointer;
        }

        .repeater-add {
            grid-column: 1 / -1;
            border: 0;
            background: transparent;
            color: #059669;
            font-weight: 900;
            cursor: pointer;
            text-align: left;
            padding: 6px 0;
        }

        .full-span {
            grid-column: 1 / -1;
        }

        .password-card {
            margin-top: 24px;
        }

        @media (max-width: 900px) {
            .repeater-row,
            .repeater-row.video-row {
                grid-template-columns: 1fr;
            }

            .repeater-number {
                display: none;
            }
        }


        .analytics-tabs {
            display: inline-flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .analytics-tabs button {
            border: 1px solid rgba(148, 163, 184, .18);
            background: #f8fafc;
            color: #64748b;
            border-radius: 12px;
            padding: 9px 13px;
            font-size: 12px;
            font-weight: 900;
            cursor: pointer;
            transition: .2s ease;
            font-family: inherit;
        }

        .analytics-tabs button:hover,
        .analytics-tabs button.active {
            background: rgba(16, 185, 129, .12);
            color: #047857;
            border-color: rgba(16, 185, 129, .22);
        }


        .product-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .product-modal-overlay {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: rgba(15, 23, 42, .58);
            backdrop-filter: blur(8px);
        }

        .product-modal-overlay.show {
            display: flex;
        }

        .product-modal {
            width: min(100%, 860px);
            max-height: 92vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            background: #fff;
            border-radius: 24px;
            box-shadow: 0 32px 90px rgba(15, 23, 42, .28);
            border: 1px solid rgba(226, 232, 240, .95);
        }

        .product-modal-head {
            position: sticky;
            top: 0;
            z-index: 2;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 20px 24px;
            background: rgba(255, 255, 255, .96);
            border-bottom: 1px solid rgba(226, 232, 240, .85);
            backdrop-filter: blur(12px);
        }

        .product-modal-head h2 {
            margin: 0;
        }

        .product-modal-close {
            width: 38px;
            height: 38px;
            border: 0;
            border-radius: 14px;
            background: #f1f5f9;
            color: #64748b;
            font-size: 24px;
            line-height: 1;
            cursor: pointer;
        }

        .product-modal-body {
            padding: 22px 24px 24px;
            overflow-y: auto;
            overflow-x: hidden;
            max-height: calc(92vh - 80px);
            overscroll-behavior: contain;
            scrollbar-gutter: stable;
        }

        .product-modal-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .product-modal-grid .span-2 {
            grid-column: 1 / -1;
        }

        .variant-section-head {
            grid-column: 1 / -1;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding-top: 16px;
            margin-top: 4px;
            border-top: 1px solid rgba(226, 232, 240, .86);
        }

        .variant-section-head strong {
            color: #334155;
            font-size: 14px;
        }

        .variant-list {
            grid-column: 1 / -1;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .variant-card {
            position: relative;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
            padding: 16px;
            border: 1px solid rgba(203, 213, 225, .86);
            background: #f8fafc;
            border-radius: 18px;
        }

        .variant-card .span-2 {
            grid-column: 1 / -1;
        }

        .variant-remove {
            position: absolute;
            top: 10px;
            right: 10px;
            width: 28px;
            height: 28px;
            border: 1px solid rgba(248, 113, 113, .35);
            border-radius: 999px;
            background: #fff;
            color: #ef4444;
            font-weight: 900;
            cursor: pointer;
        }

        .product-thumb-row {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .product-thumb-row img {
            width: 42px;
            height: 42px;
            object-fit: cover;
            border-radius: 12px;
            border: 1px solid rgba(226, 232, 240, .9);
            background: #f8fafc;
        }

        .product-muted {
            display: block;
            color: #94a3b8;
            font-size: 11px;
            margin-top: 4px;
        }


        .digital-delivery-box {
            grid-column: 1 / -1;
            display: none;
            padding: 18px;
            border-radius: 20px;
            border: 1px solid rgba(16, 185, 129, .20);
            background: linear-gradient(135deg, rgba(236,253,245,.9), rgba(255,247,237,.75));
        }

        .digital-delivery-box.show {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .digital-delivery-box .span-2 {
            grid-column: 1 / -1;
        }

        .digital-delivery-box select option[data-system-option="1"] {
            display: none;
        }

        .manual-delivery-note {
            display: none;
            border-color: rgba(59, 130, 246, .22) !important;
            background: linear-gradient(135deg, rgba(239,246,255,.88), rgba(255,255,255,.78)) !important;
        }

        .manual-delivery-note.show {
            display: block;
        }

        .license-stock-box {
            grid-column: 1 / -1;
            display: none;
            padding: 18px;
            border-radius: 20px;
            border: 1px solid rgba(251, 146, 60, .30);
            background: linear-gradient(135deg, rgba(255,247,237,.92), rgba(255,255,255,.88));
        }

        .license-stock-box.show {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .license-stock-box .span-2 {
            grid-column: 1 / -1;
        }

        .bundle-builder-box {
            grid-column: 1 / -1;
            display: none;
            padding: 18px;
            border-radius: 20px;
            border: 1px solid rgba(124,58,237,.24);
            background: linear-gradient(135deg, rgba(245,243,255,.94), rgba(255,255,255,.88));
        }

        .bundle-builder-box.show {
            display: grid;
            grid-template-columns: 1fr;
            gap: 14px;
        }

        .bundle-row {
            display: grid;
            grid-template-columns: minmax(0,1fr) 130px 34px;
            gap: 10px;
            align-items: end;
            padding: 14px;
            border-radius: 17px;
            border: 1px solid rgba(203,213,225,.82);
            background: rgba(255,255,255,.88);
        }

        .bundle-row-remove {
            width: 34px;
            height: 38px;
            border: 1px solid rgba(248,113,113,.35);
            border-radius: 12px;
            background: #fff;
            color: #ef4444;
            font-weight: 950;
            cursor: pointer;
        }

        .bundle-list {
            display: grid;
            gap: 10px;
        }

        .product-type-line {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 6px;
        }

        @media (max-width: 760px) {
            .digital-delivery-box.show,
            .license-stock-box.show {
                grid-template-columns: 1fr;
            }

            .digital-delivery-box .span-2,
            .license-stock-box .span-2 {
                grid-column: auto;
            }

            .bundle-row {
                grid-template-columns: 1fr;
            }

            .bundle-row-remove {
                width: 100%;
            }
        }

        .product-badge-soft {
            display: inline-flex;
            align-items: center;
            min-height: 24px;
            padding: 4px 9px;
            border-radius: 9px;
            background: #f1f5f9;
            color: #475569;
            font-size: 11px;
            font-weight: 900;
        }

        @media (max-width: 760px) {
            .product-modal-grid,
            .variant-card {
                grid-template-columns: 1fr;
            }

            .product-modal-grid .span-2,
            .variant-card .span-2 {
                grid-column: auto;
            }
        }


        .admin-table-tools {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            padding: 14px 16px;
            border-bottom: 1px solid rgba(226, 232, 240, .88);
            background: linear-gradient(135deg, rgba(248,250,252,.96), rgba(255,255,255,.96));
        }

        .admin-table-search {
            flex: 1 1 280px;
            min-height: 44px;
            border: 1px solid rgba(203,213,225,.95);
            border-radius: 15px;
            background: #fff;
            padding: 0 14px;
            outline: 0;
            color: #0f172a;
            font: 800 13px/1 "Plus Jakarta Sans", "Inter", system-ui, sans-serif;
        }

        .admin-table-search:focus {
            border-color: #10b981;
            box-shadow: 0 0 0 4px rgba(16,185,129,.10);
        }

        .admin-table-info {
            color: #64748b;
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
        }

        .admin-table-empty {
            display: none;
            margin: 16px;
            padding: 18px;
            border: 1px dashed rgba(148,163,184,.55);
            border-radius: 18px;
            text-align: center;
            color: #64748b;
            font-weight: 900;
            background: #f8fafc;
        }

        .admin-table-pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            border-top: 1px solid rgba(226, 232, 240, .88);
            background: #fff;
        }

        .admin-table-pagination button {
            min-height: 40px;
            border: 0;
            border-radius: 13px;
            padding: 0 15px;
            background: #ecfdf5;
            color: #047857;
            font-size: 12px;
            font-weight: 950;
            cursor: pointer;
        }

        .admin-table-pagination button:disabled {
            opacity: .45;
            cursor: not-allowed;
        }

        .admin-table-pagination span {
            color: #334155;
            font-size: 12px;
            font-weight: 950;
        }

        @media (max-width: 720px) {
            .admin-table-tools {
                align-items: stretch;
            }

            .admin-table-search,
            .admin-table-info {
                width: 100%;
            }

            .admin-table-pagination {
                flex-wrap: wrap;
            }

            .admin-table-pagination button {
                flex: 1;
            }

            .admin-table-pagination span {
                width: 100%;
                text-align: center;
                order: -1;
            }
        }



        .order-action-stack {
            display: flex;
            flex-direction: column;
            gap: 7px;
            align-items: flex-start;
        }

        .order-action-stack .btn,
        .order-action-stack button {
            width: 100%;
            justify-content: center;
            text-align: center;
        }

        .order-detail-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .order-detail-card {
            padding: 16px;
            border: 1px solid rgba(226, 232, 240, .95);
            border-radius: 18px;
            background: #f8fafc;
        }

        .order-detail-card h3 {
            margin: 0 0 10px;
            font-size: 15px;
            color: #0f172a;
        }

        .order-detail-card p,
        .order-detail-card li {
            color: #475569;
            font-size: 13px;
            line-height: 1.65;
            margin: 0;
        }

        .order-detail-card ul {
            margin: 0;
            padding-left: 18px;
        }

        .order-detail-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .order-detail-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            border-bottom: 1px dashed rgba(148, 163, 184, .35);
            padding-bottom: 8px;
        }

        .order-detail-row:last-child {
            border-bottom: 0;
            padding-bottom: 0;
        }

        .order-detail-row span {
            color: #64748b;
            font-size: 12px;
            font-weight: 800;
        }

        .order-detail-row strong {
            color: #0f172a;
            font-size: 13px;
            text-align: right;
        }

        .order-modal {
            width: min(100%, 980px);
        }

        .order-status-form {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px;
        }

        .order-status-form textarea,
        .order-note-form textarea {
            grid-column: 1 / -1;
            min-height: 86px;
        }

        .order-status-form button,
        .order-note-form button {
            grid-column: 1 / -1;
        }

                .bundle-order-children {
            margin-top: 10px;
            padding: 10px 12px;
            border-radius: 16px;
            background: #f8fafc;
            border: 1px dashed rgba(148,163,184,.55);
        }

        .bundle-order-child {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            padding: 6px 0;
            border-bottom: 1px solid rgba(226,232,240,.72);
            font-size: 12px;
        }

        .bundle-order-child:last-child {
            border-bottom: 0;
        }

        .bundle-source-chip {
            display: inline-flex;
            align-items: center;
            margin-top: 5px;
            padding: 4px 8px;
            border-radius: 999px;
            background: rgba(124,58,237,.10);
            color: #6d28d9;
            font-size: 11px;
            font-weight: 900;
        }

.order-access-box {
            padding: 12px;
            border-radius: 14px;
            background: #fff;
            border: 1px solid rgba(226,232,240,.95);
            margin-top: 8px;
        }

        .order-access-box code {
            display: block;
            white-space: pre-wrap;
            word-break: break-word;
            color: #0f172a;
            background: #f1f5f9;
            border-radius: 12px;
            padding: 10px;
            margin-top: 8px;
            font-size: 12px;
        }

        @media (max-width: 820px) {
            .order-detail-grid,
            .order-status-form {
                grid-template-columns: 1fr;
            }
        }


    </style>
</head>
<body class="admin-body">

<div class="admin-shell">
    <aside class="sidebar app-sidebar">
        <a class="brand sidebar-brand" href="admin.php">
            <?php if ($appLogo): ?>
                <div class="brand-logo">
                    <img src="<?= e($appLogo) ?>" alt="<?= e($appName) ?>">
                </div>
            <?php else: ?>
                <div class="brand-logo logo-fallback">
                    <?= e($appInitial) ?>
                </div>
            <?php endif; ?>

            <span><?= e($appName) ?></span>
        </a>

        <div class="sidebar-user-card">
            <div class="sidebar-avatar"><?= e(admin_initial($storeName ?: ($user['name'] ?? 'Admin'))) ?></div>
            <div>
                <strong><?= e($storeName) ?></strong>
                <small>
                    <span><?= e(strtoupper($user['role'] ?? 'admin')) ?></span>
                    @<?= e($user['username'] ?? 'admin') ?>
                </small>
            </div>
        </div>

        <nav class="sidebar-nav modern-nav">
            <?php if ($isKasir): ?>
                <a class="<?= nav_active('orders', $view) ?>" href="admin.php?view=orders">
                    <span class="nav-icon">⌑</span>Pesanan Masuk
                </a>
                <a href="pos.php">
                    <span class="nav-icon">▣</span>Kasir POS
                </a>
                <a href="panduan.php">
                    <span class="nav-icon">?</span>Panduan
                </a>
            <?php else: ?>
                <a class="<?= nav_active('dashboard', $view) ?>" href="admin.php">
                    <span class="nav-icon">▦</span>Dashboard
                </a>
                <a class="<?= nav_active('users', $view) ?>" href="admin.php?view=users">
                    <span class="nav-icon">◎</span>Manajemen Kasir
                </a>
                <a class="<?= nav_active('products', $view) ?>" href="admin.php?view=products">
                    <span class="nav-icon">◈</span>Katalog Produk
                </a>
                <a class="<?= nav_active('categories', $view) ?>" href="admin.php?view=categories">
                    <span class="nav-icon">◇</span>Kategori Produk
                </a>
                <a class="<?= nav_active('coupons', $view) ?>" href="admin.php?view=coupons">
                    <span class="nav-icon">⌁</span>Kupon Diskon
                </a>
                <a class="<?= nav_active('shipping', $view) ?>" href="admin.php?view=shipping">
                    <span class="nav-icon">⇄</span>Ongkos Kirim
                </a>
                <a class="<?= nav_active('articles', $view) ?>" href="admin.php?view=articles">
                    <span class="nav-icon">☰</span>Artikel & Blog
                </a>
                <a class="<?= nav_active('orders', $view) ?>" href="admin.php?view=orders">
                    <span class="nav-icon">⌑</span>Pesanan Masuk
                </a>
                <a class="<?= nav_active('customers', $view) ?>" href="admin.php?view=customers">
                    <span class="nav-icon">♡</span>Data Pembeli
                </a>
                <a class="<?= nav_active('broadcast', $view) ?>" href="admin.php?view=broadcast">
                    <span class="nav-icon">✉</span>Broadcast WA
                </a>
                <a href="pos.php">
                    <span class="nav-icon">▣</span>Kasir POS
                </a>
                <a class="<?= nav_active('tutorial', $view) ?>" href="admin.php?view=tutorial">
                    <span class="nav-icon">▷</span>Video Panduan
                </a>
                <a href="panduan.php">
                    <span class="nav-icon">?</span>Panduan Sistem
                </a>
                <a class="<?= nav_active('whatsapp', $view) ?>" href="admin.php?view=whatsapp">
                    <span class="nav-icon">◉</span>WhatsApp Gateway
                </a>
                <a class="<?= nav_active('settings', $view) ?>" href="admin.php?view=settings">
                    <span class="nav-icon">⚙</span>Pengaturan Toko
                </a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-bottom">
            <a href="index.php" target="_blank">
                <span class="nav-icon">↗</span>Lihat Toko
            </a>
            <a class="danger" href="logout.php">
                <span class="nav-icon">↘</span>Keluar
            </a>
        </div>
    </aside>

    <main class="admin-main dashboard-main">
        <div class="admin-top modern-topbar">
            <div>
                <h1><?= e($pageTitle) ?></h1>
                <p class="muted">Login sebagai <?= e($user['name'] ?? 'Admin') ?>.</p>
            </div>

            <div class="topbar-right">
                <span class="topbar-date"><?= e(date('l, d M Y')) ?></span>
                <?php if ($isKasir): ?>
                    <a class="btn btn-primary" href="pos.php">Buka POS</a>
                <?php else: ?>
                    <a class="btn btn-primary" href="index.php" target="_blank">Buka Toko</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= e($success) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= e($error) ?></div>
        <?php endif; ?>

        <?php if ($view === 'dashboard'): ?>
            <section class="dash-container">
                <div class="welcome-card">
                    <div>
                        <h2>Selamat datang kembali, <?= e($user['name'] ?? 'Admin') ?>! <span>👋</span></h2>
                        <p>Berikut ringkasan aktivitas toko Anda hari ini.</p>
                    </div>
                    <div class="welcome-shape"></div>
                </div>

                <div class="metric-grid">
                    <div class="metric-card metric-blue">
                        <div>
                            <span>Order Hari Ini</span>
                            <strong><?= (int)$todayOrders ?></strong>
                            <small>Lihat semua ›</small>
                        </div>
                        <i>▣</i>
                    </div>

                    <div class="metric-card metric-purple">
                        <div>
                            <span>Total Order</span>
                            <strong><?= (int)$stats['orders'] ?></strong>
                            <small>Semua pesanan</small>
                        </div>
                        <i>☰</i>
                    </div>

                    <div class="metric-card metric-pink">
                        <div>
                            <span>Total Pendapatan</span>
                            <strong><?= rupiah($revenueTotal) ?></strong>
                            <small>Dari transaksi masuk</small>
                        </div>
                        <i>$</i>
                    </div>

                    <div class="metric-card metric-green">
                        <div>
                            <span>Katalog Aktif</span>
                            <strong><?= (int)$stats['products'] ?></strong>
                            <small>Produk tersedia</small>
                        </div>
                        <i>◇</i>
                    </div>
                </div>

                <div class="funnel-card card">
                    <div class="panel-title-row">
                        <div>
                            <h3>WhatsApp Store Analytics <span class="live-dot">LIVE</span></h3>
                            <p>Statistik penjualan melalui WhatsApp..</p>
                        </div>
                        <div class="mini-tabs analytics-tabs">
                            <button type="button" class="analytics-tab active" data-range="today">Hari ini</button>
                            <button type="button" class="analytics-tab" data-range="7days">7 Hari</button>
                            <button type="button" class="analytics-tab" data-range="30days">30 Hari</button>
                        </div>
                    </div>

                    <div class="mini-stat-grid">
                        <div>
                            <span>Total Pelanggan</span>
                            <strong id="analyticsCustomers"><?= (int)$dashboardAnalytics['today']['customers'] ?></strong>
                        </div>
                        <div>
                            <span>Order Lunas</span>
                            <strong id="analyticsPaidOrders"><?= (int)$dashboardAnalytics['today']['paid_orders'] ?></strong>
                        </div>
                        <div>
                            <span id="analyticsRevenueLabel"><?= e($dashboardAnalytics['today']['omzet_label']) ?></span>
                            <strong id="analyticsRevenue"><?= e($dashboardAnalytics['today']['revenue_text']) ?></strong>
                        </div>
                    </div>

                    <div class="chart-box chart-wide">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>

                <div class="dash-two-col">
                    <div class="card panel clean-panel">
                        <div class="panel-title-row compact">
                            <h3>Transaksi Terbaru</h3>
                            <a href="admin.php?view=orders">Lihat Semua ›</a>
                        </div>

                        <div class="table-wrap dashboard-table-wrap">
                            <table class="table dashboard-table">
                                <thead>
                                    <tr>
                                        <th>No. Order</th>
                                        <th>Buyer</th>
                                        <th>Total</th>
                                        <th>Status</th>
                                        <th>Tanggal</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!$latestOrders): ?>
                                        <tr>
                                            <td colspan="5">Belum ada transaksi.</td>
                                        </tr>
                                    <?php endif; ?>

                                    <?php foreach ($latestOrders as $o): ?>
                                        <tr>
                                            <td>
                                                <a class="table-link" href="admin.php?view=orders">
                                                    <?= e($o['order_number']) ?>
                                                </a>
                                            </td>
                                            <td><?= e($o['buyer_name'] ?: '-') ?></td>
                                            <td><?= rupiah($o['grand_total']) ?></td>
                                            <td>
                                                <span class="status-pill status-<?= e($o['order_status']) ?>">
                                                    <?= e($o['order_status']) ?>
                                                </span>
                                            </td>
                                            <td><?= e(short_date_id($o['created_at'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="side-stack">
                        <div class="card panel clean-panel quick-action-card">
                            <h3>Quick Actions</h3>
                            <a href="admin.php?view=products">+ Tambah Produk</a>
                            <a href="admin.php?view=coupons">+ Tambah Kupon</a>
                            <a href="admin.php?view=articles">+ Kelola Konten</a>
                            <a href="admin.php?view=orders">+ Lihat Laporan</a>
                            <a href="admin.php?view=customers">+ Data Pembeli</a>
                        </div>

                        <div class="card panel clean-panel">
                            <div class="panel-title-row compact">
                                <h3>Produk Terlaris</h3>
                                <a href="admin.php?view=products">Detail ›</a>
                            </div>

                            <div class="chart-box donut-box">
                                <canvas id="topProductChart"></canvas>
                            </div>

                            <div class="top-product-list">
                                <?php if (!$topProducts): ?>
                                    <p class="muted">Belum ada data produk terjual.</p>
                                <?php endif; ?>

                                <?php foreach ($topProducts as $idx => $p): ?>
                                    <div>
                                        <span><?= ($idx + 1) ?>. <?= e($p['product_name']) ?></span>
                                        <strong><?= (int)$p['qty_total'] ?>x</strong>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card panel clean-panel">
                    <div class="panel-title-row compact">
                        <h3>Revenue 6 Bulan Terakhir</h3>
                        <span class="muted">Data transaksi bulanan</span>
                    </div>
                    <div class="chart-box monthly-box">
                        <canvas id="monthlyRevenueChart"></canvas>
                    </div>
                </div>

                <div class="bottom-metric-grid">
                    <div class="card mini-card">
                        <span>Order Diproses</span>
                        <strong><?= (int)$statusCounts['processing'] ?></strong>
                        <small>Perlu dicek ›</small>
                    </div>

                    <div class="card mini-card">
                        <span>Order Pending</span>
                        <strong><?= (int)$pendingOrders ?></strong>
                        <small>Proses hari ini ›</small>
                    </div>

                    <div class="card mini-card">
                        <span>Kupon Terpakai</span>
                        <strong><?= (int)$couponUsed ?></strong>
                        <small>Total klaim ›</small>
                    </div>
                </div>

                <div class="card panel clean-panel status-distribution">
                    <div class="panel-title-row compact">
                        <h3>Distribusi Status Order</h3>
                        <a href="admin.php?view=orders">Kelola Order ›</a>
                    </div>

                    <?php $statusTotal = max(1, array_sum($statusCounts)); ?>

                    <?php foreach ($statusCounts as $key => $count): ?>
                        <?php $percent = round(($count / $statusTotal) * 100); ?>

                        <div class="progress-row">
                            <div>
                                <span><?= e(ucfirst($key)) ?></span>
                                <b><?= (int)$count ?></b>
                            </div>
                            <div class="progress-track">
                                <span style="width:<?= (int)$percent ?>%"></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($view === 'settings'): ?>
            <form method="post" enctype="multipart/form-data" class="card panel form-grid soft-form-panel">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_settings">

                <h2>Identitas Aplikasi</h2>

                <label>
                    Nama Aplikasi
                    <input name="app_name" value="<?= e(setting('app_name', '')) ?>">
                </label>

                <label>
                    URL Logo Aplikasi
                    <input name="app_logo" value="<?= e(setting('app_logo', '')) ?>">
                    <span class="upload-help">Bisa pakai URL gambar atau upload file di bawah ini.</span>
                </label>

                <label>
                    Upload Logo Aplikasi
                    <input type="file" name="app_logo_file" accept="image/*">
                </label>

                <label>
                    URL Favicon
                    <input name="app_favicon" value="<?= e(setting('app_favicon', '')) ?>">
                </label>

                <label>
                    Upload Favicon
                    <input type="file" name="app_favicon_file" accept="image/*">
                </label>

                <label>
                    URL Aplikasi
                    <input name="app_url" value="<?= e(setting('app_url', '')) ?>">
                </label>

                <h2>Profil Toko</h2>

                <label>
                    Nama Toko
                    <input name="store_name" value="<?= e(setting('store_name', '')) ?>">
                </label>

                <label>
                    Deskripsi Toko
                    <textarea name="store_description"><?= e(setting('store_description', '')) ?></textarea>
                </label>

                <label>
                    URL Logo Toko
                    <input name="store_logo" value="<?= e(setting('store_logo', '')) ?>">
                </label>

                <label>
                    Upload Logo Toko
                    <input type="file" name="store_logo_file" accept="image/*">
                </label>

                <label>
                    Nomor WhatsApp
                    <input name="store_whatsapp" value="<?= e(setting('store_whatsapp', '')) ?>">
                </label>

                <label>
                    Email
                    <input name="store_email" value="<?= e(setting('store_email', '')) ?>">
                </label>

                <label>
                    Alamat
                    <textarea name="store_address"><?= e(setting('store_address', '')) ?></textarea>
                </label>

                <h2>RajaOngkir / Ongkir Otomatis <span class="muted">(opsional)</span></h2>

                <div class="setting-section-note">
                    Aktifkan hanya kalau toko sudah punya API Key RajaOngkir/Komerce. Kalau dinonaktifkan, checkout tetap memakai ongkir manual.
                </div>

                <label>
                    Status RajaOngkir
                    <select name="rajaongkir_enabled">
                        <option value="0" <?= setting('rajaongkir_enabled', '0') !== '1' ? 'selected' : '' ?>>Nonaktif - pakai ongkir manual</option>
                        <option value="1" <?= setting('rajaongkir_enabled', '0') === '1' ? 'selected' : '' ?>>Aktif - cek ongkir otomatis</option>
                    </select>
                </label>

                <label>
                    API Key RajaOngkir / Komerce
                    <input name="rajaongkir_api_key" value="<?= e(setting('rajaongkir_api_key', '')) ?>" placeholder="Masukkan API key">
                    <span class="upload-help">API key disimpan di server dan tidak dikirim ke browser.</span>
                </label>

                <label>
                    Base URL API
                    <input name="rajaongkir_base_url" value="<?= e(setting('rajaongkir_base_url', 'https://rajaongkir.komerce.id/api/v1/')) ?>">
                </label>

                <label>
                    Cari Origin Toko / Gudang
                    <div style="display:grid;grid-template-columns:minmax(0,1fr)120px;gap:8px;">
                        <input id="adminRajaOriginSearch" type="text" placeholder="Contoh: Semarang / Tembalang / Bandung">
                        <button class="btn btn-light" type="button" onclick="searchAdminRajaOrigin()">Cari ID</button>
                    </div>
                    <span class="upload-help">Origin ID tidak ada di dashboard. Cari lokasi asal toko dari API, lalu klik hasilnya.</span>
                </label>

                <div class="span-2" id="adminRajaOriginResults" style="display:grid;gap:8px;"></div>

                <label>
                    Origin ID Toko / Gudang
                    <input name="rajaongkir_origin_id" value="<?= e(setting('rajaongkir_origin_id', '')) ?>" placeholder="Contoh: 17580">
                    <span class="upload-help">Terisi otomatis kalau pilih dari hasil pencarian origin.</span>
                </label>

                <label>
                    Label Origin Toko
                    <input name="rajaongkir_origin_label" value="<?= e(setting('rajaongkir_origin_label', '')) ?>" placeholder="Contoh: Tembalang, Semarang, Jawa Tengah">
                </label>

                <label>
                    Kurir Aktif
                    <input name="rajaongkir_couriers" value="<?= e(setting('rajaongkir_couriers', 'jne,jnt,sicepat,pos,tiki')) ?>" placeholder="jne,jnt,sicepat,pos,tiki">
                    <span class="upload-help">Pisahkan dengan koma. Contoh: jne,jnt,sicepat,pos,tiki</span>
                </label>

                <label>
                    Mode Harga
                    <select name="rajaongkir_price_mode">
                        <option value="lowest" <?= setting('rajaongkir_price_mode', 'lowest') === 'lowest' ? 'selected' : '' ?>>Lowest</option>
                        <option value="highest" <?= setting('rajaongkir_price_mode', 'lowest') === 'highest' ? 'selected' : '' ?>>Highest</option>
                    </select>
                </label>

                <label>
                    Berat Default Produk Tanpa Berat
                    <input type="number" min="1" name="rajaongkir_default_weight_gram" value="<?= e(setting('rajaongkir_default_weight_gram', '1000')) ?>">
                    <span class="upload-help">Dalam gram. Dipakai kalau produk belum punya berat.</span>
                </label>

                <label>
                    Markup Ongkir Flat
                    <input type="number" min="0" step="1" name="rajaongkir_markup_flat" value="<?= e(setting('rajaongkir_markup_flat', '0')) ?>">
                    <span class="upload-help">Opsional. Tambahan ongkir tetap, contoh 2000.</span>
                </label>

                <label>
                    Fallback Ongkir Manual
                    <select name="rajaongkir_fallback_manual_enabled">
                        <option value="1" <?= setting('rajaongkir_fallback_manual_enabled', '1') === '1' ? 'selected' : '' ?>>Tampilkan ongkir manual juga</option>
                        <option value="0" <?= setting('rajaongkir_fallback_manual_enabled', '1') !== '1' ? 'selected' : '' ?>>Sembunyikan ongkir manual saat RajaOngkir aktif</option>
                    </select>
                </label>

                <h2>Warna & SEO</h2>

                <label>
                    Warna Utama
                    <input type="color" name="primary_color" value="<?= e(setting('primary_color', '#10b981')) ?>">
                </label>

                <label>
                    Warna Kedua
                    <input type="color" name="secondary_color" value="<?= e(setting('secondary_color', '#0f172a')) ?>">
                </label>

                <label>
                    Warna Aksen Orange
                    <input type="color" name="accent_color" value="<?= e(setting('accent_color', '#fb923c')) ?>">
                </label>

                <label>
                    Warna Background
                    <input type="color" name="background_color" value="<?= e(setting('background_color', '#f6fbf8')) ?>">
                </label>

                <h2>Gradient Card Dashboard</h2>

                <div class="color-pair-grid">
                    <div class="color-pair-box">
                        <strong>Card 1 - Order Hari Ini</strong>
                        <label>
                            Start
                            <input type="color" name="dashboard_card_1_start" value="<?= e(setting('dashboard_card_1_start', '#38bdf8')) ?>">
                        </label>
                        <label>
                            End
                            <input type="color" name="dashboard_card_1_end" value="<?= e(setting('dashboard_card_1_end', '#60a5fa')) ?>">
                        </label>
                        <div class="gradient-preview" style="background:linear-gradient(135deg, <?= e(setting('dashboard_card_1_start', '#38bdf8')) ?>, <?= e(setting('dashboard_card_1_end', '#60a5fa')) ?>)"></div>
                    </div>

                    <div class="color-pair-box">
                        <strong>Card 2 - Total Order</strong>
                        <label>
                            Start
                            <input type="color" name="dashboard_card_2_start" value="<?= e(setting('dashboard_card_2_start', '#a78bfa')) ?>">
                        </label>
                        <label>
                            End
                            <input type="color" name="dashboard_card_2_end" value="<?= e(setting('dashboard_card_2_end', '#7c3aed')) ?>">
                        </label>
                        <div class="gradient-preview" style="background:linear-gradient(135deg, <?= e(setting('dashboard_card_2_start', '#a78bfa')) ?>, <?= e(setting('dashboard_card_2_end', '#7c3aed')) ?>)"></div>
                    </div>

                    <div class="color-pair-box">
                        <strong>Card 3 - Pendapatan</strong>
                        <label>
                            Start
                            <input type="color" name="dashboard_card_3_start" value="<?= e(setting('dashboard_card_3_start', '#f472b6')) ?>">
                        </label>
                        <label>
                            End
                            <input type="color" name="dashboard_card_3_end" value="<?= e(setting('dashboard_card_3_end', '#ec4899')) ?>">
                        </label>
                        <div class="gradient-preview" style="background:linear-gradient(135deg, <?= e(setting('dashboard_card_3_start', '#f472b6')) ?>, <?= e(setting('dashboard_card_3_end', '#ec4899')) ?>)"></div>
                    </div>

                    <div class="color-pair-box">
                        <strong>Card 4 - Katalog Aktif</strong>
                        <label>
                            Start
                            <input type="color" name="dashboard_card_4_start" value="<?= e(setting('dashboard_card_4_start', '#4ade80')) ?>">
                        </label>
                        <label>
                            End
                            <input type="color" name="dashboard_card_4_end" value="<?= e(setting('dashboard_card_4_end', '#10b981')) ?>">
                        </label>
                        <div class="gradient-preview" style="background:linear-gradient(135deg, <?= e(setting('dashboard_card_4_start', '#4ade80')) ?>, <?= e(setting('dashboard_card_4_end', '#10b981')) ?>)"></div>
                    </div>
                </div>

                <h2>Tampilan Toko Depan</h2>

                <div class="setting-section-note">
                    Semua pengaturan ini opsional. Kalau dikosongkan, toko tetap berjalan dengan tampilan default.
                </div>

                <label>
                    Judul Hero / Welcome
                    <input name="storefront_hero_title" value="<?= e(setting('storefront_hero_title', 'Selamat Datang!')) ?>">
                </label>

                <label>
                    Deskripsi Hero / Welcome
                    <textarea name="storefront_hero_subtitle"><?= e(setting('storefront_hero_subtitle', 'Pilih produk yang Anda inginkan dan pesan langsung via WhatsApp.')) ?></textarea>
                </label>

                <label>
                    Warna Hero Start
                    <input type="color" name="storefront_hero_start" value="<?= e(setting('storefront_hero_start', '#10b981')) ?>">
                </label>

                <label>
                    Warna Hero End
                    <input type="color" name="storefront_hero_end" value="<?= e(setting('storefront_hero_end', '#0f766e')) ?>">
                </label>

                <label>
                    Warna Hero Aksen
                    <input type="color" name="storefront_hero_accent" value="<?= e(setting('storefront_hero_accent', '#fb923c')) ?>">
                </label>

                <h2>Banner Slider</h2>

                <label>
                    Tampilkan Banner Slider?
                    <select name="show_banner_slider">
                        <option value="1" <?= setting('show_banner_slider', '0') === '1' ? 'selected' : '' ?>>Tampilkan</option>
                        <option value="0" <?= setting('show_banner_slider', '0') !== '1' ? 'selected' : '' ?>>Sembunyikan</option>
                    </select>
                </label>

                <label>
                    URL Gambar Banner Slider
                    <input name="banner_slider_image" value="<?= e(setting('banner_slider_image', '')) ?>" placeholder="https://domain.com/banner.jpg">
                </label>

                <label>
                    Upload Gambar Banner Slider
                    <input type="file" name="banner_slider_image_file" accept="image/*">
                    <span class="upload-help">Kalau upload file, URL gambar akan otomatis diganti path upload.</span>
                </label>

                <label>
                    Link Tujuan Banner Slider
                    <input name="banner_slider_link" value="<?= e(setting('banner_slider_link', '')) ?>" placeholder="#produk / https://domain.com/promo">
                </label>

                <h2>Banner Tengah / Promo</h2>

                <label>
                    Tampilkan Banner Tengah?
                    <select name="show_banner_mid">
                        <option value="1" <?= setting('show_banner_mid', '0') === '1' ? 'selected' : '' ?>>Tampilkan</option>
                        <option value="0" <?= setting('show_banner_mid', '0') !== '1' ? 'selected' : '' ?>>Sembunyikan</option>
                    </select>
                </label>

                <label>
                    URL Gambar Banner Tengah
                    <input name="banner_mid_image" value="<?= e(setting('banner_mid_image', '')) ?>" placeholder="https://domain.com/banner-promo.jpg">
                </label>

                <label>
                    Upload Gambar Banner Tengah
                    <input type="file" name="banner_mid_image_file" accept="image/*">
                </label>

                <label>
                    Link Tujuan Banner Tengah
                    <input name="banner_mid_link" value="<?= e(setting('banner_mid_link', '')) ?>" placeholder="#produk / https://domain.com/promo">
                </label>

                <h2>Teks Berjalan / Announcement Bar</h2>

                <label>
                    Tampilkan Teks Berjalan?
                    <select name="show_announcement">
                        <option value="1" <?= setting('show_announcement', '0') === '1' ? 'selected' : '' ?>>Tampilkan</option>
                        <option value="0" <?= setting('show_announcement', '0') !== '1' ? 'selected' : '' ?>>Sembunyikan</option>
                    </select>
                </label>

                <label>
                    Teks Berjalan
                    <input name="announcement_text" value="<?= e(setting('announcement_text', '')) ?>" placeholder="Promo beli 1 gratis 1!">
                </label>

                <label>
                    Link Teks Berjalan
                    <input name="announcement_link" value="<?= e(setting('announcement_link', '')) ?>" placeholder="#produk / https://domain.com/promo">
                </label>

                <label>
                    Warna Marquee Start
                    <input type="color" name="announcement_bg_start" value="<?= e(setting('announcement_bg_start', '#fb923c')) ?>">
                </label>

                <label>
                    Warna Marquee End
                    <input type="color" name="announcement_bg_end" value="<?= e(setting('announcement_bg_end', '#f59e0b')) ?>">
                </label>

                <label>
                    Warna Teks Marquee
                    <input type="color" name="announcement_text_color" value="<?= e(setting('announcement_text_color', '#ffffff')) ?>">
                </label>

                <h2>Pop-up Promo / Welcome Message</h2>

                <label>
                    Status Pop-up
                    <select name="show_popup">
                        <option value="1" <?= setting('show_popup', '0') === '1' ? 'selected' : '' ?>>Aktif</option>
                        <option value="0" <?= setting('show_popup', '0') !== '1' ? 'selected' : '' ?>>Mati</option>
                    </select>
                </label>

                <label>
                    URL Gambar Pop-up
                    <input name="popup_image" value="<?= e(setting('popup_image', '')) ?>" placeholder="https://domain.com/popup.jpg">
                </label>

                <label>
                    Upload Gambar Pop-up
                    <input type="file" name="popup_image_file" accept="image/*">
                </label>

                <label>
                    Deskripsi Singkat Pop-up
                    <textarea name="popup_desc"><?= e(setting('popup_desc', '')) ?></textarea>
                </label>

                <label>
                    Teks Tombol Pop-up
                    <input name="popup_btn_text" value="<?= e(setting('popup_btn_text', 'Lihat Promo')) ?>">
                </label>

                <label>
                    Link Tombol Pop-up
                    <input name="popup_btn_link" value="<?= e(setting('popup_btn_link', '#produk')) ?>">
                </label>

                <h2>Profil Tentang Kami & Blog</h2>

                <label>
                    Teks Tentang Kami
                    <textarea name="about_us_text"><?= e(setting('about_us_text', '')) ?></textarea>
                    <span class="upload-help">Boleh pakai teks biasa. HTML sederhana seperti &lt;b&gt; juga bisa.</span>
                </label>

                <label>
                    Tampilkan Section Blog di Halaman Depan?
                    <select name="show_blog_section">
                        <option value="1" <?= setting('show_blog_section', '1') === '1' ? 'selected' : '' ?>>Tampilkan</option>
                        <option value="0" <?= setting('show_blog_section', '1') !== '1' ? 'selected' : '' ?>>Sembunyikan</option>
                    </select>
                </label>

                <div class="setting-divider"></div>

                <label>
                    SEO Title
                    <input name="meta_title" value="<?= e(setting('meta_title', '')) ?>">
                </label>

                <label>
                    SEO Description
                    <textarea name="meta_description"><?= e(setting('meta_description', '')) ?></textarea>
                </label>

                <h2>Pembayaran & Biaya</h2>

                <label>
                    Info Bank
                    <textarea name="bank_info"><?= e(setting('bank_info', '')) ?></textarea>
                </label>

                <label>
                    URL QRIS
                    <input name="qris_image" value="<?= e(setting('qris_image', '')) ?>">
                </label>

                <label>
                    Upload QRIS
                    <input type="file" name="qris_image_file" accept="image/*">
                </label>

                <label>
                    PPN Persen
                    <input type="number" step="0.01" name="tax_percent" value="<?= e(setting('tax_percent', '0')) ?>">
                </label>

                <label>
                    Biaya Layanan
                    <input type="number" step="1" name="service_charge" value="<?= e(setting('service_charge', '0')) ?>">
                </label>


                <h2>Membership & Point Reward</h2>

                <div class="setting-section-note">
                    Setting ini menyiapkan aturan point reward. Logic otomatis point akan dipakai oleh POS, checkout web, Data Pembeli, dan Member Area pada update berikutnya.
                </div>

                <label>
                    Status Point Reward
                    <select name="points_enabled">
                        <option value="1" <?= setting('points_enabled', '1') === '1' ? 'selected' : '' ?>>Aktif</option>
                        <option value="0" <?= setting('points_enabled', '1') !== '1' ? 'selected' : '' ?>>Nonaktif</option>
                    </select>
                </label>

                <label>
                    Nama Label Point
                    <input name="points_label" value="<?= e(setting('points_label', 'Point Reward')) ?>" placeholder="Point Reward / Poin Member">
                </label>

                <label>
                    Nominal Belanja untuk Dapat Point
                    <input type="number" step="1" name="points_earn_amount" value="<?= e(setting('points_earn_amount', '10000')) ?>" placeholder="10000">
                    <span class="upload-help">Contoh: 10000 berarti setiap belanja Rp 10.000 akan dapat point.</span>
                </label>

                <label>
                    Jumlah Point Didapat
                    <input type="number" step="1" name="points_earn_value" value="<?= e(setting('points_earn_value', '1')) ?>" placeholder="1">
                    <span class="upload-help">Contoh: Rp 10.000 = 1 point.</span>
                </label>

                <label>
                    Nilai Rupiah per 1 Point
                    <input type="number" step="1" name="points_currency_value" value="<?= e(setting('points_currency_value', '100')) ?>" placeholder="100">
                    <span class="upload-help">Contoh: 1 point = Rp 100 saat dipakai sebagai diskon.</span>
                </label>

                <label>
                    Minimal Point untuk Redeem
                    <input type="number" step="1" name="points_min_redeem" value="<?= e(setting('points_min_redeem', '50')) ?>" placeholder="50">
                </label>

                <label>
                    Maksimal Redeem dari Total Belanja (%)
                    <input type="number" step="1" min="0" max="100" name="points_max_redeem_percent" value="<?= e(setting('points_max_redeem_percent', '30')) ?>" placeholder="30">
                    <span class="upload-help">Contoh: 30 berarti point maksimal hanya bisa memotong 30% dari total belanja.</span>
                </label>

                <label>
                    Point dari Checkout Web
                    <select name="points_earn_from_web">
                        <option value="1" <?= setting('points_earn_from_web', '1') === '1' ? 'selected' : '' ?>>Aktif</option>
                        <option value="0" <?= setting('points_earn_from_web', '1') !== '1' ? 'selected' : '' ?>>Nonaktif</option>
                    </select>
                </label>

                <label>
                    Point dari POS Kasir
                    <select name="points_earn_from_pos">
                        <option value="1" <?= setting('points_earn_from_pos', '1') === '1' ? 'selected' : '' ?>>Aktif</option>
                        <option value="0" <?= setting('points_earn_from_pos', '1') !== '1' ? 'selected' : '' ?>>Nonaktif</option>
                    </select>
                </label>

                <label>
                    Point Diberikan Saat
                    <select name="points_award_when">
                        <option value="completed" <?= setting('points_award_when', 'completed') === 'completed' ? 'selected' : '' ?>>Order Completed / Lunas</option>
                        <option value="paid" <?= setting('points_award_when', 'completed') === 'paid' ? 'selected' : '' ?>>Payment Status Paid</option>
                    </select>
                </label>

                <label>
                    Redeem Point sebagai Diskon
                    <select name="points_redeem_enabled">
                        <option value="1" <?= setting('points_redeem_enabled', '1') === '1' ? 'selected' : '' ?>>Aktif</option>
                        <option value="0" <?= setting('points_redeem_enabled', '1') !== '1' ? 'selected' : '' ?>>Nonaktif</option>
                    </select>
                </label>

                <label>
                    Masa Berlaku Point (hari)
                    <input type="number" step="1" min="0" name="points_expiry_days" value="<?= e(setting('points_expiry_days', '0')) ?>" placeholder="0">
                    <span class="upload-help">Isi 0 kalau point tidak expired.</span>
                </label>

                <label>
                    Admin Bisa Adjust Point Manual
                    <select name="points_manual_adjustment_enabled">
                        <option value="1" <?= setting('points_manual_adjustment_enabled', '1') === '1' ? 'selected' : '' ?>>Aktif</option>
                        <option value="0" <?= setting('points_manual_adjustment_enabled', '1') !== '1' ? 'selected' : '' ?>>Nonaktif</option>
                    </select>
                </label>

                <div class="auto-field-note">
                    <strong>Contoh hitungan default</strong>
                    <span>Belanja Rp 100.000, rule Rp 10.000 = 1 point, pembeli dapat 10 point. Jika 1 point = Rp 100, maka 10 point bernilai Rp 1.000.</span>
                </div>


                <h2>Tracking Pixel</h2>

                <label>
                    Facebook Pixel ID
                    <input name="facebook_pixel_id" value="<?= e(setting('facebook_pixel_id', '')) ?>" placeholder="ID FB Pixel">
                </label>

                <label>
                    TikTok Pixel ID
                    <input name="tiktok_pixel_id" value="<?= e(setting('tiktok_pixel_id', '')) ?>" placeholder="ID TikTok">
                </label>

                <label>
                    Google Analytics ID
                    <input name="google_analytics_id" value="<?= e(setting('google_analytics_id', '')) ?>" placeholder="G-XXXXXXX">
                </label>

                <div class="auto-field-note">
                    <strong>Aman untuk iklan</strong>
                    <span>Pixel akan otomatis dipasang di halaman toko depan kalau ID-nya diisi.</span>
                </div>

                <h2>Legal & Kebijakan Toko</h2>

                <label>
                    Terms of Service
                    <select name="show_terms">
                        <option value="1" <?= setting('show_terms', '0') === '1' ? 'selected' : '' ?>>Tampilkan</option>
                        <option value="0" <?= setting('show_terms', '0') !== '1' ? 'selected' : '' ?>>Sembunyikan</option>
                    </select>
                </label>

                <label>
                    Privacy Policy
                    <select name="show_privacy">
                        <option value="1" <?= setting('show_privacy', '0') === '1' ? 'selected' : '' ?>>Tampilkan</option>
                        <option value="0" <?= setting('show_privacy', '0') !== '1' ? 'selected' : '' ?>>Sembunyikan</option>
                    </select>
                </label>

                <label>
                    Isi Terms of Service
                    <textarea name="terms_text" placeholder="Syarat dan ketentuan toko..."><?= e(setting('terms_text', '')) ?></textarea>
                </label>

                <label>
                    Isi Privacy Policy
                    <textarea name="privacy_text" placeholder="Kebijakan privasi toko..."><?= e(setting('privacy_text', '')) ?></textarea>
                </label>

                <h2>Video Panduan (Playlist)</h2>

                <?php $videoPlaylistRows = admin_json_setting_array('video_playlist_json'); ?>
                <div class="repeater-wrap" id="videoRepeater">
                    <?php if (!$videoPlaylistRows): ?>
                        <?php $videoPlaylistRows = [['url' => '']]; ?>
                    <?php endif; ?>

                    <?php foreach ($videoPlaylistRows as $idx => $video): ?>
                        <div class="repeater-row video-row">
                            <div class="repeater-number"><?= (int)($idx + 1) ?></div>
                            <input name="video_playlist[<?= (int)$idx ?>][url]" value="<?= e($video['url'] ?? '') ?>" placeholder="https://youtube.com/watch?v=...">
                            <button class="repeater-remove" type="button" onclick="removeRepeaterRow(this)">🗑</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button class="repeater-add" type="button" onclick="addVideoRow()">⊕ Tambah Video</button>

                <h2>Sosial Media Toko</h2>

                <?php $socialRows = admin_json_setting_array('social_links_json'); ?>
                <div class="repeater-wrap" id="socialRepeater">
                    <?php if (!$socialRows): ?>
                        <?php $socialRows = [['platform' => 'Instagram', 'url' => '']]; ?>
                    <?php endif; ?>

                    <?php foreach ($socialRows as $idx => $social): ?>
                        <div class="repeater-row">
                            <div class="repeater-number"><?= (int)($idx + 1) ?></div>
                            <select name="social_links[<?= (int)$idx ?>][platform]">
                                <?php $platform = $social['platform'] ?? 'Instagram'; ?>
                                <?php foreach (['Instagram','TikTok','Facebook','YouTube','WhatsApp','Website','Marketplace','Lainnya'] as $option): ?>
                                    <option value="<?= e($option) ?>" <?= $platform === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input name="social_links[<?= (int)$idx ?>][url]" value="<?= e($social['url'] ?? '') ?>" placeholder="https://...">
                            <button class="repeater-remove" type="button" onclick="removeRepeaterRow(this)">🗑</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <button class="repeater-add" type="button" onclick="addSocialRow()">⊕ Tambah Sosmed</button>

                <h2>Footer</h2>

                <label>
                    Teks Footer Toko
                    <input name="footer_text" value="<?= e(setting('footer_text', '')) ?>">
                </label>

                <label>
                    Teks Footer Login
                    <input name="login_footer_text" value="<?= e(setting('login_footer_text', 'Powered by {app_name} — simple, fast, and ready to sell.')) ?>" placeholder="Powered by {app_name} — simple, fast, and ready to sell.">
                    <span class="upload-help">Variable: {app_name}, {store_name}, {year}</span>
                </label>

                <button class="btn btn-primary full-span" type="submit">Simpan Pengaturan</button>
            </form>

            <form method="post" class="card panel form-grid soft-form-panel password-card">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="change_password">

                <h2>Ganti Password</h2>

                <label>
                    Password Lama
                    <input type="password" name="old_password" autocomplete="current-password" required>
                </label>

                <label>
                    Password Baru
                    <input type="password" name="new_password" autocomplete="new-password" required>
                </label>

                <button class="btn btn-primary" type="submit">Update Password</button>
            </form>
        <?php endif; ?>

        <?php if ($view === 'categories'): ?>
            <div class="card panel clean-panel">
                <div class="panel-title-row compact product-toolbar">
                    <div>
                        <h2>Kategori Produk</h2>
                        <p class="muted">Kelola kategori utama dan sub kategori. Nama, slug, induk, urutan, dan status bisa diedit kapan saja.</p>
                    </div>
                    <button class="btn btn-primary" type="button" onclick="openCategoryModal()">+ Tambah Kategori</button>
                </div>
            </div>

            <div class="card panel table-wrap clean-panel">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nama</th>
                            <th>Induk</th>
                            <th>Slug</th>
                            <th>Urutan</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$categories): ?>
                            <tr>
                                <td colspan="6">Belum ada kategori.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($categories as $c): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($c['parent_id'])): ?>
                                        <span style="color:#94a3b8;font-weight:900;margin-right:4px;">↳</span><?= e($c['name']) ?>
                                    <?php else: ?>
                                        <strong><?= e($c['name']) ?></strong>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($c['parent_name'] ?: 'Kategori Utama') ?></td>
                                <td><?= e($c['slug']) ?></td>
                                <td><?= (int)($c['sort_order'] ?? 0) ?></td>
                                <td><?= $c['is_active'] ? 'Aktif' : 'Nonaktif' ?></td>
                                <td>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                        <button class="btn" type="button" onclick='openCategoryModal(<?= json_encode([
                                            "id" => (int)$c["id"],
                                            "name" => (string)$c["name"],
                                            "slug" => (string)$c["slug"],
                                            "parent_id" => $c["parent_id"] !== null ? (int)$c["parent_id"] : "",
                                            "sort_order" => (int)($c["sort_order"] ?? 0),
                                            "is_active" => !empty($c["is_active"]) ? 1 : 0,
                                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>Edit</button>

                                        <form method="post" onsubmit="return confirm('Hapus kategori?')" style="display:inline-block;">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_category">
                                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                            <button class="btn" type="submit">Hapus</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="product-modal-overlay" id="categoryModal" aria-hidden="true">
                <div class="product-modal" role="dialog" aria-modal="true" aria-labelledby="categoryModalTitle">
                    <div class="product-modal-head">
                        <h2 id="categoryModalTitle">Tambah Kategori</h2>
                        <button class="product-modal-close" type="button" onclick="closeCategoryModal()">×</button>
                    </div>

                    <form method="post" class="product-modal-body">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add_category">
                        <input type="hidden" name="id" value="">

                        <div class="product-modal-grid">
                            <label>
                                Nama Kategori
                                <input name="name" required placeholder="Contoh: Sepatu Pria">
                            </label>

                            <label>
                                Slug <span class="muted">(opsional)</span>
                                <input name="slug" placeholder="otomatis dari nama, contoh: sepatu-pria">
                                <span class="upload-help">Bisa diedit jika salah ketik. Sistem tetap mencegah slug dobel.</span>
                            </label>

                            <label>
                                Induk Kategori
                                <select name="parent_id">
                                    <option value="">Kategori Utama / Tanpa Induk</option>
                                    <?php foreach ($categories as $parentCat): ?>
                                        <?php if (!empty($parentCat['parent_id'])) continue; ?>
                                        <option value="<?= (int)$parentCat['id'] ?>"><?= e($parentCat['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="upload-help">Kosongkan jika kategori ini mau dijadikan kategori utama/induk.</span>
                            </label>

                            <label>
                                Urutan <span class="muted">(opsional)</span>
                                <input type="number" name="sort_order" min="0" step="1" placeholder="Kosongkan untuk otomatis">
                            </label>

                            <div class="auto-field-note span-2">
                                <strong>Slug, induk, dan urutan</strong>
                                <span>Slug boleh dikosongkan agar otomatis dari nama. Induk kategori dipakai untuk membuat sub kategori. Urutan menentukan posisi tampil di admin dan toko.</span>
                            </div>

                            <label>
                                <input type="checkbox" name="is_active" value="1" checked>
                                Aktif
                            </label>
                        </div>

                        <div class="product-modal-actions">
                            <button class="btn" type="button" onclick="closeCategoryModal()">Batal</button>
                            <button class="btn btn-primary" type="submit" id="categorySubmitButton">Simpan Kategori</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'products'): ?>
            <div class="card panel clean-panel">
                <div class="panel-title-row compact product-toolbar">
                    <div>
                        <h2>Katalog Produk</h2>
                        <p class="muted">Kelola produk, berat, varian warna/size, harga, dan stok. Varian bersifat opsional per produk.</p>
                    </div>
                    <button class="btn btn-primary" type="button" onclick="openProductModal()">+ Tambah Produk</button>
                </div>
            </div>

            <div class="card panel clean-panel">
                <div class="panel-title-row compact">
                    <div>
                        <h2>Import Produk via CSV</h2>
                        <p class="muted">Cocok kalau produk sudah banyak. Bisa import produk fisik, digital, Canva/Drive, HTML content, dan lisensi stock. SKU yang sama akan di-update.</p>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <a class="btn" href="admin.php?view=products&download=products_csv_export">Export Produk CSV</a>
                        <a class="btn btn-primary" href="admin.php?view=products&download=product_csv_template">Download Template CSV</a>
                    </div>
                </div>

                <form method="post" enctype="multipart/form-data" class="form-grid soft-form-panel" style="box-shadow:none;border:0;padding:0;margin-top:16px;">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="import_products_csv">

                    <label>
                        Upload File CSV
                        <input type="file" name="csv_file" accept=".csv,text/csv" required>
                        <span class="upload-help">Kolom wajib: nama_produk dan harga. Untuk stok kode, pisahkan kode dengan tanda | dalam kolom stok_kode.</span>
                    </label>

                    <div class="auto-field-note">
                        <strong>Format kolom CSV</strong>
                        <span>sku, nama_produk, kategori, sub_kategori, harga, harga_promo, stok, tipe_stok, satuan, berat_gram, badge, deskripsi, url_gambar, status, tipe_produk, delivery_type, judul_akses, url_akses, path_file, konten_html, instruksi_akses, label_tombol, auto_deliver, expired_hari, alert_stok_kode, stok_kode</span>
                    </div>

                    <button class="btn btn-primary" type="submit">Import Produk</button>
                </form>
            </div>

            <div class="card panel table-wrap clean-panel">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Produk & Kategori</th>
                            <th>Harga</th>
                            <th>Stok / Varian</th>
                            <th>Berat</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$products): ?>
                            <tr>
                                <td colspan="5">Belum ada produk.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($products as $p): ?>
                            <?php $variantTotal = $productVariantCounts[(int)$p['id']] ?? 0; ?>
                            <tr>
                                <td>
                                    <div class="product-thumb-row">
                                        <?php if (!empty($p['main_image'])): ?>
                                            <img src="<?= e($p['main_image']) ?>" alt="<?= e($p['name']) ?>">
                                        <?php else: ?>
                                            <span class="product-badge-soft">IMG</span>
                                        <?php endif; ?>
                                        <div>
                                            <strong><?= e($p['name']) ?></strong>
                                            <?php if (!empty($p['badge'])): ?>
                                                <span class="product-badge-soft"><?= e($p['badge']) ?></span>
                                            <?php endif; ?>
                                            <span class="product-muted">Kategori: <?= e($p['category_name'] ?? '-') ?></span>
                                            <span class="product-muted">SKU: <?= e($p['sku'] ?: '-') ?></span>
                                            <?php if (array_key_exists('product_type', $p)): ?>
                                                <div class="product-type-line">
                                                    <span class="product-badge-soft"><?= e(strtoupper($p['product_type'] ?: 'physical')) ?></span>
                                                    <?php if (!empty($p['delivery_type']) && $p['delivery_type'] !== 'none'): ?>
                                                        <span class="product-badge-soft"><?= e($p['delivery_type']) ?></span>
                                                    <?php endif; ?>
                                                    <?php if (($p['product_type'] ?? '') === 'bundle'): ?>
                                                        <span class="product-badge-soft"><?= count($productBundleItemsMap[(int)$p['id']] ?? []) ?> item bundle</span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <strong><?= rupiah($p['sale_price'] ?: $p['price']) ?></strong>
                                    <?php if (!empty($p['sale_price'])): ?>
                                        <span class="product-muted">Harga normal: <?= rupiah($p['price']) ?></span>
                                    <?php endif; ?>
                                    <span class="product-muted"><?= e($p['unit'] ?: 'unit') ?></span>
                                </td>
                                <td>
                                    <span class="product-badge-soft">
                                        <?= e($p['stock_type'] === 'unlimited' ? 'Unlimited' : (($p['stock'] ?? 0) . ' stok')) ?>
                                    </span>
                                    <span class="product-muted"><?= (int)$variantTotal ?> varian</span>
                                    <?php if (($p['product_type'] ?? '') === 'license'): ?>
                                        <?php $licenseSummary = $productLicenseStockCounts[(int)$p['id']] ?? ['available' => 0, 'assigned' => 0, 'total' => 0]; ?>
                                        <span class="product-muted">Lisensi tersedia: <?= (int)$licenseSummary['available'] ?> / <?= (int)$licenseSummary['total'] ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (array_key_exists('weight_gram', $p) && $p['weight_gram'] !== null && $p['weight_gram'] !== ''): ?>
                                        <strong><?= (int)$p['weight_gram'] ?> gr</strong>
                                    <?php else: ?>
                                        <span class="muted">Belum diisi</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                                        <button class="btn" type="button" onclick='openProductModal(<?= json_encode($productEditData[(int)$p['id']] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>✎ Edit</button>

                                        <form method="post" onsubmit="return confirm('Hapus produk?')" style="display:inline-block;">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_product">
                                            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                            <button class="btn" type="submit">Hapus</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="product-modal-overlay" id="productModal" aria-hidden="true">
                <div class="product-modal" role="dialog" aria-modal="true" aria-labelledby="productModalTitle">
                    <div class="product-modal-head">
                        <h2 id="productModalTitle">Tambah Produk</h2>
                        <button class="product-modal-close" type="button" onclick="closeProductModal()">×</button>
                    </div>

                    <form method="post" enctype="multipart/form-data" class="product-modal-body">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add_product">
                        <input type="hidden" name="id" value="">

                        <div class="product-modal-grid">
                            <label>
                                Kategori
                                <select name="category_id">
                                    <option value="">Tanpa kategori</option>
                                    <?php foreach ($categories as $c): ?>
                                        <option value="<?= (int)$c['id'] ?>">
                                            <?= !empty($c['parent_id']) ? '↳ ' : '' ?><?= e($c['name']) ?><?= !empty($c['parent_name']) ? ' — ' . e($c['parent_name']) : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>

                            <label>
                                Nama Produk
                                <input name="name" required placeholder="Contoh: iMersGlow Serum">
                            </label>

                            <div class="auto-field-note">
                                <strong>SKU otomatis</strong>
                                <span>Sistem akan membuat SKU unik otomatis kalau kolom SKU dikosongkan.</span>
                            </div>

                            <label>
                                SKU <span class="muted">(opsional)</span>
                                <input name="sku" placeholder="Kosongkan untuk otomatis">
                            </label>

                            <label>
                                Satuan Produk
                                <input name="unit" placeholder="pcs, pack, bottle, box">
                            </label>

                            <label>
                                Harga Utama
                                <input type="number" step="1" name="price" required placeholder="139000">
                            </label>

                            <label>
                                Harga Promo
                                <input type="number" step="1" name="sale_price" placeholder="Opsional">
                            </label>

                            <label>
                                Stok
                                <input type="number" name="stock" placeholder="Contoh: 100">
                            </label>

                            <label>
                                Tipe Stok
                                <select name="stock_type">
                                    <option value="limited">Terbatas</option>
                                    <option value="unlimited">Unlimited</option>
                                </select>
                            </label>

                            <label>
                                Berat Produk (gram)
                                <input type="number" name="weight_gram" min="0" step="1" placeholder="Contoh: 250">
                            </label>

                            <label>
                                Tipe Produk
                                <select name="product_type" id="productTypeSelect" onchange="toggleDigitalDeliveryFields()">
                                    <option value="physical">Produk Fisik</option>
                                    <option value="digital">Produk Digital</option>
                                    <option value="license">Lisensi</option>
                                    <option value="bundle">Bundling</option>
                                    <option value="service">Jasa / Service</option>
                                </select>
                            </label>

                            <div class="digital-delivery-box" id="serviceProductBox">
                                <div class="auto-field-note span-2">
                                    <strong>Produk Jasa / Service</strong>
                                    <span>Cocok untuk jasa website, maintenance, desain, service TV, renovasi, konsultasi, booking, dan layanan lain. Ongkir tidak wajib, stok dibuat unlimited, dan berat diabaikan.</span>
                                </div>
                            </div>

                            <label>
                                Badge <span class="muted">(opsional)</span>
                                <input name="badge" placeholder="Promo, Terlaris, Exclusive">
                            </label>

                            <div class="digital-delivery-box" id="digitalDeliveryBox">
                                <div class="auto-field-note span-2">
                                    <strong>Digital Delivery</strong>
                                    <span>Akses digital baru tampil di member area setelah order approved/completed. Untuk top up, tagihan, atau PPOB manual, gunakan Produk Digital dengan Manual Delivery dan minta data tujuan di catatan checkout.</span>
                                </div>

                                <div class="auto-field-note span-2 manual-delivery-note" id="manualDeliveryNote">
                                    <strong>Contoh Manual Delivery</strong>
                                    <span>Token PLN, pulsa, paket data, e-wallet, dan tagihan: pembeli isi nomor tujuan/ID pelanggan di catatan checkout, lalu admin proses setelah pembayaran dan kirim hasil via WhatsApp.</span>
                                </div>

                                <label>
                                    Metode Delivery
                                    <select name="delivery_type" id="deliveryTypeSelect" onchange="toggleDigitalDeliveryFields()">
                                        <option value="manual">Manual Delivery</option>
                                        <option value="file">Upload File</option>
                                        <option value="external_link">Link Eksternal</option>
                                        <option value="gdrive">Google Drive</option>
                                        <option value="canva">Canva Template</option>
                                        <option value="html_content">HTML / Konten Member Area</option>
                                        <option value="license_stock" data-system-option="1">License Stock</option>
                                        <option value="none" data-system-option="1">Tidak Ada Delivery</option>
                                    </select>
                                </label>

                                <label>
                                    Judul Akses
                                    <input name="delivery_title" placeholder="Contoh: Download Ebook Premium">
                                </label>

                                <label class="digital-url-field">
                                    URL Akses / Link
                                    <input name="delivery_url" placeholder="Google Drive, Canva, Notion, Telegram, dll">
                                </label>

                                <label class="digital-file-field">
                                    Upload File Digital
                                    <input type="file" name="delivery_file_upload">
                                    <span class="upload-help">Support PDF, ZIP, DOCX, XLSX, PPTX, TXT, gambar, video/audio ringan.</span>
                                </label>

                                <label class="digital-file-field">
                                    Path File Manual
                                    <input name="delivery_file_path" placeholder="uploads/digital/file.zip atau URL file">
                                </label>

                                <label>
                                    Label Tombol
                                    <input name="delivery_button_label" value="Buka Akses" placeholder="Download / Buka Link / Pakai Template">
                                </label>

                                <label>
                                    Masa Berlaku Akses (hari)
                                    <input type="number" name="access_expires_days" min="0" step="1" value="0">
                                    <span class="upload-help">Isi 0 kalau tidak expired.</span>
                                </label>

                                <label>
                                    <input type="checkbox" name="digital_auto_deliver" value="1" checked>
                                    Auto deliver saat order completed
                                </label>

                                <label class="span-2 digital-content-field">
                                    Konten HTML / Materi Member Area
                                    <textarea name="delivery_content" rows="6" placeholder="Isi materi, instruksi, embed video, atau konten HTML sederhana."></textarea>
                                </label>

                                <label class="span-2">
                                    Instruksi Penggunaan
                                    <textarea name="delivery_instruction" rows="4" placeholder="Contoh: klik tombol download, ekstrak file ZIP, lalu ikuti panduan."></textarea>
                                </label>
                            </div>

                            <div class="license-stock-box" id="licenseStockBox">
                                <div class="auto-field-note span-2">
                                    <strong>Stok Lisensi</strong>
                                    <span>Satu baris = satu lisensi/kode siap pakai. Pakai tipe ini hanya jika admin sudah punya stok lisensi sebelum order. Untuk token PLN/pulsa/PPOB, pilih Produk Digital + Manual Delivery.</span>
                                </div>

                                <label>
                                    Alert Stok Menipis
                                    <input type="number" name="license_low_stock_alert" min="0" step="1" value="5">
                                </label>

                                <label>
                                    Label Tombol Member
                                    <input name="license_button_label" value="Lihat Lisensi" oninput="document.querySelector('input[name=delivery_button_label]').value=this.value">
                                </label>

                                <label class="span-2">
                                    Input Stok Lisensi / Kode Akses
                                    <textarea name="license_stock_lines" rows="8" placeholder="ABC-111&#10;ABC-222&#10;username1 | password1&#10;voucher-game-xyz"></textarea>
                                    <span class="upload-help">Saat edit produk, isi hanya kalau ingin menambahkan stok kode baru. Kode lama/assigned tidak ditampilkan ulang demi keamanan.</span>
                                </label>
                            </div>

                            <div class="bundle-builder-box" id="bundleBuilderBox">
                                <div class="auto-field-note">
                                    <strong>Isi Bundle</strong>
                                    <span>Pilih produk komponen dan jumlahnya. Harga tetap diambil dari produk induk bundle, stok diproses dari masing-masing isi bundle.</span>
                                </div>

                                <div class="bundle-list" id="bundleList">
                                    <div class="bundle-row" data-bundle-row>
                                        <label>
                                            Produk Komponen
                                            <select name="bundle_items[0][component_product_id]">
                                                <option value="">-- Pilih produk isi bundle --</option>
                                                <?php foreach ($products as $bundleProductOption): ?>
                                                    <option value="<?= (int)$bundleProductOption['id'] ?>">
                                                        <?= e($bundleProductOption['name']) ?> · <?= e(strtoupper($bundleProductOption['product_type'] ?? 'physical')) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>

                                        <label>
                                            Qty
                                            <input type="number" step="0.01" min="0.01" name="bundle_items[0][qty]" value="1">
                                        </label>

                                        <button class="bundle-row-remove" type="button" onclick="removeBundleRow(this)">×</button>
                                    </div>
                                </div>

                                <button class="btn btn-light" type="button" onclick="addBundleRow()">+ Tambah Isi Bundle</button>

                                <div class="auto-field-note">
                                    Contoh: Paket Tahun Baru berisi Sabun x2, Serum x1, Lisensi x1. Saat order completed, stok tiap komponen akan diproses oleh Bundle Engine.
                                </div>
                            </div>

                            <label class="span-2">
                                Gambar Utama <span class="muted">(URL atau upload)</span>
                                <input name="main_image" placeholder="https://... atau uploads/products/nama-file.jpg">
                                <input type="file" name="main_image_upload" accept="image/*">
                                <span class="upload-help">Bisa pakai URL atau upload langsung. Jika upload diisi, sistem akan memakai file upload sebagai gambar utama.</span>
                            </label>

                            <label class="span-2">
                                Gallery Gambar Tambahan <span class="muted">(URL dan/atau upload)</span>
                                <textarea name="gallery_images" rows="4" placeholder="Satu URL/path gambar per baris&#10;https://domain.com/gambar-1.jpg&#10;uploads/products/gambar-2.jpg"></textarea>
                                <input type="file" name="gallery_image_uploads[]" accept="image/*" multiple>
                                <span class="upload-help">Upload bisa banyak sekaligus. Gambar varian juga otomatis ikut masuk thumbnail jika URL/upload gambar varian diisi.</span>
                            </label>

                            <label class="span-2">
                                Deskripsi
                                <textarea name="description" rows="4" placeholder="Deskripsi singkat produk..."></textarea>
                            </label>

                            <label class="span-2">
                                <input type="checkbox" name="is_active" value="1" checked>
                                Produk aktif
                            </label>

                            <div class="variant-section-head">
                                <div>
                                    <strong>Varian Produk <span class="muted">(opsional)</span></strong>
                                    <span class="upload-help">Cocok untuk warna, tipe, ukuran, harga, dan stok yang berbeda.</span>
                                </div>
                                <button class="btn btn-light" type="button" onclick="addVariantRow()">+ Tambah Varian</button>
                            </div>

                            <div class="variant-list" id="variantList">
                                <div class="variant-card" data-variant-row>
                                    <button class="variant-remove" type="button" onclick="removeVariantRow(this)">×</button>

                                    <label>
                                        Warna / Tipe
                                        <input name="variants[0][type]" placeholder="Merah, Pedas, Botol...">
                                    </label>

                                    <label>
                                        Ukuran / Size
                                        <input name="variants[0][size]" placeholder="XL, 40, 250ml...">
                                    </label>

                                    <label>
                                        Harga Varian
                                        <input type="number" step="1" name="variants[0][price]" placeholder="Kosong = harga utama">
                                    </label>

                                    <label>
                                        Stok Varian
                                        <input type="number" name="variants[0][stock]" placeholder="Kosong = unlimited">
                                    </label>

                                    <label class="span-2">
                                        Gambar Varian <span class="muted">(URL atau upload)</span>
                                        <input name="variants[0][image_url]" placeholder="https://... atau uploads/products/varian.jpg">
                                        <input type="file" name="variant_image_uploads[0]" accept="image/*">
                                    </label>
                                </div>
                            </div>

                            <button class="btn btn-primary full-span" type="submit">Simpan Produk</button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($view === 'orders'): ?>
            <div class="card panel clean-panel">
                <div class="panel-title-row compact product-toolbar">
                    <div>
                        <h2>Pesanan Masuk</h2>
                        <p class="muted">Kelola order, status bertahap, catatan internal, struk, resend WhatsApp, point reward, dan akses digital.</p>
                    </div>
                    <a class="btn btn-primary" href="pos.php">Buka Kasir POS</a>
                </div>
            </div>

            <div class="card panel table-wrap clean-panel" data-per-page="10">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Order & Waktu</th>
                            <th>Pelanggan</th>
                            <th>Rincian Biaya</th>
                            <th>Pembayaran</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$orders): ?>
                            <tr>
                                <td colspan="6">Belum ada order.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($orders as $o): ?>
                            <?php
                                $orderId = (int)$o['id'];
                                $orderItems = $orderItemsMap[$orderId] ?? [];
                                [$orderDisplayItems, $orderBundleChildrenMap] = admin_group_bundle_order_items($orderItems);
                                $orderLogs = $orderStatusLogsMap[$orderId] ?? [];
                                $orderNotes = $orderNotesMap[$orderId] ?? [];
                                $orderAccessRows = $orderDigitalAccessMap[$orderId] ?? [];
                                $detailId = 'orderDetailModal-' . $orderId;
                                $orderStatus = strtolower((string)($o['order_status'] ?? 'pending'));
                                $paymentStatus = strtolower((string)($o['payment_status'] ?? 'unpaid'));
                            ?>
                            <tr>
                                <td>
                                    <strong><?= e(admin_order_number($o)) ?></strong><br>
                                    <small><?= e($o['created_at'] ? date('d/m/Y H:i', strtotime($o['created_at'])) : '-') ?></small>
                                    <?php if (!empty($o['order_channel'])): ?>
                                        <br><span class="product-badge-soft"><?= e(strtoupper($o['order_channel'])) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?= e($o['buyer_name'] ?: 'Walk-in Customer') ?></strong><br>
                                    <small><?= e($o['buyer_phone'] ?: '-') ?></small>
                                    <?php if (!empty($o['buyer_address'])): ?>
                                        <br><small><?= e(admin_str_limit((string)$o['buyer_address'], 52)) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small>Subtotal: <?= rupiah($o['subtotal'] ?? 0) ?></small><br>
                                    <small>Ongkir: <?= rupiah($o['shipping_cost'] ?? 0) ?></small><br>
                                    <small>Diskon: -<?= rupiah($o['discount_amount'] ?? 0) ?></small>
                                    <?php if (!empty($o['points_discount_amount'])): ?>
                                        <br><small>Diskon Point: -<?= rupiah($o['points_discount_amount']) ?></small>
                                    <?php endif; ?>
                                    <br><strong>Total: <?= rupiah($o['grand_total']) ?></strong>
                                </td>
                                <td>
                                    <?= e($o['payment_method'] ?? '-') ?><br>
                                    <small><?= e($paymentStatus ?: '-') ?></small>
                                    <?php if (!empty($o['paid_at'])): ?>
                                        <br><small>Lunas: <?= e(date('d/m/Y H:i', strtotime($o['paid_at']))) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="status-pill status-<?= e($orderStatus) ?>">
                                        <?= e($orderStatus) ?>
                                    </span>
                                    <?php if (!empty($o['points_earned'])): ?>
                                        <br><small>+<?= (int)$o['points_earned'] ?> point</small>
                                    <?php endif; ?>
                                    <?php if (!empty($o['digital_access_generated_at'])): ?>
                                        <br><small>Akses digital aktif</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="order-action-stack">
                                        <button class="btn btn-primary" type="button" onclick="openOrderDetailModal('<?= e($detailId) ?>')">Detail</button>

                                        <?php if (($o['order_status'] ?? '') !== 'completed'): ?>
                                            <form method="post">
                                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="order_complete">
                                                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                                <button class="btn btn-primary" type="submit">Tandai Selesai</button>
                                            </form>
                                        <?php endif; ?>

                                        <a class="btn" href="struk.php?id=<?= (int)$o['id'] ?>&code=<?= urlencode(admin_order_number($o)) ?>" target="_blank" rel="noopener">Cetak Struk</a>

                                        <form method="post">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="order_resend_invoice">
                                            <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                            <button class="btn" type="submit">Kirim Ulang WA</button>
                                        </form>

                                        <?php if ($isAdmin): ?>
                                            <form method="post" onsubmit="return confirm('Hapus order ini?')">
                                                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                                <input type="hidden" name="action" value="delete_order">
                                                <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                                <button class="btn" type="submit">Hapus</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                            <tr style="display:none;">
                                <td colspan="6">
                                    <div class="product-modal-overlay" id="<?= e($detailId) ?>" aria-hidden="true">
                                        <div class="product-modal order-modal" role="dialog" aria-modal="true" aria-labelledby="<?= e($detailId) ?>Title">
                                            <div class="product-modal-head">
                                                <h2 id="<?= e($detailId) ?>Title">Detail Order <?= e(admin_order_number($o)) ?></h2>
                                                <button class="product-modal-close" type="button" onclick="closeOrderDetailModal('<?= e($detailId) ?>')">×</button>
                                            </div>

                                            <div class="product-modal-body">
                                                <div class="order-detail-grid">
                                                    <div class="order-detail-card">
                                                        <h3>Data Pembeli</h3>
                                                        <div class="order-detail-list">
                                                            <div class="order-detail-row"><span>Nama</span><strong><?= e($o['buyer_name'] ?: 'Walk-in Customer') ?></strong></div>
                                                            <div class="order-detail-row"><span>WhatsApp</span><strong><?= e($o['buyer_phone'] ?: '-') ?></strong></div>
                                                            <div class="order-detail-row"><span>Alamat</span><strong><?= e($o['buyer_address'] ?? '-') ?></strong></div>
                                                            <div class="order-detail-row"><span>Catatan</span><strong><?= e($o['customer_note'] ?? '-') ?></strong></div>
                                                        </div>
                                                    </div>

                                                    <div class="order-detail-card">
                                                        <h3>Ringkasan Biaya</h3>
                                                        <div class="order-detail-list">
                                                            <div class="order-detail-row"><span>Subtotal</span><strong><?= rupiah($o['subtotal'] ?? 0) ?></strong></div>
                                                            <div class="order-detail-row"><span>Ongkir</span><strong><?= rupiah($o['shipping_cost'] ?? 0) ?></strong></div>
                                                            <div class="order-detail-row"><span>Diskon</span><strong>-<?= rupiah($o['discount_amount'] ?? 0) ?></strong></div>
                                                            <div class="order-detail-row"><span>Diskon Point</span><strong>-<?= rupiah($o['points_discount_amount'] ?? 0) ?></strong></div>
                                                            <div class="order-detail-row"><span>Total</span><strong><?= rupiah($o['grand_total'] ?? 0) ?></strong></div>
                                                            <div class="order-detail-row"><span>Point Didapat</span><strong><?= (int)($o['points_earned'] ?? 0) ?></strong></div>
                                                        </div>
                                                    </div>

                                                    <div class="order-detail-card">
                                                        <h3>Item Order</h3>
                                                        <?php if (!$orderItems): ?>
                                                            <p>Belum ada item order.</p>
                                                        <?php else: ?>
                                                            <ul>
                                                                <?php foreach ($orderDisplayItems as $item): ?>
                                                                    <?php
                                                                        $itemId = (int)($item['id'] ?? 0);
                                                                        $children = $orderBundleChildrenMap[$itemId] ?? [];
                                                                        $itemTypeLabel = admin_order_item_type_label((array)$item);
                                                                    ?>
                                                                    <li>
                                                                        <strong><?= e($item['product_name'] ?? 'Produk') ?></strong>
                                                                        <?= !empty($item['variant_label']) ? ' - ' . e($item['variant_label']) : '' ?>
                                                                        <?php if ($itemTypeLabel): ?>
                                                                            <span class="bundle-source-chip"><?= e($itemTypeLabel) ?></span>
                                                                        <?php endif; ?>
                                                                        <br>
                                                                        <?= e(admin_number_clean($item['qty'] ?? 1)) ?> x <?= rupiah($item['price'] ?? 0) ?>
                                                                        = <strong><?= rupiah($item['subtotal'] ?? 0) ?></strong>

                                                                        <?php if ($children): ?>
                                                                            <div class="bundle-order-children">
                                                                                <strong>Isi bundle:</strong>
                                                                                <?php foreach ($children as $child): ?>
                                                                                    <div class="bundle-order-child">
                                                                                        <span>
                                                                                            <?= e(preg_replace('/^Isi Bundle\s*-\s*/i', '', (string)($child['product_name'] ?? 'Produk'))) ?>
                                                                                            <small>(<?= e(admin_order_item_type_label((array)$child)) ?>)</small>
                                                                                        </span>
                                                                                        <strong>x<?= e(admin_number_clean($child['qty'] ?? 1)) ?></strong>
                                                                                    </div>
                                                                                <?php endforeach; ?>
                                                                            </div>
                                                                        <?php endif; ?>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        <?php endif; ?>
                                                    </div>

                                                    <div class="order-detail-card">
                                                        <h3>Update Status</h3>
                                                        <form method="post" class="order-status-form">
                                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="order_update_status">
                                                            <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">

                                                            <label>
                                                                Status Order
                                                                <select name="order_status">
                                                                    <?php foreach (['pending','new','paid','processing','shipped','delivered','completed','cancelled','refunded'] as $statusOption): ?>
                                                                        <option value="<?= e($statusOption) ?>" <?= $orderStatus === $statusOption ? 'selected' : '' ?>><?= e(ucfirst($statusOption)) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </label>

                                                            <label>
                                                                Status Pembayaran
                                                                <select name="payment_status">
                                                                    <?php foreach (['unpaid','pending','paid','failed','refunded'] as $payOption): ?>
                                                                        <option value="<?= e($payOption) ?>" <?= $paymentStatus === $payOption ? 'selected' : '' ?>><?= e(ucfirst($payOption)) ?></option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            </label>

                                                            <textarea name="note" placeholder="Catatan perubahan status, opsional"></textarea>
                                                            <button class="btn btn-primary" type="submit">Update Status</button>
                                                        </form>
                                                    </div>

                                                    <div class="order-detail-card">
                                                        <h3>Catatan Internal</h3>
                                                        <form method="post" class="order-note-form">
                                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                                            <input type="hidden" name="action" value="order_add_note">
                                                            <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                                                            <textarea name="note" placeholder="Contoh: pembeli minta dikirim sore / perlu follow up / VIP customer"></textarea>
                                                            <button class="btn btn-primary" type="submit">Simpan Catatan</button>
                                                        </form>

                                                        <div style="margin-top:14px;">
                                                            <?php if (!$orderNotes && empty($o['internal_note'])): ?>
                                                                <p>Belum ada catatan internal.</p>
                                                            <?php endif; ?>

                                                            <?php if (!empty($o['internal_note'])): ?>
                                                                <div class="order-access-box"><?= nl2br(e($o['internal_note'])) ?></div>
                                                            <?php endif; ?>

                                                            <?php foreach ($orderNotes as $noteRow): ?>
                                                                <div class="order-access-box">
                                                                    <small><?= e($noteRow['created_at'] ?? '-') ?></small><br>
                                                                    <?= nl2br(e($noteRow['note'] ?? '')) ?>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>

                                                    <div class="order-detail-card">
                                                        <h3>Riwayat Status</h3>
                                                        <?php if (!$orderLogs): ?>
                                                            <p>Belum ada riwayat status.</p>
                                                        <?php else: ?>
                                                            <?php foreach (array_slice($orderLogs, 0, 8) as $log): ?>
                                                                <div class="order-access-box">
                                                                    <strong><?= e(($log['old_status'] ?? '-') . ' → ' . ($log['new_status'] ?? '-')) ?></strong><br>
                                                                    <small><?= e($log['created_at'] ?? '-') ?></small>
                                                                    <?php if (!empty($log['note'])): ?>
                                                                        <br><?= nl2br(e($log['note'])) ?>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </div>

                                                    <div class="order-detail-card span-2">
                                                        <h3>Akses Digital / Lisensi</h3>
                                                        <?php if (!$orderAccessRows): ?>
                                                            <p>Belum ada akses digital untuk order ini. Akses akan dibuat otomatis saat order completed jika produk bertipe digital/license.</p>
                                                        <?php else: ?>
                                                            <?php foreach ($orderAccessRows as $access): ?>
                                                                <div class="order-access-box">
                                                                    <strong><?= e($access['access_title'] ?? 'Akses Digital') ?></strong>
                                                                    <span class="product-badge-soft"><?= e($access['access_type'] ?? '-') ?></span>
                                                                    <br><small>Status: <?= e($access['status'] ?? '-') ?> | Dibuat: <?= e($access['created_at'] ?? '-') ?></small>

                                                                    <?php if (!empty($access['access_url'])): ?>
                                                                        <br><a href="<?= e($access['access_url']) ?>" target="_blank" rel="noopener">Buka Link Akses</a>
                                                                    <?php endif; ?>

                                                                    <?php if (!empty($access['license_payload'])): ?>
                                                                        <code><?= e($access['license_payload']) ?></code>
                                                                    <?php elseif (!empty($access['access_content'])): ?>
                                                                        <code><?= e(admin_str_limit((string)$access['access_content'], 500)) ?></code>
                                                                    <?php endif; ?>

                                                                    <?php if (!empty($access['access_instruction'])): ?>
                                                                        <p style="margin-top:8px;"><?= nl2br(e($access['access_instruction'])) ?></p>
                                                                    <?php endif; ?>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>


        <?php if ($view === 'broadcast'): ?>
            <div class="card panel clean-panel">
                <div class="panel-title-row compact product-toolbar">
                    <div>
                        <h2>Broadcast WhatsApp</h2>
                        <p class="muted">Broadcast WA dibuat sebagai value booster. Sistem memakai queue dan batch default 20 pesan supaya lebih aman untuk shared hosting dan gateway.</p>
                    </div>
                    <a class="btn" href="admin.php?view=whatsapp">Setting Gateway</a>
                </div>
            </div>

            <div class="grid cards-4">
                <div class="stat-card card-blue">
                    <div>
                        <span>Total Campaign</span>
                        <strong><?= (int)$broadcastStats['campaigns'] ?></strong>
                        <small>Campaign dibuat</small>
                    </div>
                </div>
                <div class="stat-card card-purple">
                    <div>
                        <span>Queue Pending</span>
                        <strong><?= (int)$broadcastStats['pending'] ?></strong>
                        <small>Menunggu dikirim</small>
                    </div>
                </div>
                <div class="stat-card card-yellow">
                    <div>
                        <span>Terkirim</span>
                        <strong><?= (int)$broadcastStats['sent'] ?></strong>
                        <small>Pesan sukses</small>
                    </div>
                </div>
                <div class="stat-card card-green">
                    <div>
                        <span>Gagal</span>
                        <strong><?= (int)$broadcastStats['failed'] ?></strong>
                        <small>Cek nomor/gateway</small>
                    </div>
                </div>
            </div>

            <div class="card panel clean-panel">
                <div class="panel-title-row compact">
                    <div>
                        <h2>Buat Campaign Broadcast</h2>
                        <p class="muted">Pilih target, tulis pesan, setujui policy, lalu sistem membuat queue. Pengiriman dilakukan manual per batch.</p>
                    </div>
                </div>

                <?php if (!$broadcastEnabled): ?>
                    <div class="auto-field-note" style="margin-bottom:16px;">
                        Broadcast sedang nonaktif dari setting. Aktifkan setting broadcast_enabled jika ingin digunakan.
                    </div>
                <?php endif; ?>

                <div class="auto-field-note" style="margin-bottom:16px;background:#fff7ed;border-color:rgba(251,146,60,.25);color:#9a3412;">
                    <strong>Policy Broadcast:</strong><br>
                    <?= nl2br(e($broadcastPolicyNotice)) ?><br><br>
                    Sistem ini membantu mengurangi risiko dengan queue dan batch default <strong><?= (int)$broadcastBatchLimit ?> pesan</strong>, tetapi risiko banned/suspend tetap mengikuti kebijakan WhatsApp/gateway.
                </div>

                <form method="post" class="form-grid soft-form-panel" style="box-shadow:none;border:0;padding:0;">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_broadcast_campaign">

                    <label>
                        Judul Campaign
                        <input name="title" placeholder="Contoh: Promo Member Akhir Pekan" required>
                    </label>

                    <label>
                        Target Penerima
                        <select name="target_type" id="broadcastTargetType">
                            <?php foreach (admin_broadcast_target_options() as $targetKey => $targetLabel): ?>
                                <option value="<?= e($targetKey) ?>">
                                    <?= e($targetLabel) ?><?= isset($broadcastTargetCounts[$targetKey]) ? ' (' . (int)$broadcastTargetCounts[$targetKey] . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        Produk Khusus <span class="muted">(hanya untuk target pembeli produk tertentu)</span>
                        <select name="product_id">
                            <option value="">-- Pilih produk --</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?= (int)$product['id'] ?>"><?= e($product['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        Batch Kirim
                        <input name="batch_limit" type="number" min="1" max="50" value="<?= (int)$broadcastBatchLimit ?>">
                        <span class="upload-help">Rekomendasi aman: 20 pesan per batch. Maksimal sistem: 50 pesan per batch.</span>
                    </label>

                    <label style="grid-column:1/-1;">
                        Isi Pesan
                        <textarea name="message_template" rows="8" required placeholder="Halo {nama},

Ada info promo terbaru dari {store_name}.
Point kamu saat ini: {point}

Cek member area:
{member_url}"></textarea>
                        <span class="upload-help">
                            Variabel: {nama}, {whatsapp}, {point}, {total_order}, {total_belanja}, {last_order}, {last_order_number}, {store_name}, {member_url}
                        </span>
                    </label>

                    <label style="grid-column:1/-1;display:flex;gap:10px;align-items:flex-start;font-weight:800;">
                        <input type="checkbox" name="policy_accepted" value="1" required style="width:auto;height:auto;margin-top:4px;">
                        <span>Saya paham bahwa broadcast WhatsApp harus digunakan secara wajar. Risiko banned, suspend, limit, atau pembatasan nomor karena broadcast asal-asalan berada di luar tanggung jawab penyedia aplikasi.</span>
                    </label>

                    <div>
                        <button class="btn btn-primary" type="submit">Buat Queue Broadcast</button>
                    </div>
                </form>
            </div>

            <div class="card panel table-wrap clean-panel">
                <div class="panel-title-row compact">
                    <div>
                        <h2>Riwayat Campaign</h2>
                        <p class="muted">Klik Kirim Batch untuk mengirim bertahap sesuai batch limit campaign.</p>
                    </div>
                </div>

                <table class="table">
                    <thead>
                        <tr>
                            <th>Campaign</th>
                            <th>Target</th>
                            <th>Progress</th>
                            <th>Status</th>
                            <th>Dibuat</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$broadcastCampaigns): ?>
                            <tr>
                                <td colspan="6">Belum ada campaign broadcast.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($broadcastCampaigns as $campaign): ?>
                            <?php
                                $targetOptions = admin_broadcast_target_options();
                                $targetLabel = $targetOptions[$campaign['target_type'] ?? ''] ?? ($campaign['target_type'] ?? '-');
                                $pendingCount = admin_table_exists('wa_message_queue') ? (int)safe_scalar("SELECT COUNT(*) FROM wa_message_queue WHERE campaign_id = ? AND status = 'pending'", [(int)$campaign['id']], 0) : 0;
                            ?>
                            <tr>
                                <td>
                                    <strong><?= e($campaign['title']) ?></strong><br>
                                    <small>Batch: <?= (int)($campaign['batch_limit'] ?? 20) ?> pesan</small>
                                </td>
                                <td><?= e($targetLabel) ?></td>
                                <td>
                                    <strong><?= (int)($campaign['sent_count'] ?? 0) ?></strong> terkirim /
                                    <strong><?= (int)($campaign['failed_count'] ?? 0) ?></strong> gagal /
                                    <strong><?= $pendingCount ?></strong> pending<br>
                                    <small>Total queue: <?= (int)($campaign['queued_count'] ?? $campaign['total_recipients'] ?? 0) ?></small>
                                </td>
                                <td>
                                    <span class="status-pill status-<?= e($campaign['status'] ?? 'draft') ?>">
                                        <?= e($campaign['status'] ?? 'draft') ?>
                                    </span>
                                </td>
                                <td><?= e(!empty($campaign['created_at']) ? short_date_id($campaign['created_at']) : '-') ?></td>
                                <td>
                                    <?php if ($pendingCount > 0): ?>
                                        <form method="post" style="display:inline-block;" onsubmit="return confirm('Kirim batch berikutnya untuk campaign ini?')">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="send_broadcast_batch">
                                            <input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>">
                                            <button class="btn btn-primary" type="submit">Kirim Batch</button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="post" style="display:inline-block;" onsubmit="return confirm('Hapus campaign dan queue broadcast ini?')">
                                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_broadcast_campaign">
                                        <input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>">
                                        <button class="btn" type="submit">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>


        <?php if ($view === 'customers'): ?>
            <div class="card panel clean-panel">
                <div class="panel-title-row compact product-toolbar">
                    <div>
                        <h2>Data Pembeli / Member</h2>
                        <p class="muted">Data pembeli dari checkout web, POS, atau input manual. Dipakai untuk repeat order, promo blast, point rewards, dan backup database pembeli via CSV.</p>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <?php if ($isAdmin): ?>
                            <a class="btn" href="admin.php?view=customers&download=customers_csv_export">Export CSV</a>
                        <?php endif; ?>
                        <button class="btn btn-primary" type="button" onclick="openCustomerModal()">+ Tambah Pembeli</button>
                    </div>
                </div>
            </div>

            <div class="card panel table-wrap clean-panel">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nama & WhatsApp</th>
                            <th>Transaksi</th>
                            <th>Total Belanja</th>
                            <th>Point</th>
                            <th>Sumber</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$customersList): ?>
                            <tr>
                                <td colspan="7">Belum ada data pembeli.</td>
                            </tr>
                        <?php endif; ?>

                        <?php foreach ($customersList as $customer): ?>
                            <tr>
                                <td>
                                    <strong><?= e($customer['name'] ?: 'Tanpa Nama') ?></strong><br>
                                    <small><?= e($customer['phone']) ?></small>
                                </td>
                                <td><?= (int)($customer['total_orders'] ?? 0) ?> order<br><small>Terakhir: <?= e($customer['last_order_at'] ?: '-') ?></small></td>
                                <td><?= rupiah($customer['total_spent'] ?? 0) ?></td>
                                <td><?= (int)($customer['points'] ?? 0) ?></td>
                                <td><?= e($customer['source'] ?: '-') ?></td>
                                <td>
                                    <span class="status-pill status-<?= e($customer['status'] ?? 'active') ?>">
                                        <?= e($customer['status'] ?? 'active') ?>
                                    </span>
                                </td>
                                <td>
                                    <a class="btn" target="_blank" rel="noopener" href="https://wa.me/<?= e($customer['phone']) ?>">WA</a>
                                    <form method="post" style="display:inline-block;" onsubmit="return confirm('Hapus data pembeli ini?')">
                                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_customer">
                                        <input type="hidden" name="id" value="<?= (int)$customer['id'] ?>">
                                        <button class="btn" type="submit">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>


            <div class="product-modal-overlay" id="customerModal" aria-hidden="true">
                <div class="product-modal" role="dialog" aria-modal="true" aria-labelledby="customerModalTitle">
                    <div class="product-modal-head">
                        <h2 id="customerModalTitle">Tambah / Update Pembeli</h2>
                        <button class="product-modal-close" type="button" onclick="closeCustomerModal()">×</button>
                    </div>

                    <form method="post" class="product-modal-body">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add_customer">

                        <div class="product-modal-grid">
                            <label>
                                Nama Pembeli <span class="muted">(opsional)</span>
                                <input name="name" placeholder="Contoh: Budi">
                            </label>

                            <label>
                                Nomor WhatsApp Aktif
                                <input name="phone" placeholder="628xxxxxxxxxx / 08xxxxxxxxxx" required>
                            </label>

                            <label>
                                Status
                                <select name="status">
                                    <option value="active">Aktif</option>
                                    <option value="inactive">Nonaktif</option>
                                </select>
                            </label>

                            <label>
                                Catatan Singkat
                                <input name="note_short" placeholder="Pelanggan grosir / suka promo skincare" oninput="document.getElementById('customerNoteTextarea').value=this.value">
                            </label>

                            <label class="span-2">
                                Catatan Detail
                                <textarea id="customerNoteTextarea" name="note" rows="5" placeholder="Opsional, contoh: pelanggan grosir / suka promo skincare"></textarea>
                            </label>

                            <div class="auto-field-note">
                                <strong>Siap untuk membership</strong>
                                <span>Data ini otomatis terisi saat pembeli checkout di web atau transaksi lewat POS dengan nomor WA. Kalau nomor sudah ada, sistem akan update data lama.</span>
                            </div>

                            <button class="btn btn-primary full-span" type="submit">Simpan Pembeli</button>
                        </div>
                    </form>
                </div>
            </div>

        <?php if ($view === 'users'): ?>
            <div class="card panel clean-panel">
                <div class="panel-title-row compact product-toolbar">
                    <div>
                        <h2>Manajemen Staf Kasir</h2>
                        <p class="muted">Kelola akun admin dan kasir. Admin punya akses penuh, kasir hanya untuk POS dan proses pesanan masuk.</p>
                    </div>
                    <button class="btn btn-primary" type="button" onclick="openUserModal()">+ Tambah User</button>
                </div>
            </div>

            <div class="card panel table-wrap clean-panel">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nama & Kontak</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Login Terakhir</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$usersList): ?>
                            <tr><td colspan="5">Belum ada user.</td></tr>
                        <?php endif; ?>

                        <?php foreach ($usersList as $u): ?>
                            <tr>
                                <td>
                                    <strong><?= e($u['name']) ?></strong> <small>@<?= e($u['username']) ?></small><br>
                                    <small><?= e($u['email'] ?: '-') ?> <?= $u['phone'] ? ' | ' . e($u['phone']) : '' ?></small>
                                </td>
                                <td><span class="status-pill"><?= e(strtoupper($u['role'])) ?></span></td>
                                <td><span class="status-pill status-<?= e($u['status']) ?>"><?= e($u['status']) ?></span></td>
                                <td><?= e($u['last_login_at'] ?: '-') ?></td>
                                <td>
                                    <?php if ((int)$u['id'] !== (int)($user['id'] ?? 0)): ?>
                                        <form method="post" onsubmit="return confirm('Hapus user ini?')">
                                            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                            <button class="btn" type="submit">Hapus</button>
                                        </form>
                                    <?php else: ?>
                                        <small>Sedang login</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>


            <div class="product-modal-overlay" id="userModal" aria-hidden="true">
                <div class="product-modal" role="dialog" aria-modal="true" aria-labelledby="userModalTitle">
                    <div class="product-modal-head">
                        <h2 id="userModalTitle">Tambah Kasir / Admin</h2>
                        <button class="product-modal-close" type="button" onclick="closeUserModal()">×</button>
                    </div>

                    <form method="post" class="product-modal-body">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="add_user">

                        <div class="product-modal-grid">
                            <label>
                                Nama Lengkap
                                <input name="name" required placeholder="Contoh: Kasir 1">
                            </label>

                            <label>
                                Username
                                <input name="username" required placeholder="kasir1">
                            </label>

                            <label>
                                Email <span class="muted">(opsional)</span>
                                <input type="email" name="email" placeholder="email@domain.com">
                            </label>

                            <label>
                                No WhatsApp <span class="muted">(opsional)</span>
                                <input name="phone" placeholder="628xxxxxxxxxx">
                            </label>

                            <label>
                                Password
                                <input type="password" name="password" required autocomplete="new-password">
                            </label>

                            <label>
                                Role
                                <select name="role">
                                    <option value="kasir">Kasir</option>
                                    <option value="admin">Admin</option>
                                </select>
                            </label>

                            <label class="span-2">
                                Status
                                <select name="status">
                                    <option value="active">Aktif</option>
                                    <option value="inactive">Nonaktif</option>
                                </select>
                            </label>

                            <div class="auto-field-note">
                                <strong>Hak akses role</strong>
                                <span>Admin bisa mengelola semua menu. Kasir hanya bisa membuka Pesanan Masuk dan Kasir POS.</span>
                            </div>

                            <button class="btn btn-primary full-span" type="submit">Simpan User</button>
                        </div>
                    </form>
                </div>
            </div>

        <?php if ($view === 'coupons'): ?>
            <div class="card panel clean-panel">
                <h2>Kupon Diskon</h2>
                <p class="muted">Buat kode promo untuk menarik pelanggan belanja lebih banyak.</p>
            </div>

            <form method="post" class="card panel form-grid soft-form-panel">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add_coupon">

                <h2>Buat Kupon</h2>

                <label>Kode Kupon
                    <input name="code" placeholder="WELCOME20" required>
                </label>

                <label>Tipe Diskon
                    <select name="discount_type">
                        <option value="fixed">Nominal Rupiah</option>
                        <option value="percent">Persen</option>
                    </select>
                </label>

                <label>Nilai Diskon
                    <input type="number" step="0.01" name="discount_value" required>
                </label>

                <label>Minimal Belanja
                    <input type="number" step="1" name="min_purchase" value="0">
                </label>

                <label>Maksimal Diskon
                    <input type="number" step="1" name="max_discount" placeholder="Opsional">
                </label>

                <label>Batas Pemakaian
                    <input type="number" name="usage_limit" placeholder="Opsional">
                </label>

                <label>Mulai Berlaku
                    <input type="datetime-local" name="start_at">
                </label>

                <label>Berakhir
                    <input type="datetime-local" name="end_at">
                </label>

                <label>
                    <input type="checkbox" name="is_active" value="1" checked>
                    Aktif
                </label>

                <button class="btn btn-primary" type="submit">Buat Kupon</button>
            </form>

            <div class="card panel table-wrap clean-panel">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Kode</th>
                            <th>Nilai Diskon</th>
                            <th>Min. Belanja</th>
                            <th>Dipakai</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$couponsList): ?>
                            <tr><td colspan="6">Belum ada kupon.</td></tr>
                        <?php endif; ?>

                        <?php foreach ($couponsList as $coupon): ?>
                            <tr>
                                <td><strong><?= e($coupon['code']) ?></strong></td>
                                <td><?= $coupon['discount_type'] === 'percent' ? e($coupon['discount_value']) . '%' : rupiah($coupon['discount_value']) ?></td>
                                <td><?= rupiah($coupon['min_purchase']) ?></td>
                                <td><?= (int)$coupon['used_count'] ?><?= $coupon['usage_limit'] ? ' / ' . (int)$coupon['usage_limit'] : '' ?></td>
                                <td><?= $coupon['is_active'] ? 'Aktif' : 'Nonaktif' ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Hapus kupon?')">
                                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_coupon">
                                        <input type="hidden" name="id" value="<?= (int)$coupon['id'] ?>">
                                        <button class="btn" type="submit">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($view === 'shipping'): ?>
            <div class="card panel clean-panel">
                <h2>Tarif Pengiriman Manual</h2>
                <p class="muted">Atur area pengiriman dan tarif ongkos kirim manual untuk checkout.</p>
            </div>

            <form method="post" class="card panel form-grid soft-form-panel">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add_shipping">

                <h2>Tambah Area Pengiriman</h2>

                <label>Nama Area / Layanan
                    <input name="name" placeholder="Dalam Kota / Reguler / Ambil Sendiri" required>
                </label>

                <label>Tarif Ongkos Kirim
                    <input type="number" step="1" name="cost" value="0" required>
                </label>

                <label>Deskripsi
                    <textarea name="description" placeholder="Estimasi, catatan area, dll."></textarea>
                </label>

                <label>
                    <input type="checkbox" name="is_active" value="1" checked>
                    Aktif
                </label>

                <button class="btn btn-primary" type="submit">Tambah Area</button>
            </form>

            <div class="card panel table-wrap clean-panel">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Nama Area / Layanan</th>
                            <th>Tarif</th>
                            <th>Status</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$shippingRates): ?>
                            <tr><td colspan="4">Belum ada ongkos kirim.</td></tr>
                        <?php endif; ?>

                        <?php foreach ($shippingRates as $rate): ?>
                            <tr>
                                <td><strong><?= e($rate['name']) ?></strong><br><small><?= e($rate['description'] ?: '-') ?></small></td>
                                <td><strong><?= rupiah($rate['cost']) ?></strong></td>
                                <td><?= $rate['is_active'] ? 'Aktif' : 'Nonaktif' ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Hapus tarif ini?')">
                                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_shipping">
                                        <input type="hidden" name="id" value="<?= (int)$rate['id'] ?>">
                                        <button class="btn" type="submit">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($view === 'articles'): ?>
            <div class="card panel clean-panel">
                <h2>Artikel & Blog</h2>
                <p class="muted">Tulis artikel, info, promo, atau edukasi toko untuk meningkatkan SEO dan trust.</p>
            </div>

            <form method="post" enctype="multipart/form-data" class="card panel form-grid soft-form-panel">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="add_article">

                <h2>Tulis Artikel</h2>

                <label>Judul Artikel
                    <input name="title" required>
                </label>

                <label>URL Cover Artikel
                    <input name="cover_image" placeholder="https://domain.com/cover.jpg">
                </label>

                <label>Upload Cover Artikel
                    <input type="file" name="cover_image_file" accept="image/*">
                </label>

                <label>Ringkasan
                    <textarea name="excerpt"></textarea>
                </label>

                <label>Isi Artikel
                    <textarea name="content" rows="8"></textarea>
                </label>

                <label>SEO Title
                    <input name="meta_title">
                </label>

                <label>SEO Description
                    <textarea name="meta_description"></textarea>
                </label>

                <label>
                    <input type="checkbox" name="is_published" value="1" checked>
                    Publish
                </label>

                <button class="btn btn-primary" type="submit">Tulis Artikel</button>
            </form>

            <div class="card panel table-wrap clean-panel">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Cover</th>
                            <th>Judul Artikel</th>
                            <th>Status</th>
                            <th>Tanggal</th>
                            <th>Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$articlesList): ?>
                            <tr><td colspan="5">Belum ada artikel.</td></tr>
                        <?php endif; ?>

                        <?php foreach ($articlesList as $article): ?>
                            <tr>
                                <td>
                                    <?php if (!empty($article['cover_image'])): ?>
                                        <img src="<?= e($article['cover_image']) ?>" alt="" style="width:54px;height:54px;object-fit:cover;border-radius:12px;">
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><strong><?= e($article['title']) ?></strong><br><small>/<?= e($article['slug']) ?></small></td>
                                <td><?= $article['is_published'] ? 'Published' : 'Draft' ?></td>
                                <td><?= e($article['created_at'] ? date('d/m/Y', strtotime($article['created_at'])) : '-') ?></td>
                                <td>
                                    <form method="post" onsubmit="return confirm('Hapus artikel?')">
                                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="action" value="delete_article">
                                        <input type="hidden" name="id" value="<?= (int)$article['id'] ?>">
                                        <button class="btn" type="submit">Hapus</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <?php if ($view === 'whatsapp'): ?>
            <form method="post" class="card panel form-grid soft-form-panel">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_settings">

                <h2>WhatsApp Gateway</h2>

                <label>Status Notifikasi WA
                    <select name="wa_enabled">
                        <option value="1" <?= setting('wa_enabled', '0') === '1' ? 'selected' : '' ?>>Aktif</option>
                        <option value="0" <?= setting('wa_enabled', '0') !== '1' ? 'selected' : '' ?>>Mati</option>
                    </select>
                </label>

                <label>Provider
                    <select name="wa_provider">
                        <?php $waProvider = setting('wa_provider', 'fonnte'); ?>
                        <option value="fonnte" <?= $waProvider === 'fonnte' ? 'selected' : '' ?>>Fonnte</option>
                        <option value="waplus" <?= $waProvider === 'waplus' ? 'selected' : '' ?>>WAplus</option>
                        <option value="starsender" <?= $waProvider === 'starsender' ? 'selected' : '' ?>>StarSender</option>
                        <option value="xsender" <?= $waProvider === 'xsender' ? 'selected' : '' ?>>XSender</option>
                        <option value="custom" <?= $waProvider === 'custom' ? 'selected' : '' ?>>Custom JSON API</option>
                    </select>
                </label>

                <label>API Token / API Key
                    <input name="wa_token" value="<?= e(setting('wa_token', '')) ?>">
                </label>

                <label>Sender / Device Number
                    <input name="wa_sender" value="<?= e(setting('wa_sender', '')) ?>" placeholder="Khusus provider yang butuh sender">
                </label>

                <label>Nomor Admin
                    <input name="wa_admin_number" value="<?= e(setting('wa_admin_number', '')) ?>" placeholder="628xxxxxxxxxx">
                </label>

                <label>Custom API URL
                    <input name="wa_custom_url" value="<?= e(setting('wa_custom_url', '')) ?>" placeholder="Untuk provider custom">
                </label>

                <h2>Template Notifikasi WhatsApp</h2>

                <div class="auto-field-note">
                    <strong>Variable umum</strong>
                    <span>{buyer_name}, {buyer_phone}, {order_number}, {grand_total}, {subtotal}, {shipping_cost}, {discount_amount}, {payment_method}, {items}, {receipt_url}</span>
                </div>

                <label>Pesan Checkout Web ke Pembeli
                    <textarea name="wa_web_order_buyer_template" rows="7"><?= e(setting('wa_web_order_buyer_template', "Halo *{buyer_name}*,\\n\\nPesanan Anda berhasil dibuat di toko kami.\\n\\nOrder: *{order_number}*\\n{items}\\n\\nSubtotal: *{subtotal}*\\nOngkir: *{shipping_cost}*\\nDiskon: *-{discount_amount}*\\nTotal: *{grand_total}*\\n\\nSilakan lanjut konfirmasi pesanan melalui WhatsApp.")) ?></textarea>
                </label>

                <label>Pesan Checkout Web ke Admin
                    <textarea name="wa_web_order_admin_template" rows="7"><?= e(setting('wa_web_order_admin_template', "Order baru dari website.\\n\\nOrder: *{order_number}*\\nNama: *{buyer_name}*\\nWA: *{buyer_phone}*\\nTotal: *{grand_total}*\\n\\n{items}")) ?></textarea>
                </label>

                <label>Pesan Struk POS ke Pembeli
                    <textarea name="wa_pos_receipt_template" rows="7"><?= e(setting('wa_pos_receipt_template', "Halo *{buyer_name}*, berikut struk transaksi Anda.\\n\\nOrder: *{order_number}*\\n{items}\\n\\nTotal: *{grand_total}*\\nBayar: *{paid_amount}*\\nKembali: *{change_amount}*\\n\\nLihat / simpan struk PDF:\\n{receipt_url}\\n\\nTerima kasih.")) ?></textarea>
                </label>

                <label>Pesan Update Status / Pesanan Selesai
                    <textarea name="wa_order_status_update_template" rows="9"><?= e(setting('wa_order_status_update_template', "Halo *{buyer_name}*,\n\nUpdate pesanan Anda: *{status_text}*.\n\nOrder: *{order_number}*\n\n*Rincian Pesanan*\n--------------------\n{items}\n\n*Pengiriman*\n--------------------\n{shipping_info}\n\n*Total:* {grand_total}\n\n{note_block}\n\nStruk pesanan:\n{receipt_url}\n\nTerima kasih, pesanan Anda akan kami proses sesuai antrean toko.")) ?></textarea>
                    <span class="upload-help">Dipakai saat admin klik Pesanan Selesai atau update status paid/processing/shipped/delivered/completed.</span>
                </label>

                <div class="auto-field-note">
                    <strong>Variable update status</strong>
                    <span>{buyer_name}, {buyer_phone}, {order_number}, {status}, {status_text}, {items}, {shipping_info}, {shipping_name}, {shipping_destination}, {buyer_address}, {subtotal}, {shipping_cost}, {discount_amount}, {grand_total}, {note}, {note_block}, {receipt_url}, {store_name}</span>
                </div>

                <label>Pesan Update Status Produk Digital
                    <textarea name="wa_order_status_digital_template" rows="9"><?= e(setting('wa_order_status_digital_template', "Halo *{buyer_name}*,\n\nPesanan digital Anda sudah kami proses.\n\nOrder: *{order_number}*\n\n*Produk Digital*\n--------------------\n{digital_items}\n\n*Total:* {grand_total}\n\nAkses produk digital Anda tersedia di member area:\n{member_url}\n\nStruk pesanan:\n{receipt_url}\n\nTerima kasih.")) ?></textarea>
                    <span class="upload-help">Dipakai otomatis kalau order hanya berisi produk digital/lisensi. Untuk order campuran fisik + digital, sistem tetap memakai template update status umum.</span>
                </label>

                <div class="auto-field-note">
                    <strong>Variable khusus digital</strong>
                    <span>{order_type}, {digital_items}, {digital_access_info}, {member_url}. Variable umum seperti {buyer_name}, {order_number}, {grand_total}, {receipt_url} juga tetap bisa dipakai.</span>
                </div>

                <label>Pesan Update Status Produk Jasa / Service
                    <textarea name="wa_order_status_service_template" rows="9"><?= e(setting('wa_order_status_service_template', "Halo *{buyer_name}*,\\n\\nUpdate pesanan jasa Anda: *{status_text}*.\\n\\nOrder: *{order_number}*\\n\\n*Layanan/Jasa*\\n--------------------\\n{service_items}\\n\\n*Catatan/Brief*\\n--------------------\\n{note}\\n\\n*Total:* {grand_total}\\n\\nStruk pesanan:\\n{receipt_url}\\n\\nTim kami akan menghubungi Anda untuk proses berikutnya.\\n\\nTerima kasih.")) ?></textarea>
                    <span class="upload-help">Dipakai otomatis kalau order hanya berisi produk jasa/service.</span>
                </label>

                <div class="auto-field-note">
                    <strong>Variable khusus jasa</strong>
                    <span>{order_type}, {service_items}, {note}, {note_block}. Variable umum seperti {buyer_name}, {order_number}, {grand_total}, {receipt_url}, {store_name} juga tetap bisa dipakai.</span>
                </div>

                <h2>Template Member</h2>

                <div class="auto-field-note">
                    <strong>Variable member</strong>
                    <span>{member_name}, {member_phone}, {pin}, {store_name}, {member_url}</span>
                </div>

                <label>Notifikasi Member Daftar / Buat PIN
                    <textarea name="wa_member_register_template" rows="6"><?= e(setting('wa_member_register_template', "Halo *{member_name}*,\\n\\nAkun member Anda di *{store_name}* berhasil dibuat.\\n\\nNomor WA: *{member_phone}*\\nPIN Login: *{pin}*\\n\\nLogin member area:\\n{member_url}")) ?></textarea>
                </label>

                <label>Notifikasi Lupa PIN Member
                    <textarea name="wa_member_forgot_pin_template" rows="6"><?= e(setting('wa_member_forgot_pin_template', "Halo Kak,\\n\\nPIN member baru Anda untuk *{store_name}* adalah:\\n\\n*{pin}*\\n\\nLogin kembali di:\\n{member_url}\\n\\nAbaikan pesan ini jika Anda tidak meminta reset PIN.")) ?></textarea>
                </label>

                <h2>Template Lama / Fallback</h2>

                <label>Fallback Order Buyer
                    <textarea name="wa_order_buyer_template" rows="5"><?= e(setting('wa_order_buyer_template', "Halo *{buyer_name}*,\\nPesanan Anda berhasil dibuat.\\n\\nOrder: *{order_number}*\\nTotal: *{grand_total}*\\n\\nTerima kasih.")) ?></textarea>
                </label>

                <label>Fallback Order Admin
                    <textarea name="wa_order_admin_template" rows="5"><?= e(setting('wa_order_admin_template', "Order baru masuk.\\n\\nOrder: *{order_number}*\\nBuyer: *{buyer_name}*\\nTotal: *{grand_total}*")) ?></textarea>
                </label>

                <button class="btn btn-primary" type="submit">Simpan WhatsApp Gateway</button>
            </form>
        <?php endif; ?>

        <?php if ($view === 'whatsapp'): ?>
            <form method="post" class="card panel form-grid soft-form-panel">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="test_whatsapp">

                <h2>Test Pengiriman WhatsApp</h2>

                <label>Nomor Tujuan Test
                    <input name="test_wa_target" value="<?= e(setting('wa_admin_number', setting('store_whatsapp', ''))) ?>" placeholder="628xxxxxxxxxx">
                </label>

                <label>Pesan Test
                    <textarea name="test_wa_message" rows="4">Test WhatsApp Gateway dari <?= e(setting('store_name', setting('app_name', 'Toko Online'))) ?>.
Kalau pesan ini masuk, berarti gateway aktif dan normal.</textarea>
                </label>

                <div class="auto-field-note">
                    <strong>Cek langsung dari admin</strong>
                    <span>Tombol ini mengetes provider, token, sender, dan koneksi gateway tanpa melewati POS / checkout web.</span>
                </div>

                <button class="btn btn-primary" type="submit">Kirim Test WhatsApp</button>
            </form>
        <?php endif; ?>

        <?php if ($view === 'tutorial'): ?>
            <div class="card panel clean-panel">
                <h2>Video Panduan</h2>
                <p class="muted">Playlist video panduan ini diatur dari menu Pengaturan Toko.</p>
            </div>

            <?php $tutorialVideos = admin_json_setting_array('video_playlist_json'); ?>

            <div class="card panel clean-panel">
                <?php if (!$tutorialVideos): ?>
                    <p class="muted">Belum ada video panduan. Tambahkan link YouTube dari Pengaturan Toko.</p>
                <?php else: ?>
                    <div class="video-list">
                        <?php foreach ($tutorialVideos as $idx => $video): ?>
                            <?php $url = trim((string)($video['url'] ?? '')); ?>
                            <?php if ($url === '') continue; ?>
                            <div style="padding:14px 0;border-bottom:1px solid rgba(148,163,184,.18);">
                                <strong>Video <?= (int)($idx + 1) ?></strong><br>
                                <a href="<?= e($url) ?>" target="_blank" rel="noopener"><?= e($url) ?></a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </main>
</div>

<?php if ($view === 'dashboard'): ?>
<script>
const cssVar = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

const primary = cssVar('--primary') || '#10b981';
const accent = cssVar('--accent') || '#fb923c';
const secondary = cssVar('--secondary') || '#0f172a';

const rupiahAxis = (value) => 'Rp ' + Number(value || 0).toLocaleString('id-ID');

Chart.defaults.font.family = "Plus Jakarta Sans, Poppins, Montserrat, sans-serif";
Chart.defaults.color = '#64748b';

const dashboardAnalytics = <?= chart_json($dashboardAnalytics) ?>;

const revenueChart = new Chart(document.getElementById('revenueChart'), {
    type: 'line',
    data: {
        labels: dashboardAnalytics.today.labels,
        datasets: [{
            label: 'Pendapatan',
            data: dashboardAnalytics.today.values,
            borderColor: primary,
            backgroundColor: 'rgba(16,185,129,.12)',
            tension: .45,
            fill: true,
            pointRadius: 4,
            pointHoverRadius: 6,
            pointBackgroundColor: primary,
            borderWidth: 3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                callbacks: {
                    label: ctx => rupiahAxis(ctx.raw)
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: value => value >= 1000 ? (value / 1000) + 'k' : value
                },
                grid: {
                    color: 'rgba(148,163,184,.18)'
                }
            },
            x: {
                grid: {
                    color: 'rgba(148,163,184,.12)'
                }
            }
        }
    }
});

document.querySelectorAll('.analytics-tab').forEach(button => {
    button.addEventListener('click', () => {
        const range = button.dataset.range || 'today';
        const data = dashboardAnalytics[range];

        if (!data) return;

        document.querySelectorAll('.analytics-tab').forEach(item => item.classList.remove('active'));
        button.classList.add('active');

        revenueChart.data.labels = data.labels;
        revenueChart.data.datasets[0].data = data.values;
        revenueChart.update();

        const customers = document.getElementById('analyticsCustomers');
        const paidOrders = document.getElementById('analyticsPaidOrders');
        const revenueLabel = document.getElementById('analyticsRevenueLabel');
        const revenue = document.getElementById('analyticsRevenue');

        if (customers) customers.textContent = data.customers;
        if (paidOrders) paidOrders.textContent = data.paid_orders;
        if (revenueLabel) revenueLabel.textContent = data.omzet_label;
        if (revenue) revenue.textContent = data.revenue_text;
    });
});

new Chart(document.getElementById('topProductChart'), {
    type: 'doughnut',
    data: {
        labels: <?= chart_json($topProductLabels) ?>,
        datasets: [{
            data: <?= chart_json($topProductValues) ?>,
            backgroundColor: [
                primary,
                '#3b82f6',
                accent,
                '#8b5cf6',
                '#06b6d4'
            ],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '68%',
        plugins: {
            legend: {
                position: 'bottom',
                labels: {
                    usePointStyle: true,
                    boxWidth: 8,
                    font: {
                        size: 11,
                        weight: '700'
                    }
                }
            }
        }
    }
});

new Chart(document.getElementById('monthlyRevenueChart'), {
    type: 'bar',
    data: {
        labels: <?= chart_json($monthlyLabels) ?>,
        datasets: [{
            label: 'Revenue',
            data: <?= chart_json($monthlyValues) ?>,
            backgroundColor: '#3b82f6',
            borderRadius: 12,
            barThickness: 24
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                callbacks: {
                    label: ctx => rupiahAxis(ctx.raw)
                }
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: value => value >= 1000 ? (value / 1000) + 'k' : value
                },
                grid: {
                    color: 'rgba(148,163,184,.16)'
                }
            },
            x: {
                grid: {
                    display: false
                }
            }
        }
    }
});
</script>
<?php endif; ?>


<script>
document.addEventListener('input', function(e) {
    if (!e.target.matches('input[type="color"][name^="dashboard_card_"]')) return;

    const box = e.target.closest('.color-pair-box');
    if (!box) return;

    const start = box.querySelector('input[name$="_start"]');
    const end = box.querySelector('input[name$="_end"]');
    const preview = box.querySelector('.gradient-preview');

    if (start && end && preview) {
        preview.style.background = `linear-gradient(135deg, ${start.value}, ${end.value})`;
    }
});
</script>


<script>
function removeRepeaterRow(button) {
    const row = button.closest('.repeater-row');
    if (!row) return;
    const wrap = row.parentElement;
    row.remove();
    renumberRepeater(wrap);
}

function renumberRepeater(wrap) {
    if (!wrap) return;
    wrap.querySelectorAll('.repeater-row').forEach((row, index) => {
        const number = row.querySelector('.repeater-number');
        if (number) number.textContent = index + 1;

        row.querySelectorAll('input, select, textarea').forEach(input => {
            input.name = input.name.replace(/\[\d+\]/, '[' + index + ']');
        });
    });
}

function addVideoRow() {
    const wrap = document.getElementById('videoRepeater');
    if (!wrap) return;
    const index = wrap.querySelectorAll('.repeater-row').length;
    const div = document.createElement('div');
    div.className = 'repeater-row video-row';
    div.innerHTML = `
        <div class="repeater-number">${index + 1}</div>
        <input name="video_playlist[${index}][url]" value="" placeholder="https://youtube.com/watch?v=...">
        <button class="repeater-remove" type="button" onclick="removeRepeaterRow(this)">🗑</button>
    `;
    wrap.appendChild(div);
}

function addSocialRow() {
    const wrap = document.getElementById('socialRepeater');
    if (!wrap) return;
    const index = wrap.querySelectorAll('.repeater-row').length;
    const options = ['Instagram','TikTok','Facebook','YouTube','WhatsApp','Website','Marketplace','Lainnya']
        .map(item => `<option value="${item}">${item}</option>`).join('');
    const div = document.createElement('div');
    div.className = 'repeater-row';
    div.innerHTML = `
        <div class="repeater-number">${index + 1}</div>
        <select name="social_links[${index}][platform]">${options}</select>
        <input name="social_links[${index}][url]" value="" placeholder="https://...">
        <button class="repeater-remove" type="button" onclick="removeRepeaterRow(this)">🗑</button>
    `;
    wrap.appendChild(div);
}
</script>


<script>
function categoryModalForm() {
    const modal = document.getElementById('categoryModal');
    return modal ? modal.querySelector('form') : null;
}

function setCategoryField(form, name, value) {
    const field = form ? form.querySelector('[name="' + name + '"]') : null;
    if (!field) return;

    if (field.type === 'checkbox') {
        field.checked = value === 1 || value === '1' || value === true;
        return;
    }

    field.value = value ?? '';
}

function openCategoryModal(data = null) {
    const modal = document.getElementById('categoryModal');
    const form = categoryModalForm();

    if (!modal || !form) return;

    form.reset();

    const actionField = form.querySelector('[name="action"]');
    const idField = form.querySelector('[name="id"]');
    const title = document.getElementById('categoryModalTitle');
    const submitButton = document.getElementById('categorySubmitButton');
    const parentSelect = form.querySelector('[name="parent_id"]');

    if (parentSelect) {
        Array.from(parentSelect.options).forEach(function (option) {
            option.disabled = false;
        });
    }

    if (data && data.id) {
        if (actionField) actionField.value = 'edit_category';
        if (idField) idField.value = data.id;
        if (title) title.textContent = 'Edit Kategori';
        if (submitButton) submitButton.textContent = 'Update Kategori';

        setCategoryField(form, 'name', data.name || '');
        setCategoryField(form, 'slug', data.slug || '');
        setCategoryField(form, 'parent_id', data.parent_id || '');
        setCategoryField(form, 'sort_order', data.sort_order || 0);
        setCategoryField(form, 'is_active', data.is_active ?? 1);

        if (parentSelect) {
            Array.from(parentSelect.options).forEach(function (option) {
                option.disabled = option.value !== '' && Number(option.value) === Number(data.id || 0);
            });
        }
    } else {
        if (actionField) actionField.value = 'add_category';
        if (idField) idField.value = '';
        if (title) title.textContent = 'Tambah Kategori';
        if (submitButton) submitButton.textContent = 'Simpan Kategori';

        setCategoryField(form, 'parent_id', '');
        setCategoryField(form, 'sort_order', '');
        setCategoryField(form, 'is_active', 1);
    }

    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeCategoryModal() {
    const modal = document.getElementById('categoryModal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

function toggleDigitalDeliveryFields() {
    const productType = document.getElementById('productTypeSelect');
    const deliveryType = document.getElementById('deliveryTypeSelect');
    const digitalBox = document.getElementById('digitalDeliveryBox');
    const licenseBox = document.getElementById('licenseStockBox');
    const bundleBox = document.getElementById('bundleBuilderBox');
    const serviceBox = document.getElementById('serviceProductBox');

    if (!productType || !digitalBox || !licenseBox || !bundleBox) return;

    const type = productType.value || 'physical';
    const method = deliveryType ? (deliveryType.value || 'manual') : 'manual';

    const isDigital = type === 'digital';
    const isLicense = type === 'license';
    const isBundle = type === 'bundle';
    const isService = type === 'service';

    digitalBox.classList.toggle('show', isDigital);
    licenseBox.classList.toggle('show', isLicense);
    bundleBox.classList.toggle('show', isBundle);
    if (serviceBox) serviceBox.classList.toggle('show', isService);

    if (deliveryType) {
        deliveryType.disabled = false;

        if (isLicense) {
            deliveryType.value = 'license_stock';
        } else if (isBundle || isService) {
            deliveryType.value = 'none';
        } else if (isDigital && ['license_stock', 'none', ''].includes(deliveryType.value || '')) {
            deliveryType.value = 'manual';
        }
    }

    const activeMethod = deliveryType ? (deliveryType.value || 'manual') : method;
    const manualNote = document.getElementById('manualDeliveryNote');
    if (manualNote) {
        manualNote.classList.toggle('show', isDigital && activeMethod === 'manual');
    }

    document.querySelectorAll('.digital-url-field').forEach(el => {
        el.style.display = (isDigital && ['external_link', 'gdrive', 'canva'].includes(activeMethod)) ? '' : 'none';
    });

    document.querySelectorAll('.digital-file-field').forEach(el => {
        el.style.display = (isDigital && activeMethod === 'file') ? '' : 'none';
    });

    document.querySelectorAll('.digital-content-field').forEach(el => {
        el.style.display = (isDigital && activeMethod === 'html_content') ? '' : 'none';
    });
}

document.addEventListener('DOMContentLoaded', toggleDigitalDeliveryFields);

function productModalForm() {
    const modal = document.getElementById('productModal');
    return modal ? modal.querySelector('form') : null;
}

function setProductField(form, name, value) {
    const field = form ? form.querySelector('[name="' + name + '"]') : null;
    if (!field) return;

    if (field.type === 'checkbox') {
        field.checked = value === 1 || value === '1' || value === true;
        return;
    }

    field.value = value ?? '';
}

function resetVariantRows() {
    const list = document.getElementById('variantList');
    if (!list) return;

    const rows = Array.from(list.querySelectorAll('[data-variant-row]'));
    rows.forEach((row, index) => {
        if (index > 0) row.remove();
    });

    const first = list.querySelector('[data-variant-row]');
    if (first) {
        first.querySelectorAll('input').forEach(input => input.value = '');
    }

    refreshVariantNames();
}

function setVariantRows(variants) {
    resetVariantRows();

    const list = document.getElementById('variantList');
    if (!list) return;

    const cleanVariants = Array.isArray(variants) ? variants.filter(Boolean) : [];

    if (!cleanVariants.length) {
        return;
    }

    const first = list.querySelector('[data-variant-row]');
    if (!first) return;

    cleanVariants.forEach((variant, index) => {
        let row = index === 0 ? first : first.cloneNode(true);

        if (index > 0) {
            list.appendChild(row);
        }

        const map = {
            type: variant.type || '',
            size: variant.size || '',
            price: variant.price === null ? '' : (variant.price || ''),
            stock: variant.stock === null ? '' : (variant.stock || ''),
            image_url: variant.image_url || ''
        };

        Object.keys(map).forEach(function (key) {
            const input = row.querySelector('input[name*="[' + key + ']"]');
            if (input) input.value = map[key];
        });
    });

    refreshVariantNames();
}

function resetBundleRows() {
    const list = document.getElementById('bundleList');
    if (!list) return;

    const rows = Array.from(list.querySelectorAll('[data-bundle-row]'));
    rows.forEach((row, index) => {
        if (index > 0) row.remove();
    });

    const first = list.querySelector('[data-bundle-row]');
    if (first) {
        first.querySelectorAll('select, input').forEach(field => {
            if (field.tagName === 'SELECT') {
                field.value = '';
            } else if (field.name.includes('[qty]')) {
                field.value = '1';
            } else {
                field.value = '';
            }
        });
    }

    refreshBundleNames();
}

function setBundleRows(items) {
    resetBundleRows();

    const list = document.getElementById('bundleList');
    if (!list) return;

    const cleanItems = Array.isArray(items) ? items.filter(Boolean) : [];

    if (!cleanItems.length) {
        return;
    }

    const first = list.querySelector('[data-bundle-row]');
    if (!first) return;

    cleanItems.forEach((item, index) => {
        let row = index === 0 ? first : first.cloneNode(true);

        if (index > 0) {
            list.appendChild(row);
        }

        const select = row.querySelector('select[name*="[component_product_id]"]');
        const qty = row.querySelector('input[name*="[qty]"]');

        if (select) select.value = item.component_product_id || '';
        if (qty) qty.value = item.qty || 1;
    });

    refreshBundleNames();
}

function refreshBundleNames() {
    const list = document.getElementById('bundleList');
    if (!list) return;

    list.querySelectorAll('[data-bundle-row]').forEach((row, index) => {
        row.querySelectorAll('select, input').forEach(field => {
            field.name = field.name.replace(/bundle_items\[\d+\]/, 'bundle_items[' + index + ']');
        });
    });
}

function addBundleRow() {
    const list = document.getElementById('bundleList');
    if (!list) return;

    const first = list.querySelector('[data-bundle-row]');
    if (!first) return;

    const clone = first.cloneNode(true);
    clone.querySelectorAll('select, input').forEach(field => {
        if (field.tagName === 'SELECT') {
            field.value = '';
        } else if (field.name.includes('[qty]')) {
            field.value = '1';
        } else {
            field.value = '';
        }
    });

    list.appendChild(clone);
    refreshBundleNames();
}

function removeBundleRow(button) {
    const list = document.getElementById('bundleList');
    const row = button.closest('[data-bundle-row]');
    if (!list || !row) return;

    const rows = list.querySelectorAll('[data-bundle-row]');

    if (rows.length <= 1) {
        row.querySelectorAll('select, input').forEach(field => {
            if (field.tagName === 'SELECT') {
                field.value = '';
            } else if (field.name.includes('[qty]')) {
                field.value = '1';
            } else {
                field.value = '';
            }
        });
        return;
    }

    row.remove();
    refreshBundleNames();
}

function openProductModal(productData = null) {
    const modal = document.getElementById('productModal');
    const form = productModalForm();
    if (!modal || !form) return;

    form.reset();
    resetVariantRows();
    resetBundleRows();

    const actionField = form.querySelector('[name="action"]');
    const idField = form.querySelector('[name="id"]');
    const title = document.getElementById('productModalTitle');
    const submitButton = form.querySelector('button[type="submit"]');

    if (productData && productData.id) {
        if (actionField) actionField.value = 'edit_product';
        if (idField) idField.value = productData.id;
        if (title) title.textContent = 'Edit Produk';
        if (submitButton) submitButton.textContent = 'Update Produk';

        [
            'category_id',
            'name',
            'sku',
            'unit',
            'price',
            'sale_price',
            'stock',
            'stock_type',
            'weight_gram',
            'product_type',
            'delivery_type',
            'badge',
            'delivery_title',
            'delivery_url',
            'delivery_file_path',
            'delivery_button_label',
            'access_expires_days',
            'delivery_content',
            'delivery_instruction',
            'license_low_stock_alert',
            'main_image',
            'gallery_images',
            'description'
        ].forEach(function (name) {
            setProductField(form, name, productData[name] ?? '');
        });

        setProductField(form, 'digital_auto_deliver', productData.digital_auto_deliver ?? 1);
        setProductField(form, 'is_active', productData.is_active ?? 1);
        setProductField(form, 'license_button_label', productData.delivery_button_label || 'Lihat Lisensi');

        const licenseLines = form.querySelector('[name="license_stock_lines"]');
        if (licenseLines) {
            licenseLines.value = '';
            licenseLines.placeholder = 'Isi hanya kode baru kalau ingin menambah stok kode.';
        }

        setVariantRows(productData.variants || []);
        setBundleRows(productData.bundle_items || []);
    } else {
        if (actionField) actionField.value = 'add_product';
        if (idField) idField.value = '';
        if (title) title.textContent = 'Tambah Produk';
        if (submitButton) submitButton.textContent = 'Simpan Produk';

        setProductField(form, 'product_type', 'physical');
        setProductField(form, 'delivery_type', 'manual');
        setProductField(form, 'stock_type', 'limited');
        setProductField(form, 'delivery_button_label', 'Buka Akses');
        setProductField(form, 'license_button_label', 'Lihat Lisensi');
        setProductField(form, 'access_expires_days', '0');
        setProductField(form, 'license_low_stock_alert', '5');
        setProductField(form, 'digital_auto_deliver', 1);
        setProductField(form, 'is_active', 1);
    }

    toggleDigitalDeliveryFields();

    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeProductModal() {
    const modal = document.getElementById('productModal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

function refreshVariantNames() {
    const list = document.getElementById('variantList');
    if (!list) return;

    list.querySelectorAll('[data-variant-row]').forEach((row, index) => {
        row.querySelectorAll('input').forEach(input => {
            input.name = input.name
                .replace(/variants\[\d+\]/, 'variants[' + index + ']')
                .replace(/variant_image_uploads\[\d+\]/, 'variant_image_uploads[' + index + ']');
        });
    });
}

function addVariantRow() {
    const list = document.getElementById('variantList');
    if (!list) return;

    const first = list.querySelector('[data-variant-row]');
    if (!first) return;

    const clone = first.cloneNode(true);
    clone.querySelectorAll('input').forEach(input => input.value = '');
    list.appendChild(clone);
    refreshVariantNames();
}

function removeVariantRow(button) {
    const list = document.getElementById('variantList');
    const row = button.closest('[data-variant-row]');
    if (!list || !row) return;

    const rows = list.querySelectorAll('[data-variant-row]');
    if (rows.length <= 1) {
        row.querySelectorAll('input').forEach(input => input.value = '');
        return;
    }

    row.remove();
    refreshVariantNames();
}

document.addEventListener('click', function(event) {
    const modal = document.getElementById('productModal');
    if (!modal || !modal.classList.contains('show')) return;

    if (event.target === modal) {
        closeProductModal();
    }
});

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        closeProductModal();
    }
});
</script>


<script>
function openCustomerModal() {
    const modal = document.getElementById('customerModal');
    if (!modal) return;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeCustomerModal() {
    const modal = document.getElementById('customerModal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

function openUserModal() {
    const modal = document.getElementById('userModal');
    if (!modal) return;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeUserModal() {
    const modal = document.getElementById('userModal');
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

document.addEventListener('click', function(event) {
    ['customerModal', 'userModal'].forEach(function(id) {
        const modal = document.getElementById(id);
        if (modal && modal.classList.contains('show') && event.target === modal) {
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }
    });
});

document.addEventListener('keydown', function(event) {
    if (event.key !== 'Escape') return;

    ['customerModal', 'userModal'].forEach(function(id) {
        const modal = document.getElementById(id);
        if (modal && modal.classList.contains('show')) {
            modal.classList.remove('show');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }
    });
});
</script>


<script>
(function () {
    const tableWraps = Array.from(document.querySelectorAll('.table-wrap'));
    if (!tableWraps.length) return;

    tableWraps.forEach(function (wrap, index) {
        if (wrap.dataset.searchPaginationReady === '1') return;

        const table = wrap.querySelector('table.table');
        if (!table) return;

        const tbody = table.querySelector('tbody');
        if (!tbody) return;

        const originalRows = Array.from(tbody.querySelectorAll('tr')).filter(function (row) {
            const text = (row.textContent || '').trim().toLowerCase();
            if (!text) return false;
            if (row.querySelector('.product-modal-overlay')) return false;
            if (row.children.length === 1 && (text.includes('belum ada') || text.includes('tidak ada'))) return false;
            return true;
        });

        if (!originalRows.length) return;

        wrap.dataset.searchPaginationReady = '1';

        const perPage = parseInt(wrap.dataset.perPage || '10', 10);
        let currentPage = 1;

        const tools = document.createElement('div');
        tools.className = 'admin-table-tools';

        const search = document.createElement('input');
        search.type = 'search';
        search.className = 'admin-table-search';
        search.placeholder = 'Cari data di tabel ini...';

        const info = document.createElement('div');
        info.className = 'admin-table-info';

        tools.appendChild(search);
        tools.appendChild(info);
        table.parentNode.insertBefore(tools, table);

        const empty = document.createElement('div');
        empty.className = 'admin-table-empty';
        empty.textContent = 'Tidak ada data yang cocok dengan pencarian.';
        table.parentNode.insertBefore(empty, table.nextSibling);

        const pagination = document.createElement('div');
        pagination.className = 'admin-table-pagination';

        const prev = document.createElement('button');
        prev.type = 'button';
        prev.textContent = 'Sebelumnya';

        const pageText = document.createElement('span');
        pageText.textContent = 'Halaman 1';

        const next = document.createElement('button');
        next.type = 'button';
        next.textContent = 'Berikutnya';

        pagination.appendChild(prev);
        pagination.appendChild(pageText);
        pagination.appendChild(next);
        table.parentNode.insertBefore(pagination, empty.nextSibling);

        function getSearchText(row) {
            if (!row.dataset.adminSearchText) {
                row.dataset.adminSearchText = (row.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
            }
            return row.dataset.adminSearchText;
        }

        function getFilteredRows() {
            const keyword = (search.value || '').trim().toLowerCase();

            if (!keyword) {
                return originalRows;
            }

            return originalRows.filter(function (row) {
                return getSearchText(row).includes(keyword);
            });
        }

        function render() {
            const filtered = getFilteredRows();
            const totalRows = filtered.length;
            const totalPages = Math.max(1, Math.ceil(totalRows / perPage));

            if (currentPage > totalPages) currentPage = totalPages;
            if (currentPage < 1) currentPage = 1;

            const start = (currentPage - 1) * perPage;
            const end = start + perPage;

            originalRows.forEach(function (row) {
                row.style.display = 'none';
            });

            filtered.slice(start, end).forEach(function (row) {
                row.style.display = '';
            });

            empty.style.display = totalRows ? 'none' : 'block';
            table.style.display = totalRows ? '' : 'none';

            pagination.style.display = totalRows > perPage ? 'flex' : 'none';

            info.textContent = totalRows + ' data ditemukan';
            pageText.textContent = 'Halaman ' + currentPage + ' dari ' + totalPages;

            prev.disabled = currentPage <= 1;
            next.disabled = currentPage >= totalPages;
        }

        search.addEventListener('input', function () {
            currentPage = 1;
            render();
        });

        prev.addEventListener('click', function () {
            currentPage = Math.max(1, currentPage - 1);
            render();
            wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        next.addEventListener('click', function () {
            currentPage += 1;
            render();
            wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });

        render();
    });
})();
</script>


<script>
function openOrderDetailModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;

    // Modal order dirender di dalam <tr style="display:none"> agar tabel tetap rapi.
    // Saat akan dibuka, pindahkan overlay ke <body> supaya tidak ikut tersembunyi oleh parent row.
    if (modal.parentElement !== document.body) {
        document.body.appendChild(modal);
    }

    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function closeOrderDetailModal(id) {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

document.addEventListener('click', function(event) {
    if (!event.target.classList || !event.target.classList.contains('product-modal-overlay')) return;
    if (!event.target.id || !event.target.id.startsWith('orderDetailModal-')) return;
    closeOrderDetailModal(event.target.id);
});

document.addEventListener('keydown', function(event) {
    if (event.key !== 'Escape') return;
    document.querySelectorAll('[id^="orderDetailModal-"].show').forEach(function(modal) {
        closeOrderDetailModal(modal.id);
    });
});
</script>


<script>
function adminEscapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, function (char) {
        return ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        })[char];
    });
}

async function searchAdminRajaOrigin() {
    const input = document.getElementById('adminRajaOriginSearch');
    const results = document.getElementById('adminRajaOriginResults');
    const query = (input ? input.value : '').trim();
    const csrf = document.querySelector('input[name="_csrf"]')?.value || '';

    if (!results) return;

    if (query.length < 3) {
        results.innerHTML = '<div class="setting-section-note">Ketik minimal 3 huruf lokasi toko/gudang.</div>';
        return;
    }

    results.innerHTML = '<div class="setting-section-note">Mencari lokasi RajaOngkir...</div>';

    const fd = new FormData();
    fd.append('_csrf', csrf);
    fd.append('action', 'rajaongkir_search_origin_admin');
    fd.append('query', query);

    try {
        const res = await fetch('admin.php?view=settings', { method: 'POST', body: fd });
        const data = await res.json();

        if (!data.success) {
            results.innerHTML = '<div class="setting-section-note">' + adminEscapeHtml(data.message || 'Gagal mencari origin.') + '</div>';
            return;
        }

        const rows = Array.isArray(data.data) ? data.data : [];
        if (!rows.length) {
            results.innerHTML = '<div class="setting-section-note">Lokasi tidak ditemukan. Coba kata kunci lain.</div>';
            return;
        }

        results.innerHTML = rows.map(function (row) {
            const label = row.label || ('ID ' + row.id);
            return `
                <button class="btn btn-light" type="button" style="justify-content:flex-start;text-align:left;white-space:normal;line-height:1.5;"
                    onclick="setAdminRajaOrigin('${adminEscapeHtml(String(row.id))}', '${encodeURIComponent(label)}')">
                    ID ${adminEscapeHtml(String(row.id))} — ${adminEscapeHtml(label)}
                </button>
            `;
        }).join('');
    } catch (error) {
        results.innerHTML = '<div class="setting-section-note">Gagal menghubungi API. Cek API key Shipping Cost dan koneksi server.</div>';
    }
}

function setAdminRajaOrigin(id, encodedLabel) {
    const originInput = document.querySelector('input[name="rajaongkir_origin_id"]');
    const labelInput = document.querySelector('input[name="rajaongkir_origin_label"]');
    const results = document.getElementById('adminRajaOriginResults');
    const label = decodeURIComponent(encodedLabel || '');

    if (originInput) originInput.value = id;
    if (labelInput) labelInput.value = label;

    if (results) {
        results.innerHTML = '<div class="setting-section-note">Origin dipilih: <strong>ID ' + adminEscapeHtml(id) + '</strong> — ' + adminEscapeHtml(label) + '. Jangan lupa klik Simpan Pengaturan.</div>';
    }
}
</script>

</body>
</html>
