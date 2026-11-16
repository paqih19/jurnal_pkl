
<?php
// journal.php — Jurnal Kerja PKL
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/notifications.php';

requireLogin();

$user = currentUser();
$uid = (int) $user['id'];
$role = $user['role'];

$isPembimbing = in_array($role, ['pembimbing', 'teacher', 'admin'], true);

if (!defined('UPLOAD_DIR') || !defined('UPLOAD_URL')) {
    die('Konfigurasi upload belum tersedia.');
}

function journalCsrf(): string
{
    if (empty($_SESSION['csrf_journal'])) {
        $_SESSION['csrf_journal'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_journal'];
}

function journalVerifyCsrf(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf_journal'])
        && hash_equals($_SESSION['csrf_journal'], $_POST['csrf']);
}

function journalLength(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

function journalValidDate(string $date): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function journalImageExtension(string $tmp, int $size): ?string
{
    if (!is_uploaded_file($tmp) || $size <= 0 || $size > 5 * 1024 * 1024) {
        return null;
    }

    $info = @getimagesize($tmp);

    if (!$info) {
        return null;
    }

    $types = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp'
    ];

    return $types[$info[2]] ?? null;
}

function journalUploadPhotos(int $journalId, PDO $pdo): array
{
    $uploaded = [];

    if (!isset($_FILES['photos']['name']) || !is_array($_FILES['photos']['name'])) {
        return $uploaded;
    }

    $names = $_FILES['photos']['name'];

    if (count($names) > 8) {
        throw new RuntimeException('Maksimal 8 foto dalam satu jurnal.');
    }

    if (!is_dir(UPLOAD_DIR) && !mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
        throw new RuntimeException('Folder upload tidak dapat dibuat.');
    }

    if (!is_writable(UPLOAD_DIR)) {
        throw new RuntimeException('Folder upload tidak dapat ditulis.');
    }

    foreach ($names as $i => $name) {
        $error = $_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE;

        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }

        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Ada foto yang gagal diunggah.');
        }

        $tmp = $_FILES['photos']['tmp_name'][$i] ?? '';
        $size = (int) ($_FILES['photos']['size'][$i] ?? 0);
        $ext = journalImageExtension($tmp, $size);

        if (!$ext) {
            throw new RuntimeException('Format foto harus JPG, PNG, atau WEBP dan maksimal 5 MB.');
        }

        $filename = 'jurnal_' . $journalId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $path = rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . $filename;

        if (!move_uploaded_file($tmp, $path)) {
            throw new RuntimeException('Foto gagal dipindahkan ke folder upload.');
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO journal_photos (journal_id, filename) VALUES (?, ?)"
            );
            $stmt->execute([$journalId, $filename]);
            $uploaded[] = $filename;
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        }
    }

    return $uploaded;
}

function journalPhotoPath(string $filename): string
{
    return rtrim(UPLOAD_DIR, '/\\') . DIRECTORY_SEPARATOR . basename($filename);
}

function journalCanManage(array $journal, int $uid, bool $isPembimbing): bool
{
    return (int) $journal['user_id'] === $uid || $isPembimbing;
}

function journalDateLabel(?string $date): string
{
    if (!$date) {
        return '-';
    }

    if ($date === date('Y-m-d')) {
        return 'Hari Ini';
    }

    if ($date === date('Y-m-d', strtotime('-1 day'))) {
        return 'Kemarin';
    }

    return function_exists('tanggalIndo') ? tanggalIndo($date) : date('d-m-Y', strtotime($date));
}

