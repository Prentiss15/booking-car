<?php
// check.php - หน้าตรวจสอบรายชื่อ (ดีไซน์สากล เรียบหรู สะอาดตา พร้อมค้นหา Real-time และระบบมอนิเตอร์สด)
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDb();
seedDemoDataIfEmpty($db);

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
    $cleanPhone = preg_replace('/[^0-9]/', '', $searchQuery);
    $sql = "
        SELECT b.*, v.name as vehicle_name, v.vehicle_number, v.type as vehicle_type, v.vehicle_type_label
        FROM bookings b
        JOIN vehicles v ON b.vehicle_id = v.id
        WHERE b.trip_id = ? AND (
            b.passenger_name LIKE ? OR 
            b.first_name LIKE ? OR 
            b.last_name_or_nickname LIKE ?
    ";
    $params = [$selectedTrip['id'], "%{$searchQuery}%", "%{$searchQuery}%", "%{$searchQuery}%"];
    if (!empty($cleanPhone)) {
        $sql .= " OR REPLACE(REPLACE(b.phone, '-', ''), ' ', '') LIKE ?";
        $params[] = "%{$cleanPhone}%";
    }
    $sql .= ") ORDER BY b.vehicle_id ASC, b.seat_number ASC";
    
    $stmtSearch = $db->prepare($sql);
    $stmtSearch->execute($params);
    $myBookings = $stmtSearch->fetchAll();
}

