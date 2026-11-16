
<?php
// api/obrolan_create_group.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/notifications.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

/**
 * Respons JSON.
 */
function groupJsonResponse(
    string $status,
    string $message,
    array $extra = [],
    int $httpCode = 200
): never {
    http_response_code($httpCode);

    echo json_encode(
        array_merge([
            'status' => $status,
            'message' => $message
        ], $extra),
        JSON_UNESCAPED_UNICODE |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

// Hanya menerima POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    groupJsonResponse(
        'error',
        'Metode permintaan tidak diizinkan.',
        [],
        405
    );
}

// Periksa autentikasi.
if (empty($_SESSION['user_id'])) {
    groupJsonResponse(
        'error',
        'Silakan login terlebih dahulu.',
        [],
        401
    );
}

$uid = (int) $_SESSION['user_id'];

if ($uid <= 0) {
    groupJsonResponse(
        'error',
        'Sesi pengguna tidak valid.',
        [],
        401
    );
}

// Baca JSON.
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!is_array($input)) {
    groupJsonResponse(
        'error',
        'Data permintaan tidak valid.',
        [],
        400
    );
}

// Validasi nama grup.
$name = trim((string) ($input['name'] ?? ''));

if (
    $name === '' ||
    mb_strlen($name, 'UTF-8') > 80
) {
    groupJsonResponse(
        'error',
        'Nama grup wajib diisi dan maksimal 80 karakter.',
        [],
        422
    );
}

// Validasi anggota.
$memberIds = $input['members'] ?? [];

if (!is_array($memberIds) || empty($memberIds)) {
    groupJsonResponse(
        'error',
        'Pilih minimal satu anggota.',
        [],
        422
    );
}

// Batasi jumlah data mentah.
if (count($memberIds) > 100) {
    groupJsonResponse(
        'error',
        'Maksimal 100 anggota.',
        [],
        422
    );
}

// Validasi setiap ID anggota.
$cleanIds = [];

foreach ($memberIds as $memberId) {
    if (
        !is_scalar($memberId) ||
        filter_var($memberId, FILTER_VALIDATE_INT) === false
    ) {
        groupJsonResponse(
            'error',
            'Terdapat ID anggota yang tidak valid.',
            [],
            422
        );
    }

    $memberId = (int) $memberId;

    if ($memberId <= 0) {
        groupJsonResponse(
            'error',
            'ID anggota tidak valid.',
            [],
            422
        );
    }

    $cleanIds[] = $memberId;
}

// Pembuat otomatis masuk grup.
$cleanIds[] = $uid;

$memberIds = array_values(
    array_unique($cleanIds)
);

if (count($memberIds) > 101) {
    groupJsonResponse(
        'error',
        'Jumlah anggota melebihi batas.',
        [],
        422
    );
}

try {
    // Pastikan semua anggota benar-benar terdaftar.
    $placeholders = implode(
        ',',
        array_fill(0, count($memberIds), '?')
    );

    $check = $pdo->prepare("
        SELECT id
        FROM users
        WHERE id IN ($placeholders)
    ");

    $check->execute($memberIds);

    $validIds = array_map(
        'intval',
        $check->fetchAll(PDO::FETCH_COLUMN)
    );

    sort($validIds);

    $expectedIds = $memberIds;
    sort($expectedIds);

    if ($validIds !== $expectedIds) {
        groupJsonResponse(
            'error',
            'Satu atau beberapa anggota tidak ditemukan.',
            [],
            422
        );
    }

    $pdo->beginTransaction();

    // Buat grup.
    $stmt = $pdo->prepare("
        INSERT INTO chat_rooms
            (name, type, created_by, created_at)
        VALUES
            (?, 'group', ?, NOW())
    ");

    $stmt->execute([
        $name,
        $uid
    ]);

    $roomId = (int) $pdo->lastInsertId();

    if ($roomId <= 0) {
        throw new RuntimeException(
            'ID grup tidak berhasil dibuat.'
        );
    }

    // Masukkan seluruh anggota.
    $insertMember = $pdo->prepare("
        INSERT INTO chat_members
            (room_id, user_id, joined_at)
        VALUES
            (?, ?, NOW())
    ");

    foreach ($validIds as $memberId) {
        $insertMember->execute([
            $roomId,
            $memberId
        ]);
    }

    // Pesan sistem pertama.
    $systemMessage = $pdo->prepare("
        INSERT INTO messages
            (user_id, room_id, content, created_at)
        VALUES
            (?, ?, ?, NOW())
    ");

    $systemMessage->execute([
        $uid,
        $roomId,
        '📢 Grup "' . $name . '" berhasil dibuat.'
    ]);

    // Simpan seluruh perubahan.
    $pdo->commit();

    // Kirim notifikasi setelah transaksi berhasil.
    $notifIds = array_values(
        array_filter(
            $validIds,
            fn($id) => $id !== $uid
        )
    );

    if (!empty($notifIds)) {
        try {
            createNotificationBulk(
                $pdo,
                $notifIds,
                'group',
                '👥 Kamu ditambahkan ke grup "' . $name . '"',
                'Buka obrolan untuk mulai berkomunikasi.',
                'obrolan.php'
            );
        } catch (Throwable $e) {
            error_log(
                '[Group Notification] ' . $e->getMessage()
            );
        }
    }

    groupJsonResponse(
        'success',
        'Grup berhasil dibuat.',
        [
            'room_id' => $roomId,
            'room_name' => $name,
            'member_count' => count($validIds)
        ]
    );

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        '[Obrolan Create Group] ' . $e->getMessage()
    );

    groupJsonResponse(
        'error',
        'Gagal membuat grup. Silakan coba lagi.',
        [],
        500
    );
}
