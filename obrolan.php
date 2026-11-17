<?php
// obrolan.php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = currentUser();
$uid  = (int) $user['id'];
$isPembimbing = isPembimbing();

// Guard $pdo
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit('Koneksi database tidak tersedia.');
}

// Deteksi tabel chat
$hasChatTables = false;
$missingTables = [];

try {
    foreach (['chat_rooms', 'chat_members', 'messages'] as $table) {
        $exists = (bool) $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
        if (!$exists) $missingTables[] = $table;
    }
    $hasChatTables = empty($missingTables);
} catch (Throwable $e) {
    $missingTables = ['chat_rooms', 'chat_members', 'messages'];
}

if (!$hasChatTables) {
    require_once __DIR__ . '/includes/header.php';
    ?>
    <div class="max-w-xl mx-auto my-12 rounded-3xl border border-amber-200 bg-amber-50 p-8 text-center dark:border-amber-800 dark:bg-amber-900/20">
        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-amber-100 text-3xl dark:bg-amber-900/40">⚠️</div>
        <h2 class="mb-2 text-xl font-bold text-amber-800 dark:text-amber-300">Fitur Obrolan Belum Aktif</h2>
        <p class="mb-4 text-sm text-amber-700 dark:text-amber-300">Beberapa tabel berikut belum tersedia di database:</p>
        <ul class="mb-4 inline-block space-y-2 text-left text-sm text-amber-700 dark:text-amber-300">
            <?php foreach ($missingTables as $table): ?>
                <li>• <code><?= e($table) ?></code></li>
            <?php endforeach; ?>
        </ul>
        <p class="text-xs text-amber-600 dark:text-amber-400">Hubungi admin untuk mengaktifkan fitur obrolan.</p>
    </div>
    <?php
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

require_once __DIR__ . '/includes/header.php';
?>

<style>
    :root {
        --chat-primary: #4F46E5;
        --chat-primary-dark: #4338CA;
        --chat-purple: #7C3AED;
        --chat-blue: #3B82F6;
    }

    .chat-shell {
        height: min(86vh, 900px);
        min-height: 540px;
        border-color: #E2E8F0;
        border-radius: 18px;
        box-shadow: 0 12px 35px rgba(30, 41, 59, .08);
    }

    .dark .chat-shell {
        border-color: #334155;
        box-shadow: 0 12px 35px rgba(0, 0, 0, .18);
    }

    .chat-bg {
        background-color: #F8FAFC;
        background-image: radial-gradient(circle, rgba(79,70,229,.07) 1px, transparent 1px);
        background-size: 22px 22px;
    }

    .dark .chat-bg {
        background-color: #111827;
        background-image: radial-gradient(circle, rgba(129,140,248,.08) 1px, transparent 1px);
    }

    .chat-gradient { background: #4F46E5; }

    .bubble-out {
        background: #E9EAFE;
        color: #25245A;
        border-top-right-radius: 4px !important;
    }

    .dark .bubble-out {
        background: #373477;
        color: #F1F0FF;
    }

    .bubble-in {
        background: #FFFFFF;
        color: #334155;
        border: 1px solid #E9EDF5;
        border-top-left-radius: 4px !important;
    }

    .dark .bubble-in {
        background: #263247;
        color: #E2E8F0;
        border-color: #334155;
    }

    .bubble-out::after {
        content: '';
        position: absolute;
        top: 0;
        right: -8px;
        border-style: solid;
        border-width: 0 0 10px 10px;
        border-color: transparent transparent transparent #E9EAFE;
    }

    .dark .bubble-out::after {
        border-color: transparent transparent transparent #373477;
    }

    .bubble-in::after {
        content: '';
        position: absolute;
        top: 0;
        left: -8px;
        border-style: solid;
        border-width: 0 10px 10px 0;
        border-color: transparent #FFFFFF transparent transparent;
    }

    .dark .bubble-in::after {
        border-color: transparent #263247 transparent transparent;
    }

    .room-active {
        background: #EEF2FF;
        border-left: 3px solid #4F46E5;
    }

    .dark .room-active {
        background: #25264D;
        border-left-color: #818CF8;
    }

    .room-tab-active {
        background: #FFFFFF !important;
        color: #4F46E5 !important;
        box-shadow: 0 2px 7px rgba(15,23,42,.12);
    }

    .date-chip {
        background: #E9EAFE;
        color: #4338CA;
        border: 1px solid #DDE1FF;
    }

    .dark .date-chip {
        background: #292B55;
        color: #C7D2FE;
        border-color: #3F4174;
    }

    .quoted-bar { border-left: 4px solid #6366F1; }

    #chat-box::-webkit-scrollbar,
    #room-list::-webkit-scrollbar,
    #member-list::-webkit-scrollbar {
        width: 6px;
    }

    #chat-box::-webkit-scrollbar-thumb,
    #room-list::-webkit-scrollbar-thumb,
    #member-list::-webkit-scrollbar-thumb {
        background: #C7D2FE;
        border-radius: 10px;
    }

    .dark #chat-box::-webkit-scrollbar-thumb,
    .dark #room-list::-webkit-scrollbar-thumb,
    .dark #member-list::-webkit-scrollbar-thumb {
        background: #4B5185;
    }

    .chat-action { transition: all .2s ease; }
    .chat-action:hover {
        transform: translateY(-1px);
        filter: brightness(.96);
    }

    #send-btn { background: #4F46E5; }
    #send-btn:hover { background: #4338CA; }
    #send-btn:disabled { opacity: .45; }

    #chat-input:focus,
    #search-input:focus,
    #group-name:focus {
        border-color: #818CF8;
        box-shadow: 0 0 0 3px rgba(99,102,241,.12);
    }

    .attach-file {
        background: #F1F3FF;
        border: 1px solid #E0E7FF;
        border-radius: 12px;
        color: inherit;
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 8px 12px;
        text-decoration: none;
    }

    .dark .attach-file {
        background: #263247;
        border-color: #3A4560;
    }

    .attach-file-icon {
        border-radius: 10px;
        width: 36px;
        height: 36px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        flex-shrink: 0;
    }

    #context-menu {
        border-color: #E2E8F0;
        border-radius: 12px;
    }

    .dark #context-menu { border-color: #334155; }

    .menu-item:hover {
        background: #EEF2FF;
        color: #4338CA;
    }

    .dark .menu-item:hover {
        background: #30365B;
        color: #C7D2FE;
    }

    #create-group-modal > div,
    #new-chat-modal > div {
        border: 1px solid #E2E8F0;
        border-radius: 18px;
    }

    .dark #create-group-modal > div,
    .dark #new-chat-modal > div { border-color: #334155; }

    .chat-action,
    .room-item,
    .user-item,
    #send-btn { transition: all .2s ease; }

    .attach-img {
        max-width: 260px;
        max-height: 300px;
        border-radius: 12px;
        cursor: pointer;
        display: block;
    }

    .attach-file-info {
        min-width: 0;
        flex: 1;
    }

    .attach-file-name {
        font-weight: 600;
        font-size: 13px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .attach-file-size {
        font-size: 11px;
        opacity: .6;
    }

    .upload-overlay {
        position: absolute;
        inset: 0;
        background: rgba(15,23,42,.55);
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        color: white;
        font-size: 12px;
    }

    .spinner-sm {
        border: 2px solid rgba(255,255,255,.3);
        border-top-color: white;
        border-radius: 50%;
        width: 20px;
        height: 20px;
        animation: spin 1s linear infinite;
        display: inline-block;
    }

    @keyframes spin { to { transform: rotate(360deg); } }

    .caption-text {
        font-size: 13px;
        margin-top: 4px;
        word-break: break-word;
    }

    .msg-hl {
        outline: 2px solid #FBBF24;
        outline-offset: 2px;
        border-radius: 8px;
    }

    #preview-modal { backdrop-filter: blur(8px); }

    @media (max-width: 767px) {
        .chat-shell {
            height: calc(100dvh - 120px);
            min-height: 450px;
            border-radius: 14px;
        }
        .attach-img { max-width: 210px; }
    }
</style>

<div class="chat-shell relative flex overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-xl dark:border-slate-700 dark:bg-slate-900">

    <!-- SIDEBAR -->
    <aside id="sidebar" class="absolute inset-0 z-20 flex w-full shrink-0 flex-col border-r border-slate-200 bg-white transition-all duration-300 dark:border-slate-700 dark:bg-slate-900 md:relative md:w-80">

        <div class="chat-gradient p-4 text-white">
            <div class="mb-4 flex items-center justify-between gap-3">
                <div class="flex min-w-0 items-center gap-3">
                    <img src="<?= e(avatarUrl($user['photo'] ?? null, $user['name'] ?? 'User', 40)) ?>"
                         class="h-10 w-10 rounded-full border-2 border-white/40 object-cover"
                         alt="Foto profil">
                    <div class="min-w-0">
                        <div class="truncate text-sm font-bold"><?= e($user['name']) ?></div>
                        <div class="text-xs text-indigo-100"><?= $isPembimbing ? 'Pembimbing' : 'Siswa' ?></div>
                    </div>
                </div>
                <a href="<?= BASE_URL ?>/dashboard.php"
                   class="rounded-xl p-2 transition hover:bg-white/15"
                   title="Kembali ke dashboard">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                </a>
            </div>

            <div class="mb-3 flex gap-1 rounded-xl bg-black/10 p-1">
                <button data-room-tab="all" class="room-tab room-tab-active flex-1 rounded-lg px-2 py-2 text-xs font-semibold text-white transition">Semua</button>
                <button data-room-tab="group" class="room-tab flex-1 rounded-lg px-2 py-2 text-xs font-semibold text-white transition">Grup</button>
                <button data-room-tab="private" class="room-tab flex-1 rounded-lg px-2 py-2 text-xs font-semibold text-white transition">Pribadi</button>
            </div>

            <div class="relative">
                <svg class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-white/70" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
                <input id="room-search" type="text" placeholder="Cari percakapan..."
                       class="w-full rounded-xl border border-white/15 bg-white/15 py-2.5 pl-9 pr-4 text-sm text-white placeholder-white/70 outline-none transition focus:bg-white/25">
            </div>
        </div>

        <div id="room-list" class="flex-1 overflow-y-auto">
            <div class="p-4 text-center text-sm text-slate-400">Memuat chat...</div>
        </div>

        <div class="flex gap-2 border-t border-slate-100 p-3 dark:border-slate-700">
            <button onclick="openCreateGroup()" class="chat-action flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-indigo-600 py-3 text-xs font-semibold text-white shadow-sm hover:bg-indigo-700">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Grup Baru
            </button>
            <button onclick="openNewChat()" class="chat-action flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 py-3 text-xs font-semibold text-white shadow-sm hover:opacity-90">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/>
                </svg>
                Chat Baru
            </button>
        </div>
    </aside>

    <!-- AREA CHAT -->
    <div class="relative flex min-w-0 flex-1 flex-col">
        <div class="chat-gradient z-10 flex shrink-0 items-center gap-2 px-3 py-3 text-white shadow-md sm:px-4">
            <button onclick="toggleSidebar()" class="rounded-xl p-2 transition hover:bg-white/15" title="Daftar chat">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>

            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-white/20">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>

            <div class="min-w-0 flex-1">
                <h2 id="room-name" class="truncate text-sm font-bold sm:text-base">Obrolan</h2>
                <p id="room-subtitle" class="truncate text-xs text-indigo-100">Pilih atau buat percakapan</p>
            </div>

            <button id="search-btn" class="rounded-xl p-2 transition hover:bg-white/15" title="Cari pesan">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                </svg>
            </button>

            <button id="info-btn" class="rounded-xl p-2 transition hover:bg-white/15" title="Info ruang chat">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </button>
        </div>

        <div id="search-bar" class="hidden items-center gap-2 border-b border-slate-200 bg-white px-4 py-2 dark:border-slate-700 dark:bg-slate-800">
            <input id="search-input" type="text" placeholder="Cari pesan dalam percakapan..."
                   class="min-w-0 flex-1 rounded-xl bg-slate-100 px-4 py-2.5 text-sm outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-slate-700 dark:text-white">
            <button id="search-close" class="rounded-lg p-2 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div id="chat-box" class="chat-bg flex flex-1 flex-col gap-1 overflow-y-auto p-3 sm:p-4">
            <p id="loading-msg" class="mt-4 text-center text-sm text-slate-500 dark:text-slate-400">Pilih percakapan untuk memulai.</p>
        </div>

        <button id="scroll-bottom" class="absolute bottom-28 right-5 z-10 hidden rounded-full border border-slate-200 bg-white p-3 text-indigo-600 shadow-lg transition hover:bg-indigo-50 dark:border-slate-700 dark:bg-slate-800 dark:text-indigo-300">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
            </svg>
        </button>

        <div id="reply-preview" class="hidden items-start gap-2 border-t border-slate-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-800">
            <div class="quoted-bar min-w-0 flex-1 pl-3 text-xs">
                <div id="reply-to-name" class="font-bold text-indigo-600 dark:text-indigo-300"></div>
                <div id="reply-to-text" class="truncate text-slate-500 dark:text-slate-400"></div>
            </div>
            <button id="reply-close" class="shrink-0 rounded-lg p-1 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-700">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="shrink-0 border-t border-slate-200 bg-white p-2 dark:border-slate-700 dark:bg-slate-800 sm:p-3">
            <form id="chat-form" class="flex items-end gap-1.5 sm:gap-2">
                <button type="button" id="attach-btn" class="rounded-xl p-2.5 text-slate-500 transition hover:bg-indigo-50 hover:text-indigo-600 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-indigo-300" title="Lampiran">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/>
                    </svg>
                </button>

                <button type="button" id="emoji-btn" class="rounded-xl p-2.5 text-slate-500 transition hover:bg-indigo-50 hover:text-indigo-600 dark:text-slate-300 dark:hover:bg-slate-700 dark:hover:text-indigo-300" title="Emoji">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </button>

                <textarea id="chat-input" rows="1" placeholder="Tulis pesan..."
                          class="max-h-32 min-w-0 flex-1 resize-none rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm outline-none transition focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 disabled:cursor-not-allowed disabled:opacity-60 dark:border-slate-600 dark:bg-slate-700 dark:text-white dark:focus:ring-indigo-900"
                          disabled></textarea>

                <button type="submit" id="send-btn" class="chat-gradient flex h-11 w-11 shrink-0 items-center justify-center rounded-xl text-white shadow-md transition hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-40" disabled title="Kirim pesan">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12l14-7-4 14-3-6-7-1zm7 1l7-8"/>
                    </svg>
                </button>
            </form>

            <div id="emoji-panel" class="mt-2 hidden max-h-40 grid-cols-8 gap-1 overflow-y-auto rounded-xl border border-slate-200 bg-white p-2 text-xl shadow-lg dark:border-slate-700 dark:bg-slate-700"></div>

            <div id="attach-panel" class="mt-2 hidden gap-2 rounded-xl border border-slate-200 bg-white p-2 shadow-lg dark:border-slate-700 dark:bg-slate-700">
                <label class="flex flex-1 cursor-pointer flex-col items-center gap-2 rounded-xl p-3 transition hover:bg-indigo-50 dark:hover:bg-slate-600">
                    <svg class="h-8 w-8 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                    </svg>
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-200">Gambar</span>
                    <input type="file" id="file-image" accept="image/*" class="hidden">
                </label>

                <label class="flex flex-1 cursor-pointer flex-col items-center gap-2 rounded-xl p-3 transition hover:bg-indigo-50 dark:hover:bg-slate-600">
                    <svg class="h-8 w-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    <span class="text-xs font-medium text-slate-600 dark:text-slate-200">Dokumen</span>
                    <input type="file" id="file-doc" class="hidden">
                </label>
            </div>
        </div>
    </div>
</div>

<!-- PREVIEW GAMBAR -->
<div id="preview-modal" class="fixed inset-0 z-[60] hidden items-center justify-center bg-slate-950/95 p-4">
    <button onclick="closePreviewImage()" class="absolute right-4 top-4 rounded-xl p-2 text-white hover:bg-white/10">
        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
    </button>
    <img id="preview-img" class="max-h-full max-w-full rounded-xl object-contain" alt="Pratinjau gambar">
</div>

<!-- MODAL BUAT GRUP -->
<div id="create-group-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
    <div class="flex max-h-[85vh] w-full max-w-md flex-col overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-slate-800">
        <div class="chat-gradient flex items-center justify-between px-5 py-4 text-white">
            <h3 class="font-bold">Buat Grup Baru</h3>
            <button onclick="closeCreateGroup()" class="rounded-lg p-1 hover:bg-white/15">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="flex-1 space-y-4 overflow-y-auto p-5">
            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700 dark:text-slate-200">Nama Grup <span class="text-red-500">*</span></label>
                <input type="text" id="group-name" maxlength="80" placeholder="Contoh: Tim PKL"
                       class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-100 dark:border-slate-600 dark:bg-slate-700 dark:text-white">
            </div>
            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700 dark:text-slate-200">Pilih Anggota</label>
                <div id="member-list" class="max-h-64 space-y-1 overflow-y-auto rounded-xl border border-slate-200 p-2 dark:border-slate-600">
                    <p class="py-2 text-center text-sm text-slate-400">Memuat...</p>
                </div>
            </div>
        </div>

        <div class="flex justify-end gap-2 border-t border-slate-100 px-5 py-4 dark:border-slate-700">
            <button onclick="closeCreateGroup()" class="rounded-xl bg-slate-100 px-4 py-2.5 text-sm font-semibold text-slate-600 dark:bg-slate-700 dark:text-slate-200">Batal</button>
            <button onclick="submitCreateGroup()" class="chat-gradient rounded-xl px-5 py-2.5 text-sm font-semibold text-white shadow-md">Buat Grup</button>
        </div>
    </div>
</div>

<!-- MODAL CHAT BARU -->
<div id="new-chat-modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-slate-950/50 p-4 backdrop-blur-sm">
    <div class="flex max-h-[85vh] w-full max-w-md flex-col overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-slate-800">
        <div class="chat-gradient flex items-center justify-between px-5 py-4 text-white">
            <h3 class="font-bold">Mulai Chat Baru</h3>
            <button onclick="closeNewChat()" class="rounded-lg p-1 hover:bg-white/15">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="overflow-y-auto p-4">
            <div id="users-list" class="space-y-1">
                <p class="py-4 text-center text-sm text-slate-400">Memuat...</p>
            </div>
        </div>
    </div>
</div>

<audio id="notif-sound" preload="auto">
    <source src="data:audio/wav;base64,UklGRl9vT19XQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQAAAAA=" type="audio/wav">
</audio>
<div id="context-menu" class="fixed z-50 hidden min-w-[180px] overflow-hidden rounded-xl border border-slate-100 bg-white shadow-2xl dark:border-slate-700 dark:bg-slate-800"></div>

<script>
/* ================= KONFIGURASI ================= */
const BASE_URL = <?= json_encode(BASE_URL) ?>;
const UPLOAD_BASE = <?= json_encode(UPLOAD_URL) ?>;
const CURRENT_USER_ID = <?= (int)$user['id'] ?>;

const chatBox = document.getElementById('chat-box');
const chatForm = document.getElementById('chat-form');
const chatInput = document.getElementById('chat-input');
const sendBtn = document.getElementById('send-btn');
const contextMenu = document.getElementById('context-menu');
const replyPreview = document.getElementById('reply-preview');
const scrollBtn = document.getElementById('scroll-bottom');
const sidebar = document.getElementById('sidebar');

let currentRoomId = 0;
let currentRoomName = '';
let currentRoomType = '';
let lastRenderedId = 0;
let lastDate = null;
let isAtBottom = true;
let replyTo = null;
let editingId = null;
let currentRoomTab = 'all';
let allRooms = [];
let allUsers = [];
let fetchInFlight = false;
let pendingRoomFetch = false;
let roomsInFlight = false;
let typingTimer = null;
let typingSent = false;
let pollTimer = null;
let roomsPollTimer = null;

/* ================= UTILITAS ================= */
window.esc = window.esc || (s => String(s ?? '').replace(/[&<>"']/g, c => ({
    '&':'&amp;',
    '<':'&lt;',
    '>':'&gt;',
    '"':'&quot;',
    "'":'&#39;'
}[c])));

