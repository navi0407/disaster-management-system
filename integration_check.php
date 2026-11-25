<?php
// WP13: Full Module Integration Pass.
// Read-only. It never changes data. Each check looks for rows that break a link or a rule
// between modules. A check passes when it finds 0 rows.
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/relief_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

// [stage, what is checked, SQL that returns the bad rows with a "detail" column, level]
// level "fail" = a real bug. level "warn" = worth a look, may be fine.
$checks = [
  // ---- 1. Disaster -> households -> residents
  [
    'Residents & households',
    'Residents whose household does not exist',
    "SELECT CONCAT('resident #', r.resident_id) AS detail FROM residents r
   LEFT JOIN households h ON h.household_id = r.household_id
   WHERE r.household_id IS NOT NULL AND h.household_id IS NULL",
    'fail'
  ],
  [
    'Residents & households',
    'Household heads that do not exist',
    "SELECT CONCAT('household #', h.household_id) AS detail FROM households h
   LEFT JOIN residents r ON r.resident_id = h.head_resident_id
   WHERE h.head_resident_id IS NOT NULL AND r.resident_id IS NULL",
    'fail'
  ],
  [
    'Residents & households',
    'Household heads who belong to a different household',
    "SELECT CONCAT('household #', h.household_id, ' head resident #', r.resident_id) AS detail FROM households h
   JOIN residents r ON r.resident_id = h.head_resident_id
   WHERE r.household_id IS NULL OR r.household_id <> h.household_id",
    'warn'
  ],
  [
    'Residents & households',
    'Disaster areas that match no household barangay (spelling must be the same)',
    "SELECT CONCAT(d.disaster_number, ' area: ', d.area_affected) AS detail FROM disasters d
   WHERE d.area_affected IS NOT NULL AND d.area_affected <> ''
     AND NOT EXISTS (SELECT 1 FROM households h WHERE h.barangay = d.area_affected)",
    'warn'
  ],

  // ---- 2. Evacuees and centers
  [
    'Evacuation',
    'Evacuees pointing to a missing disaster, resident or center',
    "SELECT CONCAT('evacuee #', e.evacuee_id) AS detail FROM evacuees e
   LEFT JOIN disasters d ON d.disaster_id = e.disaster_id
   LEFT JOIN residents r ON r.resident_id = e.resident_id
   LEFT JOIN evacuation_centers c ON c.center_id = e.center_id
   WHERE (e.disaster_id IS NOT NULL AND d.disaster_id IS NULL)
      OR (e.resident_id IS NOT NULL AND r.resident_id IS NULL)
      OR (e.center_id IS NOT NULL AND c.center_id IS NULL)",
    'fail'
  ],
  [
    'Evacuation',
    'Evacuee household differs from the resident\'s household',
    "SELECT CONCAT('evacuee #', e.evacuee_id) AS detail FROM evacuees e
   JOIN residents r ON r.resident_id = e.resident_id
   WHERE NOT (e.household_id <=> r.household_id)",
    'warn'
  ],
  [
    'Evacuation',
    'Centers whose current_occupancy does not match checked-in evacuees',
    "SELECT CONCAT(c.center_name, ' saved ', c.current_occupancy, ' but real ', x.n) AS detail
   FROM evacuation_centers c
   JOIN (SELECT c2.center_id, (SELECT COUNT(*) FROM evacuees e WHERE e.center_id = c2.center_id AND e.status = 'Checked-in') AS n
         FROM evacuation_centers c2) x ON x.center_id = c.center_id
   WHERE COALESCE(c.current_occupancy, 0) <> x.n",
    'fail'
  ],
  [
    'Evacuation',
    'Centers over capacity',
    "SELECT CONCAT(center_name, ' ', current_occupancy, '/', capacity) AS detail FROM evacuation_centers
   WHERE current_occupancy > capacity",
    'fail'
  ],
  [
    'Evacuation',
    'Residents checked in at more than one place',
    "SELECT CONCAT('resident #', resident_id, ' x', COUNT(*)) AS detail FROM evacuees
   WHERE status = 'Checked-in' GROUP BY resident_id HAVING COUNT(*) > 1",
    'fail'
  ],
  [
    'Evacuation',
    'People still checked in under a Closed disaster',
    "SELECT CONCAT('evacuee #', e.evacuee_id, ' in ', d.disaster_number) AS detail FROM evacuees e
   JOIN disasters d ON d.disaster_id = e.disaster_id
   WHERE e.status = 'Checked-in' AND d.status = 'Closed'",
    'warn'
  ],

  // ---- 3. Resources
  [
    'Resources',
    'Resources whose quantity_on_hand does not match their transactions',
    "SELECT CONCAT(r.resource_code, ' saved ', r.quantity_on_hand, ' but real ', COALESCE(t.total, 0)) AS detail
   FROM resources r
   LEFT JOIN (SELECT resource_id, SUM(CASE WHEN transaction_type = 'Receive' THEN quantity
                                           WHEN transaction_type = 'Release' THEN -quantity ELSE 0 END) AS total
              FROM resource_transactions GROUP BY resource_id) t ON t.resource_id = r.resource_id
   WHERE COALESCE(r.quantity_on_hand, 0) <> COALESCE(t.total, 0)",
    'fail'
  ],
  [
    'Resources',
    'Resources with negative stock',
    "SELECT CONCAT(resource_code, ' = ', quantity_on_hand) AS detail FROM resources WHERE quantity_on_hand < 0",
    'fail'
  ],
  [
    'Resources',
    'Transactions pointing to a missing resource',
    "SELECT CONCAT('transaction #', t.transaction_id) AS detail FROM resource_transactions t
   LEFT JOIN resources r ON r.resource_id = t.resource_id
   WHERE t.resource_id IS NOT NULL AND r.resource_id IS NULL",
    'fail'
  ],

  // ---- 4. Relief stock
  [
    'Relief',
    'Relief items with negative stock (received minus given out)',
    "SELECT CONCAT(i.item_name, ' = ', x.stock) AS detail FROM relief_items i
   JOIN (SELECT i2.relief_item_id,
           COALESCE((SELECT SUM(quantity) FROM relief_receipts WHERE relief_item_id = i2.relief_item_id), 0) -
           COALESCE((SELECT SUM(quantity) FROM distribution_items WHERE relief_item_id = i2.relief_item_id AND date_given IS NOT NULL), 0) AS stock
         FROM relief_items i2) x ON x.relief_item_id = i.relief_item_id
   WHERE x.stock < 0",
    'fail'
  ],
  [
    'Relief',
    'Approved requests promising more than is in stock',
    "SELECT CONCAT(i.item_name, ' stock ', x.stock, ' reserved ', x.reserved) AS detail FROM relief_items i
   JOIN (SELECT i2.relief_item_id,
           COALESCE((SELECT SUM(quantity) FROM relief_receipts WHERE relief_item_id = i2.relief_item_id), 0) -
           COALESCE((SELECT SUM(quantity) FROM distribution_items WHERE relief_item_id = i2.relief_item_id AND date_given IS NOT NULL), 0) AS stock,
           COALESCE((SELECT SUM(di.quantity) FROM distribution_items di JOIN relief_distributions d ON d.distribution_id = di.distribution_id
                     WHERE di.relief_item_id = i2.relief_item_id AND d.status = 'Approved'), 0) AS reserved
         FROM relief_items i2) x ON x.relief_item_id = i.relief_item_id
   WHERE x.reserved > x.stock",
    'fail'
  ],

  // ---- 5. Distribution flow
  [
    'Distribution',
    'Distribution lines pointing to a missing request or item',
    "SELECT CONCAT('line #', di.distribution_item_id) AS detail FROM distribution_items di
   LEFT JOIN relief_distributions d ON d.distribution_id = di.distribution_id
   LEFT JOIN relief_items i ON i.relief_item_id = di.relief_item_id
   WHERE (di.distribution_id IS NOT NULL AND d.distribution_id IS NULL)
      OR (di.relief_item_id IS NOT NULL AND i.relief_item_id IS NULL)",
    'fail'
  ],
  [
    'Distribution',
    'Approved requests with no approver or approval date',
    "SELECT reference_number AS detail FROM relief_distributions
   WHERE status = 'Approved' AND (approved_by IS NULL OR approval_date IS NULL)",
    'fail'
  ],
  [
    'Distribution',
    'Released requests with no release date',
    "SELECT reference_number AS detail FROM relief_distributions WHERE status = 'Released' AND release_date IS NULL",
    'fail'
  ],
  [
    'Distribution',
    'Released requests with no given lines, or lines with no household',
    "SELECT d.reference_number AS detail FROM relief_distributions d
   WHERE d.status = 'Released' AND (
     NOT EXISTS (SELECT 1 FROM distribution_items di WHERE di.distribution_id = d.distribution_id AND di.date_given IS NOT NULL)
     OR EXISTS (SELECT 1 FROM distribution_items di WHERE di.distribution_id = d.distribution_id AND di.household_id IS NULL))",
    'fail'
  ],
  [
    'Distribution',
    'Items marked as given on a request that is not Released',
    "SELECT DISTINCT d.reference_number AS detail FROM relief_distributions d
   JOIN distribution_items di ON di.distribution_id = d.distribution_id
   WHERE d.status <> 'Released' AND di.date_given IS NOT NULL",
    'fail'
  ],
  [
    'Distribution',
    'Open requests (Requested or Approved) under a Closed disaster',
    "SELECT d.reference_number AS detail FROM relief_distributions d
   JOIN disasters ds ON ds.disaster_id = d.disaster_id
   WHERE d.status IN ('Requested', 'Approved') AND ds.status = 'Closed'",
    'warn'
  ],
  [
    'Distribution',
    'Released requests with no audit log entry',
    "SELECT d.reference_number AS detail FROM relief_distributions d
   WHERE d.status = 'Released' AND NOT EXISTS (SELECT 1 FROM audit_logs a WHERE a.reference_number = d.reference_number
                                                AND a.activity = 'Released relief distribution')",
    'warn'
  ],

  // ---- 6. Users and audit
  [
    'Users & audit',
    'Audit log entries from a user that does not exist',
    "SELECT CONCAT('log #', a.log_id) AS detail FROM audit_logs a
   LEFT JOIN users u ON u.user_id = a.user_id
   WHERE a.user_id IS NOT NULL AND u.user_id IS NULL",
    'fail'
  ],
];

