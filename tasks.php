
<?php
// tasks.php
require_once __DIR__ . '/includes/auth.php';
requireLogin();
require_once __DIR__ . '/includes/activity.php';

$user = currentUser();
$uid  = (int)($user['id'] ?? 0);
$role = $user['role'] ?? '';

$isPembimbing = in_array($role, ['pembimbing', 'teacher', 'admin'], true);
$today = date('Y-m-d');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// =====================================================
// HELPER
// =====================================================
if (!function_exists('taskEscape')) {
    function taskEscape($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

function taskCsrfToken(): string {
    if (empty($_SESSION['csrf_tasks'])) {
        $_SESSION['csrf_tasks'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_tasks'];
}

function taskVerifyCsrf(): bool {
    return isset($_POST['csrf'], $_SESSION['csrf_tasks'])
        && hash_equals($_SESSION['csrf_tasks'], $_POST['csrf']);
}

function taskRedirect(): never {
    redirect('tasks.php');
}

function taskFlashError(string $message): never {
    setFlash('error', $message);
    taskRedirect();
}

function taskFlashSuccess(string $message): never {
    setFlash('success', $message);
    taskRedirect();
}

// =====================================================
// DETEKSI STRUKTUR TABEL
// =====================================================
$taskCols = [];

try {
    foreach ($pdo->query("SHOW COLUMNS FROM tasks")->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $taskCols[$column['Field']] = true;
    }
} catch (Throwable $e) {
    error_log('Tasks schema error: ' . $e->getMessage());
    exit('Tabel tasks belum tersedia atau tidak dapat dibaca.');
}

$requiredColumns = ['id', 'title', 'created_by', 'status'];

foreach ($requiredColumns as $column) {
    if (!isset($taskCols[$column])) {
        exit('Struktur tabel tasks belum sesuai. Kolom ' . taskEscape($column) . ' tidak ditemukan.');
    }
}

$hasDescription = isset($taskCols['description']);
$hasTeacher     = isset($taskCols['teacher_name']);
$hasDeadline    = isset($taskCols['deadline']);
$hasCreatedAt   = isset($taskCols['created_at']);
$hasUpdatedAt   = isset($taskCols['updated_at']);

// =====================================================
// VALIDASI INPUT
// =====================================================
function validateTaskInput(array $post, array $columns): array {
    $title = trim($post['title'] ?? '');
    $desc = trim($post['description'] ?? '');
    $teacher = trim($post['teacher_name'] ?? '');
    $deadline = trim($post['deadline'] ?? '');
    $status = $post['status'] ?? 'todo';

    $errors = [];

    if (mb_strlen($title) < 3) {
        $errors[] = 'Judul tugas minimal 3 karakter.';
    }

    if (mb_strlen($title) > 150) {
        $errors[] = 'Judul tugas maksimal 150 karakter.';
    }

    if (mb_strlen($desc) > 2000) {
        $errors[] = 'Deskripsi maksimal 2000 karakter.';
    }

    if (mb_strlen($teacher) > 100) {
        $errors[] = 'Nama pembimbing maksimal 100 karakter.';
    }

    if ($deadline !== '') {
        $date = DateTime::createFromFormat('Y-m-d', $deadline);

        if (!$date || $date->format('Y-m-d') !== $deadline) {
            $errors[] = 'Tanggal deadline tidak valid.';
        }
    }

    if (!in_array($status, ['todo', 'doing', 'done'], true)) {
        $errors[] = 'Status tugas tidak valid.';
    }

    return [
        'ok' => empty($errors),
        'errors' => $errors,
        'data' => [
            'title' => $title,
            'description' => $desc,
            'teacher_name' => $teacher,
            'deadline' => $deadline ?: null,
            'status' => $status
        ]
    ];
}

// =====================================================
// CEK HAK AKSES TUGAS
// =====================================================
function taskCanManage(array $task, int $uid, bool $isPembimbing): bool {
    return $isPembimbing || (int)$task['created_by'] === $uid;
}

// =====================================================
// TAMBAH TUGAS
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {

    if (!taskVerifyCsrf()) {
        taskFlashError('Sesi kedaluwarsa. Silakan coba lagi.');
    }

    $validation = validateTaskInput($_POST, $taskCols);

    if (!$validation['ok']) {
        taskFlashError(implode(' ', $validation['errors']));
    }

    $data = $validation['data'];

    $fields = ['title', 'created_by', 'status'];
    $values = [
        $data['title'],
        $uid,
        $data['status']
    ];

    if ($hasDescription) {
        $fields[] = 'description';
        $values[] = $data['description'];
    }

    if ($hasTeacher) {
        $fields[] = 'teacher_name';
        $values[] = $data['teacher_name'];
    }

    if ($hasDeadline) {
        $fields[] = 'deadline';
        $values[] = $data['deadline'];
    }

    if ($hasCreatedAt) {
        $fields[] = 'created_at';
        $values[] = date('Y-m-d H:i:s');
    }

    $placeholders = implode(', ', array_fill(0, count($fields), '?'));
    $fieldList = implode(', ', $fields);

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO tasks ($fieldList) VALUES ($placeholders)"
        );

        $stmt->execute($values);

        if (function_exists('logActivity')) {
            logActivity('task_add', 'Menambahkan tugas: ' . $data['title']);
        }

        taskFlashSuccess('Tugas berhasil ditambahkan!');
    } catch (Throwable $e) {
        error_log('Task add error: ' . $e->getMessage());
        taskFlashError('Tugas gagal ditambahkan. Periksa kembali database.');
    }
}

// =====================================================
// EDIT TUGAS
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {

    if (!taskVerifyCsrf()) {
        taskFlashError('Sesi kedaluwarsa. Silakan coba lagi.');
    }

    $id = (int)($_POST['id'] ?? 0);
    $validation = validateTaskInput($_POST, $taskCols);

    if (!$id || !$validation['ok']) {
        taskFlashError(
            implode(' ', $validation['errors']) ?: 'Data tugas tidak valid.'
        );
    }

    $check = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
    $check->execute([$id]);
    $task = $check->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        taskFlashError('Tugas tidak ditemukan.');
    }

    if (!taskCanManage($task, $uid, $isPembimbing)) {
        taskFlashError('Kamu tidak memiliki akses untuk mengedit tugas ini.');
    }

    $data = $validation['data'];

    $sets = ['title = ?', 'status = ?'];
    $params = [$data['title'], $data['status']];

    if ($hasDescription) {
        $sets[] = 'description = ?';
        $params[] = $data['description'];
    }

    if ($hasTeacher) {
        $sets[] = 'teacher_name = ?';
        $params[] = $data['teacher_name'];
    }

    if ($hasDeadline) {
        $sets[] = 'deadline = ?';
        $params[] = $data['deadline'];
    }

    if ($hasUpdatedAt) {
        $sets[] = 'updated_at = NOW()';
    }

    $params[] = $id;

    try {
        $stmt = $pdo->prepare(
            'UPDATE tasks SET ' . implode(', ', $sets) . ' WHERE id = ?'
        );

        $stmt->execute($params);

        if (function_exists('logActivity')) {
            logActivity('task_edit', "Mengedit tugas #{$id}: {$data['title']}");
        }

        taskFlashSuccess('Tugas berhasil diperbarui!');
    } catch (Throwable $e) {
        error_log('Task edit error: ' . $e->getMessage());
        taskFlashError('Tugas gagal diperbarui.');
    }
}

// =====================================================
// HAPUS TUGAS
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {

    if (!taskVerifyCsrf()) {
        taskFlashError('Sesi kedaluwarsa. Silakan coba lagi.');
    }

    $id = (int)($_POST['id'] ?? 0);

    if (!$id) {
        taskFlashError('ID tugas tidak valid.');
    }

    $check = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
    $check->execute([$id]);
    $task = $check->fetch(PDO::FETCH_ASSOC);

    if (!$task) {
        taskFlashError('Tugas tidak ditemukan.');
    }

    if (!taskCanManage($task, $uid, $isPembimbing)) {
        taskFlashError('Kamu tidak memiliki akses untuk menghapus tugas ini.');
    }

    try {
        $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([$id]);

        if (function_exists('logActivity')) {
            logActivity('task_delete', "Menghapus tugas #{$id}");
        }

        taskFlashSuccess('Tugas berhasil dihapus.');
    } catch (Throwable $e) {
        error_log('Task delete error: ' . $e->getMessage());
        taskFlashError('Tugas gagal dihapus. Data mungkin masih digunakan.');
    }
}

// =====================================================
// AMBIL DATA TUGAS
// =====================================================
$order = "CASE status
    WHEN 'todo' THEN 1
    WHEN 'doing' THEN 2
    WHEN 'done' THEN 3
    ELSE 4 END";

if ($hasDeadline) {
    $order .= ", deadline IS NULL, deadline ASC";
}

if ($hasCreatedAt) {
    $order .= ", created_at DESC";
}

$stmt = $pdo->query("SELECT * FROM tasks ORDER BY $order");
$tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =====================================================
// STATISTIK
// =====================================================
$stats = [
    'total' => count($tasks),
    'todo' => 0,
    'doing' => 0,
    'done' => 0,
    'terlewat' => 0,
    'hari_ini' => 0
];

foreach ($tasks as $task) {
    $status = $task['status'] ?? 'todo';

    if (isset($stats[$status])) {
        $stats[$status]++;
    }

    $deadline = $task['deadline'] ?? null;

    if ($status !== 'done' && $deadline) {
        if ($deadline < $today) {
            $stats['terlewat']++;
        } elseif ($deadline === $today) {
            $stats['hari_ini']++;
        }
    }
}

$progress = $stats['total'] > 0
    ? (int)round(($stats['done'] / $stats['total']) * 100)
    : 0;

// =====================================================
// HELPER TAMPILAN
// =====================================================
function taskPriority(array $task, string $today): array {
    if (($task['status'] ?? '') === 'done') {
        return ['Selesai', 'indigo', '✓'];
    }

    $deadline = $task['deadline'] ?? '';

    if (!$deadline) {
        return ['Tanpa Tenggat', 'slate', '—'];
    }

    $days = (int)((strtotime($deadline) - strtotime($today)) / 86400);

    if ($days < 0) return ['Terlewat', 'red', '!'];
    if ($days === 0) return ['Hari Ini', 'orange', '•'];
    if ($days <= 2) return ['Mendesak', 'orange', '•'];
    if ($days <= 7) return ['Segera', 'purple', '•'];

    return ['Normal', 'blue', '•'];
}

function taskDeadlineLabel(array $task, string $today): string {
    $deadline = $task['deadline'] ?? '';

    if (!$deadline) return 'Tanpa deadline';

    $days = (int)((strtotime($deadline) - strtotime($today)) / 86400);

    if ($days < 0) return abs($days) . ' hari terlambat';
    if ($days === 0) return 'Deadline hari ini';
    if ($days === 1) return 'Deadline besok';

    return $days . ' hari lagi';
}

function taskStatusLabel(string $status): string {
    return match ($status) {
        'todo' => 'Belum Dikerjakan',
        'doing' => 'Sedang Dikerjakan',
        'done' => 'Selesai',
        default => 'Tidak diketahui'
    };
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
.task-card {
    transition: transform .2s ease, box-shadow .2s ease;
}
.task-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 12px 25px rgba(79,70,229,.09);
}
.task-gradient {
    background: linear-gradient(135deg,#4F46E5 0%,#9333EA 55%,#3B82F6 100%);
}
.task-soft {
    background: linear-gradient(135deg,#EEF2FF 0%,#FAF5FF 50%,#EFF6FF 100%);
}
.dark .task-soft {
    background: linear-gradient(135deg,#1E1B4B 0%,#312E81 55%,#172554 100%);
}
.kanban-col {
    min-height: 280px;
}
.drag-over {
    background: rgba(99,102,241,.10);
    outline: 2px dashed #6366f1;
}
.dragging {
    opacity: .45;
}
.line-clamp-2 {
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
</style>

<div class="space-y-6">

    <!-- HEADER -->
    <div class="task-soft rounded-2xl border border-indigo-100 dark:border-indigo-900/50 p-5 md:p-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <div class="inline-flex items-center gap-2 text-xs font-semibold text-indigo-600 dark:text-indigo-300 bg-white/80 dark:bg-slate-900/50 px-3 py-1.5 rounded-full mb-3">
                    <span class="w-2 h-2 rounded-full bg-indigo-500"></span>
                    MANAJEMEN KEGIATAN PKL
                </div>

                <h1 class="text-2xl md:text-3xl font-bold text-slate-900 dark:text-white">
                    Daftar Tugas
                </h1>

                <p class="text-sm text-slate-600 dark:text-slate-300 mt-2">
                    <?= $isPembimbing
                        ? 'Pantau dan kelola tugas seluruh peserta PKL.'
                        : 'Atur pekerjaan dan pantau progres tugas PKL kamu.' ?>
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <button id="toggle-view"
                    class="px-4 py-2.5 rounded-xl bg-white dark:bg-slate-800 border border-indigo-100 dark:border-slate-700 text-indigo-700 dark:text-indigo-300 font-semibold text-sm hover:bg-indigo-50 dark:hover:bg-slate-700 transition">
                    <span id="view-label">Tampilan Daftar</span>
                </button>

                <button onclick="openAddModal()"
                    class="task-gradient text-white px-5 py-2.5 rounded-xl font-semibold text-sm shadow-md hover:opacity-90 transition">
                    + Tambah Tugas
                </button>
            </div>
        </div>
    </div>

    <!-- STATISTIK -->
    <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">

        <?php
        $statCards = [
            ['Total Tugas', $stats['total'], 'indigo'],
            ['Belum Dikerjakan', $stats['todo'], 'red'],
            ['Sedang Dikerjakan', $stats['doing'], 'purple'],
            ['Selesai', $stats['done'], 'blue'],
            ['Deadline Hari Ini', $stats['hari_ini'], 'orange'],
            ['Terlambat', $stats['terlewat'], 'red']
        ];
        ?>

        <?php foreach ($statCards as $card): ?>
            <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-slate-100 dark:border-slate-700 shadow-sm">
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    <?= taskEscape($card[0]) ?>
                </p>
                <p class="text-2xl font-bold mt-2 text-<?= $card[2] ?>-600 dark:text-<?= $card[2] ?>-400">
                    <?= (int)$card[1] ?>
                </p>
            </div>
        <?php endforeach; ?>

    </div>

    <!-- PROGRESS -->
    <div class="bg-white dark:bg-slate-800 rounded-xl p-5 border border-slate-100 dark:border-slate-700">
        <div class="flex justify-between items-center mb-3">
            <div>
                <h3 class="font-bold text-slate-800 dark:text-white">Progress Keseluruhan</h3>
                <p class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                    Persentase tugas yang telah diselesaikan
                </p>
            </div>

            <span class="text-xl font-bold text-indigo-600 dark:text-indigo-400">
                <?= $progress ?>%
            </span>
        </div>

        <div class="h-3 rounded-full bg-slate-100 dark:bg-slate-700 overflow-hidden">
            <div class="h-full task-gradient rounded-full transition-all duration-500"
                style="width:<?= $progress ?>%"></div>
        </div>

        <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">
            <?= $stats['done'] ?> dari <?= $stats['total'] ?> tugas telah selesai.
        </p>
    </div>

    <!-- FILTER -->
    <div class="bg-white dark:bg-slate-800 rounded-xl p-4 border border-slate-100 dark:border-slate-700">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-3">

            <div class="md:col-span-2">
                <input type="search" id="search-input"
                    placeholder="Cari judul, pembimbing, atau deskripsi..."
                    class="w-full px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-sm text-slate-800 dark:text-white outline-none focus:ring-2 focus:ring-indigo-500">
            </div>

            <select id="filter-status"
                class="px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-sm text-slate-800 dark:text-white">
                <option value="">Semua Status</option>
                <option value="todo">Belum Dikerjakan</option>
                <option value="doing">Sedang Dikerjakan</option>
                <option value="done">Selesai</option>
                <option value="overdue">Terlambat</option>
                <option value="today">Deadline Hari Ini</option>
            </select>

            <select id="sort-by"
                class="px-3 py-2.5 rounded-xl border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-sm text-slate-800 dark:text-white">
                <option value="status">Urut: Status</option>
                <option value="deadline">Urut: Deadline</option>
                <option value="title">Urut: Judul</option>
                <option value="created">Urut: Terbaru</option>
            </select>

        </div>
    </div>

    <!-- LIST VIEW -->
    <div id="list-view" class="hidden grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">

        <?php foreach ($tasks as $t):
            $id = (int)$t['id'];
            $status = $t['status'] ?? 'todo';
            $deadline = $t['deadline'] ?? '';
            $description = $t['description'] ?? '';
            $teacher = $t['teacher_name'] ?? '';
            $canManage = taskCanManage($t, $uid, $isPembimbing);
            [$priority, $priorityColor, $priorityIcon] = taskPriority($t, $today);
            $overdue = $status !== 'done' && $deadline && $deadline < $today;
        ?>

        <div class="task-card bg-white dark:bg-slate-800 rounded-xl border border-slate-100 dark:border-slate-700 overflow-hidden"
            data-task-card
            data-id="<?= $id ?>"
            data-status="<?= taskEscape($status) ?>"
            data-deadline="<?= taskEscape($deadline) ?>"
            data-created="<?= taskEscape($t['created_at'] ?? '') ?>"
            data-search="<?= taskEscape(mb_strtolower($t['title'].' '.$teacher.' '.$description)) ?>">

            <div class="h-1.5 task-gradient"></div>

            <div class="p-4">
                <div class="flex items-start justify-between gap-2">
                    <h3 class="font-bold text-slate-800 dark:text-white line-clamp-2">
                        <?= taskEscape($t['title']) ?>
                    </h3>

                    <span class="text-xs px-2 py-1 rounded-full bg-<?= $priorityColor ?>-100 text-<?= $priorityColor ?>-700 dark:bg-<?= $priorityColor ?>-900/30 dark:text-<?= $priorityColor ?>-300 whitespace-nowrap">
                        <?= taskEscape($priority) ?>
                    </span>
                </div>

                <div class="text-xs text-slate-500 dark:text-slate-400 mt-3 space-y-2">
                    <p> Pembimbing: <?= taskEscape($teacher ?: 'Belum ditentukan') ?></p>
                    <p class="<?= $overdue ? 'text-red-500 font-semibold' : '' ?>">
                        Deadline: <?= taskEscape(taskDeadlineLabel($t, $today)) ?>
                    </p>
                    <p>Status: <?= taskEscape(taskStatusLabel($status)) ?></p>
                </div>

                <?php if ($description): ?>
                    <p class="text-sm text-slate-600 dark:text-slate-300 mt-3 line-clamp-2">
                        <?= taskEscape($description) ?>
                    </p>
                <?php endif; ?>

                <div class="border-t border-slate-100 dark:border-slate-700 mt-4 pt-3 flex items-center justify-between gap-2">

                    <select class="task-status-select text-xs rounded-lg border border-slate-200 dark:border-slate-600 bg-slate-50 dark:bg-slate-700 text-slate-700 dark:text-white px-2 py-2"
                        data-id="<?= $id ?>"
                        <?= !$canManage ? 'disabled title="Hanya pembuat tugas atau pembimbing yang dapat mengubah status."' : '' ?>>
                        <option value="todo" <?= $status === 'todo' ? 'selected' : '' ?>>Belum</option>
                        <option value="doing" <?= $status === 'doing' ? 'selected' : '' ?>>Dikerjakan</option>
                        <option value="done" <?= $status === 'done' ? 'selected' : '' ?>>Selesai</option>
                    </select>

                    <?php if ($canManage): ?>
                    <div class="flex gap-1">
                        <button type="button" onclick="openEditModal(<?= $id ?>)"
                            class="px-3 py-2 text-xs rounded-lg bg-indigo-50 dark:bg-indigo-900/30 text-indigo-600 dark:text-indigo-300 hover:bg-indigo-100">
                            Edit
                        </button>

                        <button type="button"
                            onclick='confirmDelete(<?= $id ?>, <?= json_encode($t['title'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'
                            class="px-3 py-2 text-xs rounded-lg bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-300 hover:bg-red-100">
                            Hapus
                        </button>
                    </div>
                    <?php endif; ?>

                </div>
            </div>
        </div>

        <?php endforeach; ?>

    </div>

    <!-- KANBAN VIEW -->
    <div id="kanban-view" class="grid grid-cols-1 md:grid-cols-3 gap-4">

        <?php
        $columns = [
            'todo' => ['Belum Dikerjakan', 'border-red-200 dark:border-red-900/50'],
            'doing' => ['Sedang Dikerjakan', 'border-purple-200 dark:border-purple-900/50'],
            'done' => ['Selesai', 'border-blue-200 dark:border-blue-900/50']
        ];
        ?>

        <?php foreach ($columns as $status => $column): ?>

        <div class="kanban-col bg-slate-50 dark:bg-slate-800/70 rounded-xl p-3 border <?= $column[1] ?>"
            data-kanban-column="<?= $status ?>">

            <div class="flex items-center justify-between px-1 mb-3">
                <h3 class="font-bold text-sm text-slate-700 dark:text-white">
                    <?= taskEscape($column[0]) ?>
                </h3>

                <span class="text-xs font-bold px-2.5 py-1 rounded-full bg-white dark:bg-slate-700 text-indigo-600 dark:text-indigo-300"
                    data-count="<?= $status ?>">
                    <?= count(array_filter($tasks, fn($t) => ($t['status'] ?? '') === $status)) ?>
                </span>
            </div>

            <div class="kanban-drop flex flex-col gap-3 min-h-48"
                data-status="<?= $status ?>">

                <?php foreach ($tasks as $t):
                    if (($t['status'] ?? '') !== $status) continue;

                    $id = (int)$t['id'];
                    $deadline = $t['deadline'] ?? '';
                    $description = $t['description'] ?? '';
                    $teacher = $t['teacher_name'] ?? '';
                    $canManage = taskCanManage($t, $uid, $isPembimbing);
                    $overdue = $status !== 'done' && $deadline && $deadline < $today;
                    [$priority, $priorityColor, $priorityIcon] = taskPriority($t, $today);
                ?>

                <div class="task-card bg-white dark:bg-slate-800 rounded-xl p-4 border border-slate-100 dark:border-slate-700 shadow-sm"
                    data-task-card
                    data-id="<?= $id ?>"
                    data-status="<?= taskEscape($status) ?>"
                    data-deadline="<?= taskEscape($deadline) ?>"
                    data-created="<?= taskEscape($t['created_at'] ?? '') ?>"
                    data-search="<?= taskEscape(mb_strtolower($t['title'].' '.$teacher.' '.$description)) ?>"
                    <?= $canManage ? 'draggable="true"' : '' ?>>

                    <div class="flex justify-between gap-2">
                        <h4 class="font-semibold text-sm text-slate-800 dark:text-white line-clamp-2">
                            <?= taskEscape($t['title']) ?>
                        </h4>

                        <span class="text-xs text-indigo-500 whitespace-nowrap">
                            <?= taskEscape($priorityIcon) ?>
                        </span>
                    </div>

                    <p class="text-xs text-slate-500 dark:text-slate-400 mt-2">
                        Pembimbing: <?= taskEscape($teacher ?: '-') ?>
                    </p>

                    <p class="text-xs mt-2 <?= $overdue ? 'text-red-500 font-semibold' : 'text-slate-500 dark:text-slate-400' ?>">
                        <?= taskEscape(taskDeadlineLabel($t, $today)) ?>
                    </p>

                    <?php if ($canManage): ?>
                    <div class="flex justify-end gap-2 mt-3">
                        <button type="button" onclick="openEditModal(<?= $id ?>)"
                            class="text-xs text-indigo-600 dark:text-indigo-300 hover:underline">
                            Edit
                        </button>

                        <button type="button"
                            onclick='confirmDelete(<?= $id ?>, <?= json_encode($t['title'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>)'
                            class="text-xs text-red-500 hover:underline">
                            Hapus
                        </button>
                    </div>
                    <?php endif; ?>

                </div>

                <?php endforeach; ?>

            </div>
        </div>

        <?php endforeach; ?>

    </div>

    <!-- EMPTY STATE -->
    <div id="empty-state" class="hidden text-center bg-white dark:bg-slate-800 rounded-xl p-10 border border-slate-100 dark:border-slate-700">
        <div class="text-4xl mb-3">📋</div>
        <h3 class="font-bold text-slate-800 dark:text-white">Tidak ada tugas ditemukan</h3>
        <p class="text-sm text-slate-500 dark:text-slate-400 mt-2">
            Coba ubah kata pencarian atau filter tugas.
        </p>
    </div>

</div>

<!-- MODAL TAMBAH / EDIT -->
<div id="task-modal" class="hidden fixed inset-0 z-50 bg-slate-950/60 backdrop-blur-sm items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-800 w-full max-w-lg rounded-2xl shadow-2xl overflow-hidden max-h-[90vh] overflow-y-auto">

        <div class="task-gradient px-6 py-5 flex justify-between items-center">
            <div>
                <h3 id="modal-title" class="text-lg font-bold text-white">Tambah Tugas</h3>
                <p class="text-xs text-indigo-100 mt-1">Lengkapi informasi tugas PKL</p>
            </div>

            <button type="button" onclick="closeModal()" class="text-white text-2xl">&times;</button>
        </div>

        <form method="POST" id="task-form" class="p-6 space-y-4">

            <input type="hidden" name="csrf" value="<?= taskEscape(taskCsrfToken()) ?>">
            <input type="hidden" name="action" id="form-action" value="add">
            <input type="hidden" name="id" id="form-id">

            <div>
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">
                    Nama Tugas *
                </label>

                <input type="text" name="title" id="form-title" required minlength="3" maxlength="150"
                    placeholder="Contoh: Membuat laporan kegiatan"
                    class="w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-white outline-none focus:ring-2 focus:ring-indigo-500">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">

                <?php if ($hasTeacher): ?>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">
                        Diberikan Oleh
                    </label>

                    <input type="text" name="teacher_name" id="form-teacher" maxlength="100"
                        placeholder="Nama pembimbing"
                        class="w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-white outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <?php endif; ?>

                <?php if ($hasDeadline): ?>
                <div>
                    <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">
                        Deadline
                    </label>

                    <input type="date" name="deadline" id="form-deadline"
                        class="w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-white outline-none focus:ring-2 focus:ring-indigo-500">
                </div>
                <?php endif; ?>

            </div>

            <div>
                <label class="block text-sm font-semibold text-slate-700 dark:text-slate-300 mb-2">
                    Status
                </label>

                <select name="status" id="form-status"
                    class="w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-white outline-none focus:ring-2 focus:ring-indigo-500">
                    <option value="todo">Belum Dikerjakan</option>
                    <option value="doing">Sedang Dikerjakan</option>
                    <option value="done">Selesai</option>
                </select>
            </div>

            <?php if ($hasDescription): ?>
            <div>
                <div class="flex justify-between mb-2">
                    <label class="text-sm font-semibold text-slate-700 dark:text-slate-300">
                        Deskripsi
                    </label>
                    <span id="desc-count" class="text-xs text-slate-400">0 / 2000</span>
                </div>

                <textarea name="description" id="form-desc" rows="4" maxlength="2000"
                    placeholder="Tuliskan detail pekerjaan..."
                    class="w-full px-4 py-3 rounded-xl border border-slate-200 dark:border-slate-600 dark:bg-slate-700 dark:text-white outline-none focus:ring-2 focus:ring-indigo-500 resize-none"></textarea>
            </div>
            <?php endif; ?>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" onclick="closeModal()"
                    class="px-5 py-2.5 rounded-xl border border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-300 font-semibold text-sm">
                    Batal
                </button>

                <button type="submit"
                    class="task-gradient px-5 py-2.5 rounded-xl text-white font-semibold text-sm">
                    Simpan Tugas
                </button>
            </div>

        </form>
    </div>
</div>

<!-- MODAL HAPUS -->
<div id="delete-modal" class="hidden fixed inset-0 z-50 bg-slate-950/60 backdrop-blur-sm items-center justify-center p-4">
    <div class="bg-white dark:bg-slate-800 w-full max-w-sm rounded-2xl p-6 shadow-2xl">

        <div class="w-14 h-14 rounded-full bg-red-50 dark:bg-red-900/30 text-red-500 flex items-center justify-center text-2xl mx-auto mb-4">
            !
        </div>

        <h3 class="font-bold text-lg text-center text-slate-800 dark:text-white">
            Hapus Tugas?
        </h3>

        <p id="delete-task-name" class="text-sm text-center text-slate-500 dark:text-slate-400 mt-2 mb-6"></p>

        <form method="POST" class="flex gap-2">
            <input type="hidden" name="csrf" value="<?= taskEscape(taskCsrfToken()) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" id="delete-id">

            <button type="button" onclick="closeDeleteModal()"
                class="flex-1 px-4 py-2.5 rounded-xl border border-slate-200 dark:border-slate-600 text-slate-600 dark:text-slate-300 font-semibold text-sm">
                Batal
            </button>

            <button type="submit"
                class="flex-1 px-4 py-2.5 rounded-xl bg-red-500 hover:bg-red-600 text-white font-semibold text-sm">
                Ya, Hapus
            </button>
        </form>
    </div>
</div>

<!-- TOAST -->
<div id="toast-container" class="fixed bottom-5 right-5 z-[60] flex flex-col gap-2"></div>

<script>
const TASKS_DATA = <?= json_encode(array_map(function($t) {
    return [
        'id' => (int)$t['id'],
        'title' => $t['title'],
        'description' => $t['description'] ?? '',
        'teacher_name' => $t['teacher_name'] ?? '',
        'deadline' => $t['deadline'] ?? '',
        'status' => $t['status'] ?? 'todo'
    ];
}, $tasks), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;

const TODAY = <?= json_encode($today) ?>;
const CSRF_TOKEN = <?= json_encode(taskCsrfToken()) ?>;

let currentView = localStorage.getItem('tasks-view') || 'kanban';

const modal = document.getElementById('task-modal');
const deleteModal = document.getElementById('delete-modal');

function showModal(element) {
    element.classList.remove('hidden');
    element.classList.add('flex');
}

function hideModal(element) {
    element.classList.add('hidden');
    element.classList.remove('flex');
}

// =====================================================
// VIEW
// =====================================================
function applyView() {
    const list = document.getElementById('list-view');
    const kanban = document.getElementById('kanban-view');

    if (currentView === 'list') {
        list.classList.remove('hidden');
        kanban.classList.add('hidden');
        document.getElementById('view-label').textContent = 'Tampilan Kolom';
    } else {
        list.classList.add('hidden');
        kanban.classList.remove('hidden');
        document.getElementById('view-label').textContent = 'Tampilan Daftar';
    }

    filterTasks();
}

document.getElementById('toggle-view').addEventListener('click', () => {
    currentView = currentView === 'list' ? 'kanban' : 'list';
    localStorage.setItem('tasks-view', currentView);
    applyView();
});

// =====================================================
// MODAL
// =====================================================
function openAddModal() {
    document.getElementById('modal-title').textContent = 'Tambah Tugas';
    document.getElementById('form-action').value = 'add';
    document.getElementById('form-id').value = '';
    document.getElementById('form-title').value = '';

    const teacher = document.getElementById('form-teacher');
    const deadline = document.getElementById('form-deadline');
    const desc = document.getElementById('form-desc');

    if (teacher) teacher.value = '';
    if (deadline) deadline.value = '';
    if (desc) desc.value = '';

    document.getElementById('form-status').value = 'todo';
    updateDescCount();
    showModal(modal);

    setTimeout(() => document.getElementById('form-title').focus(), 100);
}

function openEditModal(id) {
    const task = TASKS_DATA.find(item => item.id === Number(id));
    if (!task) return;

    document.getElementById('modal-title').textContent = 'Edit Tugas';
    document.getElementById('form-action').value = 'edit';
    document.getElementById('form-id').value = task.id;
    document.getElementById('form-title').value = task.title;
    document.getElementById('form-status').value = task.status;

    const teacher = document.getElementById('form-teacher');
    const deadline = document.getElementById('form-deadline');
    const desc = document.getElementById('form-desc');

    if (teacher) teacher.value = task.teacher_name;
    if (deadline) deadline.value = task.deadline;
    if (desc) desc.value = task.description;

    updateDescCount();
    showModal(modal);
}

function closeModal() {
    hideModal(modal);
}

function confirmDelete(id, title) {
    document.getElementById('delete-id').value = id;
    document.getElementById('delete-task-name').textContent = title;
    showModal(deleteModal);
}

function closeDeleteModal() {
    hideModal(deleteModal);
}

modal.addEventListener('click', e => {
    if (e.target === modal) closeModal();
});

deleteModal.addEventListener('click', e => {
    if (e.target === deleteModal) closeDeleteModal();
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeModal();
        closeDeleteModal();
    }
});

// =====================================================
// COUNTER DESKRIPSI
// =====================================================
function updateDescCount() {
    const desc = document.getElementById('form-desc');
    const counter = document.getElementById('desc-count');

    if (!desc || !counter) return;

    counter.textContent = desc.value.length + ' / 2000';
    counter.classList.toggle('text-red-500', desc.value.length > 1800);
}

const descInput = document.getElementById('form-desc');

if (descInput) {
    descInput.addEventListener('input', updateDescCount);
}

// =====================================================
// TOAST
// =====================================================
function showToast(message, type = 'success') {
    const toast = document.createElement('div');

    toast.className =
        'px-4 py-3 rounded-xl shadow-lg text-white text-sm font-semibold ' +
        (type === 'success' ? 'task-gradient' : 'bg-red-500');

    toast.textContent = message;

    document.getElementById('toast-container').appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity .3s';

        setTimeout(() => toast.remove(), 300);
    }, 2600);
}

// =====================================================
// UPDATE STATUS
// =====================================================
async function updateStatus(id, status, element = null) {
    if (element) element.disabled = true;

    try {
        const response = await fetch('api/task_update.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify({
                id: Number(id),
                status: status
            })
        });

        const result = await response.json();

        if (!response.ok || result.status !== 'success') {
            throw new Error(result.message || 'Status gagal diperbarui.');
        }

        const task = TASKS_DATA.find(item => item.id === Number(id));

        if (task) task.status = status;

        showToast('Status tugas berhasil diperbarui.');

        // Muat ulang agar posisi kartu, statistik, dan progress sinkron.
        setTimeout(() => window.location.reload(), 500);

    } catch (error) {
        showToast(error.message || 'Koneksi bermasalah.', 'error');

        if (element) {
            element.disabled = false;

            const task = TASKS_DATA.find(item => item.id === Number(id));
            if (task) element.value = task.status;
        }
    }
}

