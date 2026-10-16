<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/resource_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$currentUserId = resCurrentUserId();

$errors = [];
$selected = (int) ($_POST['resource_id'] ?? $_GET['resource_id'] ?? 0);
$type = $_POST['transaction_type'] ?? 'Receive';
$qtyRaw = trim($_POST['quantity'] ?? '');
$remarks = trim($_POST['remarks'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($type, ['Receive', 'Release'], true)) $errors[] = 'Please choose Receive or Release.';
    if ($selected <= 0) $errors[] = 'Please choose a resource.';
    if ($qtyRaw === '' || !ctype_digit($qtyRaw) || (int) $qtyRaw < 1) $errors[] = 'Quantity must be a whole number greater than 0.';
    elseif ((int) $qtyRaw > 1000000) $errors[] = 'Quantity is too large.';
    if (mb_strlen($remarks) > 255) $errors[] = 'Remarks must be 255 characters or less.';

    if (!$errors) {
        $qty = (int) $qtyRaw;
        try {
            mysqli_begin_transaction($conn);
            // lock the resource row so two releases can't take the same stock
            $lock = mysqli_prepare($conn, "SELECT * FROM resources WHERE resource_id = ? FOR UPDATE");
            mysqli_stmt_bind_param($lock, 'i', $selected);
            mysqli_stmt_execute($lock);
            $res = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            mysqli_stmt_close($lock);

            if (!$res) $errors[] = 'That resource does not exist.';
            elseif ($res['status'] !== 'Active') $errors[] = 'That resource is inactive.';
            else {
                $before = resCalcQuantity($conn, $selected); // real stock from the history
                if ($type === 'Release' && $qty > $before)
                    $errors[] = 'Not enough stock. Only ' . $before . ' ' . $res['unit'] . ' on hand.';
            }

            if ($errors) {
                mysqli_rollback($conn);
            } else {
                $txId = dbInsert($conn, 'resource_transactions', [
                    'resource_id' => $selected, 'transaction_type' => $type, 'quantity' => $qty,
                    'remarks' => $remarks !== '' ? $remarks : null,
                    'user_id' => $currentUserId, 'transaction_date' => date('Y-m-d H:i:s'),
                ]);
                $ref = ($type === 'Receive' ? 'RCV-' : 'REL-') . str_pad((string) $txId, 5, '0', STR_PAD_LEFT);
                dbUpdate($conn, 'resource_transactions', 'transaction_id', $txId, ['reference_number' => $ref]);
                $after = recountQuantity($conn, $selected);
                logAudit($conn, $currentUserId, $type === 'Receive' ? 'Received stock' : 'Released stock',
                         'resource_transactions', 'On hand: ' . $before, 'On hand: ' . $after . ' (' . $res['resource_name'] . ', ' . $type . ' ' . $qty . ')', $ref);
                mysqli_commit($conn);
                header('Location: resource_transaction_monitor.php?done=' . $txId); exit;
            }
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $errors[] = 'Something went wrong. Nothing was changed.';
        }
    }
}

$resources = resQuery($conn,
    "SELECT resource_id, resource_code, resource_name, unit, quantity_on_hand
     FROM resources WHERE status = 'Active' ORDER BY resource_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receive or Release Stock</title>
    <link rel="stylesheet" href="<?= resEsc(resCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>
<main>
    <h1>Receive or Release Stock</h1>
    <?php foreach ($errors as $err): ?><div class="error-box"><?= resEsc($err) ?></div><?php endforeach; ?>

    <form method="post">
        <label>Resource
            <select name="resource_id">
                <option value="">-- choose --</option>
                <?php foreach ($resources as $r): ?>
                    <option value="<?= (int) $r['resource_id'] ?>" <?= $selected === (int) $r['resource_id'] ? 'selected' : '' ?>>
                        <?= resEsc($r['resource_code'] . ' - ' . $r['resource_name'] . ' (on hand: ' . $r['quantity_on_hand'] . ' ' . $r['unit'] . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Type
            <select name="transaction_type">
                <?php foreach (['Receive', 'Release'] as $t): ?>
                    <option value="<?= $t ?>" <?= $type === $t ? 'selected' : '' ?>><?= $t ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Quantity <input type="number" name="quantity" min="1" value="<?= resEsc($qtyRaw) ?>"></label>
        <label>Source or reason (optional) <input type="text" name="remarks" maxlength="255" value="<?= resEsc($remarks) ?>"></label>

        <p><button type="submit">Save</button> <a href="resource_monitor.php">Back to inventory</a></p>
    </form>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
