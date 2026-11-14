<?php
// includes/header.php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/notifications.php';
$user = currentUser();
$flash = getFlash();
$currentPage = basename($_SERVER['PHP_SELF']);
$unreadChat = isLoggedIn() ? unreadChatCount() : 0;
$unreadNotif = isLoggedIn() ? unreadNotifCount($pdo, (int)$user['id']) : 0;
$isPembimbing = isPembimbing();

$pageTitles = [
    'dashboard.php'   => 'Dashboard',
    'monitoring.php'  => 'Monitoring',
    'obrolan.php'     => 'Obrolan',
    'tasks.php'       => 'Tugas',
    'journal.php'     => 'Jurnal',
    'attendance.php'  => 'Absensi',
    'profile.php'     => 'Profil',
];
$pageTitle = $pageTitles[$currentPage] ?? APP_NAME;

$menus = $isPembimbing ? [
    'dashboard.php'   => ['Dashboard',  '🏠'],
    'monitoring.php'  => ['Monitoring', '📊'],
    'obrolan.php'     => ['Obrolan',    '💬'],
    'tasks.php'       => ['Tugas',      '📋'],
    'journal.php'     => ['Jurnal',     '📝'],
    'attendance.php'  => ['Absensi',    '✅'],
] : [
    'dashboard.php'   => ['Dashboard',  '🏠'],
    'obrolan.php'     => ['Obrolan',    '💬'],
    'tasks.php'       => ['Tugas',      '📋'],
    'journal.php'     => ['Jurnal',     '📝'],
    'attendance.php'  => ['Absensi',    '✅'],
];

$menusMobile = $menus;
$menusMobile['profile.php'] = ['Profil', '👤'];

