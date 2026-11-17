<?php
// api/task_update.php

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

require_once __DIR__ . '/../includes/activity.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function taskUpdateResponse(
    string $status,
    string $message,
    int $code = 200
): never {
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
    taskUpdateResponse('error', 'Metode request tidak diizinkan.', 405);
}

$user = currentUser();

$uid  = (int) ($user['id'] ?? 0);
$role = $user['role'] ?? '';

if ($uid <= 0) {
    taskUpdateResponse('error', 'Sesi login tidak valid.', 401);
}

$isPembimbing = in_array($role, ['pembimbing', 'teacher', 'admin'], true);

// Baca JSON
$body = json_decode(file_get_contents('php://input'), true);

if (!is_array($body)) {
    taskUpdateResponse('error', 'Format data tidak valid.', 400);
}

$id     = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
$status = $body['status'] ?? '';

$allowedStatus = ['todo', 'doing', 'done'];

if (
    $id === false || $id === null || $id <= 0 ||
    !is_string($status) ||
    !in_array($status, $allowedStatus, true)
) {
    taskUpdateResponse('error', 'Input tugas tidak valid.', 422);
}

try {
    $pdo->beginTransaction();

    // Ambil tugas + lock
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
        taskUpdateResponse('error', 'Tugas tidak ditemukan.', 404);
    }

    // Cek hak akses
    if (!$isPembimbing && (int) $task['created_by'] !== $uid) {
        $pdo->rollBack();
        taskUpdateResponse('error', 'Kamu tidak memiliki izin mengubah tugas ini.', 403);
    }

    $oldStatus = (string) $task['status'];

    // Tidak ada perubahan
    if ($oldStatus === $status) {
        $pdo->commit();
        taskUpdateResponse('success', 'Status tugas tidak berubah.');
    }

    // Deteksi kolom updated_at (cache per request)
    static $hasUpdatedAt = null;

    if ($hasUpdatedAt === null) {
        $columns = $pdo->query("SHOW COLUMNS FROM tasks")->fetchAll(PDO::FETCH_COLUMN);
        $hasUpdatedAt = in_array('updated_at', $columns, true);
    }

    // Update
    if ($hasUpdatedAt) {
        $update = $pdo->prepare("
            UPDATE tasks SET status = ?, updated_at = NOW() WHERE id = ?
        ");
    } else {
        $update = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ?");
    }

    $update->execute([$status, $id]);

    $pdo->commit();

    // Log aktivitas (tidak memblokir)
    try {
        $title = (string) $task['title'];

        if (function_exists('logActivity')) {
            logActivity(
                'task_status',
                "Status tugas #{$id} ({$title}) berubah dari {$oldStatus} menjadi {$status}"
            );
        }
    } catch (Throwable $logError) {
        error_log('Task update activity error: ' . $logError->getMessage());
    }

    taskUpdateResponse('success', 'Status tugas berhasil diperbarui.');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('Task update error: ' . $e->getMessage());

    taskUpdateResponse('error', 'Terjadi kesalahan saat memperbarui status tugas.', 500);
}