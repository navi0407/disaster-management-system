<?php
// Lab 40: audit trail viewer. Admin only. Read only.
require_once 'config/db.php';
require_once 'includes/crud.php';

$q = trim($_GET['q'] ?? '');
$userId = trim($_GET['user_id'] ?? '');
$table = trim($_GET['table'] ?? '');
$from = trim($_GET['from'] ?? '');
$to = trim($_GET['to'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

function auditValidDate($v) { $d = DateTime::createFromFormat('Y-m-d', $v); return $d && $d->format('Y-m-d') === $v; }

$where = []; $types = ''; $params = [];
if ($q !== '') {
    $where[] = "(a.activity LIKE ? OR a.reference_number LIKE ? OR a.old_value LIKE ? OR a.new_value LIKE ?)";
    $types .= 'ssss'; array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($userId !== '' && ctype_digit($userId)) { $where[] = "a.user_id = ?"; $types .= 'i'; $params[] = (int) $userId; }
if ($table !== '') { $where[] = "a.affected_table = ?"; $types .= 's'; $params[] = $table; }
if ($from !== '' && auditValidDate($from)) { $where[] = "DATE(a.log_datetime) >= ?"; $types .= 's'; $params[] = $from; }
if ($to !== '' && auditValidDate($to)) { $where[] = "DATE(a.log_datetime) <= ?"; $types .= 's'; $params[] = $to; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$total = (int) authQuery($conn, "SELECT COUNT(*) AS n FROM audit_logs a" . $whereSql, $types, $params)[0]['n'];
$pages = max(1, (int) ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$rows = authQuery($conn,
    "SELECT a.*, u.username FROM audit_logs a LEFT JOIN users u ON u.user_id = a.user_id" . $whereSql
    . " ORDER BY a.log_datetime DESC, a.log_id DESC LIMIT " . (int) $perPage . " OFFSET " . (int) $offset, $types, $params);

$userList = authQuery($conn, "SELECT user_id, username FROM users ORDER BY username");
$tableList = authQuery($conn, "SELECT DISTINCT affected_table FROM audit_logs WHERE affected_table IS NOT NULL ORDER BY affected_table");
$keep = http_build_query(array_filter(['q' => $q, 'user_id' => $userId, 'table' => $table, 'from' => $from, 'to' => $to], fn($v) => $v !== ''));

function auditShort($v) {
    $v = (string) ($v ?? '');
    return mb_strlen($v) > 90 ? mb_substr($v, 0, 90) . '...' : $v;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Audit Trail</title>
    <link rel="stylesheet" href="<?= authEsc(authCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>
<main class="wide">
    <h1>Audit Trail</h1>
    <p>Every change in the system, who did it, and when. This page is read only.</p>

    <form method="get" class="filter-bar">
        <input type="text" name="q" placeholder="Search activity, reference, values..." value="<?= authEsc($q) ?>">
        <select name="user_id"><option value="">All users</option>
            <?php foreach ($userList as $u): ?><option value="<?= (int) $u['user_id'] ?>" <?= $userId === (string) $u['user_id'] ? 'selected' : '' ?>><?= authEsc($u['username']) ?></option><?php endforeach; ?>
        </select>
        <select name="table"><option value="">All tables</option>
            <?php foreach ($tableList as $t): ?><option <?= $table === $t['affected_table'] ? 'selected' : '' ?>><?= authEsc($t['affected_table']) ?></option><?php endforeach; ?>
        </select>
        <input type="date" name="from" value="<?= authEsc($from) ?>" title="From date">
        <input type="date" name="to" value="<?= authEsc($to) ?>" title="To date">
        <button type="submit">Filter</button>
        <a href="audit_trail.php">Clear</a>
    </form>

    <p><strong><?= $total ?></strong> entries | Page <?= $page ?> of <?= $pages ?>
       <?php if ($page > 1): ?> | <a href="?<?= authEsc($keep . ($keep ? '&' : '') . 'page=' . ($page - 1)) ?>">&larr; Newer</a><?php endif; ?>
       <?php if ($page < $pages): ?> | <a href="?<?= authEsc($keep . ($keep ? '&' : '') . 'page=' . ($page + 1)) ?>">Older &rarr;</a><?php endif; ?></p>

    <table class="data-table">
        <thead><tr><th>When</th><th>User</th><th>Activity</th><th>Table</th><th>Reference</th><th>Before</th><th>After</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7">No records found.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= authEsc($r['log_datetime']) ?></td>
                <td><?= authEsc($r['username'] ?? 'unknown') ?></td>
                <td><?= authEsc($r['activity']) ?></td>
                <td><?= authEsc($r['affected_table']) ?></td>
                <td><?= authEsc($r['reference_number']) ?></td>
                <td title="<?= authEsc($r['old_value']) ?>"><?= authEsc(auditShort($r['old_value'])) ?></td>
                <td title="<?= authEsc($r['new_value']) ?>"><?= authEsc(auditShort($r['new_value'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
