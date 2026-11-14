<?php
// dashboard.php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = currentUser();
$uid = (int)$user['id'];
$today = date('Y-m-d');
$nowHour = (int)date('H');
$isPembimbing = isPembimbing();

if ($nowHour < 11)      $greeting = 'Selamat Pagi';
elseif ($nowHour < 15)  $greeting = 'Selamat Siang';
elseif ($nowHour < 18)  $greeting = 'Selamat Sore';
else                    $greeting = 'Selamat Malam';

// ============================================================
// ============ DASHBOARD PEMBIMBING ==========================
// ============================================================
if ($isPembimbing):
    $totalSiswa = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role NOT IN ('pembimbing','teacher')")->fetchColumn();
    $stmt = $pdo->prepare("SELECT status, COUNT(*) as jml FROM attendance WHERE date = ? GROUP BY status");
    $stmt->execute([$today]);
    $absenStats = ['hadir'=>0,'izin'=>0,'sakit'=>0,'telat'=>0,'alpha'=>0];
    foreach ($stmt->fetchAll() as $r) $absenStats[$r['status']] = (int)$r['jml'];
    $totalHadir = $absenStats['hadir'] + $absenStats['telat'];
    $totalJurnalHariIni = (int)$pdo->query("SELECT COUNT(*) FROM journals WHERE activity_date = '$today'")->fetchColumn();
    $belumAbsen = max(0, $totalSiswa - array_sum($absenStats));

    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.photo FROM users u
        WHERE u.role NOT IN ('pembimbing','teacher') AND u.id NOT IN (SELECT user_id FROM attendance WHERE date = ?)
        ORDER BY u.name LIMIT 6
    ");
    $stmt->execute([$today]);
    $siswaBelumAbsen = $stmt->fetchAll();

    $jurnalTerbaru = $pdo->query("
        SELECT j.*, u.name, u.photo AS user_photo FROM journals j
        JOIN users u ON j.user_id = u.id
        ORDER BY j.created_at DESC LIMIT 5
    ")->fetchAll();

        $persenHadir = $totalSiswa > 0 ? round(($totalHadir / $totalSiswa) * 100) : 0;

    // LOAD HEADER
    require_once __DIR__ . '/includes/header.php';
?>
<div class="relative bg-gradient-to-br from-amber-500
    <div class="absolute -top-16 -right-16 w-64 h-64 bg-white/10 rounded-full blur-2xl"></div>
    <div class="absolute top-4 right-6 text-7xl opacity-20 hidden md:block">👨‍🏫</div>
    <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div class="text-white">
            <p class="text-amber-100 text-sm mb-1"><?= date('l, d F Y') ?></p>
            <h1 class="text-2xl md:text-3xl font-bold mb-2"><?= $greeting ?>, <?= e($user['name']) ?>! 👋</h1>
            <p class="text-amber-100 text-sm md:text-base max-w-xl">Ini dashboard <b>monitoring</b> kamu sebagai pembimbing PKL. Pantau aktivitas siswa hari ini.</p>
        </div>
        <div class="bg-white/20 backdrop-blur border border-white/30 text-white px-4 py-3 rounded-xl text-sm">
            <div class="flex items-center gap-2"><span class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></span><span class="font-semibold">👨‍🏫 Mode Pembimbing</span></div>
        </div>
    </div>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="flex items-center justify-between mb-1"><span class="text-xs text-gray-500">Total Siswa</span><span class="text-lg">👥</span></div>
        <div class="text-2xl font-bold text-indigo-600"><?= $totalSiswa ?></div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="flex items-center justify-between mb-1"><span class="text-xs text-gray-500">Hadir Hari Ini</span><span class="text-lg">✅</span></div>
        <div class="text-2xl font-bold text-green-600"><?= $totalHadir ?></div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="flex items-center justify-between mb-1"><span class="text-xs text-gray-500">Jurnal Hari Ini</span><span class="text-lg">📝</span></div>
        <div class="text-2xl font-bold text-blue-600"><?= $totalJurnalHariIni ?></div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700 <?= $belumAbsen > 0 ? 'ring-2 ring-red-300' : '' ?>">
        <div class="flex items-center justify-between mb-1"><span class="text-xs text-gray-500">Belum Absen</span><span class="text-lg">⏳</span></div>
        <div class="text-2xl font-bold text-red-500"><?= $belumAbsen ?></div>
    </div>
</div>

<div class="bg-white dark:bg-gray-800 rounded-xl p-4 mb-6 border border-gray-100 dark:border-gray-700">
    <div class="flex justify-between items-center mb-2">
        <span class="text-sm font-semibold">Kehadiran Siswa Hari Ini</span>
        <span class="text-sm font-bold text-green-600"><?= $persenHadir ?>%</span>
    </div>
    <div class="w-full h-2.5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
        <div class="h-full bg-gradient-to-r from-green-500 to-emerald-500 rounded-full" style="width:<?= $persenHadir ?>%"></div>
    </div>
    <p class="text-xs text-gray-500 mt-2"><?= $totalHadir ?> dari <?= $totalSiswa ?> siswa hadir</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white dark:bg-gray-800 rounded-xl p-5 border border-gray-100 dark:border-gray-700">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-bold text-sm">⚠️ Siswa Belum Absen</h3>
            <a href="attendance.php" class="text-xs text-primary hover:underline">Lihat semua →</a>
        </div>
        <?php if (count($siswaBelumAbsen) > 0): ?>
            <div class="space-y-2">
                <?php foreach ($siswaBelumAbsen as $s): ?>
                    <div class="flex items-center gap-3 p-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">
                        <img src="<?= avatarUrl($s['photo'], $s['name'], 36) ?>" class="w-9 h-9 rounded-full object-cover">
                        <span class="text-sm font-medium flex-1 truncate"><?= e($s['name']) ?></span>
                        <span class="text-xs text-red-500 font-semibold bg-red-50 dark:bg-red-900/30 px-2 py-1 rounded-full">Belum</span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center py-6"><div class="text-4xl mb-2">🎉</div><p class="text-sm text-green-600 font-semibold">Semua siswa sudah absen!</p></div>
        <?php endif; ?>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-5 border border-gray-100 dark:border-gray-700">
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-bold text-sm">📝 Jurnal Terbaru</h3>
            <a href="journal.php" class="text-xs text-primary hover:underline">Lihat semua →</a>
        </div>
        <?php if (count($jurnalTerbaru) > 0): ?>
            <div class="space-y-2">
                <?php foreach ($jurnalTerbaru as $j): ?>
                    <div class="flex items-start gap-3 p-2 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">
                        <img src="<?= avatarUrl($j['user_photo'], $j['name'], 32) ?>" class="w-8 h-8 rounded-full object-cover mt-0.5">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium truncate"><?= e($j['title']) ?></p>
                            <p class="text-xs text-gray-500"><?= e($j['name']) ?> • <?= timeAgo($j['created_at']) ?></p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-sm text-gray-500 text-center py-6">Belum ada jurnal</p>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 gap-3">
    <a href="monitoring.php" class="bg-gradient-to-br from-amber-500 to-orange-600 text-white rounded-xl p-4 hover:scale-105 transition shadow flex flex-col items-center gap-1"><span class="text-2xl">📊</span><span class="text-xs font-bold">Monitoring</span></a>
    <a href="tasks.php" class="bg-gradient-to-br from-blue-500 to-blue-600 text-white rounded-xl p-4 hover:scale-105 transition shadow flex flex-col items-center gap-1"><span class="text-2xl">📋</span><span class="text-xs font-bold">Buat Tugas</span></a>
    <a href="attendance.php" class="bg-gradient-to-br from-green-500 to-green-600 text-white rounded-xl p-4 hover:scale-105 transition shadow flex flex-col items-center gap-1"><span class="text-2xl">✅</span><span class="text-xs font-bold">Rekap Absen</span></a>
    <a href="obrolan.php" class="bg-gradient-to-br from-purple-500 to-purple-600 text-white rounded-xl p-4 hover:scale-105 transition shadow flex flex-col items-center gap-1"><span class="text-2xl">💬</span><span class="text-xs font-bold">Obrolan</span></a>
</div>

<?php
require_once __DIR__ . '/includes/footer.php';
exit;
endif;

// ============================================================
// ============ DASHBOARD SISWA ===============================
// ============================================================
$stmt = $pdo->query("SELECT COUNT(*) FROM tasks WHERE status != 'done'");
$total_tugas = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM tasks WHERE status = 'done'");
$tugas_selesai = (int)$stmt->fetchColumn();
$stmt = $pdo->query("SELECT COUNT(*) FROM tasks");
$tugas_total = (int)$stmt->fetchColumn();
$progress_tugas = $tugas_total > 0 ? round($tugas_selesai / $tugas_total * 100) : 0;

$stmt = $pdo->prepare("SELECT title, deadline, DATEDIFF(deadline, CURDATE()) AS days_left FROM tasks WHERE status != 'done' AND deadline IS NOT NULL AND deadline >= CURDATE() ORDER BY deadline ASC LIMIT 1");
$stmt->execute();
$next_task = $stmt->fetch();

$stmt = $pdo->prepare("SELECT status FROM attendance WHERE user_id = ? AND date = ?");
$stmt->execute([$uid, $today]);
$absen_hari_ini = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT date, status FROM attendance WHERE user_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) ORDER BY date ASC");
$stmt->execute([$uid]);
$week_attendance = [];
foreach ($stmt->fetchAll() as $row) $week_attendance[$row['date']] = $row['status'];

