<?php
// tasks.php
require_once __DIR__ . '/includes/header.php';
requireLogin();

$uid = (int)$user['id'];

// ============ PROSES TAMBAH ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $title    = trim($_POST['title'] ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $teacher  = trim($_POST['teacher_name'] ?? '');
    $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;

    if ($title !== '') {
        $stmt = $pdo->prepare("INSERT INTO tasks (title, description, teacher_name, deadline, created_by, status, created_at) VALUES (?, ?, ?, ?, ?, 'todo', NOW())");
        $stmt->execute([$title, $desc, $teacher, $deadline, $uid]);
        setFlash('success', 'Tugas berhasil ditambahkan!');
        redirect('tasks.php');
    }
}

// ============ PROSES EDIT ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $id       = (int)($_POST['id'] ?? 0);
    $title    = trim($_POST['title'] ?? '');
    $desc     = trim($_POST['description'] ?? '');
    $teacher  = trim($_POST['teacher_name'] ?? '');
    $deadline = !empty($_POST['deadline']) ? $_POST['deadline'] : null;

    if ($id && $title !== '') {
        $stmt = $pdo->prepare("UPDATE tasks SET title=?, description=?, teacher_name=?, deadline=? WHERE id=?");
        $stmt->execute([$title, $desc, $teacher, $deadline, $id]);
        setFlash('success', 'Tugas berhasil diupdate!');
        redirect('tasks.php');
    }
}

// ============ PROSES HAPUS ============
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
    $stmt->execute([$id]);
    setFlash('success', 'Tugas dihapus.');
    redirect('tasks.php');
}

