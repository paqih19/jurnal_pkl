<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');
if (empty($_SESSION['user_id'])) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }

$uid = (int)$_SESSION['user_id'];
$in = json_decode(file_get_contents('php://input'), true) ?? [];
$otherId = (int)($in['user_id'] ?? 0);

if (!$otherId || $otherId === $uid) { echo json_encode(['status'=>'error','message'=>'User invalid']); exit; }

try {
    // Cek apakah sudah ada room private antara mereka
    $stmt = $pdo->prepare("
        SELECT r.id FROM chat_rooms r
        WHERE r.type = 'private'
        AND EXISTS (SELECT 1 FROM chat_members WHERE room_id = r.id AND user_id = ?)
        AND EXISTS (SELECT 1 FROM chat_members WHERE room_id = r.id AND user_id = ?)
        LIMIT 1
    ");
    $stmt->execute([$uid, $otherId]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        echo json_encode(['status'=>'success','room_id'=>(int)$existing]);
        exit;
    }

    // Buat baru
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO chat_rooms (name, type, created_by, created_at) VALUES (NULL, 'private', ?, NOW())");
    $stmt->execute([$uid]);
    $roomId = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare("INSERT INTO chat_members (room_id, user_id, joined_at) VALUES (?, ?, NOW())");
    $stmt->execute([$roomId, $uid]);
    $stmt->execute([$roomId, $otherId]);

    $pdo->commit();
    echo json_encode(['status'=>'success','room_id'=>$roomId]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}