$displayVehicles = [];
foreach ($vehicles as $v) {
    if ($filterVehicleId > 0 && $v['id'] !== $filterVehicleId) {
        continue;
    }

    $stmtB = $db->prepare("SELECT * FROM bookings WHERE vehicle_id = ? ORDER BY seat_number ASC");
    $stmtB->execute([$v['id']]);
    $bList = $stmtB->fetchAll();

    $seatMap = [];
    foreach ($bList as $b) {
        $seatMap[$b['seat_number']] = $b;
    }

    $displayVehicles[] = [
        'info' => $v,
        'seats' => $seatMap,
        'booked_count' => count($bList)
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

define('APP_TITLE', 'ตรวจสอบรายชื่อ');
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-6xl mx-auto px-4 sm:px-6 py-6 sm:py-8">

    <!-- Top Schedule & Information Bar -->
    <?php if ($selectedTrip): ?>
        <div class="bg-white rounded-2xl p-5 sm:p-6 border border-slate-200/90 shadow-xs mb-5">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
                
                <div class="space-y-2">
                    <div class="flex items-center space-x-2 text-xs sm:text-sm text-slate-500 font-medium">
                        <span class="shrink-0 whitespace-nowrap">กำหนดการเดินทาง:</span>
                        <strong class="text-slate-900 font-bold"><?= formatThaiDate($selectedTrip['trip_date']) ?></strong>
                    </div>
                    <h1 class="text-xl sm:text-2xl font-bold text-slate-900 tracking-tight">
                        ตรวจสอบรายชื่อผู้ร่วมเดินทาง
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-500">
                        <?= clean($selectedTrip['title']) ?>
                    </p>

                    <!-- การตัดคำ: ใส่ shrink-0 whitespace-nowrap ไม่ให้ ขาไป / ขากลับ ตัดคำ -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 pt-2 text-xs sm:text-sm">
                        <div class="flex items-center space-x-2 bg-slate-50 border border-slate-200/80 px-3.5 py-2 rounded-xl text-slate-700">
                            <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shrink-0"></span>
                            <span class="font-bold text-slate-900 shrink-0 whitespace-nowrap">ขาไป:</span>
                            <span class="text-slate-700 leading-snug"><?= clean($selectedTrip['pickup_time_info'] ?? 'ขึ้นรถ 08.00 น.') ?></span>
                        </div>
                        <div class="flex items-center space-x-2 bg-slate-50 border border-slate-200/80 px-3.5 py-2 rounded-xl text-slate-700">
                            <span class="w-2.5 h-2.5 rounded-full bg-blue-500 shrink-0"></span>
                            <span class="font-bold text-slate-900 shrink-0 whitespace-nowrap">ขากลับ:</span>
                            <span class="text-slate-700 leading-snug"><?= clean($selectedTrip['return_time_info'] ?? 'ขึ้นรถ 16.00 น.') ?></span>
                        </div>
                    </div>

                    <?php if (!empty($selectedTrip['notice_red'])): ?>
                        <div class="mt-2 text-xs sm:text-sm font-medium text-rose-800 bg-rose-50 border border-rose-200 p-3 rounded-xl flex items-start space-x-2">
                            <i class="fa-solid fa-circle-info text-rose-500 mt-0.5 shrink-0"></i>
                            <span class="leading-relaxed"><?= clean($selectedTrip['notice_red']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Stats summary -->
                <div class="bg-slate-800 text-white p-5 rounded-2xl min-w-[220px] shadow-sm border border-slate-700">
                    <div class="text-xs text-slate-400 font-medium uppercase tracking-wider">ลงชื่อแล้วทั้งหมด</div>
                    <div class="text-3xl font-bold mt-1 text-white">
                        <?= number_format($totalBooked) ?> <span class="text-sm font-normal text-slate-400">/ <?= $totalCapacity ?> คน</span>
                    </div>
                    <div class="border-t border-slate-700/80 mt-3 pt-2 flex items-center justify-between text-xs sm:text-sm text-slate-300">
                        <span class="whitespace-nowrap">ที่นั่งว่างคงเหลือ:</span>
                        <span class="font-bold text-emerald-400 text-sm whitespace-nowrap"><?= max(0, $totalCapacity - $totalBooked) ?> ที่นั่ง</span>
                    </div>
                </div>

            </div>
        </div>
    <?php endif; ?>

    <!-- Item 8: Live Auto-Refresh Monitoring Bar (มอนิเตอร์สด) -->
    <div class="bg-white rounded-2xl p-4 border border-slate-200/90 shadow-xs mb-5 flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center space-x-3">
            <span id="liveDot" class="w-3.5 h-3.5 rounded-full bg-emerald-500 live-dot"></span>
            <div>
                <div class="flex items-center space-x-2">
                    <span class="text-xs sm:text-sm font-bold text-slate-900">ระบบมอนิเตอร์สด (Auto-Refresh)</span>
                    <span id="refreshTimerBadge" class="text-[11px] bg-emerald-50 text-emerald-700 font-semibold px-2.5 py-0.5 rounded-full border border-emerald-200">
                        เปิดมอนิเตอร์
                    </span>
                </div>
                <span id="lastUpdatedTime" class="text-xs text-slate-500 block mt-0.5">
                    อัปเดตล่าสุด: กำลังโหลด...
                </span>
            </div>
        </div>

        <div class="flex items-center space-x-2 w-full sm:w-auto justify-between sm:justify-end">
            <div class="flex items-center space-x-1.5">
                <label for="refreshInterval" class="text-xs text-slate-600 font-medium whitespace-nowrap">รอบอัปเดต:</label>
                <select id="refreshInterval" onchange="setLiveRefresh(this.value)" class="text-xs sm:text-sm font-medium bg-slate-50 border border-slate-300 rounded-lg px-2.5 py-1.5 text-slate-800 outline-none focus:ring-1 focus:ring-slate-800">
                    <option value="0">ปิดมอนิเตอร์</option>
                    <option value="15">ทุก 15 วินาที</option>
                    <option value="30" selected>ทุก 30 วินาที</option>
                    <option value="60">ทุก 1 นาที</option>
                </select>
            </div>
            <button type="button" onclick="manualRefreshNow()" class="px-3.5 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs sm:text-sm font-semibold flex items-center space-x-1.5 transition" title="กดเพื่อดึงข้อมูลใหม่ทันที">
                <i class="fa-solid fa-arrows-rotate" id="refreshIcon"></i>
                <span class="whitespace-nowrap">รีเฟรช</span>
            </button>
        </div>
    </div>

    <!-- Search Section (Clean Modern UI with Real-time Autocomplete) -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-xs mb-5 relative">
        <div class="max-w-2xl">
            <h2 class="text-sm sm:text-base font-bold text-slate-900 mb-1 flex items-center space-x-2">
                <i class="fa-solid fa-magnifying-glass text-slate-500 text-xs sm:text-sm"></i>
                <span>ค้นหาชื่อเพื่อดูคันรถและที่นั่ง</span>
            </h2>
            <p class="text-xs sm:text-sm text-slate-500 mb-3">
                พิมพ์ชื่อ, ฉายา หรือเบอร์โทรศัพท์ (ระบบจะแสดงชื่อแนะนำให้ทันที)
            </p>

            <div class="relative">
                <form method="GET" action="/check.php" id="searchForm" class="flex flex-col sm:flex-row gap-2">
                    <input type="hidden" name="trip_id" id="tripIdInput" value="<?= $selectedTripId ?>">
                    
                    <div class="relative flex-grow">
                        <input type="text" 
                               id="liveSearchInput"
                               name="q" 
                               autocomplete="off"
                               value="<?= clean($searchQuery) ?>" 
                               placeholder="เช่น บุญช่วย, กตญาโณ, 082..." 
                               class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-300 rounded-xl text-sm sm:text-base text-slate-900 placeholder-slate-400 focus:bg-white focus:ring-2 focus:ring-slate-800 focus:border-slate-800 outline-none">
                        <i class="fa-solid fa-search absolute left-3.5 top-3.5 text-slate-400 text-sm"></i>
                    </div>

                    <div class="flex gap-2">
                        <button type="submit" class="bg-slate-800 hover:bg-slate-700 text-white font-semibold px-5 py-2.5 rounded-xl text-xs sm:text-sm transition shadow-xs flex-1 sm:flex-initial">
                            ค้นหา
                        </button>
                        <?php if (!empty($searchQuery)): ?>
                            <a href="/check.php?trip_id=<?= $selectedTripId ?>" class="bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold px-4 py-2.5 rounded-xl text-xs sm:text-sm flex items-center justify-center transition">
                                ล้าง
                            </a>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Dropdown Autocomplete -->
                <div id="suggestionDropdown" class="absolute left-0 right-0 top-full mt-1.5 bg-white text-slate-800 rounded-xl shadow-xl border border-slate-200 overflow-hidden z-30 hidden max-h-72 overflow-y-auto"></div>
            </div>
        </div>

        <!-- Search Result Cards -->
        <?php if (!empty($searchQuery)): ?>
            <div class="mt-4 pt-4 border-t border-slate-100">
                <?php if (!empty($myBookings)): ?>
                    <div class="space-y-2">
                        <span class="text-xs sm:text-sm font-semibold text-slate-800 block">ผลการค้นหา (พบ <?= count($myBookings) ?> คน):</span>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <?php foreach ($myBookings as $b): 
                                $bFullName = formatCleanPassengerName($b);
                            ?>
                                <div class="bg-slate-50/80 p-4 rounded-xl border border-slate-200 flex items-center justify-between">
                                    <div>
                                        <div class="font-bold text-slate-900 text-sm sm:text-base"><?= clean($bFullName) ?></div>
                                        <div class="text-xs sm:text-sm text-slate-600 mt-1 flex flex-wrap items-center gap-1.5">
                                            <span>คันรถ: <strong class="text-slate-900"><?= clean($b['vehicle_name']) ?></strong></span>
                                            <span>•</span>
                                            <span>ที่นั่งที่: <strong class="text-blue-700 font-bold"><?= $b['seat_number'] ?></strong></span>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <button type="button" 
                                                onclick="openCancelModal(<?= $b['id'] ?>, '<?= clean($bFullName) ?>', '<?= clean($b['vehicle_name']) ?>', <?= $b['seat_number'] ?>)"
                                                class="text-xs font-semibold text-rose-600 hover:text-rose-800 hover:underline px-2.5 py-1.5 bg-rose-50 rounded-lg border border-rose-200">
                                            ยกเลิกการจอง
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-xs sm:text-sm text-slate-500 p-2">
                        ไม่พบรายชื่อที่ตรงกับ "<?= clean($searchQuery) ?>"
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Vehicle Filter Tabs -->
    <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
        <div class="flex flex-wrap items-center gap-2 text-xs sm:text-sm">
            <a href="?trip_id=<?= $selectedTripId ?>" 
               class="px-3.5 py-2 rounded-xl font-semibold transition <?= $filterVehicleId === 0 ? 'bg-slate-800 text-white shadow-xs' : 'bg-white text-slate-700 hover:bg-slate-100 border border-slate-200' ?>">
                ดูทุกคัน (<?= count($vehicles) ?>)
            </a>

            <?php foreach ($vehicles as $v): 
                $isActiveTab = $filterVehicleId === $v['id'];
            ?>
                <a href="?trip_id=<?= $selectedTripId ?>&vehicle_id=<?= $v['id'] ?>" 
                   class="px-3.5 py-2 rounded-xl font-semibold transition <?= $isActiveTab ? 'bg-slate-800 text-white shadow-xs' : 'bg-white text-slate-700 hover:bg-slate-100 border border-slate-200' ?>">
                    <span><?= clean($v['name']) ?></span>
                    <span class="text-xs <?= $isActiveTab ? 'text-slate-300' : 'text-slate-400' ?> ml-1">(<?= $v['booked_count'] ?>/<?= $v['total_seats'] ?>)</span>
                </a>
            <?php endforeach; ?>
        </div>

        <a href="/index.php?trip_id=<?= $selectedTripId ?>" class="bg-emerald-600 hover:bg-emerald-700 text-white text-xs sm:text-sm font-semibold px-4 py-2 rounded-xl transition shadow-xs flex items-center space-x-1.5">
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

            $vehLabel = clean($v['vehicle_type_label'] ?? ($v['type'] === 'van' ? 'รถตู้ (10 ที่นั่ง)' : 'รถบัส ' . $totalSeats . ' ที่นั่ง'));
        ?>
            <div class="bg-white rounded-2xl border border-slate-200/90 overflow-hidden shadow-xs">
                
                <!-- Vehicle Header -->
                <div class="p-4 sm:p-5 border-b border-slate-100 flex items-center justify-between bg-slate-50/80">
                    <div class="flex items-center space-x-2.5">
                        <span class="font-bold text-slate-900 text-sm sm:text-base"><?= clean($v['name']) ?></span>
                        <span class="text-xs text-slate-600 font-medium px-2.5 py-0.5 bg-white border border-slate-200 rounded-lg">
                            <?= $vehLabel ?>
                        </span>
                    </div>

                    <div class="text-xs sm:text-sm font-bold">
                        <?php if ($isFull): ?>
                            <span class="text-rose-600">● เต็มแล้ว (<?= $totalSeats ?> คน)</span>
                        <?php else: ?>
                            <span class="text-emerald-700">● ว่างอีก <?= $availCount ?> ที่นั่ง</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Table View -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs sm:text-sm">
                        <thead class="bg-slate-50/70 text-slate-500 font-semibold border-b border-slate-200 text-xs uppercase tracking-wider">
                            <tr>
                                <th class="py-3 px-3.5 w-16 text-center shrink-0 whitespace-nowrap">ลำดับ</th>
                                <th class="py-3 px-4 shrink-0 whitespace-nowrap">ชื่อ - ฉายา (นามสกุล)</th>
                                <th class="py-3 px-3.5 w-36 text-center shrink-0 whitespace-nowrap">เบอร์โทร</th>
                                <th class="py-3 px-3.5 shrink-0 whitespace-nowrap">การเดินทาง</th>
                                <th class="py-3 px-3.5 shrink-0 whitespace-nowrap">หมายเหตุ</th>
                                <th class="py-3 px-3.5 text-center w-24 shrink-0 whitespace-nowrap">สถานะ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
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
                                <tr class="transition <?= $isMatch ? 'bg-amber-100/90 font-semibold' : ($has ? 'hover:bg-slate-50/80 bg-white' : 'bg-slate-50/30 text-slate-300') ?>">
                                    
                                    <td class="py-3 px-3.5 text-center font-mono font-bold text-slate-600 text-xs sm:text-sm">
                                        <?= $sn ?>
                                    </td>

                                    <!-- Passenger Name: คำนำหน้าติดกับชื่อเสมอ ไม่เว้นวรรค (Item 5) -->
                                    <td class="py-3 px-4 font-semibold <?= $has ? 'text-slate-900 text-sm sm:text-base' : 'italic text-slate-400 text-xs sm:text-sm' ?>">
                                        <?= $has ? clean($fullName) : '- ที่นั่งว่าง -' ?>
                                    </td>

                                    <td class="py-3 px-3.5 text-center font-mono text-xs sm:text-sm text-slate-700 whitespace-nowrap">
                                        <?php if ($has): ?>
                                            <a href="tel:<?= clean($p['phone']) ?>" class="hover:text-blue-600 hover:underline">
                                                <?= clean($p['phone']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-slate-300">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="py-3 px-3.5 text-xs sm:text-sm text-slate-600 whitespace-nowrap">
                                        <?= $has ? clean($p['travel_type'] ?? 'เดินทางไป และ เดินทางกลับ') : '-' ?>
                                    </td>

                                    <td class="py-3 px-3.5 text-xs sm:text-sm">
                                        <?php if ($has && !empty($p['admin_note'])): ?>
                                            <span class="inline-block bg-amber-50 text-amber-900 border border-amber-300 px-2 py-0.5 rounded text-xs font-semibold">
                                                <?= clean($p['admin_note']) ?>
                                            </span>
                                        <?php elseif ($has && !empty($p['note'])): ?>
                                            <span class="text-slate-600"><?= clean($p['note']) ?></span>
                                        <?php else: ?>
                                            <span class="text-slate-300">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Item 5: แก้คำผิด จองที่นี้ เป็น จองที่นี่ -->
                                    <td class="py-3 px-3.5 text-center text-xs whitespace-nowrap">
                                        <?php if ($has): ?>
                                            <span class="text-emerald-700 font-bold text-xs bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded-md">
                                                ลงชื่อแล้ว
                                            </span>
                                        <?php else: ?>
                                            <a href="/index.php?trip_id=<?= $selectedTripId ?>" class="text-blue-600 hover:text-blue-800 font-bold text-xs bg-blue-50 border border-blue-200 px-2 py-0.5 rounded-md hover:bg-blue-100 transition">
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

<!-- Modal: Cancel Booking -->
<div id="cancelModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs hidden p-4">
    <div class="bg-white rounded-2xl p-6 max-w-sm w-full shadow-2xl border border-slate-200 text-xs sm:text-sm">
        <h3 class="font-bold text-slate-900 text-base mb-1 text-center">ยืนยันการยกเลิก</h3>
        <p class="text-slate-500 text-center mb-4">ยกเลิกการลงชื่อของ <span id="cancelPassengerName" class="font-bold text-slate-800"></span></p>

        <form id="cancelForm" onsubmit="submitCancel(event)" class="space-y-3.5">
            <input type="hidden" id="cancelBookingId" name="booking_id" value="">
            <div>
                <label class="block text-slate-700 font-semibold mb-1">เบอร์โทรศัพท์ที่ใช้ลงชื่อ เพื่อยืนยัน</label>
                <input type="tel" id="phone_confirm" name="phone_confirm" required placeholder="0812345678" class="w-full px-3.5 py-2 bg-slate-50 border border-slate-300 rounded-xl text-sm outline-none focus:ring-2 focus:ring-slate-800">
            </div>

            <div class="flex gap-2 pt-2">
                <button type="button" onclick="closeCancelModal()" class="flex-1 py-2 text-slate-700 bg-slate-100 hover:bg-slate-200 rounded-xl font-semibold transition">ปิด</button>
                <button type="submit" id="confirmCancelBtn" class="flex-1 py-2 text-white bg-rose-600 hover:bg-rose-700 rounded-xl font-semibold transition shadow-xs">ยืนยันยกเลิก</button>
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

    async function fetchSuggestions(query) {
        try {
            const res = await fetch(`/api/suggestions.php?q=${encodeURIComponent(query)}&trip_id=${tripId}`);
            const data = await res.json();
            renderSuggestions(data.results || [], query);
        } catch (e) {
            console.error(e);
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

        let html = '<div class="divide-y divide-slate-100 text-xs sm:text-sm">';
        results.forEach(r => {
            html += `
                <div class="p-3 hover:bg-slate-50 cursor-pointer transition flex items-center justify-between gap-3" 
                     onclick="selectSuggestion('${escapeHtml(r.name)}')">
                    <div>
                        <div class="font-semibold text-slate-900">${highlightMatch(r.name, query)}</div>
                        <div class="text-xs text-slate-400">เบอร์: ${r.phone}</div>
                    </div>
                    <div class="text-right">
                        <span class="inline-block bg-slate-100 text-slate-700 font-semibold text-xs px-2 py-0.5 rounded">
                            ${escapeHtml(r.vehicle_name)}
                        </span>
                        <div class="text-[11px] text-slate-400">ที่นั่งเบอร์ ${r.seat_number}</div>
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
        return escapeHtml(text).replace(regex, '<mark class="bg-amber-100 text-slate-900 px-0.5 rounded font-semibold">$1</mark>');
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
        if (!<?= $isTripActive ? 'true' : 'false' ?> && !<?= isAdminLoggedIn() ? 'true' : 'false' ?>) {
            alert('รอบการเดินทางนี้ปิดรับและตัดยอดแล้ว ไม่อนุญาตให้ถอดชื่อออกเอง หากมีเหตุจำเป็นกรุณาติดต่อผู้ดูแลระบบ');
            return;
        }
        document.getElementById('cancelBookingId').value = bookingId;
        document.getElementById('cancelPassengerName').textContent = passengerName;
        document.getElementById('phone_confirm').value = '';
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
                alert(data.message);
                location.reload();
            } else {
                alert(data.message || 'เกิดข้อผิดพลาด');
                btn.disabled = false;
            }
        } catch (e) {
            alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
            btn.disabled = false;
        }
    }

    // Item 8: Live Monitoring Auto-Refresh Logic
    let refreshTimer = null;
    let countdown = 30;

    function updateLastTime() {
        const now = new Date();
        const timeStr = now.toLocaleTimeString('th-TH', { hour: '2-digit', minute: '2-digit', second: '2-digit' }) + ' น.';
        const el = document.getElementById('lastUpdatedTime');
        if (el) el.textContent = 'อัปเดตล่าสุด: ' + timeStr;
    }

    function setLiveRefresh(seconds) {
        seconds = parseInt(seconds);
        localStorage.setItem('car_check_refresh_interval', seconds);
        if (refreshTimer) clearInterval(refreshTimer);
        
        const dot = document.getElementById('liveDot');
        const badge = document.getElementById('refreshTimerBadge');
        
        if (seconds <= 0) {
            if (dot) dot.className = 'w-3.5 h-3.5 rounded-full bg-slate-300';
            if (badge) {
                badge.textContent = 'ปิดมอนิเตอร์';
                badge.className = 'text-[11px] bg-slate-100 text-slate-500 font-medium px-2.5 py-0.5 rounded-full border border-slate-200';
            }
            return;
        }

        if (dot) dot.className = 'w-3.5 h-3.5 rounded-full bg-emerald-500 live-dot';
        if (badge) {
            badge.textContent = `อัปเดตทุก ${seconds} วิ`;
            badge.className = 'text-[11px] bg-emerald-50 text-emerald-700 font-semibold px-2.5 py-0.5 rounded-full border border-emerald-200';
        }

        countdown = seconds;
        refreshTimer = setInterval(() => {
            countdown--;
            if (countdown <= 0) {
                manualRefreshNow();
            }
        }, 1000);
    }

    function manualRefreshNow() {
        const icon = document.getElementById('refreshIcon');
        if (icon) icon.classList.add('fa-spin');
        sessionStorage.setItem('car_check_scroll_pos', window.scrollY);
        window.location.reload();
    }

    window.addEventListener('DOMContentLoaded', () => {
        updateLastTime();
        const savedScroll = sessionStorage.getItem('car_check_scroll_pos');
        if (savedScroll !== null) {
            window.scrollTo(0, parseInt(savedScroll));
            sessionStorage.removeItem('car_check_scroll_pos');
        }
        const savedInterval = localStorage.getItem('car_check_refresh_interval') !== null 
            ? parseInt(localStorage.getItem('car_check_refresh_interval')) 
            : 30; // default 30s
        const selectEl = document.getElementById('refreshInterval');
        if (selectEl) selectEl.value = savedInterval;
        setLiveRefresh(savedInterval);
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