// ============ AMBIL DATA ============
$stmt = $pdo->query("SELECT * FROM tasks ORDER BY 
    CASE status WHEN 'todo' THEN 1 WHEN 'doing' THEN 2 WHEN 'done' THEN 3 END, 
    deadline IS NULL, deadline ASC, created_at DESC");
$tasks = $stmt->fetchAll();

// Statistik
$stats = [
    'total'    => count($tasks),
    'todo'     => 0,
    'doing'    => 0,
    'done'     => 0,
    'terlewat' => 0,
];
$today = date('Y-m-d');
foreach ($tasks as $t) {
    $stats[$t['status']] = ($stats[$t['status']] ?? 0) + 1;
    if ($t['status'] !== 'done' && $t['deadline'] && $t['deadline'] < $today) {
        $stats['terlewat']++;
    }
}
$progress = $stats['total'] > 0 ? round($stats['done'] / $stats['total'] * 100) : 0;

// Helper: prioritas otomatis dari deadline
function taskPriority($t) {
    if ($t['status'] === 'done') return ['label'=>'Selesai','color'=>'gray','icon'=>'✅'];
    if (!$t['deadline']) return ['label'=>'Tanpa Tenggat','color'=>'gray','icon'=>'—'];
    $days = (int)((strtotime($t['deadline']) - strtotime(date('Y-m-d'))) / 86400);
    if ($days < 0)   return ['label'=>'Terlewat','color'=>'red','icon'=>'🔴'];
    if ($days === 0) return ['label'=>'Hari Ini','color'=>'red','icon'=>'🔴'];
    if ($days <= 2)  return ['label'=>'Mendesak','color'=>'orange','icon'=>'🟠'];
    if ($days <= 7)  return ['label'=>'Tinggi','color'=>'yellow','icon'=>'🟡'];
    return ['label'=>'Normal','color'=>'green','icon'=>'🟢'];
}
function deadlineLabel($t) {
    if (!$t['deadline']) return 'Tanpa deadline';
    $days = (int)((strtotime($t['deadline']) - strtotime(date('Y-m-d'))) / 86400);
    if ($days < 0)   return '⚠️ ' . abs($days) . ' hari lalu';
    if ($days === 0) return '⏰ Hari ini';
    if ($days === 1) return '⏰ Besok';
    return '📅 ' . $days . ' hari lagi';
}
?>

<style>
    @keyframes fadeIn { from{opacity:0;transform:translateY(10px)} to{opacity:1;transform:translateY(0)} }
    .task-card { animation: fadeIn .35s ease-out both; }
    .task-card:nth-child(1){animation-delay:.02s} .task-card:nth-child(2){animation-delay:.04s}
    .task-card:nth-child(3){animation-delay:.06s} .task-card:nth-child(4){animation-delay:.08s}
    .task-card:nth-child(5){animation-delay:.10s} .task-card:nth-child(6){animation-delay:.12s}
    .task-card:hover { transform: translateY(-3px); }
    .kanban-col { min-height: 400px; }
    .drag-over { background: rgba(99, 102, 241, 0.08); border: 2px dashed #6366f1; }
    .dragging { opacity: .4; transform: rotate(2deg); }
    .line-clamp-2 { display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
    .toast-enter { animation: toastIn .3s ease-out; }
    @keyframes toastIn { from{opacity:0;transform:translateX(20px)} to{opacity:1;transform:translateX(0)} }
</style>

<!-- ============ HEADER ============ -->
<div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-6 gap-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 dark:text-white">📋 Daftar Tugas PKL</h1>
        <p class="text-gray-500 dark:text-gray-400 mt-1">Kelola tugas dari guru pembimbing atau pekerjaan harian.</p>
    </div>
    <div class="flex gap-2">
        <button id="toggle-view" class="bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-200 px-4 py-2.5 rounded-lg font-medium transition flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/></svg>
            <span id="view-label">Tampilan Kolom</span>
        </button>
        <button onclick="openAddModal()" class="bg-primary hover:bg-indigo-700 text-white px-5 py-2.5 rounded-lg font-medium transition-colors shadow-sm flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            Tambah Tugas
        </button>
    </div>
</div>

<!-- ============ STATISTIK ============ -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="flex items-center justify-between mb-1">
            <span class="text-xs text-gray-500 dark:text-gray-400">Total</span>
            <span class="text-lg">📋</span>
        </div>
        <div class="text-2xl font-bold text-gray-800 dark:text-white"><?= $stats['total'] ?></div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="flex items-center justify-between mb-1">
            <span class="text-xs text-gray-500 dark:text-gray-400">Selesai</span>
            <span class="text-lg">✅</span>
        </div>
        <div class="text-2xl font-bold text-green-600 dark:text-green-400"><?= $stats['done'] ?></div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700">
        <div class="flex items-center justify-between mb-1">
            <span class="text-xs text-gray-500 dark:text-gray-400">Sedang Dikerjakan</span>
            <span class="text-lg">🟡</span>
        </div>
        <div class="text-2xl font-bold text-yellow-500"><?= $stats['doing'] ?></div>
    </div>
    <div class="bg-white dark:bg-gray-800 rounded-xl p-4 border border-gray-100 dark:border-gray-700 <?= $stats['terlewat'] > 0 ? 'ring-2 ring-red-300 dark:ring-red-700' : '' ?>">
        <div class="flex items-center justify-between mb-1">
            <span class="text-xs text-gray-500 dark:text-gray-400">Terlewat</span>
            <span class="text-lg">⚠️</span>
        </div>
        <div class="text-2xl font-bold text-red-500"><?= $stats['terlewat'] ?></div>
    </div>
</div>

<!-- Progress bar -->
<div class="bg-white dark:bg-gray-800 rounded-xl p-4 mb-6 border border-gray-100 dark:border-gray-700">
    <div class="flex justify-between items-center mb-2">
        <span class="text-sm font-semibold text-gray-700 dark:text-gray-300">Progress Keseluruhan</span>
        <span class="text-sm font-bold text-primary"><?= $progress ?>%</span>
    </div>
    <div class="w-full h-2.5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
        <div class="h-full bg-gradient-to-r from-indigo-500 via-purple-500 to-pink-500 rounded-full transition-all duration-500" style="width:<?= $progress ?>%"></div>
    </div>
</div>

<!-- ============ PENCARIAN & FILTER ============ -->
<div class="bg-white dark:bg-gray-800 rounded-xl p-4 mb-6 border border-gray-100 dark:border-gray-700">
    <div class="grid grid-cols-1 md:grid-cols-4 gap-3">
        <div class="md:col-span-2 relative">
            <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" id="search-input" placeholder="Cari tugas..." class="w-full pl-9 pr-4 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none dark:text-white">
        </div>
        <select id="filter-status" class="px-3 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none dark:text-white">
            <option value="">Semua Status</option>
            <option value="todo">🔴 Belum Dikerjakan</option>
            <option value="doing">🟡 Sedang Dikerjakan</option>
            <option value="done">🟢 Selesai</option>
        </select>
        <select id="sort-by" class="px-3 py-2 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-lg text-sm focus:ring-2 focus:ring-primary outline-none dark:text-white">
            <option value="status">Urut: Status</option>
            <option value="deadline">Urut: Deadline</option>
            <option value="title">Urut: Judul</option>
            <option value="created">Urut: Terbaru</option>
        </select>
    </div>
</div>

<!-- ============ TAMPILAN: DAFTAR ============ -->
<div id="list-view" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 hidden">
    <?php foreach ($tasks as $t):
        $p = taskPriority($t);
        $isDone = $t['status'] === 'done';
        $isOverdue = $t['status'] !== 'done' && $t['deadline'] && $t['deadline'] < $today;
        $borderColor = match($t['status']) {
            'todo'  => 'border-t-red-500',
            'doing' => 'border-t-yellow-400',
            'done'  => 'border-t-green-500',
            default => 'border-t-gray-300'
        };
    ?>
    <div class="task-card bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-100 dark:border-gray-700 border-t-4 <?= $borderColor ?> <?= $isDone ? 'opacity-70' : '' ?> transition-all duration-300 cursor-pointer"
         data-id="<?= $t['id'] ?>"
         data-status="<?= $t['status'] ?>"
         data-title="<?= e(strtolower($t['title'])) ?>"
         data-teacher="<?= e(strtolower($t['teacher_name'] ?? '')) ?>"
         data-deadline="<?= $t['deadline'] ?? '' ?>"
         data-created="<?= $t['created_at'] ?? '' ?>"
         onclick="if(!event.target.closest('button,select,a')) openEditModal(<?= $t['id'] ?>)">

        <div class="p-4">
            <div class="flex items-start justify-between gap-2 mb-2">
                <h3 class="font-bold text-gray-900 dark:text-white text-base line-clamp-2 flex-1 <?= $isDone ? 'line-through' : '' ?>"><?= e($t['title']) ?></h3>
                <span class="text-xs whitespace-nowrap px-2 py-0.5 rounded-full bg-<?= $p['color'] ?>-100 text-<?= $p['color'] ?>-700 dark:bg-<?= $p['color'] ?>-900/40 dark:text-<?= $p['color'] ?>-300 font-semibold shrink-0"><?= $p['icon'] ?> <?= $p['label'] ?></span>
            </div>

            <div class="space-y-1 text-xs text-gray-500 dark:text-gray-400 mb-3">
                <p class="flex items-center gap-1.5">👨‍🏫 <span class="truncate"><?= e($t['teacher_name'] ?: 'Tidak disebutkan') ?></span></p>
                <p class="flex items-center gap-1.5 <?= $isOverdue ? 'text-red-600 dark:text-red-400 font-semibold' : '' ?>">
                    <?= deadlineLabel($t) ?>
                </p>
            </div>

            <?php if ($t['description']): ?>
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-3 line-clamp-2"><?= e($t['description']) ?></p>
            <?php endif; ?>

            <div class="flex items-center justify-between gap-2 mt-auto pt-3 border-t border-gray-100 dark:border-gray-700">
                <select class="status-select text-xs border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg px-2 py-1 bg-gray-50 focus:ring-2 focus:ring-primary cursor-pointer"
                        data-id="<?= $t['id'] ?>"
                        onclick="event.stopPropagation()"
                        onchange="updateStatus(<?= $t['id'] ?>, this.value, this)">
                    <option value="todo" <?= $t['status']==='todo'?'selected':'' ?>>🔴 Belum Dikerjakan</option>
                    <option value="doing" <?= $t['status']==='doing'?'selected':'' ?>>🟡 Sedang Dikerjakan</option>
                    <option value="done" <?= $t['status']==='done'?'selected':'' ?>>🟢 Selesai</option>
                </select>

                <div class="flex gap-1">
                    <button onclick="event.stopPropagation();openEditModal(<?= $t['id'] ?>)" class="text-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/30 p-1.5 rounded-lg transition" title="Edit">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </button>
                    <button onclick="event.stopPropagation();confirmDelete(<?= $t['id'] ?>, <?= htmlspecialchars(json_encode($t['title']), ENT_QUOTES) ?>)" class="text-red-500 hover:bg-red-50 dark:hover:bg-red-900/30 p-1.5 rounded-lg transition" title="Hapus">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($tasks)): ?>
    <div class="col-span-full text-center py-16">
        <div class="text-6xl mb-4">📭</div>
        <h3 class="text-lg font-bold text-gray-700 dark:text-gray-300 mb-2">Belum ada tugas</h3>
        <p class="text-gray-500 dark:text-gray-400 text-sm mb-4">Tambahkan tugas pertama kamu!</p>
        <button onclick="openAddModal()" class="bg-primary hover:bg-indigo-700 text-white px-5 py-2 rounded-lg font-medium">+ Tambah Tugas</button>
    </div>
    <?php endif; ?>
</div>

<!-- ============ TAMPILAN: KOLOM ============ -->
<div id="kanban-view" class="grid grid-cols-1 md:grid-cols-3 gap-4">
    <?php
    $columns = [
        'todo'  => ['label'=>'🔴 Belum Dikerjakan', 'bg'=>'bg-red-50 dark:bg-red-900/10'],
        'doing' => ['label'=>'🟡 Sedang Dikerjakan', 'bg'=>'bg-yellow-50 dark:bg-yellow-900/10'],
        'done'  => ['label'=>'🟢 Selesai', 'bg'=>'bg-green-50 dark:bg-green-900/10'],
    ];
    foreach ($columns as $status => $col): ?>
    <div class="kanban-col rounded-xl p-3 <?= $col['bg'] ?> border border-gray-100 dark:border-gray-700" data-status="<?= $status ?>">
        <div class="flex items-center justify-between mb-3 px-1">
            <h3 class="font-bold text-gray-800 dark:text-white text-sm"><?= $col['label'] ?></h3>
            <span class="text-xs bg-white dark:bg-gray-700 text-gray-600 dark:text-gray-300 px-2 py-0.5 rounded-full font-bold" data-count="<?= $status ?>">0</span>
        </div>
        <div class="flex flex-col gap-2 kanban-drop" data-status="<?= $status ?>">
            <?php foreach ($tasks as $t):
                if ($t['status'] !== $status) continue;
                $p = taskPriority($t);
                $isOverdue = $t['status'] !== 'done' && $t['deadline'] && $t['deadline'] < $today;
            ?>
            <div class="task-card bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-100 dark:border-gray-700 p-3 cursor-grab active:cursor-grabbing"
                 draggable="true"
                 data-id="<?= $t['id'] ?>"
                 data-status="<?= $t['status'] ?>"
                 data-title="<?= e(strtolower($t['title'])) ?>"
                 data-teacher="<?= e(strtolower($t['teacher_name'] ?? '')) ?>"
                 data-deadline="<?= $t['deadline'] ?? '' ?>"
                 data-created="<?= $t['created_at'] ?? '' ?>">
                <div class="flex items-start justify-between gap-1 mb-1.5">
                    <h4 class="font-semibold text-gray-900 dark:text-white text-sm line-clamp-2 flex-1 <?= $t['status']==='done'?'line-through':'' ?>"><?= e($t['title']) ?></h4>
                    <span class="text-xs shrink-0" title="<?= $p['label'] ?>"><?= $p['icon'] ?></span>
                </div>
                <p class="text-[11px] text-gray-500 dark:text-gray-400 mb-1">👨‍🏫 <?= e($t['teacher_name'] ?: '-') ?></p>
                <p class="text-[11px] <?= $isOverdue ? 'text-red-600 font-semibold' : 'text-gray-500 dark:text-gray-400' ?>"><?= deadlineLabel($t) ?></p>
                <div class="flex justify-end gap-1 mt-2">
                    <button onclick="event.stopPropagation();openEditModal(<?= $t['id'] ?>)" class="text-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/30 p-1 rounded text-xs">✏️</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ============ MODAL TAMBAH/EDIT ============ -->
<div id="task-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl w-full max-w-lg shadow-2xl max-h-[90vh] overflow-y-auto">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100 dark:border-gray-700 sticky top-0 bg-white dark:bg-gray-800 z-10">
            <h3 id="modal-title" class="text-lg font-bold text-gray-800 dark:text-white">Tugas Baru</h3>
            <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 p-1 rounded-full hover:bg-gray-100 dark:hover:bg-gray-700">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" class="p-6" id="task-form">
            <input type="hidden" name="action" id="form-action" value="add">
            <input type="hidden" name="id" id="form-id" value="">

            <div class="mb-4">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Nama Tugas *</label>
                <input type="text" name="title" id="form-title" required placeholder="Contoh: Input data barang inventaris" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-primary focus:border-primary outline-none">
            </div>

            <div class="grid grid-cols-2 gap-4 mb-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Diberikan Oleh</label>
                    <input type="text" name="teacher_name" id="form-teacher" placeholder="Nama guru" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-primary outline-none">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Deadline</label>
                    <input type="date" name="deadline" id="form-deadline" class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-primary outline-none">
                </div>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Deskripsi (opsional)</label>
                <textarea name="description" id="form-desc" rows="3" placeholder="Detail tugas..." class="w-full px-4 py-2 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white rounded-lg focus:ring-2 focus:ring-primary outline-none resize-none"></textarea>
            </div>

            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeModal()" class="px-4 py-2 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 font-medium">Batal</button>
                <button type="submit" class="bg-primary hover:bg-indigo-700 text-white px-5 py-2 rounded-lg font-medium">Simpan</button>
            </div>
        </form>
    </div>
</div>

<!-- ============ MODAL HAPUS ============ -->
<div id="delete-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white dark:bg-gray-800 rounded-2xl w-full max-w-sm shadow-2xl p-6 text-center">
        <div class="text-5xl mb-3">🗑️</div>
        <h3 class="text-lg font-bold text-gray-800 dark:text-white mb-2">Hapus tugas ini?</h3>
        <p id="delete-task-name" class="text-sm text-gray-500 dark:text-gray-400 mb-6"></p>
        <div class="flex gap-2">
            <button onclick="closeDeleteModal()" class="flex-1 px-4 py-2.5 rounded-lg border border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 font-medium">Batal</button>
            <a id="delete-confirm-link" href="#" class="flex-1 bg-red-500 hover:bg-red-600 text-white px-4 py-2.5 rounded-lg font-medium text-center">Hapus</a>
        </div>
    </div>
</div>

<!-- ============ NOTIFIKASI ============ -->
<div id="toast-container" class="fixed bottom-6 right-6 z-50 flex flex-col gap-2"></div>

<script>
const TASKS_DATA = <?= json_encode(array_map(fn($t)=>[
    'id' => $t['id'],
    'title' => $t['title'],
    'description' => $t['description'] ?? '',
    'teacher_name' => $t['teacher_name'] ?? '',
    'deadline' => $t['deadline'] ?? '',
    'status' => $t['status']
], $tasks)) ?>;

let currentView = localStorage.getItem('tasks-view') || 'kanban';

// ============ UBAH TAMPILAN ============
function applyView() {
    const listEl = document.getElementById('list-view');
    const kanbanEl = document.getElementById('kanban-view');
    const label = document.getElementById('view-label');
    if (currentView === 'list') {
        listEl.classList.remove('hidden');
        kanbanEl.classList.add('hidden');
        label.textContent = 'Tampilan Kolom';
    } else {
        listEl.classList.add('hidden');
        kanbanEl.classList.remove('hidden');
        label.textContent = 'Tampilan Daftar';
        updateKanbanCounts();
    }
}
document.getElementById('toggle-view').onclick = () => {
    currentView = currentView === 'list' ? 'kanban' : 'list';
    localStorage.setItem('tasks-view', currentView);
    applyView();
};

function updateKanbanCounts() {
    document.querySelectorAll('.kanban-drop').forEach(col => {
        const count = col.querySelectorAll('.task-card').length;
        const badge = document.querySelector(`[data-count="${col.dataset.status}"]`);
        if (badge) badge.textContent = count;
    });
}

// ============ MODAL ============
function openAddModal() {
    document.getElementById('modal-title').textContent = 'Tugas Baru';
    document.getElementById('form-action').value = 'add';
    document.getElementById('form-id').value = '';
    document.getElementById('form-title').value = '';
    document.getElementById('form-teacher').value = '';
    document.getElementById('form-deadline').value = '';
    document.getElementById('form-desc').value = '';
    document.getElementById('task-modal').classList.remove('hidden');
    setTimeout(() => document.getElementById('form-title').focus(), 100);
}

function openEditModal(id) {
    const t = TASKS_DATA.find(x => x.id == id);
    if (!t) return;
    document.getElementById('modal-title').textContent = 'Edit Tugas';
    document.getElementById('form-action').value = 'edit';
    document.getElementById('form-id').value = t.id;
    document.getElementById('form-title').value = t.title;
    document.getElementById('form-teacher').value = t.teacher_name;
    document.getElementById('form-deadline').value = t.deadline;
    document.getElementById('form-desc').value = t.description;
    document.getElementById('task-modal').classList.remove('hidden');
}

function closeModal() {
    document.getElementById('task-modal').classList.add('hidden');
}

function confirmDelete(id, title) {
    document.getElementById('delete-task-name').textContent = title;
    document.getElementById('delete-confirm-link').href = '?delete=' + id;
    document.getElementById('delete-modal').classList.remove('hidden');
}
function closeDeleteModal() {
    document.getElementById('delete-modal').classList.add('hidden');
}

// Tutup modal kalau klik backdrop
document.getElementById('task-modal').addEventListener('click', e => { if (e.target.id === 'task-modal') closeModal(); });
document.getElementById('delete-modal').addEventListener('click', e => { if (e.target.id === 'delete-modal') closeDeleteModal(); });

// ============ UBAH STATUS ============
async function updateStatus(id, status, el) {
    if (el) el.disabled = true;
    try {
        const res = await fetch('api/task_update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ id, status })
        });
        const r = await res.json();
        if (r.status === 'success' || r.success) {
            showToast('Status berhasil diubah ✅', 'success');
            setTimeout(() => location.reload(), 400);
        } else {
            showToast('Gagal: ' + (r.message || ''), 'error');
            if (el) el.disabled = false;
        }
    } catch (e) {
        showToast('Koneksi error', 'error');
        if (el) el.disabled = false;
    }
}

// ============ PENCARIAN & FILTER ============
function filterTasks() {
    const q = document.getElementById('search-input').value.toLowerCase().trim();
    const status = document.getElementById('filter-status').value;
    const sortBy = document.getElementById('sort-by').value;

    document.querySelectorAll('#list-view .task-card').forEach(card => {
        const matchQ = !q || card.dataset.title.includes(q) || card.dataset.teacher.includes(q);
        const matchS = !status || card.dataset.status === status;
        card.style.display = (matchQ && matchS) ? '' : 'none';
    });

    document.querySelectorAll('#kanban-view .task-card').forEach(card => {
        const matchQ = !q || card.dataset.title.includes(q) || card.dataset.teacher.includes(q);
        const matchS = !status || card.dataset.status === status;
        card.style.display = (matchQ && matchS) ? '' : 'none';
    });

    if (currentView === 'list') {
        const container = document.getElementById('list-view');
        const cards = Array.from(container.querySelectorAll('.task-card'));
        cards.sort((a, b) => {
            if (sortBy === 'deadline') {
                if (!a.dataset.deadline) return 1;
                if (!b.dataset.deadline) return -1;
                return a.dataset.deadline.localeCompare(b.dataset.deadline);
            } else if (sortBy === 'title') {
                return a.dataset.title.localeCompare(b.dataset.title);
            } else if (sortBy === 'created') {
                return b.dataset.created.localeCompare(a.dataset.created);
            }
            return a.dataset.status.localeCompare(b.dataset.status);
        });
        cards.forEach(c => container.appendChild(c));
    }
    updateKanbanCounts();
}

['search-input', 'filter-status', 'sort-by'].forEach(id => {
    document.getElementById(id).addEventListener('input', filterTasks);
});

// ============ GESER KARTU ANTAR KOLOM ============
document.querySelectorAll('.kanban-drop').forEach(col => {
    col.addEventListener('dragover', e => {
        e.preventDefault();
        col.classList.add('drag-over');
    });
    col.addEventListener('dragleave', () => col.classList.remove('drag-over'));
    col.addEventListener('drop', async e => {
        e.preventDefault();
        col.classList.remove('drag-over');
        const card = document.querySelector('.dragging');
        if (!card) return;
        const newStatus = col.dataset.status;
        if (card.dataset.status === newStatus) return;

        col.appendChild(card);
        card.dataset.status = newStatus;
        updateKanbanCounts();
        await updateStatus(card.dataset.id, newStatus);
    });
});

document.querySelectorAll('#kanban-view .task-card').forEach(card => {
    card.addEventListener('dragstart', () => card.classList.add('dragging'));
    card.addEventListener('dragend', () => card.classList.remove('dragging'));
});

// ============ NOTIFIKASI ============
function showToast(msg, type = 'success') {
    const el = document.createElement('div');
    el.className = `toast-enter px-4 py-3 rounded-lg shadow-lg text-white font-medium text-sm ${type === 'success' ? 'bg-green-500' : 'bg-red-500'}`;
    el.textContent = msg;
    document.getElementById('toast-container').appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 300); }, 2500);
}

// ============ MULAI ============
applyView();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>