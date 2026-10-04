<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';

$currentUserId = $_SESSION['user_id'] ?? 1; // placeholder until Lab 37 (login) exists

// Only show active disaster types in the dropdown
$types = dbSearch($conn, 'disaster_types', ['status'], 'Active');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Basic validation (Lab 48 will expand on this project-wide)
    if (empty($_POST['disaster_name'])) $errors[] = 'Disaster name is required.';
    if (empty($_POST['disaster_type_id'])) $errors[] = 'Disaster type is required.';
    if (empty($_POST['area_affected'])) $errors[] = 'Area affected is required.';
    if (empty($_POST['severity'])) $errors[] = 'Severity is required.';

    if (empty($errors)) {
        $data = [
            'disaster_name'    => $_POST['disaster_name'],
            'disaster_type_id' => $_POST['disaster_type_id'],
            'area_affected'    => $_POST['area_affected'],
            'severity'         => $_POST['severity'],
            'status'           => 'Reported', // always starts at the first step of the lifecycle
            'date_started'     => $_POST['date_started'] ?: null,
            'date_reported'    => date('Y-m-d H:i:s'),
        ];
        $newId = dbInsert($conn, 'disasters', $data);

        // Auto-generate a human-readable disaster number now that we have the real ID
        $disasterNumber = 'DST-' . date('Y') . '-' . str_pad($newId, 4, '0', STR_PAD_LEFT);
        dbUpdate($conn, 'disasters', 'disaster_id', $newId, ['disaster_number' => $disasterNumber]);

        logAudit($conn, $currentUserId, 'Registered new disaster', 'disasters', null, $disasterNumber);
        header('Location: disaster_monitor.php?registered=' . urlencode($disasterNumber));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register Disaster</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<main>
    <h1>Register a Disaster</h1>

    <?php if ($errors): ?>
        <div class="error-box">
            <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <label>Disaster Name</label>
        <input type="text" name="disaster_name" placeholder="e.g. Typhoon Egay" value="<?= htmlspecialchars($_POST['disaster_name'] ?? '') ?>" required>

        <label>Disaster Type</label>
        <select name="disaster_type_id" required>
            <option value="">-- Select type --</option>
            <?php foreach ($types as $t): ?>
                <option value="<?= $t['disaster_type_id'] ?>"><?= htmlspecialchars($t['type_name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label>Area Affected (barangay)</label>
        <input type="text" name="area_affected" placeholder="e.g. Barangay Bonfal Proper" value="<?= htmlspecialchars($_POST['area_affected'] ?? '') ?>" required>

        <label>Severity</label>
        <select name="severity" required>
            <option value="">-- Select severity --</option>
            <option>Low</option>
            <option>Moderate</option>
            <option>Severe</option>
            <option>Catastrophic</option>
        </select>

        <label>Date Started</label>
        <input type="datetime-local" name="date_started">

        <button type="submit">Register Disaster</button>
    </form>
</main>

<?php include 'includes/footer.php'; ?>
</body>
</html>