function formatBytes(b) {
    if (!b) return '0 B';
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
    if (b < 1073741824) return (b / 1048576).toFixed(1) + ' MB';
    return (b / 1073741824).toFixed(2) + ' GB';
}

const isMeRole = role => ['pembimbing', 'teacher', 'admin'].includes(role);

function jsonResponse(res) {
    return res.text().then(text => {
        try {
            return JSON.parse(text);
        } catch {
            console.error('Respons server bukan JSON:', text.substring(0, 300));
            throw new Error('Respons server tidak valid.');
        }
    });
}

/* ================= PREVIEW GAMBAR ================= */
function openPreviewImage(src) {
    const modal = document.getElementById('preview-modal');
    document.getElementById('preview-img').src = src;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function closePreviewImage() {
    const modal = document.getElementById('preview-modal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closePreviewImage();
        hideMenu();
        cancelReply();
        closeCreateGroup();
        closeNewChat();
    }
});

/* ================= SIDEBAR ================= */
function toggleSidebar() {
    if (window.innerWidth < 768) {
        sidebar.classList.toggle('hidden');
    } else {
        sidebar.classList.toggle('md:w-0');
        sidebar.classList.toggle('md:border-r-0');
        sidebar.classList.toggle('overflow-hidden');
    }
}

/* ================= EMPTY STATE ================= */
function showEmptyState() {
    chatInput.disabled = true;
    sendBtn.disabled = true;

    document.getElementById('room-name').textContent = 'Obrolan';
    document.getElementById('room-subtitle').textContent = 'Pilih atau buat percakapan';

    chatBox.innerHTML = `
        <div class="flex h-full flex-col items-center justify-center px-4 py-16 text-center">
            <div class="mb-5 flex h-24 w-24 items-center justify-center rounded-3xl bg-gradient-to-br from-indigo-100 via-purple-100 to-blue-100 text-5xl dark:from-indigo-900/40 dark:via-purple-900/40 dark:to-blue-900/40">💬</div>
            <h3 class="mb-2 text-lg font-bold text-slate-800 dark:text-white">Mulai Percakapan</h3>
            <p class="mb-6 max-w-xs text-sm text-slate-500 dark:text-slate-400">Belum ada ruang obrolan. Buat grup atau mulai chat pribadi.</p>
            <div class="flex flex-wrap justify-center gap-2">
                <button onclick="openCreateGroup()" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700">＋ Buat Grup</button>
                <button onclick="openNewChat()" class="rounded-xl bg-gradient-to-r from-purple-600 to-blue-600 px-4 py-2.5 text-sm font-semibold text-white">Chat Baru</button>
            </div>
        </div>`;
}

