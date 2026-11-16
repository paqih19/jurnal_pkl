
<?php
// api/obrolan_edit.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

/**
 * Respons JSON.
 */
function chatEditResponse(
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

    chatEditResponse(
        'error',
        'Metode permintaan tidak diizinkan.',
        [],
        405
    );
}

// Periksa login.
if (empty($_SESSION['user_id'])) {
    chatEditResponse(
        'error',
        'Silakan login terlebih dahulu.',
        [],
        401
    );
}

$uid = (int) $_SESSION['user_id'];

if ($uid <= 0) {
    chatEditResponse(
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
    chatEditResponse(
        'error',
        'Data permintaan tidak valid.',
        [],
        400
    );
}

// Validasi ID pesan.
$id = filter_var(
    $input['id'] ?? null,
    FILTER_VALIDATE_INT
);

if ($id === false || $id === null || $id <= 0) {
    chatEditResponse(
        'error',
        'ID pesan tidak valid.',
        [],
        422
    );
}

// Validasi isi pesan.
if (!isset($input['content']) || !is_string($input['content'])) {
    chatEditResponse(
        'error',
        'Isi pesan tidak valid.',
        [],
        422
    );
}

$content = trim($input['content']);

if ($content === '') {
    chatEditResponse(
        'error',
        'Isi pesan tidak boleh kosong.',
        [],
        422
    );
}

if (mb_strlen($content, 'UTF-8') > 1000) {
    chatEditResponse(
        'error',
        'Pesan maksimal 1000 karakter.',
        [],
        422
    );
}

try {
    $pdo->beginTransaction();

    // Ambil pesan sekaligus pastikan pengguna anggota room.
    $check = $pdo->prepare("
        SELECT
            m.id,
            m.user_id,
            m.room_id,
            m.content,
            m.created_at,
            m.deleted_at
        FROM messages m
        INNER JOIN chat_members cm
            ON cm.room_id = m.room_id
        WHERE m.id = ?
          AND cm.user_id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $check->execute([
        $id,
        $uid
    ]);

    $message = $check->fetch(PDO::FETCH_ASSOC);

    if (!$message) {
        $pdo->rollBack();

        chatEditResponse(
            'error',
            'Pesan tidak ditemukan atau akses ditolak.',
            [],
            404
        );
    }

    // Pastikan pesan milik pengguna.
    if ((int) $message['user_id'] !== $uid) {
        $pdo->rollBack();

        chatEditResponse(
            'error',
            'Kamu hanya dapat mengedit pesan milik sendiri.',
            [],
            403
        );
    }

    // Pesan yang sudah dihapus tidak dapat diedit.
    if (!empty($message['deleted_at'])) {
        $pdo->rollBack();

        chatEditResponse(
            'error',
            'Pesan yang sudah dihapus tidak dapat diedit.',
            [],
            409
        );
    }

    // Periksa batas waktu 15 menit.
    $createdAt = strtotime($message['created_at']);

    if (
        $createdAt === false ||
        time() - $createdAt > 900
    ) {
        $pdo->rollBack();

        chatEditResponse(
            'error',
            'Waktu edit pesan sudah habis (maksimal 15 menit).',
            [],
            409
        );
    }

    // Tidak perlu memperbarui jika isi tidak berubah.
    if ($message['content'] === $content) {
        $pdo->rollBack();

        chatEditResponse(
            'success',
            'Tidak ada perubahan pada pesan.',
            [
                'id' => $id,
                'unchanged' => true
            ]
        );
    }

    // Perbarui pesan.
    $update = $pdo->prepare("
        UPDATE messages
        SET
            content = ?,
            edited_at = NOW()
        WHERE id = ?
          AND user_id = ?
          AND deleted_at IS NULL
    ");

    $update->execute([
        $content,
        $id,
        $uid
    ]);

    $pdo->commit();

    chatEditResponse(
        'success',
        'Pesan berhasil diperbarui.',
        [
            'id' => $id,
            'content' => $content,
            'edited' => true
        ]
    );

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        '[Obrolan Edit] ' . $e->getMessage()
    );

    chatEditResponse(
        'error',
        'Terjadi kesalahan saat mengedit pesan.',
        [],
        500
    );
}
