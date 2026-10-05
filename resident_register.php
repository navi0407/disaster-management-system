<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';

$currentUserId = $_SESSION['user_id'] ?? 1; // placeholder until Lab 37 (login) exists

$households = dbGetAll($conn, 'households', 'barangay, address');

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editing = $editId ? dbGetById($conn, 'residents', 'resident_id', $editId) : null;

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['household_id'])) $errors[] = 'Household is required.';
    if (empty($_POST['first_name'])) $errors[] = 'First name is required.';
    if (empty($_POST['last_name'])) $errors[] = 'Last name is required.';
    if (empty($_POST['birthdate'])) $errors[] = 'Birthdate is required.';

    if (empty($errors)) {
        $data = [
            'household_id'       => $_POST['household_id'],
            'first_name'         => $_POST['first_name'],
            'last_name'          => $_POST['last_name'],
            'birthdate'          => $_POST['birthdate'],
            'gender'              => $_POST['gender'],
            'address'             => $_POST['address'],
            'barangay'            => $_POST['barangay'],
            'vulnerability_type'  => $_POST['vulnerability_type'] ?: 'None',
            'contact_number'      => $_POST['contact_number'],
            'emergency_contact'   => $_POST['emergency_contact'],
        ];

        if (!empty($_POST['resident_id'])) {
            $residentId = (int) $_POST['resident_id'];
            dbUpdate($conn, 'residents', 'resident_id', $residentId, $data);
            logAudit($conn, $currentUserId, 'Updated resident', 'residents', null, $_POST['first_name'] . ' ' . $_POST['last_name']);
            header('Location: resident_monitor.php?updated=' . $residentId);
        } else {
            $data['status'] = 'Active';
            $newId = dbInsert($conn, 'residents', $data);
            logAudit($conn, $currentUserId, 'Registered new resident', 'residents', null, $_POST['first_name'] . ' ' . $_POST['last_name']);
            header('Location: resident_monitor.php?registered=' . $newId);
        }
        exit;
    }
}

// Pre-fill from the database when editing, from a failed submission otherwise, or leave blank
$f = fn($field, $default = '') => htmlspecialchars($_POST[$field] ?? $editing[$field] ?? $default);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register Resident</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<main>
    <h1><?= $editing ? 'Edit Resident' : 'Register a Resident' ?></h1>

    <?php if ($errors): ?>
        <div class="error-box">
            <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($households)): ?>
        <div class="error-box">
            <p>No households exist yet. <a href="household_register.php">Register a household</a> first.</p>
        </div>
    <?php else: ?>
    <form method="post">
        <?php if ($editing): ?>
            <input type="hidden" name="resident_id" value="<?= $editing['resident_id'] ?>">
        <?php endif; ?>

        <label>Household</label>
        <select name="household_id" required>
            <option value="">-- Select household --</option>
            <?php foreach ($households as $h): ?>
                <option value="<?= $h['household_id'] ?>" <?= $f('household_id') == $h['household_id'] ? 'selected' : '' ?>>
                    #<?= $h['household_id'] ?> &mdash; <?= htmlspecialchars($h['barangay']) ?>, <?= htmlspecialchars($h['address']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>First Name</label>
        <input type="text" name="first_name" value="<?= $f('first_name') ?>" required>

        <label>Last Name</label>
        <input type="text" name="last_name" value="<?= $f('last_name') ?>" required>

        <label>Birthdate</label>
        <input type="date" name="birthdate" value="<?= $f('birthdate') ?>" required>

        <label>Gender</label>
        <select name="gender">
            <option <?= $f('gender') === 'Male' ? 'selected' : '' ?>>Male</option>
            <option <?= $f('gender') === 'Female' ? 'selected' : '' ?>>Female</option>
        </select>

        <label>Address</label>
        <input type="text" name="address" value="<?= $f('address') ?>" placeholder="Can match the household's address, or be more specific">

        <label>Barangay</label>
        <input type="text" name="barangay" value="<?= $f('barangay') ?>" placeholder="e.g. Barangay Bonfal Proper">

        <label>Vulnerability Type</label>
        <select name="vulnerability_type">
            <?php $vTypes = ['None' => 'None', 'PWD' => 'PWD', 'Senior' => 'Senior Citizen', 'Pregnant' => 'Pregnant', 'Infant' => 'Infant']; ?>
            <?php foreach ($vTypes as $val => $label): ?>
                <option value="<?= $val ?>" <?= $f('vulnerability_type', 'None') === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>

        <label>Contact Number</label>
        <input type="text" name="contact_number" value="<?= $f('contact_number') ?>" placeholder="Optional">

        <label>Emergency Contact</label>
        <input type="text" name="emergency_contact" value="<?= $f('emergency_contact') ?>" placeholder="Name and/or number">

        <button type="submit"><?= $editing ? 'Save Changes' : 'Register Resident' ?></button>
    </form>
    <?php endif; ?>
</main>

<?php include 'includes/footer.php'; ?>
</body>
</html>
