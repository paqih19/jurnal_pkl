<?php
// attendance.php
require_once __DIR__ . '/includes/header.php';
requireLogin();

$uid = (int)$user['id'];
$today = date('Y-m-d');
$isPembimbing = isPembimbing();

// ============ DETEKSI KOLOM DB ============
$cols = [];
try {
    foreach ($pdo->query("SHOW COLUMNS FROM attendance") as $col) {
        $cols[$col['Field']] = true;
    }
} catch (Exception $e) {}
$has = fn($c) => isset($cols[$c]);

// ============ PROSES ABSEN (HANYA SISWA) ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'absen') {

    // Tolak kalau pembimbing
    if ($isPembimbing) {
        setFlash('error', 'Pembimbing tidak perlu absen. Hanya siswa yang wajib absen.');
        redirect('attendance.php');
    }

    $status = $_POST['status'] ?? '';
    $note = trim($_POST['note'] ?? '');
    $lat = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? (float)$_POST['latitude'] : null;
    $lng = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
    $locText = trim($_POST['location_text'] ?? '');

    if (!in_array($status, ['hadir', 'izin', 'sakit', 'alpha'])) {
        setFlash('error', 'Status tidak valid.');
        redirect('attendance.php');
    }

    // Foto & lokasi wajib untuk hadir/izin/sakit
    $needPhoto = in_array($status, ['hadir', 'izin', 'sakit']);
    if ($needPhoto && (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK)) {
        setFlash('error', 'Foto wajib diupload untuk status ini.');
        redirect('attendance.php');
    }
    if ($needPhoto && ($lat === null || $lng === null)) {
        setFlash('error', 'Lokasi wajib diaktifkan.');
        redirect('attendance.php');
    }

    // Cek sudah absen
    $stmt = $pdo->prepare("SELECT id FROM attendance WHERE user_id = ? AND date = ?");
    $stmt->execute([$uid, $today]);
    if ($stmt->fetch()) {
        setFlash('error', 'Kamu sudah absen hari ini.');
        redirect('attendance.php');
    }

    // Upload foto
    $photoName = null;
    if ($needPhoto && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0777, true);
        $tmp = $_FILES['photo']['tmp_name'];
        $orig = $_FILES['photo']['name'];
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $allowed = ['jpg','jpeg','png','webp'];
        if (!in_array($ext, $allowed)) {
            setFlash('error', 'Format foto harus JPG, PNG, atau WEBP.');
            redirect('attendance.php');
        }
        if ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
            setFlash('error', 'Foto maksimal 5MB.');
            redirect('attendance.php');
        }
        $photoName = 'absen_' . $uid . '_' . date('Ymd_His') . '.' . $ext;
        if (!move_uploaded_file($tmp, UPLOAD_DIR . $photoName)) {
            setFlash('error', 'Gagal upload foto.');
            redirect('attendance.php');
        }
    }

    // Bangun INSERT dinamis
    $fields = ['user_id', 'date', 'status', 'note'];
    $values = [$uid, $today, $status, $note];
    $placeholders = ['?', '?', '?', '?'];

    if ($has('check_in'))      { $fields[]='check_in';      $placeholders[]='NOW()'; }
    if ($has('photo'))         { $fields[]='photo';         $placeholders[]='?'; $values[]=$photoName; }
    if ($has('latitude'))      { $fields[]='latitude';      $placeholders[]='?'; $values[]=$lat; }
    if ($has('longitude'))     { $fields[]='longitude';     $placeholders[]='?'; $values[]=$lng; }
    if ($has('location_text')) { $fields[]='location_text'; $placeholders[]='?'; $values[]=$locText; }

    try {
        $sql = "INSERT INTO attendance (" . implode(',', $fields) . ") VALUES (" . implode(',', $placeholders) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        setFlash('success', 'Absen berhasil disimpan!');
    } catch (Exception $e) {
        if ($photoName && file_exists(UPLOAD_DIR . $photoName)) @unlink(UPLOAD_DIR . $photoName);
        setFlash('error', 'Gagal menyimpan absen: ' . $e->getMessage());
    }
    redirect('attendance.php');
}

