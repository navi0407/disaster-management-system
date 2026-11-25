<?php
require_once 'config/db.php';
require_once 'includes/errors.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/relief_helpers.php';
require_once 'includes/distribution_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$currentUserId = relCurrentUserId();

$id = (int) ($_GET['id'] ?? $_POST['distribution_id'] ?? 0);
$errors = [];

$rows = $id > 0 ? relQuery(
    $conn,
    "SELECT d.*, ds.disaster_number, ds.disaster_name, ds.status AS disaster_status,
            c.center_name, c.status AS center_status
     FROM relief_distributions d
     LEFT JOIN disasters ds ON d.disaster_id = ds.disaster_id
     LEFT JOIN evacuation_centers c ON d.center_id = c.center_id
     WHERE d.distribution_id = ?",
    'i',
    [$id]
) : [];
$dist = $rows[0] ?? null;

$households = $dist ? distBeneficiaries($conn, (int) $dist['disaster_id'], (int) $dist['center_id']) : [];
$lines = ($dist && $dist['status'] === 'Approved') ? distRequestedLines($conn, $id) : [];

// qty[item_id][household_id], signed[household_id]
$qtyInput = is_array($_POST['qty'] ?? null) ? $_POST['qty'] : [];
$signedInput = is_array($_POST['signed'] ?? null) ? $_POST['signed'] : [];