$results = [];
$failCount = 0;
$warnCount = 0;
foreach ($checks as [$stage, $title, $sql, $level]) {
  $row = ['stage' => $stage, 'title' => $title, 'level' => $level, 'count' => 0, 'sample' => [], 'error' => ''];
  try {
    $res = mysqli_query($conn, $sql);
    if ($res === false) throw new Exception(mysqli_error($conn));
    $found = mysqli_fetch_all($res, MYSQLI_ASSOC);
    $row['count'] = count($found);
    $row['sample'] = array_slice(array_column($found, 'detail'), 0, 5);
  } catch (Throwable $e) {
    $row['error'] = $e->getMessage();
  }
  if ($row['error'] || $row['count'] > 0) {
    if ($level === 'fail' || $row['error']) $failCount++;
    else $warnCount++;
  }
  $results[] = $row;
}

// How much data each module holds, so you can see the whole chain at a glance.
$tables = [
  'disasters',
  'households',
  'residents',
  'evacuation_centers',
  'evacuees',
  'resources',
  'resource_transactions',
  'relief_items',
  'relief_receipts',
  'relief_distributions',
  'distribution_items',
  'audit_logs'
];
$counts = [];
foreach ($tables as $t) {
  $r = mysqli_query($conn, "SELECT COUNT(*) AS n FROM $t");
  $counts[$t] = $r ? (int) mysqli_fetch_assoc($r)['n'] : null;
}
$statusRows = relQuery($conn, "SELECT status, COUNT(*) AS n FROM relief_distributions GROUP BY status ORDER BY status");
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>Integration Check</title>
  <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
  <?php include 'includes/header.php'; ?>
  <main class="wide">
    <h1>Integration Check</h1>
    <p>This page only reads data. It looks for broken links and broken rules between the modules.</p>

    <?php if ($failCount === 0): ?>
      <div class="success-box">All required checks passed<?= $warnCount ? ', with ' . $warnCount . ' warning(s) to look at' : '' ?>.</div>
    <?php else: ?>
      <div class="error-box"><?= $failCount ?> check(s) failed. Fix those first.</div>
    <?php endif; ?>

    <table class="data-table">
      <thead>
        <tr>
          <th>Stage</th>
          <th>Check</th>
          <th>Result</th>
          <th>Found</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($results as $r):
          if ($r['error']) {
            $label = 'Error';
            $cls = 'check-fail';
          } elseif ($r['count'] === 0) {
            $label = 'Pass';
            $cls = 'check-pass';
          } elseif ($r['level'] === 'warn') {
            $label = 'Warning';
            $cls = 'check-warn';
          } else {
            $label = 'Fail';
            $cls = 'check-fail';
          } ?>
          <tr>
            <td><?= relEsc($r['stage']) ?></td>
            <td><?= relEsc($r['title']) ?></td>
            <td class="<?= $cls ?>"><?= $label ?></td>
            <td><?= $r['error'] ? relEsc($r['error']) : ($r['count'] ? (int) $r['count'] . ': ' . relEsc(implode('; ', $r['sample'])) . ($r['count'] > 5 ? ' ...' : '') : '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <h2>Records in each table</h2>
    <table class="data-table">
      <thead>
        <tr>
          <th>Table</th>
          <th>Rows</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($counts as $t => $n): ?><tr>
            <td><?= relEsc($t) ?></td>
            <td><?= $n === null ? 'missing' : $n ?></td>
          </tr><?php endforeach; ?>
      </tbody>
    </table>

    <h2>Relief requests by status</h2>
    <p><?php foreach ($statusRows as $s): ?><?= relEsc($s['status']) ?>: <strong><?= (int) $s['n'] ?></strong> &nbsp; <?php endforeach; ?><?= $statusRows ? '' : 'None yet.' ?></p>
  </main>
  <?php include 'includes/footer.php'; ?>
</body>

</html>