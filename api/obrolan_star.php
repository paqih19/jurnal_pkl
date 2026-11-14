<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');
if (empty($_SESSION['user_id'])) { echo json_encode(['status'=>'error']); exit; }
$in = json_decode(file_get_contents('php://input'), true);
$id = (int)($in['message_id'] ?? 0);
try {
    $check = $pdo->prepare("SELECT 1 FROM message_stars WHERE message_id=? AND user_id=?");
    $check->execute([$id, $_SESSION['user_id']]);
    if ($check->fetch()) {
        $pdo->prepare("DELETE FROM message_stars WHERE message_id=? AND user_id=?")->execute([$id, $_SESSION['user_id']]);
    } else {
        $pdo->prepare("INSERT INTO message_stars (message_id,user_id) VALUES (?,?)")->execute([$id, $_SESSION['user_id']]);
    }
    echo json_encode(['status'=>'success']);
} catch (Exception $e) { echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }