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
    $remainingSeatsAll = max(0, $totalSeatsAll - $totalBookedAll);
    $fillPercent = $totalSeatsAll > 0 ? min(100, round(($totalBookedAll / $totalSeatsAll) * 100)) : 0;
    // Circular donut parameters (radius = 38, circumference = 2 * PI * 38 = 238.76)
    $donutCircumference = 238.76;
    $donutOffset = $donutCircumference * (1 - ($fillPercent / 100));
}

define('APP_TITLE', 'ลงชื่อจองรถ');
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-3xl mx-auto px-4 sm:px-6 pt-2 pb-8 sm:pt-3 sm:pb-10">

    <!-- CU NEX Exact Inline Status Bar (📍 พิกัดพื้นที่  ⛅ สภาพอากาศ  🌫️ สภาพฝุ่น PM 2.5 / US AQI) -->
    <div class="flex items-center justify-center flex-wrap gap-x-4 gap-y-1.5 py-1 text-xs sm:text-sm text-slate-700 font-medium mb-4 sm:mb-5">
        <!-- Location: 📍 คลองหลวง, ปทุมธานี -->
        <div class="flex items-center space-x-1.5">
            <i class="fa-solid fa-location-dot text-red-500 text-sm"></i>
            <span class="text-slate-800 font-medium">คลองหลวง, ปทุมธานี</span>
        </div>

        <!-- Weather: ⛅ 30°C -->
        <div class="flex items-center space-x-1.5 text-slate-800">
            <span id="cunexWeatherIcon" class="text-amber-500 text-sm">
                <i class="fa-solid fa-cloud-sun"></i>
            </span>
            <span id="cunexTemp" class="font-medium">28°C</span>
        </div>

        <!-- Air Quality / Dust: 🌫️ 46 US AQI -->
        <div class="flex items-center space-x-1.5 text-slate-800" title="คุณภาพอากาศ (US AQI / PM 2.5)">
            <svg class="w-4 h-4 text-sky-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M4 8h12a3 3 0 0 0 3-3V4"/>
                <path d="M2 13h16a3 3 0 0 1 3 3v1"/>
                <path d="M6 18h9a2 2 0 0 0 2-2"/>
            </svg>
            <span id="cunexAqiVal" class="font-medium">46 US AQI</span>
        </div>
    </div>

    <?php if ($selectedTrip): ?>
        
        <!-- Header Overview Card (Clean Modern Card with Circular Donut Ring & Squircles) -->
        <div class="bg-surface rounded-3xl border border-charcoal-border/70 p-6 sm:p-8 shadow-card mb-8 relative overflow-hidden">
            <!-- Subtle accent top gradient bar -->
            <div class="absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-emerald-400 via-sky-500 to-blue-600"></div>

            <div class="space-y-6">
                <!-- Top Meta Row -->
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-charcoal-divider pb-4">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-medium bg-canvas border border-charcoal-border text-charcoal-secondary">
                            <i class="fa-regular fa-calendar-check text-accent"></i>
                            <span><?= formatThaiDate($selectedTrip['trip_date']) ?></span>
                        </span>
                        <?php if ($isTripActive): ?>
                            <span class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                <span>สถานะ: เปิดรับลงชื่อ</span>
                            </span>
                        <?php else: ?>
                            <span class="inline-flex items-center space-x-1.5 px-3 py-1 rounded-full text-xs font-semibold bg-rose-50 text-rose-700 border border-rose-200">
                                <span class="w-2 h-2 rounded-full bg-rose-500"></span>
                                <span>สถานะ: ปิดรับการลงชื่อ</span>
                            </span>
                        <?php endif; ?>
                    </div>

                    <a href="/check.php?trip_id=<?= $selectedTrip['id'] ?>" class="text-xs font-semibold text-accent hover:text-accent-hover transition flex items-center space-x-1">
                        <span>ดูรายชื่อทั้งหมด</span>
                        <i class="fa-solid fa-chevron-right text-[10px]"></i>
                    </a>
                </div>

                <!-- Main Overview Body: Title + Donut Stats Ring -->
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-6">
                    <div class="space-y-2 flex-1 min-w-0">
                        <span class="text-xs font-semibold uppercase tracking-wider text-charcoal-muted">รอบการเดินทาง</span>
                        <h1 class="text-2xl sm:text-3xl font-extrabold text-charcoal tracking-tight leading-tight">
                            <?= clean($selectedTrip['title']) ?>
                        </h1>
                        <p class="text-xs text-charcoal-secondary leading-relaxed pt-1">
                            จัดสรรที่นั่งสำหรับพระภิกษุ-สามเณร และสาธุชนผู้ร่วมเดินทาง
                        </p>
                    </div>

                    <!-- Circular Progress Ring (Inspired by Usage Widget from Reference UI) -->
                    <div class="flex items-center justify-center sm:justify-start gap-4 bg-canvas/80 p-4 rounded-2xl border border-charcoal-border/60 shrink-0 w-full sm:w-auto shadow-xs">
                        <div class="relative w-24 h-24 flex items-center justify-center">
                            <svg class="w-24 h-24 -rotate-90 transform" viewBox="0 0 90 90">
                                <defs>
                                    <linearGradient id="tripDonutGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                        <stop offset="0%" stop-color="#10B981" />
                                        <stop offset="50%" stop-color="#0EA5E9" />
                                        <stop offset="100%" stop-color="#2563EB" />
                                    </linearGradient>
                                </defs>
                                <!-- Track -->
                                <circle cx="45" cy="45" r="38" stroke="#E2E8F0" stroke-width="8" fill="none" class="donut-ring-track" />
                                <!-- Progress -->
                                <circle cx="45" cy="45" r="38" stroke="url(#tripDonutGrad)" stroke-width="8" fill="none"
                                        stroke-linecap="round"
                                        stroke-dasharray="<?= $donutCircumference ?>"
                                        stroke-dashoffset="<?= $donutOffset ?>"
                                        style="--donut-circumference:<?= $donutCircumference ?>;--donut-offset:<?= $donutOffset ?>"
                                        class="donut-ring-progress" />
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center text-center">
                                <span class="text-lg font-extrabold text-charcoal leading-none donut-center-num"><?= $totalBookedAll ?></span>
                                <span class="text-[10px] text-charcoal-muted font-medium mt-0.5">/ <?= $totalSeatsAll ?></span>
                            </div>
                        </div>
                        <div class="space-y-1 text-left pr-2">
                            <div class="text-[11px] font-semibold text-charcoal-muted uppercase tracking-wider">สำรองแล้ว</div>
                            <div class="text-base font-bold text-accent donut-percent-num" data-target="<?= $fillPercent ?>">0%</div>
                            <div class="text-xs text-charcoal-secondary font-medium">
                                ว่าง <span class="text-emerald-600 font-bold donut-remaining-num" data-target="<?= $remainingSeatsAll ?>">0</span> ที่นั่ง
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Deadline Notice -->
                <?php if (!empty($selectedTrip['deadline_notice'])): ?>
                    <div class="bg-canvas border-l-4 border-accent p-3.5 rounded-r-xl text-xs text-charcoal-secondary leading-relaxed font-normal">
                        <?= nl2br(clean($selectedTrip['deadline_notice'])) ?>
                    </div>
                <?php endif; ?>

                <!-- Boarding Schedule (Squircle Icon Cards) -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs pt-1">
                    <div class="app-tap flex items-start gap-3.5 bg-canvas/70 p-3.5 rounded-2xl border border-charcoal-border/70 hover:border-accent/40 transition">
                        <div class="w-10 h-10 rounded-2xl squircle-icon-blue flex items-center justify-center shrink-0 shadow-sm text-sm mt-0.5">
                            <i class="fa-solid fa-arrow-right"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-semibold text-charcoal-muted block uppercase tracking-wider">เวลาขึ้นรถ (ขาไป)</span>
                            <span class="font-bold text-charcoal text-xs sm:text-sm block mt-1 leading-relaxed break-words"><?= clean($selectedTrip['pickup_time_info'] ?? 'ขึ้นรถ 08.00 น.') ?></span>
                        </div>
                    </div>
                    <div class="app-tap flex items-start gap-3.5 bg-canvas/70 p-3.5 rounded-2xl border border-charcoal-border/70 hover:border-mint/40 transition">
                        <div class="w-10 h-10 rounded-2xl squircle-icon-mint flex items-center justify-center shrink-0 shadow-sm text-sm mt-0.5">
                            <i class="fa-solid fa-arrow-left"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <span class="text-[11px] font-semibold text-charcoal-muted block uppercase tracking-wider">เวลาขึ้นรถ (ขากลับ)</span>
                            <span class="font-bold text-charcoal text-xs sm:text-sm block mt-1 leading-relaxed break-words"><?= clean($selectedTrip['return_time_info'] ?? 'ขึ้นรถ 16.00 น.') ?></span>
                        </div>
                    </div>
                </div>

                <?php if (!empty($selectedTrip['notice_red'])): ?>
                    <div class="text-xs font-medium text-rose-800 bg-rose-50 border border-rose-200/80 p-3 rounded-2xl flex items-start gap-2.5 leading-relaxed">
                        <i class="fa-solid fa-circle-info text-rose-600 mt-0.5 shrink-0 text-sm"></i>
                        <span><?= clean($selectedTrip['notice_red']) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$isTripActive): ?>
            <!-- Status Closed Alert Banner -->
            <div class="bg-surface border border-charcoal-border rounded-2xl p-5 mb-8 flex items-start gap-4 shadow-subtle">
                <div class="w-10 h-10 rounded-2xl bg-rose-50 border border-rose-200 text-rose-600 flex items-center justify-center shrink-0 text-base">
                    <i class="fa-solid fa-lock"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <h2 class="text-sm font-bold text-charcoal">ขณะนี้ปิดรับการลงชื่อสำหรับรอบนี้แล้ว</h2>
                        <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-rose-100 text-rose-700">ปิดรับลงชื่อ</span>
                    </div>
                    <p class="text-xs text-charcoal-secondary mt-1 leading-relaxed">
                        ผู้ดูแลระบบได้ปิดการรับลงชื่อเรียบร้อยแล้ว หากท่านได้ลงชื่อไว้แล้ว สามารถตรวจสอบรายชื่อและคันรถได้ที่ปุ่มด้านล่าง
                    </p>
                    <div class="mt-3">
                        <a href="/check.php?trip_id=<?= $selectedTrip['id'] ?>" class="inline-flex items-center space-x-2 bg-gradient-to-r from-blue-600 to-sky-500 hover:from-blue-700 hover:to-sky-600 text-white text-xs font-semibold px-5 py-2.5 rounded-full transition shadow-sm">
                            <i class="fa-solid fa-magnifying-glass text-xs"></i>
                            <span>ตรวจสอบรายชื่อผู้ร่วมเดินทาง</span>
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Modern Booking Form (Rich UI with Squircles & Pill Radios) -->
        <form id="bookingWebForm" onsubmit="submitWebBooking(event)" class="bg-surface rounded-3xl p-6 sm:p-8 shadow-card border border-charcoal-border/70 space-y-8">
            <input type="hidden" name="trip_id" value="<?= $selectedTrip['id'] ?>">

            <!-- Section 1: ข้อมูลผู้เดินทาง -->
            <div>
                <div class="flex items-center space-x-3 mb-1">
                    <span class="w-7 h-7 rounded-xl bg-gradient-to-tr from-blue-600 to-sky-500 text-white flex items-center justify-center text-xs font-bold shadow-sm">1</span>
                    <h2 class="text-sm font-bold text-charcoal tracking-tight">ข้อมูลผู้ร่วมเดินทาง</h2>
                </div>
                <p class="text-xs text-charcoal-muted mb-5 ml-10">กรุณากรอกข้อมูลให้ถูกต้องสำหรับการประสานงานและการจัดที่นั่ง</p>

                <div class="grid grid-cols-1 sm:grid-cols-12 gap-4 ml-0 sm:ml-10">
                    <!-- คำนำหน้า -->
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-semibold text-charcoal mb-1.5">คำนำหน้า <span class="text-rose-500">*</span></label>
                        <select name="prefix" id="prefixSelect" onchange="togglePrefixOther(this.value)" class="w-full px-4 py-3 bg-canvas/60 border border-charcoal-border rounded-2xl text-sm sm:text-base text-charcoal focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none transition min-h-[48px]">
                            <option value="พระ">พระ</option>
                            <option value="พระมหา">พระมหา</option>
                            <option value="สามเณร">สามเณร</option>
                            <option value="นาย">นาย</option>
                            <option value="นางสาว">นางสาว</option>
                            <option value="นาง">นาง</option>
                            <option value="เด็กชาย">เด็กชาย</option>
                            <option value="other">อื่นๆ (ระบุ)</option>
                        </select>
                        <input type="text" id="prefixCustom" placeholder="ระบุคำนำหน้า" class="w-full mt-2 px-4 py-3 bg-canvas/60 border border-charcoal-border rounded-2xl text-sm sm:text-base text-charcoal min-h-[48px] hidden">
                    </div>

                    <!-- ชื่อ -->
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-semibold text-charcoal mb-1.5">ชื่อ <span class="text-rose-500">*</span></label>
                        <input type="text" 
                               name="first_name" 
                               required 
                               pattern="^[a-zA-Z\u0E01-\u0E5B\s\.\-]{2,50}$"
                               title="กรุณากรอกชื่อเป็นตัวอักษรภาษาไทยหรือภาษาอังกฤษอย่างน้อย 2 ตัวอักษร"
                               placeholder="เช่น บุญช่วย หรือ สมชาย" 
                               class="w-full px-4 py-3 bg-canvas/60 border border-charcoal-border rounded-2xl text-sm sm:text-base text-charcoal placeholder-charcoal-subtle focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none transition min-h-[48px]">
                    </div>

                    <!-- ฉายา / นามสกุล -->
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-semibold text-charcoal mb-1.5">ฉายา (หรือนามสกุล) <span class="text-rose-500">*</span></label>
                        <input type="text" 
                               name="last_name_or_nickname" 
                               required 
                               pattern="^[a-zA-Z\u0E01-\u0E5B\s\.\-]{2,50}$"
                               title="กรุณากรอกฉายาหรือนามสกุลเป็นตัวอักษร"
                               placeholder="เช่น ธมฺมรกฺขิโต หรือ ใจดี" 
                               class="w-full px-4 py-3 bg-canvas/60 border border-charcoal-border rounded-2xl text-sm sm:text-base text-charcoal placeholder-charcoal-subtle focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none transition min-h-[48px]">
                    </div>

                    <!-- อายุ -->
                    <div class="sm:col-span-4">
                        <label class="block text-xs font-semibold text-charcoal mb-1.5">อายุ (ปี) <span class="text-rose-500">*</span></label>
                        <input type="number" 
                               name="age" 
                               required 
                               min="5" 
                               max="120" 
                               placeholder="เช่น 30" 
                               class="w-full px-4 py-3 bg-canvas/60 border border-charcoal-border rounded-2xl text-sm sm:text-base text-charcoal placeholder-charcoal-subtle focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none transition min-h-[48px]">
                    </div>

                    <!-- เบอร์โทร -->
                    <div class="sm:col-span-8">
                        <label class="block text-xs font-semibold text-charcoal mb-1.5">เบอร์โทรศัพท์มือถือ <span class="text-rose-500">*</span></label>
                        <input type="tel" 
                               name="phone" 
                               required 
                               pattern="^0[0-9]{8,9}$"
                               maxlength="10"
                               minlength="9"
                               inputmode="numeric"
                               title="กรุณากรอกเบอร์โทรศัพท์ 9-10 หลัก (ขึ้นต้นด้วย 0)"
                               placeholder="เช่น 0812345678 (9-10 หลัก)" 
                               class="w-full px-4 py-3 bg-canvas/60 border border-charcoal-border rounded-2xl text-sm sm:text-base text-charcoal placeholder-charcoal-subtle focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none transition font-mono min-h-[48px]">
                    </div>
                </div>
            </div>

            <hr class="border-charcoal-divider">

            <!-- Section 2: เลือกรถที่จะเดินทาง (Squircle Icon Cards) -->
            <div>
                <div class="flex items-center space-x-3 mb-1">
                    <span class="w-7 h-7 rounded-xl bg-gradient-to-tr from-blue-600 to-sky-500 text-white flex items-center justify-center text-xs font-bold shadow-sm">2</span>
                    <h2 class="text-sm font-bold text-charcoal tracking-tight">รถคันที่เลือกเดินทาง</h2>
                </div>
                <p class="text-xs text-charcoal-muted mb-4 ml-10">เลือกรถที่ท่านต้องการ ระบบจะจัดสรรที่นั่งว่างให้อัตโนมัติตามลำดับ</p>

                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3.5 ml-0 sm:ml-10">
                    <?php foreach ($vehicles as $index => $v): 
                        $isFull = $v['booked_count'] >= $v['total_seats'];
                        $avail = max(0, $v['total_seats'] - $v['booked_count']);
                        $isVan = ($v['type'] === 'van');
                        $vehLabel = clean($v['vehicle_type_label'] ?? ($isVan ? 'รถตู้ (10 ที่นั่ง)' : 'รถบัส (' . $v['total_seats'] . ' ที่นั่ง)'));
                    ?>
                        <label class="app-tap app-radio-card group relative flex flex-col justify-between p-4 rounded-2xl border-2 transition-all cursor-pointer <?= $isFull ? 'bg-canvas/50 border-charcoal-border/60 opacity-60 cursor-not-allowed' : 'hover:border-accent hover:shadow-card border-charcoal-border bg-surface' ?>">
                            <div>
                                <div class="flex items-start justify-between mb-3">
                                    <!-- Squircle Vehicle Icon -->
                                    <div class="w-10 h-10 rounded-2xl <?= $isVan ? 'squircle-icon-mint' : 'squircle-icon-blue' ?> flex items-center justify-center shadow-sm text-sm">
                                        <i class="fa-solid <?= $isVan ? 'fa-van-shuttle' : 'fa-bus' ?>"></i>
                                    </div>
                                    <input type="radio" 
                                           name="vehicle_id" 
                                           value="<?= $v['id'] ?>" 
                                           <?= $isFull ? 'disabled' : '' ?> 
                                           required 
                                           class="w-4 h-4 text-accent border-charcoal-border focus:ring-accent mt-1">
                                </div>
                                
                                <div>
                                    <span class="font-bold text-charcoal text-sm block group-hover:text-accent transition"><?= clean($v['name']) ?></span>
                                    <span class="text-xs text-charcoal-muted block leading-snug mt-0.5"><?= $vehLabel ?></span>
                                </div>
                            </div>
                            
                            <div class="pt-3 mt-3 border-t border-charcoal-divider flex items-center justify-between text-xs">
                                <span class="text-[11px] text-charcoal-subtle font-medium"><?= $v['total_seats'] ?> ที่นั่ง</span>
                                <?php if ($isFull): ?>
                                    <span class="font-bold text-rose-700 text-[11px] bg-rose-50 px-2.5 py-0.5 rounded-full border border-rose-200">เต็มแล้ว</span>
                                <?php else: ?>
                                    <span class="font-bold text-emerald-700 text-[11px] bg-emerald-50 px-2.5 py-0.5 rounded-full border border-emerald-200">ว่าง <?= $avail ?></span>
                                <?php endif; ?>
                            </div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <hr class="border-charcoal-divider">

            <!-- Section 3: รายละเอียดการเดินทาง -->
            <div>
                <div class="flex items-center space-x-3 mb-1">
                    <span class="w-7 h-7 rounded-xl bg-gradient-to-tr from-blue-600 to-sky-500 text-white flex items-center justify-center text-xs font-bold shadow-sm">3</span>
                    <h2 class="text-sm font-bold text-charcoal tracking-tight">รายละเอียดการเดินทาง</h2>
                </div>
                <p class="text-xs text-charcoal-muted mb-4 ml-10">ระบุความประสงค์ในการเดินทาง</p>

                <div class="space-y-2.5 ml-0 sm:ml-10 text-xs sm:text-sm">
                    <label class="app-tap flex items-center space-x-3 p-3.5 rounded-2xl border border-charcoal-border hover:bg-canvas/60 hover:border-accent/50 cursor-pointer transition">
                        <input type="radio" name="travel_type" value="เดินทางไป และ เดินทางกลับ" checked required class="w-4 h-4 text-accent focus:ring-accent">
                        <span class="font-medium text-charcoal">1. เดินทางไป และ เดินทางกลับ</span>
                    </label>
                    <label class="app-tap flex items-center space-x-3 p-3.5 rounded-2xl border border-charcoal-border hover:bg-canvas/60 hover:border-accent/50 cursor-pointer transition">
                        <input type="radio" name="travel_type" value="เดินทางไปอย่างเดียว" class="w-4 h-4 text-accent focus:ring-accent">
                        <span class="font-medium text-charcoal">2. เดินทางไปอย่างเดียว</span>
                    </label>
                    <label class="app-tap flex items-center space-x-3 p-3.5 rounded-2xl border border-charcoal-border hover:bg-canvas/60 hover:border-accent/50 cursor-pointer transition">
                        <input type="radio" name="travel_type" value="เดินทางกลับอย่างเดียว" class="w-4 h-4 text-accent focus:ring-accent">
                        <span class="font-medium text-charcoal">3. เดินทางกลับอย่างเดียว</span>
                    </label>
                    <label class="app-tap flex items-center space-x-3 p-3.5 rounded-2xl border border-charcoal-border hover:bg-canvas/60 hover:border-accent/50 cursor-pointer transition">
                        <input type="radio" name="travel_type" value="other" id="travelOtherRadio" class="w-4 h-4 text-accent focus:ring-accent">
                        <span class="font-medium text-charcoal shrink-0">อื่นๆ:</span>
                        <input type="text" id="travelOtherText" placeholder="ระบุเพิ่มเติม เช่น มีคนกลับแทน" onfocus="document.getElementById('travelOtherRadio').checked = true" class="border-b border-charcoal-border focus:border-accent outline-none px-2 py-0.5 text-xs sm:text-sm flex-1 max-w-xs bg-transparent text-charcoal">
                    </label>
                </div>
            </div>

            <!-- Submit Button Bar (Vibrant Pill Gradient CTA) -->
            <div class="pt-4 flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-charcoal-divider">
                <a href="/check.php?trip_id=<?= $selectedTripId ?>" class="app-tap text-xs font-semibold text-charcoal-muted hover:text-accent transition flex items-center space-x-1 py-2">
                    <i class="fa-solid fa-arrow-left text-[10px]"></i>
                    <span>ตรวจสอบรายชื่อ</span>
                </a>

                <?php if ($isTripActive): ?>
                    <button type="submit" 
                            id="submitBtn"
                            class="app-tap w-full sm:w-auto bg-gradient-to-r from-blue-600 via-blue-500 to-sky-500 hover:from-blue-700 hover:to-sky-600 text-white font-bold px-8 py-3.5 rounded-full text-sm shadow-md shadow-blue-500/25 transition flex items-center justify-center space-x-2 min-h-[48px]">
                        <i class="fa-solid fa-check text-xs"></i>
                        <span>ยืนยันการลงชื่อ</span>
                    </button>
                <?php else: ?>
                    <button type="button" 
                            disabled
                            class="w-full sm:w-auto bg-canvas text-charcoal-subtle border border-charcoal-border font-semibold px-8 py-3.5 rounded-full text-xs sm:text-sm cursor-not-allowed flex items-center justify-center space-x-2">
                        <i class="fa-solid fa-lock text-xs"></i>
                        <span>ปิดรับการลงชื่อแล้ว</span>
                    </button>
                <?php endif; ?>
            </div>

        </form>

    <?php else: ?>
        <div class="bg-surface rounded-lg p-12 text-center border border-charcoal-border shadow-card">
            <h2 class="text-sm font-semibold text-charcoal">ยังไม่มีรอบการเดินทางที่เปิดรับลงชื่อ</h2>
            <div class="mt-4">
                <a href="/admin/login.php" class="bg-accent hover:bg-accent-hover text-white text-xs font-medium px-4 py-2 rounded-md shadow-subtle transition">เข้าสู่ระบบ Admin</a>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- Modal Success -->
