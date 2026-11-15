<?php
// Lab 46: Disaster Response Performance Report
//
// How each number is worked out (these are the definitions to explain in the defense):
//  Affected residents   Active residents whose household barangay is the disaster's affected area.
//  Evacuation rate      affected residents who were ever checked in for that disaster / affected residents.
//  Households sheltered households that had someone checked in for that disaster.
//  Households served    sheltered households that received at least one released item for that disaster.
//  Relief rate          households served / households sheltered.
//  Unserved households  households sheltered - households served.
//  Response time        hours from the date reported to the FIRST check-in, and to the FIRST relief release.
//  Resource utilization units released / units received, over all resource transactions.
require_once 'config/db.php';
require_once 'includes/report_helpers.php';

$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$scope = $_GET['scope'] ?? '';

$where = []; $types = ''; $params = [];
if ($from !== '' && rptValidDate($from)) { $where[] = "DATE(d.date_reported) >= ?"; $types .= 's'; $params[] = $from; }
if ($to !== '' && rptValidDate($to)) { $where[] = "DATE(d.date_reported) <= ?"; $types .= 's'; $params[] = $to; }
if ($scope === 'active') $where[] = "COALESCE(d.status, '') <> 'Closed'";
if ($scope === 'closed') $where[] = "d.status = 'Closed'";

$rows = rptQuery($conn,
    "SELECT d.disaster_id, d.disaster_number, d.disaster_name, d.status, d.date_reported,
        (SELECT COUNT(*) FROM residents r JOIN households h ON h.household_id = r.household_id
          WHERE r.status = 'Active' AND TRIM(h.barangay) = TRIM(d.area_affected)) AS affected,
        (SELECT COUNT(DISTINCT e.resident_id) FROM evacuees e
           JOIN residents r ON r.resident_id = e.resident_id JOIN households h ON h.household_id = r.household_id
          WHERE e.disaster_id = d.disaster_id AND r.status = 'Active' AND TRIM(h.barangay) = TRIM(d.area_affected)) AS evacuated,
        (SELECT COUNT(DISTINCT e.household_id) FROM evacuees e
          WHERE e.disaster_id = d.disaster_id AND e.household_id IS NOT NULL) AS hh_sheltered,
        (SELECT COUNT(DISTINCT di.household_id) FROM distribution_items di
           JOIN relief_distributions rd ON rd.distribution_id = di.distribution_id
          WHERE rd.disaster_id = d.disaster_id AND di.date_given IS NOT NULL AND di.household_id IN
                (SELECT e2.household_id FROM evacuees e2 WHERE e2.disaster_id = d.disaster_id)) AS hh_served,
        (SELECT MIN(e.date_checked_in) FROM evacuees e WHERE e.disaster_id = d.disaster_id) AS first_checkin,
        (SELECT MIN(rd.release_date) FROM relief_distributions rd
          WHERE rd.disaster_id = d.disaster_id AND rd.status = 'Released') AS first_release
     FROM disasters d" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY d.date_reported DESC, d.disaster_id DESC", $types, $params);

$table = []; $sumAff = $sumEvac = $sumSheltered = $sumServed = 0; $hCheck = []; $hRelease = [];
foreach ($rows as $r) {
    $aff = (int) $r['affected']; $ev = (int) $r['evacuated']; $sh = (int) $r['hh_sheltered']; $sv = (int) $r['hh_served'];
    $unserved = max(0, $sh - $sv);
    $evRate = rptPct($ev, $aff); $reRate = rptPct($sv, $sh);
    $h1 = rptHours($r['date_reported'], $r['first_checkin']);
    $h2 = rptHours($r['date_reported'], $r['first_release']);
    if ($h1 !== null) $hCheck[] = $h1;
    if ($h2 !== null) $hRelease[] = $h2;
    $sumAff += $aff; $sumEvac += $ev; $sumSheltered += $sh; $sumServed += $sv;
    $table[] = [$r['disaster_number'] . ' - ' . $r['disaster_name'], $r['status'], $aff, $ev,
        $evRate === null ? '-' : rptFmt($evRate) . '%', $sh, $sv, $reRate === null ? '-' : rptFmt($reRate) . '%', $unserved,
        rptHoursText($h1), rptHoursText($h2)];
}

// Resource use is not tied to a disaster, so it is one number for the whole system.
$tx = rptQuery($conn,
    "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'Receive' THEN quantity END), 0) AS rec,
            COALESCE(SUM(CASE WHEN transaction_type = 'Release' THEN quantity END), 0) AS rel FROM resource_transactions")[0] ?? ['rec' => 0, 'rel' => 0];
$util = rptPct((int) $tx['rel'], (int) $tx['rec']);

$evAll = rptPct($sumEvac, $sumAff);
$reAll = rptPct($sumServed, $sumSheltered);
$avg = fn($a) => $a ? round(array_sum($a) / count($a), 1) : null;

rptPage([
    'title' => 'Disaster Response Performance Report',
    'intro' => 'How well each disaster was answered: who was affected, who was sheltered, who got relief, and how fast. Dates filter on the date reported.',
    'csv' => 'response_performance_report',
    'filters' => rptDates($from, $to, 'Reported from', 'Reported to')
        . rptSelect('scope', ['active' => 'Active only', 'closed' => 'Closed only'], $scope, 'Active and closed'),
    'kpis' => [
        ['Number of affected residents', $sumAff],
        ['Evacuation rate', $evAll === null ? '-' : rptFmt($evAll) . '%'],
        ['Resource utilization', $util === null ? '-' : rptFmt($util) . '%'],
        ['Relief distribution rate', $reAll === null ? '-' : rptFmt($reAll) . '%'],
        ['Unserved beneficiaries (households)', max(0, $sumSheltered - $sumServed)],
        ['Average response time to first check-in', rptHoursText($avg($hCheck))],
        ['Average response time to first relief', rptHoursText($avg($hRelease))],
    ],
    'sections' => [
        ['title' => 'Performance by disaster',
         'headers' => ['Disaster', 'Status', 'Affected residents', 'Evacuated', 'Evacuation rate', 'Households sheltered', 'Households served', 'Relief rate', 'Unserved households', 'Time to first check-in', 'Time to first relief'],
         'rows' => $table, 'note' => 'A "-" means there was nothing to divide by yet, so a rate cannot be worked out.'],
    ],
    'notes' => [
        'Evacuation rate = evacuated affected residents / affected residents. Relief rate = households served / households sheltered. Unserved = sheltered - served.',
        'Response time counts from the date the disaster was reported to the first check-in, and to the first released relief. Resource utilization = units released / units received, for all resources.',
        'Overall numbers add up all disasters shown. A resident in the area of two disasters counts in both.',
    ],
]);
