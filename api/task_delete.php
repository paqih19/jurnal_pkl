
<?php
// api/task_delete.php

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

require_once __DIR__ . '/../includes/activity.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function taskDeleteResponse(
    string $status,
    string $message,
    int $code = 200
): never {
    http_response_code($code);

    echo json_encode([
        'status'  => $status,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// Hanya POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    taskDeleteResponse(
        'error',
        'Metode request tidak diizinkan.',
        405
    );
}

$user = currentUser();

$uid = (int) ($user['id'] ?? 0);
$role = $user['role'] ?? '';

$isPembimbing = in_array(
    $role,
    ['pembimbing', 'teacher', 'admin'],
    true
);

if ($uid <= 0) {
    taskDeleteResponse('error', 'Sesi pengguna tidak valid.', 401);
}

// Baca JSON
$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body)) {
    taskDeleteResponse('error', 'Data request tidak valid.', 400);
}

$id = filter_var(
    $body['id'] ?? null,
    FILTER_VALIDATE_INT
);

if (!$id || $id <= 0) {
    taskDeleteResponse('error', 'ID tugas tidak valid.', 400);
}

try {
    $pdo->beginTransaction();

    // Ambil tugas dan kunci baris selama proses berlangsung
    $stmt = $pdo->prepare("
        SELECT id, created_by, title
        FROM tasks
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([$id]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        $pdo->rollBack();

        taskDeleteResponse(
            'error',
            'Tugas tidak ditemukan.',
            404
        );
    }

    // Pemeriksaan hak akses
    if (
        !$isPembimbing &&
        (int) $task['created_by'] !== $uid
    ) {
        $pdo->rollBack();

        taskDeleteResponse(
            'error',
            'Anda tidak berhak menghapus tugas ini.',
            403
        );
    }

    // Hapus tugas
    $delete = $pdo->prepare("
        DELETE FROM tasks
        WHERE id = ?
    ");

    $delete->execute([$id]);

    if ($delete->rowCount() !== 1) {
        $pdo->rollBack();

        taskDeleteResponse(
            'error',
            'Tugas gagal dihapus.',
            500
        );
    }

    $pdo->commit();

    // Catat aktivitas setelah penghapusan berhasil
    try {
        $title = (string) $task['title'];

        logActivity(
            'task_delete',
            "Tugas #{$id} ('{$title}') dihapus"
        );
    } catch (Throwable $logError) {
        error_log('task_delete activity: ' . $logError->getMessage());
    }

    taskDeleteResponse(
        'success',
        'Tugas berhasil dihapus.'
    );

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('task_delete: ' . $e->getMessage());

    taskDeleteResponse(
        'error',
        'Terjadi kesalahan saat menghapus tugas.',
        500
    );
}
