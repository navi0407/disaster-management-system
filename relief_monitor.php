<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/relief_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$currentUserId = relCurrentUserId();
$errors = [];

// ---- Delete (only when the item has no receipts and was never given out) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = (int) $_POST['delete_id'];
    $row = dbGetById($conn, 'relief_items', 'relief_item_id', $id);
    $r1 = relQuery($conn, "SELECT COUNT(*) AS total FROM relief_receipts WHERE relief_item_id = ?", 'i', [$id]);
    $r2 = relQuery($conn, "SELECT COUNT(*) AS total FROM distribution_items WHERE relief_item_id = ?", 'i', [$id]);
    if (!$row) {
        $errors[] = 'That relief item does not exist.';
    } elseif ((int) $r1[0]['total'] > 0 || (int) $r2[0]['total'] > 0) {
        $errors[] = 'That item already has receipts or distributions, so it cannot be deleted.';
    } else {
        dbDelete($conn, 'relief_items', 'relief_item_id', $id);
        logAudit($conn, $currentUserId, 'Deleted relief item', 'relief_items', $row['item_name'], null, 'RLI-' . $id);
        header('Location: relief_monitor.php?deleted=1'); exit;
    }
}

// ---- Filters ----
$q = trim($_GET['q'] ?? '');
$stockFilter = $_GET['stock'] ?? '';
$expiry = $_GET['expiry'] ?? '';

$sql = "SELECT i.*,
          COALESCE((SELECT SUM(quantity) FROM relief_receipts WHERE relief_item_id = i.relief_item_id), 0) AS received,
          COALESCE((SELECT SUM(quantity) FROM distribution_items WHERE relief_item_id = i.relief_item_id), 0) AS distributed,
          (SELECT MIN(expiration_date) FROM relief_receipts
             WHERE relief_item_id = i.relief_item_id AND expiration_date >= CURDATE()) AS next_expiry,
          (SELECT COUNT(*) FROM relief_receipts
             WHERE relief_item_id = i.relief_item_id AND expiration_date < CURDATE()) AS expired_batches
        FROM relief_items i
        WHERE (i.item_name LIKE ? OR i.description LIKE ?)
        ORDER BY i.item_name";
$all = relQuery($conn, $sql, 'ss', ["%$q%", "%$q%"]);

$limit = date('Y-m-d', strtotime('+30 days'));
$rows = [];
$low = $out = $soon = 0;
foreach ($all as $r) {
    $r['stock'] = (int) $r['received'] - (int) $r['distributed'];
    $r['level'] = relStockLevel($r['stock'], $r['min_stock_level']);
    $isSoon = $r['next_expiry'] && $r['next_expiry'] <= $limit;
    $isExpired = (int) $r['expired_batches'] > 0;

    if (in_array($stockFilter, ['In Stock', 'Low Stock', 'Out of Stock'], true) && $r['level'] !== $stockFilter) continue;
    if ($expiry === 'expiring' && !$isSoon) continue;
    if ($expiry === 'expired' && !$isExpired) continue;

    if ($r['level'] === 'Low Stock') $low++;
    if ($r['level'] === 'Out of Stock') $out++;
    if ($isSoon || $isExpired) $soon++;
    $r['is_soon'] = $isSoon;
    $r['is_expired'] = $isExpired;
    $rows[] = $r;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Relief Inventory</title>
    <link rel="stylesheet" href="<?= relEsc(relCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>
<main class="wide">
    <h1>Relief Inventory</h1>
    <p>
        <a href="relief_item_register.php">+ Register relief item</a> |
        <a href="relief_receipt.php">Record a receipt</a> |
        <a href="relief_receipt_monitor.php">Receipt history</a>
    </p>

    <?php if (isset($_GET['saved'])): ?><div class="success-box">Relief item saved.</div><?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?><div class="success-box">Relief item deleted.</div><?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="error-box"><?= relEsc($err) ?></div><?php endforeach; ?>

    <form method="get" class="filter-bar">
        <input type="text" name="q" placeholder="Search item or description..." value="<?= relEsc($q) ?>">
        <select name="stock">
            <option value="">All stock levels</option>
            <?php foreach (['In Stock', 'Low Stock', 'Out of Stock'] as $s): ?><option <?= $stockFilter === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
        </select>
        <select name="expiry">
            <option value="">Any expiry</option>
            <option value="expiring" <?= $expiry === 'expiring' ? 'selected' : '' ?>>Batch expiring in 30 days</option>
            <option value="expired" <?= $expiry === 'expired' ? 'selected' : '' ?>>Has an expired batch</option>
        </select>
        <button type="submit">Filter</button>
        <a href="relief_monitor.php">Clear</a>
    </form>

    <p><strong><?= count($rows) ?></strong> item(s) |
       Low stock: <strong><?= $low ?></strong> |
       Out of stock: <strong><?= $out ?></strong> |
       Expiring or expired: <strong><?= $soon ?></strong></p>

    <table class="data-table">
        <thead><tr>
            <th>Item</th><th>Unit</th><th>Received</th><th>Given out</th><th>In stock</th><th>Min</th>
            <th>Stock level</th><th>Next expiry</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="9">No records found.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= relEsc($r['item_name']) ?>
                    <?php if ($r['description']): ?><br><small><?= relEsc($r['description']) ?></small><?php endif; ?></td>
                <td><?= relEsc($r['unit']) ?></td>
                <td><?= (int) $r['received'] ?></td>
                <td><?= (int) $r['distributed'] ?></td>
                <td><strong><?= (int) $r['stock'] ?></strong></td>
                <td><?= (int) $r['min_stock_level'] ?></td>
                <td class="<?= relStockClass($r['level']) ?>"><?= $r['level'] ?></td>
                <td class="<?= $r['is_soon'] ? 'expiry-soon' : '' ?>">
                    <?= relEsc($r['next_expiry']) ?>
                    <?php if ($r['is_expired']): ?><span class="expiry-expired"><?= (int) $r['expired_batches'] ?> expired</span><?php endif; ?>
                </td>
                <td>
                    <a href="relief_item_register.php?id=<?= (int) $r['relief_item_id'] ?>">Edit</a> |
                    <a href="relief_receipt.php?relief_item_id=<?= (int) $r['relief_item_id'] ?>">Receive</a> |
                    <a href="relief_receipt_monitor.php?relief_item_id=<?= (int) $r['relief_item_id'] ?>">History</a>
                    <?php if ((int) $r['received'] === 0 && (int) $r['distributed'] === 0): ?>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this relief item?');">
                        <input type="hidden" name="delete_id" value="<?= (int) $r['relief_item_id'] ?>">
                        <button type="submit">Delete</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
