<link rel="stylesheet" href="fonts.css?v=10">
<link rel="stylesheet" href="app_extra.css?v=10">
<link rel="stylesheet" href="theme.css?v=10">
<link rel="icon" type="image/png" href="logo.png">
<?php
// Side navigation. Each link lists the page-name prefixes that belong to it, so the right link lights up
// on its register, edit and detail pages too. Pages that only an administrator can open are listed only for an administrator.
$navUser = function_exists('authUser') ? authUser() : null;
$navIsAdmin = function_exists('authIsAdmin') && authIsAdmin();
$navSections = [
    'Main' => [
        ['index.php', 'Home', ['index']],
        ['dashboard.php', 'Dashboard', ['dashboard']],
    ],
    'Disasters' => [
        ['disaster_types.php', 'Disaster Types', ['disaster_types', 'demo_disaster_types']],
        ['disaster_monitor.php', 'Disasters', ['disaster_monitor', 'disaster_register']],
        ['disaster_affected_residents.php', 'Affected Residents', ['disaster_affected']],
    ],
    'People' => [
        ['household_monitor.php', 'Households', ['household_']],
        ['resident_monitor.php', 'Residents', ['resident_']],
    ],
    'Evacuation' => [
        ['evacuation_center_monitor.php', 'Evacuation Centers', ['evacuation_center']],
        ['evacuee_monitor.php', 'Evacuees', ['evacuee_']],
    ],
    'Supplies' => [
        ['resource_monitor.php', 'Resources', ['resource_']],
        ['relief_monitor.php', 'Relief Stock', ['relief_monitor', 'relief_item', 'relief_receipt']],
    ],
    'Distribution' => [
        ['relief_request_monitor.php', 'Requests', ['relief_request']],
        ['relief_distribution_monitor.php', 'Given Out', ['relief_distribution', 'relief_release', 'relief_acknowledge']],
    ],
    'Reports' => [
        ['report_index.php', 'Reports', ['report_']],
    ],
];
if ($navIsAdmin) {
    $navSections['Administration'] = [
        ['integration_check.php', 'System Check', ['integration_check']],
        ['business_rules.php', 'Business Rules', ['business_rules']],
        ['users.php', 'Users', ['users']],
        ['audit_trail.php', 'Audit Trail', ['audit_trail']],
    ];
}
$navCurrent = basename($_SERVER['SCRIPT_NAME'] ?? '');
$navActive = null;
foreach ($navSections as $items) {
    foreach ($items as $item) {
        foreach ($item[2] as $prefix) {
            if (strpos($navCurrent, $prefix) === 0) { $navActive = $item[0]; break 3; }
        }
    }
}
$navEsc = fn($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
?>
<script>document.body.classList.add('has-sidebar');</script>
<div class="topbar no-print">
    <button type="button" class="menu-btn" aria-label="Open menu" aria-expanded="false"
            onclick="var s=document.getElementById('sidebar');var o=s.classList.toggle('open');this.setAttribute('aria-expanded',o);">&#9776; Menu</button>
    <span class="topbar-title">Disaster Management System</span>
</div>
<aside class="sidebar no-print" id="sidebar">
    <a class="side-brand" href="index.php">
        <span class="side-logo"><img src="logo.png" alt="DMS logo" width="42" height="55"></span>
        <span class="side-brand-text">
            <span class="side-brand-name">Disaster Management System</span>
        </span>
    </a>
    <nav class="side-nav" aria-label="Main menu">
        <?php foreach ($navSections as $title => $items): ?>
            <div class="side-group"><?= $navEsc($title) ?></div>
            <?php foreach ($items as $item): ?>
                <a href="<?= $navEsc($item[0]) ?>" class="<?= $item[0] === $navActive ? 'active' : '' ?>"
                   <?= $item[0] === $navActive ? 'aria-current="page"' : '' ?>><?= $navEsc($item[1]) ?></a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
    <?php if ($navUser): ?>
    <div class="side-user">
        <div class="side-user-row">
            <span class="side-avatar"><?= $navEsc(strtoupper(mb_substr($navUser['username'], 0, 1))) ?></span>
            <span class="side-user-text">
                <strong><?= $navEsc($navUser['username']) ?></strong>
                <small><?= $navEsc($navUser['role']) ?></small>
            </span>
        </div>
        <div class="side-user-links">
            <a href="change_password.php">Change password</a>
            <a href="logout.php">Log out</a>
        </div>
    </div>
    <?php endif; ?>
</aside>
