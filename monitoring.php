
<?php
// monitoring.php
// Monitoring khusus pembimbing

require_once __DIR__ . '/includes/auth.php';

requireLogin();
requireRole('pembimbing');

$user = currentUser();
$uid  = (int) $user['id'];

$today   = date('Y-m-d');
$weekAgo = date('Y-m-d', strtotime('-6 days'));

$search        = trim($_GET['q'] ?? '');
$filterJurusan = trim($_GET['jurusan'] ?? '');
$filterKelas   = trim($_GET['kelas'] ?? '');
$filterStatus  = trim($_GET['status'] ?? '');

$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// ==================================================
// DETEKSI STRUKTUR TABEL
// ==================================================

function monitoringColumns(PDO $pdo, string $table): array
{
    $columns = [];

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $columns[$column['Field']] = $column;
        }
    } catch (Throwable $e) {
        return [];
    }

    return $columns;
}

$userCols    = monitoringColumns($pdo, 'users');
$attCols     = monitoringColumns($pdo, 'attendance');
$journalCols = monitoringColumns($pdo, 'journals');
$taskCols    = monitoringColumns($pdo, 'tasks');

$hasEmail   = isset($userCols['email']);
$hasPhoto   = isset($userCols['photo']);
$hasJurusan = isset($userCols['jurusan']);
$hasKelas   = isset($userCols['kelas']);
$hasNis     = isset($userCols['nis']);

$checkInCol = isset($attCols['check_in'])
    ? 'check_in'
    : (isset($attCols['check_in_time']) ? 'check_in_time' : null);

$journalDateCol = isset($journalCols['activity_date'])
    ? 'activity_date'
    : (isset($journalCols['date']) ? 'date' : null);

$hasTasks = isset($taskCols['id'])
    && isset($taskCols['status'])
    && isset($taskCols['assigned_to'])
    && isset($taskCols['created_by']);

// ==================================================
// DETEKSI ROLE SISWA
// ==================================================

$roleSiswa = 'student';

try {
    $roles = $pdo->query("SELECT DISTINCT role FROM users")
        ->fetchAll(PDO::FETCH_COLUMN);

    foreach (['student', 'siswa', 'murid', 'peserta'] as $candidate) {
        if (in_array($candidate, $roles, true)) {
            $roleSiswa = $candidate;
            break;
        }
    }
} catch (Throwable $e) {
    // Tetap menggunakan student sebagai default.
}

// ==================================================
// DETEKSI STATUS ABSENSI
// ==================================================

$statusOptions = [
    'hadir' => 'Hadir',
    'izin'  => 'Izin',
    'sakit' => 'Sakit',
    'alpha' => 'Alpha'
];

if (isset($attCols['status'])) {
    $statusType = $attCols['status']['Type'] ?? '';

    if (preg_match("/enum\((.*)\)/i", $statusType, $matches)) {
        preg_match_all("/'([^']+)'/", $matches[1], $values);

        $statusOptions = [];

        foreach ($values[1] as $value) {
            $statusOptions[$value] = ucfirst($value);
        }
    }
}

// ==================================================
// FILTER DATA SISWA
// ==================================================

$where = ["u.role = :roleSiswa"];
$params = [':roleSiswa' => $roleSiswa];

if ($search !== '') {
    $conditions = ["u.name LIKE :search"];

    if ($hasEmail) {
        $conditions[] = "u.email LIKE :search";
    }

    if ($hasNis) {
        $conditions[] = "u.nis LIKE :search";
    }

    $where[] = '(' . implode(' OR ', $conditions) . ')';
    $params[':search'] = "%{$search}%";
}

if ($filterJurusan !== '' && $hasJurusan) {
    $where[] = "u.jurusan = :jurusan";
    $params[':jurusan'] = $filterJurusan;
}

if ($filterKelas !== '' && $hasKelas) {
    $where[] = "u.kelas = :kelas";
    $params[':kelas'] = $filterKelas;
}

if ($filterStatus === 'sudah') {
    $where[] = "a.status IS NOT NULL";
} elseif ($filterStatus === 'belum') {
    $where[] = "a.status IS NULL";
} elseif (isset($statusOptions[$filterStatus])) {
    $where[] = "a.status = :status";
    $params[':status'] = $filterStatus;
}

$whereSql = implode(' AND ', $where);

// ==================================================
// STATISTIK ABSENSI
// ==================================================

$stats = [
    'total'       => 0,
    'sudah_absen' => 0,
    'belum_absen' => 0,
    'hadir'       => 0,
    'izin'        => 0,
    'sakit'       => 0,
    'alpha'       => 0
];

