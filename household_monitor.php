<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/list_view.php';

$currentUserId = $_SESSION['user_id'] ?? 1; // placeholder until Lab 37 (login) exists

// Set or change the household head
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['household_id'], $_POST['head_resident_id'])) {
    $householdId = (int) $_POST['household_id'];
    $headId = $_POST['head_resident_id'] ?: null;
    dbUpdate($conn, 'households', 'household_id', $householdId, ['head_resident_id' => $headId]);
    logAudit($conn, $currentUserId, 'Set household head', 'households', null, $headId, (string) $householdId);
    header('Location: household_monitor.php');
    exit;
}

$query = $_GET['q'] ?? '';
$sql = "SELECT h.*, r.first_name AS head_first, r.last_name AS head_last,
               (SELECT COUNT(*) FROM residents WHERE household_id = h.household_id) AS member_count,
               (SELECT COUNT(*) FROM residents WHERE household_id = h.household_id AND vulnerability_type != 'None') AS vulnerable_count
        FROM households h
        LEFT JOIN residents r ON h.head_resident_id = r.resident_id
        WHERE 1=1";
$params = [];
$types = '';
if ($query !== '') {
    $sql .= " AND (h.barangay LIKE ? OR h.address LIKE ?)";
    $like = "%$query%";
    $params = [$like, $like];
    $types = 'ss';
}
$sql .= " ORDER BY h.barangay, h.address";

$stmt = mysqli_prepare($conn, $sql);
if ($params) mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$households = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Household Monitoring</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<main>
    <h1>Household Monitoring</h1>

    <?php if (isset($_GET['registered'])): ?>
        <div class="success-box">Household #<?= (int) $_GET['registered'] ?> registered successfully. You can now register residents into it.</div>
    <?php endif; ?>

    <p><a href="household_register.php">+ Register a new household</a> &nbsp; | &nbsp; <a href="resident_register.php">+ Register a resident</a></p>

    <?php renderSearchBox($query); ?>

    <table class="data-table">
        <thead>
            <tr><th>ID</th><th>Barangay</th><th>Address</th><th>Members</th><th>Vulnerable</th><th>Head Resident</th><th>Set Head</th></tr>
        </thead>
        <tbody>
        <?php if (empty($households)): ?>
            <tr><td colspan="7">No records found.</td></tr>
        <?php endif; ?>
        <?php foreach ($households as $h): ?>
            <?php
                // Pull just this household's members for the head dropdown
                $memberStmt = mysqli_prepare($conn, "SELECT resident_id, first_name, last_name FROM residents WHERE household_id = ?");
                mysqli_stmt_bind_param($memberStmt, 'i', $h['household_id']);
                mysqli_stmt_execute($memberStmt);
                $householdMembers = mysqli_fetch_all(mysqli_stmt_get_result($memberStmt), MYSQLI_ASSOC);
            ?>
            <tr>
                <td><?= $h['household_id'] ?></td>
                <td><?= htmlspecialchars($h['barangay']) ?></td>
                <td><?= htmlspecialchars($h['address']) ?></td>
                <td><?= $h['member_count'] ?></td>
                <td><?= $h['vulnerable_count'] > 0 ? $h['vulnerable_count'] . ' &#9888;' : '0' ?></td>
                <td><?= $h['head_first'] ? htmlspecialchars($h['head_first'] . ' ' . $h['head_last']) : '&mdash; Not set' ?></td>
                <td>
                    <?php if (empty($householdMembers)): ?>
                        No residents yet
                    <?php else: ?>
                        <form method="post" class="inline-form">
                            <input type="hidden" name="household_id" value="<?= $h['household_id'] ?>">
                            <select name="head_resident_id">
                                <option value="">-- None --</option>
                                <?php foreach ($householdMembers as $m): ?>
                                    <option value="<?= $m['resident_id'] ?>" <?= $h['head_resident_id'] == $m['resident_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit">Set</button>
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
