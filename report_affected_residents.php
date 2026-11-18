<?php
// Lab 42: Affected Residents Report
// A household is affected when its barangay is the same text as a disaster's area_affected.
// Residents are counted only when their status is Active.
require_once 'config/db.php';
require_once 'includes/report_helpers.php';

$disasterId = trim($_GET['disaster_id'] ?? '');
$disId = ($disasterId !== '' && ctype_digit($disasterId)) ? (int) $disasterId : 0;

$activeOnly = ($disasterId === 'active');   // "Active disasters only": every disaster that is not Closed
$hhRows = $disId
    ? rptQuery($conn, "SELECT DISTINCT h.household_id FROM households h JOIN disasters d ON TRIM(h.barangay) = TRIM(d.area_affected) WHERE d.disaster_id = ?", 'i', [$disId])
    : rptQuery($conn, "SELECT DISTINCT h.household_id FROM households h JOIN disasters d ON TRIM(h.barangay) = TRIM(d.area_affected)"
        . ($activeOnly ? " WHERE COALESCE(d.status, '') <> 'Closed'" : ""));
$hhIds = array_map('intval', array_column($hhRows, 'household_id'));

$totalRes = 0; $vulnTotal = 0; $byBarangay = []; $vulnRows = []; $centerRows = []; $sheltered = 0;
if ($hhIds) {
    [$ph, $ty, $pa] = rptIn($hhIds);
    $totalRes = (int) (rptQuery($conn, "SELECT COUNT(*) AS n FROM residents r WHERE r.status = 'Active' AND r.household_id IN ($ph)", $ty, $pa)[0]['n'] ?? 0);

    foreach (rptQuery($conn,
        "SELECT h.barangay, COUNT(r.resident_id) AS residents, COUNT(DISTINCT h.household_id) AS households
         FROM households h LEFT JOIN residents r ON r.household_id = h.household_id AND r.status = 'Active'
         WHERE h.household_id IN ($ph) GROUP BY h.barangay ORDER BY residents DESC, h.barangay", $ty, $pa) as $r)
        $byBarangay[] = [$r['barangay'] ?: '(no barangay)', (int) $r['households'], (int) $r['residents']];

    foreach (rptQuery($conn,
        "SELECT TRIM(r.vulnerability_type) AS vtype, COUNT(*) AS n FROM residents r
         WHERE r.status = 'Active' AND r.household_id IN ($ph) AND " . rptVulnSql('r') . "
         GROUP BY TRIM(r.vulnerability_type) ORDER BY n DESC", $ty, $pa) as $r) {
        $vulnRows[] = [$r['vtype'], (int) $r['n']];
        $vulnTotal += (int) $r['n'];
    }

    // people who are checked in at a center right now (for the chosen disaster, or any disaster)
    $cSql = "SELECT c.center_name, COUNT(DISTINCT e.resident_id) AS n
             FROM evacuees e JOIN residents r ON r.resident_id = e.resident_id
             JOIN evacuation_centers c ON c.center_id = e.center_id
             WHERE e.status = 'Checked-in' AND r.status = 'Active' AND r.household_id IN ($ph)"
          . ($disId ? " AND e.disaster_id = ?" : "") . " GROUP BY c.center_id, c.center_name ORDER BY n DESC";
    foreach (rptQuery($conn, $cSql, $ty . ($disId ? 'i' : ''), $disId ? array_merge($pa, [$disId]) : $pa) as $r) {
        $centerRows[] = [$r['center_name'], (int) $r['n']];
        $sheltered += (int) $r['n'];
    }
    $centerRows[] = ['Not in any center', max(0, $totalRes - $sheltered)];
}

$disasterOptions = [];
foreach (rptQuery($conn, "SELECT disaster_id, disaster_number, disaster_name FROM disasters ORDER BY date_reported DESC") as $d)
    $disasterOptions[$d['disaster_id']] = $d['disaster_number'] . ' - ' . $d['disaster_name'];

rptPage([
    'title' => 'Affected Residents Report',
    'intro' => $disId ? 'Residents living in the area of the chosen disaster.' : ($activeOnly ? 'Residents living in the area of any active disaster (not Closed). Each resident is counted once.' : 'Residents living in the area of any disaster. Each resident is counted once.'),
    'csv' => 'affected_residents_report',
    'filters' => rptSelect('disaster_id', ['active' => 'Active disasters only'] + $disasterOptions, $disasterId, 'All disasters'),
    'kpis' => [
        ['Total affected residents', $totalRes], ['Affected households', count($hhIds)],
        ['Vulnerable residents', $vulnTotal], ['Sheltered right now', $sheltered],
    ],
    'sections' => [
        ['title' => 'Residents by barangay', 'headers' => ['Barangay', 'Households', 'Residents'], 'rows' => $byBarangay],
        ['title' => 'Vulnerable residents', 'headers' => ['Vulnerability type', 'Residents'], 'rows' => $vulnRows,
         'note' => 'A resident is vulnerable when a vulnerability type is set (anything except empty or "None").'],
        ['title' => 'Residents by evacuation center', 'headers' => ['Evacuation center', 'Residents checked in'], 'rows' => $centerRows],
    ],
    'notes' => ['If these numbers differ from the Affected Residents page, check that disaster areas and household barangays are spelled exactly the same.'],
]);
