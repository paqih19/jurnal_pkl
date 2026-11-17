<?php
// includes/functions.php

require_once __DIR__ . '/../config/db.php';

// ==================================================
// KONFIGURASI FALLBACK
// ==================================================
if (!defined('BASE_URL')) {
    define('BASE_URL', '');
}
if (!defined('APP_NAME')) {
    define('APP_NAME', 'Jurnal PKL');
}

// ==================================================
// ESCAPE HTML
// ==================================================
if (!function_exists('e')) {
    function e($str): string
    {
        if (is_array($str) || is_object($str)) {
            return '';
        }

        return htmlspecialchars(
            (string)($str ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

// ==================================================
// REDIRECT
// ==================================================
if (!function_exists('redirect')) {
    function redirect($path): void
    {
        $path = trim((string)$path);

        if ($path === '') {
            $path = 'dashboard.php';
        }

        // URL absolut dengan host lain → fallback ke dashboard
        if (preg_match('#^https?://#i', $path)) {
            $baseHost   = parse_url(BASE_URL, PHP_URL_HOST);
            $targetHost = parse_url($path, PHP_URL_HOST);

            if (
                !$baseHost ||
                strtolower((string)$baseHost) !== strtolower((string)$targetHost)
            ) {
                $path = 'dashboard.php';
            }
        }

        // Cegah header injection (newline)
        if (preg_match('/[\r\n]/', $path)) {
            $path = 'dashboard.php';
        }

        if (preg_match('#^https?://#i', $path)) {
            header('Location: ' . $path);
        } else {
            header(
                'Location: ' .
                rtrim(BASE_URL, '/') . '/' . ltrim($path, '/')
            );
        }

        exit;
    }
}

// ==================================================
// FLASH MESSAGE
// ==================================================
if (!function_exists('setFlash')) {
    function setFlash($type, $msg): void
    {
        $_SESSION['flash'] = [
            'type' => (string)$type,
            'msg'  => (string)$msg,
        ];
    }
}

if (!function_exists('getFlash')) {
    function getFlash(): ?array
    {
        if (empty($_SESSION['flash'])) {
            return null;
        }

        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);

        return is_array($flash) ? $flash : null;
    }
}

// ==================================================
// FORMAT TANGGAL INDONESIA
// ==================================================
if (!function_exists('tanggalIndo')) {
    function tanggalIndo($date): string
    {
        $bulan = [
            1  => 'Januari',
            2  => 'Februari',
            3  => 'Maret',
            4  => 'April',
            5  => 'Mei',
            6  => 'Juni',
            7  => 'Juli',
            8  => 'Agustus',
            9  => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        if (!$date) {
            return '-';
        }

        $timestamp = strtotime((string)$date);

        if ($timestamp === false) {
            return '-';
        }

        return date('j', $timestamp) . ' ' .
            $bulan[(int)date('n', $timestamp)] . ' ' .
            date('Y', $timestamp);
    }
}

// ==================================================
// FORMAT WAKTU CHAT
// ==================================================
if (!function_exists('waktuChat')) {
    function waktuChat($datetime): string
    {
        if (!$datetime) {
            return '-';
        }

        $timestamp = strtotime((string)$datetime);

        if ($timestamp === false) {
            return '-';
        }

        $day = date('Y-m-d', $timestamp);

        if ($day === date('Y-m-d')) {
            return date('H:i', $timestamp);
        }

        if ($day === date('Y-m-d', strtotime('-1 day'))) {
            return 'Kemarin ' . date('H:i', $timestamp);
        }

        return date('d/m H:i', $timestamp);
    }
}

// ==================================================
// WAKTU RELATIF
// ==================================================
if (!function_exists('timeAgo')) {
    function timeAgo($datetime): string
    {
        if (!$datetime) {
            return '-';
        }

        $timestamp = strtotime((string)$datetime);

        if ($timestamp === false) {
            return '-';
        }

        $diff = time() - $timestamp;

        // Waktu di masa depan → tampilkan "baru saja"
        if ($diff < 0) {
            return 'baru saja';
        }

        if ($diff < 60) {
            return 'baru saja';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . ' menit lalu';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . ' jam lalu';
        }
        if ($diff < 2592000) {
            return floor($diff / 86400) . ' hari lalu';
        }
        if ($diff < 31536000) {
            return floor($diff / 2592000) . ' bulan lalu';
        }

        return floor($diff / 31536000) . ' tahun lalu';
    }
}

// ==================================================
// FORMAT UKURAN FILE
// ==================================================
if (!function_exists('formatBytes')) {
    function formatBytes($bytes): string
    {
        $bytes = (int)$bytes;

        if ($bytes <= 0) {
            return '0 B';
        }
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        if ($bytes < 1073741824) {
            return round($bytes / 1048576, 1) . ' MB';
        }

        return round($bytes / 1073741824, 2) . ' GB';
    }
}

// ==================================================
// INISIAL NAMA
// ==================================================
if (!function_exists('initials')) {
    function initials($name): string
    {
        $name = trim((string)($name ?? ''));

        if ($name === '') {
            return '?';
        }

        $parts = preg_split('/\s+/', $name);

        if (is_array($parts) && count($parts) >= 2) {
            return mb_strtoupper(
                mb_substr($parts[0], 0, 1) .
                mb_substr($parts[1], 0, 1)
            );
        }

        return mb_strtoupper(mb_substr($name, 0, 2));
    }
}

// ==================================================
// URL AVATAR
// ==================================================
if (!function_exists('avatarUrl')) {
    function avatarUrl($photo, $name, $size = 80): string
    {
        $size = max(20, min(512, (int)$size));

        if (!empty($photo)) {
            $photo = trim((string)$photo);

            // URL eksternal
            if (preg_match('#^https?://#i', $photo)) {
                return $photo;
            }

            // Cegah path traversal
            $filename = basename($photo);

            if ($filename !== '') {
                if (defined('UPLOAD_DIR') && is_file(UPLOAD_DIR . $filename)) {
                    return rtrim(UPLOAD_URL, '/') . '/' . rawurlencode($filename);
                }

                if (defined('UPLOAD_URL')) {
                    return rtrim(UPLOAD_URL, '/') . '/' . rawurlencode($filename);
                }
            }
        }

        return 'https://ui-avatars.com/api/?name=' .
            urlencode((string)($name ?: 'User')) .
            '&background=EEF2FF&color=4F46E5&size=' . $size .
            '&bold=true';
    }
}

// ==================================================
// BATASI PANJANG TEKS
// ==================================================
if (!function_exists('strLimit')) {
    function strLimit($str, $limit = 50, $end = '...'): string
    {
        $str   = trim((string)($str ?? ''));
        $limit = max(0, (int)$limit);

        if (mb_strlen($str) <= $limit) {
            return $str;
        }

        $end       = (string)$end;
        $available = max(0, $limit - mb_strlen($end));

        return mb_substr($str, 0, $available) . $end;
    }
}

// ==================================================
// JUMLAH PESAN CHAT BELUM DIBACA
// ==================================================
if (!function_exists('unreadChatCount')) {
    function unreadChatCount(): int
    {
        static $cache = null;

        if ($cache !== null) {
            return $cache;
        }

        if (empty($_SESSION['user_id'])) {
            return $cache = 0;
        }

        global $pdo;

        if (!isset($pdo) || !($pdo instanceof PDO)) {
            return $cache = 0;
        }

        try {
            $uid = (int)$_SESSION['user_id'];

            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM messages m
                JOIN chat_members cm
                    ON cm.room_id = m.room_id
                    AND cm.user_id = ?
                WHERE m.user_id != ?
                    AND m.created_at > COALESCE(cm.last_read_at, '1970-01-01')
            ");

            $stmt->execute([$uid, $uid]);

            return $cache = (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            // Tabel chat mungkin belum ada
            return $cache = 0;
        }
    }
}

// ==================================================
// CSRF TOKEN
// ==================================================
if (!function_exists('csrfToken')) {
    function csrfToken(string $formKey = 'default'): string
    {
        $key = 'csrf_' . preg_replace('/[^a-z0-9_]/i', '', $formKey);

        if (empty($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(32));
        }

        return $_SESSION[$key];
    }
}

// ==================================================
// VERIFIKASI CSRF
// ==================================================
if (!function_exists('verifyCsrf')) {
    function verifyCsrf(string $formKey = 'default'): bool
    {
        $key = 'csrf_' . preg_replace('/[^a-z0-9_]/i', '', $formKey);

        $token = $_POST['csrf'] ?? '';

        if (
            !is_string($token) ||
            empty($_SESSION[$key]) ||
            !is_string($_SESSION[$key])
        ) {
            return false;
        }

        return hash_equals($_SESSION[$key], $token);
    }
}

// ==================================================
// VALIDASI UPLOAD GAMBAR
// ==================================================
if (!function_exists('validateImageUpload')) {
    function validateImageUpload(
        array $file,
        int $maxBytes = 5242880
    ): array {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($error !== UPLOAD_ERR_OK) {
            return [
                'ok'    => false,
                'error' => 'File tidak ditemukan atau gagal diunggah.',
            ];
        }

        $tmp  = $file['tmp_name'] ?? '';
        $size = (int)($file['size'] ?? 0);

        if ($size <= 0 || $size > $maxBytes) {
            return [
                'ok'    => false,
                'error' => 'Ukuran file kosong atau terlalu besar.',
            ];
        }

        if (!is_uploaded_file($tmp)) {
            return [
                'ok'    => false,
                'error' => 'File upload tidak valid.',
            ];
        }

        if (!function_exists('finfo_open')) {
            return [
                'ok'    => false,
                'error' => 'Fitur pemeriksaan file tidak tersedia.',
            ];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if (!$finfo) {
            return [
                'ok'    => false,
                'error' => 'Gagal memeriksa format gambar.',
            ];
        }

        $mime = finfo_file($finfo, $tmp) ?: '';
        finfo_close($finfo);

        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
        ];

        if (!isset($allowed[$mime])) {
            return [
                'ok'    => false,
                'error' => 'Format harus JPG, PNG, atau WEBP.',
            ];
        }

        if (@getimagesize($tmp) === false) {
            return [
                'ok'    => false,
                'error' => 'File gambar rusak atau tidak valid.',
            ];
        }

        return [
            'ok'   => true,
            'ext'  => $allowed[$mime],
            'mime' => $mime,
        ];
    }
}