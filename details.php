<?php
// details.php - ข้อมูลผู้ลงชื่อโดยละเอียด (สำหรับผู้ดูแลระบบเท่านั้น)
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

// ข้อ 1: ให้เห็นเฉพาะ admin
requireAdminLogin();

$db = getDb();
seedDemoDataIfEmpty($db);

$msg = '';
$msgType = 'success';

// จัดการการแก้ไขข้อมูลผู้โดยสารเมื่อ admin ต้องการแก้ไขชื่อ ฉายา/นามสกุล เบอร์โทร
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_passenger') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $prefix = clean($_POST['prefix'] ?? '');
    $firstName = clean($_POST['first_name'] ?? '');
    $lastName = clean($_POST['last_name_or_nickname'] ?? '');
    $phone = clean($_POST['phone'] ?? '');
    $age = !empty($_POST['age']) ? clean($_POST['age']) : '';
    $travelType = clean($_POST['travel_type'] ?? 'เดินทางไป และ เดินทางกลับ');
    $adminNote = clean($_POST['admin_note'] ?? '');

    if ($bookingId <= 0 || empty($firstName)) {
        $msg = 'กรุณาระบุชื่อผู้ลงทะเบียนให้ถูกต้อง';
        $msgType = 'error';
    } else {
        $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
        if (!empty($phone) && !preg_match('/^0[0-9]{8,9}$/', $phoneDigits)) {
            $msg = 'เบอร์โทรศัพท์ไม่ถูกต้อง (ต้องขึ้นต้นด้วย 0 และมีความยาว 9-10 หลัก)';
            $msgType = 'error';
        } else {
            $fullName = trim($prefix . $firstName . ' ' . $lastName);
            $stmtUpdate = $db->prepare("
                UPDATE bookings 
                SET prefix = ?, first_name = ?, last_name_or_nickname = ?, passenger_name = ?, phone = ?, age = ?, travel_type = ?, admin_note = ?
                WHERE id = ?
            ");
            $stmtUpdate->execute([$prefix, $firstName, $lastName, $fullName, $phone, $age, $travelType, $adminNote, $bookingId]);
            $msg = "แก้ไขข้อมูลคุณ \"{$fullName}\" เรียบร้อยแล้ว";
            $msgType = 'success';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'bulk_delete_passengers') {
    $bookingIds = $_POST['booking_ids'] ?? [];
    if (is_array($bookingIds)) {
        $cleanIds = array_filter(array_map('intval', $bookingIds), function($id) { return $id > 0; });
        if (!empty($cleanIds)) {
            $inClause = implode(',', array_fill(0, count($cleanIds), '?'));
            $stmtDel = $db->prepare("DELETE FROM bookings WHERE id IN ({$inClause})");
            $stmtDel->execute(array_values($cleanIds));
            $delCount = count($cleanIds);
            $msg = "ลบรายชื่อผู้ลงทะเบียนที่เลือกจำนวน {$delCount} คนเรียบร้อยแล้ว";
            $msgType = 'success';
        } else {
            $msg = 'กรุณาเลือกรายชื่อที่ต้องการลบอย่างน้อย 1 รายการ';
            $msgType = 'error';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_passenger') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    if ($bookingId > 0) {
        $stmtDel = $db->prepare("DELETE FROM bookings WHERE id = ?");
        $stmtDel->execute([$bookingId]);
        $msg = 'ลบรายชื่อผู้ลงทะเบียนเรียบร้อยแล้ว';
        $msgType = 'success';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_all_bookings') {
    $targetTripId = (int)($_POST['trip_id'] ?? 0);
    if ($targetTripId > 0) {
        $stmtCount = $db->prepare("SELECT COUNT(*) FROM bookings WHERE trip_id = ?");
        $stmtCount->execute([$targetTripId]);
        $cnt = (int)$stmtCount->fetchColumn();

        $stmtClear = $db->prepare("DELETE FROM bookings WHERE trip_id = ?");
        $stmtClear->execute([$targetTripId]);
        $msg = "ล้างข้อมูลผู้ลงชื่อในรอบนี้ทั้งหมดจำนวน {$cnt} คนเรียบร้อยแล้ว (ที่นั่งว่าง 0 คน พร้อมสำหรับรอบใหม่)";
        $msgType = 'success';
    }
}

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
$filterPrefix = clean($_GET['prefix'] ?? '');
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

if (!empty($filterPrefix)) {
    if ($filterPrefix === 'พระ') {
        $sql .= " AND b.prefix = 'พระ'";
    } elseif ($filterPrefix === 'พระมหา') {
        $sql .= " AND b.prefix = 'พระมหา'";
    } elseif ($filterPrefix === 'สามเณร') {
        $sql .= " AND b.prefix = 'สามเณร'";
    } elseif ($filterPrefix === 'ฆราวาส') {
        $sql .= " AND b.prefix IN ('นาย', 'นาง', 'นางสาว', 'เด็กชาย', 'เด็กหญิง')";
    } elseif ($filterPrefix === 'อื่นๆ') {
        $sql .= " AND (b.prefix NOT IN ('พระ', 'พระมหา', 'สามเณร', 'นาย', 'นาง', 'นางสาว', 'เด็กชาย', 'เด็กหญิง') OR b.prefix IS NULL OR b.prefix = '')";
    }
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
    fputcsv($output, ['ลำดับที่', 'คันรถ', 'ประเภทรถ', 'ที่นั่ง', 'ชื่อเต็ม_ข้อมูลดิบ (คำนำหน้า+ชื่อ ฉายา/นามสกุล)', 'คำนำหน้า', 'ชื่อ', 'ฉายา/นามสกุล', 'อายุ', 'เบอร์โทรศัพท์', 'การเดินทาง', 'หมายเหตุผู้ดูแล', 'วันที่ลงชื่อ']);
    
    $i = 1;
    foreach ($passengers as $p) {
        $rawFullName = !empty($p['first_name']) ? trim($p['prefix'] . $p['first_name'] . ' ' . $p['last_name_or_nickname']) : $p['passenger_name'];
        fputcsv($output, [
            $i++,
            $p['vehicle_name'],
            $p['vehicle_type_label'] ?? '',
            $p['seat_number'],
            $rawFullName,
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
            <button type="button" onclick="openClearAllModal()" class="bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 font-medium px-3.5 py-2 rounded-lg text-xs transition flex items-center space-x-1.5 shadow-2xs" title="ล้างรายชื่อผู้ลงทะเบียนทั้งหมดในรอบนี้ (คงสภาพคันรถไว้สำหรับรอบถัดไป)">
                <i class="fa-solid fa-trash-can text-rose-500"></i>
                <span>ล้างรายชื่อทั้งรอบ</span>
            </button>
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

    <!-- Alert Messages -->
    <?php if (!empty($msg)): ?>
        <div class="p-3.5 mb-6 rounded-xl flex items-center space-x-2.5 <?= $msgType === 'success' ? 'bg-emerald-50 text-emerald-800 border border-emerald-200' : 'bg-rose-50 text-rose-800 border border-rose-200' ?>">
            <i class="fa-solid <?= $msgType === 'success' ? 'fa-check text-emerald-600' : 'fa-triangle-exclamation text-rose-600' ?> text-sm"></i>
            <span class="text-xs sm:text-sm font-medium"><?= $msg ?></span>
        </div>
    <?php endif; ?>

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

            <!-- Search input (4 cols) -->
            <div class="sm:col-span-4 relative">
                <input type="text" 
                       name="q" 
                       value="<?= clean($searchQuery) ?>" 
                       placeholder="ค้นหาชื่อ, ฉายา หรือเบอร์โทร..." 
                       class="w-full pl-9 pr-3 py-2 bg-slate-50/50 border border-slate-300 rounded-lg text-xs sm:text-sm text-slate-800 placeholder-slate-400 focus:bg-white focus:ring-1 focus:ring-slate-900 focus:border-slate-900 outline-none">
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-3 text-slate-400 text-xs"></i>
            </div>

            <!-- Prefix Filter (2 cols) -->
            <div class="sm:col-span-2">
                <select name="prefix" class="w-full px-3 py-2 bg-slate-50/50 border border-slate-300 rounded-lg text-xs sm:text-sm text-slate-800 focus:bg-white focus:ring-1 focus:ring-slate-900 outline-none">
                    <option value="">-- คำนำหน้าทั้งหมด --</option>
                    <option value="พระ" <?= $filterPrefix === 'พระ' ? 'selected' : '' ?>>พระ</option>
                    <option value="พระมหา" <?= $filterPrefix === 'พระมหา' ? 'selected' : '' ?>>พระมหา</option>
                    <option value="สามเณร" <?= $filterPrefix === 'สามเณร' ? 'selected' : '' ?>>สามเณร</option>
                    <option value="ฆราวาส" <?= $filterPrefix === 'ฆราวาส' ? 'selected' : '' ?>>ฆราวาส (นาย/นาง/น.ส.)</option>
                    <option value="อื่นๆ" <?= $filterPrefix === 'อื่นๆ' ? 'selected' : '' ?>>อื่นๆ</option>
                </select>
            </div>

            <!-- Vehicle Filter (2 cols) -->
            <div class="sm:col-span-2">
                <select name="vehicle_id" class="w-full px-3 py-2 bg-slate-50/50 border border-slate-300 rounded-lg text-xs sm:text-sm text-slate-800 focus:bg-white focus:ring-1 focus:ring-slate-900 outline-none">
                    <option value="0">-- ทุกคันรถ --</option>
                    <?php foreach ($vehicles as $v): ?>
                        <option value="<?= $v['id'] ?>" <?= $filterVehicleId === $v['id'] ? 'selected' : '' ?>>
                            <?= clean($v['name']) ?> (<?= clean($v['vehicle_type_label'] ?? $v['type']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Travel Type Filter (2 cols) -->
            <div class="sm:col-span-2">
                <select name="travel_type" class="w-full px-3 py-2 bg-slate-50/50 border border-slate-300 rounded-lg text-xs sm:text-sm text-slate-800 focus:bg-white focus:ring-1 focus:ring-slate-900 outline-none">
                    <option value="">-- การเดินทางทั้งหมด --</option>
                    <option value="กลับ" <?= $filterTravelType === 'กลับ' ? 'selected' : '' ?>>ไป และ กลับ</option>
                    <option value="อย่างเดียว" <?= $filterTravelType === 'อย่างเดียว' ? 'selected' : '' ?>>ไปอย่างเดียว</option>
                </select>
            </div>

            <!-- Buttons (2 cols) -->
            <div class="sm:col-span-2 flex gap-2">
                <button type="submit" class="flex-1 bg-slate-900 hover:bg-slate-800 text-white font-medium py-2 rounded-lg text-xs sm:text-sm transition">
                    กรองข้อมูล
                </button>
                <?php if (!empty($searchQuery) || $filterVehicleId > 0 || !empty($filterTravelType) || !empty($filterPrefix)): ?>
                    <a href="?trip_id=<?= $selectedTripId ?>" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-600 rounded-lg text-xs flex items-center justify-center">
                        ล้าง
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Data Table & Bulk Selection Form -->
    <form id="bulkDeleteForm" method="POST" onsubmit="return false;">
        <input type="hidden" name="action" value="bulk_delete_passengers">
        <input type="hidden" name="trip_id" value="<?= $selectedTripId ?>">

        <div class="bg-white rounded-xl border border-slate-200/80 shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs sm:text-sm">
                    <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-200 text-[11px] uppercase tracking-wider">
                        <tr>
                            <th class="py-3 px-3 w-10 text-center">
                                <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll(this)" class="w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer" title="เลือกทั้งหมด">
                            </th>
                            <th class="py-3 px-3 w-12 text-center">#</th>
                            <th class="py-3 px-4">คันรถ / ชนิดรถ</th>
                            <th class="py-3 px-3 text-center w-16">ที่นั่ง</th>
                            <th class="py-3 px-4">ชื่อ-ฉายา/นามสกุล (ข้อมูลดิบพร้อมคัดลอก)</th>
                            <th class="py-3 px-3 text-center">อายุ</th>
                            <th class="py-3 px-4 text-center">เบอร์โทรศัพท์</th>
                            <th class="py-3 px-4">การเดินทาง</th>
                            <th class="py-3 px-4">หมายเหตุผู้ดูแล</th>
                            <th class="py-3 px-4 text-center">เวลาลงชื่อ</th>
                            <th class="py-3 px-4 text-center w-28">จัดการ</th>
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
                                $rawFullName = !empty($p['first_name']) ? trim($p['prefix'] . $p['first_name'] . ' ' . $p['last_name_or_nickname']) : $p['passenger_name'];
                            ?>
                                <tr class="hover:bg-slate-50/70 transition passenger-row" id="row-<?= $p['id'] ?>">
                                    <td class="py-3 px-3 text-center">
                                        <input type="checkbox" name="booking_ids[]" value="<?= $p['id'] ?>" class="passenger-checkbox w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer" onchange="updateSelectedCount()">
                                    </td>
                                    <td class="py-3 px-3 text-center font-mono text-slate-400 text-xs">
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
                                    <td class="py-3 px-4">
                                        <div class="flex items-center space-x-1.5">
                                            <span class="font-bold text-slate-900 text-xs sm:text-sm select-all">
                                                 <?= clean($rawFullName) ?>
                                            </span>
                                            <button type="button" 
                                                    onclick="copyRawText('<?= htmlspecialchars(addslashes($rawFullName), ENT_QUOTES) ?>', this)" 
                                                    class="text-slate-400 hover:text-indigo-600 p-1 text-xs transition rounded hover:bg-slate-100" 
                                                    title="คลิกเพื่อคัดลอกชื่อไปกรอก">
                                                <i class="fa-regular fa-copy"></i>
                                            </button>
                                        </div>
                                        <span class="text-[10px] text-slate-400 font-normal block mt-0.5">
                                            <?= clean($p['prefix']) ?> • <?= clean($p['first_name']) ?> • <?= clean($p['last_name_or_nickname']) ?>
                                        </span>
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
                                    <td class="py-3 px-4 text-center">
                                        <div class="flex items-center justify-center space-x-1.5">
                                            <button type="button" 
                                                    data-id="<?= $p['id'] ?>"
                                                    data-prefix="<?= htmlspecialchars($p['prefix'] ?? '', ENT_QUOTES) ?>"
                                                    data-firstname="<?= htmlspecialchars($p['first_name'] ?? '', ENT_QUOTES) ?>"
                                                    data-lastname="<?= htmlspecialchars($p['last_name_or_nickname'] ?? '', ENT_QUOTES) ?>"
                                                    data-fullname="<?= htmlspecialchars($rawFullName, ENT_QUOTES) ?>"
                                                    data-phone="<?= htmlspecialchars($p['phone'] ?? '', ENT_QUOTES) ?>"
                                                    data-age="<?= htmlspecialchars($p['age'] ?? '', ENT_QUOTES) ?>"
                                                    data-travel="<?= htmlspecialchars($p['travel_type'] ?? 'เดินทางไป และ เดินทางกลับ', ENT_QUOTES) ?>"
                                                    data-adminnote="<?= htmlspecialchars($p['admin_note'] ?? '', ENT_QUOTES) ?>"
                                                    data-vehicle="<?= htmlspecialchars($p['vehicle_name'] ?? '', ENT_QUOTES) ?>"
                                                    data-seat="<?= $p['seat_number'] ?>"
                                                    onclick="openEditPassengerModalFromBtn(this)" 
                                                    class="inline-flex items-center space-x-1 px-2 py-1 rounded-lg bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-semibold text-xs transition border border-indigo-200 shadow-2xs" 
                                                    title="แก้ไขข้อมูล (ชื่อ-ฉายา/นามสกุล, เบอร์โทร)">
                                                <i class="fa-solid fa-pen-to-square text-[11px]"></i>
                                                <span>แก้ไข</span>
                                            </button>
                                            <button type="button" 
                                                    onclick="deleteSinglePassenger(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($rawFullName), ENT_QUOTES) ?>')" 
                                                    class="p-1 rounded-lg text-slate-400 hover:text-rose-600 hover:bg-rose-50 transition" 
                                                    title="ลบรายชื่อนี้">
                                                <i class="fa-solid fa-trash-can text-xs"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </form>

    <!-- Floating Bulk Actions Bar (ลบทีละหลายคน สไตล์สากล) -->
    <div id="bulkActionBar" class="fixed bottom-6 inset-x-0 mx-auto max-w-xl z-40 bg-slate-900 text-white px-5 py-3.5 rounded-2xl shadow-2xl flex items-center justify-between border border-slate-700 hidden transform transition-all duration-200">
        <div class="flex items-center space-x-3">
            <span class="w-7 h-7 rounded-full bg-indigo-500 text-white font-bold text-xs flex items-center justify-center shadow-xs" id="selectedCountBadge">0</span>
            <div>
                <span class="font-bold text-xs sm:text-sm">เลือกรายชื่อ <span id="selectedCountText">0</span> คน</span>
                <span class="text-[11px] text-slate-400 block sm:inline sm:ml-2">จากที่แสดงทั้งหมด <?= count($passengers) ?> คน</span>
            </div>
        </div>
        <div class="flex items-center space-x-2">
            <button type="button" onclick="deselectAll()" class="text-xs text-slate-300 hover:text-white px-2.5 py-1.5 rounded-lg hover:bg-slate-800 transition">
                ยกเลิก
            </button>
            <button type="button" onclick="submitBulkDelete()" class="bg-rose-600 hover:bg-rose-700 text-white font-semibold text-xs px-3.5 py-1.5 rounded-lg transition flex items-center space-x-1.5 shadow-sm">
                <i class="fa-solid fa-trash-can text-xs"></i>
                <span>ลบรายการที่เลือก (<span id="deleteBtnCount">0</span>)</span>
            </button>
        </div>
    </div>

    <!-- Hidden Form for Single Deletion -->
    <form id="singleDeleteForm" method="POST" class="hidden">
        <input type="hidden" name="action" value="delete_passenger">
        <input type="hidden" name="trip_id" value="<?= $selectedTripId ?>">
        <input type="hidden" id="singleDeleteBookingId" name="booking_id" value="">
    </form>

    <!-- Modal: ล้างรายชื่อทั้งหมดในรอบนี้ -->
    <div id="clearAllModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs hidden p-4 overflow-y-auto">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-xl border border-slate-200 text-xs sm:text-sm" onclick="event.stopPropagation()">
            <div class="flex items-center space-x-3 text-rose-600 mb-3">
                <div class="w-10 h-10 rounded-xl bg-rose-100 flex items-center justify-center text-lg">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                </div>
                <div>
                    <h3 class="font-bold text-slate-900 text-base">ล้างรายชื่อทั้งหมดในรอบนี้</h3>
                    <p class="text-xs text-slate-500">สำหรับเริ่มรอบถัดไป หรือเริ่มเปิดรับใหม่</p>
                </div>
            </div>
            <p class="text-slate-600 mb-4 text-xs leading-relaxed">
                การดำเนินการนี้จะทำการ <strong class="text-rose-600">ลบรายชื่อผู้ลงทะเบียนทั้งหมดในรอบนี้ (<?= number_format($totalCount) ?> คน)</strong> ออกจากระบบ โดยที่ <strong class="text-slate-900">คันรถและการตั้งค่ารอบเดิมจะยังคงอยู่ครบ 100%</strong> เพื่อให้พร้อมรับลงชื่อรอบใหม่ได้ทันที
            </p>
            <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 text-[11px] text-amber-800 mb-4">
                <i class="fa-solid fa-circle-info mr-1"></i>
                คำแนะนำตามมาตรฐาน: หากต้องการเก็บประวัติรอบเดิมไว้ดูย้อนหลัง แนะนำให้ใช้ปุ่ม <strong>"คัดลอกรอบใหม่"</strong> ในหน้าแผงควบคุมหลักแทน
            </div>
            <form method="POST" class="flex justify-end space-x-2">
                <input type="hidden" name="action" value="clear_all_bookings">
                <input type="hidden" name="trip_id" value="<?= $selectedTripId ?>">
                <button type="button" onclick="closeClearAllModal()" class="px-4 py-2 text-xs font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition">
                    ยกเลิก
                </button>
                <button type="submit" class="px-4 py-2 text-xs font-semibold text-white bg-rose-600 hover:bg-rose-700 rounded-lg shadow-xs transition flex items-center space-x-1.5">
                    <i class="fa-solid fa-trash-can text-xs"></i>
                    <span>ยืนยันล้างข้อมูลทั้งรอบ</span>
                </button>
            </form>
        </div>
    </div>

</div>

<!-- Modal: แก้ไขข้อมูลผู้ลงชื่อ (สำหรับ Admin) -->
<div id="editPassengerModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs hidden p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-xl border border-slate-200 my-8 text-xs sm:text-sm" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between pb-3 border-b border-slate-200">
            <div>
                <h3 class="font-bold text-slate-900 text-sm sm:text-base flex items-center">
                    <i class="fa-solid fa-user-pen text-indigo-600 mr-2"></i>
                    <span>แก้ไขข้อมูลผู้ลงชื่อ</span>
                </h3>
                <p id="editModalSubtitle" class="text-xs text-slate-500 mt-0.5"></p>
            </div>
            <button type="button" onclick="closeEditPassengerModal()" class="text-slate-400 hover:text-slate-600 p-1 rounded-lg hover:bg-slate-100 transition">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>
        </div>

        <form method="POST" class="mt-4 space-y-3.5" id="editPassengerForm">
            <input type="hidden" name="action" value="edit_passenger">
            <input type="hidden" id="editBookingId" name="booking_id" value="">

            <div class="grid grid-cols-1 sm:grid-cols-12 gap-3">
                <!-- คำนำหน้า -->
                <div class="sm:col-span-4">
                    <label class="block text-slate-700 font-medium mb-1 text-xs">คำนำหน้า <span class="text-rose-500">*</span></label>
                    <select id="editPrefix" name="prefix" class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs font-semibold focus:bg-white focus:ring-1 focus:ring-slate-900 outline-none">
                        <option value="พระ">พระ</option>
                        <option value="พระมหา">พระมหา</option>
                        <option value="สามเณร">สามเณร</option>
                        <option value="นาย">นาย</option>
                        <option value="นาง">นาง</option>
                        <option value="นางสาว">นางสาว</option>
                        <option value="เด็กชาย">เด็กชาย</option>
                        <option value="เด็กหญิง">เด็กหญิง</option>
                        <option value="">(ไม่มีคำนำหน้า / อื่นๆ)</option>
                    </select>
                </div>

                <!-- ชื่อ -->
                <div class="sm:col-span-8">
                    <label class="block text-slate-700 font-medium mb-1 text-xs">ชื่อ <span class="text-rose-500">*</span></label>
                    <input type="text" id="editFirstName" name="first_name" required placeholder="เช่น บุญช่วย หรือ สมชาย" class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs font-semibold focus:bg-white focus:ring-1 focus:ring-slate-900 outline-none">
                </div>
            </div>

            <!-- ฉายา / นามสกุล -->
            <div>
                <label class="block text-slate-700 font-medium mb-1 text-xs">ฉายา (พระ/สามเณร) หรือ นามสกุล (ฆราวาส)</label>
                <input type="text" id="editLastName" name="last_name_or_nickname" placeholder="เช่น สุวฑฺฒโน หรือ ใจดี" class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs focus:bg-white focus:ring-1 focus:ring-slate-900 outline-none">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <!-- เบอร์โทร -->
                <div>
                    <label class="block text-slate-700 font-medium mb-1 text-xs">เบอร์โทรศัพท์ <span class="text-rose-500">*</span></label>
                    <input type="tel" id="editPhone" name="phone" required placeholder="0812345678" pattern="^0[0-9]{8,9}$" title="เบอร์โทรศัพท์ 9-10 หลัก ขึ้นต้นด้วย 0" class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs font-mono focus:bg-white focus:ring-1 focus:ring-slate-900 outline-none">
                </div>

                <!-- อายุ -->
                <div>
                    <label class="block text-slate-700 font-medium mb-1 text-xs">อายุ (ปี)</label>
                    <input type="number" id="editAge" name="age" min="1" max="120" placeholder="เช่น 25" class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs font-mono focus:bg-white focus:ring-1 focus:ring-slate-900 outline-none">
                </div>
            </div>

            <!-- การเดินทาง -->
            <div>
                <label class="block text-slate-700 font-medium mb-1 text-xs">ลักษณะการเดินทาง</label>
                <select id="editTravelType" name="travel_type" class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs focus:bg-white focus:ring-1 focus:ring-slate-900 outline-none">
                    <option value="เดินทางไป และ เดินทางกลับ">เดินทางไป และ เดินทางกลับ</option>
                    <option value="เดินทางไปอย่างเดียว">เดินทางไปอย่างเดียว</option>
                    <option value="เดินทางกลับอย่างเดียว">เดินทางกลับอย่างเดียว</option>
                </select>
            </div>

            <!-- หมายเหตุผู้ดูแล -->
            <div>
                <label class="block text-slate-700 font-medium mb-1 text-xs">หมายเหตุสำหรับผู้ดูแล</label>
                <input type="text" id="editAdminNote" name="admin_note" placeholder="เช่น มีคนเดินทางแทน, แจ้งยกเลิก" class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs focus:bg-white focus:ring-1 focus:ring-slate-900 outline-none">
            </div>

            <div class="pt-4 flex items-center justify-end space-x-2.5 border-t border-slate-100">
                <button type="button" onclick="closeEditPassengerModal()" class="px-4 py-2 text-xs font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition">
                    ยกเลิก
                </button>
                <button type="submit" class="px-4 py-2 text-xs font-medium text-white bg-slate-900 hover:bg-slate-800 rounded-lg shadow-xs transition flex items-center space-x-1.5">
                    <i class="fa-solid fa-floppy-disk text-xs"></i>
                    <span>บันทึกการแก้ไข</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function copyRawText(text, btn) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
            const icon = btn.querySelector('i');
            if (icon) {
                icon.className = 'fa-solid fa-check text-emerald-600';
                setTimeout(() => {
                    icon.className = 'fa-regular fa-copy';
                }, 1500);
            }
        }).catch(() => {
            prompt('คัดลอกข้อความด้านล่างนี้ได้เลยครับ:', text);
        });
    } else {
        prompt('คัดลอกข้อความด้านล่างนี้ได้เลยครับ:', text);
    }
}

function openEditPassengerModalFromBtn(btn) {
    const ds = btn.dataset;
    document.getElementById('editBookingId').value = ds.id || '';
    
    // Prefix
    const prefixSelect = document.getElementById('editPrefix');
    prefixSelect.value = ds.prefix || '';
    if (ds.prefix && prefixSelect.value !== ds.prefix) {
        let customOpt = Array.from(prefixSelect.options).find(o => o.value === ds.prefix);
        if (!customOpt) {
            customOpt = new Option(ds.prefix, ds.prefix, true, true);
            prefixSelect.add(customOpt);
        }
    }
    
    // Name
    let firstName = ds.firstname;
    if (!firstName && ds.fullname) {
        firstName = ds.fullname;
    }
    document.getElementById('editFirstName').value = firstName || '';
    document.getElementById('editLastName').value = ds.lastname || '';
    document.getElementById('editPhone').value = ds.phone || '';
    document.getElementById('editAge').value = ds.age || '';
    document.getElementById('editTravelType').value = ds.travel || 'เดินทางไป และ เดินทางกลับ';
    document.getElementById('editAdminNote').value = ds.adminnote || '';
    
    document.getElementById('editModalSubtitle').textContent = (ds.vehicle ? ds.vehicle : '') + ' • ที่นั่งที่ ' + (ds.seat ? ds.seat : '-');
    
    document.getElementById('editPassengerModal').classList.remove('hidden');
}

// Bulk selection handlers
function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.passenger-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = master.checked;
        const row = document.getElementById('row-' + cb.value);
        if (row) {
            if (master.checked) {
                row.classList.add('bg-indigo-50/50');
            } else {
                row.classList.remove('bg-indigo-50/50');
            }
        }
    });
    updateSelectedCount();
}

function updateSelectedCount() {
    const checkboxes = document.querySelectorAll('.passenger-checkbox:checked');
    const count = checkboxes.length;
    const bar = document.getElementById('bulkActionBar');
    const badge = document.getElementById('selectedCountBadge');
    const text = document.getElementById('selectedCountText');
    const btnCount = document.getElementById('deleteBtnCount');
    const master = document.getElementById('selectAllCheckbox');
    const all = document.querySelectorAll('.passenger-checkbox');

    if (badge) badge.textContent = count;
    if (text) text.textContent = count;
    if (btnCount) btnCount.textContent = count;

    if (count > 0) {
        bar?.classList.remove('hidden');
    } else {
        bar?.classList.add('hidden');
    }

    if (master && all.length > 0) {
        master.checked = count === all.length;
        master.indeterminate = count > 0 && count < all.length;
    }

    // Highlight selected rows
    document.querySelectorAll('.passenger-checkbox').forEach(cb => {
        const row = document.getElementById('row-' + cb.value);
        if (row) {
            if (cb.checked) {
                row.classList.add('bg-indigo-50/50');
            } else {
                row.classList.remove('bg-indigo-50/50');
            }
        }
    });
}

function deselectAll() {
    document.querySelectorAll('.passenger-checkbox').forEach(cb => {
        cb.checked = false;
        const row = document.getElementById('row-' + cb.value);
        if (row) row.classList.remove('bg-indigo-50/50');
    });
    const master = document.getElementById('selectAllCheckbox');
    if (master) {
        master.checked = false;
        master.indeterminate = false;
    }
    updateSelectedCount();
}

function submitBulkDelete() {
    const checkboxes = document.querySelectorAll('.passenger-checkbox:checked');
    const count = checkboxes.length;
    if (count === 0) {
        alert('กรุณาเลือกรายชื่อที่ต้องการลบอย่างน้อย 1 รายการ');
        return;
    }
    if (confirm('ยืนยันลบรายชื่อผู้ลงทะเบียนที่เลือกจำนวน ' + count + ' คน หรือไม่?\n(การกระทำนี้จะลบข้อมูลออกจากระบบทันที)')) {
        document.getElementById('bulkDeleteForm').submit();
    }
}

function deleteSinglePassenger(bookingId, passengerName) {
    if (confirm('ยืนยันลบรายชื่อคุณ ' + passengerName + ' ออกจากระบบหรือไม่?')) {
        document.getElementById('singleDeleteBookingId').value = bookingId;
        document.getElementById('singleDeleteForm').submit();
    }
}

function openClearAllModal() {
    document.getElementById('clearAllModal').classList.remove('hidden');
}

function closeClearAllModal() {
    document.getElementById('clearAllModal').classList.add('hidden');
}

document.getElementById('clearAllModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeClearAllModal();
    }
});

function closeEditPassengerModal() {
    document.getElementById('editPassengerModal').classList.add('hidden');
}

// Close on backdrop click
document.getElementById('editPassengerModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeEditPassengerModal();
    }
});

// ESC key to close any open modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEditPassengerModal();
        closeClearAllModal();
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
