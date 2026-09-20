<?php
// index.php - หน้าลงชื่อจองรถต้นเดือน (ดีไซน์สากล เรียบหรู สะอาดตา)
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDb();
seedDemoDataIfEmpty($db);

$stmt = $db->query("SELECT * FROM trips ORDER BY is_active DESC, trip_date DESC");
$allTrips = $stmt->fetchAll();

$selectedTripId = isset($_GET['trip_id']) ? (int)$_GET['trip_id'] : ($allTrips[0]['id'] ?? 0);
$selectedTrip = null;

foreach ($allTrips as $t) {
    if ($t['id'] === $selectedTripId) {
        $selectedTrip = $t;
        break;
    }
}
if (!$selectedTrip && !empty($allTrips)) {
    $selectedTrip = $allTrips[0];
    $selectedTripId = $selectedTrip['id'];
}
$isTripActive = $selectedTrip && ((int)$selectedTrip['is_active'] === 1);

$vehicles = [];
$totalSeatsAll = 0;
$totalBookedAll = 0;

if ($selectedTrip) {
    $stmtVeh = $db->prepare("
        SELECT v.*, 
               (SELECT COUNT(*) FROM bookings b WHERE b.vehicle_id = v.id) as booked_count
        FROM vehicles v 
        WHERE v.trip_id = ? 
        ORDER BY v.type ASC, v.vehicle_number ASC
    ");
    $stmtVeh->execute([$selectedTrip['id']]);
    $vehicles = $stmtVeh->fetchAll();

    foreach ($vehicles as $v) {
        $totalSeatsAll += $v['total_seats'];
        $totalBookedAll += $v['booked_count'];
    }
}

define('APP_TITLE', 'ลงชื่อจองรถ');
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-3xl mx-auto px-4 sm:px-6 py-8">

    <?php if ($selectedTrip): ?>
        
        <!-- Header Banner Card (ข้อ 4: คำว่า งานประจำเดือน: เอาออกแล้ว) -->
        <div class="bg-white rounded-2xl border border-slate-200/90 p-6 sm:p-7 shadow-xs mb-6">
            <div class="space-y-2.5">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3">
                    <span class="text-xs font-semibold text-slate-600 flex items-center space-x-1.5">
                        <i class="fa-regular fa-calendar-check text-slate-500"></i>
                        <span><?= formatThaiDate($selectedTrip['trip_date']) ?></span>
                    </span>
                    <span class="text-xs text-slate-500">
                        จองแล้ว <strong class="text-slate-800"><?= $totalBookedAll ?></strong> / <?= $totalSeatsAll ?> ที่นั่ง (ว่างอีก <?= max(0, $totalSeatsAll - $totalBookedAll) ?>)
                    </span>
                </div>

                <h1 class="text-2xl font-bold text-slate-900 tracking-tight">
                    <?= clean($selectedTrip['title']) ?>
                </h1>

                <!-- Deadline Notice -->
                <?php if (!empty($selectedTrip['deadline_notice'])): ?>
                    <div class="bg-amber-50/80 border-l-3 border-amber-500 p-3 rounded-r-lg text-xs text-amber-900 leading-relaxed font-normal">
                        <?= nl2br(clean($selectedTrip['deadline_notice'])) ?>
                    </div>
                <?php endif; ?>

                <!-- Boarding Schedule -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs pt-1 text-slate-600">
                    <div class="flex items-center space-x-2 bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                        <span class="w-2 h-2 rounded-full bg-emerald-600"></span>
                        <span class="font-medium text-slate-900">ขาไป:</span>
                        <span><?= clean($selectedTrip['pickup_time_info'] ?? 'ขึ้นรถ 08.00 น.') ?></span>
                    </div>
                    <div class="flex items-center space-x-2 bg-slate-50 p-2.5 rounded-lg border border-slate-100">
                        <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                        <span class="font-medium text-slate-900">ขากลับ:</span>
                        <span><?= clean($selectedTrip['return_time_info'] ?? 'ขึ้นรถ 16.00 น.') ?></span>
                    </div>
                </div>

                <?php if (!empty($selectedTrip['notice_red'])): ?>
                    <div class="text-xs font-medium text-rose-700 bg-rose-50 border border-rose-200/80 p-2.5 rounded-lg">
                        <i class="fa-solid fa-circle-info mr-1 text-rose-500"></i>
                        <span><?= clean($selectedTrip['notice_red']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$isTripActive): ?>
            <!-- Status Closed Alert Banner -->
            <div class="bg-rose-50 border border-rose-200 rounded-2xl p-4 sm:p-5 mb-6 flex items-start gap-3.5 shadow-xs">
                <div class="w-10 h-10 rounded-xl bg-rose-100 text-rose-600 flex items-center justify-center shrink-0 text-lg">
                    <i class="fa-solid fa-lock"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <h2 class="text-sm font-bold text-rose-900">ขณะนี้ปิดรับการลงชื่อสำหรับรอบนี้แล้ว</h2>
                        <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-rose-200 text-rose-800">ปิดรับลงชื่อ</span>
                    </div>
                    <p class="text-xs text-rose-700 mt-1 leading-relaxed">
                        ผู้ดูแลระบบได้ปิดการรับลงชื่อเรียบร้อยแล้ว หากท่านได้ลงชื่อไว้แล้ว สามารถตรวจสอบรายชื่อและคันรถได้ที่ปุ่มด้านล่าง
                    </p>
                    <div class="mt-3">
                        <a href="/check.php?trip_id=<?= $selectedTrip['id'] ?>" class="inline-flex items-center space-x-1.5 bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold px-4 py-2 rounded-lg transition shadow-xs">
                            <i class="fa-solid fa-magnifying-glass text-xs"></i>
                            <span>ตรวจสอบรายชื่อผู้ร่วมเดินทาง</span>
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Professional Booking Form -->
        <form id="bookingWebForm" onsubmit="submitWebBooking(event)" class="bg-white rounded-2xl p-6 sm:p-8 shadow-xs border border-slate-200/90 space-y-6">
            <input type="hidden" name="trip_id" value="<?= $selectedTrip['id'] ?>">

            <!-- Section 1: ข้อมูลผู้เดินทาง -->
            <div>
                <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wider mb-1 flex items-center space-x-2">
                    <span class="w-5 h-5 rounded bg-slate-900 text-white flex items-center justify-center text-[10px] font-bold">1</span>
                    <span>ข้อมูลผู้ร่วมเดินทาง</span>
                </h2>
                <p class="text-xs text-slate-400 mb-4 ml-7">กรุณากรอกข้อมูลให้ถูกต้องสำหรับการประสานงาน</p>

                <div class="grid grid-cols-1 sm:grid-cols-12 gap-3.5 ml-0 sm:ml-7">
                    <!-- คำนำหน้า -->
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-medium text-slate-700 mb-1">คำนำหน้า <span class="text-rose-500">*</span></label>
                        <select name="prefix" id="prefixSelect" onchange="togglePrefixOther(this.value)" class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs sm:text-sm text-slate-800 focus:bg-white focus:ring-1 focus:ring-slate-900 focus:border-slate-900 outline-none">
                            <option value="พระ">พระ</option>
                            <option value="พระมหา">พระมหา</option>
                            <option value="สามเณร">สามเณร</option>
                            <option value="นาย">นาย</option>
                            <option value="นางสาว">นางสาว</option>
                            <option value="นาง">นาง</option>
                            <option value="เด็กชาย">เด็กชาย</option>
                            <option value="other">อื่นๆ (ระบุ)</option>
                        </select>
                        <input type="text" id="prefixCustom" placeholder="ระบุคำนำหน้า" class="w-full mt-1.5 px-3 py-1.5 bg-white border border-slate-300 rounded-lg text-xs hidden">
                    </div>

                    <!-- ชื่อ -->
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-medium text-slate-700 mb-1">ชื่อ <span class="text-rose-500">*</span></label>
                        <input type="text" name="first_name" required placeholder="เช่น ประพันธ์" class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:bg-white focus:ring-1 focus:ring-slate-900 focus:border-slate-900 outline-none">
                    </div>

                    <!-- ฉายา / นามสกุล -->
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-medium text-slate-700 mb-1">ฉายา (หรือนามสกุล) <span class="text-rose-500">*</span></label>
                        <input type="text" name="last_name_or_nickname" required placeholder="เช่น ชาติวฑฺฒโน" class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:bg-white focus:ring-1 focus:ring-slate-900 focus:border-slate-900 outline-none">
                    </div>

                    <!-- อายุ -->
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-medium text-slate-700 mb-1">อายุ (ปี) <span class="text-rose-500">*</span></label>
                        <input type="number" name="age" required min="1" max="120" placeholder="เช่น 35" class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:bg-white focus:ring-1 focus:ring-slate-900 focus:border-slate-900 outline-none">
                    </div>

                    <!-- เบอร์โทร -->
                    <div class="sm:col-span-8">
                        <label class="block text-xs font-medium text-slate-700 mb-1">เบอร์โทรศัพท์มือถือ <span class="text-rose-500">*</span></label>
                        <input type="tel" name="phone" required placeholder="เช่น 0812345678" class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:bg-white focus:ring-1 focus:ring-slate-900 focus:border-slate-900 outline-none">
                    </div>
                </div>
            </div>

            <hr class="border-slate-100">

            <!-- Section 2: เลือกรถที่จะเดินทาง (ข้อ 2: แสดงชนิดรถหลากหลาย) -->
            <div>
                <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wider mb-1 flex items-center space-x-2">
                    <span class="w-5 h-5 rounded bg-slate-900 text-white flex items-center justify-center text-[10px] font-bold">2</span>
                    <span>รถคันที่เลือกเดินทาง</span>
                </h2>
                <p class="text-xs text-slate-400 mb-4 ml-7">เลือกรถที่ท่านต้องการ ระบบจะจัดสรรที่นั่งว่างให้อัตโนมัติตามลำดับ</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3 ml-0 sm:ml-7">
                    <?php foreach ($vehicles as $index => $v): 
                        $isFull = $v['booked_count'] >= $v['total_seats'];
                        $avail = max(0, $v['total_seats'] - $v['booked_count']);
                        $vehLabel = clean($v['vehicle_type_label'] ?? ($v['type'] === 'van' ? 'รถตู้ (10 ที่นั่ง)' : 'รถบัส (' . $v['total_seats'] . ' ที่นั่ง)'));
                    ?>
                        <label class="relative flex flex-col justify-between p-3.5 rounded-xl border transition cursor-pointer <?= $isFull ? 'bg-slate-50/60 border-slate-200 opacity-60 cursor-not-allowed' : 'hover:border-slate-800 hover:bg-slate-50/50 border-slate-200 bg-white' ?>">
                            <div class="flex items-start justify-between mb-2">
                                <div>
                                    <span class="font-bold text-slate-900 text-sm block"><?= clean($v['name']) ?></span>
                                    <span class="text-[11px] text-slate-500 block leading-snug"><?= $vehLabel ?></span>
                                </div>
                                <input type="radio" 
                                       name="vehicle_id" 
                                       value="<?= $v['id'] ?>" 
                                       <?= $isFull ? 'disabled' : '' ?> 
                                       required 
                                       class="w-4 h-4 text-slate-900 border-slate-300 focus:ring-slate-900 mt-0.5">
                            </div>
                            
                            <div class="pt-2 border-t border-slate-100 flex items-center justify-between text-xs">
                                <span class="text-[11px] text-slate-400">ขนาด <?= $v['total_seats'] ?> ที่นั่ง</span>
                                <?php if ($isFull): ?>
                                    <span class="font-semibold text-rose-600 text-[11px]">เต็มแล้ว</span>
                                <?php else: ?>
                                    <span class="font-semibold text-emerald-700 text-[11px]">ว่าง <?= $avail ?> ที่นั่ง</span>
                                <?php endif; ?>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <hr class="border-slate-100">

            <!-- Section 3: รายละเอียดการเดินทาง -->
            <div>
                <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wider mb-1 flex items-center space-x-2">
                    <span class="w-5 h-5 rounded bg-slate-900 text-white flex items-center justify-center text-[10px] font-bold">3</span>
                    <span>รายละเอียดการเดินทาง</span>
                </h2>
                <p class="text-xs text-slate-400 mb-3 ml-7">ระบุความประสงค์ในการเดินทาง</p>

                <div class="space-y-2 ml-0 sm:ml-7 text-xs">
                    <label class="flex items-center space-x-2.5 p-2.5 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                        <input type="radio" name="travel_type" value="เดินทางไป และ เดินภาพกลับ" checked required class="w-4 h-4 text-slate-900 focus:ring-slate-900">
                        <span class="font-medium text-slate-800">1. เดินทางไป และ เดินทางกลับ</span>
                    </label>
                    <label class="flex items-center space-x-2.5 p-2.5 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                        <input type="radio" name="travel_type" value="เดินทางไปอย่างเดียว" class="w-4 h-4 text-slate-900 focus:ring-slate-900">
                        <span class="font-medium text-slate-800">2. เดินทางไปอย่างเดียว</span>
                    </label>
                    <label class="flex items-center space-x-2.5 p-2.5 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                        <input type="radio" name="travel_type" value="เดินทางกลับอย่างเดียว" class="w-4 h-4 text-slate-900 focus:ring-slate-900">
                        <span class="font-medium text-slate-800">3. เดินทางกลับอย่างเดียว</span>
                    </label>
                    <label class="flex items-center space-x-2.5 p-2.5 rounded-lg border border-slate-200 hover:bg-slate-50 cursor-pointer">
                        <input type="radio" name="travel_type" value="other" id="travelOtherRadio" class="w-4 h-4 text-slate-900 focus:ring-slate-900">
                        <span class="font-medium text-slate-800">อื่นๆ:</span>
                        <input type="text" id="travelOtherText" placeholder="ระบุเพิ่มเติม เช่น มีคนกลับแทน" onfocus="document.getElementById('travelOtherRadio').checked = true" class="border-b border-slate-300 focus:border-slate-900 outline-none px-2 py-0.5 text-xs flex-1 max-w-xs">
                    </label>
                </div>
            </div>

            <!-- Submit Button Bar -->
            <div class="pt-4 flex flex-col sm:flex-row items-center justify-between gap-3 border-t border-slate-100">
                <a href="/check.php?trip_id=<?= $selectedTripId ?>" class="text-xs font-medium text-slate-600 hover:text-slate-900">
                    ← ตรวจสอบรายชื่อ
                </a>

                <?php if ($isTripActive): ?>
                    <button type="submit" 
                            id="submitBtn"
                            class="w-full sm:w-auto bg-slate-900 hover:bg-slate-800 text-white font-medium px-8 py-2.5 rounded-lg text-xs sm:text-sm shadow-xs transition flex items-center justify-center space-x-2">
                        <i class="fa-solid fa-check text-xs"></i>
                        <span>ยืนยันการลงชื่อ</span>
                    </button>
                <?php else: ?>
                    <button type="button" 
                            disabled
                            class="w-full sm:w-auto bg-slate-200 text-slate-400 font-medium px-8 py-2.5 rounded-lg text-xs sm:text-sm cursor-not-allowed flex items-center justify-center space-x-2">
                        <i class="fa-solid fa-lock text-xs"></i>
                        <span>ปิดรับการลงชื่อแล้ว</span>
                    </button>
                <?php endif; ?>
            </div>

        </form>

    <?php else: ?>
        <div class="bg-white rounded-2xl p-12 text-center border border-slate-200 shadow-xs">
            <h2 class="text-sm font-bold text-slate-700">ยังไม่มีรอบการเดินทางที่เปิดรับลงชื่อ</h2>
            <div class="mt-4">
                <a href="/admin/login.php" class="bg-slate-900 text-white text-xs font-medium px-4 py-2 rounded-lg">เข้าสู่ระบบ Admin</a>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- Modal Success -->
<div id="successModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs hidden p-4">
    <div class="bg-white rounded-2xl p-6 sm:p-7 max-w-sm w-full shadow-xl border border-slate-200 text-center">
        <div class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto text-xl mb-3 border border-emerald-200">
            <i class="fa-solid fa-check"></i>
        </div>
        <h3 class="text-base font-bold text-slate-900 mb-0.5">ลงชื่อเรียบร้อยแล้ว</h3>
        <p class="text-xs text-slate-500 mb-4">ข้อมูลของท่านถูกบันทึกเข้าสู่ระบบแล้ว</p>
        
        <div class="bg-slate-50 border border-slate-200 rounded-xl p-3.5 text-left space-y-1.5 text-xs mb-5">
            <div class="flex justify-between">
                <span class="text-slate-400">ผู้เดินทาง:</span>
                <span id="modalPassenger" class="font-semibold text-slate-800">-</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-400">คันรถ:</span>
                <span id="modalVehicle" class="font-bold text-slate-900">-</span>
            </div>
            <div class="flex justify-between">
                <span class="text-slate-400">ลำดับที่นั่ง:</span>
                <span id="modalSeat" class="font-bold text-emerald-700">-</span>
            </div>
        </div>

        <div class="flex gap-2">
            <a href="/check.php?trip_id=<?= $selectedTripId ?>" class="flex-1 py-2 px-3 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-xs font-medium transition">
                ตรวจสอบรายชื่อ
            </a>
            <button type="button" onclick="location.reload()" class="py-2 px-3 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-medium transition">
                ลงชื่อเพิ่ม
            </button>
        </div>
    </div>
</div>

<script>
    function togglePrefixOther(val) {
        const customInput = document.getElementById('prefixCustom');
        if (val === 'other') {
            customInput.classList.remove('hidden');
            customInput.focus();
        } else {
            customInput.classList.add('hidden');
        }
    }

    async function submitWebBooking(event) {
        event.preventDefault();
        
        if (!<?= $isTripActive ? 'true' : 'false' ?>) {
            alert('รอบการเดินทางนี้ปิดรับการลงชื่อแล้ว');
            return;
        }

        const form = document.getElementById('bookingWebForm');
        const formData = new FormData(form);
        formData.append('action', 'book');

        if (formData.get('prefix') === 'other') {
            const customPrefix = document.getElementById('prefixCustom').value.trim();
            if (!customPrefix) {
                alert('กรุณาระบุคำนำหน้า');
                return;
            }
            formData.set('prefix', customPrefix);
        }

        if (formData.get('travel_type') === 'other') {
            const customTravel = document.getElementById('travelOtherText').value.trim();
            if (!customTravel) {
                alert('กรุณาระบุรายละเอียดการเดินทาง');
                return;
            }
            formData.set('travel_type', customTravel);
        }

        const submitBtn = document.getElementById('submitBtn');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = 'กำลังบันทึก...';

        try {
            const response = await fetch('/api/booking.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                document.getElementById('modalPassenger').textContent = result.booking.passenger_name;
                document.getElementById('modalVehicle').textContent = result.booking.vehicle_name;
                document.getElementById('modalSeat').textContent = `ลำดับที่ ${result.booking.seat_number}`;
                document.getElementById('successModal').classList.remove('hidden');
            } else {
                alert(result.message || 'เกิดข้อผิดพลาดในการลงชื่อ');
                submitBtn.disabled = false;
                submitBtn.innerHTML = originalText;
            }
        } catch (err) {
            console.error(err);
            alert('เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์');
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
