<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');
if (empty($_SESSION['user_id'])) { echo json_encode([]); exit; }

$uid = (int)$_SESSION['user_id'];

try {
    // Ambil semua room yang diikuti user
    $stmt = $pdo->prepare("
        SELECT r.id, r.name, r.type,
               (SELECT COUNT(*) FROM chat_members WHERE room_id = r.id) AS member_count,
               (SELECT m.content FROM messages m WHERE m.room_id = r.id ORDER BY m.id DESC LIMIT 1) AS last_msg,
               (SELECT m.created_at FROM messages m WHERE m.room_id = r.id ORDER BY m.id DESC LIMIT 1) AS last_time,
               (SELECT u.name FROM messages m JOIN users u ON m.user_id = u.id WHERE m.room_id = r.id ORDER BY m.id DESC LIMIT 1) AS last_sender
        FROM chat_rooms r
        JOIN chat_members cm ON cm.room_id = r.id
        WHERE cm.user_id = ?
        ORDER BY (SELECT MAX(m.id) FROM messages m WHERE m.room_id = r.id) DESC, r.id ASC
    ");
    $stmt->execute([$uid]);
    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Unread per room
    foreach ($rooms as &$r) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM messages m
            WHERE m.room_id = ? AND m.user_id != ?
              AND m.created_at > COALESCE(
                (SELECT last_read_at FROM chat_members WHERE room_id = ? AND user_id = ?),
                '1970-01-01'
              )
        ");
        $stmt->execute([$r['id'], $uid, $r['id'], $uid]);
        $r['unread'] = (int)$stmt->fetchColumn();

        // Untuk room private, tampilkan nama lawan bicara
        if ($r['type'] === 'private') {
            $stmt2 = $pdo->prepare("
                SELECT u.name, u.photo FROM chat_members cm
                JOIN users u ON cm.user_id = u.id
                WHERE cm.room_id = ? AND cm.user_id != ?
                LIMIT 1
            ");
            $stmt2->execute([$r['id'], $uid]);
            $other = $stmt2->fetch();
            if ($other) {
                $r['name'] = $other['name'];
                $r['photo'] = $other['photo'];
            }
        }
        $r['type'] = $r['type'] === 'public' ? 'group' : $r['type'];
    }

    echo json_encode($rooms);
} catch (Exception $e) {
    echo json_encode([]);
}