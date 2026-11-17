<?php
// includes/activity.php

/**
 * ==================================================
 * SISTEM LOG AKTIVITAS JURNAL PKL
 * ==================================================
 * Mencatat aktivitas pengguna ke tabel activity_log.
 * Tetap berjalan tanpa mengganggu aplikasi jika gagal.
 */

if (!function_exists('logActivity')) {

    /**
     * Catat aktivitas ke tabel activity_log.
     *
     * @param string      $aksi   Nama aksi (maks 50 karakter)
     * @param string|null $detail Detail tambahan (maks 2000 karakter)
     * @param int|null    $userId ID user (null = dari session)
     */
    function logActivity(
        string $aksi,
        ?string $detail = null,
        ?int $userId = null
    ): void {
        global $pdo;

        try {
            // Pastikan koneksi database tersedia
            if (!isset($pdo) || !$pdo instanceof PDO) {
                return;
            }

            // Cek tabel activity_log ada (cache per request)
            static $tableExists = null;

            if ($tableExists === null) {
                try {
                    $pdo->query("SELECT 1 FROM activity_log LIMIT 1");
                    $tableExists = true;
                } catch (Throwable $e) {
                    $tableExists = false;
                    error_log('Activity Log: tabel activity_log tidak tersedia.');
                }
            }

            if (!$tableExists) {
                return;
            }

            // Ambil data pengguna dari session
            $uid = $userId ?? (
                (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0) ?: null
            );

            $username = $_SESSION['name'] ?? $_SESSION['nama'] ?? null;
            $role     = $_SESSION['role'] ?? null;

            // Ambil data dari currentUser() jika tersedia
            if (function_exists('currentUser')) {
                $current = currentUser();

                if (is_array($current)) {
                    $uid      = $uid ?? ((int)($current['id'] ?? 0) ?: null);
                    $username = $username ?? ($current['name'] ?? null);
                    $role     = $role ?? ($current['role'] ?? null);
                }
            }

            // Batasi panjang data (pakai mb_substr agar aman untuk UTF-8)
            $substr = function_exists('mb_substr') ? 'mb_substr' : 'substr';

            $aksi = $substr(trim($aksi), 0, 50);
            $aksi = $aksi !== '' ? $aksi : 'unknown';

            $detail = $detail !== null
                ? $substr(trim($detail), 0, 2000)
                : null;

            $detail = ($detail === '') ? null : $detail;

            $username = $username !== null
                ? $substr(trim((string)$username), 0, 100)
                : null;

            $username = ($username === '') ? null : $username;

            $role = $role !== null
                ? $substr(trim((string)$role), 0, 30)
                : null;

            $role = ($role === '') ? null : $role;

            // Ambil IP dari koneksi langsung
            // Tidak menggunakan X-Forwarded-For karena dapat dipalsukan
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;

            if ($ip && !filter_var($ip, FILTER_VALIDATE_IP)) {
                $ip = null;
            }

            $ip = $ip ? substr($ip, 0, 45) : null;

            // Informasi browser
            $userAgent = substr(
                (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
                0, 255
            );

            $userAgent = $userAgent !== '' ? $userAgent : null;

            // Simpan aktivitas
            $sql = "INSERT INTO activity_log
                    (user_id, username, role, aksi, detail, ip, user_agent)
                    VALUES (?, ?, ?, ?, ?, ?, ?)";

            $stmt = $pdo->prepare($sql);

            $stmt->execute([
                $uid,
                $username,
                $role,
                $aksi,
                $detail,
                $ip,
                $userAgent
            ]);

        } catch (Throwable $e) {
            // Catat kegagalan tanpa mengganggu halaman aplikasi
            error_log('Activity Log Error: ' . $e->getMessage());
        }
    }
}