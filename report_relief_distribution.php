<?php
// Lab 45: Relief Distribution Report
require_once 'config/db.php';
require_once 'includes/report_helpers.php';

$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$disasterId = trim($_GET['disaster_id'] ?? '');
$centerId = trim($_GET['center_id'] ?? '');

// Filter for items that were really given out (date_given is set).
$gw = ["di.date_given IS NOT NULL"]; $gt = ''; $gp = [];
if ($from !== '' && rptValidDate($from)) { $gw[] = "DATE(di.date_given) >= ?"; $gt .= 's'; $gp[] = $from; }
if ($to !== '' && rptValidDate($to)) { $gw[] = "DATE(di.date_given) <= ?"; $gt .= 's'; $gp[] = $to; }
if ($disasterId !== '' && ctype_digit($disasterId)) { $gw[] = "d.disaster_id = ?"; $gt .= 'i'; $gp[] = (int) $disasterId; }
if ($centerId !== '' && ctype_digit($centerId)) { $gw[] = "d.center_id = ?"; $gt .= 'i'; $gp[] = (int) $centerId; }
$gWhere = ' WHERE ' . implode(' AND ', $gw);
$gFrom = " FROM distribution_items di
           JOIN relief_distributions d ON d.distribution_id = di.distribution_id
           JOIN relief_items i ON i.relief_item_id = di.relief_item_id
           LEFT JOIN households h ON h.household_id = di.household_id
           LEFT JOIN disasters ds ON ds.disaster_id = d.disaster_id
           LEFT JOIN evacuation_centers c ON c.center_id = d.center_id";

// Receipts use the same dates (they are not tied to a disaster or a center).
$rOn = ''; $rt = ''; $rp = [];
if ($from !== '' && rptValidDate($from)) { $rOn .= " AND rr.date_received >= ?"; $rt .= 's'; $rp[] = $from; }
if ($to !== '' && rptValidDate($to)) { $rOn .= " AND rr.date_received <= ?"; $rt .= 's'; $rp[] = $to; }


