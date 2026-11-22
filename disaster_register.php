<?php
require_once 'config/db.php';
require_once 'includes/validate.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/evacuation_helpers.php';

$currentUserId = $_SESSION['user_id'] ?? 1;

// Only active disaster types can be picked
$types = dbSearch($conn, 'disaster_types', ['status'], 'Active');
$typeIds = array_map(fn($t) => (int) $t['disaster_type_id'], $types);
$severities = ['Low', 'Moderate', 'Severe', 'Catastrophic'];

// What is shown in the form (kept after a failed save so the user does not retype everything)
$f = [
    'disaster_name'    => trim($_POST['disaster_name'] ?? ''),
    'disaster_type_id' => trim($_POST['disaster_type_id'] ?? ''),
    'area_affected'    => trim($_POST['area_affected'] ?? ''),
    'severity'         => trim($_POST['severity'] ?? ''),
    'date_started'     => trim($_POST['date_started'] ?? ''),
];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Lab 48: shared checks. Lab 49 rule 1: every disaster needs a valid disaster type.
    $errors = vCollect([
        vRequired('Disaster name', $f['disaster_name']) ?: vMax('Disaster name', $f['disaster_name'], 100),
        vRequired('Disaster type', $f['disaster_type_id'])
            ?: (in_array((int) $f['disaster_type_id'], $typeIds, true) ? '' : 'Please choose an active disaster type.'),
        vRequired('Area affected', $f['area_affected']) ?: vMax('Area affected', $f['area_affected'], 100),
        vRequired('Severity', $f['severity']) ?: vOneOf('Severity', $f['severity'], $severities),
        vDateTime('Date started', $f['date_started']) ?: vNotFuture('Date started', $f['date_started']),
    ]);

    // Lab 50: duplicate records. The same name in the same area that is still open is almost surely a double entry.
    if (!$errors) {
        $dup = dbQueryAll($conn,
            "SELECT disaster_number FROM disasters WHERE LOWER(disaster_name) = LOWER(?) AND LOWER(area_affected) = LOWER(?)
             AND status <> 'Closed' LIMIT 1", 'ss', [$f['disaster_name'], $f['area_affected']]);
        if ($dup) $errors[] = 'This disaster is already registered as ' . $dup[0]['disaster_number'] . ' and is still open.';
    }

    if (!$errors) {
        try {
            mysqli_begin_transaction($conn);
            $newId = dbInsert($conn, 'disasters', [
                'disaster_name'    => $f['disaster_name'],
                'disaster_type_id' => (int) $f['disaster_type_id'],
                'area_affected'    => $f['area_affected'],
                'severity'         => $f['severity'],
                'status'           => 'Reported', // always starts at the first step of the lifecycle
                'date_started'     => $f['date_started'] !== '' ? str_replace('T', ' ', $f['date_started']) : null,
                'date_reported'    => date('Y-m-d H:i:s'),
            ]);
            // Human-readable disaster number built from the real id (the database also keeps it unique)
            $disasterNumber = 'DST-' . date('Y') . '-' . str_pad($newId, 4, '0', STR_PAD_LEFT);
            dbUpdate($conn, 'disasters', 'disaster_id', $newId, ['disaster_number' => $disasterNumber]);
            logAudit($conn, $currentUserId, 'Registered new disaster', 'disasters', null, $disasterNumber);
            mysqli_commit($conn);
            header('Location: disaster_monitor.php?registered=' . urlencode($disasterNumber));
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
    <title>Register Disaster</title>
    <link rel="stylesheet" href="<?= errEsc(authCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>

<main>
    <h1>Register a Disaster</h1>

    <?php foreach ($errors as $err): ?><div class="error-box"><?= errEsc($err) ?></div><?php endforeach; ?>

    <?php if (!$types): ?>
        <div class="error-box">There is no active disaster type. <a href="disaster_types.php">Add or activate one first.</a></div>
    <?php endif; ?>

    <form method="post">
        <label>Disaster Name</label>
        <input type="text" name="disaster_name" maxlength="100" placeholder="e.g. Typhoon Egay" value="<?= errEsc($f['disaster_name']) ?>">

        <label>Disaster Type</label>
        <select name="disaster_type_id">
            <option value="">-- Select type --</option>
            <?php foreach ($types as $t): ?>
                <option value="<?= (int) $t['disaster_type_id'] ?>" <?= $f['disaster_type_id'] === (string) $t['disaster_type_id'] ? 'selected' : '' ?>><?= errEsc($t['type_name']) ?></option>
            <?php endforeach; ?>
        </select>

        <label>Area Affected (barangay)</label>
        <input type="text" name="area_affected" maxlength="100" placeholder="e.g. Barangay Bonfal Proper" value="<?= errEsc($f['area_affected']) ?>">

        <label>Severity</label>
        <select name="severity">
            <option value="">-- Select severity --</option>
            <?php foreach ($severities as $s): ?>
                <option <?= $f['severity'] === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>

        <label>Date Started (optional)</label>
        <input type="datetime-local" name="date_started" max="<?= date('Y-m-d\TH:i') ?>" value="<?= errEsc($f['date_started']) ?>">

        <button type="submit">Register Disaster</button>
    </form>
</main>

<?php include 'includes/footer.php'; ?>
</body>
</html>