/* ================= DAFTAR ROOM ================= */
async function loadRooms() {
    if (roomsInFlight) return allRooms;

    roomsInFlight = true;
    try {
        const res = await fetch(`${BASE_URL}/api/obrolan_rooms.php`, {
            credentials: 'same-origin',
            cache: 'no-store'
        });

        if (!res.ok) throw new Error('Gagal mengambil daftar chat.');

        const data = await jsonResponse(res);
        allRooms = Array.isArray(data) ? data : [];
        renderRooms();
        return allRooms;
    } catch (e) {
        console.error(e);
        return allRooms;
    } finally {
        roomsInFlight = false;
    }
}

function renderRooms() {
    const search = document.getElementById('room-search').value.toLowerCase().trim();
    const list = document.getElementById('room-list');

    let filtered = allRooms.filter(room =>
        currentRoomTab === 'all' || room.type === currentRoomTab
    );

    if (search) {
        filtered = filtered.filter(room =>
            (room.name || '').toLowerCase().includes(search)
        );
    }

    if (!filtered.length) {
        list.innerHTML = `
            <div class="p-7 text-center">
                <div class="mb-3 text-4xl">📭</div>
                <p class="text-sm font-semibold text-slate-600 dark:text-slate-300">Chat tidak ditemukan</p>
                <p class="mt-1 text-xs text-slate-400">Coba kata kunci lain atau buat percakapan baru.</p>
            </div>`;
        return;
    }

    list.innerHTML = filtered.map(room => {
        const active = room.id == currentRoomId ? 'room-active' : '';
        const photo = room.photo
            ? UPLOAD_BASE + room.photo
            : `https://ui-avatars.com/api/?name=${encodeURIComponent(room.name || 'Grup')}&background=4F46E5&color=fff&size=80`;

        const icon = room.type === 'group' ? '👥' : '👤';
        const preview = room.last_msg
            ? `${esc(room.last_sender || '')}: ${esc(String(room.last_msg).substring(0, 35))}`
            : 'Belum ada pesan';

        return `
            <div data-room-id="${esc(room.id)}"
                 data-room-name="${esc(room.name || 'Chat')}"
                 data-room-type="${esc(room.type || 'group')}"
                 class="room-item ${active} flex cursor-pointer items-center gap-3 border-b border-slate-100 p-3 transition hover:bg-indigo-50 dark:border-slate-800 dark:hover:bg-slate-800">
                <img src="${esc(photo)}" onerror="this.src='https://ui-avatars.com/api/?name=Chat&background=4F46E5&color=fff'"
                     class="h-11 w-11 shrink-0 rounded-2xl object-cover shadow-sm" alt="">
                <div class="min-w-0 flex-1">
                    <div class="mb-1 flex items-center justify-between gap-2">
                        <div class="truncate text-sm font-bold text-slate-800 dark:text-slate-100">${icon} ${esc(room.name || 'Grup')}</div>
                        ${Number(room.unread) > 0
                            ? `<span class="shrink-0 rounded-full bg-indigo-600 px-2 py-0.5 text-[10px] font-bold text-white">${esc(room.unread)}</span>`
                            : ''}
                    </div>
                    <div class="truncate text-xs text-slate-500 dark:text-slate-400">${preview}</div>
                </div>
            </div>`;
    }).join('');

    list.querySelectorAll('.room-item').forEach(el => {
        el.onclick = () => switchRoom(
            Number(el.dataset.roomId),
            el.dataset.roomName,
            el.dataset.roomType
        );
    });
}

