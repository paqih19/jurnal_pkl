
<?php
// ======================================================
// LAPORAN.PHP - JURNAL KERJA PKL
// ======================================================

require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = currentUser();

$allowedRoles = ['admin', 'pembimbing', 'teacher'];

if (!in_array($user['role'], $allowedRoles, true)) {
    http_response_code(403);
    exit('Akses ditolak. Halaman ini hanya untuk admin dan pembimbing.');
}

// ======================================================
// HELPER
// ======================================================

function laporanHasColumn(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$column]);
        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return false;
    }
}

function laporanHasTable(PDO $pdo, string $table): bool
{
    try {
        $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function laporanEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// ======================================================
// FILTER TANGGAL
// ======================================================

$today = date('Y-m-d');
$firstDay = date('Y-m-01');

$startDate = $_GET['mulai'] ?? $firstDay;
$endDate = $_GET['sampai'] ?? $today;

$startObject = DateTime::createFromFormat('!Y-m-d', $startDate);
$endObject = DateTime::createFromFormat('!Y-m-d', $endDate);

if (!$startObject || $startObject->format('Y-m-d') !== $startDate) {
    $startDate = $firstDay;
}

if (!$endObject || $endObject->format('Y-m-d') !== $endDate) {
    $endDate = $today;
}

if ($startDate > $endDate) {
    [$startDate, $endDate] = [$endDate, $startDate];
}

// ======================================================
// DETEKSI DATABASE
// ======================================================

$hasJurusan = laporanHasColumn($pdo, 'users', 'jurusan');
$hasKelas = laporanHasColumn($pdo, 'users', 'kelas');

$hasAttendance = laporanHasTable($pdo, 'attendance');
$hasJournals = laporanHasTable($pdo, 'journals');
$hasTasks = laporanHasTable($pdo, 'tasks');

$attendanceDateColumn = (
    $hasAttendance && laporanHasColumn($pdo, 'attendance', 'date')
) ? 'date' : null;

$journalDateColumn = null;

if ($hasJournals) {
    if (laporanHasColumn($pdo, 'journals', 'activity_date')) {
        $journalDateColumn = 'activity_date';
    } elseif (laporanHasColumn($pdo, 'journals', 'date')) {
        $journalDateColumn = 'date';
    }
}

$hasTaskStatus = $hasTasks && laporanHasColumn($pdo, 'tasks', 'status');
$hasAssignedTo = $hasTasks && laporanHasColumn($pdo, 'tasks', 'assigned_to');
$hasCreatedBy = $hasTasks && laporanHasColumn($pdo, 'tasks', 'created_by');

// ======================================================
// DATA SISWA
// ======================================================

$sqlUsers = "
    SELECT
        id,
        name,
        username,
        " . ($hasJurusan ? "jurusan" : "NULL AS jurusan") . ",
        " . ($hasKelas ? "kelas" : "NULL AS kelas") . "
    FROM users
    WHERE LOWER(role) NOT IN ('admin', 'pembimbing', 'teacher')
    ORDER BY name ASC
";

$students = $pdo->query($sqlUsers)->fetchAll(PDO::FETCH_ASSOC);

// ======================================================
// DATA ABSENSI
// ======================================================

$attendanceData = [];

if ($attendanceDateColumn) {
    $sql = "
        SELECT
            user_id,
            COUNT(*) AS total_absensi,
            SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) AS hadir,
            SUM(CASE WHEN status = 'izin' THEN 1 ELSE 0 END) AS izin,
            SUM(CASE WHEN status = 'sakit' THEN 1 ELSE 0 END) AS sakit,
            SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) AS alpha
        FROM attendance
        WHERE `$attendanceDateColumn` BETWEEN ? AND ?
        GROUP BY user_id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$startDate, $endDate]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $attendanceData[(int) $row['user_id']] = $row;
    }
}

// ======================================================
// DATA JURNAL
// ======================================================

$journalData = [];

if ($journalDateColumn) {
    $sql = "
        SELECT user_id, COUNT(*) AS total_jurnal
        FROM journals
        WHERE `$journalDateColumn` BETWEEN ? AND ?
        GROUP BY user_id
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$startDate, $endDate]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $journalData[(int) $row['user_id']] = (int) $row['total_jurnal'];
    }
}