// =====================================================
// PROSES FORM
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!journalVerifyCsrf()) {
        setFlash('error', 'Sesi kedaluwarsa. Silakan muat ulang halaman.');
        redirect('journal.php');
    }

    $action = $_POST['action'] ?? '';

    // ---------------- TAMBAH ----------------
    if ($action === 'add') {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $date = trim($_POST['activity_date'] ?? '');

        if (journalLength($title) < 3 || journalLength($title) > 100) {
            setFlash('error', 'Judul harus 3 sampai 100 karakter.');
            redirect('journal.php');
        }

        if ($content === '' || journalLength($content) > 2000) {
            setFlash('error', 'Deskripsi wajib diisi dan maksimal 2000 karakter.');
            redirect('journal.php');
        }

        if (!journalValidDate($date)) {
            setFlash('error', 'Tanggal kegiatan tidak valid.');
            redirect('journal.php');
        }

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                INSERT INTO journals
                    (user_id, title, content, activity_date, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");

            $stmt->execute([$uid, $title, $content, $date]);
            $journalId = (int) $pdo->lastInsertId();

            journalUploadPhotos($journalId, $pdo);

            $pdo->commit();

            try {
                notifyPembimbing(
                    $pdo,
                    'journal',
                    'Jurnal Baru: ' . $title,
                    'Oleh: ' . $user['name'] . ' • ' . date('d M Y', strtotime($date)),
                    'journal.php'
                );
            } catch (Throwable $e) {
                // Notifikasi tidak boleh membatalkan jurnal yang sudah tersimpan.
            }

            if (function_exists('logActivity')) {
                logActivity('journal_add', 'Jurnal: ' . $title);
            }

            setFlash('success', 'Jurnal berhasil ditambahkan!');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            setFlash('error', 'Gagal menyimpan jurnal: ' . $e->getMessage());
        }

        redirect('journal.php');
    }

    // ---------------- EDIT ----------------
    if ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $date = trim($_POST['activity_date'] ?? '');

        if (
            $id <= 0 ||
            journalLength($title) < 3 ||
            journalLength($title) > 100 ||
            $content === '' ||
            journalLength($content) > 2000 ||
            !journalValidDate($date)
        ) {
            setFlash('error', 'Data jurnal tidak valid.');
            redirect('journal.php');
        }

        try {
            $stmt = $pdo->prepare("SELECT * FROM journals WHERE id = ?");
            $stmt->execute([$id]);
            $journal = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$journal || !journalCanManage($journal, $uid, $isPembimbing)) {
                setFlash('error', 'Tidak memiliki akses mengedit jurnal ini.');
                redirect('journal.php');
            }

            $stmt = $pdo->prepare("
                UPDATE journals
                SET title = ?, content = ?, activity_date = ?
                WHERE id = ?
            ");

            $stmt->execute([$title, $content, $date, $id]);

            if (function_exists('logActivity')) {
                logActivity('journal_edit', 'Jurnal #' . $id);
            }

            setFlash('success', 'Jurnal berhasil diperbarui!');
        } catch (Throwable $e) {
            setFlash('error', 'Gagal mengedit jurnal: ' . $e->getMessage());
        }

        redirect('journal.php');
    }

    // ---------------- HAPUS ----------------
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);

        try {
            $stmt = $pdo->prepare("SELECT * FROM journals WHERE id = ?");
            $stmt->execute([$id]);
            $journal = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$journal || !journalCanManage($journal, $uid, $isPembimbing)) {
                setFlash('error', 'Tidak memiliki akses menghapus jurnal ini.');
                redirect('journal.php');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT filename FROM journal_photos WHERE journal_id = ?");
            $stmt->execute([$id]);
            $files = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $pdo->prepare("DELETE FROM journal_photos WHERE journal_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM journals WHERE id = ?")->execute([$id]);

            $pdo->commit();

            foreach ($files as $file) {
                $path = journalPhotoPath($file);
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            if (function_exists('logActivity')) {
                logActivity('journal_delete', 'Jurnal #' . $id);
            }

            setFlash('success', 'Jurnal berhasil dihapus.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            setFlash('error', 'Gagal menghapus jurnal: ' . $e->getMessage());
        }

        redirect('journal.php');
    }

    // ---------------- KOMENTAR PEMBIMBING ----------------
    if ($action === 'comment') {
        if (!$isPembimbing) {
            setFlash('error', 'Hanya pembimbing yang dapat memberikan komentar.');
            redirect('journal.php');
        }

        $id = (int) ($_POST['id'] ?? 0);
        $comment = trim($_POST['teacher_comment'] ?? '');

        if ($id <= 0 || $comment === '' || journalLength($comment) > 2000) {
            setFlash('error', 'Komentar wajib diisi dan maksimal 2000 karakter.');
            redirect('journal.php');
        }

        try {
            $stmt = $pdo->prepare("SELECT user_id, title FROM journals WHERE id = ?");
            $stmt->execute([$id]);
            $journal = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$journal) {
                setFlash('error', 'Jurnal tidak ditemukan.');
                redirect('journal.php');
            }

            $stmt = $pdo->prepare("
                UPDATE journals
                SET teacher_comment = ?, commented_by = ?, commented_at = NOW()
                WHERE id = ?
            ");

            $stmt->execute([$comment, $uid, $id]);

            try {
                createNotification(
                    $pdo,
                    (int) $journal['user_id'],
                    'journal_comment',
                    'Komentar Pembimbing',
                    'Pembimbing memberikan komentar pada jurnal: ' . $journal['title'],
                    'journal.php'
                );
            } catch (Throwable $e) {
                // Komentar tetap tersimpan jika notifikasi gagal.
            }

            if (function_exists('logActivity')) {
                logActivity('journal_comment', 'Komentar jurnal #' . $id);
            }

            setFlash('success', 'Komentar berhasil dikirim!');
        } catch (Throwable $e) {
            setFlash('error', 'Gagal mengirim komentar: ' . $e->getMessage());
        }

        redirect('journal.php');
    }
}

