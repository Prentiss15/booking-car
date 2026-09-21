<?php
// includes/footer.php
$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
$isAdmin = isAdminLoggedIn();
?>
    </main>

    <!-- Footer (ธุรการอาศรมบรรพชิต DCI) -->
    <footer class="bg-white border-t border-slate-200 mt-14 py-8 text-center text-xs text-slate-500 hidden md:block">
        <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="flex items-center space-x-2">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                <span class="font-semibold text-slate-700">ระบบจองรถต้นเดือน</span>
                <span class="text-slate-400">• งานบูชาข้าวพระต้นเดือน</span>
            </div>
            <div class="flex items-center space-x-1.5 text-slate-600">
                <span>พัฒนาและดูแลโดย:</span>
                <strong class="text-indigo-950 font-bold">ธุรการอาศรมบรรพชิต DCI</strong>
            </div>
        </div>
    </footer>

    <!-- Mobile Bottom Sticky Navigation Bar (Item 7: ให้เห็นแถบค้างไว้ว่ามีเมนูอะไรบ้าง) -->
    <nav class="md:hidden fixed bottom-0 left-0 right-0 z-40 bg-white/95 backdrop-blur-md border-t border-slate-200/90 shadow-[0_-4px_16px_rgba(0,0,0,0.06)] px-2 py-1.5 flex items-center justify-around">
        <a href="/index.php" class="flex flex-col items-center py-1 px-3 rounded-xl transition <?= $currentScript === 'index.php' ? 'text-blue-600 font-bold bg-blue-50' : 'text-slate-500 hover:text-slate-800' ?>">
            <i class="fa-solid fa-van-shuttle text-lg mb-0.5"></i>
            <span class="text-[11px]">ลงชื่อ</span>
        </a>

        <a href="/check.php" class="flex flex-col items-center py-1 px-3 rounded-xl transition <?= $currentScript === 'check.php' ? 'text-blue-600 font-bold bg-blue-50' : 'text-slate-500 hover:text-slate-800' ?>">
            <i class="fa-solid fa-clipboard-list text-lg mb-0.5"></i>
            <span class="text-[11px]">ตรวจสอบ</span>
        </a>

        <?php if ($isAdmin): ?>
            <a href="/details.php" class="flex flex-col items-center py-1 px-3 rounded-xl transition <?= $currentScript === 'details.php' ? 'text-amber-700 font-bold bg-amber-50' : 'text-slate-500 hover:text-slate-800' ?>">
                <i class="fa-solid fa-table-list text-lg mb-0.5"></i>
                <span class="text-[11px]">ข้อมูลละเอียด</span>
            </a>
            <a href="/admin/index.php" class="flex flex-col items-center py-1 px-3 rounded-xl transition <?= str_contains($currentScript, 'admin') && $currentScript !== 'login.php' ? 'text-amber-700 font-bold bg-amber-50' : 'text-slate-500 hover:text-slate-800' ?>">
                <i class="fa-solid fa-gear text-lg mb-0.5"></i>
                <span class="text-[11px]">จัดการระบบ</span>
            </a>
        <?php else: ?>
            <a href="/admin/login.php" class="flex flex-col items-center py-1 px-3 rounded-xl transition <?= $currentScript === 'login.php' ? 'text-blue-600 font-bold bg-blue-50' : 'text-slate-500 hover:text-slate-800' ?>">
                <i class="fa-solid fa-lock text-lg mb-0.5"></i>
                <span class="text-[11px]">Admin</span>
            </a>
        <?php endif; ?>
    </nav>

    <!-- Toast Notification Container -->
    <div id="toast" class="fixed bottom-20 md:bottom-6 right-6 z-50 transform translate-y-20 opacity-0 transition-all duration-300 pointer-events-none max-w-md bg-slate-900/95 text-white px-5 py-3.5 rounded-2xl shadow-2xl flex items-center space-x-3 border border-slate-700/50">
        <i id="toastIcon" class="fa-solid fa-circle-check text-emerald-400 text-lg"></i>
        <div id="toastMessage" class="text-sm font-medium">ข้อความแจ้งเตือน</div>
    </div>

    <script>
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            const toastIcon = document.getElementById('toastIcon');

            if (!toast || !toastMessage || !toastIcon) return;

            toastMessage.textContent = message;
            if (type === 'success') {
                toastIcon.className = 'fa-solid fa-circle-check text-emerald-400 text-lg';
            } else if (type === 'error') {
                toastIcon.className = 'fa-solid fa-circle-xmark text-rose-400 text-lg';
            } else {
                toastIcon.className = 'fa-solid fa-circle-info text-sky-400 text-lg';
            }

            toast.classList.remove('translate-y-20', 'opacity-0', 'pointer-events-none');
            setTimeout(() => {
                toast.classList.add('translate-y-20', 'opacity-0', 'pointer-events-none');
            }, 3000);
        }
    </script>
</body>
</html>
