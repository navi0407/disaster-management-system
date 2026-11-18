<?php
// Lab 30: distribution monitoring. One row per household line that was actually given out.
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/relief_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$q = trim($_GET['q'] ?? '');
$disasterId = trim($_GET['disaster_id'] ?? '');
$centerId = trim($_GET['center_id'] ?? '');
$itemId = trim($_GET['item_id'] ?? '');
$ack = $_GET['ack'] ?? '';
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');

$where = ["di.date_given IS NOT NULL"]; $types = ''; $params = [];
if ($q !== '') {
    $where[] = "(d.reference_number LIKE ? OR h.barangay LIKE ? OR r.first_name LIKE ? OR r.last_name LIKE ?)";
    $types .= 'ssss'; array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($disasterId !== '' && ctype_digit($disasterId)) { $where[] = "d.disaster_id = ?"; $types .= 'i'; $params[] = (int) $disasterId; }
if ($centerId !== '' && ctype_digit($centerId)) { $where[] = "d.center_id = ?"; $types .= 'i'; $params[] = (int) $centerId; }
if ($itemId !== '' && ctype_digit($itemId)) { $where[] = "di.relief_item_id = ?"; $types .= 'i'; $params[] = (int) $itemId; }
if ($ack === 'yes') $where[] = "di.signature_on_file = 1";
if ($ack === 'no') $where[] = "di.signature_on_file = 0";
if ($from !== '' && relValidDate($from)) { $where[] = "DATE(di.date_given) >= ?"; $types .= 's'; $params[] = $from; }
if ($to !== '' && relValidDate($to)) { $where[] = "DATE(di.date_given) <= ?"; $types .= 's'; $params[] = $to; }
$whereSql = " WHERE " . implode(' AND ', $where);

$base = " FROM distribution_items di
          JOIN relief_distributions d ON d.distribution_id = di.distribution_id
          JOIN relief_items ri ON ri.relief_item_id = di.relief_item_id
          LEFT JOIN disasters ds ON ds.disaster_id = d.disaster_id
          LEFT JOIN evacuation_centers c ON c.center_id = d.center_id
          LEFT JOIN households h ON h.household_id = di.household_id
          LEFT JOIN residents r ON r.resident_id = di.resident_id
          LEFT JOIN users u ON u.user_id = di.distributed_by";

$rows = relQuery($conn,
    "SELECT di.*, d.reference_number, d.distribution_id AS dist_id, ds.disaster_number, c.center_name, ri.item_name, ri.unit,
            h.barangay, CONCAT(r.first_name, ' ', r.last_name) AS head_name, u.username AS giver"
    . $base . $whereSql . " ORDER BY di.date_given DESC, di.distribution_item_id DESC LIMIT 300", $types, $params);

// the totals use the same filters, so they always match the table
$tot = relQuery($conn,
    "SELECT COUNT(*) AS line_count, COUNT(DISTINCT di.household_id) AS households,
            COALESCE(SUM(di.signature_on_file = 0), 0) AS unsigned_lines" . $base . $whereSql, $types, $params)[0];
$perItem = relQuery($conn,
    "SELECT ri.item_name, ri.unit, SUM(di.quantity) AS total" . $base . $whereSql . " GROUP BY ri.relief_item_id, ri.item_name, ri.unit ORDER BY ri.item_name", $types, $params);

$disasters = relQuery($conn, "SELECT disaster_id, disaster_number, disaster_name FROM disasters ORDER BY date_reported DESC");
$centers = dbGetAll($conn, 'evacuation_centers', 'center_name');
$itemList = dbGetAll($conn, 'relief_items', 'item_name');
$ready = relQuery($conn, "SELECT COUNT(*) AS n FROM relief_distributions WHERE status = 'Approved'")[0]['n'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Relief Distribution Monitor</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>
<main class="wide">
    <h1>Relief Distribution Monitor</h1>
    <p><a href="relief_request_monitor.php">Requests</a> | <a href="relief_monitor.php">Relief inventory</a></p>

    <?php if (isset($_GET['released'])): ?><div class="success-box"><?= relEsc($_GET['released']) ?> released. Stock went down by the items given out.</div><?php endif; ?>
    <?php if ($ready > 0): ?><div class="success-box"><?= (int) $ready ?> approved request(s) are waiting to be released. <a href="relief_request_monitor.php?status=Approved">See them</a></div><?php endif; ?>

    <form method="get" class="filter-bar">
        <input type="text" name="q" placeholder="Search reference, barangay, head..." value="<?= relEsc($q) ?>">
        <select name="disaster_id"><option value="">All disasters</option>
            <?php foreach ($disasters as $d): ?><option value="<?= (int) $d['disaster_id'] ?>" <?= $disasterId === (string) $d['disaster_id'] ? 'selected' : '' ?>><?= relEsc($d['disaster_number'] . ' - ' . $d['disaster_name']) ?></option><?php endforeach; ?>
        </select>
        <select name="center_id"><option value="">All centers</option>
            <?php foreach ($centers as $c): ?><option value="<?= (int) $c['center_id'] ?>" <?= $centerId === (string) $c['center_id'] ? 'selected' : '' ?>><?= relEsc($c['center_name']) ?></option><?php endforeach; ?>
        </select>
        <select name="item_id"><option value="">All items</option>
            <?php foreach ($itemList as $i): ?><option value="<?= (int) $i['relief_item_id'] ?>" <?= $itemId === (string) $i['relief_item_id'] ? 'selected' : '' ?>><?= relEsc($i['item_name']) ?></option><?php endforeach; ?>
        </select>
        <select name="ack">
            <option value="">Signed or not</option>
            <option value="yes" <?= $ack === 'yes' ? 'selected' : '' ?>>Signed</option>
            <option value="no" <?= $ack === 'no' ? 'selected' : '' ?>>Not signed</option>
        </select>
        <input type="date" name="from" value="<?= relEsc($from) ?>" title="Given from">
        <input type="date" name="to" value="<?= relEsc($to) ?>" title="Given to">
        <button type="submit">Filter</button>
        <a href="relief_distribution_monitor.php">Clear</a>
    </form>

    <p><strong><?= (int) $tot['line_count'] ?></strong> line(s) |
       Households served: <strong><?= (int) $tot['households'] ?></strong> |
       Not yet signed: <strong><?= (int) $tot['unsigned_lines'] ?></strong></p>

    <?php if ($perItem): ?>
    <p>Totals given out:
        <?php foreach ($perItem as $p): ?><strong><?= (int) $p['total'] ?></strong> <?= relEsc($p['unit']) ?> of <?= relEsc($p['item_name']) ?>; <?php endforeach; ?>
    </p>
    <?php endif; ?>

    <table class="data-table">
        <thead><tr><th>Reference</th><th>Disaster</th><th>Center</th><th>Household</th><th>Head</th><th>Item</th><th>Qty</th><th>Given on</th><th>Given by</th><th>Signed</th><th>Action</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="11">No records found.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= relEsc($r['reference_number']) ?></td>
                <td><?= relEsc($r['disaster_number']) ?></td>
                <td><?= relEsc($r['center_name']) ?></td>
                <td>#<?= (int) $r['household_id'] ?> <?= relEsc($r['barangay']) ?></td>
                <td><?= relEsc($r['head_name']) ?></td>
                <td><?= relEsc($r['item_name']) ?></td>
                <td><?= (int) $r['quantity'] ?> <?= relEsc($r['unit']) ?></td>
                <td><?= relEsc($r['date_given']) ?></td>
                <td><?= relEsc($r['giver']) ?></td>
                <td class="<?= $r['signature_on_file'] ? 'ack-yes' : 'ack-no' ?>"><?= $r['signature_on_file'] ? 'Yes' : 'Not yet' ?></td>
                <td><a href="relief_acknowledge.php?id=<?= (int) $r['dist_id'] ?>"><?= $r['signature_on_file'] ? 'View' : 'Acknowledge' ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
