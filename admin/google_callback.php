<?php
// admin/google_callback.php - Google OAuth 2.0 / OpenID Connect Callback Handler
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDb();
$code = $_GET['code'] ?? '';
$state = $_GET['state'] ?? '';
$savedState = $_SESSION['oauth2_state'] ?? '';

// 1. Verify CSRF State
if (empty($code) || empty($state) || !hash_equals($savedState, $state)) {
    header('Location: /admin/login.php?error=invalid_state');
    exit;
}
unset($_SESSION['oauth2_state']);

try {
    // 2. Exchange Authorization Code for Google Profile
    $googleProfile = exchangeGoogleAuthCode($code);

    if (!$googleProfile || empty($googleProfile['email'])) {
        header('Location: /admin/login.php?error=google_failed');
        exit;
    }

    $email = strtolower(trim($googleProfile['email']));
    $googleSub = $googleProfile['sub'] ?? '';
    $name = $googleProfile['name'] ?? 'ผู้ดูแล';
    $picture = $googleProfile['picture'] ?? '';

    // 3. Verify Admin Authorization in Database
    $stmt = $db->prepare("SELECT * FROM admins WHERE LOWER(email) = ?");
    $stmt->execute([$email]);
    $admin = $stmt->fetch();

    if (!$admin) {
        $encodedEmail = urlencode($email);
        header("Location: /admin/login.php?error=unauthorized_email&email={$encodedEmail}");
        exit;
    }

    if ((int) ($admin['is_active'] ?? 1) !== 1) {
        header('Location: /admin/login.php?error=account_disabled');
        exit;
    }

    // 4. Update Admin Metadata (Avatar, Google ID, Last Login)
    $stmtUp = $db->prepare("
        UPDATE admins 
        SET google_id = ?, avatar = COALESCE(NULLIF(?, ''), avatar), last_login = CURRENT_TIMESTAMP 
        WHERE id = ?
    ");
    $stmtUp->execute([$googleSub, $picture, $admin['id']]);

    // 5. Establish Hardened Session
    session_regenerate_id(true);

    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_id'] = (int) $admin['id'];
    $_SESSION['admin_email'] = $admin['email'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['admin_name'] = !empty($admin['name']) ? $admin['name'] : $name;
    $_SESSION['admin_role'] = $admin['role'];
    $_SESSION['admin_avatar'] = !empty($picture) ? $picture : ($admin['avatar'] ?: "https://ui-avatars.com/api/?name=" . urlencode($admin['name']) . "&background=3d516b&color=fff");

    // Redirect to unified admin hub
    header('Location: /admin/index.php');
    exit;

} catch (Throwable $e) {
    error_log("Google Callback Exception: " . $e->getMessage());
    header('Location: /admin/login.php?error=server_error');
    exit;
}
