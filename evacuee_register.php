<?php
require_once 'config/db.php';
require_once 'includes/validate.php';
require_once 'includes/rules.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/evacuation_helpers.php';

$currentUserId = $_SESSION['user_id'] ?? 1;

// Only some roles may put a center over its capacity (see authCapabilities in includes/auth.php)
$canOverride = authCan('override_capacity');

$f = [
    'disaster_id'   => trim($_POST['disaster_id'] ?? ''),
    'resident_id'   => trim($_POST['resident_id'] ?? ''),
    'center_id'     => trim($_POST['center_id'] ?? ''),
    'special_needs' => trim($_POST['special_needs'] ?? ''),
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $disasterId = (int) $f['disaster_id'];
    $residentId = (int) $f['resident_id'];
    $centerId   = (int) $f['center_id'];
    $override   = $canOverride && !empty($_POST['override_capacity']);

    // Lab 48: shared checks. Lab 49 rule 4: the evacuee must reference a valid disaster.
    $errors = vCollect([
        vExists($conn, 'disaster', 'disasters', 'disaster_id', $disasterId),
        vExists($conn, 'resident', 'residents', 'resident_id', $residentId),
        vExists($conn, 'evacuation center', 'evacuation_centers', 'center_id', $centerId),
        vMax('Special needs', $f['special_needs'], 255),
    ]);

    if (!$errors) {
        try {
            mysqli_begin_transaction($conn);

            // Lock the center row so two check-ins at the same moment can't both take the last spot
            $lock = mysqli_prepare($conn, "SELECT * FROM evacuation_centers WHERE center_id = ? FOR UPDATE");
            mysqli_stmt_bind_param($lock, 'i', $centerId);
            mysqli_stmt_execute($lock);
            $center = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            mysqli_stmt_close($lock);

            $resident = dbGetById($conn, 'residents', 'resident_id', $residentId);

            // Lab 49 rules, each one from includes/rules.php
            $errors = vCollect([
                ruleDisasterOpen($conn, $disasterId),                       // rule 12: closed disasters are locked
                ($resident && $resident['status'] === 'Active') ? '' : 'That resident is inactive or does not exist.',
                ($center && $center['status'] === 'Active') ? '' : 'That evacuation center is not active.',
                ruleResidentNotCheckedIn($conn, $residentId),               // rule 3: one place at a time
                ruleCenterHasRoom($conn, $centerId, 1, $override),          // rule 5: capacity, unless authorized
            ]);
            // A closed disaster is also hidden from ordinary check-in, even for an administrator
            $dis = dbGetById($conn, 'disasters', 'disaster_id', $disasterId);
            if ($dis && $dis['status'] === 'Closed' && !$errors) $errors[] = 'That disaster is closed. Records under it cannot be added.';

            if ($errors) {
                mysqli_rollback($conn);
            } else {
                $evacueeId = dbInsert($conn, 'evacuees', [
                    'disaster_id'     => $disasterId,
                    'resident_id'     => $residentId,
                    'household_id'    => $resident['household_id'],
                    'center_id'       => $centerId,
                    'special_needs'   => $f['special_needs'] !== '' ? $f['special_needs'] : null,
                    'date_checked_in' => date('Y-m-d H:i:s'),
                    'status'          => 'Checked-in',
                ]);
                $wasOver = countCheckedIn($conn, $centerId) > (int) $center['capacity'];
                syncCenterOccupancy($conn, $centerId);
                logAudit(
                    $conn,
                    $currentUserId,
                    $wasOver ? 'Checked in evacuee OVER CAPACITY (authorized)' : 'Checked in evacuee',
                    'evacuees',
                    null,
                    $resident['first_name'] . ' ' . $resident['last_name'] . ' at ' . $center['center_name'],
                    'EVC-' . $evacueeId
                );
                mysqli_commit($conn);
                header('Location: evacuee_monitor.php?checked_in=' . $evacueeId);
                exit;
            }
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $errors = [errMessage($e)];
        }
    }
}

