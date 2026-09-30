<?php
// includes/header.php
if (!defined('APP_TITLE')) {
    define('APP_TITLE', 'ระบบจองรถต้นเดือน');
}
$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
$isAdmin = isAdminLoggedIn();
?>
<!DOCTYPE html>
<html lang="th" class="h-full bg-white">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
    <meta name="theme-color" content="#ffffff">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <title><?= APP_TITLE ?> - อาศรมบรรพชิต DCI</title>
    <!-- Prompt & Inter Fonts for refined typography -->
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
                        sans: ['Prompt', 'Inter', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'sans-serif'],
                    },
                    colors: {
                        canvas: '#ffffff',        // Seamless Clean White like CU NEX
                        surface: {
                            DEFAULT: '#ffffff',   // Clean White Surface
                            muted: '#f8fafc',    // Slate 50
                            elevated: '#ffffff',
                        },
                        charcoal: {               // Rich Slate Scale
                            DEFAULT: '#0f172a',   // Slate 900
                            secondary: '#334155', // Slate 700
                            muted: '#64748b',     // Slate 500
                            subtle: '#94a3b8',    // Slate 400
                            border: '#e2e8f0',    // Slate 200
                            divider: '#f1f5f9',   // Slate 100
                        },
                        accent: {                 // Vibrant Royal Blue
                            DEFAULT: '#2563eb',   // Royal Blue
                            hover: '#1d4ed8',     // Deep Blue
                            active: '#1e40af',
                            subtle: '#eff6ff',    // Ice Blue Tint
                            border: '#bfdbfe',
                        },
                        mint: {                   // Fresh Mint & Lime (from reference UI)
                            DEFAULT: '#10b981',
                            hover: '#059669',
                            subtle: '#ecfdf5',
                            border: '#a7f3d0',
                            text: '#065f46',
                            lime: '#84cc16',
                        },
                        sky: {                    // Sky Cyan
                            DEFAULT: '#0ea5e9',
                            subtle: '#f0f9ff',
                            border: '#bae6fd',
                        },
                        sage: {                   // Kept for backward compatibility
                            DEFAULT: '#10b981',
                            subtle: '#ecfdf5',
                            border: '#a7f3d0',
                            text: '#065f46',
                        },
                        mutedred: {               // Kept for backward compatibility
                            DEFAULT: '#ef4444',
                            subtle: '#fff1f2',
                            border: '#fecdd3',
                            text: '#be123c',
                        }
                    },
                    boxShadow: {
                        'subtle': '0 1px 3px 0 rgba(15, 23, 42, 0.04), 0 1px 2px -1px rgba(15, 23, 42, 0.02)',
                        'card': '0 4px 20px -2px rgba(15, 23, 42, 0.04), 0 2px 6px -1px rgba(15, 23, 42, 0.02)',
                        'elevated': '0 12px 30px -4px rgba(37, 99, 235, 0.08), 0 4px 12px -2px rgba(15, 23, 42, 0.04)',
                        'blue-glow': '0 8px 24px -4px rgba(37, 99, 235, 0.35)',
                        'mint-glow': '0 8px 24px -4px rgba(16, 185, 129, 0.35)',
                    },
                    borderRadius: {
                        'xs': '6px',
                        'sm': '8px',
                        'DEFAULT': '12px',
                        'md': '14px',
                        'lg': '16px',
                        'xl': '20px',
                        '2xl': '24px',
                        'full': '9999px',
                    }
                }
            }
        }
    </script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="min-h-full flex flex-col font-sans text-charcoal antialiased bg-white selection:bg-accent selection:text-white pb-28 md:pb-8 safe-pb-mobile">

    <!-- Seamless Native App Top Bar (CU NEX Style - No Harsh Borders) -->
    <header class="app-topbar-glass sticky top-0 z-40">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                
                <!-- Brand Title (CU NEX Clean Typography) -->
                <div class="flex items-center space-x-2.5">
                    <a href="/index.php" class="flex items-center space-x-2.5 group">
                        <div class="w-9 h-9 rounded-2xl bg-gradient-to-tr from-blue-600 via-sky-500 to-emerald-400 text-white flex items-center justify-center shadow-xs transition-transform group-hover:scale-105 shrink-0">
                            <i class="fa-solid fa-van-shuttle text-sm"></i>
                        </div>
                        <div class="leading-tight">
                            <span class="font-bold text-sm sm:text-base tracking-tight text-slate-800 block group-hover:text-blue-600 transition-colors">ธุรการอาศรมบรรพชิต DCI</span>
                            <span class="text-[10.5px] sm:text-xs text-slate-400 font-medium block">ระบบจองรถต้นเดือน</span>
                        </div>
                    </a>
                </div>



                <!-- Desktop Navigation Links -->
                <nav class="hidden md:flex items-center space-x-2">
                    <a href="/index.php" class="px-4 py-2 rounded-full text-xs transition flex items-center space-x-1.5 <?= $currentScript === 'index.php' ? 'bg-accent text-white shadow-md shadow-blue-500/20 font-semibold' : 'text-charcoal-secondary hover:text-charcoal hover:bg-surface-muted font-medium' ?>">
                        <i class="fa-solid fa-calendar-plus text-xs"></i>
                        <span>ลงชื่อจองรถ</span>
                    </a>

                    <a href="/check.php" class="px-4 py-2 rounded-full text-xs transition flex items-center space-x-1.5 <?= $currentScript === 'check.php' ? 'bg-accent text-white shadow-md shadow-blue-500/20 font-semibold' : 'text-charcoal-secondary hover:text-charcoal hover:bg-surface-muted font-medium' ?>">
                        <i class="fa-solid fa-clipboard-list text-xs"></i>
                        <span>ตรวจสอบรายชื่อ</span>
                    </a>

                    <?php if ($isAdmin): 
                        $curAdm = getCurrentAdmin();
                    ?>
                        <a href="/admin/index.php" class="px-4 py-2 rounded-full text-xs font-medium transition flex items-center space-x-1.5 <?= str_contains($_SERVER['PHP_SELF'] ?? '', '/admin/') ? 'bg-accent text-white shadow-md shadow-blue-500/20 font-semibold' : 'text-charcoal-secondary hover:text-charcoal hover:bg-surface-muted' ?>">
                            <i class="fa-solid fa-sliders text-xs"></i>
                            <span>จัดการระบบ</span>
                        </a>

                        <!-- User Profile Dropdown Pill -->
                        <div class="relative ml-2" id="userMenuDropdown">
                            <button type="button" 
                                    onclick="document.getElementById('userMenuModal').classList.toggle('hidden')" 
                                    class="flex items-center space-x-2.5 p-1.5 pr-3 rounded-full bg-surface border border-charcoal-border hover:border-accent shadow-subtle transition">
                                <img src="<?= htmlspecialchars($curAdm['avatar'], ENT_QUOTES, 'UTF-8') ?>" 
                                     alt="Avatar" 
                                     class="w-7 h-7 rounded-full object-cover ring-1 ring-charcoal-border">
                                <div class="text-left hidden lg:block leading-tight">
                                    <span class="text-xs font-semibold text-charcoal block truncate max-w-[130px]"><?= htmlspecialchars($curAdm['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="text-[10px] text-charcoal-muted block truncate max-w-[130px]"><?= htmlspecialchars($curAdm['email'], ENT_QUOTES, 'UTF-8') ?></span>
                                </div>
                                <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full border <?= $curAdm['role_badge'] ?>">
                                    <?= htmlspecialchars($curAdm['role'], ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <i class="fa-solid fa-chevron-down text-[10px] text-charcoal-muted ml-0.5"></i>
                            </button>

                            <!-- Profile Dropdown Content -->
                            <div id="userMenuModal" class="hidden absolute right-0 mt-2 w-64 glass-dropdown p-2.5 z-50 text-xs shadow-elevated">
                                <div class="p-2.5 border-b border-charcoal-border mb-1.5">
                                    <div class="font-bold text-charcoal text-sm"><?= htmlspecialchars($curAdm['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="text-charcoal-muted text-[11px] truncate flex items-center space-x-1 mt-0.5">
                                        <i class="fa-brands fa-google text-[10px] text-accent"></i>
                                        <span><?= htmlspecialchars($curAdm['email'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </div>
                                    <div class="mt-2 inline-block px-2 py-0.5 rounded text-[10px] font-semibold border <?= $curAdm['role_badge'] ?>">
                                        <?= htmlspecialchars($curAdm['role_label'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>

                                <?php if (hasAdminRole('admin')): ?>
                                    <a href="/admin/index.php" class="flex items-center space-x-2 px-2.5 py-2 rounded-md hover:bg-surface-muted text-charcoal transition">
                                        <i class="fa-solid fa-gear text-charcoal-muted w-4"></i>
                                        <span>แผงควบคุมแอดมิน (Admin Panel)</span>
                                    </a>
                                    <a href="/admin/index.php?open_mgmt=1" class="flex items-center space-x-2 px-2.5 py-2 rounded-md hover:bg-surface-muted text-charcoal transition">
                                        <i class="fa-solid fa-users-gear text-charcoal-muted w-4"></i>
                                        <span>จัดการสิทธิ์ผู้ดูแลระบบ</span>
                                    </a>
                                <?php endif; ?>
                                <hr class="border-charcoal-border my-1">
                                <a href="/admin/logout.php" onclick="showLogoutModal('/admin/logout.php'); return false;" class="flex items-center space-x-2 px-2.5 py-2 rounded-md hover:bg-mutedred-subtle text-mutedred transition">
                                    <i class="fa-solid fa-arrow-right-from-bracket w-4"></i>
                                    <span>ออกจากระบบ (Logout)</span>
                                </a>
                            </div>
                        </div>

                    <?php else: ?>
                        <!-- เพิ่มเติม Button (Same as mobile bottom bar) -->
                        <a href="/admin/login.php" class="px-4 py-2 rounded-full text-xs transition flex items-center space-x-1.5 <?= $currentScript === 'login.php' ? 'bg-accent text-white shadow-md shadow-blue-500/20 font-semibold' : 'text-charcoal-secondary hover:text-charcoal hover:bg-surface-muted font-medium' ?>" title="เพิ่มเติม">
                            <i class="fa-solid fa-ellipsis text-xs"></i>
                            <span>เพิ่มเติม</span>
                        </a>
                    <?php endif; ?>
                </nav>



            </div>
        </div>
    </header>

    <main class="flex-grow">
