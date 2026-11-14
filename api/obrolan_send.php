<?php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/notifications.php';
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();

try {
    if (empty($_SESSION['user_id'])) throw new Exception('Unauthorized');
    $uid = (int)$_SESSION['user_id'];

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $content = trim($input['content'] ?? '');
    $replyTo = !empty($input['reply_to_id']) ? (int)$input['reply_to_id'] : null;
    $roomId = (int)($input['room_id'] ?? 1);

    if ($content === '') throw new Exception('Pesan kosong');
    if (mb_strlen($content) > 1000) throw new Exception('Maks 1000 karakter');

    $check = $pdo->prepare("SELECT 1 FROM chat_members WHERE room_id = ? AND user_id = ?");
    $check->execute([$roomId, $uid]);
    if (!$check->fetch()) throw new Exception('Kamu bukan anggota room ini');

    $hasReply = false;
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM messages") as $row) {
            if ($row['Field'] === 'reply_to_id') { $hasReply = true; break; }
        }
    } catch (Exception $e) {}

    if ($hasReply && $replyTo) {
        $stmt = $pdo->prepare("INSERT INTO messages (user_id, room_id, content, reply_to_id, created_at) VALUES (?,?,?,?,NOW())");
        $stmt->execute([$uid, $roomId, $content, $replyTo]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO messages (user_id, room_id, content, created_at) VALUES (?,?,?,NOW())");
        $stmt->execute([$uid, $roomId, $content]);
    }

    try {
        $pdo->prepare("INSERT INTO user_activity (user_id,last_seen) VALUES (?,NOW()) ON DUPLICATE KEY UPDATE last_seen=NOW()")->execute([$uid]);
    } catch (Exception $e) {}

    // Notifikasi ke anggota room lain
    try {
        $stmtRoom = $pdo->prepare("SELECT name, type FROM chat_rooms WHERE id = ?");
        $stmtRoom->execute([$roomId]);
        $room = $stmtRoom->fetch();
        $roomName = $room['name'] ?? 'Obrolan';
        $roomType = $room['type'] ?? 'group';

        $stmtUser = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $stmtUser->execute([$uid]);
        $senderName = $stmtUser->fetchColumn() ?: 'Seseorang';

        $stmtMembers = $pdo->prepare("SELECT cm.user_id FROM chat_members cm WHERE cm.room_id = ? AND cm.user_id != ?");
        $stmtMembers->execute([$roomId, $uid]);
        $otherIds = array_column($stmtMembers->fetchAll(), 'user_id');

        if (!empty($otherIds)) {
            $shortContent = mb_strlen($content) > 60 ? mb_substr($content, 0, 60) . '...' : $content;
            $title = $roomType === 'private'
                ? "💬 Pesan baru dari {$senderName}"
                : "💬 Obrolan baru di {$roomName}";
            createNotificationBulk($pdo, $otherIds, 'chat', $title, $senderName . ': ' . $shortContent, 'obrolan.php');
        }
    } catch (Exception $e) {}

    ob_end_clean();
    echo json_encode(['status' => 'success', 'id' => $pdo->lastInsertId()]);
} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}