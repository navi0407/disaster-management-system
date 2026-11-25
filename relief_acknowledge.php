<?php
// Lab 29: beneficiary acknowledgment. Marks a household's received items as signed for.
require_once 'config/db.php';
require_once 'includes/errors.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/relief_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$currentUserId = relCurrentUserId();

$id = (int) ($_GET['id'] ?? $_POST['distribution_id'] ?? 0);
$errors = [];
$done = '';

$rows = $id > 0 ? relQuery(
    $conn,
    "SELECT d.*, ds.disaster_number, ds.disaster_name, c.center_name
     FROM relief_distributions d
     LEFT JOIN disasters ds ON d.disaster_id = ds.disaster_id
     LEFT JOIN evacuation_centers c ON d.center_id = c.center_id
     WHERE d.distribution_id = ?",
    'i',
    [$id]
) : [];
$dist = $rows[0] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dist && isset($_POST['household_id'])) {
    $hid = (int) $_POST['household_id'];
    if ($dist['status'] !== 'Released') {
        $errors[] = 'Only a Released distribution can be acknowledged.';
    } else {
        try {
            mysqli_begin_transaction($conn);
            $stmt = mysqli_prepare($conn, "UPDATE distribution_items SET signature_on_file = 1
                                           WHERE distribution_id = ? AND household_id = ? AND signature_on_file = 0");
            mysqli_stmt_bind_param($stmt, 'ii', $id, $hid);
            mysqli_stmt_execute($stmt);
            $changed = mysqli_stmt_affected_rows($stmt);
            mysqli_stmt_close($stmt);
            if ($changed > 0) {
                logAudit(
                    $conn,
                    $currentUserId,
                    'Beneficiary acknowledged relief',
                    'distribution_items',
                    'Not signed',
                    'Signed: household #' . $hid . ', ' . $changed . ' line(s)',
                    $dist['reference_number']
                );
                $done = 'Household #' . $hid . ' marked as received and signed.';
            } else {
                $errors[] = 'Nothing to acknowledge for that household. It may already be signed.';
            }
            mysqli_commit($conn);
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $errors[] = errMessage($e);
        }
    }
}

$byHousehold = [];
if ($dist) {
    $items = relQuery(
        $conn,
        "SELECT di.household_id, di.quantity, di.signature_on_file, di.date_given, ri.item_name, ri.unit,
                h.barangay, CONCAT(r.first_name, ' ', r.last_name) AS head_name
         FROM distribution_items di
         JOIN relief_items ri ON ri.relief_item_id = di.relief_item_id
         LEFT JOIN households h ON h.household_id = di.household_id
         LEFT JOIN residents r ON r.resident_id = di.resident_id
         WHERE di.distribution_id = ? AND di.date_given IS NOT NULL
         ORDER BY h.barangay, di.household_id, ri.item_name",
        'i',
        [$id]
    );
    foreach ($items as $it) {
        $k = (int) $it['household_id'];
        if (!isset($byHousehold[$k])) $byHousehold[$k] = ['barangay' => $it['barangay'], 'head' => $it['head_name'], 'signed' => true, 'items' => []];
        $byHousehold[$k]['items'][] = $it['item_name'] . ' x' . $it['quantity'] . ' ' . $it['unit'];
        if (!$it['signature_on_file']) $byHousehold[$k]['signed'] = false;
    }
}
if (!$dist) errNotFound('distribution record');   // Lab 50: a bad or old link
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Beneficiary Acknowledgment</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <?php include 'includes/header.php'; ?>
    <main class="wide">
        <h1>Beneficiary Acknowledgment</h1>
        <p><a href="relief_distribution_monitor.php">&larr; Back to distribution monitor</a></p>
        <?php if ($done): ?><div class="success-box"><?= relEsc($done) ?></div><?php endif; ?>
        <?php foreach ($errors as $err): ?><div class="error-box"><?= relEsc($err) ?></div><?php endforeach; ?>

        <?php if (!$dist): ?>
            <div class="error-box">That distribution was not found.</div>
        <?php else: ?>
            <p><strong><?= relEsc($dist['reference_number']) ?></strong> (<span class="status-<?= strtolower(relEsc($dist['status'])) ?>"><?= relEsc($dist['status']) ?></span>)
                for <?= relEsc($dist['disaster_number'] . ' - ' . $dist['disaster_name']) ?> at <?= relEsc($dist['center_name']) ?></p>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Household</th>
                        <th>Head</th>
                        <th>Items received</th>
                        <th>Acknowledged</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$byHousehold): ?><tr>
                            <td colspan="5">Nothing has been released for this request yet.</td>
                        </tr><?php endif; ?>
                    <?php foreach ($byHousehold as $hid => $h): ?>
                        <tr>
                            <td>#<?= $hid ?> <?= relEsc($h['barangay']) ?></td>
                            <td><?= relEsc($h['head']) ?></td>
                            <td><?= relEsc(implode(', ', $h['items'])) ?></td>
                            <td class="<?= $h['signed'] ? 'ack-yes' : 'ack-no' ?>"><?= $h['signed'] ? 'Yes' : 'Not yet' ?></td>
                            <td>
                                <?php if (!$h['signed']): ?>
                                    <form method="post" class="inline-form">
                                        <input type="hidden" name="distribution_id" value="<?= (int) $id ?>">
                                        <input type="hidden" name="household_id" value="<?= (int) $hid ?>">
                                        <button type="submit">Mark received</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </main>
    <?php include 'includes/footer.php'; ?>
</body>

</html>