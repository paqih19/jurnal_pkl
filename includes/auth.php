<?php
// includes/auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/functions.php';

function requireLogin() {
    if (empty($_SESSION['user_id'])) {
        setFlash('error', 'Silakan login terlebih dahulu.');
        redirect('login.php');
    }
}

function requireGuest() {
    if (!empty($_SESSION['user_id'])) {
        redirect('dashboard.php');
    }
}

function requireRole($role) {
    requireLogin();
    $userRole = $_SESSION['role'] ?? '';
    $match = ($userRole === $role);
    if (!$match && in_array($role, ['teacher','pembimbing']) && in_array($userRole, ['teacher','pembimbing'])) {
        $match = true;
    }
    if (!$match) {
        setFlash('error', 'Akses ditolak.');
        redirect('dashboard.php');
    }
}

function hasRole($role) {
    $userRole = $_SESSION['role'] ?? '';
    if ($userRole === $role) return true;
    if (in_array($role, ['teacher','pembimbing']) && in_array($userRole, ['teacher','pembimbing'])) return true;
    return false;
}

function isTeacher() { return hasRole('pembimbing'); }
function isPembimbing() { return hasRole('pembimbing'); }
function isStudent() { return !isPembimbing() && !empty($_SESSION['user_id']); }

function currentUser() {
    return [
        'id'       => $_SESSION['user_id']   ?? null,
        'name'     => $_SESSION['name']      ?? null,
        'email'    => $_SESSION['email']     ?? null,
        'username' => $_SESSION['username']  ?? null,
        'role'     => $_SESSION['role']      ?? null,
        'photo'    => $_SESSION['photo']     ?? null,
    ];
}

function isLoggedIn() {
    return !empty($_SESSION['user_id']);
}