// ======================================================
// DATA TUGAS
// ======================================================

$taskData = [];

if ($hasTasks && $hasTaskStatus && ($hasAssignedTo || $hasCreatedBy)) {
    $conditions = [];

    if ($hasAssignedTo) {
        $conditions[] = "t.assigned_to = u.id";
    }

    if ($hasCreatedBy && $hasAssignedTo) {
        $conditions[] = "(t.created_by = u.id AND t.assigned_to IS NULL)";
    } elseif ($hasCreatedBy) {
        $conditions[] = "t.created_by = u.id";
    }

    $joinCondition = implode(' OR ', $conditions);

    $sql = "
        SELECT
            u.id AS user_id,
            COUNT(t.id) AS total_tugas,
            COALESCE(SUM(CASE WHEN t.status = 'done' THEN 1 ELSE 0 END), 0) AS selesai,
            COALESCE(SUM(CASE WHEN t.status IN ('todo', 'doing') THEN 1 ELSE 0 END), 0) AS belum_selesai
        FROM users u
        LEFT JOIN tasks t ON ($joinCondition)
        WHERE LOWER(u.role) NOT IN ('admin', 'pembimbing', 'teacher')
        GROUP BY u.id
    ";

    $stmt = $pdo->query($sql);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $taskData[(int) $row['user_id']] = $row;
    }
}

// ======================================================
// TOTAL STATISTIK
// ======================================================

$totalHadir = 0;
$totalIzin = 0;
$totalSakit = 0;
$totalAlpha = 0;
$totalJurnal = 0;
$totalTugas = 0;
$totalSelesai = 0;
$totalBelumSelesai = 0;

foreach ($students as $student) {
    $id = (int) $student['id'];

    $attendance = $attendanceData[$id] ?? [];
    $tasks = $taskData[$id] ?? [];

    $totalHadir += (int) ($attendance['hadir'] ?? 0);
    $totalIzin += (int) ($attendance['izin'] ?? 0);
    $totalSakit += (int) ($attendance['sakit'] ?? 0);
    $totalAlpha += (int) ($attendance['alpha'] ?? 0);

    $totalJurnal += (int) ($journalData[$id] ?? 0);
    $totalTugas += (int) ($tasks['total_tugas'] ?? 0);
    $totalSelesai += (int) ($tasks['selesai'] ?? 0);
    $totalBelumSelesai += (int) ($tasks['belum_selesai'] ?? 0);
}

$totalAbsensi = $totalHadir + $totalIzin + $totalSakit + $totalAlpha;

$persenHadir = $totalAbsensi > 0
    ? round(($totalHadir / $totalAbsensi) * 100)
    : 0;

// ======================================================
// HEADER
// ======================================================

require_once __DIR__ . '/includes/header.php';
?>

