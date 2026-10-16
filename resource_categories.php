<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/resource_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$currentUserId = resCurrentUserId();

$errors = [];
$formId = 0;
$formName = '';

// ---- Delete (only when no resource uses the category) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    $id = (int) $_POST['delete_id'];
    $row = dbGetById($conn, 'resource_categories', 'category_id', $id);
    $used = resQuery($conn, "SELECT COUNT(*) AS total FROM resources WHERE category_id = ?", 'i', [$id]);
    if (!$row) {
        $errors[] = 'That category does not exist.';
    } elseif ((int) $used[0]['total'] > 0) {
        $errors[] = 'That category is used by resources, so it cannot be deleted.';
    } else {
        dbDelete($conn, 'resource_categories', 'category_id', $id);
        logAudit($conn, $currentUserId, 'Deleted resource category', 'resource_categories', $row['category_name'], null, 'CAT-' . $id);
        header('Location: resource_categories.php?deleted=1'); exit;
    }
}

// ---- Add or edit ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $formId = (int) ($_POST['category_id'] ?? 0);
    $formName = trim($_POST['category_name'] ?? '');

    if ($formName === '') $errors[] = 'Category name is required.';
    elseif (mb_strlen($formName) > 50) $errors[] = 'Category name must be 50 characters or less.';
    else {
        $dup = resQuery($conn,
            "SELECT COUNT(*) AS total FROM resource_categories WHERE LOWER(category_name) = LOWER(?) AND category_id <> ?",
            'si', [$formName, $formId]);
        if ((int) $dup[0]['total'] > 0) $errors[] = 'That category already exists.';
    }

    if (!$errors) {
        if ($formId > 0) {
            $old = dbGetById($conn, 'resource_categories', 'category_id', $formId);
            if (!$old) {
                $errors[] = 'That category does not exist.';
            } else {
                dbUpdate($conn, 'resource_categories', 'category_id', $formId, ['category_name' => $formName]);
                logAudit($conn, $currentUserId, 'Updated resource category', 'resource_categories', $old['category_name'], $formName, 'CAT-' . $formId);
                header('Location: resource_categories.php?saved=1'); exit;
            }
        } else {
            $newId = dbInsert($conn, 'resource_categories', ['category_name' => $formName]);
            logAudit($conn, $currentUserId, 'Added resource category', 'resource_categories', null, $formName, 'CAT-' . $newId);
            header('Location: resource_categories.php?saved=1'); exit;
        }
    }
}

// ---- Load a category into the form when Edit is clicked ----
if (isset($_GET['edit']) && !$errors && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $row = dbGetById($conn, 'resource_categories', 'category_id', (int) $_GET['edit']);
    if ($row) { $formId = (int) $row['category_id']; $formName = $row['category_name']; }
}

// ---- List ----
$q = trim($_GET['q'] ?? '');
$rows = resQuery($conn,
    "SELECT c.category_id, c.category_name, COUNT(r.resource_id) AS resource_count
     FROM resource_categories c
     LEFT JOIN resources r ON r.category_id = c.category_id
     WHERE c.category_name LIKE ?
     GROUP BY c.category_id, c.category_name
     ORDER BY c.category_name", 's', ['%' . $q . '%']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Resource Categories</title>
    <link rel="stylesheet" href="<?= resEsc(resCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>
<main>
    <h1>Resource Categories</h1>

    <?php if (isset($_GET['saved'])): ?><div class="success-box">Category saved.</div><?php endif; ?>
    <?php if (isset($_GET['deleted'])): ?><div class="success-box">Category deleted.</div><?php endif; ?>
    <?php foreach ($errors as $err): ?><div class="error-box"><?= resEsc($err) ?></div><?php endforeach; ?>

    <form method="post">
        <h2><?= $formId ? 'Edit category' : 'Add category' ?></h2>
        <input type="hidden" name="category_id" value="<?= (int) $formId ?>">
        <label>Category name
            <input type="text" name="category_name" maxlength="50" value="<?= resEsc($formName) ?>">
        </label>
        <p>
            <button type="submit" name="save" value="1"><?= $formId ? 'Update' : 'Add' ?></button>
            <?php if ($formId): ?><a href="resource_categories.php">Cancel</a><?php endif; ?>
        </p>
    </form>

    <form method="get" class="filter-bar">
        <input type="text" name="q" placeholder="Search categories..." value="<?= resEsc($q) ?>">
        <button type="submit">Search</button>
    </form>

    <table class="data-table">
        <thead><tr><th>Category</th><th>Resources</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="3">No records found.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= resEsc($r['category_name']) ?></td>
                <td><?= (int) $r['resource_count'] ?></td>
                <td>
                    <a href="resource_categories.php?edit=<?= (int) $r['category_id'] ?>">Edit</a>
                    <?php if ((int) $r['resource_count'] === 0): ?>
                    <form method="post" class="inline-form" style="display:inline" onsubmit="return confirm('Delete this category?');">
                        <input type="hidden" name="delete_id" value="<?= (int) $r['category_id'] ?>">
                        <button type="submit">Delete</button>
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
