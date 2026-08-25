<?php


require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../class/reports/ReportScheduler.php';

$db = new Database();
$conn = $db->getConnection();

// Get all active tenants
$res = $conn->query("SELECT DISTINCT tenant_id FROM scheduled_reports WHERE is_active = 1");
$processed = 0;

while ($row = $res->fetch_assoc()) {
    $tenantId = (int)$row['tenant_id'];
    $scheduler = new ReportScheduler($conn, $tenantId);

    echo "[" . date('Y-m-d H:i:s') . "] Processing tenant #{$tenantId}...\n";
    $result = $scheduler->runScheduledReports();
    echo "  Executed: {$result['executed']} reports\n";
    $processed += $result['executed'];
}

echo "[" . date('Y-m-d H:i:s') . "] Done. Total processed: {$processed}\n";