<div id="successModal" class="fixed inset-0 z-50 flex items-center justify-center bg-charcoal/50 backdrop-blur-xs hidden p-4">
    <div class="bg-surface rounded-lg p-6 sm:p-7 max-w-sm w-full shadow-elevated border border-charcoal-border text-center">
        <div class="w-10 h-10 rounded-md bg-accent-subtle text-accent flex items-center justify-center mx-auto text-lg mb-3 border border-accent-border">
            <i class="fa-solid fa-check"></i>
        </div>
        <h3 class="text-base font-bold text-charcoal mb-0.5">ลงชื่อเรียบร้อยแล้ว</h3>
        <p class="text-xs text-charcoal-muted mb-4">ข้อมูลของท่านถูกบันทึกเข้าสู่ระบบแล้ว</p>
        
        <div class="bg-surface-muted border border-charcoal-border rounded-md p-3.5 text-left space-y-1.5 text-xs mb-5">
            <div class="flex justify-between">
                <span class="text-charcoal-muted">ผู้เดินทาง:</span>
                <span id="modalPassenger" class="font-medium text-charcoal">-</span>
            </div>
            <div class="flex justify-between">
                <span class="text-charcoal-muted">คันรถ:</span>
                <span id="modalVehicle" class="font-semibold text-charcoal">-</span>
            </div>
            <div class="flex justify-between">
                <span class="text-charcoal-muted">ลำดับที่นั่ง:</span>
                <span id="modalSeat" class="font-semibold text-charcoal">-</span>
            </div>
        </div>

        <div class="flex gap-2">
            <a href="/check.php?trip_id=<?= $selectedTripId ?>" class="flex-1 py-2 px-3 rounded-md bg-accent hover:bg-accent-hover text-white text-xs font-medium transition shadow-subtle">
                ตรวจสอบรายชื่อ
            </a>
            <button type="button" onclick="location.reload()" class="py-2 px-3 rounded-md bg-surface border border-charcoal-border hover:bg-surface-muted text-charcoal text-xs font-medium transition">
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
                document.getElementById('prefixCustom').focus();
                return;
            }
            formData.set('prefix', customPrefix);
        }

        const firstName = (formData.get('first_name') || '').trim();
        const lastName = (formData.get('last_name_or_nickname') || '').trim();
        const rawPhone = (formData.get('phone') || '').trim();
        const cleanPhone = rawPhone.replace(/[^0-9]/g, '');
        const age = parseInt(formData.get('age') || '0', 10);

        // Regular Expressions สำหรับตรวจสอบความถูกต้อง
        const nameRegex = /^[a-zA-Z\u0E01-\u0E5B\s\.\-]{2,50}$/;
        const phoneRegex = /^0[0-9]{8,9}$/;

        if (!nameRegex.test(firstName)) {
            alert('กรุณากรอกชื่อให้ถูกต้อง (ต้องเป็นตัวอักษร 2 ตัวขึ้นไป และไม่มีตัวเลขหรืออักขระพิเศษ)');
            document.querySelector('input[name="first_name"]').focus();
            return;
        }

        if (!nameRegex.test(lastName)) {
            alert('กรุณากรอกฉายาหรือนามสกุลให้ถูกต้อง (ต้องเป็นตัวอักษร และไม่มีตัวเลขหรืออักขระพิเศษ)');
            document.querySelector('input[name="last_name_or_nickname"]').focus();
            return;
        }

        if (isNaN(age) || age < 1 || age > 120) {
            alert('กรุณาระบุอายุให้ถูกต้อง (ระหว่าง 1 ถึง 120 ปี)');
            document.querySelector('input[name="age"]').focus();
            return;
        }

        if (!phoneRegex.test(cleanPhone)) {
            alert('กรุณากรอกเบอร์โทรศัพท์มือถือที่ถูกต้อง 9-10 หลัก (ขึ้นต้นด้วย 0 เช่น 0812345678)');
            document.querySelector('input[name="phone"]').focus();
            return;
        }
        formData.set('phone', cleanPhone);

        if (formData.get('travel_type') === 'other') {
            const customTravel = document.getElementById('travelOtherText').value.trim();
            if (!customTravel) {
                alert('กรุณาระบุรายละเอียดการเดินทาง');
                document.getElementById('travelOtherText').focus();
                return;
            }
            formData.set('travel_type', customTravel);
        }

        const submitBtn = document.getElementById('submitBtn');
        const originalText = submitBtn.innerHTML;
        submitBtn.disabled = true;
        // Skeleton Shimmer Loading Feedback (Item 4)
        submitBtn.innerHTML = `
            <div class="flex items-center space-x-2">
                <span class="skeleton w-3.5 h-3.5 rounded-full inline-block"></span>
                <span>กำลังตรวจสอบและบันทึก...</span>
            </div>
        `;

        try {
            const response = await fetch('/api/booking.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();

            if (result.success) {
                Swal.fire({
                    icon: 'success',
                    title: 'ลงชื่อเรียบร้อยแล้ว!',
                    html: `
                        <div class="bg-slate-50 border border-slate-200/80 rounded-2xl p-4 text-left space-y-2 text-xs sm:text-sm mt-3">
                            <div class="flex justify-between items-center py-0.5">
                                <span class="text-slate-500">ผู้เดินทาง:</span>
                                <strong class="text-slate-900">${result.booking.passenger_name}</strong>
                            </div>
                            <div class="flex justify-between items-center py-0.5">
                                <span class="text-slate-500">คันรถ:</span>
                                <strong class="text-slate-900">${result.booking.vehicle_name}</strong>
                            </div>
                            <div class="flex justify-between items-center py-0.5 border-t border-slate-200/60 pt-1.5">
                                <span class="text-slate-500">ลำดับที่นั่ง:</span>
                                <strong class="text-blue-600 font-bold text-sm sm:text-base">ลำดับที่ ${result.booking.seat_number}</strong>
                            </div>
                        </div>
                    `,
                    confirmButtonText: 'ตรวจสอบรายชื่อ',
                    showCancelButton: true,
                    cancelButtonText: 'ปิดหน้านี้',
                    reverseButtons: true,
                    allowOutsideClick: false
                }).then((res) => {
                    if (res.isConfirmed) {
                        window.location.href = `/check.php?trip_id=<?= $selectedTripId ?>`;
                    } else {
                        window.location.reload();
                    }
                });
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

    // CU NEX Inline Weather & AQI Updater (Matches CU NEX App Design)
    async function updateCunexWeather() {
        const lat = 14.07;
        const lon = 100.64;

        try {
            const [weatherRes, airRes] = await Promise.all([
                fetch(`https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lon}&current=temperature_2m,weather_code`).then(r => r.json()).catch(() => null),
                fetch(`https://air-quality-api.open-meteo.com/v1/air-quality?latitude=${lat}&longitude=${lon}&current=pm2_5,us_aqi`).then(r => r.json()).catch(() => null)
            ]);

            // 1. Weather
            if (weatherRes && weatherRes.current) {
                const temp = Math.round(weatherRes.current.temperature_2m);
                const code = Number(weatherRes.current.weather_code);
                const tempEl = document.getElementById('cunexTemp');
                const iconEl = document.getElementById('cunexWeatherIcon');

                if (tempEl) tempEl.textContent = `${temp}°C`;

                let iconHtml = '<i class="fa-solid fa-cloud-sun text-amber-500"></i>';
                if (code === 0) {
                    iconHtml = '<i class="fa-solid fa-sun text-amber-500"></i>';
                } else if (code <= 3) {
                    iconHtml = '<i class="fa-solid fa-cloud-sun text-amber-500"></i>';
                } else if (code <= 48) {
                    iconHtml = '<i class="fa-solid fa-smog text-slate-400"></i>';
                } else if (code <= 67) {
                    iconHtml = '<i class="fa-solid fa-cloud-rain text-blue-500"></i>';
                } else if (code <= 82) {
                    iconHtml = '<i class="fa-solid fa-cloud-bolt text-indigo-500"></i>';
                }

                if (iconEl) iconEl.innerHTML = iconHtml;
            }

            // 2. Air Quality / AQI (Exact format: "46 US AQI")
            if (airRes && airRes.current) {
                const aqiEl = document.getElementById('cunexAqiVal');
                const aqi = airRes.current.us_aqi ? Math.round(airRes.current.us_aqi) : 46;
                if (aqiEl) aqiEl.textContent = `${aqi} US AQI`;
            }
        } catch (e) {
            console.error('CU NEX weather fetch failed:', e);
        }
    }

    updateCunexWeather();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
