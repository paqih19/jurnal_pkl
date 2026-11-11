<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) { echo json_encode(['status'=>'error']); exit; }

$in = json_decode(file_get_contents('php://input'), true);
$id = (int)($in['id'] ?? 0);
if (!$id) { echo json_encode(['status'=>'error','message'=>'ID invalid']); exit; }

try {
    // Soft delete — hanya bisa hapus pesan sendiri
    $stmt = $pdo->prepare("UPDATE messages SET deleted_at=NOW(), content='' WHERE id=? AND user_id=?");
    $stmt->execute([$id, $_SESSION['user_id']]);
    echo json_encode(['status'=>'success']);
} catch (Exception $e) { echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }