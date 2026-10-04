<?php
// DEMO ONLY — proves the reusable engine works before you build Lab 4 properly.
// Delete or keep this as a reference once Lab 4 has its own real page.

require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/list_view.php';

$table = 'disaster_types';
$idField = 'disaster_type_id';
$demoUserId = 1; // placeholder until Lab 37 (login) exists

// Handle "add" form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['type_name'])) {
    $data = [
        'type_name'   => $_POST['type_name'],
        'description' => $_POST['description'],
    ];
    dbInsert($conn, $table, $data);
    logAudit($conn, $demoUserId, 'Added disaster type', $table, null, $_POST['type_name']);
    header('Location: demo_disaster_types.php');
    exit;
}

// Handle search, otherwise show everything
$query = $_GET['q'] ?? '';
$rows = $query
    ? dbSearch($conn, $table, ['type_name', 'description'], $query)
    : dbGetAll($conn, $table, 'type_name');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Demo: Disaster Types (Reusable Engine Test)</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<main>
    <h1>Reusable Engine Test: Disaster Types</h1>
    <p>If this page works, your CRUD engine, search engine, and audit logger are all functioning together.</p>

    <h2>Add a Disaster Type</h2>
    <form method="post">
        <input type="text" name="type_name" placeholder="Type name (e.g. Typhoon)" required>
        <input type="text" name="description" placeholder="Short description">
        <button type="submit">Add</button>
    </form>

    <h2>All Disaster Types</h2>
    <?php renderSearchBox($query); ?>
    <?php renderTable(['ID', 'Type Name', 'Description'], $rows); ?>
</main>

<?php include 'includes/footer.php'; ?>
</body>
</html>
