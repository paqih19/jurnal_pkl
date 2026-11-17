<?php
// profile.php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = currentUser();
$uid  = (int) $user['id'];

// Guard $pdo
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Koneksi database tidak tersedia.');
}

// ==========================================
// CSRF KHUSUS PROFIL
// ==========================================
function profileCsrfToken(): string {
    if (empty($_SESSION['csrf_profile'])) {
        $_SESSION['csrf_profile'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_profile'];
}

function verifyProfileCsrf(): bool {
    return isset($_POST['csrf'], $_SESSION['csrf_profile'])
        && hash_equals($_SESSION['csrf_profile'], (string) $_POST['csrf']);
}

function profileStrongPassword(string $password): bool {
    return strlen($password) >= 8
        && preg_match('/[A-Za-z]/', $password)
        && preg_match('/\d/', $password);
}

// ==========================================
// DETEKSI KOLOM DATABASE
// ==========================================
$userCols = [];

try {
    foreach ($pdo->query("SHOW COLUMNS FROM users") as $column) {
        $userCols[$column['Field']] = true;
    }
} catch (Throwable $e) {
    setFlash('error', 'Tidak dapat membaca struktur tabel pengguna.');
    redirect('dashboard.php');
}

$hasPassword = isset($userCols['password']);
$hasBio      = isset($userCols['bio']);
$hasJurusan  = isset($userCols['jurusan']);
$hasSekolah  = isset($userCols['sekolah']);
$hasPhoto    = isset($userCols['photo']);
$hasCreated  = isset($userCols['created_at']);

// ==========================================
// AMBIL DATA PENGGUNA
// ==========================================
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$uid]);
$currentUserData = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUserData) {
    setFlash('error', 'Data pengguna tidak ditemukan.');
    redirect('dashboard.php');
}

$isPembimbing = in_array(
    $currentUserData['role'] ?? '',
    ['pembimbing', 'teacher', 'admin'],
    true
);

// ==========================================
// UPDATE PROFIL
// ==========================================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'update'
) {
    if (!verifyProfileCsrf()) {
        setFlash('error', 'Sesi kedaluwarsa. Silakan muat ulang halaman.');
        redirect('profile.php');
    }

    $name    = trim($_POST['name'] ?? '');
    $bio     = $hasBio ? trim($_POST['bio'] ?? '') : '';
    $jurusan = $hasJurusan ? trim($_POST['jurusan'] ?? '') : '';
    $sekolah = $hasSekolah ? trim($_POST['sekolah'] ?? '') : '';

    $removePhoto = ($_POST['remove_photo'] ?? '') === '1';
    $error = null;

    $nameLen    = function_exists('mb_strlen') ? mb_strlen($name) : strlen($name);
    $bioLen     = function_exists('mb_strlen') ? mb_strlen($bio) : strlen($bio);
    $jurusanLen = function_exists('mb_strlen') ? mb_strlen($jurusan) : strlen($jurusan);
    $sekolahLen = function_exists('mb_strlen') ? mb_strlen($sekolah) : strlen($sekolah);

    if ($name === '' || $nameLen < 2) {
        $error = 'Nama minimal 2 karakter.';
    } elseif ($nameLen > 100) {
        $error = 'Nama maksimal 100 karakter.';
    } elseif ($bioLen > 300) {
        $error = 'Bio maksimal 300 karakter.';
    } elseif ($jurusanLen > 50) {
        $error = 'Jurusan atau bidang maksimal 50 karakter.';
    } elseif ($sekolahLen > 100) {
        $error = 'Nama sekolah maksimal 100 karakter.';
    }

    if (!$error) {
        try {
            $sets = ['name = ?'];
            $values = [$name];

            if ($hasBio) {
                $sets[] = 'bio = ?';
                $values[] = $bio;
            }

            if ($hasJurusan) {
                $sets[] = 'jurusan = ?';
                $values[] = $jurusan;
            }

            if ($hasSekolah) {
                $sets[] = 'sekolah = ?';
                $values[] = $sekolah;
            }

            $oldPhoto = $currentUserData['photo'] ?? null;

            if ($removePhoto && $hasPhoto) {
                $sets[] = 'photo = NULL';
            }

            $values[] = $uid;

            $sql = "UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?";
            $pdo->prepare($sql)->execute($values);

            $_SESSION['name'] = $name;

            if ($removePhoto && $hasPhoto) {
                $_SESSION['photo'] = null;

                if (
                    !empty($oldPhoto)
                    && defined('UPLOAD_DIR')
                    && is_file(UPLOAD_DIR . basename($oldPhoto))
                ) {
                    @unlink(UPLOAD_DIR . basename($oldPhoto));
                }
            }

            if (function_exists('logActivity')) {
                logActivity('profile_update', 'Memperbarui informasi profil');
            }

            setFlash('success', 'Profil berhasil diperbarui!');
        } catch (Throwable $e) {
            error_log('Profile update error: ' . $e->getMessage());
            setFlash('error', 'Gagal memperbarui profil. Silakan coba lagi.');
        }
    } else {
        setFlash('error', $error);
    }

    redirect('profile.php');
}

