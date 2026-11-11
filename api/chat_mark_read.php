<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');
if (empty($_SESSION['user_id'])) { echo json_encode(['status'=>'error']); exit; }

$uid = (int)$_SESSION['user_id'];
$in = json_decode(file_get_contents('php://input'), true) ?? [];
$roomId = (int)($in['room_id'] ?? 1);

try {
    // Update last_read_at di chat_members
    $pdo->prepare("UPDATE chat_members SET last_read_at = NOW() WHERE room_id = ? AND user_id = ?")
        ->execute([$roomId, $uid]);

    // Tetap isi message_reads untuk centang biru
    $pdo->prepare("
        INSERT IGNORE INTO message_reads (message_id, user_id)
        SELECT id, ? FROM messages WHERE room_id = ? AND user_id != ? AND id NOT IN (
            SELECT message_id FROM message_reads WHERE user_id = ?
        )
    ")->execute([$uid, $roomId, $uid, $uid]);

    try {
        $pdo->prepare("INSERT INTO user_activity (user_id,last_seen) VALUES (?,NOW()) ON DUPLICATE KEY UPDATE last_seen=NOW()")->execute([$uid]);
    } catch (Exception $e) {}

    echo json_encode(['status'=>'success']);
} catch (Exception $e) { echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }