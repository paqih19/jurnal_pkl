<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) { echo json_encode(['status' => 'error']); exit; }

$uid = (int)$_SESSION['user_id'];
$in = json_decode(file_get_contents('php://input'), true) ?? [];
$id = (int)($in['id'] ?? 0);
$all = !empty($in['all']);

try {
    if ($all) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        $stmt->execute([$uid]);
    } elseif ($id) {
        $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
        $stmt->execute([$id, $uid]);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Invalid']); exit;
    }
    echo json_encode(['status' => 'success']);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}