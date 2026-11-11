<?php
// journal.php
require_once __DIR__ . '/includes/header.php';
requireLogin();

$uid = (int)$user['id'];

// ============ PROSES TAMBAH ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $activity_date = $_POST['activity_date'] ?? date('Y-m-d');

    if ($title && $activity_date) {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO journals (user_id, title, content, activity_date, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$uid, $title, $content, $activity_date]);
            $journal_id = $pdo->lastInsertId();

            // Upload foto
            if (!empty($_FILES['photos']['name'][0])) {
                if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0777, true);
                $total_files = count($_FILES['photos']['name']);
                for ($i = 0; $i < $total_files; $i++) {
                    if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_OK) {
                        $tmp_name = $_FILES['photos']['tmp_name'][$i];
                        $name = $_FILES['photos']['name'][$i];
                        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                        if (in_array($ext, ['jpg','jpeg','png','webp'])) {
                            if ($_FILES['photos']['size'][$i] > 5 * 1024 * 1024) continue;
                            $new_filename = 'jurnal_' . $journal_id . '_' . time() . '_' . $i . '.' . $ext;
                            if (move_uploaded_file($tmp_name, UPLOAD_DIR . $new_filename)) {
                                $stmtPhoto = $pdo->prepare("INSERT INTO journal_photos (journal_id, filename) VALUES (?, ?)");
                                $stmtPhoto->execute([$journal_id, $new_filename]);
                            }
                        }
                    }
                }
            }
            $pdo->commit();
            setFlash('success', 'Jurnal berhasil disimpan!');
            redirect('journal.php');
        } catch (Exception $e) {
            $pdo->rollBack();
            setFlash('error', 'Gagal menyimpan jurnal: ' . $e->getMessage());
        }
    }
}

// ============ PROSES EDIT ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $id = (int)($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $activity_date = $_POST['activity_date'] ?? date('Y-m-d');

    $check = $pdo->prepare("SELECT user_id FROM journals WHERE id = ?");
    $check->execute([$id]);
    $owner = $check->fetchColumn();
    if ($owner == $uid && $title) {
        $stmt = $pdo->prepare("UPDATE journals SET title=?, content=?, activity_date=? WHERE id=?");
        $stmt->execute([$title, $content, $activity_date, $id]);
        setFlash('success', 'Jurnal diupdate!');
    }
    redirect('journal.php');
}

// ============ HAPUS ============
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("SELECT user_id FROM journals WHERE id = ?");
    $stmt->execute([$id]);
    $owner = $stmt->fetchColumn();

    if ($owner == $uid || $user['role'] === 'teacher') {
        $stmtPhotos = $pdo->prepare("SELECT filename FROM journal_photos WHERE journal_id = ?");
        $stmtPhotos->execute([$id]);
        foreach ($stmtPhotos->fetchAll() as $p) @unlink(UPLOAD_DIR . $p['filename']);
        $pdo->prepare("DELETE FROM journals WHERE id = ?")->execute([$id]);
        setFlash('success', 'Jurnal dihapus.');
    } else {
        setFlash('error', 'Tidak berhak menghapus.');
    }
    redirect('journal.php');
}

