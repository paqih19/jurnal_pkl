
<?php
// api/notifications_get.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

/**
 * Mengirim respons JSON.
 */
function notificationJsonResponse(
    array $data,
    int $httpCode = 200
): never {
    http_response_code($httpCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

// Endpoint ini hanya menerima GET.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');

    notificationJsonResponse([
        'success' => false,
        'message' => 'Metode permintaan tidak diizinkan.',
        'unread' => 0,
        'items' => []
    ], 405);
}

// Pastikan pengguna sudah login.
if (empty($_SESSION['user_id'])) {
    notificationJsonResponse([
        'success' => false,
        'message' => 'Silakan login terlebih dahulu.',
        'unread' => 0,
        'items' => []
    ], 401);
}

$uid = (int) $_SESSION['user_id'];

if ($uid <= 0) {
    notificationJsonResponse([
        'success' => false,
        'message' => 'Sesi pengguna tidak valid.',
        'unread' => 0,
        'items' => []
    ], 401);
}

/**
 * Mengubah tanggal menjadi teks waktu.
 */
function notificationTimeAgo(?string $date): string
{
    if (!$date) {
        return '-';
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return '-';
    }

    $diff = max(0, time() - $timestamp);

    if ($diff < 60) {
        return 'baru saja';
    }

    if ($diff < 3600) {
        return floor($diff / 60) . ' menit lalu';
    }

    if ($diff < 86400) {
        return floor($diff / 3600) . ' jam lalu';
    }

    if ($diff < 2592000) {
        return floor($diff / 86400) . ' hari lalu';
    }

    if ($diff < 31536000) {
        return date('d M', $timestamp);
    }

    return date('d M Y', $timestamp);
}

try {
    // Ambil jumlah notifikasi yang belum dibaca.
    $unread = (int) unreadNotifCount($pdo, $uid);

    // Ambil maksimal 20 notifikasi terbaru.
    $items = getNotifications($pdo, $uid, 20);

    if (!is_array($items)) {
        $items = [];
    }

    $formattedItems = [];

    foreach ($items as $notification) {
        if (!is_array($notification)) {
            continue;
        }

        $createdAt = isset($notification['created_at'])
            ? (string) $notification['created_at']
            : null;

        // Normalisasi status dibaca.
        $notification['is_read'] = !empty($notification['is_read']);

        // Tambahkan informasi waktu.
        $notification['time_ago'] = notificationTimeAgo($createdAt);

        $formattedItems[] = $notification;
    }

    notificationJsonResponse([
        'success' => true,
        'message' => 'Notifikasi berhasil dimuat.',
        'unread' => max(0, $unread),
        'items' => $formattedItems
    ]);

} catch (Throwable $e) {

    // Simpan detail error di log server, bukan di respons pengguna.
    error_log(
        '[Notifications API] ' . $e->getMessage()
    );

    notificationJsonResponse([
        'success' => false,
        'message' => 'Notifikasi gagal dimuat. Silakan coba lagi.',
        'unread' => 0,
        'items' => []
    ], 500);
}
