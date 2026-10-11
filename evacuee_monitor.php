<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/evacuation_helpers.php';

$currentUserId = $_SESSION['user_id'] ?? 1; // placeholder until Lab 37 (login) exists

// Lab 16: check out an evacuee
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout_id'])) {
    $id = (int) $_POST['checkout_id'];
    try {
        mysqli_begin_transaction($conn);
        $stay = dbGetById($conn, 'evacuees', 'evacuee_id', $id);
        if ($stay && $stay['status'] === 'Checked-in') {
            dbUpdate($conn, 'evacuees', 'evacuee_id', $id, [
                'status'           => 'Checked-out',
                'date_checked_out' => date('Y-m-d H:i:s'),
            ]);
            syncCenterOccupancy($conn, (int) $stay['center_id']);
            logAudit($conn, $currentUserId, 'Checked out evacuee', 'evacuees', 'Checked-in', 'Checked-out', 'EVC-' . $id);
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
    }
    header('Location: evacuee_monitor.php?checked_out=1');
    exit;
}

// Filters
$query = $_GET['q'] ?? '';
$disasterFilter = $_GET['disaster_id'] ?? '';
$centerFilter = $_GET['center_id'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$vulnFilter = $_GET['vulnerable'] ?? '';

$sql = "SELECT e.*, r.first_name, r.last_name, r.vulnerability_type,
               c.center_name, d.disaster_number, d.disaster_name
        FROM evacuees e
        JOIN residents r ON e.resident_id = r.resident_id
        LEFT JOIN evacuation_centers c ON e.center_id = c.center_id
        LEFT JOIN disasters d ON e.disaster_id = d.disaster_id
        WHERE 1=1";
$params = [];
$types = '';

if ($query !== '') {
    $sql .= " AND (r.first_name LIKE ? OR r.last_name LIKE ?)";
    $like = "%$query%";
    $params = array_merge($params, [$like, $like]);
    $types .= 'ss';
}
if ($disasterFilter !== '') {
    $sql .= " AND e.disaster_id = ?";
    $params[] = (int) $disasterFilter;
    $types .= 'i';
}
if ($centerFilter !== '') {
    $sql .= " AND e.center_id = ?";
    $params[] = (int) $centerFilter;
    $types .= 'i';
}
if ($statusFilter !== '') {
    $sql .= " AND e.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}
if ($vulnFilter === '1') {
    $sql .= " AND r.vulnerability_type != 'None'";
}
$sql .= " ORDER BY (e.status = 'Checked-in') DESC, e.date_checked_in DESC";
$evacuees = dbQueryAll($conn, $sql, $types, $params);

// Lab 17: overall totals (follow the disaster filter, if one is picked)
$totalsSql = "SELECT COALESCE(SUM(e.status = 'Checked-in'), 0) AS sheltered,
                     COUNT(DISTINCT CASE WHEN e.status = 'Checked-in' THEN e.household_id END) AS households,
                     COALESCE(SUM(e.status = 'Checked-in' AND r.vulnerability_type != 'None'), 0) AS vulnerable,
                     COALESCE(SUM(e.status = 'Checked-out'), 0) AS checked_out
              FROM evacuees e
              JOIN residents r ON e.resident_id = r.resident_id";
$totalsParams = [];
$totalsTypes = '';
if ($disasterFilter !== '') {
    $totalsSql .= " WHERE e.disaster_id = ?";
    $totalsParams[] = (int) $disasterFilter;
    $totalsTypes .= 'i';
}
$totals = dbQueryAll($conn, $totalsSql, $totalsTypes, $totalsParams)[0];

// Lab 17: totals and remaining space per center
$centerSql = "SELECT c.center_id, c.center_name, c.capacity,
                     COUNT(e.evacuee_id) AS occupied,
                     COUNT(DISTINCT e.household_id) AS households,
                     COALESCE(SUM(r.vulnerability_type != 'None'), 0) AS vulnerable
              FROM evacuation_centers c
              LEFT JOIN evacuees e ON e.center_id = c.center_id AND e.status = 'Checked-in'";
$centerParams = [];
$centerTypes = '';
if ($disasterFilter !== '') {
    $centerSql .= " AND e.disaster_id = ?";
    $centerParams[] = (int) $disasterFilter;
    $centerTypes .= 'i';
}
$centerSql .= " LEFT JOIN residents r ON r.resident_id = e.resident_id
                GROUP BY c.center_id, c.center_name, c.capacity
                ORDER BY c.center_name";
$centerSummary = dbQueryAll($conn, $centerSql, $centerTypes, $centerParams);

$allDisasters = dbQueryAll($conn, "SELECT disaster_id, disaster_number, disaster_name FROM disasters ORDER BY date_reported DESC");
$allCenters = dbQueryAll($conn, "SELECT center_id, center_name FROM evacuation_centers ORDER BY center_name");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Evacuee Monitoring</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<main>
    <h1>Evacuee Monitoring</h1>

    <?php if (isset($_GET['checked_in'])): ?>
        <div class="success-box">Evacuee checked in successfully.</div>
    <?php endif; ?>
    <?php if (isset($_GET['checked_out'])): ?>
        <div class="success-box">Evacuee checked out successfully.</div>
    <?php endif; ?>

    <p><a href="evacuee_register.php">+ Check in an evacuee</a> &nbsp;|&nbsp; <a href="evacuation_center_monitor.php">View evacuation centers</a></p>

    <p>
        Currently sheltered: <strong><?= (int) $totals['sheltered'] ?></strong> &nbsp;|&nbsp;
        From <strong><?= (int) $totals['households'] ?></strong> household(s) &nbsp;|&nbsp;
        Vulnerable: <strong><?= (int) $totals['vulnerable'] ?></strong> &nbsp;|&nbsp;
        Already checked out: <strong><?= (int) $totals['checked_out'] ?></strong>
    </p>

    <h2>Per Evacuation Center</h2>
    <table class="data-table">
        <thead>
            <tr><th>Center</th><th>Sheltered</th><th>Households</th><th>Vulnerable</th><th>Capacity</th><th>Space Left</th></tr>
        </thead>
        <tbody>
        <?php if (empty($centerSummary)): ?>
            <tr><td colspan="6">No evacuation centers yet.</td></tr>
        <?php endif; ?>
        <?php foreach ($centerSummary as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['center_name']) ?></td>
                <td><?= (int) $c['occupied'] ?></td>
                <td><?= (int) $c['households'] ?></td>
                <td><?= (int) $c['vulnerable'] ?></td>
                <td><?= (int) $c['capacity'] ?></td>
                <td><?= max(0, (int) $c['capacity'] - (int) $c['occupied']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Evacuee Records</h2>
    <form method="get" class="filter-bar">
        <input type="text" name="q" placeholder="Search resident name..." value="<?= htmlspecialchars($query) ?>">
        <select name="disaster_id">
            <option value="">All Disasters</option>
            <?php foreach ($allDisasters as $d): ?>
                <option value="<?= $d['disaster_id'] ?>" <?= $disasterFilter == $d['disaster_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['disaster_number'] . ' - ' . $d['disaster_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="center_id">
            <option value="">All Centers</option>
            <?php foreach ($allCenters as $c): ?>
                <option value="<?= $c['center_id'] ?>" <?= $centerFilter == $c['center_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['center_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">All Statuses</option>
            <?php foreach (['Checked-in', 'Checked-out'] as $s): ?>
                <option <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <select name="vulnerable">
            <option value="">Everyone</option>
            <option value="1" <?= $vulnFilter === '1' ? 'selected' : '' ?>>Vulnerable only</option>
        </select>
        <button type="submit">Filter</button>
    </form>

    <table class="data-table">
        <thead>
            <tr><th>Resident</th><th>Vulnerability</th><th>Disaster</th><th>Center</th><th>Special Needs</th><th>Checked In</th><th>Checked Out</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php if (empty($evacuees)): ?>
            <tr><td colspan="9">No records found.</td></tr>
        <?php endif; ?>
        <?php foreach ($evacuees as $e): ?>
            <tr>
                <td><?= htmlspecialchars($e['first_name'] . ' ' . $e['last_name']) ?></td>
                <td><?= htmlspecialchars($e['vulnerability_type']) ?></td>
                <td><?= htmlspecialchars($e['disaster_number'] ?? '') ?></td>
                <td><?= htmlspecialchars($e['center_name'] ?? '') ?></td>
                <td><?= htmlspecialchars($e['special_needs'] ?? '') ?></td>
                <td><?= htmlspecialchars($e['date_checked_in']) ?></td>
                <td><?= htmlspecialchars($e['date_checked_out'] ?? '') ?></td>
                <td class="status-<?= strtolower($e['status']) ?>"><?= htmlspecialchars($e['status']) ?></td>
                <td>
                    <?php if ($e['status'] === 'Checked-in'): ?>
                        <form method="post" class="inline-form" onsubmit="return confirm('Check this evacuee out?');">
                            <input type="hidden" name="checkout_id" value="<?= $e['evacuee_id'] ?>">
                            <button type="submit">Check Out</button>
                        </form>
                    <?php else: ?>
                        &mdash;
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>

<?php include 'includes/footer.php'; ?>
</body>
</html>
