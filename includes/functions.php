<?php
// includes/functions.php - Utility functions for date calculation, auth, formatting and helpers

// Set Thailand timezone for all date/time operations
date_default_timezone_set('Asia/Bangkok');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * ตรวจสอบว่า Admin เข้าสู่ระบบอยู่หรือไม่
 */
function isAdminLoggedIn(): bool {
    return !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

/**
 * ตรวจสอบว่าเป็น Superadmin หรือไม่
 */
function isSuperAdmin(): bool {
    return isAdminLoggedIn() && !empty($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'superadmin';
}

/**
 * บังคับให้ต้องเข้าสู่ระบบ Admin ก่อน
 */
function requireAdminLogin(): void {
    if (!isAdminLoggedIn()) {
        $returnUrl = urlencode($_SERVER['REQUEST_URI'] ?? '/admin/index.php');
        header("Location: /admin/login.php?redirect={$returnUrl}");
        exit;
    }
}

/**
 * ดึงข้อมูล Admin ปัจจุบัน
 */
function getCurrentAdmin(): ?array {
    if (!isAdminLoggedIn()) return null;
    return [
        'id' => $_SESSION['admin_id'] ?? 0,
        'username' => $_SESSION['admin_username'] ?? '',
        'name' => $_SESSION['admin_name'] ?? 'ผู้ดูแล',
        'role' => $_SESSION['admin_role'] ?? 'admin'
    ];
}

/**
 * คำนวณวันอาทิตย์ต้นเดือน (First Sunday of month)
 */
function getFirstSundayOfMonth(?int $year = null, ?int $month = null): string {
    if ($year === null) $year = (int)date('Y');
    if ($month === null) $month = (int)date('m');

    $firstDay = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    
    if ($firstDay->format('N') == 7) {
        return $firstDay->format('Y-m-d');
    } else {
        $firstDay->modify('first sunday of this month');
        return $firstDay->format('Y-m-d');
    }
}

/**
 * หาวันอาทิตย์ต้นเดือนรอบถัดไปที่ยังไม่เลยกำหนด
 */
function getNextFirstSunday(): string {
    $today = date('Y-m-d');
    $currentFirstSunday = getFirstSundayOfMonth((int)date('Y'), (int)date('m'));
    
    if ($currentFirstSunday >= $today) {
        return $currentFirstSunday;
    }
    
    $nextMonth = new DateTime('first day of next month');
    return getFirstSundayOfMonth((int)$nextMonth->format('Y'), (int)$nextMonth->format('m'));
}

/**
 * แสดงรายชื่อวันอาทิตย์ต้นเดือนถัดๆ ไป
 */
function getUpcomingFirstSundays(int $count = 6): array {
    $sundays = [];
    $current = new DateTime();
    
    for ($i = 0; $i < $count; $i++) {
        $targetDate = clone $current;
        if ($i > 0) {
            $targetDate->modify("+{$i} month");
        }
        $sundayStr = getFirstSundayOfMonth((int)$targetDate->format('Y'), (int)$targetDate->format('m'));
        $sundays[] = [
            'date' => $sundayStr,
            'label' => formatThaiDate($sundayStr, true)
        ];
    }
    return $sundays;
}

/**
 * แปลงวันที่ Y-m-d เป็นภาษาไทย
 */
function formatThaiDate(?string $dateStr, bool $includeDay = true): string {
    if (!$dateStr) return '-';
    
    $timestamp = strtotime($dateStr);
    if (!$timestamp) return $dateStr;

    $thaiDays = [
        'Sunday' => 'วันอาทิตย์',
        'Monday' => 'วันจันทร์',
        'Tuesday' => 'วันอังคาร',
        'Wednesday' => 'วันพุธ',
        'Thursday' => 'วันพฤหัสบดี',
        'Friday' => 'วันศุกร์',
        'Saturday' => 'วันเสาร์'
    ];

    $thaiMonths = [
        1 => 'มกราคม', 2 => 'กุมภาพันธ์', 3 => 'มีนาคม', 4 => 'เมษายน',
        5 => 'พฤษภาคม', 6 => 'มิถุนายน', 7 => 'กรกฎาคม', 8 => 'สิงหาคม',
        9 => 'กันยายน', 10 => 'ตุลาคม', 11 => 'พฤศจิกายน', 12 => 'ธันวาคม'
    ];

    $dayName = $thaiDays[date('l', $timestamp)] ?? '';
    $dayNum = date('j', $timestamp);
    $monthNum = (int)date('n', $timestamp);
    $monthName = $thaiMonths[$monthNum] ?? '';
    $yearThai = (int)date('Y', $timestamp) + 543;

    if ($includeDay) {
        return "{$dayName}ที่ {$dayNum} {$monthName} {$yearThai}";
    }
    return "{$dayNum} {$monthName} {$yearThai}";
}

/**
 * Sanitize input
 */
function clean(mixed $value): string {
    return htmlspecialchars(trim((string)$value), ENT_QUOTES, 'UTF-8');
}

/**
 * คืนค่าสีหัวตารางตามลำดับคัน
 */
function getVehicleHeaderStyle(int $vehicleNumber, ?string $color = null): array {
    $styles = [
        1 => ['bg' => '#85b977', 'text' => '#143c08', 'border' => '#5c964c', 'badge' => 'bg-emerald-100 text-emerald-800'],
        2 => ['bg' => '#f6c445', 'text' => '#5c3a00', 'border' => '#d49d1f', 'badge' => 'bg-amber-100 text-amber-800'],
        3 => ['bg' => '#bdd7ee', 'text' => '#0c355c', 'border' => '#8cb5db', 'badge' => 'bg-sky-100 text-sky-800'],
        4 => ['bg' => '#d9b3e6', 'text' => '#4c175e', 'border' => '#b885c9', 'badge' => 'bg-purple-100 text-purple-800'],
        5 => ['bg' => '#f8b4b4', 'text' => '#6b1414', 'border' => '#e07a7a', 'badge' => 'bg-rose-100 text-rose-800']
    ];
    
    $index = (($vehicleNumber - 1) % 5) + 1;
    return $styles[$index] ?? $styles[1];
}

/**
 * สร้างข้อมูลตัวอย่างเริ่มต้น
 */
function seedDemoDataIfEmpty(PDO $db): void {
    static $seededChecked = false;
    if ($seededChecked) return;
    $seededChecked = true;

    $flagFile = sys_get_temp_dir() . '/.car_seeded_flag';
    if (file_exists($flagFile)) return;

    $stmt = $db->query("SELECT COUNT(*) as count FROM trips");
    $count = (int)$stmt->fetch()['count'];
    if ($count > 0) {
        @file_put_contents($flagFile, '1');
        return;
    }

    $tripDate = '2026-10-04';
    
    $stmt = $db->prepare("
        INSERT INTO trips (
            title, trip_date, departure_time, destination, pickup_location, 
            pickup_time_info, return_time_info, notice_red, deadline_notice, is_active
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");
    $stmt->execute([
        'งานบูชาข้าวพระต้นเดือน',
        $tripDate,
        '08.00 น.',
        'งานบูชาข้าวพระต้นเดือน',
        'หน้ากุฏิพระประจำ',
        'ขึ้นรถหน้ากุฏิพระประจำ 08.00 น.',
        'เดินทางกลับ ขึ้นรถที่วิหารคดคอร์ 32 และอาคารปราบบมาร 16.00 น.',
        '***ถ่ายภาพสลิปการลงทะเบียน ทั้งขาไป - และขากลับส่งที่ พม.อานุภาพ เวลา 16.00 น.',
        "1. งดถอดชื่อออกเมื่อถึงวันที่ตัดยอดแล้ว\n2. ตัดยอดวันอังคารก่อนวันงาน เวลา 15:00 น."
    ]);
    $tripId = getDbLastInsertId($db, 'trips');

    $stmtVeh = $db->prepare("
        INSERT INTO vehicles (trip_id, type, vehicle_type_label, vehicle_number, name, total_seats, header_color)
        VALUES (?, 'van', 'รถตู้ (10 ที่นั่ง)', ?, ?, 10, ?)
    ");
    
    $stmtVeh->execute([$tripId, 1, 'คันที่ 1', 'green']);
    $v1Id = getDbLastInsertId($db, 'vehicles');

    $stmtVeh->execute([$tripId, 2, 'คันที่ 2', 'amber']);
    $v2Id = getDbLastInsertId($db, 'vehicles');

    $stmtVeh->execute([$tripId, 3, 'คันที่ 3', 'blue']);
    $v3Id = getDbLastInsertId($db, 'vehicles');

    $stmtBook = $db->prepare("
        INSERT INTO bookings (trip_id, vehicle_id, seat_number, passenger_name, prefix, first_name, last_name_or_nickname, age, phone, travel_type, admin_note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    $car1Data = [
        [1, 'พระมหาบุญช่วย สุวฑฺฒโน', 'พระมหา', 'บุญช่วย', 'สุวฑฺฒโน', '35', '0825430890', 'เดินทางไป และ เดินทางกลับ', ''],
        [2, 'พระอภิภัทร กตญาโณ', 'พระ', 'อภิภัทร', 'กตญาโณ', '30', '0819751685', 'เดินทางไป และ เดินทางกลับ', ''],
        [3, 'พระมหาวีระพล วชิรจิตฺโต', 'พระมหา', 'วีระพล', 'วชิรจิตฺโต', '32', '0925199561', 'เดินทางไป และ เดินทางกลับ', ''],
        [4, 'พระมหาก้องพิภพ ธมฺมวิสารโท', 'พระมหา', 'ก้องพิภพ', 'ธมฺมวิสารโท', '28', '0626417455', 'เดินทางไป และ เดินทางกลับ', ''],
        [5, 'พระมหานนทวัฒน์ ธมฺมนนฺทิโย', 'พระมหา', 'นนทวัฒน์', 'ธมฺมนนฺทิโย', '29', '0821419930', 'เดินทางไป และ เดินทางกลับ', ''],
        [6, 'พระณัฐพงษ์ ฐิตวชิโร', 'พระ', 'ณัฐพงษ์', 'ฐิตวชิโร', '31', '0983597109', 'เดินทางไป และ เดินทางกลับ', ''],
        [7, 'พระยงยุทธ สุภทฺทวาโส', 'พระ', 'ยงยุทธ', 'สุภทฺทวาโส', '40', '0929516511', 'เดินทางไป และ เดินทางกลับ', ''],
        [8, 'พระกิตติศักดิ์ ธมฺมสกฺโก', 'พระ', 'กิตติศักดิ์', 'ธมฺมสกฺโก', '33', '0611915204', 'เดินทางไป และ เดินทางกลับ', ''],
        [9, 'พระมหาเสกสรรค์ สุทธจิตฺโต', 'พระมหา', 'เสกสรรค์', 'สุทฺธจิตฺโต', '34', '0653615523', 'เดินทางไป และ เดินทางกลับ', ''],
        [10, 'พระสุเมธ สุภพโล', 'พระ', 'สุเมธ', 'สุภพโล', '38', '0928276289', 'เดินทางไป และ เดินทางกลับ', '']
    ];
    foreach ($car1Data as $d) {
        $stmtBook->execute([$tripId, $v1Id, $d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], $d[7], $d[8]]);
    }

    $car2Data = [
        [1, 'พระมหาธันวา ธมฺมวิริโย', 'พระมหา', 'ธันวา', 'ธมฺมวิริโย', '36', '0814816435', 'เดินทางไป และ เดินทางกลับ', ''],
        [2, 'พระมหานิรุทธิ์ รตนสุโภ', 'พระมหา', 'นิรุทธิ์', 'รตนสุโภ', '31', '0654454009', 'เดินทางไป และ เดินทางกลับ', ''],
        [3, 'พระมหาเอกชัย ชยสาโร', 'พระมหา', 'เอกชัย', 'ชยสาโร', '34', '0915123729', 'เดินทางไป และ เดินทางกลับ', ''],
        [4, 'พระวัฒนา ปสิทฺธิโก', 'พระ', 'วัฒนา', 'ปสิทฺธิโก', '29', '0807656522', 'เดินทางไป และ เดินทางกลับ', ''],
        [5, 'พระวรินทร ธมฺมมงฺคโล', 'พระ', 'วรินทร', 'ธมฺมมงฺคโล', '30', '0910547690', 'เดินทางไป และ เดินทางกลับ', ''],
        [6, 'พระมหาโชคตระการ ธมฺมโชติกาโร', 'พระมหา', 'โชคตระการ', 'ธมฺมโชติกาโร', '33', '0956240284', 'เดินทางไป และ เดินทางกลับ', ''],
        [7, 'พระมหาดุสิต ธมฺมเตโช', 'พระมหา', 'ดุสิต', 'ธมฺมเตโช', '35', '0925197633', 'เดินทางไป และ เดินทางกลับ', ''],
        [8, 'พระมหาจตุรงค์ จิรฏฺฐิโต', 'พระมหา', 'จตุรงค์', 'จิรฏฺฐิโต', '32', '0957916553', 'เดินทางไป และ เดินทางกลับ', ''],
        [9, 'พระอุกฤษฎ์ ธมฺมรโต', 'พระ', 'อุกฤษฎ์', 'ธมฺมรโต', '37', '0839749141', 'ไปอย่างเดียว', 'พม.เกียรติศักดิ์กลับแทน'],
        [10, 'พระมหาดีธรรม ธมฺมาภิรโม', 'พระมหา', 'ดีธรรม', 'ธมฺมาภิรโม', '35', '0953262096', 'เดินทางไป และ เดินทางกลับ', '']
    ];
    foreach ($car2Data as $d) {
        $stmtBook->execute([$tripId, $v2Id, $d[0], $d[1], $d[2], $d[3], $d[4], $d[5], $d[6], $d[7], $d[8]]);
    }
}
