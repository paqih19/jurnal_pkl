<?php
// includes/footer.php
?>
    </main>
    <footer class="bg-white dark:bg-slate-800 border-t border-gray-100 dark:border-slate-700 mt-auto">
        <div class="max-w-6xl mx-auto px-4 py-6">
            <div class="flex flex-col md:flex-row justify-between items-center gap-3">
                <div class="flex items-center gap-2">
                    <span class="text-xl">📔</span>
                    <span class="font-extrabold text-transparent bg-clip-text bg-gradient-to-r from-primary to-brand-purple">Jurnal PKL</span>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 text-center">
                    &copy; <?= date('Y') ?> <?= APP_NAME ?>. Dibuat dengan <span class="text-red-500">❤️</span> untuk PKL.
                </p>
            </div>
        </div>
    </footer>
</body>
</html>