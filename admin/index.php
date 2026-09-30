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

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $expTripId = (int)($_GET['trip_id'] ?? 0);
    if (!$expTripId) {
        $stmtDef = $db->query("SELECT id FROM trips ORDER BY trip_date DESC, id DESC LIMIT 1");
        $expTripId = (int)($stmtDef->fetchColumn() ?: 0);
    }

    $stmtExpTrip = $db->prepare("SELECT * FROM trips WHERE id = ?");
    $stmtExpTrip->execute([$expTripId]);
    $expTrip = $stmtExpTrip->fetch();

    $stmtExp = $db->prepare("
        SELECT b.*, v.name as vehicle_name, v.vehicle_type_label, v.type as vehicle_type
        FROM bookings b
        JOIN vehicles v ON b.vehicle_id = v.id
        WHERE v.trip_id = ?
        ORDER BY v.vehicle_number ASC, b.seat_number ASC
    ");
    $stmtExp->execute([$expTripId]);
    $expPassengers = $stmtExp->fetchAll();

    $tripTitleSafe = preg_replace('/[^a-zA-Z0-9_\x{0E00}-\x{0E7F}-]/u', '_', $expTrip['title'] ?? 'trip');
    $filename = "passenger_roster_{$tripTitleSafe}_" . date('Ymd_His') . ".csv";

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM

    fputcsv($output, [
        'ลำดับ', 'คันรถ', 'ชนิดรถ', 'ที่นั่ง', 'ชื่อ-นามสกุล', 'คำนำหน้า', 'ชื่อ', 'ฉายา/นามสกุล', 'อายุ', 'เบอร์โทรศัพท์', 'การเดินทาง', 'หมายเหตุผู้ดูแล', 'เวลาลงชื่อ'
    ]);

    $expIdx = 1;
    foreach ($expPassengers as $p) {
        $rawFullName = !empty($p['first_name']) ? trim($p['prefix'] . $p['first_name'] . ' ' . $p['last_name_or_nickname']) : $p['passenger_name'];
        fputcsv($output, [
            $expIdx++,
            sanitizeCsvField($p['vehicle_name']),
            sanitizeCsvField($p['vehicle_type_label'] ?? ''),
            sanitizeCsvField($p['seat_number']),
            sanitizeCsvField($rawFullName),
            sanitizeCsvField($p['prefix']),
            sanitizeCsvField($p['first_name']),
            sanitizeCsvField($p['last_name_or_nickname']),
            sanitizeCsvField($p['age']),
            sanitizeCsvField($p['phone']),
            sanitizeCsvField($p['travel_type']),
            sanitizeCsvField($p['admin_note'] ?? ''),
            date('d/m/Y H:i', strtotime($p['created_at']))
        ]);
    }
    fclose($output);
    exit;
}

