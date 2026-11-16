
<?php
// api/obrolan_delete.php

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
function chatDeleteResponse(
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

    chatDeleteResponse(
        'error',
        'Metode permintaan tidak diizinkan.',
        [],
        405
    );
}

// Periksa login.
if (empty($_SESSION['user_id'])) {
    chatDeleteResponse(
        'error',
        'Silakan login terlebih dahulu.',
        [],
        401
    );
}

$uid = (int) $_SESSION['user_id'];

if ($uid <= 0) {
    chatDeleteResponse(
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
    chatDeleteResponse(
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
    chatDeleteResponse(
        'error',
        'ID pesan tidak valid.',
        [],
        422
    );
}

try {
    $pdo->beginTransaction();

    // Pastikan pesan berada di room yang diikuti pengguna
    // dan pesan tersebut memang milik pengguna.
    $check = $pdo->prepare("
        SELECT
            m.id,
            m.user_id,
            m.room_id,
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

        chatDeleteResponse(
            'error',
            'Pesan tidak ditemukan atau akses ditolak.',
            [],
            404
        );
    }

    // Pesan orang lain tidak boleh dihapus.
    if ((int) $message['user_id'] !== $uid) {
        $pdo->rollBack();

        chatDeleteResponse(
            'error',
            'Kamu hanya dapat menghapus pesan milik sendiri.',
            [],
            403
        );
    }

    // Cek apakah sudah pernah dihapus.
    if (!empty($message['deleted_at'])) {
        $pdo->rollBack();

        chatDeleteResponse(
            'error',
            'Pesan ini sudah dihapus.',
            [],
            409
        );
    }

    // Hapus secara soft delete.
    $delete = $pdo->prepare("
        UPDATE messages
        SET
            deleted_at = NOW(),
            content = ''
        WHERE id = ?
          AND user_id = ?
          AND deleted_at IS NULL
    ");

    $delete->execute([
        $id,
        $uid
    ]);

    if ($delete->rowCount() <= 0) {
        $pdo->rollBack();

        chatDeleteResponse(
            'error',
            'Pesan gagal dihapus atau sudah dihapus.',
            [],
            409
        );
    }

    $pdo->commit();

    chatDeleteResponse(
        'success',
        'Pesan berhasil dihapus.',
        [
            'id' => $id,
            'deleted' => true
        ]
    );

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        '[Obrolan Delete] ' . $e->getMessage()
    );

    chatDeleteResponse(
        'error',
        'Terjadi kesalahan saat menghapus pesan.',
        [],
        500
    );
}
