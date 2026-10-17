<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/relief_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();

$itemId = trim($_GET['relief_item_id'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$q = trim($_GET['q'] ?? '');

$where = []; $types = ''; $params = [];
if ($itemId !== '' && ctype_digit($itemId)) { $where[] = "r.relief_item_id = ?"; $types .= 'i'; $params[] = (int) $itemId; }
if ($from !== '' && relValidDate($from)) { $where[] = "r.date_received >= ?"; $types .= 's'; $params[] = $from; }
if ($to !== '' && relValidDate($to)) { $where[] = "r.date_received <= ?"; $types .= 's'; $params[] = $to; }
if ($q !== '') {
    $where[] = "(r.receipt_number LIKE ? OR r.source LIKE ? OR i.item_name LIKE ?)";
    $types .= 'sss'; array_push($params, "%$q%", "%$q%", "%$q%");
}

$sql = "SELECT r.*, i.item_name FROM relief_receipts r
        LEFT JOIN relief_items i ON r.relief_item_id = i.relief_item_id"
     . ($where ? " WHERE " . implode(' AND ', $where) : '')
     . " ORDER BY r.date_received DESC, r.receipt_id DESC LIMIT 300";
$rows = relQuery($conn, $sql, $types, $params);
$items = dbGetAll($conn, 'relief_items', 'item_name');

$today = date('Y-m-d');
$limit = date('Y-m-d', strtotime('+30 days'));
$totalQty = 0;
foreach ($rows as $r) $totalQty += (int) $r['quantity'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Relief Receipts</title>
    <link rel="stylesheet" href="<?= relEsc(relCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>
<main class="wide">
    <h1>Relief Receipt History</h1>
    <p><a href="relief_receipt.php">+ Record a receipt</a> | <a href="relief_monitor.php">Back to relief inventory</a></p>

    <?php if (isset($_GET['done'])): ?><div class="success-box">Receipt <?= relEsc($_GET['done']) ?> saved.</div><?php endif; ?>

    <form method="get" class="filter-bar">
        <input type="text" name="q" placeholder="Search receipt, source, item..." value="<?= relEsc($q) ?>">
        <select name="relief_item_id">
            <option value="">All items</option>
            <?php foreach ($items as $i): ?>
                <option value="<?= (int) $i['relief_item_id'] ?>" <?= $itemId === (string) $i['relief_item_id'] ? 'selected' : '' ?>><?= relEsc($i['item_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= relEsc($from) ?>" title="From date">
        <input type="date" name="to" value="<?= relEsc($to) ?>" title="To date">
        <button type="submit">Filter</button>
        <a href="relief_receipt_monitor.php">Clear</a>
    </form>

    <p><strong><?= count($rows) ?></strong> receipt(s) shown | Total quantity: <strong><?= $totalQty ?></strong></p>

    <table class="data-table">
        <thead><tr><th>Receipt no.</th><th>Item</th><th>Source</th><th>Quantity</th><th>Date received</th><th>Expires</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="6">No records found.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r):
            $expClass = '';
            if ($r['expiration_date']) {
                if ($r['expiration_date'] < $today) $expClass = 'expiry-expired';
                elseif ($r['expiration_date'] <= $limit) $expClass = 'expiry-soon';
            }
        ?>
            <tr>
                <td><?= relEsc($r['receipt_number']) ?></td>
                <td><?= relEsc($r['item_name']) ?></td>
                <td><?= relEsc($r['source']) ?></td>
                <td><?= (int) $r['quantity'] ?> <?= relEsc($r['unit']) ?></td>
                <td><?= relEsc($r['date_received']) ?></td>
                <td class="<?= $expClass ?>"><?= relEsc($r['expiration_date']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
