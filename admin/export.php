<?php
// admin/export.php - รายงานรายชื่อผู้โดยสารแยกตามคัน สำหรับพิมพ์หรือส่งให้คนขับ
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// SECURITY FIX: เฉพาะ Admin ที่เข้าสู่ระบบแล้วเท่านั้นที่พิมพ์ใบรายชื่อได้
requireAdminLogin();

$db = getDb();
$tripId = (int)($_GET['trip_id'] ?? 0);
$vehicleId = (int)($_GET['vehicle_id'] ?? 0);

if (!$tripId) {
    die("กรุณาระบุรอบการเดินทาง");
}

$stmtTrip = $db->prepare("SELECT * FROM trips WHERE id = ?");
$stmtTrip->execute([$tripId]);
$trip = $stmtTrip->fetch();

if (!$trip) {
    die("ไม่พบข้อมูลรอบการเดินทาง");
}

$sql = "SELECT * FROM vehicles WHERE trip_id = ?";
$params = [$tripId];
if ($vehicleId) {
    $sql .= " AND id = ?";
    $params[] = $vehicleId;
}
$sql .= " ORDER BY type ASC, vehicle_number ASC";

$stmtVeh = $db->prepare($sql);
$stmtVeh->execute($params);
$vehicles = $stmtVeh->fetchAll();
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ใบรายชื่อผู้โดยสาร - <?= clean($trip['title']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: 'Prompt', sans-serif; }
        @media print {
            .no-print { display: none !important; }
            .page-break { page-break-after: always; }
        }
    </style>
</head>
<body class="bg-slate-100 p-4 sm:p-8 text-slate-800">

    <div class="max-w-4xl mx-auto mb-6 no-print flex justify-between items-center bg-white p-4 rounded-xl shadow">
        <a href="/admin/index.php?trip_id=<?= $tripId ?>" class="text-indigo-600 hover:text-indigo-800 text-sm font-semibold">
            ← กลับหน้า Admin
        </a>
        <button onclick="window.print()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-2 rounded-lg text-sm shadow">
            🖨️ สั่งพิมพ์ / บันทึกเป็น PDF
        </button>
    </div>

    <?php foreach ($vehicles as $index => $v): 
        $stmtB = $db->prepare("SELECT * FROM bookings WHERE vehicle_id = ? ORDER BY seat_number ASC");
        $stmtB->execute([$v['id']]);
        $passengers = $stmtB->fetchAll();
        $pMap = [];
        foreach ($passengers as $p) {
            $pMap[$p['seat_number']] = $p;
        }
    ?>
        <div class="max-w-4xl mx-auto bg-white p-8 rounded-2xl shadow-sm mb-8 border border-slate-200 page-break">
            <!-- Header -->
            <div class="border-b-2 border-slate-800 pb-4 mb-4 flex justify-between items-start">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">ใบรายชื่อผู้โดยสารประจำคันรถ</h1>
                    <div class="text-sm font-semibold text-indigo-900 mt-1"><?= clean($trip['title']) ?></div>
                    <div class="text-xs text-slate-600 mt-1">
                        <span>วันเดินทาง: <strong><?= formatThaiDate($trip['trip_date']) ?></strong></span> | 
                        <span>เวลาล้อหมุน: <strong><?= clean($trip['departure_time']) ?></strong></span> | 
                        <span>จุดนัดพบ: <strong><?= clean($trip['pickup_location']) ?></strong></span>
                    </div>
                </div>
                <div class="text-right">
                    <span class="inline-block bg-slate-900 text-white font-bold text-sm px-3 py-1 rounded-lg">
                        <?= clean($v['name']) ?>
                    </span>
                    <div class="text-xs text-slate-600 mt-1 font-mono">
                        ทะเบียน: <?= !empty($v['license_plate']) ? clean($v['license_plate']) : '-' ?>
                    </div>
                    <div class="text-xs text-slate-600">
                        คนขับ: <?= !empty($v['driver_name']) ? clean($v['driver_name']) : '-' ?> (<?= clean($v['driver_phone']) ?>)
                    </div>
                </div>
            </div>

            <!-- Summary -->
            <div class="flex justify-between items-center text-xs text-slate-600 mb-3 bg-slate-50 p-2.5 rounded-lg border border-slate-200">
                <span>จำนวนที่นั่งทั้งหมด: <strong><?= $v['total_seats'] ?> ที่นั่ง</strong></span>
                <span>จำนวนผู้โดยสารที่ลงชื่อ: <strong><?= count($passengers) ?> คน</strong></span>
                <span>ที่นั่งว่าง: <strong><?= max(0, $v['total_seats'] - count($passengers)) ?> ที่นั่ง</strong></span>
            </div>

            <!-- Table -->
            <table class="w-full text-left text-xs border border-slate-300">
                <thead class="bg-slate-100 text-slate-700 font-bold border-b border-slate-300">
                    <tr>
                        <th class="py-2 px-3 border-r border-slate-300 w-16 text-center">ที่นั่ง</th>
                        <th class="py-2 px-3 border-r border-slate-300">ชื่อ - สกุล ผู้โดยสาร</th>
                        <th class="py-2 px-3 border-r border-slate-300 w-32">เบอร์โทรศัพท์</th>
                        <th class="py-2 px-3 border-r border-slate-300 w-36">แผนก / สังกัด</th>
                        <th class="py-2 px-3 border-r border-slate-300">หมายเหตุ</th>
                        <th class="py-2 px-3 w-20 text-center">เช็คชื่อ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php for ($sn = 1; $sn <= $v['total_seats']; $sn++): 
                        $has = isset($pMap[$sn]);
                        $p = $has ? $pMap[$sn] : null;
                    ?>
                        <tr class="<?= $has ? '' : 'text-slate-300 bg-slate-50/40' ?>">
                            <td class="py-2 px-3 border-r border-slate-200 text-center font-bold font-mono">
                                <?= $sn ?>
                            </td>
                            <td class="py-2 px-3 border-r border-slate-200 font-medium <?= $has ? 'text-slate-900 font-semibold' : 'italic' ?>">
                                <?= $has ? clean($p['passenger_name']) : '- ที่นั่งว่าง -' ?>
                            </td>
                            <td class="py-2 px-3 border-r border-slate-200 font-mono">
                                <?= $has ? clean($p['phone']) : '' ?>
                            </td>
                            <td class="py-2 px-3 border-r border-slate-200">
                                <?= $has ? clean($p['department']) : '' ?>
                            </td>
                            <td class="py-2 px-3 border-r border-slate-200 text-[11px]">
                                <?= $has ? clean($p['note']) : '' ?>
                            </td>
                            <td class="py-2 px-3 text-center">
                                <span class="inline-block w-4 h-4 border border-slate-400 rounded"></span>
                            </td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>

</body>
</html>
