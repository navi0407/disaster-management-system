<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';

$currentUserId = $_SESSION['user_id'] ?? 1; // placeholder until Lab 37 (login) exists

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$editing = $editId ? dbGetById($conn, 'evacuation_centers', 'center_id', $editId) : null;

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['center_name'])) $errors[] = 'Center name is required.';
    if (empty($_POST['location'])) $errors[] = 'Location is required.';
    if (empty($_POST['barangay'])) $errors[] = 'Barangay is required.';
    if (!isset($_POST['capacity']) || (int) $_POST['capacity'] < 1) $errors[] = 'Capacity must be at least 1.';

    // Business rule: capacity can't be set lower than the people already inside
    if (!empty($_POST['center_id'])) {
        $existing = dbGetById($conn, 'evacuation_centers', 'center_id', (int) $_POST['center_id']);
        if ($existing && (int) $_POST['capacity'] < (int) $existing['current_occupancy']) {
            $errors[] = 'Capacity cannot be lower than the current occupancy (' . (int) $existing['current_occupancy'] . ').';
        }
    }

    if (empty($errors)) {
        $data = [
            'center_name'    => $_POST['center_name'],
            'location'       => $_POST['location'],
            'barangay'       => $_POST['barangay'],
            'contact_person' => $_POST['contact_person'],
            'contact_number' => $_POST['contact_number'],
            'capacity'       => (int) $_POST['capacity'],
            'facilities'     => $_POST['facilities'],
        ];

        if (!empty($_POST['center_id'])) {
            $centerId = (int) $_POST['center_id'];
            dbUpdate($conn, 'evacuation_centers', 'center_id', $centerId, $data);
            logAudit($conn, $currentUserId, 'Updated evacuation center', 'evacuation_centers', null, $_POST['center_name']);
            header('Location: evacuation_center_monitor.php?updated=' . $centerId);
        } else {
            $data['current_occupancy'] = 0; // nobody has checked in yet
            $data['status'] = 'Active';
            $newId = dbInsert($conn, 'evacuation_centers', $data);
            logAudit($conn, $currentUserId, 'Registered evacuation center', 'evacuation_centers', null, $_POST['center_name']);
            header('Location: evacuation_center_monitor.php?registered=' . $newId);
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
    <title><?= $editing ? 'Edit Evacuation Center' : 'Register Evacuation Center' ?></title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<main>
    <h1><?= $editing ? 'Edit Evacuation Center' : 'Register an Evacuation Center' ?></h1>

    <?php if ($errors): ?>
        <div class="error-box">
            <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <?php if ($editing): ?>
            <input type="hidden" name="center_id" value="<?= $editing['center_id'] ?>">
        <?php endif; ?>

        <label>Center Name</label>
        <input type="text" name="center_name" value="<?= $f('center_name') ?>" placeholder="e.g. Bayombong Central School Gym" required>

        <label>Location</label>
        <input type="text" name="location" value="<?= $f('location') ?>" placeholder="Specific address or landmark" required>

        <label>Barangay</label>
        <input type="text" name="barangay" value="<?= $f('barangay') ?>" required>

        <label>Contact Person</label>
        <input type="text" name="contact_person" value="<?= $f('contact_person') ?>">

        <label>Contact Number</label>
        <input type="text" name="contact_number" value="<?= $f('contact_number') ?>">

        <label>Capacity (max number of evacuees)</label>
        <input type="number" name="capacity" min="1" value="<?= $f('capacity') ?>" required>

        <label>Facilities</label>
        <input type="text" name="facilities" value="<?= $f('facilities') ?>" placeholder="e.g. Water, toilets, medical area">

        <button type="submit"><?= $editing ? 'Save Changes' : 'Register Center' ?></button>
    </form>
</main>

<?php include 'includes/footer.php'; ?>
</body>
</html>