// ============ DATA ABSEN SAYA HARI INI ============
$selSaya = ['status', 'note'];
if ($has('check_in'))      $selSaya[] = 'check_in';
if ($has('photo'))         $selSaya[] = 'photo';
if ($has('latitude'))      $selSaya[] = 'latitude';
if ($has('longitude'))     $selSaya[] = 'longitude';
if ($has('location_text')) $selSaya[] = 'location_text';

$stmt = $pdo->prepare("SELECT " . implode(',', $selSaya) . " FROM attendance WHERE user_id = ? AND date = ?");
$stmt->execute([$uid, $today]);
$absenSaya = $stmt->fetch();
if ($absenSaya) {
    foreach (['check_in','photo','latitude','longitude','location_text'] as $k) {
        if (!isset($absenSaya[$k])) $absenSaya[$k] = null;
    }
}

// ============ REKAP HARI INI ============
$selRekap = ['a.status','a.note','u.name','u.role','u.photo'];
if ($has('check_in'))      $selRekap[] = 'a.check_in';
if ($has('photo'))         $selRekap[] = 'a.photo AS foto_absen';
if ($has('latitude'))      $selRekap[] = 'a.latitude';
if ($has('longitude'))     $selRekap[] = 'a.longitude';
if ($has('location_text')) $selRekap[] = 'a.location_text';

