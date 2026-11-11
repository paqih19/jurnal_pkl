<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) { echo json_encode(['status'=>'error']); exit; }

$in = json_decode(file_get_contents('php://input'), true);
$id = (int)($in['id'] ?? 0);
$content = trim($in['content'] ?? '');

if (!$id || $content === '') { echo json_encode(['status'=>'error','message'=>'Invalid']); exit; }

try {
    $stmt = $pdo->prepare("UPDATE messages SET content=?, edited_at=NOW() WHERE id=? AND user_id=? AND deleted_at IS NULL AND created_at > NOW() - INTERVAL 15 MINUTE");
    $stmt->execute([$content, $id, $_SESSION['user_id']]);
    if ($stmt->rowCount() === 0) {
        echo json_encode(['status'=>'error','message'=>'Tidak bisa edit (sudah >15 menit atau bukan pesan Anda)']);
    } else {
        echo json_encode(['status'=>'success']);
    }
} catch (Exception $e) { echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }