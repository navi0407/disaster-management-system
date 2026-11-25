<?php
// Lab 43: Evacuation Center Report
require_once 'config/db.php';
require_once 'includes/report_helpers.php';

$level = $_GET['level'] ?? '';
$status = $_GET['status'] ?? '';
$levels = ['Available', 'Nearly Full', 'Full'];

// Same levels as the evacuation center monitor: under 70% Available, 70 to 99% Nearly Full, 100% Full.
function rptCenterLevel($pct)
{
    return $pct >= 100 ? 'Full' : ($pct >= 70 ? 'Nearly Full' : 'Available');
}

$rows = rptQuery(
    $conn,
    "SELECT c.center_id, c.center_name, c.barangay, c.status, c.capacity, c.current_occupancy,
            (SELECT COUNT(DISTINCT e.household_id) FROM evacuees e WHERE e.center_id = c.center_id AND e.status = 'Checked-in') AS households,
            (SELECT COUNT(*) FROM evacuees e JOIN residents r ON r.resident_id = e.resident_id
              WHERE e.center_id = c.center_id AND e.status = 'Checked-in' AND " . rptVulnSql('r') . ") AS vulnerable
     FROM evacuation_centers c" . ($status === 'Active' || $status === 'Inactive' ? " WHERE c.status = '" . $status . "'" : '') . " ORDER BY c.center_name"
);

$table = [];
$cap = 0;
$occ = 0;
$hh = 0;
$vul = 0;
$full = 0;
$count = 0;
foreach ($rows as $r) {
    $c = (int) $r['capacity'];
    $o = (int) $r['current_occupancy'];
    $pct = $c > 0 ? round($o / $c * 100, 1) : 0.0;
    $lv = rptCenterLevel($pct);
    if (in_array($level, $levels, true) && $lv !== $level) continue;
    $count++;
    $cap += $c;
    $occ += $o;
    $hh += (int) $r['households'];
    $vul += (int) $r['vulnerable'];
    if ($lv === 'Full') $full++;
    $table[] = [$r['center_name'], $r['barangay'], $r['status'], $c, $o, max(0, $c - $o), rptFmt($pct) . '%', $lv, (int) $r['households'], (int) $r['vulnerable']];
}
$overall = $cap > 0 ? round($occ / $cap * 100, 1) : null;
if ($table) $table[] = ['TOTAL', '', '', $cap, $occ, max(0, $cap - $occ), $overall === null ? '-' : rptFmt($overall) . '%', '', $hh, $vul];

rptPage([
    'title' => 'Evacuation Center Report',
    'intro' => 'Capacity and occupancy of every evacuation center right now.',
    'csv' => 'evacuation_center_report',
    'filters' => rptSelect('level', array_combine($levels, $levels), $level, 'All levels')
        . rptSelect('status', ['Active' => 'Active', 'Inactive' => 'Inactive'], $status, 'All statuses'),
    'kpis' => [
        ['Centers shown', $count],
        ['Total capacity', $cap],
        ['People sheltered', $occ],
        ['Free spaces', max(0, $cap - $occ)],
        ['Overall occupancy', $overall === null ? '-' : rptFmt($overall) . '%'],
        ['Centers full', $full],
    ],
    'sections' => [
        [
            'title' => 'Centers',
            'headers' => ['Center', 'Barangay', 'Status', 'Capacity', 'Occupancy', 'Available spaces', 'Occupancy %', 'Level', 'Households', 'Vulnerable evacuees'],
            'rows' => $table,
            'note' => 'Occupancy is the saved count the evacuation pages keep up to date. Households and vulnerable evacuees count people checked in now.'
        ],
    ],
]);
