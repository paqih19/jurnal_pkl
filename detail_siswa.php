<?php
// detail_siswa.php
require_once __DIR__ . '/includes/auth.php';

requireLogin();

// FIX: izinkan admin juga, bukan hanya pembimbing
if (!isPembimbing() && !isAdmin()) {
    setFlash('error', 'Kamu tidak memiliki akses ke halaman ini.');
    redirect('dashboard.php');
}

$user    = currentUser();
$idSiswa = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$idSiswa || $idSiswa <= 0) {
    setFlash('error', 'ID siswa tidak valid.');
    redirect('monitoring.php');
}

// ==================================================
// AMBIL DATA SISWA
// ==================================================
$stmt = $pdo->prepare("
    SELECT * FROM users
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
// HELPER
// ==================================================
function detailImageUrl(?string $filename): string {
    if (!$filename) return '';
    $filename = trim($filename);

    if (preg_match('/^https?:\/\//i', $filename)) return $filename;
    if (str_starts_with($filename, '/'))          return $filename;

    $base = defined('UPLOAD_URL') ? rtrim(UPLOAD_URL, '/') : 'uploads';
    return $base . '/' . ltrim($filename, '/');
}

function detailDate($date): string {
    if (!$date) return '-';
    $t = strtotime($date);
    return $t ? date('d M Y', $t) : '-';
}

function detailStatusClass($status): string {
    return match ($status) {
        'hadir', 'done' => 'bg-green-100 text-green-700',
        'izin',  'doing'=> 'bg-blue-100 text-blue-700',
        'sakit'         => 'bg-yellow-100 text-yellow-700',
        'alpha'         => 'bg-red-100 text-red-700',
        'todo'          => 'bg-gray-100 text-gray-700',
        default         => 'bg-gray-100 text-gray-600'
    };
}

function detailStatusLabel($status): string {
    return match ($status) {
        'hadir' => 'Hadir',
        'izin'  => 'Izin',
        'sakit' => 'Sakit',
        'alpha' => 'Alpha',
        'done'  => 'Selesai',
        'doing' => 'Sedang Dikerjakan',
        'todo'  => 'Belum Dikerjakan',
        default => ucfirst((string) $status)
    };
}

// ==================================================
// STATISTIK
// ==================================================
$stat = [
    'total_absen'    => 0,
    'hadir'          => 0,
    'izin'           => 0,
    'sakit'          => 0,
    'alpha'          => 0,
    'total_jurnal'   => 0,
    'total_tugas'    => 0,
    'tugas_selesai'  => 0,
];

try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*)             AS total_absen,
            SUM(status = 'hadir') AS hadir,
            SUM(status = 'izin')  AS izin,
            SUM(status = 'sakit') AS sakit,
            SUM(status = 'alpha') AS alpha
        FROM attendance WHERE user_id = ?
    ");
    $stmt->execute([$idSiswa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($row as $k => $v) $stat[$k] = (int) $v;
} catch (Throwable $e) { error_log('Detail attendance stats: ' . $e->getMessage()); }

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM journals WHERE user_id = ?");
    $stmt->execute([$idSiswa]);
    $stat['total_jurnal'] = (int) $stmt->fetchColumn();
} catch (Throwable $e) { error_log('Detail journal stats: ' . $e->getMessage()); }

try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total_tugas, SUM(status = 'done') AS tugas_selesai
        FROM tasks
        WHERE assigned_to = ? OR (created_by = ? AND assigned_to IS NULL)
    ");
    $stmt->execute([$idSiswa, $idSiswa]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $stat['total_tugas']   = (int) ($row['total_tugas']   ?? 0);
    $stat['tugas_selesai'] = (int) ($row['tugas_selesai'] ?? 0);
} catch (Throwable $e) { error_log('Detail task stats: ' . $e->getMessage()); }

// ==================================================
// RIWAYAT ABSENSI
// ==================================================
$attendance = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM attendance
        WHERE user_id = ?
        ORDER BY date DESC, id DESC
        LIMIT 30
    ");
    $stmt->execute([$idSiswa]);
    $attendance = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('Detail attendance: ' . $e->getMessage()); }

