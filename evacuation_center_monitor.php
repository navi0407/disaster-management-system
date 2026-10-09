<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';

$currentUserId = $_SESSION['user_id'] ?? 1; // placeholder until Lab 37 (login) exists

// Toggle Active/Inactive
if (isset($_GET['toggle'])) {
    $id = (int) $_GET['toggle'];
    $current = dbGetById($conn, 'evacuation_centers', 'center_id', $id);
    if ($current) {
        $newStatus = $current['status'] === 'Active' ? 'Inactive' : 'Active';
        dbUpdate($conn, 'evacuation_centers', 'center_id', $id, ['status' => $newStatus]);
        logAudit($conn, $currentUserId, 'Changed evacuation center status', 'evacuation_centers', $current['status'], $newStatus, $current['center_name']);
    }
    header('Location: evacuation_center_monitor.php');
    exit;
}

$query = $_GET['q'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$capacityFilter = $_GET['capacity_state'] ?? '';

$sql = "SELECT * FROM evacuation_centers WHERE 1=1";
$params = [];
$types = '';

if ($query !== '') {
    $sql .= " AND (center_name LIKE ? OR barangay LIKE ? OR location LIKE ?)";
    $like = "%$query%";
    $params = array_merge($params, [$like, $like, $like]);
    $types .= 'sss';
}
if ($statusFilter !== '') {
    $sql .= " AND status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}
$sql .= " ORDER BY center_name";

$stmt = mysqli_prepare($conn, $sql);
if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$centers = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

// Work out available capacity, occupancy %, and a capacity state for each center
foreach ($centers as &$c) {
    $capacity = (int) $c['capacity'];
    $occupied = (int) $c['current_occupancy'];
    $c['available'] = max(0, $capacity - $occupied);
    $c['percent'] = $capacity > 0 ? round(($occupied / $capacity) * 100) : 0;
    if ($c['percent'] >= 100) {
        $c['state'] = 'Full';
    } elseif ($c['percent'] >= 70) {
        $c['state'] = 'Nearly Full';
    } else {
        $c['state'] = 'Available';
    }
}
unset($c);

// Capacity-state filter is applied after the math, since the state is calculated, not stored
if ($capacityFilter !== '') {
    $centers = array_values(array_filter($centers, fn($c) => $c['state'] === $capacityFilter));
}

$totalCapacity = array_sum(array_column($centers, 'capacity'));
$totalOccupied = array_sum(array_column($centers, 'current_occupancy'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Evacuation Center Monitoring</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<main>
    <h1>Evacuation Center Monitoring</h1>

    <?php if (isset($_GET['registered'])): ?>
        <div class="success-box">Evacuation center registered successfully.</div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
        <div class="success-box">Evacuation center updated successfully.</div>
    <?php endif; ?>

    <p><a href="evacuation_center_register.php">+ Register a new evacuation center</a></p>

    <form method="get" class="filter-bar">
        <input type="text" name="q" placeholder="Search name, barangay, or location..." value="<?= htmlspecialchars($query) ?>">
        <select name="status">
            <option value="">All Statuses</option>
            <?php foreach (['Active', 'Inactive'] as $s): ?>
                <option <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <select name="capacity_state">
            <option value="">All Capacity Levels</option>
            <?php foreach (['Available', 'Nearly Full', 'Full'] as $s): ?>
                <option <?= $capacityFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Filter</button>
    </form>

    <p>Showing <strong><?= count($centers) ?></strong> center(s) &nbsp;|&nbsp;
       Total capacity: <strong><?= $totalCapacity ?></strong> &nbsp;|&nbsp;
       Currently sheltered: <strong><?= $totalOccupied ?></strong></p>

    <table class="data-table">
        <thead>
            <tr>
                <th>Center</th><th>Barangay</th><th>Capacity</th><th>Occupied</th>
                <th>Available</th><th>Occupancy</th><th>Capacity Level</th><th>Status</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($centers)): ?>
            <tr><td colspan="9">No records found.</td></tr>
        <?php endif; ?>
        <?php foreach ($centers as $c): ?>
            <tr>
                <td>
                    <?= htmlspecialchars($c['center_name']) ?><br>
                    <small><?= htmlspecialchars($c['location']) ?></small>
                </td>
                <td><?= htmlspecialchars($c['barangay']) ?></td>
                <td><?= (int) $c['capacity'] ?></td>
                <td><?= (int) $c['current_occupancy'] ?></td>
                <td><?= $c['available'] ?></td>
                <td>
                    <div class="occupancy-bar">
                        <div class="occupancy-fill state-<?= strtolower(str_replace(' ', '-', $c['state'])) ?>"
                             style="width: <?= min(100, $c['percent']) ?>%"></div>
                    </div>
                    <?= $c['percent'] ?>%
                </td>
                <td class="level-<?= strtolower(str_replace(' ', '-', $c['state'])) ?>"><?= $c['state'] ?></td>
                <td class="status-<?= strtolower($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></td>
                <td>
                    <a href="evacuation_center_register.php?edit=<?= $c['center_id'] ?>">Edit</a> |
                    <a href="?toggle=<?= $c['center_id'] ?>"><?= $c['status'] === 'Active' ? 'Deactivate' : 'Activate' ?></a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>

<?php include 'includes/footer.php'; ?>
</body>
</html>
