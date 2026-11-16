<?php
// activity_log.php

require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/config/db.php';

$user = currentUser();
$role = $user['role'] ?? '';

$isAdmin = ($role === 'admin');
$isPembimbing = in_array($role, ['pembimbing', 'teacher'], true);

// Hanya admin dan pembimbing yang dapat melihat log aktivitas.
if (!$isAdmin && !$isPembimbing) {
    setFlash('error', 'Kamu tidak memiliki akses ke halaman Log Aktivitas.');
    redirect('dashboard.php');
}

$pageTitle = 'Log Aktivitas';

$search = trim($_GET['search'] ?? '');
$filterRole = trim($_GET['role'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        username LIKE ?
        OR aksi LIKE ?
        OR detail LIKE ?
        OR ip LIKE ?
    )";

    $keyword = '%' . $search . '%';

    array_push(
        $params,
        $keyword,
        $keyword,
        $keyword,
        $keyword
    );
}

if ($filterRole !== '') {
    $where[] = "role = ?";
    $params[] = $filterRole;
}

$whereSql = $where
    ? 'WHERE ' . implode(' AND ', $where)
    : '';

try {
    // Total data
    $countStmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM activity_log
        $whereSql
    ");

    $countStmt->execute($params);

    $totalData = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalData / $limit));

    // Data aktivitas
    $stmt = $pdo->prepare("
        SELECT *
        FROM activity_log
        $whereSql
        ORDER BY id DESC
        LIMIT $limit OFFSET $offset
    ");

    $stmt->execute($params);
    $activities = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Statistik aktivitas
    $totalStmt = $pdo->query("
        SELECT COUNT(*) FROM activity_log
    ");

    $allActivities = (int)$totalStmt->fetchColumn();

    $todayStmt = $pdo->query("
        SELECT COUNT(*)
        FROM activity_log
        WHERE DATE(created_at) = CURDATE()
    ");

    $todayActivities = (int)$todayStmt->fetchColumn();

    $userStmt = $pdo->query("
        SELECT COUNT(DISTINCT user_id)
        FROM activity_log
        WHERE user_id IS NOT NULL
    ");

    $activeUsers = (int)$userStmt->fetchColumn();

} catch (Throwable $e) {
    error_log('Activity Log Page Error: ' . $e->getMessage());

    $activities = [];
    $totalData = 0;
    $totalPages = 1;
    $allActivities = 0;
    $todayActivities = 0;
    $activeUsers = 0;

    $errorMessage = 'Data log aktivitas gagal dimuat. Periksa struktur tabel activity_log.';
}

// Helper lokal agar tidak bentrok dengan fungsi global.
$logEscape = static function ($value): string {
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
};

$baseQuery = [];

if ($search !== '') {
    $baseQuery['search'] = $search;
}

if ($filterRole !== '') {
    $baseQuery['role'] = $filterRole;
}

require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 py-6 space-y-6">

    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-800 dark:text-white">
                Log Aktivitas
            </h1>

            <p class="text-sm text-slate-500 dark:text-slate-400 mt-1">
                Riwayat aktivitas pengguna dalam aplikasi Jurnal PKL.
            </p>
        </div>

        <div class="flex items-center gap-2 text-sm text-indigo-600 dark:text-indigo-400">
            <i class="fas fa-history"></i>
            Monitoring Aktivitas
        </div>
    </div>

    <?php if (isset($errorMessage)): ?>
        <div class="rounded-xl bg-red-50 text-red-700 border border-red-200 p-4">
            <?= $logEscape($errorMessage) ?>
        </div>
    <?php endif; ?>

    <!-- Statistik -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">

        <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Total Aktivitas
                    </p>
                    <h2 class="text-2xl font-bold text-slate-800 dark:text-white mt-2">
                        <?= number_format($allActivities) ?>
                    </h2>
                </div>

                <div class="w-12 h-12 rounded-xl bg-indigo-100 dark:bg-indigo-500/20 text-indigo-600 flex items-center justify-center">
                    <i class="fas fa-chart-line text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Aktivitas Hari Ini
                    </p>
                    <h2 class="text-2xl font-bold text-slate-800 dark:text-white mt-2">
                        <?= number_format($todayActivities) ?>
                    </h2>
                </div>

                <div class="w-12 h-12 rounded-xl bg-purple-100 dark:bg-purple-500/20 text-purple-600 flex items-center justify-center">
                    <i class="fas fa-calendar-day text-xl"></i>
                </div>
            </div>
        </div>

        <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">
            <div class="flex justify-between items-center">
                <div>
                    <p class="text-sm text-slate-500 dark:text-slate-400">
                        Pengguna Tercatat
                    </p>
                    <h2 class="text-2xl font-bold text-slate-800 dark:text-white mt-2">
                        <?= number_format($activeUsers) ?>
                    </h2>
                </div>

                <div class="w-12 h-12 rounded-xl bg-blue-100 dark:bg-blue-500/20 text-blue-600 flex items-center justify-center">
                    <i class="fas fa-users text-xl"></i>
                </div>
            </div>
        </div>

    </div>

    <!-- Filter -->
    <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 p-5 shadow-sm">

        <form method="GET" class="grid grid-cols-1 md:grid-cols-3 gap-3">

            <div class="md:col-span-2">
                <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-2">
                    Cari Aktivitas
                </label>

                <div class="relative">
                    <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"></i>

                    <input
                        type="text"
                        name="search"
                        value="<?= $logEscape($search) ?>"
                        placeholder="Cari nama, aktivitas, detail, atau IP..."
                        class="w-full pl-10 pr-4 py-3 rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-slate-800 dark:text-white focus:ring-2 focus:ring-indigo-500 outline-none"
                    >
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-slate-600 dark:text-slate-300 mb-2">
                    Filter Role
                </label>

                <select
                    name="role"
                    class="w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-slate-800 dark:text-white outline-none"
                >
                    <option value="">Semua Role</option>
                    <option value="admin" <?= $filterRole === 'admin' ? 'selected' : '' ?>>Admin</option>
                    <option value="pembimbing" <?= $filterRole === 'pembimbing' ? 'selected' : '' ?>>Pembimbing</option>
                    <option value="teacher" <?= $filterRole === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                    <option value="student" <?= $filterRole === 'student' ? 'selected' : '' ?>>Student</option>
                    <option value="anggota" <?= $filterRole === 'anggota' ? 'selected' : '' ?>>Anggota</option>
                </select>
            </div>

            <div class="md:col-span-3 flex flex-wrap gap-2">
                <button
                    type="submit"
                    class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white font-medium transition"
                >
                    <i class="fas fa-filter mr-1"></i>
                    Terapkan Filter
                </button>

                <a
                    href="activity_log.php"
                    class="px-5 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 dark:bg-slate-700 dark:hover:bg-slate-600 text-slate-700 dark:text-white font-medium transition"
                >
                    Reset
                </a>
            </div>

        </form>
    </div>

    <!-- Tabel aktivitas -->
    <div class="bg-white dark:bg-slate-800 rounded-2xl border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden">

        <div class="p-5 border-b border-slate-200 dark:border-slate-700 flex justify-between items-center">
            <div>
                <h2 class="font-bold text-slate-800 dark:text-white">
                    Riwayat Aktivitas
                </h2>

                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    Menampilkan <?= count($activities) ?> dari <?= number_format($totalData) ?> data
                </p>
            </div>

            <span class="text-xs px-3 py-1.5 rounded-full bg-indigo-50 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-300">
                Halaman <?= $page ?> / <?= $totalPages ?>
            </span>
        </div>

        <div class="overflow-x-auto">

            <table class="w-full text-sm text-left">

                <thead class="bg-slate-50 dark:bg-slate-700/60 text-slate-600 dark:text-slate-300">
                    <tr>
                        <th class="px-5 py-4">Pengguna</th>
                        <th class="px-5 py-4">Aktivitas</th>
                        <th class="px-5 py-4">Detail</th>
                        <th class="px-5 py-4">IP Address</th>
                        <th class="px-5 py-4">Waktu</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-slate-100 dark:divide-slate-700">

                    <?php if (!$activities): ?>

                        <tr>
                            <td colspan="5" class="px-5 py-12 text-center">
                                <div class="text-slate-400">
                                    <i class="fas fa-inbox text-4xl mb-3"></i>

                                    <p class="font-medium text-slate-600 dark:text-slate-300">
                                        Belum ada aktivitas ditemukan.
                                    </p>

                                    <p class="text-xs mt-1">
                                        Aktivitas pengguna akan muncul di sini setelah tercatat.
                                    </p>
                                </div>
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($activities as $item): ?>

                            <tr class="hover:bg-slate-50 dark:hover:bg-slate-700/40 transition">

                                <td class="px-5 py-4">
                                    <p class="font-semibold text-slate-800 dark:text-white">
                                        <?= $logEscape($item['username'] ?? 'Pengguna') ?>
                                    </p>

                                    <span class="inline-block mt-1 text-xs px-2 py-1 rounded-full bg-indigo-50 dark:bg-indigo-500/20 text-indigo-600 dark:text-indigo-300">
                                        <?= $logEscape($item['role'] ?? '-') ?>
                                    </span>
                                </td>

                                <td class="px-5 py-4">
                                    <span class="font-medium text-slate-700 dark:text-slate-200">
                                        <?= $logEscape($item['aksi'] ?? '-') ?>
                                    </span>
                                </td>

                                <td class="px-5 py-4 max-w-xs">
                                    <p class="text-slate-600 dark:text-slate-300 break-words">
                                        <?= $logEscape($item['detail'] ?? '-') ?>
                                    </p>

                                    <?php if (!empty($item['user_agent'])): ?>
                                        <p class="text-xs text-slate-400 mt-1 break-all">
                                            <?= $logEscape($item['user_agent']) ?>
                                        </p>
                                    <?php endif; ?>
                                </td>

                                <td class="px-5 py-4 text-slate-500 dark:text-slate-400 whitespace-nowrap">
                                    <?= $logEscape($item['ip'] ?? '-') ?>
                                </td>

                                <td class="px-5 py-4 text-slate-500 dark:text-slate-400 whitespace-nowrap">
                                    <?php
                                    $created = $item['created_at'] ?? null;

                                    echo $created
                                        ? $logEscape(date('d-m-Y H:i', strtotime($created)))
                                        : '-';
                                    ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>

            <div class="p-5 border-t border-slate-200 dark:border-slate-700 flex flex-wrap justify-between items-center gap-3">

                <p class="text-xs text-slate-500 dark:text-slate-400">
                    Halaman <?= $page ?> dari <?= $totalPages ?>
                </p>

                <div class="flex gap-2">

                    <?php
                    $previousQuery = http_build_query(
                        array_merge($baseQuery, ['page' => max(1, $page - 1)])
                    );

                    $nextQuery = http_build_query(
                        array_merge($baseQuery, ['page' => min($totalPages, $page + 1)])
                    );
                    ?>

                    <?php if ($page > 1): ?>
                        <a
                            href="?<?= $logEscape($previousQuery) ?>"
                            class="px-4 py-2 rounded-lg bg-slate-100 dark:bg-slate-700 text-slate-700 dark:text-white hover:bg-indigo-100 dark:hover:bg-indigo-500/30"
                        >
                            <i class="fas fa-chevron-left mr-1"></i>
                            Sebelumnya
                        </a>
                    <?php endif; ?>

                    <?php if ($page < $totalPages): ?>
                        <a
                            href="?<?= $logEscape($nextQuery) ?>"
                            class="px-4 py-2 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white"
                        >
                            Selanjutnya
                            <i class="fas fa-chevron-right ml-1"></i>
                        </a>
                    <?php endif; ?>

                </div>
            </div>

        <?php endif; ?>

    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>