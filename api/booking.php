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

        // Server-side Regular Expression Validation
        if (!empty($firstName) && !preg_match('/^[a-zA-Z\x{0E01}-\x{0E5B}\s\.\-]{2,60}$/u', $firstName)) {
            echo json_encode(['success' => false, 'message' => 'ชื่อไม่ถูกต้อง กรุณากรอกเป็นตัวอักษร']);
            exit;
        }

        if (!empty($lastNameOrNickname) && !preg_match('/^[a-zA-Z\x{0E01}-\x{0E5B}\s\.\-]{2,60}$/u', $lastNameOrNickname)) {
            echo json_encode(['success' => false, 'message' => 'ฉายาหรือนามสกุลไม่ถูกต้อง กรุณากรอกเป็นตัวอักษร']);
            exit;
        }

        if (!preg_match('/^0[0-9]{8,9}$/', $phone)) {
            echo json_encode(['success' => false, 'message' => 'เบอร์โทรศัพท์มือถือไม่ถูกต้อง กรุณากรอก 9-10 หลัก ขึ้นต้นด้วย 0']);
            exit;
        }

        if (!empty($age) && ((int)$age < 1 || (int)$age > 120)) {
            echo json_encode(['success' => false, 'message' => 'อายุต้องอยู่ระหว่าง 1 ถึง 120 ปี']);
            exit;
        }

        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $isSqlite = ($driver === 'sqlite');

        // High Concurrency / Multi-threading Safe Engine (Up to 5 Retries with Jittered Backoff)
        $maxRetries = 5;
        $attempt = 0;
        $bookingSuccess = false;
        $bookingResult = null;
        $errorMessage = '';

        while ($attempt < $maxRetries) {
            $attempt++;
            try {
                // 1. Transaction & Mutex Locking
                if ($isSqlite) {
                    $db->exec("PRAGMA busy_timeout = 10000;");
                }
                $db->beginTransaction();

                // 2. ตรวจสอบสถานะรอบรถ
                $stmt = $db->prepare("SELECT is_active FROM trips WHERE id = ?");
                $stmt->execute([$tripId]);
                $trip = $stmt->fetch();
                if (!$trip || (int)$trip['is_active'] !== 1) {
                    if ($db->inTransaction()) $db->rollBack();
                    echo json_encode(['success' => false, 'message' => 'รอบการเดินทางนี้ปิดรับการลงชื่อแล้ว']);
                    exit;
                }

                // 3. ดึงข้อมูลรถและล็อคแถวเพื่อป้องกันการแย่งที่นั่ง (Pessimistic Row Lock ใน PostgreSQL)
                if (!$isSqlite) {
                    $stmtVeh = $db->prepare("SELECT * FROM vehicles WHERE id = ? FOR UPDATE");
                } else {
                    $stmtVeh = $db->prepare("SELECT * FROM vehicles WHERE id = ?");
                }
                $stmtVeh->execute([$vehicleId]);
                $veh = $stmtVeh->fetch();
                if (!$veh) {
                    if ($db->inTransaction()) $db->rollBack();
                    echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลรถคันที่เลือก']);
                    exit;
                }

                // 4. ตรวจสอบความซ้ำซ้อน (ชื่อหรือเบอร์โทร) ภายใต้ Transaction Lock ป้องกัน Double Booking
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
                    if ($db->inTransaction()) $db->rollBack();
                    echo json_encode([
                        'success' => false, 
                        'message' => "ท่าน (หรือเบอร์นี้) ได้ลงชื่อไว้ใน {$dup['vehicle_name']} ลำดับที่ {$dup['seat_number']} แล้ว หากต้องการเปลี่ยนคัน กรุณายกเลิกในหน้าตรวจสอบก่อน"
                    ]);
                    exit;
                }

                // 5. หาที่นั่งว่างแบบ Atomic หรือตรวจสอบที่นั่งที่ระบุ
                $finalSeatNumber = $seatNumber;
                if ($finalSeatNumber <= 0) {
                    $stmtOccupied = $db->prepare("SELECT seat_number FROM bookings WHERE vehicle_id = ? ORDER BY seat_number ASC");
                    $stmtOccupied->execute([$vehicleId]);
                    $occupied = $stmtOccupied->fetchAll(PDO::FETCH_COLUMN);

                    $allocated = 0;
                    for ($s = 1; $s <= (int)$veh['total_seats']; $s++) {
                        if (!in_array($s, $occupied)) {
                            $allocated = $s;
                            break;
                        }
                    }

                    if ($allocated <= 0) {
                        if ($db->inTransaction()) $db->rollBack();
                        echo json_encode(['success' => false, 'message' => "ขออภัย {$veh['name']} มีผู้ลงชื่อเต็มแล้ว ({$veh['total_seats']} ที่นั่ง) กรุณาเลือกคันอื่น"]);
                        exit;
                    }
                    $finalSeatNumber = $allocated;
                } else {
                    $stmtCheck = $db->prepare("SELECT id FROM bookings WHERE vehicle_id = ? AND seat_number = ?");
                    $stmtCheck->execute([$vehicleId, $finalSeatNumber]);
                    if ($stmtCheck->fetch()) {
                        if ($db->inTransaction()) $db->rollBack();
                        echo json_encode(['success' => false, 'message' => "ที่นั่งลำดับที่ {$finalSeatNumber} ใน {$veh['name']} มีผู้ลงชื่อแล้ว"]);
                        exit;
                    }
                }

                // 6. บันทึกการลงชื่อ
                $now = date('Y-m-d H:i:s');
                $stmtInsert = $db->prepare("
                    INSERT INTO bookings (
                        trip_id, vehicle_id, seat_number, passenger_name, 
                        prefix, first_name, last_name_or_nickname, age, phone, travel_type, note, created_at
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtInsert->execute([
                    $tripId, $vehicleId, $finalSeatNumber, $passengerName,
                    $prefix, $firstName, $lastNameOrNickname, $age, $phone, $travelType, $note, $now
                ]);
                $bookingId = getDbLastInsertId($db, 'bookings');

                $db->commit();
                $bookingSuccess = true;
                $bookingResult = [
                    'id' => $bookingId,
                    'vehicle_name' => $veh['name'],
                    'seat_number' => $finalSeatNumber,
                    'passenger_name' => $passengerName,
                    'phone' => $phone,
                    'travel_type' => $travelType,
                    'created_at' => $now
                ];
                break; // สำเร็จ หลุดออกจากลูป retry

            } catch (PDOException $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $errCode = $e->getCode();
                $errInfo = $e->getMessage();

                // ตรวจสอบว่าเกิด Collision ชนกันหรือไม่ หากชนกันให้ Backoff และ Retry
                $isCollision = str_contains($errInfo, 'UNIQUE') || 
                               str_contains($errInfo, 'locked') || 
                               str_contains($errInfo, 'busy') || 
                               str_contains($errInfo, 'could not obtain lock') ||
                               $errCode == 23505 || 
                               $errCode == '23505';

                if ($isCollision && $attempt < $maxRetries) {
                    usleep(mt_rand(15000, 60000)); // สุ่มหน่วงเวลา 15ms - 60ms แล้วลองใหม่
                    continue;
                }
                $errorMessage = "ระบบมีผู้ใช้งานหนาแน่น กรุณาลองใหม่อีกครั้ง (" . ($isCollision ? 'ที่นั่งถูกจับจองไปก่อนหน้า' : 'เซิร์ฟเวอร์กำลังประมวลผล') . ")";
                break;
            }
        }

        if ($bookingSuccess && $bookingResult) {
            echo json_encode([
                'success' => true,
                'message' => "ลงชื่อสำเร็จ! ท่านได้ลงชื่อใน {$bookingResult['vehicle_name']} ลำดับที่ {$bookingResult['seat_number']}",
                'booking' => $bookingResult
            ]);
            exit;
        } else {
            echo json_encode([
                'success' => false,
                'message' => !empty($errorMessage) ? $errorMessage : 'ไม่สามารถลงชื่อได้ในขณะนี้ กรุณาลองใหม่อีกครั้ง'
            ]);
            exit;
        }

    } elseif ($action === 'cancel') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        
        // กฎความปลอดภัย: การยกเลิกการลงชื่อให้สิทธิ์เฉพาะผู้ดูแลระบบ (Admin) เท่านั้น
        $isAdmin = isAdminLoggedIn();
        if (!$isAdmin) {
            echo json_encode([
                'success' => false, 
                'message' => 'ขออภัย สิทธิ์ในการยกเลิกการลงชื่อสำหรับผู้ดูแลระบบ (Admin) เท่านั้น หากต้องการยกเลิกกรุณาแจ้งผู้ประสานงานหรือผู้ดูแลระบบ'
            ]);
            exit;
        }

        if (!$bookingId) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบรหัสการลงชื่อ']);
            exit;
        }

        $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ?");
        $stmt->execute([$bookingId]);
        $b = $stmt->fetch();
        if (!$b) {
            echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลการลงชื่อ']);
            exit;
        }

        $stmtDel = $db->prepare("DELETE FROM bookings WHERE id = ?");
        $stmtDel->execute([$bookingId]);

        echo json_encode(['success' => true, 'message' => "ยกเลิกการลงชื่อของคุณ {$b['passenger_name']} เรียบร้อยแล้ว"]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Invalid action']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()]);
}
