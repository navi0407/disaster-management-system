<?php
// Lab 44: Resource Inventory Report
require_once 'config/db.php';
require_once 'includes/report_helpers.php';

$categoryId = trim($_GET['category_id'] ?? '');
$level = $_GET['level'] ?? '';
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$days = (isset($_GET['days']) && ctype_digit($_GET['days']) && (int) $_GET['days'] >= 1 && (int) $_GET['days'] <= 365) ? (int) $_GET['days'] : 30;
$levels = ['In Stock', 'Low Stock', 'Out of Stock'];

// Received and released are counted inside the chosen dates. Remaining is always the stock right now.
$dateSql = ''; $dateTypes = ''; $dateParams = [];
if ($from !== '' && rptValidDate($from)) { $dateSql .= " AND DATE(t.transaction_date) >= ?"; $dateTypes .= 's'; $dateParams[] = $from; }
if ($to !== '' && rptValidDate($to)) { $dateSql .= " AND DATE(t.transaction_date) <= ?"; $dateTypes .= 's'; $dateParams[] = $to; }

$where = ''; $wTypes = ''; $wParams = [];
if ($categoryId !== '' && ctype_digit($categoryId)) { $where = " WHERE r.category_id = ?"; $wTypes = 'i'; $wParams = [(int) $categoryId]; }

$sql = "SELECT r.resource_code, r.resource_name, cat.category_name, r.unit, r.quantity_on_hand, r.min_stock_level,
               r.storage_location, r.expiration_date, r.status,
               COALESCE((SELECT SUM(t.quantity) FROM resource_transactions t WHERE t.resource_id = r.resource_id AND t.transaction_type = 'Receive'$dateSql), 0) AS received,
               COALESCE((SELECT SUM(t.quantity) FROM resource_transactions t WHERE t.resource_id = r.resource_id AND t.transaction_type = 'Release'$dateSql), 0) AS released
        FROM resources r LEFT JOIN resource_categories cat ON cat.category_id = r.category_id$where
        ORDER BY r.resource_name";
// the date filter appears twice in the SQL (received and released), then the category filter
$rows = rptQuery($conn, $sql, $dateTypes . $dateTypes . $wTypes, array_merge($dateParams, $dateParams, $wParams));

$today = strtotime(date('Y-m-d'));
$inv = []; $lowRows = []; $expRows = [];
$nAvail = $nLow = $nOut = $nExpiring = $nExpired = 0; $totRec = $totRel = 0;
foreach ($rows as $r) {
    $qty = (int) $r['quantity_on_hand']; $min = (int) $r['min_stock_level'];
    $lv = $qty <= 0 ? 'Out of Stock' : ($qty <= $min ? 'Low Stock' : 'In Stock');
    if (in_array($level, $levels, true) && $lv !== $level) continue;

    $expText = ''; $left = null;
    if ($r['expiration_date']) {
        $left = (int) round((strtotime($r['expiration_date']) - $today) / 86400);
        $expText = $left < 0 ? 'Expired' : ($left <= $days ? 'Expires in ' . $left . ' day(s)' : 'OK');
    }
    if ($qty > 0) $nAvail++;
    if ($lv === 'Low Stock') $nLow++;
    if ($lv === 'Out of Stock') $nOut++;
    if ($left !== null && $left < 0) $nExpired++;
    if ($left !== null && $left >= 0 && $left <= $days) $nExpiring++;
    $totRec += (int) $r['received']; $totRel += (int) $r['released'];

    $inv[] = [$r['resource_code'], $r['resource_name'], $r['category_name'], $r['unit'], (int) $r['received'], (int) $r['released'], $qty, $min, $lv, rptDay($r['expiration_date']), $expText, $r['storage_location']];
    if ($lv !== 'In Stock') $lowRows[] = [$r['resource_code'], $r['resource_name'], $qty . ' ' . $r['unit'], $min, $lv, $r['storage_location']];
    if ($left !== null && $left <= $days) $expRows[] = [$r['resource_code'], $r['resource_name'], $qty . ' ' . $r['unit'], rptDay($r['expiration_date']), $left < 0 ? 'Expired ' . abs($left) . ' day(s) ago' : $left . ' day(s) left'];
}
usort($expRows, fn($a, $b) => strcmp($a[3], $b[3]));

$catOptions = [];
foreach (rptQuery($conn, "SELECT category_id, category_name FROM resource_categories ORDER BY category_name") as $c) $catOptions[$c['category_id']] = $c['category_name'];

rptPage([
    'title' => 'Resource Inventory Report',
    'intro' => 'Stock, low stock, expiry, and what was received and released.',
    'csv' => 'resource_inventory_report',
    'filters' => rptSelect('category_id', $catOptions, $categoryId, 'All categories')
        . rptSelect('level', array_combine($levels, $levels), $level, 'All stock levels')
        . rptDates($from, $to, 'Transactions from', 'Transactions to')
        . '<input type="number" name="days" min="1" max="365" value="' . (int) $days . '" title="Expiring within this many days" style="width:90px">',
    'kpis' => [
        ['Resources shown', count($inv)], ['Available (stock above 0)', $nAvail], ['Low stock', $nLow], ['Out of stock', $nOut],
        ['Expiring within ' . $days . ' days', $nExpiring], ['Expired', $nExpired],
        ['Units received (in dates)', $totRec], ['Units released (in dates)', $totRel],
    ],
    'sections' => [
        ['title' => 'Inventory', 'headers' => ['Code', 'Resource', 'Category', 'Unit', 'Received', 'Released', 'Remaining', 'Minimum', 'Level', 'Expires', 'Expiry check', 'Storage'],
         'rows' => $inv, 'note' => 'Received and released use the dates above (all time if empty). Remaining is the stock right now.'],
        ['title' => 'Low-stock and out-of-stock resources', 'headers' => ['Code', 'Resource', 'Remaining', 'Minimum', 'Level', 'Storage'], 'rows' => $lowRows],
        ['title' => 'Expiring and expired resources (within ' . $days . ' days)', 'headers' => ['Code', 'Resource', 'Remaining', 'Expires', 'Time left'], 'rows' => $expRows],
    ],
]);
