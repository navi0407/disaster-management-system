<?php
// Lab 41: Disaster Report
require_once 'config/db.php';
require_once 'includes/report_helpers.php';

$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$typeId = trim($_GET['type_id'] ?? '');
$scope = $_GET['scope'] ?? '';

$where = [];
$types = '';
$params = [];
if ($from !== '' && rptValidDate($from)) {
    $where[] = "DATE(d.date_reported) >= ?";
    $types .= 's';
    $params[] = $from;
}
if ($to !== '' && rptValidDate($to)) {
    $where[] = "DATE(d.date_reported) <= ?";
    $types .= 's';
    $params[] = $to;
}
if ($typeId !== '' && ctype_digit($typeId)) {
    $where[] = "d.disaster_type_id = ?";
    $types .= 'i';
    $params[] = (int) $typeId;
}
if ($scope === 'active') $where[] = "COALESCE(d.status, '') <> 'Closed'";
if ($scope === 'closed') $where[] = "d.status = 'Closed'";

// Duration: from the start (or the report date) to the end. A disaster that is still open counts up to today.
$rows = rptQuery(
    $conn,
    "SELECT d.disaster_id, d.disaster_number, d.disaster_name, dt.type_name, d.area_affected, d.severity, d.status,
            d.date_started, d.date_ended, d.date_reported, d.date_closed,
            CASE WHEN COALESCE(d.date_started, d.date_reported) IS NULL THEN NULL
                 ELSE GREATEST(0, DATEDIFF(COALESCE(d.date_closed, d.date_ended, NOW()), COALESCE(d.date_started, d.date_reported))) END AS days
     FROM disasters d LEFT JOIN disaster_types dt ON dt.disaster_type_id = d.disaster_type_id"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
        . " ORDER BY d.date_reported DESC, d.disaster_id DESC",
    $types,
    $params
);

$active = array_values(array_filter($rows, fn($r) => $r['status'] !== 'Closed'));
$closed = array_values(array_filter($rows, fn($r) => $r['status'] === 'Closed'));

$listRow = fn($r) => [$r['disaster_number'], $r['disaster_name'], $r['type_name'], $r['severity'], $r['status'], $r['area_affected'], rptDay($r['date_started'] ?: $r['date_reported'])];
$closedRow = fn($r) => [$r['disaster_number'], $r['disaster_name'], $r['type_name'], $r['severity'], $r['area_affected'], rptDay($r['date_started'] ?: $r['date_reported']), rptDay($r['date_closed'] ?: $r['date_ended']), rptFmt((int) $r['days'])];

$countRows = function ($by) {
    $out = [];
    foreach ($by as $label => [$t, $a, $c]) $out[] = [$label, $t, $a, $c];
    return $out;
};

$closedDays = array_map(fn($r) => (int) $r['days'], array_filter($closed, fn($r) => $r['days'] !== null));
$avgClosed = $closedDays ? round(array_sum($closedDays) / count($closedDays), 1) : null;
$longest = null;
foreach ($rows as $r) if ($r['days'] !== null && ($longest === null || (int) $r['days'] > (int) $longest['days'])) $longest = $r;

$durationRows = [];
foreach ($rows as $r) $durationRows[] = [
    $r['disaster_number'],
    $r['disaster_name'],
    $r['status'],
    rptDay($r['date_started'] ?: $r['date_reported']),
    $r['status'] === 'Closed' ? rptDay($r['date_closed'] ?: $r['date_ended']) : 'Still open',
    $r['days'] === null ? '-' : (int) $r['days']
];

$typeOptions = [];
foreach (rptQuery($conn, "SELECT disaster_type_id, type_name FROM disaster_types ORDER BY type_name") as $t) $typeOptions[$t['disaster_type_id']] = $t['type_name'];

rptPage([
    'title' => 'Disaster Report',
    'intro' => 'Disasters by status, type, severity and area, and how long they lasted. Dates filter on the date reported.',
    'csv' => 'disaster_report',
    'filters' => rptDates($from, $to, 'Reported from', 'Reported to')
        . rptSelect('type_id', $typeOptions, $typeId, 'All types')
        . rptSelect('scope', ['active' => 'Active only', 'closed' => 'Closed only'], $scope, 'Active and closed'),
    'kpis' => [
        ['Disasters', count($rows)],
        ['Active (not closed)', count($active)],
        ['Closed', count($closed)],
        ['Average duration of closed disasters', $avgClosed === null ? '-' : rptFmt($avgClosed) . ' days'],
        ['Longest disaster', $longest ? $longest['disaster_number'] . ' (' . (int) $longest['days'] . ' days)' : '-'],
    ],
    'sections' => [
        [
            'title' => 'Active disasters',
            'headers' => ['Number', 'Name', 'Type', 'Severity', 'Status', 'Area affected', 'Started'],
            'rows' => array_map($listRow, $active),
            'note' => 'Active means any status except Closed (Reported, Monitoring, Active, Response, Recovery).'
        ],
        [
            'title' => 'Closed disasters',
            'headers' => ['Number', 'Name', 'Type', 'Severity', 'Area affected', 'Started', 'Closed', 'Days'],
            'rows' => array_map($closedRow, $closed)
        ],
        ['title' => 'Disaster types', 'headers' => ['Type', 'Disasters', 'Active', 'Closed'], 'rows' => $countRows(rptCountBy($rows, 'type_name'))],
        ['title' => 'Disaster severity', 'headers' => ['Severity', 'Disasters', 'Active', 'Closed'], 'rows' => $countRows(rptCountBy($rows, 'severity'))],
        ['title' => 'Affected areas', 'headers' => ['Area', 'Disasters', 'Active', 'Closed'], 'rows' => $countRows(rptCountBy($rows, 'area_affected'))],
        [
            'title' => 'Disaster duration',
            'headers' => ['Number', 'Name', 'Status', 'Started', 'Ended', 'Days'],
            'rows' => $durationRows,
            'note' => 'Days are counted from the start date (or the report date if there is no start date) to the end date. Open disasters count up to today.'
        ],
    ],
]);
