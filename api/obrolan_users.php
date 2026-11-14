<?php
session_start();
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');
if (empty($_SESSION['user_id'])) { echo json_encode([]); exit; }

$uid = (int)$_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT id, name, photo, role
    FROM users
    WHERE id != ?
    ORDER BY role DESC, name ASC
");
$stmt->execute([$uid]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($users as &$u) {
    $u['photo_url'] = !empty($u['photo'])
        ? UPLOAD_URL . $u['photo']
        : 'https://ui-avatars.com/api/?name=' . urlencode($u['name']) . '&background=random&size=40';
}
echo json_encode($users);