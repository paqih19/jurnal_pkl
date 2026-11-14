<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();

try {
    if (empty($_SESSION['user_id'])) { ob_end_clean(); echo json_encode([]); exit; }

    $uid = (int)$_SESSION['user_id'];
    $roomId = isset($_GET['room_id']) ? (int)$_GET['room_id'] : 1;

    // Cek user adalah anggota room ini
    $check = $pdo->prepare("SELECT 1 FROM chat_members WHERE room_id = ? AND user_id = ?");
    $check->execute([$roomId, $uid]);
    if (!$check->fetch()) { ob_end_clean(); echo json_encode([]); exit; }

    $cols = [];
    try {
        foreach ($pdo->query("SHOW COLUMNS FROM messages") as $row) $cols[$row['Field']] = true;
    } catch (Exception $e) {}
    $has = fn($c) => isset($cols[$c]);

    $selectParts = ["m.id","m.content","m.created_at","m.user_id","u.name","u.photo"];
    foreach (['reply_to_id','edited_at','deleted_at','is_pinned','attachment','attachment_type','file_name','file_size'] as $c) {
        if ($has($c)) $selectParts[] = "m.$c";
    }

    $stmt = $pdo->prepare("
        SELECT " . implode(',', $selectParts) . "
        FROM messages m
        JOIN users u ON m.user_id = u.id
        WHERE m.room_id = ?
        ORDER BY m.id DESC LIMIT 100
    ");
    $stmt->execute([$roomId]);
    $messages = array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));

    // Reply map
    $replyMap = [];
    if ($has('reply_to_id')) {
        $replyIds = array_filter(array_column($messages, 'reply_to_id'));
        if ($replyIds) {
            $in = implode(',', array_map('intval', $replyIds));
            $r = $pdo->query("SELECT m2.id, m2.content, u2.name FROM messages m2 JOIN users u2 ON m2.user_id = u2.id WHERE m2.id IN ($in)");
            foreach ($r as $row) $replyMap[(int)$row['id']] = ['content'=>$row['content'],'name'=>$row['name']];
        }
    }

    // Read receipts
    $readIds = [];
    if (tableExists($pdo, 'message_reads')) {
        $r = $pdo->prepare("SELECT DISTINCT message_id FROM message_reads WHERE user_id != ?");
        $r->execute([$uid]);
        $readIds = array_map('intval', array_column($r->fetchAll(), 'message_id'));
    }

    // Reactions
    $reactions = [];
    if (tableExists($pdo, 'message_reactions')) {
        $r = $pdo->query("SELECT message_id, emoji FROM message_reactions");
        foreach ($r as $row) $reactions[(int)$row['message_id']][] = ['emoji'=>$row['emoji']];
    }

    // Stars
    $starredIds = [];
    if (tableExists($pdo, 'message_stars')) {
        $r = $pdo->prepare("SELECT message_id FROM message_stars WHERE user_id = ?");
        $r->execute([$uid]);
        $starredIds = array_map('intval', array_column($r->fetchAll(), 'message_id'));
    }

    $data = [];
    foreach ($messages as $m) {
        $id = (int)$m['id'];
        $deleted = $has('deleted_at') && !empty($m['deleted_at']);
        $replyTo = $has('reply_to_id') && !empty($m['reply_to_id']) ? (int)$m['reply_to_id'] : null;
        $reply = $replyTo ? ($replyMap[$replyTo] ?? null) : null;

        $data[] = [
            'id'              => $id,
            'content'         => $deleted ? '' : ($m['content'] ?? ''),
            'created_at'      => date('H:i', strtotime($m['created_at'])),
            'created_at_full' => $m['created_at'],
            'name'            => $m['name'],
            'photo'           => !empty($m['photo']) ? UPLOAD_URL.$m['photo'] : 'https://ui-avatars.com/api/?name='.urlencode($m['name']).'&background=random&size=50',
            'is_me'           => ((int)$m['user_id'] === $uid),
            'is_read'         => in_array($id, $readIds),
            'edited_at'       => $has('edited_at')  ? ($m['edited_at'] ?? null) : null,
            'deleted_at'      => $has('deleted_at') ? ($m['deleted_at'] ?? null) : null,
            'is_pinned'       => $has('is_pinned')  ? (bool)$m['is_pinned'] : false,
            'is_starred'      => in_array($id, $starredIds),
            'attachment'      => ($has('attachment') && !empty($m['attachment'])) ? UPLOAD_URL.$m['attachment'] : null,
            'attachment_type' => $has('attachment_type') ? ($m['attachment_type'] ?? null) : null,
            'file_name'       => $has('file_name')  ? ($m['file_name'] ?? null) : null,
            'file_size'       => $has('file_size')  ? (int)($m['file_size'] ?? 0) : null,
            'reply_to_id'     => $replyTo,
            'reply_content'   => $reply['content'] ?? null,
            'reply_name'      => $reply['name'] ?? null,
            'reactions'       => $reactions[$id] ?? [],
        ];
    }

    ob_end_clean();
    echo json_encode($data);

} catch (Throwable $e) {
    ob_end_clean();
    http_response_code(200);
    echo json_encode(['__error'=>true,'message'=>$e->getMessage(),'file'=>basename($e->getFile()),'line'=>$e->getLine()]);
}

function tableExists($pdo, $t) {
    try { $pdo->query("SELECT 1 FROM $t LIMIT 1"); return true; } catch (Exception $e) { return false; }
}