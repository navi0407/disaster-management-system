<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/relief_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$currentUserId = relCurrentUserId();

$errors = [];
$selected = (int) ($_POST['relief_item_id'] ?? $_GET['relief_item_id'] ?? 0);
$source = trim($_POST['source'] ?? '');
$qtyRaw = trim($_POST['quantity'] ?? '');
$dateReceived = trim($_POST['date_received'] ?? date('Y-m-d'));
$expiration = trim($_POST['expiration_date'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($selected <= 0) $errors[] = 'Please choose a relief item.';
    if ($source === '') $errors[] = 'Source is required (who gave or delivered it).';
    elseif (mb_strlen($source) > 100) $errors[] = 'Source must be 100 characters or less.';
    if ($qtyRaw === '' || !ctype_digit($qtyRaw) || (int) $qtyRaw < 1) $errors[] = 'Quantity must be a whole number greater than 0.';
    elseif ((int) $qtyRaw > 1000000) $errors[] = 'Quantity is too large.';

    if (!relValidDate($dateReceived)) $errors[] = 'Date received is not a valid date.';
    elseif ($dateReceived > date('Y-m-d')) $errors[] = 'Date received cannot be in the future.';

    if ($expiration !== '') {
        if (!relValidDate($expiration)) $errors[] = 'Expiration date is not a valid date.';
        elseif (relValidDate($dateReceived) && $expiration <= $dateReceived) $errors[] = 'Expiration date must be after the date received.';
    }

    if (!$errors) {
        try {
            mysqli_begin_transaction($conn);
            $item = dbGetById($conn, 'relief_items', 'relief_item_id', $selected);
            if (!$item) {
                $errors[] = 'That relief item does not exist.';
                mysqli_rollback($conn);
            } else {
                $qty = (int) $qtyRaw;
                $before = relStock($conn, $selected);
                $number = relGenerateReceiptNumber($conn);
                dbInsert($conn, 'relief_receipts', [
                    'receipt_number' => $number, 'relief_item_id' => $selected, 'source' => $source,
                    'quantity' => $qty, 'unit' => $item['unit'], 'date_received' => $dateReceived,
                    'expiration_date' => $expiration !== '' ? $expiration : null,
                ]);
                logAudit($conn, $currentUserId, 'Received relief items', 'relief_receipts',
                         'In stock: ' . $before,
                         'In stock: ' . ($before + $qty) . ' (' . $item['item_name'] . ', +' . $qty . ' ' . $item['unit'] . ' from ' . $source . ')',
                         $number);
                mysqli_commit($conn);
                header('Location: relief_receipt_monitor.php?done=' . urlencode($number)); exit;
            }
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $errors[] = 'Something went wrong. Nothing was changed.';
        }
    }
}

$items = dbGetAll($conn, 'relief_items', 'item_name');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Record Relief Receipt</title>
    <link rel="stylesheet" href="<?= relEsc(relCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>
<main>
    <h1>Record Relief Receipt</h1>
    <?php foreach ($errors as $err): ?><div class="error-box"><?= relEsc($err) ?></div><?php endforeach; ?>

    <?php if (!$items): ?>
        <div class="error-box">There are no relief items yet. <a href="relief_item_register.php">Register an item first.</a></div>
    <?php endif; ?>

    <form method="post">
        <label>Relief item
            <select name="relief_item_id">
                <option value="">-- choose --</option>
                <?php foreach ($items as $i): ?>
                    <option value="<?= (int) $i['relief_item_id'] ?>" <?= $selected === (int) $i['relief_item_id'] ? 'selected' : '' ?>>
                        <?= relEsc($i['item_name'] . ' (' . $i['unit'] . ')') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Source (donor or supplier) <input type="text" name="source" maxlength="100" value="<?= relEsc($source) ?>"></label>
        <label>Quantity <input type="number" name="quantity" min="1" value="<?= relEsc($qtyRaw) ?>"></label>
        <label>Date received <input type="date" name="date_received" max="<?= date('Y-m-d') ?>" value="<?= relEsc($dateReceived) ?>"></label>
        <label>Expiration date (optional) <input type="date" name="expiration_date" value="<?= relEsc($expiration) ?>"></label>

        <p><button type="submit">Save receipt</button> <a href="relief_monitor.php">Back to relief inventory</a></p>
    </form>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