// ============ AMBIL DATA ============
$journals = $pdo->query("
    SELECT j.*, u.name, u.photo AS user_photo, u.role
    FROM journals j
    JOIN users u ON j.user_id = u.id
    ORDER BY j.activity_date DESC, j.created_at DESC
")->fetchAll();

$photosByJournal = [];
if ($journals) {
    $ids = implode(',', array_map('intval', array_column($journals, 'id')));
    $allPhotos = $pdo->query("SELECT journal_id, filename FROM journal_photos WHERE journal_id IN ($ids)")->fetchAll();
    foreach ($allPhotos as $p) $photosByJournal[$p['journal_id']][] = $p['filename'];
}

// Statistik
$totalJournals = count($journals);
$totalPhotos = array_sum(array_map(fn($p) => count($p), $photosByJournal));
$myJournals = count(array_filter($journals, fn($j) => $j['user_id'] == $uid));

// Helper: label tanggal
function relDate($date) {
    $today = date('Y-m-d');
    $yest = date('Y-m-d', strtotime('-1 day'));
    if ($date === $today) return 'Hari Ini';
    if ($date === $yest)  return 'Kemarin';
    return tanggalIndo($date);
}
?>

<style>
    @keyframes fadeIn { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
    .journal-card { animation: fadeIn .35s ease-out both; }
    .journal-card:nth-child(1){animation-delay:.02s} .journal-card:nth-child(2){animation-delay:.06s}
    .journal-card:nth-child(3){animation-delay:.10s} .journal-card:nth-child(4){animation-delay:.14s}
    .journal-card:nth-child(5){animation-delay:.18s}
    .gallery-img { transition: transform .3s ease; }
    .gallery-img:hover { transform: scale(1.05); }
    .drop-zone.dragover { border-color:#6366f1; background:rgba(99,102,241,.05); }
    .preview-thumb { animation: fadeIn .25s ease; }
    #lightbox { backdrop-filter: blur(8px); }
</style>

<!-- ============ HEADER ============ -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">📸 Jurnal Aktivitas</h1>
        <p class="text-gray-500 dark:text-gray-400 mt-1">Catat setiap kegiatan PKL kamu di sini.</p>
    </div>
    <button onclick="toggleForm()" class="bg-primary hover:bg-indigo-700 text-white px-5 py-2.5 rounded-lg font-medium transition-colors shadow-sm flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        Tulis Jurnal Baru
    </button>
</div>

<!-- ============ STATISTIK ============ -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-6">
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="flex items-center justify-between mb-1">
            <span class="text-xs text-gray-500 dark:text-gray-400">Total Jurnal</span>
            <span class="text-lg">📝</span>
        </div>
        <div class="text-2xl font-bold text-gray-800 dark:text-white"><?= $totalJournals ?></div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="flex items-center justify-between mb-1">
            <span class="text-xs text-gray-500 dark:text-gray-400">Total Foto</span>
            <span class="text-lg">📷</span>
        </div>
        <div class="text-2xl font-bold text-blue-600 dark:text-blue-400"><?= $totalPhotos ?></div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="flex items-center justify-between mb-1">
            <span class="text-xs text-gray-500 dark:text-gray-400">Jurnal Saya</span>
            <span class="text-lg">👤</span>
        </div>
        <div class="text-2xl font-bold text-purple-600 dark:text-purple-400"><?= $myJournals ?></div>
    </div>
</div>

<!-- ============ FORM TULIS JURNAL ============ -->
<div id="form-add" class="hidden bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 p-6 mb-6">
    <div class="flex items-center justify-between mb-4 border-b border-gray-100 dark:border-gray-700 pb-3">
        <h3 class="text-lg font-bold text-gray-800 dark:text-white">✍️ Buat Jurnal Baru</h3>
        <button onclick="toggleForm()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <form method="POST" enctype="multipart/form-data" id="journal-form">
        <input type="hidden" name="action" value="add">

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Judul Kegiatan *</label>
                <input type="text" name="title" required maxlength="100" placeholder="Contoh: Pengecekan Server Dinas" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-primary outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Tanggal Kegiatan *</label>
                <input type="date" name="activity_date" value="<?= date('Y-m-d') ?>" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-primary outline-none">
            </div>
        </div>

        <div class="mb-4">
            <div class="flex justify-between items-center mb-2">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">Deskripsi Kegiatan *</label>
                <span id="char-count" class="text-xs text-gray-400">0 / 2000</span>
            </div>
            <textarea name="content" id="content-input" rows="5" maxlength="2000" placeholder="Ceritakan detail pekerjaan atau kegiatan hari ini..." required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-primary outline-none resize-none"></textarea>
        </div>

        <!-- Upload Foto -->
        <div class="mb-6">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Upload Foto</label>
            <div id="drop-zone" class="drop-zone border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-xl p-6 text-center cursor-pointer hover:border-primary transition bg-gray-50 dark:bg-gray-700/30">
                <input type="file" name="photos[]" id="file-input" multiple accept="image/jpeg,image/png,image/webp" class="hidden">
                <svg class="w-12 h-12 mx-auto text-gray-400 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <p class="text-sm text-gray-600 dark:text-gray-300 font-medium">Klik atau tarik foto ke sini</p>
                <p class="text-xs text-gray-400 mt-1">Maksimal 5MB per foto • JPG, PNG, WEBP</p>
            </div>
            <div id="preview-container" class="grid grid-cols-3 md:grid-cols-5 gap-2 mt-3"></div>
        </div>

        <div class="flex justify-end gap-2">
            <button type="button" onclick="toggleForm()" class="px-5 py-2.5 text-gray-600 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 rounded-lg font-medium">Batal</button>
            <button type="submit" class="bg-primary hover:bg-indigo-700 text-white px-6 py-2.5 rounded-lg font-medium shadow-sm">Simpan Jurnal</button>
        </div>
    </form>
</div>

<!-- ============ PENCARIAN & FILTER ============ -->
<div class="bg-white dark:bg-gray-800 rounded-xl p-3 mb-6 border border-gray-100 dark:border-gray-700">
    <div class="flex flex-col md:flex-row gap-3 items-stretch md:items-center">
        <!-- Tab -->
        <div class="flex gap-1 bg-gray-100 dark:bg-gray-700 p-1 rounded-lg">
            <button data-tab="all" class="tab-btn px-3 py-1.5 text-xs font-semibold rounded-md bg-white dark:bg-gray-800 text-primary shadow-sm">Semua</button>
            <button data-tab="mine" class="tab-btn px-3 py-1.5 text-xs font-semibold rounded-md text-gray-600 dark:text-gray-300">Saya</button>
        </div>
        <!-- Pencarian -->
        <div class="flex-1 relative">
            <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" id="search-input" placeholder="Cari judul atau isi jurnal..." class="w-full pl-9 pr-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none dark:text-white">
        </div>
        <!-- Urutkan -->
        <select id="sort-by" class="px-3 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg text-sm dark:text-white outline-none">
            <option value="newest">Terbaru</option>
            <option value="oldest">Terlama</option>
            <option value="photos">Paling Banyak Foto</option>
        </select>
    </div>
</div>

<!-- ============ DAFTAR JURNAL ============ -->
<div id="journal-list" class="space-y-5">
    <?php if (empty($journals)): ?>
        <div class="bg-white dark:bg-gray-800 rounded-2xl p-12 text-center border border-gray-100 dark:border-gray-700">
            <div class="text-6xl mb-4">📭</div>
            <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-1">Belum ada jurnal</h3>
            <p class="text-gray-500 dark:text-gray-400 text-sm mb-4">Jadilah yang pertama menulis jurnal PKL!</p>
            <button onclick="toggleForm()" class="bg-primary hover:bg-indigo-700 text-white px-5 py-2 rounded-lg font-medium">+ Tulis Jurnal</button>
        </div>
    <?php endif; ?>

    <?php foreach ($journals as $j):
        $isMine = $j['user_id'] == $uid;
        $photos = $photosByJournal[$j['id']] ?? [];
    ?>
    <div class="journal-card bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-700 overflow-hidden"
         data-title="<?= e(strtolower($j['title'])) ?>"
         data-content="<?= e(strtolower($j['content'] ?? '')) ?>"
         data-user="<?= $j['user_id'] ?>"
         data-photos="<?= count($photos) ?>"
         data-date="<?= $j['activity_date'] ?>">

        <!-- Header: Penulis -->
        <div class="flex items-center gap-3 px-5 pt-4 pb-2">
            <img src="<?= !empty($j['user_photo']) ? UPLOAD_URL.$j['user_photo'] : 'https://ui-avatars.com/api/?name='.urlencode($j['name']).'&background=random&size=44' ?>"
                 class="w-10 h-10 rounded-full object-cover ring-2 ring-indigo-100 dark:ring-indigo-900">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                    <span class="font-semibold text-gray-800 dark:text-white text-sm truncate"><?= e($j['name']) ?></span>
                    <?php if ($j['role'] === 'teacher'): ?>
                        <span class="text-[10px] bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-400 font-bold px-1.5 py-0.5 rounded">GURU</span>
                    <?php endif; ?>
                    <?php if ($isMine): ?>
                        <span class="text-[10px] bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300 font-bold px-1.5 py-0.5 rounded">ANDA</span>
                    <?php endif; ?>
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400 flex items-center gap-1">
                    <span class="inline-block w-1.5 h-1.5 rounded-full bg-primary"></span>
                    <?= relDate($j['activity_date']) ?>
                    <span class="text-gray-300">•</span>
                    <?= date('H:i', strtotime($j['created_at'])) ?>
                </div>
            </div>

            <?php if ($isMine || $user['role'] === 'teacher'): ?>
            <div class="relative">
                <button onclick="event.stopPropagation();toggleMenu(<?= $j['id'] ?>)" class="text-gray-400 hover:bg-gray-100 dark:hover:bg-gray-700 p-1.5 rounded-full">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/></svg>
                </button>
                <div id="menu-<?= $j['id'] ?>" class="hidden absolute right-0 top-8 bg-white dark:bg-gray-700 rounded-lg shadow-lg border border-gray-200 dark:border-gray-600 overflow-hidden z-10 min-w-[140px]">
                    <button onclick="openEditModal(<?= $j['id'] ?>, <?= htmlspecialchars(json_encode(['title'=>$j['title'],'content'=>$j['content']??'','date'=>$j['activity_date']]), ENT_QUOTES) ?>)" class="w-full text-left px-4 py-2 text-sm hover:bg-gray-100 dark:hover:bg-gray-600 dark:text-gray-200">✏️ Edit</button>
                    <a href="?delete=<?= $j['id'] ?>" onclick="return confirm('Hapus jurnal ini beserta fotonya?')" class="block px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30">🗑️ Hapus</a>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Isi -->
        <div class="px-5 pb-3">
            <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2"><?= e($j['title']) ?></h3>
            <p class="text-gray-700 dark:text-gray-300 text-sm leading-relaxed whitespace-pre-wrap"><?= e($j['content']) ?></p>
        </div>

        <!-- Galeri Foto -->
        <?php if (!empty($photos)): ?>
        <div class="px-5 pb-4">
            <?php
            $count = count($photos);
            $gridClass = $count == 1 ? 'grid-cols-1' : ($count == 2 ? 'grid-cols-2' : ($count == 3 ? 'grid-cols-3' : 'grid-cols-2 md:grid-cols-3'));
            ?>
            <div class="grid <?= $gridClass ?> gap-1.5 rounded-xl overflow-hidden">
                <?php foreach ($photos as $idx => $foto):
                    if ($idx >= 4) break;
                    $isLast = ($idx === 3 && $count > 4);
                ?>
                    <a href="javascript:void(0)" onclick="openLightbox(<?= htmlspecialchars(json_encode(array_map(fn($f) => UPLOAD_URL.$f, $photos)), ENT_QUOTES) ?>, <?= $idx ?>)" class="relative group aspect-square overflow-hidden bg-gray-100 dark:bg-gray-700">
                        <img src="<?= UPLOAD_URL.$foto ?>" class="gallery-img w-full h-full object-cover" loading="lazy">
                        <?php if ($isLast): ?>
                            <div class="absolute inset-0 bg-black/60 flex items-center justify-center text-white font-bold text-2xl">+<?= $count - 4 ?></div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Keterangan Foto -->
        <div class="px-5 py-3 border-t border-gray-100 dark:border-gray-700 text-sm text-gray-500 dark:text-gray-400">
            <?php if (count($photos) > 0): ?>
            <span class="flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <?= count($photos) ?> foto
            </span>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ============ MODAL EDIT ============ -->
<div id="edit-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div class="flex justify-between items-center px-6 py-4 border-b border-gray-100 dark:border-gray-700 sticky top-0 bg-white dark:bg-gray-800">
            <h3 class="font-bold text-gray-800 dark:text-white">Edit Jurnal</h3>
            <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" class="p-6">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit-id">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Judul *</label>
                    <input type="text" name="title" id="edit-title" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-primary outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Tanggal *</label>
                    <input type="date" name="activity_date" id="edit-date" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-primary outline-none">
                </div>
            </div>
            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Deskripsi *</label>
                <textarea name="content" id="edit-content" rows="5" required class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-primary outline-none resize-none"></textarea>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeEditModal()" class="px-4 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 font-medium">Batal</button>
                <button type="submit" class="bg-primary hover:bg-indigo-700 text-white px-5 py-2 rounded-lg font-medium">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- ============ TAMPILAN FOTO BESAR ============ -->
<div id="lightbox" class="hidden fixed inset-0 bg-black/95 z-[60] items-center justify-center p-4">
    <button onclick="closeLightbox()" class="absolute top-4 right-4 text-white p-2 hover:bg-white/10 rounded-full z-10">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
    </button>
    <button onclick="navLightbox(-1)" class="absolute left-4 text-white p-3 bg-white/10 hover:bg-white/20 rounded-full">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </button>
    <img id="lightbox-img" class="max-w-full max-h-full object-contain rounded-lg">
    <button onclick="navLightbox(1)" class="absolute right-4 text-white p-3 bg-white/10 hover:bg-white/20 rounded-full">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    </button>
    <div id="lightbox-counter" class="absolute bottom-4 text-white text-sm bg-black/50 px-3 py-1 rounded-full"></div>
</div>

<!-- ============ NOTIFIKASI ============ -->
<div id="toast-container" class="fixed bottom-6 right-6 z-50 flex flex-col gap-2"></div>

<script>
let lbImages = [];
let lbIndex = 0;
let activeTab = 'all';

// ============ BUKA/TUTUP FORM ============
function toggleForm() {
    const f = document.getElementById('form-add');
    f.classList.toggle('hidden');
    if (!f.classList.contains('hidden')) {
        setTimeout(() => document.querySelector('[name="title"]').focus(), 100);
        f.scrollIntoView({behavior:'smooth', block:'start'});
    }
}

// ============ HITUNG KARAKTER ============
const contentInput = document.getElementById('content-input');
const charCount = document.getElementById('char-count');
contentInput.addEventListener('input', () => {
    charCount.textContent = contentInput.value.length + ' / 2000';
    charCount.classList.toggle('text-red-500', contentInput.value.length > 1900);
});

// ============ PRATINJAU FOTO ============
const fileInput = document.getElementById('file-input');
const dropZone = document.getElementById('drop-zone');
const previewContainer = document.getElementById('preview-container');
let filesBuffer = [];

dropZone.addEventListener('click', () => fileInput.click());
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('dragover');
    handleFiles(e.dataTransfer.files);
});
fileInput.addEventListener('change', () => handleFiles(fileInput.files));

function handleFiles(files) {
    for (const f of files) {
        if (!f.type.startsWith('image/')) continue;
        if (f.size > 5 * 1024 * 1024) { showToast('Foto terlalu besar: ' + f.name, 'error'); continue; }
        filesBuffer.push(f);
        addPreview(f);
    }
    syncFiles();
}

function addPreview(file) {
    const idx = filesBuffer.length - 1;
    const reader = new FileReader();
    reader.onload = e => {
        const div = document.createElement('div');
        div.className = 'preview-thumb relative aspect-square rounded-lg overflow-hidden bg-gray-100 group';
        div.dataset.idx = idx;
        div.innerHTML = `
            <img src="${e.target.result}" class="w-full h-full object-cover">
            <button type="button" onclick="removePreview(this)" class="absolute top-1 right-1 bg-red-500 text-white rounded-full w-5 h-5 flex items-center justify-center text-xs shadow-lg hover:bg-red-600">×</button>
        `;
        previewContainer.appendChild(div);
    };
    reader.readAsDataURL(file);
}

function removePreview(btn) {
    const idx = parseInt(btn.parentElement.dataset.idx);
    filesBuffer[idx] = null;
    btn.parentElement.remove();
    syncFiles();
}

function syncFiles() {
    const dt = new DataTransfer();
    filesBuffer.forEach(f => { if (f) dt.items.add(f); });
    fileInput.files = dt.files;
}

// ============ TAB & FILTER ============
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.onclick = () => {
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.classList.remove('bg-white','dark:bg-gray-800','text-primary','shadow-sm');
            b.classList.add('text-gray-600','dark:text-gray-300');
        });
        btn.classList.add('bg-white','dark:bg-gray-800','text-primary','shadow-sm');
        btn.classList.remove('text-gray-600','dark:text-gray-300');
        activeTab = btn.dataset.tab;
        filterJournals();
    };
});

