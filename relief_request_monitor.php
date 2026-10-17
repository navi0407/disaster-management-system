<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/relief_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$q = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$disasterId = trim($_GET['disaster_id'] ?? '');
$centerId = trim($_GET['center_id'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$statuses = ['Requested', 'Approved', 'Rejected', 'Cancelled', 'Released'];

$where = []; $types = ''; $params = [];
if ($q !== '') {
    $where[] = "(d.reference_number LIKE ? OR c.center_name LIKE ? OR ds.disaster_name LIKE ? OR ds.disaster_number LIKE ?)";
    $types .= 'ssss'; array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if (in_array($status, $statuses, true)) { $where[] = "d.status = ?"; $types .= 's'; $params[] = $status; }
if ($disasterId !== '' && ctype_digit($disasterId)) { $where[] = "d.disaster_id = ?"; $types .= 'i'; $params[] = (int) $disasterId; }
if ($centerId !== '' && ctype_digit($centerId)) { $where[] = "d.center_id = ?"; $types .= 'i'; $params[] = (int) $centerId; }
if ($from !== '' && relValidDate($from)) { $where[] = "DATE(d.request_date) >= ?"; $types .= 's'; $params[] = $from; }
if ($to !== '' && relValidDate($to)) { $where[] = "DATE(d.request_date) <= ?"; $types .= 's'; $params[] = $to; }

$sql = "SELECT d.*, ds.disaster_number, ds.disaster_name, c.center_name, ur.username AS requester,
          (SELECT GROUP_CONCAT(CONCAT(ri.item_name, ' x', di.quantity, ' ', ri.unit) SEPARATOR ', ')
             FROM distribution_items di JOIN relief_items ri ON ri.relief_item_id = di.relief_item_id
             WHERE di.distribution_id = d.distribution_id) AS items_summary
        FROM relief_distributions d
        LEFT JOIN disasters ds ON d.disaster_id = ds.disaster_id
        LEFT JOIN evacuation_centers c ON d.center_id = c.center_id
        LEFT JOIN users ur ON d.requested_by = ur.user_id"
     . ($where ? " WHERE " . implode(' AND ', $where) : '')
     . " ORDER BY d.request_date DESC, d.distribution_id DESC LIMIT 300";
$rows = relQuery($conn, $sql, $types, $params);

$disasters = relQuery($conn, "SELECT disaster_id, disaster_number, disaster_name FROM disasters ORDER BY date_reported DESC");
$centers = dbGetAll($conn, 'evacuation_centers', 'center_name');

$counts = array_fill_keys($statuses, 0);
foreach ($rows as $r) if (isset($counts[$r['status']])) $counts[$r['status']]++;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Relief Distribution Requests</title>
    <link rel="stylesheet" href="<?= relEsc(relCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>
<main class="wide">
    <h1>Relief Distribution Requests</h1>
    <p><a href="relief_request.php">+ New request</a> | <a href="relief_monitor.php">Relief inventory</a></p>

    <?php if (isset($_GET['done'])): ?><div class="success-box">Request <?= relEsc($_GET['done']) ?> submitted. It now waits for approval.</div><?php endif; ?>

    <form method="get" class="filter-bar">
        <input type="text" name="q" placeholder="Search reference, disaster, center..." value="<?= relEsc($q) ?>">
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $s): ?><option <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
        </select>
        <select name="disaster_id">
            <option value="">All disasters</option>
            <?php foreach ($disasters as $d): ?>
                <option value="<?= (int) $d['disaster_id'] ?>" <?= $disasterId === (string) $d['disaster_id'] ? 'selected' : '' ?>><?= relEsc($d['disaster_number'] . ' - ' . $d['disaster_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="center_id">
            <option value="">All centers</option>
            <?php foreach ($centers as $c): ?>
                <option value="<?= (int) $c['center_id'] ?>" <?= $centerId === (string) $c['center_id'] ? 'selected' : '' ?>><?= relEsc($c['center_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= relEsc($from) ?>" title="From date">
        <input type="date" name="to" value="<?= relEsc($to) ?>" title="To date">
        <button type="submit">Filter</button>
        <a href="relief_request_monitor.php">Clear</a>
    </form>

    <p><strong><?= count($rows) ?></strong> request(s) |
       Waiting: <strong><?= $counts['Requested'] ?></strong> |
       Approved: <strong><?= $counts['Approved'] ?></strong> |
       Rejected: <strong><?= $counts['Rejected'] ?></strong> |
       Cancelled: <strong><?= $counts['Cancelled'] ?></strong> |
       Released: <strong><?= $counts['Released'] ?></strong></p>

    <table class="data-table">
        <thead><tr><th>Reference</th><th>Disaster</th><th>Center</th><th>Items</th><th>Requested by</th><th>Requested on</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="8">No records found.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= relEsc($r['reference_number']) ?></td>
                <td><?= relEsc($r['disaster_number']) ?><br><small><?= relEsc($r['disaster_name']) ?></small></td>
                <td><?= relEsc($r['center_name']) ?></td>
                <td><?= relEsc($r['items_summary']) ?></td>
                <td><?= relEsc($r['requester']) ?></td>
                <td><?= relEsc($r['request_date']) ?></td>
                <td class="status-<?= strtolower(relEsc($r['status'])) ?>"><?= relEsc($r['status']) ?></td>
                <td><a href="relief_request_view.php?id=<?= (int) $r['distribution_id'] ?>"><?= $r['status'] === 'Requested' ? 'Review' : 'View' ?></a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
