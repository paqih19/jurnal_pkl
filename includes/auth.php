<?php
// includes/auth.php

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/activity.php';

// ================= ROLE =================
if (!defined('ROLE_PEMBIMBING')) {
    define('ROLE_PEMBIMBING', ['pembimbing', 'teacher', 'admin']);
}

// ================= LOGIN =================
function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        setFlash('error', 'Silakan login terlebih dahulu.');
        redirect('login.php');
    }

    // Logout otomatis setelah 8 jam tidak aktif
    $last = (int)($_SESSION['last_activity'] ?? 0);

    if ($last > 0 && (time() - $last) > 8 * 3600) {
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        setFlash('error', 'Sesi berakhir karena tidak aktif. Silakan login kembali.');
        redirect('login.php');
    }

    $_SESSION['last_activity'] = time();
}

// ================= GUEST =================
function requireGuest(): void
{
    if (!empty($_SESSION['user_id'])) {
        redirect('dashboard.php');
    }
}

// ================= HAK AKSES =================
function hasRole($role): bool
{
    $userRole = $_SESSION['role'] ?? '';

    if (is_array($role)) {
        return in_array($userRole, $role, true);
    }

    if ($userRole === $role) {
        return true;
    }

    // Admin juga boleh mengakses halaman pembimbing
    if (
        $role === 'pembimbing' &&
        in_array($userRole, ROLE_PEMBIMBING, true)
    ) {
        return true;
    }

    return false;
}

function requireRole($role): void
{
    requireLogin();

    if (!hasRole($role)) {
        setFlash('error', 'Akses ditolak. Kamu tidak memiliki izin.');
        redirect('dashboard.php');
    }
}

// ================= IDENTITAS ROLE =================
function isTeacher(): bool
{
    return hasRole('pembimbing');
}

function isPembimbing(): bool
{
    return hasRole('pembimbing');
}

function isAdmin(): bool
{
    return hasRole('admin');
}

function isStudent(): bool
{
    return !empty($_SESSION['user_id'])
        && !isPembimbing()
        && !isAdmin();
}

// ================= DATA PENGGUNA =================
function currentUser(): array
{
    return [
        'id'       => $_SESSION['user_id'] ?? null,
        'name'     => $_SESSION['name'] ?? null,
        'email'    => $_SESSION['email'] ?? null,
        'username' => $_SESSION['username'] ?? null,
        'role'     => $_SESSION['role'] ?? null,
        'photo'    => $_SESSION['photo'] ?? null,
    ];
}

// ================= STATUS LOGIN =================
function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

// ================= REMEMBER ME =================
function tryRememberMe(PDO $pdo): void
{
    if (isLoggedIn() || empty($_COOKIE['remember_token'])) {
        return;
    }

    $raw = $_COOKIE['remember_token'];

    // Token harus berupa 64 karakter hexadecimal
    if (
        !is_string($raw) ||
        !preg_match('/^[a-f0-9]{64}$/i', $raw)
    ) {
        return;
    }

    try {
        $hashed = hash('sha256', $raw);

        $stmt = $pdo->prepare(
            "SELECT id, name, email, username, role, photo
             FROM users
             WHERE remember_token = ?
             LIMIT 1"
        );

        $stmt->execute([$hashed]);
        $user = $stmt->fetch();

        if (!$user) {
            setcookie('remember_token', '', [
                'expires' => time() - 3600,
                'path' => '/',
                'httponly' => true,
                'secure' => !empty($_SERVER['HTTPS'])
                    && $_SERVER['HTTPS'] !== 'off',
                'samesite' => 'Lax'
            ]);

            return;
        }

        // Perbarui ID session
        session_regenerate_id(true);

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['name'] = $user['name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['photo'] = $user['photo'] ?? null;
        $_SESSION['last_activity'] = time();

        logActivity(
            'login_remember',
            'Login otomatis melalui remember-me',
            (int)$user['id']
        );

    } catch (Throwable $e) {
        error_log('Remember Me Error: ' . $e->getMessage());
    }
}