<?php
// Lab 38: user and role management. Admin only (the guard in includes/auth.php enforces it).
require_once 'config/db.php';
require_once 'includes/errors.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
$me = authUser();
$errors = [];

$roles = authQuery($conn, "SELECT role_id, role_name FROM roles ORDER BY role_name");
$roleById = [];
foreach ($roles as $r) $roleById[(int) $r['role_id']] = $r['role_name'];
$statuses = ['Active', 'Inactive'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!authCsrfOk()) {
        $errors[] = 'The page expired. Please try again.';
    } elseif ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $roleId = (int) ($_POST['role_id'] ?? 0);
        if (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $username)) $errors[] = 'Username: 3 to 50 letters, numbers, dots or underscores.';
        if (strlen($password) < 8) $errors[] = 'The password must be at least 8 characters.';
        if (!isset($roleById[$roleId])) $errors[] = 'Please choose a role.';
        if (!$errors && authQuery($conn, "SELECT user_id FROM users WHERE username = ?", 's', [$username]))
            $errors[] = 'That username is already taken.';
        if (!$errors) {
            $newId = dbInsert($conn, 'users', ['username' => $username, 'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                                               'role_id' => $roleId, 'status' => 'Active']);
            logAudit($conn, $me['id'], 'Created user', 'users', null, $username . ' as ' . $roleById[$roleId], 'USR-' . $newId);
            header('Location: users.php?done=added'); exit;
        }
    } elseif ($action === 'update') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        if (!isset($roleById[$roleId]) || !in_array($status, $statuses, true)) $errors[] = 'Invalid role or status.';
        if (!$errors) {
            try {
                mysqli_begin_transaction($conn);
                // lock the active admins so two people can't remove the last two admins at the same moment
                $admins = authQuery($conn, "SELECT u.user_id FROM users u JOIN roles r ON r.role_id = u.role_id
                                            WHERE LOWER(r.role_name) IN ('administrator', 'admin') AND u.status = 'Active' FOR UPDATE");
                $adminIds = array_map('intval', array_column($admins, 'user_id'));
                $target = dbGetById($conn, 'users', 'user_id', $uid);
                $stillAdmin = authRoleKey($roleById[$roleId]) === 'administrator' && $status === 'Active';
                if (!$target) {
                    $errors[] = 'That user does not exist.';
                } elseif ($uid === $me['id'] && !$stillAdmin) {
                    $errors[] = 'You cannot remove your own administrator role or deactivate yourself.';
                } elseif (in_array($uid, $adminIds, true) && !$stillAdmin && count($adminIds) <= 1) {
                    $errors[] = 'There must always be at least one active administrator.';
                } else {
                    $old = ($roleById[(int) $target['role_id']] ?? 'No role') . ', ' . $target['status'];
                    dbUpdate($conn, 'users', 'user_id', $uid, ['role_id' => $roleId, 'status' => $status]);
                    logAudit($conn, $me['id'], 'Updated user', 'users', $old, $roleById[$roleId] . ', ' . $status, 'USR-' . $uid);
                    mysqli_commit($conn);
                    header('Location: users.php?done=updated'); exit;
                }
                mysqli_rollback($conn);
            } catch (Throwable $e) {
                mysqli_rollback($conn);
                $errors[] = errMessage($e);
            }
        }
    } elseif ($action === 'reset') {
        $uid = (int) ($_POST['user_id'] ?? 0);
        $password = (string) ($_POST['password'] ?? '');
        if (strlen($password) < 8) $errors[] = 'The new password must be at least 8 characters.';
        if (!$errors && !dbGetById($conn, 'users', 'user_id', $uid)) $errors[] = 'That user does not exist.';
        if (!$errors) {
            dbUpdate($conn, 'users', 'user_id', $uid, ['password_hash' => password_hash($password, PASSWORD_BCRYPT)]);
            logAudit($conn, $me['id'], 'Reset user password', 'users', null, null, 'USR-' . $uid);  // the password itself is never logged
            header('Location: users.php?done=reset'); exit;
        }
    } else {
        $errors[] = 'Unknown action.';
    }
}

