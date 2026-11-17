<?php
// api/notif_fetch.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

/**
 * Kirim respons JSON.
 */
function notificationJsonResponse(array $data, int $httpCode = 200): never
{
    http_response_code($httpCode);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/**
 * Ubah tanggal menjadi teks waktu relatif.
 */
function notificationTimeAgo(?string $date): string
{
    if (!$date) return '-';

    $timestamp = strtotime($date);
    if ($timestamp === false) return '-';

    $diff = max(0, time() - $timestamp);

    if ($diff < 60)       return 'baru saja';
    if ($diff < 3600)     return floor($diff / 60) . ' menit lalu';
    if ($diff < 86400)    return floor($diff / 3600) . ' jam lalu';
    if ($diff < 2592000)  return floor($diff / 86400) . ' hari lalu';
    if ($diff < 31536000) return date('d M', $timestamp);

    return date('d M Y', $timestamp);
}

// Hanya GET
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');

    notificationJsonResponse([
        'success' => false,
        'message' => 'Metode permintaan tidak diizinkan.',
        'unread'  => 0,
        'items'   => [],
    ], 405);
}

// Harus login
if (empty($_SESSION['user_id'])) {
    notificationJsonResponse([
        'success' => false,
        'message' => 'Silakan login terlebih dahulu.',
        'unread'  => 0,
        'items'   => [],
    ], 401);
}

$uid = (int) $_SESSION['user_id'];

if ($uid <= 0) {
    notificationJsonResponse([
        'success' => false,
        'message' => 'Sesi pengguna tidak valid.',
        'unread'  => 0,
        'items'   => [],
    ], 401);
}

try {
    $unread = (int) unreadNotifCount($pdo, $uid);
    $items  = getNotifications($pdo, $uid, 20);

    if (!is_array($items)) {
        $items = [];
    }

    $formattedItems = [];

    foreach ($items as $notification) {
        if (!is_array($notification)) continue;

        $createdAt = isset($notification['created_at'])
            ? (string) $notification['created_at']
            : null;

        $notification['is_read']  = !empty($notification['is_read']);
        $notification['time_ago'] = notificationTimeAgo($createdAt);

        $formattedItems[] = $notification;
    }

    notificationJsonResponse([
        'success' => true,
        'message' => 'Notifikasi berhasil dimuat.',
        'unread'  => max(0, $unread),
        'items'   => $formattedItems,
    ]);

} catch (Throwable $e) {
    error_log('[Notifications API] ' . $e->getMessage());

    notificationJsonResponse([
        'success' => false,
        'message' => 'Notifikasi gagal dimuat. Silakan coba lagi.',
        'unread'  => 0,
        'items'   => [],
    ], 500);
}