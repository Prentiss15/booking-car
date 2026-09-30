<?php
// check.php - หน้าตรวจสอบรายชื่อ (ดีไซน์สากล เรียบหรู สะอาดตา พร้อมค้นหา Real-time)
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDb();
seedDemoDataIfEmpty($db);

$isAdmin = isAdminLoggedIn();

$searchQuery = clean($_GET['q'] ?? '');
$filterVehicleId = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;

$stmtTrips = $db->query("SELECT * FROM trips ORDER BY is_active DESC, trip_date DESC");
$allTrips = $stmtTrips->fetchAll();
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

$totalCapacity = 0;
$totalBooked = 0;

$vehicles = [];
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
        $totalCapacity += $v['total_seats'];
        $totalBooked += $v['booked_count'];
    }
}

$myBookings = [];
if (!empty($searchQuery) && $selectedTrip) {
    $myBookings = searchBookingsFts($db, $searchQuery, (int)$selectedTrip['id'], 60);
}

// Optimization: Bulk fetch all bookings for active vehicles in 1 single query (Eliminating N+1 query storm)
$displayVehicles = [];
$vehicleIds = array_column($vehicles, 'id');
$bookingsByVehicle = [];

if (!empty($vehicleIds)) {
    $inClause = implode(',', array_fill(0, count($vehicleIds), '?'));
    $stmtAllB = $db->prepare("SELECT * FROM bookings WHERE vehicle_id IN ({$inClause}) ORDER BY seat_number ASC");
    $stmtAllB->execute($vehicleIds);
    $allBList = $stmtAllB->fetchAll();

    foreach ($allBList as $b) {
        $bookingsByVehicle[$b['vehicle_id']][$b['seat_number']] = $b;
    }
}

foreach ($vehicles as $v) {
    if ($filterVehicleId > 0 && $v['id'] !== $filterVehicleId) {
        continue;
    }
    $seatMap = $bookingsByVehicle[$v['id']] ?? [];
    $displayVehicles[] = [
        'info' => $v,
        'seats' => $seatMap,
        'booked_count' => count($seatMap)
    ];
}

// Helper formatting: คำนำหน้าติดกับชื่อเสมอ ไม่เว้นวรรค
function formatCleanPassengerName(array $p): string {
    $prefix = trim($p['prefix'] ?? '');
    $firstName = trim($p['first_name'] ?? '');
    $lastName = trim($p['last_name_or_nickname'] ?? '');
    if (!empty($firstName)) {
        return $prefix . $firstName . ($lastName ? ' ' . $lastName : '');
    }
    return preg_replace('/^(พระมหา|พระครู|พระอาจารย์|พระ|สามเณร|นาย|นางสาว|นาง)\s+/u', '$1', trim($p['passenger_name'] ?? ''));
}

$remainingCapacity = max(0, $totalCapacity - $totalBooked);
$checkFillPercent = $totalCapacity > 0 ? min(100, round(($totalBooked / $totalCapacity) * 100)) : 0;
$checkDonutCircumference = 238.76;
$checkDonutOffset = $checkDonutCircumference * (1 - ($checkFillPercent / 100));