// ==================================================
// RIWAYAT JURNAL
// ==================================================
$journals = [];
try {
    $stmt = $pdo->prepare("
        SELECT j.*, reviewer.name AS reviewer_name
        FROM journals j
        LEFT JOIN users reviewer ON reviewer.id = j.commented_by
        WHERE j.user_id = ?
        ORDER BY j.activity_date DESC, j.id DESC
        LIMIT 30
    ");
    $stmt->execute([$idSiswa]);
    $journals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Detail journals: ' . $e->getMessage());

    // Fallback jika kolom komentar belum ada
    try {
        $stmt = $pdo->prepare("
            SELECT * FROM journals
            WHERE user_id = ?
            ORDER BY activity_date DESC, id DESC
            LIMIT 30
        ");
        $stmt->execute([$idSiswa]);
        $journals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e2) { error_log('Detail journals fallback: ' . $e2->getMessage()); }
}

// ==================================================
// FOTO DOKUMENTASI JURNAL
// ==================================================
$journalPhotos = [];
try {
    if ($journals) {
        $journalIds   = array_map('intval', array_column($journals, 'id'));
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
} catch (Throwable $e) { error_log('Detail journal photos: ' . $e->getMessage()); }

// ==================================================
// TUGAS SISWA
// ==================================================
$tasks = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM tasks
        WHERE assigned_to = ? OR (created_by = ? AND assigned_to IS NULL)
        ORDER BY
            CASE status
                WHEN 'doing' THEN 1
                WHEN 'todo'  THEN 2
                WHEN 'done'  THEN 3
                ELSE 4
            END,
            deadline ASC
        LIMIT 50
    ");
    $stmt->execute([$idSiswa, $idSiswa]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { error_log('Detail tasks: ' . $e->getMessage()); }

require_once __DIR__ . '/includes/header.php';

$namaSiswa = $siswa['name'] ?? 'Siswa';
$fotoSiswa = $siswa['photo'] ?? null;
?>

<div class="mb-6 flex items-center justify-between flex-wrap gap-3">
    <div>
        <a href="monitoring.php" class="text-sm text-indigo-600 hover:underline">← Kembali ke Monitoring</a>
        <h1 class="text-2xl font-bold dark:text-white mt-2">Detail Siswa PKL</h1>
        <p class="text-gray-500 text-sm mt-1">Informasi lengkap perkembangan siswa.</p>
    </div>
    <button onclick="window.print()"
            class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-semibold">
        🖨️ Cetak Laporan
    </button>
</div>

<!-- PROFIL SISWA -->
<div class="bg-white dark:bg-gray-800 rounded-2xl p-5 border border-gray-100 dark:border-gray-700 mb-6">
    <div class="flex items-center gap-4 flex-wrap">
        <img src="<?= avatarUrl($fotoSiswa, $namaSiswa, 90) ?>"
             class="w-20 h-20 rounded-full object-cover border-4 border-indigo-100"
             alt="Foto siswa">

        <div class="flex-1">
            <h2 class="text-xl font-bold dark:text-white"><?= e($namaSiswa) ?></h2>
            <p class="text-sm text-gray-500"><?= e($siswa['email'] ?? '-') ?></p>

            <div class="flex flex-wrap gap-2 mt-2">
                <?php if (!empty($siswa['jurusan'])): ?>
                    <span class="bg-indigo-50 text-indigo-700 text-xs px-3 py-1 rounded-full"><?= e($siswa['jurusan']) ?></span>
                <?php endif; ?>
                <?php if (!empty($siswa['kelas'])): ?>
                    <span class="bg-blue-50 text-blue-700 text-xs px-3 py-1 rounded-full">Kelas <?= e($siswa['kelas']) ?></span>
                <?php endif; ?>
                <?php if (!empty($siswa['nis'])): ?>
                    <span class="bg-gray-100 text-gray-700 text-xs px-3 py-1 rounded-full">NIS: <?= e($siswa['nis']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- STATISTIK -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="text-xs text-gray-500 mb-1">📅 Total Absen</div>
        <div class="text-2xl font-bold text-indigo-600"><?= $stat['total_absen'] ?></div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="text-xs text-gray-500 mb-1">✅ Hadir</div>
        <div class="text-2xl font-bold text-green-600"><?= $stat['hadir'] ?></div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="text-xs text-gray-500 mb-1">📝 Jurnal</div>
        <div class="text-2xl font-bold text-blue-600"><?= $stat['total_jurnal'] ?></div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="text-xs text-gray-500 mb-1">📋 Tugas</div>
        <div class="text-2xl font-bold text-yellow-600">
            <?= $stat['tugas_selesai'] ?>/<?= $stat['total_tugas'] ?>
        </div>
    </div>
</div>

<!-- RIWAYAT ABSENSI -->
<div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 mb-6 overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
        <h3 class="font-bold dark:text-white">Riwayat Absensi</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-700/60 text-slate-600 dark:text-slate-300">
                <tr>
                    <th class="px-5 py-3 text-left">Tanggal</th>
                    <th class="px-5 py-3 text-left">Status</th>
                    <th class="px-5 py-3 text-left">Jam</th>
                    <th class="px-5 py-3 text-left">Catatan</th>
                    <th class="px-5 py-3 text-left">Bukti</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                <?php if (!$attendance): ?>
                    <tr><td colspan="5" class="text-center py-8 text-gray-400">Belum ada data absensi.</td></tr>
                <?php endif; ?>
                <?php foreach ($attendance as $a): ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                        <td class="px-5 py-3"><?= e(detailDate($a['date'])) ?></td>
                        <td class="px-5 py-3">
                            <span class="px-2 py-1 rounded-full text-xs font-bold <?= detailStatusClass($a['status']) ?>">
                                <?= detailStatusLabel($a['status']) ?>
                            </span>
                        </td>
                        <td class="px-5 py-3"><?= e($a['check_in'] ? substr($a['check_in'], 0, 5) : '-') ?></td>
                        <td class="px-5 py-3 text-gray-500 max-w-xs truncate"><?= e($a['note'] ?: '-') ?></td>
                        <td class="px-5 py-3">
                            <?php if (!empty($a['photo'])): ?>
                                <a href="<?= e(detailImageUrl($a['photo'])) ?>" target="_blank" rel="noopener">
                                    <img src="<?= e(detailImageUrl($a['photo'])) ?>"
                                         class="w-10 h-10 rounded object-cover border" alt="Bukti">
                                </a>
                            <?php else: ?>
                                <span class="text-xs text-gray-400">-</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- RIWAYAT JURNAL -->
<div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 mb-6 overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
        <h3 class="font-bold dark:text-white">Riwayat Jurnal</h3>
    </div>
    <div class="p-5 space-y-4">
        <?php if (!$journals): ?>
            <p class="text-center text-gray-400 py-6">Belum ada jurnal.</p>
        <?php endif; ?>
        <?php foreach ($journals as $j): ?>
            <div class="border border-gray-100 dark:border-gray-700 rounded-xl p-4">
                <div class="flex justify-between items-start gap-3 mb-2">
                    <div>
                        <h4 class="font-semibold dark:text-white"><?= e($j['title']) ?></h4>
                        <p class="text-xs text-gray-400">
                            <?= e(detailDate($j['activity_date'] ?? $j['created_at'])) ?>
                        </p>
                    </div>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-300 whitespace-pre-line">
                    <?= e($j['content'] ?? '') ?>
                </p>

                <?php if (!empty($journalPhotos[$j['id']])): ?>
                    <div class="flex flex-wrap gap-2 mt-3">
                        <?php foreach ($journalPhotos[$j['id']] as $foto): ?>
                            <a href="<?= e(detailImageUrl($foto)) ?>" target="_blank" rel="noopener">
                                <img src="<?= e(detailImageUrl($foto)) ?>"
                                     class="w-16 h-16 object-cover rounded-lg border" alt="Foto jurnal">
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($j['comment'])): ?>
                    <div class="mt-3 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                        <p class="text-xs font-semibold text-blue-700 dark:text-blue-300 mb-1">
                            💬 Komentar<?= !empty($j['reviewer_name']) ? ' — ' . e($j['reviewer_name']) : '' ?>
                        </p>
                        <p class="text-sm text-gray-700 dark:text-gray-300"><?= e($j['comment']) ?></p>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- TUGAS -->
<div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 overflow-hidden">
    <div class="px-5 py-4 border-b border-gray-100 dark:border-gray-700">
        <h3 class="font-bold dark:text-white">Daftar Tugas</h3>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 dark:bg-slate-700/60 text-slate-600 dark:text-slate-300">
                <tr>
                    <th class="px-5 py-3 text-left">Judul</th>
                    <th class="px-5 py-3 text-left">Status</th>
                    <th class="px-5 py-3 text-left">Deadline</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
                <?php if (!$tasks): ?>
                    <tr><td colspan="3" class="text-center py-8 text-gray-400">Belum ada tugas.</td></tr>
                <?php endif; ?>
                <?php foreach ($tasks as $t): ?>
                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/40">
                        <td class="px-5 py-3"><?= e($t['title']) ?></td>
                        <td class="px-5 py-3">
                            <span class="px-2 py-1 rounded-full text-xs font-bold <?= detailStatusClass($t['status']) ?>">
                                <?= detailStatusLabel($t['status']) ?>
                            </span>
                        </td>
                        <td class="px-5 py-3 text-gray-500"><?= e(detailDate($t['deadline'] ?? null)) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>