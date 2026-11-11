<?php
// profile.php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = currentUser();
$uid = (int)$user['id'];

// ============ DETEKSI KOLOM PASSWORD ============
$userCols = [];
try {
    foreach ($pdo->query("SHOW COLUMNS FROM users") as $c) {
        $userCols[$c['Field']] = true;
    }
} catch (Exception $e) {}
$hasPassword = isset($userCols['password']);

// ============ AMBIL DATA USER ============
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$uid]);
$currentUserData = $stmt->fetch();

$isPembimbing = in_array($currentUserData['role'], ['pembimbing','teacher']);

// ============ PROSES UPDATE PROFIL ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $name = trim($_POST['name'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $jurusan = trim($_POST['jurusan'] ?? '');
    $sekolah = trim($_POST['sekolah'] ?? '');

    $new_photo = $currentUserData['photo'];
    $error = null;

    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['photo']['tmp_name'];
        $filename = $_FILES['photo']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $error = 'Format foto harus JPG, PNG, atau WEBP.';
        } elseif ($_FILES['photo']['size'] > 5 * 1024 * 1024) {
            $error = 'Ukuran foto maksimal 5MB.';
        } else {
            if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0777, true);
            $new_photo = 'user_' . $uid . '_' . time() . '.' . $ext;
            if (move_uploaded_file($tmp_name, UPLOAD_DIR . $new_photo)) {
                if (!empty($currentUserData['photo']) && file_exists(UPLOAD_DIR . $currentUserData['photo'])) {
                    @unlink(UPLOAD_DIR . $currentUserData['photo']);
                }
            } else {
                $error = 'Gagal upload foto.';
            }
        }
    }

    if (isset($_POST['remove_photo']) && $_POST['remove_photo'] === '1') {
        if (!empty($currentUserData['photo']) && file_exists(UPLOAD_DIR . $currentUserData['photo'])) {
            @unlink(UPLOAD_DIR . $currentUserData['photo']);
        }
        $new_photo = null;
    }

    if (!$error) {
        $stmt = $pdo->prepare("UPDATE users SET name = ?, bio = ?, jurusan = ?, sekolah = ?, photo = ? WHERE id = ?");
        $stmt->execute([$name, $bio, $jurusan, $sekolah, $new_photo, $uid]);
        $_SESSION['name'] = $name;
        $_SESSION['photo'] = $new_photo;
        setFlash('success', 'Profil berhasil diperbarui!');
    } else {
        setFlash('error', $error);
    }
    redirect('profile.php');
}

// ============ PROSES GANTI PASSWORD ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password' && $hasPassword) {
    $old = $_POST['old_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (strlen($new) < 6) {
        setFlash('error', 'Password baru minimal 6 karakter.');
    } elseif ($new !== $confirm) {
        setFlash('error', 'Konfirmasi password tidak cocok.');
    } elseif (!password_verify($old, $currentUserData['password'])) {
        setFlash('error', 'Password lama salah.');
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hash, $uid]);
        setFlash('success', 'Password berhasil diubah!');
    }
    redirect('profile.php');
}

// ============ STATISTIK ============
$stats = ['jurnal'=>0, 'tugas'=>0, 'absen'=>0];
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM journals WHERE user_id = ?");
    $s->execute([$uid]); $stats['jurnal'] = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE created_by = ? AND status='done'");
    $s->execute([$uid]); $stats['tugas'] = (int)$s->fetchColumn();
} catch (Exception $e) {}
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE user_id = ? AND status='hadir'");
    $s->execute([$uid]); $stats['absen'] = (int)$s->fetchColumn();
} catch (Exception $e) {}

$joinDate = !empty($currentUserData['created_at']) ? date('F Y', strtotime($currentUserData['created_at'])) : '-';

