
<?php
// api/obrolan_create_private.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

/**
 * Respons JSON.
 */
function privateChatResponse(
    string $status,
    string $message,
    array $extra = [],
    int $httpCode = 200
): never {
    http_response_code($httpCode);

    echo json_encode(
        array_merge([
            'status' => $status,
            'message' => $message
        ], $extra),
        JSON_UNESCAPED_UNICODE |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

// Hanya menerima POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    privateChatResponse(
        'error',
        'Metode permintaan tidak diizinkan.',
        [],
        405
    );
}

// Periksa login.
if (empty($_SESSION['user_id'])) {
    privateChatResponse(
        'error',
        'Silakan login terlebih dahulu.',
        [],
        401
    );
}

$uid = (int) $_SESSION['user_id'];

if ($uid <= 0) {
    privateChatResponse(
        'error',
        'Sesi pengguna tidak valid.',
        [],
        401
    );
}

// Ambil input JSON.
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    privateChatResponse(
        'error',
        'Data permintaan tidak valid.',
        [],
        400
    );
}

// Validasi ID pengguna tujuan.
$otherId = filter_var(
    $input['user_id'] ?? null,
    FILTER_VALIDATE_INT
);

if (
    $otherId === false ||
    $otherId === null ||
    $otherId <= 0 ||
    $otherId === $uid
) {
    privateChatResponse(
        'error',
        'Pengguna tujuan tidak valid.',
        [],
        422
    );
}

try {
    // Pastikan pengguna tujuan tersedia.
    $chk = $pdo->prepare("
        SELECT id, name
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $chk->execute([$otherId]);

    $otherUser = $chk->fetch(PDO::FETCH_ASSOC);

    if (!$otherUser) {
        privateChatResponse(
            'error',
            'Pengguna tidak ditemukan.',
            [],
            404
        );
    }

    // Pastikan akun pembuat juga masih tersedia.
    $senderQuery = $pdo->prepare("
        SELECT name
        FROM users
        WHERE id = ?
        LIMIT 1
    ");

    $senderQuery->execute([$uid]);

    $senderName = $senderQuery->fetchColumn();

    if ($senderName === false) {
        privateChatResponse(
            'error',
            'Akun pengguna tidak ditemukan.',
            [],
            404
        );
    }

    // Cari room private yang sudah ada.
    $findRoom = $pdo->prepare("
        SELECT r.id
        FROM chat_rooms r
        WHERE r.type = 'private'

          AND (
              SELECT COUNT(*)
              FROM chat_members cm
              WHERE cm.room_id = r.id
          ) = 2

          AND EXISTS (
              SELECT 1
              FROM chat_members cm
              WHERE cm.room_id = r.id
                AND cm.user_id = ?
          )

          AND EXISTS (
              SELECT 1
              FROM chat_members cm
              WHERE cm.room_id = r.id
                AND cm.user_id = ?
          )

        ORDER BY r.id ASC
        LIMIT 1
    ");

    $findRoom->execute([
        $uid,
        $otherId
    ]);

    $existingRoom = $findRoom->fetchColumn();

    // Jika room sudah tersedia, gunakan room tersebut.
    if ($existingRoom) {
        privateChatResponse(
            'success',
            'Obrolan pribadi sudah tersedia.',
            [
                'room_id' => (int) $existingRoom,
                'existing' => true
            ]
        );
    }

    // Buat room baru.
    $pdo->beginTransaction();

    $createRoom = $pdo->prepare("
        INSERT INTO chat_rooms
            (name, type, created_by, created_at)
        VALUES
            (NULL, 'private', ?, NOW())
    ");

    $createRoom->execute([$uid]);

    $roomId = (int) $pdo->lastInsertId();

    if ($roomId <= 0) {
        throw new RuntimeException(
            'Room ID tidak berhasil dibuat.'
        );
    }

    // Masukkan kedua pengguna.
    $addMember = $pdo->prepare("
        INSERT INTO chat_members
            (room_id, user_id, joined_at)
        VALUES
            (?, ?, NOW())
    ");

    $addMember->execute([
        $roomId,
        $uid
    ]);

    $addMember->execute([
        $roomId,
        $otherId
    ]);

    // Simpan transaksi.
    $pdo->commit();

    // Kirim notifikasi kepada penerima.
    try {
        createNotification(
            $pdo,
            (int) $otherId,
            'chat',
            '💬 ' . $senderName . ' ingin mengobrol denganmu',
            'Buka obrolan untuk membalas pesan.',
            'obrolan.php'
        );

    } catch (Throwable $e) {
        error_log(
            '[Private Chat Notification] ' . $e->getMessage()
        );
    }

    privateChatResponse(
        'success',
        'Obrolan pribadi berhasil dibuat.',
        [
            'room_id' => $roomId,
            'existing' => false
        ]
    );

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        '[Obrolan Create Private] ' . $e->getMessage()
    );

    privateChatResponse(
        'error',
        'Gagal membuat obrolan. Silakan coba lagi.',
        [],
        500
    );
}
