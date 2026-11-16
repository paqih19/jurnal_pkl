
<?php
// api/obrolan_fetch.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

ini_set('display_errors', '0');
error_reporting(E_ALL);

ob_start();

/**
 * Respons array JSON.
 * Format array dipertahankan agar frontend lama tetap kompatibel.
 */
function obrolanFetchResponse(array $data): never
{
    if (ob_get_level() > 0) {
        ob_end_clean();
    }

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/**
 * Periksa keberadaan tabel.
 */
function obrolanFetchTableExists(PDO $pdo, string $table): bool
{
    $allowedTables = [
        'message_reads',
        'message_reactions',
        'message_stars'
    ];

    if (!in_array($table, $allowedTables, true)) {
        return false;
    }

    try {
        $stmt = $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return $stmt !== false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Ambil daftar kolom tabel.
 */
function obrolanFetchColumns(PDO $pdo, string $table): array
{
    $allowedTables = ['messages', 'users'];

    if (!in_array($table, $allowedTables, true)) {
        return [];
    }

    $columns = [];

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[$row['Field']] = true;
        }
    } catch (Throwable $e) {
        error_log('[Obrolan Fetch Columns] ' . $e->getMessage());
    }

    return $columns;
}

try {

    // Periksa login.
    if (empty($_SESSION['user_id'])) {
        obrolanFetchResponse([]);
    }

    $uid = (int) $_SESSION['user_id'];

    if ($uid <= 0) {
        obrolanFetchResponse([]);
    }

    // Validasi parameter room.
    $roomId = filter_var(
        $_GET['room_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $sinceId = filter_var(
        $_GET['since_id'] ?? 0,
        FILTER_VALIDATE_INT
    );

    if (
        $roomId === false ||
        $roomId === null ||
        $roomId <= 0 ||
        $sinceId === false ||
        $sinceId === null ||
        $sinceId < 0
    ) {
        obrolanFetchResponse([]);
    }

    // Pastikan pengguna merupakan anggota room.
    $check = $pdo->prepare("
        SELECT 1
        FROM chat_members
        WHERE room_id = ?
          AND user_id = ?
        LIMIT 1
    ");

    $check->execute([
        $roomId,
        $uid
    ]);

    if (!$check->fetchColumn()) {
        obrolanFetchResponse([]);
    }

    // Deteksi kolom yang tersedia.
    $messageColumns = obrolanFetchColumns($pdo, 'messages');
    $userColumns = obrolanFetchColumns($pdo, 'users');

    $hasMessageColumn = fn($column) =>
        isset($messageColumns[$column]);

    $hasUserColumn = fn($column) =>
        isset($userColumns[$column]);

    // Kolom wajib.
    $requiredMessageColumns = [
        'id',
        'content',
        'created_at',
        'user_id',
        'room_id'
    ];

    foreach ($requiredMessageColumns as $column) {
        if (!$hasMessageColumn($column)) {
            throw new RuntimeException(
                "Kolom messages.$column tidak ditemukan."
            );
        }
    }

    if (!$hasUserColumn('id') || !$hasUserColumn('name')) {
        throw new RuntimeException(
            'Struktur tabel users tidak lengkap.'
        );
    }

    // Susun kolom SELECT.
    $selectParts = [
        'm.id',
        'm.content',
        'm.created_at',
        'm.user_id',
        'u.name',
        $hasUserColumn('photo') ? 'u.photo AS photo' : 'NULL AS photo'
    ];

    $optionalColumns = [
        'reply_to_id',
        'edited_at',
        'deleted_at',
        'is_pinned',
        'attachment',
        'attachment_type',
        'file_name',
        'file_size'
    ];

    foreach ($optionalColumns as $column) {
        if ($hasMessageColumn($column)) {
            $selectParts[] = "m.`$column`";
        }
    }

    // Filter pesan berdasarkan room.
    $where = 'm.room_id = ?';
    $params = [$roomId];

    // Pengambilan pesan baru untuk polling.
    if ($sinceId > 0) {
        $where .= ' AND m.id > ?';
        $params[] = $sinceId;
    }

    // Ambil maksimal 100 pesan terbaru.
    $sql = "
        SELECT " . implode(', ', $selectParts) . "
        FROM messages m
        INNER JOIN users u
            ON u.id = m.user_id
        WHERE $where
        ORDER BY m.id DESC
        LIMIT 100
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $messages = array_reverse(
        $stmt->fetchAll(PDO::FETCH_ASSOC)
    );

    if (empty($messages)) {
        obrolanFetchResponse([]);
    }

    // Kumpulkan ID pesan yang sedang ditampilkan.
    $messageIds = array_map(
        'intval',
        array_column($messages, 'id')
    );

    $placeholders = implode(
        ',',
        array_fill(0, count($messageIds), '?')
    );

    /*
     * REPLY MAP
     * Hanya mengambil pesan balasan dari room yang sama.
     */
    $replyMap = [];

    if ($hasMessageColumn('reply_to_id')) {

        $replyIds = [];

        foreach ($messages as $message) {
            $replyId = (int) ($message['reply_to_id'] ?? 0);

            if ($replyId > 0) {
                $replyIds[] = $replyId;
            }
        }

        $replyIds = array_values(array_unique($replyIds));

        if (!empty($replyIds)) {
            $replyPlaceholders = implode(
                ',',
                array_fill(0, count($replyIds), '?')
            );

            $deletedSelect = $hasMessageColumn('deleted_at')
                ? 'm2.deleted_at'
                : 'NULL AS deleted_at';

            $replyQuery = $pdo->prepare("
                SELECT
                    m2.id,
                    m2.content,
                    $deletedSelect,
                    u2.name
                FROM messages m2
                INNER JOIN users u2
                    ON u2.id = m2.user_id
                WHERE m2.room_id = ?
                  AND m2.id IN ($replyPlaceholders)
            ");

            $replyQuery->execute(
                array_merge([$roomId], $replyIds)
            );

            foreach ($replyQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $isDeleted = !empty($row['deleted_at']);

                $replyMap[(int) $row['id']] = [
                    'content' => $isDeleted
                        ? ''
                        : ($row['content'] ?? ''),
                    'name' => $row['name'],
                    'deleted' => $isDeleted
                ];
            }
        }
    }

    /*
     * READ RECEIPTS
     * Hanya membaca status pesan yang sedang ditampilkan.
     */
    $readIds = [];

    if (
        obrolanFetchTableExists($pdo, 'message_reads') &&
        !empty($messageIds)
    ) {
        $readQuery = $pdo->prepare("
            SELECT DISTINCT message_id
            FROM message_reads
            WHERE user_id != ?
              AND message_id IN ($placeholders)
        ");

        $readQuery->execute(
            array_merge([$uid], $messageIds)
        );

        $readIds = array_map(
            'intval',
            $readQuery->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    /*
     * REACTIONS
     * Hanya mengambil reaksi dari pesan dalam room ini.
     */
    $reactions = [];

    if (
        obrolanFetchTableExists($pdo, 'message_reactions') &&
        !empty($messageIds)
    ) {
        $reactionQuery = $pdo->prepare("
            SELECT message_id, emoji
            FROM message_reactions
            WHERE message_id IN ($placeholders)
        ");

        $reactionQuery->execute($messageIds);

        foreach ($reactionQuery->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $reactionId = (int) $row['message_id'];

            $reactions[$reactionId][] = [
                'emoji' => $row['emoji']
            ];
        }
    }

    /*
     * STARRED MESSAGES
     * Hanya mengambil bintang milik pengguna saat ini
     * untuk pesan dalam room yang sedang dibuka.
     */
    $starredIds = [];

    if (
        obrolanFetchTableExists($pdo, 'message_stars') &&
        !empty($messageIds)
    ) {
        $starQuery = $pdo->prepare("
            SELECT message_id
            FROM message_stars
            WHERE user_id = ?
              AND message_id IN ($placeholders)
        ");

        $starQuery->execute(
            array_merge([$uid], $messageIds)
        );

        $starredIds = array_map(
            'intval',
            $starQuery->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    /*
     * FORMAT FOTO DAN LAMPIRAN
     */
    $uploadUrl = defined('UPLOAD_URL')
        ? rtrim(UPLOAD_URL, '/') . '/'
        : '../uploads/';

    $data = [];

    foreach ($messages as $m) {

        $id = (int) $m['id'];

        $deleted = $hasMessageColumn('deleted_at')
            && !empty($m['deleted_at']);

        $replyTo = (
            $hasMessageColumn('reply_to_id') &&
            !empty($m['reply_to_id'])
        )
            ? (int) $m['reply_to_id']
            : null;

        $reply = $replyTo
            ? ($replyMap[$replyTo] ?? null)
            : null;

        $createdTimestamp = strtotime($m['created_at']);

        $createdTime = $createdTimestamp !== false
            ? date('H:i', $createdTimestamp)
            : '';

        $photo = !empty($m['photo'])
            ? $uploadUrl . ltrim($m['photo'], '/\\')
            : 'https://ui-avatars.com/api/?name=' .
                urlencode($m['name']) .
                '&background=random&size=50';

        $attachment = (
            $hasMessageColumn('attachment') &&
            !empty($m['attachment'])
        )
            ? $uploadUrl . ltrim($m['attachment'], '/\\')
            : null;

        $data[] = [
            'id' => $id,

            'content' => $deleted
                ? ''
                : ($m['content'] ?? ''),

            'created_at' => $createdTime,
            'created_at_full' => $m['created_at'],

            'name' => $m['name'],
            'photo' => $photo,

            'is_me' => ((int) $m['user_id'] === $uid),

            'is_read' => in_array(
                $id,
                $readIds,
                true
            ),

            'edited_at' => $hasMessageColumn('edited_at')
                ? ($m['edited_at'] ?? null)
                : null,

            'deleted_at' => $hasMessageColumn('deleted_at')
                ? ($m['deleted_at'] ?? null)
                : null,

            'is_pinned' => $hasMessageColumn('is_pinned')
                ? (bool) ($m['is_pinned'] ?? false)
                : false,

            'is_starred' => in_array(
                $id,
                $starredIds,
                true
            ),

            'attachment' => $attachment,

            'attachment_type' => $hasMessageColumn('attachment_type')
                ? ($m['attachment_type'] ?? null)
                : null,

            'file_name' => $hasMessageColumn('file_name')
                ? ($m['file_name'] ?? null)
                : null,

            'file_size' => $hasMessageColumn('file_size')
                ? (int) ($m['file_size'] ?? 0)
                : null,

            'reply_to_id' => $replyTo,

            'reply_content' => $reply['content'] ?? null,

            'reply_name' => $reply['name'] ?? null,

            'reply_deleted' => $reply['deleted'] ?? false,

            'reactions' => $reactions[$id] ?? []
        ];
    }

    obrolanFetchResponse($data);

} catch (Throwable $e) {

    error_log(
        '[Obrolan Fetch] ' . $e->getMessage()
    );

    obrolanFetchResponse([]);
}
