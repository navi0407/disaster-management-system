<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/evacuation_helpers.php';

$currentUserId = $_SESSION['user_id'] ?? 1; // placeholder until Lab 37 (login) exists

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $disasterId = (int) ($_POST['disaster_id'] ?? 0);
    $residentId = (int) ($_POST['resident_id'] ?? 0);
    $centerId   = (int) ($_POST['center_id'] ?? 0);

    if (!$disasterId) $errors[] = 'Please select a disaster.';
    if (!$residentId) $errors[] = 'Please select a resident.';
    if (!$centerId)   $errors[] = 'Please select an evacuation center.';

    if (empty($errors)) {
        try {
            mysqli_begin_transaction($conn);

            // Lock the center row so two check-ins at the same moment can't both take the last spot
            $lock = mysqli_prepare($conn, "SELECT * FROM evacuation_centers WHERE center_id = ? FOR UPDATE");
            mysqli_stmt_bind_param($lock, 'i', $centerId);
            mysqli_stmt_execute($lock);
            $center = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            mysqli_stmt_close($lock);

            $disaster = dbGetById($conn, 'disasters', 'disaster_id', $disasterId);
            $resident = dbGetById($conn, 'residents', 'resident_id', $residentId);

            // Lab 15: assignment rules
            if (!$disaster || $disaster['status'] === 'Closed') {
                $errors[] = 'That disaster is closed or does not exist.';
            }
            if (!$resident || $resident['status'] !== 'Active') {
                $errors[] = 'That resident is inactive or does not exist.';
            }
            if (!$center || $center['status'] !== 'Active') {
                $errors[] = 'That evacuation center is not active.';
            } elseif (countCheckedIn($conn, $centerId) >= (int) $center['capacity']) {
                $errors[] = 'That evacuation center is already full.';
            }
            if ($resident) {
                $stay = getActiveStay($conn, $residentId);
                if ($stay) {
                    $errors[] = 'This resident is already checked in at ' . $stay['center_name'] . '. Check them out first.';
                }
            }

            if ($errors) {
                mysqli_rollback($conn);
            } else {
                $evacueeId = dbInsert($conn, 'evacuees', [
                    'disaster_id'     => $disasterId,
                    'resident_id'     => $residentId,
                    'household_id'    => $resident['household_id'],
                    'center_id'       => $centerId,
                    'special_needs'   => $_POST['special_needs'] !== '' ? $_POST['special_needs'] : null,
                    'date_checked_in' => date('Y-m-d H:i:s'),
                    'status'          => 'Checked-in',
                ]);
                syncCenterOccupancy($conn, $centerId);
                logAudit($conn, $currentUserId, 'Checked in evacuee', 'evacuees', null,
                    $resident['first_name'] . ' ' . $resident['last_name'] . ' at ' . $center['center_name'],
                    'EVC-' . $evacueeId);
                mysqli_commit($conn);
                header('Location: evacuee_monitor.php?checked_in=' . $evacueeId);
                exit;
            }
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $errors[] = 'Something went wrong while saving. Nothing was changed, please try again.';
        }
    }
}

// Dropdown data
$disasters = dbQueryAll($conn, "SELECT * FROM disasters WHERE status != 'Closed' ORDER BY date_reported DESC");
$residents = dbQueryAll($conn,
    "SELECT r.*, h.barangay AS household_barangay FROM residents r
     LEFT JOIN households h ON r.household_id = h.household_id
     WHERE r.status = 'Active'
       AND r.resident_id NOT IN (SELECT resident_id FROM evacuees WHERE status = 'Checked-in')
     ORDER BY r.last_name, r.first_name");
$centers = dbQueryAll($conn, "SELECT * FROM evacuation_centers WHERE status = 'Active' ORDER BY center_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register Evacuee</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<main>
    <h1>Register an Evacuee (Check In)</h1>

    <?php if ($errors): ?>
        <div class="error-box">
            <?php foreach ($errors as $e): ?><p><?= htmlspecialchars($e) ?></p><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (empty($disasters)): ?>
        <div class="error-box"><p>There is no open disaster. <a href="disaster_register.php">Register a disaster</a> first.</p></div>
    <?php elseif (empty($centers)): ?>
        <div class="error-box"><p>There is no active evacuation center. <a href="evacuation_center_register.php">Register a center</a> first.</p></div>
    <?php elseif (empty($residents)): ?>
        <div class="error-box"><p>There are no residents available to check in. Either none are registered, or everyone is already checked in. <a href="resident_register.php">Register a resident</a>.</p></div>
    <?php else: ?>
    <form method="post">
        <label>Disaster</label>
        <select name="disaster_id" required>
            <option value="">-- Select disaster --</option>
            <?php foreach ($disasters as $d): ?>
                <option value="<?= $d['disaster_id'] ?>" <?= ($_POST['disaster_id'] ?? '') == $d['disaster_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['disaster_number'] . ' - ' . $d['disaster_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Resident</label>
        <select name="resident_id" required>
            <option value="">-- Select resident --</option>
            <?php foreach ($residents as $r): ?>
                <option value="<?= $r['resident_id'] ?>" <?= ($_POST['resident_id'] ?? '') == $r['resident_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($r['last_name'] . ', ' . $r['first_name']) ?>
                    (Household #<?= $r['household_id'] ?>, <?= htmlspecialchars($r['barangay'] ?: $r['household_barangay']) ?>)
                    <?= $r['vulnerability_type'] !== 'None' ? ' - ' . htmlspecialchars($r['vulnerability_type']) : '' ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label>Evacuation Center</label>
        <select name="center_id" required>
            <option value="">-- Select center --</option>
            <?php foreach ($centers as $c): ?>
                <?php $available = (int) $c['capacity'] - (int) $c['current_occupancy']; ?>
                <option value="<?= $c['center_id'] ?>" <?= $available <= 0 ? 'disabled' : '' ?>
                        <?= ($_POST['center_id'] ?? '') == $c['center_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($c['center_name']) ?>
                    (<?= $available > 0 ? $available . ' of ' . (int) $c['capacity'] . ' spots left' : 'FULL' ?>)
                </option>
            <?php endforeach; ?>
        </select>

        <label>Special Needs (optional)</label>
        <input type="text" name="special_needs" value="<?= htmlspecialchars($_POST['special_needs'] ?? '') ?>"
               placeholder="e.g. Needs maintenance medicine, uses a wheelchair">

        <button type="submit">Check In Evacuee</button>
    </form>
    <?php endif; ?>
</main>

<?php include 'includes/footer.php'; ?>
</body>
</html>
