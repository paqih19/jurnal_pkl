<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) { echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit; }
if (empty($_FILES['file'])) { echo json_encode(['status'=>'error','message'=>'No file']); exit; }

$uid = (int)$_SESSION['user_id'];
$file = $_FILES['file'];
$type = $_POST['type'] ?? 'file';
$replyTo = !empty($_POST['reply_to_id']) ? (int)$_POST['reply_to_id'] : null;
$roomId = (int)($_POST['room_id'] ?? 1);

// Cek anggota room
$check = $pdo->prepare("SELECT 1 FROM chat_members WHERE room_id = ? AND user_id = ?");
$check->execute([$roomId, $uid]);
if (!$check->fetch()) { echo json_encode(['status'=>'error','message'=>'Bukan anggota room']); exit; }

if ($file['error'] !== UPLOAD_ERR_OK) { echo json_encode(['status'=>'error','message'=>'Upload error']); exit; }
if ($file['size'] > 10*1024*1024) { echo json_encode(['status'=>'error','message'=>'Maks 10MB']); exit; }

$ext = pathinfo($file['name'], PATHINFO_EXTENSION);
$allowed = ['image'=>['jpg','jpeg','png','gif','webp'], 'audio'=>['mp3','wav','ogg','webm','m4a'], 'file'=>['pdf','doc','docx','xls','xlsx','zip','rar','txt','csv']];
if (!in_array(strtolower($ext), $allowed[$type] ?? [])) {
    echo json_encode(['status'=>'error','message'=>'Tipe file tidak diizinkan']); exit;
}

$dir = __DIR__ . '/../uploads/';
if (!is_dir($dir)) mkdir($dir, 0777, true);
$filename = uniqid('chat_').'.'.$ext;
if (!move_uploaded_file($file['tmp_name'], $dir.$filename)) {
    echo json_encode(['status'=>'error','message'=>'Gagal simpan']); exit;
}

try {
    $stmt = $pdo->prepare("INSERT INTO messages (user_id, room_id, content, attachment, attachment_type, file_name, file_size, reply_to_id, created_at) VALUES (?, ?, '', ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([$uid, $roomId, $filename, $type, $file['name'], $file['size'], $replyTo]);
    echo json_encode(['status'=>'success','id'=>$pdo->lastInsertId()]);
} catch (Exception $e) { echo json_encode(['status'=>'error','message'=>$e->getMessage()]); }