/* ================= PINDAH ROOM ================= */
function switchRoom(id, name, type) {
    id = Number(id);
    if (!id || currentRoomId === id) return;

    currentRoomId = id;
    currentRoomName = name || 'Chat';
    currentRoomType = type || 'group';

    lastRenderedId = 0;
    lastDate = null;
    isAtBottom = true;
    replyTo = null;
    editingId = null;
    replyPreview.classList.add('hidden');

    chatBox.innerHTML = '<p id="loading-msg" class="mt-4 text-center text-sm text-slate-500 dark:text-slate-400">Memuat pesan...</p>';

    document.getElementById('room-name').textContent = currentRoomName;
    document.getElementById('room-subtitle').textContent =
        currentRoomType === 'group' ? 'Grup percakapan' : 'Chat pribadi';

    chatInput.disabled = false;
    sendBtn.disabled = false;

    renderRooms();

    if (fetchInFlight) {
        pendingRoomFetch = true;
    } else {
        fetchMessages(true);
    }

    if (window.innerWidth < 768) sidebar.classList.add('hidden');

    try {
        localStorage.setItem('lastRoomId', String(id));
    } catch (e) {}
}

/* ================= AMBIL PESAN ================= */
async function fetchMessages(reset = false) {
    if (!currentRoomId) return;

    if (fetchInFlight) {
        pendingRoomFetch = true;
        return;
    }

    fetchInFlight = true;
    const requestedRoomId = currentRoomId;

    if (reset) {
        lastRenderedId = 0;
        lastDate = null;
        chatBox.innerHTML = '';
    }

    try {
        const url = `${BASE_URL}/api/obrolan_fetch.php?room_id=${encodeURIComponent(requestedRoomId)}${lastRenderedId ? '&since_id=' + lastRenderedId : ''}`;

        const res = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store'
        });

        if (!res.ok) throw new Error('Gagal mengambil pesan.');

        const messages = await jsonResponse(res);

        if (requestedRoomId !== currentRoomId) return;

        document.getElementById('loading-msg')?.remove();

        if (!Array.isArray(messages)) return;

        if (messages.length === 0 && lastRenderedId === 0) {
            chatBox.innerHTML = `
                <div class="mt-8 text-center text-sm text-slate-500 dark:text-slate-400">
                    <div class="mb-2 text-3xl">👋</div>
                    Belum ada pesan. Mulai percakapan!
                </div>`;
        }

        const newCount = messages.filter(message =>
            Number(message.id) > lastRenderedId && !message.is_me
        ).length;

        if (newCount > 0 && lastRenderedId > 0) {
            const sound = document.getElementById('notif-sound');
            sound?.play().catch(() => {});
        }

        let html = '';
        let prevSender = null;
        let newLastId = lastRenderedId;
        const wasAtBottom = isAtBottom;

        messages.forEach(message => {
            if (Number(message.id) <= lastRenderedId) {
                prevSender = message.name;
                return;
            }

            html += renderMessage(message, prevSender);
            prevSender = message.name;
            newLastId = Number(message.id);
        });

        if (html) {
            if (chatBox.querySelector('p.text-center') || chatBox.querySelector('div.text-center')) {
                chatBox.innerHTML = '';
            }

            chatBox.insertAdjacentHTML('beforeend', html);
            lastRenderedId = newLastId;

            if (wasAtBottom) {
                scrollToBottom(true);
            } else {
                scrollBtn.classList.remove('hidden');
            }
        }

        fetch(`${BASE_URL}/api/obrolan_mark_read.php`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({room_id: requestedRoomId})
        }).catch(() => {});

        loadRooms();
    } catch (e) {
        console.error(e);
        if (requestedRoomId === currentRoomId) {
            document.getElementById('loading-msg')?.remove();
        }
    } finally {
        fetchInFlight = false;

        if (pendingRoomFetch) {
            pendingRoomFetch = false;
            if (currentRoomId) fetchMessages(true);
        }
    }
}

