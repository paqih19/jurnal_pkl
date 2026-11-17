<?php
// api/upload_photo.php
// Endpoint upload foto profil — dipanggil dari profile.php via XHR

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function uploadPhotoResponse(
    string $status,
    string $message,
    array $extra = [],
    int $code = 200
): never {
    http_response_code($code);

    echo json_encode(
        array_merge(['status' => $status, 'message' => $message], $extra),
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

// Hanya POST
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    uploadPhotoResponse('error', 'Metode request tidak diizinkan.', [], 405);
}

$user = currentUser();
$uid  = (int) ($user['id'] ?? 0);

if ($uid <= 0) {
    uploadPhotoResponse('error', 'Sesi pengguna tidak valid.', [], 401);
}

// Pastikan konstanta upload tersedia
if (!defined('UPLOAD_DIR') || !defined('UPLOAD_URL')) {
    uploadPhotoResponse('error', 'Konfigurasi upload tidak tersedia.', [], 500);
}

// Validasi file
if (!isset($_FILES['photo']) || !is_array($_FILES['photo'])) {
    uploadPhotoResponse('error', 'File foto tidak ditemukan.', [], 400);
}

$file = $_FILES['photo'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'Ukuran file melebihi batas server.',
        UPLOAD_ERR_FORM_SIZE  => 'Ukuran file terlalu besar.',
        UPLOAD_ERR_PARTIAL    => 'File hanya terunggah sebagian.',
        UPLOAD_ERR_NO_FILE    => 'Silakan pilih file terlebih dahulu.',
        UPLOAD_ERR_NO_TMP_DIR => 'Folder sementara server tidak tersedia.',
        UPLOAD_ERR_CANT_WRITE => 'Server gagal menulis file.',
        UPLOAD_ERR_EXTENSION  => 'Upload dihentikan oleh konfigurasi server.',
    ];

    uploadPhotoResponse(
        'error',
        $uploadErrors[$file['error']] ?? 'Upload file gagal.',
        [],
        400
    );
}

if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
    uploadPhotoResponse('error', 'File upload tidak valid.', [], 400);
}

// Batas 5 MB
$maxSize = 5 * 1024 * 1024;

if (($file['size'] ?? 0) <= 0 || $file['size'] > $maxSize) {
    uploadPhotoResponse('error', 'Ukuran foto maksimal 5 MB.', [], 400);
}

// Deteksi MIME asli
$finfo = finfo_open(FILEINFO_MIME_TYPE);

if (!$finfo) {
    uploadPhotoResponse('error', 'Server tidak dapat memeriksa tipe file.', [], 500);
}

$mime = finfo_file($finfo, $file['tmp_name']) ?: '';
finfo_close($finfo);

$allowed = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

if (!isset($allowed[$mime])) {
    uploadPhotoResponse(
        'error',
        'Format foto harus JPG, PNG, atau WEBP.',
        [],
        400
    );
}

$ext = $allowed[$mime];

// Verifikasi gambar valid
if (@getimagesize($file['tmp_name']) === false) {
    uploadPhotoResponse('error', 'File gambar rusak atau tidak valid.', [], 400);
}

// Pastikan folder upload ada dan writable
if (!is_dir(UPLOAD_DIR)) {
    if (!mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
        uploadPhotoResponse('error', 'Folder upload tidak dapat dibuat.', [], 500);
    }
}

if (!is_writable(UPLOAD_DIR)) {
    uploadPhotoResponse('error', 'Folder upload tidak dapat ditulis.', [], 500);
}

// Ambil foto lama untuk dihapus (jika ada)
$oldPhoto = null;

try {
    $stmt = $pdo->prepare("SELECT photo FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$uid]);
    $oldPhoto = $stmt->fetchColumn();
} catch (Throwable $e) {
    error_log('upload_photo old query: ' . $e->getMessage());
}

// Simpan file baru dengan nama acak
$filename = 'avatar_' . $uid . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
$fullPath = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $filename;

if (!move_uploaded_file($file['tmp_name'], $fullPath)) {
    uploadPhotoResponse('error', 'Gagal menyimpan foto.', [], 500);
}

// Update database
try {
    $stmt = $pdo->prepare("UPDATE users SET photo = ? WHERE id = ?");
    $stmt->execute([$filename, $uid]);
} catch (Throwable $e) {
    @unlink($fullPath);
    error_log('upload_photo update: ' . $e->getMessage());
    uploadPhotoResponse('error', 'Gagal memperbarui profil.', [], 500);
}

// Hapus foto lama (jika ada dan berbeda dari foto baru)
if (!empty($oldPhoto) && $oldPhoto !== $filename) {
    $oldPath = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . basename($oldPhoto);

    if (is_file($oldPath)) {
        @unlink($oldPath);
    }
}

// Update session agar avatar di header langsung berubah
$_SESSION['photo'] = $filename;

// Log aktivitas
try {
    if (function_exists('logActivity')) {
        logActivity('profile_photo', 'Memperbarui foto profil');
    }
} catch (Throwable $e) {
    error_log('upload_photo activity: ' . $e->getMessage());
}

// Bangun URL publik
$photoUrl = rtrim(UPLOAD_URL, '/') . '/' . rawurlencode($filename) . '?v=' . time();

uploadPhotoResponse('success', 'Foto profil berhasil diperbarui.', [
    'url'      => $photoUrl,
    'filename' => $filename,
]);