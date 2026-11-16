
<?php
// api/obrolan_rooms.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function roomsJsonResponse(array $data): never
{
    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function roomsGetColumns(PDO $pdo, string $table): array
{
    // Nama tabel hanya berasal dari daftar internal.
    $allowed = ['messages', 'users', 'chat_rooms', 'chat_members'];

    if (!in_array($table, $allowed, true)) {
        throw new RuntimeException('Nama tabel tidak diizinkan.');
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    roomsJsonResponse([]);
}

$uid = (int) $_SESSION['user_id'];

try {
    // ==========================================
    // DETEKSI STRUKTUR DATABASE
    // ==========================================

    $messageColumns = roomsGetColumns($pdo, 'messages');
    $userColumns = roomsGetColumns($pdo, 'users');
    $memberColumns = roomsGetColumns($pdo, 'chat_members');

    $requiredMessages = [
        'id',
        'room_id',
        'user_id',
        'content',
        'created_at'
    ];

    foreach ($requiredMessages as $column) {
        if (!in_array($column, $messageColumns, true)) {
            throw new RuntimeException(
                "Kolom messages.$column tidak ditemukan."
            );
        }
    }

    if (
        !in_array('id', $userColumns, true) ||
        !in_array('id', $memberColumns, true) &&
        !in_array('room_id', $memberColumns, true)
    ) {
        throw new RuntimeException('Struktur tabel tidak sesuai.');
    }

    // Mendukung users.name maupun users.nama.
    $userNameColumn = in_array('name', $userColumns, true)
        ? 'name'
        : (in_array('nama', $userColumns, true) ? 'nama' : null);

    if ($userNameColumn === null) {
        throw new RuntimeException(
            'Kolom nama pengguna tidak ditemukan.'
        );
    }

    $hasPhoto = in_array('photo', $userColumns, true);
    $hasDeletedAt = in_array('deleted_at', $messageColumns, true);
    $hasLastRead = in_array('last_read_at', $memberColumns, true);

    $deletedCondition = $hasDeletedAt
        ? "(m.deleted_at IS NULL OR m.deleted_at = '')"
        : "1=1";

    $lastReadExpression = $hasLastRead
        ? "COALESCE(cm.last_read_at, '1970-01-01 00:00:00')"
        : "'1970-01-01 00:00:00'";

    $photoSelect = $hasPhoto ? 'u.photo' : 'NULL AS photo';

    // ==========================================
    // AMBIL DAFTAR RUANG OBROLAN
    // ==========================================

    $sql = "
        SELECT
            r.id,
            r.name,
            r.type,

            (
                SELECT COUNT(*)
                FROM chat_members members
                WHERE members.room_id = r.id
            ) AS member_count,

            (
                SELECT m.content
                FROM messages m
                WHERE m.room_id = r.id
                  AND $deletedCondition
                ORDER BY m.id DESC
                LIMIT 1
            ) AS last_msg,

            (
                SELECT m.created_at
                FROM messages m
                WHERE m.room_id = r.id
                  AND $deletedCondition
                ORDER BY m.id DESC
                LIMIT 1
            ) AS last_time,

            (
                SELECT u.`$userNameColumn`
                FROM messages m
                LEFT JOIN users u ON u.id = m.user_id
                WHERE m.room_id = r.id
                  AND $deletedCondition
                ORDER BY m.id DESC
                LIMIT 1
            ) AS last_sender,

            (
                SELECT COUNT(*)
                FROM messages m
                WHERE m.room_id = r.id
                  AND m.user_id != ?
                  AND m.created_at > $lastReadExpression
                  AND $deletedCondition
            ) AS unread

        FROM chat_rooms r

        INNER JOIN chat_members cm
            ON cm.room_id = r.id

        WHERE cm.user_id = ?

        ORDER BY
            (
                SELECT MAX(m.id)
                FROM messages m
                WHERE m.room_id = r.id
            ) DESC,
            r.id ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$uid, $uid]);

    $rooms = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$rooms) {
        roomsJsonResponse([]);
    }

    // ==========================================
    // AMBIL DATA LAWAN BICARA PRIVATE CHAT
    // ==========================================

    $privateRoomIds = [];

    foreach ($rooms as $room) {
        if (($room['type'] ?? '') === 'private') {
            $privateRoomIds[] = (int) $room['id'];
        }
    }

    $otherMap = [];

    if ($privateRoomIds) {
        $placeholders = implode(
            ',',
            array_fill(0, count($privateRoomIds), '?')
        );

        $photoSelect = $hasPhoto
            ? 'u.photo'
            : 'NULL AS photo';

        $otherSql = "
            SELECT
                cm.room_id,
                u.`$userNameColumn` AS name,
                $photoSelect

            FROM chat_members cm

            INNER JOIN users u
                ON u.id = cm.user_id

            WHERE cm.room_id IN ($placeholders)
              AND cm.user_id != ?

            ORDER BY cm.room_id ASC
        ";

        $params = array_merge($privateRoomIds, [$uid]);

        $stmtOther = $pdo->prepare($otherSql);
        $stmtOther->execute($params);

        foreach ($stmtOther->fetchAll(PDO::FETCH_ASSOC) as $other) {
            $roomId = (int) $other['room_id'];

            if (!isset($otherMap[$roomId])) {
                $otherMap[$roomId] = [
                    'name' => $other['name'],
                    'photo' => $other['photo']
                ];
            }
        }
    }

    // ==========================================
    // RAPKAN DATA UNTUK FRONTEND
    // ==========================================

    foreach ($rooms as &$room) {
        $room['id'] = (int) $room['id'];
        $room['unread'] = (int) $room['unread'];
        $room['member_count'] = (int) $room['member_count'];

        if ($room['type'] === 'public') {
            $room['type'] = 'group';
        }

        if (
            $room['type'] === 'private' &&
            isset($otherMap[$room['id']])
        ) {
            $room['name'] = $otherMap[$room['id']]['name'];
            $room['photo'] = $otherMap[$room['id']]['photo'];
        } else {
            $room['photo'] = null;
        }

        $room['last_msg'] = $room['last_msg'] ?? '';
        $room['last_sender'] = $room['last_sender'] ?? '';

        if (
            $room['last_msg'] === '' &&
            !empty($room['last_time'])
        ) {
            $room['last_msg'] = 'Pesan telah dihapus';
        }
    }

    unset($room);

    roomsJsonResponse($rooms);

} catch (Throwable $e) {
    error_log(
        '[OBROLAN ROOMS] ' . $e->getMessage()
    );

    // Format array tetap dipertahankan untuk frontend lama.
    roomsJsonResponse([]);
}
