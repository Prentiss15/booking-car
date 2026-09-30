<?php
// includes/db.php - Database connection and schema initializer
date_default_timezone_set('Asia/Bangkok');

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
            $db->exec('PRAGMA busy_timeout = 10000;');
            $db->exec('PRAGMA synchronous = NORMAL;');
            $db->exec('PRAGMA temp_store = MEMORY;');
            $db->exec('PRAGMA mmap_size = 268435456;');
            $db->exec('PRAGMA cache_size = -64000;');
        }
        
        // Run database initialization and migrations ONLY when schema needs upgrade (Version 3)
        $isPgsql = ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql');
        if ($isPgsql) {
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS _schema_versions (version INTEGER PRIMARY KEY);");
                $currentVer = (int)$db->query("SELECT COALESCE(MAX(version), 0) FROM _schema_versions")->fetchColumn();
                if ($currentVer < 3) {
                    initDatabase($db);
                    runMigrations($db);
                    $db->exec("INSERT INTO _schema_versions (version) VALUES (3) ON CONFLICT (version) DO NOTHING;");
                }
            } catch (Throwable $e) {
                initDatabase($db);
                runMigrations($db);
            }
        } else {
            $userVersion = (int)$db->query("PRAGMA user_version")->fetchColumn();
            if ($userVersion < 3) {
                initDatabase($db);
                runMigrations($db);
                $db->exec("PRAGMA user_version = 3;");
            }
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
                name TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'admin',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");

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
                created_at DATETIME DEFAULT (datetime('now', 'localtime')),
                FOREIGN KEY (trip_id) REFERENCES trips(id) ON DELETE CASCADE,
                FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
                UNIQUE(vehicle_id, seat_number)
            );
        ");
    }
}

