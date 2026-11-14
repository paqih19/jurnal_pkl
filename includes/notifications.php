<?php
// includes/notifications.php

function createNotification($pdo, $userId, $type, $title, $message = '', $link = null) {
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt->execute([$userId, $type, $title, $message, $link]);
        return true;
    } catch (Exception $e) { return false; }
}

function createNotificationBulk($pdo, $userIds, $type, $title, $message = '', $link = null) {
    if (empty($userIds)) return false;
    try {
        $ph = implode(',', array_fill(0, count($userIds), '(?,?,?,?,?,NOW())'));
        $values = [];
        foreach ($userIds as $uid) {
            $values[] = $uid; $values[] = $type; $values[] = $title; $values[] = $message; $values[] = $link;
        }
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, type, title, message, link, created_at) VALUES " . $ph);
        $stmt->execute($values);
        return true;
    } catch (Exception $e) { return false; }
}

function unreadNotifCount($pdo, $userId) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $stmt->execute([$userId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) { return 0; }
}

function getNotifications($pdo, $userId, $limit = 15) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return []; }
}

function notifyPembimbing($pdo, $type, $title, $message = '', $link = null) {
    try {
        $stmt = $pdo->query("SELECT id FROM users WHERE role IN ('pembimbing','teacher')");
        $ids = array_column($stmt->fetchAll(), 'id');
        if (empty($ids)) return false;
        return createNotificationBulk($pdo, $ids, $type, $title, $message, $link);
    } catch (Exception $e) { return false; }
}