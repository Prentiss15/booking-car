<?php
// api/suggestions.php - High-Performance Full-Text Search (FTS) API
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

    // Call Full-Text Search Engine
    $rows = searchBookingsFts($db, $query, $tripId, 12);

    $isAdmin = isAdminLoggedIn();
    $results = [];
    foreach ($rows as $r) {
        $results[] = [
            'id' => (int)$r['id'],
            'name' => $r['passenger_name'],
            'phone' => maskPhoneNumber($r['phone']),
            'vehicle_name' => $r['vehicle_name'],
            'vehicle_id' => (int)$r['vehicle_id'],
            'seat_number' => (int)$r['seat_number'],
            'travel_type' => $r['travel_type'],
            'admin_note' => $isAdmin ? ($r['admin_note'] ?? '') : ''
        ];
    }

    echo json_encode(['results' => $results]);

} catch (Throwable $e) {
    error_log("Suggestions FTS Error: " . $e->getMessage());
    echo json_encode(['error' => 'Search error', 'results' => []]);
}