/* ================= SCROLL ================= */
function scrollToBottom(force = false) {
    if (force || isAtBottom) {
        chatBox.scrollTop = chatBox.scrollHeight;
    }
}

chatBox.addEventListener('scroll', () => {
    isAtBottom = chatBox.scrollHeight - chatBox.scrollTop - chatBox.clientHeight < 80;
    scrollBtn.classList.toggle('hidden', isAtBottom);
});

scrollBtn.onclick = () => {
    chatBox.scrollTop = chatBox.scrollHeight;
    isAtBottom = true;
    scrollBtn.classList.add('hidden');
};

/* ================= TANGGAL PESAN ================= */
function dateLabel(date) {
    const dt = new Date(date);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);

    const same = (a, b) => a.toDateString() === b.toDateString();

    if (same(dt, today)) return 'Hari Ini';
    if (same(dt, yesterday)) return 'Kemarin';

    return dt.toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'long',
        year: 'numeric'
    });
}

/* ================= RENDER PESAN ================= */
function renderMessage(msg, prevSender) {
    const dateKey = new Date(msg.created_at_full).toDateString();
    let html = '';

    if (dateKey !== lastDate) {
        html += `
            <div class="my-4 text-center">
                <span class="date-chip rounded-full px-4 py-1.5 text-[11px] font-semibold shadow-sm">
                    ${esc(dateLabel(msg.created_at_full))}
                </span>
            </div>`;
        lastDate = dateKey;
        prevSender = null;
    }

    const isMine = !!msg.is_me;
    const showAvatar = !isMine && prevSender !== msg.name;
    const bubbleClass = isMine ? 'bubble-out' : 'bubble-in';
    const wrapperClass = isMine ? 'ml-auto flex-row-reverse' : '';

    const ticks = isMine
        ? `<svg class="ml-1 inline-block h-3.5 w-3.5 ${msg.is_read ? 'text-blue-500' : 'text-slate-400'}" viewBox="0 0 16 15" fill="currentColor"><path d="M15.01 3.316l-.478-.372a.365.365 0 00-.51.063L8.666 9.879a.32.32 0 01-.484.033l-.358-.325a.32.32 0 00-.484.032l-.378.483a.418.418 0 00.036.541l1.32 1.266c.143.14.361.125.484-.033l6.272-8.048a.366.366 0 00-.064-.512z"/></svg>`
        : '';

    let replyHtml = '';

    if (msg.reply_to_id && msg.reply_content) {
        replyHtml = `
            <div class="quoted-bar mb-2 rounded-lg bg-indigo-50/80 py-2 pl-3 pr-2 text-xs dark:bg-slate-900/50">
                <div class="font-bold text-indigo-600 dark:text-indigo-300">${esc(msg.reply_name || 'User')}</div>
                <div class="truncate text-slate-600 dark:text-slate-300">${esc(msg.reply_content)}</div>
            </div>`;
    }

    let contentHtml = '';

    if (msg.deleted_at) {
        contentHtml = `<i class="text-sm text-slate-500">🚫 Pesan telah dihapus</i>`;
    } else if (msg.attachment_type === 'image') {
        const imageSrc = esc(msg.attachment);
        contentHtml = `
            <div class="relative">
                <img src="${imageSrc}" class="attach-img" onclick="openPreviewImage(this.src)" loading="lazy" alt="Lampiran gambar">
            </div>`;
        if (msg.content) contentHtml += `<div class="caption-text">${esc(msg.content)}</div>`;
    } else if (msg.attachment_type === 'audio') {
        contentHtml = `
            <div class="mb-1 flex items-center gap-2 rounded-xl bg-indigo-500/10 p-2 dark:bg-white/10">
                <div class="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-600 text-white">🎤</div>
                <audio controls src="${esc(msg.attachment)}" class="h-8 max-w-[200px]"></audio>
            </div>`;
        if (msg.content) contentHtml += `<div class="caption-text">${esc(msg.content)}</div>`;
    } else if (msg.attachment_type === 'file') {
        const ext = (msg.file_name || '').split('.').pop().toLowerCase();

        const fileIcons = {
            pdf: ['📄', '#ef4444'],
            doc: ['📝', '#3b82f6'],
            docx: ['📝', '#3b82f6'],
            xls: ['📊', '#22c55e'],
            xlsx: ['📊', '#22c55e'],
            ppt: ['📽️', '#f97316'],
            pptx: ['📽️', '#f97316'],
            zip: ['🗜️', '#a855f7'],
            rar: ['🗜️', '#a855f7'],
            txt: ['📃', '#6b7280'],
            csv: ['📊', '#22c55e']
        };

        const [icon, color] = fileIcons[ext] || ['📎', '#6366f1'];

        contentHtml = `
            <a href="${esc(msg.attachment)}" download="${esc(msg.file_name)}" class="attach-file">
                <div class="attach-file-icon" style="background:${color}20;color:${color}">${icon}</div>
                <div class="attach-file-info">
                    <div class="attach-file-name">${esc(msg.file_name || 'File')}</div>
                    <div class="attach-file-size">${formatBytes(msg.file_size)}</div>
                </div>
                <svg class="h-5 w-5 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
            </a>`;

        if (msg.content) contentHtml += `<div class="caption-text">${esc(msg.content)}</div>`;
    } else {
        contentHtml = `<div class="whitespace-pre-wrap break-words pr-14">${esc(msg.content)}</div>`;
    }

    const editedLabel = msg.edited_at
        ? `<span class="ml-1 text-[10px] italic text-slate-500">(diedit)</span>`
        : '';

    html += `
        <div class="mt-${showAvatar ? '3' : '1'} flex max-w-[88%] items-end gap-1.5 md:max-w-[65%] ${wrapperClass}"
             data-msg-id="${esc(msg.id)}"
             data-msg-name="${esc(msg.name)}"
             data-msg-ismine="${isMine ? 1 : 0}"
             data-msg-deleted="${msg.deleted_at ? 1 : 0}">
            ${!isMine ? `
                <div class="w-8 shrink-0">
                    ${showAvatar ? `<img src="${esc(msg.photo)}" onerror="this.style.display='none'" class="h-8 w-8 rounded-full object-cover shadow-sm" alt="">` : ''}
                </div>` : ''}
            <div class="relative min-w-0">
                ${showAvatar ? `<div class="mb-1 px-1 text-[11px] font-bold text-indigo-600 dark:text-indigo-300">${esc(msg.name)}</div>` : ''}
                <div class="msg-bubble relative rounded-xl px-3 py-2 text-sm shadow-sm ${bubbleClass}"
                     data-content="${esc(msg.content || '')}"
                     data-attachment="${esc(msg.attachment || '')}"
                     oncontextmenu="openMenu(event, this.closest('[data-msg-id]'))">
                    ${replyHtml}
                    ${contentHtml}
                    <div class="absolute bottom-1 right-2 flex items-center gap-1 text-[10px] ${isMine ? 'text-indigo-900/60 dark:text-indigo-100/70' : 'text-slate-400'}">
                        <span>${esc(msg.created_at)}</span>
                        ${editedLabel}
                        ${ticks}
                    </div>
                </div>
            </div>
        </div>`;

    return html;
}