$users = authQuery($conn,
    "SELECT u.user_id, u.username, u.role_id, u.status,
            (SELECT MAX(a.log_datetime) FROM audit_logs a WHERE a.user_id = u.user_id AND a.activity = 'Logged in') AS last_login
     FROM users u ORDER BY u.username");
$doneMsg = ['added' => 'User created.', 'updated' => 'User updated.', 'reset' => 'Password reset.'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Users</title>
    <link rel="stylesheet" href="<?= authEsc(authCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>
<main class="wide">
    <h1>Users and Roles</h1>
    <?php if (isset($_GET['done'], $doneMsg[$_GET['done']])): ?><div class="success-box"><?= authEsc($doneMsg[$_GET['done']]) ?></div><?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="error-box"><?= authEsc($err) ?></div><?php endforeach; ?>

    <table class="data-table">
        <thead><tr><th>Username</th><th>Role and status</th><th>Last login</th><th>Reset password</th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): $uid = (int) $u['user_id']; ?>
            <tr>
                <td><?= authEsc($u['username']) ?><?= $uid === $me['id'] ? ' <small>(you)</small>' : '' ?></td>
                <td>
                    <form method="post" class="inline-form">
                        <?= authCsrfField() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                        <select name="role_id">
                            <?php foreach ($roles as $r): ?><option value="<?= (int) $r['role_id'] ?>" <?= (int) $u['role_id'] === (int) $r['role_id'] ? 'selected' : '' ?>><?= authEsc($r['role_name']) ?></option><?php endforeach; ?>
                        </select>
                        <select name="status">
                            <?php foreach ($statuses as $s): ?><option <?= $u['status'] === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
                        </select>
                        <button type="submit">Save</button>
                    </form>
                </td>
                <td><?= authEsc($u['last_login'] ?: 'Never') ?></td>
                <td>
                    <form method="post" class="inline-form">
                        <?= authCsrfField() ?>
                        <input type="hidden" name="action" value="reset">
                        <input type="hidden" name="user_id" value="<?= $uid ?>">
                        <input type="password" name="password" placeholder="New password" autocomplete="new-password">
                        <button type="submit">Reset</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h2>Add a user</h2>
    <form method="post" autocomplete="off">
        <?= authCsrfField() ?>
        <input type="hidden" name="action" value="add">
        <label>Username <input type="text" name="username" maxlength="50" value="<?= authEsc($_POST['username'] ?? '') ?>"></label>
        <label>Password (8 characters or more) <input type="password" name="password" autocomplete="new-password"></label>
        <label>Role
            <select name="role_id">
                <option value=""> choose </option>
                <?php foreach ($roles as $r): ?><option value="<?= (int) $r['role_id'] ?>"><?= authEsc($r['role_name']) ?></option><?php endforeach; ?>
            </select>
        </label>
        <p><button type="submit">Create user</button></p>
    </form>

    <h2>What each role can do</h2>
    <table class="data-table">
        <thead><tr><th>Role</th><th>Can change data in</th><th>Can only look at</th><th>Can approve requests</th></tr></thead>
        <tbody>
        <tr><td>Administrator</td><td>Everything, including users and the audit trail</td><td>-</td><td>Yes</td></tr>
        <tr><td>Disaster Officer</td><td>Disaster records</td><td>All other modules</td><td>No</td></tr>
        <tr><td>Resident Officer</td><td>Households and residents</td><td>All other modules</td><td>No</td></tr>
        <tr><td>Evacuation Officer</td><td>Evacuation centers and evacuees</td><td>All other modules</td><td>No</td></tr>
        <tr><td>Resource Officer</td><td>Resource inventory</td><td>All other modules</td><td>No</td></tr>
        <tr><td>Relief Officer</td><td>Relief goods, relief requests</td><td>All other modules</td><td>Yes</td></tr>
        <tr><td>Distribution Officer</td><td>Relief requests, releasing approved distributions</td><td>All other modules</td><td>No</td></tr>
        <tr><td>Management</td><td>Nothing</td><td>Everything except users and the audit trail</td><td>No</td></tr>
        </tbody>
    </table>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