// =====================================================
// AMBIL JURNAL
// =====================================================
$journals = [];

try {
    $stmt = $pdo->query("
        SELECT
            j.*,
            u.name,
            u.photo AS user_photo,
            u.role,
            reviewer.name AS reviewer_name
        FROM journals j
        INNER JOIN users u ON u.id = j.user_id
        LEFT JOIN users reviewer ON reviewer.id = j.commented_by
        ORDER BY j.activity_date DESC, j.id DESC
    ");

    $journals = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    setFlash('error', 'Gagal memuat jurnal. Periksa struktur tabel database.');
}

// =====================================================
// FOTO JURNAL
// =====================================================
$photosByJournal = [];

if ($journals) {
    try {
        $ids = array_filter(array_map('intval', array_column($journals, 'id')));

        if ($ids) {
            $stmt = $pdo->query("
                SELECT journal_id, filename
                FROM journal_photos
                WHERE journal_id IN (" . implode(',', $ids) . ")
                ORDER BY id ASC
            ");

            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $photo) {
                $photosByJournal[(int) $photo['journal_id']][] = $photo['filename'];
            }
        }
    } catch (Throwable $e) {
        $photosByJournal = [];
    }
}

// =====================================================
// STATISTIK
// =====================================================
$totalJournals = count($journals);
$totalPhotos = 0;
$myJournals = 0;
$commentedJournals = 0;

foreach ($journals as $j) {
    $totalPhotos += count($photosByJournal[(int) $j['id']] ?? []);

    if ((int) $j['user_id'] === $uid) {
        $myJournals++;
    }

    if (!empty($j['teacher_comment'])) {
        $commentedJournals++;
    }
}

$csrf = journalCsrf();

require_once __DIR__ . '/includes/header.php';
?>

<style>
@keyframes journalFade {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
}
.journal-card { animation: journalFade .3s ease both; }
.gallery-img { transition: transform .3s ease; }
.gallery-img:hover { transform: scale(1.04); }
.drop-zone.dragover { border-color: #6366f1; background: rgba(99,102,241,.06); }
#lightbox { backdrop-filter: blur(8px); }
</style>

<div class="flex flex-col md:flex-row justify-between gap-4 mb-6">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">📸 Jurnal Aktivitas PKL</h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
            Dokumentasikan kegiatan dan perkembangan pekerjaan PKL.
        </p>
    </div>

    <?php if (!$isPembimbing): ?>
    <button onclick="toggleForm()" class="bg-primary hover:bg-indigo-700 text-white px-5 py-2.5 rounded-lg font-semibold shadow-sm">
        + Tulis Jurnal Baru
    </button>
    <?php endif; ?>
</div>

<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <p class="text-xs text-gray-500">📝 Total Jurnal</p>
        <p class="text-2xl font-bold text-gray-900 dark:text-white mt-1"><?= $totalJournals ?></p>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <p class="text-xs text-gray-500">📷 Total Foto</p>
        <p class="text-2xl font-bold text-blue-600 mt-1"><?= $totalPhotos ?></p>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <p class="text-xs text-gray-500">👤 Jurnal Saya</p>
        <p class="text-2xl font-bold text-purple-600 mt-1"><?= $myJournals ?></p>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <p class="text-xs text-gray-500">💬 Sudah Dikomentari</p>
        <p class="text-2xl font-bold text-green-600 mt-1"><?= $commentedJournals ?></p>
    </div>
</div>

<?php if (!$isPembimbing): ?>
<div id="form-add" class="hidden bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-sm p-6 mb-6">
    <div class="flex justify-between items-center mb-5">
        <h2 class="font-bold text-lg text-gray-900 dark:text-white">✍️ Buat Jurnal Baru</h2>
        <button type="button" onclick="toggleForm()" class="text-gray-400 text-xl">×</button>
    </div>

    <form method="POST" enctype="multipart/form-data" id="journal-form">
        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="add">

        <div class="grid md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-medium mb-2 text-gray-700 dark:text-gray-300">Judul Kegiatan *</label>
                <input name="title" required minlength="3" maxlength="100"
                    placeholder="Contoh: Membuat jaringan komputer"
                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-4 py-2.5">
            </div>

            <div>
                <label class="block text-sm font-medium mb-2 text-gray-700 dark:text-gray-300">Tanggal Kegiatan *</label>
                <input type="date" name="activity_date" value="<?= date('Y-m-d') ?>" required
                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-4 py-2.5">
            </div>
        </div>

        <div class="mb-4">
            <div class="flex justify-between mb-2">
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Deskripsi Kegiatan *</label>
                <span id="char-count" class="text-xs text-gray-400">0 / 2000</span>
            </div>
            <textarea name="content" id="content-input" rows="5" maxlength="2000" required
                placeholder="Jelaskan kegiatan, proses pekerjaan, hasil, dan kendala yang kamu alami..."
                class="w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-4 py-3 resize-y"></textarea>
        </div>

        <div class="mb-5">
            <label class="block text-sm font-medium mb-2 text-gray-700 dark:text-gray-300">Dokumentasi Foto</label>

            <div id="drop-zone" class="drop-zone border-2 border-dashed border-gray-300 dark:border-gray-600 rounded-xl p-7 text-center cursor-pointer hover:border-primary">
                <input type="file" name="photos[]" id="file-input" multiple accept="image/jpeg,image/png,image/webp" class="hidden">
                <div class="text-4xl mb-2">📷</div>
                <p class="text-sm font-medium text-gray-700 dark:text-gray-300">Klik atau tarik foto ke sini</p>
                <p class="text-xs text-gray-400 mt-1">JPG, PNG, WEBP • Maksimal 8 foto • 5 MB per foto</p>
            </div>

            <div id="preview-container" class="grid grid-cols-3 md:grid-cols-5 gap-2 mt-3"></div>
        </div>

        <div class="flex justify-end gap-2">
            <button type="button" onclick="toggleForm()" class="px-5 py-2.5 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">Batal</button>
            <button type="submit" class="px-6 py-2.5 rounded-lg bg-primary hover:bg-indigo-700 text-white font-semibold">Simpan Jurnal</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="bg-white dark:bg-gray-800 rounded-xl border border-gray-100 dark:border-gray-700 p-3 mb-6">
    <div class="flex flex-col md:flex-row gap-3">
        <div class="flex gap-1 bg-gray-100 dark:bg-gray-700 p-1 rounded-lg">
            <button data-tab="all" class="tab-btn px-4 py-2 rounded-md text-xs font-semibold bg-white dark:bg-gray-800 text-primary">Semua</button>
            <button data-tab="mine" class="tab-btn px-4 py-2 rounded-md text-xs font-semibold text-gray-600 dark:text-gray-300">Saya</button>
        </div>

        <input id="search-input" type="search" placeholder="Cari judul, deskripsi, atau nama..."
            class="flex-1 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 dark:text-white px-4 py-2 text-sm">

        <select id="sort-by" class="rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 dark:text-white px-3 py-2 text-sm">
            <option value="newest">Terbaru</option>
            <option value="oldest">Terlama</option>
            <option value="photos">Foto terbanyak</option>
        </select>
    </div>
</div>

<div id="journal-list" class="space-y-5">
<?php if (!$journals): ?>
    <div class="bg-white dark:bg-gray-800 rounded-2xl p-12 text-center border border-gray-100 dark:border-gray-700">
        <div class="text-5xl mb-4">📭</div>
        <h3 class="font-bold text-lg text-gray-900 dark:text-white">Belum ada jurnal</h3>
        <p class="text-sm text-gray-500 mt-2">Jurnal kegiatan PKL akan tampil di sini.</p>
    </div>
<?php endif; ?>

<?php foreach ($journals as $j):
    $jid = (int) $j['id'];
    $isMine = (int) $j['user_id'] === $uid;
    $photos = $photosByJournal[$jid] ?? [];
    $isAuthorPembimbing = in_array($j['role'], ['pembimbing', 'teacher', 'admin'], true);
    $photoUrls = array_map(
        fn($f) => rtrim(UPLOAD_URL, '/') . '/' . rawurlencode(basename($f)),
        $photos
    );
    $canEdit = $isMine;
    $canDelete = $isMine || $isPembimbing;
?>
    <article class="journal-card bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-sm overflow-hidden"
        data-title="<?= e(function_exists('mb_strtolower') ? mb_strtolower($j['title']) : strtolower($j['title'])) ?>"
        data-content="<?= e(function_exists('mb_strtolower') ? mb_strtolower($j['content'] ?? '') : strtolower($j['content'] ?? '')) ?>"
        data-name="<?= e(strtolower($j['name'])) ?>"
        data-user="<?= (int) $j['user_id'] ?>"
        data-photos="<?= count($photos) ?>"
        data-date="<?= e($j['activity_date']) ?>">

        <div class="px-5 pt-5 pb-3 flex items-center gap-3">
            <img src="<?= e(avatarUrl($j['user_photo'] ?? null, $j['name'] ?? 'User', 44)) ?>"
                class="w-11 h-11 rounded-full object-cover ring-2 ring-indigo-100 dark:ring-indigo-900" alt="Foto profil">

            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap gap-2 items-center">
                    <span class="font-semibold text-sm text-gray-900 dark:text-white"><?= e($j['name']) ?></span>

                    <?php if ($isAuthorPembimbing): ?>
                        <span class="text-[10px] bg-yellow-100 text-yellow-700 px-2 py-1 rounded font-bold">PEMBIMBING</span>
                    <?php endif; ?>

                    <?php if ($isMine): ?>
                        <span class="text-[10px] bg-indigo-100 text-indigo-700 px-2 py-1 rounded font-bold">ANDA</span>
                    <?php endif; ?>
                </div>

                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    <?= e(journalDateLabel($j['activity_date'])) ?>
                    • <?= e(date('H:i', strtotime($j['created_at']))) ?>
                </p>
            </div>

            <?php if ($canEdit || $canDelete): ?>
            <div class="relative">
                <button type="button" onclick="toggleMenu(<?= $jid ?>)" class="p-2 rounded-full hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-500">⋮</button>

                <div id="menu-<?= $jid ?>" class="hidden absolute right-0 top-9 z-20 min-w-[140px] rounded-lg bg-white dark:bg-gray-700 shadow-xl border border-gray-200 dark:border-gray-600 overflow-hidden">
                    <?php if ($canEdit): ?>
                    <button type="button"
                        onclick='openEditModal(<?= $jid ?>, <?= json_encode([
                            'title' => $j['title'],
                            'content' => $j['content'] ?? '',
                            'date' => $j['activity_date']
                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>)'
                        class="w-full text-left px-4 py-2 text-sm text-gray-700 dark:text-white hover:bg-gray-100 dark:hover:bg-gray-600">
                        ✏️ Edit
                    </button>
                    <?php endif; ?>

                    <?php if ($canDelete): ?>
                    <form method="POST" onsubmit="return confirm('Yakin ingin menghapus jurnal ini beserta fotonya?')">
                        <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $jid ?>">
                        <button class="w-full text-left px-4 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30">🗑️ Hapus</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="px-5 pb-4">
            <h2 class="text-lg font-bold text-gray-900 dark:text-white mb-2"><?= e($j['title']) ?></h2>
            <p class="text-sm leading-relaxed text-gray-700 dark:text-gray-300 whitespace-pre-wrap"><?= e($j['content'] ?? '') ?></p>
        </div>

        <?php if ($photos): ?>
        <div class="px-5 pb-4">
            <div class="grid grid-cols-2 md:grid-cols-3 gap-2">
                <?php foreach ($photos as $index => $photo):
                    if ($index >= 4) break;
                    $last = $index === 3 && count($photos) > 4;
                ?>
                <button type="button"
                    onclick='openLightbox(<?= json_encode($photoUrls, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>, <?= $index ?>)'
                    class="relative aspect-square overflow-hidden rounded-lg bg-gray-100 dark:bg-gray-700">
                    <img src="<?= e($photoUrls[$index]) ?>" loading="lazy" alt="Dokumentasi jurnal" class="gallery-img w-full h-full object-cover">
                    <?php if ($last): ?>
                        <span class="absolute inset-0 bg-black/60 text-white flex items-center justify-center font-bold text-2xl">+<?= count($photos) - 4 ?></span>
                    <?php endif; ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($j['teacher_comment'])): ?>
        <div class="mx-5 mb-4 p-4 rounded-xl bg-indigo-50 dark:bg-indigo-900/20 border border-indigo-100 dark:border-indigo-800">
            <div class="flex items-center gap-2 mb-2">
                <span>💬</span>
                <strong class="text-sm text-indigo-800 dark:text-indigo-300">Komentar Pembimbing</strong>
            </div>
            <p class="text-sm text-gray-700 dark:text-gray-300 whitespace-pre-wrap"><?= e($j['teacher_comment']) ?></p>
            <p class="text-xs text-gray-500 mt-2">
                <?= e($j['reviewer_name'] ?? 'Pembimbing') ?>
                <?php if (!empty($j['commented_at'])): ?>
                    • <?= e(date('d-m-Y H:i', strtotime($j['commented_at']))) ?>
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>

        <?php if ($isPembimbing): ?>
        <div class="mx-5 mb-4 border border-gray-200 dark:border-gray-700 rounded-xl p-4">
            <h3 class="text-sm font-bold text-gray-800 dark:text-white mb-3">✍️ Berikan Komentar</h3>
            <form method="POST">
                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <input type="hidden" name="action" value="comment">
                <input type="hidden" name="id" value="<?= $jid ?>">
                <textarea name="teacher_comment" rows="3" maxlength="2000" required
                    placeholder="Berikan masukan atau evaluasi untuk siswa..."
                    class="w-full rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-3 py-2 text-sm"><?= e($j['teacher_comment'] ?? '') ?></textarea>
                <div class="flex justify-end mt-2">
                    <button class="px-4 py-2 bg-primary hover:bg-indigo-700 text-white text-sm rounded-lg font-semibold">Kirim Komentar</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <div class="px-5 py-3 border-t border-gray-100 dark:border-gray-700 flex justify-between items-center text-xs text-gray-500 dark:text-gray-400">
            <span>📷 <?= count($photos) ?> foto dokumentasi</span>
            <button type="button" onclick="copyJournalLink(<?= $jid ?>)" class="text-primary hover:underline">Salin Link</button>
        </div>
    </article>
