<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';

$currentUserId = $_SESSION['user_id'] ?? 1; // placeholder until Lab 37 (login) exists

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['barangay'])) $errors[] = 'Barangay is required.';
    if (empty($_POST['address'])) $errors[] = 'Address is required.';

    if (empty($errors)) {
        $data = [
            'barangay' => $_POST['barangay'],
            'address'  => $_POST['address'],
            // head_resident_id stays NULL until a resident is registered and assigned as head
        ];
        $newId = dbInsert($conn, 'households', $data);
        logAudit($conn, $currentUserId, 'Registered new household', 'households', null, $_POST['barangay'] . ' - ' . $_POST['address']);
        header('Location: household_monitor.php?registered=' . $newId);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register Household</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<main>
    <h1>Register a Household</h1>

    <?php if ($errors): ?>
        <div class="error-box">
            <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <label>Barangay</label>
        <input type="text" name="barangay" placeholder="e.g. Barangay Bonfal Proper" value="<?= htmlspecialchars($_POST['barangay'] ?? '') ?>" required>

        <label>Address</label>
        <input type="text" name="address" placeholder="Street / Purok" value="<?= htmlspecialchars($_POST['address'] ?? '') ?>" required>

        <button type="submit">Register Household</button>
    </form>

    <p>Once this household is saved, you can register residents into it and assign a household head.</p>
</main>

<?php include 'includes/footer.php'; ?>
</body>
</html>