// Handle POST actions with CSRF & Multi-Tier RBAC Verification
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!verifyCsrfToken($token)) {
        $msg = 'ความปลอดภัย: โทเค็น CSRF ไม่ถูกต้อง กรุณารีเฟรชหน้าเว็บและลองใหม่อีกครั้ง';
        $msgType = 'error';
    } else {
        $currentRole = $currentAdmin['role'] ?? 'admin';
        if ($currentRole === 'staff') {
            $msg = 'ขออภัย บัญชีระดับ Staff Coordinator มีสิทธิ์ดูข้อมูลและพิมพ์รายงานเท่านั้น ไม่สามารถแก้ไขข้อมูลได้';
            $msgType = 'error';
        } else {
            $action = $_POST['action'] ?? '';

            // ปุ่มสถานะเปิดรับ / ปิดรับ
            if ($action === 'toggle_trip_status') {
                $tripId = (int)($_POST['trip_id'] ?? 0);
                $newStatus = (int)($_POST['new_status'] ?? 0);

                if ($tripId) {
                    $stmt = $db->prepare("UPDATE trips SET is_active = ? WHERE id = ?");
                    $stmt->execute([$newStatus, $tripId]);
                    $statusText = $newStatus === 1 ? 'เปิดรับลงชื่อ' : 'ปิดรับการลงชื่อ';
                    $msg = "เปลี่ยนสถานะรอบเป็น \"{$statusText}\" เรียบร้อยแล้ว";
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
                $age = !empty($_POST['age']) ? clean($_POST['age']) : '';
                $adminNote = clean($_POST['admin_note'] ?? '');
                $travelType = clean($_POST['travel_type'] ?? '');
                $phone = clean($_POST['phone'] ?? '');

                if ($bookingId && $targetVehicleId) {
                    try {
                        $db->beginTransaction();

                        $stmtCur = $db->prepare("SELECT * FROM bookings WHERE id = ?");
                        $stmtCur->execute([$bookingId]);
                        $curBooking = $stmtCur->fetch();

                        if ($curBooking) {
                            $oldVehId = (int)$curBooking['vehicle_id'];
                            $fullName = !empty($firstName) ? trim($prefix . $firstName . ' ' . $lastName) : $curBooking['passenger_name'];

                            if ($oldVehId !== $targetVehicleId) {
                                $stmtVeh = $db->prepare("SELECT trip_id, total_seats, name FROM vehicles WHERE id = ?");
                                $stmtVeh->execute([$targetVehicleId]);
                                $targetVeh = $stmtVeh->fetch();
                                if (!$targetVeh) {
                                    throw new Exception("ไม่พบข้อมูลคันรถปลายทาง");
                                }
                                $maxSeats = (int)$targetVeh['total_seats'];

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
                                    $db->rollBack();
                                    $msg = "ไม่สามารถย้ายได้ เนื่องจาก {$targetVeh['name']} เต็มแล้ว";
                                    $msgType = 'error';
                                } else {
                                    $stmtUpdate = $db->prepare("
                                        UPDATE bookings 
                                        SET trip_id = ?, vehicle_id = ?, seat_number = ?, prefix = ?, first_name = ?, last_name_or_nickname = ?, passenger_name = ?, age = ?, admin_note = ?, travel_type = ?, phone = ?
                                        WHERE id = ?
                                    ");
                                    $stmtUpdate->execute([
                                        $targetVeh['trip_id'], $targetVehicleId, $newSeat, $prefix, $firstName, 
                                        $lastName, $fullName, $age, $adminNote, $travelType, $phone, $bookingId
                                    ]);
                                    $db->commit();
                                    $msg = "ย้ายและแก้ไขข้อมูลคุณ {$fullName} ไปยัง {$targetVeh['name']} ลำดับที่ {$newSeat} เรียบร้อยแล้ว";
                                }
                            } else {
                                $stmtUpdate = $db->prepare("
                                    UPDATE bookings 
                                    SET prefix = ?, first_name = ?, last_name_or_nickname = ?, passenger_name = ?, age = ?, admin_note = ?, travel_type = ?, phone = ?
                                    WHERE id = ?
                                ");
                                $stmtUpdate->execute([$prefix, $firstName, $lastName, $fullName, $age, $adminNote, $travelType, $phone, $bookingId]);
                                $db->commit();
                                $msg = "อัปเดตข้อมูลคุณ {$fullName} เรียบร้อยแล้ว";
                            }
                        } else {
                            $db->rollBack();
                            $msg = "ไม่พบข้อมูลผู้โดยสารที่ต้องการแก้ไข";
                            $msgType = 'error';
                        }
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        $msg = "เกิดข้อผิดพลาดในการบันทึกข้อมูล: " . clean($e->getMessage());
                        $msgType = 'error';
                    }
                }

            } elseif ($action === 'bulk_delete_passengers') {
                $bookingIds = $_POST['booking_ids'] ?? [];
                if (!empty($bookingIds) && is_array($bookingIds)) {
                    $sanitizedIds = array_values(array_filter(array_map('intval', $bookingIds), fn($id) => $id > 0));
                    if (!empty($sanitizedIds)) {
                        $placeholders = implode(',', array_fill(0, count($sanitizedIds), '?'));
                        $stmtDel = $db->prepare("DELETE FROM bookings WHERE id IN ({$placeholders})");
                        $stmtDel->execute($sanitizedIds);
                        $delCount = $stmtDel->rowCount();
                        $msg = "ลบข้อมูลผู้โดยสารที่เลือกจำนวน {$delCount} คน เรียบร้อยแล้ว";
                    }
                }

            } elseif ($action === 'add_admin_user') {
                $curRole = $currentAdmin['role'] ?? 'staff';
                if ($curRole === 'staff') {
                    $msg = 'ขออภัย ผู้ประสานงานรถไม่มีสิทธิ์จัดการผู้ดูแลระบบ';
                    $msgType = 'error';
                } else {
                    $newEmail = strtolower(trim(clean($_POST['new_email'] ?? '')));
                    $newName = clean($_POST['new_name'] ?? '');
                    $newRole = clean($_POST['new_role'] ?? 'staff');

                    // ผู้ดูแลทั่วไป (admin) จัดการสิทธิ์ได้เฉพาะ ผู้ประสานงานรถ (staff)
                    if ($curRole === 'admin') {
                        $newRole = 'staff';
                    } else {
                        if (!in_array($newRole, ['superadmin', 'admin', 'staff'])) {
                            $newRole = 'staff';
                        }
                    }

                    if (empty($newEmail) || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                        $msg = 'กรุณาระบุที่อยู่อีเมล Gmail ที่ถูกต้อง';
                        $msgType = 'error';
                    } elseif (empty($newName)) {
                        $msg = 'กรุณาระบุชื่อ-นามสกุลของผู้ประสานงาน/ผู้ดูแล';
                        $msgType = 'error';
                    } else {
                        $stmtCheck = $db->prepare("SELECT id FROM admins WHERE email = ?");
                        $stmtCheck->execute([$newEmail]);
                        if ($stmtCheck->fetch()) {
                            $msg = "อีเมล {$newEmail} มีสิทธิ์ในระบบอยู่แล้ว";
                            $msgType = 'error';
                        } else {
                            try {
                                $baseUsername = preg_replace('/[^a-zA-Z0-9_\.]/', '', explode('@', $newEmail)[0]) ?: 'admin';
                                $username = $baseUsername;
                                $suffix = 1;
                                while (true) {
                                    $stmtU = $db->prepare("SELECT id FROM admins WHERE username = ?");
                                    $stmtU->execute([$username]);
                                    if (!$stmtU->fetch()) break;
                                    $username = $baseUsername . '_' . $suffix++;
                                }

                                $randHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                                $avatar = "https://ui-avatars.com/api/?name=" . urlencode($newName) . "&background=3d516b&color=fff&size=128";

                                $stmtIns = $db->prepare("
                                    INSERT INTO admins (email, username, password, name, role, avatar, is_active, created_at) 
                                    VALUES (?, ?, ?, ?, ?, ?, 1, ?)
                                ");
                                $stmtIns->execute([$newEmail, $username, $randHash, $newName, $newRole, $avatar, date('Y-m-d H:i:s')]);
                                $roleName = $newRole === 'staff' ? 'ผู้ประสานงานรถ (Staff)' : ($newRole === 'admin' ? 'ผู้ดูแลทั่วไป (Admin)' : 'ผู้ดูแลระบบสูงสุด (Super Admin)');
                                $msg = "เพิ่มสิทธิ์บัญชี Gmail: {$newEmail} [{$roleName}] เรียบร้อยแล้ว สามารถเข้าสู่ระบบได้ทันที";
                                $msgType = 'success';
                            } catch (Throwable $e) {
                                $msg = 'เกิดข้อผิดพลาดในการบันทึกสิทธิ์: ' . $e->getMessage();
                                $msgType = 'error';
                            }
                        }
                    }
                }

            } elseif ($action === 'toggle_admin_status') {
                $curRole = $currentAdmin['role'] ?? 'staff';
                if ($curRole === 'staff') {
                    $msg = 'ขออภัย ผู้ประสานงานรถไม่มีสิทธิ์จัดการผู้ดูแลระบบ';
                    $msgType = 'error';
                } else {
                    $targetAdminId = (int)($_POST['target_admin_id'] ?? 0);
                    $newStatus = (int)($_POST['new_status'] ?? 1);

                    if ($targetAdminId === (int)$currentAdmin['id']) {
                        $msg = 'ไม่สามารถระงับการใช้งานบัญชีตนเองที่กำลังล็อกอินอยู่ได้';
                        $msgType = 'error';
                    } elseif ($targetAdminId > 0) {
                        $stmtTarget = $db->prepare("SELECT role, name FROM admins WHERE id = ?");
                        $stmtTarget->execute([$targetAdminId]);
                        $targetUser = $stmtTarget->fetch();

                        if (!$targetUser) {
                            $msg = 'ไม่พบข้อมูลบัญชีที่ต้องการแก้ไข';
                            $msgType = 'error';
                        } elseif ($curRole === 'admin' && $targetUser['role'] !== 'staff') {
                            $msg = 'ผู้ดูแลทั่วไปสามารถจัดการสถานะได้เฉพาะบัญชีผู้ประสานงานรถ (Staff) เท่านั้น';
                            $msgType = 'error';
                        } else {
                            $stmtUp = $db->prepare("UPDATE admins SET is_active = ? WHERE id = ?");
                            $stmtUp->execute([$newStatus, $targetAdminId]);
                            $statusTxt = $newStatus === 1 ? 'เปิดใช้งาน' : 'ระงับการใช้งาน';
                            $msg = "เปลี่ยนสถานะบัญชีคุณ {$targetUser['name']} เป็น \"{$statusTxt}\" เรียบร้อยแล้ว";
                        }
                    }
                }

            } elseif ($action === 'delete_admin_user') {
                $curRole = $currentAdmin['role'] ?? 'staff';
                if ($curRole === 'staff') {
                    $msg = 'ขออภัย ผู้ประสานงานรถไม่มีสิทธิ์จัดการผู้ดูแลระบบ';
                    $msgType = 'error';
                } else {
                    $targetAdminId = (int)($_POST['target_admin_id'] ?? 0);
                    if ($targetAdminId === (int)$currentAdmin['id']) {
                        $msg = 'ไม่สามารถลบบัญชีของตนเองที่กำลังล็อกอินอยู่ได้';
                        $msgType = 'error';
                    } elseif ($targetAdminId > 0) {
                        $stmtGet = $db->prepare("SELECT name, email, username, role FROM admins WHERE id = ?");
                        $stmtGet->execute([$targetAdminId]);
                        $targetUser = $stmtGet->fetch();

                        if (!$targetUser) {
                            $msg = 'ไม่พบข้อมูลบัญชีที่ต้องการลบ';
                            $msgType = 'error';
                        } elseif ($curRole === 'admin' && $targetUser['role'] !== 'staff') {
                            $msg = 'ผู้ดูแลทั่วไปสามารถลบได้เฉพาะบัญชีผู้ประสานงานรถ (Staff) เท่านั้น';
                            $msgType = 'error';
                        } else {
                            $stmtDel = $db->prepare("DELETE FROM admins WHERE id = ?");
                            $stmtDel->execute([$targetAdminId]);
                            $displayId = $targetUser['email'] ?: $targetUser['username'];
                            $msg = "ลบบัญชีคุณ {$targetUser['name']} ({$displayId}) เรียบร้อยแล้ว";
                        }
                    }
                }

            } elseif ($action === 'delete_booking') {
                $bookingId = (int)($_POST['booking_id'] ?? 0);
                $stmt = $db->prepare("DELETE FROM bookings WHERE id = ?");
                $stmt->execute([$bookingId]);
                $msg = 'ลบรายชื่อผู้โดยสารเรียบร้อยแล้ว';

            } elseif ($action === 'delete_vehicle') {
                $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
                if ($vehicleId > 0) {
                    $stmtCheck = $db->prepare("SELECT COUNT(*) FROM bookings WHERE vehicle_id = ?");
                    $stmtCheck->execute([$vehicleId]);
                    $passengerCount = (int)$stmtCheck->fetchColumn();

                    if ($passengerCount > 0) {
                        $msg = "ไม่สามารถลบรถคันนี้ได้ เนื่องจากมีผู้โดยสารลงชื่ออยู่แล้ว {$passengerCount} คน กรุณาย้ายหรือลบผู้โดยสารออกก่อน";
                        $msgType = 'error';
                    } else {
                        $stmt = $db->prepare("DELETE FROM vehicles WHERE id = ?");
                        $stmt->execute([$vehicleId]);
                        $msg = 'ลบรถคันดังกล่าวเรียบร้อยแล้ว';
                    }
                }

            } elseif ($action === 'clear_all_bookings') {
                if (!$isSuper) {
                    $msg = 'เฉพาะ Super Admin เท่านั้นที่สามารถล้างรายชื่อทั้งรอบได้';
                    $msgType = 'error';
                } else {
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

            } elseif ($action === 'create_trip') {
                $curRole = $currentAdmin['role'] ?? 'staff';
                if ($curRole === 'staff') {
                    $msg = 'ขออภัย ผู้ประสานงานรถไม่มีสิทธิ์สร้างรอบการเดินทาง';
                    $msgType = 'error';
                } else {
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
                            $newTripId = getDbLastInsertId($db, 'trips');

                            $stmtVeh = $db->prepare("
                                INSERT INTO vehicles (trip_id, type, vehicle_type_label, vehicle_number, name, total_seats, header_color)
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ");
                            
                            $carNum = 1;
                            for ($i = 1; $i <= $vanCount; $i++) {
                                $stmtVeh->execute([$newTripId, 'van', 'รถตู้ (10 ที่นั่ง VIP)', $carNum, "คันที่ {$carNum}", 10, 'green']);
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
                            $_GET['trip_id'] = $newTripId;
                        } catch (Exception $e) {
                            $db->rollBack();
                            $msg = 'เกิดข้อผิดพลาดในการสร้างรอบ: ' . $e->getMessage();
                            $msgType = 'error';
                        }
                    }
                }

            } elseif ($action === 'duplicate_trip') {
                $curRole = $currentAdmin['role'] ?? 'staff';
                if ($curRole === 'staff') {
                    $msg = 'ขออภัย ผู้ประสานงานรถไม่มีสิทธิ์คัดลอกรอบ';
                    $msgType = 'error';
                } else {
                    $sourceTripId = (int)($_POST['source_trip_id'] ?? 0);
                    if ($sourceTripId > 0) {
                        $stmtSrc = $db->prepare("SELECT * FROM trips WHERE id = ?");
                        $stmtSrc->execute([$sourceTripId]);
                        $srcTrip = $stmtSrc->fetch();

                        if ($srcTrip) {
                            $db->beginTransaction();
                            try {
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
                                    $srcTrip['deadline_notice'], $srcTrip['notes'] ?? ''
                                ]);
                                $newTripId = getDbLastInsertId($db, 'trips');

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
                                        $v['name'], $v['license_plate'] ?? '', $v['driver_name'] ?? '', $v['driver_phone'] ?? '',
                                        $v['total_seats'], $v['header_color'] ?? 'green'
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
                }

            } elseif ($action === 'delete_trip') {
                if (!$isSuper) {
                    $msg = 'เฉพาะ Super Admin เท่านั้นที่สามารถลบรอบการเดินทางได้';
                    $msgType = 'error';
                } else {
                    $targetTripId = (int)($_POST['trip_id'] ?? 0);
                    if ($targetTripId > 0) {
                        $stmtTrip = $db->prepare("SELECT title, trip_date FROM trips WHERE id = ?");
                        $stmtTrip->execute([$targetTripId]);
                        $t = $stmtTrip->fetch();
                        if ($t) {
                            $db->beginTransaction();
                            try {
                                $db->prepare("DELETE FROM bookings WHERE trip_id = ?")->execute([$targetTripId]);
                                $db->prepare("DELETE FROM vehicles WHERE trip_id = ?")->execute([$targetTripId]);
                                $db->prepare("DELETE FROM trips WHERE id = ?")->execute([$targetTripId]);
                                $db->commit();
                                $msg = "ลบรอบการเดินทาง '{$t['title']} (" . formatThaiDate($t['trip_date']) . ")' เรียบร้อยแล้ว";
                                $msgType = 'success';
                                unset($_GET['trip_id']);
                            } catch (Exception $e) {
                                $db->rollBack();
                                $msg = 'เกิดข้อผิดพลาดในการลบรอบ: ' . $e->getMessage();
                                $msgType = 'error';
                            }
                        }
                    }
                }
            }
        }
    }
}

$suggestedDate = getNextFirstSunday();
$upcomingSundays = getUpcomingFirstSundays(6);

$stmtTrips = $db->query("
    SELECT t.*,
           COALESCE(v_stat.vehicle_count, 0) as vehicle_count,
           COALESCE(v_stat.total_capacity, 0) as total_capacity,
           COALESCE(b_stat.booking_count, 0) as booking_count
    FROM trips t
    LEFT JOIN (
        SELECT trip_id, COUNT(id) as vehicle_count, SUM(total_seats) as total_capacity
        FROM vehicles GROUP BY trip_id
    ) v_stat ON v_stat.trip_id = t.id
    LEFT JOIN (
        SELECT trip_id, COUNT(id) as booking_count
        FROM bookings GROUP BY trip_id
    ) b_stat ON b_stat.trip_id = t.id
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

        // ดึงรายชื่อผู้โดยสารทั้งหมดในรอบนี้สำหรับตารางข้อมูลโดยละเอียด
        $stmtAllP = $db->prepare("
            SELECT b.*, v.name as vehicle_name, v.vehicle_type_label, v.type as vehicle_type, v.vehicle_number
            FROM bookings b
            JOIN vehicles v ON b.vehicle_id = v.id
            WHERE v.trip_id = ?
            ORDER BY v.vehicle_number ASC, b.seat_number ASC
        ");
        $stmtAllP->execute([$currentTrip['id']]);
        $allTripPassengers = $stmtAllP->fetchAll();

        $roundTripCount = 0;
        $oneWayCount = 0;
        foreach ($allTripPassengers as $ap) {
            $tt = $ap['travel_type'] ?? '';
            if (mb_stripos($tt, 'กลับ') !== false && mb_stripos($tt, 'ไป') !== false) {
                $roundTripCount++;
            } else {
                $oneWayCount++;
            }
        }

        $totalCapacity = 0;
        foreach ($tripVehicles as $v) {
            $totalCapacity += (int)$v['total_seats'];
        }
        $totalBooked = count($allTripPassengers);
        $totalAvailable = max(0, $totalCapacity - $totalBooked);
    }
}

// ดึงรายชื่อ Admin ตามระดับสิทธิ์ (Requirement 6: ดูแลระบบสูงสุดเห็นทั้งหมด, ผู้ดูแลทั่วไปเห็นเฉพาะผู้ประสานงานรถ, ผู้ประสานงานรถไม่เห็นของใครเลย)
$currentAdminRole = $currentAdmin['role'] ?? 'staff';
if ($isSuper) {
    // ดูแลระบบสูงสุด เห็นทั้งหมด
    $adminsList = $db->query("SELECT id, email, username, name, role, is_active, last_login, created_at FROM admins ORDER BY id ASC")->fetchAll();
} elseif ($currentAdminRole === 'admin') {
    // ผู้ดูแลทั่วไป จะเห็นเฉพาะ ผู้ประสานงานรถ
    $stmtAdm = $db->prepare("SELECT id, email, username, name, role, is_active, last_login, created_at FROM admins WHERE role = 'staff' ORDER BY id ASC");
    $stmtAdm->execute();
    $adminsList = $stmtAdm->fetchAll();
} else {
    // ผู้ประสานงานรถ จะไม่เห็นของใครเลย
    $adminsList = [];
}

define('APP_TITLE', 'แผงควบคุมระบบ Admin');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="max-w-7xl 2xl:max-w-[1440px] mx-auto px-4 sm:px-6 lg:px-8 py-6 sm:py-8">

    <!-- Top Admin Header -->
    <div class="flex flex-col md:flex-row md:items-center md:justify-between pb-6 border-b border-charcoal-border gap-4 mb-6">
        <div>
            <div class="flex items-center space-x-2 text-xs text-charcoal-muted font-medium mb-1">
                <span class="px-2.5 py-0.5 rounded-full font-semibold text-[11px] <?= $isSuper ? 'bg-purple-100 text-purple-800 border border-purple-200' : ($currentAdminRole === 'admin' ? 'bg-accent-subtle text-accent border border-accent-border' : 'bg-amber-100 text-amber-800 border border-amber-200') ?>">
                    <i class="fa-solid <?= $isSuper ? 'fa-crown mr-1 text-purple-600' : ($currentAdminRole === 'admin' ? 'fa-shield-halved mr-1 text-accent' : 'fa-clipboard-user mr-1 text-amber-600') ?>"></i>
                    <?= $currentAdmin['role_label'] ?? 'Admin Mode' ?>
                </span>
                <span class="text-charcoal-subtle">/</span>
                <span class="text-charcoal-muted">ผู้ดูแล: <strong class="text-charcoal"><?= clean($currentAdmin['name']) ?></strong> (<?= clean($currentAdmin['email'] ?: $currentAdmin['username']) ?>)</span>
            </div>
            <h1 class="text-2xl font-bold text-charcoal tracking-tight">แผงควบคุมระบบบริหารยานพาหนะ</h1>
            <p class="text-xs text-charcoal-muted mt-0.5">จัดการรอบเดินทาง จัดสรรยานพาหนะ รายชื่อผู้โดยสาร และจัดการสิทธิ์ผู้ดูแลระบบ</p>
        </div>

        <!-- Top Desktop Quick Action Buttons -->
        <div class="flex flex-wrap items-center gap-2">
            <?php if ($isSuper || $currentAdminRole === 'admin'): ?>
                <button type="button" onclick="openNewTripModal()" class="bg-charcoal hover:bg-charcoal-secondary text-white font-medium px-3.5 py-2 rounded-lg text-xs transition flex items-center space-x-1.5 shadow-subtle active:scale-95 cursor-pointer">
                    <i class="fa-solid fa-plus text-xs"></i>
                    <span>+ สร้างรอบใหม่</span>
                </button>
                <button type="button" onclick="openAdminMgmtModal()" class="bg-surface hover:bg-surface-muted text-charcoal border border-charcoal-border font-medium px-3.5 py-2 rounded-lg text-xs transition flex items-center space-x-1.5 shadow-subtle active:scale-95 cursor-pointer">
                    <i class="fa-solid fa-users-gear text-accent text-xs"></i>
                    <span>จัดการสิทธิ์แอดมิน (Gmail)</span>
                </button>
            <?php endif; ?>
            <a href="/index.php" class="bg-surface hover:bg-surface-muted text-charcoal border border-charcoal-border font-medium px-3.5 py-2 rounded-lg text-xs transition flex items-center space-x-1.5 shadow-subtle">
                <i class="fa-solid fa-arrow-up-right-from-square text-charcoal-muted text-[11px]"></i>
                <span>ดูหน้าบ้าน</span>
            </a>
        </div>
    </div>

    <!-- Alert Messages -->
    <?php if (!empty($msg)): ?>
        <div class="p-3.5 mb-6 rounded-md flex items-center space-x-2.5 <?= $msgType === 'success' ? 'bg-sage-50 text-sage-800 border border-sage-200' : 'bg-mutedred-50 text-mutedred-800 border border-mutedred-200' ?> shadow-subtle">
            <i class="fa-solid <?= $msgType === 'success' ? 'fa-check text-sage-600' : 'fa-triangle-exclamation text-mutedred-600' ?> text-sm"></i>
            <span class="text-xs sm:text-sm font-medium"><?= $msg ?></span>
        </div>
    <?php endif; ?>

    <!-- Quick Statistics Grid (Responsive CSS Grid) -->
    <?php
    $totalCapacityInSelected = 0;
    $totalBookedInSelected = 0;
    if ($currentTrip && !empty($tripVehicles)) {
        foreach ($tripVehicles as $v) {
            $totalCapacityInSelected += (int)$v['total_seats'];
            $totalBookedInSelected += (int)$v['booked_count'];
        }
    }
    $remainingSeatsInSelected = max(0, $totalCapacityInSelected - $totalBookedInSelected);
    $fillPercent = $totalCapacityInSelected > 0 ? min(100, round(($totalBookedInSelected / $totalCapacityInSelected) * 100)) : 0;
    ?>
    <div class="grid-stats-cards grid grid-cols-2 lg:grid-cols-4 gap-3.5 mb-6">
        <div class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">รอบการเดินทางปัจจุบัน</span>
                <i class="fa-solid fa-calendar-check stat-card-icon"></i>
            </div>
            <div class="text-sm font-bold text-charcoal truncate" title="<?= clean($currentTrip['title'] ?? '-') ?>">
                <?= clean($currentTrip['title'] ?? 'ไม่มีรอบที่เลือก') ?>
            </div>
            <div class="text-[11px] text-charcoal-muted mt-0.5">
                <?= $currentTrip ? formatThaiDate($currentTrip['trip_date']) : '-' ?>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">จำนวนคันรถในรอบ</span>
                <i class="fa-solid fa-van-shuttle stat-card-icon"></i>
            </div>
            <div class="stat-card-value">
                <?= count($tripVehicles) ?> <span class="text-xs font-normal text-charcoal-muted">คัน</span>
            </div>
            <div class="text-[11px] text-charcoal-muted mt-0.5">
                ความจุรวม <?= number_format($totalCapacityInSelected) ?> ที่นั่ง
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">ผู้ลงชื่อแล้ว</span>
                <i class="fa-solid fa-users stat-card-icon"></i>
            </div>
            <div class="stat-card-value text-accent">
                <?= number_format($totalBookedInSelected) ?> <span class="text-xs font-normal text-charcoal-muted">/ <?= number_format($totalCapacityInSelected) ?></span>
            </div>
            <div class="w-full bg-charcoal-border/50 h-1.5 rounded-full overflow-hidden mt-1.5">
                <div class="bg-accent h-full transition-all duration-500" style="width: <?= $fillPercent ?>%"></div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-card-header">
                <span class="stat-card-title">ที่นั่งว่างคงเหลือ</span>
                <i class="fa-solid fa-chair stat-card-icon"></i>
            </div>
            <div class="stat-card-value <?= $remainingSeatsInSelected > 0 ? 'text-sage-700' : 'text-mutedred-700' ?>">
                <?= number_format($remainingSeatsInSelected) ?> <span class="text-xs font-normal text-charcoal-muted">ที่นั่ง</span>
            </div>
            <div class="text-[11px] text-charcoal-muted mt-0.5">
                <?= $remainingSeatsInSelected > 0 ? 'พร้อมให้ลงทะเบียน' : 'ที่นั่งเต็มทุกคันแล้ว' ?>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
        
        <!-- Left: Trip List (Sidebar) -->
        <div class="lg:col-span-4 xl:col-span-3 space-y-4">
            <div class="bg-surface rounded-xl border border-charcoal-border p-4 shadow-card">
                <div class="flex items-center justify-between mb-3 pb-2 border-b border-charcoal-border/60">
                    <span class="text-xs font-semibold text-charcoal uppercase tracking-wider">รอบการเดินทาง</span>
                    <?php if ($isSuper || $currentAdminRole === 'admin'): ?>
                        <button type="button" onclick="openNewTripModal()" class="text-xs font-semibold text-accent hover:text-accent-hover flex items-center space-x-1 cursor-pointer">
                            <i class="fa-solid fa-plus text-[10px]"></i>
                            <span>สร้างรอบ</span>
                        </button>
                    <?php else: ?>
                        <span class="text-[11px] text-charcoal-subtle font-medium"><?= count($trips) ?> รอบในระบบ</span>
                    <?php endif; ?>
                </div>

                <div class="space-y-2.5">
                    <?php foreach ($trips as $t): 
                        $isSelected = $t['id'] === $currentTripId;
                    ?>
                        <div class="p-3.5 rounded-lg border transition-all cursor-pointer <?= $isSelected ? 'border-accent bg-accent-subtle/40 shadow-subtle ring-1 ring-accent' : 'border-charcoal-border hover:border-charcoal-muted/40 bg-surface hover:bg-surface-muted/60' ?>" onclick="location.href='?trip_id=<?= $t['id'] ?>'">
                            <div class="flex items-start justify-between">
                                <h3 class="font-semibold text-charcoal text-sm leading-snug"><?= clean($t['title']) ?></h3>
                                <span class="text-[11px] font-semibold px-2 py-0.5 rounded-sm border <?= $t['is_active'] ? 'bg-emerald-100 text-emerald-800 border-emerald-300' : 'bg-rose-100 text-rose-800 border-rose-300' ?>">
                                    <?= $t['is_active'] ? 'เปิดรับลงชื่อ' : 'ปิดรับการลงชื่อ' ?>
                                </span>
                            </div>
                            <div class="text-xs text-charcoal-muted mt-1 flex items-center space-x-1.5">
                                <i class="fa-regular fa-calendar text-[11px] text-charcoal-subtle"></i>
                                <span><?= formatThaiDate($t['trip_date']) ?></span>
                            </div>
                            <div class="mt-2.5 pt-2 border-t border-charcoal-border/50 flex justify-between text-xs text-charcoal-muted">
                                <span><?= $t['vehicle_count'] ?> คัน</span>
                                <span class="font-semibold text-charcoal">ลงชื่อ <?= $t['booking_count'] ?> / <?= $t['total_capacity'] ?? 0 ?> ที่นั่ง</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Right: Manage Selected Trip & Vehicles -->
        <div class="lg:col-span-8 xl:col-span-9 space-y-6">
            <?php if ($currentTrip): 
                $isOpen = (int)$currentTrip['is_active'] === 1;
            ?>
                
                <!-- 1. Trip Header Bar with Desktop Actions -->
                <div class="bg-surface rounded-xl border border-charcoal-border p-5 shadow-card">
                    <div class="flex flex-col xl:flex-row xl:items-center justify-between gap-4 pb-4 border-b border-charcoal-border/60">
                        <div class="min-w-0">
                            <span class="text-[11px] uppercase tracking-wider text-charcoal-subtle font-semibold">รอบการเดินทางที่เลือก</span>
                            <h2 class="text-xl font-bold text-charcoal tracking-tight truncate"><?= clean($currentTrip['title']) ?></h2>
                            <div class="text-xs text-charcoal-muted mt-1 flex flex-wrap items-center gap-2">
                                <span class="flex items-center"><i class="fa-regular fa-calendar mr-1.5 text-charcoal-subtle"></i><?= formatThaiDate($currentTrip['trip_date']) ?></span>
                                <span class="text-charcoal-border">•</span>
                                <span class="flex items-center"><i class="fa-regular fa-clock mr-1.5 text-charcoal-subtle"></i>เวลา <?= clean($currentTrip['departure_time']) ?></span>
                            </div>
                        </div>

                        <!-- Action Buttons on Desktop -->
                        <div class="flex flex-wrap items-center gap-2">
                            <!-- ปุ่มสถานะ เปิดรับ / ปิดรับ -->
                            <form method="POST" class="inline m-0">
                                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                <input type="hidden" name="action" value="toggle_trip_status">
                                <input type="hidden" name="trip_id" value="<?= $currentTrip['id'] ?>">
                                <input type="hidden" name="new_status" value="<?= $isOpen ? '0' : '1' ?>">
                                
                                <button type="submit" 
                                        class="px-3.5 py-2 rounded-lg text-xs font-bold transition shadow-subtle flex items-center space-x-2 border <?= $isOpen ? 'bg-emerald-600 hover:bg-emerald-700 text-white border-emerald-700' : 'bg-rose-700 hover:bg-rose-800 text-white border-rose-800' ?> cursor-pointer">
                                    <i class="fa-solid <?= $isOpen ? 'fa-toggle-on text-base' : 'fa-toggle-off text-base text-rose-200' ?>"></i>
                                    <span>สถานะ: <?= $isOpen ? 'เปิดรับลงชื่อ' : 'ปิดรับการลงชื่อ' ?></span>
                                </button>
                            </form>

                            <?php if ($isSuper || $currentAdminRole === 'admin'): ?>
                                <form method="POST" onsubmit="return confirm('ยืนยันคัดลอกโครงสร้างรอบนี้ (คันรถทั้งหมด) ไปสร้างเป็นรอบใหม่สำหรับเดือนถัดไป หรือไม่?\n(รอบใหม่จะมีรถครบทุกคันและที่นั่งว่าง 0 คน พร้อมเปิดรับลงชื่อทันที)')" class="inline m-0">
                                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                    <input type="hidden" name="action" value="duplicate_trip">
                                    <input type="hidden" name="source_trip_id" value="<?= $currentTrip['id'] ?>">
                                    <button type="submit" class="px-3 py-2 rounded-lg text-xs font-medium bg-surface hover:bg-surface-muted text-charcoal border border-charcoal-border transition flex items-center space-x-1.5 shadow-subtle cursor-pointer" title="คัดลอกคันรถทั้งหมดไปสร้างรอบใหม่สำหรับเดือนถัดไป">
                                        <i class="fa-solid fa-copy text-accent text-xs"></i>
                                        <span>คัดลอกรอบ</span>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ($isSuper): ?>
                                <form method="POST" onsubmit="return confirm('ยืนยันล้างรายชื่อผู้ลงทะเบียนในรอบนี้ทั้งหมดหรือไม่?\n(คันรถจะยังคงอยู่ครบ 100% ที่นั่งจะว่าง 0 คน)')" class="inline m-0">
                                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                    <input type="hidden" name="action" value="clear_all_bookings">
                                    <input type="hidden" name="trip_id" value="<?= $currentTrip['id'] ?>">
                                    <button type="submit" class="px-3 py-2 rounded-lg text-xs font-medium bg-surface hover:bg-rose-50 text-rose-700 border border-charcoal-border hover:border-rose-300 transition flex items-center space-x-1.5 shadow-subtle cursor-pointer" title="ล้างรายชื่อทั้งหมดในรอบนี้">
                                        <i class="fa-solid fa-trash-can text-rose-600 text-xs"></i>
                                        <span>ล้างรายชื่อ</span>
                                    </button>
                                </form>

                                <form method="POST" onsubmit="return confirm('⚠️ คำเตือนสำคัญ!\nคุณแน่ใจหรือไม่ว่าต้องการลบรอบการเดินทางนี้ทิ้งอย่างถาวร?\n(ข้อมูลคันรถและรายชื่อผู้ลงชื่อในรอบนี้ทั้งหมดจะถูกลบ และไม่สามารถกู้คืนได้)')" class="inline m-0">
                                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                    <input type="hidden" name="action" value="delete_trip">
                                    <input type="hidden" name="trip_id" value="<?= $currentTrip['id'] ?>">
                                    <button type="submit" class="px-3 py-2 rounded-lg text-xs font-medium bg-rose-600 hover:bg-rose-700 text-white transition flex items-center space-x-1.5 shadow-subtle cursor-pointer" title="ลบรอบนี้ทิ้งอย่างถาวร">
                                        <i class="fa-solid fa-trash text-xs"></i>
                                        <span>ลบรอบนี้</span>
                                    </button>
                                </form>
                            <?php endif; ?>

                            <a href="/admin/export.php?trip_id=<?= $currentTrip['id'] ?>" target="_blank" class="px-3 py-2 rounded-lg text-xs font-medium bg-surface hover:bg-surface-muted text-charcoal border border-charcoal-border transition flex items-center space-x-1.5 shadow-subtle" title="พิมพ์ใบรายชื่อ">
                                <i class="fa-solid fa-print text-charcoal-muted text-xs"></i>
                                <span>พิมพ์</span>
                            </a>
                        </div>
                    </div>

                    <!-- Quick Editable Settings Form -->
                    <form method="POST" class="mt-4 space-y-3 text-xs">
                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                        <input type="hidden" name="action" value="update_trip_details">
                        <input type="hidden" name="trip_id" value="<?= $currentTrip['id'] ?>">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-charcoal font-medium mb-1">ชื่องาน / กิจกรรม</label>
                                <input type="text" name="title" value="<?= clean($currentTrip['title']) ?>" required class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-md text-xs font-medium text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                            </div>
                            <div>
                                <label class="block text-charcoal font-medium mb-1">วันที่เดินทาง</label>
                                <input type="date" name="trip_date" value="<?= clean($currentTrip['trip_date']) ?>" required class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-md text-xs font-medium text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-charcoal font-medium mb-1">จุดขึ้นรถขาไป</label>
                                <input type="text" name="pickup_time_info" value="<?= clean($currentTrip['pickup_time_info']) ?>" class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-md text-xs text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                            </div>
                            <div>
                                <label class="block text-charcoal font-medium mb-1">จุดขึ้นรถขากลับ</label>
                                <input type="text" name="return_time_info" value="<?= clean($currentTrip['return_time_info']) ?>" class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-md text-xs text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                            </div>
                        </div>

                        <div>
                            <label class="block text-charcoal font-medium mb-1">ข้อความแจ้งเตือน</label>
                            <input type="text" name="notice_red" value="<?= clean($currentTrip['notice_red']) ?>" class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-md text-xs text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                        </div>

                        <div class="flex justify-end pt-1">
                            <button type="submit" class="bg-accent hover:bg-accent-hover text-white font-medium px-4 py-2 rounded-md text-xs transition shadow-subtle">
                                บันทึกการแก้ไข
                            </button>
                        </div>
                    </form>
                </div>

                <!-- 2. ตารางข้อมูลผู้โดยสารและการจัดรถ (Unified Detailed Table View) -->
                <div class="space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-charcoal-border">
                        <div class="flex items-center space-x-2.5">
                            <div class="w-8 h-8 rounded-lg bg-accent-subtle text-accent flex items-center justify-center font-bold">
                                <i class="fa-solid fa-table-list text-sm"></i>
                            </div>
                            <div>
                                <h2 class="text-sm font-bold text-charcoal">ข้อมูลผู้โดยสารและการจัดรถ</h2>
                                <p class="text-[11px] text-charcoal-muted">รายชื่อผู้โดยสาร คัดกรอง ค้นหา จัดสรรรถ และจัดการข้อมูลรอบการเดินทาง</p>
                            </div>
                        </div>

                        <!-- Top Quick Action Buttons -->
                        <div class="flex items-center gap-2">
                            <button type="button" onclick="openAddVehicleModal()" class="bg-accent hover:bg-accent-hover text-white text-xs font-semibold px-3.5 py-1.5 rounded-md shadow-subtle flex items-center space-x-1.5 active:scale-95 transition">
                                <i class="fa-solid fa-plus text-xs"></i>
                                <span>+ เพิ่มรถ</span>
                            </button>
                            <a href="?trip_id=<?= $currentTrip['id'] ?>&export=csv" class="px-3.5 py-1.5 rounded-md text-xs font-medium bg-surface hover:bg-surface-muted text-charcoal border border-charcoal-border transition flex items-center space-x-1 shadow-subtle" title="ส่งออกไฟล์ CSV">
                                <i class="fa-solid fa-file-arrow-down text-accent"></i>
                                <span>ส่งออก CSV</span>
                            </a>
                        </div>
                    </div>

                    <!-- Summary mini-stats -->
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div class="p-3 bg-surface rounded-lg border border-charcoal-border shadow-subtle flex items-center justify-between">
                            <div>
                                <div class="text-[11px] text-charcoal-muted font-medium">ผู้ลงชื่อทั้งหมด</div>
                                <div class="text-base font-bold text-accent"><?= $totalBooked ?> <span class="text-xs font-normal text-charcoal-muted">คน</span></div>
                            </div>
                            <div class="w-8 h-8 rounded-full bg-accent-subtle text-accent flex items-center justify-center">
                                <i class="fa-solid fa-users text-xs"></i>
                            </div>
                        </div>
                        <div class="p-3 bg-surface rounded-lg border border-charcoal-border shadow-subtle flex items-center justify-between">
                            <div>
                                <div class="text-[11px] text-charcoal-muted font-medium">ที่นั่งรวมทั้งหมด</div>
                                <div class="text-base font-bold text-charcoal">
                                    <?= $totalBooked ?>/<?= $totalCapacity ?> 
                                    <span class="text-[11px] font-normal <?= $totalAvailable > 0 ? 'text-sage-700' : 'text-mutedred-700' ?>">(ว่าง <?= $totalAvailable ?>)</span>
                                </div>
                            </div>
                            <div class="w-8 h-8 rounded-full bg-surface-muted text-charcoal flex items-center justify-center">
                                <i class="fa-solid fa-chair text-xs"></i>
                            </div>
                        </div>
                        <div class="p-3 bg-surface rounded-lg border border-charcoal-border shadow-subtle flex items-center justify-between">
                            <div>
                                <div class="text-[11px] text-charcoal-muted font-medium">เดินทางไป และ กลับ</div>
                                <div class="text-base font-bold text-sage-700"><?= $roundTripCount ?> <span class="text-xs font-normal text-charcoal-muted">คน</span></div>
                            </div>
                            <div class="w-8 h-8 rounded-full bg-sage-50 text-sage-700 flex items-center justify-center">
                                <i class="fa-solid fa-arrows-left-right text-xs"></i>
                            </div>
                        </div>
                        <div class="p-3 bg-surface rounded-lg border border-charcoal-border shadow-subtle flex items-center justify-between">
                            <div>
                                <div class="text-[11px] text-charcoal-muted font-medium">เดินทางเที่ยวเดียว</div>
                                <div class="text-base font-bold text-charcoal"><?= $oneWayCount ?> <span class="text-xs font-normal text-charcoal-muted">คน</span></div>
                            </div>
                            <div class="w-8 h-8 rounded-full bg-charcoal-divider text-charcoal flex items-center justify-center">
                                <i class="fa-solid fa-arrow-right text-xs"></i>
                            </div>
                        </div>
                    </div>

                    <!-- สถานะรถแต่ละคัน (Vehicle Status & Quick Filter Chips) -->
                    <div class="bg-surface p-3.5 rounded-lg border border-charcoal-border shadow-card space-y-2.5">
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <div class="flex items-center space-x-2">
                                <i class="fa-solid fa-van-shuttle text-xs text-accent"></i>
                                <span class="text-xs font-bold text-charcoal">สถานะรถในรอบนี้ (<?= count($tripVehicles) ?> คัน)</span>
                            </div>
                            <span class="text-[11px] text-charcoal-muted">คลิกที่ป้ายรถเพื่อกรองดูรายชื่อเฉพาะคัน หรือกดถังขยะเพื่อลบรถที่ว่าง</span>
                        </div>

                        <?php if (empty($tripVehicles)): ?>
                            <div class="p-4 bg-surface-muted rounded-md text-center text-charcoal-muted text-xs border border-dashed border-charcoal-border">
                                <i class="fa-solid fa-van-shuttle text-xl mb-1 text-charcoal-subtle block"></i>
                                ยังไม่มีรถในรอบนี้ คลิก "+ เพิ่มรถ" ด้านบนเพื่อเริ่มจัดรถ
                            </div>
                        <?php else: ?>
                            <div class="flex flex-wrap items-center gap-2">
                                <!-- ชิปดูรถทั้งหมด -->
                                <button type="button" 
                                        onclick="filterByVehicleChip('')" 
                                        data-vname=""
                                        class="vehicle-chip-btn px-2.5 py-1 rounded text-xs font-bold transition flex items-center space-x-1.5 bg-accent text-white shadow-xs">
                                    <i class="fa-solid fa-list-check text-[11px]"></i>
                                    <span>ทุกคันรถ (<?= count($allTripPassengers ?? []) ?> คน)</span>
                                </button>

                                <?php foreach ($tripVehicles as $v): 
                                    $isFull = $v['booked_count'] >= $v['total_seats'];
                                    $isEmpty = $v['booked_count'] == 0;
                                    $vehLabel = clean($v['vehicle_type_label'] ?? ($v['type'] === 'van' ? 'รถตู้' : 'รถบัส'));
                                ?>
                                    <div class="inline-flex items-center rounded-md border border-charcoal-border bg-surface-muted/60 p-0.5 text-xs transition hover:border-charcoal shadow-xs">
                                        <button type="button" 
                                                onclick="filterByVehicleChipFromBtn(this)" 
                                                data-vname="<?= htmlspecialchars($v['name'], ENT_QUOTES, 'UTF-8') ?>"
                                                class="vehicle-chip-btn px-2.5 py-1 rounded text-xs font-medium text-charcoal hover:text-accent hover:bg-surface transition flex items-center space-x-1.5"
                                                title="คลิกเพื่อกรองดูเฉพาะ <?= clean($v['name']) ?>">
                                            <i class="fa-solid <?= $v['type'] === 'bus' ? 'fa-bus' : 'fa-van-shuttle' ?> text-[11px]"></i>
                                            <span class="font-semibold"><?= clean($v['name']) ?></span>
                                            <span class="text-[11px] <?= $isFull ? 'text-mutedred-700 font-bold' : ($isEmpty ? 'text-charcoal-subtle' : 'text-sage-700') ?>">
                                                (<?= $v['booked_count'] ?>/<?= $v['total_seats'] ?>)
                                            </span>
                                        </button>

                                        <?php if ($isEmpty): ?>
                                            <form method="POST" onsubmit="return confirm('ยืนยันลบรถคันนี้หรือไม่? (รถคันนี้ยังไม่มีผู้โดยสาร)')" class="inline m-0">
                                                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                                <input type="hidden" name="action" value="delete_vehicle">
                                                <input type="hidden" name="vehicle_id" value="<?= $v['id'] ?>">
                                                <button type="submit" class="p-1 px-1.5 text-charcoal-subtle hover:text-mutedred-700 hover:bg-mutedred-50 rounded transition text-[11px]" title="ลบรถคันนี้">
                                                    <i class="fa-solid fa-trash-can"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Real-time Filter Bar -->
                    <div class="bg-surface p-3.5 rounded-lg border border-charcoal-border shadow-card flex flex-col sm:flex-row gap-2.5 items-stretch sm:items-center justify-between">
                        <div class="flex-1 relative">
                            <input type="text" 
                                   id="tableSearchInput" 
                                   oninput="filterDetailedTable()" 
                                   placeholder="ค้นหาชื่อ, ฉายา, เบอร์โทร, อายุ, หมายเหตุ..." 
                                   class="w-full pl-8 pr-3 py-1.5 bg-surface border border-charcoal-border rounded-md text-xs text-charcoal placeholder-charcoal-subtle focus:ring-1 focus:ring-accent focus:border-accent outline-none transition">
                            <i class="fa-solid fa-magnifying-glass absolute left-2.5 top-2 text-charcoal-subtle text-xs"></i>
                        </div>

                        <div class="flex items-center gap-2">
                            <select id="tableVehicleFilter" onchange="onVehicleSelectChange()" class="px-2.5 py-1.5 bg-surface border border-charcoal-border rounded-md text-xs text-charcoal focus:ring-1 focus:ring-accent focus:border-accent outline-none transition">
                                <option value="">-- ทุกคันรถ --</option>
                                <?php foreach ($tripVehicles as $v): ?>
                                    <option value="<?= clean($v['name']) ?>"><?= clean($v['name']) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <select id="tableTravelFilter" onchange="filterDetailedTable()" class="px-2.5 py-1.5 bg-surface border border-charcoal-border rounded-md text-xs text-charcoal focus:ring-1 focus:ring-accent focus:border-accent outline-none transition">
                                <option value="">-- การเดินทางทั้งหมด --</option>
                                <option value="กลับ">ไป และ กลับ</option>
                                <option value="อย่างเดียว">ไปอย่างเดียว</option>
                            </select>

                            <button type="button" onclick="resetDetailedTableFilter()" class="px-2.5 py-1.5 bg-surface hover:bg-surface-muted text-charcoal-muted hover:text-charcoal border border-charcoal-border rounded-md text-xs transition" title="ล้างตัวกรอง">
                                <i class="fa-solid fa-rotate-left"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Bulk Delete Action Bar & Table Form -->
                    <form id="bulkDeleteForm" method="POST" action="?trip_id=<?= $currentTripId ?>">
                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                        <input type="hidden" name="action" value="bulk_delete_passengers">

                        <div class="flex items-center justify-between py-1 mb-2">
                            <div class="flex items-center space-x-2">
                                <span id="selectedCountBadge" class="hidden text-xs font-semibold px-2.5 py-0.5 rounded bg-accent-subtle text-accent border border-accent-border">
                                    เลือก <strong id="selectedCountNum">0</strong> รายการ
                                </span>
                                <button type="button" 
                                        id="btnBulkDelete" 
                                        onclick="submitBulkDelete()" 
                                        class="hidden px-3 py-1 rounded-md text-xs font-medium bg-rose-50 hover:bg-rose-100 text-rose-700 border border-rose-300 transition flex items-center space-x-1 shadow-subtle">
                                    <i class="fa-solid fa-trash-can text-rose-600"></i>
                                    <span>ลบรายการที่เลือก</span>
                                </button>
                            </div>
                            <div class="text-[11px] text-charcoal-muted">
                                แสดง <strong id="visibleCount" class="text-charcoal"><?= count($allTripPassengers ?? []) ?></strong> คน
                            </div>
                        </div>

                        <div class="bg-surface rounded-lg border border-charcoal-border shadow-card overflow-hidden">
                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs min-w-[1000px]">
                                    <thead class="bg-surface-muted text-charcoal-muted font-semibold border-b border-charcoal-border text-[11px] uppercase tracking-wider">
                                        <tr>
                                            <th class="py-2.5 px-3 w-10 text-center">
                                                <input type="checkbox" id="selectAllDetailedCheckbox" onchange="toggleSelectAllDetailed(this)" class="w-3.5 h-3.5 rounded border-charcoal-border text-accent focus:ring-accent cursor-pointer" title="เลือกทั้งหมด">
                                            </th>
                                            <th class="py-2.5 px-2.5 w-10 text-center">#</th>
                                            <th class="py-2.5 px-3">คันรถ</th>
                                            <th class="py-2.5 px-2.5 text-center w-12">ที่นั่ง</th>
                                            <th class="py-2.5 px-3">ชื่อ-ฉายา/นามสกุล (คัดลอกได้)</th>
                                            <th class="py-2.5 px-2.5 text-center w-14">อายุ</th>
                                            <th class="py-2.5 px-3 text-center w-28">เบอร์โทร</th>
                                            <th class="py-2.5 px-3">การเดินทาง</th>
                                            <th class="py-2.5 px-3">หมายเหตุผู้ดูแล</th>
                                            <th class="py-2.5 px-3 w-28 text-center">จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody id="detailedTableBody" class="divide-y divide-charcoal-border/50">
                                        <?php if (empty($allTripPassengers)): ?>
                                            <tr id="emptyTableRow">
                                                <td colspan="10" class="py-10 text-center text-charcoal-muted">
                                                    <i class="fa-regular fa-folder-open text-xl mb-1.5 block text-charcoal-subtle"></i>
                                                    <span>ยังไม่มีผู้ลงชื่อในรอบนี้</span>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($allTripPassengers as $idx => $p): 
                                                $rawFullName = !empty($p['first_name']) ? trim($p['prefix'] . $p['first_name'] . ' ' . $p['last_name_or_nickname']) : $p['passenger_name'];
                                            ?>
                                                <tr class="hover:bg-surface-muted/60 transition detailed-table-row" 
                                                    data-name="<?= htmlspecialchars(mb_strtolower($rawFullName), ENT_QUOTES) ?>" 
                                                    data-phone="<?= htmlspecialchars(preg_replace('/[^0-9]/', '', $p['phone']), ENT_QUOTES) ?>" 
                                                    data-vehicle="<?= htmlspecialchars($p['vehicle_name'], ENT_QUOTES) ?>" 
                                                    data-travel="<?= htmlspecialchars(mb_stripos($p['travel_type'], 'กลับ') !== false ? 'กลับ' : 'อย่างเดียว', ENT_QUOTES) ?>"
                                                    data-age="<?= htmlspecialchars($p['age'] ?? '', ENT_QUOTES) ?>"
                                                    data-note="<?= htmlspecialchars(mb_strtolower($p['admin_note'] ?? ''), ENT_QUOTES) ?>">
                                                    <td class="py-2.5 px-3 text-center">
                                                        <input type="checkbox" name="booking_ids[]" value="<?= $p['id'] ?>" onchange="updateDetailedSelectedCount()" class="detailed-checkbox w-3.5 h-3.5 rounded border-charcoal-border text-accent focus:ring-accent cursor-pointer">
                                                    </td>
                                                    <td class="py-2.5 px-2.5 text-center font-mono text-charcoal-subtle text-[11px]">
                                                        <?= ($idx + 1) ?>
                                                    </td>
                                                    <td class="py-2.5 px-3">
                                                        <div class="font-medium text-charcoal"><?= clean($p['vehicle_name']) ?></div>
                                                        <span class="text-[10px] text-charcoal-muted block"><?= clean($p['vehicle_type_label'] ?? '') ?></span>
                                                    </td>
                                                    <td class="py-2.5 px-2.5 text-center font-mono font-medium text-charcoal">
                                                        <span class="inline-block px-1.5 py-0.5 rounded bg-surface-muted border border-charcoal-border text-[11px]">
                                                            <?= $p['seat_number'] ?>
                                                        </span>
                                                    </td>
                                                    <td class="py-2.5 px-3">
                                                        <div class="flex items-center space-x-1.5">
                                                            <span class="font-medium text-charcoal"><?= clean($rawFullName) ?></span>
                                                            <button type="button" 
                                                                    data-copy="<?= htmlspecialchars($rawFullName, ENT_QUOTES, 'UTF-8') ?>" 
                                                                    data-label="ชื่อ" 
                                                                    onclick="copyFromBtn(this)" 
                                                                    class="text-charcoal-subtle hover:text-accent p-1 text-[11px] transition rounded hover:bg-surface-muted" 
                                                                    title="คลิกเพื่อคัดลอกชื่อ">
                                                                <i class="fa-regular fa-copy"></i>
                                                            </button>
                                                        </div>
                                                        <?php if (!empty($p['first_name'])): ?>
                                                            <span class="text-[10px] text-charcoal-muted block">
                                                                <?= clean($p['prefix']) ?> • <?= clean($p['first_name']) ?> • <?= clean($p['last_name_or_nickname']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-2.5 px-2.5 text-center font-mono">
                                                        <?php if (!empty($p['age'])): ?>
                                                            <div class="inline-flex items-center space-x-1 justify-center">
                                                                <span class="text-charcoal"><?= clean($p['age']) ?></span>
                                                                <button type="button" 
                                                                        data-copy="<?= htmlspecialchars((string)$p['age'], ENT_QUOTES, 'UTF-8') ?>" 
                                                                        data-label="อายุ" 
                                                                        onclick="copyFromBtn(this)" 
                                                                        class="text-charcoal-subtle hover:text-accent p-0.5 text-[10px] transition rounded" 
                                                                        title="คัดลอกอายุ">
                                                                    <i class="fa-regular fa-copy"></i>
                                                                </button>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-charcoal-subtle">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-2.5 px-3 text-center font-mono">
                                                        <?php if (!empty($p['phone'])): ?>
                                                            <div class="inline-flex items-center space-x-1 justify-center">
                                                                <span class="text-charcoal-muted"><?= clean($p['phone']) ?></span>
                                                                <button type="button" 
                                                                        data-copy="<?= htmlspecialchars((string)$p['phone'], ENT_QUOTES, 'UTF-8') ?>" 
                                                                        data-label="เบอร์โทร" 
                                                                        onclick="copyFromBtn(this)" 
                                                                        class="text-charcoal-subtle hover:text-accent p-0.5 text-[10px] transition rounded" 
                                                                        title="คัดลอกเบอร์โทร">
                                                                    <i class="fa-regular fa-copy"></i>
                                                                </button>
                                                            </div>
                                                        <?php else: ?>
                                                            <span class="text-charcoal-subtle">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-2.5 px-3 text-charcoal-muted">
                                                        <?= clean($p['travel_type']) ?>
                                                    </td>
                                                    <td class="py-2.5 px-3">
                                                        <?php if (!empty($p['admin_note'])): ?>
                                                            <span class="inline-block bg-surface-muted text-charcoal border border-charcoal-border px-2 py-0.5 rounded-sm text-[11px]">
                                                                <?= clean($p['admin_note']) ?>
                                                            </span>
                                                        <?php else: ?>
                                                            <span class="text-charcoal-subtle">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="py-2.5 px-3 text-center whitespace-nowrap">
                                                        <div class="flex items-center justify-center space-x-1.5">
                                                            <button type="button" 
                                                                    data-passenger="<?= htmlspecialchars(json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
                                                                    onclick="openMoveModalFromBtn(this)"
                                                                    class="text-charcoal hover:text-accent font-medium px-2.5 py-1 rounded-md border border-charcoal-border hover:bg-surface-muted text-[11px] transition shadow-subtle inline-flex items-center space-x-1 cursor-pointer"
                                                                    title="ย้ายคัน / แก้ไขข้อมูล">
                                                                <i class="fa-solid fa-pen-to-square text-[10px]"></i>
                                                                <span>ย้าย/แก้ไข</span>
                                                            </button>
                                                            <form method="POST" onsubmit="return confirm('ยืนยันลบชื่อนี้หรือไม่?')" class="inline m-0">
                                                                <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                                                <input type="hidden" name="action" value="delete_booking">
                                                                <input type="hidden" name="booking_id" value="<?= $p['id'] ?>">
                                                                <button type="submit" class="text-charcoal-subtle hover:text-mutedred-700 p-1.5 transition rounded hover:bg-mutedred-50 cursor-pointer" title="ลบชื่อ">
                                                                    <i class="fa-solid fa-trash-can text-xs"></i>
                                                                </button>
                                                            </form>
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
                </div>

            <?php else: ?>
                <div class="bg-surface rounded-lg border border-dashed border-charcoal-border p-12 text-center shadow-card">
                    <div class="w-12 h-12 rounded-full bg-accent-subtle text-accent flex items-center justify-center mx-auto mb-3">
                        <i class="fa-solid fa-calendar-xmark text-xl"></i>
                    </div>
                    <h3 class="font-bold text-charcoal text-base">ยังไม่มีรอบการเดินทางในระบบ</h3>
                    <p class="text-xs text-charcoal-muted mt-1 max-w-sm mx-auto">ยังไม่มีข้อมูลรอบการเดินทางที่เลือกหรือเปิดใช้งาน</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php if ($isSuper || $currentAdminRole === 'admin'): ?>
<!-- Modal: จัดการสิทธิ์ผู้ดูแลระบบ Gmail (Google OAuth Multi-Tier RBAC) -->
<div id="adminMgmtModal" class="fixed inset-0 z-50 flex items-start sm:items-center justify-center bg-slate-900/60 backdrop-blur-sm hidden p-2 sm:p-4" onclick="closeAdminMgmtModal(event)">
    <div class="bg-surface rounded-xl w-full max-w-3xl shadow-elevated border border-charcoal-border text-xs text-charcoal flex flex-col max-h-[92vh]" onclick="event.stopPropagation()">
        <!-- Header -->
        <div class="flex-shrink-0 bg-surface flex items-center justify-between px-4 sm:px-6 py-3.5 border-b border-charcoal-border rounded-t-xl">
            <div class="min-w-0 pr-3">
                <h3 class="font-bold text-charcoal text-sm sm:text-base flex items-center space-x-2">
                    <i class="fa-solid <?= $isSuper ? 'fa-crown text-purple-600' : 'fa-users-gear text-accent' ?>"></i>
                    <span><?= $isSuper ? 'จัดการสิทธิ์ผู้ดูแลระบบ (Super Admin Mode)' : 'จัดการสิทธิ์ผู้ประสานงานรถ (Staff Coordinator)' ?></span>
                </h3>
                <p class="text-charcoal-muted text-[11px] mt-0.5 leading-snug">
                    <?= $isSuper 
                        ? 'เพิ่มสิทธิ์บัญชี Gmail เข้าสู่ระบบตามมาตรฐานสากล แบ่ง 3 ระดับ (Super Admin, Admin, Staff)' 
                        : 'จัดการบัญชี Gmail สำหรับผู้ประสานงานรถ (Staff) เพื่อช่วยเช็กชื่อและดูแลผู้โดยสาร' ?>
                </p>
            </div>
            <button type="button" onclick="closeAdminMgmtModal()" class="flex-shrink-0 w-8 h-8 rounded-full bg-surface-muted hover:bg-red-50 hover:text-red-600 text-charcoal-muted transition flex items-center justify-center active:scale-90" title="ปิดหน้าต่าง">
                <i class="fa-solid fa-xmark text-base"></i>
            </button>
        </div>

        <!-- Scrollable content -->
        <div class="overflow-y-auto flex-1 p-4 sm:p-6 space-y-6">

            <!-- 1. ส่วนฟอร์มเพิ่มสิทธิ์ (นำมาไว้ด้านบนสุดเพื่อให้เห็นทันทีและกดได้โดยไม่ถูกปุ่มด้านล่างบัง) -->
            <?php if ($isSuper): ?>
                <div class="bg-surface-muted p-4 sm:p-5 rounded-xl border border-charcoal-border shadow-xs">
                    <h4 class="font-bold text-charcoal text-sm mb-1.5 flex items-center space-x-2">
                        <span class="w-6 h-6 rounded-full bg-accent/10 text-accent flex items-center justify-center text-xs">
                            <i class="fa-solid fa-user-plus"></i>
                        </span>
                        <span>+ กำหนดสิทธิ์บัญชี Gmail ใหม่ (Super Admin Mode)</span>
                    </h4>
                    <p class="text-charcoal-muted text-[11px] mb-3.5">
                        เมื่อเพิ่มบัญชี Gmail แล้ว เจ้าของอีเมลจะสามารถกด <strong>"ดำเนินการต่อด้วยบัญชี Google"</strong> หรือกรอกอีเมลเพื่อเข้าสู่ระบบได้ทันที
                    </p>
                    <form method="POST" class="space-y-3.5">
                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                        <input type="hidden" name="action" value="add_admin_user">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-charcoal font-semibold mb-1 text-[11px]">ชื่อ-สกุล ผู้ดูแล <span class="text-red-500">*</span></label>
                                <input type="text" name="new_name" required placeholder="เช่น คุณสมเกียรติ มุ่งมั่น หรือ พม.เอกลักษณ์" class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-lg text-xs text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                            </div>
                            <div>
                                <label class="block text-charcoal font-semibold mb-1 text-[11px]">อีเมล Gmail ผู้ดูแล <span class="text-red-500">*</span></label>
                                <input type="email" name="new_email" required placeholder="somkiat.dci@gmail.com" class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-lg text-xs font-mono text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                            </div>
                        </div>

                        <div>
                            <label class="block text-charcoal font-semibold mb-1.5 text-[11px]">เลือกระดับสิทธิ์ (Role Tier):</label>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                                <label class="p-2.5 rounded-lg border border-charcoal-border bg-surface hover:bg-surface-muted cursor-pointer flex items-start space-x-2.5 transition">
                                    <input type="radio" name="new_role" value="staff" class="mt-0.5 text-accent focus:ring-accent">
                                    <div>
                                        <span class="font-bold text-charcoal block text-xs">Staff (Tier 1)</span>
                                        <span class="text-[10px] text-charcoal-muted leading-tight block mt-0.5">ดูข้อมูลผู้โดยสาร เช็กชื่อ พิมพ์รายงาน</span>
                                    </div>
                                </label>
                                <label class="p-2.5 rounded-lg border border-accent/50 bg-accent-subtle/30 cursor-pointer flex items-start space-x-2.5 transition">
                                    <input type="radio" name="new_role" value="admin" checked class="mt-0.5 text-accent focus:ring-accent">
                                    <div>
                                        <span class="font-bold text-charcoal block text-xs">Admin (Tier 2)</span>
                                        <span class="text-[10px] text-charcoal-muted leading-tight block mt-0.5">จัดการรอบรถ จัดที่นั่ง ย้าย/แก้ไขรายชื่อ</span>
                                    </div>
                                </label>
                                <label class="p-2.5 rounded-lg border border-purple-300 bg-purple-50/50 cursor-pointer flex items-start space-x-2.5 transition">
                                    <input type="radio" name="new_role" value="superadmin" class="mt-0.5 text-accent focus:ring-accent">
                                    <div>
                                        <span class="font-bold text-purple-900 block text-xs">Super Admin (Tier 3)</span>
                                        <span class="text-[10px] text-purple-700 leading-tight block mt-0.5">ควบคุมระบบสูงสุด ลบรอบ จัดการสิทธิ์แอดมิน</span>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <div class="flex justify-end pt-1">
                            <button type="submit" class="bg-accent hover:bg-accent-hover text-white px-5 py-2.5 rounded-lg font-semibold text-xs sm:text-sm transition shadow-sm flex items-center space-x-2 active:scale-95 cursor-pointer">
                                <i class="fa-solid fa-plus text-xs"></i>
                                <span>เพิ่มสิทธิ์บัญชี Gmail</span>
                            </button>
                        </div>
                    </form>
                </div>
            <?php elseif ($currentAdminRole === 'admin'): ?>
                <!-- เพิ่มผู้ประสานงานรถ (สำหรับ GENERAL ADMIN) -->
                <div class="bg-surface-muted p-4 sm:p-5 rounded-xl border border-charcoal-border shadow-xs">
                    <h4 class="font-bold text-charcoal text-sm mb-1.5 flex items-center space-x-2">
                        <span class="w-6 h-6 rounded-full bg-accent/10 text-accent flex items-center justify-center text-xs">
                            <i class="fa-solid fa-user-plus"></i>
                        </span>
                        <span>+ เพิ่มผู้ประสานงานรถ (Staff Coordinator)</span>
                    </h4>
                    <p class="text-charcoal-muted text-[11px] mb-3.5">
                        เมื่อเพิ่มบัญชี Gmail แล้ว เจ้าของอีเมลจะสามารถล็อกอินเพื่อดูข้อมูลผู้โดยสาร เช็กชื่อ และพิมพ์รายงานได้
                    </p>
                    <form method="POST" class="space-y-3.5">
                        <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                        <input type="hidden" name="action" value="add_admin_user">
                        <input type="hidden" name="new_role" value="staff">

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-charcoal font-semibold mb-1 text-[11px]">ชื่อ-สกุล ผู้ประสานงาน <span class="text-red-500">*</span></label>
                                <input type="text" name="new_name" required placeholder="เช่น คุณสมเกียรติ หรือ เจ้าหน้าที่ประสานงาน" class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-lg text-xs text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                            </div>
                            <div>
                                <label class="block text-charcoal font-semibold mb-1 text-[11px]">อีเมล Gmail ผู้ประสานงาน <span class="text-red-500">*</span></label>
                                <input type="email" name="new_email" required placeholder="name.dci@gmail.com" class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-lg text-xs font-mono text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                            </div>
                        </div>

                        <div class="flex items-center justify-between pt-1">
                            <span class="text-[11px] text-charcoal-muted">สิทธิ์ที่ได้รับ: <strong class="text-amber-700">ผู้ประสานงานรถ (Staff Coordinator)</strong></span>
                            <button type="submit" class="bg-accent hover:bg-accent-hover text-white px-5 py-2.5 rounded-lg font-semibold text-xs sm:text-sm transition shadow-sm flex items-center space-x-2 active:scale-95 cursor-pointer">
                                <i class="fa-solid fa-plus text-xs"></i>
                                <span>เพิ่มผู้ประสานงานรถ</span>
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

            <!-- 2. ตารางรายชื่อผู้ดูแลระบบ / ผู้ประสานงานรถ -->
            <div class="bg-surface-muted border border-charcoal-border rounded-xl p-4">
                <div class="flex items-center justify-between mb-3">
                    <span class="font-semibold text-charcoal flex items-center space-x-1.5">
                        <i class="fa-solid fa-users text-accent"></i>
                        <span><?= $isSuper ? 'รายชื่อบัญชี Gmail ทั้งหมดในระบบ' : 'รายชื่อผู้ประสานงานรถ (Staff Coordinator)' ?> (<?= count($adminsList) ?> บัญชี):</span>
                    </span>
                    <span class="text-[11px] text-charcoal-muted">สิทธิ์ของคุณ: <strong class="text-charcoal"><?= clean($currentAdmin['role_label'] ?? ucfirst($currentAdminRole)) ?></strong></span>
                </div>

                <div class="overflow-x-auto bg-surface rounded-lg border border-charcoal-border">
                    <table class="w-full text-left text-xs min-w-[1000px]">
                        <thead class="bg-surface-muted text-charcoal-muted font-semibold border-b border-charcoal-border">
                            <tr>
                                <th class="py-2.5 px-3">ชื่อ-สกุล</th>
                                <th class="py-2.5 px-3">บัญชี Gmail</th>
                                <th class="py-2.5 px-3">ระดับสิทธิ์</th>
                                <th class="py-2.5 px-3 text-center">สถานะ</th>
                                <th class="py-2.5 px-3 text-center">เข้าใช้งานล่าสุด</th>
                                <th class="py-2.5 px-3 text-center">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-charcoal-border/50">
                            <?php if (empty($adminsList)): ?>
                                <tr>
                                    <td colspan="6" class="py-4 text-center text-charcoal-muted">ยังไม่มีรายชื่อในระบบ</td>
                                </tr>
                            <?php endif; ?>
                            <?php foreach ($adminsList as $adm): 
                                $isMe = (int)$adm['id'] === (int)$currentAdmin['id'];
                                $roleBadge = '';
                                if ($adm['role'] === 'superadmin') {
                                    $roleBadge = 'bg-accent-subtle text-accent border-accent/20';
                                    $roleTitle = '👑 Super Admin';
                                } elseif ($adm['role'] === 'admin') {
                                    $roleBadge = 'bg-blue-50 text-blue-700 border-blue-200';
                                    $roleTitle = '🛡️ Admin';
                                } else {
                                    $roleBadge = 'bg-amber-50 text-amber-800 border-amber-200';
                                    $roleTitle = '📋 Staff Coordinator';
                                }
                                $admEmail = clean($adm['email'] ?: ($adm['username'] . '@gmail.com'));
                            ?>
                                <tr class="<?= $isMe ? 'bg-accent-subtle/30' : 'hover:bg-surface-muted/40' ?> transition">
                                    <td class="py-2.5 px-3">
                                        <div class="flex items-center space-x-2">
                                            <div class="w-7 h-7 rounded-full bg-charcoal/10 flex items-center justify-center font-bold text-charcoal text-[11px]">
                                                <?= mb_substr($adm['name'], 0, 1) ?>
                                            </div>
                                            <div>
                                                <div class="font-medium text-charcoal"><?= clean($adm['name']) ?></div>
                                                <?php if ($isMe): ?>
                                                    <span class="text-[10px] text-accent font-semibold">(บัญชีของคุณที่กำลังใช้งาน)</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-2.5 px-3 font-mono text-charcoal-muted">
                                        <div class="flex items-center space-x-1.5">
                                            <i class="fa-brands fa-google text-[11px] text-mutedred-600"></i>
                                            <span><?= $admEmail ?></span>
                                        </div>
                                    </td>
                                    <td class="py-2.5 px-3">
                                        <span class="px-2 py-0.5 rounded-sm text-[10px] font-semibold border <?= $roleBadge ?>">
                                            <?= $roleTitle ?>
                                        </span>
                                    </td>
                                    <td class="py-2.5 px-3 text-center">
                                        <?php if ((int)($adm['is_active'] ?? 1) === 1): ?>
                                            <span class="px-2 py-0.5 rounded-sm text-[10px] font-semibold bg-sage-50 text-sage-800 border border-sage-200">
                                                <i class="fa-solid fa-circle text-[6px] mr-1 text-sage-600"></i>ใช้งานปกติ
                                            </span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded-sm text-[10px] font-semibold bg-mutedred-50 text-mutedred-800 border border-mutedred-200">
                                                <i class="fa-solid fa-circle text-[6px] mr-1 text-mutedred-600"></i>ระงับชั่วคราว
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-center text-charcoal-muted text-[11px]">
                                        <?= !empty($adm['last_login']) ? clean($adm['last_login']) : '<span class="text-charcoal-subtle">-</span>' ?>
                                    </td>
                                    <td class="py-2.5 px-3 text-center">
                                        <?php if (($isSuper || ($currentAdminRole === 'admin' && $adm['role'] === 'staff')) && !$isMe): ?>
                                            <div class="flex items-center justify-center space-x-1.5">
                                                <!-- Toggle status button -->
                                                <form method="POST" class="inline">
                                                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                                    <input type="hidden" name="action" value="toggle_admin_status">
                                                    <input type="hidden" name="target_admin_id" value="<?= $adm['id'] ?>">
                                                    <input type="hidden" name="new_status" value="<?= (int)($adm['is_active'] ?? 1) === 1 ? '0' : '1' ?>">
                                                    <button type="submit" class="px-2 py-0.5 rounded text-[10px] font-medium border border-charcoal-border hover:bg-surface-muted transition" title="สลับสถานะเปิด/ระงับ">
                                                        <?= (int)($adm['is_active'] ?? 1) === 1 ? 'ระงับ' : 'เปิดใช้' ?>
                                                    </button>
                                                </form>

                                                <!-- Delete button -->
                                                <form method="POST" onsubmit="return confirm('ยืนยันลบสิทธิ์บัญชี Gmail: <?= $admEmail ?> หรือไม่?')" class="inline">
                                                    <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
                                                    <input type="hidden" name="action" value="delete_admin_user">
                                                    <input type="hidden" name="target_admin_id" value="<?= $adm['id'] ?>">
                                                    <button type="submit" class="text-charcoal-subtle hover:text-mutedred-700 p-1 transition" title="ลบสิทธิ์บัญชีนี้">
                                                        <i class="fa-solid fa-trash-can text-xs"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php elseif ($isMe): ?>
                                            <span class="text-charcoal-subtle text-[10px] italic">(คุณ)</span>
                                        <?php else: ?>
                                            <span class="text-charcoal-subtle text-[11px]">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
        <!-- End Scrollable content -->

        <!-- Clean Modal Footer docked at the bottom -->
        <div class="flex-shrink-0 bg-surface-muted/60 border-t border-charcoal-border px-4 sm:px-6 py-3 flex items-center justify-end">
            <button type="button" onclick="closeAdminMgmtModal()" class="px-5 py-2 rounded-lg bg-surface hover:bg-surface-muted border border-charcoal-border text-charcoal font-semibold text-xs sm:text-sm transition flex items-center space-x-1.5 active:scale-95 shadow-xs cursor-pointer">
                <i class="fa-solid fa-xmark"></i>
                <span>ปิดหน้าต่าง</span>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Modal: เพิ่มรถชนิดอื่น -->
<div id="addVehicleModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm hidden p-4">
    <div class="bg-surface rounded-lg p-6 max-w-md w-full shadow-elevated border border-charcoal-border text-xs text-charcoal">
        <div class="flex items-center justify-between pb-3 border-b border-charcoal-border">
            <h3 class="font-bold text-charcoal text-sm">เพิ่มยานพาหนะในรอบเดินทาง</h3>
            <button type="button" onclick="closeAddVehicleModal()" class="text-charcoal-subtle hover:text-charcoal transition"><i class="fa-solid fa-xmark text-sm"></i></button>
        </div>

        <form method="POST" class="mt-4 space-y-3">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="action" value="add_custom_vehicle">
            <input type="hidden" name="trip_id" value="<?= $currentTripId ?>">

            <div>
                <label class="block text-charcoal font-medium mb-1">ประเภทรถที่ต้องการจัด <span class="text-mutedred-600">*</span></label>
                <select name="vehicle_type_label" id="vehTypeSelect" onchange="handleVehTypeChange(this.value)" class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-md text-xs font-medium text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                    <option value="รถตู้ (10 ที่นั่ง VIP)" data-type="van" data-seats="10">รถตู้ (10 ที่นั่ง VIP)</option>
                    <option value="รถตู้ (13 ที่นั่ง มาตรฐาน)" data-type="van" data-seats="13">รถตู้ (13 ที่นั่ง มาตรฐาน)</option>
                    <option value="รถบัสปรับอากาศ 1 ชั้น (40 ที่นั่ง)" data-type="bus" data-seats="40">รถบัสปรับอากาศ 1 ชั้น (40 ที่นั่ง)</option>
                    <option value="รถบัสปรับอากาศ 2 ชั้น (50 ที่นั่ง)" data-type="bus" data-seats="50">รถบัสปรับอากาศ 2 ชั้น (50 ที่นั่ง)</option>
                    <option value="รถบัสปรับอากาศ 2 ชั้น (60 ที่นั่ง)" data-type="bus" data-seats="60">รถบัสปรับอากาศ 2 ชั้น (60 ที่นั่ง)</option>
                    <option value="รถบัสพัดลม (40 ที่นั่ง)" data-type="bus" data-seats="40">รถบัสพัดลม (40 ที่นั่ง)</option>
                    <option value="other" data-type="bus" data-seats="40">กำหนดประเภทเอง...</option>
                </select>
                <input type="hidden" name="veh_type" id="vehTypeHidden" value="van">
                <input type="text" id="customTypeInput" name="custom_type_label" placeholder="ระบุประเภทรถ เช่น รถตู้ VIP 11 ที่นั่ง" class="w-full mt-2 px-3 py-2 bg-surface border border-charcoal-border rounded-md text-xs hidden text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
            </div>

            <div>
                <label class="block text-charcoal font-medium mb-1">ชื่อคันรถ (เช่น คันที่ 4 หรือ รถบัส 1)</label>
                <input type="text" name="name" placeholder="ปล่อยว่างเพื่อให้ระบบตั้งชื่อ คันที่ N อัตโนมัติ" class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-md text-xs text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
            </div>

            <div>
                <label class="block text-charcoal font-medium mb-1">จำนวนที่นั่งทั้งหมด (ที่นั่ง) <span class="text-mutedred-600">*</span></label>
                <input type="number" id="seatsInput" name="total_seats" value="10" min="1" max="100" required class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-md text-xs font-semibold text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-charcoal font-medium mb-1">เลขทะเบียน (ถ้ามี)</label>
                    <input type="text" name="license_plate" placeholder="เช่น ฮฮ-1234 กทม." class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-md text-xs text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                </div>
                <div>
                    <label class="block text-charcoal font-medium mb-1">ชื่อคนขับ (ถ้ามี)</label>
                    <input type="text" name="driver_name" placeholder="เช่น นายสมชาย" class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-md text-xs text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                </div>
            </div>

            <div class="pt-3 flex justify-end gap-2 border-t border-charcoal-border">
                <button type="button" onclick="closeAddVehicleModal()" class="px-3.5 py-2 text-xs font-medium text-charcoal bg-surface hover:bg-surface-muted border border-charcoal-border rounded-md transition shadow-subtle">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 text-xs font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition shadow-subtle">เพิ่มคันนี้</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: ย้ายคนข้ามคัน -->
<div id="moveModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm hidden p-4">
    <div class="bg-surface rounded-lg p-6 max-w-md w-full shadow-elevated border border-charcoal-border text-xs text-charcoal">
        <div class="flex items-center justify-between pb-3 border-b border-charcoal-border">
            <div>
                <h3 class="font-bold text-charcoal text-sm">ย้ายคันรถ / แก้ไขหมายเหตุ</h3>
                <p id="movePassengerName" class="text-xs text-charcoal-muted font-medium mt-0.5">-</p>
            </div>
            <button type="button" onclick="closeMoveModal()" class="text-charcoal-subtle hover:text-charcoal transition"><i class="fa-solid fa-xmark text-sm"></i></button>
        </div>

        <form method="POST" class="mt-4 space-y-3">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="action" value="move_or_edit_passenger">
            <input type="hidden" id="moveBookingId" name="booking_id" value="">

            <div class="grid grid-cols-1 sm:grid-cols-12 gap-2.5">
                <div class="sm:col-span-4">
                    <label class="block text-charcoal font-medium mb-1">คำนำหน้า</label>
                    <select id="movePrefix" name="prefix" class="w-full px-2.5 py-2 bg-surface border border-charcoal-border rounded-md text-xs text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
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
                    <label class="block text-charcoal font-medium mb-1">ชื่อ</label>
                    <input type="text" id="moveFirstName" name="first_name" placeholder="ชื่อ" class="w-full px-2.5 py-2 bg-surface border border-charcoal-border rounded-md text-xs font-semibold text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                </div>
            </div>

            <div>
                <label class="block text-charcoal font-medium mb-1">ฉายา (พระ) หรือ นามสกุล (ฆราวาส)</label>
                <input type="text" id="moveLastName" name="last_name_or_nickname" placeholder="ฉายา หรือ นามสกุล" class="w-full px-2.5 py-2 bg-surface border border-charcoal-border rounded-md text-xs text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
            </div>

            <div>
                <label class="block text-charcoal font-medium mb-1">คันรถที่จะให้เดินทาง (เลือกย้ายข้ามคันได้)</label>
                <select id="moveTargetVehicleId" name="target_vehicle_id" class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-md text-xs font-semibold text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                    <?php foreach ($tripVehicles as $tv): ?>
                        <option value="<?= $tv['id'] ?>">
                            <?= clean($tv['name']) ?> (<?= clean($tv['vehicle_type_label'] ?? '') ?> - <?= $tv['booked_count'] ?>/<?= $tv['total_seats'] ?> ที่นั่ง)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div>
                <label class="block text-charcoal font-medium mb-1">หมายเหตุสำหรับผู้ดูแล (เช่น มีคนกลับแทน)</label>
                <input type="text" id="moveAdminNote" name="admin_note" placeholder="เช่น มีคนกลับแทน, พม.เกียรติศักดิ์กลับแทน" class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-md text-xs focus:border-accent focus:ring-1 focus:ring-accent outline-none font-medium text-charcoal transition">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-12 gap-2">
                <div class="sm:col-span-5">
                    <label class="block text-charcoal font-medium mb-1">การเดินทาง</label>
                    <select id="moveTravelType" name="travel_type" class="w-full px-2.5 py-2 bg-surface border border-charcoal-border rounded-md text-xs text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                        <option value="เดินทางไป และ เดินทางกลับ">เดินทางไป และ เดินทางกลับ</option>
                        <option value="เดินทางไปอย่างเดียว">เดินทางไปอย่างเดียว</option>
                        <option value="เดินทางกลับอย่างเดียว">เดินทางกลับอย่างเดียว</option>
                    </select>
                </div>
                <div class="sm:col-span-4">
                    <label class="block text-charcoal font-medium mb-1">เบอร์โทรศัพท์</label>
                    <input type="tel" id="movePhone" name="phone" class="w-full px-2.5 py-2 bg-surface border border-charcoal-border rounded-md text-xs text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                </div>
                <div class="sm:col-span-3">
                    <label class="block text-charcoal font-medium mb-1">อายุ (ปี)</label>
                    <input type="number" id="moveAge" name="age" min="1" max="120" placeholder="เช่น 25" class="w-full px-2.5 py-2 bg-surface border border-charcoal-border rounded-md text-xs font-mono text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                </div>
            </div>

            <div class="pt-3 flex justify-end gap-2 border-t border-charcoal-border">
                <button type="button" onclick="closeMoveModal()" class="px-3.5 py-2 text-xs font-medium text-charcoal bg-surface hover:bg-surface-muted border border-charcoal-border rounded-md transition shadow-subtle">ยกเลิก</button>
                <button type="submit" class="px-4 py-2 text-xs font-medium text-white bg-accent hover:bg-accent-hover rounded-md transition shadow-subtle">บันทึก</button>
            </div>
        </form>
    </div>
</div>


<!-- Modal: สร้างรอบการเดินทางใหม่ -->
<div id="newTripModal" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm hidden p-4 overflow-y-auto">
    <div class="bg-surface rounded-xl max-w-lg w-full p-6 shadow-elevated border border-charcoal-border my-8 text-xs text-charcoal" onclick="event.stopPropagation()">
        <div class="flex items-center justify-between pb-3 border-b border-charcoal-border">
            <h3 class="font-bold text-charcoal text-sm flex items-center space-x-2">
                <i class="fa-solid fa-calendar-plus text-accent"></i>
                <span>สร้างรอบการเดินทางใหม่</span>
            </h3>
            <button type="button" onclick="closeNewTripModal()" class="text-charcoal-subtle hover:text-charcoal transition w-7 h-7 rounded-full flex items-center justify-center hover:bg-surface-muted cursor-pointer"><i class="fa-solid fa-xmark text-sm"></i></button>
        </div>

        <form method="POST" class="mt-4 space-y-3.5">
            <input type="hidden" name="csrf_token" value="<?= getCsrfToken() ?>">
            <input type="hidden" name="action" value="create_trip">

            <div>
                <label class="block font-semibold text-charcoal mb-1">ชื่องาน / กิจกรรม <span class="text-mutedred-600">*</span></label>
                <input type="text" name="title" required value="งานบูชาข้าวพระต้นเดือน" class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-lg text-xs font-semibold text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block font-semibold text-charcoal mb-1">วันเดินทาง (วันอาทิตย์ต้นเดือน) <span class="text-mutedred-600">*</span></label>
                    <input type="date" name="trip_date" required value="<?= $suggestedDate ?>" class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-lg text-xs font-bold text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                </div>
                <div>
                    <label class="block font-semibold text-charcoal mb-1">เวลาล้อหมุน <span class="text-mutedred-600">*</span></label>
                    <input type="text" name="departure_time" required value="08.00 น." class="w-full px-3 py-2 bg-surface border border-charcoal-border rounded-lg text-xs text-charcoal focus:border-accent focus:ring-1 focus:ring-accent outline-none transition">
                </div>
            </div>

            <div class="bg-surface-muted p-3.5 rounded-xl border border-charcoal-border space-y-2.5">
                <div class="font-bold text-charcoal text-[11px] flex items-center justify-between">
                    <span>กำหนดยานพาหนะเริ่มต้น (เลือกจำนวนคัน):</span>
                    <span class="text-[10px] text-charcoal-muted">สามารถเพิ่ม/ลบทีหลังได้</span>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div class="bg-surface p-2.5 border border-charcoal-border rounded-lg flex items-center justify-between shadow-xs">
                        <span class="font-medium">รถตู้ (10 ที่นั่ง)</span>
                        <input type="number" name="van_count" value="2" min="0" max="30" class="w-14 px-2 py-1 border border-charcoal-border rounded text-center font-bold text-charcoal focus:border-accent outline-none">
                    </div>
                    <div class="bg-surface p-2.5 border border-charcoal-border rounded-lg flex items-center justify-between shadow-xs">
                        <span class="font-medium">บัสแอร์ 1 ชั้น (40 ที่)</span>
                        <input type="number" name="bus_ac1_count" value="0" min="0" max="20" class="w-14 px-2 py-1 border border-charcoal-border rounded text-center font-bold text-charcoal focus:border-accent outline-none">
                    </div>
                    <div class="bg-surface p-2.5 border border-charcoal-border rounded-lg flex items-center justify-between shadow-xs">
                        <span class="font-medium">บัสแอร์ 2 ชั้น (50 ที่)</span>
                        <input type="number" name="bus_ac2_count" value="0" min="0" max="20" class="w-14 px-2 py-1 border border-charcoal-border rounded text-center font-bold text-charcoal focus:border-accent outline-none">
                    </div>
                    <div class="bg-surface p-2.5 border border-charcoal-border rounded-lg flex items-center justify-between shadow-xs">
                        <span class="font-medium">บัสพัดลม (40 ที่)</span>
                        <input type="number" name="bus_fan_count" value="0" min="0" max="20" class="w-14 px-2 py-1 border border-charcoal-border rounded text-center font-bold text-charcoal focus:border-accent outline-none">
                    </div>
                </div>
            </div>

            <div class="pt-3 flex justify-end gap-2 border-t border-charcoal-border">
                <button type="button" onclick="closeNewTripModal()" class="px-4 py-2 text-xs font-medium text-charcoal bg-surface hover:bg-surface-muted border border-charcoal-border rounded-lg transition shadow-subtle cursor-pointer">ยกเลิก</button>
                <button type="submit" class="px-5 py-2 text-xs font-semibold text-white bg-accent hover:bg-accent-hover rounded-lg transition shadow-subtle active:scale-95 cursor-pointer">สร้างรอบการเดินทาง</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openNewTripModal() { document.getElementById('newTripModal').classList.remove('hidden'); }
    function closeNewTripModal() { document.getElementById('newTripModal').classList.add('hidden'); }

    function openAddVehicleModal() { document.getElementById('addVehicleModal').classList.remove('hidden'); }
    function closeAddVehicleModal() { document.getElementById('addVehicleModal').classList.add('hidden'); }

    function openAdminMgmtModal() { document.getElementById('adminMgmtModal').classList.remove('hidden'); }
    function closeAdminMgmtModal() { document.getElementById('adminMgmtModal').classList.add('hidden'); }

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

    function filterByVehicleChip(vehName) {
        const sel = document.getElementById('tableVehicleFilter');
        if (sel) {
            sel.value = vehName;
        }
        updateVehicleChipActive(vehName);
        filterDetailedTable();
    }

    function onVehicleSelectChange() {
        const sel = document.getElementById('tableVehicleFilter');
        const val = sel ? sel.value : '';
        updateVehicleChipActive(val);
        filterDetailedTable();
    }

    function updateVehicleChipActive(selectedVehName) {
        const chips = document.querySelectorAll('.vehicle-chip-btn');
        chips.forEach(btn => {
            const vname = btn.getAttribute('data-vname') || '';
            if (vname === selectedVehName) {
                btn.className = "vehicle-chip-btn px-2.5 py-1 rounded text-xs font-bold transition flex items-center space-x-1.5 bg-accent text-white shadow-xs";
            } else {
                btn.className = "vehicle-chip-btn px-2.5 py-1 rounded text-xs font-medium text-charcoal hover:text-accent hover:bg-surface transition flex items-center space-x-1.5";
            }
        });
    }

    function switchAdminView(view) {
        // Fallback for backward compatibility
    }

    function filterByVehicleChipFromBtn(btn) {
        if (!btn) return;
        const vehName = btn.getAttribute('data-vname') || '';
        filterByVehicleChip(vehName);
    }

    function copyFromBtn(btn) {
        if (!btn) return;
        const text = btn.getAttribute('data-copy') || '';
        const label = btn.getAttribute('data-label') || 'ข้อมูล';
        copyRawText(text, btn, label);
    }

    function openMoveModalFromBtn(btn) {
        if (!btn) return;
        try {
            const passengerData = JSON.parse(btn.getAttribute('data-passenger'));
            openMoveModal(passengerData);
        } catch (e) {
            console.error('Invalid passenger json data', e);
        }
    }

    function copyRawText(text, btnElement, label = 'ข้อมูล') {
        if (!text) return;
        navigator.clipboard.writeText(text).then(() => {
            if (btnElement) {
                const originalHtml = btnElement.innerHTML;
                btnElement.innerHTML = '<i class="fa-solid fa-check text-emerald-600"></i>';
                setTimeout(() => { btnElement.innerHTML = originalHtml; }, 1800);
            }
            if (typeof Swal !== 'undefined') {
                const Toast = Swal.mixin({
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 1500,
                    timerProgressBar: false
                });
                Toast.fire({
                    icon: 'success',
                    title: `คัดลอก${label}แล้ว: "${text}"`
                });
            }
        }).catch(err => {
            console.error('Copy failed: ', err);
        });
    }

    function filterDetailedTable() {
        const rawSearch = (document.getElementById('tableSearchInput')?.value || '').trim();
        const searchVal = rawSearch.toLowerCase();
        const searchDigits = rawSearch.replace(/[^0-9]/g, '');
        const vehVal = (document.getElementById('tableVehicleFilter')?.value || '').trim();
        const travelVal = (document.getElementById('tableTravelFilter')?.value || '').trim();

        const rows = document.querySelectorAll('.detailed-table-row');
        let visibleCount = 0;

        rows.forEach(row => {
            const name = row.getAttribute('data-name') || '';
            const phone = row.getAttribute('data-phone') || '';
            const veh = row.getAttribute('data-vehicle') || '';
            const travel = row.getAttribute('data-travel') || '';
            const age = row.getAttribute('data-age') || '';
            const note = row.getAttribute('data-note') || '';

            const matchPhone = searchDigits.length > 0 && phone.includes(searchDigits);
            const matchSearch = !searchVal || name.includes(searchVal) || matchPhone || age.includes(searchVal) || note.includes(searchVal);
            const matchVeh = !vehVal || veh === vehVal;
            const matchTravel = !travelVal || travel === travelVal;

            if (matchSearch && matchVeh && matchTravel) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        const cntEl = document.getElementById('visibleCount');
        if (cntEl) cntEl.textContent = visibleCount;
    }

    function resetDetailedTableFilter() {
        if (document.getElementById('tableSearchInput')) document.getElementById('tableSearchInput').value = '';
        if (document.getElementById('tableVehicleFilter')) document.getElementById('tableVehicleFilter').value = '';
        if (document.getElementById('tableTravelFilter')) document.getElementById('tableTravelFilter').value = '';
        updateVehicleChipActive('');
        filterDetailedTable();
    }

    function toggleSelectAllDetailed(master) {
        const checkboxes = document.querySelectorAll('.detailed-checkbox');
        checkboxes.forEach(cb => {
            const row = cb.closest('tr');
            if (row && row.style.display !== 'none') {
                cb.checked = master.checked;
            }
        });
        updateDetailedSelectedCount();
    }

    function updateDetailedSelectedCount() {
        const checked = document.querySelectorAll('.detailed-checkbox:checked');
        const badge = document.getElementById('selectedCountBadge');
        const numEl = document.getElementById('selectedCountNum');
        const btnDel = document.getElementById('btnBulkDelete');

        if (checked.length > 0) {
            if (badge) badge.classList.remove('hidden');
            if (numEl) numEl.textContent = checked.length;
            if (btnDel) btnDel.classList.remove('hidden');
        } else {
            if (badge) badge.classList.add('hidden');
            if (btnDel) btnDel.classList.add('hidden');
        }
    }

    function submitBulkDelete() {
        const checked = document.querySelectorAll('.detailed-checkbox:checked');
        if (checked.length === 0) {
            alert('กรุณาเลือกรายการที่ต้องการลบอย่างน้อย 1 รายการ');
            return;
        }
        if (confirm(`ยืนยันการลบรายชื่อผู้โดยสารที่เลือกจำนวน ${checked.length} คน หรือไม่?`)) {
            document.getElementById('bulkDeleteForm').submit();
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

        const ageInput = document.getElementById('moveAge');
        if (ageInput) {
            ageInput.value = passenger.age || '';
        }

        document.getElementById('moveAdminNote').value = passenger.admin_note || '';
        document.getElementById('moveTravelType').value = passenger.travel_type || 'เดินทางไป และ เดินทางกลับ';
        document.getElementById('movePhone').value = passenger.phone || '';
        document.getElementById('moveModal').classList.remove('hidden');
    }
    function closeMoveModal() { document.getElementById('moveModal').classList.add('hidden'); }

    <?php if (!empty($_GET['open_mgmt'])): ?>
    window.addEventListener('DOMContentLoaded', function() {
        if (typeof openAdminMgmtModal === 'function') {
            openAdminMgmtModal();
        }
    });
    <?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