document.getElementById('search-input').addEventListener('input', filterJournals);
document.getElementById('sort-by').addEventListener('change', filterJournals);

function filterJournals() {
    const q = document.getElementById('search-input').value.toLowerCase().trim();
    const sortBy = document.getElementById('sort-by').value;
    const cards = Array.from(document.querySelectorAll('.journal-card'));
    const myId = '<?= $uid ?>';

    cards.forEach(card => {
        const matchQ = !q || card.dataset.title.includes(q) || card.dataset.content.includes(q);
        const matchTab = activeTab === 'all' || card.dataset.user === myId;
        card.style.display = (matchQ && matchTab) ? '' : 'none';
    });

    const visible = cards.filter(c => c.style.display !== 'none');
    visible.sort((a, b) => {
        if (sortBy === 'photos') return b.dataset.photos - a.dataset.photos;
        const da = a.dataset.date, db = b.dataset.date;
        return sortBy === 'oldest' ? da.localeCompare(db) : db.localeCompare(da);
    });

    const container = document.getElementById('journal-list');
    visible.forEach(c => container.appendChild(c));
}

// ============ MENU TITIK TIGA ============
function toggleMenu(id) {
    document.querySelectorAll('[id^="menu-"]').forEach(m => {
        if (m.id !== 'menu-'+id) m.classList.add('hidden');
    });
    document.getElementById('menu-'+id).classList.toggle('hidden');
}
document.addEventListener('click', e => {
    if (!e.target.closest('[onclick*="toggleMenu"]')) {
        document.querySelectorAll('[id^="menu-"]').forEach(m => m.classList.add('hidden'));
    }
});

