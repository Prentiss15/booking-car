<?php
// includes/db.php - Database connection and schema initializer

function getDb(): PDO {
    static $db = null;
    if ($db === null) {
        $envDbPath = getenv('DB_PATH');
        if (!empty($envDbPath)) {
            $dbPath = $envDbPath;
        } elseif (file_exists(__DIR__ . '/../data/database.sqlite')) {
            $dbPath = __DIR__ . '/../data/database.sqlite';
        } else {
            $dbPath = __DIR__ . '/../database.sqlite';
        }

        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $db = new PDO('sqlite:' . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        $db->exec('PRAGMA foreign_keys = ON;');
        $db->exec('PRAGMA journal_mode = WAL;');
        
        initDatabase($db);
    }
    return $db;
}

function initDatabase(PDO $db): void {
    // 1. Admins table with plain_password for superadmin recovery
    $db->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            plain_password TEXT DEFAULT '',
            name TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'admin', -- 'superadmin' or 'admin'
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    try {
        $db->exec("ALTER TABLE admins ADD COLUMN plain_password TEXT DEFAULT '';");
    } catch (Exception $e) {}

    // Seed superadmin and default admin if not exist
    $stmtSuper = $db->prepare("SELECT id FROM admins WHERE username = 'superadmin'");
    $stmtSuper->execute();
    if (!$stmtSuper->fetch()) {
        $superHash = password_hash('superadmin123', PASSWORD_DEFAULT);
        $stmtInsSuper = $db->prepare("
            INSERT INTO admins (username, password, plain_password, name, role) 
            VALUES (?, ?, ?, ?, 'superadmin')
        ");
        $stmtInsSuper->execute(['superadmin', $superHash, 'superadmin123', 'ผู้ดูแลระบบสูงสุด (Super Admin)']);
    }

    $stmtAdmin = $db->prepare("SELECT id FROM admins WHERE username = 'admin'");
    $stmtAdmin->execute();
    if (!$stmtAdmin->fetch()) {
        $adminHash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmtInsAdmin = $db->prepare("
            INSERT INTO admins (username, password, plain_password, name, role) 
            VALUES (?, ?, ?, ?, 'admin')
        ");
        $stmtInsAdmin->execute(['admin', $adminHash, 'admin123', 'ผู้ดูแลทั่วไป (Admin)']);
    } else {
        // Update existing admin plain_password if empty
        $db->exec("UPDATE admins SET plain_password = 'admin123' WHERE username = 'admin' AND (plain_password IS NULL OR plain_password = '');");
    }

    // 2. Trips table
    $db->exec("
        CREATE TABLE IF NOT EXISTS trips (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            trip_date DATE NOT NULL,
            departure_time TEXT NOT NULL DEFAULT '08.00 น.',
            destination TEXT NOT NULL DEFAULT 'ร่วมงานบุญบูชาข้าวพระต้นเดือน',
            pickup_location TEXT NOT NULL DEFAULT 'หน้ากุฏิพระประจำ',
            pickup_time_info TEXT DEFAULT 'ขึ้นรถหน้ากุฏิพระประจำ 08.00 น.',
            return_time_info TEXT DEFAULT 'เดินทางกลับ ขึ้นรถที่วิหารคดคอร์ 32 และอาคารปราบบมาร 16.00 น.',
            notice_red TEXT DEFAULT '***ถ่ายภาพสลิปการลงทะเบียน ทั้งขาไป - และขากลับส่งที่ พม.อานุภาพ เวลา 16.00 น.',
            deadline_notice TEXT DEFAULT '1. งดถอดชื่อออกเมื่อถึงวันที่ตัดยอดแล้ว\n2. ตัดยอดวันอังคารก่อนวันงาน เวลา 15:00 น.',
            notes TEXT,
            is_active INTEGER NOT NULL DEFAULT 1, -- 1 = เปิดรับ, 0 = ปิดรับ
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");

    $tripCols = [
        'pickup_time_info' => "TEXT DEFAULT 'ขึ้นรถหน้ากุฏิพระประจำ 08.00 น.'",
        'return_time_info' => "TEXT DEFAULT 'เดินทางกลับ ขึ้นรถที่วิหารคดคอร์ 32 และอาคารปราบบมาร 16.00 น.'",
        'notice_red' => "TEXT DEFAULT '***ถ่ายภาพสลิปการลงทะเบียน ทั้งขาไป - และขากลับส่งที่ พม.อานุภาพ เวลา 16.00 น.'",
        'deadline_notice' => "TEXT DEFAULT '1. งดถอดชื่อออกเมื่อถึงวันที่ตัดยอดแล้ว\n2. ตัดยอดวันอังคารก่อนวันงาน เวลา 15:00 น.'"
    ];
    foreach ($tripCols as $col => $type) {
        try {
            $db->exec("ALTER TABLE trips ADD COLUMN {$col} {$type};");
        } catch (Exception $e) {}
    }

    // 3. Vehicles table
    $db->exec("
        CREATE TABLE IF NOT EXISTS vehicles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            trip_id INTEGER NOT NULL,
            type TEXT NOT NULL DEFAULT 'van',
            vehicle_type_label TEXT NOT NULL DEFAULT 'รถตู้ (10 ที่นั่ง)',
            vehicle_number INTEGER NOT NULL,
            name TEXT NOT NULL,
            license_plate TEXT,
            driver_name TEXT,
            driver_phone TEXT,
            total_seats INTEGER NOT NULL DEFAULT 10,
            header_color TEXT DEFAULT 'green',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE
        );
    ");

    try {
        $db->exec("ALTER TABLE vehicles ADD COLUMN vehicle_type_label TEXT NOT NULL DEFAULT 'รถตู้ (10 ที่นั่ง)';");
    } catch (Exception $e) {}

    // 4. Bookings table
    $db->exec("
        CREATE TABLE IF NOT EXISTS bookings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            trip_id INTEGER NOT NULL,
            vehicle_id INTEGER NOT NULL,
            seat_number INTEGER NOT NULL,
            passenger_name TEXT NOT NULL,
            prefix TEXT DEFAULT '',
            first_name TEXT DEFAULT '',
            last_name_or_nickname TEXT DEFAULT '',
            age TEXT DEFAULT '',
            phone TEXT NOT NULL,
            travel_type TEXT DEFAULT 'เดินทางไป และ เดินทางกลับ',
            department TEXT,
            note TEXT,
            admin_note TEXT DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
            FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
            UNIQUE(vehicle_id, seat_number)
        );
    ");

    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_trip ON bookings(trip_id);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_vehicle ON bookings(vehicle_id);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_passenger ON bookings(passenger_name);");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_phone ON bookings(phone);");
}