<div class="space-y-6">

    <!-- HEADER LAPORAN -->
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-r from-indigo-600 to-indigo-700 p-6 md:p-8 text-white shadow-lg">

        <div class="absolute -right-10 -top-10 w-48 h-48 bg-white/10 rounded-full"></div>
        <div class="absolute right-20 -bottom-20 w-56 h-56 bg-white/10 rounded-full"></div>

        <div class="relative flex flex-col md:flex-row md:items-center md:justify-between gap-5">

            <div>
                <span class="inline-flex items-center gap-2 bg-white/15 px-3 py-1.5 rounded-full text-xs font-semibold mb-3">
                    📊 PUSAT LAPORAN PKL
                </span>

                <h1 class="text-2xl md:text-3xl font-extrabold">
                    Laporan Kegiatan PKL
                </h1>

                <p class="text-indigo-100 text-sm mt-2">
                    Rekap absensi, jurnal kegiatan, dan progres tugas siswa.
                </p>
            </div>

            <button onclick="window.print()"
                class="bg-white text-indigo-700 hover:bg-indigo-50 px-5 py-3 rounded-xl font-bold text-sm shadow-md transition">
                🖨️ Cetak / Simpan PDF
            </button>

        </div>
    </div>

    <!-- FILTER TANGGAL -->
    <div class="bg-white dark:bg-slate-800 rounded-2xl border border-gray-100 dark:border-slate-700 shadow-sm p-5">

        <div class="flex items-center gap-3 mb-4">
            <div class="w-10 h-10 rounded-xl bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center text-xl">
                📅
            </div>

            <div>
                <h2 class="font-bold text-gray-800 dark:text-white">
                    Filter Periode Laporan
                </h2>
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Pilih rentang tanggal laporan
                </p>
            </div>
        </div>

        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-4">

            <div>
                <label class="block text-sm font-semibold text-gray-600 dark:text-gray-300 mb-2">
                    Tanggal Mulai
                </label>

                <input type="date" name="mulai"
                    value="<?= laporanEscape($startDate) ?>"
                    class="w-full rounded-xl border border-gray-200 dark:border-slate-600 bg-gray-50 dark:bg-slate-700 text-gray-800 dark:text-white px-4 py-3 focus:ring-2 focus:ring-indigo-500 outline-none">
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-600 dark:text-gray-300 mb-2">
                    Tanggal Sampai
                </label>

                <input type="date" name="sampai"
                    value="<?= laporanEscape($endDate) ?>"
                    class="w-full rounded-xl border border-gray-200 dark:border-slate-600 bg-gray-50 dark:bg-slate-700 text-gray-800 dark:text-white px-4 py-3 focus:ring-2 focus:ring-indigo-500 outline-none">
            </div>

            <div class="flex items-end gap-2">
                <button type="submit"
                    class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-3 rounded-xl font-bold transition">
                    🔍 Terapkan
                </button>

                <a href="laporan.php"
                    class="bg-gray-100 dark:bg-slate-700 hover:bg-gray-200 dark:hover:bg-slate-600 text-gray-700 dark:text-gray-200 px-4 py-3 rounded-xl font-semibold transition">
                    Reset
                </a>
            </div>

        </form>
    </div>

    <!-- KARTU STATISTIK -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">

        <div class="bg-white dark:bg-slate-800 border border-gray-100 dark:border-slate-700 rounded-2xl p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <div class="w-11 h-11 rounded-xl bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center text-xl">👥</div>
                <span class="text-xs font-semibold text-indigo-600 dark:text-indigo-400">SISWA</span>
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-4">Total Siswa</p>
            <h2 class="text-3xl font-extrabold text-gray-800 dark:text-white mt-1">
                <?= count($students) ?>
            </h2>
        </div>

        <div class="bg-white dark:bg-slate-800 border border-gray-100 dark:border-slate-700 rounded-2xl p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <div class="w-11 h-11 rounded-xl bg-green-100 dark:bg-green-900/40 flex items-center justify-center text-xl">✅</div>
                <span class="text-xs font-semibold text-green-600 dark:text-green-400">ABSENSI</span>
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-4">Total Kehadiran</p>
            <h2 class="text-3xl font-extrabold text-green-600 dark:text-green-400 mt-1">
                <?= $totalHadir ?>
            </h2>
            <p class="text-xs text-gray-400 mt-1"><?= $persenHadir ?>% dari seluruh catatan absensi</p>
        </div>

        <div class="bg-white dark:bg-slate-800 border border-gray-100 dark:border-slate-700 rounded-2xl p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <div class="w-11 h-11 rounded-xl bg-blue-100 dark:bg-blue-900/40 flex items-center justify-center text-xl">📝</div>
                <span class="text-xs font-semibold text-blue-600 dark:text-blue-400">JURNAL</span>
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-4">Total Jurnal</p>
            <h2 class="text-3xl font-extrabold text-blue-600 dark:text-blue-400 mt-1">
                <?= $totalJurnal ?>
            </h2>
        </div>

        <div class="bg-white dark:bg-slate-800 border border-gray-100 dark:border-slate-700 rounded-2xl p-5 shadow-sm">
            <div class="flex items-center justify-between">
                <div class="w-11 h-11 rounded-xl bg-purple-100 dark:bg-purple-900/40 flex items-center justify-center text-xl">📋</div>
                <span class="text-xs font-semibold text-purple-600 dark:text-purple-400">TUGAS</span>
            </div>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-4">Tugas Selesai</p>
            <h2 class="text-3xl font-extrabold text-purple-600 dark:text-purple-400 mt-1">
                <?= $totalSelesai ?>
                <span class="text-sm text-gray-400 font-medium">/ <?= $totalTugas ?></span>
            </h2>
        </div>

    </div>

    <!-- RINGKASAN ABSENSI -->
    <div class="bg-white dark:bg-slate-800 rounded-2xl border border-gray-100 dark:border-slate-700 shadow-sm p-5">

        <h2 class="font-bold text-gray-800 dark:text-white mb-4">
            📊 Ringkasan Absensi
        </h2>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">

            <div class="bg-green-50 dark:bg-green-900/20 rounded-xl p-4">
                <p class="text-xs text-green-700 dark:text-green-400 font-semibold">Hadir</p>
                <p class="text-2xl font-extrabold text-green-700 dark:text-green-400 mt-1"><?= $totalHadir ?></p>
            </div>

            <div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-xl p-4">
                <p class="text-xs text-yellow-700 dark:text-yellow-400 font-semibold">Izin</p>
                <p class="text-2xl font-extrabold text-yellow-700 dark:text-yellow-400 mt-1"><?= $totalIzin ?></p>
            </div>

            <div class="bg-blue-50 dark:bg-blue-900/20 rounded-xl p-4">
                <p class="text-xs text-blue-700 dark:text-blue-400 font-semibold">Sakit</p>
                <p class="text-2xl font-extrabold text-blue-700 dark:text-blue-400 mt-1"><?= $totalSakit ?></p>
            </div>

            <div class="bg-red-50 dark:bg-red-900/20 rounded-xl p-4">
                <p class="text-xs text-red-700 dark:text-red-400 font-semibold">Alpha</p>
                <p class="text-2xl font-extrabold text-red-700 dark:text-red-400 mt-1"><?= $totalAlpha ?></p>
            </div>

        </div>
    </div>

    <!-- TABEL LAPORAN -->
    <div class="bg-white dark:bg-slate-800 rounded-2xl border border-gray-100 dark:border-slate-700 shadow-sm overflow-hidden">

        <div class="p-5 md:p-6 border-b border-gray-100 dark:border-slate-700 flex flex-col md:flex-row md:items-center md:justify-between gap-3">

            <div>
                <h2 class="font-extrabold text-gray-800 dark:text-white text-lg">
                    📑 Rekapitulasi Siswa
                </h2>

                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Periode <?= laporanEscape($startDate) ?> sampai <?= laporanEscape($endDate) ?>
                </p>
            </div>

            <span class="bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 px-3 py-2 rounded-lg text-xs font-bold">
                <?= count($students) ?> Siswa Terdaftar
            </span>

        </div>

        <div class="overflow-x-auto">

            <table class="w-full text-sm text-left">

                <thead class="bg-indigo-50 dark:bg-slate-700/70 text-indigo-900 dark:text-indigo-200">
                    <tr>
                        <th class="px-5 py-4 font-bold">No</th>
                        <th class="px-5 py-4 font-bold">Nama Siswa</th>
                        <th class="px-5 py-4 font-bold">Jurusan</th>
                        <th class="px-5 py-4 text-center font-bold">Hadir</th>
                        <th class="px-5 py-4 text-center font-bold">Izin</th>
                        <th class="px-5 py-4 text-center font-bold">Sakit</th>
                        <th class="px-5 py-4 text-center font-bold">Alpha</th>
                        <th class="px-5 py-4 text-center font-bold">Jurnal</th>
                        <th class="px-5 py-4 text-center font-bold">Tugas</th>
                        <th class="px-5 py-4 text-center font-bold">Selesai</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-gray-100 dark:divide-slate-700">

                    <?php if (empty($students)): ?>

                        <tr>
                            <td colspan="10" class="px-5 py-12 text-center">
                                <div class="text-4xl mb-3">📭</div>
                                <p class="font-semibold text-gray-600 dark:text-gray-300">
                                    Belum ada data siswa
                                </p>
                                <p class="text-xs text-gray-400 mt-1">
                                    Data akan muncul setelah siswa terdaftar.
                                </p>
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($students as $index => $student): ?>

                            <?php
                            $id = (int) $student['id'];
                            $attendance = $attendanceData[$id] ?? [];
                            $tasks = $taskData[$id] ?? [];
                            ?>

                            <tr class="hover:bg-indigo-50/50 dark:hover:bg-slate-700/40 transition">

                                <td class="px-5 py-4 text-gray-500 dark:text-gray-400">
                                    <?= $index + 1 ?>
                                </td>

                                <td class="px-5 py-4">
                                    <div class="font-bold text-gray-800 dark:text-white">
                                        <?= laporanEscape($student['name']) ?>
                                    </div>
                                    <div class="text-xs text-gray-400 mt-1">
                                        @<?= laporanEscape($student['username']) ?>
                                    </div>
                                </td>

                                <td class="px-5 py-4 text-gray-600 dark:text-gray-300">
                                    <?= laporanEscape($student['jurusan'] ?? '-') ?>
                                </td>

                                <td class="px-5 py-4 text-center">
                                    <span class="inline-flex min-w-8 justify-center bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-400 px-2.5 py-1 rounded-lg font-bold">
                                        <?= (int) ($attendance['hadir'] ?? 0) ?>
                                    </span>
                                </td>

                                <td class="px-5 py-4 text-center text-yellow-600 dark:text-yellow-400 font-semibold">
                                    <?= (int) ($attendance['izin'] ?? 0) ?>
                                </td>

                                <td class="px-5 py-4 text-center text-blue-600 dark:text-blue-400 font-semibold">
                                    <?= (int) ($attendance['sakit'] ?? 0) ?>
                                </td>

                                <td class="px-5 py-4 text-center text-red-600 dark:text-red-400 font-semibold">
                                    <?= (int) ($attendance['alpha'] ?? 0) ?>
                                </td>

                                <td class="px-5 py-4 text-center font-semibold text-indigo-600 dark:text-indigo-400">
                                    <?= (int) ($journalData[$id] ?? 0) ?>
                                </td>

                                <td class="px-5 py-4 text-center font-semibold text-gray-700 dark:text-gray-300">
                                    <?= (int) ($tasks['total_tugas'] ?? 0) ?>
                                </td>

                                <td class="px-5 py-4 text-center">
                                    <span class="inline-flex min-w-8 justify-center bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-400 px-2.5 py-1 rounded-lg font-bold">
                                        <?= (int) ($tasks['selesai'] ?? 0) ?>
                                    </span>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>
            </table>

        </div>
    </div>

    <!-- KETERANGAN -->
    <div class="bg-indigo-50 dark:bg-slate-800 border border-indigo-100 dark:border-slate-700 rounded-2xl p-5">

        <h3 class="font-bold text-indigo-800 dark:text-indigo-300 mb-3">
            💡 Keterangan Laporan
        </h3>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm text-gray-600 dark:text-gray-300">

            <div class="flex items-start gap-2">
                <span>📅</span>
                <p>Absensi dan jurnal mengikuti periode yang dipilih.</p>
            </div>

            <div class="flex items-start gap-2">
                <span>📋</span>
                <p>Progres tugas menampilkan kondisi tugas saat ini.</p>
            </div>

            <div class="flex items-start gap-2">
                <span>🖨️</span>
                <p>Laporan dapat dicetak atau disimpan sebagai PDF.</p>
            </div>

        </div>
    </div>

</div>

<!-- PRINT STYLE -->
<style>
@media print {
    @page {
        size: landscape;
        margin: 12mm;
    }

    body {
        background: white !important;
        color: #111827 !important;
    }

    nav,
    button,
    form,
    footer,
    #toast-container {
        display: none !important;
    }

    main {
        max-width: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .shadow-sm,
    .shadow-lg {
        box-shadow: none !important;
    }

    table {
        width: 100% !important;
        border-collapse: collapse !important;
        font-size: 10px !important;
    }

    th,
    td {
        border: 1px solid #d1d5db !important;
        padding: 7px !important;
        color: #111827 !important;
    }

    thead {
        background: #eef2ff !important;
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }

    .bg-indigo-600,
    .bg-indigo-50,
    .bg-green-50,
    .bg-yellow-50,
    .bg-blue-50,
    .bg-red-50,
    .bg-purple-50 {
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }

    tr {
        page-break-inside: avoid;
    }
}
</style>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
