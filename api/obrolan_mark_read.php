
<?php
// api/obrolan_mark_read.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function markReadResponse(string $status, string $message, int $code = 200): never
{
    http_response_code($code);

    echo json_encode([
        'status'  => $status,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// Wajib login
if (empty($_SESSION['user_id'])) {
    markReadResponse('error', 'Silakan login terlebih dahulu.', 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    markReadResponse('error', 'Metode request tidak diizinkan.', 405);
}

$uid = (int) $_SESSION['user_id'];

// Baca JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    markReadResponse('error', 'Data request tidak valid.', 400);
}

$roomId = filter_var(
    $input['room_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$roomId || $roomId <= 0) {
    markReadResponse('error', 'Ruang obrolan tidak valid.', 400);
}

try {
    $pdo->beginTransaction();

    // Pastikan pengguna merupakan anggota ruang
    $checkMember = $pdo->prepare("
        SELECT 1
        FROM chat_members
        WHERE room_id = ?
          AND user_id = ?
        LIMIT 1
    ");

    $checkMember->execute([$roomId, $uid]);

    if (!$checkMember->fetchColumn()) {
        $pdo->rollBack();
        markReadResponse('error', 'Akses ruang obrolan ditolak.', 403);
    }

    // Perbarui waktu terakhir membaca
    $updateRead = $pdo->prepare("
        UPDATE chat_members
        SET last_read_at = NOW()
        WHERE room_id = ?
          AND user_id = ?
    ");

    $updateRead->execute([$roomId, $uid]);

    // Catat pesan anggota lain sebagai sudah dibaca.
    // Bagian ini opsional agar fitur utama tetap berjalan
    // apabila tabel message_reads belum tersedia.
    try {
        $checkTable = $pdo->query("
            SHOW TABLES LIKE 'message_reads'
        ");

        if ($checkTable->fetchColumn()) {
            $messages = $pdo->prepare("
                SELECT m.id
                FROM messages m
                WHERE m.room_id = ?
                  AND m.user_id != ?
                  AND NOT EXISTS (
                      SELECT 1
                      FROM message_reads mr
                      WHERE mr.message_id = m.id
                        AND mr.user_id = ?
                  )
            ");

            $messages->execute([$roomId, $uid, $uid]);

            $insertRead = $pdo->prepare("
                INSERT INTO message_reads (message_id, user_id)
                VALUES (?, ?)
            ");

            foreach ($messages->fetchAll(PDO::FETCH_COLUMN) as $messageId) {
                try {
                    $insertRead->execute([
                        (int) $messageId,
                        $uid
                    ]);
                } catch (Throwable $e) {
                    // Abaikan jika terjadi duplikasi atau kegagalan
                    // pada pencatatan pesan individual.
                    error_log('message_reads: ' . $e->getMessage());
                }
            }
        }
    } catch (Throwable $e) {
        error_log('message_reads optional: ' . $e->getMessage());
    }

    // Perbarui aktivitas pengguna
    try {
        $activity = $pdo->prepare("
            INSERT INTO user_activity (user_id, last_seen)
            VALUES (?, NOW())
            ON DUPLICATE KEY UPDATE last_seen = NOW()
        ");

        $activity->execute([$uid]);

    } catch (Throwable $e) {
        error_log('user_activity: ' . $e->getMessage());
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