// ============ MODAL EDIT ============
function openEditModal(id, data) {
    document.getElementById('edit-id').value = id;
    document.getElementById('edit-title').value = data.title;
    document.getElementById('edit-content').value = data.content;
    document.getElementById('edit-date').value = data.date;
    document.getElementById('edit-modal').classList.remove('hidden');
    setTimeout(() => document.getElementById('edit-title').focus(), 100);
}
function closeEditModal() { document.getElementById('edit-modal').classList.add('hidden'); }
document.getElementById('edit-modal').addEventListener('click', e => {
    if (e.target.id === 'edit-modal') closeEditModal();
});

// ============ TAMPILAN FOTO BESAR ============
function openLightbox(images, idx) {
    lbImages = images;
    lbIndex = idx;
    updateLightbox();
    document.getElementById('lightbox').classList.remove('hidden');
    document.getElementById('lightbox').classList.add('flex');
}
function closeLightbox() {
    document.getElementById('lightbox').classList.add('hidden');
    document.getElementById('lightbox').classList.remove('flex');
}
function navLightbox(dir) {
    lbIndex = (lbIndex + dir + lbImages.length) % lbImages.length;
    updateLightbox();
}
function updateLightbox() {
    document.getElementById('lightbox-img').src = lbImages[lbIndex];
    document.getElementById('lightbox-counter').textContent = (lbIndex+1) + ' / ' + lbImages.length;
}
document.addEventListener('keydown', e => {
    if (document.getElementById('lightbox').classList.contains('hidden')) return;
    if (e.key === 'Escape') closeLightbox();
    if (e.key === 'ArrowLeft') navLightbox(-1);
    if (e.key === 'ArrowRight') navLightbox(1);
});

// ============ NOTIFIKASI ============
function showToast(msg, type = 'success') {
    const el = document.createElement('div');
    el.className = `px-4 py-3 rounded-lg shadow-lg text-white font-medium text-sm ${type === 'success' ? 'bg-green-500' : 'bg-red-500'}`;
    el.textContent = msg;
    el.style.animation = 'fadeIn .3s ease-out';
    document.getElementById('toast-container').appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 300); }, 2500);
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>