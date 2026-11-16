
<?php
// detail_siswa.php

require_once __DIR__ . '/includes/auth.php';

requireLogin();
requireRole('pembimbing');

$user = currentUser();
$uid = (int) $user['id'];

$idSiswa = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$idSiswa || $idSiswa <= 0) {
    setFlash('error', 'ID siswa tidak valid.');
    redirect('monitoring.php');
}

// ==================================================
// AMBIL DATA SISWA
// ==================================================

$stmt = $pdo->prepare("
    SELECT *
    FROM users
    WHERE id = ?
      AND role NOT IN ('pembimbing', 'teacher', 'admin')
    LIMIT 1
");
$stmt->execute([$idSiswa]);

$siswa = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$siswa) {
    setFlash('error', 'Data siswa tidak ditemukan.');
    redirect('monitoring.php');
}

// ==================================================
// FUNGSI BANTU
// ==================================================

function detailImageUrl($filename): string
{
    if (!$filename) {
        return '';
    }

    $filename = trim((string) $filename);

    if (preg_match('/^https?:\/\//i', $filename)) {
        return $filename;
    }

    if (str_starts_with($filename, '/')) {
        return $filename;
    }

    $base = defined('UPLOAD_URL') ? rtrim(UPLOAD_URL, '/') : 'uploads';

    return $base . '/' . ltrim($filename, '/');
}

function detailDate($date): string
{
    if (!$date) {
        return '-';
    }

    $time = strtotime($date);

    return $time ? date('d M Y', $time) : '-';
}

function detailStatusClass($status): string
{
    return match ($status) {
        'hadir', 'done' => 'bg-green-100 text-green-700',
        'izin', 'doing' => 'bg-blue-100 text-blue-700',
        'sakit' => 'bg-yellow-100 text-yellow-700',
        'alpha' => 'bg-red-100 text-red-700',
        'todo' => 'bg-gray-100 text-gray-700',
        default => 'bg-gray-100 text-gray-600'
    };
}

function detailStatusLabel($status): string
{
    return match ($status) {
        'hadir' => 'Hadir',
        'izin' => 'Izin',
        'sakit' => 'Sakit',
        'alpha' => 'Alpha',
        'done' => 'Selesai',
        'doing' => 'Sedang Dikerjakan',
        'todo' => 'Belum Dikerjakan',
        default => ucfirst((string) $status)
    };
}

// ==================================================
// STATISTIK
// ==================================================

$stat = [
    'total_absen' => 0,
    'hadir' => 0,
    'izin' => 0,
    'sakit' => 0,
    'alpha' => 0,
    'total_jurnal' => 0,
    'total_tugas' => 0,
    'tugas_selesai' => 0
];

try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_absen,
            SUM(status = 'hadir') AS hadir,
            SUM(status = 'izin') AS izin,
            SUM(status = 'sakit') AS sakit,
            SUM(status = 'alpha') AS alpha
        FROM attendance
        WHERE user_id = ?
    ");

    $stmt->execute([$idSiswa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    foreach ($row as $key => $value) {
        $stat[$key] = (int) $value;
    }
} catch (Throwable $e) {
    error_log('Detail attendance stats: ' . $e->getMessage());
}

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM journals
        WHERE user_id = ?
    ");
    $stmt->execute([$idSiswa]);
    $stat['total_jurnal'] = (int) $stmt->fetchColumn();
} catch (Throwable $e) {
    error_log('Detail journal stats: ' . $e->getMessage());
}