$fotoUrl = !empty($currentUserData['photo'])
    ? UPLOAD_URL . $currentUserData['photo']
    : 'https://ui-avatars.com/api/?name=' . urlencode($currentUserData['name']) . '&background=EEF2FF&color=4F46E5&size=200';

// ============ WARNA BERDASARKAN ROLE ============
$roleColor = $isPembimbing
    ? [
        'gradient' => 'from-amber-500 to-orange-600',
        'bg-light' => 'bg-amber-50 dark:bg-amber-900/20',
        'text'     => 'text-amber-600 dark:text-amber-400',
        'border'   => 'border-amber-200 dark:border-amber-800',
        'label'    => '👨‍🏫 Pembimbing',
        'icon'     => '👨‍🏫',
        'ring'     => 'amber',
    ]
    : [
        'gradient' => 'from-indigo-500 to-purple-600',
        'bg-light' => 'bg-indigo-50 dark:bg-indigo-900/20',
        'text'     => 'text-indigo-600 dark:text-indigo-400',
        'border'   => 'border-indigo-200 dark:border-indigo-800',
        'label'    => '👤 Siswa PKL',
        'icon'     => '👤',
        'ring'     => 'indigo',
    ];

// ============ LOAD HEADER (SETELAH SEMUA REDIRECT) ============
require_once __DIR__ . '/includes/header.php';
?>