try { $unread_chat = unreadChatCount(); } catch (Exception $e) { $unread_chat = 0; }

$activities = [];
try { $stmt = $pdo->prepare("SELECT created_at, 'jurnal' AS type, title AS label FROM journals WHERE user_id = ? ORDER BY created_at DESC LIMIT 1"); $stmt->execute([$uid]); if ($row = $stmt->fetch()) $activities[] = $row; } catch (Exception $e) {}
try { $stmt = $pdo->prepare("SELECT updated_at AS created_at, 'task' AS type, title AS label FROM tasks WHERE created_by = ? AND status='done' ORDER BY updated_at DESC LIMIT 1"); $stmt->execute([$uid]); if ($row = $stmt->fetch()) $activities[] = $row; } catch (Exception $e) {}
try { $stmt = $pdo->prepare("SELECT CONCAT(date,' ',COALESCE(check_in,'00:00:00')) AS created_at, 'absen' AS type, status AS label FROM attendance WHERE user_id = ? ORDER BY date DESC LIMIT 1"); $stmt->execute([$uid]); if ($row = $stmt->fetch()) $activities[] = $row; } catch (Exception $e) {}
usort($activities, fn($a,$b)=>strtotime($b['created_at']) - strtotime($a['created_at']));
$activities = array_slice($activities, 0, 4);

