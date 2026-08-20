<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/user_permissions.php';
require_once __DIR__ . '/ticket_assignment.php';
$csrfToken = csrf_token();

$user_id = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
$user_email = 'Account';
$user_name = 'Account';
$user_company = (string) ($_SESSION['company'] ?? '');
$user_department = (string) ($_SESSION['department'] ?? '');
$user_region = (string) ($_SESSION['region'] ?? '');
$tmUserPermissions = user_permissions_defaults();

if ($user_id > 0 && isset($conn)) {
    user_permissions_ensure_table($conn);
    $tmUserPermissions = user_permissions_get_for_user($conn, $user_id);
    $hasNavbarRegionColumn = false;
    $navbarRegionColumnRes = $conn->query("SHOW COLUMNS FROM users LIKE 'region'");
    if ($navbarRegionColumnRes && $navbarRegionColumnRes->num_rows > 0) {
        $hasNavbarRegionColumn = true;
    }
    $user_query = $conn->query("SELECT name, email, company, department" . ($hasNavbarRegionColumn ? ", region" : "") . " FROM users WHERE id = $user_id");
    if ($user_query && $user_query->num_rows > 0) {
        $user_row = $user_query->fetch_assoc();
        $user_email = trim((string) ($user_row['email'] ?? ''));
        $user_name = trim((string) ($user_row['name'] ?? ''));
        $user_company = trim((string) ($user_row['company'] ?? $user_company));
        $user_department = trim((string) ($user_row['department'] ?? $user_department));
        $user_region = $hasNavbarRegionColumn ? trim((string) ($user_row['region'] ?? $user_region)) : $user_region;
        if ($user_company !== '') $_SESSION['company'] = $user_company;
        if ($user_department !== '') $_SESSION['department'] = $user_department;
        $_SESSION['region'] = $user_region;
        if ($user_name === '') {
            $user_name = $user_email !== '' ? $user_email : 'Account';
        }
    }
}

// Helper to check active link
function isActive($page) {
    $current = basename($_SERVER['PHP_SELF']);
    // Handle main pages
    if ($current == $page) {
        return 'active';
    }
    // Handle sub-pages
    if ($page == 'my_tickets.php' && ($current == 'view_ticket.php' || $current == 'view_tickets_user.php')) {
        return 'active';
    }
    if ($page == 'knowledge_base.php' && $current == 'view_article.php') {
        return 'active';
    }
    if ($page == 'feedback.php' && $current == 'feedback.php') {
        return 'active';
    }
    if ($page == 'book_conference.php' && $current == 'book_conference.php') {
        return 'active';
    }
    if ($page == 'analytics.php' && $current == 'analytics.php') {
        return 'active';
    }
    if ($page == 'sales_analytics.php' && $current == 'sales_analytics.php') {
        return 'active';
    }
    return '';
}

$employeeNavItems = [
    ['key' => 'dashboard', 'page' => 'dashboard.php', 'label' => 'Dashboard'],
    ['key' => 'create_ticket', 'page' => 'request_ticket.php', 'label' => 'Create Ticket'],
    ['key' => 'all_ticket', 'page' => 'my_task.php', 'label' => 'Assigned Tickets'],
    ['key' => 'my_tickets', 'page' => 'my_tickets.php', 'label' => 'My Submitted Tickets'],
    ['key' => 'feedback', 'page' => 'feedback.php', 'label' => 'Feedback'],
    ['key' => 'knowledge_base', 'page' => 'knowledge_base.php', 'label' => 'Knowledge Base'],
    ['key' => 'conference_booking', 'page' => 'book_conference.php', 'label' => 'Conference Booking'],
    ['key' => 'analytics', 'page' => 'analytics.php', 'label' => 'Analytics'],
];

$isLapcSalesEmployee = function_exists('ticket_normalize_company')
    && ticket_normalize_company($user_company) === '@leadsagri.com'
    && strcasecmp($user_department, 'Sales') === 0
    && $user_region !== '';
$employeeViewMode = $isLapcSalesEmployee ? (string) ($_SESSION['employee_view_mode'] ?? 'employee') : 'employee';
if (!in_array($employeeViewMode, ['employee', 'manager'], true)) {
    $employeeViewMode = 'employee';
}
if (!$isLapcSalesEmployee) {
    unset($_SESSION['employee_view_mode']);
}
if ($isLapcSalesEmployee && $employeeViewMode === 'manager') {
    $employeeNavItems = [
        ['key' => 'dashboard', 'page' => 'dashboard.php', 'label' => 'Dashboard'],
        ['key' => 'sales_submitted_tickets', 'page' => 'sales_submitted_tickets.php', 'label' => ' Submitted Tickets'],
        ['key' => 'sales_manager_analytics', 'page' => 'sales_analytics.php', 'label' => 'Analytics'],
    ];
}

$currentEmployeePage = basename($_SERVER['PHP_SELF'] ?? '');
// The dashboard currently renders this same sidebar beside the shared navbar.
// Every other employee page gets it directly from this include.
$showSharedMobileSidebar = $currentEmployeePage !== 'dashboard.php';
?>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style id="employee-navbar-critical-logo-styles">
.navbar {
    border-bottom: 4px solid #F4C430;
}

.navbar .logo-icon {
    width: 56px;
    height: 56px;
    max-width: 56px;
    flex: 0 0 56px;
    object-fit: contain;
}

.mobile-sidebar-header img {
    width: 36px;
    height: 36px;
    max-width: 36px;
    object-fit: contain;
}

.mobile-sidebar,
.mobile-sidebar-overlay,
.notification-dropdown {
    display: none;
}

@media (min-width: 769px) {
    .navbar,
    body.employee-analytics-page .navbar {
        display: grid !important;
        grid-template-columns: 282px minmax(0, 1fr) auto !important;
        align-items: center !important;
        column-gap: 12px !important;
        padding: 14px 56px 14px 28px !important;
    }

    .navbar .nav-left,
    body.employee-analytics-page .navbar .nav-left {
        width: 282px !important;
        min-width: 282px !important;
        max-width: 282px !important;
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
    }

    .navbar .brand-name,
    body.employee-analytics-page .navbar .brand-name {
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }

    .navbar .navbar-collapse,
    body.employee-analytics-page .navbar .navbar-collapse {
        min-width: 0 !important;
        width: 100% !important;
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) auto !important;
        align-items: center !important;
        column-gap: 14px !important;
        grid-column: 2 / 4 !important;
    }

    .navbar .nav-center,
    body.employee-analytics-page .navbar .nav-center {
        min-width: 0 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: clamp(8px, 1vw, 18px) !important;
    }

    .navbar .nav-link,
    body.employee-analytics-page .navbar .nav-link {
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        white-space: nowrap !important;
        min-height: 40px !important;
        padding: 8px 10px !important;
        font-size: 13px !important;
        line-height: 1.15 !important;
        box-sizing: border-box !important;
    }

    .navbar .nav-right,
    body.employee-analytics-page .navbar .nav-right {
        width: auto !important;
        min-width: 188px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: flex-end !important;
        gap: 18px !important;
        padding-right: 0 !important;
    }

    .navbar .notification-wrapper,
    body.employee-analytics-page .navbar .notification-wrapper {
        position: relative !important;
        margin-right: 0 !important;
    }

    .navbar .user-btn-name,
    body.employee-analytics-page .navbar .user-btn-name {
        max-width: 120px !important;
    }
}

.tm-global-chat-fab,
.priority-escalation-toast-host {
    visibility: hidden;
}

@media (max-width: 768px) {
    .navbar .logo-icon {
        width: 36px;
        height: 36px;
        max-width: 36px;
        flex-basis: 36px;
    }
}
</style>
<nav class="navbar">
    <div class="nav-left">
        <img src="../assets/img/UPDATEDlogo.png" alt="Leads Agri Logo" class="logo-icon" width="56" height="56">
        <div class="brand-name">Leads DeskMetamorph</div>
        <button class="navbar-toggler" id="navbarToggler">
            <i class="fas fa-bars"></i>
        </button>
    </div>

    <div class="navbar-collapse" id="navbarCollapse">
        <div class="nav-center">
            <?php foreach ($employeeNavItems as $navItem): ?>
                <?php
                    $permissionKey = (string) ($navItem['key'] ?? '');
                    $isVisible = !array_key_exists($permissionKey, $tmUserPermissions) || (int) $tmUserPermissions[$permissionKey] === 1;
                    if (!$isVisible) {
                        continue;
                    }
                ?>
                <a href="<?= htmlspecialchars((string) $navItem['page'], ENT_QUOTES, 'UTF-8'); ?>" class="nav-link <?= isActive((string) $navItem['page']) ?>">
                    <?= htmlspecialchars((string) $navItem['label'], ENT_QUOTES, 'UTF-8'); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="nav-right">
            <!-- Notification Bell -->
            <div class="notification-wrapper">
                <div class="notification-bell" id="notifBell">
                    <i class="fas fa-bell"></i>
                    <span class="notification-dot" id="notifDot" style="display: none;"></span>
                    <span class="notification-badge" id="notifBadge" style="display: none;">0</span>
                </div>
                <div class="notification-dropdown" id="notifDropdown">
                    <div class="notif-header">
                        <i class="fas fa-bell" style="color: #16a34a;"></i>
                        <span>Notifications</span>
                    </div>
                    <div class="notif-list" id="notifList">
                        <div class="notif-empty">No notifications</div>
                    </div>
                    <div class="notif-footer">
                        <a href="notifications.php">View All Notifications</a>
                    </div>
                </div>
            </div>

            <div class="user-menu">
                <button type="button" class="user-btn" aria-label="<?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8'); ?>" onclick="window.toggleEmployeeUserMenu && window.toggleEmployeeUserMenu(event)">
                    <i class="fas fa-user"></i>
                    <span class="user-btn-name"><?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8'); ?></span>
                    <i class="fas fa-chevron-down" style="font-size: 10px;"></i>
                </button>
                <div class="user-dropdown">
                    <a href="my_profile.php" class="dropdown-item">My Profile</a>
                    <?php if ($isLapcSalesEmployee): ?>
                        <div class="employee-view-switcher">
                            <div class="employee-view-label">View as</div>
                            <a href="set_view_mode.php?mode=manager" class="dropdown-item employee-view-option <?= $employeeViewMode === 'manager' ? 'is-active' : ''; ?>">
                                <span>Manager View</span>
                                <?php if ($employeeViewMode === 'manager'): ?><i class="fas fa-check"></i><?php endif; ?>
                            </a>
                            <a href="set_view_mode.php?mode=employee" class="dropdown-item employee-view-option <?= $employeeViewMode === 'employee' ? 'is-active' : ''; ?>">
                                <span>Employee View</span>
                                <?php if ($employeeViewMode === 'employee'): ?><i class="fas fa-check"></i><?php endif; ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    <a href="logout.php" class="dropdown-item">Logout</a>
                </div>
            </div>
        </div>
    </div>
