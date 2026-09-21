<?php
// admin/login.php - หน้าเข้าสู่ระบบสำหรับ Admin
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$error = '';
$db = getDb();

// ถ้าล็อกอินอยู่แล้ว ให้ไปหน้า admin/index.php ทันที
if (isAdminLoggedIn()) {
    header('Location: /admin/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = clean($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        $stmt = $db->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password'])) {
            // ป้องกัน Session Fixation
            session_regenerate_id(true);

            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_name'] = $admin['name'];
            $_SESSION['admin_role'] = $admin['role'];

            // ป้องกัน Open Redirect Vulnerability
            $redirect = $_GET['redirect'] ?? '/admin/index.php';
            if (!is_string($redirect) || !str_starts_with($redirect, '/') || str_starts_with($redirect, '//') || str_contains($redirect, '\\')) {
                $redirect = '/admin/index.php';
            }

            header("Location: {$redirect}");
            exit;
        } else {
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        }
    }
}

define('APP_TITLE', 'เข้าสู่ระบบผู้ดูแลระบบ');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="min-h-[80vh] flex items-center justify-center px-4 py-12">
    <div class="bg-white rounded-3xl p-8 sm:p-10 max-w-md w-full shadow-xl border border-slate-200">
        
        <div class="text-center mb-8">
            <div class="w-14 h-14 rounded-2xl bg-slate-900 border border-slate-800 text-amber-400 flex items-center justify-center mx-auto shadow-md mb-4 ring-4 ring-slate-100">
                <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path>
                </svg>
            </div>
            <h1 class="text-xl font-bold text-slate-900 tracking-tight">เข้าสู่ระบบผู้ดูแล</h1>
            <p class="text-xs text-slate-500 mt-1">ระบบจองรถต้นเดือน • ธุรการอาศรมบรรพชิต DCI</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="p-3.5 mb-6 rounded-2xl bg-rose-50 text-rose-800 border border-rose-200 text-xs sm:text-sm flex items-center space-x-2">
                <i class="fa-solid fa-circle-exclamation text-rose-500 text-base"></i>
                <span><?= $error ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1.5">ชื่อผู้ใช้งาน (Username)</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                        <i class="fa-solid fa-user text-xs"></i>
                    </span>
                    <input type="text" 
                           name="username" 
                           required 
                           value="<?= clean($_POST['username'] ?? '') ?>"
                           placeholder="ชื่อผู้ใช้งาน"
                           autocomplete="username"
                           class="w-full pl-9 pr-3 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-1 focus:ring-slate-900 focus:border-slate-900 outline-none transition">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1.5">รหัสผ่าน (Password)</label>
                <div class="relative">
                    <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                        <i class="fa-solid fa-lock text-xs"></i>
                    </span>
                    <input type="password" 
                           name="password" 
                           required 
                           placeholder="กรอกรหัสผ่าน"
                           autocomplete="current-password"
                           class="w-full pl-9 pr-3 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm focus:ring-1 focus:ring-slate-900 focus:border-slate-900 outline-none transition">
                </div>
            </div>

            <button type="submit" class="w-full py-2.5 px-4 bg-slate-900 hover:bg-slate-800 text-white rounded-xl font-semibold text-xs sm:text-sm shadow-xs transition flex items-center justify-center space-x-2">
                <i class="fa-solid fa-arrow-right-to-bracket text-xs"></i>
                <span>เข้าสู่ระบบ</span>
            </button>
        </form>

        <div class="mt-6 text-center">
            <a href="/index.php" class="text-xs text-slate-500 hover:text-indigo-600">
                ← กลับสู่หน้าหลัก
            </a>
        </div>

    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
