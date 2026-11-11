<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');
if (empty($_SESSION['user_id'])) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }

$uid = (int)$_SESSION['user_id'];
$in = json_decode(file_get_contents('php://input'), true) ?? [];
$name = trim($in['name'] ?? '');
$memberIds = $in['members'] ?? [];

if ($name === '' || !is_array($memberIds) || empty($memberIds)) {
    echo json_encode(['status'=>'error','message'=>'Nama grup & anggota wajib diisi']); exit;
}

try {
    $pdo->beginTransaction();

    // Buat grup
    $stmt = $pdo->prepare("INSERT INTO chat_rooms (name, type, created_by, created_at) VALUES (?, 'group', ?, NOW())");
    $stmt->execute([$name, $uid]);
    $roomId = (int)$pdo->lastInsertId();

    // Tambahkan anggota (pembuat + yang dipilih)
    $memberIds = array_unique(array_map('intval', $memberIds));
    $memberIds[] = $uid;

    $stmt = $pdo->prepare("INSERT INTO chat_members (room_id, user_id, joined_at) VALUES (?, ?, NOW())");
    foreach ($memberIds as $mid) {
        $stmt->execute([$roomId, $mid]);
    }

    // Kirim pesan sistem
    $stmt = $pdo->prepare("INSERT INTO messages (user_id, room_id, content, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$uid, $roomId, "📢 Grup dibuat oleh sistem"]);

    $pdo->commit();
    echo json_encode(['status'=>'success','room_id'=>$roomId]);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}