function runMigrations(PDO $db): void {
    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $isPgsql = ($driver === 'pgsql');

    // 1. Upgrade admins table with Gmail & Multi-Tier RBAC fields
    if (!$isPgsql) {
        $cols = [];
        try {
            $colsInfo = $db->query("PRAGMA table_info(admins)")->fetchAll();
            foreach ($colsInfo as $c) {
                $cols[] = strtolower($c['name']);
            }
        } catch (Throwable $e) {}

        if (!in_array('email', $cols)) {
            try { $db->exec("ALTER TABLE admins ADD COLUMN email TEXT DEFAULT '';"); } catch (Throwable $e) {}
        }
        if (!in_array('google_id', $cols)) {
            try { $db->exec("ALTER TABLE admins ADD COLUMN google_id TEXT DEFAULT '';"); } catch (Throwable $e) {}
        }
        if (!in_array('avatar', $cols)) {
            try { $db->exec("ALTER TABLE admins ADD COLUMN avatar TEXT DEFAULT '';"); } catch (Throwable $e) {}
        }
        if (!in_array('is_active', $cols)) {
            try { $db->exec("ALTER TABLE admins ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1;"); } catch (Throwable $e) {}
        }
        if (!in_array('last_login', $cols)) {
            try { $db->exec("ALTER TABLE admins ADD COLUMN last_login DATETIME;"); } catch (Throwable $e) {}
        }
    } else {
        $adminCols = [
            'email' => "VARCHAR(255) DEFAULT ''",
            'google_id' => "VARCHAR(255) DEFAULT ''",
            'avatar' => "VARCHAR(500) DEFAULT ''",
            'is_active' => "INTEGER NOT NULL DEFAULT 1",
            'last_login' => "TIMESTAMP"
        ];
        foreach ($adminCols as $col => $def) {
            try {
                $db->exec("ALTER TABLE admins ADD COLUMN IF NOT EXISTS {$col} {$def};");
            } catch (Throwable $e) {}
        }
    }
    try {
        $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_admins_email ON admins(email);");
    } catch (Throwable $e) {}

    // 2. Setup SQLite FTS5 Full-Text Search Virtual Table with Trigram Tokenizer
    if (!$isPgsql) {
        try {
            $db->exec("
                CREATE VIRTUAL TABLE IF NOT EXISTS bookings_fts USING fts5(
                    booking_id UNINDEXED,
                    trip_id UNINDEXED,
                    passenger_name,
                    first_name,
                    last_name_or_nickname,
                    phone,
                    travel_type,
                    admin_note,
                    tokenize='trigram'
                );
            ");

            // Auto-sync triggers for FTS5
            $db->exec("
                CREATE TRIGGER IF NOT EXISTS trg_bookings_fts_ai AFTER INSERT ON bookings BEGIN
                    INSERT INTO bookings_fts (booking_id, trip_id, passenger_name, first_name, last_name_or_nickname, phone, travel_type, admin_note)
                    VALUES (new.id, new.trip_id, new.passenger_name, new.first_name, new.last_name_or_nickname, new.phone, new.travel_type, new.admin_note);
                END;

                CREATE TRIGGER IF NOT EXISTS trg_bookings_fts_ad AFTER DELETE ON bookings BEGIN
                    DELETE FROM bookings_fts WHERE booking_id = old.id;
                END;

                CREATE TRIGGER IF NOT EXISTS trg_bookings_fts_au AFTER UPDATE ON bookings BEGIN
                    DELETE FROM bookings_fts WHERE booking_id = old.id;
                    INSERT INTO bookings_fts (booking_id, trip_id, passenger_name, first_name, last_name_or_nickname, phone, travel_type, admin_note)
                    VALUES (new.id, new.trip_id, new.passenger_name, new.first_name, new.last_name_or_nickname, new.phone, new.travel_type, new.admin_note);
                END;
            ");

            // Backfill existing bookings into FTS index
            $db->exec("
                INSERT INTO bookings_fts (booking_id, trip_id, passenger_name, first_name, last_name_or_nickname, phone, travel_type, admin_note)
                SELECT b.id, b.trip_id, b.passenger_name, b.first_name, b.last_name_or_nickname, b.phone, b.travel_type, b.admin_note
                FROM bookings b
                WHERE b.id NOT IN (SELECT booking_id FROM bookings_fts);
            ");
        } catch (Throwable $e) {}
    }

    // 3. High-Performance Composite & Foreign Key Indexes
    try {
        $db->exec("CREATE INDEX IF NOT EXISTS idx_vehicles_trip ON vehicles(trip_id);");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_trip ON bookings(trip_id);");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_vehicle ON bookings(vehicle_id);");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_veh_seat ON bookings(vehicle_id, seat_number);");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_trip_phone ON bookings(trip_id, phone);");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_passenger ON bookings(passenger_name);");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_admins_email ON admins(email);");
    } catch (Throwable $e) {}

    // 4. Seed / Update standard Multi-Tier Gmail Admin Accounts
    $standardAdmins = [
        [
            'email' => 'pongsakorn664@gmail.com',
            'username' => 'pongsakorn664',
            'name' => 'คุณพงศกร (Super Admin)',
            'role' => 'superadmin',
            'avatar' => 'https://ui-avatars.com/api/?name=Pongsakorn&background=2563eb&color=fff&size=128'
        ],
        [
            'email' => 'superadmin@gmail.com',
            'username' => 'superadmin',
            'name' => 'ผู้ดูแลระบบสูงสุด (Super Admin)',
            'role' => 'superadmin',
            'avatar' => 'https://ui-avatars.com/api/?name=Super+Admin&background=3d516b&color=fff&size=128'
        ],
        [
            'email' => 'admin.dci@gmail.com',
            'username' => 'admin',
            'name' => 'ผู้ดูแลทั่วไป (Admin DCI)',
            'role' => 'admin',
            'avatar' => 'https://ui-avatars.com/api/?name=Admin+DCI&background=4a6b5b&color=fff&size=128'
        ],
        [
            'email' => 'staff.dci@gmail.com',
            'username' => 'staff',
            'name' => 'ผู้ประสานงานรถ (Staff Coordinator)',
            'role' => 'staff',
            'avatar' => 'https://ui-avatars.com/api/?name=Staff+DCI&background=c27803&color=fff&size=128'
        ]
    ];

    foreach ($standardAdmins as $adm) {
        try {
            $stmtCheck = $db->prepare("SELECT id, email FROM admins WHERE email = ? OR username = ?");
            $stmtCheck->execute([$adm['email'], $adm['username']]);
            $existing = $stmtCheck->fetch();

            if ($existing) {
                $stmtUp = $db->prepare("UPDATE admins SET email = ?, role = ?, name = ?, avatar = COALESCE(NULLIF(avatar, ''), ?) WHERE id = ?");
                $stmtUp->execute([$adm['email'], $adm['role'], $adm['name'], $adm['avatar'], $existing['id']]);
            } else {
                $randHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
                $stmtIns = $db->prepare("
                    INSERT INTO admins (username, email, password, name, role, avatar, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, 1)
                ");
                $stmtIns->execute([$adm['username'], $adm['email'], $randHash, $adm['name'], $adm['role'], $adm['avatar']]);
            }
        } catch (Throwable $e) {}
    }
}