define('APP_TITLE', 'ตรวจสอบรายชื่อ');
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 sm:py-10">

    <!-- Top Schedule & Information Card (Clean Modern Card with Circular Donut Ring) -->
    <?php if ($selectedTrip): ?>
        <div class="bg-surface rounded-3xl p-4 sm:p-8 border border-charcoal-border/70 shadow-card mb-8 relative overflow-hidden">
            <!-- Subtle accent top gradient bar -->
            <div class="absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-emerald-400 via-sky-500 to-blue-600"></div>

            <div class="space-y-4 sm:space-y-6">
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

                    <?php if ($isTripActive): ?>
                        <a href="/index.php?trip_id=<?= $selectedTrip['id'] ?>" class="text-xs font-semibold text-accent hover:text-accent-hover transition flex items-center space-x-1">
                            <span>ลงชื่อเข้าร่วมเดินทาง</span>
                            <i class="fa-solid fa-chevron-right text-[10px]"></i>
                        </a>
                    <?php endif; ?>
                </div>

                <!-- Main Overview: Title & Donut Stats Ring -->
                <div class="flex flex-row items-center justify-between gap-2.5 sm:gap-6">
                    <div class="space-y-1 sm:space-y-2 flex-1 min-w-0">

                        <h1 class="text-base sm:text-2xl lg:text-3xl font-extrabold text-charcoal tracking-tight leading-snug">
                            ตรวจสอบรายชื่อและคันรถ
                        </h1>
                        <p class="text-[11px] sm:text-xs lg:text-sm text-charcoal-secondary leading-snug line-clamp-2 sm:line-clamp-none">
                            <?= clean($selectedTrip['title']) ?>
                        </p>

                    </div>

                    <!-- Circular Progress Donut Ring (Reference App Style) -->
                    <div class="flex items-center gap-2 sm:gap-3 bg-canvas/80 px-2.5 py-2 sm:p-4 lg:p-5 rounded-2xl border border-charcoal-border/60 shrink-0">
                        <div class="relative w-14 h-14 sm:w-20 sm:h-20 lg:w-24 lg:h-24 flex items-center justify-center shrink-0">
                            <svg class="w-14 h-14 sm:w-20 sm:h-20 lg:w-24 lg:h-24 -rotate-90 transform" viewBox="0 0 90 90">
                                <defs>
                                    <linearGradient id="checkDonutGrad" x1="0%" y1="0%" x2="100%" y2="100%">
                                        <stop offset="0%" stop-color="#10B981" />
                                        <stop offset="50%" stop-color="#0EA5E9" />
                                        <stop offset="100%" stop-color="#2563EB" />
                                    </linearGradient>
                                </defs>
                                <circle cx="45" cy="45" r="38" stroke="#E2E8F0" stroke-width="8" fill="none" class="donut-ring-track" />
                                <circle cx="45" cy="45" r="38" stroke="url(#checkDonutGrad)" stroke-width="8" fill="none"
                                        stroke-linecap="round"
                                        stroke-dasharray="<?= $checkDonutCircumference ?>"
                                        stroke-dashoffset="<?= $checkDonutOffset ?>"
                                        style="--donut-circumference:<?= $checkDonutCircumference ?>;--donut-offset:<?= $checkDonutOffset ?>"
                                        class="donut-ring-progress" />
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center text-center">
                                <span class="text-sm sm:text-lg lg:text-xl font-extrabold text-charcoal leading-none donut-center-num"><?= $totalBooked ?></span>
                                <span class="text-[8px] sm:text-[10px] text-charcoal-muted font-medium mt-0.5">/ <?= $totalCapacity ?></span>
                            </div>
                        </div>
                        <div class="space-y-0.5 sm:space-y-1 text-left pr-0.5 sm:pr-2">
                            <div class="text-[9px] sm:text-[11px] font-semibold text-charcoal-muted uppercase tracking-wider">สำรองแล้ว</div>
                            <div class="text-sm sm:text-base lg:text-lg font-bold text-accent donut-percent-num" data-target="<?= $checkFillPercent ?>">0%</div>
                            <div class="text-[10px] sm:text-xs text-charcoal-secondary font-medium whitespace-nowrap">
                                ว่างอีก <span class="text-emerald-600 font-bold donut-remaining-num" data-target="<?= $remainingCapacity ?>">0</span> ที่นั่ง
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Search Section (Clean Modern UI with Pill Elements) -->
    <div class="bg-surface rounded-3xl p-6 sm:p-7 border border-charcoal-border/70 shadow-card mb-8 relative">
        <div class="max-w-2xl">
            <h2 class="text-sm font-bold text-charcoal mb-1 flex items-center space-x-2">
                <i class="fa-solid fa-magnifying-glass text-accent text-xs"></i>
                <span>ค้นหาชื่อเพื่อดูคันรถและลำดับที่นั่ง</span>
            </h2>
            <p class="text-xs text-charcoal-muted mb-4">
                พิมพ์ชื่อ, ฉายา หรือเบอร์โทรศัพท์ เพื่อค้นหาลำดับที่นั่ง
            </p>

            <div class="relative">
                <form method="GET" action="/check.php" id="searchForm" class="flex flex-col sm:flex-row gap-2.5">
                    <input type="hidden" name="trip_id" id="tripIdInput" value="<?= $selectedTripId ?>">
                    
                    <div class="relative flex-grow">
                        <input type="text" 
                               id="liveSearchInput"
                               name="q" 
                               autocomplete="off"
                               value="<?= clean($searchQuery) ?>" 
                               placeholder="เช่น บุญช่วย, กตญาโณ, 082..." 
                               class="w-full pl-10 pr-4 py-2.5 bg-canvas/60 border border-charcoal-border rounded-xl text-sm text-charcoal placeholder-charcoal-subtle focus:ring-2 focus:ring-accent/20 focus:border-accent outline-none transition">
                        <i class="fa-solid fa-search absolute left-3.5 top-3.5 text-charcoal-muted text-xs"></i>
                    </div>

                    <div class="flex gap-2">
                        <!-- Search CTA Button -->
                        <button type="submit" class="bg-gradient-to-r from-blue-600 to-sky-500 hover:from-blue-700 hover:to-sky-600 text-white font-bold px-6 py-2.5 rounded-full text-xs sm:text-sm transition shadow-sm flex-1 sm:flex-initial">
                            ค้นหา
                        </button>
                        <?php if (!empty($searchQuery)): ?>
                            <a href="/check.php?trip_id=<?= $selectedTripId ?>" class="bg-canvas hover:bg-slate-200 border border-charcoal-border text-charcoal-secondary font-semibold px-4 py-2.5 rounded-full text-xs sm:text-sm flex items-center justify-center transition">
                                ล้าง
                            </a>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Dropdown Autocomplete -->
                <div id="suggestionDropdown" class="absolute left-0 right-0 top-full mt-2 bg-surface text-charcoal rounded-2xl shadow-elevated border border-charcoal-border overflow-hidden z-30 hidden max-h-72 overflow-y-auto"></div>
            </div>
        </div>

        <!-- Search Result Cards -->
        <?php if (!empty($searchQuery)): ?>
            <div class="mt-6 pt-5 border-t border-charcoal-divider">
                <?php if (!empty($myBookings)): ?>
                    <div class="space-y-3">
                        <span class="text-xs font-bold text-charcoal block">ผลการค้นหา (พบ <?= count($myBookings) ?> คน):</span>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3.5">
                            <?php foreach ($myBookings as $b): 
                                $bFullName = formatCleanPassengerName($b);
                            ?>
                                <div class="bg-canvas/70 p-4 rounded-2xl border border-charcoal-border/70 flex items-center justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <div class="font-bold text-charcoal text-sm sm:text-base break-words"><?= clean($bFullName) ?></div>
                                        <div class="text-xs text-charcoal-secondary mt-1 flex flex-wrap items-center gap-2">
                                            <span class="bg-white px-2.5 py-0.5 rounded-full border border-charcoal-border/60">คัน: <strong class="text-charcoal"><?= clean($b['vehicle_name']) ?></strong></span>
                                            <span class="bg-white px-2.5 py-0.5 rounded-full border border-charcoal-border/60">ที่นั่งที่: <strong class="text-accent font-bold"><?= $b['seat_number'] ?></strong></span>
                                            <?php if (!empty($b['phone'])): ?>
                                                <?php if ($isAdmin): ?>
                                                    <a href="tel:<?= clean($b['phone']) ?>" class="bg-white px-2.5 py-0.5 rounded-full border border-charcoal-border/60 text-accent font-semibold inline-flex items-center gap-1 hover:underline">
                                                        <i class="fa-solid fa-phone text-[10px]"></i>
                                                        <span><?= clean($b['phone']) ?></span>
                                                    </a>
                                                <?php else: ?>
                                                    <span class="bg-white px-2.5 py-0.5 rounded-full border border-charcoal-border/60 text-charcoal font-medium inline-flex items-center gap-1">
                                                        <i class="fa-solid fa-phone text-[10px] text-charcoal-muted"></i>
                                                        <span><?= clean(maskPhoneNumber($b['phone'])) ?></span>
                                                    </span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php if ($isAdmin): ?>
                                        <div class="shrink-0 text-right">
                                            <button type="button" 
                                                    onclick="openCancelModal(<?= $b['id'] ?>, '<?= clean($bFullName) ?>', '<?= clean($b['vehicle_name']) ?>', <?= $b['seat_number'] ?>)"
                                                    class="text-xs font-semibold text-rose-700 hover:text-rose-800 px-3 py-1.5 bg-rose-50 rounded-full border border-rose-200 hover:bg-rose-100 transition shadow-subtle">
                                                ยกเลิกการจอง
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-xs text-charcoal-muted p-2">
                        ไม่พบรายชื่อที่ตรงกับ "<?= clean($searchQuery) ?>"
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Vehicle Filter Tabs (Modern Swipeable Pill Tabs - iOS/Android Feel) -->
    <div class="flex items-center justify-between gap-3 mb-6">
        <div class="flex items-center gap-2 overflow-x-auto whitespace-nowrap no-scrollbar py-1.5 px-0.5 -mx-4 px-4 sm:mx-0 sm:px-0 scroll-smooth flex-1">
            <a href="?trip_id=<?= $selectedTripId ?>" 
               class="app-tap px-4 py-2 rounded-full font-semibold transition shrink-0 <?= $filterVehicleId === 0 ? 'bg-gradient-to-r from-blue-600 to-sky-500 text-white shadow-sm' : 'bg-surface text-charcoal-secondary hover:bg-canvas border border-charcoal-border' ?>">
                ดูทุกคัน (<?= count($vehicles) ?>)
            </a>

            <?php foreach ($vehicles as $v): 
                $isActiveTab = $filterVehicleId === $v['id'];
            ?>
                <a href="?trip_id=<?= $selectedTripId ?>&vehicle_id=<?= $v['id'] ?>" 
                   class="app-tap px-4 py-2 rounded-full font-semibold transition shrink-0 <?= $isActiveTab ? 'bg-gradient-to-r from-blue-600 to-sky-500 text-white shadow-sm' : 'bg-surface text-charcoal-secondary hover:bg-canvas border border-charcoal-border' ?>">
                    <span><?= clean($v['name']) ?></span>
                    <span class="text-xs <?= $isActiveTab ? 'text-blue-100' : 'text-charcoal-muted' ?> ml-1">(<?= $v['booked_count'] ?>/<?= $v['total_seats'] ?>)</span>
                </a>
            <?php endforeach; ?>
        </div>

        <!-- CTA Button (Pill Gradient) -->
        <a href="/index.php?trip_id=<?= $selectedTripId ?>" class="app-tap hidden sm:flex bg-gradient-to-r from-blue-600 to-sky-500 hover:from-blue-700 hover:to-sky-600 text-white text-xs sm:text-sm font-semibold px-5 py-2 rounded-full transition shadow-sm items-center space-x-1.5 shrink-0">
            <i class="fa-solid fa-plus text-xs"></i>
            <span>ลงชื่อเพิ่ม</span>
        </a>
    </div>

    <!-- Vehicle Passenger Directory -->
    <div class="space-y-6">
        <?php foreach ($displayVehicles as $item): 
            $v = $item['info'];
            $seatMap = $item['seats'];
            $bookedCount = $item['booked_count'];
            $totalSeats = $v['total_seats'];
            $isFull = $bookedCount >= $totalSeats;
            $availCount = max(0, $totalSeats - $bookedCount);
            $isVan = ($v['type'] === 'van');

            $vehLabel = clean($v['vehicle_type_label'] ?? ($isVan ? 'รถตู้ (10 ที่นั่ง)' : 'รถบัส ' . $totalSeats . ' ที่นั่ง'));
        ?>
            <div class="bg-surface rounded-3xl border border-charcoal-border/70 overflow-hidden shadow-card">
                
                <!-- Vehicle Header with Squircle Icon Badge & Pill Status -->
                <div class="p-4 sm:p-5 border-b border-charcoal-divider flex flex-wrap items-center justify-between gap-3 bg-canvas/40">
                    <div class="flex items-center space-x-3">
                        <div class="w-10 h-10 rounded-2xl <?= $isVan ? 'squircle-icon-mint' : 'squircle-icon-blue' ?> flex items-center justify-center shadow-sm text-sm">
                            <i class="fa-solid <?= $isVan ? 'fa-van-shuttle' : 'fa-bus' ?>"></i>
                        </div>
                        <div>
                            <span class="font-bold text-charcoal text-sm sm:text-base block"><?= clean($v['name']) ?></span>
                            <span class="text-xs text-charcoal-muted font-medium block">
                                <?= $vehLabel ?>
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center space-x-3">
                        <div class="text-xs sm:text-sm font-medium">
                            <?php if ($isFull): ?>
                                <span class="text-rose-700 bg-rose-50 px-3 py-1 rounded-full border border-rose-200 font-bold text-xs">● เต็มแล้ว (<?= $totalSeats ?> คน)</span>
                            <?php else: ?>
                                <span class="text-emerald-700 bg-emerald-50 px-3 py-1 rounded-full border border-emerald-200 font-bold text-xs">● ว่างอีก <?= $availCount ?> ที่นั่ง</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Mobile Passenger List View (block md:hidden: Fits 100% Mobile Screen, Large Legible Typography, No Horizontal Scroll) -->
                <div class="divide-y divide-charcoal-divider/60 block md:hidden">
                    <?php for ($sn = 1; $sn <= $totalSeats; $sn++): 
                        $has = isset($seatMap[$sn]);
                        $p = $has ? $seatMap[$sn] : null;
                        $fullName = $has ? formatCleanPassengerName($p) : '';

                        $isMatch = false;
                        if ($has && !empty($searchQuery)) {
                            $combined = $fullName . ' ' . $p['phone'];
                            if (mb_stripos($combined, $searchQuery) !== false) {
                                $isMatch = true;
                            }
                        }
                    ?>
                        <div class="app-list-tile p-3.5 sm:p-4 transition <?= $isMatch ? 'bg-blue-50/90 border-l-4 border-accent' : ($has ? 'bg-surface' : 'bg-canvas/20') ?>">
                            <div class="flex items-center justify-between gap-3">
                                <!-- Left: Seat Squircle Badge & Passenger Info -->
                                <div class="flex items-center space-x-3 min-w-0 flex-1">
                                    <span class="w-10 h-10 rounded-2xl shrink-0 flex items-center justify-center font-bold text-xs sm:text-sm <?= $has ? 'bg-gradient-to-tr from-blue-500/10 to-sky-500/15 text-accent border border-accent-border/60 shadow-xs' : 'bg-slate-100 text-slate-400 border border-slate-200' ?>">
                                        <?= $sn ?>
                                    </span>

                                    <div class="min-w-0 flex-1">
                                        <?php if ($has): ?>
                                            <!-- Passenger Full Name: Large, bold, legible on all phone screens -->
                                            <div class="font-bold text-charcoal text-sm sm:text-base leading-snug break-words">
                                                <?= clean($fullName) ?>
                                            </div>

                                            <!-- Phone & Travel Meta -->
                                            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1 mt-1 text-xs text-charcoal-secondary">
                                                <?php if (!empty($p['phone'])): ?>
                                                    <?php $maskedPhone = maskPhoneNumber($p['phone']); ?>
                                                    <?php if ($isAdmin): ?>
                                                        <a href="tel:<?= clean($p['phone']) ?>" class="app-tap inline-flex items-center space-x-1 text-accent font-semibold hover:underline bg-accent-subtle/70 px-2 py-0.5 rounded-full border border-accent-border/40 text-[11px]">
                                                            <i class="fa-solid fa-phone text-[9px]"></i>
                                                            <span><?= clean($p['phone']) ?></span>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="inline-flex items-center space-x-1 text-charcoal-muted bg-canvas px-2 py-0.5 rounded-full border border-charcoal-border text-[11px]">
                                                            <i class="fa-solid fa-phone text-[9px] text-slate-400"></i>
                                                            <span><?= clean($maskedPhone) ?></span>
                                                        </span>
                                                    <?php endif; ?>
                                                <?php endif; ?>

                                                <?php if (!empty($p['travel_type'])): ?>
                                                    <span class="inline-flex items-center text-[11px] text-charcoal-muted bg-canvas px-2 py-0.5 rounded-full border border-charcoal-border">
                                                        <i class="fa-solid fa-route text-[9px] mr-1 text-slate-400"></i>
                                                        <span><?= clean($p['travel_type']) ?></span>
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <!-- Notes -->
                                            <?php if ($isAdmin && !empty($p['admin_note'])): ?>
                                                <div class="mt-1 text-[11px] text-accent bg-accent-subtle/80 border border-accent-border/60 px-2 py-0.5 rounded-md inline-block">
                                                    <i class="fa-solid fa-tag text-[9px] mr-0.5"></i> <?= clean($p['admin_note']) ?>
                                                </div>
                                            <?php elseif (!empty($p['note'])): ?>
                                                <div class="mt-1 text-[11px] text-charcoal-muted bg-canvas border border-charcoal-border px-2 py-0.5 rounded-md inline-block">
                                                    <?= clean($p['note']) ?>
                                                </div>
                                            <?php endif; ?>

                                        <?php else: ?>
                                            <div class="text-xs sm:text-sm text-slate-400 italic">
                                                - ที่นั่งว่าง -
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Right: Action Button -->
                                <div class="shrink-0 self-center">
                                    <?php if ($has): ?>
                                        <div class="flex items-center space-x-1">
                                            <span class="text-[11px] font-semibold text-emerald-700 bg-emerald-50 px-2.5 py-0.5 rounded-full border border-emerald-200">
                                                ลงชื่อแล้ว
                                            </span>
                                            <?php if ($isAdmin): ?>
                                                <button type="button" 
                                                        onclick="openCancelModal(<?= $p['id'] ?>, '<?= clean($fullName) ?>', '<?= clean($v['name']) ?>', <?= $sn ?>)"
                                                        class="app-tap text-rose-500 hover:text-rose-700 p-2 transition ml-1 rounded-full hover:bg-rose-50" title="ยกเลิกการลงชื่อ">
                                                    <i class="fa-solid fa-trash text-xs"></i>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <?php if ($isTripActive): ?>
                                            <a href="/index.php?trip_id=<?= $selectedTripId ?>" class="app-tap text-xs font-bold text-accent bg-blue-50 border border-blue-200 px-3.5 py-1.5 rounded-full hover:bg-accent hover:text-white transition flex items-center space-x-1 shadow-xs">
                                                <i class="fa-solid fa-plus text-[10px]"></i>
                                                <span>จองที่นี่</span>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-[11px] text-slate-400 font-medium px-2.5 py-0.5 rounded-full bg-slate-100">
                                                ว่าง
                                            </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>

                <!-- Desktop Passenger Table View (hidden md:block) -->
                <div class="overflow-x-auto hidden md:block">
                    <table class="w-full text-left text-xs sm:text-sm">
                        <thead class="bg-surface-muted text-charcoal-muted font-medium border-b border-charcoal-divider text-xs uppercase tracking-wider">
                            <tr>
                                <th class="py-3 px-3.5 w-16 text-center shrink-0 whitespace-nowrap">ลำดับ</th>
                                <th class="py-3 px-4 shrink-0 whitespace-nowrap">ชื่อ - ฉายา (นามสกุล)</th>
                                <th class="py-3 px-3.5 w-36 text-center shrink-0 whitespace-nowrap">เบอร์โทร</th>
                                <th class="py-3 px-3.5 shrink-0 whitespace-nowrap">การเดินทาง</th>
                                <th class="py-3 px-3.5 shrink-0 whitespace-nowrap">หมายเหตุ</th>
                                <th class="py-3 px-3.5 text-center w-24 shrink-0 whitespace-nowrap">สถานะ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-charcoal-divider">
                            <?php for ($sn = 1; $sn <= $totalSeats; $sn++): 
                                $has = isset($seatMap[$sn]);
                                $p = $has ? $seatMap[$sn] : null;
                                $fullName = $has ? formatCleanPassengerName($p) : '';

                                $isMatch = false;
                                if ($has && !empty($searchQuery)) {
                                    $combined = $fullName . ' ' . $p['phone'];
                                    if (mb_stripos($combined, $searchQuery) !== false) {
                                        $isMatch = true;
                                    }
                                }
                            ?>
                                <tr class="transition <?= $isMatch ? 'bg-accent-subtle/50 font-medium' : ($has ? 'hover:bg-surface-muted/50 bg-surface' : 'bg-surface-muted/30 text-charcoal-subtle') ?>">
                                    
                                    <td class="py-3 px-3.5 text-center font-mono font-medium text-charcoal-muted text-xs sm:text-sm">
                                        <?= $sn ?>
                                    </td>

                                    <!-- Passenger Name -->
                                    <td class="py-3 px-4 <?= $has ? 'font-medium text-charcoal text-sm' : 'italic text-charcoal-subtle text-xs sm:text-sm' ?>">
                                        <?= $has ? clean($fullName) : '- ที่นั่งว่าง -' ?>
                                    </td>

                                    <td class="py-3 px-3.5 text-center font-mono text-xs sm:text-sm text-charcoal-secondary whitespace-nowrap">
                                        <?php if ($has): ?>
                                            <?php $maskedPhone = maskPhoneNumber($p['phone']); ?>
                                            <?php if ($isAdmin): ?>
                                                <a href="tel:<?= clean($p['phone']) ?>" class="hover:text-accent hover:underline">
                                                    <?= clean($p['phone']) ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-charcoal-muted"><?= clean($maskedPhone) ?></span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-charcoal-subtle">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="py-3 px-3.5 text-xs sm:text-sm text-charcoal-secondary whitespace-nowrap">
                                        <?= $has ? clean($p['travel_type'] ?? 'เดินทางไป และ เดินทางกลับ') : '-' ?>
                                    </td>

                                    <td class="py-3 px-3.5 text-xs sm:text-sm">
                                        <?php if ($has && $isAdmin && !empty($p['admin_note'])): ?>
                                            <span class="inline-block bg-surface-muted text-charcoal border border-charcoal-border px-2 py-0.5 rounded-sm text-xs font-medium">
                                                <?= clean($p['admin_note']) ?>
                                            </span>
                                        <?php elseif ($has && !empty($p['note'])): ?>
                                            <span class="text-charcoal-secondary"><?= clean($p['note']) ?></span>
                                        <?php else: ?>
                                            <span class="text-charcoal-subtle">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="py-3 px-3.5 text-center text-xs whitespace-nowrap">
                                        <?php if ($has): ?>
                                            <div class="inline-flex items-center justify-center space-x-1.5">
                                                <span class="text-charcoal-secondary font-medium text-xs bg-surface-muted border border-charcoal-border px-2 py-0.5 rounded-sm">
                                                    ลงชื่อแล้ว
                                                </span>
                                                <button type="button" 
                                                        onclick="openCancelModal(<?= $p['id'] ?>, '<?= clean($fullName) ?>', '<?= clean($v['name']) ?>', <?= $sn ?>)"
                                                        class="text-mutedred hover:text-red-700 p-1 transition" title="ยกเลิกการลงชื่อ">
                                                    <i class="fa-solid fa-xmark text-xs"></i>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <a href="/index.php?trip_id=<?= $selectedTripId ?>" class="text-accent hover:text-accent-hover font-medium text-xs bg-surface border border-charcoal-border px-2.5 py-0.5 rounded-sm hover:bg-surface-muted transition shadow-subtle">
                                                จองที่นี่
                                            </a>
                                        <?php endif; ?>
                                    </td>

                                </tr>
                            <?php endfor; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        <?php endforeach; ?>
    </div>