try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*) AS total_tugas,
            SUM(status = 'done') AS tugas_selesai
        FROM tasks
        WHERE assigned_to = ?
           OR (created_by = ? AND assigned_to IS NULL)
    ");

    $stmt->execute([$idSiswa, $idSiswa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stat['total_tugas'] = (int) ($row['total_tugas'] ?? 0);
    $stat['tugas_selesai'] = (int) ($row['tugas_selesai'] ?? 0);
} catch (Throwable $e) {
    error_log('Detail task stats: ' . $e->getMessage());
}

// ==================================================
// RIWAYAT ABSENSI
// ==================================================

$attendance = [];

try {
    $stmt = $pdo->prepare("
        SELECT *
        FROM attendance
        WHERE user_id = ?
        ORDER BY date DESC, id DESC
        LIMIT 30
    ");

    $stmt->execute([$idSiswa]);
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Detail attendance: ' . $e->getMessage());
}

// ==================================================
// RIWAYAT JURNAL
// ==================================================

$journals = [];

try {
    $stmt = $pdo->prepare("
        SELECT
            j.*,
            reviewer.name AS reviewer_name
        FROM journals j
        LEFT JOIN users reviewer
            ON reviewer.id = j.commented_by
        WHERE j.user_id = ?
        ORDER BY j.activity_date DESC, j.id DESC
        LIMIT 30
    ");

    $stmt->execute([$idSiswa]);
    $journals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Detail journals: ' . $e->getMessage());

    // Fallback jika kolom komentar belum tersedia.
    try {
        $stmt = $pdo->prepare("
            SELECT *
            FROM journals
            WHERE user_id = ?
            ORDER BY activity_date DESC, id DESC
            LIMIT 30
        ");

        $stmt->execute([$idSiswa]);
        $journals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) {
        error_log('Detail journals fallback: ' . $e2->getMessage());
    }
}

// ==================================================
// FOTO DOKUMENTASI JURNAL
// ==================================================

$journalPhotos = [];

try {
    if ($journals) {
        $journalIds = array_map(
            'intval',
            array_column($journals, 'id')
        );

        $placeholders = implode(',', array_fill(0, count($journalIds), '?'));

        $stmt = $pdo->prepare("
            SELECT journal_id, filename
            FROM journal_photos
            WHERE journal_id IN ($placeholders)
            ORDER BY journal_id DESC
        ");

        $stmt->execute($journalIds);

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $photo) {
            $journalPhotos[(int) $photo['journal_id']][] = $photo['filename'];
        }
    }
} catch (Throwable $e) {
    error_log('Detail journal photos: ' . $e->getMessage());
}

// ==================================================
// TUGAS SISWA
// ==================================================

$tasks = [];

try {
    $stmt = $pdo->prepare("
        SELECT *
        FROM tasks
        WHERE assigned_to = ?
           OR (created_by = ? AND assigned_to IS NULL)
        ORDER BY
            CASE status
                WHEN 'doing' THEN 1
                WHEN 'todo' THEN 2
                WHEN 'done' THEN 3
                ELSE 4
            END,
            deadline ASC
        LIMIT 50
    ");

    $stmt->execute([$idSiswa, $idSiswa]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Detail tasks: ' . $e->getMessage());
}

// ==================================================
// HEADER
// ==================================================

require_once __DIR__ . '/includes/header.php';

$namaSiswa = $siswa['name'] ?? 'Siswa';
$fotoSiswa = $siswa['photo'] ?? null;

?>

<div class="mb-6 flex items-center justify-between flex-wrap gap-3">

    <div>
        <a href="monitoring.php"
           class="text-sm text-indigo-600 hover:underline">
            ← Kembali ke Monitoring
        </a>

        <h1 class="text-2xl font-bold dark:text-white mt-2">
            Detail Siswa PKL
        </h1>

        <p class="text-gray-500 text-sm mt-1">
            Informasi lengkap perkembangan siswa.
        </p>
    </div>

    <button onclick="window.print()"
            class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-semibold">
        🖨️ Cetak Laporan
    </button>

</div>

<!-- PROFIL SISWA -->

<div class="bg-white dark:bg-gray-800 rounded-2xl p-5 border border-gray-100 dark:border-gray-700 mb-6">

    <div class="flex items-center gap-4 flex-wrap">

        <img
            src="<?= avatarUrl($fotoSiswa, $namaSiswa, 90) ?>"
            class="w-20 h-20 rounded-full object-cover border-4 border-indigo-100"
            alt="Foto siswa"
        >

        <div class="flex-1">

            <h2 class="text-xl font-bold dark:text-white">
                <?= e($namaSiswa) ?>
            </h2>

            <p class="text-sm text-gray-500">
                <?= e($siswa['email'] ?? '-') ?>
            </p>

            <div class="flex flex-wrap gap-2 mt-2">

                <?php if (!empty($siswa['jurusan'])): ?>
                    <span class="bg-indigo-50 text-indigo-700 text-xs px-3 py-1 rounded-full">
                        <?= e($siswa['jurusan']) ?>
                    </span>
                <?php endif; ?>

                <?php if (!empty($siswa['kelas'])): ?>
                    <span class="bg-blue-50 text-blue-700 text-xs px-3 py-1 rounded-full">
                        Kelas <?= e($siswa['kelas']) ?>
                    </span>
                <?php endif; ?>

                <?php if (!empty($siswa['nis'])): ?>
                    <span class="bg-gray-100 text-gray-700 text-xs px-3 py-1 rounded-full">
                        NIS: <?= e($siswa['nis']) ?>
                    </span>
                <?php endif; ?>

            </div>

        </div>

    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-5 pt-5 border-t border-gray-100 dark:border-gray-700">

        <div>
            <div class="text-xs text-gray-500">Username</div>
            <div class="font-semibold dark:text-white">
                <?= e($siswa['username'] ?? '-') ?>
            </div>
        </div>

        <div>
            <div class="text-xs text-gray-500">Sekolah</div>
            <div class="font-semibold dark:text-white">
                <?= e($siswa['sekolah'] ?? '-') ?>
            </div>
        </div>

        <div>
            <div class="text-xs text-gray-500">Bergabung</div>
            <div class="font-semibold dark:text-white">
                <?= detailDate($siswa['created_at'] ?? null) ?>
            </div>
        </div>

    </div>

    <?php if (!empty($siswa['bio'])): ?>
        <div class="mt-4 text-sm text-gray-600 dark:text-gray-300">
            <?= nl2br(e($siswa['bio'])) ?>
        </div>
    <?php endif; ?>

</div>

<!-- STATISTIK -->

<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">

    <?php
    $cards = [
        ['Total Absensi', $stat['total_absen'], 'text-indigo-600', '📅'],
        ['Hadir', $stat['hadir'], 'text-green-600', '✅'],
        ['Total Jurnal', $stat['total_jurnal'], 'text-blue-600', '📘'],
        ['Tugas Selesai', $stat['tugas_selesai'], 'text-green-600', '🎯']
    ];
    ?>

    <?php foreach ($cards as [$label, $value, $color, $icon]): ?>

        <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">

            <div class="text-xs text-gray-500">
                <?= $icon ?> <?= e($label) ?>
            </div>

            <div class="text-2xl font-bold <?= $color ?> mt-2">
                <?= (int) $value ?>
            </div>

        </div>

    <?php endforeach; ?>

</div>

<!-- RINGKASAN ABSENSI -->

<div class="bg-white dark:bg-gray-800 rounded-2xl p-5 border border-gray-100 dark:border-gray-700 mb-6">

    <h2 class="font-bold text-lg dark:text-white mb-4">
        📊 Rekap Kehadiran
    </h2>

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">

        <?php
        $attendanceCards = [
            ['Hadir', $stat['hadir'], 'text-green-600'],
            ['Izin', $stat['izin'], 'text-blue-600'],
            ['Sakit', $stat['sakit'], 'text-yellow-600'],
            ['Alpha', $stat['alpha'], 'text-red-600']
        ];
        ?>

        <?php foreach ($attendanceCards as [$label, $value, $color]): ?>

            <div class="bg-gray-50 dark:bg-gray-700/40 rounded-xl p-3">

                <div class="text-xs text-gray-500">
                    <?= e($label) ?>
                </div>

                <div class="text-xl font-bold <?= $color ?>">
                    <?= (int) $value ?>
                </div>

            </div>

        <?php endforeach; ?>

    </div>

</div>

<!-- RIWAYAT ABSENSI -->

<div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 mb-6 overflow-hidden">

    <div class="p-5 border-b border-gray-100 dark:border-gray-700">

        <h2 class="font-bold text-lg dark:text-white">
            📅 Riwayat Absensi
        </h2>

        <p class="text-xs text-gray-500 mt-1">
            Menampilkan maksimal 30 data absensi terbaru.
        </p>

    </div>

    <div class="overflow-x-auto">

        <table class="w-full text-sm text-left">

            <thead class="bg-gray-50 dark:bg-gray-700/40 text-gray-500 text-xs uppercase">

                <tr>
                    <th class="p-3">Tanggal</th>
                    <th class="p-3">Status</th>
                    <th class="p-3">Jam Masuk</th>
                    <th class="p-3">Lokasi</th>
                    <th class="p-3">Bukti Foto</th>
                </tr>

            </thead>

            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">

                <?php if (!$attendance): ?>

                    <tr>
                        <td colspan="5" class="p-8 text-center text-gray-500">
                            Belum ada riwayat absensi.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($attendance as $a): ?>

                        <tr>

                            <td class="p-3 dark:text-white">
                                <?= detailDate($a['date'] ?? null) ?>
                            </td>

                            <td class="p-3">
                                <span class="px-3 py-1 rounded-full text-xs font-semibold <?= detailStatusClass($a['status'] ?? '') ?>">
                                    <?= e(detailStatusLabel($a['status'] ?? '')) ?>
                                </span>
                            </td>

                            <td class="p-3 text-gray-500">
                                <?= !empty($a['check_in'])
                                    ? e(substr($a['check_in'], 0, 5))
                                    : (!empty($a['check_in_time'])
                                        ? e(substr($a['check_in_time'], 0, 5))
                                        : '-') ?>
                            </td>

                            <td class="p-3 text-gray-500 max-w-xs">
                                <?= e($a['location_text'] ?? '-') ?>
                            </td>

                            <td class="p-3">

                                <?php if (!empty($a['photo'])): ?>

                                    <a href="<?= e(detailImageUrl($a['photo'])) ?>"
                                       target="_blank"
                                       rel="noopener noreferrer"
                                       class="text-indigo-600 hover:underline text-xs">
                                        Lihat Foto
                                    </a>

                                <?php else: ?>

                                    <span class="text-gray-400 text-xs">
                                        Tidak ada foto
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

<!-- RIWAYAT JURNAL -->

<div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 mb-6 overflow-hidden">

    <div class="p-5 border-b border-gray-100 dark:border-gray-700">

        <h2 class="font-bold text-lg dark:text-white">
            📘 Jurnal Kegiatan PKL
        </h2>

        <p class="text-xs text-gray-500 mt-1">
            Riwayat kegiatan dan komentar pembimbing.
        </p>

    </div>

    <div class="p-5 space-y-5">

        <?php if (!$journals): ?>

            <div class="text-center py-8 text-gray-500">
                Belum ada jurnal kegiatan.
            </div>

        <?php else: ?>

            <?php foreach ($journals as $j): ?>

                <div class="border border-gray-100 dark:border-gray-700 rounded-xl p-4">

                    <div class="flex items-start justify-between flex-wrap gap-2">

                        <div>

                            <h3 class="font-bold dark:text-white">
                                <?= e($j['title'] ?? 'Tanpa judul') ?>
                            </h3>

                            <p class="text-xs text-gray-500 mt-1">
                                <?= detailDate($j['activity_date'] ?? null) ?>
                            </p>

                        </div>

                        <?php if (!empty($j['teacher_comment'])): ?>

                            <span class="bg-green-100 text-green-700 text-xs px-3 py-1 rounded-full">
                                Sudah Dikomentari
                            </span>

                        <?php else: ?>

                            <span class="bg-yellow-100 text-yellow-700 text-xs px-3 py-1 rounded-full">
                                Belum Dikomentari
                            </span>

                        <?php endif; ?>

                    </div>

                    <div class="text-sm text-gray-600 dark:text-gray-300 mt-3 whitespace-pre-line">
                        <?= e($j['content'] ?? '-') ?>
                    </div>

                    <!-- FOTO JURNAL -->

                    <?php if (!empty($journalPhotos[(int) $j['id']])): ?>

                        <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mt-4">

                            <?php foreach ($journalPhotos[(int) $j['id']] as $photo): ?>

                                <a href="<?= e(detailImageUrl($photo)) ?>"
                                   target="_blank"
                                   rel="noopener noreferrer">

                                    <img
                                        src="<?= e(detailImageUrl($photo)) ?>"
                                        alt="Dokumentasi jurnal"
                                        loading="lazy"
                                        class="w-full h-32 object-cover rounded-lg border border-gray-200 dark:border-gray-600"
                                    >

                                </a>

                            <?php endforeach; ?>

                        </div>

                    <?php endif; ?>

                    <!-- KOMENTAR PEMBIMBING -->

                    <?php if (!empty($j['teacher_comment'])): ?>

                        <div class="mt-4 bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-100 dark:border-indigo-800 rounded-xl p-4">

                            <div class="font-semibold text-indigo-700 dark:text-indigo-300 text-sm">
                                💬 Komentar Pembimbing
                            </div>

                            <div class="text-sm text-gray-700 dark:text-gray-300 mt-2 whitespace-pre-line">
                                <?= e($j['teacher_comment']) ?>
                            </div>

                            <?php if (!empty($j['reviewer_name'])): ?>
                                <div class="text-xs text-gray-500 mt-2">
                                    Oleh: <?= e($j['reviewer_name']) ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($j['commented_at'])): ?>
                                <div class="text-xs text-gray-400 mt-1">
                                    <?= detailDate($j['commented_at']) ?>
                                </div>
                            <?php endif; ?>

                        </div>

                    <?php endif; ?>

                </div>

            <?php endforeach; ?>

        <?php endif; ?>

    </div>

</div>

<!-- TUGAS SISWA -->

<div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 mb-6 overflow-hidden">

    <div class="p-5 border-b border-gray-100 dark:border-gray-700">

        <h2 class="font-bold text-lg dark:text-white">
            🎯 Progres Tugas PKL
        </h2>

        <p class="text-xs text-gray-500 mt-1">
            Daftar tugas yang diberikan kepada siswa.
        </p>

    </div>

    <div class="overflow-x-auto">

        <table class="w-full text-sm text-left">

            <thead class="bg-gray-50 dark:bg-gray-700/40 text-gray-500 text-xs uppercase">

                <tr>
                    <th class="p-3">Tugas</th>
                    <th class="p-3">Deadline</th>
                    <th class="p-3">Status</th>
                </tr>

            </thead>

            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">

                <?php if (!$tasks): ?>

                    <tr>
                        <td colspan="3" class="p-8 text-center text-gray-500">
                            Belum ada tugas untuk siswa ini.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($tasks as $task): ?>

                        <tr>

                            <td class="p-3">

                                <div class="font-semibold dark:text-white">
                                    <?= e($task['title'] ?? '-') ?>
                                </div>

                                <?php if (!empty($task['description'])): ?>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <?= e($task['description']) ?>
                                    </div>
                                <?php endif; ?>

                            </td>

                            <td class="p-3 text-gray-500">
                                <?= detailDate($task['deadline'] ?? null) ?>
                            </td>

                            <td class="p-3">

                                <span class="px-3 py-1 rounded-full text-xs font-semibold <?= detailStatusClass($task['status'] ?? '') ?>">
                                    <?= e(detailStatusLabel($task['status'] ?? '')) ?>
                                </span>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>

<div class="mb-8">
    <a href="monitoring.php"
       class="inline-block bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 px-5 py-2.5 rounded-lg text-sm font-semibold">
        ← Kembali ke Monitoring
    </a>
</div>

<style>
@media print {
    body {
        background: white !important;
    }

    nav,
    header,
    footer,
    button,
    a[href="monitoring.php"] {
        display: none !important;
    }

    .bg-white,
    .dark\:bg-gray-800 {
        background: white !important;
        color: black !important;
        box-shadow: none !important;
    }

    .overflow-x-auto {
        overflow: visible !important;
    }

    img {
        max-width: 100%;
        break-inside: avoid;
    }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
