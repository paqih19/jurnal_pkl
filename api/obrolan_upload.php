<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
if (empty($_FILES['file'])) { echo json_encode(['status'=>'error','message'=>'No file']); exit; }

$uid = (int)$_SESSION['user_id'];
$file = $_FILES['file'];
$type = $_POST['type'] ?? 'file';
$caption = trim($_POST['caption'] ?? '');
$replyTo = !empty($_POST['reply_to_id']) ? (int)$_POST['reply_to_id'] : null;
$roomId = (int)($_POST['room_id'] ?? 1);

$check = $pdo->prepare("SELECT 1 FROM chat_members WHERE room_id = ? AND user_id = ?");
$check->execute([$roomId, $uid]);
if (!$check->fetch()) { echo json_encode(['status'=>'error','message'=>'Bukan anggota room']); exit; }

if ($file['error'] !== UPLOAD_ERR_OK) { echo json_encode(['status'=>'error','message'=>'Upload error']); exit; }
if ($file['size'] > 10*1024*1024) { echo json_encode(['status'=>'error','message'=>'Maks 10MB']); exit; }

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
$allowed = [
    'image' => ['jpg','jpeg','png','gif','webp'],
    'audio' => ['mp3','wav','ogg','webm','m4a'],
    'file'  => ['pdf','doc','docx','xls','xlsx','ppt','pptx','zip','rar','txt','csv']
];
if (!in_array($ext, $allowed[$type] ?? [])) {
    echo json_encode(['status'=>'error','message'=>'Tipe file tidak diizinkan']); exit;
}

$dir = __DIR__ . '/../uploads/';
if (!is_dir($dir)) mkdir($dir, 0777, true);
$filename = 'chat_' . $uid . '_' . time() . '_' . uniqid() . '.' . $ext;
if (!move_uploaded_file($file['tmp_name'], $dir.$filename)) {
    echo json_encode(['status'=>'error','message'=>'Gagal simpan']); exit;
}

try {
    $stmt = $pdo->prepare("INSERT INTO messages (user_id, room_id, content, attachment, attachment_type, file_name, file_size, reply_to_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$uid, $roomId, $caption, $filename, $type, $file['name'], $file['size'], $replyTo]);
    $msgId = $pdo->lastInsertId();

    // Notifikasi
    require_once __DIR__ . '/../includes/notifications.php';
    try {
        $stmtRoom = $pdo->prepare("SELECT name, type FROM chat_rooms WHERE id = ?");
        $stmtRoom->execute([$roomId]);
        $room = $stmtRoom->fetch();
        $roomName = $room['name'] ?? 'Obrolan';
        $roomType = $room['type'] ?? 'group';

        $stmtUser = $pdo->prepare("SELECT name FROM users WHERE id = ?");
        $stmtUser->execute([$uid]);
        $senderName = $stmtUser->fetchColumn() ?: 'Seseorang';

        $stmtMembers = $pdo->prepare("SELECT cm.user_id FROM chat_members cm WHERE cm.room_id = ? AND cm.user_id != ?");
        $stmtMembers->execute([$roomId, $uid]);
        $otherIds = array_column($stmtMembers->fetchAll(), 'user_id');

        if (!empty($otherIds)) {
            $icons = ['image'=>'🖼️','audio'=>'🎤','file'=>'📎'];
            $icon = $icons[$type] ?? '📎';
            $title = $roomType === 'private'
                ? "{$icon} Pesan baru dari {$senderName}"
                : "{$icon} Obrolan baru di {$roomName}";
            $msg = $senderName . ' mengirim ' . ($type === 'image' ? 'gambar' : ($type === 'audio' ? 'voice note' : 'file'));
            createNotificationBulk($pdo, $otherIds, 'chat', $title, $msg, 'obrolan.php');
        }
    } catch (Exception $e) {}

    echo json_encode([
        'status' => 'success',
        'id' => $msgId,
        'attachment' => UPLOAD_URL . $filename,
        'attachment_type' => $type,
        'file_name' => $file['name'],
        'file_size' => $file['size'],
        'caption' => $caption
    ]);
} catch (Exception $e) {
    @unlink($dir.$filename);
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}