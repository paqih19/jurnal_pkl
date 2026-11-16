
<?php
// api/obrolan_react.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function reactJsonResponse(
    string $status,
    string $message,
    ?string $action = null,
    int $code = 200
): never {
    http_response_code($code);

    $response = [
        'status'  => $status,
        'message' => $message
    ];

    if ($action !== null) {
        $response['action'] = $action;
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

// Login
if (empty($_SESSION['user_id'])) {
    reactJsonResponse('error', 'Silakan login terlebih dahulu.', null, 401);
}

// Hanya POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reactJsonResponse('error', 'Metode request tidak diizinkan.', null, 405);
}

$uid = (int) $_SESSION['user_id'];

// Ambil JSON
$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    reactJsonResponse('error', 'Data request tidak valid.', null, 400);
}

$messageId = filter_var(
    $input['message_id'] ?? null,
    FILTER_VALIDATE_INT
);

$emoji = $input['emoji'] ?? '';

if (!$messageId || $messageId <= 0 || !is_string($emoji)) {
    reactJsonResponse('error', 'Pesan atau emoji tidak valid.', null, 400);
}

$emoji = trim($emoji);

if ($emoji === '') {
    reactJsonResponse('error', 'Silakan pilih emoji reaksi.', null, 400);
}

if (mb_strlen($emoji, 'UTF-8') > 8) {
    reactJsonResponse('error', 'Emoji terlalu panjang.', null, 400);
}

try {
    $pdo->beginTransaction();

    // Pastikan pesan ada, belum dihapus, dan user anggota ruang.
    $checkMessage = $pdo->prepare("
        SELECT m.id
        FROM messages m
        INNER JOIN chat_members cm
            ON cm.room_id = m.room_id
        WHERE m.id = ?
          AND cm.user_id = ?
          AND (
              m.deleted_at IS NULL
              OR m.deleted_at = ''
          )
        LIMIT 1
        FOR UPDATE
    ");

    $checkMessage->execute([$messageId, $uid]);

    if (!$checkMessage->fetchColumn()) {
        $pdo->rollBack();

        reactJsonResponse(
            'error',
            'Pesan tidak ditemukan atau akses ditolak.',
            null,
            403
        );
    }

    // Cek apakah reaksi yang sama sudah ada.
    $checkReaction = $pdo->prepare("
        SELECT 1
        FROM message_reactions
        WHERE message_id = ?
          AND user_id = ?
          AND emoji = ?
        LIMIT 1
    ");

    $checkReaction->execute([
        $messageId,
        $uid,
        $emoji
    ]);

    if ($checkReaction->fetchColumn()) {

        // Jika sudah ada, hapus reaksi.
        $deleteReaction = $pdo->prepare("
            DELETE FROM message_reactions
            WHERE message_id = ?
              AND user_id = ?
              AND emoji = ?
        ");

        $deleteReaction->execute([
            $messageId,
            $uid,
            $emoji
        ]);

        $pdo->commit();

        reactJsonResponse(
            'success',
            'Reaksi berhasil dihapus.',
            'removed'
        );

    } else {

        // Jika belum ada, tambahkan reaksi.
        $insertReaction = $pdo->prepare("
            INSERT INTO message_reactions
                (message_id, user_id, emoji)
            VALUES (?, ?, ?)
        ");

        $insertReaction->execute([
            $messageId,
            $uid,
            $emoji
        ]);

        $pdo->commit();

        reactJsonResponse(
            'success',
            'Reaksi berhasil ditambahkan.',
            'added'
        );
    }

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('obrolan_react: ' . $e->getMessage());

    reactJsonResponse(
        'error',
        'Terjadi kesalahan saat memproses reaksi.',
        null,
        500
    );
}
