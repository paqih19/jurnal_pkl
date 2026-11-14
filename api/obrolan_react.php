<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) { echo json_encode(['status'=>'error']); exit; }

$in = json_decode(file_get_contents('php://input'), true);
$id = (int)($in['message_id'] ?? 0);
$emoji = $in['emoji'] ?? '';

if (!$id || !$emoji) { echo json_encode(['status'=>'error']); exit; }

try {
    // Toggle reaction
    $check = $pdo->prepare("SELECT 1 FROM message_reactions WHERE message_id=? AND user_id=? AND emoji=?");
    $check->execute([$id, $_SESSION['user_id'], $emoji]);
    if ($check->fetch()) {
        $pdo->prepare("DELETE FROM message_reactions WHERE message_id=? AND user_id=? AND emoji=?")
            ->execute([$id, $_SESSION['user_id'], $emoji]);
    } else {
        $pdo->prepare("INSERT INTO message_reactions (message_id,user_id,emoji) VALUES (?,?,?)")
            ->execute([$id, $_SESSION['user_id'], $emoji]);
    }
    echo json_encode(['status'=>'success']);
} catch (Exception $e) { echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }