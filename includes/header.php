<?php
// includes/header.php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notifications.php';

// ================= DATA PENGGUNA =================
csrfToken('default');

$user = currentUser();
$flash = getFlash();

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

$role = $user['role'] ?? '';
$isAdmin = ($role === 'admin');
$isPembimbing = in_array($role, ['pembimbing', 'teacher'], true);
$isStaff = $isAdmin || $isPembimbing;

$unreadChat = isLoggedIn() ? unreadChatCount() : 0;

$unreadNotif = 0;

if (isLoggedIn()) {
    try {
        $unreadNotif = unreadNotifCount($pdo, (int)$user['id']);
    } catch (Throwable $e) {
        $unreadNotif = 0;
    }
}

// ================= JUDUL HALAMAN =================
$pageTitles = [
    'dashboard.php'   => 'Dashboard',
    'monitoring.php'  => 'Monitoring',
    'laporan.php'     => 'Laporan',
    'obrolan.php'     => 'Obrolan',
    'tasks.php'       => 'Tugas',
    'journal.php'     => 'Jurnal',
    'attendance.php'  => 'Absensi',
    'profile.php'     => 'Profil',
    'activity_log.php'=> 'Log Aktivitas',
    'security.php'    => 'Keamanan',
];

$pageTitle = $pageTitles[$currentPage] ?? APP_NAME;

// ================= MENU =================
if ($isStaff) {
    $menus = [
        'dashboard.php'  => ['Dashboard', '🏠'],
        'monitoring.php' => ['Monitoring', '📊'],
        'laporan.php'    => ['Laporan', '📑'],
        'obrolan.php'    => ['Obrolan', '💬'],
        'tasks.php'      => ['Tugas', '📋'],
        'journal.php'    => ['Jurnal', '📝'],
        'attendance.php' => ['Absensi', '✅'],
        'activity_log.php' => ['Log Aktivitas', '📜'],
    ];
} else {
    $menus = [
        'dashboard.php'  => ['Dashboard', '🏠'],
        'obrolan.php'    => ['Obrolan', '💬'],
        'tasks.php'      => ['Tugas', '📋'],
        'journal.php'    => ['Jurnal', '📝'],
        'attendance.php' => ['Absensi', '✅'],
    ];
}

$menusMobile = $menus;
$menusMobile['profile.php'] = ['Profil', '👤'];

// ================= WARNA ROLE =================
if ($isAdmin) {
    $roleLabel = 'Admin';
    $roleIcon = '🛡️';
    $roleClass = 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300';

    $topBarClass = 'from-purple-500 to-indigo-600';
    $brandTextClass = 'from-purple-500 to-indigo-600';
    $activeClass = 'nav-active-admin';
    $userHeaderClass = 'from-purple-50 to-indigo-50 dark:from-purple-900/30 dark:to-indigo-900/30';
    $avatarRingClass = 'ring-purple-200 dark:ring-purple-900';
    $notificationHeaderClass = 'from-purple-600 to-indigo-600';

} elseif ($isPembimbing) {
    $roleLabel = 'Pembimbing';
    $roleIcon = '👨‍🏫';
    $roleClass = 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300';

    $topBarClass = 'from-amber-400 to-orange-500';
    $brandTextClass = 'from-amber-500 to-orange-600';
    $activeClass = 'nav-active-pembimbing';
    $userHeaderClass = 'from-amber-50 to-orange-50 dark:from-amber-900/30 dark:to-orange-900/30';
    $avatarRingClass = 'ring-amber-200 dark:ring-amber-900';
    $notificationHeaderClass = 'from-amber-500 to-orange-500';

} else {
    $roleLabel = 'Siswa';
    $roleIcon = '👤';
    $roleClass = 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-300';

    $topBarClass = 'from-indigo-500 to-purple-600';
    $brandTextClass = 'from-indigo-500 to-purple-600';
    $activeClass = 'nav-active';
    $userHeaderClass = 'from-indigo-50 to-purple-50 dark:from-indigo-900/30 dark:to-purple-900/30';
    $avatarRingClass = 'ring-indigo-200 dark:ring-indigo-900';
    $notificationHeaderClass = 'from-indigo-600 to-purple-600';
}

