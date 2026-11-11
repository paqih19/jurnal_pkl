<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$id = (int)($input['id'] ?? 0);
$status = $input['status'] ?? '';

if (!$id || !in_array($status, ['todo','doing','done'])) {
    echo json_encode(['status'=>'error','message'=>'Invalid input']); exit;
}

try {
    $stmt = $pdo->prepare("UPDATE tasks SET status=? WHERE id=?");
    $stmt->execute([$status, $id]);
    echo json_encode(['status'=>'success']);
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}