</div>

<!-- Modal: Cancel Booking (Admin Only) -->
<div id="cancelModal" class="fixed inset-0 z-50 flex items-center justify-center bg-charcoal/40 backdrop-blur-sm hidden p-4">
    <div class="bg-surface rounded-3xl p-6 sm:p-7 max-w-sm w-full shadow-2xl border border-charcoal-border text-xs sm:text-sm animate-[swalSpringIn_0.3s_ease]">
        <div class="w-12 h-12 rounded-2xl bg-rose-50 text-rose-600 flex items-center justify-center mx-auto mb-3 text-xl border border-rose-200">
            <i class="fa-solid fa-triangle-exclamation"></i>
        </div>
        <h3 class="font-bold text-charcoal text-base mb-1 text-center">ยกเลิกการลงชื่อ</h3>
        <p class="text-charcoal-muted text-center mb-5 text-xs leading-relaxed">
            ยืนยันการยกเลิกการลงชื่อของ <br>
            <strong id="cancelPassengerName" class="font-bold text-charcoal text-sm"></strong> <br>
            <span class="text-[11px] text-rose-600 font-medium mt-1 inline-block">* สิทธิ์เฉพาะผู้ดูแลระบบ (Admin) *</span>
        </p>

        <form id="cancelForm" onsubmit="submitCancel(event)" class="space-y-3.5">
            <input type="hidden" id="cancelBookingId" name="booking_id" value="">

            <div class="flex gap-2.5 pt-1">
                <button type="button" onclick="closeCancelModal()" class="flex-1 py-2.5 text-charcoal-secondary bg-canvas border border-charcoal-border hover:bg-slate-200 rounded-full font-semibold transition">ปิด</button>
                <button type="submit" id="confirmCancelBtn" class="flex-1 py-2.5 text-white bg-rose-600 hover:bg-rose-700 rounded-full font-semibold transition shadow-sm">ยืนยันยกเลิก</button>
            </div>
        </form>
    </div>
