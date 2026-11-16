
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
    int $httpCode = 200
): never {
    http_response_code($httpCode);

    if ($httpCode === 405) {
        header('Allow: POST');
    }

    echo json_encode([
        'status'  => $status,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// ==========================================
// VALIDASI REQUEST
// ==========================================

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

if ($uid <= 0) {
    taskUpdateResponse(
        'error',
        'Sesi pengguna tidak valid.',
        401
    );
}

// ==========================================
// HAK AKSES
// ==========================================

$isPembimbing = in_array(
    $role,
    ['pembimbing', 'teacher', 'admin'],
    true
);

// ==========================================
// BACA JSON
// ==========================================

$rawBody = file_get_contents('php://input');

if ($rawBody === false || trim($rawBody) === '') {
    taskUpdateResponse(
        'error',
        'Data permintaan kosong.',
        400
    );
}

$body = json_decode($rawBody, true);

if (!is_array($body) || json_last_error() !== JSON_ERROR_NONE) {
    taskUpdateResponse(
        'error',
        'Format JSON tidak valid.',
        400
    );
}

$id = filter_var(
    $body['id'] ?? null,
    FILTER_VALIDATE_INT
);

$status = $body['status'] ?? '';

$allowedStatuses = [
    'todo',
    'doing',
    'done'
];

if (
    !$id ||
    $id <= 0 ||
    !is_string($status) ||
    !in_array($status, $allowedStatuses, true)
) {
    taskUpdateResponse(
        'error',
        'Data tugas atau status tidak valid.',
        422
    );
}

// ==========================================
// PROSES UPDATE
// ==========================================

try {
    $pdo->beginTransaction();

    // Ambil dan kunci data tugas
    $stmt = $pdo->prepare("
        SELECT id, created_by, title, status
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

    // ======================================
    // PEMERIKSAAN HAK AKSES
    // ======================================

    if (
        !$isPembimbing &&
        (int) $task['created_by'] !== $uid
    ) {
        $pdo->rollBack();

        taskUpdateResponse(
            'error',
            'Kamu tidak memiliki izin untuk mengubah tugas ini.',
            403
        );
    }

    $oldStatus = (string) $task['status'];

    // ======================================
    // CEK PERUBAHAN STATUS
    // ======================================

    if ($oldStatus === $status) {
        $pdo->commit();

        taskUpdateResponse(
            'success',
            'Status tugas tidak mengalami perubahan.'
        );
    }

    // ======================================
    // DETEKSI KOLOM UPDATED_AT
    // ======================================

    $columns = $pdo
        ->query("SHOW COLUMNS FROM tasks")
        ->fetchAll(PDO::FETCH_COLUMN);

    $hasUpdatedAt = in_array(
        'updated_at',
        $columns,
        true
    );

    // ======================================
    // UPDATE STATUS
    // ======================================

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

    // Simpan informasi aktivitas sebelum commit
    $title = mb_substr(
        (string) $task['title'],
        0,
        150,
        'UTF-8'
    );

    $safeTitle = htmlspecialchars(
        $title,
        ENT_QUOTES,
        'UTF-8'
    );

    $activityMessage =
        "Status tugas #{$id} '{$safeTitle}' " .
        "diubah dari {$oldStatus} menjadi {$status}";

    // Simpan perubahan database
    $pdo->commit();

    // ======================================
    // CATAT AKTIVITAS
    // ======================================

    try {
        logActivity(
            'task_status',
            $activityMessage
        );
    } catch (Throwable $logError) {
        error_log(
            '[TASK UPDATE ACTIVITY] ' .
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
        '[TASK UPDATE] ' . $e->getMessage()
    );

    taskUpdateResponse(
        'error',
        'Terjadi kesalahan saat memperbarui status tugas.',
        500
    );
}
