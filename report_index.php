<?php
require_once 'config/db.php';
require_once 'includes/report_helpers.php';
$reports = [
    ['report_disaster.php', 'Lab 41: Disaster Report', 'Active and closed disasters, types, severity, affected areas and how long each one lasted.'],
    ['report_affected_residents.php', 'Lab 42: Affected Residents Report', 'Affected residents and households, by barangay, vulnerable residents, and who is in which evacuation center.'],
    ['report_evacuation_center.php', 'Lab 43: Evacuation Center Report', 'Capacity, occupancy, free spaces, occupancy percent, households and vulnerable evacuees per center.'],
    ['report_resource_inventory.php', 'Lab 44: Resource Inventory Report', 'Available, low stock and expiring resources, with what was received, released and what remains.'],
    ['report_relief_distribution.php', 'Lab 45: Relief Distribution Report', 'Relief received and given out, by disaster, barangay and evacuation center, and how many beneficiaries.'],
    ['report_response_performance.php', 'Lab 46: Disaster Response Performance Report', 'Evacuation rate, resource use, relief rate, unserved households and response time per disaster.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reports</title>
    <link rel="stylesheet" href="<?= rptEsc(rptCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>
<main class="wide">
    <h1>Reports</h1>
    <p>Every report can be filtered, printed, and downloaded as a CSV file.</p>
    <div class="report-cards">
    <?php foreach ($reports as [$file, $title, $text]): ?>
        <a class="report-card" href="<?= rptEsc($file) ?>"><strong><?= rptEsc($title) ?></strong><span><?= rptEsc($text) ?></span></a>
    <?php endforeach; ?>
    </div>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
