<?php
// api/obrolan_send.php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

ini_set('display_errors', '0');
error_reporting(E_ALL);

ob_start();

function sendChatResponse(
    string $status,
    string $message = '',
    ?int $id = null,
    int $code = 200
): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($code);

    $response = ['status' => $status];

    if ($message !== '') {
        $response['message'] = $message;
    }

    if ($id !== null) {
        $response['id'] = $id;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    exit;
}

// Hanya POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    sendChatResponse('error', 'Metode request tidak diizinkan.', null, 405);
}

// Login
if (empty($_SESSION['user_id'])) {
    sendChatResponse('error', 'Silakan login terlebih dahulu.', null, 401);
}

$uid = (int) $_SESSION['user_id'];

if ($uid <= 0) {
    sendChatResponse('error', 'Sesi pengguna tidak valid.', null, 401);
}

// Baca input
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    sendChatResponse('error', 'Data pesan tidak valid.', null, 400);
}

$roomId  = filter_var($input['room_id'] ?? null, FILTER_VALIDATE_INT);
$content = $input['content'] ?? '';
$replyTo = null;

if ($roomId === false || $roomId === null || $roomId <= 0) {
    sendChatResponse('error', 'Ruang obrolan tidak valid.', null, 400);
}

if (!is_string($content)) {
    sendChatResponse('error', 'Format pesan tidak valid.', null, 400);
}

$content = trim($content);

if ($content === '') {
    sendChatResponse('error', 'Pesan tidak boleh kosong.', null, 400);
}

if (mb_strlen($content, 'UTF-8') > 1000) {
    sendChatResponse('error', 'Pesan maksimal 1000 karakter.', null, 400);
}

// Validasi reply_to_id
if (isset($input['reply_to_id']) && $input['reply_to_id'] !== '') {
    $replyTo = filter_var($input['reply_to_id'], FILTER_VALIDATE_INT);

    if ($replyTo === false || $replyTo === null || $replyTo <= 0) {
        sendChatResponse('error', 'Pesan balasan tidak valid.', null, 400);
    }
}

try {
    $pdo->beginTransaction();

    // Verifikasi keanggotaan
    $checkMember = $pdo->prepare("
        SELECT 1 FROM chat_members
        WHERE room_id = ? AND user_id = ?
        LIMIT 1
        FOR UPDATE
    ");
    $checkMember->execute([$roomId, $uid]);

    if (!$checkMember->fetchColumn()) {
        $pdo->rollBack();
        sendChatResponse('error', 'Kamu bukan anggota ruang obrolan ini.', null, 403);
    }

    // Deteksi kolom reply_to_id (cache per request)
    static $hasReply = null;

    if ($hasReply === null) {
        try {
            $columns = $pdo->query("SHOW COLUMNS FROM messages")->fetchAll(PDO::FETCH_COLUMN);
            $hasReply = in_array('reply_to_id', $columns, true);
        } catch (Throwable $e) {
            $hasReply = false;
            error_log('obrolan_send columns: ' . $e->getMessage());
        }
    }

    // Validasi pesan yang dibalas
    if ($replyTo !== null) {
        if (!$hasReply) {
            $pdo->rollBack();
            sendChatResponse('error', 'Fitur balas pesan belum tersedia.', null, 400);
        }

        $checkReply = $pdo->prepare("
            SELECT id FROM messages
            WHERE id = ?
              AND room_id = ?
              AND (deleted_at IS NULL OR deleted_at = '')
            LIMIT 1
        ");
        $checkReply->execute([$replyTo, $roomId]);

        if (!$checkReply->fetchColumn()) {
            $pdo->rollBack();
            sendChatResponse('error', 'Pesan yang ingin dibalas tidak ditemukan.', null, 400);
        }
    }

    // Simpan pesan
    if ($hasReply && $replyTo !== null) {
        $insert = $pdo->prepare("
            INSERT INTO messages (user_id, room_id, content, reply_to_id, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $insert->execute([$uid, $roomId, $content, $replyTo]);
    } else {
        $insert = $pdo->prepare("
            INSERT INTO messages (user_id, room_id, content, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $insert->execute([$uid, $roomId, $content]);
    }

    $msgId = (int) $pdo->lastInsertId();

    $pdo->commit();

    // Update last seen (opsional)
    try {
        $activity = $pdo->prepare("
            INSERT INTO user_activity (user_id, last_seen)
            VALUES (?, NOW())
            ON DUPLICATE KEY UPDATE last_seen = NOW()
        ");
        $activity->execute([$uid]);
    } catch (Throwable $e) {
        error_log('obrolan_send activity: ' . $e->getMessage());
    }

    // Kirim notifikasi (tidak memblokir keberhasilan)
    try {
        $stmtRoom = $pdo->prepare("SELECT name, type FROM chat_rooms WHERE id = ? LIMIT 1");
        $stmtRoom->execute([$roomId]);
        $room = $stmtRoom->fetch(PDO::FETCH_ASSOC);

        $roomName = $room['name'] ?? 'Obrolan';
        $roomType = $room['type'] ?? 'group';

        $stmtUser = $pdo->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
        $stmtUser->execute([$uid]);
        $senderName = $stmtUser->fetchColumn() ?: 'Seseorang';

        $stmtMembers = $pdo->prepare("
            SELECT user_id FROM chat_members
            WHERE room_id = ? AND user_id != ?
        ");
        $stmtMembers->execute([$roomId, $uid]);

        $otherIds = array_map('intval', $stmtMembers->fetchAll(PDO::FETCH_COLUMN));

        if (!empty($otherIds)) {
            $short = mb_strlen($content, 'UTF-8') > 60
                ? mb_substr($content, 0, 60, 'UTF-8') . '...'
                : $content;

            $title = $roomType === 'private'
                ? "💬 Pesan baru dari {$senderName}"
                : "💬 Obrolan baru di {$roomName}";

            createNotificationBulk(
                $pdo,
                $otherIds,
                'chat',
                $title,
                $senderName . ': ' . $short,
                'obrolan.php'
            );
        }
    } catch (Throwable $e) {
        error_log('obrolan_send notification: ' . $e->getMessage());
    }

    sendChatResponse('success', 'Pesan berhasil dikirim.', $msgId);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('obrolan_send: ' . $e->getMessage());

    sendChatResponse('error', 'Terjadi kesalahan saat mengirim pesan.', null, 500);
}