<?php
// api/obrolan_mark_read.php

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function markReadResponse(string $status, string $message, int $code = 200): never
{
    http_response_code($code);

    echo json_encode(
        ['status' => $status, 'message' => $message],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

// Hanya POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    markReadResponse('error', 'Metode request tidak diizinkan.', 405);
}

// Login
if (empty($_SESSION['user_id'])) {
    markReadResponse('error', 'Silakan login terlebih dahulu.', 401);
}

$uid = (int) $_SESSION['user_id'];

if ($uid <= 0) {
    markReadResponse('error', 'Sesi pengguna tidak valid.', 401);
}

// Baca input
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    markReadResponse('error', 'Data request tidak valid.', 400);
}

$roomId = filter_var($input['room_id'] ?? null, FILTER_VALIDATE_INT);

if ($roomId === false || $roomId === null || $roomId <= 0) {
    markReadResponse('error', 'Ruang obrolan tidak valid.', 400);
}

try {
    $pdo->beginTransaction();

    // Verifikasi keanggotaan
    $checkMember = $pdo->prepare("
        SELECT 1 FROM chat_members
        WHERE room_id = ? AND user_id = ?
        LIMIT 1
    ");
    $checkMember->execute([$roomId, $uid]);

    if (!$checkMember->fetchColumn()) {
        $pdo->rollBack();
        markReadResponse('error', 'Akses ruang obrolan ditolak.', 403);
    }

    // Update last_read_at
    $updateRead = $pdo->prepare("
        UPDATE chat_members
        SET last_read_at = NOW()
        WHERE room_id = ? AND user_id = ?
    ");
    $updateRead->execute([$roomId, $uid]);

    // Tandai pesan orang lain sebagai dibaca (opsional, jika tabel tersedia)
    static $hasMessageReads = null;

    if ($hasMessageReads === null) {
        try {
            $pdo->query("SELECT 1 FROM message_reads LIMIT 1");
            $hasMessageReads = true;
        } catch (Throwable $e) {
            $hasMessageReads = false;
        }
    }

    if ($hasMessageReads) {
        try {
            $messages = $pdo->prepare("
                SELECT m.id
                FROM messages m
                WHERE m.room_id = ?
                  AND m.user_id != ?
                  AND NOT EXISTS (
                      SELECT 1 FROM message_reads mr
                      WHERE mr.message_id = m.id
                        AND mr.user_id = ?
                  )
            ");
            $messages->execute([$roomId, $uid, $uid]);

            $insertRead = $pdo->prepare("
                INSERT IGNORE INTO message_reads (message_id, user_id)
                VALUES (?, ?)
            ");

            foreach ($messages->fetchAll(PDO::FETCH_COLUMN) as $messageId) {
                try {
                    $insertRead->execute([(int) $messageId, $uid]);
                } catch (Throwable $e) {
                    // Abaikan duplikasi/kegagalan individual
                }
            }
        } catch (Throwable $e) {
            error_log('message_reads optional: ' . $e->getMessage());
        }
    }

    // Update last seen (opsional, jika tabel tersedia)
    try {
        $activity = $pdo->prepare("
            INSERT INTO user_activity (user_id, last_seen)
            VALUES (?, NOW())
            ON DUPLICATE KEY UPDATE last_seen = NOW()
        ");
        $activity->execute([$uid]);
    } catch (Throwable $e) {
        error_log('user_activity optional: ' . $e->getMessage());
    }

    $pdo->commit();

    markReadResponse('success', 'Pesan berhasil ditandai sudah dibaca.');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('obrolan_mark_read: ' . $e->getMessage());

    markReadResponse('error', 'Terjadi kesalahan pada server.', 500);
}