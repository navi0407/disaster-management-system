<?php
require_once 'config/db.php';
require_once 'includes/validate.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/evacuation_helpers.php';

$currentUserId = $_SESSION['user_id'] ?? 1;

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editing = $editId ? dbGetById($conn, 'evacuation_centers', 'center_id', $editId) : null;

$errors = [];
if ($editId && !$editing) $errors[] = 'That evacuation center does not exist. It may have been removed.';

$fields = ['center_name', 'location', 'barangay', 'contact_person', 'contact_number', 'capacity', 'facilities'];
$f = [];
foreach ($fields as $k) $f[$k] = trim($_POST[$k] ?? $editing[$k] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postId = (int) ($_POST['center_id'] ?? 0);
    $existing = $postId ? dbGetById($conn, 'evacuation_centers', 'center_id', $postId) : null;
    if ($postId && !$existing) $errors[] = 'That evacuation center does not exist. It may have been removed.';

    // Lab 48: shared checks
    $errors = array_merge($errors, vCollect([
        vRequired('Center name', $f['center_name']) ?: vMax('Center name', $f['center_name'], 100),
        vRequired('Location', $f['location']) ?: vMax('Location', $f['location'], 150),
        vRequired('Barangay', $f['barangay']) ?: vMax('Barangay', $f['barangay'], 100),
        vMax('Contact person', $f['contact_person'], 100),
        vPhone('Contact number', $f['contact_number']),
        vWholeNumber('Capacity', $f['capacity'], 1, 100000),
        vMax('Facilities', $f['facilities'], 255),
    ]));

    // Business rule: capacity can't be set lower than the people really inside (counted, not the saved number)
    if (!$errors && $existing) {
        $inside = countCheckedIn($conn, $postId);
        if ((int) $f['capacity'] < $inside)
            $errors[] = 'Capacity cannot be lower than the number of people inside right now (' . $inside . ').';
    }

    // Lab 50: duplicate records. Same center name in the same barangay.
    if (!$errors) {
        $dup = dbQueryAll($conn,
            "SELECT center_id FROM evacuation_centers WHERE LOWER(center_name) = LOWER(?) AND LOWER(barangay) = LOWER(?) AND center_id <> ? LIMIT 1",
            'ssi', [$f['center_name'], $f['barangay'], $postId]);
        if ($dup) $errors[] = 'An evacuation center with that name already exists in this barangay.';
    }

    if (!$errors) {
        $data = [
            'center_name'    => $f['center_name'],
            'location'       => $f['location'],
            'barangay'       => $f['barangay'],
            'contact_person' => $f['contact_person'],
            'contact_number' => $f['contact_number'],
            'capacity'       => (int) $f['capacity'],
            'facilities'     => $f['facilities'],
        ];
        try {
            mysqli_begin_transaction($conn);
            if ($existing) {
                dbUpdate($conn, 'evacuation_centers', 'center_id', $postId, $data);
                logAudit($conn, $currentUserId, 'Updated evacuation center', 'evacuation_centers', null, $f['center_name']);
                $go = 'evacuation_center_monitor.php?updated=' . $postId;
            } else {
                $data['current_occupancy'] = 0; // nobody has checked in yet
                $data['status'] = 'Active';
                $newId = dbInsert($conn, 'evacuation_centers', $data);
                logAudit($conn, $currentUserId, 'Registered evacuation center', 'evacuation_centers', null, $f['center_name']);
                $go = 'evacuation_center_monitor.php?registered=' . $newId;
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
$isEdit = $editing || !empty($_POST['center_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $isEdit ? 'Edit Evacuation Center' : 'Register Evacuation Center' ?></title>
    <link rel="stylesheet" href="<?= errEsc(authCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>

<main>
    <h1><?= $isEdit ? 'Edit Evacuation Center' : 'Register an Evacuation Center' ?></h1>

    <?php foreach ($errors as $err): ?><div class="error-box"><?= errEsc($err) ?></div><?php endforeach; ?>

    <form method="post">
        <?php if ($isEdit): ?>
            <input type="hidden" name="center_id" value="<?= (int) ($editing['center_id'] ?? $_POST['center_id'] ?? 0) ?>">
        <?php endif; ?>

        <label>Center Name</label>
        <input type="text" name="center_name" maxlength="100" value="<?= errEsc($f['center_name']) ?>" placeholder="e.g. Bayombong Central School Gym">

        <label>Location</label>
        <input type="text" name="location" maxlength="150" value="<?= errEsc($f['location']) ?>" placeholder="Specific address or landmark">

        <label>Barangay</label>
        <input type="text" name="barangay" maxlength="100" value="<?= errEsc($f['barangay']) ?>">

        <label>Contact Person</label>
        <input type="text" name="contact_person" maxlength="100" value="<?= errEsc($f['contact_person']) ?>">

        <label>Contact Number</label>
        <input type="text" name="contact_number" maxlength="20" value="<?= errEsc($f['contact_number']) ?>" placeholder="e.g. 09171234567">

        <label>Capacity (max number of evacuees)</label>
        <input type="number" name="capacity" min="1" max="100000" value="<?= errEsc($f['capacity']) ?>">

        <label>Facilities</label>
        <input type="text" name="facilities" maxlength="255" value="<?= errEsc($f['facilities']) ?>" placeholder="e.g. Water, toilets, medical area">

        <button type="submit"><?= $isEdit ? 'Save Changes' : 'Register Center' ?></button>
    </form>
</main>

<?php include 'includes/footer.php'; ?>
</body>
</html>
