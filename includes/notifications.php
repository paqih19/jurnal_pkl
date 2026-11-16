<?php
// includes/notifications.php

/**
 * Memotong teks dengan aman.
 */
if (!function_exists('notifLimitText')) {
    function notifLimitText(string $text, int $limit): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, 0, $limit, 'UTF-8');
        }

        return substr($text, 0, $limit);
    }
}

/**
 * Membuat notifikasi untuk satu pengguna.
 */
if (!function_exists('createNotification')) {
    function createNotification(
        PDO $pdo,
        int $userId,
        string $type,
        string $title,
        string $message = '',
        ?string $link = null
    ): bool {
        if ($userId <= 0 || trim($title) === '') {
            return false;
        }

        try {
            $type    = notifLimitText(trim($type), 30);
            $title   = notifLimitText(trim($title), 200);
            $message = notifLimitText(trim($message), 1000);
            $link    = $link !== null && trim($link) !== ''
                ? notifLimitText(trim($link), 255)
                : null;

            $stmt = $pdo->prepare("
                INSERT INTO notifications
                    (user_id, type, title, message, link, is_read, created_at)
                VALUES
                    (?, ?, ?, ?, ?, 0, NOW())
            ");

            $stmt->execute([
                $userId,
                $type,
                $title,
                $message,
                $link
            ]);

            return true;

        } catch (Throwable $e) {
            error_log('Create Notification Error: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Membuat notifikasi untuk banyak pengguna.
 * Diproses per 500 pengguna agar query tidak terlalu besar.
 */
if (!function_exists('createNotificationBulk')) {
    function createNotificationBulk(
        PDO $pdo,
        array $userIds,
        string $type,
        string $title,
        string $message = '',
        ?string $link = null
    ): bool {
        $userIds = array_values(array_unique(
            array_filter(
                array_map('intval', $userIds),
                fn($id) => $id > 0
            )
        ));

        if (!$userIds || trim($title) === '') {
            return false;
        }

        $type    = notifLimitText(trim($type), 30);
        $title   = notifLimitText(trim($title), 200);
        $message = notifLimitText(trim($message), 1000);
        $link    = $link !== null && trim($link) !== ''
            ? notifLimitText(trim($link), 255)
            : null;

        $startedTransaction = false;

        try {
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTransaction = true;
            }

            foreach (array_chunk($userIds, 500) as $chunk) {
                $placeholders = implode(
                    ',',
                    array_fill(0, count($chunk), '(?,?,?,?,?,0,NOW())')
                );

                $values = [];

                foreach ($chunk as $uid) {
                    $values[] = $uid;
                    $values[] = $type;
                    $values[] = $title;
                    $values[] = $message;
                    $values[] = $link;
                }

                $sql = "
                    INSERT INTO notifications
                        (user_id, type, title, message, link, is_read, created_at)
                    VALUES $placeholders
                ";

                $stmt = $pdo->prepare($sql);
                $stmt->execute($values);
            }

            if ($startedTransaction) {
                $pdo->commit();
            }

            return true;

        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log('Bulk Notification Error: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Menghitung jumlah notifikasi yang belum dibaca.
 */
if (!function_exists('unreadNotifCount')) {
    function unreadNotifCount(PDO $pdo, int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM notifications
                WHERE user_id = ?
                  AND is_read = 0
            ");

            $stmt->execute([$userId]);

            return (int) $stmt->fetchColumn();

        } catch (Throwable $e) {
            error_log('Unread Notification Error: ' . $e->getMessage());
            return 0;
        }
    }
}

/**
 * Mengambil daftar notifikasi pengguna.
 */
if (!function_exists('getNotifications')) {
    function getNotifications(
        PDO $pdo,
        int $userId,
        int $limit = 15
    ): array {
        if ($userId <= 0) {
            return [];
        }

        $limit = max(1, min(100, $limit));

        try {
            $stmt = $pdo->prepare("
                SELECT *
                FROM notifications
                WHERE user_id = ?
                ORDER BY created_at DESC, id DESC
                LIMIT $limit
            ");

            $stmt->execute([$userId]);

            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        } catch (Throwable $e) {
            error_log('Get Notifications Error: ' . $e->getMessage());
            return [];
        }
    }
}

/**
 * Menandai satu notifikasi sebagai sudah dibaca.
 */
if (!function_exists('markNotificationRead')) {
    function markNotificationRead(
        PDO $pdo,
        int $notifId,
        int $userId
    ): bool {
        if ($notifId <= 0 || $userId <= 0) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE id = ?
                  AND user_id = ?
            ");

            $stmt->execute([$notifId, $userId]);

            return $stmt->rowCount() > 0;

        } catch (Throwable $e) {
            error_log('Mark Notification Read Error: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Menandai seluruh notifikasi pengguna sebagai sudah dibaca.
 */
if (!function_exists('markAllNotificationsRead')) {
    function markAllNotificationsRead(
        PDO $pdo,
        int $userId
    ): bool {
        if ($userId <= 0) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE notifications
                SET is_read = 1
                WHERE user_id = ?
                  AND is_read = 0
            ");

            $stmt->execute([$userId]);

            return true;

        } catch (Throwable $e) {
            error_log('Mark All Notifications Read Error: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Mengirim notifikasi kepada seluruh pembimbing.
 */
if (!function_exists('notifyPembimbing')) {
    function notifyPembimbing(
        PDO $pdo,
        string $type,
        string $title,
        string $message = '',
        ?string $link = null
    ): bool {
        try {
            $stmt = $pdo->query("
                SELECT id
                FROM users
                WHERE role IN ('pembimbing', 'teacher')
            ");

            $ids = array_column(
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                'id'
            );

            if (!$ids) {
                return false;
            }

            return createNotificationBulk(
                $pdo,
                $ids,
                $type,
                $title,
                $message,
                $link
            );

        } catch (Throwable $e) {
            error_log('Notify Pembimbing Error: ' . $e->getMessage());
            return false;
        }
    }
}

/**
 * Mengirim notifikasi kepada seluruh siswa.
 */
if (!function_exists('notifySiswa')) {
    function notifySiswa(
        PDO $pdo,
        string $type,
        string $title,
        string $message = '',
        ?string $link = null
    ): bool {
        try {
            $stmt = $pdo->query("
                SELECT id
                FROM users
                WHERE role IS NOT NULL
                  AND role NOT IN ('pembimbing', 'teacher', 'admin')
            ");

            $ids = array_column(
                $stmt->fetchAll(PDO::FETCH_ASSOC),
                'id'
            );

            if (!$ids) {
                return false;
            }

            return createNotificationBulk(
                $pdo,
                $ids,
                $type,
                $title,
                $message,
                $link
            );

        } catch (Throwable $e) {
            error_log('Notify Siswa Error: ' . $e->getMessage());
            return false;
        }
    }
}