document.querySelectorAll('.task-status-select').forEach(select => {
    select.addEventListener('change', function() {
        const task = TASKS_DATA.find(item => item.id === Number(this.dataset.id));
        const previous = task ? task.status : 'todo';

        updateStatus(this.dataset.id, this.value, this);

        if (task) this.value = this.value || previous;
    });
});

// =====================================================
// SEARCH, FILTER, SORT
// =====================================================
function filterTasks() {
    const query = document.getElementById('search-input').value.toLowerCase().trim();
    const status = document.getElementById('filter-status').value;
    const sortBy = document.getElementById('sort-by').value;

    const activeView = currentView === 'list'
        ? document.getElementById('list-view')
        : document.getElementById('kanban-view');

    const cards = Array.from(activeView.querySelectorAll('[data-task-card]'));

    let visibleCount = 0;

    cards.forEach(card => {
        const search = (card.dataset.search || '').toLowerCase();
        const taskStatus = card.dataset.status;
        const deadline = card.dataset.deadline || '';

        const matchSearch = !query || search.includes(query);

        let matchStatus = true;

        if (status === 'overdue') {
            matchStatus = taskStatus !== 'done' && deadline !== '' && deadline < TODAY;
        } else if (status === 'today') {
            matchStatus = taskStatus !== 'done' && deadline === TODAY;
        } else if (status) {
            matchStatus = taskStatus === status;
        }

        const visible = matchSearch && matchStatus;

        card.style.display = visible ? '' : 'none';

        if (visible) visibleCount++;
    });

    if (currentView === 'list') {
        cards.sort((a, b) => {
            if (sortBy === 'deadline') {
                const da = a.dataset.deadline || '9999-12-31';
                const db = b.dataset.deadline || '9999-12-31';
                return da.localeCompare(db);
            }

            if (sortBy === 'title') {
                return (a.dataset.search || '').localeCompare(b.dataset.search || '');
            }

            if (sortBy === 'created') {
                return (b.dataset.created || '').localeCompare(a.dataset.created || '');
            }

            const order = {todo: 1, doing: 2, done: 3};

            return (order[a.dataset.status] || 4) - (order[b.dataset.status] || 4);
        });

        cards.forEach(card => activeView.appendChild(card));
    }

    document.getElementById('empty-state').classList.toggle('hidden', visibleCount > 0);

    document.querySelectorAll('.kanban-drop').forEach(column => {
        const count = Array.from(column.querySelectorAll('[data-task-card]'))
            .filter(card => card.style.display !== 'none').length;

        const badge = document.querySelector(`[data-count="${column.dataset.status}"]`);

        if (badge) badge.textContent = count;
    });
}