// ==========================================
// GANTI PASSWORD
// ==========================================
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'change_password'
    && $hasPassword
) {
    if (!verifyProfileCsrf()) {
        setFlash('error', 'Sesi kedaluwarsa. Silakan muat ulang halaman.');
        redirect('profile.php');
    }

    $now = time();

    $_SESSION['pwd_attempts'] = array_values(array_filter(
        $_SESSION['pwd_attempts'] ?? [],
        fn($time) => $now - $time < 900
    ));

    if (count($_SESSION['pwd_attempts']) >= 5) {
        setFlash('error', 'Terlalu banyak percobaan. Coba lagi 15 menit.');
        redirect('profile.php');
    }

    $old     = (string) ($_POST['old_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    if (!password_verify($old, $currentUserData['password'])) {
        $_SESSION['pwd_attempts'][] = $now;
        setFlash('error', 'Password lama salah.');
    } elseif (!profileStrongPassword($new)) {
        setFlash('error', 'Password minimal 8 karakter dan harus mengandung huruf serta angka.');
    } elseif ($new === $old) {
        setFlash('error', 'Password baru tidak boleh sama dengan password lama.');
    } elseif ($new !== $confirm) {
        setFlash('error', 'Konfirmasi password tidak cocok.');
    } else {
        try {
            $hash = password_hash($new, PASSWORD_DEFAULT);

            $pdo->prepare(
                "UPDATE users SET password = ? WHERE id = ?"
            )->execute([$hash, $uid]);

            $_SESSION['pwd_attempts'] = [];

            session_regenerate_id(true);
            $_SESSION['csrf_profile'] = bin2hex(random_bytes(32));

            if (function_exists('logActivity')) {
                logActivity('password_change', 'Password berhasil diubah');
            }

            setFlash('success', 'Password berhasil diubah!');
        } catch (Throwable $e) {
            error_log('Password change error: ' . $e->getMessage());
            setFlash('error', 'Gagal mengubah password.');
        }
    }

    redirect('profile.php');
}

// ==========================================
// STATISTIK
// ==========================================
$stats = [
    'jurnal' => 0,
    'tugas' => 0,
    'absen' => 0,
    'total_absen' => 0
];

try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM journals WHERE user_id = ?");
    $stmt->execute([$uid]);
    $stats['jurnal'] = (int) $stmt->fetchColumn();
} catch (Throwable $e) {}

// Tugas: hitung tugas yang di-assign ke user ATAU dibuat user (jika ada kolom)
try {
    $taskCols = [];
    foreach ($pdo->query("SHOW COLUMNS FROM tasks") as $col) {
        $taskCols[$col['Field']] = true;
    }

    if (isset($taskCols['assigned_to'])) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM tasks
            WHERE assigned_to = ? AND status = 'done'
        ");
        $stmt->execute([$uid]);
        $stats['tugas'] = (int) $stmt->fetchColumn();
    } else {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM tasks
            WHERE created_by = ? AND status = 'done'
        ");
        $stmt->execute([$uid]);
        $stats['tugas'] = (int) $stmt->fetchColumn();
    }
} catch (Throwable $e) {}