$stmt = $pdo->prepare("
    SELECT " . implode(',', $selRekap) . "
    FROM attendance a
    JOIN users u ON a.user_id = u.id
    WHERE a.date = ?
    ORDER BY u.role ASC, u.id ASC
");
$stmt->execute([$today]);
$rekapAbsen = $stmt->fetchAll();
foreach ($rekapAbsen as &$r) {
    foreach (['check_in','foto_absen','latitude','longitude','location_text'] as $k) {
        if (!isset($r[$k])) $r[$k] = null;
    }
}
unset($r);

// Total siswa (bukan pembimbing)
$totalSiswa = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role NOT IN ('pembimbing','teacher')")->fetchColumn();

// ============ STATISTIK ============
$stats = ['hadir'=>0, 'izin'=>0, 'sakit'=>0, 'alpha'=>0];
foreach ($rekapAbsen as $r) {
    if (!in_array($r['role'], ['pembimbing','teacher']) && isset($stats[$r['status']])) {
        $stats[$r['status']]++;
    }
}
$sudahAbsen = $stats['hadir'] + $stats['izin'] + $stats['sakit'] + $stats['alpha'];
$belumAbsen = max(0, $totalSiswa - $sudahAbsen);
$progressHadir = $totalSiswa > 0 ? round($sudahAbsen / $totalSiswa * 100) : 0;

// ============ RIWAYAT 7 HARI (SISWA) ============
$weekHistory = [];
if (!$isPembimbing) {
    $stmt = $pdo->prepare("
        SELECT date, status FROM attendance
        WHERE user_id = ? AND date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        ORDER BY date ASC
    ");
    $stmt->execute([$uid]);
    foreach ($stmt->fetchAll() as $row) $weekHistory[$row['date']] = $row['status'];
}

$dayNames = ['Min','Sen','Sel','Rab','Kam','Jum','Sab'];
$weekDays = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $weekDays[] = [
        'date'   => $d,
        'day'    => $dayNames[(int)date('w', strtotime($d))],
        'num'    => (int)date('j', strtotime($d)),
        'today'  => $d === $today,
        'status' => $weekHistory[$d] ?? null
    ];
}

// ============ HELPER ============
function labelStatus($s) {
    return match($s) {
        'hadir' => 'Hadir', 'izin' => 'Izin', 'sakit' => 'Sakit',
        'telat' => 'Telat', 'alpha' => 'Tidak Hadir', default => 'Belum Absen'
    };
}
function warnaStatus($s) {
    return match($s) {
        'hadir' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
        'izin'  => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
        'sakit' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
        'telat' => 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400',
        'alpha' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
        default => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
    };
}
?>

<style>
    @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
    .fade-up { animation: fadeUp .4s ease-out both; }
    .radio-card:has(input:checked) { border-color: #6366f1; background: #eef2ff; }
    .dark .radio-card:has(input:checked) { background: rgba(99,102,241,0.15); }
</style>

<!-- HEADER -->
<div class="mb-6 fade-up">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">📅 Absensi Harian</h1>
    <p class="text-gray-500 dark:text-gray-400 mt-1">
        Tanggal: <span class="font-semibold text-primary"><?= tanggalIndo($today) ?></span>
    </p>
</div>

<!-- INFO PEMBIMBING -->
<?php if ($isPembimbing): ?>
<div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-2xl p-5 mb-6 fade-up">
    <div class="flex items-start gap-3">
        <span class="text-3xl">👨‍🏫</span>
        <div>
            <h3 class="font-bold text-amber-800 dark:text-amber-300 mb-1">Mode Pembimbing</h3>
            <p class="text-sm text-amber-700 dark:text-amber-300">
                Pembimbing <b>tidak wajib absen harian</b>. Kamu bisa memantau kehadiran siswa di bawah.
            </p>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- STATISTIK -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6 fade-up">
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="flex items-center justify-between mb-1"><span class="text-xs text-gray-500">Hadir</span><span class="text-lg">✅</span></div>
        <div class="text-2xl font-bold text-green-600"><?= $stats['hadir'] ?></div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="flex items-center justify-between mb-1"><span class="text-xs text-gray-500">Izin</span><span class="text-lg">📝</span></div>
        <div class="text-2xl font-bold text-blue-600"><?= $stats['izin'] ?></div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="flex items-center justify-between mb-1"><span class="text-xs text-gray-500">Sakit</span><span class="text-lg">🤒</span></div>
        <div class="text-2xl font-bold text-yellow-600"><?= $stats['sakit'] ?></div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="flex items-center justify-between mb-1"><span class="text-xs text-gray-500">Belum Absen</span><span class="text-lg">⏳</span></div>
        <div class="text-2xl font-bold text-gray-600"><?= $belumAbsen ?></div>
    </div>
</div>

<!-- PROGRESS -->
<div class="bg-white dark:bg-gray-800 rounded-xl p-4 mb-6 border border-gray-100 dark:border-gray-700 fade-up">
    <div class="flex justify-between items-center mb-2">
        <span class="text-sm font-semibold">Kehadiran Siswa Hari Ini</span>
        <span class="text-sm font-bold text-primary"><?= $progressHadir ?>%</span>
    </div>
    <div class="w-full h-2.5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
        <div class="h-full bg-gradient-to-r from-green-500 to-emerald-500 rounded-full transition-all" style="width:<?= $progressHadir ?>%"></div>
    </div>
    <p class="text-xs text-gray-500 mt-2"><?= $sudahAbsen ?> dari <?= $totalSiswa ?> siswa sudah absen</p>
</div>

<!-- ISI UTAMA -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- KOLOM KIRI -->
    <div class="lg:col-span-1 flex flex-col gap-6">

        <?php if (!$isPembimbing): ?>
            <!-- SISWA: FORM / STATUS ABSEN -->
            <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 p-6 fade-up">
                <?php if ($absenSaya): ?>
                    <!-- Sudah absen -->
                    <div class="text-center py-4">
                        <div class="inline-flex items-center justify-center w-20 h-20 bg-green-50 dark:bg-green-900/30 rounded-full mb-4 text-green-500">
                            <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <h3 class="text-xl font-bold mb-2">Sudah Absen!</h3>
                        <p class="text-sm text-gray-500 mb-4">Kamu sudah tercatat hari ini.</p>
                        <div class="inline-block px-4 py-1.5 rounded-full text-sm font-bold <?= warnaStatus($absenSaya['status']) ?>"><?= labelStatus($absenSaya['status']) ?></div>
                        <?php if ($absenSaya['check_in']): ?>
                            <p class="text-xs text-gray-400 mt-3">🕐 Jam masuk: <?= date('H:i', strtotime($absenSaya['check_in'])) ?></p>
                        <?php endif; ?>
                        <?php if ($absenSaya['note']): ?>
                            <p class="mt-3 text-sm text-gray-500 italic">"<?= e($absenSaya['note']) ?>"</p>
                        <?php endif; ?>
                        <?php if (!empty($absenSaya['photo'])): ?>
                            <div class="mt-4"><img src="<?= UPLOAD_URL.$absenSaya['photo'] ?>" class="w-full max-w-xs mx-auto rounded-xl border border-gray-200 cursor-pointer" onclick="window.open(this.src)"></div>
                        <?php endif; ?>
                        <?php if (!empty($absenSaya['latitude']) && !empty($absenSaya['longitude'])): ?>
                            <div class="mt-3 text-xs text-gray-500">📍 <?= $absenSaya['location_text'] ?: ($absenSaya['latitude'].', '.$absenSaya['longitude']) ?></div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- Form absen -->
                    <h3 class="font-bold mb-4 border-b border-gray-100 dark:border-gray-700 pb-3">Isi Kehadiran</h3>
                    <form method="POST" enctype="multipart/form-data" id="absen-form">
                        <input type="hidden" name="action" value="absen">
                        <input type="hidden" name="latitude" id="lat-input" value="">
                        <input type="hidden" name="longitude" id="lng-input" value="">
                        <input type="hidden" name="location_text" id="loc-input" value="">

                        <div class="space-y-2 mb-5">
                            <?php
                            $options = [
                                ['hadir', '✅', 'Hadir', 'Masuk kerja hari ini'],
                                ['izin',  '📝', 'Izin',  'Ada keperluan penting'],
                                ['sakit', '🤒', 'Sakit', 'Sedang tidak enak badan'],
                                ['alpha', '❌', 'Tidak Hadir', 'Tanpa kabar'],
                            ];
                            foreach ($options as $opt):
                            ?>
                            <label class="radio-card flex items-center p-3 border-2 border-gray-200 dark:border-gray-600 rounded-xl cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition">
                                <input type="radio" name="status" value="<?= $opt[0] ?>" required class="w-4 h-4 text-primary focus:ring-primary">
                                <span class="ml-3 text-xl"><?= $opt[1] ?></span>
                                <div class="ml-2">
                                    <div class="font-medium text-sm"><?= $opt[2] ?></div>
                                    <div class="text-xs text-gray-400"><?= $opt[3] ?></div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <!-- Foto -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-2">Foto Bukti <span class="text-red-500">*</span></label>
                            <div id="photo-drop" class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-xl p-4 text-center cursor-pointer hover:border-primary transition bg-gray-50 dark:bg-gray-700/30">
                                <input type="file" name="photo" id="photo-input" accept="image/*" capture="environment" class="hidden">
                                <div id="photo-preview-wrap" class="hidden">
                                    <img id="photo-preview" class="w-full max-h-48 object-cover rounded-lg mb-2 mx-auto">
                                    <button type="button" onclick="resetPhoto(event)" class="text-xs text-red-500 hover:underline">Ganti foto</button>
                                </div>
                                <div id="photo-placeholder">
                                    <svg class="w-10 h-10 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                    <p class="text-sm font-medium text-gray-600 dark:text-gray-300">Ketuk untuk ambil foto</p>
                                    <p class="text-xs text-gray-400 mt-1">JPG, PNG, WEBP • Maks 5MB</p>
                                </div>
                            </div>
                        </div>

                        <!-- Lokasi -->
                        <div class="mb-4">
                            <label class="block text-sm font-medium mb-2">Lokasi <span class="text-red-500">*</span></label>
                            <div id="location-box" class="p-3 bg-gray-50 dark:bg-gray-700/30 rounded-xl border border-gray-200 dark:border-gray-600 text-sm">
                                <div class="flex items-center gap-2 text-gray-500">
                                    <svg class="w-4 h-4 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                    <span id="loc-status">Mengambil lokasi...</span>
                                </div>
                                <div id="loc-detail" class="hidden mt-2 text-xs text-gray-600"></div>
                                <button type="button" onclick="getLocation()" id="loc-retry" class="hidden mt-2 text-xs text-primary hover:underline">Coba lagi</button>
                            </div>
                        </div>

                        <div class="mb-5">
                            <label class="block text-sm font-medium mb-2">Catatan (boleh dikosongkan)</label>
                            <input type="text" name="note" maxlength="200" placeholder="Contoh: Sakit demam" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-primary outline-none">
                        </div>

                        <button type="submit" id="submit-btn" class="w-full bg-primary hover:bg-indigo-700 text-white py-3 rounded-xl font-bold transition-colors shadow-sm disabled:opacity-50">
                            Simpan Absen
                        </button>
                    </form>
                <?php endif; ?>
            </div>

            <!-- Riwayat 7 hari -->
            <div class="bg-white dark:bg-gray-800 rounded-xl p-5 shadow-sm border border-gray-100 dark:border-gray-700 fade-up">
                <h3 class="font-bold text-sm mb-4">📊 Riwayat 7 Hari</h3>
                <div class="grid grid-cols-7 gap-1.5">
                    <?php foreach ($weekDays as $wd):
                        $color = 'bg-gray-100 dark:bg-gray-700 text-gray-400';
                        $icon = $wd['num'];
                        if ($wd['status'] === 'hadir') { $color = 'bg-green-500 text-white'; $icon = '✓'; }
                        elseif ($wd['status'] === 'telat') { $color = 'bg-orange-500 text-white'; $icon = '!'; }
                        elseif ($wd['status'] === 'izin') { $color = 'bg-blue-500 text-white'; $icon = 'i'; }
                        elseif ($wd['status'] === 'sakit') { $color = 'bg-yellow-500 text-white'; $icon = 's'; }
                        elseif ($wd['status'] === 'alpha') { $color = 'bg-red-500 text-white'; $icon = '×'; }
                        elseif ($wd['today']) { $color = 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 border-2 border-indigo-400'; }
                    ?>
                    <div class="text-center">
                        <div class="text-[10px] font-semibold text-gray-400 mb-1"><?= $wd['day'] ?></div>
                        <div class="aspect-square rounded-lg flex items-center justify-center text-xs font-bold <?= $color ?>"><?= $icon ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

    </div>

    <!-- KOLOM KANAN: REKAP -->
    <div class="lg:col-span-2">
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden fade-up">
            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/30">
                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
                    <h3 class="font-bold">Kehadiran Siswa Hari Ini</h3>
                    <div class="relative w-full sm:w-64">
                        <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input type="text" id="search-rekap" placeholder="Cari nama..." class="w-full pl-9 pr-4 py-2 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg text-sm outline-none dark:text-white">
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse" id="rekap-table">
                    <thead>
                        <tr class="text-xs text-gray-500 uppercase">
                            <th class="py-3 px-6 font-semibold border-b border-gray-100 dark:border-gray-700">Nama</th>
                            <th class="py-3 px-6 font-semibold border-b border-gray-100 dark:border-gray-700">Status</th>
                            <th class="py-3 px-6 font-semibold border-b border-gray-100 dark:border-gray-700">Bukti</th>
                            <th class="py-3 px-6 font-semibold border-b border-gray-100 dark:border-gray-700">Catatan</th>
                        </tr>
                    </thead>
                    <tbody class="text-sm">
                        <?php
                        $adaSiswa = false;
                        foreach ($rekapAbsen as $r) if (!in_array($r['role'], ['pembimbing','teacher'])) $adaSiswa = true;
                        ?>
                        <?php if (!$adaSiswa): ?>
                            <tr><td colspan="4" class="text-center py-12 text-gray-400">
                                <div class="text-5xl mb-3">📭</div>
                                <p class="text-sm">Belum ada siswa yang absen hari ini</p>
                            </td></tr>
                        <?php endif; ?>

                        <?php foreach ($rekapAbsen as $r):
                            if (in_array($r['role'], ['pembimbing','teacher'])) continue;
                            $namaKey = strtolower($r['name']);
                        ?>
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 border-b border-gray-50 dark:border-gray-700/50 last:border-0" data-name="<?= e($namaKey) ?>">
                                <td class="py-3 px-6">
                                    <div class="flex items-center gap-3">
                                        <img src="<?= avatarUrl($r['photo'], $r['name'], 36) ?>" class="w-9 h-9 rounded-full object-cover">
                                        <div>
                                            <div class="font-medium"><?= e($r['name']) ?></div>
                                            <div class="text-xs text-gray-400">Siswa</div>
                                        </div>
                                    </div>
                                </td>
                                <td class="py-3 px-6">
                                    <span class="px-3 py-1 rounded-full text-xs font-bold <?= warnaStatus($r['status']) ?>"><?= labelStatus($r['status']) ?></span>
                                    <?php if ($r['check_in']): ?><div class="text-xs text-gray-400 mt-1">🕐 <?= date('H:i', strtotime($r['check_in'])) ?></div><?php endif; ?>
                                </td>
                                <td class="py-3 px-6">
                                    <?php if (!empty($r['foto_absen'])): ?>
                                        <img src="<?= UPLOAD_URL.$r['foto_absen'] ?>" class="w-12 h-12 rounded-lg object-cover cursor-pointer border border-gray-200" onclick="window.open(this.src)">
                                    <?php else: ?>
                                        <span class="text-xs text-gray-400">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-6 text-gray-600 dark:text-gray-400 text-sm"><?= e($r['note'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if (!$isPembimbing): ?>
<script>
// ============ FOTO ============
const photoInput = document.getElementById('photo-input');
const photoDrop = document.getElementById('photo-drop');
const photoPreview = document.getElementById('photo-preview');
const photoWrap = document.getElementById('photo-preview-wrap');
const photoPlaceholder = document.getElementById('photo-placeholder');

photoDrop.addEventListener('click', e => {
    if (e.target.tagName !== 'BUTTON') photoInput.click();
});
photoInput.addEventListener('change', () => {
    const f = photoInput.files[0];
    if (!f) return;
    if (f.size > 5 * 1024 * 1024) { alert('Foto maksimal 5MB'); photoInput.value = ''; return; }
    const reader = new FileReader();
    reader.onload = e => {
        photoPreview.src = e.target.result;
        photoWrap.classList.remove('hidden');
        photoPlaceholder.classList.add('hidden');
    };
    reader.readAsDataURL(f);
});
function resetPhoto(e) {
    e.stopPropagation();
    photoInput.value = '';
    photoWrap.classList.add('hidden');
    photoPlaceholder.classList.remove('hidden');
}

// ============ LOKASI ============
function getLocation() {
    const status = document.getElementById('loc-status');
    const detail = document.getElementById('loc-detail');
    const retry = document.getElementById('loc-retry');
    status.textContent = 'Mengambil lokasi...';
    detail.classList.add('hidden');
    retry.classList.add('hidden');

    if (!navigator.geolocation) {
        status.textContent = 'Browser tidak mendukung GPS';
        return;
    }

    navigator.geolocation.getCurrentPosition(pos => {
        const lat = pos.coords.latitude.toFixed(8);
        const lng = pos.coords.longitude.toFixed(8);
        document.getElementById('lat-input').value = lat;
        document.getElementById('lng-input').value = lng;
        status.textContent = '✅ Lokasi ditemukan';
        detail.textContent = `Lat: ${lat}, Lng: ${lng}`;
        detail.classList.remove('hidden');

        // Reverse geocoding
        fetch(`https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}`)
            .then(r => r.json())
            .then(d => {
                if (d.display_name) {
                    document.getElementById('loc-input').value = d.display_name;
                    detail.textContent = d.display_name;
                }
            }).catch(() => {});
    }, err => {
        status.textContent = 'Gagal mengambil lokasi';
        retry.classList.remove('hidden');
    }, { enableHighAccuracy: true, timeout: 10000 });
}
getLocation();

// ============ SEARCH REKAP ============
document.getElementById('search-rekap')?.addEventListener('input', function() {
    const q = this.value.toLowerCase().trim();
    document.querySelectorAll('#rekap-table tbody tr[data-name]').forEach(row => {
        row.style.display = row.dataset.name.includes(q) ? '' : 'none';
    });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>