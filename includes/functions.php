<?php
// includes/functions.php
require_once __DIR__ . '/../config/db.php';

function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect($path) {
    header("Location: " . BASE_URL . "/" . ltrim($path, '/'));
    exit;
}

function setFlash($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function getFlash() {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $f;
    }
    return null;
}

function tanggalIndo($date) {
    $bulan = [1=>'Januari','Februari','Maret','April','Mei','Juni',
              'Juli','Agustus','September','Oktober','November','Desember'];
    if (!$date) return '-';
    $ts = strtotime($date);
    return date('j', $ts) . ' ' . $bulan[(int)date('n', $ts)] . ' ' . date('Y', $ts);
}

function waktuChat($datetime) {
    if (!$datetime) return '-';
    $ts = strtotime($datetime);
    if (date('Y-m-d', $ts) === date('Y-m-d')) return date('H:i', $ts);
    if (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) return 'Kemarin ' . date('H:i', $ts);
    return date('d/m H:i', $ts);
}

function timeAgo($datetime) {
    if (!$datetime) return '-';
    $diff = time() - strtotime($datetime);
    if ($diff < 60)        return 'baru saja';
    if ($diff < 3600)      return floor($diff / 60) . ' menit lalu';
    if ($diff < 86400)     return floor($diff / 3600) . ' jam lalu';
    if ($diff < 2592000)   return floor($diff / 86400) . ' hari lalu';
    if ($diff < 31536000)  return floor($diff / 2592000) . ' bulan lalu';
    return floor($diff / 31536000) . ' tahun lalu';
}

function formatBytes($bytes) {
    if (!$bytes) return '0 B';
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}

function initials($name) {
    if (!$name) return '?';
    $parts = preg_split('/\s+/', trim($name));
    if (count($parts) >= 2) {
        return strtoupper(substr($parts[0], 0, 1) . substr($parts[1], 0, 1));
    }
    return strtoupper(substr($name, 0, 2));
}

function avatarUrl($photo, $name, $size = 80) {
    if (!empty($photo)) return UPLOAD_URL . $photo;
    return 'https://ui-avatars.com/api/?name=' . urlencode($name ?: 'User')
         . '&background=EEF2FF&color=4F46E5&size=' . $size . '&bold=true';
}

function strLimit($str, $limit = 50, $end = '...') {
    $str = trim($str ?? '');
    if (mb_strlen($str) <= $limit) return $str;
    return mb_substr($str, 0, $limit - mb_strlen($end)) . $end;
}

function unreadChatCount() {
    static $cache = null;
    if ($cache !== null) return $cache;
    if (empty($_SESSION['user_id'])) return $cache = 0;

    global $pdo;
    try {
        $uid = (int)$_SESSION['user_id'];
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM messages m
            JOIN chat_members cm ON cm.room_id = m.room_id AND cm.user_id = ?
            WHERE m.user_id != ?
              AND m.created_at > COALESCE(cm.last_read_at, '1970-01-01')
        ");
        $stmt->execute([$uid, $uid]);
        $cache = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $cache = 0;
    }
    return $cache;
}