$baseUrl = rtrim(BASE_URL, '/');
?>

<!DOCTYPE html>
<html lang="id" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>

    <meta name="csrf-token" content="<?= e($_SESSION['csrf_default'] ?? '') ?>">
    <meta name="theme-color" content="#4F46E5">

    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📔</text></svg>">

    <script src="https://cdn.tailwindcss.com"></script>

    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'sans-serif']
                    },
                    colors: {
                        primary: '#4F46E5',
                        'primary-dark': '#4338CA',
                        'brand-purple': '#7C3AED'
                    }
                }
            }
        };
    </script>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        .nav-active {
            background: linear-gradient(135deg, #EEF2FF, #F5F3FF);
            color: #4F46E5;
            font-weight: 700;
        }

        .dark .nav-active {
            background: linear-gradient(135deg, rgba(99,102,241,.18), rgba(139,92,246,.18));
            color: #A5B4FC;
        }

        .nav-active-admin {
            background: linear-gradient(135deg, #F3E8FF, #E0E7FF);
            color: #6D28D9;
            font-weight: 700;
        }

        .dark .nav-active-admin {
            background: linear-gradient(135deg, rgba(168,85,247,.18), rgba(99,102,241,.18));
            color: #C4B5FD;
        }

        .nav-active-pembimbing {
            background: linear-gradient(135deg, #FEF3C7, #FFEDD5);
            color: #B45309;
            font-weight: 700;
        }

        .dark .nav-active-pembimbing {
            background: linear-gradient(135deg, rgba(245,158,11,.18), rgba(249,115,22,.18));
            color: #FBBF24;
        }

        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        ::-webkit-scrollbar-thumb {
            background: linear-gradient(180deg, #6366F1, #A855F7);
            border-radius: 10px;
        }

        .badge-pulse {
            animation: pulseBadge 1.5s infinite;
        }

        @keyframes pulseBadge {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.12); }
        }
    </style>
</head>

<body class="bg-slate-50 dark:bg-slate-900 text-slate-800 dark:text-slate-100 font-sans antialiased flex flex-col min-h-screen">

<nav class="sticky top-0 z-50 bg-white/95 dark:bg-slate-800/95 backdrop-blur-md border-b border-slate-200 dark:border-slate-700 shadow-sm">

    <div class="h-1 w-full bg-gradient-to-r <?= $topBarClass ?>"></div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6">
        <div class="flex justify-between items-center h-16 gap-3">

            <!-- BRAND -->
            <a href="<?= $baseUrl ?>/dashboard.php"
               class="flex items-center gap-2 shrink-0 hover:opacity-80 transition">
                <span class="text-2xl">📔</span>
                <span class="font-extrabold text-transparent bg-clip-text bg-gradient-to-r <?= $brandTextClass ?> text-lg hidden sm:inline">
                    <?= e(APP_NAME) ?>
                </span>
            </a>

            <!-- DESKTOP MENU -->
            <div class="hidden md:flex items-center justify-center gap-1 flex-1">
                <?php foreach ($menus as $file => $data): ?>
                    <?php
                    $isActive = ($currentPage === $file);
                    $linkClass = $isActive
                        ? $activeClass
                        : 'text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700';
                    ?>

                    <a href="<?= $baseUrl ?>/<?= e($file) ?>"
                       class="relative flex items-center gap-1.5 px-3 py-2 rounded-xl text-sm transition-all <?= $linkClass ?>">
                        <span><?= $data[1] ?></span>
                        <span><?= e($data[0]) ?></span>

                        <?php if ($file === 'obrolan.php' && $unreadChat > 0): ?>
                            <span class="absolute -top-1 -right-1 min-w-5 h-5 px-1 flex items-center justify-center rounded-full bg-red-500 text-white text-[10px] font-bold">
                                <?= $unreadChat > 99 ? '99+' : $unreadChat ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <!-- RIGHT MENU -->
            <div class="flex items-center gap-1 shrink-0">

                <!-- NOTIFICATION -->
                <div class="relative" id="notif-wrapper">
                    <button type="button"
                            id="notif-btn"
                            onclick="toggleNotif()"
                            aria-label="Notifikasi"
                            class="relative p-2.5 rounded-xl text-slate-500 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 transition">

                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                        </svg>

                        <span id="notif-badge"
                              class="<?= $unreadNotif > 0 ? '' : 'hidden' ?> absolute top-0 right-0 min-w-4 h-4 px-1 rounded-full bg-red-500 text-white text-[10px] font-bold flex items-center justify-center badge-pulse">
                            <?= $unreadNotif > 99 ? '99+' : $unreadNotif ?>
                        </span>
                    </button>

                    <div id="notif-dropdown"
                         class="hidden absolute right-0 top-12 w-80 sm:w-96 bg-white dark:bg-slate-800 rounded-2xl shadow-2xl border border-slate-200 dark:border-slate-700 overflow-hidden">

                        <div class="flex items-center justify-between px-4 py-3 bg-gradient-to-r <?= $notificationHeaderClass ?> text-white">
                            <h3 class="font-bold text-sm">🔔 Notifikasi</h3>

                            <button type="button"
                                    onclick="markAllRead()"
                                    class="text-xs bg-white/20 hover:bg-white/30 px-3 py-1.5 rounded-lg transition">
                                Tandai semua
                            </button>
                        </div>

                        <div id="notif-list" class="max-h-96 overflow-y-auto">
                            <div class="p-6 text-center text-slate-400 text-sm">
                                <div class="text-3xl mb-2">📭</div>
                                Memuat notifikasi...
                            </div>
                        </div>
                    </div>
                </div>

                <!-- DARK MODE -->
                <button type="button"
                        id="theme-toggle"
                        aria-label="Ganti tema"
                        class="p-2.5 rounded-xl text-slate-500 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 transition">

                    <svg class="w-5 h-5 theme-icon-moon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/>
                    </svg>

                    <svg class="w-5 h-5 theme-icon-sun hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m12.728 0l-.707-.707M6.343 6.343l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                </button>

                <!-- USER MENU DESKTOP -->
                <div class="hidden md:block relative">
                    <button type="button"
                            id="user-menu-btn"
                            onclick="toggleUserMenu()"
                            class="flex items-center gap-2 p-1.5 pr-3 rounded-full hover:bg-slate-100 dark:hover:bg-slate-700 transition">

                        <img src="<?= e(avatarUrl($user['photo'], $user['name'], 40)) ?>"
                             alt="Foto profil"
                             class="w-9 h-9 rounded-full object-cover ring-2 <?= $avatarRingClass ?>">

                        <div class="hidden lg:block text-left">
                            <p class="text-sm font-semibold truncate max-w-28">
                                <?= e($user['name']) ?>
                            </p>

                            <span class="inline-flex items-center gap-1 text-[10px] font-bold px-2 py-0.5 rounded-full <?= $roleClass ?>">
                                <?= $roleIcon ?> <?= e($roleLabel) ?>
                            </span>
                        </div>

                        <svg class="w-4 h-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>

                    <div id="user-menu"
                         class="hidden absolute right-0 top-14 w-64 bg-white dark:bg-slate-800 rounded-2xl shadow-xl border border-slate-200 dark:border-slate-700 overflow-hidden">

                        <div class="p-4 bg-gradient-to-br <?= $userHeaderClass ?> border-b border-slate-100 dark:border-slate-700">
                            <div class="flex items-center gap-3">
                                <img src="<?= e(avatarUrl($user['photo'], $user['name'], 48)) ?>"
                                     alt="Foto profil"
                                     class="w-12 h-12 rounded-full object-cover ring-2 ring-white dark:ring-slate-700">

                                <div class="min-w-0">
                                    <p class="font-bold text-sm truncate">
                                        <?= e($user['name']) ?>
                                    </p>

                                    <p class="text-xs text-slate-500 dark:text-slate-400 truncate">
                                        <?= e($user['email']) ?>
                                    </p>

                                    <span class="inline-flex mt-1 text-[10px] font-bold px-2 py-0.5 rounded-full <?= $roleClass ?>">
                                        <?= $roleIcon ?> <?= e($roleLabel) ?>
                                    </span>
                                </div>
                            </div>
                        </div>

                        <a href="<?= $baseUrl ?>/profile.php"
                           class="block px-4 py-3 text-sm hover:bg-slate-50 dark:hover:bg-slate-700 transition">
                            👤 Profil Saya
                        </a>

                        <?php if ($isStaff): ?>
                            <a href="<?= $baseUrl ?>/activity_log.php"
                               class="block px-4 py-3 text-sm hover:bg-slate-50 dark:hover:bg-slate-700 transition">
                                📜 Log Aktivitas
                            </a>
                        <?php endif; ?>

                        <a href="<?= $baseUrl ?>/dashboard.php"
                           class="block px-4 py-3 text-sm hover:bg-slate-50 dark:hover:bg-slate-700 transition">
                            🏠 Dashboard
                        </a>

                        <div class="border-t border-slate-100 dark:border-slate-700"></div>

                        <a href="<?= $baseUrl ?>/logout.php"
                           class="block px-4 py-3 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                            🚪 Keluar
                        </a>
                    </div>
                </div>

                <!-- LOGOUT MOBILE -->
                <a href="<?= $baseUrl ?>/logout.php"
                   class="md:hidden px-3 py-2 rounded-lg text-xs font-bold bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400">
                    Keluar
                </a>

                <!-- MOBILE MENU BUTTON -->
                <button type="button"
                        id="mobile-toggle"
                        aria-label="Buka menu"
                        aria-expanded="false"
                        class="md:hidden relative p-2 rounded-xl text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700">

                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 6h16M4 12h16M4 18h16"/>
                    </svg>

                    <?php if ($unreadChat > 0): ?>
                        <span class="absolute top-1 right-1 w-2.5 h-2.5 bg-red-500 rounded-full border-2 border-white dark:border-slate-800"></span>
                    <?php endif; ?>
                </button>

            </div>
        </div>
    </div>

    <!-- MOBILE NAVIGATION -->
    <div id="mobile-menu"
         class="hidden md:hidden border-t border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800">

        <div class="px-3 py-3 space-y-1">
            <?php foreach ($menusMobile as $file => $data): ?>
                <?php
                $isActive = ($currentPage === $file);
                $mobileClass = $isActive
                    ? $activeClass
                    : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700';
                ?>

                <a href="<?= $baseUrl ?>/<?= e($file) ?>"
                   class="flex items-center gap-3 px-3 py-3 rounded-xl text-sm font-medium <?= $mobileClass ?>">

                    <span class="text-lg"><?= $data[1] ?></span>
                    <span class="flex-1"><?= e($data[0]) ?></span>

                    <?php if ($file === 'obrolan.php' && $unreadChat > 0): ?>
                        <span class="bg-red-500 text-white text-xs font-bold px-2 py-1 rounded-full">
                            <?= $unreadChat > 99 ? '99+' : $unreadChat ?>
                        </span>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>

</nav>

<!-- FLASH MESSAGE -->
<?php if ($flash): ?>
    <?php
    $flashSuccess = ($flash['type'] ?? '') === 'success';

    $flashClass = $flashSuccess
        ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300'
        : 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300';
    ?>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 pt-4 w-full">
        <div class="flex items-center gap-3 p-4 rounded-xl border shadow-sm <?= $flashClass ?>">
            <span class="text-xl">
                <?= $flashSuccess ? '✅' : '⚠️' ?>
            </span>

            <div class="flex-1 text-sm font-medium">
                <?= e($flash['msg'] ?? '') ?>
            </div>

            <button type="button"
                    onclick="this.parentElement.remove()"
                    aria-label="Tutup pesan"
                    class="opacity-60 hover:opacity-100">
                ✕
            </button>
        </div>
    </div>
<?php endif; ?>

<!-- MAIN CONTENT -->
<main class="max-w-7xl mx-auto px-4 sm:px-6 py-6 flex-grow w-full">

<div id="toast-container"
     class="fixed bottom-6 right-6 z-[60] flex flex-col gap-2 pointer-events-none">
</div>

<script>
// ================= MOBILE MENU =================
(function () {
    const toggle = document.getElementById('mobile-toggle');
    const menu = document.getElementById('mobile-menu');

    if (!toggle || !menu) return;

    toggle.addEventListener('click', function () {
        const isHidden = menu.classList.toggle('hidden');
        toggle.setAttribute('aria-expanded', String(!isHidden));
    });
})();

// ================= USER MENU =================
function toggleUserMenu() {
    document.getElementById('user-menu')?.classList.toggle('hidden');
}

document.addEventListener('click', function (event) {
    const menu = document.getElementById('user-menu');
    const button = document.getElementById('user-menu-btn');

    if (menu && button &&
        !menu.contains(event.target) &&
        !button.contains(event.target)) {
        menu.classList.add('hidden');
    }
});

// ================= DARK MODE =================
(function () {
    const html = document.documentElement;
    const button = document.getElementById('theme-toggle');

    let savedTheme = null;

    try {
        savedTheme = localStorage.getItem('theme');
    } catch (error) {}

    const prefersDark = window.matchMedia &&
        window.matchMedia('(prefers-color-scheme: dark)').matches;

    const isDark = savedTheme
        ? savedTheme === 'dark'
        : prefersDark;

    html.classList.toggle('dark', isDark);
    updateThemeIcon(isDark);

    button?.addEventListener('click', function () {
        const dark = html.classList.toggle('dark');

        try {
            localStorage.setItem('theme', dark ? 'dark' : 'light');
        } catch (error) {}

        updateThemeIcon(dark);
    });

    function updateThemeIcon(dark) {
        document.querySelector('.theme-icon-moon')
            ?.classList.toggle('hidden', dark);

        document.querySelector('.theme-icon-sun')
            ?.classList.toggle('hidden', !dark);
    }
})();

// ================= ESCAPE HTML =================
window.esc = window.esc || function (value) {
    return String(value ?? '').replace(/[&<>"']/g, function (char) {
        return {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        }[char];
    });
};

// ================= NOTIFICATIONS =================
let notifOpen = false;
let lastNotifCount = <?= (int)$unreadNotif ?>;
const originalTitle = document.title;

const notifBaseUrl = <?= json_encode($baseUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function toggleNotif() {
    const dropdown = document.getElementById('notif-dropdown');

    if (!dropdown) return;

    notifOpen = !notifOpen;
    dropdown.classList.toggle('hidden', !notifOpen);

    if (notifOpen) {
        loadNotifications();
    }
}

async function loadNotifications() {
    const list = document.getElementById('notif-list');
    const badge = document.getElementById('notif-badge');

    if (!list || !badge) return;

    try {
        const response = await fetch(notifBaseUrl + '/api/notif_fetch.php', {
            credentials: 'same-origin',
            cache: 'no-store'
        });

        if (!response.ok) throw new Error('Gagal mengambil notifikasi.');

        const data = await response.json();
        const unread = Math.max(0, Number(data.unread) || 0);

        updateNotifBadge(unread);

        if (!Array.isArray(data.items) || data.items.length === 0) {
            list.innerHTML = `
                <div class="p-6 text-center text-slate-400 text-sm">
                    <div class="text-3xl mb-2">📭</div>
                    Tidak ada notifikasi
                </div>`;
            return;
        }

        const icons = {
            task: '📋',
            journal: '📝',
            attendance: '✅',
            chat: '💬',
            group: '👥',
            system: '🔔'
        };

        list.innerHTML = data.items.map(function (item) {
            const id = Number(item.id) || 0;
            const icon = icons[item.type] || '🔔';

            const bg = item.is_read
                ? 'bg-white dark:bg-slate-800'
                : 'bg-indigo-50 dark:bg-indigo-900/20';

            return `
                <button type="button"
                    data-notif-id="${id}"
                    data-notif-link="${window.esc(item.link || '')}"
                    class="w-full text-left px-4 py-3 border-b border-slate-100 dark:border-slate-700 hover:bg-slate-50 dark:hover:bg-slate-700/50 ${bg}">

                    <div class="flex items-start gap-3">
                        <div class="text-2xl shrink-0">${icon}</div>

                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold text-slate-800 dark:text-white">
                                ${window.esc(item.title)}
                            </div>

                            <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">
                                ${window.esc(item.message || '')}
                            </div>

                            <div class="text-[10px] text-slate-400 mt-1">
                                ${window.esc(item.time_ago || '')}
                            </div>
                        </div>

                        ${!item.is_read
                            ? '<span class="w-2 h-2 bg-indigo-500 rounded-full shrink-0 mt-2"></span>'
                            : ''}
                    </div>
                </button>`;
        }).join('');

    } catch (error) {
        console.error('Gagal memuat notifikasi:', error);

        list.innerHTML = `
            <div class="p-5 text-center text-sm text-red-500">
                Gagal memuat notifikasi.
            </div>`;
    }
}

function updateNotifBadge(count) {
    const badge = document.getElementById('notif-badge');

    if (!badge) return;

    if (count > 0) {
        badge.textContent = count > 99 ? '99+' : count;
        badge.classList.remove('hidden');
    } else {
        badge.classList.add('hidden');
    }
}

async function markRead(id) {
    try {
        await fetch(notifBaseUrl + '/api/notif_read.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({id: Number(id)})
        });

        await loadNotifications();

    } catch (error) {
        console.error('Gagal menandai notifikasi:', error);
    }
}

function openNotif(id, link) {
    const safeLink = String(link || '').trim();

    markRead(id);

    if (!safeLink) return;

    if (
        safeLink.startsWith('//') ||
        /^[a-z][a-z0-9+.-]*:/i.test(safeLink) ||
        safeLink.includes('\\')
    ) {
        return;
    }

    const cleanLink = safeLink.replace(/^\/+/, '');
    window.location.href = notifBaseUrl + '/' + cleanLink;
}

document.getElementById('notif-list')?.addEventListener('click', function (event) {
    const button = event.target.closest('[data-notif-id]');

    if (!button) return;

    const id = Number(button.dataset.notifId);
    const link = button.dataset.notifLink || '';

    if (link) {
        openNotif(id, link);
    } else {
        markRead(id);
    }
});

async function markAllRead() {
    try {
        await fetch(notifBaseUrl + '/api/notif_read.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'same-origin',
            body: JSON.stringify({all: true})
        });

        await loadNotifications();

    } catch (error) {
        console.error('Gagal menandai semua notifikasi:', error);
    }
}

// ================= CLOSE DROPDOWNS =================
document.addEventListener('click', function (event) {
    const wrapper = document.getElementById('notif-wrapper');
    const dropdown = document.getElementById('notif-dropdown');

    if (wrapper && dropdown &&
        !wrapper.contains(event.target) &&
        notifOpen) {
        dropdown.classList.add('hidden');
        notifOpen = false;
    }
});

// ================= AUTO REFRESH =================
setInterval(async function () {
    try {
        const response = await fetch(notifBaseUrl + '/api/notif_fetch.php', {
            credentials: 'same-origin',
            cache: 'no-store'
        });

        if (!response.ok) return;

        const data = await response.json();
        const unread = Math.max(0, Number(data.unread) || 0);

        updateNotifBadge(unread);

        if (notifOpen) {
            await loadNotifications();
        }

        if (unread > lastNotifCount) {
            document.title = '(' + unread + ') Notifikasi Baru!';

            setTimeout(function () {
                document.title = originalTitle;
            }, 3000);
        }

        lastNotifCount = unread;

    } catch (error) {}
}, 15000);

// ================= TOAST =================
window.showToast = function (message, type = 'success') {
    const container = document.getElementById('toast-container');

    if (!container) return;

    const toast = document.createElement('div');

    let color = 'bg-indigo-600';

    if (type === 'success') color = 'bg-green-600';
    else if (type === 'error') color = 'bg-red-600';

    toast.className = color +
        ' text-white px-4 py-3 rounded-xl shadow-lg text-sm font-medium pointer-events-auto transition-opacity duration-300';

    toast.textContent = String(message ?? '');

    container.appendChild(toast);

    setTimeout(function () {
        toast.style.opacity = '0';

        setTimeout(function () {
            toast.remove();
        }, 300);
    }, 2500);
};
</script>