<?php
// includes/db.php - Database connection and schema initializer

class AppPDO extends PDO {
    public function lastInsertId(?string $name = null): string|false {
        if ($this->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            try {
                $val = $this->query("SELECT LASTVAL()")->fetchColumn();
                if ($val !== false && $val > 0) {
                    return (string)$val;
                }
            } catch (Throwable $e) {}
        }
        return parent::lastInsertId($name);
    }
}

function getDbLastInsertId(PDO $db, string $table = ''): int {
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
        try {
            $val = $db->query("SELECT LASTVAL()")->fetchColumn();
            if ($val !== false && $val > 0) {
                return (int)$val;
            }
        } catch (Throwable $e) {}
        if (!empty($table)) {
            try {
                $seq = str_ends_with($table, '_seq') ? $table : ($table . '_id_seq');
                $val = $db->lastInsertId($seq);
                if ($val) return (int)$val;
            } catch (Throwable $e) {}
        }
    }
    return (int)$db->lastInsertId();
}

function getDb(): PDO {
    static $db = null;
    if ($db === null) {
        $databaseUrl = getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? ($_SERVER['DATABASE_URL'] ?? (getenv('POSTGRES_URL') ?: ($_ENV['POSTGRES_URL'] ?? ($_SERVER['POSTGRES_URL'] ?? '')))));
        $databaseUrl = trim((string)$databaseUrl);
        
        if (!empty($databaseUrl) && (str_starts_with($databaseUrl, 'postgres://') || str_starts_with($databaseUrl, 'postgresql://'))) {
            // PostgreSQL connection (e.g. Neon.tech, Supabase, Render Postgres)
            $parsed = parse_url($databaseUrl);
            $host = $parsed['host'] ?? 'localhost';
            $port = $parsed['port'] ?? 5432;
            $user = isset($parsed['user']) ? urldecode($parsed['user']) : '';
            $pass = isset($parsed['pass']) ? urldecode($parsed['pass']) : '';
            $dbname = isset($parsed['path']) ? ltrim(urldecode($parsed['path']), '/') : '';
            
            // Check query string for sslmode or options
            $sslmode = 'require';
            $endpoint = '';
            if (!empty($parsed['query'])) {
                parse_str($parsed['query'], $queryParts);
                if (isset($queryParts['sslmode'])) {
                    $sslmode = $queryParts['sslmode'];
                }
                if (!empty($queryParts['endpoint'])) {
                    $endpoint = $queryParts['endpoint'];
                }
            }
            
            $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode}";
            if (!empty($endpoint)) {
                $dsn .= ";options='endpoint={$endpoint}'";
            }

            $db = new AppPDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true, // Re-use TCP/SSL connection across requests
            ]);
        } else {
            // Fallback to SQLite
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

            $db = new AppPDO('sqlite:' . $dbPath);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            $db->exec('PRAGMA foreign_keys = ON;');
            $db->exec('PRAGMA journal_mode = WAL;');
        }
        
        // Optimize: Only initialize once per container lifecycle
        $flagFile = sys_get_temp_dir() . '/.car_db_ready_' . substr(md5($databaseUrl ?: 'sqlite'), 0, 8);
        if (!file_exists($flagFile)) {
            initDatabase($db);
            @file_put_contents($flagFile, '1');
        }
    }
    return $db;
}