// Dropdown data
$disasters = dbQueryAll($conn, "SELECT * FROM disasters WHERE status != 'Closed' ORDER BY date_reported DESC");
$residents = dbQueryAll(
    $conn,
    "SELECT r.*, h.barangay AS household_barangay FROM residents r
     LEFT JOIN households h ON r.household_id = h.household_id
     WHERE r.status = 'Active'
       AND r.resident_id NOT IN (SELECT resident_id FROM evacuees WHERE status = 'Checked-in')
     ORDER BY r.last_name, r.first_name"
);
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

        <?php foreach ($errors as $err): ?><div class="error-box"><?= errEsc($err) ?></div><?php endforeach; ?>

        <?php if (empty($disasters)): ?>
            <div class="error-box">There is no open disaster. <a href="disaster_register.php">Register a disaster</a> first.</div>
        <?php elseif (empty($centers)): ?>
            <div class="error-box">There is no active evacuation center. <a href="evacuation_center_register.php">Register a center</a> first.</div>
        <?php elseif (empty($residents)): ?>
            <div class="error-box">There are no residents available to check in. Either none are registered, or everyone is already checked in. <a href="resident_register.php">Register a resident</a>.</div>
        <?php else: ?>
            <form method="post">
                <label>Disaster</label>
                <select name="disaster_id">
                    <option value="">-- Select disaster --</option>
                    <?php foreach ($disasters as $d): ?>
                        <option value="<?= (int) $d['disaster_id'] ?>" <?= $f['disaster_id'] === (string) $d['disaster_id'] ? 'selected' : '' ?>>
                            <?= errEsc($d['disaster_number'] . ' - ' . $d['disaster_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Resident</label>
                <select name="resident_id">
                    <option value="">-- Select resident --</option>
                    <?php foreach ($residents as $r): ?>
                        <option value="<?= (int) $r['resident_id'] ?>" <?= $f['resident_id'] === (string) $r['resident_id'] ? 'selected' : '' ?>>
                            <?= errEsc($r['last_name'] . ', ' . $r['first_name']) ?>
                            (Household #<?= (int) $r['household_id'] ?>, <?= errEsc($r['barangay'] ?: $r['household_barangay']) ?>)
                            <?= $r['vulnerability_type'] !== 'None' ? ' - ' . errEsc($r['vulnerability_type']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label>Evacuation Center</label>
                <select name="center_id">
                    <option value="">-- Select center --</option>
                    <?php foreach ($centers as $c): ?>
                        <?php $available = (int) $c['capacity'] - (int) $c['current_occupancy']; ?>
                        <option value="<?= (int) $c['center_id'] ?>" <?= ($available <= 0 && !$canOverride) ? 'disabled' : '' ?>
                            <?= $f['center_id'] === (string) $c['center_id'] ? 'selected' : '' ?>>
                            <?= errEsc($c['center_name']) ?>
                            (<?= $available > 0 ? $available . ' of ' . (int) $c['capacity'] . ' spots left' : 'FULL' ?>)
                        </option>
                    <?php endforeach; ?>
                </select>

                <?php if ($canOverride): ?>
                    <label><input type="checkbox" name="override_capacity" value="1" <?= !empty($_POST['override_capacity']) ? 'checked' : '' ?>>
                        Allow going over capacity (authorized users only, this is recorded in the audit trail)</label>
                <?php endif; ?>

                <label>Special Needs (optional)</label>
                <input type="text" name="special_needs" maxlength="255" value="<?= errEsc($f['special_needs']) ?>"
                    placeholder="e.g. Needs maintenance medicine, uses a wheelchair">

                <button type="submit">Check In Evacuee</button>
            </form>
        <?php endif; ?>
    </main>

    <?php include 'includes/footer.php'; ?>
</body>

</html>