</nav>

<?php if ($showSharedMobileSidebar): ?>
<script>
document.body && document.body.classList.add('employee-shared-mobile-sidebar-page');
</script>
<div id="mobileSidebar" class="mobile-sidebar" aria-hidden="true">
    <div class="mobile-sidebar-footer mobile-sidebar-header-actions" aria-label="Notifications and account">
        <a href="notifications.php" class="mobile-sidebar-icon-link" aria-label="Notifications">
            <i class="fas fa-bell"></i>
            <span id="mobileSidebarNotifBadge" class="mobile-sidebar-badge"></span>
        </a>
        <div class="mobile-sidebar-user">
            <button type="button" id="mobileSidebarUserBtn" class="mobile-sidebar-user-btn" aria-label="Account menu">
                <i class="fas fa-user"></i>
                <i class="fas fa-chevron-down" style="font-size: 11px;"></i>
            </button>
            <div id="mobileSidebarUserMenu" class="mobile-sidebar-user-menu">
                <a href="my_profile.php">My Profile</a>
                <a href="logout.php">Logout</a>
            </div>
        </div>
    </div>
    <?php foreach ($employeeNavItems as $navItem): ?>
        <?php
            $permissionKey = (string) ($navItem['key'] ?? '');
            if (in_array($permissionKey, ['analytics', 'sales_manager_analytics'], true)) {
                continue;
            }
            $isVisible = !array_key_exists($permissionKey, $tmUserPermissions) || (int) $tmUserPermissions[$permissionKey] === 1;
            if (!$isVisible) {
                continue;
            }
        ?>
        <a href="<?= htmlspecialchars((string) $navItem['page'], ENT_QUOTES, 'UTF-8'); ?>" class="<?= isActive((string) $navItem['page']); ?>">
            <?= htmlspecialchars((string) $navItem['label'], ENT_QUOTES, 'UTF-8'); ?>
        </a>
    <?php endforeach; ?>
</div>

<div id="mobileSidebarOverlay" class="mobile-sidebar-overlay" aria-hidden="true"></div>
<?php endif; ?>

<div id="priorityEscalationToastHost" class="priority-escalation-toast-host" aria-live="polite" aria-atomic="true"></div>

<script>
window.TM_CSRF_TOKEN = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
window.TM_MESSENGER_STYLE = 'employee';
</script>

<button type="button" id="globalChatFab" class="tm-global-chat-fab" onclick="window.TMGlobalChat && window.TMGlobalChat.open()">
    <i class="fas fa-comments"></i>
    <span class="tm-global-chat-label">Chat</span>
    <span id="globalChatBadge" class="chat-badge"></span>
</button>

<style>
@media (max-width: 768px) {
    body {
        zoom: 0.78;
    }
}

.navbar {
    border-bottom: 4px solid #F4C430;
}

.navbar,
.navbar *,
.navbar *::before,
.navbar *::after {
    transition: none !important;
    animation: none !important;
}

.navbar .nav-link,
.navbar .nav-link:hover,
.navbar .nav-link.active,
.navbar .notification-bell,
.navbar .notification-bell:hover,
.navbar .user-btn,
.navbar .user-btn:hover {
    transform: none !important;
}

@media (min-width: 769px) {
    .navbar {
        display: grid !important;
        grid-template-columns: 282px minmax(0, 1fr) auto !important;
        align-items: center !important;
        column-gap: 12px !important;
        padding: 14px 56px 14px 28px !important;
    }

    .navbar .nav-left {
        width: 282px !important;
        min-width: 282px !important;
        max-width: 282px !important;
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
    }

    .navbar .brand-name {
        font-size: 18px !important;
        line-height: 1.18 !important;
        max-width: none !important;
        white-space: nowrap !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
    }

    .navbar .navbar-collapse {
        min-width: 0 !important;
        width: 100% !important;
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) auto !important;
        align-items: center !important;
        column-gap: 14px !important;
        grid-column: 2 / 4 !important;
    }

    .navbar .nav-center {
        min-width: 0 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: flex-start !important;
        gap: clamp(8px, 1vw, 18px) !important;
    }

    .navbar .nav-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        white-space: nowrap;
        text-align: center;
        line-height: 1.15;
        min-height: 40px;
        padding: 8px 10px !important;
        border: 1px solid transparent;
        font-size: 13px !important;
        font-weight: 600 !important;
        box-sizing: border-box !important;
    }

    .navbar .nav-link.active {
        border: 1px solid #F4C430 !important;
        border-radius: 999px !important;
        padding: 8px 18px !important;
        color: #F4C430 !important;
        background: rgba(165, 214, 167, 0.18) !important;
    }

    .navbar .nav-right {
        width: auto !important;
        min-width: 188px !important;
        display: flex !important;
        align-items: center !important;
        justify-content: flex-end !important;
        gap: 18px !important;
        padding-right: 0 !important;
    }

    .navbar .notification-wrapper {
        margin-right: 0;
    }

    .navbar .user-btn {
        padding: 8px 16px !important;
    }

    .navbar .user-btn-name {
        max-width: 120px !important;
    }
}

@media (min-width: 769px) and (max-width: 1280px) {
    .navbar {
        grid-template-columns: 238px minmax(0, 1fr) auto !important;
        column-gap: 10px !important;
        padding: 14px 40px 14px 22px !important;
    }

    .navbar .nav-left {
        width: 238px !important;
        min-width: 238px !important;
        max-width: 238px !important;
    }

    .navbar .brand-name {
        font-size: 16px !important;
    }

    .navbar .nav-center {
        gap: clamp(6px, 0.75vw, 12px) !important;
    }

    .navbar .nav-link {
        min-height: 36px;
        padding-inline: 6px !important;
        font-size: 12px !important;
    }

    .navbar .nav-right {
        min-width: 176px !important;
        gap: 14px !important;
        padding-right: 0 !important;
    }

    .navbar .user-btn-name {
        max-width: 104px !important;
    }
}

@media (min-width: 769px) and (max-width: 1100px) {
    .navbar {
        grid-template-columns: 210px minmax(0, 1fr) auto !important;
        column-gap: 10px !important;
        padding: 14px 30px 14px 18px !important;
    }

    .navbar .nav-left {
        width: 210px !important;
        min-width: 210px !important;
        max-width: 210px !important;
    }

    .navbar .brand-name {
        font-size: 13px !important;
    }

    .navbar .nav-center {
        gap: 6px !important;
    }

    .navbar .nav-link {
        min-height: 34px;
        padding-inline: 6px !important;
        font-size: 11px !important;
    }

    .navbar .navbar-collapse {
        column-gap: 10px !important;
    }

    .navbar .nav-right {
        width: auto !important;
        min-width: 164px !important;
        gap: 10px !important;
        padding-right: 0 !important;
    }

    .navbar .user-btn {
        padding-inline: 10px !important;
    }

    .navbar .user-btn-name {
        max-width: 86px !important;
    }
}

/* Notification Styles */
.notification-wrapper {
    position: relative;
    margin-right: 15px;
}

.notification-bell {
    position: relative;
    cursor: pointer;
    font-size: 1.2rem;
    color: white;
    padding: 8px;
    transition: transform 0.2s;
}

.notification-bell:hover {
    transform: scale(1.1);
}

.notification-dot {
    position: absolute;
    top: 5px;
    right: 5px;
    width: 8px;
    height: 8px;
    background-color: #ff4444;
    border-radius: 50%;
    border: 2px solid #1B5E20; /* Match navbar bg */
}

.notification-badge {
    position: absolute;
    top: -2px;
    right: -10px;
    background-color: #ff4444;
    color: white;
    font-size: 0.7rem;
    padding: 2px 5px;
    border-radius: 10px;
    font-weight: bold;
    border: 2px solid #1B5E20;
}

.notification-dropdown {
    position: absolute;
    top: 50px;
    right: -10px;
    width: 380px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.15);
    display: none;
    z-index: 1000;
    overflow: hidden;
    animation: slideDown 0.2s ease-out;
    border: none;
}

.notification-dropdown.show {
    display: block;
}

.notif-header {
    background: #fff;
    padding: 16px 20px;
    border-bottom: 1px solid #f0f0f0;
    font-weight: 700;
    font-size: 1.1rem;
    color: #1e293b;
    display: flex;
    align-items: center;
    gap: 10px;
}

.notif-list {
    max-height: 400px;
    overflow-y: auto;
    background: #fff;
}
.notif-section-label {
    position: sticky;
    top: 0;
    z-index: 2;
    padding: 10px 16px 8px;
    background: #ffffff;
    border-bottom: 1px solid #eef2f7;
    font-size: 0.9rem;
    font-weight: 500;
    color: #475569;
}