<?php endforeach; ?>
</div>

<!-- MODAL EDIT -->
<div id="edit-modal" class="hidden fixed inset-0 z-50 bg-black/60 items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl w-full max-w-lg p-6">
        <div class="flex justify-between items-center mb-5">
            <h3 class="font-bold text-lg text-gray-900 dark:text-white">Edit Jurnal</h3>
            <button type="button" onclick="closeEditModal()" class="text-gray-500 text-xl">×</button>
        </div>

        <form method="POST">
            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="id" id="edit-id">

            <label class="block text-sm mb-2 text-gray-700 dark:text-gray-300">Judul</label>
            <input type="text" name="title" id="edit-title" required minlength="3" maxlength="100"
                class="w-full mb-4 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-3 py-2">

            <label class="block text-sm mb-2 text-gray-700 dark:text-gray-300">Tanggal</label>
            <input type="date" name="activity_date" id="edit-date" required
                class="w-full mb-4 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-3 py-2">

            <label class="block text-sm mb-2 text-gray-700 dark:text-gray-300">Deskripsi</label>
            <textarea name="content" id="edit-content" rows="5" required maxlength="2000"
                class="w-full mb-5 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white px-3 py-2"></textarea>

            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeEditModal()" class="px-4 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300">Batal</button>
                <button class="px-5 py-2 rounded-lg bg-primary text-white font-semibold">Simpan Perubahan</button>
            </div>
        </form>
    </div>
