
<?php
// api/notifications_read.php

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
function notificationReadResponse(
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

    notificationReadResponse(
        'error',
        'Metode permintaan tidak diizinkan.',
        [],
        405
    );
}

// Periksa login.
if (empty($_SESSION['user_id'])) {
    notificationReadResponse(
        'error',
        'Silakan login terlebih dahulu.',
        [],
        401
    );
}

$uid = (int) $_SESSION['user_id'];

if ($uid <= 0) {
    notificationReadResponse(
        'error',
        'Sesi pengguna tidak valid.',
        [],
        401
    );
}

// Ambil data JSON.
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    notificationReadResponse(
        'error',
        'Data permintaan tidak valid.',
        [],
        400
    );
}

$id = filter_var(
    $input['id'] ?? null,
    FILTER_VALIDATE_INT
);

$all = filter_var(
    $input['all'] ?? false,
    FILTER_VALIDATE_BOOLEAN
);

try {
    if ($all) {
        // Tandai semua notifikasi milik pengguna sebagai dibaca.
        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE user_id = ?
              AND is_read = 0
        ");

        $stmt->execute([$uid]);

        $affected = $stmt->rowCount();

        notificationReadResponse(
            'success',
            'Semua notifikasi berhasil ditandai sudah dibaca.',
            [
                'affected' => $affected,
                'all' => true
            ]
        );

    } elseif ($id !== false && $id !== null && $id > 0) {

        // Tandai satu notifikasi milik pengguna sebagai dibaca.
        $stmt = $pdo->prepare("
            UPDATE notifications
            SET is_read = 1
            WHERE id = ?
              AND user_id = ?
        ");

        $stmt->execute([
            $id,
            $uid
        ]);

        notificationReadResponse(
            'success',
            'Notifikasi berhasil diperbarui.',
            [
                'affected' => $stmt->rowCount(),
                'id' => $id,
                'all' => false
            ]
        );

    } else {
        notificationReadResponse(
            'error',
            'ID notifikasi tidak valid.',
            [],
            400
        );
    }

} catch (Throwable $e) {

    error_log(
        '[Notifications Read API] ' . $e->getMessage()
    );

    notificationReadResponse(
        'error',
        'Gagal memperbarui notifikasi. Silakan coba lagi.',
        [],
        500
    );
}
