<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/list_view.php';

$table = 'disaster_types';
$idField = 'disaster_type_id';
$currentUserId = $_SESSION['user_id'] ?? 1; // placeholder until Lab 37 (login) exists

// Add a new disaster type
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['type_name'])) {
    $data = [
        'type_name'   => $_POST['type_name'],
        'description' => $_POST['description'],
        'status'      => 'Active',
    ];
    dbInsert($conn, $table, $data);
    logAudit($conn, $currentUserId, 'Added disaster type', $table, null, $_POST['type_name']);
    header('Location: disaster_types.php');
    exit;
}

// Toggle Active/Inactive
if (isset($_GET['toggle'])) {
    $id = (int) $_GET['toggle'];
    $current = dbGetById($conn, $table, $idField, $id);
    if ($current) {
        $newStatus = $current['status'] === 'Active' ? 'Inactive' : 'Active';
        dbUpdate($conn, $table, $idField, $id, ['status' => $newStatus]);
        logAudit($conn, $currentUserId, 'Changed disaster type status', $table, $current['status'], $newStatus, $current['type_name']);
    }
    header('Location: disaster_types.php');
    exit;
}

$query = $_GET['q'] ?? '';
$rows = $query
    ? dbSearch($conn, $table, ['type_name', 'description'], $query)
    : dbGetAll($conn, $table, 'type_name');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Disaster Types</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<main>
    <h1>Disaster Type Management</h1>

    <h2>Add a Disaster Type</h2>
    <form method="post">
        <input type="text" name="type_name" placeholder="Type name (e.g. Typhoon)" required>
        <input type="text" name="description" placeholder="Short description">
        <button type="submit">Add</button>
    </form>

    <h2>All Disaster Types</h2>
    <?php renderSearchBox($query); ?>
    <table class="data-table">
        <thead>
            <tr><th>ID</th><th>Type Name</th><th>Description</th><th>Status</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="5">No records found.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['disaster_type_id']) ?></td>
                <td><?= htmlspecialchars($row['type_name']) ?></td>
                <td><?= htmlspecialchars($row['description']) ?></td>
                <td class="status-<?= strtolower($row['status']) ?>"><?= htmlspecialchars($row['status']) ?></td>
                <td>
                    <a href="?toggle=<?= $row['disaster_type_id'] ?>">
                        <?= $row['status'] === 'Active' ? 'Deactivate' : 'Activate' ?>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>

<?php include 'includes/footer.php'; ?>
</body>
</html>