.notif-item {
    --notif-accent: transparent;
    position: relative;
    display: flex;
    align-items: flex-start;
    padding: 16px 40px 16px 26px;
    border-bottom: 1px solid #f1f5f9;
    cursor: pointer;
    transition: all 0.2s ease;
    gap: 0;
}
.notif-item::before {
    content: "";
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 5px;
    background: var(--notif-accent);
}
.notif-item.variant-assign::before,
.notif-item.variant-close::before,
.notif-item.variant-low::before { --notif-accent: #22c55e; }
.notif-item.variant-note::before { --notif-accent: #f59e0b; }
.notif-item.variant-medium::before { --notif-accent: #eab308; }
.notif-item.variant-high::before { --notif-accent: #ef4444; }
.notif-item.variant-critical::before { --notif-accent: #E53935; }
.notif-item.variant-update::before { --notif-accent: #0f766e; }
.notif-item.variant-booking::before { --notif-accent: #0f766e; }
.notif-item.variant-reassign::before { --notif-accent: #9333ea; }

.notif-item:hover {
    background-color: #f8fafc;
}
.notif-item.notif-chat-pending {
    display: flex;
    align-items: flex-start;
    gap: 0;
    margin: 0;
    padding: 16px 40px 16px 26px;
    border: 0;
    border-bottom: 1px solid #f1f5f9;
    border-radius: 0;
    box-shadow: none;
    background: #ffffff;
    overflow: hidden;
}
.notif-item.notif-chat-pending:hover {
    background-color: #f8fbff;
}
.notif-item.notif-chat-pending::before {
    content: "";
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 5px;
    background: #1B5E20 !important;
    border-radius: 0;
}
.notif-item.unread {
    background-color: #ffffff;
    padding-right: 58px;
}
.notif-unread-dot {
    position: absolute;
    right: 18px;
    top: 50%;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #5aa364;
    transform: translateY(-50%);
    box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.96);
    pointer-events: none;
    z-index: 1;
}
.notif-item.unread.variant-assign,
.notif-item.unread.variant-close,
.notif-item.unread.variant-low {
    background: #ecfdf5;
}
.notif-item.unread.variant-medium {
    background: #fffbeb;
}
.notif-item.unread.variant-medium .notif-unread-dot {
    background: #eab308;
}
.notif-item.unread.variant-high {
    background: #fef2f2;
}
.notif-item.unread.variant-high .notif-unread-dot {
    background: #ef4444;
}
.notif-item.unread.variant-note {
    background: #fff8ef;
}
.notif-item.unread.variant-critical {
    background: #fff4f5;
}
.notif-item.unread.variant-critical .notif-unread-dot {
    background: #E53935;
}
.notif-item.unread.variant-update {
    background: #f0fdfa;
}
.notif-item.unread.variant-booking {
    background: #f4fbf7;
}
.notif-item.unread.variant-reassign {
    background: #faf5ff;
}
.notif-item.unread.variant-reassign .notif-unread-dot {
    background: #9333ea;
}
.notif-item.priority-escalation {
    position: relative;
    gap: 0;
    margin: 0;
    padding: 12px 40px 12px 24px;
    border: 1px solid #fecaca;
    border-width: 0 0 1px 0;
    border-radius: 0;
    background: linear-gradient(135deg, #fff7f7 0%, #fffafa 70%, #ffffff 100%);
    box-shadow: none;
    overflow: hidden;
}
.notif-item.priority-escalation::before {
    content: "";
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 4px;
    border-radius: 0;
    background: #ff1f2d;
}
.notif-item.priority-escalation.priority-low::before { --notif-accent: #22c55e; }
.notif-item.priority-escalation.priority-medium::before { --notif-accent: #eab308; }
.notif-item.priority-escalation.priority-high::before { --notif-accent: #ef4444; }
.notif-item.priority-escalation.priority-critical::before { --notif-accent: #E53935; }
.notif-item.priority-escalation.variant-update::before { --notif-accent: #d4a017; }
.notif-item.priority-escalation.unread .notif-unread-dot {
    right: 20px;
    background: #ff1f2d;
}

.notif-pill {
    display: inline-flex;
    align-items: center;
    border-radius: 11px;
    border: 2px solid currentColor;
    background: #ffffff;
    color: #64748b;
    overflow: hidden;
    min-height: 26px;
}
.notif-pill-icon {
    width: 28px;
    height: 26px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    color: #ffffff;
    font-weight: 800;
}
.notif-pill-text {
    padding: 0 16px 0 12px;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 0.01em;
    line-height: 1;
}
.notif-pill.variant-assign,
.notif-pill.variant-close,
.notif-pill.variant-low {
    color: #16a34a;
    background: #ecfdf5;
}
.notif-pill.variant-assign .notif-pill-icon,
.notif-pill.variant-close .notif-pill-icon,
.notif-pill.variant-low .notif-pill-icon {
    background: linear-gradient(135deg, #4ade80, #22c55e);
}
.notif-pill.variant-medium {
    color: #eab308;
    background: #fffbeb;
}
.notif-pill.variant-medium .notif-pill-icon {
    background: linear-gradient(135deg, #facc15, #eab308);
}
.notif-pill.variant-high {
    color: #ef4444;
    background: #fef2f2;
}
.notif-pill.notif-priority-breach-pill {
    min-height: 24px;
    border: 1px solid #ff3b45;
    border-radius: 7px;
    color: #ff2634;
    background: #fff7f7;
}
.notif-pill.notif-priority-breach-pill .notif-pill-icon {
    width: 28px;
    height: 22px;
    color: #ffffff;
    background: #ff3b45;
    border-right: 1px solid #ff3b45;
    font-size: 13px;
}
.notif-pill.notif-priority-breach-pill .notif-pill-text {
    padding: 0 8px;
    font-size: 10px;
    font-weight: 900;
    white-space: nowrap;
}
.notif-pill.variant-note {
    color: #f59e0b;
    background: #fff8ef;
}
.notif-pill.variant-high .notif-pill-icon {
    background: linear-gradient(135deg, #fb7185, #ef4444);
}
.notif-pill.variant-note .notif-pill-icon {
    background: linear-gradient(135deg, #fcd34d, #f59e0b);
}
.notif-pill.variant-critical {
    color: #E53935;
    background: #fff4f5;
}
.notif-pill.variant-critical .notif-pill-icon {
    background: linear-gradient(135deg, #ff7d7d, #E53935);
}
.notif-pill.variant-update {
    color: #0f766e;
    background: #f0fdfa;
}
.notif-pill.variant-update .notif-pill-icon {
    background: linear-gradient(135deg, #34d399, #0f766e);
}
.notif-item.priority-escalation .notif-pill.variant-update {
    color: #d4a017;
    background: #fff9db;
}
.notif-item.priority-escalation .notif-pill.variant-update .notif-pill-icon {
    background: linear-gradient(135deg, #fcd34d, #f59e0b);
}
.notif-pill.variant-reassign {
    color: #9333ea;
    background: #faf5ff;
}
.notif-pill.variant-reassign .notif-pill-icon {
    background: linear-gradient(135deg, #c084fc, #9333ea);
}
.notif-pill.variant-follow-up {
    color: #7c4a03;
    background: #fff6d8;
}
.notif-pill.variant-follow-up .notif-pill-icon {
    background: linear-gradient(135deg, #fde68a, #f59e0b);
}
.notif-pill.notif-chat-pill {
    min-height: 36px;
    min-width: 36px;
    padding: 0;
    gap: 0;
    border: 0;
    border-radius: 999px;
    color: #ffffff;
    background: #1B5E20;
    box-shadow: 0 6px 14px rgba(27, 94, 32, 0.2);
}
.notif-pill.notif-chat-pill .notif-pill-icon {
    width: 36px;
    height: 36px;
    font-size: 16px;
    background: transparent;
}
.notif-pill.notif-chat-pill .notif-pill-text {
    display: none;
}

.notif-content {
    flex: 1;
    min-width: 0;
}
.notif-item.priority-escalation .notif-content {
    padding-left: 0;
}
.notif-title {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 5px;
    flex-wrap: wrap;
}
.notif-item.priority-escalation .notif-title {
    flex-wrap: wrap;
}
.notif-item.priority-escalation .notif-title-text {
    font-size: 0.9rem;
    font-weight: 800;
}
.notif-item.notif-chat-pending .notif-title {
    gap: 10px;
    margin-bottom: 6px;
}
.notif-title-text {
    font-size: 0.92rem;
    font-weight: 700;
    line-height: 1.3;
    color: #111827;
}
.notif-item.notif-chat-pending .notif-title-text {
    font-size: 0.92rem;
    font-weight: 700;
    color: #111827;
    line-height: 1.3;
}
.notif-item.variant-follow-up {
    background: linear-gradient(180deg, #fffdf4 0%, #fff9e7 100%);
}
.notif-item.variant-follow-up::before {
    background: #f4c542;
}
.notif-item.variant-follow-up.unread .notif-unread-dot {
    background: #f4c542;
}
.notif-item.notif-chat-pending.unread .notif-unread-dot {
    background: #1B5E20;
}
.notif-item.variant-follow-up .notif-title-text {
    color: #111827;
}

.notif-msg {
    font-size: 0.92rem;
    color: #334155;
    line-height: 1.4;
    margin-bottom: 6px;
}
.notif-item.notif-chat-pending .notif-msg {
    font-size: 0.9rem;
    color: #23324f;
    line-height: 1.45;
    margin-bottom: 6px;
}
.notif-item.notif-chat-pending .notif-msg strong {
    color: #1d4f9b;
    font-weight: 700;
}
.notif-item.booking-created-card {
    gap: 12px;
    padding: 14px 40px 14px 16px;
}
.notif-item.booking-created-card .left-pill {
    min-width: 78px;
    display: flex;
    align-items: center;
    gap: 8px;
    align-self: flex-start;
    background: #f0fdfa;
    border-radius: 8px;
    padding: 8px;
    flex: 0 0 auto;
}
.notif-item.booking-created-card .booking-icon {
    width: 20px;
    height: 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: #0f766e;
    font-size: 16px;
}
.notif-item.booking-created-card .pill-label {
    color: #0f766e;
    font-weight: 600;
    font-size: 0.86rem;
}
.notif-item.booking-created-card .notification-body {
    min-width: 0;
    flex: 1;
}
.notif-item.booking-created-card .booking-title {
    margin: 0;
    font-size: 0.95rem;
    font-weight: 800;
    line-height: 1.25;
    color: #111827;
}
.notif-item.booking-created-card .booking-detail {
    margin-top: 6px;
    color: #333333;
    font-size: 0.9rem;
    line-height: 1.35;
}
.notif-item.booking-created-card .notif-time {
    margin-top: 8px;
    color: #1e88e5;
    font-weight: 600;
}
.notif-item.priority-escalation .notif-msg {
    font-size: 0.95rem;
    line-height: 1.4;
    color: #334155;
    margin-bottom: 6px;
}

.notif-keyword {
    display: inline-flex;
    align-items: center;
    padding: 0.08rem 0.45rem;
    border-radius: 999px;
    font-size: 0.83em;
    font-weight: 700;
    line-height: 1.2;
    margin: 0 0.08rem;
    vertical-align: baseline;
}

.notif-keyword-success {
    background: #dcfce7;
    color: #166534;
}

.notif-keyword-info {
    background: #dbeafe;
    color: #1d4ed8;
}

.notif-keyword-assign {
    background: #e0f2fe;
    color: #0284c7;
}

.notif-keyword-reassign {
    background: #f3e8ff;
    color: #7e22ce;
}

.notif-keyword-generic {
    background: #e2e8f0;
    color: #475569;
}

.notif-time {
    font-size: 0.8rem;
    color: #94a3b8;
    display: block;
}
.notif-item.notif-chat-pending .notif-time {
    font-size: 0.82rem;
    color: #94a3b8;
    letter-spacing: 0.01em;
}
.notif-item.priority-escalation .notif-time {
    font-size: 0.8rem;
    color: #94a3b8;
}

.priority-escalation-toast-host {
    position: fixed;
    top: 82px;
    right: 18px;
    z-index: 5000;
    display: flex;
    flex-direction: column;
    gap: 10px;
    pointer-events: none;
    visibility: visible;
}

.priority-escalation-toast {
    min-width: 340px;
    max-width: min(460px, calc(100vw - 36px));
    background: linear-gradient(180deg, #fff7f8 0%, #ffffff 100%);
    color: #3f1d24;
    border-radius: 22px;
    box-shadow: 0 22px 48px rgba(127, 29, 29, 0.18);
    padding: 18px 18px 18px 16px;
    border-left: 6px solid #fb7185;
    pointer-events: auto;
    display: flex;
    align-items: center;
    gap: 14px;
}

.priority-escalation-toast.priority-critical {
    border-left-color: #dc2626;
}

.priority-escalation-toast-icon {
    width: 64px;
    height: 64px;
    border-radius: 18px;
    background: linear-gradient(180deg, #d9465f 0%, #be123c 100%);
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 28px;
    box-shadow: inset 0 1px 0 rgba(255,255,255,0.24);
}

.priority-escalation-toast-body {
    flex: 1;
    min-width: 0;
}

.priority-escalation-toast-title {
    font-size: 14px;
    font-weight: 800;
    margin-bottom: 6px;
    color: #881337;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.priority-escalation-toast-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 58px;
    padding: 5px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 800;
    color: #ffffff;
    letter-spacing: 0.01em;
    background: #fb7185;
}

.priority-escalation-toast-pill.priority-critical {
    background: #dc2626;
}

.priority-escalation-toast-pill.priority-high {
    background: #dc2626;
}

.priority-escalation-toast-message {
    font-size: 15px;
    line-height: 1.5;
    color: #4c1d2a;
}

.priority-escalation-toast-dot {
    width: 14px;
    height: 14px;
    border-radius: 999px;
    background: #fb7185;
    box-shadow: 0 0 0 4px rgba(251, 113, 133, 0.14);
    flex-shrink: 0;
}

.priority-escalation-toast.priority-critical .priority-escalation-toast-dot {
    background: #dc2626;
    box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.12);
}

.priority-escalation-toast.employee-chat-toast {
    background: linear-gradient(180deg, #f2f7ff 0%, #ffffff 100%);
    color: #173a6b;
    border-left-color: #2563eb;
    box-shadow: 0 22px 48px rgba(37, 99, 235, 0.18);
}

.priority-escalation-toast.employee-chat-toast .priority-escalation-toast-icon {
    background: linear-gradient(180deg, #60a5fa 0%, #2563eb 100%);
}

.priority-escalation-toast.employee-chat-toast .priority-escalation-toast-title {
    color: #1d4f9b;
}

.priority-escalation-toast.employee-chat-toast .priority-escalation-toast-pill {
    background: #2563eb;
}

.priority-escalation-toast.employee-chat-toast .priority-escalation-toast-message {
    color: #1f3a63;
}

.priority-escalation-toast.employee-chat-toast .priority-escalation-toast-dot {
    background: #2563eb;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.14);
}

@media (max-width: 640px) {
    .priority-escalation-toast {
        min-width: 0;
        width: calc(100vw - 24px);
        padding: 16px;
        gap: 12px;
    }

    .priority-escalation-toast-icon {
        width: 56px;
        height: 56px;
        border-radius: 16px;
        font-size: 24px;
    }

    .priority-escalation-toast-message {
        font-size: 14px;
    }
}

.notif-footer {
    padding: 12px;
    background: #f8fafc;
    border-top: 1px solid #f1f5f9;
    text-align: center;
}

.notif-footer a {
    color: #16a34a;
    font-weight: 600;
    font-size: 0.9rem;
    text-decoration: none;
    transition: color 0.2s;
}

.notif-footer a:hover {
    color: #15803d;
    text-decoration: underline;
}

.notif-empty {
    padding: 30px;
    text-align: center;
    color: #94a3b8;
    font-style: italic;
}

/* Scrollbar styling */
.notif-list::-webkit-scrollbar {
    width: 6px;
}
.notif-list::-webkit-scrollbar-track {
    background: #f1f5f9;
}
.notif-list::-webkit-scrollbar-thumb {
    background: #cbd5e1;
    border-radius: 3px;
}

@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}

.tm-global-chat-fab {
    position: fixed;
    right: 24px;
    bottom: 24px;
    z-index: 2500;
    background: #1B5E20;
    color: #ffffff;
    border: none;
    border-radius: 999px;
    padding: 12px 16px;
    font-weight: 800;
    display: inline-flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    box-shadow: 0 12px 28px rgba(2, 6, 23, 0.25);
    user-select: none;
    visibility: visible;
}
.tm-global-chat-fab:hover { background: #144a1e; }
.tm-global-chat-fab:active { transform: translateY(1px); }
.tm-global-chat-fab .tm-global-chat-label { font-size: 14px; }
.tm-global-chat-fab .chat-badge {
    position: absolute;
    top: -6px;
    right: -6px;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: #ef4444;
    color: #ffffff;
    font-size: 11px;
    font-weight: 900;
    display: none;
    align-items: center;
    justify-content: center;
    line-height: 1;
}
.tm-global-chat-fab .chat-badge.is-visible { display: inline-flex; }
@media (max-width: 768px) {
    .tm-global-chat-fab {
        right: 12px;
        bottom: 12px;
        width: 42px;
        height: 42px;
        min-width: 42px;
        min-height: 42px;
        padding: 0;
        border-radius: 999px;
        justify-content: center;
        gap: 0;
    }
    .tm-global-chat-fab .tm-global-chat-label { display: none; }
    .tm-global-chat-fab i { font-size: 16px; }
    .tm-global-chat-fab .chat-badge {
        top: -4px;
        right: -4px;
    }
}

/* Employee user pill (match admin style) */
.user-menu {
    position: relative;
    display: inline-block;
    z-index: 20000;
}
.user-btn {
    display: flex;
    align-items: center;
    gap: 8px;
    background: rgba(255, 255, 255, 0.15);
    border: 1px solid rgba(255, 255, 255, 0.3);
    color: #ffffff;
    padding: 8px 16px;
    border-radius: 30px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: all 0.2s ease;
    position: relative;
    z-index: 20001;
    pointer-events: auto;
}
.user-btn:hover { background: rgba(255,255,255,0.25); }
.user-btn-name {
    display: inline-block;
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.user-dropdown {
    position: absolute;
    right: 0;
    top: 50px;
    background: #ffffff;
    min-width: 200px;
    border-radius: 14px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    display: none;
    flex-direction: column;
    overflow: hidden;
    z-index: 20002;
    border: 1px solid #e5e7eb;
    pointer-events: auto;
}
.user-dropdown.show { display: flex; }
body.employee-analytics-page .user-btn,
body.employee-sales-manager-page .user-btn {
    position: relative !important;
    z-index: 20001 !important;
    pointer-events: auto !important;
}
body.employee-analytics-page .user-dropdown,
body.employee-sales-manager-page .user-dropdown {
    z-index: 20002 !important;
}
.user-dropdown .dropdown-item {
    padding: 12px 16px;
    text-decoration: none;
    color: #1f2937;
    font-size: 14px;
    transition: background 0.2s;
    display: block;
    font-weight: 500;
}
.user-dropdown .dropdown-item:hover {
    background: #f9fafb;
    color: #1B5E20;
}
.employee-view-switcher {
    margin: 4px 0;
    padding: 8px 0;
    border-top: 1px solid #eef2f7;
    border-bottom: 1px solid #eef2f7;
}
.employee-view-label {
    padding: 2px 16px 7px;
    color: #64748b;
    font-size: 11px;
    font-weight: 700;
}
.user-dropdown .employee-view-option {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.user-dropdown .employee-view-option span {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.user-dropdown .employee-view-option i {
    font-size: 12px;
}
.user-dropdown .employee-view-option.is-active {
    background: #ecfdf3;
    color: #166534;
}

.mobile-sidebar,
.mobile-sidebar-overlay {
    display: none;
}

@media (max-width: 768px) {
    .navbar {
        display: flex !important;
        align-items: center !important;
        flex-wrap: nowrap !important;
        padding: 12px 18px 12px 14px !important;
    }

    .navbar .nav-left {
        width: 100% !important;
        display: grid !important;
        grid-template-columns: auto minmax(0, 1fr) 44px !important;
        align-items: center !important;
        gap: 10px !important;
    }

    .navbar .logo-icon {
        grid-column: 1;
    }

    .navbar .brand-name {
        grid-column: 2;
        min-width: 0;
    }

    .navbar .navbar-toggler {
        grid-column: 3;
        justify-self: end !important;
        margin-left: 0 !important;
        margin-right: 0 !important;
        width: 44px !important;
        height: 44px !important;
        min-width: 44px !important;
        min-height: 44px !important;
    }

    body.employee-shared-mobile-sidebar-page #navbarCollapse,
    body.employee-shared-mobile-sidebar-page.sidebar-open #navbarCollapse {
        display: none !important;
    }

    body.employee-shared-mobile-sidebar-page.sidebar-open .tm-global-chat-fab {
        opacity: 0;
        pointer-events: none;
        transform: translateY(8px);
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar {
        position: fixed;
        top: 0;
        left: -260px;
        right: auto;
        width: 260px;
        height: var(--employee-mobile-sidebar-height, 100vh);
        min-height: var(--employee-mobile-sidebar-height, 100vh);
        max-height: var(--employee-mobile-sidebar-height, 100vh);
        background: #1B5E20;
        padding: 20px;
        transition: left 0.3s ease;
        z-index: 2000;
        display: flex;
        flex-direction: column;
        gap: 18px;
        overflow-x: hidden;
        overflow-y: auto;
        overscroll-behavior: contain;
        box-sizing: border-box;
        box-shadow: 12px 0 28px rgba(15, 23, 42, 0.25);
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar.active {
        left: 0;
        right: auto;
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar-header {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-bottom: 8px;
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar-header img {
        width: 36px;
        height: 36px;
        border-radius: 50%;
        background: #ffffff;
        padding: 4px;
        object-fit: contain;
        flex: 0 0 36px;
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar-header span {
        color: #ffffff;
        font-size: 15px;
        font-weight: 700;
        line-height: 1.2;
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar a {
        color: #ffffff;
        text-decoration: none;
        font-size: 16px;
        font-weight: 500;
        min-height: 44px;
        display: flex;
        align-items: center;
        padding: 10px 12px;
        border-radius: 10px;
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar a.active,
    body.employee-shared-mobile-sidebar-page .mobile-sidebar a:hover {
        background: rgba(255, 255, 255, 0.12);
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar-footer {
        margin: 0 0 8px;
        padding: 0 0 14px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.18);
        display: flex;
        align-items: center;
        gap: 12px;
        flex: 0 0 auto;
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar-icon-link,
    body.employee-shared-mobile-sidebar-page .mobile-sidebar-user-btn {
        min-height: 44px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.12);
        border: 1px solid rgba(255, 255, 255, 0.28);
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        text-decoration: none;
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar-icon-link {
        width: 44px;
        min-width: 44px;
        position: relative;
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar-icon-link i,
    body.employee-shared-mobile-sidebar-page .mobile-sidebar-user-btn i {
        font-size: 16px;
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar-badge {
        position: absolute;
        top: -6px;
        right: -4px;
        min-width: 20px;
        height: 20px;
        padding: 0 6px;
        border-radius: 999px;
        background: #ff4d4f;
        color: #ffffff;
        font-size: 11px;
        font-weight: 800;
        display: none;
        align-items: center;
        justify-content: center;
        line-height: 1;
        border: 2px solid #1B5E20;
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar-user {
        position: relative;
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar-user-btn {
        gap: 10px;
        padding: 0 16px;
        cursor: pointer;
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar-user-menu {
        position: absolute;
        right: 0;
        top: calc(100% + 10px);
        min-width: 170px;
        background: #ffffff;
        border-radius: 12px;
        box-shadow: 0 16px 30px rgba(15, 23, 42, 0.18);
        padding: 8px;
        display: none;
        flex-direction: column;
        gap: 4px;
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar-user-menu.show {
        display: flex;
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar-user-menu a {
        min-height: 40px;
        color: #0f172a;
        font-size: 14px;
        font-weight: 600;
        padding: 10px 12px;
        border-radius: 10px;
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar-user-menu a:hover {
        background: #f1f5f9;
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: var(--employee-mobile-sidebar-height, 100vh);
        min-height: var(--employee-mobile-sidebar-height, 100vh);
        background: rgba(0, 0, 0, 0.4);
        opacity: 0;
        visibility: hidden;
        transition: 0.3s;
        z-index: 1500;
        display: block;
    }

    body.employee-shared-mobile-sidebar-page .mobile-sidebar-overlay.active {
        opacity: 1;
        visibility: visible;
    }

    body.employee-shared-mobile-sidebar-page .nav-left,
    body.employee-shared-mobile-sidebar-page .navbar-toggler {
        position: relative;
        z-index: 2105;
    }

    /* One authoritative reference-style mobile header for every employee page. */
    html body > nav.navbar,
    html body[class] > nav.navbar {
        position: sticky !important;
        top: 0 !important;
        z-index: 2105 !important;
        width: 100% !important;
        height: 84px !important;
        min-height: 84px !important;
        display: grid !important;
        grid-template-columns: minmax(0, 1fr) auto !important;
        align-items: center !important;
        gap: 8px !important;
        padding: 10px 14px !important;
        border-bottom: 4px solid #F4C430 !important;
        background: linear-gradient(90deg, #075f28, #006a2d) !important;
        box-sizing: border-box !important;
        box-shadow: none !important;
        -webkit-text-size-adjust: 100% !important;
        text-size-adjust: 100% !important;
    }

    html body[class] > nav.navbar .nav-left {
        width: 100% !important;
        min-width: 0 !important;
        display: grid !important;
        grid-template-columns: 38px 44px minmax(0, 1fr) !important;
        grid-template-areas: "menu logo brand" !important;
        align-items: center !important;
        gap: 9px !important;
    }

    html body[class] > nav.navbar .navbar-toggler {
        grid-area: menu !important;
        justify-self: start !important;
        width: 38px !important;
        height: 38px !important;
        min-width: 38px !important;
        min-height: 38px !important;
        margin: 0 !important;
        padding: 0 !important;
        border: 0 !important;
        border-radius: 0 !important;
        background: transparent !important;
        color: #ffffff !important;
        box-shadow: none !important;
    }

    html body[class] > nav.navbar .navbar-toggler i,
    html body[class] > nav.navbar .navbar-toggler svg {
        width: 18px !important;
        height: 18px !important;
        color: #ffffff !important;
        font-size: 18px !important;
        line-height: 1 !important;
    }

    html body[class] > nav.navbar .logo-icon {
        grid-area: logo !important;
        width: 44px !important;
        height: 44px !important;
        min-width: 44px !important;
        max-width: 44px !important;
        flex-basis: 44px !important;
        padding: 5px !important;
    }

    html body[class] > nav.navbar .brand-name {
        grid-area: brand !important;
        min-width: 0 !important;
        font-family: 'Segoe UI', Arial, sans-serif !important;
        font-size: 16px !important;
        font-weight: 700 !important;
        line-height: 1.1 !important;
        letter-spacing: 0 !important;
        overflow: hidden !important;
        text-overflow: ellipsis !important;
        white-space: nowrap !important;
    }

    html body[class] > nav.navbar #navbarCollapse {
        width: auto !important;
        min-width: 0 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: flex-end !important;
        margin: 0 !important;
        padding: 0 !important;
        border: 0 !important;
    }

    html body[class] > nav.navbar .nav-center {
        display: none !important;
    }

    html body[class] > nav.navbar .nav-right {
        width: auto !important;
        min-width: 0 !important;
        display: flex !important;
        align-items: center !important;
        justify-content: flex-end !important;
        gap: 6px !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    html body[class] > nav.navbar .notification-bell,
    html body[class] > nav.navbar .user-btn {
        width: 38px !important;
        height: 38px !important;
        min-width: 38px !important;
        min-height: 38px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        margin: 0 !important;
        padding: 0 !important;
        border: 0 !important;
        background: transparent !important;
        color: #ffffff !important;
        box-shadow: none !important;
    }

    html body[class] > nav.navbar .user-btn {
        width: 46px !important;
        min-width: 46px !important;
        gap: 4px !important;
    }

    html body[class] > nav.navbar .notification-bell > i,
    html body[class] > nav.navbar .user-btn > i:first-child {
        color: #ffffff !important;
        font-size: 17px !important;
    }

    html body[class] > nav.navbar .user-btn > i:first-child {
        width: 30px !important;
        height: 30px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        border-radius: 50% !important;
        background: #ffffff !important;
        color: #176b35 !important;
    }

    html body[class] > nav.navbar .user-btn-name {
        display: none !important;
    }

    html body[class] > nav.navbar .user-btn > i:last-child {
        display: inline-block !important;
        color: #ffffff !important;
        font-size: 9px !important;
    }

    /* Keep the mobile notification popover compact without resizing the bell. */
    html body[class] > nav.navbar .notification-dropdown {
        left: auto !important;
        right: 12px !important;
        width: min(350px, calc(100vw - 32px)) !important;
        max-height: 72vh !important;
        margin: 0 !important;
        border-radius: 10px !important;
    }

    /* Knowledge Base uses 100% page zoom; compensate so its header has the
       same visible dimensions and type size as the 78%-scaled employee pages. */
    html body.employee-knowledge-base-page > nav.navbar {
        height: 66px !important;
        min-height: 66px !important;
        padding: 8px 11px !important;
    }

    html body.employee-knowledge-base-page > nav.navbar .nav-left {
        grid-template-columns: 30px 34px minmax(0, 1fr) !important;
        gap: 7px !important;
    }

    html body.employee-knowledge-base-page > nav.navbar .logo-icon {
        width: 34px !important;
        height: 34px !important;
        min-width: 34px !important;
        max-width: 34px !important;
        flex-basis: 34px !important;
    }

    html body.employee-knowledge-base-page > nav.navbar .brand-name {
        font-size: 13px !important;
    }

    html body.employee-knowledge-base-page > nav.navbar .navbar-toggler,
    html body.employee-knowledge-base-page > nav.navbar .notification-bell {
        width: 30px !important;
        height: 30px !important;
        min-width: 30px !important;
        min-height: 30px !important;
    }

    html body.employee-knowledge-base-page > nav.navbar .navbar-toggler i,
    html body.employee-knowledge-base-page > nav.navbar .navbar-toggler svg,
    html body.employee-knowledge-base-page > nav.navbar .notification-bell > i {
        width: 14px !important;
        height: 14px !important;
        font-size: 14px !important;
    }

    html body.employee-knowledge-base-page > nav.navbar .nav-right {
        gap: 6px !important;
    }

    html body.employee-knowledge-base-page > nav.navbar .user-btn {
        width: 36px !important;
        height: 30px !important;
        min-width: 36px !important;
        min-height: 30px !important;
        gap: 3px !important;
    }

    html body.employee-knowledge-base-page > nav.navbar .user-btn > i:first-child {
        width: 24px !important;
        height: 24px !important;
        font-size: 12px !important;
    }

    html body.employee-knowledge-base-page > nav.navbar .user-btn > i:last-child {
        font-size: 8px !important;
    }

    html body.employee-knowledge-base-page > nav.navbar .notification-badge {
        padding: 1px 4px !important;
        font-size: 9px !important;
    }
}
</style>

<?php if ($showSharedMobileSidebar): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const menuBtn = document.getElementById('navbarToggler');
    const sidebar = document.getElementById('mobileSidebar');
    const overlay = document.getElementById('mobileSidebarOverlay');
    const mobileUserBtn = document.getElementById('mobileSidebarUserBtn');
    const mobileUserMenu = document.getElementById('mobileSidebarUserMenu');
    const desktopNotifBadge = document.getElementById('notifBadge');
    const mobileNotifBadge = document.getElementById('mobileSidebarNotifBadge');
    const navbarCollapse = document.getElementById('navbarCollapse');

    function syncMobileSidebarHeight() {
        if (!document.documentElement || !document.body) return;

        const viewportHeight = window.visualViewport && window.visualViewport.height
            ? window.visualViewport.height
            : window.innerHeight;
        let bodyZoom = parseFloat(window.getComputedStyle(document.body).zoom || '1');

        if (!Number.isFinite(bodyZoom) || bodyZoom <= 0) {
            bodyZoom = 1;
        }

        document.documentElement.style.setProperty(
            '--employee-mobile-sidebar-height',
            Math.ceil(viewportHeight / bodyZoom) + 'px'
        );
    }

    function closeSidebar() {
        if (!sidebar || !overlay) return;
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
        document.body.classList.remove('sidebar-open');
        if (mobileUserMenu) mobileUserMenu.classList.remove('show');
        sidebar.setAttribute('aria-hidden', 'true');
        overlay.setAttribute('aria-hidden', 'true');
        if (navbarCollapse) navbarCollapse.classList.remove('show');
    }

    function syncMobileNotifBadge() {
        if (!desktopNotifBadge || !mobileNotifBadge) return;
        const desktopText = (desktopNotifBadge.textContent || '').trim();
        const desktopVisible = desktopNotifBadge.style.display !== 'none' && desktopText !== '';
        mobileNotifBadge.textContent = desktopText;
        mobileNotifBadge.style.display = desktopVisible ? 'inline-flex' : 'none';
    }

    if (!menuBtn || !sidebar || !overlay) return;

    syncMobileSidebarHeight();
    window.addEventListener('orientationchange', syncMobileSidebarHeight);
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', syncMobileSidebarHeight);
    }

    menuBtn.addEventListener('click', function (event) {
        if (window.innerWidth > 768) return;
        event.preventDefault();
        event.stopPropagation();
        if (typeof event.stopImmediatePropagation === 'function') {
            event.stopImmediatePropagation();
        }
        if (navbarCollapse) navbarCollapse.classList.remove('show');
        syncMobileSidebarHeight();
        const shouldOpen = !sidebar.classList.contains('active');
        sidebar.classList.toggle('active', shouldOpen);
        overlay.classList.toggle('active', shouldOpen);
        document.body.classList.toggle('sidebar-open', shouldOpen);
        sidebar.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
        overlay.setAttribute('aria-hidden', shouldOpen ? 'false' : 'true');
    }, true);

    overlay.addEventListener('click', function () {
        if (window.innerWidth > 768) return;
        closeSidebar();
    });

    sidebar.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth > 768) return;
            closeSidebar();
        });
    });

    if (mobileUserBtn && mobileUserMenu) {
        mobileUserBtn.addEventListener('click', function (event) {
            if (window.innerWidth > 768) return;
            event.stopPropagation();
            mobileUserMenu.classList.toggle('show');
        });

        document.addEventListener('click', function (event) {
            if (window.innerWidth > 768) return;
            if (!mobileUserMenu.contains(event.target) && !mobileUserBtn.contains(event.target)) {
                mobileUserMenu.classList.remove('show');
            }
        });
    }

    window.addEventListener('resize', function () {
        syncMobileSidebarHeight();
        if (window.innerWidth > 768) {
            closeSidebar();
        }
    });

    syncMobileNotifBadge();
    if (desktopNotifBadge && typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver(syncMobileNotifBadge);
        observer.observe(desktopNotifBadge, { attributes: true, childList: true, subtree: true });
    }
});
</script>
<?php endif; ?>

<script>
window.toggleEmployeeUserMenu = function(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    var userBtn = document.querySelector('.user-btn');
    var userDropdown = document.querySelector('.user-dropdown');
    var notifDropdown = document.getElementById('notifDropdown');
    if (notifDropdown) {
        notifDropdown.classList.remove('show');
    }
    if (userDropdown) {
        var willShow = !userDropdown.classList.contains('show');
        var useFixedUserMenu = document.body
            && (document.body.classList.contains('employee-analytics-page') || document.body.classList.contains('employee-sales-manager-page'));
        if (useFixedUserMenu && userBtn && willShow) {
            var rect = userBtn.getBoundingClientRect();
            userDropdown.style.position = 'fixed';
            userDropdown.style.top = Math.round(rect.bottom + 10) + 'px';
            userDropdown.style.right = Math.max(12, Math.round(window.innerWidth - rect.right)) + 'px';
            userDropdown.style.zIndex = '20002';
        } else if (!willShow) {
            userDropdown.style.position = '';
            userDropdown.style.top = '';
            userDropdown.style.right = '';
            userDropdown.style.zIndex = '';
        }
        userDropdown.classList.toggle('show');
    }
};

document.addEventListener('DOMContentLoaded', function() {
    const bell = document.getElementById('notifBell');
    const dropdown = document.getElementById('notifDropdown');
    const badge = document.getElementById('notifBadge');
    const dot = document.getElementById('notifDot');
    const list = document.getElementById('notifList');
    const userBtn = document.querySelector('.user-btn');
    const userDropdown = document.querySelector('.user-dropdown');
    const chatReminderToastKey = 'tm_employee_seen_hr_chat_pending_notifications';
    let chatReminderToastIds = new Set();

    try {
        const stored = sessionStorage.getItem(chatReminderToastKey);
        if (stored) {
            JSON.parse(stored).forEach((id) => {
                chatReminderToastIds.add(String(id));
            });
        }
    } catch (err) {
        chatReminderToastIds = new Set();
    }

    function persistChatReminderToastIds() {
        try {
            sessionStorage.setItem(chatReminderToastKey, JSON.stringify(Array.from(chatReminderToastIds)));
        } catch (err) {
            // Ignore storage failures; the toast will still render once per poll cycle.
        }
    }

    function showChatReminderToast(notification) {
        const host = document.getElementById('priorityEscalationToastHost');
        if (!host) return;

        const toast = document.createElement('div');
        toast.className = 'priority-escalation-toast employee-chat-toast';
        toast.innerHTML = `
            <div class="priority-escalation-toast-icon" aria-hidden="true">
                <i class="fas fa-comments"></i>
            </div>
            <div class="priority-escalation-toast-body">
                <div class="priority-escalation-toast-title">
                    <span class="priority-escalation-toast-pill">Chat</span>
                    <span>Pending Chat</span>
                </div>
                <div class="priority-escalation-toast-message">${escapeHtml(notification.message || 'You have a pending chat reply.')}</div>
            </div>
            <span class="priority-escalation-toast-dot" aria-hidden="true"></span>
        `;
        host.appendChild(toast);

        window.setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(-8px)';
            toast.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
        }, 5600);

        window.setTimeout(() => {
            if (toast && toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 6200);
    }
    
    // Toggle dropdown
    document.addEventListener('click', function(e) {
        const btn = e.target && e.target.closest ? e.target.closest('.user-btn') : null;
        if (!btn) return;
        if (document.body && (document.body.classList.contains('employee-analytics-page') || document.body.classList.contains('employee-sales-manager-page'))) {
            e.preventDefault();
            e.stopPropagation();
            if (typeof e.stopImmediatePropagation === 'function') e.stopImmediatePropagation();
            window.toggleEmployeeUserMenu(e);
        }
    }, true);

    if (bell && dropdown) {
        bell.addEventListener('click', function(e) {
            e.stopPropagation();
            if (userDropdown) userDropdown.classList.remove('show');
            dropdown.classList.toggle('show');
        });
    }

    if (userBtn && userDropdown) {
        userBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if (dropdown) dropdown.classList.remove('show');
            window.toggleEmployeeUserMenu(e);
        });
    }

    // Close dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (dropdown && bell && !dropdown.contains(e.target) && !bell.contains(e.target)) {
            dropdown.classList.remove('show');
        }
        if (userDropdown && userBtn && !userDropdown.contains(e.target) && !userBtn.contains(e.target)) {
            userDropdown.classList.remove('show');
        }
    });

    // Relative time helpers
    function toRelative(ts) {
        const now = new Date();
        const then = new Date(ts.replace(' ', 'T'));
        const diff = Math.max(0, Math.floor((now - then) / 1000)); // seconds
        if (diff < 10) return 'Just now';
        if (diff < 60) return `${diff}s ago`;
        const m = Math.floor(diff / 60);
        if (m < 60) return `${m} minute${m === 1 ? '' : 's'} ago`;
        const h = Math.floor(diff / 3600);
        if (h < 24) return `${h} hour${h === 1 ? '' : 's'} ago`;
        const d = Math.floor(diff / 86400);
        return `${d} day${d === 1 ? '' : 's'} ago`;
    }
    function updateRelativeTimes() {
        document.querySelectorAll('.notif-time[data-timestamp]').forEach(el => {
            const ts = el.getAttribute('data-timestamp');
            el.textContent = toRelative(ts);
        });
    }
    function getNotifSectionLabel(ts) {
        const value = new Date(String(ts).replace(' ', 'T'));
        if (Number.isNaN(value.getTime())) return 'Older';
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const itemDay = new Date(value.getFullYear(), value.getMonth(), value.getDate());
        const diffDays = Math.round((today - itemDay) / 86400000);
        if (diffDays <= 0) return 'Today';
        if (diffDays === 1) return 'Yesterday';
        return 'Older';
    }

    window.TM_EMPLOYEE_NOTIF_LAST_UNREAD_COUNT = window.TM_EMPLOYEE_NOTIF_LAST_UNREAD_COUNT || 0;

    function setEmployeeNotificationBadge(unreadCount) {
        const badge = document.getElementById('notifBadge');
        const dot = document.getElementById('notifDot');
        const value = Math.max(0, parseInt(String(unreadCount || 0), 10) || 0);
        window.TM_EMPLOYEE_NOTIF_LAST_UNREAD_COUNT = value;

        if (value > 0) {
            badge.textContent = value > 9 ? '9+' : value;
            badge.style.display = 'block';
            dot.style.display = 'block';
        } else {
            badge.textContent = '';
            badge.style.display = 'none';
            dot.style.display = 'none';
        }
    }

    function consumeEmployeeNotificationUnread(notifItem) {
        if (!notifItem || !notifItem.classList.contains('unread')) return;
        notifItem.classList.remove('unread');
        const unreadDot = notifItem.querySelector('.notif-unread-dot');
        if (unreadDot) unreadDot.remove();
        setEmployeeNotificationBadge((window.TM_EMPLOYEE_NOTIF_LAST_UNREAD_COUNT || 0) - 1);
    }

    // Fetch Notifications
    function fetchNotifications() {
        fetch('fetch_notifications.php?_=' + Date.now(), { cache: 'no-store' })
            .then(response => response.json())
            .then(data => {
                const list = document.getElementById('notifList');
                const unreadCount = Math.max(0, parseInt(String(data.unread_count || 0), 10) || 0);

                // Update Badge
                setEmployeeNotificationBadge(unreadCount);

                // Update List
                if (data.notifications && data.notifications.length > 0) {
                    let currentSection = '';
                    list.innerHTML = data.notifications.map(n => {
                        const sectionLabel = getNotifSectionLabel(n.created_at);
                        const sectionHtml = sectionLabel !== currentSection
                            ? `<div class="notif-section-label">${escapeHtml(sectionLabel)}</div>`
                            : '';
                        currentSection = sectionLabel;
                        const actionType = (n.action_type || '').toString().toLowerCase() || (function (legacyType) {
                            if (legacyType === 'ticket_claimed' || legacyType === 'claim_ticket') return 'claim';
                            if (legacyType === 'dept_assigned' || legacyType === 'new_ticket') return 'assign';
                            if (legacyType === 'reassigned') return 'reassign';
                            if (legacyType === 'ticket_closed') return 'close';
                            if (legacyType === 'status_update' || legacyType === 'note_added') return 'update';
                            return '';
                        })((n.type || '').toString());
                        const isPriorityEscalation = (n.type || '').toString() === 'priority_escalated';
                        const rawPriority = (n.priority || '').toString().toLowerCase();
                        const allowed = ['critical', 'high', 'medium', 'low'];
                        const priorityKey = isPriorityEscalation
                            ? escalationPriorityFromMessage(n.message)
                            : (allowed.includes(rawPriority) ? rawPriority : '');
                        const typeKey = (n.type || '').toString();
                        const isResolvedStatus = (actionType === 'update' || typeKey === 'status_update') && /\bresolved\b/i.test(String(n.message || ''));
                        let titleText = getNotificationTitle(actionType, typeKey, priorityKey);
                        if (isResolvedStatus) {
                            titleText = 'Ticket Resolved';
                        }
                        if (typeKey === 'conference_booking_created') {
                            const payload = parseBookingPayload(n.message);
                            const email = payload.user_email || 'Someone';
                            const room = payload.room_name || payload.room || 'the room';
                            const date = formatBookingDate(payload.booking_date || '');
                            const start = formatBookingTime(payload.start_time || '');
                            const end = formatBookingTime(payload.end_time || '');
                            const location = payload.location || payload.room_location || room;
                            const detail = `${email} booked ${room}${date ? ` on ${date}` : ''}${start || end ? ` from ${start} to ${end}` : ''} (${location}).`;
                            const unreadDotHtml = Number(n.is_read) === 0 ? '<span class="notif-unread-dot" aria-hidden="true"></span>' : '';
                            return `
                                ${sectionHtml}
                                <div class="notif-item booking-created-card variant-booking ${n.is_read == 0 ? 'unread' : ''}" data-notif-id="${n.id}" data-ticket-id="${n.ticket_id}" onclick="markAsRead(${n.id}, ${n.ticket_id}, 'conference_booking_created')">
                                    ${unreadDotHtml}
                                    <div class="left-pill">
                                        <span class="booking-icon"><i class="fas fa-calendar-check"></i></span>
                                        <span class="pill-label">Booking</span>
                                    </div>
                                    <div class="notification-body">
                                        <h4 class="booking-title">Conference Booking Created</h4>
                                        <div class="booking-detail">${escapeHtml(detail)}</div>
                                        <time class="notif-time" data-timestamp="${escapeHtml(n.created_at)}">${escapeHtml(n.time_ago || '')}</time>
                                    </div>
                                </div>
                            `;
                        }
                        const isFollowUp = typeKey === 'follow_up';
                        const isChatPending = typeKey === 'hr_chat_pending';
                        let variantClass = 'variant-update';
                        let pillText = 'Updated';
                        let pillIcon = 'fa-rotate';
                        if (isChatPending) {
                            variantClass = 'variant-update';
                            pillText = 'Chat';
                            pillIcon = 'fa-comments';
                        } else if (isFollowUp) {
                            variantClass = 'variant-follow-up';
                            pillText = 'Follow Up';
                            pillIcon = 'fa-rotate';
                            accentColor = '#d4a017';
                            dotColor = '#d4a017';
                        } else if (priorityKey === 'critical') {
                            variantClass = 'variant-critical';
                            pillText = 'Critical';
                            pillIcon = 'fa-exclamation';
                        } else if (priorityKey === 'high') {
                            variantClass = 'variant-high';
                            pillText = 'High';
                            pillIcon = 'fa-exclamation';
                        } else if (priorityKey === 'medium') {
                            variantClass = 'variant-medium';
                            pillText = 'Medium';
                            pillIcon = 'fa-triangle-exclamation';
                        } else if (priorityKey === 'low') {
                            variantClass = 'variant-low';
                            pillText = 'Low';
                            pillIcon = 'fa-arrow-down';
                        } else if (actionType === 'claim' || typeKey === 'ticket_claimed') {
                            variantClass = 'variant-assign';
                            pillText = 'Claimed';
                            pillIcon = 'fa-user-check';
                        } else if (actionType === 'assign') {
                            variantClass = 'variant-assign';
                            pillText = 'Assigned';
                            pillIcon = 'fa-check';
                        } else if (actionType === 'reassign') {
                            variantClass = 'variant-reassign';
                            pillText = 'Reassigned';
                            pillIcon = 'fa-retweet';
                        } else if (actionType === 'close') {
                            variantClass = 'variant-close';
                            pillText = 'Closed';
                            pillIcon = 'fa-check';
                        } else if (actionType === 'update' && n.type === 'note_added') {
                            variantClass = 'variant-note';
                            pillText = 'Private Note';
                            pillIcon = 'fa-plus';
                        }
                        if (actionType === 'reassign') {
                            variantClass = 'variant-reassign';
                            pillText = 'Reassigned';
                            pillIcon = 'fa-retweet';
                        }
                        if (isChatPending && Number(n.is_read) === 0 && !chatReminderToastIds.has(String(n.id))) {
                            chatReminderToastIds.add(String(n.id));
                            persistChatReminderToastIds();
                            showChatReminderToast(n);
                        }
                        if (isPriorityEscalation && priorityKey === 'high') {
                            pillText = escalationTransitionLabel(n.message) || 'At Risk -> Breach';
                            pillIcon = 'fa-stopwatch';
                        }
                        const unreadDotHtml = Number(n.is_read) === 0 ? '<span class="notif-unread-dot" aria-hidden="true"></span>' : '';
                        const breachPillClass = isPriorityEscalation && priorityKey === 'high' ? 'notif-priority-breach-pill' : '';
                        const pillHtml = `<span class="notif-pill ${variantClass} ${breachPillClass} ${isChatPending ? 'notif-chat-pill' : ''}"><span class="notif-pill-icon"><i class="fas ${pillIcon}"></i></span>${isChatPending ? '' : `<span class="notif-pill-text">${escapeHtml(pillText)}</span>`}</span>`;
                        const messageHtml = `<div class="notif-title">${pillHtml}<span class="notif-title-text">${escapeHtml(titleText)}</span></div><div class="notif-msg">${highlightNotificationMessage(n.message)}</div>`;
                        return `
                            ${sectionHtml}
                            <div class="notif-item ${n.is_read == 0 ? 'unread' : ''} ${variantClass} ${isPriorityEscalation ? `priority-escalation ${variantClass}` : ''} ${isChatPending ? 'notif-chat-pending' : ''}" data-notif-id="${n.id}" data-ticket-id="${n.ticket_id}" onclick="markAsRead(${n.id}, ${n.ticket_id}, '${n.type || ''}')">
                                ${unreadDotHtml}
                                <div class="notif-content">
                                    ${messageHtml}
                                    <time class="notif-time" data-timestamp="${n.created_at}">${n.time_ago || ''}</time>
                                </div>
                            </div>
                        `;
                    }).join('');
                    updateRelativeTimes();
                } else {
                    list.innerHTML = '<div class="notif-empty">No notifications</div>';
                }
            })
            .catch(err => console.error('Error fetching notifications:', err));
    }
    
    function escapeHtml(text) {
        if (text === null || text === undefined) return '';
        return text.toString()
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    function parseBookingPayload(message) {
        try {
            const parsed = JSON.parse(String(message || '{}'));
            return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
        } catch (error) {
            return {};
        }
    }

    function formatBookingDate(value) {
        const raw = String(value || '').trim();
        if (!raw) return '';
        const date = new Date(`${raw}T00:00:00`);
        if (Number.isNaN(date.getTime())) return raw;
        return date.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
    }

    function formatBookingTime(value) {
        const raw = String(value || '').trim();
        if (!raw) return '';
        const date = new Date(`1970-01-01T${raw.length === 5 ? `${raw}:00` : raw}`);
        if (Number.isNaN(date.getTime())) return raw;
        return date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }

    function getPriorityNotificationTitle(priorityKey) {
        if (priorityKey === 'critical') return 'Priority Escalation';
        if (priorityKey === 'high') return 'Priority Escalation';
        if (priorityKey === 'low') return 'Ticket Assigned';
        return 'Ticket Update';
    }

    function getNotificationTitle(actionType, type, priorityKey) {
        if (type === 'priority_escalated') return getPriorityNotificationTitle(priorityKey);
        if (type === 'conference_booking_created') return 'Conference Booking Created';
        if (type === 'conference_booking_cancelled') return 'Conference Booking Cancelled';
        if (type === 'conference_booking_deleted') return 'Conference Booking Deleted';
        if (type === 'ticket_claimed' || actionType === 'claim') return 'Ticket Claimed';
        if (actionType === 'assign') return 'Ticket Assigned';
        if (actionType === 'reassign') return 'Ticket Reassigned';
        if (actionType === 'close') return 'Ticket Closed';
        if (type === 'follow_up') return 'Follow Up Request';
        if (type === 'hr_chat_pending') return 'Pending Chat';
        if (actionType === 'update' && type === 'note_added') return 'Ticket Note';
        if (actionType === 'update') return 'Status Update';
        return 'Ticket Update';
    }

    function escalationPriorityFromMessage(message) {
        const match = String(message || '').match(/escalated(?:\s+from\s+(?:on track|at risk|breach|critical|high|medium|low))?\s+to\s+(breach|at risk|on track|critical|high|medium|low)\b/i);
        if (!match) return '';
        const value = String(match[1] || '').toLowerCase();
        if (value === 'breach') return 'high';
        if (value === 'at risk') return 'medium';
        if (value === 'on track') return 'low';
        return value;
    }

    function escalationTransitionLabel(message) {
        const match = String(message || '').match(/\bescalated\s+from\s+(on track|at risk|breach|critical|high|medium|low)\s+to\s+(on track|at risk|breach|critical|high|medium|low)\b/i);
        if (!match) return '';
        const label = (value) => {
            const key = String(value || '').toLowerCase();
            if (key === 'at risk') return 'At Risk';
            if (key === 'breach') return 'Breach';
            if (key === 'on track') return 'On Track';
            return key.charAt(0).toUpperCase() + key.slice(1);
        };
        return `${label(match[1])} -> ${label(match[2])}`;
    }

    function highlightNotificationMessage(text) {
        const safe = escapeHtml(text);
        return safe.replace(/\b(in progress|resolved|closed|open)\b/gi, (match) => {
            const token = match.toLowerCase().replace(/\s+/g, ' ').trim();
            let className = 'notif-keyword-generic';
            if (token === 'in progress') {
                className = 'notif-keyword-success';
            } else if (token === 'resolved') {
                className = 'notif-keyword-info';
            } else if (token === 'closed') {
                className = 'notif-keyword-success';
            } else if (token === 'open') {
                className = 'notif-keyword-info';
            }
            return `<span class="notif-keyword ${className}">${match}</span>`;
        });
    }

    // Mark as Read & Redirect
    const CSRF_TOKEN = <?php echo json_encode(csrf_token()); ?>;
    const IS_LAPC_SALES_MANAGER_VIEW = <?php echo json_encode($isLapcSalesEmployee && $employeeViewMode === 'manager'); ?>;
    window.TM_CSRF_TOKEN = CSRF_TOKEN;
    window.markAsRead = function(id, ticketId, type) {
        const notifItem = document.querySelector('.notif-item[data-notif-id="' + String(id) + '"]');
        if (notifItem && notifItem.getAttribute('data-marking-read') === '1') return;
        if (notifItem) notifItem.setAttribute('data-marking-read', '1');
        consumeEmployeeNotificationUnread(notifItem);

        // Send request to mark as read
        const body = 'id=' + encodeURIComponent(String(id)) + (CSRF_TOKEN ? ('&csrf_token=' + encodeURIComponent(String(CSRF_TOKEN))) : '');
        fetch('mark_notification_read.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body
        }).then(() => {
            if (!ticketId) {
                window.location.href = 'notifications.php';
                return;
            }
            if (IS_LAPC_SALES_MANAGER_VIEW) {
                window.location.href = `sales_submitted_tickets.php?ticket_id=${ticketId}`;
                return;
            }
            const notifType = String(type || '');
            if (notifType === 'hr_chat_pending') {
                window.location.href = `my_task.php?ticket_id=${ticketId}&chat=1`;
                return;
            }
            const taskTypes = new Set(['dept_assigned', 'reassigned', 'priority_escalated', 'new_ticket', 'follow_up', 'hr_chat_pending']);
            if (taskTypes.has(notifType)) {
                window.location.href = `my_task.php?ticket_id=${ticketId}`;
            } else {
                window.location.href = `my_tickets.php?ticket_id=${ticketId}`;
            }
        });
    };

    // Initial fetch and poll every 5 seconds
    fetchNotifications();
    setInterval(fetchNotifications, 5000);
    // Also refresh relative timestamps every 60s
    setInterval(updateRelativeTimes, 60000);

    function setGlobalChatBadge(n) {
        const badge = document.getElementById('globalChatBadge');
        if (!badge) return;
        const count = Math.max(0, parseInt(String(n || 0), 10) || 0);
        if (count <= 0) {
            badge.classList.remove('is-visible');
            badge.textContent = '';
            return;
        }
        badge.classList.add('is-visible');
        badge.textContent = count > 99 ? '99+' : String(count);
    }

    function fetchChatUnreadTotal() {
        const formData = new FormData();
        formData.append('action', 'conversations');
        if (window.TM_CSRF_TOKEN) formData.append('csrf_token', String(window.TM_CSRF_TOKEN));
        const headers = { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' };
        if (window.TM_CSRF_TOKEN) headers['X-CSRF-Token'] = String(window.TM_CSRF_TOKEN);
        fetch('chat_fetch.php', { method: 'POST', body: formData, headers: headers })
            .then(r => r.text())
            .then(txt => {
                let data = null;
                try { data = JSON.parse(txt); } catch (e) { return; }
                if (data && data.error) return;
                const items = Array.isArray(data) ? data : [];
                let total = 0;
                items.forEach(c => {
                    const unreadValue = c && c.unread_count_raw != null ? c.unread_count_raw : (c && c.unread_count != null ? c.unread_count : 0);
                    const u = parseInt(String(unreadValue), 10) || 0;
                    total += Math.max(0, u);
                });
                setGlobalChatBadge(total);
            })
            .catch(() => {});
    }
    window.TMRefreshGlobalChatBadge = fetchChatUnreadTotal;

    function ensureTicketModalScript() {
        if (window.TMTicketModal) return;
        if (document.getElementById('tmTicketModalScript')) return;
        const s = document.createElement('script');
        s.id = 'tmTicketModalScript';
        s.src = '../js/ticket-modal.js?v=' + Date.now();
        document.body.appendChild(s);
    }

    window.TMGlobalChat = {
        open: function() {
            ensureTicketModalScript();
            const tryOpen = function(attempt) {
                if (window.TMTicketModal && typeof window.TMTicketModal.openMessengerChat === 'function') {
                    window.TMTicketModal.openMessengerChat();
                    return;
                }
                if (attempt >= 20) return;
                setTimeout(function() { tryOpen(attempt + 1); }, 50);
            };
            tryOpen(0);
        }
    };

    fetchChatUnreadTotal();
    setInterval(fetchChatUnreadTotal, 3000);
});
</script>
