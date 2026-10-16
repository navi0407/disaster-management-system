<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/resource_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$currentUserId = resCurrentUserId();
$errors = [];

// ---- Toggle Active / Inactive ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    $row = dbGetById($conn, 'resources', 'resource_id', (int) $_POST['toggle_id']);
    if ($row) {
        $new = $row['status'] === 'Active' ? 'Inactive' : 'Active';
        dbUpdate($conn, 'resources', 'resource_id', (int) $row['resource_id'], ['status' => $new]);
        logAudit($conn, $currentUserId, 'Changed resource status', 'resources', $row['status'], $new, $row['resource_code']);
    }
    header('Location: resource_monitor.php?toggled=1'); exit;
}

// ---- Recount every resource from its transactions ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recount_all'])) {
    $fixed = 0;
    foreach (dbGetAll($conn, 'resources') as $r) {
        $new = recountQuantity($conn, (int) $r['resource_id']);
        if ((int) $r['quantity_on_hand'] !== $new) {
            $fixed++;
            logAudit($conn, $currentUserId, 'Recounted stock', 'resources', 'On hand: ' . $r['quantity_on_hand'], 'On hand: ' . $new, $r['resource_code']);
        }
    }
    header('Location: resource_monitor.php?recounted=' . $fixed); exit;
}

// ---- Filters ----
$q = trim($_GET['q'] ?? '');
$categoryId = trim($_GET['category_id'] ?? '');
$status = $_GET['status'] ?? '';
$stock = $_GET['stock'] ?? '';
$expiry = $_GET['expiry'] ?? '';

$where = []; $types = ''; $params = [];
if ($q !== '') {
    $where[] = "(r.resource_name LIKE ? OR r.resource_code LIKE ? OR r.storage_location LIKE ?)";
    $types .= 'sss'; array_push($params, "%$q%", "%$q%", "%$q%");
}
if ($categoryId !== '' && ctype_digit($categoryId)) { $where[] = "r.category_id = ?"; $types .= 'i'; $params[] = (int) $categoryId; }
if (in_array($status, ['Active', 'Inactive'], true)) { $where[] = "r.status = ?"; $types .= 's'; $params[] = $status; }
if ($stock === 'Out of Stock') $where[] = "r.quantity_on_hand <= 0";
elseif ($stock === 'Low Stock') $where[] = "r.quantity_on_hand > 0 AND r.quantity_on_hand <= COALESCE(r.min_stock_level, 0)";
elseif ($stock === 'In Stock') $where[] = "r.quantity_on_hand > COALESCE(r.min_stock_level, 0)";
if ($expiry === 'expiring') $where[] = "r.expiration_date >= CURDATE() AND r.expiration_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
elseif ($expiry === 'expired') $where[] = "r.expiration_date < CURDATE()";

$sql = "SELECT r.*, c.category_name FROM resources r
        LEFT JOIN resource_categories c ON r.category_id = c.category_id"
     . ($where ? " WHERE " . implode(' AND ', $where) : '') . " ORDER BY r.resource_name";
$rows = resQuery($conn, $sql, $types, $params);
$categories = dbGetAll($conn, 'resource_categories', 'category_name');