</div>

<!-- LIGHTBOX -->
<div id="lightbox" class="hidden fixed inset-0 z-[60] bg-black/95 items-center justify-center p-4">
    <button type="button" onclick="closeLightbox()" class="absolute top-4 right-4 text-white text-3xl">×</button>
    <button type="button" onclick="navLightbox(-1)" class="absolute left-3 md:left-8 text-white text-4xl">‹</button>
    <img id="lightbox-img" class="max-w-full max-h-[85vh] object-contain rounded-lg" alt="Foto jurnal">
    <button type="button" onclick="navLightbox(1)" class="absolute right-3 md:right-8 text-white text-4xl">›</button>
    <div id="lightbox-counter" class="absolute bottom-5 text-white bg-black/50 rounded-full px-4 py-2 text-sm"></div>
</div>

<script>
let activeTab = 'all';
let lbImages = [];
let lbIndex = 0;
let filesBuffer = [];

function toastJournal(message, type = 'success') {
    if (typeof window.showToast === 'function') {
        window.showToast(message, type);
        return;
    }
    alert(message);
}

function toggleForm() {
    const form = document.getElementById('form-add');
    if (!form) return;

    form.classList.toggle('hidden');

    if (!form.classList.contains('hidden')) {
        form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        setTimeout(() => form.querySelector('[name="title"]')?.focus(), 100);
    }
}

