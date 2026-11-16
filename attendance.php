
<?php
// attendance.php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';

requireLogin();

$user = currentUser();
$uid = (int) $user['id'];
$today = date('Y-m-d');

$isPemantau = isPembimbing() || isAdmin();
$isPembimbingUser = isPembimbing();

// Direktori upload
$uploadPath = rtrim(
    defined('UPLOAD_DIR') ? UPLOAD_DIR : __DIR__ . '/uploads/',
    '/\\'
) . DIRECTORY_SEPARATOR;

$uploadUrl = defined('UPLOAD_URL') ? UPLOAD_URL : 'uploads/';

// =====================================================
// CSRF
// =====================================================

function csrfAtt(): string
{
    if (empty($_SESSION['csrf_attendance'])) {
        $_SESSION['csrf_attendance'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_attendance'];
}

function verifyCsrfAtt(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf_attendance'])
        && hash_equals($_SESSION['csrf_attendance'], $_POST['csrf']);
}

// =====================================================
// STATUS
// =====================================================

function labelStatus($status): string
{
    return match ($status) {
        'hadir' => 'Hadir',
        'izin'  => 'Izin',
        'sakit' => 'Sakit',
        'alpha' => 'Tidak Hadir',
        default => 'Belum Absen'
    };
}

function warnaStatus($status): string
{
    return match ($status) {
        'hadir' => 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400',
        'izin'  => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400',
        'sakit' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/30 dark:text-yellow-400',
        'alpha' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400',
        default => 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'
    };
}

// =====================================================
// PROSES ABSENSI
// =====================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'absen') {

    if ($isPemantau) {
        setFlash('error', 'Admin dan pembimbing tidak perlu melakukan absensi.');
        redirect('attendance.php');
    }

    if (!verifyCsrfAtt()) {
        setFlash('error', 'Sesi kedaluwarsa. Silakan muat ulang halaman.');
        redirect('attendance.php');
    }

    // Pembatasan percobaan
    $attempts = $_SESSION['absen_attempts'] ?? [];

    $attempts = array_values(array_filter(
        $attempts,
        fn($time) => time() - $time < 60
    ));

    if (count($attempts) >= 3) {
        setFlash('error', 'Terlalu banyak percobaan. Tunggu 1 menit.');
        redirect('attendance.php');
    }

    $attempts[] = time();
    $_SESSION['absen_attempts'] = $attempts;

    $status = $_POST['status'] ?? '';
    $note = trim($_POST['note'] ?? '');

    $lat = isset($_POST['latitude']) && $_POST['latitude'] !== ''
        ? (float) $_POST['latitude']
        : null;

    $lng = isset($_POST['longitude']) && $_POST['longitude'] !== ''
        ? (float) $_POST['longitude']
        : null;

    $locText = trim($_POST['location_text'] ?? '');

    // Validasi status
    if (!in_array($status, ['hadir', 'izin', 'sakit', 'alpha'], true)) {
        setFlash('error', 'Status absensi tidak valid.');
        redirect('attendance.php');
    }

    // Validasi catatan
    if (mb_strlen($note) > 255) {
        setFlash('error', 'Catatan maksimal 255 karakter.');
        redirect('attendance.php');
    }

    // Foto dan GPS wajib untuk hadir, izin, sakit
    $needProof = in_array($status, ['hadir', 'izin', 'sakit'], true);

    if ($needProof) {

        if (
            !isset($_FILES['photo']) ||
            $_FILES['photo']['error'] !== UPLOAD_ERR_OK
        ) {
            setFlash('error', 'Foto bukti wajib diupload.');
            redirect('attendance.php');
        }

        if ($lat === null || $lng === null) {
            setFlash('error', 'Aktifkan lokasi GPS terlebih dahulu.');
            redirect('attendance.php');
        }

        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            setFlash('error', 'Koordinat lokasi tidak valid.');
            redirect('attendance.php');
        }
    }

    // Cek apakah sudah absen
    $stmt = $pdo->prepare("
        SELECT id
        FROM attendance
        WHERE user_id = ? AND date = ?
        LIMIT 1
    ");

    $stmt->execute([$uid, $today]);

    if ($stmt->fetch()) {
        setFlash('error', 'Kamu sudah melakukan absensi hari ini.');
        redirect('attendance.php');
    }

    // =================================================
    // UPLOAD FOTO
    // =================================================

    $photoName = null;

    if ($needProof) {

        $file = $_FILES['photo'];

        if ($file['size'] > 5 * 1024 * 1024) {
            setFlash('error', 'Ukuran foto maksimal 5MB.');
            redirect('attendance.php');
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            setFlash('error', 'Upload foto tidak valid.');
            redirect('attendance.php');
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedMime = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp'
        ];

        if (!isset($allowedMime[$mime])) {
            setFlash('error', 'Format foto harus JPG, PNG, atau WEBP.');
            redirect('attendance.php');
        }

        if (!@getimagesize($file['tmp_name'])) {
            setFlash('error', 'File gambar tidak valid.');
            redirect('attendance.php');
        }

        if (!is_dir($uploadPath)) {
            if (!mkdir($uploadPath, 0755, true) && !is_dir($uploadPath)) {
                setFlash('error', 'Folder upload tidak dapat dibuat.');
                redirect('attendance.php');
            }
        }

        $ext = $allowedMime[$mime];

        $photoName = 'absen_' . $uid . '_' .
            date('Ymd_His') . '_' .
            bin2hex(random_bytes(5)) . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $uploadPath . $photoName)) {
            setFlash('error', 'Gagal menyimpan foto.');
            redirect('attendance.php');
        }
    }

    // =================================================
    // SIMPAN DATABASE
    // =================================================

    try {

        $checkIn = date('H:i:s');

        $stmt = $pdo->prepare("
            INSERT INTO attendance (
                user_id,
                date,
                status,
                note,
                check_in,
                photo,
                latitude,
                longitude,
                location_text
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $uid,
            $today,
            $status,
            $note !== '' ? $note : null,
            $checkIn,
            $photoName,
            $lat,
            $lng,
            $locText !== '' ? mb_substr($locText, 0, 255) : null
        ]);

        // Reset pembatasan setelah berhasil
        $_SESSION['absen_attempts'] = [];

        // =================================================
        // NOTIFIKASI PEMBIMBING
        // =================================================

        try {

            $label = labelStatus($status);

            notifyPembimbing(
                $pdo,
                'attendance',
                '📅 ' . $user['name'] . ' telah melakukan absensi',
                "Status: {$label}\nTanggal: " . date('d-m-Y') .
                "\nJam: " . date('H:i'),
                'attendance.php'
            );

        } catch (Throwable $e) {
            // Absensi tetap berhasil meskipun notifikasi gagal
        }

        // Log aktivitas
        if (function_exists('logActivity')) {
            try {
                logActivity('attendance', "Absen {$status}");
            } catch (Throwable $e) {}
        }

        setFlash('success', 'Absensi berhasil disimpan!');

    } catch (Throwable $e) {

        // Hapus foto jika penyimpanan database gagal
        if ($photoName && file_exists($uploadPath . $photoName)) {
            @unlink($uploadPath . $photoName);
        }

        setFlash('error', 'Gagal menyimpan absensi. Silakan coba lagi.');
    }

    redirect('attendance.php');
}

