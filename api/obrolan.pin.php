<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');
if (empty($_SESSION['user_id'])) { echo json_encode(['status'=>'error']); exit; }
$in = json_decode(file_get_contents('php://input'), true);
$id = (int)($in['message_id'] ?? 0);
if (!$id) { echo json_encode(['status'=>'error']); exit; }
try {
    $pdo->prepare("UPDATE messages SET is_pinned = 1 - is_pinned WHERE id=?")->execute([$id]);
    echo json_encode(['status'=>'success']);
} catch (Exception $e) { echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }