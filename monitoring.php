<?php
// monitoring.php
require_once __DIR__ . '/includes/header.php';
requireLogin();
requireRole('pembimbing');

$today = date('Y-m-d');

$siswa = $pdo->query("
    SELECT u.*,
        (SELECT status FROM attendance WHERE user_id = u.id AND date = '$today' LIMIT 1) AS absen_hari_ini,
        (SELECT COUNT(*) FROM journals WHERE user_id = u.id) AS total_jurnal
    FROM users u WHERE u.role NOT IN ('pembimbing','teacher') ORDER BY u.name
")->fetchAll();
?>
<div class="mb-6">
    <h1 class="text-2xl font-bold">📊 Monitoring Siswa</h1>
    <p class="text-gray-500 mt-1">Pantau aktivitas semua siswa PKL.</p>
</div>

<div class="grid grid-cols-2 md:grid-cols-3 gap-3 mb-6">
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="text-xs text-gray-500 mb-1">Total Siswa</div>
        <div class="text-2xl font-bold text-indigo-600"><?= count($siswa) ?></div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="text-xs text-gray-500 mb-1">Sudah Absen</div>
        <div class="text-2xl font-bold text-green-600"><?= count(array_filter($siswa, fn($s) => $s['absen_hari_ini'])) ?></div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="text-xs text-gray-500 mb-1">Belum Absen</div>
        <div class="text-2xl font-bold text-red-500"><?= count(array_filter($siswa, fn($s) => !$s['absen_hari_ini'])) ?></div>
    </div>
</div>

<div class="bg-white dark:bg-gray-800 rounded-xl p-4 mb-6 border border-gray-100 dark:border-gray-700">
    <input type="text" id="search-siswa" placeholder="🔍 Cari nama siswa..." class="w-full px-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg text-sm outline-none dark:text-white">
</div>

<div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left" id="tabel-siswa">
            <thead class="bg-gray-50 dark:bg-gray-700/30">
                <tr class="text-xs text-gray-500 uppercase">
                    <th class="py-3 px-4 font-semibold">Nama</th>
                    <th class="py-3 px-4 font-semibold">Jurusan</th>
                    <th class="py-3 px-4 font-semibold">Absen Hari Ini</th>
                    <th class="py-3 px-4 font-semibold">Jurnal</th>
                </tr>
            </thead>
            <tbody class="text-sm divide-y divide-gray-100 dark:divide-gray-700">
                <?php foreach ($siswa as $s):
                    $badge = match($s['absen_hari_ini']) {
                        'hadir' => 'bg-green-100 text-green-700',
                        'izin'  => 'bg-blue-100 text-blue-700',
                        'sakit' => 'bg-yellow-100 text-yellow-700',
                        'telat' => 'bg-orange-100 text-orange-700',
                        'alpha' => 'bg-red-100 text-red-700',
                        default => 'bg-gray-100 text-gray-600'
                    };
                    $label = match($s['absen_hari_ini']) {
                        'hadir' => 'Hadir', 'izin' => 'Izin', 'sakit' => 'Sakit',
                        'telat' => 'Telat', 'alpha' => 'Tidak Hadir', default => 'Belum'
                    };
                ?>
                <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30" data-name="<?= e(strtolower($s['name'])) ?>">
                    <td class="py-3 px-4">
                        <div class="flex items-center gap-3">
                            <img src="<?= avatarUrl($s['photo'], $s['name'], 36) ?>" class="w-9 h-9 rounded-full object-cover">
                            <div>
                                <div class="font-medium"><?= e($s['name']) ?></div>
                                <div class="text-xs text-gray-500"><?= e($s['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td class="py-3 px-4 text-gray-600"><?= e($s['jurusan'] ?: '-') ?></td>
                    <td class="py-3 px-4">
                        <span class="px-3 py-1 rounded-full text-xs font-bold <?= $badge ?>"><?= $label ?></span>
                    </td>
                    <td class="py-3 px-4"><span class="font-bold text-blue-600"><?= $s['total_jurnal'] ?></span> <span class="text-xs text-gray-500">jurnal</span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.getElementById('search-siswa').addEventListener('input', function() {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('#tabel-siswa tbody tr').forEach(row => {
        row.style.display = row.dataset.name.includes(q) ? '' : 'none';
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>