<?php
require_once 'config/db.php';
require_once 'includes/crud.php';

$disasters = dbGetAll($conn, 'disasters', 'date_reported DESC');

$selectedId = $_GET['disaster_id'] ?? '';
$selectedDisaster = null;
$households = [];
$totalResidents = 0;
$totalVulnerable = 0;

if ($selectedId !== '') {
    $selectedDisaster = dbGetById($conn, 'disasters', 'disaster_id', (int) $selectedId);

    if ($selectedDisaster) {
        // The actual integration: match households by barangay against the disaster's affected area
        $sql = "SELECT h.*,
                (SELECT COUNT(*) FROM residents WHERE household_id = h.household_id) AS member_count,
                (SELECT COUNT(*) FROM residents WHERE household_id = h.household_id AND vulnerability_type != 'None') AS vulnerable_count
                FROM households h
                WHERE h.barangay = ?
                ORDER BY h.address";
        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, 's', $selectedDisaster['area_affected']);
        mysqli_stmt_execute($stmt);
        $households = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

        foreach ($households as $h) {
            $totalResidents += (int) $h['member_count'];
            $totalVulnerable += (int) $h['vulnerable_count'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Disaster-Affected Residents</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'includes/header.php'; ?>

<main>
    <h1>Disaster &rarr; Affected Residents</h1>
    <p>Select a disaster to see which households and residents are in its affected area.</p>

    <form method="get" class="filter-bar">
        <select name="disaster_id" onchange="this.form.submit()">
            <option value="">-- Select a disaster --</option>
            <?php foreach ($disasters as $d): ?>
                <option value="<?= $d['disaster_id'] ?>" <?= $selectedId == $d['disaster_id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($d['disaster_number'] . ' — ' . $d['disaster_name'] . ' (' . $d['area_affected'] . ')') ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit">Go</button></noscript>
    </form>

    <?php if ($selectedDisaster): ?>
        <h2><?= htmlspecialchars($selectedDisaster['disaster_number']) ?>: <?= htmlspecialchars($selectedDisaster['disaster_name']) ?></h2>
        <p>Affected area: <strong><?= htmlspecialchars($selectedDisaster['area_affected']) ?></strong> &nbsp;|&nbsp;
           Severity: <?= htmlspecialchars($selectedDisaster['severity']) ?> &nbsp;|&nbsp;
           Status: <?= htmlspecialchars($selectedDisaster['status']) ?></p>

        <p><strong><?= count($households) ?></strong> household(s) affected,
           <strong><?= $totalResidents ?></strong> resident(s) total,
           <strong><?= $totalVulnerable ?></strong> flagged as vulnerable.</p>

        <?php if (empty($households)): ?>
            <p>No households are registered in this disaster's affected area yet.</p>
        <?php else: ?>
            <?php foreach ($households as $h): ?>
                <h3>Household #<?= $h['household_id'] ?> &mdash; <?= htmlspecialchars($h['address']) ?></h3>
                <?php
                    $resStmt = mysqli_prepare($conn, "SELECT * FROM residents WHERE household_id = ? ORDER BY first_name");
                    mysqli_stmt_bind_param($resStmt, 'i', $h['household_id']);
                    mysqli_stmt_execute($resStmt);
                    $members = mysqli_fetch_all(mysqli_stmt_get_result($resStmt), MYSQLI_ASSOC);
                ?>
                <table class="data-table">
                    <thead><tr><th>Name</th><th>Age</th><th>Gender</th><th>Vulnerability</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($members as $m): ?>
                        <?php $age = $m['birthdate'] ? floor((time() - strtotime($m['birthdate'])) / 31556926) : '—'; ?>
                        <tr>
                            <td><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name']) ?></td>
                            <td><?= $age ?></td>
                            <td><?= htmlspecialchars($m['gender']) ?></td>
                            <td><?= htmlspecialchars($m['vulnerability_type']) ?></td>
                            <td class="status-<?= strtolower($m['status']) ?>"><?= htmlspecialchars($m['status']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endif; ?>
</main>

<?php include 'includes/footer.php'; ?>
</body>
</html>