$activeClassMap = $isPembimbing ? 'nav-active-pembimbing shadow-sm' : 'nav-active shadow-sm';
$mobileActiveClass = $isPembimbing ? 'nav-active-pembimbing' : 'nav-active';
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📔</text></svg>">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: { primary: '#4F46E5', 'primary-dark': '#4338CA', 'brand-purple': '#7C3AED' },
                }
            }
        }
    </script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        .nav-active { background: linear-gradient(135deg,#EEF2FF,#F5F3FF); color:#4F46E5; font-weight:600; }
        .dark .nav-active { background: linear-gradient(135deg,rgba(99,102,241,.15),rgba(139,92,246,.15)); color:#A5B4FC; }
        .nav-active-pembimbing { background: linear-gradient(135deg,#FEF3C7,#FFEDD5); color:#B45309; font-weight:600; }
        .dark .nav-active-pembimbing { background: linear-gradient(135deg,rgba(245,158,11,.15),rgba(249,115,22,.15)); color:#FBBF24; }
        ::-webkit-scrollbar { width:8px; height:8px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(180deg,#6366f1,#a855f7); border-radius:4px; }
        @keyframes pulseBadge { 0%,100% { transform: scale(1); } 50% { transform: scale(1.15); } }
        .badge-pulse { animation: pulseBadge 1.5s infinite; }
    </style>
</head>
<body class="bg-gray-50 dark:bg-slate-900 text-gray-800 dark:text-gray-100 font-sans antialiased flex flex-col min-h-screen">

    <nav class="bg-white/90 dark:bg-slate-800/90 backdrop-blur-md shadow-sm sticky top-0 z-50 border-b border-gray-100 dark:border-slate-700">
        <div class="h-1 w-full <?= $isPembimbing ? 'bg-gradient-to-r from-amber-400 to-orange-500' : 'bg-gradient-to-r from-primary to-brand-purple' ?>"></div>
        <div class="max-w-6xl mx-auto px-4">
            <div class="flex justify-between h-16 items-center gap-3">
                <a href="<?= BASE_URL ?>/dashboard.php" class="flex items-center gap-2 hover:opacity-80 transition shrink-0">
                    <span class="text-2xl">📔</span>
                    <span class="font-extrabold text-transparent bg-clip-text bg-gradient-to-r <?= $isPembimbing ? 'from-amber-500 to-orange-600' : 'from-primary to-brand-purple' ?> text-lg hidden sm:inline">Jurnal PKL</span>
                </a>

                <div class="hidden md:flex items-center gap-1 flex-1 justify-center">
                    <?php foreach ($menus as $file => $data):
                        $isActive = ($currentPage === $file);
                        $activeClass = $isActive ? $activeClassMap : 'text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-slate-700';
                        $badge = ($file === 'obrolan.php' && $unreadChat > 0) ? $unreadChat : 0;
                    ?>
                        <a href="<?= BASE_URL ?>/<?= $file ?>" class="px-3 py-2 rounded-lg text-sm font-medium transition-all flex items-center gap-1.5 relative <?= $activeClass ?>">
                            <span class="text-base"><?= $data[1] ?></span><?= $data[0] ?>
                            <?php if ($badge > 0): ?><span class="absolute -top-1 -right-1 bg-red-500 text-white text-[10px] font-bold min-w-[18px] h-[18px] flex items-center justify-center px-1 rounded-full"><?= $badge > 99 ? '99+' : $badge ?></span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div class="flex items-center gap-1 shrink-0">

                    <!-- NOTIFIKASI BELL -->
                    <div class="relative" id="notif-wrapper">
                        <button onclick="toggleNotif()" id="notif-btn" class="text-gray-500 dark:text-gray-400 hover:text-primary p-2 rounded-full hover:bg-gray-100 dark:hover:bg-slate-700 transition relative">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                            <span id="notif-badge" class="<?= $unreadNotif > 0 ? '' : 'hidden' ?> absolute -top-0.5 -right-0.5 bg-red-500 text-white text-[10px] font-bold min-w-[18px] h-[18px] flex items-center justify-center px-1 rounded-full badge-pulse"><?= $unreadNotif > 99 ? '99+' : $unreadNotif ?></span>
                        </button>

                        <div id="notif-dropdown" class="hidden absolute right-0 top-12 w-80 sm:w-96 bg-white dark:bg-slate-800 rounded-2xl shadow-2xl border border-gray-100 dark:border-slate-700 overflow-hidden z-50">
                            <div class="flex items-center justify-between px-4 py-3 bg-gradient-to-r <?= $isPembimbing ? 'from-amber-500 to-orange-500' : 'from-primary to-purple-600' ?> text-white">
                                <h3 class="font-bold text-sm">🔔 Notifikasi</h3>
                                <button onclick="markAllRead()" class="text-xs bg-white/20 hover:bg-white/30 px-2 py-1 rounded transition">Tandai semua</button>
                            </div>
                            <div id="notif-list" class="max-h-96 overflow-y-auto">
                                <div class="p-6 text-center text-gray-400 text-sm"><div class="text-3xl mb-2">📭</div>Memuat...</div>
                            </div>
                        </div>
                    </div>

                    <!-- DARK MODE -->
                    <button id="theme-toggle" class="text-gray-500 dark:text-gray-400 hover:text-primary p-2 rounded-full hover:bg-gray-100 dark:hover:bg-slate-700 transition">
                        <svg class="w-5 h-5 theme-icon-moon" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"/></svg>
                        <svg class="w-5 h-5 theme-icon-sun hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364-6.364l-.707.707M6.343 17.657l-.707.707m12.728 0l-.707-.707M6.343 6.343l-.707-.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                    </button>

                    <!-- USER MENU -->
                    <div class="hidden md:block relative">
                        <button onclick="toggleUserMenu()" id="user-menu-btn" class="flex items-center gap-2 p-1.5 pr-3 rounded-full hover:bg-gray-100 dark:hover:bg-slate-700 transition">
                            <img src="<?= avatarUrl($user['photo'], $user['name'], 40) ?>" class="w-9 h-9 rounded-full object-cover ring-2 <?= $isPembimbing ? 'ring-amber-100 dark:ring-amber-900' : 'ring-indigo-100 dark:ring-indigo-900' ?>">
                            <div class="text-left hidden lg:block">
                                <p class="text-sm font-semibold leading-none text-gray-800 dark:text-white truncate max-w-[120px]"><?= e($user['name']) ?></p>
                                <span class="inline-flex items-center gap-1 text-[10px] font-bold px-1.5 py-0.5 rounded-full mt-1 <?= $isPembimbing ? 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' : 'bg-indigo-100 text-indigo-700 dark:bg-indigo-900/30 dark:text-indigo-400' ?>"><?= $isPembimbing ? '👨‍🏫 Pembimbing' : '👤 Siswa' ?></span>
                            </div>
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div id="user-menu" class="hidden absolute right-0 top-14 w-64 bg-white dark:bg-slate-800 rounded-2xl shadow-xl border border-gray-100 dark:border-slate-700 overflow-hidden z-50">
                            <div class="px-4 py-4 <?= $isPembimbing ? 'bg-gradient-to-br from-amber-50 to-orange-50 dark:from-amber-900/30 dark:to-orange-900/30' : 'bg-gradient-to-br from-indigo-50 to-purple-50 dark:from-indigo-900/30 dark:to-purple-900/30' ?> border-b border-gray-100 dark:border-slate-700">
                                <div class="flex items-center gap-3">
                                    <img src="<?= avatarUrl($user['photo'], $user['name'], 48) ?>" class="w-12 h-12 rounded-full object-cover ring-2 ring-white dark:ring-slate-700">
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold text-gray-800 dark:text-white truncate"><?= e($user['name']) ?></p>
                                        <p class="text-xs text-gray-500 dark:text-gray-400 truncate"><?= e($user['email']) ?></p>
                                    </div>
                                </div>
                            </div>
                            <a href="<?= BASE_URL ?>/profile.php" class="flex items-center gap-2 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-slate-700">👤 Profil Saya</a>
                            <a href="<?= BASE_URL ?>/dashboard.php" class="flex items-center gap-2 px-4 py-2.5 text-sm text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-slate-700">🏠 Dashboard</a>
                            <div class="border-t border-gray-100 dark:border-slate-700"></div>
                            <a href="<?= BASE_URL ?>/logout.php" class="flex items-center gap-2 px-4 py-2.5 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 font-medium">🚪 Keluar</a>
                        </div>
                    </div>

                    <a href="<?= BASE_URL ?>/logout.php" class="md:hidden bg-red-50 dark:bg-red-900/30 text-red-600 dark:text-red-400 px-2 py-2 rounded-lg text-xs font-semibold">Keluar</a>

                    <button id="mobile-toggle" class="md:hidden text-gray-600 dark:text-gray-300 p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-slate-700 relative">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        <?php if ($unreadChat > 0): ?><span class="absolute top-1 right-1 w-2.5 h-2.5 bg-red-500 rounded-full border-2 border-white dark:border-slate-800"></span><?php endif; ?>
                    </button>
                </div>
            </div>
        </div>

        <div id="mobile-menu" class="hidden md:hidden border-t border-gray-100 dark:border-slate-700 bg-white dark:bg-slate-800">
            <div class="px-3 py-3 space-y-1">
                <?php foreach ($menusMobile as $file => $data):
                    $isActive = ($currentPage === $file);
                    $activeClass = $isActive ? $mobileActiveClass : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-slate-700';
                    $badge = ($file === 'obrolan.php' && $unreadChat > 0) ? $unreadChat : 0;
                ?>
                    <a href="<?= BASE_URL ?>/<?= $file ?>" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition <?= $activeClass ?>">
                        <span class="text-lg"><?= $data[1] ?></span>
                        <span class="flex-1"><?= $data[0] ?></span>
                        <?php if ($badge > 0): ?><span class="bg-red-500 text-white text-xs font-bold min-w-[20px] h-5 flex items-center justify-center px-1.5 rounded-full"><?= $badge > 99 ? '99+' : $badge ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </nav>

    <?php if ($flash): ?>
    <div class="max-w-6xl mx-auto px-4 pt-4 w-full">
        <div class="flex items-start gap-3 p-4 rounded-xl shadow-sm border <?= $flash['type'] === 'success' ? 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-800 text-green-800 dark:text-green-300' : 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-800 text-red-800 dark:text-red-300' ?>">
            <div class="text-xl shrink-0"><?= $flash['type'] === 'success' ? '✅' : '⚠️' ?></div>
            <div class="flex-1 text-sm font-medium"><?= e($flash['msg']) ?></div>
            <button onclick="this.parentElement.remove()" class="text-current opacity-60 hover:opacity-100">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
    </div>
    <?php endif; ?>

    <main class="max-w-6xl mx-auto px-4 py-6 flex-grow w-full">
    <div id="toast-container" class="fixed bottom-6 right-6 z-[60] flex flex-col gap-2 pointer-events-none"></div>

    <script>
        document.getElementById('mobile-toggle')?.addEventListener('click', () => {
            document.getElementById('mobile-menu').classList.toggle('hidden');
        });
        function toggleUserMenu() {
            document.getElementById('user-menu').classList.toggle('hidden');
        }
        document.addEventListener('click', e => {
            const menu = document.getElementById('user-menu');
            const btn = document.getElementById('user-menu-btn');
            if (menu && btn && !menu.contains(e.target) && !btn.contains(e.target)) menu.classList.add('hidden');
        });
        (function() {
            const html = document.documentElement;
            const saved = localStorage.getItem('theme');
            if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
                html.classList.add('dark'); updateIcon(true);
            }
            document.getElementById('theme-toggle')?.addEventListener('click', () => {
                const isDark = html.classList.toggle('dark');
                localStorage.setItem('theme', isDark ? 'dark' : 'light');
                updateIcon(isDark);
            });
            function updateIcon(isDark) {
                const m = document.querySelector('.theme-icon-moon');
                const s = document.querySelector('.theme-icon-sun');
                if (!m || !s) return;
                m.classList.toggle('hidden', isDark);
                s.classList.toggle('hidden', !isDark);
            }
        })();

        // ============ NOTIFIKASI ============
        let notifOpen = false;

        function toggleNotif() {
            const dd = document.getElementById('notif-dropdown');
            notifOpen = !notifOpen;
            dd.classList.toggle('hidden', !notifOpen);
            if (notifOpen) loadNotifications();
        }

        function esc(s){return String(s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}

        async function loadNotifications() {
            try {
                const res = await fetch('<?= BASE_URL ?>/api/notif_fetch.php', {credentials: 'same-origin'});
                const data = await res.json();
                const list = document.getElementById('notif-list');
                const badge = document.getElementById('notif-badge');

                if (data.unread > 0) {
                    badge.textContent = data.unread > 99 ? '99+' : data.unread;
                    badge.classList.remove('hidden');
                } else {
                    badge.classList.add('hidden');
                }

                if (!data.items || data.items.length === 0) {
                    list.innerHTML = '<div class="p-6 text-center text-gray-400 text-sm"><div class="text-3xl mb-2">📭</div>Tidak ada notifikasi</div>';
                    return;
                }

                const icons = {'task':'📋','journal':'📝','attendance':'✅','chat':'💬','group':'👥','system':'🔔'};

                list.innerHTML = data.items.map(n => {
                    const icon = icons[n.type] || '🔔';
                    const bg = n.is_read ? 'bg-white dark:bg-slate-800' : 'bg-indigo-50 dark:bg-indigo-900/20';
                    const link = n.link ? `onclick="openNotif(${n.id}, '${n.link}')"` : `onclick="markRead(${n.id})"`;
                    return `<div class="px-4 py-3 border-b border-gray-100 dark:border-slate-700 hover:bg-gray-50 dark:hover:bg-slate-700/50 cursor-pointer ${bg}" ${link}>
                        <div class="flex items-start gap-3">
                            <div class="text-2xl shrink-0">${icon}</div>
                            <div class="min-w-0 flex-1">
                                <div class="text-sm font-semibold text-gray-800 dark:text-white ${n.is_read ? 'opacity-70' : ''}">${esc(n.title)}</div>
                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">${esc(n.message || '')}</div>
                                <div class="text-[10px] text-gray-400 mt-1">${n.time_ago}</div>
                            </div>
                            ${!n.is_read ? '<div class="w-2 h-2 bg-indigo-500 rounded-full shrink-0 mt-2"></div>' : ''}
                        </div>
                    </div>`;
                }).join('');
            } catch (err) { console.error(err); }
        }

        async function markRead(id) {
            await fetch('<?= BASE_URL ?>/api/notif_read.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                credentials: 'same-origin', body: JSON.stringify({id})
            });
            loadNotifications();
        }

        function openNotif(id, link) {
            markRead(id);
            if (link) setTimeout(() => window.location.href = '<?= BASE_URL ?>/' + link, 200);
        }

        async function markAllRead() {
            await fetch('<?= BASE_URL ?>/api/notif_read.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                credentials: 'same-origin', body: JSON.stringify({all: true})
            });
            loadNotifications();
        }

        document.addEventListener('click', e => {
            const wrapper = document.getElementById('notif-wrapper');
            if (wrapper && !wrapper.contains(e.target) && notifOpen) {
                document.getElementById('notif-dropdown').classList.add('hidden');
                notifOpen = false;
            }
        });

        setInterval(() => {
            if (notifOpen) loadNotifications();
            else {
                fetch('<?= BASE_URL ?>/api/notif_fetch.php', {credentials: 'same-origin'})
                    .then(r => r.json())
                    .then(data => {
                        const badge = document.getElementById('notif-badge');
                        if (data.unread > 0) {
                            badge.textContent = data.unread > 99 ? '99+' : data.unread;
                            badge.classList.remove('hidden');
                        } else badge.classList.add('hidden');
                    }).catch(() => {});
            }
        }, 15000);

        window.showToast = function(msg, type = 'success') {
            const el = document.createElement('div');
            const color = type === 'success' ? 'bg-green-500' : (type === 'error' ? 'bg-red-500' : 'bg-indigo-500');
            el.className = `${color} text-white px-4 py-3 rounded-lg shadow-lg font-medium text-sm pointer-events-auto`;
            el.textContent = msg;
            document.getElementById('toast-container').appendChild(el);
            setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 300); }, 2500);
        };
    </script>