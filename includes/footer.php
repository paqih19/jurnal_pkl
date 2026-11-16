<?php
// includes/footer.php

$appName = defined('APP_NAME') ? APP_NAME : 'Jurnal PKL';
?>

    </main>

    <!-- FOOTER -->
    <footer class="relative mt-auto overflow-hidden
                   bg-white dark:bg-slate-900
                   border-t border-slate-200 dark:border-slate-700">

        <!-- Garis aksen -->
        <div class="h-1 w-full bg-gradient-to-r
                    from-indigo-500 via-purple-500 to-blue-500"></div>

        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-7">

            <div class="flex flex-col md:flex-row
                        items-center justify-between gap-5">

                <!-- Identitas aplikasi -->
                <div class="flex items-center gap-3">

                    <div class="w-11 h-11 rounded-xl
                                flex items-center justify-center
                                bg-gradient-to-br from-indigo-500 to-purple-600
                                text-white shadow-md shadow-indigo-500/20">

                        <span class="text-2xl">📔</span>
                    </div>

                    <div>
                        <h3 class="font-bold text-lg
                                   bg-gradient-to-r from-indigo-600 to-purple-600
                                   dark:from-indigo-400 dark:to-purple-400
                                   bg-clip-text text-transparent">
                            <?= e($appName) ?>
                        </h3>

                        <p class="text-xs text-slate-500 dark:text-slate-400">
                            Sistem Informasi Jurnal Praktik Kerja Lapangan
                        </p>
                    </div>

                </div>

                <!-- Informasi footer -->
                <div class="text-center md:text-right">

                    <p class="text-sm text-slate-600 dark:text-slate-300">
                        &copy; <?= date('Y') ?>
                        <span class="font-semibold"><?= e($appName) ?></span>
                    </p>

                    <p class="text-xs mt-1 text-slate-500 dark:text-slate-400">
                        Dibuat dengan
                        <span class="text-red-500">❤️</span>
                        untuk mendukung kegiatan PKL.
                    </p>

                </div>

            </div>

            <!-- Garis pemisah -->
            <div class="border-t border-slate-100 dark:border-slate-800 mt-6 pt-4">

                <p class="text-center text-xs text-slate-400 dark:text-slate-500">
                    Jurnal kegiatan, presensi, tugas, dan dokumentasi PKL.
                </p>

            </div>

        </div>

    </footer>

    <!-- BACK TO TOP -->
    <button
        type="button"
        id="back-to-top"
        aria-label="Kembali ke atas"
        title="Kembali ke atas"
        class="hidden fixed bottom-6 right-6 z-40
               w-11 h-11 rounded-xl
               items-center justify-center
               bg-gradient-to-br from-indigo-600 to-purple-600
               text-white shadow-lg shadow-indigo-500/25
               hover:from-indigo-700 hover:to-purple-700
               hover:-translate-y-1 hover:shadow-xl
               transition-all duration-300
               focus:outline-none focus:ring-4 focus:ring-indigo-300
               dark:focus:ring-indigo-900">

        <svg class="w-5 h-5"
             fill="none"
             stroke="currentColor"
             viewBox="0 0 24 24">

            <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M5 10l7-7m0 0l7 7m-7-7v18">
            </path>

        </svg>

    </button>

    <!-- JAVASCRIPT FOOTER -->
    <script>
    (function () {

        const btn = document.getElementById('back-to-top');

        if (!btn) return;

        // Tampilkan tombol setelah halaman digulir
        function toggleBackToTop() {

            if (window.scrollY > 400) {
                btn.classList.remove('hidden');
                btn.classList.add('flex');
            } else {
                btn.classList.add('hidden');
                btn.classList.remove('flex');
            }

        }

        window.addEventListener('scroll', toggleBackToTop, {
            passive: true
        });

        // Kembali ke bagian paling atas
        btn.addEventListener('click', function () {

            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });

        });

        // Periksa posisi awal halaman
        toggleBackToTop();

    })();
    </script>

</body>
</html>