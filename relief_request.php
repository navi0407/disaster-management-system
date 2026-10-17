<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/relief_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$currentUserId = relCurrentUserId();

$errors = [];
$disasterId = (int) ($_POST['disaster_id'] ?? 0);
$centerId = (int) ($_POST['center_id'] ?? 0);
$qtyInput = is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [];

$items = dbGetAll($conn, 'relief_items', 'item_name');
$itemIds = array_map(fn($i) => (int) $i['relief_item_id'], $items);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($disasterId <= 0) $errors[] = 'Please choose a disaster.';
    if ($centerId <= 0) $errors[] = 'Please choose an evacuation center.';

    $lines = [];
    foreach ($qtyInput as $itemId => $v) {
        $v = trim((string) $v);
        if ($v === '') continue;
        if (!ctype_digit($v) || (int) $v < 1 || (int) $v > 1000000) {
            $errors[] = 'Quantities must be whole numbers greater than 0.';
            break;
        }
        if (in_array((int) $itemId, $itemIds, true)) $lines[(int) $itemId] = (int) $v;
    }
    if (!$lines && !$errors) $errors[] = 'Enter a quantity for at least one relief item.';

    if (!$errors) {
        try {
            mysqli_begin_transaction($conn);
            $disaster = dbGetById($conn, 'disasters', 'disaster_id', $disasterId);
            $center = dbGetById($conn, 'evacuation_centers', 'center_id', $centerId);
            if (!$disaster || $disaster['status'] === 'Closed') $errors[] = 'That disaster is closed or does not exist.';
            if (!$center || $center['status'] !== 'Active') $errors[] = 'That evacuation center is not active.';

            if ($errors) {
                mysqli_rollback($conn);
            } else {
                $ref = relGenerateRequestNumber($conn);
                $distId = dbInsert($conn, 'relief_distributions', [
                    'disaster_id' => $disasterId, 'center_id' => $centerId, 'requested_by' => $currentUserId,
                    'status' => 'Requested', 'request_date' => date('Y-m-d H:i:s'), 'reference_number' => $ref,
                ]);
                $parts = [];
                foreach ($lines as $itemId => $q) {
                    dbInsert($conn, 'distribution_items', ['distribution_id' => $distId, 'relief_item_id' => $itemId, 'quantity' => $q]);
                    $it = dbGetById($conn, 'relief_items', 'relief_item_id', $itemId);
                    $parts[] = $it['item_name'] . ' x' . $q . ' ' . $it['unit'];
                }
                logAudit($conn, $currentUserId, 'Requested relief distribution', 'relief_distributions', null,
                         $disaster['disaster_number'] . ' at ' . $center['center_name'] . ': ' . implode(', ', $parts), $ref);
                mysqli_commit($conn);
                header('Location: relief_request_monitor.php?done=' . urlencode($ref)); exit;
            }
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $errors[] = 'Something went wrong. Nothing was changed.';
        }
    }
}

$disasters = relQuery($conn, "SELECT disaster_id, disaster_number, disaster_name, status FROM disasters
                              WHERE COALESCE(status, '') <> 'Closed' ORDER BY date_reported DESC");
$centers = relQuery($conn, "SELECT center_id, center_name, barangay FROM evacuation_centers
                            WHERE status = 'Active' ORDER BY center_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Request Relief Distribution</title>
    <link rel="stylesheet" href="<?= relEsc(relCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>
<main>
    <h1>Request Relief Distribution</h1>
    <?php foreach ($errors as $err): ?><div class="error-box"><?= relEsc($err) ?></div><?php endforeach; ?>

    <?php if (!$disasters): ?><div class="error-box">There is no open disaster to request relief for.</div><?php endif; ?>
    <?php if (!$centers): ?><div class="error-box">There is no active evacuation center.</div><?php endif; ?>
    <?php if (!$items): ?><div class="error-box">There are no relief items yet. <a href="relief_item_register.php">Register an item first.</a></div><?php endif; ?>

    <form method="post">
        <label>Disaster
            <select name="disaster_id">
                <option value="">-- choose --</option>
                <?php foreach ($disasters as $d): ?>
                    <option value="<?= (int) $d['disaster_id'] ?>" <?= $disasterId === (int) $d['disaster_id'] ? 'selected' : '' ?>>
                        <?= relEsc($d['disaster_number'] . ' - ' . $d['disaster_name'] . ' (' . $d['status'] . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Evacuation center
            <select name="center_id">
                <option value="">-- choose --</option>
                <?php foreach ($centers as $c): ?>
                    <option value="<?= (int) $c['center_id'] ?>" <?= $centerId === (int) $c['center_id'] ? 'selected' : '' ?>>
                        <?= relEsc($c['center_name'] . ($c['barangay'] ? ' (' . $c['barangay'] . ')' : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <h2>Items needed</h2>
        <p>Type a quantity only for the items you need. Leave the rest empty.</p>
        <table class="data-table">
            <thead><tr><th>Item</th><th>In stock</th><th>Available to promise</th><th>Quantity needed</th></tr></thead>
            <tbody>
            <?php foreach ($items as $i):
                $iid = (int) $i['relief_item_id']; ?>
                <tr>
                    <td><?= relEsc($i['item_name']) ?> (<?= relEsc($i['unit']) ?>)</td>
                    <td><?= relStock($conn, $iid) ?></td>
                    <td><?= relAvailable($conn, $iid) ?></td>
                    <td><input type="number" name="qty[<?= $iid ?>]" min="1" value="<?= relEsc($qtyInput[$iid] ?? '') ?>"></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p><button type="submit">Submit request</button> <a href="relief_request_monitor.php">Back to requests</a></p>
    </form>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
