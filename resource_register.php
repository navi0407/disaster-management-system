<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/resource_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$currentUserId = resCurrentUserId();

$errors = [];
$id = (int) ($_POST['resource_id'] ?? $_GET['id'] ?? 0);
$resource = null;
if ($id > 0) {
    $resource = dbGetById($conn, 'resources', 'resource_id', $id);
    if (!$resource) { $errors[] = 'That resource does not exist.'; $id = 0; }
}
$categories = dbGetAll($conn, 'resource_categories', 'category_name');

// Form values (from the database on edit, from the post when saving)
$f = [
    'resource_name'    => $resource['resource_name'] ?? '',
    'category_id'      => $resource['category_id'] ?? '',
    'unit'             => $resource['unit'] ?? '',
    'min_stock_level'  => $resource['min_stock_level'] ?? '0',
    'storage_location' => $resource['storage_location'] ?? '',
    'expiration_date'  => $resource['expiration_date'] ?? '',
    'status'           => $resource['status'] ?? 'Active',
];

function resSummary($d) {
    return ($d['resource_name'] ?? '') . ' | unit ' . ($d['unit'] ?? '') . ' | min ' . ($d['min_stock_level'] ?? '')
         . ' | ' . ($d['storage_location'] ?? '') . ' | exp ' . ($d['expiration_date'] ?? '') . ' | ' . ($d['status'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['resource_name', 'unit', 'storage_location', 'expiration_date'] as $k) $f[$k] = trim($_POST[$k] ?? '');
    $f['category_id'] = trim($_POST['category_id'] ?? '');
    $f['min_stock_level'] = trim($_POST['min_stock_level'] ?? '');
    $f['status'] = $id ? ($_POST['status'] ?? 'Active') : 'Active';

    if ($f['resource_name'] === '') $errors[] = 'Resource name is required.';
    elseif (mb_strlen($f['resource_name']) > 100) $errors[] = 'Resource name must be 100 characters or less.';

    if ($f['category_id'] === '' || !dbGetById($conn, 'resource_categories', 'category_id', (int) $f['category_id']))
        $errors[] = 'Please choose a category.';

    if ($f['unit'] === '') $errors[] = 'Unit is required (for example pcs, kg, box).';
    elseif (mb_strlen($f['unit']) > 20) $errors[] = 'Unit must be 20 characters or less.';

    if ($f['min_stock_level'] === '' || !ctype_digit($f['min_stock_level'])) $errors[] = 'Minimum stock level must be a whole number, 0 or more.';
    if (mb_strlen($f['storage_location']) > 100) $errors[] = 'Storage location must be 100 characters or less.';
    if ($f['expiration_date'] !== '' && !resValidDate($f['expiration_date'])) $errors[] = 'Expiration date is not a valid date.';
    if (!in_array($f['status'], ['Active', 'Inactive'], true)) $errors[] = 'Status must be Active or Inactive.';

    if (!$errors) {
        $dup = resQuery($conn,
            "SELECT COUNT(*) AS total FROM resources WHERE LOWER(resource_name) = LOWER(?) AND category_id = ? AND resource_id <> ?",
            'sii', [$f['resource_name'], (int) $f['category_id'], $id]);
        if ((int) $dup[0]['total'] > 0) $errors[] = 'A resource with that name already exists in this category.';
    }

    if (!$errors) {
        $data = [
            'resource_name'    => $f['resource_name'],
            'category_id'      => (int) $f['category_id'],
            'unit'             => $f['unit'],
            'min_stock_level'  => (int) $f['min_stock_level'],
            'storage_location' => $f['storage_location'] !== '' ? $f['storage_location'] : null,
            'expiration_date'  => $f['expiration_date'] !== '' ? $f['expiration_date'] : null,
            'status'           => $f['status'],
        ];
        if ($id) {
            dbUpdate($conn, 'resources', 'resource_id', $id, $data);
            logAudit($conn, $currentUserId, 'Updated resource', 'resources', resSummary($resource), resSummary($data), $resource['resource_code']);
        } else {
            $data['resource_code'] = generateResourceCode($conn);
            $data['quantity_on_hand'] = 0; // stock only changes through transactions
            $newId = dbInsert($conn, 'resources', $data);
            logAudit($conn, $currentUserId, 'Added resource', 'resources', null, resSummary($data), $data['resource_code']);
        }
        header('Location: resource_monitor.php?saved=1'); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $id ? 'Edit Resource' : 'Register Resource' ?></title>
    <link rel="stylesheet" href="<?= resEsc(resCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>
<main>
    <h1><?= $id ? 'Edit Resource' : 'Register Resource' ?></h1>
    <?php foreach ($errors as $err): ?><div class="error-box"><?= resEsc($err) ?></div><?php endforeach; ?>

    <?php if (!$categories): ?>
        <div class="error-box">There are no categories yet. <a href="resource_categories.php">Add a category first.</a></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="resource_id" value="<?= (int) $id ?>">

        <?php if ($resource): ?>
            <p>Code: <strong><?= resEsc($resource['resource_code']) ?></strong>
               | On hand: <strong><?= (int) $resource['quantity_on_hand'] ?> <?= resEsc($resource['unit']) ?></strong>
               (changes only through <a href="resource_transaction.php?resource_id=<?= (int) $id ?>">receive or release</a>)</p>
        <?php endif; ?>

        <label>Resource name <input type="text" name="resource_name" maxlength="100" value="<?= resEsc($f['resource_name']) ?>"></label>

        <label>Category
            <select name="category_id">
                <option value="">-- choose --</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= (int) $c['category_id'] ?>" <?= (string) $f['category_id'] === (string) $c['category_id'] ? 'selected' : '' ?>><?= resEsc($c['category_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Unit (pcs, kg, box...) <input type="text" name="unit" maxlength="20" value="<?= resEsc($f['unit']) ?>"></label>
        <label>Minimum stock level <input type="number" name="min_stock_level" min="0" value="<?= resEsc($f['min_stock_level']) ?>"></label>
        <label>Storage location <input type="text" name="storage_location" maxlength="100" value="<?= resEsc($f['storage_location']) ?>"></label>
        <label>Expiration date (optional) <input type="date" name="expiration_date" value="<?= resEsc($f['expiration_date']) ?>"></label>

        <?php if ($id): ?>
        <label>Status
            <select name="status">
                <?php foreach (['Active', 'Inactive'] as $s): ?>
                    <option value="<?= $s ?>" <?= $f['status'] === $s ? 'selected' : '' ?>><?= $s ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>

        <p><button type="submit"><?= $id ? 'Update' : 'Register' ?></button>
           <a href="resource_monitor.php">Back to inventory</a></p>
    </form>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
