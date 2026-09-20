<?php
// api/booking.php - API endpoint for booking actions
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDb();
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    if ($action === 'book') {
        $tripId = (int)($_POST['trip_id'] ?? 0);
        $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
        $seatNumber = (int)($_POST['seat_number'] ?? 0);

        $prefix = clean($_POST['prefix'] ?? '');
        $firstName = clean($_POST['first_name'] ?? '');
        $lastNameOrNickname = clean($_POST['last_name_or_nickname'] ?? '');
        $age = clean($_POST['age'] ?? '');
        $rawPhone = clean($_POST['phone'] ?? '');
        $phone = preg_replace('/[^0-9]/', '', $rawPhone);
        $travelType = clean($_POST['travel_type'] ?? 'เดินทางไป และ เดินทางกลับ');
        $note = clean($_POST['note'] ?? '');

        // รวมเป็นชื่อเต็ม passenger_name เพื่อความเข้ากันได้
        $passengerName = trim("{$prefix} {$firstName} {$lastNameOrNickname}");
        if (empty($passengerName) && !empty($_POST['passenger_name'])) {
            $passengerName = clean($_POST['passenger_name']);
        }

        if (!$tripId || !$vehicleId) {
            echo json_encode(['success' => false, 'message' => 'กรุณาเลือกรอบการเดินทางและคันรถ']);
            exit;
        }

        if (empty($firstName) && empty($passengerName)) {
            echo json_encode(['success' => false, 'message' => 'กรุณากรอกชื่อ']);
            exit;
        }

        if (empty($phone) || strlen($phone) < 9) {
            echo json_encode(['success' => false, 'message' => 'กรุณากรอกเบอร์โทรศัพท์ที่ถูกต้อง (9-10 หลัก)']);
            exit;
        }

        // ตรวจสอบสถานะรอบรถ
        $stmt = $db->prepare("SELECT is_active FROM trips WHERE id = ?");
        $stmt->execute([$tripId]);
        $trip = $stmt->fetch();
        if (!$trip || (int)$trip['is_active'] !== 1) {
            echo json_encode(['success' => false, 'message' => 'รอบการเดินทางนี้ปิดรับการลงชื่อแล้ว']);
            exit;
        }

        // ดึงข้อมูลรถ
        $stmtVeh = $db->prepare("SELECT * FROM vehicles WHERE id = ?");
        $stmtVeh->execute([$vehicleId]);
        $veh = $stmtVeh->fetch();
        if (!$veh) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลรถคันที่เลือก']);
            exit;
        }

        // หาที่นั่งว่างถ้าไม่ได้ระบุเบอร์ที่นั่งมา (Auto-assign next seat in order 1..N)
        if ($seatNumber <= 0) {
            $stmtOccupied = $db->prepare("SELECT seat_number FROM bookings WHERE vehicle_id = ? ORDER BY seat_number ASC");
            $stmtOccupied->execute([$vehicleId]);
            $occupied = $stmtOccupied->fetchAll(PDO::FETCH_COLUMN);

            for ($s = 1; $s <= $veh['total_seats']; $s++) {
                if (!in_array($s, $occupied)) {
                    $seatNumber = $s;
                    break;
                }
            }

            if ($seatNumber <= 0) {
                echo json_encode(['success' => false, 'message' => "ขออภัย {$veh['name']} มีผู้ลงชื่อเต็มแล้ว ({$veh['total_seats']} ที่นั่ง) กรุณาเลือกคันอื่น"]);
                exit;
            }
        } else {
            // ตรวจสอบที่นั่งที่ระบุว่าว่างหรือไม่
            $stmtCheck = $db->prepare("SELECT id FROM bookings WHERE vehicle_id = ? AND seat_number = ?");
            $stmtCheck->execute([$vehicleId, $seatNumber]);
            if ($stmtCheck->fetch()) {
                echo json_encode(['success' => false, 'message' => "ที่นั่งลำดับที่ {$seatNumber} ใน {$veh['name']} มีผู้ลงชื่อแล้ว"]);
                exit;
            }
        }

        // ตรวจสอบว่าเบอร์โทร หรือชื่อนี้เคยลงชื่อในรอบนี้แล้วหรือไม่
        $stmtDup = $db->prepare("
            SELECT b.seat_number, v.name as vehicle_name 
            FROM bookings b 
            JOIN vehicles v ON b.vehicle_id = v.id 
            WHERE b.trip_id = ? AND (
                REPLACE(REPLACE(b.phone, '-', ''), ' ', '') = ? 
                OR (b.first_name = ? AND b.last_name_or_nickname = ? AND b.first_name != '')
            )
        ");
        $stmtDup->execute([$tripId, $phone, $firstName, $lastNameOrNickname]);
        if ($dup = $stmtDup->fetch()) {
            echo json_encode([
                'success' => false, 
                'message' => "ท่าน (หรือเบอร์นี้) ได้ลงชื่อไว้ใน {$dup['vehicle_name']} ลำดับที่ {$dup['seat_number']} แล้ว หากต้องการเปลี่ยนคัน กรุณายกเลิกในหน้าตรวจสอบก่อน"
            ]);
            exit;
        }

        // บันทึกการจอง
        $stmtInsert = $db->prepare("
            INSERT INTO bookings (
                trip_id, vehicle_id, seat_number, passenger_name, 
                prefix, first_name, last_name_or_nickname, age, phone, travel_type, note
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtInsert->execute([
            $tripId, $vehicleId, $seatNumber, $passengerName,
            $prefix, $firstName, $lastNameOrNickname, $age, $phone, $travelType, $note
        ]);
        $bookingId = $db->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => "ลงชื่อสำเร็จ! ท่านได้ลงชื่อใน {$veh['name']} ลำดับที่ {$seatNumber}",
            'booking' => [
                'id' => $bookingId,
                'vehicle_name' => $veh['name'],
                'seat_number' => $seatNumber,
                'passenger_name' => $passengerName,
                'phone' => $phone,
                'travel_type' => $travelType
            ]
        ]);
        exit;

    } elseif ($action === 'cancel') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $phoneConfirm = clean($_POST['phone_confirm'] ?? '');
        
        // SECURITY FIX: เฉพาะ Admin ที่ล็อกอินผ่าน Session เท่านั้น ห้ามเชื่อถือ $_POST['is_admin']
        $isAdmin = isAdminLoggedIn();

        if (!$bookingId) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบรหัสการลงชื่อ']);
            exit;
        }

        $stmt = $db->prepare("
            SELECT b.*, t.is_active as trip_is_active 
            FROM bookings b 
            JOIN trips t ON b.trip_id = t.id 
            WHERE b.id = ?
        ");
        $stmt->execute([$bookingId]);
        $b = $stmt->fetch();
        if (!$b) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลการลงชื่อ']);
            exit;
        }

        if (!$isAdmin) {
            // ถ้ารอบการเดินทางปิดรับแล้ว ไม่อนุญาตให้ถอดชื่อออกเองตามระเบียบสงฆ์
            if ((int)$b['trip_is_active'] !== 1) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'รอบการเดินทางนี้ปิดรับและตัดยอดแล้ว ไม่อนุญาตให้ถอดชื่อออกเอง หากมีเหตุจำเป็นกรุณาติดต่อผู้ดูแลระบบ'
                ]);
                exit;
            }

            $cleanPhone1 = preg_replace('/[^0-9]/', '', $b['phone']);
            $cleanPhone2 = preg_replace('/[^0-9]/', '', $phoneConfirm);

            if ($cleanPhone1 !== $cleanPhone2 || empty($cleanPhone2)) {
                echo json_encode(['success' => false, 'message' => 'เบอร์โทรศัพท์ยืนยันไม่ถูกต้อง']);
                exit;
            }
        }

        $stmtDel = $db->prepare("DELETE FROM bookings WHERE id = ?");
        $stmtDel->execute([$bookingId]);

        echo json_encode(['success' => true, 'message' => 'ยกเลิกการลงชื่อเรียบร้อยแล้ว']);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}