try {
    $stmt = $pdo->prepare("
        SELECT
            SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) AS hadir,
            COUNT(*) AS total
        FROM attendance
        WHERE user_id = ?
    ");
    $stmt->execute([$uid]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $stats['absen'] = (int) ($row['hadir'] ?? 0);
    $stats['total_absen'] = (int) ($row['total'] ?? 0);
} catch (Throwable $e) {}

$kehadiran = $stats['total_absen'] > 0
    ? (int) round(($stats['absen'] / $stats['total_absen']) * 100)
    : 0;

// ==========================================
// INFORMASI PROFIL
// ==========================================
$joinDate = '-';

if (!empty($currentUserData['created_at'])) {
    $timestamp = strtotime($currentUserData['created_at']);

    if ($timestamp !== false) {
        $joinDate = date('d F Y', $timestamp);
    }
}

$photo = $currentUserData['photo'] ?? '';

$fotoUrl = !empty($photo) && defined('UPLOAD_URL')
    ? rtrim(UPLOAD_URL, '/') . '/' . rawurlencode(basename($photo)) . '?v=' . time()
    : 'https://ui-avatars.com/api/?name='
        . urlencode($currentUserData['name'])
        . '&background=EEF2FF&color=4F46E5&size=200';

$roleLabel = $isPembimbing ? 'Pembimbing' : 'Siswa PKL';
$roleIcon  = $isPembimbing ? '👨‍🏫' : '👤';

require_once __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">
<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>

<style>
@keyframes profileFade {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
}
.profile-fade { animation: profileFade .4s ease both; }
.profile-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    box-shadow: 0 4px 18px rgba(15,23,42,.04);
}
.dark .profile-card { background: #1e293b; border-color: #334155; }
.profile-input {
    width: 100%;
    padding: 11px 14px;
    border: 1px solid #cbd5e1;
    border-radius: 10px;
    outline: none;
    background: #fff;
    color: #1e293b;
    transition: .2s;
}
.profile-input:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,.13); }
.dark .profile-input { background: #0f172a; color: #fff; border-color: #475569; }
.profile-tab { border: 1px solid transparent; transition: .2s; }
.profile-tab.active { background: #eef2ff; color: #4f46e5; border-color: #c7d2fe; }
.dark .profile-tab.active { background: #312e81; color: #c7d2fe; border-color: #4338ca; }
.profile-stat { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 13px; padding: 14px 6px; }
.dark .profile-stat { background: #0f172a; border-color: #334155; }
.avatar-overlay { opacity: 0; transition: opacity .2s; }
.avatar-hover:hover .avatar-overlay { opacity: 1; }
.profile-button {
    background: linear-gradient(110deg,#4f46e5,#9333ea,#3b82f6);
    color: white;
    transition: .2s;
}
.profile-button:hover {
    filter: brightness(1.08);
    box-shadow: 0 5px 14px rgba(79,70,229,.2);
}
@media (max-width: 640px) {
    .avatar-overlay { opacity: 1; background: rgba(15,23,42,.2); }
}
</style>

<!-- HEADER PROFIL -->
<div class="mb-6 profile-fade">
    <div class="flex items-center gap-3 mb-2">
        <div class="w-11 h-11 rounded-xl bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center text-xl">
            👤
        </div>
        <div>
            <h1 class="text-2xl font-bold text-gray-900 dark:text-white">Profil Saya</h1>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Kelola informasi dan keamanan akun kamu.
            </p>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- KOLOM KIRI -->
    <div class="flex flex-col gap-6">

        <!-- KARTU PROFIL -->
        <div class="profile-card overflow-hidden profile-fade">
            <div class="h-28 bg-gradient-to-r from-indigo-600 via-purple-600 to-blue-600 relative">
                <div class="absolute right-5 top-4 text-4xl opacity-30"><?= $roleIcon ?></div>
            </div>

            <div class="px-6 pb-6 text-center">
                <div class="relative inline-block -mt-16 mb-3 avatar-hover">
                    <img
                        id="avatar-preview"
                        src="<?= e($fotoUrl) ?>"
                        alt="Foto profil"
                        class="w-32 h-32 rounded-full object-cover ring-4 ring-white dark:ring-slate-800 shadow-lg bg-white"
                    >

                    <?php if ($hasPhoto): ?>
                    <label
                        for="photo-input"
                        class="avatar-overlay absolute inset-0 rounded-full bg-black/50 flex items-center justify-center cursor-pointer"
                        title="Ganti foto profil"
                    >
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
                        </svg>
                    </label>

                    <input
                        type="file"
                        id="photo-input"
                        accept="image/jpeg,image/png,image/webp"
                        class="hidden"
                    >
                    <?php endif; ?>
                </div>

                <h2 class="text-xl font-bold text-gray-900 dark:text-white">
                    <?= e($currentUserData['name']) ?>
                </h2>

                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    <?= e($currentUserData['email'] ?? '') ?>
                </p>

                <div class="mt-4">
                    <span class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-300 border border-indigo-100 dark:border-indigo-800 text-xs font-bold">
                        <?= $roleIcon ?> <?= e($roleLabel) ?>
                    </span>
                </div>

                <?php if (!empty($currentUserData['jurusan'])): ?>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-4">
                    🎓 <?= e($currentUserData['jurusan']) ?>
                </p>
                <?php endif; ?>

                <?php if (!empty($currentUserData['sekolah'])): ?>
                <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                    <?= e($currentUserData['sekolah']) ?>
                </p>
                <?php endif; ?>

                <?php if ($hasBio && !empty($currentUserData['bio'])): ?>
                <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700">
                    <p class="text-sm text-gray-600 dark:text-gray-300 leading-relaxed">
                        <?= nl2br(e($currentUserData['bio'])) ?>
                    </p>
                </div>
                <?php endif; ?>

                <div class="mt-5 pt-4 border-t border-gray-100 dark:border-gray-700">
                    <p class="text-xs text-gray-400 dark:text-gray-500">📅 Bergabung sejak</p>
                    <p class="text-sm font-semibold text-gray-700 dark:text-gray-300 mt-1">
                        <?= e($joinDate) ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- STATISTIK -->
        <div class="profile-card p-5 profile-fade">
            <div class="flex items-center justify-between mb-4">
                <h3 class="font-bold text-gray-800 dark:text-white">Statistik Saya</h3>
                <span class="text-xl">📊</span>
            </div>

            <div class="grid grid-cols-3 gap-2 text-center">
                <div class="profile-stat">
                    <div class="text-2xl font-bold text-indigo-600 dark:text-indigo-400">
                        <?= $stats['jurnal'] ?>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Jurnal</div>
                </div>
                <div class="profile-stat">
                    <div class="text-2xl font-bold text-purple-600 dark:text-purple-400">
                        <?= $stats['tugas'] ?>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Tugas</div>
                </div>
                <div class="profile-stat">
                    <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                        <?= $stats['absen'] ?>
                    </div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">Hadir</div>
                </div>
            </div>

            <?php if ($stats['total_absen'] > 0): ?>
            <div class="mt-5">
                <div class="flex justify-between text-xs mb-2">
                    <span class="text-gray-500 dark:text-gray-400">Rasio Kehadiran</span>
                    <strong class="text-indigo-600 dark:text-indigo-400"><?= $kehadiran ?>%</strong>
                </div>
                <div class="h-2.5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                    <div class="h-full rounded-full bg-gradient-to-r from-indigo-600 via-purple-600 to-blue-500"
                         style="width:<?= $kehadiran ?>%"></div>
                </div>
                <p class="text-xs text-gray-400 mt-2">
                    <?= $stats['absen'] ?> dari <?= $stats['total_absen'] ?> catatan kehadiran.
                </p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- KOLOM KANAN -->
    <div class="lg:col-span-2">

        <div class="flex flex-wrap gap-2 mb-4">
            <button type="button" data-tab="profile"
                class="tab-btn profile-tab active px-5 py-2.5 rounded-xl text-sm font-semibold">
                👤 Informasi Profil
            </button>

            <?php if ($hasPassword): ?>
            <button type="button" data-tab="security"
                class="tab-btn profile-tab px-5 py-2.5 rounded-xl text-sm font-semibold text-gray-600 dark:text-gray-300 hover:bg-indigo-50 dark:hover:bg-indigo-900/20">
                🔐 Keamanan Akun
            </button>
            <?php endif; ?>
        </div>

        <!-- FORM PROFIL -->
        <div id="tab-profile" class="tab-content profile-card p-5 sm:p-7 profile-fade">
            <div class="mb-6">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Informasi Pribadi</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Perbarui informasi yang ditampilkan pada profil kamu.
                </p>
            </div>

            <form method="POST" id="profile-form">
                <input type="hidden" name="csrf" value="<?= e(profileCsrfToken()) ?>">
                <input type="hidden" name="action" value="update">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                            Nama Lengkap *
                        </label>
                        <input
                            type="text" name="name"
                            value="<?= e($currentUserData['name']) ?>"
                            required minlength="2" maxlength="100"
                            class="profile-input" placeholder="Masukkan nama lengkap">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">Email</label>
                        <input
                            type="email"
                            value="<?= e($currentUserData['email'] ?? '') ?>"
                            readonly
                            class="profile-input bg-gray-100 dark:bg-slate-700/50 cursor-not-allowed">
                        <p class="text-xs text-gray-400 mt-1">Email tidak dapat diubah.</p>
                    </div>
                </div>

                <?php if ($hasJurusan || $hasSekolah): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                    <?php if ($hasJurusan): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                            <?= $isPembimbing ? 'Bidang / Mata Pelajaran' : 'Jurusan' ?>
                        </label>
                        <input
                            type="text" name="jurusan"
                            value="<?= e($currentUserData['jurusan'] ?? '') ?>"
                            maxlength="50"
                            class="profile-input"
                            placeholder="<?= $isPembimbing ? 'Bidang keahlian' : 'Contoh: RPL' ?>">
                    </div>
                    <?php endif; ?>

                    <?php if ($hasSekolah): ?>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                            Asal Sekolah
                        </label>
                        <input
                            type="text" name="sekolah"
                            value="<?= e($currentUserData['sekolah'] ?? '') ?>"
                            maxlength="100"
                            class="profile-input"
                            placeholder="Nama sekolah">
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($hasBio): ?>
                <div class="mb-6">
                    <div class="flex justify-between items-center mb-2">
                        <label class="text-sm font-semibold text-gray-700 dark:text-gray-300">Tentang Saya</label>
                        <span id="bio-count" class="text-xs text-gray-400">0 / 300</span>
                    </div>
                    <textarea
                        name="bio" id="bio-input" rows="4" maxlength="300"
                        class="profile-input resize-none"
                        placeholder="Ceritakan sedikit tentang diri kamu..."
                    ><?= e($currentUserData['bio'] ?? '') ?></textarea>
                </div>
                <?php endif; ?>

                <?php if ($hasPhoto && !empty($currentUserData['photo'])): ?>
                <div class="mb-5 p-4 rounded-xl bg-red-50 dark:bg-red-900/10 border border-red-100 dark:border-red-900/30">
                    <label class="flex items-center gap-2 text-sm text-red-600 dark:text-red-400 cursor-pointer">
                        <input
                            type="checkbox" name="remove_photo" value="1"
                            id="remove-photo-check"
                            class="w-4 h-4 rounded accent-red-600">
                        Hapus foto profil
                    </label>
                </div>
                <?php endif; ?>

                <div class="flex flex-wrap justify-end gap-3 pt-5 border-t border-gray-100 dark:border-gray-700">
                    <a href="<?= rtrim(BASE_URL, '/') ?>/dashboard.php"
                        class="px-5 py-2.5 rounded-xl bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-sm font-semibold hover:bg-gray-200 dark:hover:bg-gray-600">
                        Batal
                    </a>
                    <button type="submit" class="profile-button px-6 py-2.5 rounded-xl text-sm font-semibold shadow-sm">
                        💾 Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>

        <!-- FORM PASSWORD -->
        <?php if ($hasPassword): ?>
        <div id="tab-security" class="tab-content hidden profile-card p-5 sm:p-7 profile-fade">
            <div class="mb-6">
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">Keamanan Akun</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                    Gunakan password yang kuat untuk menjaga keamanan akun.
                </p>
            </div>

            <form method="POST" id="password-form">
                <input type="hidden" name="csrf" value="<?= e(profileCsrfToken()) ?>">
                <input type="hidden" name="action" value="change_password">

                <div class="mb-5">
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                        Password Lama *
                    </label>
                    <input
                        type="password" name="old_password" required
                        autocomplete="current-password"
                        class="profile-input" placeholder="Masukkan password lama">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                            Password Baru *
                        </label>
                        <input
                            type="password" name="new_password" id="new-pwd"
                            required minlength="8"
                            autocomplete="new-password"
                            class="profile-input" placeholder="Minimal 8 karakter">

                        <div class="mt-3 h-2 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                            <div id="pwd-bar" class="h-full w-0 rounded-full transition-all" style="background:#ef4444"></div>
                        </div>
                        <p id="pwd-hint" class="text-xs text-gray-400 mt-2">
                            Minimal 8 karakter, terdiri dari huruf dan angka.
                        </p>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300 mb-2">
                            Konfirmasi Password *
                        </label>
                        <input
                            type="password" name="confirm_password" id="confirm-pwd"
                            required minlength="8"
                            autocomplete="new-password"
                            class="profile-input" placeholder="Ulangi password baru">
                        <p id="match-hint" class="text-xs mt-2"></p>
                    </div>
                </div>

                <div class="p-4 rounded-xl bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-100 dark:border-indigo-800 mb-6">
                    <p class="text-xs font-semibold text-indigo-700 dark:text-indigo-300 mb-2">
                        Tips keamanan password
                    </p>
                    <ul class="text-xs text-indigo-600 dark:text-indigo-300 space-y-1">
                        <li>✓ Minimal 8 karakter.</li>
                        <li>✓ Gunakan kombinasi huruf dan angka.</li>
                        <li>✓ Jangan gunakan password yang mudah ditebak.</li>
                    </ul>
                </div>

                <div class="flex justify-end pt-5 border-t border-gray-100 dark:border-gray-700">
                    <button type="submit" class="profile-button px-6 py-2.5 rounded-xl text-sm font-semibold">
                        🔐 Ubah Password
                    </button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- MODAL CROPPER -->
<?php if ($hasPhoto): ?>
<div id="cropper-modal" class="hidden fixed inset-0 bg-slate-950/80 z-[70] items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-800 rounded-2xl w-full max-w-lg shadow-2xl overflow-hidden">
        <div class="p-4 border-b border-gray-100 dark:border-gray-700 flex justify-between items-center">
            <div>
                <h3 class="font-bold text-gray-900 dark:text-white">✂️ Sesuaikan Foto</h3>
                <p class="text-xs text-gray-400 mt-1">Atur posisi foto sebelum menyimpan.</p>
            </div>
            <button type="button" onclick="closeCropper()" class="text-gray-400 hover:text-red-500 text-xl">✕</button>
        </div>

        <div class="p-4 bg-gray-100 dark:bg-slate-900 flex justify-center">
            <img id="cropper-image" class="max-w-full block" style="max-height:60vh" alt="Pratinjau foto">
        </div>

        <div class="p-4 flex justify-end gap-2 border-t border-gray-100 dark:border-gray-700">
            <button type="button" onclick="closeCropper()"
                class="px-4 py-2 text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 rounded-lg text-sm font-medium">
                Batal
            </button>
            <button type="button" onclick="saveCropped()"
                class="profile-button px-5 py-2 rounded-lg text-sm font-semibold">
                💾 Simpan Foto
            </button>
        </div>
    </div>
</div>

<!-- OVERLAY UPLOAD -->
<div id="upload-overlay" class="hidden fixed inset-0 bg-slate-950/60 z-[80] items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-800 rounded-2xl p-6 text-center w-full max-w-xs shadow-2xl">
        <div class="text-3xl mb-3">📤</div>
        <div class="font-semibold text-gray-800 dark:text-white mb-3" id="upload-status">
            Mengunggah foto...
        </div>
        <div class="h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
            <div id="upload-bar"
                class="h-full bg-gradient-to-r from-indigo-600 via-purple-600 to-blue-500 transition-all"
                style="width:0%"></div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// ==========================================
// KONFIGURASI
// ==========================================
const API_UPLOAD = <?= json_encode(
    rtrim(BASE_URL, '/') . '/api/upload_photo.php',
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
) ?>;

// ==========================================
// BIO COUNTER
// ==========================================
const bioInput = document.getElementById('bio-input');
const bioCount = document.getElementById('bio-count');

function updateBioCount() {
    if (!bioInput || !bioCount) return;
    bioCount.textContent = bioInput.value.length + ' / 300';
    bioCount.classList.toggle('text-red-500', bioInput.value.length >= 280);
}

if (bioInput) {
    bioInput.addEventListener('input', updateBioCount);
    updateBioCount();
}

// ==========================================
// TAB NAVIGATION
// ==========================================
document.querySelectorAll('.tab-btn').forEach(button => {
    button.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(tab => tab.classList.remove('active'));
        button.classList.add('active');
        document.querySelectorAll('.tab-content').forEach(content => content.classList.add('hidden'));

        const target = document.getElementById('tab-' + button.dataset.tab);
        if (target) target.classList.remove('hidden');
    });
});

// ==========================================
// PASSWORD STRENGTH
// ==========================================
const newPwd = document.getElementById('new-pwd');
const confirmPwd = document.getElementById('confirm-pwd');
const pwdBar = document.getElementById('pwd-bar');
const pwdHint = document.getElementById('pwd-hint');
const matchHint = document.getElementById('match-hint');

function scorePassword(password) {
    let score = 0;
    if (password.length >= 8) score++;
    if (password.length >= 12) score++;
    if (/[A-Z]/.test(password)) score++;
    if (/[a-z]/.test(password)) score++;
    if (/\d/.test(password)) score++;
    if (/[^A-Za-z0-9]/.test(password)) score++;
    return Math.min(score, 5);
}

function checkPasswordMatch() {
    if (!confirmPwd || !newPwd || !matchHint) return;

    if (!confirmPwd.value) {
        matchHint.textContent = '';
        return;
    }

    if (confirmPwd.value === newPwd.value) {
        matchHint.textContent = '✓ Password cocok';
        matchHint.className = 'text-xs mt-2 text-green-600';
    } else {
        matchHint.textContent = '✗ Password belum cocok';
        matchHint.className = 'text-xs mt-2 text-red-500';
    }
}

if (newPwd) {
    newPwd.addEventListener('input', () => {
        const score = scorePassword(newPwd.value);
        const colors = ['#ef4444','#ef4444','#f59e0b','#f59e0b','#22c55e','#16a34a'];
        const labels = ['Sangat lemah','Lemah','Cukup','Baik','Kuat','Sangat kuat'];

        pwdBar.style.width = (score / 5 * 100) + '%';
        pwdBar.style.background = colors[score];
        pwdHint.textContent = newPwd.value
            ? labels[score]
            : 'Minimal 8 karakter, terdiri dari huruf dan angka.';
        pwdHint.className = 'text-xs mt-2 ' +
            (score < 3 && newPwd.value ? 'text-red-500' : 'text-gray-400');

        checkPasswordMatch();
    });
}

if (confirmPwd) confirmPwd.addEventListener('input', checkPasswordMatch);

// ==========================================
// KONFIRMASI HAPUS FOTO
// ==========================================
document.getElementById('remove-photo-check')?.addEventListener('change', function() {
    if (this.checked && !confirm('Yakin ingin menghapus foto profil?')) {
        this.checked = false;
    }
});

// ==========================================
// CROPPER FOTO
// ==========================================
let cropper = null;

const photoInput = document.getElementById('photo-input');
const cropperModal = document.getElementById('cropper-modal');
const cropperImage = document.getElementById('cropper-image');

if (photoInput) {
    photoInput.addEventListener('change', function() {
        const file = this.files[0];
        if (!file) return;

        const allowed = ['image/jpeg', 'image/png', 'image/webp'];

        if (!allowed.includes(file.type)) {
            alert('Format foto harus JPG, PNG, atau WEBP.');
            this.value = '';
            return;
        }

        if (file.size > 5 * 1024 * 1024) {
            alert('Ukuran foto maksimal 5MB.');
            this.value = '';
            return;
        }

        if (typeof Cropper === 'undefined') {
            alert('Komponen pemotong foto gagal dimuat.');
            this.value = '';
            return;
        }

        const reader = new FileReader();

        reader.onload = event => {
            cropperImage.src = event.target.result;
            cropperModal.classList.remove('hidden');
            cropperModal.classList.add('flex');

            if (cropper) {
                cropper.destroy();
                cropper = null;
            }

            cropperImage.onload = () => {
                cropper = new Cropper(cropperImage, {
                    aspectRatio: 1,
                    viewMode: 1,
                    autoCropArea: 1,
                    movable: true,
                    zoomable: true,
                    scalable: false,
                    rotatable: false,
                    responsive: true
                });
            };
        };

        reader.readAsDataURL(file);
    });
}

function closeCropper() {
    if (cropper) {
        cropper.destroy();
        cropper = null;
    }
    cropperModal?.classList.add('hidden');
    cropperModal?.classList.remove('flex');
    if (photoInput) photoInput.value = '';
}

// ==========================================
// SIMPAN FOTO HASIL CROP
// ==========================================
async function saveCropped() {
    if (!cropper) return;

    const canvas = cropper.getCroppedCanvas({
        width: 400,
        height: 400,
        imageSmoothingQuality: 'high'
    });

    if (!canvas) {
        alert('Gagal memproses gambar.');
        return;
    }

    canvas.toBlob(blob => {
        if (!blob) {
            alert('Gagal memproses gambar.');
            return;
        }
        uploadProfilePhoto(blob);
    }, 'image/jpeg', 0.9);
}

// ==========================================
// UPLOAD FOTO
// ==========================================
function uploadProfilePhoto(blob) {
    closeCropper();

    const overlay = document.getElementById('upload-overlay');
    const bar = document.getElementById('upload-bar');
    const status = document.getElementById('upload-status');

    overlay.classList.remove('hidden');
    overlay.classList.add('flex');

    bar.style.width = '0%';
    status.textContent = 'Mengunggah foto...';

    const formData = new FormData();
    formData.append('photo', blob, 'avatar.jpg');

    const xhr = new XMLHttpRequest();
    xhr.open('POST', API_UPLOAD, true);
    xhr.withCredentials = true;

    xhr.upload.onprogress = event => {
        if (!event.lengthComputable) return;
        const percentage = Math.round(event.loaded / event.total * 100);
        bar.style.width = percentage + '%';
        status.textContent = 'Mengunggah ' + percentage + '%';
    };

    xhr.onload = () => {
        overlay.classList.add('hidden');
        overlay.classList.remove('flex');

        let response = {};

        try {
            response = JSON.parse(xhr.responseText);
        } catch (error) {
            alert('Server memberikan respons yang tidak valid.');
            return;
        }

        if (xhr.status >= 200 && xhr.status < 300 && response.status === 'success') {
            document.getElementById('avatar-preview').src = response.url;

            const removeCheck = document.getElementById('remove-photo-check');
            if (removeCheck) removeCheck.checked = false;

            alert('Foto profil berhasil diperbarui.');
        } else {
            alert('Upload gagal: ' + (response.message || 'Terjadi kesalahan.'));
        }
    };

    xhr.onerror = () => {
        overlay.classList.add('hidden');
        overlay.classList.remove('flex');
        alert('Koneksi gagal saat mengunggah foto.');
    };

    xhr.send(formData);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>