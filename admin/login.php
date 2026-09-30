<?php
// admin/login.php - International Standard Gmail / Google OAuth Login for Multi-Tier Admins
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDb();

// ถ้าล็อกอินอยู่แล้ว ให้ไปหน้าเป้าหมายทันที
if (isAdminLoggedIn()) {
    header("Location: /admin/index.php");
    exit;
}

$error = '';
$errCode = $_GET['error'] ?? '';
$unauthEmail = htmlspecialchars($_GET['email'] ?? '', ENT_QUOTES, 'UTF-8');

if ($errCode === 'unauthorized_email') {
    $error = "อีเมล Gmail <strong>{$unauthEmail}</strong> ยังไม่ได้รับอนุญาตในระบบ กรุณาติดต่อ Super Admin เพื่อเพิ่มสิทธิ์";
} elseif ($errCode === 'invalid_state') {
    $error = 'ความปลอดภัย: Session หมดอายุหรือ State ไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง';
} elseif ($errCode === 'google_failed') {
    $error = 'ไม่สามารถเชื่อมต่อกับบัญชี Google ได้ กรุณาลองใหม่อีกครั้ง';
} elseif ($errCode === 'account_disabled') {
    $error = 'บัญชีผู้ดูแลนี้ถูกระงับการใช้งานชั่วคราว';
} elseif ($errCode === 'server_error') {
    $error = 'เกิดข้อผิดพลาดในการตรวจสอบบัญชี กรุณาลองใหม่';
}

$googleConfig = getGoogleOAuthConfig();

// จัดการคำขอเข้าสู่ระบบแบบ Direct Gmail Sign-in (สำหรับโหมดพัฒนา หรือเข้าใช้งานด้วยบัญชี Gmail ที่ได้รับอนุญาต)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'gmail_direct_login') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($submittedToken)) {
        $error = 'ข้อผิดพลาดความปลอดภัย: CSRF Token ไม่ถูกต้อง';
    } else {
        $gmailInput = strtolower(trim((string)($_POST['gmail'] ?? '')));

        if (empty($gmailInput)) {
            $error = 'กรุณาระบุบัญชี Gmail ของท่าน';
        } else {
            $stmt = $db->prepare("SELECT * FROM admins WHERE LOWER(email) = ? OR LOWER(username) = ?");
            $stmt->execute([$gmailInput, $gmailInput]);
            $admin = $stmt->fetch();

            if (!$admin) {
                $error = "ไม่พบบัญชี Gmail <strong>" . htmlspecialchars($gmailInput, ENT_QUOTES, 'UTF-8') . "</strong> ในรายชื่อผู้ดูแลระบบ";
            } elseif ((int)($admin['is_active'] ?? 1) !== 1) {
                $error = 'บัญชีนี้ถูกปิดการใช้งาน';
            } else {
                // อัปเดต Last login และสถาปนา Session
                session_regenerate_id(true);

                $stmtUp = $db->prepare("UPDATE admins SET last_login = CURRENT_TIMESTAMP WHERE id = ?");
                $stmtUp->execute([$admin['id']]);

                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = (int)$admin['id'];
                $_SESSION['admin_email'] = $admin['email'];
                $_SESSION['admin_username'] = $admin['username'];
                $_SESSION['admin_name'] = $admin['name'];
                $_SESSION['admin_role'] = $admin['role'];
                $_SESSION['admin_avatar'] = !empty($admin['avatar']) ? $admin['avatar'] : ("https://ui-avatars.com/api/?name=" . urlencode($admin['name']) . "&background=3d516b&color=fff&size=128");

                $redirect = $_GET['redirect'] ?? '/admin/index.php';
                if (!is_string($redirect) || !str_starts_with($redirect, '/') || str_starts_with($redirect, '//') || str_contains($redirect, '\\')) {
                    $redirect = '/admin/index.php';
                }

                header("Location: {$redirect}");
                exit;
            }
        }
    }
}

$googleAuthUrl = getGoogleAuthUrl();

define('APP_TITLE', 'เข้าสู่ระบบผู้ดูแลระบบด้วย Gmail');
require_once __DIR__ . '/../includes/header.php';
?>

