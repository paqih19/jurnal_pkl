<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();

try {
    if (empty($_SESSION['user_id'])) throw new Exception('Unauthorized');
    $uid = (int)$_SESSION['user_id'];

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $content = trim($input['content'] ?? '');
    $replyTo = !empty($input['reply_to_id']) ? (int)$input['reply_to_id'] : null;
    $roomId = (int)($input['room_id'] ?? 1);

    if ($content === '') throw new Exception('Pesan kosong');
    if (mb_strlen($content) > 1000) throw new Exception('Maks 1000 karakter');

    // Cek anggota room
    $check = $pdo->prepare("SELECT 1 FROM chat_members WHERE room_id = ? AND user_id = ?");
    $check->execute([$roomId, $uid]);
    if (!$check->fetch()) throw new Exception('Kamu bukan anggota room ini');

    // Cek kolom reply_to_id
    $hasReply = false;
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM messages") as $row) {
            if ($row['Field'] === 'reply_to_id') { $hasReply = true; break; }
        }
    } catch (Exception $e) {}

    if ($hasReply && $replyTo) {
        $stmt = $pdo->prepare("INSERT INTO messages (user_id, room_id, content, reply_to_id, created_at) VALUES (?,?,?,?,NOW())");
        $stmt->execute([$uid, $roomId, $content, $replyTo]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO messages (user_id, room_id, content, created_at) VALUES (?,?,?,NOW())");
        $stmt->execute([$uid, $roomId, $content]);
    }

    try {
        $pdo->prepare("INSERT INTO user_activity (user_id,last_seen) VALUES (?,NOW()) ON DUPLICATE KEY UPDATE last_seen=NOW()")->execute([$uid]);
    } catch (Exception $e) {}

    ob_end_clean();
    echo json_encode(['status' => 'success', 'id' => $pdo->lastInsertId()]);
} catch (Throwable $e) {
    ob_end_clean();
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}