<?php
// details.php - ข้อมูลผู้ลงชื่อโดยละเอียด (สำหรับผู้ดูแลระบบเท่านั้น)
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// ข้อ 1: ให้เห็นเฉพาะ admin
requireAdminLogin();

$db = getDb();
seedDemoDataIfEmpty($db);

// ดึงรอบ
$stmtTrips = $db->query("SELECT * FROM trips ORDER BY trip_date DESC");
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

// ตัวกรอง
$filterVehicleId = isset($_GET['vehicle_id']) ? (int)$_GET['vehicle_id'] : 0;
$filterTravelType = clean($_GET['travel_type'] ?? '');
$searchQuery = clean($_GET['q'] ?? '');

// ดึงรายการรถเพื่อทำตัวเลือก
$vehicles = [];
if ($selectedTrip) {
    $stmtV = $db->prepare("SELECT * FROM vehicles WHERE trip_id = ? ORDER BY type ASC, vehicle_number ASC");
    $stmtV->execute([$selectedTrip['id']]);
    $vehicles = $stmtV->fetchAll();
}

// Query ผู้โดยสาร
$sql = "
    SELECT b.*, v.name as vehicle_name, v.vehicle_number, v.type as vehicle_type, v.vehicle_type_label
    FROM bookings b
    JOIN vehicles v ON b.vehicle_id = v.id
    WHERE b.trip_id = ?
";
$params = [$selectedTripId];

if ($filterVehicleId > 0) {
    $sql .= " AND b.vehicle_id = ?";
    $params[] = $filterVehicleId;
}

if (!empty($filterTravelType)) {
    $sql .= " AND b.travel_type LIKE ?";
    $params[] = "%{$filterTravelType}%";
}

if (!empty($searchQuery)) {
    $cleanPhone = preg_replace('/[^0-9]/', '', $searchQuery);
    $sql .= " AND (b.passenger_name LIKE ? OR b.first_name LIKE ? OR b.last_name_or_nickname LIKE ?";
    $params[] = "%{$searchQuery}%";
    $params[] = "%{$searchQuery}%";
    $params[] = "%{$searchQuery}%";
    if (!empty($cleanPhone)) {
        $sql .= " OR REPLACE(REPLACE(b.phone, '-', ''), ' ', '') LIKE ?";
        $params[] = "%{$cleanPhone}%";
    }
    $sql .= ")";
}

$sql .= " ORDER BY v.type ASC, v.vehicle_number ASC, b.seat_number ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$passengers = $stmt->fetchAll();

// สถิติข้อมูล (ข้อ 3: อายุเฉลี่ยไม่ต้องใส่มา)
$totalCount = count($passengers);
$roundTripCount = 0;
$oneWayCount = 0;

foreach ($passengers as $p) {
    if (mb_stripos($p['travel_type'], 'กลับ') !== false && mb_stripos($p['travel_type'], 'ไป') !== false) {
        $roundTripCount++;
    } else {
        $oneWayCount++;
    }
}

// Export CSV handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=รายงานผู้โดยสาร_' . date('Ymd_His') . '.csv');
    echo "\xEF\xBB\xBF";
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ลำดับที่', 'คันรถ', 'ประเภทรถ', 'ที่นั่ง', 'คำนำหน้า', 'ชื่อ', 'ฉายา/นามสกุล', 'อายุ', 'เบอร์โทรศัพท์', 'การเดินทาง', 'หมายเหตุผู้ดูแล', 'วันที่ลงชื่อ']);
    
    $i = 1;
    foreach ($passengers as $p) {
        fputcsv($output, [
            $i++,
            $p['vehicle_name'],
            $p['vehicle_type_label'] ?? '',
            $p['seat_number'],
            $p['prefix'],
            $p['first_name'],
            $p['last_name_or_nickname'],
            $p['age'],
            $p['phone'],
            $p['travel_type'],
            $p['admin_note'] ?? '',
            date('d/m/Y H:i', strtotime($p['created_at']))
        ]);
    }
    fclose($output);
    exit;
}