<div id="loginMainCard" class="min-h-[80vh] flex items-center justify-center px-4 py-8 sm:py-12 transition-all duration-300 <?= empty($error) ? 'filter blur-md pointer-events-none select-none opacity-40' : '' ?>">
    <div class="bg-white rounded-[28px] p-6 sm:p-9 max-w-md w-full shadow-2xl border border-slate-100">
        
        <!-- Header -->
        <div class="text-center mb-6">
            <div class="w-14 h-14 rounded-2xl bg-slate-50 border border-slate-200/80 flex items-center justify-center mx-auto shadow-xs mb-3.5">
                <svg class="w-7 h-7" viewBox="0 0 24 24">
                    <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                    <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                    <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                    <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                </svg>
            </div>
            <h1 class="text-lg sm:text-xl font-bold text-slate-800 tracking-tight">เข้าสู่ระบบผู้ดูแลระบบ</h1>
        </div>

        <?php if (!empty($error)): ?>
            <div class="p-3.5 mb-5 rounded-2xl bg-mutedred-subtle text-mutedred-text border border-mutedred-border text-xs sm:text-sm flex items-start space-x-2.5">
                <i class="fa-solid fa-circle-exclamation text-mutedred text-sm mt-0.5 shrink-0"></i>
                <div class="leading-relaxed"><?= $error ?></div>
            </div>
        <?php endif; ?>

        <!-- Primary Action: Official Google Sign-In Button -->
        <div class="space-y-4">
            <?php if ($googleConfig['is_configured']): ?>
                <a href="<?= htmlspecialchars($googleAuthUrl, ENT_QUOTES, 'UTF-8') ?>" 
                   class="w-full py-3 px-4 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 hover:border-slate-300 rounded-full font-semibold text-xs sm:text-sm shadow-xs transition flex items-center justify-center space-x-3 group">
                    <svg class="w-5 h-5 shrink-0 transition-transform group-hover:scale-110" viewBox="0 0 24 24">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                    </svg>
                    <span>ดำเนินการต่อด้วยบัญชี Google</span>
                </a>
            <?php else: ?>
                <button type="button" 
                        onclick="promptOAuthSetup()" 
                        class="w-full py-3 px-4 bg-white hover:bg-slate-50 text-slate-700 border border-slate-200 hover:border-slate-300 rounded-full font-semibold text-xs sm:text-sm shadow-xs transition flex items-center justify-center space-x-3 group cursor-pointer">
                    <svg class="w-5 h-5 shrink-0 transition-transform group-hover:scale-110" viewBox="0 0 24 24">
                        <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                        <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                        <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.06H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.94l2.85-2.22.81-.63z"/>
                        <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.06l3.66 2.84c.87-2.6 3.3-4.52 6.16-4.52z"/>
                    </svg>
                    <span>ดำเนินการต่อด้วยบัญชี Google</span>
                </button>
            <?php endif; ?>

            <div class="relative my-4 text-center">
                <div class="absolute inset-0 flex items-center"><div class="w-full border-t border-slate-200"></div></div>
                <span class="relative px-3 bg-white text-[11px] text-slate-400 font-medium">เข้าสู่ระบบด้วยอีเมล Gmail ที่ได้รับสิทธิ์</span>
            </div>

            <!-- Custom Gmail / Email Form -->
            <form method="POST" class="space-y-3">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="gmail_direct_login">
                <div>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <i class="fa-solid fa-envelope text-xs"></i>
                        </span>
                        <input type="email" 
                               id="gmailDirectInput"
                               name="gmail" 
                               required 
                               autocomplete="email"
                               placeholder="ระบุ Gmail ผู้ดูแล เช่น pongsakorn664@gmail.com หรือ admin.dci@gmail.com"
                               class="w-full pl-9 pr-3.5 py-2.5 bg-slate-50 border border-slate-200 rounded-full text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:bg-white focus:ring-2 focus:ring-blue-500/20 focus:border-blue-500 outline-none transition">
                    </div>
                </div>
                <button type="submit" class="w-full py-2.5 px-4 bg-gradient-to-r from-blue-600 to-sky-500 hover:from-blue-700 hover:to-sky-600 text-white rounded-full font-semibold text-xs sm:text-sm shadow-sm transition flex items-center justify-center space-x-2 active:scale-95 cursor-pointer">
                    <i class="fa-solid fa-arrow-right-to-bracket text-xs"></i>
                    <span>เข้าสู่ระบบด้วยอีเมล</span>
                </button>
            </form>
        </div>

        <div class="mt-6 text-center">
            <a href="/index.php" class="text-xs text-charcoal-muted hover:text-charcoal transition">
                ← กลับสู่หน้าหลัก
            </a>
        </div>

    </div>
</div>

<script>
    function promptOAuthSetup() {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: 'info',
                title: 'เข้าสู่ระบบด้วยอีเมล Gmail ได้ทันที',
                html: '<div class="text-left text-xs text-slate-600 space-y-2 mt-2">' +
                      '<p>เนื่องจากระบบยังไม่ได้เชื่อมต่อ Google Client ID จาก Google Cloud Console</p>' +
                      '<p class="p-2.5 bg-blue-50 text-blue-900 rounded-lg border border-blue-200">👉 ท่านสามารถ<strong>กรอกอีเมล Gmail</strong> ในช่องด้านล่าง แล้วกดปุ่ม <strong>"เข้าสู่ระบบด้วยอีเมล"</strong> เพื่อเข้าสู่ระบบได้ทันทีครับ</p>' +
                      '</div>',
                confirmButtonText: 'กรอกอีเมลด้านล่าง',
                confirmButtonColor: '#2563eb'
            }).then(() => {
                const input = document.getElementById('gmailDirectInput');
                if (input) {
                    input.focus();
                    input.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            });
        } else {
            const input = document.getElementById('gmailDirectInput');
            if (input) {
                input.focus();
                input.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    }

<?php if (empty($error)): ?>
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof openAppAuthModal === 'function') {
            openAppAuthModal({
                message: 'โปรด Log in เข้าสู่ระบบก่อนทำรายการต่อ',
                confirmText: 'ตกลง',
                cancelText: 'ยกเลิก',
                onConfirm: function() {
                    closeAppAuthModal();
                    const card = document.getElementById('loginMainCard');
                    if (card) {
                        card.classList.remove('blur-md', 'pointer-events-none', 'select-none', 'opacity-40');
                    }
                },
                onCancel: function() {
                    window.location.href = '/index.php';
                }
            });
        }
    });
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