/* ================= TYPING ================= */
chatInput.addEventListener('input', () => {
    chatInput.style.height = 'auto';
    chatInput.style.height = Math.min(chatInput.scrollHeight, 128) + 'px';

    if (!typingSent && currentRoomId) {
        fetch(`${BASE_URL}/api/obrolan_typing.php`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({room_id: currentRoomId, typing: true})
        }).catch(() => {});

        typingSent = true;
    }

    clearTimeout(typingTimer);
    typingTimer = setTimeout(() => {
        typingSent = false;
    }, 2500);
});

chatInput.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        chatForm.requestSubmit();
    }
});

/* ================= KIRIM PESAN ================= */
chatForm.addEventListener('submit', async e => {
    e.preventDefault();

    if (!currentRoomId) return;

    const roomAtSubmit = currentRoomId;

    if (editingId) {
        const content = chatInput.value.trim();
        if (!content) return;

        sendBtn.disabled = true;

        try {
            const res = await fetch(BASE_URL + '/api/obrolan_edit.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                credentials: 'same-origin',
                body: JSON.stringify({id: editingId, content})
            });

            const result = await jsonResponse(res);

            if (result.status === 'success') {
                editingId = null;
                chatInput.value = '';
                chatInput.style.height = 'auto';
                cancelReply();
                lastRenderedId = 0;
                lastDate = null;
                await fetchMessages(true);
                scrollToBottom(true);
            } else {
                alert(result.message || 'Pesan gagal diedit.');
            }
        } catch (err) {
            console.error(err);
            alert('Tidak dapat mengedit pesan. Periksa koneksi.');
        } finally {
            sendBtn.disabled = false;
        }

        return;
    }

    const content = chatInput.value.trim();
    if (!content) return;

    sendBtn.disabled = true;

    try {
        const body = { content, room_id: roomAtSubmit };
        if (replyTo) body.reply_to_id = replyTo.id;

        const res = await fetch(BASE_URL + '/api/obrolan_send.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify(body)
        });

        const result = await jsonResponse(res);

        if (result.status === 'success') {
            chatInput.value = '';
            chatInput.style.height = 'auto';
            cancelReply();

            if (currentRoomId === roomAtSubmit) {
                await fetchMessages();
                scrollToBottom(true);
            }
        } else {
            alert(result.message || 'Pesan gagal dikirim.');
        }
    } catch (err) {
        console.error(err);
        alert('Pesan gagal dikirim. Periksa koneksi.');
    } finally {
        sendBtn.disabled = false;
        chatInput.focus();
    }
});

/* ================= BALAS PESAN ================= */
function setReply(el) {
    const content = el.querySelector('.msg-bubble')?.dataset.content || '';

    replyTo = {
        id: el.dataset.msgId,
        name: el.dataset.msgName,
        content: content || '[Lampiran]'
    };

    editingId = null;

    document.getElementById('reply-to-name').textContent = replyTo.name;
    document.getElementById('reply-to-text').textContent = replyTo.content;

    replyPreview.classList.remove('hidden');
    replyPreview.classList.add('flex');
    chatInput.focus();
}

function cancelReply() {
    replyTo = null;
    editingId = null;
    replyPreview.classList.add('hidden');
    replyPreview.classList.remove('flex');
}

document.getElementById('reply-close').onclick = cancelReply;

/* ================= MENU PESAN ================= */
function openMenu(e, msgEl) {
    e.preventDefault();
    e.stopPropagation();

    const id = msgEl.dataset.msgId;
    const isMine = msgEl.dataset.msgIsmine === '1';

    if (msgEl.dataset.msgDeleted === '1') return;

    const bubble = msgEl.querySelector('.msg-bubble');
    const content = bubble?.dataset.content || '';

    const items = [
        {
            label: 'Balas',
            icon: '↩️',
            action: () => setReply(msgEl)
        },
        {
            label: 'Salin',
            icon: '📋',
            action: async () => {
                if (!content) return;
                try {
                    await navigator.clipboard.writeText(content);
                } catch {
                    alert('Tidak dapat menyalin pesan.');
                }
            }
        }
    ];

    if (isMine && content) {
        items.push({
            label: 'Edit',
            icon: '✏️',
            action: () => {
                editingId = id;
                replyTo = null;

                chatInput.value = content;
                chatInput.focus();

                document.getElementById('reply-to-name').textContent = '✏️ Mengedit pesan';
                document.getElementById('reply-to-text').textContent = content;

                replyPreview.classList.remove('hidden');
                replyPreview.classList.add('flex');
            }
        });
    }

    if (isMine) {
        items.push({
            label: 'Hapus',
            icon: '🗑️',
            action: async () => {
                if (!confirm('Yakin ingin menghapus pesan ini?')) return;

                try {
                    const res = await fetch(BASE_URL + '/api/obrolan_delete.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        credentials: 'same-origin',
                        body: JSON.stringify({id})
                    });

                    const result = await jsonResponse(res);

                    if (result.status === 'success') {
                        lastRenderedId = 0;
                        lastDate = null;
                        await fetchMessages(true);
                    } else {
                        alert(result.message || 'Pesan gagal dihapus.');
                    }
                } catch (err) {
                    console.error(err);
                    alert('Gagal menghapus pesan.');
                }
            }
        });
    }

    contextMenu.innerHTML = items.map((item, index) => `
        <button class="menu-item flex w-full items-center gap-3 px-4 py-3 text-left text-sm text-slate-700 transition hover:bg-indigo-50 dark:text-slate-200 dark:hover:bg-slate-700"
                data-idx="${index}">
            <span>${item.icon}</span>
            <span>${item.label}</span>
        </button>
    `).join('');

    contextMenu.classList.remove('hidden');

    const rect = contextMenu.getBoundingClientRect();
    const left = Math.min(e.clientX, window.innerWidth - rect.width - 8);
    const top = Math.min(e.clientY, window.innerHeight - rect.height - 8);

    contextMenu.style.left = Math.max(8, left) + 'px';
    contextMenu.style.top = Math.max(8, top) + 'px';

    contextMenu.querySelectorAll('.menu-item').forEach(button => {
        button.onclick = () => {
            items[Number(button.dataset.idx)].action();
            hideMenu();
        };
    });
}

