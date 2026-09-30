<?php
// details.php - รวมเข้ากับแผงควบคุมระบบ (admin/index.php) แล้ว
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

requireAdminLogin();

$tripId = isset($_GET['trip_id']) ? (int)$_GET['trip_id'] : 0;
$export = clean($_GET['export'] ?? '');
$target = '/admin/index.php?view=table';

if ($tripId > 0) {
    $target .= "&trip_id={$tripId}";
}
if (!empty($export)) {
    $target .= "&export=" . urlencode($export);
}

header("Location: {$target}");
exit;
