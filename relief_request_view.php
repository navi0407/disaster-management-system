<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/relief_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$currentUserId = relCurrentUserId();

$id = (int) ($_GET['id'] ?? $_POST['distribution_id'] ?? 0);
$errors = [];

// Short text of the lines, used in the audit log
function relLinesText($conn, $distId) {
    $rows = relQuery($conn, "SELECT ri.item_name, ri.unit, di.quantity FROM distribution_items di
                             JOIN relief_items ri ON ri.relief_item_id = di.relief_item_id
                             WHERE di.distribution_id = ? ORDER BY ri.item_name", 'i', [$distId]);
    return implode(', ', array_map(fn($r) => $r['item_name'] . ' x' . $r['quantity'] . ' ' . $r['unit'], $rows));
}

// ---- Approve, reject or cancel ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id > 0 && isset($_POST['action'])) {
    $action = $_POST['action'];
    $reason = trim($_POST['reason'] ?? '');
    if (!in_array($action, ['approve', 'reject', 'cancel'], true)) $errors[] = 'Unknown action.';
    if ($action === 'reject' && $reason === '') $errors[] = 'Please write a reason for rejecting this request.';
    if (mb_strlen($reason) > 200) $errors[] = 'The reason must be 200 characters or less.';

    if (!$errors) {
        try {
            mysqli_begin_transaction($conn);
            // lock the request so two people can't decide on it at the same time
            $rows = relQuery($conn, "SELECT * FROM relief_distributions WHERE distribution_id = ? FOR UPDATE", 'i', [$id]);
            $dist = $rows[0] ?? null;

            if (!$dist) {
                $errors[] = 'That request does not exist.';
            } elseif ($dist['status'] !== 'Requested') {
                $errors[] = 'This request is already ' . strtolower($dist['status']) . ', so it cannot be changed.';
            } elseif ($action === 'approve') {
                $disaster = dbGetById($conn, 'disasters', 'disaster_id', (int) $dist['disaster_id']);
                $center = dbGetById($conn, 'evacuation_centers', 'center_id', (int) $dist['center_id']);
                if (!$disaster || $disaster['status'] === 'Closed') $errors[] = 'The disaster is closed or does not exist.';
                if (!$center || $center['status'] !== 'Active') $errors[] = 'The evacuation center is not active.';

                $lines = relQuery($conn, "SELECT di.relief_item_id, di.quantity, ri.item_name, ri.unit
                                          FROM distribution_items di JOIN relief_items ri ON ri.relief_item_id = di.relief_item_id
                                          WHERE di.distribution_id = ? ORDER BY di.relief_item_id", 'i', [$id]);
                if (!$lines) $errors[] = 'This request has no items.';

                // lock each item (same order every time) so two approvals can't promise the same stock
                foreach ($lines as $ln) relQuery($conn, "SELECT relief_item_id FROM relief_items WHERE relief_item_id = ? FOR UPDATE", 'i', [(int) $ln['relief_item_id']]);
                foreach ($lines as $ln) {
                    $avail = relAvailable($conn, (int) $ln['relief_item_id'], $id);
                    if ((int) $ln['quantity'] > $avail)
                        $errors[] = 'Not enough ' . $ln['item_name'] . ': needs ' . $ln['quantity'] . ' ' . $ln['unit'] . ', only ' . max($avail, 0) . ' available.';
                }

                if (!$errors) {
                    dbUpdate($conn, 'relief_distributions', 'distribution_id', $id, [
                        'status' => 'Approved', 'approved_by' => $currentUserId, 'approval_date' => date('Y-m-d H:i:s')]);
                    logAudit($conn, $currentUserId, 'Approved relief distribution', 'relief_distributions', 'Requested', 'Approved: ' . relLinesText($conn, $id), $dist['reference_number']);
                    mysqli_commit($conn);
                    header('Location: relief_request_view.php?id=' . $id . '&done=approved'); exit;
                }
            } elseif ($action === 'reject') {
                dbUpdate($conn, 'relief_distributions', 'distribution_id', $id, ['status' => 'Rejected']);
                logAudit($conn, $currentUserId, 'Rejected relief distribution', 'relief_distributions', 'Requested', 'Rejected. Reason: ' . $reason, $dist['reference_number']);
                mysqli_commit($conn);
                header('Location: relief_request_view.php?id=' . $id . '&done=rejected'); exit;
            } else { // cancel
                dbUpdate($conn, 'relief_distributions', 'distribution_id', $id, ['status' => 'Cancelled']);
                logAudit($conn, $currentUserId, 'Cancelled relief distribution', 'relief_distributions', 'Requested', 'Cancelled', $dist['reference_number']);
                mysqli_commit($conn);
                header('Location: relief_request_view.php?id=' . $id . '&done=cancelled'); exit;
            }
            mysqli_rollback($conn);
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $errors[] = 'Something went wrong. Nothing was changed.';
        }
    }
}

// ---- Load the request for display ----
$dist = null; $lines = [];
if ($id > 0) {
    $rows = relQuery($conn,
        "SELECT d.*, ds.disaster_number, ds.disaster_name, ds.status AS disaster_status,
                c.center_name, c.status AS center_status, ur.username AS requester, ua.username AS approver
         FROM relief_distributions d
         LEFT JOIN disasters ds ON d.disaster_id = ds.disaster_id
         LEFT JOIN evacuation_centers c ON d.center_id = c.center_id
         LEFT JOIN users ur ON d.requested_by = ur.user_id
         LEFT JOIN users ua ON d.approved_by = ua.user_id
         WHERE d.distribution_id = ?", 'i', [$id]);
    $dist = $rows[0] ?? null;
    if ($dist) {
        $lines = relQuery($conn, "SELECT di.*, ri.item_name, ri.unit FROM distribution_items di
                                  JOIN relief_items ri ON ri.relief_item_id = di.relief_item_id
                                  WHERE di.distribution_id = ? ORDER BY ri.item_name", 'i', [$id]);
    }
}
$doneMessages = ['approved' => 'Request approved. The items are now reserved.', 'rejected' => 'Request rejected.', 'cancelled' => 'Request cancelled.'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Review Relief Request</title>
    <link rel="stylesheet" href="<?= relEsc(relCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>
<main>
    <h1>Relief Request</h1>
    <p><a href="relief_request_monitor.php">&larr; Back to requests</a></p>

    <?php if (isset($_GET['done'], $doneMessages[$_GET['done']])): ?><div class="success-box"><?= relEsc($doneMessages[$_GET['done']]) ?></div><?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="error-box"><?= relEsc($err) ?></div><?php endforeach; ?>

    <?php if (!$dist): ?>
        <div class="error-box">That request was not found.</div>
    <?php else: ?>
        <table class="data-table">
            <tbody>
                <tr><th>Reference</th><td><?= relEsc($dist['reference_number']) ?></td></tr>
                <tr><th>Status</th><td class="status-<?= strtolower(relEsc($dist['status'])) ?>"><?= relEsc($dist['status']) ?></td></tr>
                <tr><th>Disaster</th><td><?= relEsc($dist['disaster_number'] . ' - ' . $dist['disaster_name']) ?> (<?= relEsc($dist['disaster_status']) ?>)</td></tr>
                <tr><th>Evacuation center</th><td><?= relEsc($dist['center_name']) ?> (<?= relEsc($dist['center_status']) ?>)</td></tr>
                <tr><th>Requested by</th><td><?= relEsc($dist['requester']) ?> on <?= relEsc($dist['request_date']) ?></td></tr>
                <?php if ($dist['approved_by']): ?>
                <tr><th>Approved by</th><td><?= relEsc($dist['approver']) ?> on <?= relEsc($dist['approval_date']) ?></td></tr>
                <?php endif; ?>
                <?php if ($dist['release_date']): ?>
                <tr><th>Released on</th><td><?= relEsc($dist['release_date']) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <h2>Items requested</h2>
        <?php $waiting = $dist['status'] === 'Requested'; $short = false; ?>
        <table class="data-table">
            <thead><tr>
                <th>Item</th><th>Quantity</th>
                <?php if ($waiting): ?><th>In stock</th><th>Reserved by others</th><th>Available</th><th>Check</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($lines as $ln): ?>
                <tr>
                    <td><?= relEsc($ln['item_name']) ?></td>
                    <td><?= (int) $ln['quantity'] ?> <?= relEsc($ln['unit']) ?></td>
                    <?php if ($waiting):
                        $iid = (int) $ln['relief_item_id'];
                        $stock = relStock($conn, $iid);
                        $res = relReserved($conn, $iid, $id);
                        $avail = $stock - $res;
                        $enough = (int) $ln['quantity'] <= $avail;
                        if (!$enough) $short = true; ?>
                        <td><?= $stock ?></td>
                        <td><?= $res ?></td>
                        <td><?= $avail ?></td>
                        <td class="<?= $enough ? 'stock-in' : 'stock-out' ?>"><?= $enough ? 'Enough' : 'Short by ' . ((int) $ln['quantity'] - $avail) ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($waiting): ?>
            <?php if ($short): ?><div class="error-box">Some items are short, so this request cannot be approved yet. Record more receipts or reject it.</div><?php endif; ?>

            <form method="post" style="margin-top:16px">
                <input type="hidden" name="distribution_id" value="<?= (int) $id ?>">
                <button type="submit" name="action" value="approve">Approve</button>
            </form>

            <form method="post" style="margin-top:12px" onsubmit="return confirm('Reject this request?');">
                <input type="hidden" name="distribution_id" value="<?= (int) $id ?>">
                <label>Reason for rejecting
                    <input type="text" name="reason" maxlength="200" value="<?= relEsc($_POST['reason'] ?? '') ?>">
                </label>
                <p><button type="submit" name="action" value="reject">Reject</button></p>
            </form>

            <form method="post" onsubmit="return confirm('Cancel this request?');">
                <input type="hidden" name="distribution_id" value="<?= (int) $id ?>">
                <button type="submit" name="action" value="cancel">Cancel request</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
