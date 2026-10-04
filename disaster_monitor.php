<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/list_view.php';
require_once 'includes/status_rules.php';

$currentUserId = $_SESSION['user_id'] ?? 1; // placeholder until Lab 37 (login) exists

// Handle a status change (Lab 6)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disaster_id'], $_POST['new_status'])) {
    $id = (int) $_POST['disaster_id'];
    $current = dbGetById($conn, 'disasters', 'disaster_id', $id);

    if ($current && !isStatusLocked('disasters', $current['status'])) {
        $allowed = getNextAllowedStatuses('disasters', $current['status']);
        if (in_array($_POST['new_status'], $allowed, true)) {
            $updateData = ['status' => $_POST['new_status']];
            if ($_POST['new_status'] === 'Closed') {
                $updateData['date_closed'] = date('Y-m-d H:i:s');
            }
            dbUpdate($conn, 'disasters', 'disaster_id', $id, $updateData);
            logAudit($conn, $currentUserId, 'Changed disaster status', 'disasters', $current['status'], $_POST['new_status'], $current['disaster_number']);
        }
        // Silently ignore attempts to jump to a non-adjacent status (Lab 6: "restrict invalid transitions")
    }
    header('Location: disaster_monitor.php');
    exit;
}

// Search and filter (Lab 7)
$query = $_GET['q'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$severityFilter = $_GET['severity'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$sql = "SELECT d.*, t.type_name FROM disasters d
        LEFT JOIN disaster_types t ON d.disaster_type_id = t.disaster_type_id
        WHERE 1=1";
$params = [];
$types = '';

if ($query !== '') {
    $sql .= " AND (d.disaster_number LIKE ? OR d.disaster_name LIKE ? OR d.area_affected LIKE ?)";
    $like = "%$query%";
    $params = array_merge($params, [$like, $like, $like]);
    $types .= 'sss';
}
if ($typeFilter !== '') {
    $sql .= " AND d.disaster_type_id = ?";
    $params[] = $typeFilter;
    $types .= 'i';
}
if ($severityFilter !== '') {
    $sql .= " AND d.severity = ?";
    $params[] = $severityFilter;
    $types .= 's';
}
if ($statusFilter !== '') {
    $sql .= " AND d.status = ?";
    $params[] = $statusFilter;
    $types .= 's';
}
$sql .= " ORDER BY d.date_reported DESC";

$stmt = mysqli_prepare($conn, $sql);
if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$disasters = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

$allTypes = dbGetAll($conn, 'disaster_types', 'type_name');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Disaster Monitoring</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<main>
    <h1>Disaster Monitoring</h1>

    <?php if (isset($_GET['registered'])): ?>
        <div class="success-box">Disaster <?= htmlspecialchars($_GET['registered']) ?> registered successfully.</div>
    <?php endif; ?>

    <p><a href="disaster_register.php">+ Register a new disaster</a></p>

    <form method="get" class="filter-bar">
        <input type="text" name="q" placeholder="Search number, name, or area..." value="<?= htmlspecialchars($query) ?>">
        <select name="type">
            <option value="">All Types</option>
            <?php foreach ($allTypes as $t): ?>
                <option value="<?= $t['disaster_type_id'] ?>" <?= $typeFilter == $t['disaster_type_id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['type_name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="severity">
            <option value="">All Severities</option>
            <?php foreach (['Low', 'Moderate', 'Severe', 'Catastrophic'] as $s): ?>
                <option <?= $severityFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <select name="status">
            <option value="">All Statuses</option>
            <?php foreach (['Reported', 'Monitoring', 'Active', 'Response', 'Recovery', 'Closed'] as $s): ?>
                <option <?= $statusFilter === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit">Filter</button>
    </form>

    <table class="data-table">
        <thead>
            <tr><th>Number</th><th>Name</th><th>Type</th><th>Area</th><th>Severity</th><th>Status</th><th>Reported</th><th>Change Status</th></tr>
        </thead>
        <tbody>
        <?php if (empty($disasters)): ?>
            <tr><td colspan="8">No records found.</td></tr>
        <?php endif; ?>
        <?php foreach ($disasters as $d): ?>
            <?php
                $locked = isStatusLocked('disasters', $d['status']);
                $nextOptions = getNextAllowedStatuses('disasters', $d['status']);
            ?>
            <tr>
                <td><?= htmlspecialchars($d['disaster_number']) ?></td>
                <td><?= htmlspecialchars($d['disaster_name']) ?></td>
                <td><?= htmlspecialchars($d['type_name']) ?></td>
                <td><?= htmlspecialchars($d['area_affected']) ?></td>
                <td><?= htmlspecialchars($d['severity']) ?></td>
                <td class="status-<?= strtolower($d['status']) ?>"><?= htmlspecialchars($d['status']) ?></td>
                <td><?= htmlspecialchars($d['date_reported']) ?></td>
                <td>
                    <?php if ($locked): ?>
                        Closed (locked)
                    <?php else: ?>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="disaster_id" value="<?= $d['disaster_id'] ?>">
                            <select name="new_status">
                                <?php foreach ($nextOptions as $opt): ?>
                                    <option value="<?= $opt ?>" <?= $opt === $d['status'] ? 'selected' : '' ?>><?= $opt ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit">Update</button>
                        </form>
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