try {
    $sqlStats = "
        SELECT
            COUNT(DISTINCT u.id) AS total,
            COUNT(DISTINCT CASE WHEN a.status IS NOT NULL THEN u.id END) AS sudah_absen,
            COUNT(DISTINCT CASE WHEN a.status IS NULL THEN u.id END) AS belum_absen
    ";

    foreach (array_keys($statusOptions) as $status) {
        $safeStatus = str_replace('-', '_', $status);

        $sqlStats .= ",
            COUNT(DISTINCT CASE
                WHEN a.status = " . $pdo->quote($status) . "
                THEN u.id
            END) AS `$safeStatus`
        ";
    }

    $sqlStats .= "
        FROM users u
        LEFT JOIN attendance a
            ON a.user_id = u.id
            AND a.date = :today
        WHERE u.role = :roleSiswa
    ";

    $stmtStats = $pdo->prepare($sqlStats);
    $stmtStats->execute([
        ':today' => $today,
        ':roleSiswa' => $roleSiswa
    ]);

    $resultStats = $stmtStats->fetch(PDO::FETCH_ASSOC) ?: [];

    foreach ($resultStats as $key => $value) {
        $stats[$key] = (int) $value;
    }
} catch (Throwable $e) {
    // Statistik tetap menggunakan nilai default.
}

// ==================================================
// DAFTAR JURUSAN DAN KELAS
// ==================================================

$jurusanList = [];
$kelasList = [];

