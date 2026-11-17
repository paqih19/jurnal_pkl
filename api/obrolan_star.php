<?php
// api/obrolan_star.php

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function starJsonResponse(
    string $status,
    string $message,
    ?string $action = null,
    int $code = 200
): never {
    http_response_code($code);

    $response = ['status' => $status, 'message' => $message];

    if ($action !== null) {
        $response['action'] = $action;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Hanya POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    starJsonResponse('error', 'Metode request tidak diizinkan.', null, 405);
}

// Login
if (empty($_SESSION['user_id'])) {
    starJsonResponse('error', 'Silakan login terlebih dahulu.', null, 401);
}

$uid = (int) $_SESSION['user_id'];

if ($uid <= 0) {
    starJsonResponse('error', 'Sesi pengguna tidak valid.', null, 401);
}

// Baca input
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    starJsonResponse('error', 'Data request tidak valid.', null, 400);
}

$messageId = filter_var($input['message_id'] ?? null, FILTER_VALIDATE_INT);

if ($messageId === false || $messageId === null || $messageId <= 0) {
    starJsonResponse('error', 'Pesan tidak valid.', null, 400);
}

try {
    $pdo->beginTransaction();

    // Verifikasi pesan + akses
    $checkMessage = $pdo->prepare("
        SELECT m.id
        FROM messages m
        INNER JOIN chat_members cm
            ON cm.room_id = m.room_id
        WHERE m.id = ?
          AND cm.user_id = ?
          AND (m.deleted_at IS NULL OR m.deleted_at = '')
        LIMIT 1
        FOR UPDATE
    ");
    $checkMessage->execute([$messageId, $uid]);

    if (!$checkMessage->fetchColumn()) {
        $pdo->rollBack();
        starJsonResponse('error', 'Pesan tidak ditemukan atau akses ditolak.', null, 403);
    }

    // Toggle: cek existing
    $checkStar = $pdo->prepare("
        SELECT 1 FROM message_stars
        WHERE message_id = ? AND user_id = ?
        LIMIT 1
    ");
    $checkStar->execute([$messageId, $uid]);

    if ($checkStar->fetchColumn()) {
        // Hapus bintang
        $deleteStar = $pdo->prepare("
            DELETE FROM message_stars
            WHERE message_id = ? AND user_id = ?
        ");
        $deleteStar->execute([$messageId, $uid]);

        $pdo->commit();

        starJsonResponse('success', 'Pesan dihapus dari pesan berbintang.', 'unstarred');
    }

    // Tambah bintang
    $insertStar = $pdo->prepare("
        INSERT INTO message_stars (message_id, user_id)
        VALUES (?, ?)
    ");
    $insertStar->execute([$messageId, $uid]);

    $pdo->commit();

    starJsonResponse('success', 'Pesan berhasil ditambahkan ke pesan berbintang.', 'starred');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('obrolan_star: ' . $e->getMessage());

    starJsonResponse('error', 'Terjadi kesalahan saat memproses pesan berbintang.', null, 500);
}