['search-input', 'filter-status', 'sort-by'].forEach(id => {
    document.getElementById(id).addEventListener('input', filterTasks);
});

// =====================================================
// DRAG AND DROP KANBAN
// =====================================================
let draggedCard = null;

document.querySelectorAll('#kanban-view [draggable="true"]').forEach(card => {
    card.addEventListener('dragstart', () => {
        draggedCard = card;
        card.classList.add('dragging');
    });

    card.addEventListener('dragend', () => {
        card.classList.remove('dragging');
        draggedCard = null;

        document.querySelectorAll('.kanban-drop').forEach(col => {
            col.classList.remove('drag-over');
        });
    });
});

document.querySelectorAll('.kanban-drop').forEach(column => {
    column.addEventListener('dragover', e => {
        e.preventDefault();
        column.classList.add('drag-over');
    });

    column.addEventListener('dragleave', e => {
        if (!column.contains(e.relatedTarget)) {
            column.classList.remove('drag-over');
        }
    });

    column.addEventListener('drop', async e => {
        e.preventDefault();
        column.classList.remove('drag-over');

        if (!draggedCard) return;

        const newStatus = column.dataset.status;
        const oldStatus = draggedCard.dataset.status;

        if (newStatus === oldStatus) return;

        const id = draggedCard.dataset.id;

        // Pindahkan sementara. Jika server menolak, halaman dikembalikan.
        column.appendChild(draggedCard);
        draggedCard.dataset.status = newStatus;

        const task = TASKS_DATA.find(item => item.id === Number(id));

        if (task) task.status = newStatus;

        updateKanbanCounts();
        await updateStatus(id, newStatus);
    });
});

function updateKanbanCounts() {
    document.querySelectorAll('.kanban-drop').forEach(column => {
        const count = column.querySelectorAll('[data-task-card]').length;
        const badge = document.querySelector(`[data-count="${column.dataset.status}"]`);

        if (badge) badge.textContent = count;
    });
}

applyView();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
