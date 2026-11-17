<?php
// api/obrolan_pin.php

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function pinJsonResponse(
    string $status,
    string $message,
    ?bool $pinned = null,
    int $code = 200
): never {
    http_response_code($code);

    $response = [
        'status'  => $status,
        'message' => $message,
    ];

    if ($pinned !== null) {
        $response['pinned'] = $pinned;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Hanya POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    pinJsonResponse('error', 'Metode request tidak diizinkan.', null, 405);
}

// Login
if (empty($_SESSION['user_id'])) {
    pinJsonResponse('error', 'Silakan login terlebih dahulu.', null, 401);
}

$uid = (int) $_SESSION['user_id'];

if ($uid <= 0) {
    pinJsonResponse('error', 'Sesi pengguna tidak valid.', null, 401);
}

// Baca input
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    pinJsonResponse('error', 'Data request tidak valid.', null, 400);
}

$messageId = filter_var($input['message_id'] ?? null, FILTER_VALIDATE_INT);

if ($messageId === false || $messageId === null || $messageId <= 0) {
    pinJsonResponse('error', 'Pesan tidak valid.', null, 400);
}

try {
    // Cek kolom tabel (cache per request)
    static $columns = null;

    if ($columns === null) {
        $columns = $pdo->query("SHOW COLUMNS FROM messages")->fetchAll(PDO::FETCH_COLUMN);
    }

    if (!in_array('is_pinned', $columns, true)) {
        pinJsonResponse(
            'error',
            'Fitur sematkan pesan belum tersedia di database.',
            null,
            500
        );
    }

    $hasDeletedAt = in_array('deleted_at', $columns, true);

    $pdo->beginTransaction();

    // Ambil pesan + verifikasi akses
    $sql = "
        SELECT m.id, m.room_id, m.is_pinned
        FROM messages m
        INNER JOIN chat_members cm
            ON cm.room_id = m.room_id
        WHERE m.id = ?
          AND cm.user_id = ?
    ";

    if ($hasDeletedAt) {
        $sql .= " AND m.deleted_at IS NULL";
    }

    $sql .= " LIMIT 1 FOR UPDATE";

    $check = $pdo->prepare($sql);
    $check->execute([$messageId, $uid]);

    $message = $check->fetch(PDO::FETCH_ASSOC);

    if (!$message) {
        $pdo->rollBack();
        pinJsonResponse('error', 'Pesan tidak ditemukan atau akses ditolak.', null, 403);
    }

    // Toggle pin
    $newStatus = (int) $message['is_pinned'] === 1 ? 0 : 1;

    $update = $pdo->prepare("UPDATE messages SET is_pinned = ? WHERE id = ?");
    $update->execute([$newStatus, $messageId]);

    $pdo->commit();

    pinJsonResponse(
        'success',
        $newStatus ? 'Pesan berhasil disematkan.' : 'Sematan pesan berhasil dilepas.',
        (bool) $newStatus
    );

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('obrolan_pin: ' . $e->getMessage());

    pinJsonResponse(
        'error',
        'Terjadi kesalahan saat memproses sematan pesan.',
        null,
        500
    );
}