const contentInput = document.getElementById('content-input');
const charCount = document.getElementById('char-count');

contentInput?.addEventListener('input', () => {
    charCount.textContent = contentInput.value.length + ' / 2000';
});

const fileInput = document.getElementById('file-input');
const dropZone = document.getElementById('drop-zone');
const previewContainer = document.getElementById('preview-container');

dropZone?.addEventListener('click', () => fileInput.click());

dropZone?.addEventListener('dragover', event => {
    event.preventDefault();
    dropZone.classList.add('dragover');
});

dropZone?.addEventListener('dragleave', () => {
    dropZone.classList.remove('dragover');
});

dropZone?.addEventListener('drop', event => {
    event.preventDefault();
    dropZone.classList.remove('dragover');
    addFiles(event.dataTransfer.files);
});

fileInput?.addEventListener('change', event => {
    addFiles(event.target.files);
});

function addFiles(files) {
    for (const file of Array.from(files)) {
        if (filesBuffer.length >= 8) {
            toastJournal('Maksimal 8 foto.', 'error');
            break;
        }

        if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
            toastJournal('Format foto harus JPG, PNG, atau WEBP.', 'error');
            continue;
        }

        if (file.size > 5 * 1024 * 1024) {
            toastJournal('Ukuran foto maksimal 5 MB.', 'error');
            continue;
        }

        filesBuffer.push(file);
    }

    renderPreviews();
    updateFileInput();
}