try {
    if ($hasJurusan) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT jurusan
            FROM users
            WHERE role = ?
              AND jurusan IS NOT NULL
              AND jurusan <> ''
            ORDER BY jurusan
        ");

        $stmt->execute([$roleSiswa]);
        $jurusanList = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    if ($hasKelas) {
        $stmt = $pdo->prepare("
            SELECT DISTINCT kelas
            FROM users
            WHERE role = ?
              AND kelas IS NOT NULL
              AND kelas <> ''
            ORDER BY kelas
        ");

        $stmt->execute([$roleSiswa]);
        $kelasList = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
} catch (Throwable $e) {
    // Filter tetap bisa digunakan tanpa daftar tambahan.
}

// ==================================================
// SUBQUERY JURNAL
// ==================================================

$journalSelect = "
    0 AS total_jurnal,
    0 AS jurnal_minggu_ini,
    0 AS jurnal_hari_ini,
    NULL AS last_jurnal
";

$journalJoin = '';

if ($journalDateCol) {
    $journalSelect = "
        COALESCE(j.total_jurnal, 0) AS total_jurnal,
        COALESCE(j.jurnal_minggu_ini, 0) AS jurnal_minggu_ini,
        COALESCE(j.jurnal_hari_ini, 0) AS jurnal_hari_ini,
        j.last_jurnal
    ";

    $journalJoin = "
        LEFT JOIN (
            SELECT
                user_id,
                COUNT(*) AS total_jurnal,
                SUM(
                    CASE WHEN `$journalDateCol` >= :weekAgo
                    THEN 1 ELSE 0 END
                ) AS jurnal_minggu_ini,
                SUM(
                    CASE WHEN `$journalDateCol` = :todayJournal
                    THEN 1 ELSE 0 END
                ) AS jurnal_hari_ini,
                MAX(`$journalDateCol`) AS last_jurnal
            FROM journals
            GROUP BY user_id
        ) j ON j.user_id = u.id
    ";
}

// ==================================================
// SUBQUERY TUGAS
// ==================================================

$taskSelect = "
    0 AS total_tugas,
    0 AS tugas_selesai,
    0 AS tugas_berjalan,
    0 AS tugas_belum
";

$taskJoin = '';

if ($hasTasks) {
    $taskSelect = "
        COALESCE(t.total_tugas, 0) AS total_tugas,
        COALESCE(t.tugas_selesai, 0) AS tugas_selesai,
        COALESCE(t.tugas_berjalan, 0) AS tugas_berjalan,
        COALESCE(t.tugas_belum, 0) AS tugas_belum
    ";

    $taskJoin = "
        LEFT JOIN (
            SELECT
                assigned_to,
                COUNT(*) AS total_tugas,
                SUM(CASE WHEN status = 'done' THEN 1 ELSE 0 END) AS tugas_selesai,
                SUM(CASE WHEN status = 'doing' THEN 1 ELSE 0 END) AS tugas_berjalan,
                SUM(CASE WHEN status = 'todo' THEN 1 ELSE 0 END) AS tugas_belum
            FROM tasks
            WHERE assigned_to IS NOT NULL
            GROUP BY assigned_to
        ) t ON t.assigned_to = u.id
    ";
}

// ==================================================
// HITUNG TOTAL SISWA
// ==================================================

$totalRows = 0;
$totalPages = 1;
$siswa = [];

try {
    $countStmt = $pdo->prepare("
        SELECT COUNT(DISTINCT u.id)
        FROM users u
        LEFT JOIN attendance a
            ON a.user_id = u.id
            AND a.date = :today
        WHERE {$whereSql}
    ");

    $countParams = $params;
    $countParams[':today'] = $today;

    $countStmt->execute($countParams);

    $totalRows = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalRows / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    // ==================================================
    // KOLOM SELECT
    // ==================================================

    $select = "u.id, u.name";

    if ($hasEmail) {
        $select .= ", u.email";
    }

    if ($hasPhoto) {
        $select .= ", u.photo";
    }

    if ($hasJurusan) {
        $select .= ", u.jurusan";
    }

    if ($hasKelas) {
        $select .= ", u.kelas";
    }

    if ($hasNis) {
        $select .= ", u.nis";
    }

    $attSelect = "a.status AS absen_hari_ini";

    if ($checkInCol) {
        $attSelect .= ", a.`$checkInCol` AS check_in_time";
    }

    // ==================================================
    // AMBIL DATA SISWA
    // ==================================================

    $sql = "
        SELECT
            {$select},
            {$attSelect},
            {$journalSelect},
            {$taskSelect}
        FROM users u

        LEFT JOIN attendance a
            ON a.user_id = u.id
            AND a.date = :today

        {$journalJoin}
        {$taskJoin}

        WHERE {$whereSql}

        ORDER BY
            (a.status IS NULL) DESC,
            u.name ASC

        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->bindValue(':today', $today);

    if ($journalDateCol) {
        $stmt->bindValue(':weekAgo', $weekAgo);
        $stmt->bindValue(':todayJournal', $today);
    }

    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    $stmt->execute();

    $siswa = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

} catch (Throwable $e) {
    error_log('Monitoring error: ' . $e->getMessage());
    $siswa = [];
}

// ==================================================
// HEADER
// ==================================================

require_once __DIR__ . '/includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold dark:text-white">
        📊 Monitoring Siswa PKL
    </h1>

    <p class="text-gray-500 mt-1">
        Pantau kehadiran, jurnal, dan perkembangan tugas seluruh siswa PKL.
    </p>

    <p class="text-xs text-gray-400 mt-2">
        Tanggal: <?= date('d-m-Y') ?>
    </p>
</div>

<!-- STATISTIK ABSENSI -->

<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 mb-6">

    <?php
    $cards = [
        ['Total Siswa', $stats['total'], 'text-indigo-600', '👥'],
        ['Sudah Absen', $stats['sudah_absen'], 'text-green-600', '✅'],
        ['Belum Absen', $stats['belum_absen'], 'text-red-500', '⏳'],
        ['Hadir', $stats['hadir'], 'text-green-600', '🟢'],
        ['Izin', $stats['izin'], 'text-blue-600', '🔵'],
        ['Sakit', $stats['sakit'], 'text-yellow-600', '🟡'],
        ['Alpha', $stats['alpha'], 'text-red-600', '⚠️']
    ];
    ?>

    <?php foreach ($cards as [$label, $value, $color, $icon]): ?>
        <div class="bg-white dark:bg-gray-800 rounded-xl p-3 border border-gray-100 dark:border-gray-700">
            <div class="text-xs text-gray-500 mb-1">
                <?= $icon ?> <?= e($label) ?>
            </div>

            <div class="text-2xl font-bold <?= $color ?>">
                <?= (int) $value ?>
            </div>
        </div>
    <?php endforeach; ?>

</div>

<!-- FILTER -->

<form method="get"
      class="bg-white dark:bg-gray-800 rounded-xl p-4 mb-6 border border-gray-100 dark:border-gray-700">

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-3">

        <input
            type="text"
            name="q"
            value="<?= e($search) ?>"
            placeholder="🔍 Cari nama / email / NIS"
            class="px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg text-sm outline-none dark:text-white"
        >

        <?php if ($hasJurusan): ?>
            <select name="jurusan"
                    class="px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg text-sm dark:text-white">

                <option value="">Semua Jurusan</option>

                <?php foreach ($jurusanList as $j): ?>
                    <option value="<?= e($j) ?>"
                        <?= $filterJurusan === $j ? 'selected' : '' ?>>
                        <?= e($j) ?>
                    </option>
                <?php endforeach; ?>

            </select>
        <?php endif; ?>

        <?php if ($hasKelas): ?>
            <select name="kelas"
                    class="px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg text-sm dark:text-white">

                <option value="">Semua Kelas</option>

                <?php foreach ($kelasList as $k): ?>
                    <option value="<?= e($k) ?>"
                        <?= $filterKelas === $k ? 'selected' : '' ?>>
                        <?= e($k) ?>
                    </option>
                <?php endforeach; ?>

            </select>
        <?php endif; ?>

        <select name="status"
                class="px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg text-sm dark:text-white">

            <option value="">Semua Status</option>
            <option value="sudah" <?= $filterStatus === 'sudah' ? 'selected' : '' ?>>
                Sudah Absen
            </option>
            <option value="belum" <?= $filterStatus === 'belum' ? 'selected' : '' ?>>
                Belum Absen
            </option>

            <?php foreach ($statusOptions as $value => $label): ?>
                <option value="<?= e($value) ?>"
                    <?= $filterStatus === $value ? 'selected' : '' ?>>
                    <?= e($label) ?>
                </option>
            <?php endforeach; ?>

        </select>

        <div class="flex gap-2">

            <button type="submit"
                    class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold px-4 py-2 rounded-lg text-sm">
                Filter
            </button>

            <a href="monitoring.php"
               class="px-4 py-2 rounded-lg text-sm border border-gray-200 dark:border-gray-600 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700">
                Reset
            </a>

        </div>

    </div>
</form>

<!-- TABEL MONITORING -->

<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">

    <div class="p-4 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center flex-wrap gap-2">

        <div>
            <h2 class="font-bold dark:text-white">
                Daftar Siswa
            </h2>

            <p class="text-xs text-gray-500">
                Total <?= $totalRows ?> siswa ditemukan
            </p>
        </div>

        <span class="text-xs text-gray-500">
            Halaman <?= $page ?> dari <?= $totalPages ?>
        </span>

    </div>

    <div class="overflow-x-auto">

        <table class="w-full text-left">

            <thead class="bg-gray-50 dark:bg-gray-700/30">

                <tr class="text-xs text-gray-500 uppercase">

                    <th class="py-3 px-4">Siswa</th>
                    <th class="py-3 px-4">Jurusan / Kelas</th>
                    <th class="py-3 px-4">Absensi</th>
                    <th class="py-3 px-4">Jurnal</th>
                    <th class="py-3 px-4">Tugas</th>
                    <th class="py-3 px-4">Aktivitas Terakhir</th>
                    <th class="py-3 px-4 text-right">Aksi</th>

                </tr>

            </thead>

            <tbody class="text-sm divide-y divide-gray-100 dark:divide-gray-700">

                <?php if (!$siswa): ?>

                    <tr>
                        <td colspan="7" class="py-12 text-center text-gray-500">

                            <div class="text-4xl mb-2">🔍</div>

                            <div class="font-semibold">
                                Tidak ada siswa yang cocok
                            </div>

                            <div class="text-xs mt-1">
                                Coba ubah filter atau kata kunci pencarian.
                            </div>

                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($siswa as $s): ?>

                        <?php
                        $status = $s['absen_hari_ini'] ?? null;

                        $badge = match ($status) {
                            'hadir' => 'bg-green-100 text-green-700',
                            'izin'  => 'bg-blue-100 text-blue-700',
                            'sakit' => 'bg-yellow-100 text-yellow-700',
                            'alpha' => 'bg-red-100 text-red-700',
                            default => 'bg-gray-100 text-gray-600'
                        };

                        $label = $status
                            ? ($statusOptions[$status] ?? ucfirst($status))
                            : 'Belum Absen';

                        $lastJurnal = !empty($s['last_jurnal'])
                            ? date('d M Y', strtotime($s['last_jurnal']))
                            : 'Belum pernah';

                        $photo = $s['photo'] ?? null;
                        $name = $s['name'] ?? 'Siswa';

                        ?>

                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30">

                            <!-- IDENTITAS -->

                            <td class="py-3 px-4">

                                <div class="flex items-center gap-3">

                                    <img
                                        src="<?= avatarUrl($photo, $name, 36) ?>"
                                        class="w-9 h-9 rounded-full object-cover"
                                        alt="Foto siswa"
                                    >

                                    <div>

                                        <div class="font-medium dark:text-white">
                                            <?= e($name) ?>
                                        </div>

                                        <?php if (!empty($s['email'])): ?>
                                            <div class="text-xs text-gray-500">
                                                <?= e($s['email']) ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($s['nis'])): ?>
                                            <div class="text-xs text-gray-400">
                                                NIS: <?= e($s['nis']) ?>
                                            </div>
                                        <?php endif; ?>

                                    </div>

                                </div>

                            </td>

                            <!-- JURUSAN -->

                            <td class="py-3 px-4 text-gray-600 dark:text-gray-300">

                                <div>
                                    <?= e($s['jurusan'] ?? '-') ?: '-' ?>
                                </div>

                                <?php if (!empty($s['kelas'])): ?>
                                    <div class="text-xs text-gray-400">
                                        Kelas <?= e($s['kelas']) ?>
                                    </div>
                                <?php endif; ?>

                            </td>

                            <!-- ABSENSI -->

                            <td class="py-3 px-4">

                                <span class="px-3 py-1 rounded-full text-xs font-bold <?= $badge ?>">
                                    <?= e($label) ?>
                                </span>

                                <?php if (!empty($s['check_in_time'])): ?>
                                    <div class="text-xs text-gray-400 mt-1">
                                        Masuk:
                                        <?= e(substr($s['check_in_time'], 0, 5)) ?>
                                    </div>
                                <?php endif; ?>

                            </td>

                            <!-- JURNAL -->

                            <td class="py-3 px-4">

                                <div class="font-bold text-blue-600">
                                    <?= (int) ($s['total_jurnal'] ?? 0) ?>
                                    <span class="text-xs text-gray-500 font-normal">
                                        total
                                    </span>
                                </div>

                                <div class="text-xs text-gray-500">
                                    Minggu ini:
                                    <?= (int) ($s['jurnal_minggu_ini'] ?? 0) ?>
                                </div>

                                <?php if ((int) ($s['jurnal_hari_ini'] ?? 0) > 0): ?>
                                    <span class="inline-block mt-1 text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded-full font-bold">
                                        Aktif hari ini
                                    </span>
                                <?php endif; ?>

                            </td>

                            <!-- TUGAS -->

                            <td class="py-3 px-4">

                                <div class="font-bold text-indigo-600">
                                    <?= (int) ($s['total_tugas'] ?? 0) ?>
                                    <span class="text-xs text-gray-500 font-normal">
                                        total
                                    </span>
                                </div>

                                <div class="text-xs text-green-600">
                                    Selesai:
                                    <?= (int) ($s['tugas_selesai'] ?? 0) ?>
                                </div>

                                <div class="text-xs text-yellow-600">
                                    Berjalan:
                                    <?= (int) ($s['tugas_berjalan'] ?? 0) ?>
                                </div>

                                <div class="text-xs text-gray-500">
                                    Belum:
                                    <?= (int) ($s['tugas_belum'] ?? 0) ?>
                                </div>

                            </td>

                            <!-- AKTIVITAS TERAKHIR -->

                            <td class="py-3 px-4 text-xs text-gray-500">
                                <?= e($lastJurnal) ?>
                            </td>

                            <!-- AKSI -->

                            <td class="py-3 px-4 text-right">

                                <?php if (file_exists(__DIR__ . '/detail_siswa.php')): ?>

                                    <a
                                        href="detail_siswa.php?id=<?= (int) $s['id'] ?>"
                                        class="inline-block text-xs bg-indigo-50 text-indigo-600 hover:bg-indigo-100 px-3 py-1.5 rounded-lg font-semibold"
                                    >
                                        Detail →
                                    </a>

                                <?php else: ?>

                                    <span class="text-xs text-gray-400">
                                        Detail belum tersedia
                                    </span>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

    <!-- PAGINATION -->

    <?php if ($totalPages > 1): ?>

        <div class="flex items-center justify-between p-4 border-t border-gray-100 dark:border-gray-700 text-sm flex-wrap gap-2">

            <div class="text-gray-500">
                Menampilkan <?= count($siswa) ?> dari <?= $totalRows ?> siswa
            </div>

            <div class="flex gap-1 flex-wrap">

                <?php
                $qs = $_GET;

                for ($p = 1; $p <= $totalPages; $p++):

                    if (
                        $totalPages > 10 &&
                        $p > 3 &&
                        $p < $totalPages - 2 &&
                        abs($p - $page) > 1
                    ) {
                        if ($p === 4) {
                            echo '<span class="px-2 py-1.5 text-gray-400">...</span>';
                        }

                        continue;
                    }

                    $qs['page'] = $p;

                    $url = 'monitoring.php?' . http_build_query($qs);

                    $active = $p === $page;
                ?>

                    <a
                        href="<?= e($url) ?>"
                        class="px-3 py-1.5 rounded-lg <?= $active
                            ? 'bg-indigo-600 text-white'
                            : 'bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200 hover:bg-gray-200' ?>"
                    >
                        <?= $p ?>
                    </a>

                <?php endfor; ?>

            </div>

        </div>

    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
