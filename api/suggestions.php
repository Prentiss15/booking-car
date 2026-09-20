<?php
// api/suggestions.php - Instant Live Search Autocomplete / Fuzzy suggestion API
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

$db = getDb();
$query = trim($_GET['q'] ?? '');
$tripId = (int)($_GET['trip_id'] ?? 0);

if (empty($query) || mb_strlen($query) < 1) {
    echo json_encode(['results' => []]);
    exit;
}

try {
    if (!$tripId) {
        $stmtTrip = $db->query("SELECT id FROM trips ORDER BY is_active DESC, trip_date DESC LIMIT 1");
        $t = $stmtTrip->fetch();
        $tripId = $t ? (int)$t['id'] : 0;
    }

    // ตัดวรรคและแบ่งคำค้นหาเพื่อทำ Fuzzy / Partial matching
    $terms = explode(' ', $query);
    $whereParts = [];
    $params = [$tripId];

    $cleanPhone = preg_replace('/[^0-9]/', '', $query);

    foreach ($terms as $term) {
        $cleanTerm = trim($term);
        if ($cleanTerm === '') continue;
        
        $whereParts[] = "(
            b.passenger_name LIKE ? OR 
            b.first_name LIKE ? OR 
            b.last_name_or_nickname LIKE ? OR
            b.phone LIKE ?
        )";
        $wildcard = "%{$cleanTerm}%";
        $params[] = $wildcard;
        $params[] = $wildcard;
        $params[] = $wildcard;
        $params[] = $wildcard;
    }

    if (!empty($cleanPhone) && strlen($cleanPhone) >= 2) {
        $whereParts[] = "REPLACE(REPLACE(b.phone, '-', ''), ' ', '') LIKE ?";
        $params[] = "%{$cleanPhone}%";
    }

    $whereSql = !empty($whereParts) ? implode(' AND ', $whereParts) : '1=1';

    $sql = "
        SELECT b.id, b.passenger_name, b.first_name, b.last_name_or_nickname, b.phone, 
               b.seat_number, b.travel_type, b.admin_note,
               v.name as vehicle_name, v.id as vehicle_id, v.vehicle_number, v.type as vehicle_type
        FROM bookings b
        JOIN vehicles v ON b.vehicle_id = v.id
        WHERE b.trip_id = ? AND ({$whereSql})
        ORDER BY b.vehicle_id ASC, b.seat_number ASC
        LIMIT 10
    ";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $results = [];
    foreach ($rows as $r) {
        $results[] = [
            'id' => (int)$r['id'],
            'name' => $r['passenger_name'],
            'phone' => $r['phone'],
            'vehicle_name' => $r['vehicle_name'],
            'vehicle_id' => (int)$r['vehicle_id'],
            'seat_number' => (int)$r['seat_number'],
            'travel_type' => $r['travel_type'],
            'admin_note' => $r['admin_note'] ?? ''
        ];
    }

    echo json_encode(['results' => $results]);

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage(), 'results' => []]);
}