<style>
    @keyframes fadeUp { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
    .fade-up { animation: fadeUp .4s ease-out both; }
    .avatar-hover:hover .avatar-overlay { opacity: 1; }
    .avatar-overlay { transition: opacity .2s; }
</style>

<!-- HEADER -->
<div class="mb-6 fade-up">
    <h1 class="text-2xl font-bold text-gray-900 dark:text-white">
        <?= $roleColor['icon'] ?> Profil Saya
    </h1>
    <p class="text-gray-500 dark:text-gray-400 mt-1">
        <?= $isPembimbing ? 'Kelola profil pembimbing kamu.' : 'Perbarui info dan foto profil kamu.' ?>
    </p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    <!-- ============ KOLOM KIRI ============ -->
    <div class="lg:col-span-1 flex flex-col gap-6">

        <!-- Kartu Profil -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden fade-up">

            <!-- Banner gradient role -->
            <div class="h-24 bg-gradient-to-br <?= $roleColor['gradient'] ?> relative">
                <div class="absolute inset-0 opacity-20" style="background-image: radial-gradient(circle at 20% 30%, white 0%, transparent 50%), radial-gradient(circle at 80% 70%, white 0%, transparent 50%);"></div>
                <div class="absolute top-3 right-3 text-3xl opacity-40"><?= $roleColor['icon'] ?></div>
            </div>

            <!-- Avatar -->
            <div class="px-6 pb-6 text-center">
                <div class="relative inline-block -mt-16 mb-3 avatar-hover group">
                    <img id="avatar-preview" src="<?= $fotoUrl ?>"
                         class="w-32 h-32 rounded-full object-cover ring-4 ring-white dark:ring-gray-800 shadow-lg">
                    <label for="photo-input" class="avatar-overlay absolute inset-0 bg-black/50 rounded-full flex items-center justify-center opacity-0 cursor-pointer">
                        <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </label>
                    <input type="file" id="photo-input" accept="image/*" class="hidden" onchange="previewPhoto(this)">
                </div>

                <h2 class="text-lg font-bold text-gray-800 dark:text-white"><?= e($currentUserData['name']) ?></h2>
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-3"><?= e($currentUserData['email']) ?></p>

                <span class="inline-flex items-center gap-1 text-xs <?= $roleColor['bg-light'] ?> <?= $roleColor['text'] ?> px-3 py-1 rounded-full font-bold border <?= $roleColor['border'] ?>">
                    <?= $roleColor['label'] ?>
                </span>

                <?php if (!empty($currentUserData['jurusan'])): ?>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-3"><?= e($currentUserData['jurusan']) ?></p>
                <?php endif; ?>
                <?php if (!empty($currentUserData['sekolah'])): ?>
                    <p class="text-xs text-gray-400 dark:text-gray-500"><?= e($currentUserData['sekolah']) ?></p>
                <?php endif; ?>

                <div class="mt-4 pt-4 border-t border-gray-100 dark:border-gray-700 text-xs text-gray-400 dark:text-gray-500">
                    📅 Bergabung sejak <?= $joinDate ?>
                </div>
            </div>
        </div>

        <!-- Statistik -->
        <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 p-5 fade-up">
            <h3 class="font-bold text-gray-800 dark:text-white text-sm mb-4">📊 Statistik Saya</h3>
            <div class="grid grid-cols-3 gap-2 text-center">
                <div>
                    <div class="text-2xl font-bold <?= $roleColor['text'] ?>"><?= $stats['jurnal'] ?></div>
                    <div class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">Jurnal</div>
                </div>
                <div>
                    <div class="text-2xl font-bold <?= $roleColor['text'] ?>"><?= $stats['tugas'] ?></div>
                    <div class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">Tugas</div>
                </div>
                <div>
                    <div class="text-2xl font-bold <?= $roleColor['text'] ?>"><?= $stats['absen'] ?></div>
                    <div class="text-[10px] text-gray-500 dark:text-gray-400 mt-1">Hadir</div>
                </div>
            </div>
        </div>

    </div>

    <!-- ============ KOLOM KANAN: FORM ============ -->
    <div class="lg:col-span-2">

        <!-- Tab -->
        <div class="flex gap-2 mb-4">
            <button data-tab="profile" class="tab-btn px-5 py-2 rounded-xl text-sm font-semibold bg-white dark:bg-gray-800 <?= $roleColor['text'] ?> shadow-sm border border-gray-100 dark:border-gray-700">
                Profil
            </button>
            <?php if ($hasPassword): ?>
            <button data-tab="security" class="tab-btn px-5 py-2 rounded-xl text-sm font-semibold text-gray-600 dark:text-gray-300 hover:bg-white dark:hover:bg-gray-800">
                Ganti Password
            </button>
            <?php endif; ?>
        </div>

        <!-- TAB: PROFIL -->
        <div id="tab-profile" class="tab-content bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 p-6 fade-up">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="update">
                <input type="file" name="photo" id="photo-form-input" accept="image/*" class="hidden">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Nama Lengkap *</label>
                        <input type="text" name="name" value="<?= e($currentUserData['name']) ?>" required maxlength="100"
                               class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-<?= $roleColor['ring'] ?>-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Email (tidak bisa diubah)</label>
                        <input type="text" value="<?= e($currentUserData['email']) ?>" readonly
                               class="w-full px-4 py-2 border border-gray-200 dark:border-gray-600 bg-gray-100 dark:bg-gray-700/50 text-gray-500 dark:text-gray-400 rounded-lg cursor-not-allowed">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-5">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                            <?= $isPembimbing ? 'Bidang / Mapel' : 'Jurusan' ?>
                        </label>
                        <input type="text" name="jurusan" value="<?= e($currentUserData['jurusan']) ?>"
                               placeholder="<?= $isPembimbing ? 'Contoh: RPL / Matematika' : 'Contoh: RPL' ?>"
                               maxlength="50"
                               class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-<?= $roleColor['ring'] ?>-500 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Asal Sekolah</label>
                        <input type="text" name="sekolah" value="<?= e($currentUserData['sekolah']) ?>"
                               placeholder="Contoh: SMK N 1 Contoh" maxlength="100"
                               class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-<?= $roleColor['ring'] ?>-500 outline-none">
                    </div>
                </div>

                <div class="mb-6">
                    <div class="flex justify-between items-center mb-2">
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Tentang Saya</label>
                        <span class="text-xs text-gray-400" id="bio-count">0 / 300</span>
                    </div>
                    <textarea name="bio" id="bio-input" rows="4" maxlength="300"
                              placeholder="<?= $isPembimbing ? 'Ceritakan pengalaman mengajar / membimbing...' : 'Ceritakan sedikit tentang kamu...' ?>"
                              class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-<?= $roleColor['ring'] ?>-500 outline-none resize-none"><?= e($currentUserData['bio']) ?></textarea>
                </div>

                <?php if (!empty($currentUserData['photo'])): ?>
                <div class="mb-4">
                    <label class="flex items-center gap-2 text-sm text-red-600 dark:text-red-400 cursor-pointer">
                        <input type="checkbox" name="remove_photo" value="1" class="w-4 h-4 rounded">
                        Hapus foto profil (kembali ke inisial)
                    </label>
                </div>
                <?php endif; ?>

                <div class="flex justify-end gap-2 pt-4 border-t border-gray-100 dark:border-gray-700">
                    <a href="<?= BASE_URL ?>/dashboard.php" class="px-5 py-2.5 text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 rounded-lg font-medium">Batal</a>
                    <button type="submit" class="bg-gradient-to-r <?= $roleColor['gradient'] ?> text-white px-6 py-2.5 rounded-lg font-medium shadow-sm hover:shadow-md transition">
                        Simpan
                    </button>
                </div>
            </form>
        </div>

        <!-- TAB: GANTI PASSWORD -->
        <?php if ($hasPassword): ?>
        <div id="tab-security" class="tab-content hidden bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 p-6 fade-up">
            <form method="POST">
                <input type="hidden" name="action" value="change_password">

                <div class="mb-5">
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Password Lama *</label>
                    <input type="password" name="old_password" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-primary outline-none">
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Password Baru *</label>
                        <input type="password" name="new_password" required minlength="6" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-primary outline-none">
                        <p class="text-xs text-gray-400 mt-1">Minimal 6 karakter</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Konfirmasi Password *</label>
                        <input type="password" name="confirm_password" required minlength="6" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-primary outline-none">
                    </div>
                </div>
                <div class="flex justify-end pt-4 border-t border-gray-100 dark:border-gray-700">
                    <button type="submit" class="bg-gradient-to-r from-primary to-purple-600 text-white px-6 py-2.5 rounded-lg font-medium shadow-sm">Ubah Password</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
// Preview foto
function previewPhoto(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];

    if (file.size > 5 * 1024 * 1024) {
        alert('Foto terlalu besar. Maksimal 5MB.');
        input.value = '';
        return;
    }

    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('avatar-preview').src = e.target.result;
    };
    reader.readAsDataURL(file);

    const formInput = document.getElementById('photo-form-input');
    const dt = new DataTransfer();
    dt.items.add(file);
    formInput.files = dt.files;
}

// Hitung karakter bio
const bioInput = document.getElementById('bio-input');
const bioCount = document.getElementById('bio-count');
function updateBioCount() {
    bioCount.textContent = bioInput.value.length + ' / 300';
    bioCount.classList.toggle('text-red-500', bioInput.value.length > 280);
}
bioInput.addEventListener('input', updateBioCount);
updateBioCount();

// Tab switch
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.onclick = () => {
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.classList.remove('bg-white','dark:bg-gray-800','shadow-sm','border','border-gray-100','dark:border-gray-700','text-amber-600','text-indigo-600','dark:text-amber-400','dark:text-indigo-400');
            b.classList.add('text-gray-600','dark:text-gray-300');
        });
        btn.classList.add('bg-white','dark:bg-gray-800','shadow-sm','border','border-gray-100','dark:border-gray-700','<?= $roleColor['text'] ?>');
        btn.classList.remove('text-gray-600','dark:text-gray-300');

        document.querySelectorAll('.tab-content').forEach(c => c.classList.add('hidden'));
        document.getElementById('tab-' + btn.dataset.tab).classList.remove('hidden');
    };
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>