function hideMenu() {
    contextMenu.classList.add('hidden');
}

document.addEventListener('click', e => {
    if (!contextMenu.contains(e.target)) hideMenu();
});

/* ================= EMOJI ================= */
const EMOJIS = [
    '😀','😁','😂','🤣','😃','😄','😅','😉','😊','😍','🥰','😎','🤔','😴','🥳','🥺',
    '😢','😭','😡','😇','🤗','🤩','😋','🙃','🤓','😈','👻','🤖','❤️','🧡','💛','💚',
    '💙','💜','🖤','🤍','💕','💞','💗','💔','👍','👎','👌','✌️','🤞','🤟','👋','🤝',
    '🙏','💪','👏','🙌','🎉','🎊','🎁','🎂','☕','🍕','🍔','🍟','🍩','🐶','🐱','🐼',
    '🐸','🦊','🐻','🐰','🌸','🌹','🌻','🌈','⭐','🌟','✨','🔥','⚡','☀️','🌙','🌊'
];

const emojiPanel = document.getElementById('emoji-panel');

EMOJIS.forEach(emoji => {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = emoji;
    button.className = 'rounded-lg p-1 transition hover:bg-indigo-100 dark:hover:bg-slate-600';

    button.onclick = () => {
        chatInput.value += emoji;
        chatInput.focus();
        chatInput.dispatchEvent(new Event('input'));
    };

    emojiPanel.appendChild(button);
});

document.getElementById('emoji-btn').onclick = () => {
    emojiPanel.classList.toggle('hidden');
    emojiPanel.classList.toggle('grid');
    document.getElementById('attach-panel').classList.add('hidden');
    document.getElementById('attach-panel').classList.remove('flex');
};

document.getElementById('attach-btn').onclick = () => {
    const panel = document.getElementById('attach-panel');
    panel.classList.toggle('hidden');
    panel.classList.toggle('flex');

    emojiPanel.classList.add('hidden');
    emojiPanel.classList.remove('grid');
};

/* ================= UPLOAD FILE ================= */
document.getElementById('file-image').onchange = e => {
    uploadFile(e.target.files[0], 'image');
    e.target.value = '';
};

document.getElementById('file-doc').onchange = e => {
    uploadFile(e.target.files[0], 'file');
    e.target.value = '';
};

