
<?php
// api/task_update.php

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

require_once __DIR__ . '/../includes/activity.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function taskUpdateResponse(
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
    taskUpdateResponse(
        'error',
        'Metode request tidak diizinkan.',
        405
    );
}

$user = currentUser();

$uid  = (int) ($user['id'] ?? 0);
$role = $user['role'] ?? '';

$isPembimbing = in_array(
    $role,
    ['pembimbing', 'teacher', 'admin'],
    true
);

if ($uid <= 0) {
    taskUpdateResponse(
        'error',
        'Sesi login tidak valid.',
        401
    );
}

// =====================================================
// BACA INPUT JSON
// =====================================================

$input = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($input)) {
    taskUpdateResponse(
        'error',
        'Format data tidak valid.',
        400
    );
}

$id = filter_var(
    $input['id'] ?? null,
    FILTER_VALIDATE_INT
);

$status = $input['status'] ?? '';

$allowedStatus = [
    'todo',
    'doing',
    'done'
];

if (
    !$id ||
    $id <= 0 ||
    !is_string($status) ||
    !in_array($status, $allowedStatus, true)
) {
    taskUpdateResponse(
        'error',
        'Input tugas tidak valid.',
        422
    );
}

// =====================================================
// PROSES UPDATE
// =====================================================

try {
    $pdo->beginTransaction();

    // Ambil tugas dan kunci baris
    $stmt = $pdo->prepare("
        SELECT id, title, created_by, status
        FROM tasks
        WHERE id = ?
        LIMIT 1
        FOR UPDATE
    ");

    $stmt->execute([$id]);

    $task = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        $pdo->rollBack();

        taskUpdateResponse(
            'error',
            'Tugas tidak ditemukan.',
            404
        );
    }

    // =================================================
    // PEMERIKSAAN HAK AKSES
    // =================================================

    if (
        !$isPembimbing &&
        (int) $task['created_by'] !== $uid
    ) {
        $pdo->rollBack();

        taskUpdateResponse(
            'error',
            'Kamu tidak memiliki izin mengubah tugas ini.',
            403
        );
    }

    $oldStatus = (string) $task['status'];

    // Status tidak berubah
    if ($oldStatus === $status) {
        $pdo->commit();

        taskUpdateResponse(
            'success',
            'Status tugas tidak berubah.'
        );
    }

    // =================================================
    // DETEKSI KOLOM DATABASE
    // =================================================

    $columns = $pdo
        ->query("SHOW COLUMNS FROM tasks")
        ->fetchAll(PDO::FETCH_COLUMN);

    $hasUpdatedAt = in_array(
        'updated_at',
        $columns,
        true
    );

    // =================================================
    // UPDATE STATUS
    // =================================================

    if ($hasUpdatedAt) {
        $update = $pdo->prepare("
            UPDATE tasks
            SET status = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
    } else {
        $update = $pdo->prepare("
            UPDATE tasks
            SET status = ?
            WHERE id = ?
        ");
    }

    $update->execute([
        $status,
        $id
    ]);

    // =================================================
    // COMMIT
    // =================================================

    $pdo->commit();

    // =================================================
    // CATAT AKTIVITAS
    // =================================================

    try {
        $title = (string) $task['title'];

        logActivity(
            'task_status',
            "Status tugas #{$id} ({$title}) berubah dari {$oldStatus} menjadi {$status}"
        );

    } catch (Throwable $logError) {
        error_log(
            'Task update activity error: ' .
            $logError->getMessage()
        );
    }

    taskUpdateResponse(
        'success',
        'Status tugas berhasil diperbarui.'
    );

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Task update error: ' .
        $e->getMessage()
    );

    taskUpdateResponse(
        'error',
        'Terjadi kesalahan saat memperbarui status tugas.',
        500
    );
}
