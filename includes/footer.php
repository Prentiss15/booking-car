<?php
// includes/footer.php
$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
$isAdmin = isAdminLoggedIn();
?>
    </main>

    <!-- Footer (ธุรการอาศรมบรรพชิต DCI) -->
    <footer class="bg-surface border-t border-charcoal-border mt-16 py-8 text-center text-xs text-charcoal-muted hidden md:block">
        <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="flex items-center space-x-2">
                <span class="w-2 h-2 rounded-full bg-accent-border"></span>
                <span class="font-medium text-charcoal">ระบบจองรถต้นเดือน</span>
            </div>
            <div class="flex items-center space-x-1.5 text-charcoal-secondary">
                <span>กำกับดูแลโดย:</span>
                <strong class="text-charcoal font-semibold">ธุรการอาศรมบรรพชิต DCI</strong>
            </div>
        </div>
    </footer>

    <!-- Mobile Bottom Navigation Bar (Floating Pill - Deep Royal Navy) -->
    <?php 
    $curAdmFooter = $isAdmin ? getCurrentAdmin() : null; 
    ?>
    <div class="md:hidden fixed safe-bottom-dock left-2 right-2 z-40 pointer-events-none">
        <nav class="app-bottom-dock rounded-full px-1 py-1.5 flex items-center justify-around pointer-events-auto">
            <a href="/index.php" class="app-tap transition-all <?= $currentScript === 'index.php' ? 'bg-white text-blue-700 font-bold shadow-sm rounded-full py-1.5 px-3 flex items-center space-x-1.5' : 'text-white/80 hover:text-white flex flex-col items-center py-1 px-2' ?>">
                <i class="fa-solid fa-calendar-check <?= $currentScript === 'index.php' ? 'text-[15px]' : 'text-[16px] mb-0.5' ?>"></i>
                <span class="<?= $currentScript === 'index.php' ? 'text-[11px]' : 'text-[10px]' ?> tracking-tight">ลงชื่อ</span>
            </a>

            <a href="/check.php" class="app-tap transition-all <?= $currentScript === 'check.php' ? 'bg-white text-blue-700 font-bold shadow-sm rounded-full py-1.5 px-3 flex items-center space-x-1.5' : 'text-white/80 hover:text-white flex flex-col items-center py-1 px-2' ?>">
                <i class="fa-solid fa-clipboard-list <?= $currentScript === 'check.php' ? 'text-[15px]' : 'text-[16px] mb-0.5' ?>"></i>
                <span class="<?= $currentScript === 'check.php' ? 'text-[11px]' : 'text-[10px]' ?> tracking-tight">รายชื่อ</span>
            </a>

            <?php if ($isAdmin && $curAdmFooter): ?>
                <a href="/admin/index.php" class="app-tap transition-all <?= str_contains($currentScript, 'admin') && $currentScript !== 'login.php' ? 'bg-white text-blue-700 font-bold shadow-sm rounded-full py-1.5 px-2.5 flex items-center space-x-1.5' : 'text-white/80 hover:text-white flex flex-col items-center py-1 px-2' ?>">
                    <i class="fa-solid fa-sliders <?= str_contains($currentScript, 'admin') && $currentScript !== 'login.php' ? 'text-[15px]' : 'text-[16px] mb-0.5' ?>"></i>
                    <span class="<?= str_contains($currentScript, 'admin') && $currentScript !== 'login.php' ? 'text-[11px]' : 'text-[10px]' ?> tracking-tight">จัดการ</span>
                </a>

                <!-- User Profile Tab at Bottom (Shows User Avatar like App) -->
                <button type="button" onclick="openMobileUserSheet()" class="app-tap flex flex-col items-center py-0.5 px-2 rounded-full transition-all text-white/90 hover:text-white active:scale-95" title="ข้อมูลบัญชีผู้ใช้">
                    <img src="<?= htmlspecialchars($curAdmFooter['avatar'] ?? '', ENT_QUOTES, 'UTF-8') ?>" 
                         alt="User" 
                         class="w-5 h-5 rounded-full object-cover ring-1 ring-white/80 shadow-xs mb-0.5">
                    <span class="text-[10px] tracking-tight font-medium text-white/90">ผู้ใช้</span>
                </button>

                <!-- Logout Tab at Bottom -->
                <a href="/admin/logout.php" onclick="showLogoutModal('/admin/logout.php'); return false;" class="app-tap flex flex-col items-center py-1 px-1.5 rounded-full transition-all text-white/80 hover:text-red-200 hover:bg-white/10 active:scale-95" title="ออกจากระบบ">
                    <i class="fa-solid fa-arrow-right-from-bracket text-[14px] mb-0.5"></i>
                    <span class="text-[9px] tracking-tight font-semibold">ออกระบบ</span>
                </a>
            <?php else: ?>
                <!-- เพิ่มเติม Tab at Bottom (3 dots) -->
                <a href="/admin/login.php" class="app-tap transition-all <?= $currentScript === 'login.php' ? 'bg-white text-blue-700 font-bold shadow-sm rounded-full py-1.5 px-3 flex items-center space-x-1.5' : 'text-white/80 hover:text-white flex flex-col items-center py-1 px-3' ?>" title="เพิ่มเติม">
                    <i class="fa-solid fa-ellipsis <?= $currentScript === 'login.php' ? 'text-[16px]' : 'text-[18px] mb-0.5' ?>"></i>
                    <span class="<?= $currentScript === 'login.php' ? 'text-[11px]' : 'text-[10px]' ?> tracking-tight">เพิ่มเติม</span>
                </a>
            <?php endif; ?>
        </nav>
    </div>

    <!-- Native App Style Confirmation Modal (เข้าระบบ / ออกจากระบบ) -->
    <div id="appAuthModal" class="hidden fixed inset-0 z-[120] flex items-center justify-center bg-black/50 backdrop-blur-sm p-4 transition-all duration-200" onclick="closeAppAuthModal(event)">
        <div class="bg-white w-[86%] max-w-[320px] rounded-[26px] px-6 py-6 sm:py-7 shadow-2xl text-center border border-slate-100/90 alert-modal-box" onclick="event.stopPropagation()">
            <div id="appAuthModalText" class="text-slate-800 text-[15px] font-semibold leading-relaxed mb-6 pt-1">
                โปรด Log in เข้าสู่ระบบก่อนทำรายการต่อ
            </div>
            <div class="flex items-center gap-3">
                <button type="button" id="appAuthModalCancelBtn" onclick="closeAppAuthModal()" class="flex-1 py-2.5 px-4 rounded-full bg-[#E5E7EB] hover:bg-slate-300 text-slate-700 font-semibold text-sm transition-all duration-150 active:scale-95 text-center">
                    ยกเลิก
                </button>
                <a id="appAuthModalConfirmBtn" href="/admin/login.php" class="flex-1 py-2.5 px-4 rounded-full bg-gradient-to-r from-blue-600 to-sky-500 hover:from-blue-700 hover:to-sky-600 text-white font-semibold text-sm transition-all duration-150 active:scale-95 shadow-sm text-center block">
                    ตกลง
                </a>
            </div>
        </div>
    </div>

    <?php if ($isAdmin && $curAdmFooter): ?>
        <!-- Mobile Bottom Sheet User Profile Modal (Native App Style) -->
        <div id="mobileUserSheet" class="hidden fixed inset-0 z-50 flex items-end justify-center bg-black/60 backdrop-blur-xs transition-opacity duration-200" onclick="closeMobileUserSheet(event)">
            <div class="bg-white w-full max-w-md rounded-t-3xl p-5 shadow-2xl pb-8 animate-sheet-up border-t border-slate-200" onclick="event.stopPropagation()">
                <!-- Handle Bar -->
                <div class="w-12 h-1 bg-slate-300 rounded-full mx-auto mb-4"></div>

                <!-- User Header Card -->
                <div class="flex items-center space-x-3.5 mb-5 p-3 rounded-2xl bg-slate-50 border border-slate-100">
                    <img src="<?= htmlspecialchars($curAdmFooter['avatar'], ENT_QUOTES, 'UTF-8') ?>" 
                         alt="Avatar" 
                         class="w-12 h-12 rounded-full object-cover ring-2 ring-blue-500/20 shadow-xs">
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-bold text-slate-800 truncate"><?= htmlspecialchars($curAdmFooter['name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="text-xs text-slate-500 truncate"><?= htmlspecialchars($curAdmFooter['email'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="mt-1 inline-block text-[10px] font-semibold px-2 py-0.5 rounded-full border <?= $curAdmFooter['role_badge'] ?>">
                            <?= htmlspecialchars($curAdmFooter['role_label'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </div>
                </div>

                <!-- Action Button -->
                <a href="/admin/index.php?open_mgmt=1" class="w-full py-3 px-4 rounded-2xl bg-blue-600 hover:bg-blue-700 active:scale-95 text-white font-bold text-sm transition flex items-center justify-center space-x-2.5 shadow-md">
                    <i class="fa-solid fa-user-shield text-base"></i>
                    <span>จัดการสิทธิ์เข้าระบบ</span>
                </a>
            </div>
        </div>

        <script>
            function openMobileUserSheet() {
                const sheet = document.getElementById('mobileUserSheet');
                if (sheet) {
                    sheet.classList.remove('hidden');
                    document.body.style.overflow = 'hidden';
                }
            }
            function closeMobileUserSheet(e) {
                if (e && e.target && e.target !== e.currentTarget && e.currentTarget.id !== 'mobileUserSheet') return;
                const sheet = document.getElementById('mobileUserSheet');
                if (sheet) {
                    sheet.classList.add('hidden');
                    document.body.style.overflow = '';
                }
            }
        </script>
    <?php endif; ?>

    <!-- SweetAlert2 Modern Animated Alert Library (Local + CDN Fallback) -->
    <script src="/assets/js/sweetalert2.all.min.js"></script>
    <script>
        if (typeof Swal === 'undefined') {
            document.write('<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"><\/script>');
        }
    </script>

    <!-- Global Modern Alert & Toast Notification System (International Standard) -->
    <script>
        // Global Modern Toast System (Floating Top-Right with Animated Progress Bar)
        const AppToast = typeof Swal !== 'undefined' ? Swal.mixin({
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3200,
            timerProgressBar: true,
            didOpen: (toast) => {
                toast.onmouseenter = Swal.stopTimer;
                toast.onmouseleave = Swal.resumeTimer;
            }
        }) : null;

        function showToast(message, type = 'success') {
            if (typeof Swal !== 'undefined' && AppToast) {
                AppToast.fire({
                    icon: type,
                    title: message
                });
            } else {
                console.log(`[Toast ${type}]: ${message}`);
            }
        }

        // Global International Standard Animated Modal Alert Functions
        function appAlert(title, text = '', icon = 'info') {
            if (typeof Swal === 'undefined') {
                return window.nativeAlert ? window.nativeAlert(`${title}\n${text}`) : null;
            }
            return Swal.fire({
                title: title,
                text: text,
                icon: icon,
                confirmButtonText: 'ตกลง',
                buttonsStyling: true
            });
        }

        function appSuccess(title, text = '') {
            return appAlert(title, text, 'success');
        }

        function appError(title, text = '') {
            return appAlert(title, text, 'error');
        }

        function appWarning(title, text = '') {
            return appAlert(title, text, 'warning');
        }

        function appConfirm(title, text = '', confirmText = 'ยืนยัน', cancelText = 'ยกเลิก', icon = 'warning') {
            if (typeof Swal === 'undefined') {
                return Promise.resolve(confirm(`${title}\n${text}`));
            }
            return Swal.fire({
                title: title,
                text: text,
                icon: icon,
                showCancelButton: true,
                confirmButtonText: confirmText,
                cancelButtonText: cancelText,
                reverseButtons: true
            }).then(result => result.isConfirmed);
        }

        // Seamless Global Alert Override:
        // Automatically turns all standard alert('...') into animated international modals!
        if (typeof Swal !== 'undefined') {
            window.nativeAlert = window.alert;
            window.alert = function(message) {
                const msgStr = String(message || '');
                let icon = 'info';
                let title = 'แจ้งเตือน';

                if (msgStr.includes('ผิดพลาด') || msgStr.includes('ไม่ถูกต้อง') || msgStr.includes('ขออภัย') || msgStr.includes('ล้มเหลว')) {
                    icon = 'error';
                    title = 'ข้อผิดพลาด';
                } else if (msgStr.includes('กรุณา') || msgStr.includes('ปิดรับ') || msgStr.includes('เต็มแล้ว') || msgStr.includes('ระบุ')) {
                    icon = 'warning';
                    title = 'คำแนะนำ';
                } else if (msgStr.includes('สำเร็จ') || msgStr.includes('เรียบร้อย')) {
                    icon = 'success';
                    title = 'สำเร็จ';
                }

                return Swal.fire({
                    title: title,
                    text: msgStr,
                    icon: icon,
                    confirmButtonText: 'รับทราบ',
                    buttonsStyling: true
                });
            };
        }
    </script>
    <!-- Donut % Count-Up Animation -->
    <script>
        (function() {
            function easeOutCubic(t) { return 1 - Math.pow(1 - t, 3); }

            function countUpNum(el, suffix, delay, duration) {
                const target = parseInt(el.getAttribute('data-target') || '0', 10);
                const start = performance.now() + delay;
                function tick(now) {
                    const elapsed = Math.max(0, now - start);
                    const progress = Math.min(elapsed / duration, 1);
                    const current = Math.round(easeOutCubic(progress) * target);
                    el.textContent = current + suffix;
                    if (progress < 1) requestAnimationFrame(tick);
                }
                requestAnimationFrame(tick);
            }

            document.addEventListener('DOMContentLoaded', function() {
                document.querySelectorAll('.donut-percent-num').forEach(function(el) {
                    countUpNum(el, '%', 700, 900);
                });
                document.querySelectorAll('.donut-remaining-num').forEach(function(el) {
                    countUpNum(el, '', 900, 700);
                });
            });
        })();
    </script>
    <!-- Native App Style Confirmation Modal Logic (Main System Blue Theme) -->
    <script>
        window.appAuthModalOnCancel = null;

        function openAppAuthModal({ message, confirmUrl = '', confirmText = 'ตกลง', cancelText = 'ยกเลิก', onConfirm = null, onCancel = null }) {
            const modal = document.getElementById('appAuthModal');
            const modalText = document.getElementById('appAuthModalText');
            const confirmBtn = document.getElementById('appAuthModalConfirmBtn');
            const cancelBtn = document.getElementById('appAuthModalCancelBtn');
            if (!modal || !modalText || !confirmBtn) return;

            modalText.innerHTML = message;
            confirmBtn.textContent = confirmText;
            confirmBtn.removeAttribute('style'); // Use system blue gradient
            window.appAuthModalOnCancel = onCancel;

            if (typeof onConfirm === 'function') {
                confirmBtn.href = 'javascript:void(0)';
                confirmBtn.onclick = function(e) {
                    e.preventDefault();
                    window.appAuthModalOnCancel = null;
                    onConfirm();
                };
            } else {
                confirmBtn.href = confirmUrl || '/admin/login.php';
                confirmBtn.onclick = null;
            }

            if (cancelBtn) {
                cancelBtn.textContent = cancelText;
                cancelBtn.onclick = function(e) {
                    e.preventDefault();
                    closeAppAuthModal();
                };
            }

            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeAppAuthModal(e) {
            if (e && e.target && e.target !== e.currentTarget && e.currentTarget.id !== 'appAuthModal') return;
            const modal = document.getElementById('appAuthModal');
            if (modal) {
                modal.classList.add('hidden');
                document.body.style.overflow = '';
            }
            if (typeof window.appAuthModalOnCancel === 'function') {
                const cancelCb = window.appAuthModalOnCancel;
                window.appAuthModalOnCancel = null;
                cancelCb();
            }
        }

        function showLoginModal(redirectUrl = '/admin/login.php') {
            openAppAuthModal({
                message: 'โปรด Log in เข้าสู่ระบบก่อนทำรายการต่อ',
                confirmUrl: redirectUrl,
                confirmText: 'ตกลง'
            });
        }

        function showLogoutModal(logoutUrl = '/admin/logout.php') {
            openAppAuthModal({
                message: 'คุณต้องการออกจากระบบหรือไม่?',
                confirmUrl: logoutUrl,
                confirmText: 'ตกลง'
            });
        }

        // Global Interceptor: automatically intercept all login and logout clicks
        document.addEventListener('click', function(e) {
            // Ignore clicks originating from inside the modal
            if (e.target.closest('#appAuthModal')) return;

            const link = e.target.closest('a');
            if (!link) return;

            const href = link.getAttribute('href') || '';
            if (!href || href === '#' || href.startsWith('javascript:')) return;

            // Intercept logout links across the system
            if (href.includes('logout.php')) {
                e.preventDefault();
                showLogoutModal(href);
                return;
            }
        });

        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeAppAuthModal();
            }
        });
    </script>
</body>

</html>
