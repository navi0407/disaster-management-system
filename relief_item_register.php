<?php
require_once 'config/db.php';
require_once 'includes/crud.php';
require_once 'includes/audit.php';
require_once 'includes/relief_helpers.php';
if (session_status() === PHP_SESSION_NONE) session_start();
$currentUserId = relCurrentUserId();

$errors = [];
$id = (int) ($_POST['relief_item_id'] ?? $_GET['id'] ?? 0);
$item = null;
if ($id > 0) {
    $item = dbGetById($conn, 'relief_items', 'relief_item_id', $id);
    if (!$item) { $errors[] = 'That relief item does not exist.'; $id = 0; }
}

$f = [
    'item_name'       => $item['item_name'] ?? '',
    'description'     => $item['description'] ?? '',
    'unit'            => $item['unit'] ?? '',
    'min_stock_level' => $item['min_stock_level'] ?? '0',
];

function relSummary($d) {
    return ($d['item_name'] ?? '') . ' | ' . ($d['description'] ?? '') . ' | unit ' . ($d['unit'] ?? '') . ' | min ' . ($d['min_stock_level'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach (['item_name', 'description', 'unit', 'min_stock_level'] as $k) $f[$k] = trim($_POST[$k] ?? '');

    if ($f['item_name'] === '') $errors[] = 'Item name is required.';
    elseif (mb_strlen($f['item_name']) > 100) $errors[] = 'Item name must be 100 characters or less.';
    if (mb_strlen($f['description']) > 255) $errors[] = 'Description must be 255 characters or less.';
    if ($f['unit'] === '') $errors[] = 'Unit is required (for example pcs, kg, box).';
    elseif (mb_strlen($f['unit']) > 20) $errors[] = 'Unit must be 20 characters or less.';
    if ($f['min_stock_level'] === '' || !ctype_digit($f['min_stock_level'])) $errors[] = 'Minimum stock level must be a whole number, 0 or more.';

    if (!$errors) {
        $dup = relQuery($conn,
            "SELECT COUNT(*) AS total FROM relief_items WHERE LOWER(item_name) = LOWER(?) AND relief_item_id <> ?",
            'si', [$f['item_name'], $id]);
        if ((int) $dup[0]['total'] > 0) $errors[] = 'A relief item with that name already exists.';
    }

    if (!$errors) {
        $data = [
            'item_name'       => $f['item_name'],
            'description'     => $f['description'] !== '' ? $f['description'] : null,
            'unit'            => $f['unit'],
            'min_stock_level' => (int) $f['min_stock_level'],
        ];
        if ($id) {
            dbUpdate($conn, 'relief_items', 'relief_item_id', $id, $data);
            logAudit($conn, $currentUserId, 'Updated relief item', 'relief_items', relSummary($item), relSummary($data), 'RLI-' . $id);
        } else {
            $newId = dbInsert($conn, 'relief_items', $data);
            logAudit($conn, $currentUserId, 'Added relief item', 'relief_items', null, relSummary($data), 'RLI-' . $newId);
        }
        header('Location: relief_monitor.php?saved=1'); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $id ? 'Edit Relief Item' : 'Register Relief Item' ?></title>
    <link rel="stylesheet" href="<?= relEsc(relCssPath()) ?>">
</head>
<body>
<?php include 'includes/header.php'; ?>
<main>
    <h1><?= $id ? 'Edit Relief Item' : 'Register Relief Item' ?></h1>
    <?php foreach ($errors as $err): ?><div class="error-box"><?= relEsc($err) ?></div><?php endforeach; ?>

    <form method="post">
        <input type="hidden" name="relief_item_id" value="<?= (int) $id ?>">

        <?php if ($item): ?>
            <p>In stock: <strong><?= relStock($conn, $id) ?> <?= relEsc($item['unit']) ?></strong>
               (changes only when you <a href="relief_receipt.php?relief_item_id=<?= (int) $id ?>">record a receipt</a>)</p>
        <?php endif; ?>

        <label>Item name <input type="text" name="item_name" maxlength="100" value="<?= relEsc($f['item_name']) ?>"></label>
        <label>Description (optional) <input type="text" name="description" maxlength="255" value="<?= relEsc($f['description']) ?>"></label>
        <label>Unit (pcs, kg, box...) <input type="text" name="unit" maxlength="20" value="<?= relEsc($f['unit']) ?>"></label>
        <label>Minimum stock level <input type="number" name="min_stock_level" min="0" value="<?= relEsc($f['min_stock_level']) ?>"></label>

        <p><button type="submit"><?= $id ? 'Update' : 'Register' ?></button>
           <a href="relief_monitor.php">Back to relief inventory</a></p>
    </form>
</main>
<?php include 'includes/footer.php'; ?>
</body>
</html>