define('APP_TITLE', 'ข้อมูลผู้ลงชื่อโดยละเอียด (Admin)');
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Page Header (International Enterprise Clean Style) -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between pb-6 border-b border-slate-200 gap-4 mb-6">
        <div>
            <div class="flex items-center space-x-2 text-xs text-slate-500 font-medium mb-1">
                <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 font-semibold">สำหรับผู้ดูแลระบบ</span>
                <span>/</span>
                <span>ฐานข้อมูลผู้โดยสาร</span>
            </div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">ข้อมูลผู้ลงชื่อโดยละเอียด</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                <?= clean($selectedTrip['title'] ?? '') ?> • <?= formatThaiDate($selectedTrip['trip_date'] ?? null) ?>
            </p>
        </div>

        <div class="flex items-center gap-2.5">
            <a href="?trip_id=<?= $selectedTripId ?>&export=csv" class="bg-slate-900 hover:bg-slate-800 text-white font-medium px-4 py-2 rounded-lg text-xs transition flex items-center space-x-2 shadow-sm">
                <i class="fa-solid fa-file-arrow-down"></i>
                <span>ส่งออก CSV</span>
            </a>
            <button onclick="window.print()" class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-300 font-medium px-4 py-2 rounded-lg text-xs transition flex items-center space-x-1.5 shadow-xs">
                <i class="fa-solid fa-print text-slate-500"></i>
                <span>พิมพ์</span>
            </button>
            <a href="/admin/index.php?trip_id=<?= $selectedTripId ?>" class="bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 font-medium px-4 py-2 rounded-lg text-xs transition flex items-center space-x-1.5">
                <i class="fa-solid fa-sliders"></i>
                <span>ไปหน้าจัดการ</span>
            </a>
        </div>
    </div>

    <!-- Metrics Cards (ข้อ 3: เอาอายุเฉลี่ยออกแล้ว) -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs">
            <span class="text-xs text-slate-500 font-medium block">ผู้โดยสารตามที่แสดง</span>
            <div class="text-2xl font-bold text-slate-900 mt-1"><?= number_format($totalCount) ?> <span class="text-xs font-normal text-slate-400">คน</span></div>
        </div>
        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs">
            <span class="text-xs text-emerald-700 font-medium block">เดินทางไป และ กลับ</span>
            <div class="text-2xl font-bold text-emerald-900 mt-1"><?= number_format($roundTripCount) ?> <span class="text-xs font-normal text-slate-400">คน</span></div>
        </div>
        <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs">
            <span class="text-xs text-blue-700 font-medium block">เดินทางขาเดียว (ไปอย่างเดียว)</span>
            <div class="text-2xl font-bold text-blue-900 mt-1"><?= number_format($oneWayCount) ?> <span class="text-xs font-normal text-slate-400">คน</span></div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="bg-white p-4 rounded-xl border border-slate-200/80 shadow-xs mb-6">
        <form method="GET" class="grid grid-cols-1 sm:grid-cols-12 gap-3">
            <input type="hidden" name="trip_id" value="<?= $selectedTripId ?>">

            <div class="sm:col-span-5 relative">
                <input type="text" 
                       name="q" 
                       value="<?= clean($searchQuery) ?>" 
                       placeholder="ค้นหาชื่อ, ฉายา หรือเบอร์โทร..." 
                       class="w-full pl-9 pr-3 py-2 bg-slate-50/50 border border-slate-300 rounded-lg text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:bg-white focus:ring-1 focus:ring-slate-900 focus:border-slate-900 outline-none">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-3 text-slate-400 text-xs"></i>
            </div>

            <div class="sm:col-span-3">
                <select name="vehicle_id" class="w-full px-3 py-2 bg-slate-50/50 border border-slate-300 rounded-lg text-xs sm:text-sm text-slate-800 focus:bg-white focus:ring-1 focus:ring-slate-900 outline-none">
                    <option value="0">-- ทุกคันรถ --</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>" <?= $filterVehicleId === $v['id'] ? 'selected' : '' ?>>
                            <?= clean($v['name']) ?> (<?= clean($v['vehicle_type_label'] ?? $v['type']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sm:col-span-2">
                <select name="travel_type" class="w-full px-3 py-2 bg-slate-50/50 border border-slate-300 rounded-lg text-xs sm:text-sm text-slate-800 focus:bg-white focus:ring-1 focus:ring-slate-900 outline-none">
                    <option value="">-- การเดินทางทั้งหมด --</option>
                    <option value="กลับ" <?= $filterTravelType === 'กลับ' ? 'selected' : '' ?>>ไป และ กลับ</option>
                    <option value="อย่างเดียว" <?= $filterTravelType === 'อย่างเดียว' ? 'selected' : '' ?>>ไปอย่างเดียว</option>
                </select>
            </div>

            <div class="sm:col-span-2 flex gap-2">
                <button type="submit" class="flex-1 bg-slate-900 hover:bg-slate-800 text-white font-medium py-2 rounded-lg text-xs sm:text-sm transition">
                    กรองข้อมูล
                </button>
                <?php if (!empty($searchQuery) || $filterVehicleId > 0 || !empty($filterTravelType)): ?>
                    <a href="?trip_id=<?= $selectedTripId ?>" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg text-xs flex items-center justify-center">
                        ล้าง
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Data Table -->
    <div class="bg-white rounded-xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs sm:text-sm">
                <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-200 text-[11px] uppercase tracking-wider">
                    <tr>
                        <th class="py-3 px-4 w-12 text-center">#</th>
                        <th class="py-3 px-4">คันรถ / ชนิดรถ</th>
                        <th class="py-3 px-3 text-center w-16">ที่นั่ง</th>
                        <th class="py-3 px-3">คำนำหน้า</th>
                        <th class="py-3 px-4">ชื่อ</th>
                        <th class="py-3 px-4">ฉายา / นามสกุล</th>
                        <th class="py-3 px-3 text-center">อายุ</th>
                        <th class="py-3 px-4 text-center">เบอร์โทรศัพท์</th>
                        <th class="py-3 px-4">การเดินทาง</th>
                        <th class="py-3 px-4">หมายเหตุผู้ดูแล</th>
                        <th class="py-3 px-4 text-center">เวลาลงชื่อ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <?php if (empty($passengers)): ?>
                        <tr>
                            <td colspan="11" class="py-12 text-center text-slate-400">
                                <i class="fa-regular fa-folder-open text-2xl mb-2 block text-slate-300"></i>
                                <span>ไม่พบข้อมูลตามเงื่อนไขที่ระบุ</span>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($passengers as $idx => $p): 
                            $tType = $p['travel_type'] ?? 'เดินทางไป และ เดินทางกลับ';
                            $isRoundTrip = mb_stripos($tType, 'กลับ') !== false && mb_stripos($tType, 'ไป') !== false;
                        ?>
                            <tr class="hover:bg-slate-50/70 transition">
                                <td class="py-3 px-4 text-center font-mono text-slate-400 text-xs">
                                    <?= ($idx + 1) ?>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="font-semibold text-slate-900"><?= clean($p['vehicle_name']) ?></div>
                                    <span class="text-[11px] text-slate-400 block"><?= clean($p['vehicle_type_label'] ?? '') ?></span>
                                </td>
                                <td class="py-3 px-3 text-center">
                                    <span class="inline-flex items-center justify-center w-6 h-6 rounded bg-slate-100 text-slate-800 font-mono font-bold text-xs">
                                        <?= $p['seat_number'] ?>
                                    </span>
                                </td>
                                <td class="py-3 px-3 text-slate-600">
                                    <?= clean($p['prefix']) ?: '-' ?>
                                </td>
                                <td class="py-3 px-4 font-semibold text-slate-900">
                                    <?= clean($p['first_name'] ?: $p['passenger_name']) ?>
                                </td>
                                <td class="py-3 px-4 text-slate-700">
                                    <?= clean($p['last_name_or_nickname']) ?: '-' ?>
                                </td>
                                <td class="py-3 px-3 text-center font-mono text-slate-600">
                                    <?= clean($p['age']) ?: '-' ?>
                                </td>
                                <td class="py-3 px-4 text-center font-mono">
                                    <a href="tel:<?= clean($p['phone']) ?>" class="text-slate-700 hover:text-indigo-600">
                                        <?= clean($p['phone']) ?>
                                    </a>
                                </td>
                                <td class="py-3 px-4 text-xs">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium <?= $isRoundTrip ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-blue-50 text-blue-800 border border-blue-200' ?>">
                                        <?= clean($tType) ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-xs">
                                    <?php if (!empty($p['admin_note'])): ?>
                                        <span class="inline-block bg-amber-50 text-amber-900 border border-amber-300 px-2 py-0.5 rounded font-medium">
                                            <?= clean($p['admin_note']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-300">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-center text-xs text-slate-400 font-mono">
                                    <?= date('d/m/y H:i', strtotime($p['created_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