$onlineUsers = [];
try { $stmt = $pdo->prepare("SELECT u.id, u.name, u.photo, u.role FROM user_activity ua JOIN users u ON ua.user_id = u.id WHERE ua.last_seen > NOW() - INTERVAL 60 SECOND AND u.id != ? LIMIT 8"); $stmt->execute([$uid]); $onlineUsers = $stmt->fetchAll(); } catch (Exception $e) {}

$dayNames = ['Min','Sen','Sel','Rab','Kam','Jum','Sab'];
$weekDays = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $weekDays[] = ['date'=>$d, 'day'=>$dayNames[(int)date('w', strtotime($d))], 'num'=>(int)date('j', strtotime($d)), 'today'=>$d === $today, 'status'=>$week_attendance[$d] ?? null];
}

function labelAbsen($status) {
    return match($status) {
        'hadir' => 'Hadir', 'telat' => 'Telat', 'izin' => 'Izin',
        'sakit' => 'Sakit', 'alpha' => 'Tidak Hadir', default => 'Belum Absen'
    };
}

// LOAD HEADER
require_once __DIR__ . '/includes/header.php';
?>

<div class="relative bg-gradient-to-br from-indigo-600 via-indigo-500 to-purple-600 rounded-2xl shadow-lg p-6 md:p-8 mb-6 overflow-hidden">
    <div class="absolute -top-16 -right-16 w-64 h-64 bg-white/10 rounded-full blur-2xl"></div>
    <div class="absolute top-4 right-6 text-7xl opacity-20 hidden md:block">🎓</div>
    <div class="relative z-10 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div class="text-white">
            <p class="text-indigo-100 text-sm mb-1"><?= date('l, d F Y') ?></p>
            <h1 class="text-2xl md:text-3xl font-bold mb-2"><?= $greeting ?>, <?= e($user['name']) ?>! 👋</h1>
            <p class="text-indigo-100 text-sm md:text-base max-w-xl">
                <?php if (!$absen_hari_ini): ?>⚠️ Kamu <b>belum absen</b> hari ini. Yuk isi sekarang!
                <?php else: ?>Semangat menjalani PKL hari ini! Jangan lupa isi jurnal.<?php endif; ?>
            </p>
        </div>
        <?php if (!$absen_hari_ini): ?>
        <a href="attendance.php" class="bg-white text-indigo-700 font-bold px-5 py-3 rounded-xl shadow-lg hover:bg-indigo-50 transition-all hover:scale-105 whitespace-nowrap">⚡ Absen Sekarang</a>
        <?php else: ?>
        <div class="bg-white/20 backdrop-blur border border-white/30 text-white px-4 py-3 rounded-xl text-sm">
            <div class="flex items-center gap-2"><span class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></span><span class="font-semibold">Sudah absen: <?= labelAbsen($absen_hari_ini) ?></span></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
    <div class="lg:col-span-2 flex flex-col gap-6">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            <div class="bg-white dark:bg-gray-800 rounded-xl p-5 border border-gray-100 dark:border-gray-700 hover:shadow-lg transition">
                <div class="flex items-start justify-between mb-3">
                    <div class="bg-yellow-50 dark:bg-yellow-900/30 p-2.5 rounded-lg text-yellow-600">📋</div>
                    <?php if ($total_tugas > 0): ?><span class="bg-red-100 text-red-600 text-xs font-bold px-2 py-0.5 rounded-full"><?= $total_tugas ?> aktif</span><?php endif; ?>
                </div>
                <div class="text-3xl font-bold mb-1"><?= $total_tugas ?></div>
                <div class="text-gray-500 text-sm mb-3">Tugas Belum Selesai</div>
                <div class="w-full h-1.5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden mb-3">
                    <div class="h-full bg-gradient-to-r from-yellow-400 to-yellow-500 rounded-full" style="width:<?= $progress_tugas ?>%"></div>
                </div>
                <div class="flex items-center justify-between text-xs">
                    <span class="text-gray-400"><?= $progress_tugas ?>% selesai</span>
                    <a href="tasks.php" class="text-indigo-600 font-semibold hover:underline">Lihat →</a>
                </div>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-5 border border-gray-100 dark:border-gray-700 hover:shadow-lg transition">
                <div class="flex items-start justify-between mb-3"><div class="bg-green-50 dark:bg-green-900/30 p-2.5 rounded-lg text-green-600">✅</div></div>
                <div class="text-lg font-bold mb-1 <?= $absen_hari_ini ? 'text-green-600' : 'text-red-500' ?>"><?= $absen_hari_ini ? labelAbsen($absen_hari_ini) : 'Belum Absen' ?></div>
                <div class="text-gray-500 text-sm mb-3">Status Absen Hari Ini</div>
                <a href="attendance.php" class="text-indigo-600 text-xs font-semibold hover:underline">Isi Absen →</a>
            </div>
            <div class="bg-white dark:bg-gray-800 rounded-xl p-5 border border-gray-100 dark:border-gray-700 hover:shadow-lg transition relative">
                <div class="flex items-start justify-between mb-3"><div class="bg-blue-50 dark:bg-blue-900/30 p-2.5 rounded-lg text-blue-600">💬</div>
                <?php if ($unread_chat > 0): ?><span class="absolute top-3 right-3 bg-red-500 text-white text-xs font-bold min-w-[20px] h-5 flex items-center justify-center px-1.5 rounded-full"><?= $unread_chat > 99 ? '99+' : $unread_chat ?></span><?php endif; ?>
                </div>
                <div class="text-3xl font-bold mb-1"><?= $unread_chat ?></div>
                <div class="text-gray-500 text-sm mb-3">Pesan Belum Dibaca</div>
                <a href="obrolan.php" class="inline-block bg-indigo-600 text-white px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-indigo-700">Buka Obrolan</a>
            </div>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl p-5 border border-gray-100 dark:border-gray-700">
            <h3 class="font-bold mb-4">📅 Absensi 7 Hari Terakhir</h3>
            <div class="grid grid-cols-7 gap-2">
                <?php foreach ($weekDays as $wd):
                    $statusColor = 'bg-gray-100 dark:bg-gray-700 text-gray-400';
                    $icon = '';
                    if ($wd['status'] === 'hadir') { $statusColor = 'bg-green-500 text-white'; $icon = '✓'; }
                    elseif ($wd['status'] === 'telat') { $statusColor = 'bg-yellow-500 text-white'; $icon = '!'; }
                    elseif ($wd['status'] === 'izin') { $statusColor = 'bg-blue-500 text-white'; $icon = 'i'; }
                    elseif ($wd['status'] === 'sakit') { $statusColor = 'bg-purple-500 text-white'; $icon = 's'; }
                    elseif ($wd['status'] === 'alpha') { $statusColor = 'bg-red-500 text-white'; $icon = '×'; }
                    elseif ($wd['today']) { $statusColor = 'bg-indigo-100 text-indigo-600 border-2 border-indigo-400'; }
                ?>
                <div class="text-center">
                    <div class="text-[10px] font-semibold text-gray-400 mb-1"><?= $wd['day'] ?></div>
                    <div class="aspect-square rounded-xl flex items-center justify-center text-sm font-bold <?= $statusColor ?>"><?= $icon ?: $wd['num'] ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <?php
            $quickActions = [
                ['href'=>'journal.php',    'icon'=>'📝', 'label'=>'Isi Jurnal', 'color'=>'from-blue-500 to-blue-600'],
                ['href'=>'attendance.php', 'icon'=>'✅', 'label'=>'Absen',      'color'=>'from-green-500 to-green-600'],
                ['href'=>'tasks.php',      'icon'=>'📋', 'label'=>'Tugas',      'color'=>'from-yellow-500 to-yellow-600'],
                ['href'=>'obrolan.php',    'icon'=>'💬', 'label'=>'Obrolan',    'color'=>'from-purple-500 to-purple-600'],
            ];
            foreach ($quickActions as $qa): ?>
            <a href="<?= $qa['href'] ?>" class="bg-gradient-to-br <?= $qa['color'] ?> text-white rounded-xl p-4 hover:scale-105 transition shadow flex flex-col items-center gap-1">
                <span class="text-2xl"><?= $qa['icon'] ?></span><span class="text-xs font-bold"><?= $qa['label'] ?></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="flex flex-col gap-6">
        <?php if ($next_task): ?>
        <div class="bg-gradient-to-br from-orange-50 to-red-50 dark:from-orange-900/20 dark:to-red-900/20 rounded-xl p-5 border border-orange-200 dark:border-orange-800">
            <div class="flex items-center gap-2 mb-2"><span class="text-xl">⏰</span><h3 class="font-bold text-orange-800 dark:text-orange-300 text-sm">Tenggat Terdekat</h3></div>
            <p class="text-gray-800 dark:text-gray-200 font-semibold text-sm mb-2 truncate"><?= e($next_task['title']) ?></p>
            <?php
                $days = (int)$next_task['days_left'];
                $badgeColor = $days <= 1 ? 'bg-red-500' : ($days <= 3 ? 'bg-orange-500' : 'bg-blue-500');
                $badgeText = $days == 0 ? 'HARI INI' : ($days == 1 ? 'BESOK' : $days.' hari lagi');
            ?>
            <span class="<?= $badgeColor ?> text-white text-xs font-bold px-2.5 py-1 rounded-full"><?= $badgeText ?></span>
            <a href="tasks.php" class="block mt-3 text-orange-700 dark:text-orange-300 text-xs font-semibold hover:underline">Lihat Tugas →</a>
        </div>
        <?php endif; ?>

        <div class="bg-white dark:bg-gray-800 rounded-xl p-5 border border-gray-100 dark:border-gray-700">
            <div class="flex items-center justify-between mb-3">
                <h3 class="font-bold text-sm flex items-center gap-2"><span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>Teman yang Online</h3>
                <span class="text-xs text-gray-400"><?= count($onlineUsers) ?></span>
            </div>
            <?php if (count($onlineUsers) > 0): ?>
                <div class="flex flex-col gap-2 max-h-52 overflow-y-auto">
                    <?php foreach ($onlineUsers as $ou): ?>
                        <div class="flex items-center gap-2.5 p-1.5 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700">
                            <div class="relative">
                                <img src="<?= avatarUrl($ou['photo'], $ou['name'], 40) ?>" class="w-8 h-8 rounded-full object-cover">
                                <span class="absolute bottom-0 right-0 w-2.5 h-2.5 bg-green-500 border-2 border-white dark:border-gray-800 rounded-full"></span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <div class="text-xs font-semibold truncate"><?= e($ou['name']) ?></div>
                                <div class="text-[10px] text-gray-400"><?= in_array($ou['role'], ['pembimbing','teacher']) ? 'Pembimbing' : 'Siswa' ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-xs text-gray-400 text-center py-4">Tidak ada teman yang online</p>
            <?php endif; ?>
        </div>

        <div class="bg-white dark:bg-gray-800 rounded-xl p-5 border border-gray-100 dark:border-gray-700">
            <h3 class="font-bold text-sm mb-3">🕒 Aktivitas Terbaru</h3>
            <?php if (count($activities) > 0): ?>
                <div class="flex flex-col gap-3">
                    <?php foreach ($activities as $act):
                        $icon = match($act['type']) {
                            'jurnal' => ['📝', 'bg-blue-100', 'text-blue-600'],
                            'task'   => ['✅', 'bg-green-100', 'text-green-600'],
                            'absen'  => ['📍', 'bg-purple-100', 'text-purple-600'],
                            default  => ['📌', 'bg-gray-100', 'text-gray-600'],
                        };
                    ?>
                        <div class="flex items-start gap-2.5">
                            <div class="w-7 h-7 rounded-lg <?= $icon[1] ?> <?= $icon[2] ?> flex items-center justify-center text-xs shrink-0"><?= $icon[0] ?></div>
                            <div class="min-w-0 flex-1">
                                <div class="text-xs font-medium truncate"><?= e($act['label']) ?></div>
                                <div class="text-[10px] text-gray-400"><?= timeAgo($act['created_at']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p class="text-xs text-gray-400 text-center py-4">Belum ada aktivitas</p>
            <?php endif; ?>
        </div>

        <div class="bg-gradient-to-br from-indigo-50 to-purple-50 dark:from-indigo-900/20 dark:to-purple-900/20 rounded-xl p-4 border border-indigo-100 dark:border-indigo-800">
            <div class="flex items-start gap-2">
                <span class="text-lg">💡</span>
                <div>
                    <div class="font-bold text-indigo-800 dark:text-indigo-300 text-xs mb-1">Tips PKL</div>
                    <p class="text-indigo-700 dark:text-indigo-300 text-xs leading-relaxed">
                        <?php
                        $tips = ['Isi jurnal setiap hari agar tidak lupa detail pekerjaan.', 'Jangan ragu bertanya ke pembimbing jika bingung.', 'Catat setiap tugas yang diberikan, sekecil apapun.', 'Jaga komunikasi baik dengan rekan tim lewat obrolan.', 'Manfaatkan waktu PKL untuk networking & belajar hal baru.'];
                        echo $tips[(int)date('j') % count($tips)];
                        ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>