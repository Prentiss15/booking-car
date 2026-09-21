<?php
// admin/index.php - แผงควบคุมระบบสำหรับ Admin และ Super Admin
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdminLogin();

$db = getDb();
seedDemoDataIfEmpty($db);

$currentAdmin = getCurrentAdmin();
$isSuper = isSuperAdmin();

$msg = '';
$msgType = 'success';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ปุ่มสถานะเปิดรับ / ปิดรับ (ข้อ 2)
    if ($action === 'toggle_trip_status') {
        $tripId = (int)($_POST['trip_id'] ?? 0);
        $newStatus = (int)($_POST['new_status'] ?? 0);

        if ($tripId) {
            $stmt = $db->prepare("UPDATE trips SET is_active = ? WHERE id = ?");
            $stmt->execute([$newStatus, $tripId]);
            $statusText = $newStatus === 1 ? 'เปิดรับลงชื่อ' : 'ปิดรับการลงชื่อ';
            $msg = "เปลี่ยนสถานะรอบเป็น \"{$statusText}\" เรียบร้อยแล้ว";
        }

    } elseif ($action === 'create_trip') {
        $title = clean($_POST['title'] ?? '');
        $tripDate = clean($_POST['trip_date'] ?? '');
        $departureTime = clean($_POST['departure_time'] ?? '08.00 น.');
        $destination = clean($_POST['destination'] ?? 'งานบูชาข้าวพระต้นเดือน');
        $pickupTimeInfo = clean($_POST['pickup_time_info'] ?? 'ขึ้นรถหน้ากุฏิพระประจำ 08.00 น.');
        $returnTimeInfo = clean($_POST['return_time_info'] ?? 'เดินทางกลับ ขึ้นรถที่วิหารคดคอร์ 32 และอาคารปราบบมาร 16.00 น.');
        $noticeRed = clean($_POST['notice_red'] ?? '***ถ่ายภาพสลิปการลงทะเบียน ทั้งขาไป - และขากลับส่งที่ พม.อานุภาพ เวลา 16.00 น.');
        $deadlineNotice = clean($_POST['deadline_notice'] ?? "1. งดถอดชื่อออกเมื่อถึงวันที่ตัดยอดแล้ว\n2. ตัดยอดวันอังคารก่อนวันงาน เวลา 15:00 น.");
        
        $vanCount = max(0, (int)($_POST['van_count'] ?? 0));
        $busAc1Count = max(0, (int)($_POST['bus_ac1_count'] ?? 0));
        $busAc2Count = max(0, (int)($_POST['bus_ac2_count'] ?? 0));
        $busFanCount = max(0, (int)($_POST['bus_fan_count'] ?? 0));

        $totalVehiclesRequested = $vanCount + $busAc1Count + $busAc2Count + $busFanCount;

        if (empty($title) || empty($tripDate)) {
            $msg = 'กรุณากรอกชื่องานและวันที่เดินทาง';
            $msgType = 'error';
        } elseif ($totalVehiclesRequested === 0) {
            $msg = 'กรุณาเลือกรถอย่างน้อย 1 คัน';
            $msgType = 'error';
        } else {
            $db->beginTransaction();
            try {
                $stmt = $db->prepare("
                    INSERT INTO trips (
                        title, trip_date, departure_time, destination, 
                        pickup_time_info, return_time_info, notice_red, deadline_notice, is_active
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([
                    $title, $tripDate, $departureTime, $destination,
                    $pickupTimeInfo, $returnTimeInfo, $noticeRed, $deadlineNotice
                ]);
                $newTripId = $db->lastInsertId();

                $stmtVeh = $db->prepare("
                    INSERT INTO vehicles (trip_id, type, vehicle_type_label, vehicle_number, name, total_seats, header_color)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                
                $carNum = 1;
                for ($i = 1; $i <= $vanCount; $i++) {
                    $stmtVeh->execute([$newTripId, 'van', 'รถตู้ (10 ที่นั่ง)', $carNum, "คันที่ {$carNum}", 10, 'green']);
                    $carNum++;
                }

                for ($j = 1; $j <= $busAc1Count; $j++) {
                    $stmtVeh->execute([$newTripId, 'bus', 'รถบัสปรับอากาศ 1 ชั้น (40 ที่นั่ง)', $carNum, "คันที่ {$carNum} (บัสแอร์ 1 ชั้น)", 40, 'blue']);
                    $carNum++;
                }

                for ($k = 1; $k <= $busAc2Count; $k++) {
                    $stmtVeh->execute([$newTripId, 'bus', 'รถบัสปรับอากาศ 2 ชั้น (50 ที่นั่ง)', $carNum, "คันที่ {$carNum} (บัสแอร์ 2 ชั้น)", 50, 'purple']);
                    $carNum++;
                }

                for ($l = 1; $l <= $busFanCount; $l++) {
                    $stmtVeh->execute([$newTripId, 'bus', 'รถบัสพัดลม (40 ที่นั่ง)', $carNum, "คันที่ {$carNum} (บัสพัดลม)", 40, 'amber']);
                    $carNum++;
                }

                $db->commit();
                $msg = 'สร้างรอบการเดินทางเรียบร้อยแล้ว!';
                $msgType = 'success';
            } catch (Exception $e) {
                $db->rollBack();
                $msg = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
                $msgType = 'error';
            }
        }

    } elseif ($action === 'add_custom_vehicle') {
        $tripId = (int)($_POST['trip_id'] ?? 0);
        $vehType = clean($_POST['veh_type'] ?? 'van');
        $vehTypeLabel = clean($_POST['vehicle_type_label'] ?? '');
        $customLabel = clean($_POST['custom_type_label'] ?? '');
        $name = clean($_POST['name'] ?? '');
        $totalSeats = max(1, (int)($_POST['total_seats'] ?? 10));
        $licensePlate = clean($_POST['license_plate'] ?? '');
        $driverName = clean($_POST['driver_name'] ?? '');
        $driverPhone = clean($_POST['driver_phone'] ?? '');

        if ($vehTypeLabel === 'other' && !empty($customLabel)) {
            $vehTypeLabel = $customLabel;
        }

        $stmtCount = $db->prepare("SELECT COUNT(*) as c FROM vehicles WHERE trip_id = ?");
        $stmtCount->execute([$tripId]);
        $nextNum = ((int)$stmtCount->fetch()['c']) + 1;

        if (empty($name)) {
            $name = "คันที่ {$nextNum}";
        }

        $colors = ['green', 'amber', 'blue', 'purple', 'rose'];
        $c = $colors[($nextNum - 1) % count($colors)];

        $stmt = $db->prepare("
            INSERT INTO vehicles (trip_id, type, vehicle_type_label, vehicle_number, name, total_seats, license_plate, driver_name, driver_phone, header_color)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$tripId, $vehType, $vehTypeLabel, $nextNum, $name, $totalSeats, $licensePlate, $driverName, $driverPhone, $c]);
        $msg = "เพิ่ม {$name} เรียบร้อยแล้ว";

    } elseif ($action === 'update_trip_details') {
        $tripId = (int)($_POST['trip_id'] ?? 0);
        $title = clean($_POST['title'] ?? '');
        $tripDate = clean($_POST['trip_date'] ?? '');
        $departureTime = clean($_POST['departure_time'] ?? '');
        $pickupTimeInfo = clean($_POST['pickup_time_info'] ?? '');
        $returnTimeInfo = clean($_POST['return_time_info'] ?? '');
        $noticeRed = clean($_POST['notice_red'] ?? '');
        $deadlineNotice = clean($_POST['deadline_notice'] ?? '');

        if ($tripId) {
            $stmt = $db->prepare("
                UPDATE trips 
                SET title = ?, trip_date = ?, departure_time = ?, 
                    pickup_time_info = ?, return_time_info = ?, notice_red = ?, deadline_notice = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $title, $tripDate, $departureTime,
                $pickupTimeInfo, $returnTimeInfo, $noticeRed, $deadlineNotice, $tripId
            ]);
            $msg = 'บันทึกการแก้ไขข้อมูลเรียบร้อยแล้ว';
        }

    } elseif ($action === 'move_or_edit_passenger') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $targetVehicleId = (int)($_POST['target_vehicle_id'] ?? 0);
        $prefix = clean($_POST['prefix'] ?? '');
        $firstName = clean($_POST['first_name'] ?? '');
        $lastName = clean($_POST['last_name_or_nickname'] ?? '');
        $adminNote = clean($_POST['admin_note'] ?? '');
        $travelType = clean($_POST['travel_type'] ?? '');
        $phone = clean($_POST['phone'] ?? '');

        if ($bookingId && $targetVehicleId) {
            $stmtCur = $db->prepare("SELECT * FROM bookings WHERE id = ?");
            $stmtCur->execute([$bookingId]);
            $curBooking = $stmtCur->fetch();

            if ($curBooking) {
                $oldVehId = (int)$curBooking['vehicle_id'];
                $fullName = !empty($firstName) ? trim($prefix . $firstName . ' ' . $lastName) : $curBooking['passenger_name'];

                if ($oldVehId !== $targetVehicleId) {
                    $stmtVeh = $db->prepare("SELECT total_seats, name FROM vehicles WHERE id = ?");
                    $stmtVeh->execute([$targetVehicleId]);
                    $targetVeh = $stmtVeh->fetch();
                    $maxSeats = $targetVeh ? (int)$targetVeh['total_seats'] : 10;

                    $stmtOcc = $db->prepare("SELECT seat_number FROM bookings WHERE vehicle_id = ?");
                    $stmtOcc->execute([$targetVehicleId]);
                    $occupied = $stmtOcc->fetchAll(PDO::FETCH_COLUMN);

                    $newSeat = 0;
                    for ($s = 1; $s <= $maxSeats; $s++) {
                        if (!in_array($s, $occupied)) {
                            $newSeat = $s;
                            break;
                        }
                    }

                    if ($newSeat === 0) {
                        $msg = "ไม่สามารถย้ายได้ เนื่องจาก {$targetVeh['name']} เต็มแล้ว";
                        $msgType = 'error';
                    } else {
                        $stmtUpdate = $db->prepare("
                            UPDATE bookings 
                            SET vehicle_id = ?, seat_number = ?, prefix = ?, first_name = ?, last_name_or_nickname = ?, passenger_name = ?, admin_note = ?, travel_type = ?, phone = ?
                            WHERE id = ?
                        ");
                        $stmtUpdate->execute([$targetVehicleId, $newSeat, $prefix, $firstName, $lastName, $fullName, $adminNote, $travelType, $phone, $bookingId]);
                        $msg = "ย้ายและแก้ไขข้อมูลคุณ {$fullName} ไปยัง {$targetVeh['name']} ลำดับที่ {$newSeat} เรียบร้อยแล้ว";
                    }
                } else {
                    $stmtUpdate = $db->prepare("
                        UPDATE bookings 
                        SET prefix = ?, first_name = ?, last_name_or_nickname = ?, passenger_name = ?, admin_note = ?, travel_type = ?, phone = ?
                        WHERE id = ?
                    ");
                    $stmtUpdate->execute([$prefix, $firstName, $lastName, $fullName, $adminNote, $travelType, $phone, $bookingId]);
                    $msg = "อัปเดตข้อมูลคุณ {$fullName} เรียบร้อยแล้ว";
                }
            }
        }

    } elseif ($action === 'add_admin_user') {
        // เพิ่มผู้ดูแล พร้อมเก็บ plain_password ให้ Superadmin ดูได้ (ข้อ 1)
        $newUsername = clean($_POST['new_username'] ?? '');
        $newPassword = $_POST['new_password'] ?? '';
        $newName = clean($_POST['new_name'] ?? '');
        $newRole = clean($_POST['new_role'] ?? 'admin');

        if (!empty($newUsername) && !empty($newPassword) && !empty($newName)) {
            $stmtCheck = $db->prepare("SELECT id FROM admins WHERE username = ?");
            $stmtCheck->execute([$newUsername]);
            if ($stmtCheck->fetch()) {
                $msg = 'ชื่อผู้ใช้นี้มีในระบบแล้ว';
                $msgType = 'error';
            } else {
                $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmtIns = $db->prepare("INSERT INTO admins (username, password, plain_password, name, role) VALUES (?, ?, ?, ?, ?)");
                $stmtIns->execute([$newUsername, $hash, $newPassword, $newName, $newRole]);
                $msg = "เพิ่มผู้ดูแลคุณ {$newName} เรียบร้อยแล้ว";
            }
        }

    } elseif ($action === 'change_my_password') {
        $currentPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        
        if (!empty($newPass) && !empty($currentPass)) {
            $stmtMe = $db->prepare("SELECT password FROM admins WHERE id = ?");
            $stmtMe->execute([$currentAdmin['id']]);
            $row = $stmtMe->fetch();

            if ($row && password_verify($currentPass, $row['password'])) {
                $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                $stmtUp = $db->prepare("UPDATE admins SET password = ?, plain_password = ? WHERE id = ?");
                $stmtUp->execute([$newHash, $newPass, $currentAdmin['id']]);
                $msg = 'เปลี่ยนรหัสผ่านของคุณเรียบร้อยแล้ว!';
            } else {
                $msg = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';
                $msgType = 'error';
            }
        }

    } elseif ($action === 'reset_admin_password' && $isSuper) {
        // Superadmin แก้ไขรหัสผ่านของแอดมินคนอื่นได้ (ข้อ 1)
        $targetAdminId = (int)($_POST['target_admin_id'] ?? 0);
        $newAdminPass = $_POST['new_admin_password'] ?? '';

        if ($targetAdminId && !empty($newAdminPass)) {
            $newHash = password_hash($newAdminPass, PASSWORD_DEFAULT);
            $stmtUp = $db->prepare("UPDATE admins SET password = ?, plain_password = ? WHERE id = ?");
            $stmtUp->execute([$newHash, $newAdminPass, $targetAdminId]);
            $msg = 'รีเซ็ตรหัสผ่านของผู้ดูแลเรียบร้อยแล้ว';
        }

    } elseif ($action === 'delete_admin_user' && $isSuper) {
        // Superadmin ลบบัญชีผู้ดูแลคนไหนก็ได้
        $targetAdminId = (int)($_POST['target_admin_id'] ?? 0);
        
        if ($targetAdminId === (int)$currentAdmin['id']) {
            $msg = 'ไม่สามารถลบบัญชีของตนเองที่กำลังล็อกอินอยู่ได้';
            $msgType = 'error';
        } elseif ($targetAdminId > 0) {
            $stmtGet = $db->prepare("SELECT name, username FROM admins WHERE id = ?");
            $stmtGet->execute([$targetAdminId]);
            $targetUser = $stmtGet->fetch();

            if ($targetUser) {
                $stmtDel = $db->prepare("DELETE FROM admins WHERE id = ?");
                $stmtDel->execute([$targetAdminId]);
                $msg = "ลบบัญชีผู้ดูแลคุณ {$targetUser['name']} (@{$targetUser['username']}) เรียบร้อยแล้ว";
            } else {
                $msg = 'ไม่พบข้อมูลบัญชีผู้ดูแลที่ต้องการลบ';
                $msgType = 'error';
            }
        }

    } elseif ($action === 'delete_booking') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM bookings WHERE id = ?");
        $stmt->execute([$bookingId]);
        $msg = 'ลบรายชื่อผู้โดยสารเรียบร้อยแล้ว';

    } elseif ($action === 'delete_vehicle') {
        $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
        $stmt = $db->prepare("DELETE FROM vehicles WHERE id = ?");
        $stmt->execute([$vehicleId]);
        $msg = 'ลบรถคันดังกล่าวเรียบร้อยแล้ว';

    } elseif ($action === 'duplicate_trip') {
        $sourceTripId = (int)($_POST['source_trip_id'] ?? 0);
        if ($sourceTripId > 0) {
            $stmtSrc = $db->prepare("SELECT * FROM trips WHERE id = ?");
            $stmtSrc->execute([$sourceTripId]);
            $srcTrip = $stmtSrc->fetch();

            if ($srcTrip) {
                $db->beginTransaction();
                try {
                    // คำนวณวันอาทิตย์ต้นเดือนถัดไป
                    $curDate = new DateTime($srcTrip['trip_date']);
                    $curDate->modify('first day of next month');
                    if ($curDate->format('N') != 7) {
                        $curDate->modify('next sunday');
                    }
                    $nextTripDate = $curDate->format('Y-m-d');

                    $stmtNew = $db->prepare("
                        INSERT INTO trips (
                            title, trip_date, departure_time, destination, pickup_location,
                            pickup_time_info, return_time_info, notice_red, deadline_notice, notes, is_active
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                    ");
                    $stmtNew->execute([
                        $srcTrip['title'], $nextTripDate, $srcTrip['departure_time'], $srcTrip['destination'],
                        $srcTrip['pickup_location'] ?? 'หน้ากุฏิพระประจำ',
                        $srcTrip['pickup_time_info'], $srcTrip['return_time_info'], $srcTrip['notice_red'],
                        $srcTrip['deadline_notice'], $srcTrip['notes']
                    ]);
                    $newTripId = $db->lastInsertId();

                    $stmtVeh = $db->prepare("SELECT * FROM vehicles WHERE trip_id = ? ORDER BY id ASC");
                    $stmtVeh->execute([$sourceTripId]);
                    $vehicles = $stmtVeh->fetchAll();

                    $stmtInsVeh = $db->prepare("
                        INSERT INTO vehicles (trip_id, type, vehicle_type_label, vehicle_number, name, license_plate, driver_name, driver_phone, total_seats, header_color)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    foreach ($vehicles as $v) {
                        $stmtInsVeh->execute([
                            $newTripId, $v['type'], $v['vehicle_type_label'], $v['vehicle_number'],
                            $v['name'], $v['license_plate'], $v['driver_name'], $v['driver_phone'],
                            $v['total_seats'], $v['header_color']
                        ]);
                    }

                    $db->commit();
                    $msg = "คัดลอกโครงสร้างรอบเดิมเป็นรอบใหม่เรียบร้อยแล้ว (" . formatThaiDate($nextTripDate) . " - มีรถ " . count($vehicles) . " คัน ที่นั่งว่าง 0 คน พร้อมเปิดรับ)";
                    $msgType = 'success';
                    $_GET['trip_id'] = $newTripId;
                } catch (Exception $e) {
                    $db->rollBack();
                    $msg = 'เกิดข้อผิดพลาดในการคัดลอกรอบ: ' . $e->getMessage();
                    $msgType = 'error';
                }
            }
        }

    } elseif ($action === 'clear_all_bookings') {
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
}

$suggestedDate = getNextFirstSunday();
$upcomingSundays = getUpcomingFirstSundays(6);

$stmtTrips = $db->query("
    SELECT t.*,
           (SELECT COUNT(*) FROM vehicles v WHERE v.trip_id = t.id) as vehicle_count,
           (SELECT COUNT(*) FROM bookings b WHERE b.trip_id = t.id) as booking_count,
           (SELECT SUM(total_seats) FROM vehicles v WHERE v.trip_id = t.id) as total_capacity
    FROM trips t
    ORDER BY t.trip_date DESC, t.id DESC
");
$trips = $stmtTrips->fetchAll();

$currentTripId = isset($_GET['trip_id']) ? (int)$_GET['trip_id'] : ($trips[0]['id'] ?? 0);
$currentTrip = null;
$tripVehicles = [];

if ($currentTripId) {
    foreach ($trips as $t) {
        if ($t['id'] === $currentTripId) {
            $currentTrip = $t;
            break;
        }
    }

    if ($currentTrip) {
        $stmtV = $db->prepare("
            SELECT v.*, 
                   (SELECT COUNT(*) FROM bookings b WHERE b.vehicle_id = v.id) as booked_count
            FROM vehicles v 
            WHERE v.trip_id = ? 
            ORDER BY v.type ASC, v.vehicle_number ASC
        ");
        $stmtV->execute([$currentTrip['id']]);
        $tripVehicles = $stmtV->fetchAll();
    }
}

// ดึงรายชื่อ Admin ทั้งหมด (รวม plain_password สำหรับ superadmin)
$adminsList = $db->query("SELECT id, username, plain_password, name, role, created_at FROM admins ORDER BY id ASC")->fetchAll();

define('APP_TITLE', 'แผงควบคุมระบบ Admin');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

    <!-- Top Admin Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between pb-6 border-b border-slate-200 gap-4 mb-6">
        <div>
            <div class="flex items-center space-x-2 text-xs text-slate-500 font-medium mb-1">
                <span class="px-2 py-0.5 rounded font-semibold <?= $isSuper ? 'bg-purple-100 text-purple-900 border border-purple-300' : 'bg-slate-100 text-slate-700' ?>">
                    <i class="fa-solid <?= $isSuper ? 'fa-crown mr-1 text-amber-500' : 'fa-shield-halved mr-1 text-slate-500' ?>"></i>
                    <?= $isSuper ? 'Super Admin Mode' : 'Admin Mode' ?>
                </span>
                <span>/</span>
                <span>ผู้ดูแล: <strong><?= clean($currentAdmin['name']) ?></strong> (@<?= clean($currentAdmin['username']) ?>)</span>
            </div>
            <h1 class="text-2xl font-bold text-slate-900 tracking-tight">แผงควบคุมระบบจองรถต้นเดือน</h1>
            <p class="text-xs text-slate-500 mt-0.5">จัดการยานพาหนะ, สลับสถานะเปิด/ปิดรับ, ย้ายผู้โดยสารข้ามคัน และจัดการรหัสผ่านผู้ดูแล</p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <button type="button" onclick="openNewTripModal()" class="bg-slate-900 hover:bg-slate-800 text-white font-medium px-3.5 py-2 rounded-lg text-xs transition flex items-center space-x-1.5 shadow-xs">
                <i class="fa-solid fa-plus text-xs"></i>
                <span>สร้างรอบใหม่</span>
            </button>
            <button type="button" onclick="openAddVehicleModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white font-medium px-3.5 py-2 rounded-lg text-xs transition flex items-center space-x-1.5 shadow-xs">
                <i class="fa-solid fa-bus text-xs"></i>
                <span>+ เพิ่มรถในรอบนี้</span>
            </button>
            <button type="button" onclick="openAdminMgmtModal()" class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-300 font-medium px-3.5 py-2 rounded-lg text-xs transition flex items-center space-x-1.5 shadow-xs">
                <i class="fa-solid <?= $isSuper ? 'fa-key text-amber-500' : 'fa-user-gear text-slate-500' ?>"></i>
                <span><?= $isSuper ? 'ดูรหัสผ่านผู้ดูแล / เพิ่มคน' : 'จัดการบัญชี / เปลี่ยนรหัส' ?></span>
            </button>
            <a href="/details.php?trip_id=<?= $currentTripId ?>" class="bg-white hover:bg-slate-50 text-slate-700 border border-slate-300 font-medium px-3.5 py-2 rounded-lg text-xs transition flex items-center space-x-1.5 shadow-xs">
                <i class="fa-solid fa-table-list text-slate-500"></i>
                <span>ข้อมูลโดยละเอียด</span>
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

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        
        <!-- Left: Trip List (4 cols) -->
        <div class="lg:col-span-4 space-y-4">
            <div class="bg-white rounded-2xl border border-slate-200/80 p-4 shadow-xs">
                <div class="flex items-center justify-between mb-3 pb-2 border-b border-slate-100">
                    <span class="text-xs font-bold text-slate-800 uppercase tracking-wider">รอบการเดินทาง</span>
                    <span class="text-[11px] text-slate-400"><?= count($trips) ?> รอบ</span>
                </div>

                <div class="space-y-2.5">
                    <?php foreach ($trips as $t): 
                        $isSelected = $t['id'] === $currentTripId;
                    ?>
                        <div class="p-3.5 rounded-xl border transition-all cursor-pointer <?= $isSelected ? 'border-slate-900 bg-slate-50 shadow-xs' : 'border-slate-200 hover:border-slate-300 bg-white' ?>" onclick="location.href='?trip_id=<?= $t['id'] ?>'">
                            <div class="flex items-start justify-between">
                                <h3 class="font-bold text-slate-900 text-sm leading-snug"><?= clean($t['title']) ?></h3>
                                <span class="text-[10px] font-semibold px-2 py-0.5 rounded <?= $t['is_active'] ? 'bg-emerald-100 text-emerald-800 font-bold' : 'bg-rose-100 text-rose-800 font-bold' ?>">
                                    <?= $t['is_active'] ? 'เปิดรับ' : 'ปิดรับ' ?>
                                </span>
                            </div>
                            <div class="text-xs text-slate-500 mt-1">
                                <?= formatThaiDate($t['trip_date']) ?>
                            </div>
                            <div class="mt-2 pt-2 border-t border-slate-100 flex justify-between text-xs text-slate-500">
                                <span><?= $t['vehicle_count'] ?> คัน</span>
                                <span class="font-semibold text-slate-700">ลงชื่อ <?= $t['booking_count'] ?> / <?= $t['total_capacity'] ?? 0 ?> คน</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Right: Manage Selected Trip & Vehicles (8 cols) -->
        <div class="lg:col-span-8 space-y-6">
            <?php if ($currentTrip): 
                $isOpen = (int)$currentTrip['is_active'] === 1;
            ?>
                
                <!-- 1. Trip Header Bar with PROMINENT Open/Close Toggle Button (ข้อ 2) -->
                <div class="bg-white rounded-2xl border border-slate-200/80 p-5 shadow-xs">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-4 border-b border-slate-100">
                        <div>
                            <span class="text-xs text-slate-400 font-medium">รอบการเดินทางที่เลือก:</span>
                            <h2 class="text-lg font-bold text-slate-900"><?= clean($currentTrip['title']) ?></h2>
                            <div class="text-xs text-slate-500 mt-0.5"><?= formatThaiDate($currentTrip['trip_date']) ?> • เวลา <?= clean($currentTrip['departure_time']) ?></div>
                        </div>

                        <!-- ปุ่มสถานะ ปิดรับ / เปิดรับ (ข้อ 2: เด่นชัด สะดุดตา) -->
                        <div class="flex items-center space-x-2">
                            <form method="POST" class="inline">
                                <input type="hidden" name="action" value="toggle_trip_status">
                                <input type="hidden" name="trip_id" value="<?= $currentTrip['id'] ?>">
                                <input type="hidden" name="new_status" value="<?= $isOpen ? '0' : '1' ?>">
                                
                                <button type="submit" 
                                        class="px-4 py-2 rounded-xl text-xs font-bold transition shadow-sm flex items-center space-x-2 <?= $isOpen ? 'bg-emerald-600 hover:bg-emerald-700 text-white ring-4 ring-emerald-100' : 'bg-rose-600 hover:bg-rose-700 text-white ring-4 ring-rose-100' ?>">
                                    <i class="fa-solid <?= $isOpen ? 'fa-toggle-on text-base' : 'fa-toggle-off text-base' ?>"></i>
                                    <span>สถานะ: <?= $isOpen ? 'เปิดรับลงชื่อ' : 'ปิดรับการลงชื่อ' ?> (คลิกเพื่อเปลี่ยน)</span>
                                </button>
                            </form>

                            <form method="POST" onsubmit="return confirm('ยืนยันคัดลอกโครงสร้างรอบนี้ (คันรถทั้งหมด) ไปสร้างเป็นรอบใหม่สำหรับเดือนถัดไป หรือไม่?\n(รอบใหม่จะมีรถครบทุกคันและที่นั่งว่าง 0 คน พร้อมเปิดรับลงชื่อทันที)')" class="inline">
                                <input type="hidden" name="action" value="duplicate_trip">
                                <input type="hidden" name="source_trip_id" value="<?= $currentTrip['id'] ?>">
                                <button type="submit" class="px-3 py-2 rounded-xl text-xs font-semibold bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 transition flex items-center space-x-1.5 shadow-2xs" title="คัดลอกคันรถทั้งหมดไปสร้างรอบใหม่สำหรับเดือนถัดไป">
                                    <i class="fa-solid fa-copy text-indigo-500"></i>
                                    <span>คัดลอกรอบใหม่</span>
                                </button>
                            </form>

                            <form method="POST" onsubmit="return confirm('ยืนยันล้างรายชื่อผู้ลงทะเบียนในรอบนี้ทั้งหมดหรือไม่?\n(คันรถจะยังคงอยู่ครบ 100% ที่นั่งจะว่าง 0 คน)')" class="inline">
                                <input type="hidden" name="action" value="clear_all_bookings">
                                <input type="hidden" name="trip_id" value="<?= $currentTrip['id'] ?>">
                                <button type="submit" class="px-3 py-2 rounded-xl text-xs font-semibold bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-200 transition flex items-center space-x-1.5 shadow-2xs" title="ล้างรายชื่อทั้งหมดในรอบนี้">
                                    <i class="fa-solid fa-trash-can text-rose-500"></i>
                                    <span>ล้างรายชื่อ</span>
                                </button>
                            </form>

                            <a href="/admin/export.php?trip_id=<?= $currentTrip['id'] ?>" target="_blank" class="px-3 py-2 rounded-xl text-xs font-medium bg-white hover:bg-slate-50 text-slate-700 border border-slate-300 transition flex items-center space-x-1 shadow-xs" title="พิมพ์ใบรายชื่อ">
                                <i class="fa-solid fa-print text-slate-500"></i>
                                <span>พิมพ์</span>
                            </a>
                        </div>
                    </div>

                    <!-- Quick Editable Settings Form -->
                    <form method="POST" class="mt-4 space-y-3 text-xs">
                        <input type="hidden" name="action" value="update_trip_details">
                        <input type="hidden" name="trip_id" value="<?= $currentTrip['id'] ?>">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-slate-600 font-medium mb-1">ชื่องาน / กิจกรรม</label>
                                <input type="text" name="title" value="<?= clean($currentTrip['title']) ?>" required class="w-full px-3 py-1.5 bg-slate-50 border border-slate-300 rounded-lg text-xs font-semibold focus:bg-white outline-none">
                            </div>
                            <div>
                                <label class="block text-slate-600 font-medium mb-1">วันที่เดินทาง</label>
                                <input type="date" name="trip_date" value="<?= clean($currentTrip['trip_date']) ?>" required class="w-full px-3 py-1.5 bg-slate-50 border border-slate-300 rounded-lg text-xs font-semibold focus:bg-white outline-none">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-slate-600 font-medium mb-1">จุดขึ้นรถขาไป</label>
                                <input type="text" name="pickup_time_info" value="<?= clean($currentTrip['pickup_time_info']) ?>" class="w-full px-3 py-1.5 bg-slate-50 border border-slate-300 rounded-lg text-xs focus:bg-white outline-none">
                            </div>
                            <div>
                                <label class="block text-slate-600 font-medium mb-1">จุดขึ้นรถขากลับ</label>
                                <input type="text" name="return_time_info" value="<?= clean($currentTrip['return_time_info']) ?>" class="w-full px-3 py-1.5 bg-slate-50 border border-slate-300 rounded-lg text-xs focus:bg-white outline-none">
                            </div>
                        </div>

                        <div>
                            <label class="block text-slate-600 font-medium mb-1">ข้อความแจ้งเตือน</label>
                            <input type="text" name="notice_red" value="<?= clean($currentTrip['notice_red']) ?>" class="w-full px-3 py-1.5 bg-slate-50 border border-slate-300 rounded-lg text-xs focus:bg-white outline-none">
                        </div>

                        <div class="flex justify-end pt-1">
                            <button type="submit" class="bg-slate-900 hover:bg-slate-800 text-white font-medium px-4 py-1.5 rounded-lg text-xs transition">
                                บันทึกการแก้ไข
                            </button>
                        </div>
                    </form>
                </div>

                <!-- 2. Vehicle List & Passenger Roster -->
                <div class="space-y-5">
                    <div class="flex items-center justify-between">
                        <h3 class="font-bold text-slate-900 text-sm tracking-wide uppercase">
                            ยานพาหนะในรอบนี้ (<?= count($tripVehicles) ?> คัน)
                        </h3>

                        <button type="button" onclick="openAddVehicleModal()" class="text-xs font-semibold text-indigo-600 hover:text-indigo-800 flex items-center space-x-1">
                            <i class="fa-solid fa-plus text-[10px]"></i>
                            <span>เพิ่มรถชนิดอื่น</span>
                        </button>
                    </div>

                    <?php foreach ($tripVehicles as $v): 
                        $stmtP = $db->prepare("SELECT * FROM bookings WHERE vehicle_id = ? ORDER BY seat_number ASC");
                        $stmtP->execute([$v['id']]);
                        $passengers = $stmtP->fetchAll();
                        $pMap = [];
                        foreach ($passengers as $p) {
                            $pMap[$p['seat_number']] = $p;
                        }

                        $vehLabel = clean($v['vehicle_type_label'] ?? ($v['type'] === 'van' ? 'รถตู้ 10 ที่นั่ง' : 'รถบัส ' . $v['total_seats'] . ' ที่นั่ง'));
                    ?>
                        <div class="bg-white rounded-2xl border border-slate-200/80 overflow-hidden shadow-xs">
                            <div class="bg-slate-50/80 p-3.5 border-b border-slate-200/80 flex items-center justify-between">
                                <div class="flex items-center space-x-2.5">
                                    <span class="font-bold text-slate-900 text-sm"><?= clean($v['name']) ?></span>
                                    <span class="text-xs text-slate-500 font-medium px-2 py-0.5 bg-white border border-slate-200 rounded">
                                        <?= $vehLabel ?> (<?= $v['total_seats'] ?> ที่นั่ง)
                                    </span>
                                </div>
                                <div class="flex items-center space-x-2">
                                    <span class="text-xs font-semibold px-2 py-0.5 rounded <?= count($passengers) >= $v['total_seats'] ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700' ?>">
                                        <?= count($passengers) ?> / <?= $v['total_seats'] ?> คน
                                    </span>
                                    <?php if (count($passengers) === 0): ?>
                                        <form method="POST" onsubmit="return confirm('ยืนยันลบรถคันนี้หรือไม่?')" class="inline">
                                            <input type="hidden" name="action" value="delete_vehicle">
                                            <input type="hidden" name="vehicle_id" value="<?= $v['id'] ?>">
                                            <button type="submit" class="text-slate-400 hover:text-rose-600 text-xs p-1" title="ลบรถ">
                                                <i class="fa-solid fa-trash-can"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs">
                                    <thead class="bg-slate-50/50 text-slate-500 font-semibold border-b border-slate-200 text-[11px]">
                                        <tr>
                                            <th class="py-2.5 px-3 w-12 text-center">ลำดับ</th>
                                            <th class="py-2.5 px-3">ชื่อ - ฉายา</th>
                                            <th class="py-2.5 px-3 w-28 text-center">เบอร์โทร</th>
                                            <th class="py-2.5 px-3">การเดินทาง</th>
                                            <th class="py-2.5 px-3">หมายเหตุผู้ดูแล</th>
                                            <th class="py-2.5 px-3 w-28 text-center">จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        <?php for ($sn = 1; $sn <= $v['total_seats']; $sn++): 
                                            $has = isset($pMap[$sn]);
                                            $p = $has ? $pMap[$sn] : null;
                                        ?>
                                            <tr class="<?= $has ? 'bg-white hover:bg-slate-50/60' : 'bg-slate-50/20 text-slate-300' ?>">
                                                <td class="py-2 px-3 text-center font-mono font-medium">
                                                    <?= $sn ?>
                                                </td>
                                                <td class="py-2 px-3 font-medium <?= $has ? 'text-slate-900' : 'italic text-slate-300' ?>">
                                                    <?= $has ? clean($p['passenger_name']) : '- ที่นั่งว่าง -' ?>
                                                </td>
                                                <td class="py-2 px-3 text-center font-mono <?= $has ? 'text-slate-600' : 'text-slate-300' ?>">
                                                    <?= $has ? clean($p['phone']) : '-' ?>
                                                </td>
                                                <td class="py-2 px-3 text-slate-600">
                                                    <?= $has ? clean($p['travel_type']) : '-' ?>
                                                </td>
                                                <td class="py-2 px-3">
                                                    <?php if ($has && !empty($p['admin_note'])): ?>
                                                        <span class="inline-block bg-amber-50 text-amber-900 border border-amber-300 px-1.5 py-0.5 rounded text-[11px]">
                                                            <?= clean($p['admin_note']) ?>
                                                        </span>
                                                    <?php elseif ($has): ?>
                                                        <span class="text-slate-300 text-[11px]">-</span>
                                                    <?php else: ?>
                                                        <span class="text-slate-300">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="py-2 px-3 text-center">
                                                    <?php if ($has): ?>
                                                        <div class="flex items-center justify-center space-x-2">
                                                            <button type="button" 
                                                                    onclick="openMoveModal(<?= htmlspecialchars(json_encode($p)) ?>)"
                                                                    class="text-indigo-600 hover:text-indigo-900 font-medium px-2 py-0.5 rounded border border-indigo-200 hover:bg-indigo-50 text-[11px]">
                                                                ย้าย/แก้ไข
                                                            </button>
                                                            <form method="POST" onsubmit="return confirm('ยืนยันลบชื่อนี้หรือไม่?')" class="inline">
                                                                <input type="hidden" name="action" value="delete_booking">
                                                                <input type="hidden" name="booking_id" value="<?= $p['id'] ?>">
                                                                <button type="submit" class="text-slate-400 hover:text-rose-600 p-0.5" title="ลบชื่อ">
                                                                    <i class="fa-solid fa-trash-can"></i>
                                                                </button>
                                                            </form>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="text-slate-300">-</span>
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

            <?php endif; ?>
        </div>
    </div>

</div>

<!-- Modal: จัดการผู้ดูแล & SUPERADMIN ดูรหัสผ่านของทุกคน (ข้อ 1) -->
<div id="adminMgmtModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs hidden p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl p-6 max-w-2xl w-full shadow-xl border border-slate-200 my-6 text-xs">
        <div class="flex items-center justify-between pb-3 border-b border-slate-200">
            <div>
                <h3 class="font-bold text-slate-900 text-sm flex items-center space-x-2">
                    <i class="fa-solid <?= $isSuper ? 'fa-crown text-amber-500' : 'fa-users-gear text-slate-500' ?>"></i>
                    <span><?= $isSuper ? 'จัดการผู้ดูแลระบบ & ดูรหัสผ่าน (Super Admin)' : 'จัดการผู้ดูแลระบบ & เปลี่ยนรหัสผ่าน' ?></span>
                </h3>
                <p class="text-slate-400 text-[11px] mt-0.5">
                    <?= $isSuper ? 'คุณอยู่ในสถานะ Super Admin สามารถดูรหัสผ่านของแอดมินทุกคนได้กรณีลืมรหัส' : 'สำหรับเปลี่ยนรหัสผ่านและเพิ่มผู้ดูแล' ?>
                </p>
            </div>
            <button type="button" onclick="closeAdminMgmtModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <div class="mt-4 space-y-6">
            
            <!-- ตารางรายชื่อผู้ดูแล & รหัสผ่าน (สำหรับ SUPERADMIN - ข้อ 1) -->
            <?php if ($isSuper): ?>
                <div class="bg-amber-50/60 border border-amber-300 rounded-xl p-4">
                    <div class="flex items-center justify-between mb-2.5">
                        <span class="font-bold text-amber-950 flex items-center space-x-1.5">
                            <i class="fa-solid fa-key text-amber-600"></i>
                            <span>รายการบัญชีผู้ดูแลและรหัสผ่านทั้งหมด (สำหรับ Super Admin ดูเผื่อลืม):</span>
                        </span>
                        <button type="button" onclick="toggleAllPasswords()" id="toggleAllBtn" class="text-[11px] text-amber-800 underline font-semibold">
                            👁️ แสดงรหัสผ่านทั้งหมด
                        </button>
                    </div>

                    <div class="overflow-x-auto bg-white rounded-lg border border-amber-200">
                        <table class="w-full text-left text-xs">
                            <thead class="bg-amber-100/70 text-amber-950 font-bold border-b border-amber-200">
                                <tr>
                                    <th class="py-2 px-3">ชื่อ-สกุล</th>
                                    <th class="py-2 px-3">Username</th>
                                    <th class="py-2 px-3">ระดับสิทธิ์</th>
                                    <th class="py-2 px-3 font-mono">รหัสผ่าน (Password)</th>
                                    <th class="py-2 px-3 text-center">จัดการ</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-amber-100">
                                <?php foreach ($adminsList as $adm): ?>
                                    <tr>
                                        <td class="py-2 px-3 font-medium text-slate-900"><?= clean($adm['name']) ?></td>
                                        <td class="py-2 px-3 font-mono text-slate-700"><?= clean($adm['username']) ?></td>
                                        <td class="py-2 px-3">
                                            <span class="px-1.5 py-0.5 rounded text-[10px] font-bold <?= $adm['role'] === 'superadmin' ? 'bg-purple-100 text-purple-900' : 'bg-slate-100 text-slate-700' ?>">
                                                <?= clean($adm['role']) ?>
                                            </span>
                                        </td>
                                        <td class="py-2 px-3 font-mono text-slate-900">
                                            <span class="password-mask font-bold text-slate-500">••••••••</span>
                                            <span class="password-plain font-bold text-indigo-700 hidden"><?= clean($adm['plain_password'] ?: '(ไม่มีบันทึก)') ?></span>
                                        </td>
                                        <td class="py-2 px-3 text-center">
                                            <div class="flex items-center justify-center space-x-2">
                                                <button type="button" onclick="openResetPasswordPrompt(<?= $adm['id'] ?>, '<?= clean($adm['username']) ?>')" class="text-indigo-600 hover:text-indigo-900 underline text-[11px]" title="แก้ไขรหัสผ่าน">
                                                    เปลี่ยนรหัส
                                                </button>
                                                <?php if ((int)$adm['id'] !== (int)$currentAdmin['id']): ?>
                                                    <form method="POST" onsubmit="return confirm('ยืนยันลบบัญชีผู้ดูแล <?= clean($adm['name']) ?> (@<?= clean($adm['username']) ?>) หรือไม่?')" class="inline">
                                                        <input type="hidden" name="action" value="delete_admin_user">
                                                        <input type="hidden" name="target_admin_id" value="<?= $adm['id'] ?>">
                                                        <button type="submit" class="text-rose-500 hover:text-rose-700 p-1 transition" title="ลบบัญชีนี้">
                                                            <i class="fa-solid fa-trash-can text-xs"></i>
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <span class="text-slate-300 text-[10px] italic">(คุณ)</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 1. เปลี่ยนรหัสผ่านของตนเอง -->
            <div class="bg-slate-50 p-4 rounded-xl border border-slate-200">
                <h4 class="font-bold text-slate-800 mb-2">เปลี่ยนรหัสผ่านของฉัน (<?= clean($currentAdmin['username']) ?>)</h4>
                <form method="POST" class="space-y-2">
                    <input type="hidden" name="action" value="change_my_password">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <input type="password" name="current_password" required placeholder="รหัสผ่านปัจจุบัน" class="w-full px-3 py-1.5 bg-white border border-slate-300 rounded-lg text-xs">
                        <input type="password" name="new_password" required placeholder="รหัสผ่านใหม่" class="w-full px-3 py-1.5 bg-white border border-slate-300 rounded-lg text-xs">
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" class="bg-slate-900 text-white px-3.5 py-1.5 rounded-lg font-medium text-xs">เปลี่ยนรหัสผ่าน</button>
                    </div>
                </form>
            </div>

            <!-- 2. เพิ่มผู้ดูแลใหม่ -->
            <div class="bg-slate-50 p-4 rounded-xl border border-slate-200">
                <h4 class="font-bold text-slate-800 mb-2">+ เพิ่มผู้ดูแลคนใหม่</h4>
                <form method="POST" class="space-y-2">
                    <input type="hidden" name="action" value="add_admin_user">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                        <input type="text" name="new_name" required placeholder="ชื่อ-สกุล ผู้ดูแล" class="w-full px-3 py-1.5 bg-white border border-slate-300 rounded-lg text-xs">
                        <input type="text" name="new_username" required placeholder="Username" class="w-full px-3 py-1.5 bg-white border border-slate-300 rounded-lg text-xs">
                        <input type="text" name="new_password" required placeholder="Password" class="w-full px-3 py-1.5 bg-white border border-slate-300 rounded-lg text-xs font-mono">
                    </div>
                    <?php if ($isSuper): ?>
                        <div class="flex items-center space-x-2 pt-1">
                            <span class="text-slate-500">ระดับสิทธิ์:</span>
                            <label class="inline-flex items-center space-x-1 cursor-pointer">
                                <input type="radio" name="new_role" value="admin" checked>
                                <span>ผู้ดูแลทั่วไป (Admin)</span>
                            </label>
                            <label class="inline-flex items-center space-x-1 cursor-pointer ml-2">
                                <input type="radio" name="new_role" value="superadmin">
                                <span class="text-purple-700 font-bold">Super Admin</span>
                            </label>
                        </div>
                    <?php endif; ?>
                    <div class="flex justify-end pt-1">
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-1.5 rounded-lg font-medium text-xs">เพิ่มผู้ดูแล</button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>

<!-- Modal: เพิ่มรถชนิดอื่น -->
<div id="addVehicleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs hidden p-4">
    <div class="bg-white rounded-2xl p-6 max-w-md w-full shadow-xl border border-slate-200 text-xs">
        <div class="flex items-center justify-between pb-3 border-b border-slate-200">
            <h3 class="font-bold text-slate-900 text-sm">เพิ่มยานพาหนะในรอบเดินทาง</h3>
            <button type="button" onclick="closeAddVehicleModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" class="mt-4 space-y-3">
            <input type="hidden" name="action" value="add_custom_vehicle">
            <input type="hidden" name="trip_id" value="<?= $currentTripId ?>">

            <div>
                <label class="block text-slate-700 font-medium mb-1">ประเภทรถที่ต้องการจัด <span class="text-rose-500">*</span></label>
                <select name="vehicle_type_label" id="vehTypeSelect" onchange="handleVehTypeChange(this.value)" class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs font-semibold focus:bg-white outline-none">
                    <option value="รถตู้ (10 ที่นั่ง VIP)" data-type="van" data-seats="10">รถตู้ (10 ที่นั่ง VIP)</option>
                    <option value="รถตู้ (13 ที่นั่ง มาตรฐาน)" data-type="van" data-seats="13">รถตู้ (13 ที่นั่ง มาตรฐาน)</option>
                    <option value="รถบัสปรับอากาศ 1 ชั้น (40 ที่นั่ง)" data-type="bus" data-seats="40">รถบัสปรับอากาศ 1 ชั้น (40 ที่นั่ง)</option>
                    <option value="รถบัสปรับอากาศ 2 ชั้น (50 ที่นั่ง)" data-type="bus" data-seats="50">รถบัสปรับอากาศ 2 ชั้น (50 ที่นั่ง)</option>
                    <option value="รถบัสปรับอากาศ 2 ชั้น (60 ที่นั่ง)" data-type="bus" data-seats="60">รถบัสปรับอากาศ 2 ชั้น (60 ที่นั่ง)</option>
                    <option value="รถบัสพัดลม (40 ที่นั่ง)" data-type="bus" data-seats="40">รถบัสพัดลม (40 ที่นั่ง)</option>
                    <option value="other" data-type="bus" data-seats="40">กำหนดประเภทเอง...</option>
                </select>
                <input type="hidden" name="veh_type" id="vehTypeHidden" value="van">
                <input type="text" id="customTypeInput" name="custom_type_label" placeholder="ระบุประเภทรถ เช่น รถตู้ VIP 11 ที่นั่ง" class="w-full mt-2 px-3 py-1.5 bg-slate-50 border border-slate-300 rounded-lg text-xs hidden">
            </div>

            <div>
                <label class="block text-slate-700 font-medium mb-1">ชื่อคันรถ (เช่น คันที่ 4 หรือ รถบัส 1)</label>
                <input type="text" name="name" placeholder="ปล่อยว่างเพื่อให้ระบบตั้งชื่อ คันที่ N อัตโนมัติ" class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs focus:bg-white outline-none">
            </div>

            <div>
                <label class="block text-slate-700 font-medium mb-1">จำนวนที่นั่งทั้งหมด (ที่นั่ง) <span class="text-rose-500">*</span></label>
                <input type="number" id="seatsInput" name="total_seats" value="10" min="1" max="100" required class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs font-bold focus:bg-white outline-none">
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-slate-700 font-medium mb-1">เลขทะเบียน (ถ้ามี)</label>
                    <input type="text" name="license_plate" placeholder="เช่น ฮฮ-1234 กทม." class="w-full px-3 py-1.5 bg-slate-50 border border-slate-300 rounded-lg text-xs">
                </div>
                <div>
                    <label class="block text-slate-700 font-medium mb-1">ชื่อคนขับ (ถ้ามี)</label>
                    <input type="text" name="driver_name" placeholder="เช่น นายสมชาย" class="w-full px-3 py-1.5 bg-slate-50 border border-slate-300 rounded-lg text-xs">
                </div>
            </div>

            <div class="pt-3 flex justify-end gap-2 border-t border-slate-100">
                <button type="button" onclick="closeAddVehicleModal()" class="px-3.5 py-2 text-xs text-slate-600 bg-slate-100 rounded-lg">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 text-xs font-medium text-white bg-slate-900 hover:bg-slate-800 rounded-lg">เพิ่มคันนี้</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: ย้ายคนข้ามคัน -->
<div id="moveModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs hidden p-4">
    <div class="bg-white rounded-2xl p-6 max-w-md w-full shadow-xl border border-slate-200 text-xs">
        <div class="flex items-center justify-between pb-3 border-b border-slate-200">
            <div>
                <h3 class="font-bold text-slate-900 text-sm">ย้ายคันรถ / แก้ไขหมายเหตุ</h3>
                <p id="movePassengerName" class="text-xs text-slate-500 font-medium mt-0.5">-</p>
            </div>
            <button type="button" onclick="closeMoveModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" class="mt-4 space-y-3">
            <input type="hidden" name="action" value="move_or_edit_passenger">
            <input type="hidden" id="moveBookingId" name="booking_id" value="">

            <div class="grid grid-cols-1 sm:grid-cols-12 gap-2.5">
                <div class="sm:col-span-4">
                    <label class="block text-slate-700 font-medium mb-1">คำนำหน้า</label>
                    <select id="movePrefix" name="prefix" class="w-full px-2.5 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs">
                        <option value="พระ">พระ</option>
                        <option value="พระมหา">พระมหา</option>
                        <option value="สามเณร">สามเณร</option>
                        <option value="นาย">นาย</option>
                        <option value="นาง">นาง</option>
                        <option value="นางสาว">นางสาว</option>
                        <option value="เด็กชาย">เด็กชาย</option>
                        <option value="เด็กหญิง">เด็กหญิง</option>
                        <option value="">(ไม่มี/อื่นๆ)</option>
                    </select>
                </div>
                <div class="sm:col-span-8">
                    <label class="block text-slate-700 font-medium mb-1">ชื่อ</label>
                    <input type="text" id="moveFirstName" name="first_name" placeholder="ชื่อ" class="w-full px-2.5 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs font-semibold focus:bg-white outline-none">
                </div>
            </div>

            <div>
                <label class="block text-slate-700 font-medium mb-1">ฉายา (พระ) หรือ นามสกุล (ฆราวาส)</label>
                <input type="text" id="moveLastName" name="last_name_or_nickname" placeholder="ฉายา หรือ นามสกุล" class="w-full px-2.5 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs focus:bg-white outline-none">
            </div>

            <div>
                <label class="block text-slate-700 font-medium mb-1">คันรถที่จะให้เดินทาง (เลือกย้ายข้ามคันได้)</label>
                <select id="moveTargetVehicleId" name="target_vehicle_id" class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs font-semibold focus:bg-white outline-none">
                    <?php foreach ($tripVehicles as $tv): ?>
                        <option value="<?= $tv['id'] ?>">
                            <?= clean($tv['name']) ?> (<?= clean($tv['vehicle_type_label'] ?? '') ?> - <?= $tv['booked_count'] ?>/<?= $tv['total_seats'] ?> คน)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-slate-700 font-medium mb-1">หมายเหตุสำหรับผู้ดูแล (เช่น มีคนกลับแทน)</label>
                <input type="text" id="moveAdminNote" name="admin_note" placeholder="เช่น มีคนกลับแทน, พม.เกียรติศักดิ์กลับแทน" class="w-full px-3 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs focus:bg-white outline-none font-medium text-slate-800">
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-slate-700 font-medium mb-1">การเดินทาง</label>
                    <select id="moveTravelType" name="travel_type" class="w-full px-2.5 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs">
                        <option value="เดินทางไป และ เดินทางกลับ">เดินทางไป และ เดินทางกลับ</option>
                        <option value="เดินทางไปอย่างเดียว">เดินทางไปอย่างเดียว</option>
                        <option value="เดินทางกลับอย่างเดียว">เดินทางกลับอย่างเดียว</option>
                    </select>
                </div>
                <div>
                    <label class="block text-slate-700 font-medium mb-1">เบอร์โทรศัพท์</label>
                    <input type="tel" id="movePhone" name="phone" class="w-full px-2.5 py-2 bg-slate-50 border border-slate-300 rounded-lg text-xs">
                </div>
            </div>

            <div class="pt-3 flex justify-end gap-2 border-t border-slate-100">
                <button type="button" onclick="closeMoveModal()" class="px-3.5 py-2 text-xs text-slate-600 bg-slate-100 rounded-lg">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 text-xs font-medium text-white bg-slate-900 hover:bg-slate-800 rounded-lg">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: New Trip -->
<div id="newTripModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-xs hidden p-4 overflow-y-auto">
    <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-xl border border-slate-200 my-8 text-xs">
        <div class="flex items-center justify-between pb-3 border-b border-slate-200">
            <h3 class="font-bold text-slate-900 text-sm">สร้างรอบการเดินทางใหม่</h3>
            <button type="button" onclick="closeNewTripModal()" class="text-slate-400 hover:text-slate-600"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" class="mt-4 space-y-3">
            <input type="hidden" name="action" value="create_trip">

            <div>
                <label class="block font-medium text-slate-700 mb-1">ชื่องาน / กิจกรรม</label>
                <input type="text" name="title" required value="งานบูชาข้าวพระต้นเดือน" class="w-full px-3 py-1.5 bg-slate-50 border border-slate-300 rounded-lg text-xs font-semibold">
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block font-medium text-slate-700 mb-1">วันเดินทาง (วันอาทิตย์ต้นเดือน)</label>
                    <input type="date" name="trip_date" required value="<?= $suggestedDate ?>" class="w-full px-3 py-1.5 bg-slate-50 border border-slate-300 rounded-lg text-xs font-bold text-slate-900">
                </div>
                <div>
                    <label class="block font-medium text-slate-700 mb-1">เวลาล้อหมุน</label>
                    <input type="text" name="departure_time" required value="08.00 น." class="w-full px-3 py-1.5 bg-slate-50 border border-slate-300 rounded-lg text-xs">
                </div>
            </div>

            <div class="bg-slate-50 p-3 rounded-xl border border-slate-200 space-y-2">
                <div class="font-bold text-slate-800">กำหนดยานพาหนะที่จะจัด (เลือกจำนวนคัน):</div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="bg-white p-2 border rounded-lg flex items-center justify-between">
                        <span>รถตู้ (10 ที่นั่ง)</span>
                        <input type="number" name="van_count" value="2" min="0" max="20" class="w-14 px-1 py-0.5 border text-center font-bold">
                    </div>
                    <div class="bg-white p-2 border rounded-lg flex items-center justify-between">
                        <span>บัสแอร์ 1 ชั้น (40 ที่)</span>
                        <input type="number" name="bus_ac1_count" value="0" min="0" max="10" class="w-14 px-1 py-0.5 border text-center font-bold">
                    </div>
                    <div class="bg-white p-2 border rounded-lg flex items-center justify-between">
                        <span>บัสแอร์ 2 ชั้น (50 ที่)</span>
                        <input type="number" name="bus_ac2_count" value="0" min="0" max="10" class="w-14 px-1 py-0.5 border text-center font-bold">
                    </div>
                    <div class="bg-white p-2 border rounded-lg flex items-center justify-between">
                        <span>บัสพัดลม (40 ที่)</span>
                        <input type="number" name="bus_fan_count" value="0" min="0" max="10" class="w-14 px-1 py-0.5 border text-center font-bold">
                    </div>
                </div>
            </div>

            <div class="pt-2 flex justify-end gap-2">
                <button type="button" onclick="closeNewTripModal()" class="px-3.5 py-1.5 text-xs text-slate-600 bg-slate-100 rounded-lg">ยกเลิก</button>
                <button type="submit" class="px-4 py-1.5 text-xs font-medium text-white bg-slate-900 hover:bg-slate-800 rounded-lg">สร้างรอบ</button>
            </div>
        </form>
    </div>
</div>

<!-- Hidden Form for Superadmin Reset Password -->
<form id="resetPassForm" method="POST" class="hidden">
    <input type="hidden" name="action" value="reset_admin_password">
    <input type="hidden" id="resetTargetAdminId" name="target_admin_id" value="">
    <input type="hidden" id="resetNewAdminPassword" name="new_admin_password" value="">
</form>

<script>
    function openNewTripModal() { document.getElementById('newTripModal').classList.remove('hidden'); }
    function closeNewTripModal() { document.getElementById('newTripModal').classList.add('hidden'); }

    function openAddVehicleModal() { document.getElementById('addVehicleModal').classList.remove('hidden'); }
    function closeAddVehicleModal() { document.getElementById('addVehicleModal').classList.add('hidden'); }

    function openAdminMgmtModal() { document.getElementById('adminMgmtModal').classList.remove('hidden'); }
    function closeAdminMgmtModal() { document.getElementById('adminMgmtModal').classList.add('hidden'); }

    function toggleAllPasswords() {
        const masks = document.querySelectorAll('.password-mask');
        const plains = document.querySelectorAll('.password-plain');
        const btn = document.getElementById('toggleAllBtn');
        const isHidden = plains[0]?.classList.contains('hidden');

        if (isHidden) {
            masks.forEach(m => m.classList.add('hidden'));
            plains.forEach(p => p.classList.remove('hidden'));
            btn.textContent = '🔒 ซ่อนรหัสผ่าน';
        } else {
            masks.forEach(m => m.classList.remove('hidden'));
            plains.forEach(p => p.classList.add('hidden'));
            btn.textContent = '👁️ แสดงรหัสผ่านทั้งหมด';
        }
    }

    function openResetPasswordPrompt(adminId, username) {
        const newPass = prompt(`กรุณากรอกรหัสผ่านใหม่สำหรับผู้ดูแล @${username}:`);
        if (newPass && newPass.trim() !== '') {
            document.getElementById('resetTargetAdminId').value = adminId;
            document.getElementById('resetNewAdminPassword').value = newPass.trim();
            document.getElementById('resetPassForm').submit();
        }
    }

    function handleVehTypeChange(val) {
        const select = document.getElementById('vehTypeSelect');
        const selectedOpt = select.options[select.selectedIndex];
        const type = selectedOpt.getAttribute('data-type');
        const seats = selectedOpt.getAttribute('data-seats');
        
        document.getElementById('vehTypeHidden').value = type;
        if (seats) {
            document.getElementById('seatsInput').value = seats;
        }

        const customInput = document.getElementById('customTypeInput');
        if (val === 'other') {
            customInput.classList.remove('hidden');
            customInput.focus();
        } else {
            customInput.classList.add('hidden');
        }
    }

    function openMoveModal(passenger) {
        document.getElementById('moveBookingId').value = passenger.id;
        document.getElementById('movePassengerName').textContent = passenger.passenger_name;
        document.getElementById('moveTargetVehicleId').value = passenger.vehicle_id;
        
        const prefixSelect = document.getElementById('movePrefix');
        if (prefixSelect) {
            prefixSelect.value = passenger.prefix || '';
            if (passenger.prefix && prefixSelect.value !== passenger.prefix) {
                let opt = new Option(passenger.prefix, passenger.prefix, true, true);
                prefixSelect.add(opt);
            }
        }
        const firstNameInput = document.getElementById('moveFirstName');
        if (firstNameInput) {
            firstNameInput.value = passenger.first_name || passenger.passenger_name || '';
        }
        const lastNameInput = document.getElementById('moveLastName');
        if (lastNameInput) {
            lastNameInput.value = passenger.last_name_or_nickname || '';
        }

        document.getElementById('moveAdminNote').value = passenger.admin_note || '';
        document.getElementById('moveTravelType').value = passenger.travel_type || 'เดินทางไป และ เดินทางกลับ';
        document.getElementById('movePhone').value = passenger.phone || '';
        document.getElementById('moveModal').classList.remove('hidden');
    }
    function closeMoveModal() { document.getElementById('moveModal').classList.add('hidden'); }
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
