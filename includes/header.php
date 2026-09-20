<?php
// includes/header.php
if (!defined('APP_TITLE')) {
    define('APP_TITLE', 'ระบบจองรถต้นเดือน');
}
$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
$isAdmin = isAdminLoggedIn();

$localServerIp = gethostbyname(gethostname());
$serverPort = $_SERVER['SERVER_PORT'] ?? '8000';
$mobileUrl = "http://{$localServerIp}:{$serverPort}";
?>
<!DOCTYPE html>
<html lang="th" class="h-full bg-slate-100/60">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= APP_TITLE ?> - อาศรมบรรพชิต DCI</title>
    <!-- Prompt & Inter Fonts for refined International Typography -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Prompt', 'Inter', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="min-h-full flex flex-col font-sans text-slate-800 antialiased bg-slate-50 selection:bg-slate-800 selection:text-white">

    <!-- Top Clean Corporate Header -->
    <header class="bg-slate-900 text-white border-b border-slate-800 sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                
                <!-- Brand Title -->
                <div class="flex items-center space-x-3">
                    <a href="/index.php" class="flex items-center space-x-3 text-white hover:text-slate-200 transition">
                        <div class="w-9 h-9 rounded-lg bg-white/10 border border-white/15 flex items-center justify-center text-slate-200">
                            <i class="fa-solid fa-van-shuttle text-base"></i>
                        </div>
                        <div>
                            <span class="font-bold text-base tracking-normal block leading-tight">ระบบจองรถต้นเดือน</span>
                            <span class="text-[11px] text-slate-400 block font-normal">ธุรการอาศรมบรรพชิต DCI</span>
                        </div>
                    </a>
                </div>

                <!-- Navigation Links -->
                <nav class="hidden md:flex items-center space-x-1">
                    <a href="/index.php" class="px-3 py-1.5 rounded-lg text-xs font-medium transition <?= $currentScript === 'index.php' ? 'bg-white/15 text-white' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
                        ลงชื่อจองรถ
                    </a>

                    <a href="/check.php" class="px-3 py-1.5 rounded-lg text-xs font-medium transition <?= $currentScript === 'check.php' ? 'bg-white/15 text-white' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
                        ตรวจสอบรายชื่อ
                    </a>

                    <button type="button" onclick="openQrModal()" class="px-2.5 py-1.5 rounded-lg text-xs font-semibold text-emerald-400 hover:text-emerald-300 hover:bg-white/10 transition flex items-center space-x-1.5 border border-emerald-500/30 ml-1" title="สแกนเพื่อเปิดบนโทรศัพท์มือถือ">
                        <i class="fa-solid fa-qrcode"></i>
                        <span>เปิดในมือถือ</span>
                    </button>

                    <!-- ข้อ 1: ข้อมูลผู้ลงชื่อโดยละเอียด ให้เห็นเฉพาะ admin -->
                    <?php if ($isAdmin): ?>
                        <a href="/details.php" class="px-3 py-1.5 rounded-lg text-xs font-medium transition <?= $currentScript === 'details.php' ? 'bg-white/15 text-white' : 'text-slate-300 hover:text-white hover:bg-white/5' ?>">
                            <i class="fa-solid fa-lock text-[10px] mr-1 text-amber-400"></i>
                            ข้อมูลโดยละเอียด
                        </a>
                        <a href="/admin/index.php" class="px-3 py-1.5 rounded-lg text-xs font-medium transition bg-amber-500 hover:bg-amber-400 text-slate-950 font-semibold ml-2">
                            จัดการระบบ Admin
                        </a>
                        <a href="/admin/logout.php" class="px-2.5 py-1.5 rounded-lg text-xs font-normal text-slate-400 hover:text-rose-300 transition" title="ออกจากระบบ">
                            <i class="fa-solid fa-arrow-right-from-bracket"></i>
                        </a>
                    <?php else: ?>
                        <a href="/admin/login.php" class="px-2.5 py-1.5 rounded-lg text-xs font-normal text-slate-400 hover:text-slate-200 transition ml-2">
                            <i class="fa-solid fa-lock text-[10px] mr-1"></i>
                            Admin
                        </a>
                    <?php endif; ?>
                </nav>

                <!-- Mobile Menu Button -->
                <div class="flex md:hidden items-center space-x-1">
                    <button type="button" onclick="openQrModal()" class="p-2 rounded-lg text-emerald-400 hover:text-emerald-300 hover:bg-white/10 text-xs flex items-center space-x-1 border border-emerald-500/30">
                        <i class="fa-solid fa-qrcode"></i>
                        <span>QR</span>
                    </button>
                    <button type="button" onclick="document.getElementById('mobileMenu').classList.toggle('hidden')" class="p-2 rounded-lg text-slate-400 hover:text-white hover:bg-white/10">
                        <i class="fa-solid fa-bars text-lg"></i>
                    </button>
                </div>

            </div>
        </div>

        <!-- Mobile Navigation Menu -->
        <div id="mobileMenu" class="hidden md:hidden border-t border-slate-800 bg-slate-900 px-4 py-3 space-y-1">
            <button type="button" onclick="openQrModal()" class="w-full text-left px-3 py-2 rounded-lg text-xs font-semibold text-emerald-400 hover:bg-white/10 flex items-center space-x-2">
                <i class="fa-solid fa-qrcode"></i>
                <span>สแกน QR Code เปิดในมือถือ</span>
            </button>
            <a href="/index.php" class="block px-3 py-2 rounded-lg text-xs font-medium text-slate-200 hover:bg-white/10">
                ลงชื่อจองรถ
            </a>
            <a href="/check.php" class="block px-3 py-2 rounded-lg text-xs font-medium text-slate-200 hover:bg-white/10">
                ตรวจสอบรายชื่อ
            </a>
            <?php if ($isAdmin): ?>
                <a href="/details.php" class="block px-3 py-2 rounded-lg text-xs font-medium text-amber-300 hover:bg-white/10">
                    <i class="fa-solid fa-lock mr-1.5 text-xs"></i> ข้อมูลโดยละเอียด (Admin)
                </a>
                <a href="/admin/index.php" class="block px-3 py-2 rounded-lg text-xs font-semibold text-white bg-amber-600/60 mt-1">
                    จัดการระบบ Admin
                </a>
                <a href="/admin/logout.php" class="block px-3 py-2 rounded-lg text-xs text-rose-400 hover:bg-white/5">
                    ออกจากระบบ
                </a>
            <?php else: ?>
                <a href="/admin/login.php" class="block px-3 py-2 rounded-lg text-xs text-slate-400 hover:bg-white/5">
                    <i class="fa-solid fa-lock mr-1.5 text-xs"></i> เข้าสู่ระบบ Admin
                </a>
            <?php endif; ?>
        </div>
    </header>

    <!-- Modal: QR Code ใช้งานบนมือถือ -->
    <div id="qrModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/70 backdrop-blur-xs hidden p-4">
        <div class="bg-white rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-slate-200 text-center relative">
            <button type="button" onclick="closeQrModal()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 p-1">
                <i class="fa-solid fa-xmark text-lg"></i>
            </button>
            <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto mb-2 text-lg border border-emerald-200">
                <i class="fa-solid fa-mobile-screen-button"></i>
            </div>
            <h3 class="text-base font-bold text-slate-900">เปิดใช้งานผ่านโทรศัพท์มือถือ</h3>
            <p class="text-xs text-slate-500 mt-1 mb-4">
                เพียงเชื่อมต่อ Wi-Fi เดียวกัน แล้วใช้กล้องมือถือสแกนเพื่อเปิดจองรถได้ทันที (ฟรี)
            </p>

            <div class="bg-slate-50 p-4 rounded-xl border border-slate-200 inline-block mb-4 shadow-inner">
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?= urlencode($mobileUrl) ?>" 
                     alt="QR Code สำหรับมือถือ" 
                     class="w-48 h-48 mx-auto rounded-lg"
                     loading="lazy">
            </div>

            <div class="bg-slate-100 p-2.5 rounded-lg text-xs text-slate-700 font-mono flex items-center justify-between mb-4 border border-slate-200">
                <span id="qrMobileLink" class="truncate"><?= clean($mobileUrl) ?></span>
                <button type="button" onclick="copyMobileUrl()" class="ml-2 text-indigo-600 hover:text-indigo-800 font-semibold shrink-0 text-xs">
                    คัดลอก
                </button>
            </div>

            <div class="text-[11px] text-slate-500 leading-relaxed text-left bg-amber-50/70 border border-amber-200 p-2.5 rounded-lg text-amber-900">
                <i class="fa-solid fa-circle-info mr-1 text-amber-600"></i>
                <strong>คำแนะนำ:</strong> เชื่อมต่อมือถือเข้า Wi-Fi เดียวกัน แล้วสแกนด้วยกล้องมือถือ หรือคัดลอกลิงก์ส่งในกลุ่ม LINE ได้ทันทีครับ
            </div>
        </div>
    </div>

    <script>
        function openQrModal() {
            document.getElementById('qrModal').classList.remove('hidden');
        }
        function closeQrModal() {
            document.getElementById('qrModal').classList.add('hidden');
        }
        function copyMobileUrl() {
            const url = '<?= $mobileUrl ?>';
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(() => {
                    alert('คัดลอกลิงก์เรียบร้อยแล้ว:\n' + url + '\n\nสามารถนำไปส่งในกลุ่ม LINE ได้เลยครับ');
                }).catch(() => {
                    prompt('คัดลอกลิงก์ด้านล่างนี้ได้เลยครับ:', url);
                });
            } else {
                prompt('คัดลอกลิงก์ด้านล่างนี้ได้เลยครับ:', url);
            }
        }
    </script>

    <main class="flex-grow">