function renderPreviews() {
    previewContainer.innerHTML = '';

    filesBuffer.forEach((file, index) => {
        const url = URL.createObjectURL(file);
        const wrapper = document.createElement('div');
        wrapper.className = 'relative aspect-square rounded-lg overflow-hidden border border-gray-200';

        const img = document.createElement('img');
        img.src = url;
        img.className = 'w-full h-full object-cover';

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = '×';
        remove.className = 'absolute top-1 right-1 bg-red-500 text-white rounded-full w-6 h-6';
        remove.onclick = () => removeFile(index);

        wrapper.append(img, remove);
        previewContainer.appendChild(wrapper);
    });
}

function removeFile(index) {
    filesBuffer.splice(index, 1);
    renderPreviews();
    updateFileInput();
}

function updateFileInput() {
    const dt = new DataTransfer();
    filesBuffer.forEach(file => dt.items.add(file));
    fileInput.files = dt.files;
}

document.getElementById('journal-form')?.addEventListener('submit', event => {
    if (filesBuffer.length > 8) {
        event.preventDefault();
        toastJournal('Maksimal 8 foto.', 'error');
    }
});

function toggleMenu(id) {
    const menu = document.getElementById('menu-' + id);

    document.querySelectorAll('[id^="menu-"]').forEach(item => {
        if (item !== menu) item.classList.add('hidden');
    });

    menu?.classList.toggle('hidden');
}

