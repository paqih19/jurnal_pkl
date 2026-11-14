<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/notifications.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) { echo json_encode(['unread' => 0, 'items' => []]); exit; }

$uid = (int)$_SESSION['user_id'];
$unread = unreadNotifCount($pdo, $uid);
$items = getNotifications($pdo, $uid, 20);

foreach ($items as &$n) {
    $diff = time() - strtotime($n['created_at']);
    if ($diff < 60)        $n['time_ago'] = 'baru saja';
    elseif ($diff < 3600)  $n['time_ago'] = floor($diff / 60) . ' menit lalu';
    elseif ($diff < 86400) $n['time_ago'] = floor($diff / 3600) . ' jam lalu';
    elseif ($diff < 2592000) $n['time_ago'] = floor($diff / 86400) . ' hari lalu';
    else                   $n['time_ago'] = date('d M', strtotime($n['created_at']));
    $n['is_read'] = (bool)$n['is_read'];
}

echo json_encode(['unread' => $unread, 'items' => $items]);