// First visit: fill the boxes with an even split so the user only adjusts what is different.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $households && $lines) {
    foreach ($lines as $ln) {
        $split = distEvenSplit((int) $ln['quantity'], count($households));
        foreach ($households as $i => $h) $qtyInput[$ln['relief_item_id']][$h['household_id']] = $split[$i];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dist) {
    try {
        mysqli_begin_transaction($conn);
        // lock the request so it can only be released once
        $locked = relQuery($conn, "SELECT * FROM relief_distributions WHERE distribution_id = ? FOR UPDATE", 'i', [$id]);
        $dist = $locked[0] ?? null;

        if (!$dist || $dist['status'] !== 'Approved') {
            $errors[] = 'Only an Approved request can be released. This one is ' . strtolower($dist['status'] ?? 'missing') . '.';
        } else {
            $disaster = dbGetById($conn, 'disasters', 'disaster_id', (int) $dist['disaster_id']);
            $center = dbGetById($conn, 'evacuation_centers', 'center_id', (int) $dist['center_id']);
            if (!$disaster || $disaster['status'] === 'Closed') $errors[] = 'The disaster is closed or does not exist.';
            if (!$center || $center['status'] !== 'Active') $errors[] = 'The evacuation center is not active.';

            $lines = distRequestedLines($conn, $id);
            $households = distBeneficiaries($conn, (int) $dist['disaster_id'], (int) $dist['center_id']);
            $hhIds = array_map(fn($h) => (int) $h['household_id'], $households);
            if (!$lines) $errors[] = 'This request has no items.';
            if (!$households) $errors[] = 'No household is checked in at this center for this disaster, so there is nobody to give items to.';

            // lock items in the same order every time, then check real stock
            foreach ($lines as $ln) relQuery($conn, "SELECT relief_item_id FROM relief_items WHERE relief_item_id = ? FOR UPDATE", 'i', [(int) $ln['relief_item_id']]);

            $give = []; // [item_id][household_id] => qty
            foreach ($lines as $ln) {
                $iid = (int) $ln['relief_item_id'];
                $sum = 0;
                foreach ($hhIds as $hid) {
                    $v = trim((string) ($qtyInput[$iid][$hid] ?? '0'));
                    if ($v === '') $v = '0';
                    if (!ctype_digit($v) || (int) $v > 1000000) {
                        $errors[] = 'Quantities must be whole numbers, 0 or more.';
                        break 2;
                    }
                    if ((int) $v > 0) {
                        $give[$iid][$hid] = (int) $v;
                        $sum += (int) $v;
                    }
                }
                if ($sum !== (int) $ln['quantity'])
                    $errors[] = $ln['item_name'] . ': you entered ' . $sum . ' but the approved quantity is ' . $ln['quantity'] . ' ' . $ln['unit'] . '.';
                if (relUsable($conn, $iid) < (int) $ln['quantity']) {
                    $expiredLeft = relExpired($conn, $iid);
                    $errors[] = 'Not enough ' . $ln['item_name'] . ' to release'
                        . ($expiredLeft > 0 ? ' (' . $expiredLeft . ' ' . $ln['unit'] . ' in stock has expired and cannot be given out).' : '.');
                }
            }

            if (!$errors) {
                $now = date('Y-m-d H:i:s');
                $requestedText = implode(', ', array_map(fn($l) => $l['item_name'] . ' x' . $l['quantity'] . ' ' . $l['unit'], $lines));
                // the request lines are replaced by the real per-household lines
                $del = mysqli_prepare($conn, "DELETE FROM distribution_items WHERE distribution_id = ? AND date_given IS NULL");
                mysqli_stmt_bind_param($del, 'i', $id);
                mysqli_stmt_execute($del);
                mysqli_stmt_close($del);

                $heads = [];
                foreach ($households as $h) $heads[(int) $h['household_id']] = $h['head_resident_id'];
                $count = 0;
                foreach ($give as $iid => $perHh) {
                    foreach ($perHh as $hid => $q) {
                        dbInsert($conn, 'distribution_items', [
                            'distribution_id' => $id,
                            'relief_item_id' => $iid,
                            'household_id' => $hid,
                            'resident_id' => $heads[$hid],
                            'quantity' => $q,
                            'date_given' => $now,
                            'distributed_by' => $currentUserId,
                            'signature_on_file' => !empty($signedInput[$hid]) ? 1 : 0,
                        ]);
                        $count++;
                    }
                }
                dbUpdate($conn, 'relief_distributions', 'distribution_id', $id, ['status' => 'Released', 'release_date' => $now]);
                logAudit(
                    $conn,
                    $currentUserId,
                    'Released relief distribution',
                    'relief_distributions',
                    'Approved',
                    'Released: ' . $requestedText . ' to ' . count($households) . ' household(s), ' . $count . ' line(s)',
                    $dist['reference_number']
                );
                mysqli_commit($conn);
                header('Location: relief_distribution_monitor.php?released=' . urlencode($dist['reference_number']));
                exit;
            }
        }
        mysqli_rollback($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $errors[] = errMessage($e);
    }
    // reload display data after a failed attempt
    $rows = relQuery(
        $conn,
        "SELECT d.*, ds.disaster_number, ds.disaster_name, ds.status AS disaster_status, c.center_name, c.status AS center_status
         FROM relief_distributions d LEFT JOIN disasters ds ON d.disaster_id = ds.disaster_id
         LEFT JOIN evacuation_centers c ON d.center_id = c.center_id WHERE d.distribution_id = ?",
        'i',
        [$id]
    );
    $dist = $rows[0] ?? $dist;
    $lines = ($dist && $dist['status'] === 'Approved') ? distRequestedLines($conn, $id) : [];
}
if (!$dist) errNotFound('relief request');   // Lab 50: a bad or old link
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Release Relief Items</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <?php include 'includes/header.php'; ?>
    <main class="wide">
        <h1>Release Relief Items</h1>
        <p><a href="relief_request_monitor.php">&larr; Back to requests</a></p>
        <?php foreach ($errors as $err): ?><div class="error-box"><?= relEsc($err) ?></div><?php endforeach; ?>

        <?php if (!$dist): ?>
            <div class="error-box">That request was not found.</div>
        <?php elseif ($dist['status'] !== 'Approved'): ?>
            <div class="error-box">This request is <?= relEsc(strtolower($dist['status'])) ?>. Only Approved requests can be released.
                <a href="relief_request_view.php?id=<?= (int) $id ?>">View it</a>
            </div>
        <?php else: ?>
            <p><strong><?= relEsc($dist['reference_number']) ?></strong> for <?= relEsc($dist['disaster_number'] . ' - ' . $dist['disaster_name']) ?>
                at <?= relEsc($dist['center_name']) ?></p>

            <?php if (!$households): ?>
                <div class="error-box">No household is checked in at this center for this disaster. Check evacuees in first.</div>
            <?php else: ?>
                <p>Decide how many of each item every household gets. Each item's boxes must add up to the approved quantity. The boxes start with an even split.</p>
                <form method="post">
                    <input type="hidden" name="distribution_id" value="<?= (int) $id ?>">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Household</th>
                                <th>Head</th>
                                <th>Members</th>
                                <?php foreach ($lines as $ln): ?><th><?= relEsc($ln['item_name']) ?> (of <?= (int) $ln['quantity'] ?> <?= relEsc($ln['unit']) ?>)</th><?php endforeach; ?>
                                <th>Signed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($households as $h): $hid = (int) $h['household_id']; ?>
                                <tr>
                                    <td>#<?= $hid ?> <?= relEsc($h['barangay']) ?></td>
                                    <td><?= relEsc($h['head_name'] ?: 'No head set') ?></td>
                                    <td><?= (int) $h['members'] ?></td>
                                    <?php foreach ($lines as $ln): $iid = (int) $ln['relief_item_id']; ?>
                                        <td><input type="number" min="0" name="qty[<?= $iid ?>][<?= $hid ?>]" value="<?= relEsc($qtyInput[$iid][$hid] ?? 0) ?>"></td>
                                    <?php endforeach; ?>
                                    <td><input type="checkbox" name="signed[<?= $hid ?>]" value="1" <?= !empty($signedInput[$hid]) ? 'checked' : '' ?>></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p><button type="submit" onclick="return confirm('Release these items now? This cannot be undone.');">Release items</button></p>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </main>
    <?php include 'includes/footer.php'; ?>
</body>

</html>