// Totals for the rows shown
$low = $out = $soon = 0;
$today = date('Y-m-d');
$limit = date('Y-m-d', strtotime('+30 days'));
foreach ($rows as $r) {
    $lvl = getStockLevel($r['quantity_on_hand'], $r['min_stock_level']);
    if ($lvl === 'Low Stock') $low++;
    if ($lvl === 'Out of Stock') $out++;
    if ($r['expiration_date'] && $r['expiration_date'] <= $limit) $soon++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Resource Inventory</title>
    <link rel="stylesheet" href="<?= resEsc(resCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>
<main class="wide">
    <h1>Resource Inventory</h1>
    <p>
        <a href="resource_register.php">+ Register resource</a> |
        <a href="resource_transaction.php">Receive or release stock</a> |
        <a href="resource_transaction_monitor.php">Transaction history</a> |
        <a href="resource_categories.php">Categories</a>
    </p>

    <?php if (isset($_GET['saved'])): ?><div class="success-box">Resource saved.</div><?php endif; ?>
    <?php if (isset($_GET['toggled'])): ?><div class="success-box">Status changed.</div><?php endif; ?>
    <?php if (isset($_GET['recounted'])): ?><div class="success-box">Recount done. <?= (int) $_GET['recounted'] ?> resource(s) were corrected.</div><?php endif; ?>

    <form method="get" class="filter-bar">
        <input type="text" name="q" placeholder="Search name, code, location..." value="<?= resEsc($q) ?>">
        <select name="category_id">
            <option value="">All categories</option>
            <?php foreach ($categories as $c): ?>
                <option value="<?= (int) $c['category_id'] ?>" <?= $categoryId === (string) $c['category_id'] ? 'selected' : '' ?>><?= resEsc($c['category_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach (['Active', 'Inactive'] as $s): ?><option <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
        </select>
        <select name="stock">
            <option value="">All stock levels</option>
            <?php foreach (['In Stock', 'Low Stock', 'Out of Stock'] as $s): ?><option <?= $stock === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
        </select>
        <select name="expiry">
            <option value="">Any expiry</option>
            <option value="expiring" <?= $expiry === 'expiring' ? 'selected' : '' ?>>Expiring in 30 days</option>
            <option value="expired" <?= $expiry === 'expired' ? 'selected' : '' ?>>Already expired</option>
        </select>
        <button type="submit">Filter</button>
        <a href="resource_monitor.php">Clear</a>
    </form>

    <p><strong><?= count($rows) ?></strong> resource(s) |
       Low stock: <strong><?= $low ?></strong> |
       Out of stock: <strong><?= $out ?></strong> |
       Expiring or expired: <strong><?= $soon ?></strong></p>

    <table class="data-table">
        <thead><tr>
            <th>Code</th><th>Resource</th><th>Category</th><th>On hand</th><th>Min</th><th>Stock level</th>
            <th>Expires</th><th>Location</th><th>Status</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="10">No records found.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r):
            $lvl = getStockLevel($r['quantity_on_hand'], $r['min_stock_level']);
            $expClass = '';
            if ($r['expiration_date']) {
                if ($r['expiration_date'] < $today) $expClass = 'expiry-expired';
                elseif ($r['expiration_date'] <= $limit) $expClass = 'expiry-soon';
            }
        ?>
            <tr>
                <td><?= resEsc($r['resource_code']) ?></td>
                <td><?= resEsc($r['resource_name']) ?></td>
                <td><?= resEsc($r['category_name']) ?></td>
                <td><?= (int) $r['quantity_on_hand'] ?> <?= resEsc($r['unit']) ?></td>
                <td><?= (int) $r['min_stock_level'] ?></td>
                <td class="<?= stockClass($lvl) ?>"><?= $lvl ?></td>
                <td class="<?= $expClass ?>"><?= resEsc($r['expiration_date']) ?></td>
                <td><?= resEsc($r['storage_location']) ?></td>
                <td class="status-<?= strtolower(resEsc($r['status'])) ?>"><?= resEsc($r['status']) ?></td>
                <td>
                    <a href="resource_register.php?id=<?= (int) $r['resource_id'] ?>">Edit</a> |
                    <a href="resource_transaction.php?resource_id=<?= (int) $r['resource_id'] ?>">Stock</a> |
                    <a href="resource_transaction_monitor.php?resource_id=<?= (int) $r['resource_id'] ?>">History</a>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="toggle_id" value="<?= (int) $r['resource_id'] ?>">
                        <button type="submit"><?= $r['status'] === 'Active' ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <form method="post" style="margin-top:16px" onsubmit="return confirm('Recount every resource from its transactions?');">
        <button type="submit" name="recount_all" value="1">Recount all stock from transactions</button>
    </form>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
