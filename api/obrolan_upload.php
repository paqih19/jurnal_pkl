
<?php
// api/obrolan_upload.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

ini_set('display_errors', '0');
error_reporting(E_ALL);

function uploadChatResponse(
    string $status,
    string $message,
    array $extra = [],
    int $code = 200
): never {
    http_response_code($code);

    echo json_encode(
        array_merge([
            'status' => $status,
            'message' => $message
        ], $extra),
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

// Login
if (empty($_SESSION['user_id'])) {
    uploadChatResponse('error', 'Silakan login terlebih dahulu.', [], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    uploadChatResponse('error', 'Metode request tidak diizinkan.', [], 405);
}

$uid = (int) $_SESSION['user_id'];

// Validasi file upload
if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    uploadChatResponse('error', 'File tidak ditemukan.', [], 400);
}

$file = $_FILES['file'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'Ukuran file melebihi batas server.',
        UPLOAD_ERR_FORM_SIZE  => 'Ukuran file terlalu besar.',
        UPLOAD_ERR_PARTIAL    => 'File hanya terunggah sebagian.',
        UPLOAD_ERR_NO_FILE    => 'Silakan pilih file terlebih dahulu.',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara server tidak tersedia.',
        UPLOAD_ERR_CANT_WRITE => 'Server gagal menulis file.',
        UPLOAD_ERR_EXTENSION  => 'Upload dihentikan oleh konfigurasi server.'
    ];

    uploadChatResponse(
        'error',
        $uploadErrors[$file['error']] ?? 'Upload file gagal.',
        [],
        400
    );
}

if (
    empty($file['tmp_name']) ||
    !is_uploaded_file($file['tmp_name'])
) {
    uploadChatResponse('error', 'File upload tidak valid.', [], 400);
}

// Ambil input
$roomId = filter_var(
    $_POST['room_id'] ?? null,
    FILTER_VALIDATE_INT
);

$type = $_POST['type'] ?? 'file';
$caption = $_POST['caption'] ?? '';
$replyTo = null;

if (isset($_POST['reply_to_id']) && $_POST['reply_to_id'] !== '') {
    $replyTo = filter_var(
        $_POST['reply_to_id'],
        FILTER_VALIDATE_INT
    );

    if (!$replyTo || $replyTo <= 0) {
        uploadChatResponse('error', 'Pesan balasan tidak valid.', [], 400);
    }
}

if (!$roomId || $roomId <= 0) {
    uploadChatResponse('error', 'Ruang obrolan tidak valid.', [], 400);
}

if (!is_string($caption)) {
    uploadChatResponse('error', 'Caption tidak valid.', [], 400);
}

$caption = trim($caption);

if (mb_strlen($caption, 'UTF-8') > 500) {
    uploadChatResponse('error', 'Caption maksimal 500 karakter.', [], 400);
}

$allowedTypes = ['image', 'audio', 'file'];

if (!in_array($type, $allowedTypes, true)) {
    uploadChatResponse('error', 'Kategori file tidak valid.', [], 400);
}

// Batas maksimal 10 MB
$maxSize = 10 * 1024 * 1024;

if ($file['size'] <= 0 || $file['size'] > $maxSize) {
    uploadChatResponse('error', 'Ukuran file harus lebih dari 0 dan maksimal 10 MB.', [], 400);
}

// Daftar MIME dan ekstensi yang diizinkan
$allowedByType = [
    'image' => [
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png'  => ['png'],
        'image/gif'  => ['gif'],
        'image/webp' => ['webp']
    ],

    'audio' => [
        'audio/mpeg' => ['mp3'],
        'audio/mp3'  => ['mp3'],
        'audio/wav'  => ['wav'],
        'audio/x-wav' => ['wav'],
        'audio/ogg'  => ['ogg'],
        'audio/webm' => ['webm'],
        'audio/mp4'  => ['m4a'],
        'audio/x-m4a' => ['m4a']
    ],

    'file' => [
        'application/pdf' => ['pdf'],
        'application/msword' => ['doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx'],
        'application/vnd.ms-excel' => ['xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx'],
        'application/vnd.ms-powerpoint' => ['ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['pptx'],
        'application/zip' => ['zip'],
        'application/x-zip-compressed' => ['zip'],
        'application/x-rar-compressed' => ['rar'],
        'application/vnd.rar' => ['rar'],
        'application/x-rar' => ['rar'],
        'text/plain' => ['txt'],
        'text/csv' => ['csv']
    ]
];

// Deteksi MIME asli
$finfo = finfo_open(FILEINFO_MIME_TYPE);

if (!$finfo) {
    uploadChatResponse('error', 'Server tidak dapat memeriksa tipe file.', [], 500);
}

$mime = finfo_file($finfo, $file['tmp_name']) ?: '';

finfo_close($finfo);

$allowedExtensions = $allowedByType[$type] ?? [];
$extensions = $allowedExtensions[$mime] ?? [];

if (empty($extensions)) {
    uploadChatResponse('error', 'Tipe file tidak diizinkan.', [], 400);
}

// Periksa ekstensi asli juga
$originalName = basename((string) $file['name']);
$originalExt = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if (!in_array($originalExt, $extensions, true)) {
    uploadChatResponse('error', 'Ekstensi file tidak sesuai dengan isi file.', [], 400);
}

$ext = $originalExt;

// Bersihkan nama file asli untuk disimpan sebagai informasi
$originalName = preg_replace('/[\x00-\x1F\x7F]/u', '', $originalName);
$originalName = trim($originalName);

if ($originalName === '') {
    $originalName = 'file.' . $ext;
}

$originalName = mb_substr($originalName, 0, 255, 'UTF-8');

// Periksa anggota ruang
try {
    $checkMember = $pdo->prepare("
        SELECT 1
        FROM chat_members
        WHERE room_id = ?
          AND user_id = ?
        LIMIT 1
    ");

    $checkMember->execute([$roomId, $uid]);

    if (!$checkMember->fetchColumn()) {
        uploadChatResponse('error', 'Kamu bukan anggota ruang obrolan ini.', [], 403);
    }

    // Deteksi kolom messages
    $columnRows = $pdo->query("SHOW COLUMNS FROM messages")->fetchAll(PDO::FETCH_ASSOC);
    $columns = array_column($columnRows, 'Field');

    $requiredColumns = [
        'user_id',
        'room_id',
        'content',
        'attachment',
        'attachment_type',
        'file_name',
        'file_size',
        'created_at'
    ];

    foreach ($requiredColumns as $requiredColumn) {
        if (!in_array($requiredColumn, $columns, true)) {
            uploadChatResponse(
                'error',
                'Struktur tabel messages belum mendukung upload file. Kolom ' . $requiredColumn . ' belum tersedia.',
                [],
                500
            );
        }
    }

    $hasReply = in_array('reply_to_id', $columns, true);
    $hasDeletedAt = in_array('deleted_at', $columns, true);

    // Validasi pesan balasan
    if ($replyTo !== null) {
        if (!$hasReply) {
            uploadChatResponse('error', 'Fitur balas pesan belum tersedia.', [], 400);
        }

        $sqlReply = "
            SELECT id
            FROM messages
            WHERE id = ?
              AND room_id = ?
        ";

        if ($hasDeletedAt) {
            $sqlReply .= " AND deleted_at IS NULL";
        }

        $sqlReply .= " LIMIT 1";

        $checkReply = $pdo->prepare($sqlReply);
        $checkReply->execute([$replyTo, $roomId]);

        if (!$checkReply->fetchColumn()) {
            uploadChatResponse('error', 'Pesan yang ingin dibalas tidak ditemukan.', [], 400);
        }
    }

    // Siapkan folder upload
    $dir = __DIR__ . '/../uploads/chat/';

    if (!is_dir($dir)) {
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Folder upload gagal dibuat.');
        }
    }

    if (!is_writable($dir)) {
        throw new RuntimeException('Folder upload tidak dapat ditulis.');
    }

    // Nama file acak
    $filename = 'chat_' . $uid . '_' .
        bin2hex(random_bytes(16)) . '.' . $ext;

    $fullPath = $dir . $filename;
    $relativePath = 'chat/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
        throw new RuntimeException('Gagal menyimpan file.');
    }

    // Simpan pesan dan lampiran dalam transaksi
    try {
        $pdo->beginTransaction();

        if ($hasReply) {
            $insert = $pdo->prepare("
                INSERT INTO messages
                    (
                        user_id,
                        room_id,
                        content,
                        attachment,
                        attachment_type,
                        file_name,
                        file_size,
                        reply_to_id,
                        created_at
                    )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $insert->execute([
                $uid,
                $roomId,
                $caption,
                $relativePath,
                $type,
                $originalName,
                (int) $file['size'],
                $replyTo
            ]);

        } else {
            $insert = $pdo->prepare("
                INSERT INTO messages
                    (
                        user_id,
                        room_id,
                        content,
                        attachment,
                        attachment_type,
                        file_name,
                        file_size,
                        created_at
                    )
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $insert->execute([
                $uid,
                $roomId,
                $caption,
                $relativePath,
                $type,
                $originalName,
                (int) $file['size']
            ]);
        }

        $msgId = (int) $pdo->lastInsertId();

        $pdo->commit();

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        @unlink($fullPath);
        throw $e;
    }

    // Update aktivitas pengguna
    try {
        $activity = $pdo->prepare("
            INSERT INTO user_activity (user_id, last_seen)
            VALUES (?, NOW())
            ON DUPLICATE KEY UPDATE last_seen = NOW()
        ");

        $activity->execute([$uid]);

    } catch (Throwable $e) {
        error_log('obrolan_upload activity: ' . $e->getMessage());
    }

    // Notifikasi anggota lainnya
    try {
        $stmtRoom = $pdo->prepare("
            SELECT name, type
            FROM chat_rooms
            WHERE id = ?
            LIMIT 1
        ");

        $stmtRoom->execute([$roomId]);
        $room = $stmtRoom->fetch(PDO::FETCH_ASSOC);

        $roomName = $room['name'] ?? 'Obrolan';
        $roomType = $room['type'] ?? 'group';

        $stmtUser = $pdo->prepare("
            SELECT name
            FROM users
            WHERE id = ?
            LIMIT 1
        ");

        $stmtUser->execute([$uid]);
        $senderName = $stmtUser->fetchColumn() ?: 'Seseorang';

        $stmtMembers = $pdo->prepare("
            SELECT user_id
            FROM chat_members
            WHERE room_id = ?
              AND user_id != ?
        ");

        $stmtMembers->execute([$roomId, $uid]);

        $otherIds = array_map(
            'intval',
            $stmtMembers->fetchAll(PDO::FETCH_COLUMN)
        );

        if (!empty($otherIds)) {
            $icons = [
                'image' => '🖼️',
                'audio' => '🎤',
                'file'  => '📎'
            ];

            $icon = $icons[$type] ?? '📎';

            $title = $roomType === 'private'
                ? "{$icon} Pesan baru dari {$senderName}"
                : "{$icon} Obrolan baru di {$roomName}";

            $description = [
                'image' => 'mengirim gambar',
                'audio' => 'mengirim voice note',
                'file'  => 'mengirim file'
            ];

            createNotificationBulk(
                $pdo,
                $otherIds,
                'chat',
                $title,
                $senderName . ' ' . $description[$type],
                'obrolan.php'
            );
        }

    } catch (Throwable $e) {
        error_log('obrolan_upload notification: ' . $e->getMessage());
    }

    // URL lampiran
    $uploadUrl = defined('UPLOAD_URL')
        ? rtrim(UPLOAD_URL, '/') . '/'
        : '../uploads/';

    uploadChatResponse(
        'success',
        'File berhasil dikirim.',
        [
            'id'              => $msgId,
            'attachment'      => $uploadUrl . $relativePath,
            'attachment_type' => $type,
            'file_name'       => $originalName,
            'file_size'       => (int) $file['size'],
            'caption'         => $caption
        ]
    );

} catch (Throwable $e) {
    error_log('obrolan_upload: ' . $e->getMessage());

    uploadChatResponse(
        'error',
        'Gagal menyimpan pesan atau file.',
        [],
        500
    );
}
