<?php
// Lab 47: Disaster Management Dashboard. Read only. Every role can open it (the page name starts with "dashboard").
require_once 'config/db.php';
require_once 'includes/report_helpers.php';
require_once 'includes/dashboard_helpers.php';

$d = dashData($conn);

// ---- Numbers worked out from the data ----
$activeCenters = array_values(array_filter($d['centers'], fn($c) => $c['status'] === 'Active'));
$availCap = 0;
$totCap = 0;
$occ = 0;
foreach ($activeCenters as $c) {
    $cap = (int) $c['capacity'];
    $o = (int) $c['current_occupancy'];
    $totCap += $cap;
    $occ += $o;
    $availCap += max(0, $cap - $o);
}
$availRes = 0;
$lowRes = 0;
foreach ($d['resources'] as $r) {
    $q = (int) $r['quantity_on_hand'];
    if ($q > 0) $availRes++;
    if ($q > 0 && $q <= (int) $r['min_stock_level']) $lowRes++;
}

// ---- Chart rows ----
$typeRows = [];
foreach ($d['by_type'] as $r) $typeRows[] = [$r['label'], (int) $r['n'], (int) $r['n'] . ' (' . (int) $r['active'] . ' active)', 'c-blue'];

$areaRows = [];
foreach ($d['affected_by_area'] as $r) $areaRows[] = [$r['label'], (int) $r['n'], (int) $r['n'], 'c-blue'];

$evacRows = [];
foreach ($d['evacuees_by_center'] as $r) $evacRows[] = [$r['label'], (int) $r['n'], (int) $r['n'], 'c-blue'];

// Occupancy bars always run 0 to 100%, so a half-empty center looks half full.
$occRows = [];
foreach ($activeCenters as $c) {
    $cap = (int) $c['capacity'];
    $o = (int) $c['current_occupancy'];
    $pct = $cap > 0 ? round($o / $cap * 100, 1) : 0;
    $occRows[] = [$c['center_name'], min(100, $pct), rptFmt($pct) . '% (' . $o . '/' . $cap . ')', $pct >= 100 ? 'c-bad' : ($pct >= 70 ? 'c-warn' : 'c-ok')];
}
usort($occRows, fn($a, $b) => $b[1] <=> $a[1]);

$resRows = [];
foreach ($d['resources'] as $r)
    $resRows[] = [$r['resource_name'], (int) $r['quantity_on_hand'], (int) $r['quantity_on_hand'] . ' ' . $r['unit'] . ' (min ' . (int) $r['min_stock_level'] . ')', dashStockClass($r['quantity_on_hand'], $r['min_stock_level'])];
usort($resRows, fn($a, $b) => $a[1] <=> $b[1]);          // lowest stock first, so problems are on top
$resRows = array_slice($resRows, 0, 12);

$reliefRows = [];
foreach ($d['relief_items'] as $r)
    $reliefRows[] = [$r['item_name'], (int) $r['received'], (int) $r['given'], (int) $r['given'] . ' of ' . (int) $r['received'] . ' ' . $r['unit']];

$errors = $GLOBALS['RPT_ERRORS'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <?php include 'includes/header.php'; ?>
    <main class="wide dashboard">
        <h1>Disaster Management Dashboard</h1>
        <p class="no-print">Updated <?= date('Y-m-d H:i') ?> &middot; <a href="dashboard.php">Refresh</a> &middot; <a href="report_index.php">All reports</a> &middot; <button type="button" onclick="window.print()">Print</button></p>
        <?php foreach ($errors as $err): ?><div class="error-box">A query failed: <?= rptEsc($err) ?></div><?php endforeach; ?>

        <div class="dash-grid">
            <?= dashCard('Active Disasters', $d['active_disasters'], 'of ' . $d['total_disasters'] . ' disasters in total', 'report_disaster.php?scope=active', $d['active_disasters'] > 0 ? 'tone-warn' : '') ?>
            <?= dashCard('Affected Residents', $d['affected'], 'in the area of an active disaster', 'report_affected_residents.php?disaster_id=active') ?>
            <?= dashCard('Evacuees', $d['evacuees'], 'checked in right now', 'evacuee_monitor.php') ?>
            <?= dashCard('Evacuation Centers', count($activeCenters), 'active, of ' . count($d['centers']) . ' registered', 'report_evacuation_center.php') ?>
            <?= dashCard('Available Capacity', $availCap, 'free spaces of ' . $totCap . ' in active centers', 'report_evacuation_center.php', $totCap > 0 && $availCap === 0 ? 'tone-bad' : 'tone-ok') ?>
            <?= dashCard('Available Resources', $availRes, $lowRes . ' low on stock, ' . (count($d['resources']) - $availRes) . ' out', 'report_resource_inventory.php', $lowRes > 0 ? 'tone-warn' : '') ?>
            <?= dashCard('Relief Distributed', $d['releases'], 'releases, ' . $d['hh_served_all'] . ' households served', 'report_relief_distribution.php') ?>
            <?= dashCard('Unserved Beneficiaries', $d['unserved'], 'sheltered households with no relief yet', 'report_response_performance.php', $d['unserved'] > 0 ? 'tone-bad' : 'tone-ok') ?>
        </div>

        <div class="chart-grid">
            <?= dashBars('Disasters by Type', $typeRows, 'report_disaster.php', 'No disasters registered yet.') ?>
            <?= dashBars('Affected Residents by Area (active disasters)', $areaRows, 'report_affected_residents.php?disaster_id=active', 'No households match the area of an active disaster.') ?>
            <?= dashBars('Evacuees per Center', $evacRows, 'evacuee_monitor.php', 'Nobody is checked in right now.') ?>
            <?= dashBars('Evacuation Center Occupancy', $occRows, 'report_evacuation_center.php', 'No active evacuation centers.') ?>
            <?= dashBars('Resource Inventory (lowest stock first)', $resRows, 'report_resource_inventory.php', 'No resources registered yet.') ?>
            <?= dashDualBars('Relief Distribution (received and distributed)', $reliefRows, 'Received', 'Distributed', 'report_relief_distribution.php', 'No relief items registered yet.') ?>
            <?= dashLine('Monthly Disaster Trends (disasters reported, last 12 months)', $d['trend_labels'], $d['trend_values'], 'report_disaster.php') ?>
        </div>
    </main>
    <?php include 'includes/footer.php'; ?>
</body>

</html>