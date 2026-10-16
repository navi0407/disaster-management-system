<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/resource_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$resourceId = trim($_GET['resource_id'] ?? '');
$type = $_GET['type'] ?? '';
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$q = trim($_GET['q'] ?? '');

$where = []; $types = ''; $params = [];
if ($resourceId !== '' && ctype_digit($resourceId)) { $where[] = "t.resource_id = ?"; $types .= 'i'; $params[] = (int) $resourceId; }
if (in_array($type, ['Receive', 'Release'], true)) { $where[] = "t.transaction_type = ?"; $types .= 's'; $params[] = $type; }
if ($from !== '' && resValidDate($from)) { $where[] = "DATE(t.transaction_date) >= ?"; $types .= 's'; $params[] = $from; }
if ($to !== '' && resValidDate($to)) { $where[] = "DATE(t.transaction_date) <= ?"; $types .= 's'; $params[] = $to; }
if ($q !== '') {
    $where[] = "(t.reference_number LIKE ? OR t.remarks LIKE ? OR r.resource_name LIKE ?)";
    $types .= 'sss'; array_push($params, "%$q%", "%$q%", "%$q%");
}

$sql = "SELECT t.*, r.resource_code, r.resource_name, r.unit, u.username
        FROM resource_transactions t
        LEFT JOIN resources r ON t.resource_id = r.resource_id
        LEFT JOIN users u ON t.user_id = u.user_id"
     . ($where ? " WHERE " . implode(' AND ', $where) : '')
     . " ORDER BY t.transaction_date DESC, t.transaction_id DESC LIMIT 300";
$rows = resQuery($conn, $sql, $types, $params);
$resources = dbGetAll($conn, 'resources', 'resource_name');

$received = $released = 0;
foreach ($rows as $r) {
    if ($r['transaction_type'] === 'Receive') $received += (int) $r['quantity'];
    elseif ($r['transaction_type'] === 'Release') $released += (int) $r['quantity'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Resource Transactions</title>
    <link rel="stylesheet" href="<?= resEsc(resCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>
<main class="wide">
    <h1>Resource Transaction History</h1>
    <p><a href="resource_transaction.php">+ Receive or release stock</a> | <a href="resource_monitor.php">Back to inventory</a></p>

    <?php if (isset($_GET['done'])): ?><div class="success-box">Transaction saved.</div><?php endif; ?>

    <form method="get" class="filter-bar">
        <input type="text" name="q" placeholder="Search reference, remarks, resource..." value="<?= resEsc($q) ?>">
        <select name="resource_id">
            <option value="">All resources</option>
            <?php foreach ($resources as $r): ?>
                <option value="<?= (int) $r['resource_id'] ?>" <?= $resourceId === (string) $r['resource_id'] ? 'selected' : '' ?>><?= resEsc($r['resource_code'] . ' - ' . $r['resource_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="type">
            <option value="">All types</option>
            <?php foreach (['Receive', 'Release'] as $t): ?><option <?= $type === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= resEsc($from) ?>" title="From date">
        <input type="date" name="to" value="<?= resEsc($to) ?>" title="To date">
        <button type="submit">Filter</button>
        <a href="resource_transaction_monitor.php">Clear</a>
    </form>

    <p><strong><?= count($rows) ?></strong> transaction(s) shown |
       Received: <strong><?= $received ?></strong> | Released: <strong><?= $released ?></strong></p>

    <table class="data-table">
        <thead><tr><th>Date</th><th>Reference</th><th>Resource</th><th>Type</th><th>Quantity</th><th>Remarks</th><th>By</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7">No records found.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= resEsc($r['transaction_date']) ?></td>
                <td><?= resEsc($r['reference_number']) ?></td>
                <td><?= resEsc($r['resource_code'] . ' - ' . $r['resource_name']) ?></td>
                <td class="<?= $r['transaction_type'] === 'Receive' ? 'tx-receive' : 'tx-release' ?>"><?= resEsc($r['transaction_type']) ?></td>
                <td><?= (int) $r['quantity'] ?> <?= resEsc($r['unit']) ?></td>
                <td><?= resEsc($r['remarks']) ?></td>
                <td><?= resEsc($r['username']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
