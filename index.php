<?php
// index.php
require_once __DIR__ . '/includes/functions.php';

$stmt = $pdo->query("SELECT name, role, bio, jurusan, sekolah, photo FROM users ORDER BY role DESC, id ASC");
$users = $stmt->fetchAll();

$total_users = count($users);
$total_journals = 0;
$total_photos = 0;
$total_tasks = 0;
try { $total_journals = (int)$pdo->query("SELECT COUNT(*) FROM journals")->fetchColumn(); } catch (Exception $e) {}
try { $total_photos   = (int)$pdo->query("SELECT COUNT(*) FROM journal_photos")->fetchColumn(); } catch (Exception $e) {}
try { $total_tasks    = (int)$pdo->query("SELECT COUNT(*) FROM tasks WHERE status='done'")->fetchColumn(); } catch (Exception $e) {}

$pembimbings = array_filter($users, fn($u) => in_array($u['role'], ['pembimbing','teacher']));
$siswas      = array_filter($users, fn($u) => !in_array($u['role'], ['pembimbing','teacher']));
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_NAME ?> — Dokumentasi PKL</title>
    <meta name="description" content="Platform terpusat untuk mencatat jurnal harian, absensi, tugas, dan komunikasi kelompok PKL.">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>📔</text></svg>">

    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'sans-serif'] },
                    colors: { primary: '#4F46E5' },
                    animation: {
                        'blob': 'blob 14s ease-in-out infinite',
                        'float': 'float 6s ease-in-out infinite',
                    },
                    keyframes: {
                        blob: {
                            '0%,100%': { transform: 'translate(0,0) scale(1)' },
                            '33%': { transform: 'translate(40px,-50px) scale(1.15)' },
                            '66%': { transform: 'translate(-30px,30px) scale(0.9)' },
                        },
                        float: {
                            '0%,100%': { transform: 'translateY(0)' },
                            '50%': { transform: 'translateY(-10px)' },
                        },
                    }
                }
            }
        }
    </script>
    <style>
        .line-clamp-3 { display:-webkit-box; -webkit-line-clamp:3; -webkit-box-orient:vertical; overflow:hidden; }
        body { overflow-x: hidden; }

        .hero-gradient {
            background: linear-gradient(135deg, #EEF2FF 0%, #FAF5FF 50%, #EFF6FF 100%);
        }

        .glass {
            background: rgba(255,255,255,0.7);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }

        .reveal { opacity: 0; transform: translateY(30px); transition: all .8s cubic-bezier(.16,1,.3,1); }
        .reveal.active { opacity: 1; transform: translateY(0); }

        .team-glow { position: relative; }
        .team-glow::before {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: 1.25rem;
            background: linear-gradient(135deg, #6366f1, #a855f7, #3b82f6, #6366f1);
            background-size: 300% 300%;
            opacity: 0;
            transition: opacity .4s;
            z-index: -1;
            animation: gradientMove 4s ease infinite;
        }
        .team-glow:hover::before { opacity: 1; }

        .btn-shine { position: relative; overflow: hidden; }
        .btn-shine::after {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left .6s;
        }
        .btn-shine:hover::after { left: 100%; }

        .text-gradient {
            background: linear-gradient(135deg, #4F46E5, #a855f7, #3b82f6, #4F46E5);
            background-size: 300% 300%;
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            animation: gradientMove 6s ease infinite;
        }

        @keyframes gradientMove {
            0%,100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-thumb { background: linear-gradient(180deg, #6366f1, #a855f7); border-radius: 4px; }
    </style>
</head>
<body class="bg-white font-sans text-gray-800">

<!-- ========== MENU ATAS ========== -->
<nav class="glass sticky top-0 z-50 border-b border-gray-100">
    <div class="max-w-6xl mx-auto px-4 h-16 flex justify-between items-center">
        <a href="#" class="flex items-center gap-2 hover:opacity-80 transition">
            <span class="text-2xl">📔</span>
            <span class="text-gradient font-extrabold text-lg"><?= APP_NAME ?></span>
        </a>
        <a href="<?= BASE_URL ?>/login.php"
           class="btn-shine bg-gradient-to-r from-primary to-purple-600 text-white px-6 py-2 rounded-full text-sm font-semibold hover:shadow-lg hover:shadow-indigo-300/50 transition-all hover:-translate-y-0.5">
            Masuk →
        </a>
    </div>
</nav>

<!-- ========== HERO ========== -->
<header class="hero-gradient relative overflow-hidden">
    <div class="absolute top-20 -left-20 w-72 h-72 bg-indigo-300/40 rounded-full blur-3xl animate-blob"></div>
    <div class="absolute top-40 -right-20 w-80 h-80 bg-purple-300/40 rounded-full blur-3xl animate-blob" style="animation-delay:3s"></div>
    <div class="absolute bottom-0 left-1/3 w-64 h-64 bg-blue-300/30 rounded-full blur-3xl animate-blob" style="animation-delay:6s"></div>

    <div class="relative max-w-4xl mx-auto px-4 pt-20 pb-24 text-center">

        <!-- Badge -->
        <div class="reveal inline-flex items-center gap-2 bg-white/80 border border-indigo-100 px-4 py-1.5 rounded-full text-xs font-semibold text-primary mb-8 shadow-sm">
            <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
            Platform PKL • Siap Digunakan
        </div>

        <!-- Judul -->
        <h1 class="reveal text-4xl md:text-6xl font-extrabold text-gray-900 mb-6 leading-tight tracking-tight">
            Dokumentasi Kegiatan<br>
            <span class="text-gradient">Praktik Kerja Lapangan</span>
        </h1>

        <p class="reveal text-base md:text-lg text-gray-600 max-w-2xl mx-auto mb-10 leading-relaxed">
            Satu tempat untuk <b class="text-gray-900">jurnal</b>, <b class="text-gray-900">absensi</b>, <b class="text-gray-900">tugas</b>, dan <b class="text-gray-900">chat</b> kelompok PKL kamu.
        </p>

        <!-- CTA -->
        <div class="reveal mb-14">
            <a href="<?= BASE_URL ?>/login.php"
               class="btn-shine inline-flex items-center gap-2 bg-gradient-to-r from-primary to-purple-600 text-white px-8 py-3.5 rounded-full font-bold text-base hover:shadow-xl hover:shadow-indigo-400/40 transition-all hover:-translate-y-1">
                Mulai Sekarang
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
            </a>
        </div>

        <!-- Fitur pills -->
        <div class="reveal flex flex-wrap gap-2 justify-center mb-16">
            <?php
            $pills = ['📝 Jurnal Foto', '✅ Absensi Harian', '📋 Kelola Tugas', '💬 Chat Kelompok', '📊 Statistik'];
            foreach ($pills as $p):
            ?>
                <span class="bg-white/90 border border-indigo-50 text-gray-700 text-xs font-medium px-3.5 py-1.5 rounded-full shadow-sm"><?= $p ?></span>
            <?php endforeach; ?>
        </div>

        <!-- Statistik -->
        <div class="reveal grid grid-cols-2 md:grid-cols-4 gap-3 max-w-3xl mx-auto">
            <div class="bg-white/90 border border-indigo-50 rounded-2xl p-5 shadow-sm hover:shadow-md transition">
                <div class="text-3xl md:text-4xl font-extrabold text-gradient counter" data-target="<?= $total_users ?>">0</div>
                <div class="text-xs text-gray-600 mt-1 font-medium">Anggota</div>
            </div>
            <div class="bg-white/90 border border-indigo-50 rounded-2xl p-5 shadow-sm hover:shadow-md transition">
                <div class="text-3xl md:text-4xl font-extrabold text-gradient counter" data-target="<?= $total_journals ?>">0</div>
                <div class="text-xs text-gray-600 mt-1 font-medium">Jurnal</div>
            </div>
            <div class="bg-white/90 border border-indigo-50 rounded-2xl p-5 shadow-sm hover:shadow-md transition">
                <div class="text-3xl md:text-4xl font-extrabold text-gradient counter" data-target="<?= $total_photos ?>">0</div>
                <div class="text-xs text-gray-600 mt-1 font-medium">Foto</div>
            </div>
            <div class="bg-white/90 border border-indigo-50 rounded-2xl p-5 shadow-sm hover:shadow-md transition">
                <div class="text-3xl md:text-4xl font-extrabold text-gradient counter" data-target="<?= $total_tasks ?>">0</div>
                <div class="text-xs text-gray-600 mt-1 font-medium">Tugas Selesai</div>
            </div>
        </div>
    </div>
</header>

<!-- ========== TIM KELOMPOK ========== -->
<main class="max-w-6xl mx-auto px-4 py-20">
    <div class="reveal text-center mb-12">
        <span class="text-xs font-bold text-primary uppercase tracking-[0.2em]">Kenalan</span>
        <h2 class="text-3xl md:text-4xl font-extrabold text-gray-900 mt-3 mb-3">
            Tim Kelompok <span class="text-gradient">Kami</span>
        </h2>
        <p class="text-gray-500 text-sm md:text-base max-w-md mx-auto">
            <?= count($pembimbings) ?> pembimbing & <?= count($siswas) ?> siswa PKL siap berkolaborasi.
        </p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
        <?php foreach ($users as $i => $u):
            $foto = !empty($u['photo'])
                ? UPLOAD_URL . $u['photo']
                : 'https://ui-avatars.com/api/?name=' . urlencode($u['name']) . '&background=EEF2FF&color=4F46E5&size=200';
            $isPembimbing = in_array($u['role'], ['pembimbing','teacher']);
        ?>
        <div class="reveal bg-white rounded-2xl p-6 shadow-sm border border-gray-100 hover:shadow-xl hover:-translate-y-1 transition-all duration-300 text-center group"
             style="transition-delay: <?= $i*50 ?>ms">

            <!-- Foto -->
            <div class="relative inline-block mb-4">
                <img src="<?= $foto ?>" alt="<?= e($u['name']) ?>"
                     class="w-24 h-24 rounded-full object-cover mx-auto ring-4 ring-indigo-50 group-hover:ring-indigo-100 transition-all">
                <?php if ($isPembimbing): ?>
                    <span class="absolute -bottom-1 -right-1 bg-gradient-to-br from-amber-400 to-orange-500 text-white text-[10px] font-bold px-2.5 py-1 rounded-full border-2 border-white shadow-md whitespace-nowrap">
                        👨‍🏫 Pembimbing
                    </span>
                <?php else: ?>
                    <span class="absolute -bottom-1 -right-1 bg-gradient-to-br from-indigo-400 to-purple-500 text-white text-[10px] font-bold px-2.5 py-1 rounded-full border-2 border-white shadow-md whitespace-nowrap">
                        👤 Siswa
                    </span>
                <?php endif; ?>
            </div>

            <h3 class="text-lg font-bold text-gray-900 mb-1 group-hover:text-primary transition-colors"><?= e($u['name']) ?></h3>
            <p class="text-sm font-medium text-primary mb-3">
                <?= e($u['jurusan'] ?: 'Anggota PKL') ?>
            </p>

            <p class="text-sm text-gray-500 line-clamp-3 leading-relaxed min-h-[60px]">
                <?= e($u['bio'] ?: 'Belum ada deskripsi bio.') ?>
            </p>

            <div class="mt-4 pt-4 border-t border-gray-100 text-xs text-gray-400 flex items-center justify-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                <?= e($u['sekolah'] ?: 'SMK N 1 Contoh') ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($users)): ?>
            <div class="col-span-full text-center text-gray-500 py-10">Belum ada anggota tim.</div>
        <?php endif; ?>
    </div>
</main>

<!-- ========== FOOTER ========== -->
<footer class="bg-gray-50 border-t border-gray-100">
    <div class="max-w-6xl mx-auto px-4 py-8">
        <div class="flex flex-col md:flex-row justify-between items-center gap-4">
            <div class="flex items-center gap-2 font-bold">
                <span class="text-xl">📔</span>
                <span class="text-gradient"><?= APP_NAME ?></span>
            </div>
            <p class="text-xs text-gray-500 text-center md:text-right">
                &copy; <?= date('Y') ?> <?= APP_NAME ?>. Dibuat dengan <span class="text-red-500">❤️</span> untuk Siswa PKL.
            </p>
        </div>
    </div>
</footer>

<!-- ========== TOMBOL KE ATAS ========== -->
<button id="back-top" class="hidden fixed bottom-6 right-6 bg-gradient-to-br from-primary to-purple-600 text-white p-3.5 rounded-full shadow-lg hover:shadow-xl transition-all hover:-translate-y-1 z-40" title="Ke atas">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18"/></svg>
</button>

<script>
// Reveal
const observer = new IntersectionObserver((entries) => {
    entries.forEach(e => {
        if (e.isIntersecting) {
            e.target.classList.add('active');
            observer.unobserve(e.target);
        }
    });
}, { threshold: 0.1 });
document.querySelectorAll('.reveal').forEach(el => observer.observe(el));

// Counter
const counters = document.querySelectorAll('.counter');
const cObserver = new IntersectionObserver((entries) => {
    entries.forEach(e => {
        if (!e.isIntersecting) return;
        const el = e.target;
        const target = parseInt(el.dataset.target) || 0;
        let current = 0;
        const step = Math.max(1, Math.ceil(target / 40));
        const timer = setInterval(() => {
            current += step;
            if (current >= target) { current = target; clearInterval(timer); }
            el.textContent = current + (target > 0 ? '+' : '');
        }, 30);
        cObserver.unobserve(el);
    });
}, { threshold: 0.3 });
counters.forEach(c => cObserver.observe(c));

// Back to top
const backBtn = document.getElementById('back-top');
window.addEventListener('scroll', () => {
    backBtn.classList.toggle('hidden', window.scrollY < 400);
});
backBtn.onclick = () => window.scrollTo({ top: 0, behavior: 'smooth' });
</script>

</body>
</html>