async function uploadFile(file, type) {
    if (!file || !currentRoomId) return;

    if (file.size > 10 * 1024 * 1024) {
        alert('Ukuran file maksimal 10 MB.');
        return;
    }

    const roomAtUpload = currentRoomId;
    const caption = chatInput.value.trim();
    const tempId = 'temp_' + Date.now();
    const objectUrl = URL.createObjectURL(file);

    const tempHtml = `
        <div class="ml-auto mt-3 flex max-w-[85%] flex-row-reverse items-end gap-1.5 md:max-w-[65%]" id="${tempId}">
            <div class="relative min-w-0">
                <div class="bubble-out relative rounded-xl px-3 py-2 text-sm shadow-sm">
                    ${type === 'image'
                        ? `<img src="${objectUrl}" class="attach-img opacity-70" alt="Pratinjau upload">`
                        : `<div class="attach-file opacity-70">
                            <div class="attach-file-icon" style="background:#3b82f620;color:#3b82f6">📎</div>
                            <div class="attach-file-info">
                                <div class="attach-file-name">${esc(file.name)}</div>
                                <div class="attach-file-size">${formatBytes(file.size)}</div>
                            </div>
                        </div>`}
                    ${caption ? `<div class="caption-text">${esc(caption)}</div>` : ''}
                    <div class="upload-overlay"><div class="spinner-sm"></div></div>
                </div>
            </div>
        </div>`;

    chatBox.insertAdjacentHTML('beforeend', tempHtml);
    chatBox.scrollTop = chatBox.scrollHeight;

    const formData = new FormData();
    formData.append('file', file);
    formData.append('type', type);
    formData.append('room_id', roomAtUpload);

    if (caption) formData.append('caption', caption);
    if (replyTo) formData.append('reply_to_id', replyTo.id);

    document.getElementById('attach-panel').classList.add('hidden');
    document.getElementById('attach-panel').classList.remove('flex');
    sendBtn.disabled = true;

    try {
        const res = await fetch(BASE_URL + '/api/obrolan_upload.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        });

        const result = await jsonResponse(res);
        document.getElementById(tempId)?.remove();

        if (result.status === 'success') {
            if (currentRoomId === roomAtUpload) {
                chatInput.value = '';
                chatInput.style.height = 'auto';
                cancelReply();

                lastRenderedId = 0;
                lastDate = null;

                await fetchMessages(true);
                scrollToBottom(true);
            }
        } else {
            alert('Upload gagal: ' + (result.message || 'Terjadi kesalahan.'));
        }
    } catch (err) {
        document.getElementById(tempId)?.remove();
        console.error(err);
        alert('Upload gagal. Periksa koneksi dan coba lagi.');
    } finally {
        URL.revokeObjectURL(objectUrl);
        sendBtn.disabled = false;
        chatInput.focus();
    }
}

/* ================= PENCARIAN PESAN ================= */
document.getElementById('search-btn').onclick = () => {
    const bar = document.getElementById('search-bar');
    bar.classList.toggle('hidden');
    bar.classList.toggle('flex');

    if (!bar.classList.contains('hidden')) {
        document.getElementById('search-input').focus();
    }
};

document.getElementById('search-close').onclick = () => {
    const bar = document.getElementById('search-bar');
    bar.classList.add('hidden');
    bar.classList.remove('flex');

    document.getElementById('search-input').value = '';

    document.querySelectorAll('.msg-hl').forEach(el => {
        el.classList.remove('msg-hl');
    });
};

document.getElementById('search-input').addEventListener('input', e => {
    const query = e.target.value.toLowerCase().trim();

    document.querySelectorAll('.msg-bubble').forEach(bubble => {
        bubble.classList.remove('msg-hl');
    });

    if (!query) return;

    document.querySelectorAll('[data-msg-id]').forEach(el => {
        const content = el.querySelector('.msg-bubble')?.dataset.content || '';

        if (content.toLowerCase().includes(query)) {
            el.querySelector('.msg-bubble')?.classList.add('msg-hl');
        }
    });
});

/* ================= TAB ROOM ================= */
document.querySelectorAll('.room-tab').forEach(button => {
    button.onclick = () => {
        document.querySelectorAll('.room-tab').forEach(tab => {
            tab.classList.remove('room-tab-active');
        });

        button.classList.add('room-tab-active');
        currentRoomTab = button.dataset.roomTab;
        renderRooms();
    };
});

document.getElementById('room-search').addEventListener('input', renderRooms);

/* ================= MODAL GRUP ================= */
async function openCreateGroup() {
    const modal = document.getElementById('create-group-modal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    document.getElementById('group-name').value = '';

    const list = document.getElementById('member-list');
    list.innerHTML = '<p class="py-2 text-center text-sm text-slate-400">Memuat anggota...</p>';

    try {
        const res = await fetch(BASE_URL + '/api/obrolan_users.php', {
            credentials: 'same-origin'
        });

        allUsers = await jsonResponse(res);

        if (!Array.isArray(allUsers)) allUsers = [];

        list.innerHTML = allUsers.map(user => `
            <label class="flex cursor-pointer items-center gap-3 rounded-xl p-2 transition hover:bg-indigo-50 dark:hover:bg-slate-700">
                <input type="checkbox" value="${esc(user.id)}" class="member-check h-4 w-4 rounded text-indigo-600 focus:ring-indigo-500">
                <img src="${esc(user.photo_url)}" onerror="this.style.display='none'" class="h-9 w-9 rounded-full object-cover" alt="">
                <span class="min-w-0 flex-1 truncate text-sm font-semibold text-slate-700 dark:text-slate-200">${esc(user.name)}</span>
                <span class="text-xs text-slate-400">${isMeRole(user.role) ? 'Pembimbing' : 'Siswa'}</span>
            </label>
        `).join('') || '<p class="py-4 text-center text-sm text-slate-400">Belum ada anggota lain.</p>';
    } catch (err) {
        console.error(err);
        list.innerHTML = '<p class="py-2 text-center text-sm text-red-500">Gagal memuat anggota.</p>';
    }
}

function closeCreateGroup() {
    const modal = document.getElementById('create-group-modal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

async function submitCreateGroup() {
    const name = document.getElementById('group-name').value.trim();
    const members = Array.from(document.querySelectorAll('.member-check:checked'))
        .map(checkbox => parseInt(checkbox.value, 10));

    if (!name) {
        alert('Nama grup wajib diisi.');
        return;
    }

    if (!members.length) {
        alert('Pilih minimal satu anggota.');
        return;
    }

    try {
        const res = await fetch(BASE_URL + '/api/obrolan_create_group.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({name, members})
        });

        const result = await jsonResponse(res);

        if (result.status === 'success') {
            closeCreateGroup();
            await loadRooms();
            switchRoom(Number(result.room_id), name, 'group');
        } else {
            alert('Gagal membuat grup: ' + (result.message || ''));
        }
    } catch (err) {
        console.error(err);
        alert('Koneksi bermasalah. Grup belum berhasil dibuat.');
    }
}

/* ================= MODAL CHAT BARU ================= */
async function openNewChat() {
    const modal = document.getElementById('new-chat-modal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');

    const list = document.getElementById('users-list');
    list.innerHTML = '<p class="py-4 text-center text-sm text-slate-400">Memuat pengguna...</p>';

    try {
        const res = await fetch(BASE_URL + '/api/obrolan_users.php', {
            credentials: 'same-origin'
        });

        allUsers = await jsonResponse(res);

        if (!Array.isArray(allUsers)) allUsers = [];

        list.innerHTML = allUsers.map(user => `
            <div data-uid="${esc(user.id)}" data-name="${esc(user.name)}"
                 class="user-item flex cursor-pointer items-center gap-3 rounded-xl p-3 transition hover:bg-indigo-50 dark:hover:bg-slate-700">
                <img src="${esc(user.photo_url)}" onerror="this.style.display='none'" class="h-11 w-11 rounded-full object-cover" alt="">
                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-bold text-slate-800 dark:text-white">${esc(user.name)}</div>
                    <div class="text-xs text-slate-400">${isMeRole(user.role) ? 'Pembimbing' : 'Siswa'}</div>
                </div>
                <span class="text-indigo-500">›</span>
            </div>
        `).join('') || '<p class="py-4 text-center text-sm text-slate-400">Tidak ada pengguna lain.</p>';

        list.querySelectorAll('.user-item').forEach(el => {
            el.onclick = () => startPrivate(
                Number(el.dataset.uid),
                el.dataset.name
            );
        });
    } catch (err) {
        console.error(err);
        list.innerHTML = '<p class="py-4 text-center text-sm text-red-500">Gagal memuat pengguna.</p>';
    }
}

function closeNewChat() {
    const modal = document.getElementById('new-chat-modal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}

async function startPrivate(userId, name) {
    try {
        const res = await fetch(BASE_URL + '/api/obrolan_create_private.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({user_id: userId})
        });

        const result = await jsonResponse(res);

        if (result.status === 'success') {
            closeNewChat();
            await loadRooms();
            switchRoom(Number(result.room_id), name, 'private');
        } else {
            alert('Gagal memulai chat: ' + (result.message || ''));
        }
    } catch (err) {
        console.error(err);
        alert('Koneksi bermasalah.');
    }
}

/* ================= INFO ROOM ================= */
document.getElementById('info-btn').onclick = () => {
    if (!currentRoomId) {
        alert('Pilih ruang chat terlebih dahulu.');
        return;
    }

    alert(
        'Nama: ' + currentRoomName +
        '\nJenis: ' + (currentRoomType === 'group' ? 'Grup' : 'Pribadi') +
        '\nID Ruang: ' + currentRoomId
    );
};

/* ================= POLLING OTOMATIS ================= */
function startPolling() {
    stopPolling();

    pollTimer = setInterval(() => {
        if (document.visibilityState === 'visible' && currentRoomId) {
            fetchMessages();
        }
    }, 4000);

    roomsPollTimer = setInterval(() => {
        if (document.visibilityState === 'visible') {
            loadRooms();
        }
    }, 15000);
}

function stopPolling() {
    if (pollTimer) clearInterval(pollTimer);
    if (roomsPollTimer) clearInterval(roomsPollTimer);

    pollTimer = null;
    roomsPollTimer = null;
}

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        if (currentRoomId) fetchMessages();
        loadRooms();
    }
});

/* ================= INIT ================= */
async function init() {
    const rooms = await loadRooms();

    if (rooms.length > 0) {
        let lastId = 0;

        try {
            lastId = parseInt(localStorage.getItem('lastRoomId') || '0', 10);
        } catch (e) {}

        const target = rooms.find(room => Number(room.id) === lastId) || rooms[0];

        switchRoom(
            Number(target.id),
            target.name || 'Chat',
            target.type || 'group'
        );
    } else {
        showEmptyState();
    }

    startPolling();
}

init();

/* ================= STATUS KONEKSI ================= */
window.addEventListener('online', () => {
    if (currentRoomId) fetchMessages();
    loadRooms();
});

window.addEventListener('offline', () => {
    console.warn('Koneksi internet terputus.');
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>