// =====================================================
// DATA ABSENSI SAYA
// =====================================================

$absenSaya = null;

if (!$isPemantau) {

    $stmt = $pdo->prepare("
        SELECT *
        FROM attendance
        WHERE user_id = ? AND date = ?
        LIMIT 1
    ");

    $stmt->execute([$uid, $today]);
    $absenSaya = $stmt->fetch(PDO::FETCH_ASSOC);
}

// =====================================================
// RIWAYAT 7 HARI
// =====================================================

$weekHistory = [];

if (!$isPemantau) {

    $stmt = $pdo->prepare("
        SELECT date, status
        FROM attendance
        WHERE user_id = ?
        AND date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
        AND date <= CURDATE()
    ");

    $stmt->execute([$uid]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $weekHistory[$row['date']] = $row['status'];
    }
}

$dayNames = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
$weekDays = [];

for ($i = 6; $i >= 0; $i--) {

    $d = date('Y-m-d', strtotime("-$i day"));

    $weekDays[] = [
        'date' => $d,
        'day' => $dayNames[(int) date('w', strtotime($d))],
        'num' => (int) date('j', strtotime($d)),
        'today' => $d === $today,
        'status' => $weekHistory[$d] ?? null
    ];
}

// =====================================================
// REKAP BULANAN
// =====================================================

$bulanStats = [
    'hadir' => 0,
    'izin' => 0,
    'sakit' => 0,
    'alpha' => 0
];

if (!$isPemantau) {

    $stmt = $pdo->prepare("
        SELECT status, COUNT(*) AS total
        FROM attendance
        WHERE user_id = ?
        AND YEAR(date) = YEAR(CURDATE())
        AND MONTH(date) = MONTH(CURDATE())
        GROUP BY status
    ");

    $stmt->execute([$uid]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (isset($bulanStats[$row['status']])) {
            $bulanStats[$row['status']] = (int) $row['total'];
        }
    }
}

// =====================================================
// REKAP PEMBIMBING DAN ADMIN
// =====================================================

$rekapAbsen = [];

$stats = [
    'hadir' => 0,
    'izin' => 0,
    'sakit' => 0,
    'alpha' => 0
];

$totalSiswa = 0;
$sudahAbsen = 0;
$belumAbsen = 0;
$progressHadir = 0;

if ($isPemantau) {

    $totalSiswa = (int) $pdo->query("
        SELECT COUNT(*)
        FROM users
        WHERE role = 'student'
    ")->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT
            a.id,
            a.user_id,
            a.date,
            a.status,
            a.note,
            a.check_in,
            a.photo AS foto_absen,
            a.latitude,
            a.longitude,
            a.location_text,
            u.name,
            u.role,
            u.photo AS foto_profil
        FROM attendance a
        INNER JOIN users u ON u.id = a.user_id
        WHERE a.date = ?
        AND u.role = 'student'
        ORDER BY u.name ASC
    ");

    $stmt->execute([$today]);
    $rekapAbsen = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rekapAbsen as $r) {

        if (isset($stats[$r['status']])) {
            $stats[$r['status']]++;
        }
    }

    $sudahAbsen = array_sum($stats);
    $belumAbsen = max(0, $totalSiswa - $sudahAbsen);

    $progressHadir = $totalSiswa > 0
        ? round($sudahAbsen / $totalSiswa * 100)
        : 0;
}

// =====================================================
// HEADER
// =====================================================

require_once __DIR__ . '/includes/header.php';

?>

<style>
@keyframes fadeUp {
    from {
        opacity: 0;
        transform: translateY(12px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.fade-up {
    animation: fadeUp .4s ease-out both;
}

.radio-card:has(input:checked) {
    border-color: #6366f1;
    background: #eef2ff;
}

.dark .radio-card:has(input:checked) {
    background: rgba(99,102,241,.15);
}
</style>

<!-- JUDUL -->

<div class="mb-6 fade-up">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
        📅 Absensi Harian
    </h1>

    <p class="text-gray-500 dark:text-gray-400 mt-1">
        Tanggal:
        <span class="font-semibold text-primary">
            <?= e(function_exists('tanggalIndo') ? tanggalIndo($today) : date('d-m-Y')) ?>
        </span>
    </p>
</div>

<!-- MODE PEMANTAU -->

<?php if ($isPemantau): ?>

<div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-2xl p-5 mb-6 fade-up">

    <div class="flex items-start gap-3">

        <span class="text-3xl">👨‍🏫</span>

        <div>
            <h3 class="font-bold text-amber-800 dark:text-amber-300 mb-1">
                Mode Pemantauan
            </h3>

            <p class="text-sm text-amber-700 dark:text-amber-300">
                <?= $isPembimbingUser
                    ? 'Pembimbing dapat memantau kehadiran seluruh anggota PKL.'
                    : 'Admin dapat memantau rekap kehadiran seluruh anggota PKL.' ?>
            </p>
        </div>

    </div>
</div>

<?php endif; ?>

<!-- STATISTIK PEMANTAU -->

<?php if ($isPemantau): ?>

<div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6 fade-up">

    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="text-xs text-gray-500 mb-1">✅ Hadir</div>
        <div class="text-2xl font-bold text-green-600">
            <?= $stats['hadir'] ?>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="text-xs text-gray-500 mb-1">📝 Izin</div>
        <div class="text-2xl font-bold text-blue-600">
            <?= $stats['izin'] ?>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="text-xs text-gray-500 mb-1">🤒 Sakit</div>
        <div class="text-2xl font-bold text-yellow-600">
            <?= $stats['sakit'] ?>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="text-xs text-gray-500 mb-1">❌ Alpha</div>
        <div class="text-2xl font-bold text-red-500">
            <?= $stats['alpha'] ?>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="text-xs text-gray-500 mb-1">⏳ Belum</div>
        <div class="text-2xl font-bold text-gray-600">
            <?= $belumAbsen ?>
        </div>
    </div>

</div>

<!-- PROGRESS -->

<div class="bg-white dark:bg-gray-800 rounded-xl p-4 mb-6 border border-gray-100 dark:border-gray-700 fade-up">

    <div class="flex justify-between items-center mb-2">

        <span class="text-sm font-semibold">
            Kehadiran Anggota Hari Ini
        </span>

        <span class="text-sm font-bold text-primary">
            <?= $progressHadir ?>%
        </span>

    </div>

    <div class="w-full h-2.5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">

        <div
            class="h-full bg-gradient-to-r from-green-500 to-emerald-500"
            style="width:<?= $progressHadir ?>%">
        </div>

    </div>

    <p class="text-xs text-gray-500 mt-2">
        <?= $sudahAbsen ?> dari <?= $totalSiswa ?> anggota sudah melakukan absensi
    </p>

</div>

<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- KOLOM KIRI -->

    <div class="lg:col-span-1 flex flex-col gap-6">

        <?php if (!$isPemantau): ?>

        <!-- FORM / STATUS ABSENSI -->

        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 p-6 fade-up">

            <?php if ($absenSaya): ?>

                <div class="text-center py-4">

                    <div class="inline-flex items-center justify-center w-20 h-20 bg-green-50 dark:bg-green-900/30 rounded-full mb-4 text-green-500">

                        <svg class="w-10 h-10" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                        </svg>

                    </div>

                    <h3 class="text-xl font-bold mb-2 dark:text-white">
                        Absensi Tersimpan!
                    </h3>

                    <div class="inline-block px-4 py-1.5 rounded-full text-sm font-bold <?= warnaStatus($absenSaya['status']) ?>">
                        <?= labelStatus($absenSaya['status']) ?>
                    </div>

                    <?php if (!empty($absenSaya['check_in'])): ?>

                        <p class="text-xs text-gray-400 mt-3">
                            🕐 Jam masuk:
                            <?= e(substr($absenSaya['check_in'], 0, 5)) ?>
                        </p>

                    <?php endif; ?>

                    <?php if (!empty($absenSaya['note'])): ?>

                        <p class="mt-3 text-sm text-gray-500 italic">
                            "<?= e($absenSaya['note']) ?>"
                        </p>

                    <?php endif; ?>

                    <?php if (!empty($absenSaya['photo'])): ?>

                        <div class="mt-4">

                            <a href="<?= e($uploadUrl . rawurlencode($absenSaya['photo'])) ?>" target="_blank" rel="noopener noreferrer">

                                <img
                                    src="<?= e($uploadUrl . rawurlencode($absenSaya['photo'])) ?>"
                                    alt="Bukti absensi"
                                    class="w-full max-w-xs mx-auto rounded-xl border border-gray-200"
                                >

                            </a>

                        </div>

                    <?php endif; ?>

                    <?php if ($absenSaya['latitude'] !== null && $absenSaya['longitude'] !== null): ?>

                        <div class="mt-4 p-3 rounded-xl bg-gray-50 dark:bg-gray-700/40 text-left">

                            <p class="text-sm font-semibold mb-1 dark:text-white">
                                📍 Lokasi Absensi
                            </p>

                            <p class="text-xs text-gray-500 dark:text-gray-300">
                                <?= e($absenSaya['location_text'] ?: ($absenSaya['latitude'] . ', ' . $absenSaya['longitude'])) ?>
                            </p>

                            <a
                                href="https://www.google.com/maps?q=<?= rawurlencode($absenSaya['latitude'] . ',' . $absenSaya['longitude']) ?>"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-block mt-2 text-xs text-primary hover:underline"
                            >
                                Lihat di Google Maps
                            </a>

                        </div>

                    <?php endif; ?>

                </div>

            <?php else: ?>

                <h3 class="font-bold mb-4 border-b border-gray-100 dark:border-gray-700 pb-3 dark:text-white">
                    Isi Kehadiran
                </h3>

                <form method="POST" enctype="multipart/form-data" id="absen-form">

                    <input type="hidden" name="csrf" value="<?= e(csrfAtt()) ?>">
                    <input type="hidden" name="action" value="absen">

                    <input type="hidden" name="latitude" id="lat-input">
                    <input type="hidden" name="longitude" id="lng-input">
                    <input type="hidden" name="location_text" id="loc-input">

                    <!-- PILIH STATUS -->

                    <div class="space-y-2 mb-5">

                        <?php foreach ([
                            ['hadir', '✅', 'Hadir', 'Melaksanakan kegiatan PKL'],
                            ['izin', '📝', 'Izin', 'Ada keperluan penting'],
                            ['sakit', '🤒', 'Sakit', 'Sedang tidak sehat'],
                            ['alpha', '❌', 'Tidak Hadir', 'Tidak dapat hadir']
                        ] as $opt): ?>

                            <label class="radio-card flex items-center p-3 border-2 border-gray-200 dark:border-gray-600 rounded-xl cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 transition">

                                <input
                                    type="radio"
                                    name="status"
                                    value="<?= $opt[0] ?>"
                                    required
                                    class="w-4 h-4 text-primary"
                                >

                                <span class="ml-3 text-xl">
                                    <?= $opt[1] ?>
                                </span>

                                <div class="ml-2">

                                    <div class="font-medium text-sm dark:text-white">
                                        <?= $opt[2] ?>
                                    </div>

                                    <div class="text-xs text-gray-400">
                                        <?= $opt[3] ?>
                                    </div>

                                </div>

                            </label>

                        <?php endforeach; ?>

                    </div>

                    <!-- FOTO -->

                    <div class="mb-4">

                        <label class="block text-sm font-medium mb-2 dark:text-white">
                            Foto Bukti
                            <span class="text-red-500">*</span>
                        </label>

                        <div
                            id="photo-drop"
                            class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-xl p-4 text-center cursor-pointer hover:border-primary transition bg-gray-50 dark:bg-gray-700/30"
                        >

                            <input
                                type="file"
                                name="photo"
                                id="photo-input"
                                accept="image/jpeg,image/png,image/webp"
                                capture="environment"
                                class="hidden"
                            >

                            <div id="photo-preview-wrap" class="hidden">

                                <img
                                    id="photo-preview"
                                    class="w-full max-h-48 object-cover rounded-lg mb-2 mx-auto"
                                    alt="Preview foto"
                                >

                                <button
                                    type="button"
                                    onclick="resetPhoto(event)"
                                    class="text-xs text-red-500 hover:underline"
                                >
                                    Ganti foto
                                </button>

                            </div>

                            <div id="photo-placeholder">

                                <svg class="w-10 h-10 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">

                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>

                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>

                                </svg>

                                <p class="text-sm font-medium dark:text-white">
                                    Ketuk untuk mengambil foto
                                </p>

                                <p class="text-xs text-gray-400 mt-1">
                                    JPG, PNG, WEBP • Maksimal 5MB
                                </p>

                            </div>

                        </div>

                    </div>

                    <!-- GPS -->

                    <div class="mb-4">

                        <label class="block text-sm font-medium mb-2 dark:text-white">
                            Lokasi GPS
                            <span class="text-red-500">*</span>
                        </label>

                        <div id="location-box" class="p-3 bg-gray-50 dark:bg-gray-700/30 rounded-xl border border-gray-200 dark:border-gray-600 text-sm">

                            <div class="flex items-center gap-2 text-gray-500">

                                <span>📍</span>

                                <span id="loc-status">
                                    Menunggu lokasi...
                                </span>

                            </div>

                            <div id="loc-detail" class="hidden mt-2 text-xs text-gray-600 dark:text-gray-300"></div>

                            <button
                                type="button"
                                onclick="getLocation()"
                                id="loc-retry"
                                class="hidden mt-2 text-xs text-primary hover:underline"
                            >
                                Coba lagi
                            </button>

                        </div>

                    </div>

                    <!-- CATATAN -->

                    <div class="mb-5">

                        <label class="block text-sm font-medium mb-2 dark:text-white">
                            Catatan (opsional)
                        </label>

                        <textarea
                            name="note"
                            maxlength="255"
                            rows="2"
                            placeholder="Contoh: Mengerjakan tugas dari pembimbing..."
                            class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-primary outline-none"
                        ></textarea>

                    </div>

                    <button
                        type="submit"
                        id="submit-absen"
                        class="w-full bg-primary hover:bg-indigo-700 text-white py-3 rounded-xl font-bold shadow-sm transition"
                    >
                        Simpan Absensi
                    </button>

                    <p class="text-xs text-gray-400 text-center mt-3">
                        Pastikan foto dan lokasi sudah tersedia sebelum mengirim.
                    </p>

                </form>

            <?php endif; ?>

        </div>

        <!-- RIWAYAT 7 HARI -->

        <div class="bg-white dark:bg-gray-800 rounded-xl p-5 shadow-sm border border-gray-100 dark:border-gray-700 fade-up">

            <h3 class="font-bold text-sm mb-4 dark:text-white">
                📊 Riwayat 7 Hari
            </h3>

            <div class="grid grid-cols-7 gap-1.5">

                <?php foreach ($weekDays as $wd):

                    $color = 'bg-gray-100 dark:bg-gray-700 text-gray-400';
                    $icon = $wd['num'];

                    if ($wd['status'] === 'hadir') {
                        $color = 'bg-green-500 text-white';
                        $icon = '✓';
                    } elseif ($wd['status'] === 'izin') {
                        $color = 'bg-blue-500 text-white';
                        $icon = 'i';
                    } elseif ($wd['status'] === 'sakit') {
                        $color = 'bg-yellow-500 text-white';
                        $icon = 's';
                    } elseif ($wd['status'] === 'alpha') {
                        $color = 'bg-red-500 text-white';
                        $icon = '×';
                    } elseif ($wd['today']) {
                        $color = 'bg-indigo-100 dark:bg-indigo-900/30 text-indigo-600 border-2 border-indigo-400';
                    }

                ?>

                    <div class="text-center">

                        <div class="text-[10px] font-semibold text-gray-400 mb-1">
                            <?= $wd['day'] ?>
                        </div>

                        <div class="aspect-square rounded-lg flex items-center justify-center text-xs font-bold <?= $color ?>">
                            <?= $icon ?>
                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>

        <!-- REKAP BULANAN ANGGOTA -->

        <div class="bg-white dark:bg-gray-800 rounded-xl p-5 shadow-sm border border-gray-100 dark:border-gray-700 fade-up">

            <h3 class="font-bold text-sm mb-4 dark:text-white">
                📅 Rekap Bulan Ini
            </h3>

            <div class="space-y-3">

                <?php foreach ([
                    'hadir' => ['Hadir', 'text-green-600', 'bg-green-500'],
                    'izin' => ['Izin', 'text-blue-600', 'bg-blue-500'],
                    'sakit' => ['Sakit', 'text-yellow-600', 'bg-yellow-500'],
                    'alpha' => ['Tidak Hadir', 'text-red-600', 'bg-red-500']
                ] as $key => $info): ?>

                    <div class="flex justify-between items-center">

                        <span class="text-sm text-gray-500 dark:text-gray-300">
                            <?= $info[0] ?>
                        </span>

                        <span class="font-bold <?= $info[1] ?>">
                            <?= $bulanStats[$key] ?> hari
                        </span>

                    </div>

                <?php endforeach; ?>

            </div>

        </div>

        <?php endif; ?>

    </div>

    <!-- KOLOM KANAN: REKAP PEMANTAU -->

    <?php if ($isPemantau): ?>

    <div class="lg:col-span-2">

        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden fade-up">

            <div class="px-6 py-4 border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-700/30">

                <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">

                    <div>
                        <h3 class="font-bold dark:text-white">
                            Kehadiran Anggota Hari Ini
                        </h3>

                        <p class="text-xs text-gray-400 mt-1">
                            <?= e($today) ?>
                        </p>
                    </div>

                    <div class="relative w-full sm:w-64">

                        <input
                            type="text"
                            id="search-rekap"
                            placeholder="Cari nama anggota..."
                            class="w-full px-4 py-2 bg-white dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg text-sm outline-none dark:text-white"
                        >

                    </div>

                </div>

            </div>

            <div class="overflow-x-auto">

                <table class="w-full text-left border-collapse" id="rekap-table">

                    <thead>

                        <tr class="text-xs text-gray-500 uppercase">

                            <th class="py-3 px-5 font-semibold border-b border-gray-100 dark:border-gray-700">
                                Nama
                            </th>

                            <th class="py-3 px-5 font-semibold border-b border-gray-100 dark:border-gray-700">
                                Status
                            </th>

                            <th class="py-3 px-5 font-semibold border-b border-gray-100 dark:border-gray-700">
                                Bukti
                            </th>

                            <th class="py-3 px-5 font-semibold border-b border-gray-100 dark:border-gray-700">
                                Catatan
                            </th>

                        </tr>

                    </thead>

                    <tbody class="text-sm">

                        <?php if (!$rekapAbsen): ?>

                            <tr>
                                <td colspan="4" class="text-center py-12 text-gray-400">

                                    <div class="text-5xl mb-3">📭</div>

                                    <p class="text-sm">
                                        Belum ada anggota yang melakukan absensi hari ini.
                                    </p>

                                </td>
                            </tr>

                        <?php endif; ?>

                        <?php foreach ($rekapAbsen as $r): ?>

                            <tr
                                class="hover:bg-gray-50 dark:hover:bg-gray-700/30 border-b border-gray-50 dark:border-gray-700/50"
                                data-name="<?= e(strtolower($r['name'])) ?>"
                            >

                                <td class="py-3 px-5">

                                    <div class="flex items-center gap-3">

                                        <?php if (!empty($r['foto_profil'])): ?>

                                            <img
                                                src="<?= e($uploadUrl . rawurlencode($r['foto_profil'])) ?>"
                                                class="w-9 h-9 rounded-full object-cover"
                                                alt="Foto profil"
                                            >

                                        <?php else: ?>

                                            <div class="w-9 h-9 rounded-full bg-indigo-100 dark:bg-indigo-900/30 flex items-center justify-center text-indigo-600 font-bold">
                                                <?= e(strtoupper(substr($r['name'], 0, 1))) ?>
                                            </div>

                                        <?php endif; ?>

                                        <div>
                                            <div class="font-medium dark:text-white">
                                                <?= e($r['name']) ?>
                                            </div>

                                            <div class="text-xs text-gray-400">
                                                Anggota PKL
                                            </div>
                                        </div>

                                    </div>

                                </td>

                                <td class="py-3 px-5">

                                    <span class="px-3 py-1 rounded-full text-xs font-bold <?= warnaStatus($r['status']) ?>">
                                        <?= labelStatus($r['status']) ?>
                                    </span>

                                    <?php if ($r['check_in']): ?>

                                        <div class="text-xs text-gray-400 mt-1">
                                            🕐 <?= e(substr($r['check_in'], 0, 5)) ?>
                                        </div>

                                    <?php endif; ?>

                                </td>

                                <td class="py-3 px-5">

                                    <?php if (!empty($r['foto_absen'])): ?>

                                        <a
                                            href="<?= e($uploadUrl . rawurlencode($r['foto_absen'])) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >

                                            <img
                                                src="<?= e($uploadUrl . rawurlencode($r['foto_absen'])) ?>"
                                                class="w-12 h-12 rounded-lg object-cover border border-gray-200"
                                                alt="Bukti absensi"
                                            >

                                        </a>

                                    <?php else: ?>

                                        <span class="text-xs text-gray-400">-</span>

                                    <?php endif; ?>

                                </td>

                                <td class="py-3 px-5 text-gray-600 dark:text-gray-400">
                                    <?= e($r['note'] ?: '-') ?>

                                    <?php if ($r['latitude'] !== null && $r['longitude'] !== null): ?>

                                        <a
                                            href="https://www.google.com/maps?q=<?= rawurlencode($r['latitude'] . ',' . $r['longitude']) ?>"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="block mt-1 text-xs text-primary hover:underline"
                                        >
                                            📍 Lihat lokasi
                                        </a>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

    <?php else: ?>

    <!-- INFORMASI ANGGOTA -->

    <div class="lg:col-span-2">

        <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 p-6 fade-up">

            <h3 class="font-bold text-lg mb-3 dark:text-white">
                📌 Informasi Absensi PKL
            </h3>

            <p class="text-sm text-gray-500 dark:text-gray-400 leading-relaxed">
                Lakukan absensi setiap hari sesuai jadwal kegiatan PKL.
                Pastikan foto bukti dan lokasi GPS sudah tersedia ketika mengirimkan absensi.
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-6">

                <div class="p-4 rounded-xl bg-green-50 dark:bg-green-900/20">
                    <div class="text-green-600 font-bold mb-1">
                        ✅ Kehadiran
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Menandakan kamu hadir melaksanakan kegiatan PKL.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-blue-50 dark:bg-blue-900/20">
                    <div class="text-blue-600 font-bold mb-1">
                        📝 Izin
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Digunakan ketika kamu memiliki keperluan dan izin.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-yellow-50 dark:bg-yellow-900/20">
                    <div class="text-yellow-600 font-bold mb-1">
                        🤒 Sakit
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Digunakan ketika kamu tidak dapat hadir karena sakit.
                    </p>
                </div>

                <div class="p-4 rounded-xl bg-red-50 dark:bg-red-900/20">
                    <div class="text-red-600 font-bold mb-1">
                        ❌ Tidak Hadir
                    </div>
                    <p class="text-xs text-gray-500 dark:text-gray-400">
                        Digunakan untuk mencatat ketidakhadiran.
                    </p>
                </div>

            </div>

        </div>

    </div>

    <?php endif; ?>

</div>

<?php if (!$isPemantau): ?>

<script>
const photoInput = document.getElementById('photo-input');
const photoDrop = document.getElementById('photo-drop');
const photoPreview = document.getElementById('photo-preview');
const photoWrap = document.getElementById('photo-preview-wrap');
const photoPlaceholder = document.getElementById('photo-placeholder');

let photoObjectUrl = null;

// =================================================
// PREVIEW FOTO
// =================================================

photoDrop?.addEventListener('click', function(e) {

    if (e.target.closest('button')) return;

    photoInput.click();

});

photoInput?.addEventListener('change', function() {

    const file = this.files[0];

    if (!file) return;

    if (file.size > 5 * 1024 * 1024) {
        alert('Ukuran foto maksimal 5MB.');
        this.value = '';
        return;
    }

    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
        alert('Format foto harus JPG, PNG, atau WEBP.');
        this.value = '';
        return;
    }

    if (photoObjectUrl) {
        URL.revokeObjectURL(photoObjectUrl);
    }

    photoObjectUrl = URL.createObjectURL(file);

    photoPreview.src = photoObjectUrl;

    photoWrap.classList.remove('hidden');
    photoPlaceholder.classList.add('hidden');

});

function resetPhoto(e) {

    e.stopPropagation();

    photoInput.value = '';

    if (photoObjectUrl) {
        URL.revokeObjectURL(photoObjectUrl);
        photoObjectUrl = null;
    }

    photoPreview.src = '';

    photoWrap.classList.add('hidden');
    photoPlaceholder.classList.remove('hidden');

}

// =================================================
// GPS
// =================================================

function getLocation() {

    const st = document.getElementById('loc-status');
    const dt = document.getElementById('loc-detail');
    const rt = document.getElementById('loc-retry');

    const latInput = document.getElementById('lat-input');
    const lngInput = document.getElementById('lng-input');
    const locInput = document.getElementById('loc-input');

    st.textContent = 'Mengambil lokasi GPS...';

    dt.classList.add('hidden');
    rt.classList.add('hidden');

    latInput.value = '';
    lngInput.value = '';
    locInput.value = '';

    if (!navigator.geolocation) {

        st.textContent = 'Browser tidak mendukung GPS.';
        rt.classList.remove('hidden');

        return;
    }

    navigator.geolocation.getCurrentPosition(

        async function(position) {

            const lat = position.coords.latitude.toFixed(8);
            const lng = position.coords.longitude.toFixed(8);

            latInput.value = lat;
            lngInput.value = lng;

            st.textContent = '✅ Lokasi berhasil ditemukan';

            dt.textContent = 'Latitude: ' + lat + ' | Longitude: ' + lng;

            dt.classList.remove('hidden');

            // Geocoding opsional
            try {

                const baseUrl = <?= json_encode(defined('BASE_URL') ? rtrim(BASE_URL, '/') : '') ?>;

                if (baseUrl) {

                    const response = await fetch(
                        baseUrl + '/api/geocode.php?lat=' +
                        encodeURIComponent(lat) +
                        '&lng=' +
                        encodeURIComponent(lng)
                    );

                    if (response.ok) {

                        const data = await response.json();

                        if (data.display_name) {

                            locInput.value = data.display_name;

                            dt.textContent = data.display_name;
                        }
                    }
                }

            } catch (error) {

                // Alamat opsional, koordinat tetap disimpan

            }

        },

        function(error) {

            latInput.value = '';
            lngInput.value = '';

            switch (error.code) {

                case error.PERMISSION_DENIED:
                    st.textContent = 'Izin lokasi ditolak. Aktifkan izin lokasi browser.';
                    break;

                case error.POSITION_UNAVAILABLE:
                    st.textContent = 'Lokasi tidak tersedia.';
                    break;

                case error.TIMEOUT:
                    st.textContent = 'Waktu mengambil lokasi habis.';
                    break;

                default:
                    st.textContent = 'Gagal mengambil lokasi.';
            }

            rt.classList.remove('hidden');

        },

        {
            enableHighAccuracy: true,
            timeout: 20000,
            maximumAge: 0
        }

    );

}

// =================================================
// VALIDASI SEBELUM KIRIM
// =================================================

document.getElementById('absen-form')?.addEventListener('submit', function(e) {

    const selected = document.querySelector('input[name="status"]:checked');

    if (!selected) {

        e.preventDefault();
        alert('Silakan pilih status kehadiran.');

        return;
    }

    const status = selected.value;

    if (['hadir', 'izin', 'sakit'].includes(status)) {

        if (!photoInput.files.length) {

            e.preventDefault();
            alert('Silakan upload foto bukti terlebih dahulu.');

            return;
        }

        if (!document.getElementById('lat-input').value ||
            !document.getElementById('lng-input').value) {

            e.preventDefault();
            alert('Lokasi GPS belum ditemukan. Silakan coba lagi.');

            return;
        }

    }

    const button = document.getElementById('submit-absen');

    button.disabled = true;
    button.textContent = 'Menyimpan absensi...';

});

// Ambil lokasi otomatis
getLocation();

// =================================================
// PENCARIAN REKAP
// =================================================

document.getElementById('search-rekap')?.addEventListener('input', function() {

    const q = this.value.toLowerCase().trim();

    document.querySelectorAll('#rekap-table tbody tr[data-name]').forEach(row => {

        row.style.display = row.dataset.name.includes(q) ? '' : 'none';

    });

});

</script>

<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
