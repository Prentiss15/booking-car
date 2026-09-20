<?php
// includes/footer.php
?>
    </main>

    <!-- Footer (ข้อ 7: ชื่อผู้พัฒนาด้านล่างคือ ธุรการอาศรมบรรพชิต DCI) -->
    <footer class="bg-white border-t border-slate-200 mt-14 py-6 text-center text-xs text-slate-500">
        <div class="max-w-7xl mx-auto px-4 flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="flex items-center space-x-2">
                <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 animate-pulse"></span>
                <span class="font-semibold text-slate-700">ระบบจองรถต้นเดือน</span>
                <span class="text-slate-400">• งานบูชาข้าวพระต้นเดือน</span>
            </div>
            <div class="flex items-center space-x-1.5 text-slate-600">
                <span>พัฒนาและดูแลโดย:</span>
                <strong class="text-indigo-900 font-bold">ธุรการอาศรมบรรพชิต DCI</strong>
            </div>
        </div>
    </footer>

    <!-- Toast Notification Container -->
    <div id="toast" class="fixed bottom-6 right-6 z-50 transform translate-y-20 opacity-0 transition-all duration-300 pointer-events-none max-w-md bg-slate-900 text-white px-5 py-3.5 rounded-xl shadow-2xl flex items-center space-x-3">
        <i id="toastIcon" class="fa-solid fa-circle-check text-emerald-400 text-lg"></i>
        <div id="toastMessage" class="text-sm font-medium">ข้อความแจ้งเตือน</div>
    </div>

    <script>
        function showToast(message, type = 'success') {
            const toast = document.getElementById('toast');
            const toastMessage = document.getElementById('toastMessage');
            const toastIcon = document.getElementById('toastIcon');

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
            }, 3500);
        }
    </script>
</body>
</html>