function initDatabase(PDO $db): void {
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $isPgsql = ($driver === 'pgsql');

    // Quick probe: If table already exists in remote database, skip heavy DDL statements
    try {
        $probe = $db->query("SELECT 1 FROM trips LIMIT 1");
        if ($probe !== false) {
            return;
        }
    } catch (Throwable $e) {}

    if ($isPgsql) {
        // 1. Admins table (PostgreSQL)
        $db->exec("
            CREATE TABLE IF NOT EXISTS admins (
                id SERIAL PRIMARY KEY,
                username VARCHAR(100) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                plain_password VARCHAR(255) DEFAULT '',
                name VARCHAR(255) NOT NULL,
                role VARCHAR(50) NOT NULL DEFAULT 'admin',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // 2. Trips table (PostgreSQL)
        $db->exec("
            CREATE TABLE IF NOT EXISTS trips (
                id SERIAL PRIMARY KEY,
                title VARCHAR(255) NOT NULL,
                trip_date DATE NOT NULL,
                departure_time VARCHAR(100) NOT NULL DEFAULT '08.00 น.',
                destination VARCHAR(255) NOT NULL DEFAULT 'ร่วมงานบุญบูชาข้าวพระต้นเดือน',
                pickup_location VARCHAR(255) NOT NULL DEFAULT 'หน้ากุฏิพระประจำ',
                pickup_time_info VARCHAR(255) DEFAULT 'ขึ้นรถหน้ากุฏิพระประจำ 08.00 น.',
                return_time_info VARCHAR(255) DEFAULT 'เดินทางกลับ ขึ้นรถที่วิหารคดคอร์ 32 และอาคารปราบบมาร 16.00 น.',
                notice_red TEXT DEFAULT '***ถ่ายภาพสลิปการลงทะเบียน ทั้งขาไป - และขากลับส่งที่ พม.อานุภาพ เวลา 16.00 น.',
                deadline_notice TEXT DEFAULT '1. งดถอดชื่อออกเมื่อถึงวันที่ตัดยอดแล้ว\n2. ตัดยอดวันอังคารก่อนวันงาน เวลา 15:00 น.',
                notes TEXT,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // 3. Vehicles table (PostgreSQL)
        $db->exec("
            CREATE TABLE IF NOT EXISTS vehicles (
                id SERIAL PRIMARY KEY,
                trip_id INTEGER NOT NULL REFERENCES trips(id) ON DELETE CASCADE,
                type VARCHAR(50) NOT NULL DEFAULT 'van',
                vehicle_type_label VARCHAR(100) NOT NULL DEFAULT 'รถตู้ (10 ที่นั่ง)',
                vehicle_number INTEGER NOT NULL,
                name VARCHAR(255) NOT NULL,
                license_plate VARCHAR(100),
                driver_name VARCHAR(255),
                driver_phone VARCHAR(50),
                total_seats INTEGER NOT NULL DEFAULT 10,
                header_color VARCHAR(50) DEFAULT 'green',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            );
        ");

        // 4. Bookings table (PostgreSQL)
        $db->exec("
            CREATE TABLE IF NOT EXISTS bookings (
                id SERIAL PRIMARY KEY,
                trip_id INTEGER NOT NULL REFERENCES trips(id) ON DELETE CASCADE,
                vehicle_id INTEGER NOT NULL REFERENCES vehicles(id) ON DELETE CASCADE,
                seat_number INTEGER NOT NULL,
                passenger_name VARCHAR(255) NOT NULL,
                prefix VARCHAR(50) DEFAULT '',
                first_name VARCHAR(255) DEFAULT '',
                last_name_or_nickname VARCHAR(255) DEFAULT '',
                age VARCHAR(50) DEFAULT '',
                phone VARCHAR(50) NOT NULL,
                travel_type VARCHAR(100) DEFAULT 'เดินทางไป และ เดินทางกลับ',
                department VARCHAR(255),
                note TEXT,
                admin_note TEXT DEFAULT '',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT uq_vehicle_seat UNIQUE (vehicle_id, seat_number)
            );
        ");
    } else {
        // SQLite Schema
        $db->exec("
            CREATE TABLE IF NOT EXISTS admins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password TEXT NOT NULL,
                plain_password TEXT DEFAULT '',
                name TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'admin',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

        try {
            $db->exec("ALTER TABLE admins ADD COLUMN plain_password TEXT DEFAULT '';");
        } catch (Exception $e) {}

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
                is_active INTEGER NOT NULL DEFAULT 1,
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
    }

    // Common Indexes
    try {
        $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_trip ON bookings(trip_id);");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_vehicle ON bookings(vehicle_id);");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_passenger ON bookings(passenger_name);");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_phone ON bookings(phone);");
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
        $db->exec("UPDATE admins SET plain_password = 'admin123' WHERE username = 'admin' AND (plain_password IS NULL OR plain_password = '');");
    }
}