// Received and distributed per item, with the stock that is left now (all time).
$receivedByItem = [];
foreach (rptQuery($conn,
    "SELECT i.relief_item_id, i.item_name, i.unit, COALESCE(SUM(rr.quantity), 0) AS received
     FROM relief_items i LEFT JOIN relief_receipts rr ON rr.relief_item_id = i.relief_item_id$rOn
     GROUP BY i.relief_item_id, i.item_name, i.unit ORDER BY i.item_name", $rt, $rp) as $r) $receivedByItem[$r['relief_item_id']] = $r;

$givenByItem = [];
foreach (rptQuery($conn, "SELECT i.relief_item_id, SUM(di.quantity) AS given" . $gFrom . $gWhere . " GROUP BY i.relief_item_id", $gt, $gp) as $r)
    $givenByItem[$r['relief_item_id']] = (int) $r['given'];

$stockNow = [];
foreach (rptQuery($conn,
    "SELECT i.relief_item_id,
            COALESCE((SELECT SUM(quantity) FROM relief_receipts WHERE relief_item_id = i.relief_item_id), 0) -
            COALESCE((SELECT SUM(quantity) FROM distribution_items WHERE relief_item_id = i.relief_item_id AND date_given IS NOT NULL), 0) AS stock
     FROM relief_items i") as $r) $stockNow[$r['relief_item_id']] = (int) $r['stock'];

$itemRows = [];
foreach ($receivedByItem as $id => $r)
    $itemRows[] = [$r['item_name'], $r['unit'], (int) $r['received'], $givenByItem[$id] ?? 0, $stockNow[$id] ?? 0];

$byDisaster = [];
foreach (rptQuery($conn,
    "SELECT ds.disaster_number, ds.disaster_name, i.item_name, i.unit, SUM(di.quantity) AS qty, COUNT(DISTINCT di.household_id) AS hh"
    . $gFrom . $gWhere . " GROUP BY ds.disaster_id, ds.disaster_number, ds.disaster_name, i.relief_item_id, i.item_name, i.unit
                           ORDER BY ds.disaster_number, i.item_name", $gt, $gp) as $r)
    $byDisaster[] = [$r['disaster_number'] . ' - ' . $r['disaster_name'], $r['item_name'], (int) $r['qty'] . ' ' . $r['unit'], (int) $r['hh']];

$byBarangay = [];
foreach (rptQuery($conn,
    "SELECT h.barangay, i.item_name, i.unit, SUM(di.quantity) AS qty, COUNT(DISTINCT di.household_id) AS hh"
    . $gFrom . $gWhere . " GROUP BY h.barangay, i.relief_item_id, i.item_name, i.unit ORDER BY h.barangay, i.item_name", $gt, $gp) as $r)
    $byBarangay[] = [$r['barangay'] ?: '(no barangay)', $r['item_name'], (int) $r['qty'] . ' ' . $r['unit'], (int) $r['hh']];

$byCenter = [];
foreach (rptQuery($conn,
    "SELECT c.center_name, i.item_name, i.unit, SUM(di.quantity) AS qty, COUNT(DISTINCT di.household_id) AS hh"
    . $gFrom . $gWhere . " GROUP BY c.center_id, c.center_name, i.relief_item_id, i.item_name, i.unit ORDER BY c.center_name, i.item_name", $gt, $gp) as $r)
    $byCenter[] = [$r['center_name'] ?: '(no center)', $r['item_name'], (int) $r['qty'] . ' ' . $r['unit'], (int) $r['hh']];

// Beneficiaries: households that received something, and the people living in those households.
$served = array_map('intval', array_column(rptQuery($conn, "SELECT DISTINCT di.household_id" . $gFrom . $gWhere . " AND di.household_id IS NOT NULL", $gt, $gp), 'household_id'));
$people = 0;
if ($served) {
    [$ph, $ty, $pa] = rptIn($served);
    $people = (int) (rptQuery($conn, "SELECT COUNT(*) AS n FROM residents WHERE status = 'Active' AND household_id IN ($ph)", $ty, $pa)[0]['n'] ?? 0);
}
$releases = (int) (rptQuery($conn, "SELECT COUNT(DISTINCT d.distribution_id) AS n" . $gFrom . $gWhere, $gt, $gp)[0]['n'] ?? 0);

$disasterOptions = [];
foreach (rptQuery($conn, "SELECT disaster_id, disaster_number, disaster_name FROM disasters ORDER BY date_reported DESC") as $d)
    $disasterOptions[$d['disaster_id']] = $d['disaster_number'] . ' - ' . $d['disaster_name'];
$centerOptions = [];
foreach (rptQuery($conn, "SELECT center_id, center_name FROM evacuation_centers ORDER BY center_name") as $c) $centerOptions[$c['center_id']] = $c['center_name'];

rptPage([
    'title' => 'Relief Distribution Report',
    'intro' => 'Relief received and given out. Dates filter on the date received and the date given.',
    'csv' => 'relief_distribution_report',
    'filters' => rptDates($from, $to, 'From date', 'To date')
        . rptSelect('disaster_id', $disasterOptions, $disasterId, 'All disasters')
        . rptSelect('center_id', $centerOptions, $centerId, 'All centers'),
    'kpis' => [['Releases', $releases], ['Households served', count($served)], ['People in those households', $people]],
    'sections' => [
        ['title' => 'Relief received and distributed', 'headers' => ['Item', 'Unit', 'Received', 'Distributed', 'In stock now'], 'rows' => $itemRows,
         'note' => 'Received and distributed follow the filters. "In stock now" is always all time. Disaster and center filters apply to distributed only.'],
        ['title' => 'Distribution by disaster', 'headers' => ['Disaster', 'Item', 'Quantity given', 'Households'], 'rows' => $byDisaster],
        ['title' => 'Distribution by barangay', 'headers' => ['Barangay', 'Item', 'Quantity given', 'Households'], 'rows' => $byBarangay],
        ['title' => 'Distribution by evacuation center', 'headers' => ['Evacuation center', 'Item', 'Quantity given', 'Households'], 'rows' => $byCenter],
    ],
    'notes' => ['Quantities are listed per item because different items use different units and cannot be added together.'],
]);
