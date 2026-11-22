<?php
require_once 'config/db.php';
require_once 'includes/validate.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/evacuation_helpers.php';

$currentUserId = $_SESSION['user_id'] ?? 1;

$f = [
    'barangay' => trim($_POST['barangay'] ?? ''),
    'address'  => trim($_POST['address'] ?? ''),
];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lab 48: shared checks
    $errors = vCollect([
        vRequired('Barangay', $f['barangay']) ?: vMax('Barangay', $f['barangay'], 100),
        vRequired('Address', $f['address']) ?: vMax('Address', $f['address'], 150),
    ]);

    // Lab 50: duplicate records. The same barangay and address is the same household.
    if (!$errors) {
        $dup = dbQueryAll($conn,
            "SELECT household_id FROM households WHERE LOWER(barangay) = LOWER(?) AND LOWER(address) = LOWER(?) LIMIT 1",
            'ss', [$f['barangay'], $f['address']]);
        if ($dup) $errors[] = 'That household is already registered (Household #' . (int) $dup[0]['household_id'] . ').';
    }

    if (!$errors) {
        try {
            mysqli_begin_transaction($conn);
            // head_resident_id stays NULL until a resident is registered and assigned as head
            $newId = dbInsert($conn, 'households', ['barangay' => $f['barangay'], 'address' => $f['address']]);
            logAudit($conn, $currentUserId, 'Registered new household', 'households', null, $f['barangay'] . ' - ' . $f['address']);
            mysqli_commit($conn);
            header('Location: household_monitor.php?registered=' . $newId);
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $errors[] = errMessage($e);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register Household</title>
    <link rel="stylesheet" href="<?= errEsc(authCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>

<main>
    <h1>Register a Household</h1>

    <?php foreach ($errors as $err): ?><div class="error-box"><?= errEsc($err) ?></div><?php endforeach; ?>

    <form method="post">
        <label>Barangay</label>
        <input type="text" name="barangay" maxlength="100" placeholder="e.g. Barangay Bonfal Proper" value="<?= errEsc($f['barangay']) ?>">

        <label>Address</label>
        <input type="text" name="address" maxlength="150" placeholder="Street / Purok" value="<?= errEsc($f['address']) ?>">

        <button type="submit">Register Household</button>
    </form>

    <p>Once this household is saved, you can register residents into it and assign a household head.</p>
</main>

<?php include 'includes/footer.php'; ?>
</body>
</html>
