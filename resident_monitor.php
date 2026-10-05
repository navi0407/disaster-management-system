<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';

$currentUserId = $_SESSION['user_id'] ?? 1; // placeholder until Lab 37 (login) exists

// Toggle Active/Inactive
if (isset($_GET['toggle'])) {
    $id = (int) $_GET['toggle'];
    $current = dbGetById($conn, 'residents', 'resident_id', $id);
    if ($current) {
        $newStatus = $current['status'] === 'Active' ? 'Inactive' : 'Active';
        dbUpdate($conn, 'residents', 'resident_id', $id, ['status' => $newStatus]);
        logAudit($conn, $currentUserId, 'Changed resident status', 'residents', $current['status'], $newStatus, $current['first_name'] . ' ' . $current['last_name']);
    }
    header('Location: resident_monitor.php');
    exit;
}

$query = $_GET['q'] ?? '';
$barangayFilter = $_GET['barangay'] ?? '';
$vulnFilter = $_GET['vulnerability'] ?? '';

$sql = "SELECT r.*, h.barangay AS household_barangay, h.address AS household_address
        FROM residents r
        LEFT JOIN households h ON r.household_id = h.household_id
        WHERE 1=1";
$params = [];
$types = '';

if ($query !== '') {
    $sql .= " AND (r.first_name LIKE ? OR r.last_name LIKE ? OR h.barangay LIKE ?)";
    $like = "%$query%";
    $params = array_merge($params, [$like, $like, $like]);
    $types .= 'sss';
}
if ($barangayFilter !== '') {
    $sql .= " AND r.barangay = ?";
    $params[] = $barangayFilter;
    $types .= 's';
}
if ($vulnFilter !== '') {
    $sql .= " AND r.vulnerability_type = ?";
    $params[] = $vulnFilter;
    $types .= 's';
}
$sql .= " ORDER BY r.last_name, r.first_name";

$stmt = mysqli_prepare($conn, $sql);
if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$residents = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

// Distinct barangays for the filter dropdown
$barangayResult = mysqli_query($conn, "SELECT DISTINCT barangay FROM residents WHERE barangay IS NOT NULL AND barangay != '' ORDER BY barangay");
$barangays = mysqli_fetch_all($barangayResult, MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Resident Monitoring</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<main>
    <h1>Resident Monitoring</h1>

    <?php if (isset($_GET['registered'])): ?>
        <div class="success-box">Resident registered successfully.</div>
    <?php endif; ?>
    <?php if (isset($_GET['updated'])): ?>
        <div class="success-box">Resident updated successfully.</div>
    <?php endif; ?>

    <p><a href="resident_register.php">+ Register a new resident</a> &nbsp; | &nbsp; <a href="household_monitor.php">View households</a></p>

    <form method="get" class="filter-bar">
        <input type="text" name="q" placeholder="Search name or barangay..." value="<?= htmlspecialchars($query) ?>">
        <select name="barangay">
            <option value="">All Barangays</option>
            <?php foreach ($barangays as $b): ?>
                <option value="<?= htmlspecialchars($b['barangay']) ?>" <?= $barangayFilter === $b['barangay'] ? 'selected' : '' ?>><?= htmlspecialchars($b['barangay']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="vulnerability">
            <option value="">All Vulnerability Types</option>
            <?php foreach (['None', 'PWD', 'Senior', 'Pregnant', 'Infant'] as $v): ?>
                <option value="<?= $v ?>" <?= $vulnFilter === $v ? 'selected' : '' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Filter</button>
    </form>

    <table class="data-table">
        <thead>
            <tr><th>Name</th><th>Household</th><th>Barangay</th><th>Age</th><th>Vulnerability</th><th>Contact</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
        <?php if (empty($residents)): ?>
            <tr><td colspan="8">No records found.</td></tr>
        <?php endif; ?>
        <?php foreach ($residents as $r): ?>
            <?php
                $age = $r['birthdate'] ? floor((time() - strtotime($r['birthdate'])) / 31556926) : '—';
            ?>
            <tr>
                <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
                <td>#<?= $r['household_id'] ?> &mdash; <?= htmlspecialchars($r['household_address'] ?? '') ?></td>
                <td><?= htmlspecialchars($r['barangay'] ?: $r['household_barangay']) ?></td>
                <td><?= $age ?></td>
                <td><?= htmlspecialchars($r['vulnerability_type']) ?></td>
                <td><?= htmlspecialchars($r['contact_number']) ?></td>
                <td class="status-<?= strtolower($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></td>
                <td>
                    <a href="resident_register.php?edit=<?= $r['resident_id'] ?>">Edit</a> |
                    <a href="?toggle=<?= $r['resident_id'] ?>"><?= $r['status'] === 'Active' ? 'Deactivate' : 'Activate' ?></a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>

<?php include 'includes/footer.php'; ?>
</body>
</html>
