<?php
require_once 'config/db.php';
require_once 'includes/validate.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/evacuation_helpers.php';

$currentUserId = $_SESSION['user_id'] ?? 1;

$households = dbGetAll($conn, 'households', 'barangay, address');

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editing = $editId ? dbGetById($conn, 'residents', 'resident_id', $editId) : null;

$genders = ['Male', 'Female'];
$vTypes = ['None' => 'None', 'PWD' => 'PWD', 'Senior' => 'Senior Citizen', 'Pregnant' => 'Pregnant', 'Infant' => 'Infant'];

$errors = [];
if ($editId && !$editing) $errors[] = 'That resident does not exist. It may have been removed.';

// Form values: from the post after a failed save, from the database when editing, blank otherwise
$fields = [
    'household_id',
    'first_name',
    'last_name',
    'birthdate',
    'gender',
    'address',
    'barangay',
    'vulnerability_type',
    'contact_number',
    'emergency_contact'
];
$f = [];
foreach ($fields as $k) $f[$k] = trim($_POST[$k] ?? $editing[$k] ?? '');
if ($f['gender'] === '') $f['gender'] = 'Male';
if ($f['vulnerability_type'] === '') $f['vulnerability_type'] = 'None';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postId = (int) ($_POST['resident_id'] ?? 0);
    $existing = $postId ? dbGetById($conn, 'residents', 'resident_id', $postId) : null;
    if ($postId && !$existing) $errors[] = 'That resident does not exist. It may have been removed.';

    // Lab 48: shared checks
    $errors = array_merge($errors, vCollect([
        vExists($conn, 'household', 'households', 'household_id', (int) $f['household_id']),
        vPersonName('First name', $f['first_name'], 50),
        vPersonName('Last name', $f['last_name'], 50),
        vDate('Birthdate', $f['birthdate'], true) ?: vNotFuture('Birthdate', $f['birthdate'])
            ?: (substr($f['birthdate'], 0, 4) < (int) date('Y') - 120 ? 'Birthdate is too far in the past.' : ''),
        vOneOf('Gender', $f['gender'], $genders),
        vMax('Address', $f['address'], 150),
        vMax('Barangay', $f['barangay'], 100),
        vOneOf('Vulnerability type', $f['vulnerability_type'], array_keys($vTypes)),
        vPhone('Contact number', $f['contact_number']),
        vMax('Emergency contact', $f['emergency_contact'], 100),
    ]));

    // Lab 50: duplicate records. Same first name, last name and birthdate is the same person.
    if (!$errors) {
        $dup = dbQueryAll(
            $conn,
            "SELECT resident_id FROM residents WHERE LOWER(first_name) = LOWER(?) AND LOWER(last_name) = LOWER(?)
             AND birthdate = ? AND resident_id <> ? LIMIT 1",
            'sssi',
            [$f['first_name'], $f['last_name'], $f['birthdate'], $postId]
        );
        if ($dup) $errors[] = 'This resident is already registered (Resident #' . (int) $dup[0]['resident_id'] . ').';
    }

    if (!$errors) {
        $data = [
            'household_id'       => (int) $f['household_id'],
            'first_name'         => $f['first_name'],
            'last_name'          => $f['last_name'],
            'birthdate'          => $f['birthdate'],
            'gender'             => $f['gender'],
            'address'            => $f['address'],
            'barangay'           => $f['barangay'],
            'vulnerability_type' => $f['vulnerability_type'],
            'contact_number'     => $f['contact_number'],
            'emergency_contact'  => $f['emergency_contact'],
        ];
        try {
            mysqli_begin_transaction($conn);
            if ($existing) {
                dbUpdate($conn, 'residents', 'resident_id', $postId, $data);
                logAudit($conn, $currentUserId, 'Updated resident', 'residents', null, $f['first_name'] . ' ' . $f['last_name']);
                $go = 'resident_monitor.php?updated=' . $postId;
            } else {
                $data['status'] = 'Active';
                $newId = dbInsert($conn, 'residents', $data);
                logAudit($conn, $currentUserId, 'Registered new resident', 'residents', null, $f['first_name'] . ' ' . $f['last_name']);
                $go = 'resident_monitor.php?registered=' . $newId;
            }
            mysqli_commit($conn);
            header('Location: ' . $go);
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $errors[] = errMessage($e);
        }
    }
}
$isEdit = $editing || (!empty($_POST['resident_id']));
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title><?= $isEdit ? 'Edit Resident' : 'Register Resident' ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>

<body>
    <?php include 'includes/header.php'; ?>

    <main>
        <h1><?= $isEdit ? 'Edit Resident' : 'Register a Resident' ?></h1>

        <?php foreach ($errors as $err): ?><div class="error-box"><?= errEsc($err) ?></div><?php endforeach; ?>

        <?php if (empty($households)): ?>
            <div class="error-box">No households exist yet. <a href="household_register.php">Register a household</a> first.</div>
        <?php else: ?>
            <form method="post">
                <?php if ($isEdit): ?>
                    <input type="hidden" name="resident_id" value="<?= (int) ($editing['resident_id'] ?? $_POST['resident_id'] ?? 0) ?>">
                <?php endif; ?>

                <label>Household</label>
                <select name="household_id">
                    <option value=""> Select household </option>
                    <?php foreach ($households as $h): ?>
                        <option value="<?= (int) $h['household_id'] ?>" <?= (string) $f['household_id'] === (string) $h['household_id'] ? 'selected' : '' ?>>
                            #<?= (int) $h['household_id'] ?> &mdash; <?= errEsc($h['barangay']) ?>, <?= errEsc($h['address']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>First Name</label>
                <input type="text" name="first_name" maxlength="50" value="<?= errEsc($f['first_name']) ?>">

                <label>Last Name</label>
                <input type="text" name="last_name" maxlength="50" value="<?= errEsc($f['last_name']) ?>">

                <label>Birthdate</label>
                <input type="date" name="birthdate" max="<?= date('Y-m-d') ?>" value="<?= errEsc($f['birthdate']) ?>">

                <label>Gender</label>
                <select name="gender">
                    <?php foreach ($genders as $g): ?>
                        <option <?= $f['gender'] === $g ? 'selected' : '' ?>><?= $g ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Address</label>
                <input type="text" name="address" maxlength="150" value="<?= errEsc($f['address']) ?>" placeholder="Can match the household's address, or be more specific">

                <label>Barangay</label>
                <input type="text" name="barangay" maxlength="100" value="<?= errEsc($f['barangay']) ?>" placeholder="e.g. Barangay Bonfal Proper">

                <label>Vulnerability Type</label>
                <select name="vulnerability_type">
                    <?php foreach ($vTypes as $val => $label): ?>
                        <option value="<?= errEsc($val) ?>" <?= $f['vulnerability_type'] === $val ? 'selected' : '' ?>><?= errEsc($label) ?></option>
                    <?php endforeach; ?>
                </select>

                <label>Contact Number</label>
                <input type="text" name="contact_number" maxlength="20" value="<?= errEsc($f['contact_number']) ?>" placeholder="Optional, e.g. 09171234567">

                <label>Emergency Contact</label>
                <input type="text" name="emergency_contact" maxlength="100" value="<?= errEsc($f['emergency_contact']) ?>" placeholder="Name and/or number">

                <button type="submit"><?= $isEdit ? 'Save Changes' : 'Register Resident' ?></button>
            </form>
        <?php endif; ?>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>

</html>