<?php
// check.php - หน้าตรวจสอบรายชื่อ (ดีไซน์สากล เรียบหรู สะอาดตา พร้อมค้นหา Real-time)
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

define('APP_TITLE', 'ตรวจสอบรายชื่อ');
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-6xl mx-auto px-4 sm:px-6 py-8">

    <!-- Top Schedule & Information Bar -->
    <?php if ($selectedTrip): ?>
        <div class="bg-white rounded-2xl p-6 border border-slate-200/90 shadow-xs mb-6">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-5">
                
                <div class="space-y-1.5">
                    <div class="flex items-center space-x-2 text-xs text-slate-500 font-medium">
                        <span>กำหนดการเดินทาง:</span>
                        <strong class="text-slate-800 font-semibold"><?= formatThaiDate($selectedTrip['trip_date']) ?></strong>
                    </div>
                    <h1 class="text-2xl font-bold text-slate-900 tracking-tight">
                        ตรวจสอบรายชื่อผู้ร่วมเดินทาง
                    </h1>
                    <p class="text-xs text-slate-500">
                        <?= clean($selectedTrip['title']) ?>
                    </p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 pt-2 text-xs">
                        <div class="flex items-center space-x-2 bg-slate-50 border border-slate-100 px-3 py-1.5 rounded-lg text-slate-700">
                            <span class="w-2 h-2 rounded-full bg-emerald-600"></span>
                            <span class="font-medium text-slate-900">ขาไป:</span>
                            <span><?= clean($selectedTrip['pickup_time_info'] ?? 'ขึ้นรถ 08.00 น.') ?></span>
                        </div>
                        <div class="flex items-center space-x-2 bg-slate-50 border border-slate-100 px-3 py-1.5 rounded-lg text-slate-700">
                            <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                            <span class="font-medium text-slate-900">ขากลับ:</span>
                            <span><?= clean($selectedTrip['return_time_info'] ?? 'ขึ้นรถ 16.00 น.') ?></span>
                        </div>
                    </div>

                    <?php if (!empty($selectedTrip['notice_red'])): ?>
                        <div class="mt-2 text-xs font-medium text-rose-700 bg-rose-50 border border-rose-200/80 p-2 rounded-lg">
                            <i class="fa-solid fa-circle-info mr-1 text-rose-500"></i>
                            <span><?= clean($selectedTrip['notice_red']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Stats summary -->
                <div class="bg-slate-900 text-white p-4 sm:p-5 rounded-xl min-w-[200px] shadow-xs">
                    <div class="text-[11px] text-slate-400 font-medium uppercase tracking-wider">ลงชื่อแล้วทั้งหมด</div>
                    <div class="text-2xl font-bold mt-0.5">
                        <?= number_format($totalBooked) ?> <span class="text-xs font-normal text-slate-400">/ <?= $totalCapacity ?> คน</span>
                    </div>
                    <div class="border-t border-slate-800 mt-2 pt-1.5 flex items-center justify-between text-xs text-slate-300">
                        <span>ที่นั่งว่างคงเหลือ:</span>
                        <span class="font-semibold text-emerald-400"><?= max(0, $totalCapacity - $totalBooked) ?> ที่นั่ง</span>
                    </div>
                </div>

            </div>
        </div>
    <?php endif; ?>

    <!-- Search Section (Clean International UI with Real-time Autocomplete) -->
    <div class="bg-white rounded-2xl p-5 border border-slate-200/90 shadow-xs mb-6 relative">
        <div class="max-w-2xl">
            <h2 class="text-sm font-bold text-slate-900 mb-0.5 flex items-center space-x-2">
                <i class="fa-solid fa-magnifying-glass text-slate-500 text-xs"></i>
                <span>ค้นหาชื่อเพื่อดูคันรถและที่นั่ง</span>
            </h2>
            <p class="text-xs text-slate-400 mb-3">
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
                               class="w-full pl-9 pr-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:bg-white focus:ring-1 focus:ring-slate-900 focus:border-slate-900 outline-none">
                        <i class="fa-solid fa-search absolute left-3 top-2.5 text-slate-400 text-xs"></i>
                    </div>

                    <div class="flex gap-1.5">
                        <button type="submit" class="bg-slate-900 hover:bg-slate-800 text-white font-medium px-4 py-2 rounded-lg text-xs transition">
                            ค้นหา
                        </button>
                        <?php if (!empty($searchQuery)): ?>
                            <a href="/check.php?trip_id=<?= $selectedTripId ?>" class="bg-slate-100 hover:bg-slate-200 text-slate-600 font-medium px-3 py-2 rounded-lg text-xs flex items-center justify-center transition">
                                ล้าง
                            </a>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- Dropdown Autocomplete -->
                <div id="suggestionDropdown" class="absolute left-0 right-0 top-full mt-1.5 bg-white text-slate-800 rounded-xl shadow-lg border border-slate-200 overflow-hidden z-40 hidden max-h-72 overflow-y-auto"></div>
            </div>
        </div>

        <!-- Search Result Cards -->
        <?php if (!empty($searchQuery)): ?>
            <div class="mt-4 pt-4 border-t border-slate-100">
                <?php if (!empty($myBookings)): ?>
                    <div class="space-y-2">
                        <span class="text-xs font-semibold text-slate-700 block">ผลการค้นหา (พบ <?= count($myBookings) ?> คน):</span>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-2.5">
                            <?php foreach ($myBookings as $b): ?>
                                <div class="bg-slate-50 p-3.5 rounded-xl border border-slate-200 flex items-center justify-between">
                                    <div>
                                        <div class="font-bold text-slate-900 text-sm"><?= clean($b['passenger_name']) ?></div>
                                        <div class="text-xs text-slate-500 mt-0.5">
                                            <span>คันรถ: <strong class="text-slate-900"><?= clean($b['vehicle_name']) ?></strong></span>
                                            <span class="mx-1">•</span>
                                            <span>ที่นั่งที่: <strong class="text-indigo-700"><?= $b['seat_number'] ?></strong></span>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <button type="button" 
                                                onclick="openCancelModal(<?= $b['id'] ?>, '<?= clean($b['passenger_name']) ?>', '<?= clean($b['vehicle_name']) ?>', <?= $b['seat_number'] ?>)"
                                                class="text-xs text-rose-600 hover:underline">
                                            ขอยกเลิก
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="text-xs text-slate-500 p-2">
                        ไม่พบรายชื่อที่ตรงกับ "<?= clean($searchQuery) ?>"
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Vehicle Filter Tabs -->
    <div class="flex flex-wrap items-center justify-between gap-2.5 mb-5">
        <div class="flex flex-wrap items-center gap-1.5 text-xs">
            <a href="?trip_id=<?= $selectedTripId ?>" 
               class="px-3 py-1.5 rounded-lg font-medium transition <?= $filterVehicleId === 0 ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 hover:bg-slate-100 border border-slate-200' ?>">
                ดูทุกคัน (<?= count($vehicles) ?>)
            </a>

            <?php foreach ($vehicles as $v): 
                $isActiveTab = $filterVehicleId === $v['id'];
            ?>
                <a href="?trip_id=<?= $selectedTripId ?>&vehicle_id=<?= $v['id'] ?>" 
                   class="px-3 py-1.5 rounded-lg font-medium transition <?= $isActiveTab ? 'bg-slate-900 text-white' : 'bg-white text-slate-700 hover:bg-slate-100 border border-slate-200' ?>">
                    <span><?= clean($v['name']) ?></span>
                    <span class="text-[11px] text-slate-400 ml-1">(<?= $v['booked_count'] ?>/<?= $v['total_seats'] ?>)</span>
                </a>
            <?php endforeach; ?>
        </div>

        <a href="/index.php?trip_id=<?= $selectedTripId ?>" class="bg-slate-900 hover:bg-slate-800 text-white text-xs font-medium px-3.5 py-1.5 rounded-lg transition shadow-xs">
            + ลงชื่อเพิ่ม
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
                <div class="p-4 border-b border-slate-100 flex items-center justify-between bg-slate-50/70">
                    <div class="flex items-center space-x-2.5">
                        <span class="font-bold text-slate-900 text-sm"><?= clean($v['name']) ?></span>
                        <span class="text-xs text-slate-500 font-medium px-2 py-0.5 bg-white border border-slate-200 rounded">
                            <?= $vehLabel ?>
                        </span>
                    </div>

                    <div class="text-xs font-medium">
                        <?php if ($isFull): ?>
                            <span class="text-rose-600 font-semibold">● เต็มแล้ว (<?= $totalSeats ?> คน)</span>
                        <?php else: ?>
                            <span class="text-emerald-700 font-semibold">● ว่างอีก <?= $availCount ?> ที่นั่ง</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs sm:text-sm">
                        <thead class="bg-slate-50/50 text-slate-500 font-semibold border-b border-slate-200 text-[11px] uppercase tracking-wider">
                            <tr>
                                <th class="py-2.5 px-4 w-16 text-center">ลำดับ</th>
                                <th class="py-2.5 px-4">ชื่อ - ฉายา (นามสกุล)</th>
                                <th class="py-2.5 px-4 w-32 text-center">เบอร์โทร</th>
                                <th class="py-2.5 px-4">การเดินทาง</th>
                                <th class="py-2.5 px-4">หมายเหตุ</th>
                                <th class="py-2.5 px-4 text-center w-24">สถานะ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php for ($sn = 1; $sn <= $totalSeats; $sn++): 
                                $has = isset($seatMap[$sn]);
                                $p = $has ? $seatMap[$sn] : null;

                                $isMatch = false;
                                if ($has && !empty($searchQuery)) {
                                    $combined = $p['passenger_name'] . ' ' . $p['phone'];
                                    if (mb_stripos($combined, $searchQuery) !== false) {
                                        $isMatch = true;
                                    }
                                }
                            ?>
                                <tr class="transition <?= $isMatch ? 'bg-amber-100/80 font-bold' : ($has ? 'hover:bg-slate-50/70 bg-white' : 'bg-slate-50/20 text-slate-300') ?>">
                                    
                                    <td class="py-2.5 px-4 text-center font-mono font-medium text-slate-500 text-xs">
                                        <?= $sn ?>
                                    </td>

                                    <td class="py-2.5 px-4 font-medium <?= $has ? 'text-slate-900' : 'italic text-slate-300' ?>">
                                        <?= $has ? clean($p['passenger_name']) : '- ที่นั่งว่าง -' ?>
                                    </td>

                                    <td class="py-2.5 px-4 text-center font-mono text-xs text-slate-600">
                                        <?php if ($has): ?>
                                            <a href="tel:<?= clean($p['phone']) ?>" class="hover:text-indigo-600">
                                                <?= clean($p['phone']) ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-slate-300">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="py-2.5 px-4 text-xs text-slate-600">
                                        <?= $has ? clean($p['travel_type'] ?? 'เดินทางไป และ เดินทางกลับ') : '-' ?>
                                    </td>

                                    <td class="py-2.5 px-4 text-xs">
                                        <?php if ($has && !empty($p['admin_note'])): ?>
                                            <span class="inline-block bg-amber-50 text-amber-900 border border-amber-300 px-1.5 py-0.5 rounded text-[11px] font-medium">
                                                <?= clean($p['admin_note']) ?>
                                            </span>
                                        <?php elseif ($has && !empty($p['note'])): ?>
                                            <span class="text-slate-500"><?= clean($p['note']) ?></span>
                                        <?php else: ?>
                                            <span class="text-slate-300">-</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="py-2.5 px-4 text-center text-xs">
                                        <?php if ($has): ?>
                                            <span class="text-emerald-700 font-medium text-[11px]">
                                                ลงชื่อแล้ว
                                            </span>
                                        <?php else: ?>
                                            <a href="/index.php?trip_id=<?= $selectedTripId ?>" class="text-indigo-600 hover:text-indigo-800 font-semibold text-[11px]">
                                                จองที่นี้
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
    <div class="bg-white rounded-2xl p-6 max-w-sm w-full shadow-xl border border-slate-200 text-xs">
        <h3 class="font-bold text-slate-900 text-sm mb-1 text-center">ยืนยันการยกเลิก</h3>
        <p class="text-slate-500 text-center mb-3">ยกเลิกการลงชื่อของ <span id="cancelPassengerName" class="font-semibold text-slate-800"></span></p>

        <form id="cancelForm" onsubmit="submitCancel(event)" class="space-y-3">
            <input type="hidden" id="cancelBookingId" name="booking_id" value="">
            <div>
                <label class="block text-slate-700 font-medium mb-1">เบอร์โทรศัพท์ที่ใช้ลงชื่อ เพื่อยืนยัน</label>
                <input type="tel" id="phone_confirm" name="phone_confirm" required placeholder="0812345678" class="w-full px-3 py-1.5 bg-slate-50 border border-slate-300 rounded-lg text-xs outline-none">
            </div>

            <div class="flex gap-2 pt-1">
                <button type="button" onclick="closeCancelModal()" class="flex-1 py-1.5 text-slate-600 bg-slate-100 rounded-lg">ปิด</button>
                <button type="submit" id="confirmCancelBtn" class="flex-1 py-1.5 text-white bg-rose-600 hover:bg-rose-700 rounded-lg font-medium">ยืนยันยกเลิก</button>
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

        let html = '<div class="divide-y divide-slate-100 text-xs">';
        results.forEach(r => {
            html += `
                <div class="p-2.5 hover:bg-slate-50 cursor-pointer transition flex items-center justify-between gap-3" 
                     onclick="selectSuggestion('${escapeHtml(r.name)}')">
                    <div>
                        <div class="font-semibold text-slate-900">${highlightMatch(r.name, query)}</div>
                        <div class="text-[11px] text-slate-400">เบอร์: ${r.phone}</div>
                    </div>
                    <div class="text-right">
                        <span class="inline-block bg-slate-100 text-slate-700 font-semibold text-[11px] px-2 py-0.5 rounded">
                            ${escapeHtml(r.vehicle_name)}
                        </span>
                        <div class="text-[10px] text-slate-400">ที่นั่งเบอร์ ${r.seat_number}</div>
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
        return escapeHtml(text).replace(regex, '<mark class="bg-amber-100 text-slate-900 px-0.5 rounded">$1</mark>');
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
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
