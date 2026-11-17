<?php
// api/obrolan_edit.php

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function chatEditResponse(
    string $status,
    string $message,
    array $extra = [],
    int $httpCode = 200
): never {
    http_response_code($httpCode);

    echo json_encode(
        array_merge(['status' => $status, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

// Hanya POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    chatEditResponse('error', 'Metode permintaan tidak diizinkan.', [], 405);
}

// Login
if (empty($_SESSION['user_id'])) {
    chatEditResponse('error', 'Silakan login terlebih dahulu.', [], 401);
}

$uid = (int) $_SESSION['user_id'];

if ($uid <= 0) {
    chatEditResponse('error', 'Sesi pengguna tidak valid.', [], 401);
}

// Input JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    chatEditResponse('error', 'Data permintaan tidak valid.', [], 400);
}

$id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);

if ($id === false || $id === null || $id <= 0) {
    chatEditResponse('error', 'ID pesan tidak valid.', [], 422);
}

// Validasi isi pesan
if (!isset($input['content']) || !is_string($input['content'])) {
    chatEditResponse('error', 'Isi pesan tidak valid.', [], 422);
}

$content = trim($input['content']);

if ($content === '') {
    chatEditResponse('error', 'Isi pesan tidak boleh kosong.', [], 422);
}

if (mb_strlen($content, 'UTF-8') > 1000) {
    chatEditResponse('error', 'Pesan maksimal 1000 karakter.', [], 422);
}

try {
    $pdo->beginTransaction();

    // Ambil pesan + verifikasi akses
    $check = $pdo->prepare("
        SELECT m.id, m.user_id, m.room_id, m.content, m.created_at, m.deleted_at
        FROM messages m
        INNER JOIN chat_members cm
            ON cm.room_id = m.room_id
        WHERE m.id = ?
          AND cm.user_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $check->execute([$id, $uid]);
    $message = $check->fetch(PDO::FETCH_ASSOC);

    if (!$message) {
        $pdo->rollBack();
        chatEditResponse('error', 'Pesan tidak ditemukan atau akses ditolak.', [], 404);
    }

    if ((int) $message['user_id'] !== $uid) {
        $pdo->rollBack();
        chatEditResponse('error', 'Kamu hanya dapat mengedit pesan milik sendiri.', [], 403);
    }

    if (!empty($message['deleted_at'])) {
        $pdo->rollBack();
        chatEditResponse('error', 'Pesan yang sudah dihapus tidak dapat diedit.', [], 409);
    }

    // Batas waktu edit 15 menit
    $createdAt = strtotime($message['created_at']);

    if ($createdAt === false || (time() - $createdAt) > 900) {
        $pdo->rollBack();
        chatEditResponse('error', 'Waktu edit pesan sudah habis (maksimal 15 menit).', [], 409);
    }

    // Tidak ada perubahan
    if ($message['content'] === $content) {
        $pdo->rollBack();
        chatEditResponse('success', 'Tidak ada perubahan pada pesan.', [
            'id'        => $id,
            'unchanged' => true,
        ]);
    }

    // Update
    $update = $pdo->prepare("
        UPDATE messages
        SET content = ?, edited_at = NOW()
        WHERE id = ?
          AND user_id = ?
          AND deleted_at IS NULL
    ");

    $update->execute([$content, $id, $uid]);

    $pdo->commit();

    chatEditResponse('success', 'Pesan berhasil diperbarui.', [
        'id'      => $id,
        'content' => $content,
        'edited'  => true,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('[Obrolan Edit] ' . $e->getMessage());

    chatEditResponse('error', 'Terjadi kesalahan saat mengedit pesan.', [], 500);
}