document.addEventListener('click', event => {
    if (!event.target.closest('[id^="menu-"]') && !event.target.closest('button[onclick*="toggleMenu"]')) {
        document.querySelectorAll('[id^="menu-"]').forEach(item => item.classList.add('hidden'));
    }
});

function openEditModal(id, data) {
    document.getElementById('edit-id').value = id;
    document.getElementById('edit-title').value = data.title || '';
    document.getElementById('edit-content').value = data.content || '';
    document.getElementById('edit-date').value = data.date || '';

    const modal = document.getElementById('edit-modal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closeEditModal() {
    const modal = document.getElementById('edit-modal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

function openLightbox(images, index) {
    lbImages = images;
    lbIndex = index;
    updateLightbox();

    const box = document.getElementById('lightbox');
    box.classList.remove('hidden');
    box.classList.add('flex');
}

function updateLightbox() {
    if (!lbImages.length) return;

    document.getElementById('lightbox-img').src = lbImages[lbIndex];
    document.getElementById('lightbox-counter').textContent =
        (lbIndex + 1) + ' / ' + lbImages.length;
}

function navLightbox(direction) {
    if (!lbImages.length) return;

    lbIndex = (lbIndex + direction + lbImages.length) % lbImages.length;
    updateLightbox();
}

function closeLightbox() {
    const box = document.getElementById('lightbox');
    box.classList.add('hidden');
    box.classList.remove('flex');
}

document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
        closeLightbox();
        closeEditModal();
    }

    if (!document.getElementById('lightbox').classList.contains('hidden')) {
        if (event.key === 'ArrowLeft') navLightbox(-1);
        if (event.key === 'ArrowRight') navLightbox(1);
    }
});

function copyJournalLink(id) {
    const url = new URL('journal.php', window.location.href);
    url.searchParams.set('j', id);

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(url.toString())
            .then(() => toastJournal('Link jurnal berhasil disalin!'))
            .catch(() => toastJournal('Gagal menyalin link.', 'error'));
        return;
    }

    const input = document.createElement('textarea');
    input.value = url.toString();
    document.body.appendChild(input);
    input.select();

    try {
        document.execCommand('copy');
        toastJournal('Link jurnal berhasil disalin!');
    } catch (error) {
        toastJournal('Gagal menyalin link.', 'error');
    }

    input.remove();
}

document.querySelectorAll('.tab-btn').forEach(button => {
    button.addEventListener('click', () => {
        activeTab = button.dataset.tab;

        document.querySelectorAll('.tab-btn').forEach(item => {
            item.classList.remove('bg-white', 'dark:bg-gray-800', 'text-primary');
            item.classList.add('text-gray-600', 'dark:text-gray-300');
        });

        button.classList.add('bg-white', 'dark:bg-gray-800', 'text-primary');
        button.classList.remove('text-gray-600', 'dark:text-gray-300');

        filterJournals();
    });
});

function filterJournals() {
    const query = document.getElementById('search-input').value.toLowerCase().trim();
    const sort = document.getElementById('sort-by').value;
    const container = document.getElementById('journal-list');

    const cards = Array.from(container.querySelectorAll('.journal-card'));

    cards.forEach(card => {
        const matchesSearch =
            card.dataset.title.includes(query) ||
            card.dataset.content.includes(query) ||
            card.dataset.name.includes(query);

        const matchesTab =
            activeTab === 'all' ||
            card.dataset.user === '<?= $uid ?>';

        card.style.display = matchesSearch && matchesTab ? '' : 'none';
    });

    const visible = cards.filter(card => card.style.display !== 'none');

    visible.sort((a, b) => {
        if (sort === 'oldest') {
            return a.dataset.date.localeCompare(b.dataset.date);
        }

        if (sort === 'photos') {
            return Number(b.dataset.photos) - Number(a.dataset.photos);
        }

        return b.dataset.date.localeCompare(a.dataset.date);
    });

    visible.forEach(card => container.appendChild(card));
}

document.getElementById('search-input')?.addEventListener('input', filterJournals);
document.getElementById('sort-by')?.addEventListener('change', filterJournals);
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
