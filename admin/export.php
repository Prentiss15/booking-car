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
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        canvas: '#f6f8fa',
                        surface: '#fcfdfd',
                        'surface-muted': '#f0f3f6',
                        charcoal: {
                            DEFAULT: '#242c38',
                            muted: '#4b5565',
                            subtle: '#6e7987',
                            border: '#e2e6eb'
                        },
                        accent: {
                            DEFAULT: '#3d516b',
                            hover: '#304258'
                        }
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Prompt', sans-serif; }
        @media print {
            .no-print { display: none !important; }
            .page-break { page-break-after: always; }
            body { background: #ffffff !important; }
        }
    </style>
</head>
<body class="bg-canvas p-4 sm:p-8 text-charcoal">

    <div class="max-w-4xl mx-auto mb-6 no-print flex justify-between items-center bg-surface p-4 rounded-lg shadow-card border border-charcoal-border">
        <a href="/admin/index.php?trip_id=<?= $tripId ?>" class="text-charcoal-muted hover:text-charcoal text-xs font-semibold flex items-center space-x-1.5 transition">
            <span>←</span>
            <span>กลับหน้าแผงควบคุม</span>
        </a>
        <button onclick="window.print()" class="bg-accent hover:bg-accent-hover text-white font-medium px-4 py-2 rounded-md text-xs shadow-subtle transition flex items-center space-x-2">
            <span>🖨️</span>
            <span>สั่งพิมพ์ / บันทึกเป็น PDF</span>
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
        <div class="max-w-4xl mx-auto bg-surface p-8 rounded-lg shadow-card mb-8 border border-charcoal-border page-break">
            <!-- Header -->
            <div class="border-b-2 border-charcoal pb-4 mb-4 flex justify-between items-start">
                <div>
                    <h1 class="text-xl font-bold text-charcoal tracking-tight">ใบรายชื่อผู้โดยสารประจำคันรถ</h1>
                    <div class="text-sm font-semibold text-charcoal mt-1"><?= clean($trip['title']) ?></div>
                    <div class="text-xs text-charcoal-muted mt-1">
                        <span>วันเดินทาง: <strong class="text-charcoal"><?= formatThaiDate($trip['trip_date']) ?></strong></span> | 
                        <span>เวลาล้อหมุน: <strong class="text-charcoal"><?= clean($trip['departure_time']) ?></strong></span> | 
                        <span>จุดขึ้นรถ: <strong class="text-charcoal"><?= clean($trip['pickup_location'] ?? 'ตามที่กำหนด') ?></strong></span>
                    </div>
                </div>
                <div class="text-right">
                    <span class="inline-block bg-charcoal text-surface font-semibold text-xs px-3 py-1 rounded-md">
                        <?= clean($v['name']) ?>
                    </span>
                    <div class="text-xs text-charcoal-muted mt-1 font-mono">
                        ทะเบียน: <?= !empty($v['license_plate']) ? clean($v['license_plate']) : '-' ?>
                    </div>
                    <div class="text-xs text-charcoal-muted">
                        คนขับ: <?= !empty($v['driver_name']) ? clean($v['driver_name']) : '-' ?> (<?= clean($v['driver_phone']) ?>)
                    </div>
                </div>
            </div>

            <!-- Summary -->
            <div class="flex justify-between items-center text-xs text-charcoal-muted mb-3 bg-surface-muted p-2.5 rounded-md border border-charcoal-border">
                <span>จำนวนที่นั่งทั้งหมด: <strong class="text-charcoal"><?= $v['total_seats'] ?> ที่นั่ง</strong></span>
                <span>จำนวนผู้โดยสารที่ลงชื่อ: <strong class="text-charcoal"><?= count($passengers) ?> คน</strong></span>
                <span>ที่นั่งว่าง: <strong class="text-charcoal"><?= max(0, $v['total_seats'] - count($passengers)) ?> ที่นั่ง</strong></span>
            </div>

            <!-- Table -->
            <table class="w-full text-left text-xs border border-charcoal-border">
                <thead class="bg-surface-muted text-charcoal font-bold border-b border-charcoal-border">
                    <tr>
                        <th class="py-2 px-3 border-r border-charcoal-border w-16 text-center">ที่นั่ง</th>
                        <th class="py-2 px-3 border-r border-charcoal-border">ชื่อ - สกุล ผู้โดยสาร</th>
                        <th class="py-2 px-3 border-r border-charcoal-border w-32">เบอร์โทรศัพท์</th>
                        <th class="py-2 px-3 border-r border-charcoal-border w-36">การเดินทาง</th>
                        <th class="py-2 px-3 border-r border-charcoal-border">หมายเหตุ</th>
                        <th class="py-2 px-3 w-20 text-center">เช็คชื่อ</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-charcoal-border/60">
                    <?php for ($sn = 1; $sn <= $v['total_seats']; $sn++): 
                        $has = isset($pMap[$sn]);
                        $p = $has ? $pMap[$sn] : null;
                    ?>
                        <tr class="<?= $has ? '' : 'text-charcoal-subtle bg-surface-muted/30' ?>">
                            <td class="py-2 px-3 border-r border-charcoal-border/60 text-center font-bold font-mono">
                                <?= $sn ?>
                            </td>
                            <td class="py-2 px-3 border-r border-charcoal-border/60 font-medium <?= $has ? 'text-charcoal font-semibold' : 'italic' ?>">
                                <?= $has ? clean($p['passenger_name']) : '- ที่นั่งว่าง -' ?>
                            </td>
                            <td class="py-2 px-3 border-r border-charcoal-border/60 font-mono">
                                <?= $has ? clean($p['phone']) : '' ?>
                            </td>
                            <td class="py-2 px-3 border-r border-charcoal-border/60">
                                <?= $has ? clean($p['travel_type']) : '' ?>
                            </td>
                            <td class="py-2 px-3 border-r border-charcoal-border/60 text-[11px]">
                                <?= $has ? clean($p['admin_note']) : '' ?>
                            </td>
                            <td class="py-2 px-3 text-center">
                                <span class="inline-block w-4 h-4 border border-charcoal-border rounded-xs"></span>
                            </td>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>

</body>
</html>