</div>

<script>
    const searchInput = document.getElementById('liveSearchInput');
    const dropdown = document.getElementById('suggestionDropdown');
    const tripId = document.getElementById('tripIdInput').value;
    let debounceTimer = null;

    searchInput.addEventListener('input', function() {
        const query = this.value.trim();
        clearTimeout(debounceTimer);

        if (query.length === 0) {
            dropdown.classList.add('hidden');
            dropdown.innerHTML = '';
            return;
        }

        debounceTimer = setTimeout(() => {
            fetchSuggestions(query);
        }, 180);
    });

    // Skeleton Loading Shimmer for Instant Visual Feedback (Item 4)
    function renderSkeletonSuggestions() {
        let skeletonHtml = '<div class="divide-y divide-charcoal-border/30 p-2">';
        for (let i = 0; i < 3; i++) {
            skeletonHtml += `
                <div class="skeleton-row py-2.5">
                    <div class="skeleton skeleton-avatar w-7 h-7"></div>
                    <div class="flex-1 space-y-1.5 min-w-0">
                        <div class="skeleton skeleton-text w-3/4 h-3"></div>
                        <div class="skeleton skeleton-text w-1/3 h-2.5"></div>
                    </div>
                    <div class="skeleton skeleton-badge w-14 h-5"></div>
                </div>
            `;
        }
        skeletonHtml += '</div>';
        dropdown.innerHTML = skeletonHtml;
        dropdown.classList.remove('hidden');
    }

    async function fetchSuggestions(query) {
        renderSkeletonSuggestions();
        try {
            const res = await fetch(`/api/suggestions.php?q=${encodeURIComponent(query)}&trip_id=${tripId}`);
            const data = await res.json();
            renderSuggestions(data.results || [], query);
        } catch (e) {
            dropdown.innerHTML = '<div class="p-3 text-center text-xs text-mutedred">เกิดข้อผิดพลาดในการค้นหา</div>';
        }
    }


    function renderSuggestions(results, query) {
        if (results.length === 0) {
            dropdown.innerHTML = `
                <div class="p-3 text-center text-xs text-slate-400">
                    ไม่พบชื่อที่ตรงกับ "${escapeHtml(query)}"
                </div>
            `;
            dropdown.classList.remove('hidden');
            return;
        }

        let html = '<div class="divide-y divide-charcoal-border/50 text-xs sm:text-sm">';
        results.forEach(r => {
            html += `
                <div class="p-3 hover:bg-surface-muted cursor-pointer transition flex items-center justify-between gap-3" 
                     onclick="selectSuggestion('${escapeHtml(r.name)}')">
                    <div>
                        <div class="font-semibold text-charcoal">${highlightMatch(r.name, query)}</div>
                        <div class="text-xs text-charcoal-subtle">เบอร์: ${escapeHtml(r.phone || '-')}</div>
                    </div>
                    <div class="text-right">
                        <span class="inline-block bg-charcoal-50 text-charcoal font-semibold text-xs px-2 py-0.5 rounded-sm border border-charcoal-border">
                            ${escapeHtml(r.vehicle_name)}
                        </span>
                        <div class="text-[11px] text-charcoal-subtle">ที่นั่งเบอร์ ${r.seat_number}</div>
                    </div>
                </div>
            `;
        });
        html += '</div>';

        dropdown.innerHTML = html;
        dropdown.classList.remove('hidden');
    }

    function selectSuggestion(name) {
        searchInput.value = name;
        dropdown.classList.add('hidden');
        document.getElementById('searchForm').submit();
    }

    function highlightMatch(text, query) {
        if (!query) return escapeHtml(text);
        const regex = new RegExp(`(${query.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
        return escapeHtml(text).replace(regex, '<mark class="bg-accent-subtle text-accent px-1 rounded-xs font-semibold">$1</mark>');
    }

    function escapeHtml(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.classList.add('hidden');
        }
    });

    function openCancelModal(bookingId, passengerName) {
        if (!<?= isAdminLoggedIn() ? 'true' : 'false' ?>) {
            Swal.fire({
                icon: 'warning',
                title: 'สิทธิ์เฉพาะผู้ดูแลระบบ',
                text: 'ขออภัย สิทธิ์ในการยกเลิกการลงชื่อเฉพาะผู้ดูแลระบบ (Admin) เท่านั้น หากต้องการยกเลิกกรุณาติดต่อผู้ดูแลระบบ'
            });
            return;
        }
        document.getElementById('cancelBookingId').value = bookingId;
        document.getElementById('cancelPassengerName').textContent = passengerName;
        document.getElementById('cancelModal').classList.remove('hidden');
    }
    function closeCancelModal() { document.getElementById('cancelModal').classList.add('hidden'); }

    async function submitCancel(event) {
        event.preventDefault();
        const btn = document.getElementById('confirmCancelBtn');
        btn.disabled = true;

        const form = document.getElementById('cancelForm');
        const formData = new FormData(form);
        formData.append('action', 'cancel');

        try {
            const res = await fetch('/api/booking.php', { method: 'POST', body: formData });
            const data = await res.json();
            if (data.success) {
                closeCancelModal();
                await Swal.fire({
                    icon: 'success',
                    title: 'ยกเลิกการลงชื่อสำเร็จ',
                    text: data.message,
                    timer: 2000,
                    showConfirmButton: false
                });
                location.reload();
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'ไม่สามารถยกเลิกได้',
                    text: data.message || 'เกิดข้อผิดพลาด'
                });
                btn.disabled = false;
            }
        } catch (e) {
            Swal.fire({
                icon: 'error',
                title: 'ข้อผิดพลาดการเชื่อมต่อ',
                text: 'เกิดข้อผิดพลาดในการเชื่อมต่อเซิร์ฟเวอร์'
            });
            btn.disabled = false;
        }
    }

</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
