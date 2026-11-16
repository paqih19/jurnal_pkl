
<?php
// api/obrolan_users.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function obrolanUsersResponse(array $data): never
{
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

// Pastikan login
if (empty($_SESSION['user_id'])) {
    obrolanUsersResponse([]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    obrolanUsersResponse([]);
}

$uid = (int) $_SESSION['user_id'];

try {
    // Deteksi kolom opsional
    $columns = $pdo->query("SHOW COLUMNS FROM users")
        ->fetchAll(PDO::FETCH_COLUMN);

    $hasStatus = in_array('status', $columns, true);
    $hasPhoto = in_array('photo', $columns, true);
    $hasRole = in_array('role', $columns, true);

    // Pencarian pengguna
    $search = trim($_GET['q'] ?? '');

    if (!is_string($search)) {
        $search = '';
    }

    $search = mb_substr($search, 0, 100, 'UTF-8');

    // Susun query berdasarkan kolom yang tersedia
    $select = ['id', 'name'];

    $select[] = $hasPhoto
        ? 'photo'
        : 'NULL AS photo';

    $select[] = $hasRole
        ? 'role'
        : "'student' AS role";

    $sql = "
        SELECT " . implode(', ', $select) . "
        FROM users
        WHERE id != ?
    ";

    $params = [$uid];

    // Jika status tersedia, hanya tampilkan akun aktif.
    if ($hasStatus) {
        $sql .= " AND status = ?";
        $params[] = 'aktif';
    }

    // Pencarian berdasarkan nama
    if ($search !== '') {
        $sql .= " AND name LIKE ?";
        $params[] = '%' . $search . '%';
    }

    $sql .= "
        ORDER BY role DESC, name ASC
        LIMIT 200
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // URL dasar upload
    $uploadUrl = defined('UPLOAD_URL')
        ? rtrim(UPLOAD_URL, '/') . '/'
        : '../uploads/';

    foreach ($users as &$user) {
        $user['id'] = (int) $user['id'];

        $user['name'] = (string) $user['name'];
        $user['role'] = (string) $user['role'];

        if (!empty($user['photo'])) {
            $photoPath = ltrim(
                str_replace('\\', '/', $user['photo']),
                '/'
            );

            $user['photo_url'] = $uploadUrl . $photoPath;
        } else {
            $user['photo_url'] =
                'https://ui-avatars.com/api/?name=' .
                rawurlencode($user['name']) .
                '&background=4F46E5&color=ffffff&size=80';
        }
    }

    unset($user);

    obrolanUsersResponse($users);

} catch (Throwable $e) {
    error_log('obrolan_users: ' . $e->getMessage());

    obrolanUsersResponse([]);
}
