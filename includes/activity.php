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

            // Ambil data pengguna dari session
            $uid = $userId ?? (
                (int)($_SESSION['user_id'] ?? $_SESSION['id'] ?? 0) ?: null
            );

            $username = $_SESSION['name'] ?? $_SESSION['nama'] ?? null;
            $role = $_SESSION['role'] ?? null;

            // Ambil data dari currentUser() jika tersedia
            if (function_exists('currentUser')) {
                $current = currentUser();

                if (is_array($current)) {
                    $uid = $uid ?? (
                        (int)($current['id'] ?? 0) ?: null
                    );

                    $username = $username ?? ($current['name'] ?? null);
                    $role = $role ?? ($current['role'] ?? null);
                }
            }

            // Batasi panjang data
            $aksi = substr(trim($aksi), 0, 50);

            $detail = $detail !== null
                ? substr(trim($detail), 0, 2000)
                : null;

            $username = $username !== null
                ? substr(trim($username), 0, 100)
                : null;

            $role = $role !== null
                ? substr(trim($role), 0, 30)
                : null;

            // Ambil IP dari koneksi langsung
            // Tidak menggunakan X-Forwarded-For karena dapat dipalsukan
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;

            if ($ip && !filter_var($ip, FILTER_VALIDATE_IP)) {
                $ip = null;
            }

            $ip = $ip ? substr($ip, 0, 45) : null;

            // Informasi browser
            $userAgent = substr(
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                0,
                255
            ) ?: null;

            // Simpan aktivitas
            $sql = "INSERT INTO activity_log
                    (
                        user_id,
                        username,
                        role,
                        aksi,
                        detail,
                        ip,
                        user_agent
                    )
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