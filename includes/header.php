<?php
// includes/header.php
if (!defined('APP_TITLE')) {
    define('APP_TITLE', 'ระบบจองรถต้นเดือน');
}
$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
$isAdmin = isAdminLoggedIn();
?>
<!DOCTYPE html>
<html lang="th" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
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
                    },
                    colors: {
                        brand: {
                            50: '#f0fdf4',
                            100: '#dcfce7',
                            600: '#16a34a',
                            700: '#15803d',
                            800: '#166534',
                            900: '#14532d',
                        }
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="min-h-full flex flex-col font-sans text-slate-800 antialiased bg-slate-50 selection:bg-slate-800 selection:text-white pb-24 md:pb-8">

    <!-- Top Sticky Header (Lighter Modern Slate & Gold Accent) -->
    <header class="bg-slate-800 text-white border-b border-slate-700/80 sticky top-0 z-40 shadow-xs">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                
                <!-- Brand Title -->
                <div class="flex items-center space-x-3">
                    <a href="/index.php" class="flex items-center space-x-3 text-white hover:text-slate-100 transition">
                        <div class="w-10 h-10 rounded-xl bg-white/10 border border-white/15 flex items-center justify-center text-amber-300 shadow-inner">
                            <i class="fa-solid fa-van-shuttle text-lg"></i>
                        </div>
                        <div>
                            <span class="font-bold text-base sm:text-lg tracking-normal block leading-tight">ระบบจองรถต้นเดือน</span>
                            <span class="text-xs text-slate-300 block font-normal">ธุรการอาศรมบรรพชิต DCI</span>
                        </div>
                    </a>
                </div>

                <!-- Desktop Navigation Links -->
                <nav class="hidden md:flex items-center space-x-1.5">
                    <a href="/index.php" class="px-3.5 py-2 rounded-xl text-sm font-medium transition <?= $currentScript === 'index.php' ? 'bg-white/20 text-white shadow-inner' : 'text-slate-300 hover:text-white hover:bg-white/10' ?>">
                        <i class="fa-solid fa-van-shuttle mr-1.5 text-xs text-amber-300"></i>
                        ลงชื่อจองรถ
                    </a>

                    <a href="/check.php" class="px-3.5 py-2 rounded-xl text-sm font-medium transition <?= $currentScript === 'check.php' ? 'bg-white/20 text-white shadow-inner' : 'text-slate-300 hover:text-white hover:bg-white/10' ?>">
                        <i class="fa-solid fa-clipboard-list mr-1.5 text-xs text-emerald-300"></i>
                        ตรวจสอบรายชื่อ
                    </a>

                    <?php if ($isAdmin): ?>
                        <a href="/details.php" class="px-3.5 py-2 rounded-xl text-sm font-medium transition <?= $currentScript === 'details.php' ? 'bg-white/20 text-white shadow-inner' : 'text-slate-300 hover:text-white hover:bg-white/10' ?>">
                            <i class="fa-solid fa-table-list mr-1.5 text-xs text-sky-300"></i>
                            ข้อมูลโดยละเอียด
                        </a>
                        <a href="/admin/index.php" class="px-4 py-2 rounded-xl text-sm font-semibold transition bg-amber-500 hover:bg-amber-400 text-slate-950 ml-2 shadow-xs flex items-center space-x-1.5">
                            <i class="fa-solid fa-gear text-xs"></i>
                            <span>จัดการระบบ Admin</span>
                        </a>
                        <a href="/admin/logout.php" class="px-3 py-2 rounded-xl text-sm font-normal text-slate-400 hover:text-rose-300 hover:bg-white/5 transition" title="ออกจากระบบ">
                            <i class="fa-solid fa-arrow-right-from-bracket"></i>
                        </a>
                    <?php else: ?>
                        <a href="/admin/login.php" class="px-3.5 py-2 rounded-xl text-sm font-medium text-slate-300 hover:text-white hover:bg-white/10 transition ml-2 flex items-center space-x-1.5">
                            <i class="fa-solid fa-lock text-xs text-amber-300"></i>
                            <span>เข้าสู่ระบบ Admin</span>
                        </a>
                    <?php endif; ?>
                </nav>

                <!-- Mobile Menu Button (Toggle Dropdown) -->
                <div class="flex md:hidden items-center space-x-2">
                    <?php if ($isAdmin): ?>
                        <span class="text-[11px] font-semibold bg-amber-400/20 text-amber-300 border border-amber-400/30 px-2 py-0.5 rounded-md">Admin</span>
                    <?php endif; ?>
                    <button type="button" onclick="document.getElementById('mobileMenu').classList.toggle('hidden')" class="p-2.5 rounded-xl text-slate-300 hover:text-white hover:bg-white/10 transition">
                        <i class="fa-solid fa-bars text-xl"></i>
                    </button>
                </div>

            </div>
        </div>

        <!-- Mobile Dropdown Navigation Menu -->
        <div id="mobileMenu" class="hidden md:hidden border-t border-slate-700 bg-slate-850 px-4 py-3 space-y-1.5 bg-slate-800 shadow-xl">
            <a href="/index.php" class="flex items-center space-x-2.5 px-3.5 py-2.5 rounded-xl text-sm font-medium text-slate-100 hover:bg-white/10 <?= $currentScript === 'index.php' ? 'bg-white/15 text-white' : '' ?>">
                <i class="fa-solid fa-van-shuttle text-amber-300 w-5"></i>
                <span>ลงชื่อจองรถ</span>
            </a>
            <a href="/check.php" class="flex items-center space-x-2.5 px-3.5 py-2.5 rounded-xl text-sm font-medium text-slate-100 hover:bg-white/10 <?= $currentScript === 'check.php' ? 'bg-white/15 text-white' : '' ?>">
                <i class="fa-solid fa-clipboard-list text-emerald-300 w-5"></i>
                <span>ตรวจสอบรายชื่อ</span>
            </a>
            <?php if ($isAdmin): ?>
                <a href="/details.php" class="flex items-center space-x-2.5 px-3.5 py-2.5 rounded-xl text-sm font-medium text-amber-300 hover:bg-white/10 <?= $currentScript === 'details.php' ? 'bg-white/15 text-amber-200' : '' ?>">
                    <i class="fa-solid fa-table-list text-sky-300 w-5"></i>
                    <span>ข้อมูลโดยละเอียด (Admin)</span>
                </a>
                <a href="/admin/index.php" class="flex items-center space-x-2.5 px-3.5 py-2.5 rounded-xl text-sm font-semibold text-slate-950 bg-amber-400 mt-2 shadow-xs">
                    <i class="fa-solid fa-gear w-5"></i>
                    <span>จัดการระบบ Admin</span>
                </a>
                <a href="/admin/logout.php" class="flex items-center space-x-2.5 px-3.5 py-2 rounded-xl text-sm text-rose-300 hover:bg-white/5">
                    <i class="fa-solid fa-arrow-right-from-bracket w-5"></i>
                    <span>ออกจากระบบ</span>
                </a>
            <?php else: ?>
                <a href="/admin/login.php" class="flex items-center space-x-2.5 px-3.5 py-2.5 rounded-xl text-sm text-slate-300 hover:bg-white/5">
                    <i class="fa-solid fa-lock text-amber-300 w-5"></i>
                    <span>เข้าสู่ระบบ Admin</span>
                </a>
            <?php endif; ?>
        </div>
    </header>

    <main class="flex-grow">
