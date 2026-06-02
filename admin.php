<?php
/**
 * C3 Restro - Admin Panel
 * Single-file PHP + MySQL · No Framework
 * Syncs directly with cafe_panel.php (c3restro_cafe database)
 */

// ============================================================
// DATABASE CONFIG — must match cafe_panel.php
// ============================================================
define('DB_HOST', 'sql208.infinityfree.com');
define('DB_USER', 'if0_42049744');
define('DB_PASS', '02118200YashYg');
define('DB_NAME', 'if0_42049744_c3');
define('ADMIN_SESSION_KEY', 'if0_42049744_c3');
define('APP_VERSION', '2.0.0');

// ── Loyalty economy defaults (overridden by cafe_settings) ──
define('DEFAULT_POINTS_PER_RUPEE', 1);    // 1 point per ₹1 spent
define('DEFAULT_REDEEM_RATE', 40);         // 40 points = ₹1 (was 20)
define('DEFAULT_MAX_REDEEM_PCT', 15);      // max 15% of order value (was 20%)
define('DEFAULT_REFERRAL_BONUS', 150);     // was 300
define('DEFAULT_BIRTHDAY_BONUS', 300);     // was 500
define('DEFAULT_DAILY_LOGIN_BONUS', 10);   // was 20

// ============================================================
// DATABASE CONNECTION
// ============================================================
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            die('<h2 style="font-family:sans-serif;color:#c00;padding:2rem;">DB Connection Failed: ' . htmlspecialchars($e->getMessage()) . '</h2>');
        }
    }
    return $pdo;
}

// ============================================================
// ADMIN TABLE SETUP
// ============================================================
function setupAdminTables() {
    $db = getDB();
    try { $db->exec("SET FOREIGN_KEY_CHECKS=0"); } catch(PDOException $e) {}
    $db->exec("CREATE TABLE IF NOT EXISTS `admin_users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) UNIQUE NOT NULL,
        `password` VARCHAR(255) NOT NULL,
        `role` ENUM('superadmin','manager','staff') DEFAULT 'staff',
        `full_name` VARCHAR(100),
        `last_login` TIMESTAMP NULL,
        `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS `cafe_settings` (
        `key` VARCHAR(50) PRIMARY KEY,
        `value` TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS `admin_logs` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `admin_id` INT,
        `action` VARCHAR(200),
        `details` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec("CREATE TABLE IF NOT EXISTS `blocked_customers` (
        `customer_id` INT PRIMARY KEY,
        `reason` VARCHAR(200),
        `blocked_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Default admin if none exists
    $cnt = $db->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
    if ($cnt == 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $db->prepare("INSERT INTO admin_users (username,password,role,full_name) VALUES (?,?,'superadmin','Super Admin')")->execute(['admin', $hash]);
    }

    // Default settings
    $defaults = [
        'cafe_name' => 'C3 Restro',
        'cafe_logo' => '',
        'theme_color' => '#16a34a',
        'gst_percent' => '5',
        'currency' => '₹',
        'whatsapp_number' => '9876543210',
        'cafe_address' => 'Ujjain, Madhya Pradesh',
        'opening_hours' => '8:00 AM - 10:00 PM',
        'footer_text' => 'Made with ☕ & ❤️',
        'points_per_rupee' => '10',
        'bronze_threshold' => '0',
        'silver_threshold' => '200',
        'gold_threshold' => '500',
        'menu_url' => 'cafe_panel.php',
        // Loyalty economy
        'earn_rate' => '1',           // points earned per ₹1
        'redeem_rate' => '40',        // points needed per ₹1 discount (was 20)
        'max_redeem_pct' => '15',     // max % of order redeemable (was 20)
        'referral_bonus' => '150',    // was 300
        'birthday_bonus' => '300',    // was 500
        'daily_login_bonus' => '10',  // was 20
        'silver_threshold' => '2000', // was 1000
        'gold_threshold' => '8000',   // was 5000
        'platinum_threshold' => '25000', // was 15000
        'silver_multiplier' => '1.15',  // was 1.2
    ];
    $ins = $db->prepare("INSERT IGNORE INTO cafe_settings (`key`,`value`) VALUES (?,?)");
    foreach ($defaults as $k => $v) $ins->execute([$k, $v]);
}

// ============================================================
// SESSION & AUTH
// ============================================================
session_start();
setupAdminTables();

function isAdminLoggedIn() { return isset($_SESSION[ADMIN_SESSION_KEY]); }
function getAdmin() {
    if (!isAdminLoggedIn()) return null;
    $stmt = getDB()->prepare("SELECT * FROM admin_users WHERE id=? AND is_active=1");
    $stmt->execute([$_SESSION[ADMIN_SESSION_KEY]]);
    return $stmt->fetch();
}
function getSetting($key, $default = '') {
    $stmt = getDB()->prepare("SELECT value FROM cafe_settings WHERE `key`=?");
    $stmt->execute([$key]);
    $r = $stmt->fetchColumn();
    return $r !== false ? $r : $default;
}
function adminLog($action, $details = '') {
    if (!isAdminLoggedIn()) return;
    getDB()->prepare("INSERT INTO admin_logs (admin_id,action,details) VALUES (?,?,?)")
        ->execute([$_SESSION[ADMIN_SESSION_KEY], $action, $details]);
}
function requireAdmin() {
    if (!isAdminLoggedIn()) { header('Location: ?'); exit; }
}
function hasRole($role) {
    $admin = getAdmin();
    if (!$admin) return false;
    $hierarchy = ['superadmin' => 3, 'manager' => 2, 'staff' => 1];
    return ($hierarchy[$admin['role']] ?? 0) >= ($hierarchy[$role] ?? 99);
}

// ============================================================
// API / AJAX HANDLERS
// ============================================================
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $action = $_GET['api'];
    $db = getDB();
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    // ---- AUTH ----
    if ($action === 'admin_login') {
        $u = trim($data['username'] ?? '');
        $p = $data['password'] ?? '';
        $stmt = $db->prepare("SELECT * FROM admin_users WHERE username=? AND is_active=1");
        $stmt->execute([$u]);
        $admin = $stmt->fetch();
        if ($admin && password_verify($p, $admin['password'])) {
            $_SESSION[ADMIN_SESSION_KEY] = $admin['id'];
            $db->prepare("UPDATE admin_users SET last_login=NOW() WHERE id=?")->execute([$admin['id']]);
            echo json_encode(['success' => true, 'role' => $admin['role'], 'name' => $admin['full_name']]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
        }
        exit;
    }

    if ($action === 'admin_logout') {
        session_destroy();
        echo json_encode(['success' => true]);
        exit;
    }

    // All below require login
    if (!isAdminLoggedIn()) { echo json_encode(['error' => 'Unauthorized']); exit; }

    // ---- DASHBOARD ----
    if ($action === 'dashboard_stats') {
        $stats = [];
        $stats['total_customers'] = $db->query("SELECT COUNT(*) FROM customers")->fetchColumn();
        $stats['today_orders'] = $db->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()")->fetchColumn();
        $stats['total_revenue'] = $db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status='completed'")->fetchColumn();
        $stats['pending_orders'] = $db->query("SELECT COUNT(*) FROM orders WHERE status IN ('pending','confirmed','preparing')")->fetchColumn();
        $stats['points_issued'] = $db->query("SELECT COALESCE(SUM(points),0) FROM point_transactions WHERE type IN ('earn','bonus','birthday')")->fetchColumn();
        $stats['coupons_used'] = $db->query("SELECT COUNT(*) FROM coupon_usage")->fetchColumn();
        $stats['today_revenue'] = $db->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)=CURDATE() AND status!='cancelled'")->fetchColumn();
        $stats['new_customers_today'] = $db->query("SELECT COUNT(*) FROM customers WHERE DATE(created_at)=CURDATE()")->fetchColumn();

        // Top selling item
        $all_orders = $db->query("SELECT items_json FROM orders WHERE status!='cancelled'")->fetchAll();
        $item_counts = [];
        foreach ($all_orders as $o) {
            $items = json_decode($o['items_json'] ?? '[]', true);
            foreach ($items as $item) {
                $n = $item['name'] ?? 'Unknown';
                $item_counts[$n] = ($item_counts[$n] ?? 0) + ($item['qty'] ?? 1);
            }
        }
        arsort($item_counts);
        $stats['top_item'] = key($item_counts) ?? 'N/A';
        $stats['top_item_qty'] = current($item_counts) ?: 0;

        // Monthly sales (last 6 months)
        $monthly = $db->query("SELECT DATE_FORMAT(created_at,'%b %Y') as month, MONTH(created_at) as m, YEAR(created_at) as y, COALESCE(SUM(total),0) as revenue, COUNT(*) as orders FROM orders WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH) AND status!='cancelled' GROUP BY y,m ORDER BY y,m")->fetchAll();
        $stats['monthly_sales'] = $monthly;

        // Recent orders
        $recent = $db->query("SELECT o.*, c.full_name, c.mobile FROM orders o LEFT JOIN customers c ON o.customer_id=c.id ORDER BY o.created_at DESC LIMIT 10")->fetchAll();
        $stats['recent_orders'] = $recent;

        // New customers
        $new_custs = $db->query("SELECT * FROM customers ORDER BY created_at DESC LIMIT 8")->fetchAll();
        $stats['new_customers'] = $new_custs;

        // Birthday today
        $bday = $db->query("SELECT COUNT(*) FROM customers WHERE DAY(birthday)=DAY(NOW()) AND MONTH(birthday)=MONTH(NOW())")->fetchColumn();
        $stats['birthdays_today'] = $bday;

        echo json_encode($stats);
        exit;
    }

    // ---- ORDERS ----
    if ($action === 'get_orders') {
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page - 1) * $limit;
        $search = trim($_GET['search'] ?? '');
        $status = $_GET['status'] ?? '';
        $period = $_GET['period'] ?? '';
        $order_type_filter = $_GET['order_type'] ?? '';
        $where = ['1=1'];
        $params = [];
        if ($search) { $where[] = "(o.order_number LIKE ? OR c.full_name LIKE ? OR c.mobile LIKE ?)"; $params = array_merge($params, ["%$search%","%$search%","%$search%"]); }
        if ($status) { $where[] = "o.status=?"; $params[] = $status; }
        if ($order_type_filter) { $where[] = "o.order_type=?"; $params[] = $order_type_filter; }
        if ($period === 'today') { $where[] = "DATE(o.created_at)=CURDATE()"; }
        elseif ($period === 'week') { $where[] = "o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"; }
        elseif ($period === 'month') { $where[] = "o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)"; }
        $where_str = implode(' AND ', $where);
        $total = $db->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN customers c ON o.customer_id=c.id WHERE $where_str");
        $total->execute($params);
        $total_count = $total->fetchColumn();
        $stmt = $db->prepare("SELECT o.*, c.full_name, c.mobile FROM orders o LEFT JOIN customers c ON o.customer_id=c.id WHERE $where_str ORDER BY o.created_at DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        $orders = $stmt->fetchAll();
        echo json_encode(['orders' => $orders, 'total' => $total_count, 'pages' => ceil($total_count / $limit), 'page' => $page]);
        exit;
    }

    if ($action === 'update_order_status') {
        requireAdmin();
        $id = intval($data['id'] ?? 0);
        $status = $data['status'] ?? '';
        $allowed = ['pending','confirmed','preparing','ready','completed','cancelled'];
        if (!$id || !in_array($status, $allowed)) { echo json_encode(['success' => false, 'message' => 'Invalid request']); exit; }

        // Fetch current order to check previous status
        $ord_stmt = $db->prepare("SELECT * FROM orders WHERE id=?");
        $ord_stmt->execute([$id]);
        $order = $ord_stmt->fetch();

        if (!$order) { echo json_encode(['success' => false, 'message' => 'Order not found']); exit; }

        $prev_status = $order['status'];

        // Prevent re-processing already completed/cancelled orders
        if (in_array($prev_status, ['completed','cancelled'])) {
            echo json_encode(['success' => false, 'message' => 'Order already ' . $prev_status]);
            exit;
        }

        // Update the order status
        $db->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$status, $id]);

        $points_awarded = 0;

        // Award BrewCoins ONLY when status changes to 'completed'
        if ($status === 'completed') {
            $points_to_award = intval($order['points_earned']);
            $cust_id         = intval($order['customer_id']);
            $order_num_ref   = $order['order_number'];

            if ($points_to_award > 0) {
                // Add coins to customer
                $db->prepare("UPDATE customers SET points=points+? WHERE id=?")->execute([$points_to_award, $cust_id]);
                $db->prepare("INSERT INTO point_transactions (customer_id,points,type,description) VALUES (?,?,'earn','BrewCoins earned — Order #$order_num_ref completed')")->execute([$cust_id, $points_to_award]);

                // Update membership tier
                $new_pts_stmt = $db->prepare("SELECT points FROM customers WHERE id=?");
                $new_pts_stmt->execute([$cust_id]);
                $np = $new_pts_stmt->fetchColumn();

                $level = 'Bronze';
                $plat   = intval(getSetting('platinum_threshold', 15000));
                $gold   = intval(getSetting('gold_threshold', 5000));
                $silver = intval(getSetting('silver_threshold', 1000));
                if ($np >= $plat) $level = 'Platinum';
                elseif ($np >= $gold) $level = 'Gold';
                elseif ($np >= $silver) $level = 'Silver';
                $db->prepare("UPDATE customers SET membership_level=? WHERE id=?")->execute([$level, $cust_id]);

                $points_awarded = $points_to_award;
            }
        }

        adminLog('update_order_status', "Order #$id → $status" . ($points_awarded ? " | +$points_awarded BrewCoins awarded" : ""));
        echo json_encode(['success' => true, 'points_awarded' => $points_awarded]);
        exit;
    }

    if ($action === 'export_orders_csv') {
        // Returns CSV data as base64
        $period = $_GET['period'] ?? '';
        $status = $_GET['status'] ?? '';
        $where = ['1=1']; $params = [];
        if ($status) { $where[] = "o.status=?"; $params[] = $status; }
        if ($period === 'today') $where[] = "DATE(o.created_at)=CURDATE()";
        elseif ($period === 'week') $where[] = "o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
        elseif ($period === 'month') $where[] = "o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $where_str = implode(' AND ', $where);
        $stmt = $db->prepare("SELECT o.id, o.order_number, c.full_name, c.mobile, o.subtotal, o.discount, o.total, o.status, o.order_type, o.points_earned, o.coupon_code, o.created_at FROM orders o LEFT JOIN customers c ON o.customer_id=c.id WHERE $where_str ORDER BY o.created_at DESC");
        $stmt->execute($params);
        $rows = $stmt->fetchAll();
        $csv = "ID,Order#,Customer,Mobile,Subtotal,Discount,Total,Status,Type,Points Earned,Coupon,Date\n";
        foreach ($rows as $r) {
            $csv .= implode(',', array_map(fn($v) => '"'.str_replace('"','""',$v).'"', $r))."\n";
        }
        echo json_encode(['csv' => base64_encode($csv), 'filename' => 'orders_'.date('Ymd').'.csv']);
        exit;
    }

    // ---- CUSTOMERS ----
    if ($action === 'get_customers') {
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = 20; $offset = ($page-1)*$limit;
        $search = trim($_GET['search'] ?? '');
        $where = ['1=1']; $params = [];
        if ($search) { $where[] = "(c.full_name LIKE ? OR c.mobile LIKE ?)"; $params = ["%$search%","%$search%"]; }
        $where_str = implode(' AND ', $where);
        $total = $db->prepare("SELECT COUNT(*) FROM customers c WHERE $where_str");
        $total->execute($params); $tc = $total->fetchColumn();
        $stmt = $db->prepare("SELECT c.*, b.customer_id as is_blocked, (SELECT COUNT(*) FROM orders WHERE customer_id=c.id) as order_count, (SELECT COALESCE(SUM(total),0) FROM orders WHERE customer_id=c.id AND status='completed') as total_spent FROM customers c LEFT JOIN blocked_customers b ON c.id=b.customer_id WHERE $where_str ORDER BY c.created_at DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        echo json_encode(['customers' => $stmt->fetchAll(), 'total' => $tc, 'pages' => ceil($tc/$limit), 'page' => $page]);
        exit;
    }

    if ($action === 'get_customer_detail') {
        $id = intval($_GET['id'] ?? 0);
        $c = $db->prepare("SELECT c.*, b.customer_id as is_blocked FROM customers c LEFT JOIN blocked_customers b ON c.id=b.customer_id WHERE c.id=?");
        $c->execute([$id]); $cust = $c->fetch();
        $orders = $db->prepare("SELECT * FROM orders WHERE customer_id=? ORDER BY created_at DESC LIMIT 20");
        $orders->execute([$id]);
        $txns = $db->prepare("SELECT * FROM point_transactions WHERE customer_id=? ORDER BY created_at DESC LIMIT 20");
        $txns->execute([$id]);
        echo json_encode(['customer' => $cust, 'orders' => $orders->fetchAll(), 'transactions' => $txns->fetchAll()]);
        exit;
    }

    if ($action === 'adjust_points') {
        if (!hasRole('manager')) { echo json_encode(['success' => false, 'message' => 'Insufficient permission']); exit; }
        $id = intval($data['customer_id'] ?? 0);
        $pts = intval($data['points'] ?? 0);
        $reason = trim($data['reason'] ?? 'Admin adjustment');
        $type = $pts >= 0 ? 'bonus' : 'redeem';
        $db->prepare("UPDATE customers SET points=GREATEST(0,points+?) WHERE id=?")->execute([$pts, $id]);
        $db->prepare("INSERT INTO point_transactions (customer_id,points,type,description) VALUES (?,?,?,?)")->execute([$id, $pts, $type, $reason]);
        $new_pts = $db->prepare("SELECT points FROM customers WHERE id=?"); $new_pts->execute([$id]);
        $np = $new_pts->fetchColumn();
        // Update membership with Platinum support
        $level = 'Bronze';
        $plat = intval(getSetting('platinum_threshold', 15000));
        $gold = intval(getSetting('gold_threshold', 5000));
        $silver = intval(getSetting('silver_threshold', 1000));
        if ($np >= $plat) $level = 'Platinum';
        elseif ($np >= $gold) $level = 'Gold';
        elseif ($np >= $silver) $level = 'Silver';
        $db->prepare("UPDATE customers SET membership_level=? WHERE id=?")->execute([$level, $id]);
        adminLog('adjust_points', "Customer #$id: $pts points - $reason");
        echo json_encode(['success' => true, 'new_points' => $np, 'membership' => $level]);
        exit;
    }

    if ($action === 'block_customer') {
        if (!hasRole('manager')) { echo json_encode(['success' => false]); exit; }
        $id = intval($data['customer_id'] ?? 0);
        $reason = trim($data['reason'] ?? '');
        $db->prepare("INSERT IGNORE INTO blocked_customers (customer_id, reason) VALUES (?,?)")->execute([$id, $reason]);
        adminLog('block_customer', "Customer #$id");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'unblock_customer') {
        if (!hasRole('manager')) { echo json_encode(['success' => false]); exit; }
        $id = intval($data['customer_id'] ?? 0);
        $db->prepare("DELETE FROM blocked_customers WHERE customer_id=?")->execute([$id]);
        adminLog('unblock_customer', "Customer #$id");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'delete_customer') {
        if (!hasRole('superadmin')) { echo json_encode(['success' => false, 'message' => 'Superadmin only']); exit; }
        $id = intval($data['customer_id'] ?? 0);
        // Soft delete - just remove references or hard delete depending on need
        $db->prepare("DELETE FROM point_transactions WHERE customer_id=?")->execute([$id]);
        $db->prepare("DELETE FROM coupon_usage WHERE customer_id=?")->execute([$id]);
        $db->prepare("DELETE FROM blocked_customers WHERE customer_id=?")->execute([$id]);
        $db->prepare("UPDATE orders SET customer_id=0 WHERE customer_id=?")->execute([$id]);
        $db->prepare("DELETE FROM customers WHERE id=?")->execute([$id]);
        adminLog('delete_customer', "Customer #$id deleted");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'upgrade_membership') {
        if (!hasRole('manager')) { echo json_encode(['success' => false]); exit; }
        $id = intval($data['customer_id'] ?? 0);
        $level = $data['level'] ?? 'Bronze';
        if (!in_array($level, ['Bronze','Silver','Gold','Platinum'])) { echo json_encode(['success' => false]); exit; }
        $db->prepare("UPDATE customers SET membership_level=? WHERE id=?")->execute([$level, $id]);
        adminLog('upgrade_membership', "Customer #$id → $level");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'birthday_customers') {
        $custs = $db->query("SELECT * FROM customers WHERE DAY(birthday)=DAY(NOW()) AND MONTH(birthday)=MONTH(NOW()) ORDER BY full_name")->fetchAll();
        echo json_encode(['customers' => $custs]);
        exit;
    }

    // ---- MENU CATEGORIES ----
    if ($action === 'get_categories') {
        echo json_encode(['categories' => $db->query("SELECT * FROM menu_categories ORDER BY sort_order,id")->fetchAll()]);
        exit;
    }

    if ($action === 'save_category') {
        if (!hasRole('manager')) { echo json_encode(['success' => false]); exit; }
        $id = intval($data['id'] ?? 0);
        $name = trim($data['name'] ?? '');
        $icon = trim($data['icon'] ?? '☕');
        $sort = intval($data['sort_order'] ?? 0);
        if (!$name) { echo json_encode(['success' => false, 'message' => 'Name required']); exit; }
        if ($id) {
            $db->prepare("UPDATE menu_categories SET name=?,icon=?,sort_order=? WHERE id=?")->execute([$name,$icon,$sort,$id]);
        } else {
            $db->prepare("INSERT INTO menu_categories (name,icon,sort_order) VALUES (?,?,?)")->execute([$name,$icon,$sort]);
            $id = $db->lastInsertId();
        }
        adminLog('save_category', "Category #$id: $name");
        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }

    if ($action === 'delete_category') {
        if (!hasRole('manager')) { echo json_encode(['success' => false]); exit; }
        $id = intval($data['id'] ?? 0);
        $db->prepare("UPDATE menu_items SET category_id=NULL WHERE category_id=?")->execute([$id]);
        $db->prepare("DELETE FROM menu_categories WHERE id=?")->execute([$id]);
        adminLog('delete_category', "Category #$id deleted");
        echo json_encode(['success' => true]);
        exit;
    }

    // ---- MENU ITEMS ----
    if ($action === 'get_menu_items') {
        $page = max(1, intval($_GET['page'] ?? 1));
        $limit = 20; $offset = ($page-1)*$limit;
        $search = trim($_GET['search'] ?? '');
        $cat = intval($_GET['category'] ?? 0);
        $where = ['1=1']; $params = [];
        if ($search) { $where[] = "mi.name LIKE ?"; $params[] = "%$search%"; }
        if ($cat) { $where[] = "mi.category_id=?"; $params[] = $cat; }
        $where_str = implode(' AND ', $where);
        $tc = $db->prepare("SELECT COUNT(*) FROM menu_items mi WHERE $where_str"); $tc->execute($params);
        $total = $tc->fetchColumn();
        $stmt = $db->prepare("SELECT mi.*, mc.name as cat_name FROM menu_items mi LEFT JOIN menu_categories mc ON mi.category_id=mc.id WHERE $where_str ORDER BY mi.sort_order,mi.id LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        echo json_encode(['items' => $stmt->fetchAll(), 'total' => $total, 'pages' => ceil($total/$limit), 'page' => $page]);
        exit;
    }

    if ($action === 'save_menu_item') {
        if (!hasRole('manager')) { echo json_encode(['success' => false]); exit; }
        $id = intval($data['id'] ?? 0);
        $fields = ['category_id' => intval($data['category_id'] ?? 0) ?: null, 'name' => trim($data['name'] ?? ''), 'description' => trim($data['description'] ?? ''), 'price' => floatval($data['price'] ?? 0), 'image_url' => trim($data['image_url'] ?? ''), 'is_bestseller' => intval($data['is_bestseller'] ?? 0), 'is_recommended' => intval($data['is_recommended'] ?? 0), 'is_available' => intval($data['is_available'] ?? 1), 'sort_order' => intval($data['sort_order'] ?? 0)];
        if (!$fields['name'] || !$fields['price']) { echo json_encode(['success' => false, 'message' => 'Name & price required']); exit; }
        if ($id) {
            $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($fields)));
            $stmt = $db->prepare("UPDATE menu_items SET $sets WHERE id=?");
            $stmt->execute([...array_values($fields), $id]);
        } else {
            $cols = implode(',', array_keys($fields));
            $phs = implode(',', array_fill(0, count($fields), '?'));
            $db->prepare("INSERT INTO menu_items ($cols) VALUES ($phs)")->execute(array_values($fields));
            $id = $db->lastInsertId();
        }
        adminLog('save_menu_item', "Item #$id: ".$fields['name']);
        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }

    if ($action === 'delete_menu_item') {
        if (!hasRole('manager')) { echo json_encode(['success' => false]); exit; }
        $id = intval($data['id'] ?? 0);
        $db->prepare("DELETE FROM menu_items WHERE id=?")->execute([$id]);
        adminLog('delete_menu_item', "Item #$id deleted");
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'toggle_item_field') {
        if (!hasRole('manager')) { echo json_encode(['success' => false]); exit; }
        $id = intval($data['id'] ?? 0);
        $field = $data['field'] ?? '';
        $allowed_fields = ['is_available','is_bestseller','is_recommended'];
        if (!in_array($field, $allowed_fields)) { echo json_encode(['success' => false]); exit; }
        $db->prepare("UPDATE menu_items SET $field = 1 - $field WHERE id=?")->execute([$id]);
        $new_val = $db->prepare("SELECT $field FROM menu_items WHERE id=?"); $new_val->execute([$id]);
        echo json_encode(['success' => true, 'value' => $new_val->fetchColumn()]);
        exit;
    }

    if ($action === 'bulk_import_menu') {
        if (!hasRole('manager')) { echo json_encode(['success' => false]); exit; }
        $rows = $data['rows'] ?? [];
        $count = 0;
        $stmt = $db->prepare("INSERT INTO menu_items (category_id,name,description,price,image_url,is_available) VALUES (?,?,?,?,?,1) ON DUPLICATE KEY UPDATE price=VALUES(price)");
        foreach ($rows as $row) {
            if (empty($row['name']) || empty($row['price'])) continue;
            $cat_id = null;
            if (!empty($row['category'])) {
                $cat = $db->prepare("SELECT id FROM menu_categories WHERE name=?"); $cat->execute([trim($row['category'])]);
                $cat_id = $cat->fetchColumn() ?: null;
            }
            $stmt->execute([$cat_id, trim($row['name']), trim($row['description'] ?? ''), floatval($row['price']), trim($row['image_url'] ?? '')]);
            $count++;
        }
        adminLog('bulk_import_menu', "Imported $count items");
        echo json_encode(['success' => true, 'imported' => $count]);
        exit;
    }

    // ---- COMBO OFFERS ----
    if ($action === 'get_combos') {
        echo json_encode(['combos' => $db->query("SELECT * FROM combo_offers ORDER BY id DESC")->fetchAll()]);
        exit;
    }

    if ($action === 'save_combo') {
        if (!hasRole('manager')) { echo json_encode(['success' => false]); exit; }
        $id = intval($data['id'] ?? 0);
        $f = ['name' => trim($data['name'] ?? ''), 'description' => trim($data['description'] ?? ''), 'original_price' => floatval($data['original_price'] ?? 0), 'combo_price' => floatval($data['combo_price'] ?? 0), 'image_url' => trim($data['image_url'] ?? ''), 'is_active' => intval($data['is_active'] ?? 1)];
        if (!$f['name']) { echo json_encode(['success' => false]); exit; }
        if ($id) { $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($f))); $db->prepare("UPDATE combo_offers SET $sets WHERE id=?")->execute([...array_values($f), $id]); }
        else { $cols = implode(',', array_keys($f)); $phs = implode(',', array_fill(0, count($f), '?')); $db->prepare("INSERT INTO combo_offers ($cols) VALUES ($phs)")->execute(array_values($f)); $id = $db->lastInsertId(); }
        adminLog('save_combo', "Combo #$id: ".$f['name']);
        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }

    if ($action === 'delete_combo') {
        if (!hasRole('manager')) { echo json_encode(['success' => false]); exit; }
        $id = intval($data['id'] ?? 0);
        $db->prepare("DELETE FROM combo_offers WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ---- COUPONS ----
    if ($action === 'get_coupons') {
        $page = max(1, intval($_GET['page'] ?? 1)); $limit = 20; $offset = ($page-1)*$limit;
        $total = $db->query("SELECT COUNT(*) FROM coupons")->fetchColumn();
        $coupons = $db->query("SELECT c.*, (SELECT COUNT(*) FROM coupon_usage WHERE coupon_id=c.id) as actual_usage FROM coupons c ORDER BY c.id DESC LIMIT $limit OFFSET $offset")->fetchAll();
        echo json_encode(['coupons' => $coupons, 'total' => $total, 'pages' => ceil($total/$limit)]);
        exit;
    }

    if ($action === 'save_coupon') {
        if (!hasRole('manager')) { echo json_encode(['success' => false]); exit; }
        $id = intval($data['id'] ?? 0);
        $f = ['code' => strtoupper(trim($data['code'] ?? '')), 'discount_type' => $data['discount_type'] ?? 'percent', 'discount_value' => floatval($data['discount_value'] ?? 0), 'min_order' => floatval($data['min_order'] ?? 0), 'max_uses' => intval($data['max_uses'] ?? 100), 'expires_at' => $data['expires_at'] ?: null, 'is_active' => intval($data['is_active'] ?? 1), 'description' => trim($data['description'] ?? '')];
        if (!$f['code']) { echo json_encode(['success' => false, 'message' => 'Code required']); exit; }
        if ($id) { $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($f))); $db->prepare("UPDATE coupons SET $sets WHERE id=?")->execute([...array_values($f), $id]); }
        else { $cols = implode(',', array_keys($f)); $phs = implode(',', array_fill(0, count($f), '?')); $db->prepare("INSERT INTO coupons ($cols) VALUES ($phs)")->execute(array_values($f)); $id = $db->lastInsertId(); }
        adminLog('save_coupon', "Coupon #$id: ".$f['code']);
        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }

    if ($action === 'delete_coupon') {
        if (!hasRole('manager')) { echo json_encode(['success' => false]); exit; }
        $id = intval($data['id'] ?? 0);
        $db->prepare("DELETE FROM coupon_usage WHERE coupon_id=?")->execute([$id]);
        $db->prepare("DELETE FROM coupons WHERE id=?")->execute([$id]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'generate_coupon_code') {
        $code = strtoupper(substr(md5(uniqid()), 0, 8));
        echo json_encode(['code' => $code]);
        exit;
    }

    // ---- REWARDS ----
    if ($action === 'get_rewards') {
        echo json_encode(['rewards' => $db->query("SELECT * FROM rewards ORDER BY points_required")->fetchAll()]);
        exit;
    }

    if ($action === 'save_reward') {
        if (!hasRole('manager')) { echo json_encode(['success' => false]); exit; }
        $id = intval($data['id'] ?? 0);
        $f = ['name' => trim($data['name'] ?? ''), 'description' => trim($data['description'] ?? ''), 'points_required' => intval($data['points_required'] ?? 0), 'reward_type' => $data['reward_type'] ?? 'discount', 'reward_value' => trim($data['reward_value'] ?? ''), 'is_active' => intval($data['is_active'] ?? 1)];
        if (!$f['name']) { echo json_encode(['success' => false]); exit; }
        if ($id) { $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($f))); $db->prepare("UPDATE rewards SET $sets WHERE id=?")->execute([...array_values($f), $id]); }
        else { $cols = implode(',', array_keys($f)); $phs = implode(',', array_fill(0, count($f), '?')); $db->prepare("INSERT INTO rewards ($cols) VALUES ($phs)")->execute(array_values($f)); $id = $db->lastInsertId(); }
        echo json_encode(['success' => true, 'id' => $id]);
        exit;
    }

    if ($action === 'delete_reward') {
        if (!hasRole('manager')) { echo json_encode(['success' => false]); exit; }
        $db->prepare("DELETE FROM rewards WHERE id=?")->execute([intval($data['id'] ?? 0)]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ---- LOYALTY ----
    if ($action === 'get_point_history') {
        $page = max(1, intval($_GET['page'] ?? 1)); $limit = 25; $offset = ($page-1)*$limit;
        $search = trim($_GET['search'] ?? '');
        $where = ['1=1']; $params = [];
        if ($search) { $where[] = "(c.full_name LIKE ? OR c.mobile LIKE ?)"; $params = ["%$search%","%$search%"]; }
        $where_str = implode(' AND ', $where);
        $tc = $db->prepare("SELECT COUNT(*) FROM point_transactions pt LEFT JOIN customers c ON pt.customer_id=c.id WHERE $where_str"); $tc->execute($params);
        $total = $tc->fetchColumn();
        $stmt = $db->prepare("SELECT pt.*, c.full_name, c.mobile FROM point_transactions pt LEFT JOIN customers c ON pt.customer_id=c.id WHERE $where_str ORDER BY pt.created_at DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);
        echo json_encode(['transactions' => $stmt->fetchAll(), 'total' => $total, 'pages' => ceil($total/$limit)]);
        exit;
    }

    // ---- REPORTS ----
    if ($action === 'get_reports') {
        $type = $_GET['type'] ?? 'sales';
        $from = $_GET['from'] ?? date('Y-m-01');
        $to = $_GET['to'] ?? date('Y-m-d');

        if ($type === 'sales') {
            $data_r = $db->prepare("SELECT DATE(created_at) as date, COUNT(*) as orders, COALESCE(SUM(total),0) as revenue FROM orders WHERE DATE(created_at) BETWEEN ? AND ? AND status!='cancelled' GROUP BY DATE(created_at) ORDER BY date");
            $data_r->execute([$from, $to]);
            echo json_encode(['data' => $data_r->fetchAll()]);
        } elseif ($type === 'top_customers_spend') {
            $data_r = $db->query("SELECT c.full_name, c.mobile, c.membership_level, COUNT(o.id) as orders, COALESCE(SUM(o.total),0) as spent FROM customers c LEFT JOIN orders o ON c.id=o.customer_id WHERE o.status='completed' GROUP BY c.id ORDER BY spent DESC LIMIT 15");
            echo json_encode(['data' => $data_r->fetchAll()]);
        } elseif ($type === 'top_customers_points') {
            $data_r = $db->query("SELECT id, full_name, mobile, membership_level, points FROM customers ORDER BY points DESC LIMIT 15");
            echo json_encode(['data' => $data_r->fetchAll()]);
        } elseif ($type === 'top_items') {
            $all = $db->query("SELECT items_json FROM orders WHERE status!='cancelled'")->fetchAll();
            $items = []; $rev = [];
            foreach ($all as $o) { foreach (json_decode($o['items_json'] ?? '[]', true) as $i) { $n = $i['name'] ?? '?'; $items[$n] = ($items[$n] ?? 0) + ($i['qty'] ?? 1); $rev[$n] = ($rev[$n] ?? 0) + ($i['price'] ?? 0) * ($i['qty'] ?? 1); } }
            arsort($items);
            $result = array_slice(array_map(fn($k,$v) => ['name'=>$k,'qty'=>$v,'revenue'=>$rev[$k]??0], array_keys($items), array_values($items)), 0, 15);
            echo json_encode(['data' => $result]);
        } elseif ($type === 'coupons') {
            $data_r = $db->query("SELECT c.code, c.discount_type, c.discount_value, c.used_count, COUNT(cu.id) as usage_count FROM coupons c LEFT JOIN coupon_usage cu ON c.id=cu.coupon_id GROUP BY c.id ORDER BY usage_count DESC");
            echo json_encode(['data' => $data_r->fetchAll()]);
        } elseif ($type === 'repeat_customers') {
            $data_r = $db->query("SELECT c.full_name, c.mobile, COUNT(o.id) as order_count FROM customers c JOIN orders o ON c.id=o.customer_id WHERE o.status='completed' GROUP BY c.id HAVING order_count > 1 ORDER BY order_count DESC LIMIT 15");
            echo json_encode(['data' => $data_r->fetchAll()]);
        }
        exit;
    }

    // ---- SETTINGS ----
    if ($action === 'get_settings') {
        $stmt = $db->query("SELECT * FROM cafe_settings");
        $settings = [];
        foreach ($stmt->fetchAll() as $r) $settings[$r['key']] = $r['value'];
        echo json_encode(['settings' => $settings]);
        exit;
    }

    if ($action === 'save_settings') {
        if (!hasRole('superadmin')) { echo json_encode(['success' => false, 'message' => 'Superadmin only']); exit; }
        $stmt = $db->prepare("INSERT INTO cafe_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=?");
        foreach ($data as $k => $v) {
            if (preg_match('/^[a-z_]+$/', $k)) $stmt->execute([$k, $v, $v]);
        }
        adminLog('save_settings', 'Settings updated');
        echo json_encode(['success' => true]);
        exit;
    }

    // ---- STAFF / ADMIN USERS ----
    if ($action === 'get_staff') {
        if (!hasRole('superadmin')) { echo json_encode(['error' => 'Unauthorized']); exit; }
        echo json_encode(['staff' => $db->query("SELECT id,username,full_name,role,last_login,is_active,created_at FROM admin_users ORDER BY id")->fetchAll()]);
        exit;
    }

    if ($action === 'save_staff') {
        if (!hasRole('superadmin')) { echo json_encode(['success' => false]); exit; }
        $id = intval($data['id'] ?? 0);
        $u = trim($data['username'] ?? ''); $name = trim($data['full_name'] ?? ''); $role = $data['role'] ?? 'staff';
        $pass = $data['password'] ?? '';
        if (!in_array($role, ['superadmin','manager','staff'])) $role = 'staff';
        if ($id) {
            if ($pass) $db->prepare("UPDATE admin_users SET full_name=?,role=?,password=? WHERE id=?")->execute([$name,$role,password_hash($pass,PASSWORD_DEFAULT),$id]);
            else $db->prepare("UPDATE admin_users SET full_name=?,role=? WHERE id=?")->execute([$name,$role,$id]);
        } else {
            if (!$u || !$pass) { echo json_encode(['success'=>false,'message'=>'Username & password required']); exit; }
            $db->prepare("INSERT INTO admin_users (username,password,role,full_name) VALUES (?,?,?,?)")->execute([$u,password_hash($pass,PASSWORD_DEFAULT),$role,$name]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'toggle_staff') {
        if (!hasRole('superadmin')) { echo json_encode(['success' => false]); exit; }
        $id = intval($data['id'] ?? 0);
        $db->prepare("UPDATE admin_users SET is_active = 1 - is_active WHERE id=? AND id!=?")->execute([$id, $_SESSION[ADMIN_SESSION_KEY]]);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'admin_change_password') {
        $current = $data['current'] ?? ''; $new = $data['new'] ?? '';
        $admin = getAdmin();
        if (!password_verify($current, $admin['password'])) { echo json_encode(['success' => false, 'message' => 'Wrong current password']); exit; }
        if (strlen($new) < 6) { echo json_encode(['success' => false, 'message' => 'Password too short']); exit; }
        $db->prepare("UPDATE admin_users SET password=? WHERE id=?")->execute([password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
        echo json_encode(['success' => true]);
        exit;
    }

    // ---- UTILITIES ----
    if ($action === 'backup_sql') {
        if (!hasRole('superadmin')) { echo json_encode(['success' => false]); exit; }
        $tables = ['customers','menu_categories','menu_items','combo_offers','orders','coupons','coupon_usage','point_transactions','rewards','cafe_settings','admin_users'];
        $sql = "-- C3 Restro Database Backup\n-- Generated: ".date('Y-m-d H:i:s')."\n\n";
        foreach ($tables as $table) {
            try {
                $rows = $db->query("SELECT * FROM `$table`")->fetchAll();
                if (empty($rows)) continue;
                $cols = array_keys($rows[0]);
                $sql .= "-- Table: $table\n";
                foreach ($rows as $row) {
                    $vals = array_map(fn($v) => $v === null ? 'NULL' : "'".$db->quote(str_replace("'","\\'",$v))."'", array_values($row));
                    $sql .= "INSERT INTO `$table` (`".implode('`,`',$cols)."`) VALUES (".implode(',',$vals).");\n";
                }
                $sql .= "\n";
            } catch(Exception $e) {}
        }
        adminLog('backup_sql', 'Database backup exported');
        echo json_encode(['sql' => base64_encode($sql), 'filename' => 'c3restro_backup_'.date('Ymd_His').'.sql']);
        exit;
    }

    if ($action === 'reset_demo_data') {
        if (!hasRole('superadmin')) { echo json_encode(['success' => false]); exit; }
        // Clear transactional data
        $db->exec("DELETE FROM orders; DELETE FROM coupon_usage; DELETE FROM point_transactions; UPDATE coupons SET used_count=0; UPDATE customers SET points=50, membership_level='Bronze';");
        adminLog('reset_demo_data', 'Demo data reset');
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'reset_data') {
        if (!hasRole('superadmin')) { echo json_encode(['success' => false, 'message' => 'Superadmin only']); exit; }
        $scope = $data['scope'] ?? [];
        if (!is_array($scope) || empty($scope)) { echo json_encode(['success' => false, 'message' => 'No scope selected']); exit; }

        $cleared = [];
        $db->exec("SET FOREIGN_KEY_CHECKS=0");

        try {
            if (in_array('orders', $scope)) {
                $db->exec("DELETE FROM orders");
                $db->exec("DELETE FROM coupon_usage");
                $cleared[] = 'Orders & coupon usage';
                adminLog('reset_data', 'Orders cleared');
            }
            if (in_array('points', $scope)) {
                $db->exec("DELETE FROM point_transactions");
                $db->exec("UPDATE customers SET points=0, membership_level='Bronze'");
                $cleared[] = 'Customer points & transactions';
                adminLog('reset_data', 'Points reset');
            }
            if (in_array('coupons', $scope)) {
                $db->exec("DELETE FROM coupon_usage");
                $db->exec("DELETE FROM coupons");
                $cleared[] = 'Coupons';
                adminLog('reset_data', 'Coupons cleared');
            }
            if (in_array('menu', $scope)) {
                $db->exec("DELETE FROM menu_items");
                $db->exec("DELETE FROM menu_categories");
                $cleared[] = 'Menu items & categories';
                adminLog('reset_data', 'Menu cleared');
            }
            if (in_array('combos', $scope)) {
                $db->exec("DELETE FROM combo_offers");
                $cleared[] = 'Combo offers';
                adminLog('reset_data', 'Combos cleared');
            }
            if (in_array('rewards', $scope)) {
                $db->exec("DELETE FROM rewards");
                $cleared[] = 'Rewards';
                adminLog('reset_data', 'Rewards cleared');
            }
            if (in_array('customers', $scope)) {
                $db->exec("DELETE FROM point_transactions");
                $db->exec("DELETE FROM coupon_usage");
                $db->exec("DELETE FROM blocked_customers");
                $db->exec("DELETE FROM customers");
                $cleared[] = 'Customers (all data)';
                adminLog('reset_data', 'All customers deleted');
            }
            if (in_array('logs', $scope)) {
                $db->exec("DELETE FROM admin_logs");
                $cleared[] = 'Admin logs';
                adminLog('reset_data', 'Logs cleared');
            }
        } catch (Exception $e) {
            $db->exec("SET FOREIGN_KEY_CHECKS=1");
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            exit;
        }

        $db->exec("SET FOREIGN_KEY_CHECKS=1");
        echo json_encode(['success' => true, 'cleared' => $cleared]);
        exit;
    }

    if ($action === 'get_notifications') {
        $notifs = [];
        $pending = $db->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn();
        if ($pending > 0) $notifs[] = ['type' => 'warning', 'icon' => '⏳', 'msg' => "$pending pending order(s) need attention"];
        $bday = $db->query("SELECT COUNT(*) FROM customers WHERE DAY(birthday)=DAY(NOW()) AND MONTH(birthday)=MONTH(NOW())")->fetchColumn();
        if ($bday > 0) $notifs[] = ['type' => 'info', 'icon' => '🎂', 'msg' => "$bday customer(s) have birthday today!"];
        $new_today = $db->query("SELECT COUNT(*) FROM customers WHERE DATE(created_at)=CURDATE()")->fetchColumn();
        if ($new_today > 0) $notifs[] = ['type' => 'success', 'icon' => '👤', 'msg' => "$new_today new customer(s) signed up today"];
        $low = $db->query("SELECT COUNT(*) FROM menu_items WHERE is_available=0")->fetchColumn();
        if ($low > 0) $notifs[] = ['type' => 'danger', 'icon' => '❌', 'msg' => "$low menu item(s) marked unavailable"];
        echo json_encode(['notifications' => $notifs, 'count' => count($notifs)]);
        exit;
    }

    if ($action === 'get_qr_data') {
        $url = getSetting('menu_url', 'cafe_panel.php');
        echo json_encode(['url' => $url]);
        exit;
    }

    // ---- LOYALTY ANALYTICS ----
    if ($action === 'loyalty_analytics') {
        $total_issued = $db->query("SELECT COALESCE(SUM(points),0) FROM point_transactions WHERE type IN ('earn','bonus','birthday','referral')")->fetchColumn();
        $total_redeemed = $db->query("SELECT COALESCE(SUM(ABS(points)),0) FROM point_transactions WHERE type='redeem'")->fetchColumn();
        $active_loyalty = $db->query("SELECT COUNT(*) FROM customers WHERE points > 0")->fetchColumn();
        $top_customers = $db->query("SELECT id, full_name, mobile, points, membership_level FROM customers ORDER BY points DESC LIMIT 5")->fetchAll();
        $level_dist = $db->query("SELECT membership_level, COUNT(*) as cnt FROM customers GROUP BY membership_level")->fetchAll();
        $recent_txns = $db->query("SELECT pt.*, c.full_name FROM point_transactions pt LEFT JOIN customers c ON pt.customer_id=c.id ORDER BY pt.created_at DESC LIMIT 10")->fetchAll();
        $top_reward = $db->query("SELECT r.name, COUNT(cu.id) as uses FROM rewards r LEFT JOIN coupon_usage cu ON cu.coupon_id=r.id GROUP BY r.id ORDER BY uses DESC LIMIT 1")->fetch();
        echo json_encode([
            'total_issued' => $total_issued,
            'total_redeemed' => $total_redeemed,
            'active_loyalty' => $active_loyalty,
            'top_customers' => $top_customers,
            'level_dist' => $level_dist,
            'recent_txns' => $recent_txns,
            'top_reward' => $top_reward,
        ]);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ============================================================
// HTML RENDERING — Only if not API
// ============================================================
$admin = getAdmin();
$cafe_name = getSetting('cafe_name', 'C3 Restro');
$theme_color = getSetting('theme_color', '#16a34a');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($cafe_name) ?> · Admin Panel</title>
<link rel="dns-prefetch" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<style>
:root {
  /* ── Brand / Primary (green from main1.php) ── */
  --p1: #14532d; --p2: #166534; --p3: #16a34a; --p4: #22c55e; --p5: #4ade80;
  --p-light: #dcfce7; --p-glow: rgba(22,163,74,0.18);
  /* Gradient aliases used throughout */
  --grad: linear-gradient(135deg, #166534 0%, #16a34a 100%);
  --grad-warm: linear-gradient(135deg, #14532d 0%, #16a34a 100%);
  /* Backgrounds */
  --bg: #f8f9fa; --bg2: #ffffff; --bg3: #f1f3f5; --bg4: #f8f9fa;
  /* Borders */
  --border: #e5e7eb; --border2: #d1d5db;
  /* Text */
  --text: #111827; --text2: #374151; --text3: #6b7280; --text-muted: #9ca3af;
  /* Semantic colours */
  --success: #10b981; --success-bg: #d1fae5;
  --warning: #f59e0b; --warning-bg: #fef3c7;
  --danger: #ef4444; --danger-bg: #fee2e2;
  --info: #2563eb; --info-bg: #dbeafe;
  /* Shadows */
  --shadow: 0 4px 16px rgba(0,0,0,0.08), 0 1px 4px rgba(0,0,0,0.04);
  --shadow-md: 0 6px 24px rgba(0,0,0,0.10), 0 2px 8px rgba(0,0,0,0.05);
  --shadow-lg: 0 12px 40px rgba(0,0,0,0.12), 0 4px 12px rgba(0,0,0,0.06);
  --shadow-green: 0 4px 16px rgba(22,163,74,0.22);
  /* Radii */
  --radius: 16px; --radius-sm: 10px; --radius-lg: 22px;
  /* Layout */
  --sidebar-w: 248px;
  /* Fonts */
  --font: 'DM Sans', system-ui, sans-serif;
  --font-display: 'Playfair Display', Georgia, serif;
  --mono: 'JetBrains Mono', monospace;
  /* Transitions */
  --transition: all 0.22s cubic-bezier(0.4,0,0.2,1);
  --transition-bounce: all 0.32s cubic-bezier(0.34,1.56,0.64,1);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 14px; scroll-behavior: smooth; -webkit-tap-highlight-color: transparent; }
body {
  font-family: var(--font); background: var(--bg); color: var(--text);
  min-height: 100vh; line-height: 1.6;
  background-image:
    radial-gradient(ellipse at 20% 0%, rgba(22,163,74,0.04) 0%, transparent 50%),
    radial-gradient(ellipse at 80% 100%, rgba(22,163,74,0.02) 0%, transparent 50%);
  background-attachment: fixed;
}
button { font-family: var(--font); cursor: pointer; border: none; outline: none; }
input, select, textarea { font-family: var(--font); }
a { color: var(--p3); text-decoration: none; }
img { max-width: 100%; }

/* ─── SCROLLBAR ─── */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--p-light); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: var(--p5); }

/* ─── LOGIN ─── */
.login-screen {
  min-height: 100vh; display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, #052e16 0%, #14532d 50%, #166534 100%);
  position: relative; overflow: hidden;
}
.login-bg-orb {
  position: absolute; border-radius: 50%; filter: blur(80px); opacity: 0.35; animation: float 8s ease-in-out infinite;
}
.login-bg-orb:nth-child(1) { width:400px;height:400px;background:#16a34a;top:-100px;left:-100px; }
.login-bg-orb:nth-child(2) { width:300px;height:300px;background:#22c55e;bottom:-80px;right:-60px;animation-delay:-4s; }
@keyframes float { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-20px)} }
.login-card {
  background: rgba(255,255,255,0.96); backdrop-filter: blur(20px);
  border-radius: var(--radius-lg); padding: 48px 40px; width: 100%; max-width: 420px;
  position: relative; z-index: 1; box-shadow: 0 32px 80px rgba(0,0,0,0.35);
  animation: fadeUp 0.5s ease;
}
@keyframes fadeUp { from{opacity:0;transform:translateY(24px)} to{opacity:1;transform:translateY(0)} }
.login-logo {
  text-align: center; margin-bottom: 32px;
}
.login-logo .logo-icon {
  width: 64px; height: 64px; border-radius: var(--radius);
  background: var(--grad);
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 28px; margin-bottom: 12px; box-shadow: var(--shadow-green);
}
.login-logo h1 { font-size: 22px; font-weight: 700; color: var(--text); font-family: var(--font-display); }
.login-logo p { font-size: 13px; color: var(--text3); margin-top: 2px; }

/* ─── LAYOUT ─── */
.admin-layout { display: flex; min-height: 100vh; }
.sidebar {
  width: var(--sidebar-w); background: linear-gradient(180deg, #052e16 0%, #14532d 50%, #166534 100%);
  height: 100vh; position: fixed; left: 0; top: 0; z-index: 100;
  display: flex; flex-direction: column; transition: transform 0.3s ease;
  box-shadow: 4px 0 24px rgba(0,0,0,0.2);
}
.sidebar-logo {
  padding: 24px 20px; border-bottom: 1px solid rgba(255,255,255,0.08);
  display: flex; align-items: center; gap: 12px;
}
.sidebar-logo .s-icon {
  width: 40px; height: 40px; border-radius: 12px;
  background: linear-gradient(135deg, var(--p3), var(--p5));
  display: flex; align-items: center; justify-content: center; font-size: 18px;
  box-shadow: 0 4px 12px rgba(139,92,246,0.4); flex-shrink: 0;
}
.sidebar-logo .s-text h2 { font-size: 15px; font-weight: 700; color: #fff; line-height: 1.2; }
.sidebar-logo .s-text p { font-size: 11px; color: rgba(255,255,255,0.5); }
.sidebar-nav { flex: 1; overflow-y: auto; padding: 12px 12px; }
.nav-section-label {
  font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 1.2px;
  color: rgba(255,255,255,0.35); padding: 12px 8px 6px;
}
.nav-item {
  display: flex; align-items: center; gap: 10px; padding: 10px 12px;
  border-radius: var(--radius-sm); cursor: pointer; transition: all 0.18s ease;
  color: rgba(255,255,255,0.65); font-size: 13.5px; font-weight: 500;
  margin-bottom: 2px; position: relative;
}
.nav-item:hover { background: rgba(255,255,255,0.08); color: #fff; }
.nav-item.active {
  background: linear-gradient(135deg, rgba(139,92,246,0.35), rgba(124,58,237,0.2));
  color: #fff; box-shadow: 0 2px 8px rgba(139,92,246,0.2);
}
.nav-item.active::before {
  content:''; position:absolute; left:0; top:6px; bottom:6px; width:3px;
  background: var(--p4); border-radius: 2px;
}
.nav-item .nav-icon { font-size: 16px; width: 20px; text-align: center; flex-shrink: 0; }
.nav-badge { margin-left: auto; background: #ef4444; color: #fff; font-size: 10px; font-weight: 700; padding: 1px 6px; border-radius: 10px; }
.sidebar-footer {
  padding: 16px 12px; border-top: 1px solid rgba(255,255,255,0.08);
}
.admin-profile-mini {
  display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: var(--radius-sm);
  cursor: pointer; transition: background 0.15s;
}
.admin-profile-mini:hover { background: rgba(255,255,255,0.08); }
.admin-avatar {
  width: 34px; height: 34px; border-radius: 10px; flex-shrink: 0;
  background: linear-gradient(135deg, var(--p3), var(--p5));
  display: flex; align-items: center; justify-content: center;
  font-weight: 700; color: #fff; font-size: 13px;
}
.admin-profile-mini .admin-info { flex: 1; min-width: 0; }
.admin-profile-mini .admin-name { font-size: 13px; font-weight: 600; color: #fff; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.admin-profile-mini .admin-role { font-size: 11px; color: rgba(255,255,255,0.45); }

/* ─── MAIN CONTENT ─── */
.main-content { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }
.topbar {
  height: 64px; background: var(--bg2); border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 16px; padding: 0 28px;
  position: sticky; top: 0; z-index: 50; box-shadow: var(--shadow);
}
.topbar-hamburger { display: none; background: none; padding: 6px; border-radius: 8px; color: var(--text2); font-size: 18px; }
.topbar-title { font-size: 18px; font-weight: 700; color: var(--text); flex: 1; }
.topbar-title span { font-size: 12px; font-weight: 500; color: var(--text3); display: block; }
.topbar-actions { display: flex; align-items: center; gap: 10px; }
.notif-btn {
  position: relative; width: 38px; height: 38px; border-radius: 10px;
  background: var(--bg3); display: flex; align-items: center; justify-content: center;
  cursor: pointer; transition: background 0.15s; font-size: 16px;
}
.notif-btn:hover { background: var(--p-light); }
.notif-dot { position: absolute; top: 6px; right: 6px; width: 8px; height: 8px; background: #ef4444; border-radius: 50%; border: 2px solid var(--bg2); }
.page-content { flex: 1; padding: 28px; overflow-y: auto; }
.page { display: none; }
.page.active { display: block; animation: pageFade 0.25s ease; }
@keyframes pageFade { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }

/* ─── CARDS & STATS ─── */
.stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
.stat-card {
  background: var(--bg2); border-radius: var(--radius); padding: 20px;
  box-shadow: var(--shadow); border: 1px solid var(--border); position: relative; overflow: hidden;
  transition: transform 0.2s, box-shadow 0.2s;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-card::after {
  content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
  background: linear-gradient(90deg, var(--p2), var(--p4));
}
.stat-card .stat-icon { font-size: 24px; margin-bottom: 10px; }
.stat-card .stat-value { font-size: 26px; font-weight: 800; color: var(--text); line-height: 1; }
.stat-card .stat-label { font-size: 12px; color: var(--text3); margin-top: 4px; font-weight: 500; }
.stat-card .stat-sub { font-size: 11px; color: var(--text-muted); margin-top: 4px; }
.stat-card.highlight::after { background: linear-gradient(90deg, #059669, #10b981); }
.stat-card.highlight .stat-value { color: var(--success); }
.stat-card.warn::after { background: linear-gradient(90deg, #d97706, #f59e0b); }
.stat-card.warn .stat-value { color: var(--warning); }

/* ─── TABLES ─── */
.table-card { background: var(--bg2); border-radius: var(--radius); box-shadow: var(--shadow); border: 1px solid var(--border); overflow: hidden; }
.table-header { padding: 16px 20px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; border-bottom: 1px solid var(--border); }
.table-header h3 { font-size: 15px; font-weight: 700; flex: 1; }
.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; }
thead th { background: var(--bg3); padding: 11px 16px; text-align: left; font-size: 11.5px; font-weight: 700; color: var(--text3); text-transform: uppercase; letter-spacing: 0.5px; white-space: nowrap; }
tbody tr { border-bottom: 1px solid var(--border); transition: background 0.12s; }
tbody tr:hover { background: var(--bg4); }
tbody tr:last-child { border-bottom: none; }
tbody td { padding: 11px 16px; font-size: 13px; color: var(--text); vertical-align: middle; }
.empty-row td { text-align: center; padding: 40px; color: var(--text3); }

/* ─── FORMS ─── */
.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group label { font-size: 12.5px; font-weight: 600; color: var(--text2); }
.form-input, .form-select, .form-textarea {
  padding: 9px 12px; border: 1.5px solid var(--border2); border-radius: var(--radius-sm);
  background: var(--bg); font-size: 13.5px; color: var(--text); transition: border-color 0.15s, box-shadow 0.15s;
  width: 100%;
}
.form-input:focus, .form-select:focus, .form-textarea:focus { outline: none; border-color: var(--p3); box-shadow: 0 0 0 3px var(--p-glow); }
.form-textarea { min-height: 80px; resize: vertical; }
.form-select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%237b6fa0' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 10px center; padding-right: 32px; }

/* ─── BUTTONS ─── */
.btn { display: inline-flex; align-items: center; gap: 7px; padding: 8px 16px; border-radius: var(--radius-sm); font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.18s; border: none; outline: none; white-space: nowrap; }
.btn-primary { background: linear-gradient(135deg, var(--p2), var(--p4)); color: #fff; box-shadow: 0 2px 8px var(--p-glow); }
.btn-primary:hover { background: linear-gradient(135deg, var(--p1), var(--p3)); box-shadow: 0 4px 16px var(--p-glow); transform: translateY(-1px); }
.btn-secondary { background: var(--p-light); color: var(--p2); }
.btn-secondary:hover { background: #bbf7d0; }
.btn-ghost { background: var(--bg3); color: var(--text2); border: 1px solid var(--border); }
.btn-ghost:hover { background: var(--bg); border-color: var(--p5); }
.btn-danger { background: var(--danger-bg); color: var(--danger); border: 1px solid #fca5a5; }
.btn-danger:hover { background: #fee2e2; }
.btn-success { background: var(--success-bg); color: var(--success); border: 1px solid #6ee7b7; }
.btn-sm { padding: 5px 10px; font-size: 12px; }
.btn-xs { padding: 3px 8px; font-size: 11px; border-radius: 6px; }
.btn-icon { width: 32px; height: 32px; padding: 0; justify-content: center; border-radius: 8px; }
.btn-full { width: 100%; justify-content: center; }

/* ─── BADGES ─── */
.badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 20px; font-size: 11.5px; font-weight: 600; }
.badge-purple { background: var(--p-light); color: var(--p2); }
.badge-success { background: var(--success-bg); color: var(--success); }
.badge-warning { background: var(--warning-bg); color: var(--warning); }
.badge-danger { background: var(--danger-bg); color: var(--danger); }
.badge-info { background: var(--info-bg); color: var(--info); }
.badge-gray { background: #f1f5f9; color: #64748b; }

/* ─── SEARCH & FILTER BAR ─── */
.filter-bar { display: flex; gap: 10px; flex-wrap: wrap; }
.search-wrap { position: relative; }
.search-wrap .search-icon { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); color: var(--text-muted); font-size: 14px; }
.search-input { padding-left: 32px !important; min-width: 220px; }

/* ─── MODALS ─── */
.modal-overlay {
  position: fixed; inset: 0; background: rgba(15,5,40,0.65); backdrop-filter: blur(4px);
  z-index: 1000; display: flex; align-items: center; justify-content: center;
  animation: fadeIn 0.2s ease;
}
@keyframes fadeIn { from{opacity:0} to{opacity:1} }
.modal { background: var(--bg2); border-radius: var(--radius-lg); padding: 0; width: 100%; max-width: 540px; max-height: 90vh; overflow-y: auto; box-shadow: var(--shadow-lg); animation: slideUp 0.25s ease; }
@keyframes slideUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }
.modal-lg { max-width: 720px; }
.modal-header { padding: 20px 24px 16px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; background: var(--bg2); z-index: 1; border-radius: var(--radius-lg) var(--radius-lg) 0 0; }
.modal-header h3 { font-size: 16px; font-weight: 700; }
.modal-close { width: 30px; height: 30px; border-radius: 8px; background: var(--bg3); display: flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text3); transition: background 0.15s; font-size: 16px; }
.modal-close:hover { background: var(--p-light); color: var(--p2); }
.modal-body { padding: 20px 24px; }
.modal-footer { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; gap: 10px; justify-content: flex-end; }

/* ─── TOGGLES ─── */
.toggle { position: relative; display: inline-block; width: 40px; height: 22px; }
.toggle input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; cursor: pointer; inset: 0; background: #d1d5db; border-radius: 22px; transition: 0.2s; }
.toggle-slider::before { content:''; position:absolute; height:16px; width:16px; left:3px; bottom:3px; background:#fff; border-radius:50%; transition:0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.2); }
.toggle input:checked + .toggle-slider { background: linear-gradient(135deg, var(--p2), var(--p4)); }
.toggle input:checked + .toggle-slider::before { transform: translateX(18px); }

/* ─── PAGINATION ─── */
.pagination { display: flex; align-items: center; gap: 6px; padding: 14px 20px; justify-content: flex-end; border-top: 1px solid var(--border); flex-wrap: wrap; }
.pagination .pg-info { font-size: 12px; color: var(--text3); margin-right: auto; }
.pg-btn { min-width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; cursor: pointer; border: 1px solid var(--border); background: var(--bg2); color: var(--text2); transition: all 0.15s; }
.pg-btn:hover { border-color: var(--p4); color: var(--p3); }
.pg-btn.active { background: linear-gradient(135deg, var(--p2), var(--p4)); color: #fff; border-color: transparent; }
.pg-btn:disabled { opacity: 0.4; cursor: default; }

/* ─── TOAST ─── */
.toast-container { position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; pointer-events: none; }
.toast {
  display: flex; align-items: center; gap: 10px; padding: 12px 16px;
  border-radius: var(--radius); min-width: 280px; max-width: 380px;
  background: var(--bg2); box-shadow: var(--shadow-lg); border-left: 4px solid var(--p3);
  animation: toastIn 0.3s ease; pointer-events: all; font-size: 13px; font-weight: 500;
}
.toast.success { border-color: var(--success); }
.toast.error { border-color: var(--danger); }
.toast.warning { border-color: var(--warning); }
@keyframes toastIn { from{opacity:0;transform:translateX(100%)} to{opacity:1;transform:translateX(0)} }
@keyframes toastOut { from{opacity:1;transform:translateX(0)} to{opacity:0;transform:translateX(100%)} }

/* ─── CHART ─── */
.chart-wrap { position: relative; height: 200px; margin-top: 12px; }
canvas { max-height: 200px; }

/* ─── STATUS ─── */
.status-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 10px; border-radius: 20px; font-size: 11.5px; font-weight: 600; }
.status-pending { background: #fef3c7; color: #92400e; }
.status-confirmed { background: #dbeafe; color: #1e40af; }
.status-preparing { background: #dcfce7; color: #166534; }
.status-ready { background: #d1fae5; color: #065f46; }
.status-completed { background: #d1fae5; color: var(--success); }
.status-cancelled { background: #fee2e2; color: var(--danger); }

/* ─── SECTION HEADER ─── */
.section-header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; flex-wrap: wrap; }
.section-header h2 { font-size: 20px; font-weight: 800; flex: 1; }
.page-back { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 600; color: var(--p3); cursor: pointer; }

/* ─── MISC ─── */
.divider { border: none; border-top: 1px solid var(--border); margin: 16px 0; }
.flex { display: flex; }
.items-center { align-items: center; }
.gap-2 { gap: 8px; }
.gap-3 { gap: 12px; }
.ml-auto { margin-left: auto; }
.text-muted { color: var(--text3); font-size: 12px; }
.text-right { text-align: right; }
.mt-4 { margin-top: 16px; }
.mb-4 { margin-bottom: 16px; }
.p-4 { padding: 16px; }
.grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 16px; }
.fw-700 { font-weight: 700; }
.fs-12 { font-size: 12px; }
.card { background: var(--bg2); border-radius: var(--radius); padding: 20px; border: 1px solid var(--border); box-shadow: var(--shadow); }
.notif-panel { position: absolute; top: 48px; right: 0; width: 340px; background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-lg); z-index: 200; display: none; }
.notif-panel.open { display: block; animation: pageFade 0.2s ease; }
.notif-item { display: flex; gap: 10px; align-items: flex-start; padding: 12px 16px; border-bottom: 1px solid var(--border); font-size: 13px; }
.notif-item:last-child { border-bottom: none; }
.avatar-initials { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, var(--p3), var(--p5)); display: flex; align-items: center; justify-content: center; font-weight: 700; color: #fff; font-size: 13px; flex-shrink: 0; }
.mobile-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 99; }

/* ─── MOBILE BOTTOM NAV ─── */
.mobile-bottom-nav {
  display: none;
  position: fixed; bottom: 0; left: 0; right: 0; z-index: 98;
  background: var(--bg2); border-top: 1px solid var(--border);
  box-shadow: 0 -4px 20px rgba(0,0,0,0.08);
  padding: 0; height: 60px;
  justify-content: space-around; align-items: stretch;
}
.mob-nav-item {
  flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
  gap: 3px; cursor: pointer; padding: 6px 4px; font-size: 10px; font-weight: 600;
  color: var(--text3); transition: color 0.15s; position: relative; border: none;
  background: none; font-family: var(--font);
}
.mob-nav-item.active { color: var(--p3); }
.mob-nav-item .mob-nav-icon { font-size: 18px; line-height: 1; }
.mob-nav-badge {
  position: absolute; top: 4px; right: calc(50% - 16px);
  background: #ef4444; color: #fff; font-size: 9px; font-weight: 700;
  padding: 1px 5px; border-radius: 10px; min-width: 16px; text-align: center;
}

/* ─── ORDER CARD (mobile) ─── */
#orders-mobile-cards { display: none; }
.order-card-mobile {
  background: var(--bg2); border: 1px solid var(--border); border-radius: var(--radius-sm);
  padding: 14px; margin-bottom: 10px; box-shadow: var(--shadow);
}
.order-card-mobile .ocm-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.order-card-mobile .ocm-num { font-family: var(--mono); font-weight: 700; font-size: 13px; color: var(--p2); }
.order-card-mobile .ocm-meta { font-size: 12px; color: var(--text3); display: flex; flex-direction: column; gap: 2px; }
.order-card-mobile .ocm-items { font-size: 12px; color: var(--text2); margin: 6px 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.order-card-mobile .ocm-footer { display: flex; align-items: center; justify-content: space-between; margin-top: 8px; gap: 6px; flex-wrap: wrap; }
.order-card-mobile .ocm-total { font-weight: 700; font-size: 15px; color: var(--p2); }
.order-card-mobile .ocm-actions { display: flex; gap: 6px; flex-wrap: wrap; }
.order-card-mobile select.form-select { font-size: 12px; padding: 5px 8px; width: auto; }

/* ─── TABLE NUMBER BADGE ─── */
.table-num-badge {
  display: inline-flex; align-items: center; gap: 4px;
  background: #fff7ed; border: 1px solid #fed7aa; color: #c2410c;
  font-size: 11.5px; font-weight: 700; padding: 3px 9px; border-radius: 20px;
}

/* ─── RESPONSIVE ─── */
@media (max-width: 900px) {
  :root { --sidebar-w: 0px; }
  .sidebar {
    width: 260px; transform: translateX(-260px); z-index: 100;
    transition: transform 0.28s cubic-bezier(0.4,0,0.2,1);
  }
  .sidebar.open { transform: translateX(0); box-shadow: 6px 0 40px rgba(0,0,0,0.35); }
  .topbar-hamburger { display: flex; }
  .main-content { margin-left: 0; }
  .mobile-overlay.visible { display: block; }
  .page-content { padding: 14px; }
  .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; margin-bottom: 16px; }
  .grid-2, .grid-3 { grid-template-columns: 1fr; }
  .topbar { padding: 0 14px; gap: 10px; height: 56px; }
  .topbar-title { font-size: 15px; }
  .topbar-title span { display: none; }
  .notif-panel { width: 300px; right: -8px; }
  .table-wrap table { min-width: 600px; }
  .section-header { margin-bottom: 14px; }
  .section-header h2 { font-size: 17px; }
  .filter-bar { gap: 8px; }
  .filter-bar .form-input, .filter-bar .form-select { font-size: 13px; }
  .modal { max-width: 100%; border-radius: var(--radius-lg) var(--radius-lg) 0 0; }
  .modal-overlay { align-items: flex-end; }
  /* Hide desktop orders table on mobile, show cards */
  #orders-desktop-table { display: none; }
  #orders-mobile-cards { display: block; }
  .mobile-bottom-nav { display: flex; }
  /* Extra bottom padding so content isn't hidden under bottom nav */
  .page-content { padding-bottom: 72px; }
  /* Hide sound toggle text on mobile */
  #order-sound-toggle .btn-label { display: none; }
  .card { padding: 14px; }
  .stat-card { padding: 14px; }
  .stat-card .stat-value { font-size: 22px; }
}
@media (max-width: 480px) {
  .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; }
  .filter-bar { flex-direction: column; align-items: stretch; }
  .filter-bar .search-wrap, .filter-bar .form-select, .filter-bar .form-input { width: 100% !important; min-width: 0; }
  .search-input { min-width: 0 !important; }
  .modal { max-width: 100%; border-radius: var(--radius-lg) var(--radius-lg) 0 0; max-height: 95vh; }
  .modal-overlay { align-items: flex-end; }
  .topbar-actions > *:not(:last-child):not(:nth-last-child(2)) { display: none; }
  .page-content { padding: 10px 10px 72px; }
}
</style>
</head>
<body>

<div class="toast-container" id="toast-container"></div>
<div class="mobile-overlay" id="mobile-overlay" onclick="closeSidebar()"></div>

<?php if (!$admin): ?>
<!-- ============================================================ LOGIN ============================================================ -->
<div class="login-screen" id="login-screen">
  <div class="login-bg-orb"></div>
  <div class="login-bg-orb"></div>
  <div class="login-card">
    <div class="login-logo">
      <div class="logo-icon">☕</div>
      <h1><?= htmlspecialchars($cafe_name) ?></h1>
      <p>Admin Panel · Secure Access</p>
    </div>
    <div style="display:flex;flex-direction:column;gap:14px;">
      <div class="form-group">
        <label>Username</label>
        <input type="text" class="form-input" id="l-user" placeholder="Enter username" autocomplete="username">
      </div>
      <div class="form-group">
        <label>Password</label>
        <div style="position:relative">
          <input type="password" class="form-input" id="l-pass" placeholder="Enter password" autocomplete="current-password" style="padding-right:40px">
          <span onclick="togglePass()" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);cursor:pointer;color:var(--text3);font-size:16px" id="pass-eye">👁</span>
        </div>
      </div>
      <button class="btn btn-primary btn-full" onclick="doAdminLogin()" id="login-btn" style="padding:12px;">
        🔐 Sign In to Admin Panel
      </button>
    </div>
 
  </div>
</div>

<?php else: ?>
<!-- ============================================================ ADMIN LAYOUT ============================================================ -->
<div class="admin-layout">

  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-logo">
      <div class="s-icon">☕</div>
      <div class="s-text">
        <h2><?= htmlspecialchars($cafe_name) ?></h2>
        <p>Admin Panel v<?= APP_VERSION ?></p>
      </div>
    </div>
    <nav class="sidebar-nav">
      <div class="nav-section-label">Main</div>
      <div class="nav-item active" onclick="showPage('dashboard')">
        <span class="nav-icon">📊</span> Dashboard
      </div>
      <div class="nav-item" onclick="showPage('orders')">
        <span class="nav-icon">🧾</span> Orders
        <span class="nav-badge" id="nb-orders" style="display:none">0</span>
      </div>
      <div class="nav-item" onclick="showPage('customers')">
        <span class="nav-icon">👥</span> Customers
      </div>

      <div class="nav-section-label">Catalog</div>
      <div class="nav-item" onclick="showPage('menu')">
        <span class="nav-icon">🍽️</span> Menu Items
      </div>
      <div class="nav-item" onclick="showPage('categories')">
        <span class="nav-icon">📂</span> Categories
      </div>
      <div class="nav-item" onclick="showPage('combos')">
        <span class="nav-icon">🎁</span> Combo Offers
      </div>

      <div class="nav-section-label">Loyalty</div>
      <div class="nav-item" onclick="showPage('coupons')">
        <span class="nav-icon">🎟️</span> Coupons
      </div>
      <div class="nav-item" onclick="showPage('rewards')">
        <span class="nav-icon">⭐</span> Rewards
      </div>
      <div class="nav-item" onclick="showPage('loyalty')">
        <span class="nav-icon">💎</span> Points History
      </div>
      <div class="nav-item" onclick="showPage('loyalty-analytics')">
        <span class="nav-icon">📊</span> Loyalty Analytics
      </div>
      <div class="nav-item" onclick="showPage('loyalty-settings')">
        <span class="nav-icon">🪙</span> Loyalty Settings
      </div>

      <div class="nav-section-label">Insights</div>
      <div class="nav-item" onclick="showPage('reports')">
        <span class="nav-icon">📈</span> Reports
      </div>
      <div class="nav-item" onclick="showPage('notifications')">
        <span class="nav-icon">🔔</span> Notifications
        <span class="nav-badge" id="nb-notif" style="display:none">0</span>
      </div>

      <?php if (hasRole('superadmin')): ?>
      <div class="nav-section-label">Admin</div>
      <div class="nav-item" onclick="showPage('staff')">
        <span class="nav-icon">🔑</span> Staff & Roles
      </div>
      <div class="nav-item" onclick="showPage('settings')">
        <span class="nav-icon">⚙️</span> Settings
      </div>
      <div class="nav-item" onclick="showPage('tools')">
        <span class="nav-icon">🛠️</span> Tools
      </div>
      <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
      <div class="admin-profile-mini" onclick="showPage('profile')">
        <div class="admin-avatar"><?= strtoupper(substr($admin['full_name'] ?? 'A', 0, 2)) ?></div>
        <div class="admin-info">
          <div class="admin-name"><?= htmlspecialchars($admin['full_name'] ?? 'Admin') ?></div>
          <div class="admin-role"><?= ucfirst($admin['role']) ?></div>
        </div>
        <span style="color:rgba(255,255,255,0.4);font-size:12px">›</span>
      </div>
    </div>
  </aside>

  <!-- MAIN -->
  <div class="main-content">
    <header class="topbar">
      <button class="topbar-hamburger" onclick="toggleSidebar()">☰</button>
      <div class="topbar-title" id="page-title">Dashboard <span id="page-subtitle">Overview & live stats</span></div>
      <div class="topbar-actions">
        <div style="position:relative">
          <div class="notif-btn" onclick="toggleNotifPanel()" id="notif-btn">🔔
            <div class="notif-dot" id="notif-dot" style="display:none"></div>
          </div>
          <div class="notif-panel" id="notif-panel">
            <div style="padding:14px 16px;font-weight:700;font-size:14px;border-bottom:1px solid var(--border)">🔔 Notifications</div>
            <div id="notif-list"><div style="padding:20px;text-align:center;color:var(--text3)">Loading…</div></div>
          </div>
        </div>
        <button class="btn btn-ghost btn-sm" id="order-sound-toggle" onclick="toggleOrderSound()" title="Toggle new order alert sound"><span id="sound-icon">🔔</span><span class="btn-label"> Sound ON</span></button>
        <button class="btn btn-ghost btn-sm" onclick="doAdminLogout()">🚪 Logout</button>
      </div>
    </header>

    <!-- MOBILE BOTTOM NAV -->
    <nav class="mobile-bottom-nav" id="mobile-bottom-nav">
      <button class="mob-nav-item active" id="mbn-dashboard" onclick="showPage('dashboard');setMobActive('dashboard')">
        <span class="mob-nav-icon">📊</span>Dashboard
      </button>
      <button class="mob-nav-item" id="mbn-orders" onclick="showPage('orders');setMobActive('orders')">
        <span class="mob-nav-icon">🧾</span>Orders
        <span class="mob-nav-badge" id="mob-nb-orders" style="display:none">0</span>
      </button>
      <button class="mob-nav-item" id="mbn-menu" onclick="showPage('menu');setMobActive('menu')">
        <span class="mob-nav-icon">🍽️</span>Menu
      </button>
      <button class="mob-nav-item" id="mbn-customers" onclick="showPage('customers');setMobActive('customers')">
        <span class="mob-nav-icon">👥</span>Customers
      </button>
      <button class="mob-nav-item" id="mbn-more" onclick="toggleSidebar()">
        <span class="mob-nav-icon">☰</span>More
      </button>
    </nav>

    <main class="page-content">

      <!-- ═══ DASHBOARD ═══ -->
      <div class="page active" id="page-dashboard">
        <div class="stats-grid" id="stats-grid">
          <div class="stat-card"><div class="stat-icon">👥</div><div class="stat-value" id="s-customers">—</div><div class="stat-label">Total Customers</div></div>
          <div class="stat-card highlight"><div class="stat-icon">💰</div><div class="stat-value" id="s-revenue">—</div><div class="stat-label">Total Revenue</div></div>
          <div class="stat-card"><div class="stat-icon">🧾</div><div class="stat-value" id="s-today">—</div><div class="stat-label">Today's Orders</div><div class="stat-sub" id="s-today-rev"></div></div>
          <div class="stat-card warn"><div class="stat-icon">⏳</div><div class="stat-value" id="s-pending">—</div><div class="stat-label">Pending Orders</div></div>
          <div class="stat-card"><div class="stat-icon">💎</div><div class="stat-value" id="s-points">—</div><div class="stat-label">Points Issued</div></div>
          <div class="stat-card"><div class="stat-icon">🎟️</div><div class="stat-value" id="s-coupons">—</div><div class="stat-label">Coupons Used</div></div>
          <div class="stat-card"><div class="stat-icon">🏆</div><div class="stat-value" id="s-top-item" style="font-size:14px;font-weight:700">—</div><div class="stat-label">Top Selling Item</div><div class="stat-sub" id="s-top-qty"></div></div>
          <div class="stat-card"><div class="stat-icon">🎂</div><div class="stat-value" id="s-bday">—</div><div class="stat-label">Birthdays Today</div></div>
        </div>

        <div class="grid-2" style="gap:20px;margin-bottom:24px">
          <div class="card">
            <div style="font-size:15px;font-weight:700;margin-bottom:4px">📈 Monthly Sales</div>
            <div style="font-size:12px;color:var(--text3);margin-bottom:8px">Revenue & orders (last 6 months)</div>
            <div class="chart-wrap"><canvas id="sales-chart"></canvas></div>
          </div>
          <div class="card">
            <div style="font-size:15px;font-weight:700;margin-bottom:12px">👤 New Customers</div>
            <div id="new-customers-list"></div>
          </div>
        </div>

        <div class="table-card">
          <div class="table-header"><h3>🧾 Recent Orders</h3><button class="btn btn-ghost btn-sm" onclick="showPage('orders')">View All</button></div>
          <div class="table-wrap">
            <table id="recent-orders-table">
              <thead><tr><th>Order#</th><th>Customer</th><th>Total</th><th>Table</th><th>Status</th><th>Time</th><th>Action</th></tr></thead>
              <tbody id="recent-orders-body"><tr class="empty-row"><td colspan="6">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ═══ ORDERS ═══ -->
      <div class="page" id="page-orders">
        <div class="section-header">
          <h2>🧾 Order Management</h2>
          <button class="btn btn-ghost btn-sm" onclick="exportOrdersCSV()">⬇️ Export CSV</button>
        </div>
        <div class="table-card">
          <div class="table-header">
            <div class="filter-bar">
              <div class="search-wrap"><span class="search-icon">🔍</span><input type="text" class="form-input search-input" id="order-search" placeholder="Search order / customer…" oninput="loadOrders(1)"></div>
              <select class="form-select" id="order-status-filter" onchange="loadOrders(1)" style="width:150px">
                <option value="">All Status</option>
                <option value="pending">Pending</option>
                <option value="confirmed">Confirmed</option>
                <option value="preparing">Preparing</option>
                <option value="ready">Ready</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
              </select>
              <select class="form-select" id="order-period-filter" onchange="loadOrders(1)" style="width:130px">
                <option value="">All Time</option>
                <option value="today">Today</option>
                <option value="week">This Week</option>
                <option value="month">This Month</option>
              </select>
              <select class="form-select" id="order-type-filter" onchange="loadOrders(1)" style="width:145px">
                <option value="">All Types</option>
                <option value="dine-in">🪑 Dine In</option>
                <option value="pickup">🥡 Pickup</option>
                <option value="home-delivery">🏠 Home Delivery</option>
              </select>
            </div>
          </div>
          <div class="table-wrap" id="orders-desktop-table">
            <table>
              <thead><tr><th>Order#</th><th>Customer</th><th>Mobile</th><th>Items</th><th>Type / Info</th><th>Total</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
              <tbody id="orders-body"><tr class="empty-row"><td colspan="9">Loading…</td></tr></tbody>
            </table>
          </div>
          <div id="orders-mobile-cards" style="padding:10px"></div>
          <div class="pagination" id="orders-pagination"></div>
        </div>
      </div>

      <!-- ═══ CUSTOMERS ═══ -->
      <div class="page" id="page-customers">
        <div class="section-header">
          <h2>👥 Customer Management</h2>
          <button class="btn btn-ghost btn-sm" onclick="showBirthdayCustomers()">🎂 Birthdays Today</button>
        </div>
        <div class="table-card">
          <div class="table-header">
            <div class="search-wrap"><span class="search-icon">🔍</span><input type="text" class="form-input search-input" id="cust-search" placeholder="Search name / mobile…" oninput="loadCustomers(1)"></div>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Customer</th><th>Mobile</th><th>Level</th><th>Points</th><th>Orders</th><th>Spent</th><th>Joined</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody id="customers-body"><tr class="empty-row"><td colspan="9">Loading…</td></tr></tbody>
            </table>
          </div>
          <div class="pagination" id="customers-pagination"></div>
        </div>
      </div>

      <!-- ═══ MENU ITEMS ═══ -->
      <div class="page" id="page-menu">
        <div class="section-header">
          <h2>🍽️ Menu Management</h2>
          <div style="display:flex;gap:8px">
            <button class="btn btn-ghost btn-sm" onclick="openImportModal()">📥 Import CSV</button>
            <button class="btn btn-primary btn-sm" onclick="openItemModal()">＋ Add Item</button>
          </div>
        </div>
        <div class="table-card">
          <div class="table-header">
            <div class="filter-bar">
              <div class="search-wrap"><span class="search-icon">🔍</span><input type="text" class="form-input search-input" id="item-search" placeholder="Search items…" oninput="loadMenuItems(1)"></div>
              <select class="form-select" id="item-cat-filter" onchange="loadMenuItems(1)" style="width:160px"><option value="">All Categories</option></select>
            </div>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Item</th><th>Category</th><th>Price</th><th>Available</th><th>Bestseller</th><th>Recommended</th><th>Actions</th></tr></thead>
              <tbody id="menu-body"><tr class="empty-row"><td colspan="7">Loading…</td></tr></tbody>
            </table>
          </div>
          <div class="pagination" id="menu-pagination"></div>
        </div>
      </div>

      <!-- ═══ CATEGORIES ═══ -->
      <div class="page" id="page-categories">
        <div class="section-header">
          <h2>📂 Menu Categories</h2>
          <button class="btn btn-primary btn-sm" onclick="openCatModal()">＋ Add Category</button>
        </div>
        <div class="table-card">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Icon</th><th>Name</th><th>Sort Order</th><th>Actions</th></tr></thead>
              <tbody id="cats-body"><tr class="empty-row"><td colspan="4">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ═══ COMBOS ═══ -->
      <div class="page" id="page-combos">
        <div class="section-header">
          <h2>🎁 Combo Offers</h2>
          <button class="btn btn-primary btn-sm" onclick="openComboModal()">＋ Add Combo</button>
        </div>
        <div class="table-card">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Name</th><th>Description</th><th>Original</th><th>Combo Price</th><th>Savings</th><th>Active</th><th>Actions</th></tr></thead>
              <tbody id="combos-body"><tr class="empty-row"><td colspan="7">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ═══ COUPONS ═══ -->
      <div class="page" id="page-coupons">
        <div class="section-header">
          <h2>🎟️ Coupon Management</h2>
          <button class="btn btn-primary btn-sm" onclick="openCouponModal()">＋ Create Coupon</button>
        </div>
        <div class="table-card">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Code</th><th>Type</th><th>Value</th><th>Min Order</th><th>Used / Max</th><th>Expires</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody id="coupons-body"><tr class="empty-row"><td colspan="8">Loading…</td></tr></tbody>
            </table>
          </div>
          <div class="pagination" id="coupons-pagination"></div>
        </div>
      </div>

      <!-- ═══ REWARDS ═══ -->
      <div class="page" id="page-rewards">
        <div class="section-header">
          <h2>⭐ Rewards Catalog</h2>
          <button class="btn btn-primary btn-sm" onclick="openRewardModal()">＋ Add Reward</button>
        </div>
        <div class="table-card">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Reward</th><th>Description</th><th>Points Needed</th><th>Type</th><th>Value</th><th>Active</th><th>Actions</th></tr></thead>
              <tbody id="rewards-body"><tr class="empty-row"><td colspan="7">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ═══ LOYALTY / POINTS ═══ -->
      <div class="page" id="page-loyalty">
        <div class="section-header">
          <h2>💎 Points History</h2>
        </div>
        <div class="table-card">
          <div class="table-header">
            <div class="search-wrap"><span class="search-icon">🔍</span><input type="text" class="form-input search-input" id="pts-search" placeholder="Search customer…" oninput="loadPointHistory(1)"></div>
          </div>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Customer</th><th>Mobile</th><th>Points</th><th>Type</th><th>Description</th><th>Date</th></tr></thead>
              <tbody id="pts-body"><tr class="empty-row"><td colspan="6">Loading…</td></tr></tbody>
            </table>
          </div>
          <div class="pagination" id="pts-pagination"></div>
        </div>
      </div>

      <!-- ═══ REPORTS ═══ -->
      <div class="page" id="page-reports">
        <div class="section-header"><h2>📈 Reports & Analytics</h2></div>
        <div class="card mb-4" style="margin-bottom:20px">
          <div class="filter-bar" style="align-items:flex-end">
            <div class="form-group">
              <label>Report Type</label>
              <select class="form-select" id="rpt-type" onchange="loadReport()" style="width:220px">
                <option value="sales">Daily Sales</option>
                <option value="top_items">Top Selling Items</option>
                <option value="top_customers_spend">Top Customers (Spend)</option>
                <option value="top_customers_points">Top Customers (Points)</option>
                <option value="coupons">Coupon Usage</option>
                <option value="repeat_customers">Repeat Customers</option>
              </select>
            </div>
            <div class="form-group" id="rpt-date-wrap">
              <label>From</label>
              <input type="date" class="form-input" id="rpt-from" style="width:150px">
            </div>
            <div class="form-group" id="rpt-date-wrap2">
              <label>To</label>
              <input type="date" class="form-input" id="rpt-to" style="width:150px">
            </div>
            <button class="btn btn-primary" onclick="loadReport()">Run Report</button>
            <button class="btn btn-ghost" onclick="exportReportCSV()">⬇️ Export CSV</button>
          </div>
        </div>
        <div class="table-card">
          <div class="table-header"><h3 id="rpt-title">Daily Sales Report</h3></div>
          <div class="table-wrap"><table><thead id="rpt-head"></thead><tbody id="rpt-body"><tr class="empty-row"><td colspan="6">Select a report type and run</td></tr></tbody></table></div>
        </div>
      </div>

      <!-- ═══ NOTIFICATIONS ═══ -->
      <div class="page" id="page-notifications">
        <div class="section-header"><h2>🔔 Notifications & Alerts</h2></div>
        <div id="notif-cards-area"></div>
      </div>

      <!-- ═══ STAFF ═══ -->
      <div class="page" id="page-staff">
        <div class="section-header">
          <h2>🔑 Staff & Roles</h2>
          <button class="btn btn-primary btn-sm" onclick="openStaffModal()">＋ Add Staff</button>
        </div>
        <div class="table-card">
          <div class="table-wrap">
            <table>
              <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Last Login</th><th>Status</th><th>Actions</th></tr></thead>
              <tbody id="staff-body"><tr class="empty-row"><td colspan="6">Loading…</td></tr></tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- ═══ SETTINGS ═══ -->
      <div class="page" id="page-settings">
        <div class="section-header"><h2>⚙️ Cafe Settings</h2></div>
        <div class="grid-2" style="gap:20px">
          <div class="card">
            <h3 style="margin-bottom:16px;font-size:15px">🏪 Cafe Information</h3>
            <div style="display:flex;flex-direction:column;gap:12px">
              <div class="form-group"><label>Cafe Name</label><input class="form-input" id="cfg-cafe_name" placeholder="C3 Restro"></div>
              <div class="form-group"><label>Logo URL</label><input class="form-input" id="cfg-cafe_logo" placeholder="https://..."></div>
              <div class="form-group"><label>Address</label><input class="form-input" id="cfg-cafe_address" placeholder="City, State"></div>
              <div class="form-group"><label>Opening Hours</label><input class="form-input" id="cfg-opening_hours" placeholder="8:00 AM - 10:00 PM"></div>
              <div class="form-group"><label>Footer Text</label><input class="form-input" id="cfg-footer_text" placeholder="Made with ☕"></div>
            </div>
          </div>
          <div class="card">
            <h3 style="margin-bottom:16px;font-size:15px">💳 Billing & Rewards</h3>
            <div style="display:flex;flex-direction:column;gap:12px">
              <div class="form-group"><label>GST %</label><input class="form-input" id="cfg-gst_percent" type="number" placeholder="5"></div>
              <div class="form-group"><label>Currency Symbol</label><input class="form-input" id="cfg-currency" placeholder="₹"></div>
              <div class="form-group"><label>WhatsApp Number</label><input class="form-input" id="cfg-whatsapp_number" placeholder="9876543210"></div>
              <div class="form-group"><label>Points per ₹ spent (e.g. 10 = 1pt per ₹10)</label><input class="form-input" id="cfg-points_per_rupee" type="number" placeholder="10"></div>
              <div class="form-group"><label>Menu Page URL</label><input class="form-input" id="cfg-menu_url" placeholder="cafe_panel.php"></div>
            </div>
          </div>
          <div class="card">
            <h3 style="margin-bottom:16px;font-size:15px">🏅 Membership Thresholds (Points)</h3>
            <div style="display:flex;flex-direction:column;gap:12px">
              <div class="form-group"><label>🥉 Bronze (min points)</label><input class="form-input" id="cfg-bronze_threshold" type="number" placeholder="0"></div>
              <div class="form-group"><label>🥈 Silver (min points)</label><input class="form-input" id="cfg-silver_threshold" type="number" placeholder="200"></div>
              <div class="form-group"><label>🥇 Gold (min points)</label><input class="form-input" id="cfg-gold_threshold" type="number" placeholder="500"></div>
            </div>
          </div>
        </div>
        <div style="margin-top:20px">
          <button class="btn btn-primary" onclick="saveSettings()">💾 Save All Settings</button>
        </div>
      </div>

      <!-- ═══ LOYALTY SETTINGS ═══ -->
      <div class="page" id="page-loyalty-settings">
        <div class="section-header"><h2>🪙 Loyalty Program Settings</h2></div>
        <div class="grid-2" style="gap:20px">
          <div class="card">
            <h3 style="margin-bottom:16px;font-size:15px">💰 Coin Economy</h3>
            <div style="display:flex;flex-direction:column;gap:12px">
              <div class="form-group"><label>Points earned per ₹1 spent</label><input class="form-input" id="cfg-earn_rate" type="number" step="0.1" placeholder="1"></div>
              <div class="form-group"><label>Points needed for ₹1 discount (redeem rate)</label><input class="form-input" id="cfg-redeem_rate" type="number" placeholder="40"></div>
              <div class="form-group"><label>Max redemption % of order value</label><input class="form-input" id="cfg-max_redeem_pct" type="number" placeholder="15"></div>
              <div style="background:var(--bg3);padding:12px;border-radius:8px;font-size:12px;color:var(--text3)">
                💡 Example: ₹500 order → earns 500 pts → max redeem = ₹75 (needs 3000 pts at 40 pts/₹)
              </div>
            </div>
          </div>
          <div class="card">
            <h3 style="margin-bottom:16px;font-size:15px">🎁 Bonus Points</h3>
            <div style="display:flex;flex-direction:column;gap:12px">
              <div class="form-group"><label>🎂 Birthday Bonus (points)</label><input class="form-input" id="cfg-birthday_bonus" type="number" placeholder="300"></div>
              <div class="form-group"><label>👥 Referral Bonus (points)</label><input class="form-input" id="cfg-referral_bonus" type="number" placeholder="150"></div>
              <div class="form-group"><label>🔑 Daily Login Bonus (points)</label><input class="form-input" id="cfg-daily_login_bonus" type="number" placeholder="10"></div>
            </div>
          </div>
          <div class="card">
            <h3 style="margin-bottom:16px;font-size:15px">🏆 Membership Thresholds (Points)</h3>
            <div style="display:flex;flex-direction:column;gap:12px">
              <div class="form-group"><label>🥉 Bronze (default - 0 pts)</label><input class="form-input" value="0" disabled style="opacity:0.5"></div>
              <div class="form-group"><label>🥈 Silver (min points)</label><input class="form-input" id="loy-silver_threshold" type="number" placeholder="2000"></div>
              <div class="form-group"><label>🥇 Gold (min points)</label><input class="form-input" id="loy-gold_threshold" type="number" placeholder="8000"></div>
              <div class="form-group"><label>💎 Platinum (min points)</label><input class="form-input" id="loy-platinum_threshold" type="number" placeholder="25000"></div>
              <div class="form-group"><label>🥈 Silver/Gold earning multiplier (e.g. 1.15)</label><input class="form-input" id="loy-silver_multiplier" type="number" step="0.1" placeholder="1.15"></div>
            </div>
          </div>
          <div class="card">
            <h3 style="margin-bottom:16px;font-size:15px">📋 Suggested Reward Catalog</h3>
            <div style="font-size:12px;color:var(--text3);line-height:2">
              ☕ Free Espresso → <strong>800 pts</strong><br>
              💸 ₹50 OFF → <strong>1,200 pts</strong><br>
              🍰 Free Dessert → <strong>2,000 pts</strong><br>
              ☕☕ Buy 1 Get 1 Coffee → <strong>3,500 pts</strong><br>
              💸 ₹200 OFF → <strong>6,000 pts</strong><br><br>
              <a onclick="showPage('rewards')" style="cursor:pointer;color:var(--p3);font-weight:600">→ Manage Rewards</a>
            </div>
          </div>
        </div>
        <div style="margin-top:20px;display:flex;gap:12px">
          <button class="btn btn-primary" onclick="saveLoyaltySettings()">💾 Save Loyalty Settings</button>
          <button class="btn btn-ghost" onclick="showPage('loyalty-analytics')">📊 View Loyalty Analytics</button>
        </div>
      </div>

      <!-- ═══ LOYALTY ANALYTICS ═══ -->
      <div class="page" id="page-loyalty-analytics">
        <div class="section-header"><h2>📊 Loyalty Analytics</h2><button class="btn btn-ghost btn-sm" onclick="loadLoyaltyAnalytics()">🔄 Refresh</button></div>
        <div class="stats-grid" style="margin-bottom:24px">
          <div class="stat-card"><div class="stat-icon">🪙</div><div class="stat-value" id="la-issued">—</div><div class="stat-label">Total Coins Issued</div></div>
          <div class="stat-card highlight"><div class="stat-icon">💸</div><div class="stat-value" id="la-redeemed">—</div><div class="stat-label">Total Coins Redeemed</div></div>
          <div class="stat-card"><div class="stat-icon">🔥</div><div class="stat-value" id="la-active">—</div><div class="stat-label">Active Loyalty Members</div></div>
          <div class="stat-card warn"><div class="stat-icon">🏆</div><div class="stat-value" id="la-top-reward" style="font-size:14px;font-weight:700">—</div><div class="stat-label">Top Redeemed Reward</div></div>
        </div>
        <div class="grid-2" style="gap:20px;margin-bottom:20px">
          <div class="table-card">
            <div class="table-header"><h3>🏆 Top Loyalty Customers</h3></div>
            <div class="table-wrap">
              <table><thead><tr><th>#</th><th>Customer</th><th>Level</th><th>Points</th></tr></thead>
              <tbody id="la-top-body"><tr class="empty-row"><td colspan="4">Loading…</td></tr></tbody></table>
            </div>
          </div>
          <div class="table-card">
            <div class="table-header"><h3>🏅 Membership Distribution</h3></div>
            <div class="table-wrap">
              <table><thead><tr><th>Level</th><th>Members</th></tr></thead>
              <tbody id="la-dist-body"><tr class="empty-row"><td colspan="2">Loading…</td></tr></tbody></table>
            </div>
          </div>
        </div>
        <div class="table-card">
          <div class="table-header"><h3>🕐 Recent Point Transactions</h3></div>
          <div class="table-wrap">
            <table><thead><tr><th>Customer</th><th>Points</th><th>Type</th><th>Description</th><th>Date</th></tr></thead>
            <tbody id="la-txn-body"><tr class="empty-row"><td colspan="5">Loading…</td></tr></tbody></table>
          </div>
        </div>
      </div>

      <!-- ═══ TOOLS ═══ -->      <div class="page" id="page-tools">
        <div class="section-header"><h2>🛠️ Admin Tools</h2></div>
        <div class="grid-3" style="gap:20px">
          <div class="card" style="text-align:center">
            <div style="font-size:36px;margin-bottom:8px">💾</div>
            <h3 style="font-size:15px;margin-bottom:6px">Database Backup</h3>
            <p style="color:var(--text3);font-size:12px;margin-bottom:16px">Export full SQL dump of all cafe data</p>
            <button class="btn btn-primary btn-full" onclick="backupSQL()">Download SQL Backup</button>
          </div>
          <div class="card" style="text-align:center">
            <div style="font-size:36px;margin-bottom:8px">📱</div>
            <h3 style="font-size:15px;margin-bottom:6px">QR Code Generator</h3>
            <p style="color:var(--text3);font-size:12px;margin-bottom:16px">Generate QR code for your menu page</p>
            <button class="btn btn-primary btn-full" onclick="showQRCode()">Generate QR Code</button>
          </div>
          <div class="card" style="text-align:center">
            <div style="font-size:36px;margin-bottom:8px">🗑️</div>
            <h3 style="font-size:15px;margin-bottom:6px">Reset Data</h3>
            <p style="color:var(--text3);font-size:12px;margin-bottom:16px">Selectively clear menu, coupons, orders, points &amp; more</p>
            <button class="btn btn-danger btn-full" onclick="openResetDataModal()">Reset Data…</button>
          </div>
        </div>
        <div class="card mt-4">
          <h3 style="font-size:15px;margin-bottom:12px">🔐 Change Admin Password</h3>
          <div class="form-grid">
            <div class="form-group"><label>Current Password</label><input type="password" class="form-input" id="cp-current"></div>
            <div class="form-group"><label>New Password</label><input type="password" class="form-input" id="cp-new"></div>
          </div>
          <button class="btn btn-primary mt-4" onclick="changeAdminPassword()">Update Password</button>
        </div>
      </div>

      <!-- ═══ PROFILE ═══ -->
      <div class="page" id="page-profile">
        <div class="section-header"><h2>👤 Admin Profile</h2></div>
        <div class="card" style="max-width:480px">
          <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px">
            <div style="width:64px;height:64px;border-radius:16px;background:linear-gradient(135deg,var(--p2),var(--p4));display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;font-size:22px"><?= strtoupper(substr($admin['full_name'] ?? 'A', 0, 2)) ?></div>
            <div>
              <div style="font-size:18px;font-weight:800"><?= htmlspecialchars($admin['full_name'] ?? '') ?></div>
              <div style="color:var(--text3);font-size:13px">@<?= htmlspecialchars($admin['username'] ?? '') ?> · <?= ucfirst($admin['role']) ?></div>
            </div>
          </div>
          <div style="background:var(--bg3);border-radius:10px;padding:14px">
            <div style="font-size:12px;color:var(--text3)">Last Login</div>
            <div style="font-size:14px;font-weight:600"><?= $admin['last_login'] ?? 'N/A' ?></div>
          </div>
        </div>
      </div>

    </main><!-- .page-content -->
  </div><!-- .main-content -->
</div><!-- .admin-layout -->
<?php endif; ?>

<!-- ══════════════════ MODALS ══════════════════ -->
<div class="modal-overlay" id="modal-overlay" onclick="closeModal(event)" style="display:none">
  <div class="modal modal-lg" id="main-modal" onclick="event.stopPropagation()">
    <div class="modal-header">
      <h3 id="modal-title">Modal</h3>
      <div class="modal-close" onclick="closeModal()">✕</div>
    </div>
    <div class="modal-body" id="modal-body"></div>
    <div class="modal-footer" id="modal-footer" style="display:none"></div>
  </div>
</div>

<!-- CDN: Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<!-- CDN: QRCode -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

<script>
// ╔══════════════════════════════════════════════════════╗
// ║                 ADMIN PANEL JS                       ║
// ╚══════════════════════════════════════════════════════╝

let salesChart = null;
let currentPage = 'dashboard';
let reportData = [];

// ─── API HELPER ───
async function api(action, data = null, method = 'POST', params = {}) {
  try {
    let url = `?api=${action}`;
    Object.entries(params).forEach(([k,v]) => { if(v !== '' && v !== null && v !== undefined) url += `&${k}=${encodeURIComponent(v)}`; });
    const opts = { method, headers: { 'Content-Type': 'application/json' } };
    if (data) opts.body = JSON.stringify(data);
    const r = await fetch(url, opts);
    return await r.json();
  } catch(e) { toast('Network error', 'error'); return {}; }
}

// ─── TOAST ───
function toast(msg, type = 'info') {
  const icons = { success:'✅', error:'❌', warning:'⚠️', info:'ℹ️' };
  const tc = document.getElementById('toast-container');
  const t = document.createElement('div');
  t.className = `toast ${type}`;
  t.innerHTML = `<span>${icons[type]||'ℹ️'}</span><span>${msg}</span>`;
  tc.appendChild(t);
  setTimeout(() => { t.style.animation = 'toastOut 0.3s ease forwards'; setTimeout(() => t.remove(), 300); }, 3500);
}

// ─── MODAL ───
function openModal(title, bodyHTML, footerHTML = '', large = false) {
  document.getElementById('modal-title').textContent = title;
  document.getElementById('modal-body').innerHTML = bodyHTML;
  const footer = document.getElementById('modal-footer');
  if (footerHTML) { footer.innerHTML = footerHTML; footer.style.display = 'flex'; }
  else footer.style.display = 'none';
  const modal = document.getElementById('main-modal');
  modal.classList.toggle('modal-lg', large);
  document.getElementById('modal-overlay').style.display = 'flex';
}
function closeModal(e) {
  if (!e || e.target === document.getElementById('modal-overlay')) {
    document.getElementById('modal-overlay').style.display = 'none';
  }
}

// ─── SIDEBAR ───
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('mobile-overlay').classList.toggle('visible');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('mobile-overlay').classList.remove('visible');
}

// ─── NAVIGATION ───
const pageTitles = {
  dashboard: ['Dashboard', 'Overview & live stats'],
  orders: ['Orders', 'Manage & track orders'],
  customers: ['Customers', 'Customer profiles & loyalty'],
  menu: ['Menu Items', 'Add, edit & manage menu'],
  categories: ['Categories', 'Menu category management'],
  combos: ['Combo Offers', 'Bundle deals management'],
  coupons: ['Coupons', 'Discount codes & offers'],
  rewards: ['Rewards', 'Loyalty rewards catalog'],
  loyalty: ['Points History', 'Point transactions log'],
  'loyalty-analytics': ['Loyalty Analytics', 'Coin economy insights'],
  'loyalty-settings': ['Loyalty Settings', 'Configure coin economy & tiers'],
  reports: ['Reports', 'Analytics & insights'],
  notifications: ['Notifications', 'Alerts & reminders'],
  staff: ['Staff & Roles', 'Sub-admin management'],
  settings: ['Settings', 'Cafe configuration'],
  tools: ['Admin Tools', 'Utilities & backup'],
  profile: ['My Profile', 'Admin account info'],
};

// Smart page-loaded cache — prevents duplicate API calls on revisit
window.pageLoaded = {};

const pageLoaders = {
  dashboard: () => loadDashboard(),
  orders: () => loadOrders(1),
  customers: () => loadCustomers(1),
  menu: () => { loadMenuItems(1); loadCategoriesForFilter(); },
  categories: () => loadCategories(),
  combos: () => loadCombos(),
  coupons: () => loadCoupons(1),
  rewards: () => loadRewards(),
  loyalty: () => loadPointHistory(1),
  'loyalty-analytics': () => loadLoyaltyAnalytics(),
  'loyalty-settings': () => loadLoyaltySettingsPage(),
  reports: () => initReports(),
  notifications: () => loadNotifPage(),
  staff: () => loadStaff(),
  settings: () => loadSettings(),
};

function showPage(page) {
  // Hide all pages
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  // Clear all nav active states
  document.querySelectorAll('.nav-item').forEach(i => i.classList.remove('active'));

  // Show target page
  const el = document.getElementById('page-' + page);
  if (!el) {
    console.warn('[showPage] Page element not found: page-' + page);
    return;
  }
  el.classList.add('active');
  currentPage = page;

  // Update page title
  const titles = pageTitles[page] || [page, ''];
  const titleEl = document.getElementById('page-title');
  const subtitleEl = document.getElementById('page-subtitle');
  if (titleEl) titleEl.textContent = titles[0];
  if (subtitleEl) subtitleEl.textContent = titles[1];

  // Highlight active nav item
  document.querySelectorAll('.nav-item').forEach(item => {
    const oc = item.getAttribute('onclick') || '';
    if (oc.includes(`'${page}'`)) item.classList.add('active');
  });

  closeSidebar();

  // Fire the loader
  const loader = pageLoaders[page];
  if (loader) {
    console.log('[showPage] Firing loader for:', page);
    // Use Promise.resolve to handle both sync and async loaders
    Promise.resolve().then(() => loader()).catch(e => {
      console.error('[showPage] Loader error for ' + page + ':', e);
      toast('Failed to load ' + (titles[0] || page), 'error');
    });
  } else {
    console.log('[showPage] No loader for:', page);
  }
}

function setMobActive(page) {
  document.querySelectorAll('.mob-nav-item').forEach(b => b.classList.remove('active'));
  const btn = document.getElementById('mbn-' + page);
  if (btn) btn.classList.add('active');
}

// Update mobile badge for orders
function updateMobOrderBadge(count) {
  const el = document.getElementById('mob-nb-orders');
  if (!el) return;
  if (count > 0) { el.textContent = count; el.style.display = 'inline-block'; }
  else el.style.display = 'none';
}

// ─── AUTH ───
async function doAdminLogin() {
  const u = document.getElementById('l-user').value.trim();
  const p = document.getElementById('l-pass').value;
  if (!u || !p) { toast('Enter username and password', 'warning'); return; }
  const btn = document.getElementById('login-btn');
  btn.textContent = 'Signing in…'; btn.disabled = true;
  const res = await api('admin_login', { username: u, password: p });
  if (res.success) { toast('Welcome back!', 'success'); setTimeout(() => location.reload(), 500); }
  else { toast(res.message || 'Invalid credentials', 'error'); btn.textContent = '🔐 Sign In to Admin Panel'; btn.disabled = false; }
}
async function doAdminLogout() {
  if (!confirm('Logout from admin panel?')) return;
  await api('admin_logout', {});
  location.reload();
}
function togglePass() {
  const i = document.getElementById('l-pass');
  i.type = i.type === 'password' ? 'text' : 'password';
  document.getElementById('pass-eye').textContent = i.type === 'password' ? '👁' : '🙈';
}
// Login enter-key handler (safe — only runs when element exists)
document.addEventListener('keydown', e => {
  if (e.key === 'Enter' && document.getElementById('login-btn')) doAdminLogin();
});

// ─── ORDER ARRIVAL SOUND SYSTEM (Background-capable) ───
// Strategy:
//   1. Web Notifications fire even when tab is backgrounded/hidden → always visible
//   2. Service Worker polls the API every 20s independently of the page tab state
//   3. When the page IS visible, AudioContext plays the beep directly
//   4. When the page is hidden (background tab), the SW sends a push-style
//      message; on visibility restore the queued sound fires immediately
//   5. <audio> element fallback for browsers that block AudioContext in BG

let _orderSoundCtx = null;
let _lastKnownPendingCount = -1;
let _orderSoundEnabled = true;
let _pendingSoundQueue = 0;   // sounds queued while page was hidden
let _swRegistration = null;

// ── Base-64 encoded short WAV beep (generated inline — no external file needed) ──
// 3 beeps at 880Hz, 16-bit PCM, 22050Hz mono, ~1.3s
function _buildBeepWav() {
  const sampleRate = 22050;
  const duration = 1.4; // seconds
  const numSamples = Math.floor(sampleRate * duration);
  const buffer = new ArrayBuffer(44 + numSamples * 2);
  const view = new DataView(buffer);
  // RIFF header
  const writeStr = (off, s) => { for (let i = 0; i < s.length; i++) view.setUint8(off + i, s.charCodeAt(i)); };
  writeStr(0, 'RIFF');
  view.setUint32(4, 36 + numSamples * 2, true);
  writeStr(8, 'WAVE');
  writeStr(12, 'fmt ');
  view.setUint32(16, 16, true);
  view.setUint16(20, 1, true);         // PCM
  view.setUint16(22, 1, true);         // mono
  view.setUint32(24, sampleRate, true);
  view.setUint32(28, sampleRate * 2, true);
  view.setUint16(32, 2, true);
  view.setUint16(34, 16, true);
  writeStr(36, 'data');
  view.setUint32(40, numSamples * 2, true);
  // PCM samples: 3 beeps at offsets 0, 0.3, 0.6s, then rising chime at 0.9s
  const beeps = [[0, 880], [0.28, 1100], [0.56, 880]];
  for (let i = 0; i < numSamples; i++) {
    const t = i / sampleRate;
    let s = 0;
    beeps.forEach(([start, freq]) => {
      const end = start + 0.18;
      if (t >= start && t < end) {
        const env = Math.sin(Math.PI * (t - start) / 0.18); // envelope
        s += 0.45 * env * Math.sin(2 * Math.PI * freq * t);
        s += 0.2  * env * Math.sin(2 * Math.PI * freq * 2 * t);
      }
    });
    // Rising chime 0.9–1.3s
    if (t >= 0.9 && t < 1.35) {
      const p = (t - 0.9) / 0.45;
      const freq = 660 + p * 660;
      const env = p < 0.1 ? p / 0.1 : (1 - (p - 0.1) / 0.9);
      s += 0.55 * env * Math.sin(2 * Math.PI * freq * t);
    }
    view.setInt16(44 + i * 2, Math.max(-32768, Math.min(32767, s * 32767)), true);
  }
  // Convert to base64
  let bin = '';
  const bytes = new Uint8Array(buffer);
  for (let i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
  return 'data:audio/wav;base64,' + btoa(bin);
}

let _beepWavUrl = null;
function getBeepWavUrl() {
  if (!_beepWavUrl) _beepWavUrl = _buildBeepWav();
  return _beepWavUrl;
}

function getAudioCtx() {
  if (!_orderSoundCtx) {
    _orderSoundCtx = new (window.AudioContext || window.webkitAudioContext)();
  }
  if (_orderSoundCtx.state === 'suspended') _orderSoundCtx.resume();
  return _orderSoundCtx;
}

// Play via AudioContext (foreground) OR <audio> element (more reliable in some browsers)
function playOrderAlertSound() {
  if (!_orderSoundEnabled) return;

  // Always try <audio> element first — it works in background on most browsers
  try {
    const audio = new Audio(getBeepWavUrl());
    audio.volume = 1.0;
    const playPromise = audio.play();
    if (playPromise) {
      playPromise.catch(() => {
        // Autoplay blocked — fall back to AudioContext
        _playViaAudioContext();
      });
    }
  } catch(e) {
    _playViaAudioContext();
  }

  // Vibrate on mobile
  if (navigator.vibrate) navigator.vibrate([200, 100, 200, 100, 400]);
}

function _playViaAudioContext() {
  try {
    const ctx = getAudioCtx();
    const masterGain = ctx.createGain();
    masterGain.gain.value = 1.0;
    masterGain.connect(ctx.destination);
    const beepPattern = [0, 0.28, 0.56];
    beepPattern.forEach(startOffset => {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.connect(gain); gain.connect(masterGain);
      osc.type = 'square';
      osc.frequency.setValueAtTime(880, ctx.currentTime + startOffset);
      osc.frequency.setValueAtTime(1100, ctx.currentTime + startOffset + 0.05);
      osc.frequency.setValueAtTime(880, ctx.currentTime + startOffset + 0.10);
      gain.gain.setValueAtTime(0, ctx.currentTime + startOffset);
      gain.gain.linearRampToValueAtTime(0.55, ctx.currentTime + startOffset + 0.01);
      gain.gain.linearRampToValueAtTime(0, ctx.currentTime + startOffset + 0.18);
      osc.start(ctx.currentTime + startOffset);
      osc.stop(ctx.currentTime + startOffset + 0.19);
      const osc2 = ctx.createOscillator();
      const gain2 = ctx.createGain();
      osc2.connect(gain2); gain2.connect(masterGain);
      osc2.type = 'sine';
      osc2.frequency.setValueAtTime(1760, ctx.currentTime + startOffset);
      gain2.gain.setValueAtTime(0, ctx.currentTime + startOffset);
      gain2.gain.linearRampToValueAtTime(0.25, ctx.currentTime + startOffset + 0.01);
      gain2.gain.linearRampToValueAtTime(0, ctx.currentTime + startOffset + 0.18);
      osc2.start(ctx.currentTime + startOffset);
      osc2.stop(ctx.currentTime + startOffset + 0.19);
    });
    const chime = ctx.createOscillator();
    const chimeGain = ctx.createGain();
    chime.connect(chimeGain); chimeGain.connect(masterGain);
    chime.type = 'sine';
    chime.frequency.setValueAtTime(660, ctx.currentTime + 0.9);
    chime.frequency.linearRampToValueAtTime(1320, ctx.currentTime + 1.3);
    chimeGain.gain.setValueAtTime(0, ctx.currentTime + 0.9);
    chimeGain.gain.linearRampToValueAtTime(0.65, ctx.currentTime + 0.92);
    chimeGain.gain.linearRampToValueAtTime(0, ctx.currentTime + 1.35);
    chime.start(ctx.currentTime + 0.9);
    chime.stop(ctx.currentTime + 1.36);
  } catch(e) { console.warn('[OrderSound] AudioContext failed:', e); }
}

// ── Web Notification for background alerts ──
async function requestNotificationPermission() {
  if (!('Notification' in window)) return false;
  if (Notification.permission === 'granted') return true;
  if (Notification.permission === 'denied') return false;
  const perm = await Notification.requestPermission();
  return perm === 'granted';
}

function showOrderNotification(count) {
  if (!('Notification' in window) || Notification.permission !== 'granted') return;
  const n = new Notification('🔔 New Order' + (count > 1 ? 's' : '') + ' — C3 Restro', {
    body: `${count} new order${count > 1 ? 's' : ''} waiting! Tap to open admin panel.`,
    icon: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="%2316a34a"/><text y="46" x="10" font-size="40">☕</text></svg>',
    badge: 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="%2316a34a"/><text y="46" x="10" font-size="40">☕</text></svg>',
    tag: 'c3-new-order',       // replaces previous notification instead of stacking
    renotify: true,             // re-alert even if same tag
    requireInteraction: true,   // stays until dismissed
    silent: false,
    vibrate: [200, 100, 200],
  });
  n.onclick = () => { window.focus(); n.close(); };
}

// ── Service Worker for background polling ──
const _SW_SCRIPT = `
const POLL_INTERVAL = 20000; // 20s
const API_URL = '${location.href.split('?')[0]}?api=get_notifications';
let _lastCount = -1;
let _pollTimer = null;

function startPolling() {
  if (_pollTimer) return;
  _pollTimer = setInterval(doPoll, POLL_INTERVAL);
  doPoll();
}

async function doPoll() {
  try {
    const res = await fetch(API_URL, { credentials: 'include' });
    if (!res.ok) return;
    const data = await res.json();
    const pending = data.notifications?.find(n => n.msg?.includes('pending'));
    const count = pending ? (parseInt(pending.msg.split(' ')[0]) || 0) : 0;
    if (_lastCount === -1) { _lastCount = count; return; }
    if (count > _lastCount) {
      const newCount = count - _lastCount;
      // Notify the page client (plays sound if tab is visible)
      self.clients.matchAll({ includeUncontrolled: true }).then(clients => {
        clients.forEach(c => c.postMessage({ type: 'NEW_ORDER', count: newCount }));
      });
      // Show OS notification (works even if tab is background or phone is locked)
      self.registration.showNotification('🔔 New Order' + (newCount > 1 ? 's' : '') + ' — C3 Restro', {
        body: newCount + ' new order' + (newCount > 1 ? 's' : '') + ' waiting! Tap to open.',
        icon: 'data:image/svg+xml,<svg xmlns=\\'http://www.w3.org/2000/svg\\' viewBox=\\'0 0 64 64\\'><rect width=\\'64\\' height=\\'64\\' rx=\\'14\\' fill=\\'%2316a34a\\'/><text y=\\'46\\' x=\\'10\\' font-size=\\'40\\'>☕</text></svg>',
        tag: 'c3-new-order',
        renotify: true,
        requireInteraction: true,
        vibrate: [200, 100, 200, 100, 400],
        data: { url: self.registration.scope }
      });
    }
    _lastCount = count;
  } catch(e) {}
}

self.addEventListener('install', () => { self.skipWaiting(); startPolling(); });
self.addEventListener('activate', e => { e.waitUntil(self.clients.claim()); startPolling(); });
self.addEventListener('message', msg => {
  if (msg.data?.type === 'SET_BASELINE') { _lastCount = msg.data.count; }
  if (msg.data?.type === 'START_POLLING') startPolling();
});
self.addEventListener('notificationclick', e => {
  e.notification.close();
  e.waitUntil(self.clients.matchAll({ type: 'window' }).then(clients => {
    const c = clients.find(c => c.url.includes(self.registration.scope));
    if (c) { c.focus(); } else { self.clients.openWindow(e.notification.data?.url || self.registration.scope); }
  }));
});
`;

async function registerOrderServiceWorker() {
  if (!('serviceWorker' in navigator)) {
    console.warn('[SW] Service Workers not supported — background polling unavailable');
    return;
  }
  try {
    // Inline SW via Blob URL (no separate file needed)
    const blob = new Blob([_SW_SCRIPT], { type: 'application/javascript' });
    const swUrl = URL.createObjectURL(blob);

    // NOTE: Blob-URL SWs are origin-scoped. We register with a path scope.
    // Some browsers restrict Blob SW to scope of about:blank — fallback to data: URI trick.
    let reg;
    try {
      reg = await navigator.serviceWorker.register(swUrl, { scope: './' });
    } catch(e) {
      // Fallback: try registering as a same-path SW if blob is blocked
      console.warn('[SW] Blob SW failed, trying inline script approach:', e.message);
      return;
    }
    _swRegistration = reg;
    console.log('[SW] Registered:', reg.scope);

    // Listen for messages from SW
    navigator.serviceWorker.addEventListener('message', e => {
      if (e.data?.type === 'NEW_ORDER') {
        const count = e.data.count || 1;
        if (document.hidden) {
          _pendingSoundQueue += count;
        } else {
          playOrderAlertSound();
          showNewOrderFlash(count);
        }
        showOrderNotification(count);
        // Sync the page-side counter
        _lastKnownPendingCount += count;
      }
    });

    // Relay baseline to SW once notifications loaded
    navigator.serviceWorker.ready.then(sw => {
      if (_lastKnownPendingCount >= 0) {
        sw.active?.postMessage({ type: 'SET_BASELINE', count: _lastKnownPendingCount });
      }
      sw.active?.postMessage({ type: 'START_POLLING' });
    });
  } catch(e) {
    console.warn('[SW] Registration failed:', e);
  }
}

// ── Play queued sounds when user returns to tab ──
document.addEventListener('visibilitychange', () => {
  if (!document.hidden && _pendingSoundQueue > 0) {
    playOrderAlertSound();
    showNewOrderFlash(_pendingSoundQueue);
    _pendingSoundQueue = 0;
  }
  // Resume audio context if it was suspended
  if (!document.hidden && _orderSoundCtx?.state === 'suspended') {
    _orderSoundCtx.resume();
  }
});

function showNewOrderFlash(count) {
  let flash = document.getElementById('new-order-flash');
  if (!flash) {
    flash = document.createElement('div');
    flash.id = 'new-order-flash';
    flash.style.cssText = `
      position:fixed;top:70px;right:20px;z-index:99999;
      background:linear-gradient(135deg,#16a34a,#059669);
      color:#fff;font-weight:800;font-size:16px;
      padding:14px 22px;border-radius:14px;
      box-shadow:0 6px 32px rgba(22,163,74,0.55);
      display:flex;align-items:center;gap:10px;
      transform:translateX(120%);transition:transform 0.35s cubic-bezier(.34,1.56,.64,1);
      cursor:pointer;user-select:none;
    `;
    flash.onclick = () => { flash.style.transform = 'translateX(120%)'; showPage('orders'); };
    document.body.appendChild(flash);
  }
  flash.innerHTML = `<span style="font-size:24px;animation:ring 0.5s ease infinite alternate">🔔</span>
    <span>${count} NEW ORDER${count>1?'S':''}!<br><span style="font-size:12px;font-weight:400;opacity:0.9">Click to view orders</span></span>`;
  // Inject ring animation if not present
  if (!document.getElementById('ring-keyframes')) {
    const st = document.createElement('style');
    st.id = 'ring-keyframes';
    st.textContent = `@keyframes ring{from{transform:rotate(-15deg)}to{transform:rotate(15deg)}}`;
    document.head.appendChild(st);
  }
  flash.style.transform = 'translateX(0)';
  clearTimeout(flash._hideTimer);
  flash._hideTimer = setTimeout(() => { flash.style.transform = 'translateX(120%)'; }, 8000);
}

function toggleOrderSound() {
  _orderSoundEnabled = !_orderSoundEnabled;
  const icon = document.getElementById('sound-icon');
  const label = document.querySelector('#order-sound-toggle .btn-label');
  if (icon) icon.textContent = _orderSoundEnabled ? '🔔' : '🔕';
  if (label) label.textContent = _orderSoundEnabled ? ' Sound ON' : ' Sound OFF';
  toast(_orderSoundEnabled ? 'Order alert sound ON 🔔' : 'Order alert sound OFF 🔕', _orderSoundEnabled ? 'success' : 'warning');
  if (_orderSoundEnabled) {
    try { getAudioCtx(); } catch(e) {}
    // Re-request notification permission if needed
    requestNotificationPermission();
  }
}

// ─── NOTIFICATIONS ───
async function loadNotifications() {
  try {
    const res = await api('get_notifications', null, 'GET');
    const nb = document.getElementById('nb-notif');
    const dot = document.getElementById('notif-dot');
    const list = document.getElementById('notif-list');
    if (res.count > 0) {
      if (nb) { nb.textContent = res.count; nb.style.display = 'inline-block'; }
      if (dot) dot.style.display = 'block';
      const pending = res.notifications?.find(n => n.msg?.includes('pending'));
      if (pending) {
        const nb2 = document.getElementById('nb-orders');
        if (nb2) { nb2.textContent = pending.msg.split(' ')[0]; nb2.style.display = 'inline-block'; }
        updateMobOrderBadge(parseInt(pending.msg.split(' ')[0]) || 0);

        // ── New order sound alert ──
        const currentPending = parseInt(pending.msg.split(' ')[0]) || 0;
        if (_lastKnownPendingCount === -1) {
          // First load — set baseline silently, relay to SW
          _lastKnownPendingCount = currentPending;
          navigator.serviceWorker?.ready.then(sw => {
            sw.active?.postMessage({ type: 'SET_BASELINE', count: currentPending });
          }).catch(() => {});
        } else if (currentPending > _lastKnownPendingCount) {
          const newCount = currentPending - _lastKnownPendingCount;
          playOrderAlertSound();
          showNewOrderFlash(newCount);
          showOrderNotification(newCount);
          _lastKnownPendingCount = currentPending;
        } else {
          _lastKnownPendingCount = currentPending;
        }
      } else {
        // No pending orders notification — reset counter
        if (_lastKnownPendingCount === -1) _lastKnownPendingCount = 0;
        else _lastKnownPendingCount = 0;
      }
    } else {
      if (_lastKnownPendingCount === -1) _lastKnownPendingCount = 0;
      else _lastKnownPendingCount = 0;
    }
    if (list) {
      list.innerHTML = res.notifications?.length ? res.notifications.map(n =>
        `<div class="notif-item"><span style="font-size:20px">${n.icon}</span><span>${n.msg}</span></div>`
      ).join('') : '<div style="padding:20px;text-align:center;color:var(--text3);font-size:12px">No new alerts</div>';
    }
  } catch(e) { console.error('[loadNotifications]', e); }
}
function toggleNotifPanel() {
  document.getElementById('notif-panel').classList.toggle('open');
  loadNotifications();
}
document.addEventListener('click', e => {
  if (!e.target.closest('#notif-btn')) document.getElementById('notif-panel')?.classList.remove('open');
});

async function loadNotifPage() {
  const res = await api('get_notifications', null, 'GET');
  const area = document.getElementById('notif-cards-area');
  if (!res.notifications?.length) { area.innerHTML = '<div class="card" style="text-align:center;padding:40px;color:var(--text3)">🎉 No alerts right now! Everything looks great.</div>'; return; }
  const typeColors = { warning: 'warn', danger: 'danger', success: 'success', info: 'info' };
  area.innerHTML = res.notifications.map(n => `
    <div class="card" style="display:flex;align-items:center;gap:14px;margin-bottom:12px;border-left:4px solid var(--p3)">
      <span style="font-size:28px">${n.icon}</span>
      <div><div style="font-size:14px;font-weight:600">${n.msg}</div></div>
    </div>`).join('');
  // Birthday list
  const bday = res.notifications.find(n => n.msg?.includes('birthday'));
  if (bday) {
    const bc = await api('birthday_customers', null, 'GET');
    if (bc.customers?.length) {
      area.innerHTML += `<div class="table-card"><div class="table-header"><h3>🎂 Birthday Customers Today</h3></div><div class="table-wrap"><table><thead><tr><th>Name</th><th>Mobile</th><th>Level</th><th>Points</th></tr></thead><tbody>${bc.customers.map(c=>`<tr><td>${esc(c.full_name)}</td><td>${esc(c.mobile)}</td><td>${memberBadge(c.membership_level)}</td><td>⭐ ${c.points}</td></tr>`).join('')}</tbody></table></div></div>`;
    }
  }
}

// ─── DASHBOARD ───
async function loadDashboard() {
  const res = await api('dashboard_stats', null, 'GET');
  if (!res || res.error) return;
  document.getElementById('s-customers').textContent = res.total_customers || 0;
  document.getElementById('s-revenue').textContent = '₹' + Number(res.total_revenue || 0).toLocaleString('en-IN', {minimumFractionDigits:0});
  document.getElementById('s-today').textContent = res.today_orders || 0;
  document.getElementById('s-today-rev').textContent = '₹' + Number(res.today_revenue || 0).toLocaleString('en-IN', {minimumFractionDigits:0}) + ' today';
  document.getElementById('s-pending').textContent = res.pending_orders || 0;
  document.getElementById('s-points').textContent = Number(res.points_issued || 0).toLocaleString('en-IN');
  document.getElementById('s-coupons').textContent = res.coupons_used || 0;
  document.getElementById('s-top-item').textContent = res.top_item || 'N/A';
  document.getElementById('s-top-qty').textContent = `${res.top_item_qty || 0} sold`;
  document.getElementById('s-bday').textContent = res.birthdays_today || 0;

  // Monthly chart
  if (res.monthly_sales) drawSalesChart(res.monthly_sales);

  // New customers
  const nc = res.new_customers || [];
  document.getElementById('new-customers-list').innerHTML = nc.length ? nc.map(c => `
    <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)">
      <div class="avatar-initials" style="width:32px;height:32px;font-size:11px;border-radius:8px">${esc(c.full_name.substring(0,2).toUpperCase())}</div>
      <div style="flex:1"><div style="font-size:13px;font-weight:600">${esc(c.full_name)}</div><div style="font-size:11px;color:var(--text3)">${esc(c.mobile)}</div></div>
      <div>${memberBadge(c.membership_level)}</div>
    </div>`).join('') : '<p style="color:var(--text3);font-size:13px">No customers yet</p>';

  // Recent orders
  const orders = res.recent_orders || [];
  document.getElementById('recent-orders-body').innerHTML = orders.length ? orders.map(o => {
    const tableNum = extractTableNumber(o.notes);
    const deliveryAddr = extractDeliveryAddress(o.notes);
    const typeInfo = o.order_type === 'home-delivery'
      ? `${orderTypeBadge(o.order_type)}`
      : (tableNum ? `<span class="table-num-badge">🪑 ${esc(tableNum)}</span>` : orderTypeBadge(o.order_type || 'dine-in'));
    return `<tr>
      <td><span style="font-family:var(--mono);font-size:12px;font-weight:600">${esc(o.order_number)}</span></td>
      <td>${esc(o.full_name || 'Unknown')}</td>
      <td><strong>₹${Number(o.total).toLocaleString('en-IN')}</strong></td>
      <td>${typeInfo}</td>
      <td><span class="status-pill status-${o.status}">${o.status}</span></td>
      <td style="font-size:12px;color:var(--text3)">${timeAgo(o.created_at)}</td>
      <td><button class="btn btn-xs btn-secondary" onclick="openOrderDetail(${o.id})">View</button></td>
    </tr>`;
  }).join('') : '<tr class="empty-row"><td colspan="7">No orders yet</td></tr>';
}

function drawSalesChart(monthly) {
  const ctx = document.getElementById('sales-chart')?.getContext('2d');
  if (!ctx) return;
  if (salesChart) salesChart.destroy();
  salesChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: monthly.map(m => m.month),
      datasets: [{
        label: 'Revenue ₹',
        data: monthly.map(m => parseFloat(m.revenue)),
        backgroundColor: 'rgba(124,58,237,0.8)',
        borderRadius: 6, borderSkipped: false,
      },{
        label: 'Orders',
        data: monthly.map(m => parseInt(m.orders)),
        type: 'line',
        borderColor: '#4ade80',
        backgroundColor: 'rgba(167,139,250,0.1)',
        tension: 0.4, fill: true, yAxisID: 'y1',
        pointBackgroundColor: '#16a34a', pointRadius: 4,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: { grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { callback: v => '₹'+v, font:{size:11} } },
        y1: { position: 'right', grid: { display: false }, ticks: { font:{size:11} } },
        x: { grid: { display: false }, ticks: { font:{size:11} } }
      }
    }
  });
}

// ─── ORDERS ───
function extractTableNumber(notes) {
  if (!notes) return null;
  // notes format: "Table 5" or "Table 5 | some note"
  const m = notes.match(/^Table\s+(\S+)/i);
  return m ? m[1] : null;
}

function extractDeliveryAddress(notes) {
  if (!notes) return null;
  // notes format: "Delivery to: <address>" or "Delivery to: <address> | some note"
  const m = notes.match(/^Delivery to:\s*(.+?)(?:\s*\|\s*|$)/i);
  return m ? m[1].trim() : null;
}

function extractOrderNotes(notes) {
  if (!notes) return '';
  // Strip "Table X | " prefix
  let cleaned = notes.replace(/^Table\s+\S+\s*[|]?\s*/i, '');
  // Strip "Delivery to: address | " prefix
  cleaned = cleaned.replace(/^Delivery to:\s*.+?(?:\s*\|\s*)/i, '');
  // Strip "Pickup | " prefix
  cleaned = cleaned.replace(/^Pickup\s*[|]?\s*/i, '');
  return cleaned.trim();
}

function orderTypeBadge(orderType) {
  const map = {
    'dine-in':       { icon: '🪑', label: 'Dine In',  color: '#7c3aed', bg: 'rgba(124,58,237,0.1)' },
    'pickup':        { icon: '🥡', label: 'Pickup',   color: '#0284c7', bg: 'rgba(2,132,199,0.1)'  },
    'home-delivery': { icon: '🏠', label: 'Delivery', color: '#16a34a', bg: 'rgba(22,163,74,0.1)'  },
  };
  const t = map[orderType] || { icon: '📋', label: orderType || '—', color: '#6b7280', bg: 'rgba(107,114,128,0.1)' };
  return `<span style="display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:12px;font-size:11px;font-weight:700;color:${t.color};background:${t.bg};border:1px solid ${t.color}22;">${t.icon} ${t.label}</span>`;
}

function isMobile() {
  return window.innerWidth <= 900;
}

async function loadOrders(page = 1) {
  console.log('[loadOrders] page:', page);
  const tbody = document.getElementById('orders-body');
  const mobileCards = document.getElementById('orders-mobile-cards');
  const search = document.getElementById('order-search')?.value || '';
  const status = document.getElementById('order-status-filter')?.value || '';
  const period = document.getElementById('order-period-filter')?.value || '';
  const order_type = document.getElementById('order-type-filter')?.value || '';
  try {
    const res = await api('get_orders', null, 'GET', { page, search, status, period, order_type });
    if (!res || res.error) throw new Error(res?.error || 'API error');

    const statusSelect = (id, current) => ['pending','confirmed','preparing','ready','completed','cancelled']
      .map(s => `<option value="${s}" ${current===s?'selected':''}>${s}</option>`).join('');

    if (!res.orders?.length) {
      tbody.innerHTML = '<tr class="empty-row"><td colspan="9">No orders found</td></tr>';
      if (mobileCards) mobileCards.innerHTML = '<div style="padding:40px;text-align:center;color:var(--text3)">No orders found</div>';
      document.getElementById('orders-pagination').innerHTML = '';
      return;
    }

    // ── Desktop table rows ──
    tbody.innerHTML = res.orders.map(o => {
      const items = JSON.parse(o.items_json || '[]');
      const names = items.slice(0,2).map(i => i.name).join(', ') + (items.length > 2 ? ` +${items.length-2}` : '');
      const tableNum = extractTableNumber(o.notes);
      const deliveryAddr = extractDeliveryAddress(o.notes);
      const typeInfo = o.order_type === 'home-delivery'
        ? `${orderTypeBadge(o.order_type)}${deliveryAddr ? `<div style="font-size:10px;color:var(--text3);margin-top:2px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(deliveryAddr)}">📍 ${esc(deliveryAddr)}</div>` : ''}`
        : `${orderTypeBadge(o.order_type)}${tableNum ? `<div style="font-size:11px;margin-top:2px;font-weight:700;color:#c2410c">🪑 ${esc(tableNum)}</div>` : ''}`;
      return `<tr>
        <td><span style="font-family:var(--mono);font-size:12px;font-weight:600;color:var(--p2)">${esc(o.order_number)}</span></td>
        <td>${esc(o.full_name||'N/A')}</td>
        <td style="font-size:12px;color:var(--text3)">${esc(o.mobile||'')}</td>
        <td style="font-size:12px;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(names)}">${esc(names)}</td>
        <td>${typeInfo}</td>
        <td><strong>₹${Number(o.total).toLocaleString('en-IN')}</strong></td>
        <td>
          <select class="form-select" style="font-size:12px;padding:4px 8px;width:110px" onchange="updateOrderStatus(${o.id}, this.value)">
            ${statusSelect(o.id, o.status)}
          </select>
        </td>
        <td style="font-size:12px;color:var(--text3)">${formatDate(o.created_at)}</td>
        <td>
          <div style="display:flex;gap:4px">
            <button class="btn btn-xs btn-secondary" onclick="openOrderDetail(${o.id})">👁</button>
            <a href="https://wa.me/${o.mobile?.replace(/\D/g,'')}?text=Your+order+${esc(o.order_number)}+is+ready!" target="_blank" class="btn btn-xs btn-success">💬</a>
          </div>
        </td>
      </tr>`;
    }).join('');

    // ── Mobile cards ──
    if (mobileCards) {
      mobileCards.innerHTML = res.orders.map(o => {
        const items = JSON.parse(o.items_json || '[]');
        const names = items.slice(0,2).map(i => i.name).join(', ') + (items.length > 2 ? ` +${items.length-2}` : '');
        const tableNum = extractTableNumber(o.notes);
        const deliveryAddr = extractDeliveryAddress(o.notes);
        const pureNotes = extractOrderNotes(o.notes);
        const statusDot = { pending:'#f59e0b', confirmed:'#2563eb', preparing:'#16a34a', ready:'#059669', completed:'#10b981', cancelled:'#ef4444' }[o.status] || '#6b7280';
        return `<div class="order-card-mobile">
          <div class="ocm-top">
            <span class="ocm-num">#${esc(o.order_number)}</span>
            <div style="display:flex;align-items:center;gap:6px">
              ${orderTypeBadge(o.order_type)}
              <span class="status-pill status-${o.status}">${o.status}</span>
            </div>
          </div>
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px">
            <div>
              <div style="font-size:13px;font-weight:600">${esc(o.full_name||'N/A')}</div>
              <div class="ocm-items" title="${esc(names)}">${esc(names)}</div>
              ${o.order_type === 'home-delivery' && deliveryAddr ? `<div style="font-size:11px;color:#16a34a;margin-top:3px;font-weight:600">📍 ${esc(deliveryAddr)}</div>` : ''}
              ${o.order_type === 'dine-in' && tableNum ? `<div style="font-size:11px;color:#c2410c;margin-top:2px;font-weight:700">🪑 Table ${esc(tableNum)}</div>` : ''}
              ${pureNotes ? `<div style="font-size:11px;color:var(--warning);margin-top:2px">📝 ${esc(pureNotes)}</div>` : ''}
            </div>
            <div style="text-align:right;flex-shrink:0">
              <div class="ocm-total">₹${Number(o.total).toLocaleString('en-IN')}</div>
              <div style="font-size:11px;color:var(--text3)">${timeAgo(o.created_at)}</div>
            </div>
          </div>
          <div class="ocm-footer">
            <select class="form-select" style="font-size:12px;padding:5px 8px;flex:1;min-width:0" onchange="updateOrderStatus(${o.id}, this.value)">
              ${statusSelect(o.id, o.status)}
            </select>
            <div class="ocm-actions">
              <button class="btn btn-xs btn-secondary" onclick="openOrderDetail(${o.id})">👁 Details</button>
              <a href="https://wa.me/${o.mobile?.replace(/\D/g,'')}?text=Your+order+${esc(o.order_number)}+is+ready!" target="_blank" class="btn btn-xs btn-success">💬</a>
            </div>
          </div>
        </div>`;
      }).join('');
    }

    renderPagination('orders-pagination', res.page, res.pages, p => loadOrders(p));
  } catch(e) {
    console.error('[loadOrders] Error:', e);
    tbody.innerHTML = '<tr class="empty-row"><td colspan="9">⚠️ Failed to load orders. Check console.</td></tr>';
    if (mobileCards) mobileCards.innerHTML = '<div style="padding:20px;text-align:center;color:var(--danger)">⚠️ Failed to load orders</div>';
    toast('Failed to load orders', 'error');
  }
}

async function updateOrderStatus(id, status) {
  const res = await api('update_order_status', { id, status });
  if (res.success) {
    if (status === 'completed' && res.points_awarded > 0) {
      toast(`✅ Order completed! 🪙 +${res.points_awarded} BrewCoins awarded to customer`, 'success');
    } else if (status === 'completed') {
      toast('✅ Order marked completed', 'success');
    } else if (status === 'cancelled') {
      toast('❌ Order cancelled', 'warning');
    } else {
      toast(`Status updated → ${status}`, 'success');
    }
    loadOrders(1); // refresh orders list
  } else {
    toast(res.message || 'Update failed', 'error');
  }
}

async function openOrderDetail(id) {
  // Fetch order from orders list
  const res = await api('get_orders', null, 'GET', { search: '' });
  const order = res.orders?.find(o => o.id == id);
  if (!order) { toast('Order not found', 'error'); return; }
  const items = JSON.parse(order.items_json || '[]');
  const tableNum = extractTableNumber(order.notes);
  const deliveryAddr = extractDeliveryAddress(order.notes);
  const pureNotes = extractOrderNotes(order.notes);

  const typeInfoCell = order.order_type === 'home-delivery'
    ? `<div style="grid-column:1/-1"><div class="text-muted">🏠 Delivery Address</div><strong style="font-size:13px;color:#16a34a;line-height:1.5;display:block;margin-top:4px">${deliveryAddr ? esc(deliveryAddr) : '<span style="color:var(--text-muted);font-size:12px">Not provided</span>'}</strong></div>`
    : `<div><div class="text-muted">🪑 Table Number</div><strong style="font-size:18px;color:#c2410c">${tableNum ? tableNum : '<span style="color:var(--text-muted);font-size:13px">Not specified</span>'}</strong></div>`;

  const html = `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
      <div><div class="text-muted">Order Number</div><div style="font-family:var(--mono);font-weight:700;font-size:15px;color:var(--p2)">${esc(order.order_number)}</div></div>
      <div><div class="text-muted">Status</div><span class="status-pill status-${order.status}">${order.status}</span></div>
      <div><div class="text-muted">Customer</div><strong>${esc(order.full_name||'Unknown')}</strong></div>
      <div><div class="text-muted">Mobile</div>${esc(order.mobile||'N/A')}</div>
      <div><div class="text-muted">Order Type</div>${orderTypeBadge(order.order_type)}</div>
      <div><div class="text-muted">Date</div>${formatDate(order.created_at)}</div>
      ${typeInfoCell}
    </div>
    <hr class="divider">
    <div style="margin-bottom:12px"><strong>Order Items</strong></div>
    <div style="background:var(--bg3);border-radius:10px;padding:12px;margin-bottom:12px">
      ${items.map(i => `<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:13px"><span>${esc(i.name)} × ${i.qty}</span><span>₹${(i.price*i.qty).toLocaleString('en-IN')}</span></div>`).join('')}
    </div>
    <div style="text-align:right">
      <div class="text-muted">Subtotal: ₹${Number(order.subtotal).toLocaleString('en-IN')}</div>
      ${order.discount>0?`<div style="color:var(--success)">Discount: -₹${Number(order.discount).toLocaleString('en-IN')}</div>`:''}
      ${order.points_used>0?`<div style="color:var(--warning)">Points: -${order.points_used}</div>`:''}
      ${order.coupon_code?`<div style="font-size:12px;color:var(--text3)">Coupon: ${esc(order.coupon_code)}</div>`:''}
      <div style="font-size:18px;font-weight:800;margin-top:6px">Total: ₹${Number(order.total).toLocaleString('en-IN')}</div>
    </div>
    ${pureNotes?`<div style="margin-top:12px;background:var(--warning-bg);padding:10px;border-radius:8px;font-size:13px">📝 ${esc(pureNotes)}</div>`:''}
    <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-primary btn-sm" onclick="printBill(${JSON.stringify(order).replace(/'/g,'')})">🖨️ Print Bill</button>
      <a href="https://wa.me/${order.mobile?.replace(/\D/g,'')}?text=Hi+${encodeURIComponent(order.full_name||'')}!+Your+order+${encodeURIComponent(order.order_number)}+update." target="_blank" class="btn btn-success btn-sm">💬 WhatsApp</a>
    </div>`;
  openModal('Order Details', html);
}

function printBill(order) {
  const items = JSON.parse(order.items_json || '[]');
  const tableNum = extractTableNumber(order.notes);
  const deliveryAddr = extractDeliveryAddress(order.notes);
  const pureNotes = extractOrderNotes(order.notes);
  const orderTypeLabel = { 'dine-in': '🪑 Dine In', 'pickup': '🥡 Pickup', 'home-delivery': '🏠 Home Delivery' }[order.order_type] || order.order_type;
  const infoRow = order.order_type === 'home-delivery'
    ? (deliveryAddr ? `<div class="table-row" style="background:#f0fdf4;border-color:#86efac;font-size:12px;text-align:left;">🏠 Deliver to: ${deliveryAddr}</div>` : '')
    : (tableNum ? `<div class="table-row">🪑 Table ${tableNum}</div>` : '');
  const win = window.open('', '', 'width=400,height=600');
  win.document.write(`<html><head><title>Bill ${order.order_number}</title><style>body{font-family:monospace;padding:20px;max-width:320px;margin:auto}h2{text-align:center}hr{border:1px dashed #000}.row{display:flex;justify-content:space-between;margin:4px 0}.total{font-size:18px;font-weight:bold}.table-row{text-align:center;font-size:15px;font-weight:bold;background:#fff7ed;padding:6px;border-radius:6px;margin:8px 0;border:1px solid #fed7aa}.type-row{text-align:center;font-size:12px;color:#555;margin:4px 0}</style></head><body><h2>☕ C3 Restro</h2><div style="text-align:center">Order: ${order.order_number}</div><div style="text-align:center;font-size:12px">${new Date(order.created_at).toLocaleString()}</div><div class="type-row">${orderTypeLabel}</div>${infoRow}<hr>${items.map(i=>`<div class="row"><span>${i.name} x${i.qty}</span><span>₹${(i.price*i.qty).toFixed(2)}</span></div>`).join('')}<hr><div class="row"><span>Subtotal</span><span>₹${Number(order.subtotal).toFixed(2)}</span></div>${order.discount>0?`<div class="row"><span>Discount</span><span>-₹${Number(order.discount).toFixed(2)}</span></div>`:''}<hr><div class="row total"><span>TOTAL</span><span>₹${Number(order.total).toFixed(2)}</span></div>${pureNotes?`<hr><div style="font-size:12px">📝 ${pureNotes}</div>`:''}<hr><p style="text-align:center;font-size:12px">Thank you! Visit again ❤️</p></body></html>`);
  win.document.close(); win.print();
}

async function exportOrdersCSV() {
  const status = document.getElementById('order-status-filter')?.value || '';
  const period = document.getElementById('order-period-filter')?.value || '';
  const res = await api('export_orders_csv', null, 'GET', { status, period });
  if (res.csv) downloadFile(atob(res.csv), res.filename, 'text/csv');
  else toast('Export failed', 'error');
}

// ─── CUSTOMERS ───
async function loadCustomers(page = 1) {
  console.log('[loadCustomers] page:', page);
  const tbody = document.getElementById('customers-body');
  const search = document.getElementById('cust-search')?.value || '';
  try {
    const res = await api('get_customers', null, 'GET', { page, search });
    if (!res || res.error) throw new Error(res?.error || 'API error');
    if (!res.customers?.length) { tbody.innerHTML = '<tr class="empty-row"><td colspan="9">No customers found</td></tr>'; document.getElementById('customers-pagination').innerHTML = ''; return; }
    tbody.innerHTML = res.customers.map(c => `<tr>
      <td><div style="display:flex;align-items:center;gap:8px">
        <div class="avatar-initials" style="width:30px;height:30px;font-size:10px;border-radius:8px;flex-shrink:0">${esc(c.full_name.substring(0,2).toUpperCase())}</div>
        <div><div style="font-weight:600;font-size:13px">${esc(c.full_name)}</div>${c.birthday?`<div style="font-size:11px;color:var(--text3)">🎂 ${c.birthday.substring(0,10)}</div>`:''}</div>
      </div></td>
      <td style="font-family:var(--mono);font-size:12px">${esc(c.mobile)}</td>
      <td>${memberBadge(c.membership_level)}</td>
      <td><strong>⭐ ${c.points}</strong></td>
      <td>${c.order_count || 0}</td>
      <td>₹${Number(c.total_spent||0).toLocaleString('en-IN')}</td>
      <td style="font-size:11px;color:var(--text3)">${formatDate(c.created_at)}</td>
      <td>${c.is_blocked ? '<span class="badge badge-danger">Blocked</span>' : '<span class="badge badge-success">Active</span>'}</td>
      <td>
        <div style="display:flex;gap:4px">
          <button class="btn btn-xs btn-secondary" onclick="openCustomerDetail(${c.id})">👁</button>
          <button class="btn btn-xs btn-ghost" onclick="openAdjustPoints(${c.id},'${esc(c.full_name)}',${c.points})">⭐</button>
          ${c.is_blocked ? `<button class="btn btn-xs btn-success" onclick="toggleBlock(${c.id},0)">✅</button>` : `<button class="btn btn-xs btn-danger" onclick="openBlockModal(${c.id})">🚫</button>`}
        </div>
      </td>
    </tr>`).join('');
    renderPagination('customers-pagination', res.page, res.pages, p => loadCustomers(p));
  } catch(e) {
    console.error('[loadCustomers] Error:', e);
    tbody.innerHTML = '<tr class="empty-row"><td colspan="9">⚠️ Failed to load customers</td></tr>';
    toast('Failed to load customers', 'error');
  }
}

async function openCustomerDetail(id) {
  const res = await api('get_customer_detail', null, 'GET', { id });
  const c = res.customer;
  if (!c) { toast('Customer not found', 'error'); return; }
  const html = `
    <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;padding:16px;background:var(--bg3);border-radius:12px">
      <div class="avatar-initials" style="width:52px;height:52px;font-size:16px;border-radius:14px">${esc(c.full_name.substring(0,2).toUpperCase())}</div>
      <div>
        <div style="font-size:17px;font-weight:800">${esc(c.full_name)}</div>
        <div style="font-size:13px;color:var(--text3)">${esc(c.mobile)} · Ref: ${esc(c.referral_code||'N/A')}</div>
        <div style="margin-top:4px">${memberBadge(c.membership_level)} ${c.is_blocked?'<span class="badge badge-danger ml-2">Blocked</span>':''}</div>
      </div>
      <div style="margin-left:auto;text-align:right">
        <div style="font-size:22px;font-weight:800;color:var(--p2)">⭐ ${c.points}</div>
        <div style="font-size:12px;color:var(--text3)">Loyalty Points</div>
      </div>
    </div>
    <div class="grid-3" style="gap:10px;margin-bottom:16px">
      <div style="background:var(--bg3);border-radius:10px;padding:12px;text-align:center"><div style="font-size:18px;font-weight:800">${res.orders?.length||0}</div><div class="text-muted">Orders</div></div>
      <div style="background:var(--bg3);border-radius:10px;padding:12px;text-align:center"><div style="font-size:18px;font-weight:800">₹${res.orders?.reduce((s,o)=>s+parseFloat(o.total),0).toLocaleString('en-IN',{maximumFractionDigits:0})||0}</div><div class="text-muted">Total Spent</div></div>
      <div style="background:var(--bg3);border-radius:10px;padding:12px;text-align:center"><div style="font-size:18px;font-weight:800">${c.birthday?.substring(0,10)||'N/A'}</div><div class="text-muted">Birthday</div></div>
    </div>
    <div style="margin-bottom:10px;font-weight:700">Recent Orders</div>
    <div style="max-height:160px;overflow-y:auto">
      ${res.orders?.length ? res.orders.map(o=>`<div style="display:flex;justify-content:space-between;padding:8px;border-bottom:1px solid var(--border);font-size:12px"><span style="font-family:var(--mono)">${esc(o.order_number)}</span><span>₹${Number(o.total).toLocaleString('en-IN')}</span><span class="status-pill status-${o.status}" style="font-size:10px">${o.status}</span></div>`).join('') : '<div class="text-muted" style="padding:12px">No orders</div>'}
    </div>
    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn btn-ghost btn-sm" onclick="openAdjustPoints(${c.id},'${esc(c.full_name)}',${c.points})">⭐ Adjust Points</button>
      <button class="btn btn-ghost btn-sm" onclick="openUpgradeMembership(${c.id})">🏅 Change Level</button>
      ${c.is_blocked ? `<button class="btn btn-success btn-sm" onclick="toggleBlock(${c.id},0)">✅ Unblock</button>` : `<button class="btn btn-danger btn-sm" onclick="openBlockModal(${c.id})">🚫 Block</button>`}
      <button class="btn btn-danger btn-sm" onclick="deleteCustomer(${c.id})">🗑️ Delete</button>
    </div>`;
  openModal('Customer Profile — ' + c.full_name, html, '', true);
}

function openAdjustPoints(id, name, current) {
  const html = `<div style="margin-bottom:12px"><span class="badge badge-purple">Current Points: ⭐ ${current}</span></div>
    <div class="form-grid">
      <div class="form-group"><label>Points to Add/Deduct</label><input type="number" class="form-input" id="adj-pts" placeholder="e.g. 50 or -20"></div>
      <div class="form-group"><label>Reason</label><input type="text" class="form-input" id="adj-reason" placeholder="Bonus / correction…" value="Admin manual adjustment"></div>
    </div>`;
  openModal(`Adjust Points — ${name}`, html, `<button class="btn btn-ghost" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="submitAdjustPoints(${id})">Apply</button>`);
}
async function submitAdjustPoints(id) {
  const pts = parseInt(document.getElementById('adj-pts').value);
  const reason = document.getElementById('adj-reason').value;
  if (isNaN(pts) || pts === 0) { toast('Enter valid points', 'warning'); return; }
  const res = await api('adjust_points', { customer_id: id, points: pts, reason });
  if (res.success) { toast(`Points updated! New: ⭐ ${res.new_points} (${res.membership})`, 'success'); closeModal(); loadCustomers(1); }
  else toast(res.message || 'Failed', 'error');
}

function openUpgradeMembership(id) {
  const html = `<div class="form-group"><label>New Membership Level</label><select class="form-select" id="mem-level"><option value="Bronze">🥉 Bronze</option><option value="Silver">🥈 Silver</option><option value="Gold">🥇 Gold</option></select></div>`;
  openModal('Change Membership Level', html, `<button class="btn btn-ghost" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="submitUpgrade(${id})">Update</button>`);
}
async function submitUpgrade(id) {
  const level = document.getElementById('mem-level').value;
  const res = await api('upgrade_membership', { customer_id: id, level });
  if (res.success) { toast('Membership updated!', 'success'); closeModal(); loadCustomers(1); }
  else toast('Failed', 'error');
}

function openBlockModal(id) {
  const html = `<div class="form-group"><label>Reason for blocking</label><input type="text" class="form-input" id="block-reason" placeholder="Reason…"></div>`;
  openModal('Block Customer', html, `<button class="btn btn-ghost" onclick="closeModal()">Cancel</button><button class="btn btn-danger" onclick="submitBlock(${id})">Block Customer</button>`);
}
async function submitBlock(id) {
  const reason = document.getElementById('block-reason').value;
  const res = await api('block_customer', { customer_id: id, reason });
  if (res.success) { toast('Customer blocked', 'warning'); closeModal(); loadCustomers(1); }
}
async function toggleBlock(id, block) {
  const action = block ? 'block_customer' : 'unblock_customer';
  const res = await api(action, { customer_id: id });
  if (res.success) { toast(block ? 'Customer blocked' : 'Customer unblocked', block ? 'warning' : 'success'); loadCustomers(1); }
}
async function deleteCustomer(id) {
  if (!confirm('Permanently delete this customer? This cannot be undone.')) return;
  const res = await api('delete_customer', { customer_id: id });
  if (res.success) { toast('Customer deleted', 'success'); closeModal(); loadCustomers(1); }
  else toast(res.message || 'Failed', 'error');
}

async function showBirthdayCustomers() {
  const res = await api('birthday_customers', null, 'GET');
  const html = res.customers?.length ? `
    <table style="width:100%"><thead><tr style="background:var(--bg3)"><th style="padding:8px;text-align:left">Name</th><th>Mobile</th><th>Level</th><th>Points</th></tr></thead>
    <tbody>${res.customers.map(c=>`<tr style="border-bottom:1px solid var(--border)"><td style="padding:8px">${esc(c.full_name)}</td><td style="padding:8px;font-family:var(--mono);font-size:12px">${esc(c.mobile)}</td><td style="padding:8px">${memberBadge(c.membership_level)}</td><td style="padding:8px">⭐ ${c.points}</td></tr>`).join('')}</tbody></table>` : '<p style="text-align:center;padding:20px;color:var(--text3)">No birthdays today 🎂</p>';
  openModal(`🎂 Birthday Customers Today (${res.customers?.length||0})`, html, '', true);
}

// ─── CATEGORIES ───
async function loadCategories() {
  console.log('[loadCategories]');
  const tbody = document.getElementById('cats-body');
  try {
    const res = await api('get_categories', null, 'GET');
    if (!res || res.error) throw new Error(res?.error || 'API error');
    if (!res.categories?.length) { tbody.innerHTML = '<tr class="empty-row"><td colspan="4">No categories yet. Add your first category!</td></tr>'; return; }
    tbody.innerHTML = res.categories.map(c => `<tr>
      <td style="font-size:22px">${esc(c.icon)}</td>
      <td><strong>${esc(c.name)}</strong></td>
      <td>${c.sort_order}</td>
      <td><div style="display:flex;gap:4px">
        <button class="btn btn-xs btn-secondary" onclick="openCatModal(${JSON.stringify(c).replace(/"/g,'&quot;')})">✏️</button>
        <button class="btn btn-xs btn-danger" onclick="deleteCat(${c.id},'${esc(c.name)}')">🗑️</button>
      </div></td>
    </tr>`).join('');
  } catch(e) {
    console.error('[loadCategories] Error:', e);
    tbody.innerHTML = '<tr class="empty-row"><td colspan="4">⚠️ Failed to load categories</td></tr>';
    toast('Failed to load categories', 'error');
  }
}

function openCatModal(cat = null) {
  const c = cat || {};
  const html = `<div class="form-grid">
    <div class="form-group"><label>Category Name</label><input class="form-input" id="c-name" value="${esc(c.name||'')}" placeholder="e.g. Coffee"></div>
    <div class="form-group"><label>Icon (emoji)</label><input class="form-input" id="c-icon" value="${esc(c.icon||'☕')}" placeholder="☕"></div>
    <div class="form-group"><label>Sort Order</label><input type="number" class="form-input" id="c-sort" value="${c.sort_order||0}"></div>
  </div>`;
  openModal(c.id ? 'Edit Category' : 'New Category', html, `<button class="btn btn-ghost" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="submitCat(${c.id||0})">Save</button>`);
}
async function submitCat(id) {
  const data = { id, name: document.getElementById('c-name').value, icon: document.getElementById('c-icon').value, sort_order: document.getElementById('c-sort').value };
  const res = await api('save_category', data);
  if (res.success) { toast('Category saved!', 'success'); closeModal(); loadCategories(); loadCategoriesForFilter(); }
  else toast(res.message || 'Failed', 'error');
}
async function deleteCat(id, name) {
  if (!confirm(`Delete category "${name}"? Items will be uncategorized.`)) return;
  const res = await api('delete_category', { id });
  if (res.success) { toast('Deleted', 'success'); loadCategories(); }
}

// ─── MENU ITEMS ───
let _categories = [];
async function loadCategoriesForFilter() {
  const res = await api('get_categories', null, 'GET');
  _categories = res.categories || [];
  const sel = document.getElementById('item-cat-filter');
  if (!sel) return;
  const cur = sel.value;
  sel.innerHTML = '<option value="">All Categories</option>' + _categories.map(c => `<option value="${c.id}" ${cur==c.id?'selected':''}>${c.icon} ${esc(c.name)}</option>`).join('');
}

async function loadMenuItems(page = 1) {
  console.log('[loadMenuItems] page:', page);
  const tbody = document.getElementById('menu-body');
  const search = document.getElementById('item-search')?.value || '';
  const cat = document.getElementById('item-cat-filter')?.value || '';
  try {
    const res = await api('get_menu_items', null, 'GET', { page, search, category: cat });
    if (!res || res.error) throw new Error(res?.error || 'API error');
    if (!res.items?.length) { tbody.innerHTML = '<tr class="empty-row"><td colspan="7">No items found</td></tr>'; document.getElementById('menu-pagination').innerHTML = ''; return; }
    tbody.innerHTML = res.items.map(i => `<tr>
      <td><div style="display:flex;align-items:center;gap:10px">
        ${i.image_url ? `<img src="${esc(i.image_url)}" style="width:36px;height:36px;border-radius:8px;object-fit:cover">` : '<div style="width:36px;height:36px;border-radius:8px;background:var(--bg3);display:flex;align-items:center;justify-content:center;font-size:18px">🍽️</div>'}
        <div><div style="font-weight:600;font-size:13px">${esc(i.name)}</div><div style="font-size:11px;color:var(--text3);max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(i.description||'')}</div></div>
      </div></td>
      <td><span class="badge badge-gray">${esc(i.cat_name||'Uncategorized')}</span></td>
      <td><strong>₹${Number(i.price).toLocaleString('en-IN')}</strong></td>
      <td><label class="toggle"><input type="checkbox" ${i.is_available?'checked':''} onchange="toggleField(${i.id},'is_available',this)"><span class="toggle-slider"></span></label></td>
      <td><label class="toggle"><input type="checkbox" ${i.is_bestseller?'checked':''} onchange="toggleField(${i.id},'is_bestseller',this)"><span class="toggle-slider"></span></label></td>
      <td><label class="toggle"><input type="checkbox" ${i.is_recommended?'checked':''} onchange="toggleField(${i.id},'is_recommended',this)"><span class="toggle-slider"></span></label></td>
      <td><div style="display:flex;gap:4px">
        <button class="btn btn-xs btn-secondary" onclick="openItemModal(${JSON.stringify(i).replace(/"/g,'&quot;')})">✏️</button>
        <button class="btn btn-xs btn-danger" onclick="deleteItem(${i.id})">🗑️</button>
      </div></td>
    </tr>`).join('');
    renderPagination('menu-pagination', res.page, res.pages, p => loadMenuItems(p));
  } catch(e) {
    console.error('[loadMenuItems] Error:', e);
    tbody.innerHTML = '<tr class="empty-row"><td colspan="7">⚠️ Failed to load menu items</td></tr>';
    toast('Failed to load menu items', 'error');
  }
}

function openItemModal(item = null) {
  const i = item || {};
  if (!_categories.length) loadCategoriesForFilter();
  const catOpts = _categories.map(c => `<option value="${c.id}" ${i.category_id==c.id?'selected':''}>${c.icon} ${esc(c.name)}</option>`).join('');
  const html = `<div class="form-grid">
    <div class="form-group"><label>Item Name *</label><input class="form-input" id="mi-name" value="${esc(i.name||'')}" placeholder="e.g. Cappuccino"></div>
    <div class="form-group"><label>Price (₹) *</label><input type="number" class="form-input" id="mi-price" value="${i.price||''}" placeholder="0.00" step="0.5"></div>
    <div class="form-group"><label>Category</label><select class="form-select" id="mi-cat"><option value="">No Category</option>${catOpts}</select></div>
    <div class="form-group"><label>Sort Order</label><input type="number" class="form-input" id="mi-sort" value="${i.sort_order||0}"></div>
    <div class="form-group" style="grid-column:1/-1"><label>Description</label><textarea class="form-textarea" id="mi-desc" placeholder="Short description…">${esc(i.description||'')}</textarea></div>
    <div class="form-group" style="grid-column:1/-1"><label>Image URL</label><input class="form-input" id="mi-img" value="${esc(i.image_url||'')}" placeholder="https://images.unsplash.com/…"></div>
    <div class="form-group"><label>Available?</label><label class="toggle" style="margin-top:4px"><input type="checkbox" id="mi-avail" ${i.is_available!==0?'checked':''}><span class="toggle-slider"></span></label></div>
    <div class="form-group"><label>Bestseller?</label><label class="toggle" style="margin-top:4px"><input type="checkbox" id="mi-best" ${i.is_bestseller?'checked':''}><span class="toggle-slider"></span></label></div>
    <div class="form-group"><label>Recommended?</label><label class="toggle" style="margin-top:4px"><input type="checkbox" id="mi-rec" ${i.is_recommended?'checked':''}><span class="toggle-slider"></span></label></div>
  </div>`;
  openModal(i.id ? 'Edit Menu Item' : 'New Menu Item', html, `<button class="btn btn-ghost" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="submitItem(${i.id||0})">Save Item</button>`, true);
}
async function submitItem(id) {
  const data = { id, name: document.getElementById('mi-name').value, price: document.getElementById('mi-price').value, category_id: document.getElementById('mi-cat').value, description: document.getElementById('mi-desc').value, image_url: document.getElementById('mi-img').value, is_available: document.getElementById('mi-avail').checked ? 1 : 0, is_bestseller: document.getElementById('mi-best').checked ? 1 : 0, is_recommended: document.getElementById('mi-rec').checked ? 1 : 0, sort_order: document.getElementById('mi-sort').value };
  if (!data.name || !data.price) { toast('Name and price are required', 'warning'); return; }
  const res = await api('save_menu_item', data);
  if (res.success) { toast('Item saved!', 'success'); closeModal(); loadMenuItems(1); }
  else toast(res.message || 'Failed', 'error');
}
async function deleteItem(id) {
  if (!confirm('Delete this menu item?')) return;
  const res = await api('delete_menu_item', { id });
  if (res.success) { toast('Deleted', 'success'); loadMenuItems(1); }
}
async function toggleField(id, field, el) {
  const res = await api('toggle_item_field', { id, field });
  if (!res.success) { el.checked = !el.checked; toast('Update failed', 'error'); }
}

function openImportModal() {
  const html = `
    <p style="color:var(--text3);font-size:13px;margin-bottom:12px">CSV format: <strong>name,price,category,description,image_url</strong> (first row = header)</p>
    <div class="form-group"><label>Paste CSV Data</label><textarea class="form-textarea" id="import-csv" style="min-height:160px;font-family:var(--mono);font-size:12px" placeholder="name,price,category,description,image_url&#10;Espresso,180,Coffee,Rich espresso,https://…&#10;Cappuccino,220,Coffee,,"></textarea></div>`;
  openModal('📥 Bulk Import Menu CSV', html, `<button class="btn btn-ghost" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="submitImport()">Import</button>`, true);
}
async function submitImport() {
  const csv = document.getElementById('import-csv').value.trim();
  if (!csv) { toast('Paste CSV data first', 'warning'); return; }
  const lines = csv.split('\n').filter(l => l.trim());
  const headers = lines[0].toLowerCase().split(',').map(h => h.trim().replace(/"/g,''));
  const rows = lines.slice(1).map(line => {
    const vals = line.split(',').map(v => v.trim().replace(/^"|"$/g,''));
    const obj = {};
    headers.forEach((h,i) => obj[h] = vals[i] || '');
    return obj;
  }).filter(r => r.name && r.price);
  if (!rows.length) { toast('No valid rows found', 'warning'); return; }
  const res = await api('bulk_import_menu', { rows });
  if (res.success) { toast(`Imported ${res.imported} items!`, 'success'); closeModal(); loadMenuItems(1); }
  else toast('Import failed', 'error');
}

// ─── COMBOS ───
async function loadCombos() {
  console.log('[loadCombos]');
  const tbody = document.getElementById('combos-body');
  try {
    const res = await api('get_combos', null, 'GET');
    if (!res || res.error) throw new Error(res?.error || 'API error');
    if (!res.combos?.length) { tbody.innerHTML = '<tr class="empty-row"><td colspan="7">No combo offers yet</td></tr>'; return; }
    tbody.innerHTML = res.combos.map(c => `<tr>
      <td><div style="display:flex;align-items:center;gap:8px">
        ${c.image_url?`<img src="${esc(c.image_url)}" style="width:32px;height:32px;border-radius:6px;object-fit:cover">`:'<span style="font-size:20px">🎁</span>'}
        <strong>${esc(c.name)}</strong>
      </div></td>
      <td style="font-size:12px;color:var(--text3);max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(c.description||'')}</td>
      <td><span style="text-decoration:line-through;color:var(--text3)">₹${Number(c.original_price).toLocaleString('en-IN')}</span></td>
      <td><strong style="color:var(--success)">₹${Number(c.combo_price).toLocaleString('en-IN')}</strong></td>
      <td><span class="badge badge-success">Save ₹${(Number(c.original_price)-Number(c.combo_price)).toLocaleString('en-IN')}</span></td>
      <td><label class="toggle"><input type="checkbox" ${c.is_active?'checked':''} onchange="toggleCombo(${c.id},this)"><span class="toggle-slider"></span></label></td>
      <td><div style="display:flex;gap:4px">
        <button class="btn btn-xs btn-secondary" onclick="openComboModal(${JSON.stringify(c).replace(/"/g,'&quot;')})">✏️</button>
        <button class="btn btn-xs btn-danger" onclick="deleteCombo(${c.id})">🗑️</button>
      </div></td>
    </tr>`).join('');
  } catch(e) {
    console.error('[loadCombos] Error:', e);
    tbody.innerHTML = '<tr class="empty-row"><td colspan="7">⚠️ Failed to load combos</td></tr>';
    toast('Failed to load combos', 'error');
  }
}
function openComboModal(combo = null) {
  const c = combo || {};
  const html = `<div class="form-grid">
    <div class="form-group"><label>Combo Name *</label><input class="form-input" id="co-name" value="${esc(c.name||'')}" placeholder="Morning Bliss Combo"></div>
    <div class="form-group"><label>Original Price (₹)</label><input type="number" class="form-input" id="co-orig" value="${c.original_price||''}" placeholder="540"></div>
    <div class="form-group"><label>Combo Price (₹)</label><input type="number" class="form-input" id="co-price" value="${c.combo_price||''}" placeholder="399"></div>
    <div class="form-group"><label>Active?</label><label class="toggle" style="margin-top:4px"><input type="checkbox" id="co-active" ${c.is_active!==0?'checked':''}><span class="toggle-slider"></span></label></div>
    <div class="form-group" style="grid-column:1/-1"><label>Description</label><textarea class="form-textarea" id="co-desc" placeholder="What's included…">${esc(c.description||'')}</textarea></div>
    <div class="form-group" style="grid-column:1/-1"><label>Image URL</label><input class="form-input" id="co-img" value="${esc(c.image_url||'')}" placeholder="https://…"></div>
  </div>`;
  openModal(c.id ? 'Edit Combo' : 'New Combo Offer', html, `<button class="btn btn-ghost" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="submitCombo(${c.id||0})">Save</button>`, true);
}
async function submitCombo(id) {
  const data = { id, name: document.getElementById('co-name').value, description: document.getElementById('co-desc').value, original_price: document.getElementById('co-orig').value, combo_price: document.getElementById('co-price').value, image_url: document.getElementById('co-img').value, is_active: document.getElementById('co-active').checked ? 1 : 0 };
  if (!data.name) { toast('Name required', 'warning'); return; }
  const res = await api('save_combo', data);
  if (res.success) { toast('Combo saved!', 'success'); closeModal(); loadCombos(); }
  else toast('Failed', 'error');
}
async function deleteCombo(id) {
  if (!confirm('Delete this combo?')) return;
  const res = await api('delete_combo', { id });
  if (res.success) { toast('Deleted', 'success'); loadCombos(); }
}
async function toggleCombo(id, el) {
  const res = await api('save_combo', { id, is_active: el.checked ? 1 : 0 });
  if (!res.success) el.checked = !el.checked;
}

// ─── COUPONS ───
async function loadCoupons(page = 1) {
  console.log('[loadCoupons] page:', page);
  const tbody = document.getElementById('coupons-body');
  try {
    const res = await api('get_coupons', null, 'GET', { page });
    if (!res || res.error) throw new Error(res?.error || 'API error');
    if (!res.coupons?.length) { tbody.innerHTML = '<tr class="empty-row"><td colspan="8">No coupons yet</td></tr>'; return; }
    tbody.innerHTML = res.coupons.map(c => {
      const expired = c.expires_at && new Date(c.expires_at) < new Date();
      return `<tr>
        <td><strong style="font-family:var(--mono);color:var(--p2)">${esc(c.code)}</strong></td>
        <td><span class="badge ${c.discount_type==='percent'?'badge-purple':'badge-info'}">${c.discount_type}</span></td>
        <td><strong>${c.discount_type==='percent'?c.discount_value+'%':'₹'+c.discount_value}</strong></td>
        <td>₹${Number(c.min_order).toLocaleString('en-IN')}</td>
        <td>${c.actual_usage||c.used_count} / ${c.max_uses}</td>
        <td style="font-size:12px">${c.expires_at ? `<span ${expired?'style="color:var(--danger)"':''}>${c.expires_at.substring(0,10)}</span>` : '—'}</td>
        <td>${c.is_active && !expired ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Inactive</span>'}</td>
        <td><div style="display:flex;gap:4px">
          <button class="btn btn-xs btn-secondary" onclick="openCouponModal(${JSON.stringify(c).replace(/"/g,'&quot;')})">✏️</button>
          <button class="btn btn-xs btn-danger" onclick="deleteCoupon(${c.id})">🗑️</button>
        </div></td>
      </tr>`;
    }).join('');
    renderPagination('coupons-pagination', res.page||1, res.pages||1, p => loadCoupons(p));
  } catch(e) {
    console.error('[loadCoupons] Error:', e);
    tbody.innerHTML = '<tr class="empty-row"><td colspan="8">⚠️ Failed to load coupons</td></tr>';
    toast('Failed to load coupons', 'error');
  }
}
function openCouponModal(coupon = null) {
  const c = coupon || {};
  const html = `<div class="form-grid">
    <div class="form-group"><label>Coupon Code *</label>
      <div style="display:flex;gap:6px">
        <input class="form-input" id="cp-code" value="${esc(c.code||'')}" placeholder="WELCOME20" style="text-transform:uppercase">
        <button class="btn btn-ghost btn-sm" onclick="genCouponCode()" type="button">🎲</button>
      </div>
    </div>
    <div class="form-group"><label>Discount Type</label><select class="form-select" id="cp-type"><option value="percent" ${c.discount_type==='percent'?'selected':''}>Percentage %</option><option value="fixed" ${c.discount_type==='fixed'?'selected':''}>Fixed ₹</option></select></div>
    <div class="form-group"><label>Discount Value</label><input type="number" class="form-input" id="cp-val" value="${c.discount_value||''}" placeholder="20"></div>
    <div class="form-group"><label>Min Order (₹)</label><input type="number" class="form-input" id="cp-min" value="${c.min_order||0}" placeholder="0"></div>
    <div class="form-group"><label>Max Uses</label><input type="number" class="form-input" id="cp-max" value="${c.max_uses||100}"></div>
    <div class="form-group"><label>Expiry Date</label><input type="date" class="form-input" id="cp-exp" value="${c.expires_at?.substring(0,10)||''}"></div>
    <div class="form-group"><label>Active?</label><label class="toggle" style="margin-top:4px"><input type="checkbox" id="cp-active" ${c.is_active!==0?'checked':''}><span class="toggle-slider"></span></label></div>
    <div class="form-group" style="grid-column:1/-1"><label>Description</label><input class="form-input" id="cp-desc" value="${esc(c.description||'')}" placeholder="Short description…"></div>
  </div>`;
  openModal(c.id ? 'Edit Coupon' : 'Create Coupon', html, `<button class="btn btn-ghost" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="submitCoupon(${c.id||0})">Save Coupon</button>`, true);
}
async function genCouponCode() {
  const res = await api('generate_coupon_code', null, 'GET');
  if (res.code) document.getElementById('cp-code').value = res.code;
}
async function submitCoupon(id) {
  const data = { id, code: document.getElementById('cp-code').value.toUpperCase(), discount_type: document.getElementById('cp-type').value, discount_value: document.getElementById('cp-val').value, min_order: document.getElementById('cp-min').value, max_uses: document.getElementById('cp-max').value, expires_at: document.getElementById('cp-exp').value || null, is_active: document.getElementById('cp-active').checked ? 1 : 0, description: document.getElementById('cp-desc').value };
  if (!data.code) { toast('Coupon code required', 'warning'); return; }
  const res = await api('save_coupon', data);
  if (res.success) { toast('Coupon saved!', 'success'); closeModal(); loadCoupons(1); }
  else toast(res.message || 'Failed', 'error');
}
async function deleteCoupon(id) {
  if (!confirm('Delete this coupon?')) return;
  const res = await api('delete_coupon', { id });
  if (res.success) { toast('Deleted', 'success'); loadCoupons(1); }
}

// ─── REWARDS ───
async function loadRewards() {
  console.log('[loadRewards]');
  const tbody = document.getElementById('rewards-body');
  try {
    const res = await api('get_rewards', null, 'GET');
    if (!res || res.error) throw new Error(res?.error || 'API error');
    if (!res.rewards?.length) { tbody.innerHTML = '<tr class="empty-row"><td colspan="7">No rewards yet. Add rewards to motivate customers! ☕</td></tr>'; return; }
    tbody.innerHTML = res.rewards.map(r => `<tr>
      <td><strong>${esc(r.name)}</strong></td>
      <td style="font-size:12px;color:var(--text3)">${esc(r.description||'')}</td>
      <td><strong style="color:var(--p2)">⭐ ${Number(r.points_required).toLocaleString('en-IN')}</strong></td>
      <td><span class="badge badge-purple">${r.reward_type}</span></td>
      <td>${r.reward_type==='discount'?'₹'+r.reward_value:esc(r.reward_value)}</td>
      <td><label class="toggle"><input type="checkbox" ${r.is_active?'checked':''} onchange="toggleReward(${r.id},this)"><span class="toggle-slider"></span></label></td>
      <td><div style="display:flex;gap:4px">
        <button class="btn btn-xs btn-secondary" onclick="openRewardModal(${JSON.stringify(r).replace(/"/g,'&quot;')})">✏️</button>
        <button class="btn btn-xs btn-danger" onclick="deleteReward(${r.id})">🗑️</button>
      </div></td>
    </tr>`).join('');
  } catch(e) {
    console.error('[loadRewards] Error:', e);
    tbody.innerHTML = '<tr class="empty-row"><td colspan="7">⚠️ Failed to load rewards</td></tr>';
    toast('Failed to load rewards', 'error');
  }
}
function openRewardModal(reward = null) {
  const r = reward || {};
  const html = `<div class="form-grid">
    <div class="form-group"><label>Reward Name *</label><input class="form-input" id="rw-name" value="${esc(r.name||'')}" placeholder="Free Espresso"></div>
    <div class="form-group"><label>Points Required</label><input type="number" class="form-input" id="rw-pts" value="${r.points_required||''}" placeholder="100"></div>
    <div class="form-group"><label>Reward Type</label><select class="form-select" id="rw-type"><option value="discount" ${r.reward_type==='discount'?'selected':''}>Discount ₹</option><option value="freeitem" ${r.reward_type==='freeitem'?'selected':''}>Free Item</option><option value="coupon" ${r.reward_type==='coupon'?'selected':''}>Coupon Code</option></select></div>
    <div class="form-group"><label>Value (₹ amount or item name)</label><input class="form-input" id="rw-val" value="${esc(r.reward_value||'')}" placeholder="50 or Espresso"></div>
    <div class="form-group"><label>Active?</label><label class="toggle" style="margin-top:4px"><input type="checkbox" id="rw-active" ${r.is_active!==0?'checked':''}><span class="toggle-slider"></span></label></div>
    <div class="form-group" style="grid-column:1/-1"><label>Description</label><textarea class="form-textarea" id="rw-desc" placeholder="Reward description…">${esc(r.description||'')}</textarea></div>
  </div>`;
  openModal(r.id ? 'Edit Reward' : 'New Reward', html, `<button class="btn btn-ghost" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="submitReward(${r.id||0})">Save</button>`);
}
async function submitReward(id) {
  const data = { id, name: document.getElementById('rw-name').value, description: document.getElementById('rw-desc').value, points_required: document.getElementById('rw-pts').value, reward_type: document.getElementById('rw-type').value, reward_value: document.getElementById('rw-val').value, is_active: document.getElementById('rw-active').checked ? 1 : 0 };
  const res = await api('save_reward', data);
  if (res.success) { toast('Reward saved!', 'success'); closeModal(); loadRewards(); }
  else toast('Failed', 'error');
}
async function deleteReward(id) {
  if (!confirm('Delete this reward?')) return;
  const res = await api('delete_reward', { id });
  if (res.success) { toast('Deleted', 'success'); loadRewards(); }
}
async function toggleReward(id, el) {
  const res = await api('save_reward', { id, is_active: el.checked ? 1 : 0 });
  if (!res.success) el.checked = !el.checked;
}

// ─── POINTS HISTORY ───
async function loadPointHistory(page = 1) {
  console.log('[loadPointHistory] page:', page);
  const tbody = document.getElementById('pts-body');
  const search = document.getElementById('pts-search')?.value || '';
  try {
    const res = await api('get_point_history', null, 'GET', { page, search });
    if (!res || res.error) throw new Error(res?.error || 'API error');
    if (!res.transactions?.length) { tbody.innerHTML = '<tr class="empty-row"><td colspan="6">No transactions found</td></tr>'; document.getElementById('pts-pagination').innerHTML = ''; return; }
    const typeBadge = { earn: 'badge-success', redeem: 'badge-warning', bonus: 'badge-purple', birthday: 'badge-info', referral: 'badge-info' };
    tbody.innerHTML = res.transactions.map(t => `<tr>
      <td>${esc(t.full_name||'Deleted')}</td>
      <td style="font-family:var(--mono);font-size:12px">${esc(t.mobile||'')}</td>
      <td><strong ${t.points>=0?'style="color:var(--success)"':'style="color:var(--danger)"'}>${t.points>=0?'+':''}${t.points}</strong></td>
      <td><span class="badge ${typeBadge[t.type]||'badge-gray'}">${t.type}</span></td>
      <td style="font-size:12px;color:var(--text3)">${esc(t.description||'')}</td>
      <td style="font-size:11px;color:var(--text3)">${formatDate(t.created_at)}</td>
    </tr>`).join('');
    renderPagination('pts-pagination', res.page||1, res.pages||1, p => loadPointHistory(p));
  } catch(e) {
    console.error('[loadPointHistory] Error:', e);
    tbody.innerHTML = '<tr class="empty-row"><td colspan="6">⚠️ Failed to load point history</td></tr>';
    toast('Failed to load point history', 'error');
  }
}

// ─── REPORTS ───
function initReports() {
  const today = new Date();
  document.getElementById('rpt-from').value = today.toISOString().slice(0,8) + '01';
  document.getElementById('rpt-to').value = today.toISOString().slice(0,10);
  loadReport();
}

const rptConfig = {
  sales: { title: 'Daily Sales', cols: ['Date','Orders','Revenue'], keys: ['date','orders','revenue'], fmt: { revenue: v => '₹'+Number(v).toLocaleString('en-IN') } },
  top_items: { title: 'Top Selling Items', cols: ['Item Name','Qty Sold','Revenue'], keys: ['name','qty','revenue'], fmt: { revenue: v => '₹'+Number(v).toLocaleString('en-IN') } },
  top_customers_spend: { title: 'Top Customers by Spend', cols: ['Name','Mobile','Level','Orders','Total Spent'], keys: ['full_name','mobile','membership_level','orders','spent'], fmt: { spent: v => '₹'+Number(v).toLocaleString('en-IN') } },
  top_customers_points: { title: 'Top Customers by Points', cols: ['Name','Mobile','Level','Points'], keys: ['full_name','mobile','membership_level','points'] },
  coupons: { title: 'Coupon Usage Report', cols: ['Code','Type','Value','Total Uses'], keys: ['code','discount_type','discount_value','usage_count'] },
  repeat_customers: { title: 'Repeat Customers', cols: ['Name','Mobile','Order Count'], keys: ['full_name','mobile','order_count'] },
};

async function loadReport() {
  const type = document.getElementById('rpt-type').value;
  const from = document.getElementById('rpt-from').value;
  const to = document.getElementById('rpt-to').value;
  const cfg = rptConfig[type];
  document.getElementById('rpt-title').textContent = cfg.title;
  const showDates = ['sales'].includes(type);
  document.getElementById('rpt-date-wrap').style.display = showDates ? '' : 'none';
  document.getElementById('rpt-date-wrap2').style.display = showDates ? '' : 'none';
  const res = await api('get_reports', null, 'GET', { type, from, to });
  reportData = res.data || [];
  document.getElementById('rpt-head').innerHTML = `<tr>${cfg.cols.map(c=>`<th>${c}</th>`).join('')}</tr>`;
  document.getElementById('rpt-body').innerHTML = reportData.length ? reportData.map(row =>
    `<tr>${cfg.keys.map(k => {
      const val = row[k] ?? '—';
      const fmt = cfg.fmt?.[k];
      return `<td>${fmt ? fmt(val) : esc(String(val))}</td>`;
    }).join('')}</tr>`
  ).join('') : '<tr class="empty-row"><td colspan="'+cfg.cols.length+'">No data for this period</td></tr>';
}

function exportReportCSV() {
  if (!reportData.length) { toast('Run a report first', 'warning'); return; }
  const type = document.getElementById('rpt-type').value;
  const cfg = rptConfig[type];
  let csv = cfg.cols.join(',') + '\n';
  reportData.forEach(row => { csv += cfg.keys.map(k => '"'+(row[k]??'').toString().replace(/"/g,'""')+'"').join(',') + '\n'; });
  downloadFile(csv, `report_${type}_${date()}.csv`, 'text/csv');
}

// ─── STAFF ───
async function loadStaff() {
  const res = await api('get_staff', null, 'GET');
  const tbody = document.getElementById('staff-body');
  if (!res.staff?.length) { tbody.innerHTML = '<tr class="empty-row"><td colspan="6">No staff</td></tr>'; return; }
  tbody.innerHTML = res.staff.map(s => `<tr>
    <td><div style="display:flex;align-items:center;gap:8px">
      <div class="avatar-initials" style="width:30px;height:30px;font-size:10px;border-radius:8px;flex-shrink:0">${esc((s.full_name||s.username).substring(0,2).toUpperCase())}</div>
      <div style="font-weight:600">${esc(s.full_name||s.username)}</div>
    </div></td>
    <td style="font-family:var(--mono);font-size:12px">${esc(s.username)}</td>
    <td><span class="badge ${s.role==='superadmin'?'badge-purple':s.role==='manager'?'badge-info':'badge-gray'}">${s.role}</span></td>
    <td style="font-size:12px;color:var(--text3)">${s.last_login ? formatDate(s.last_login) : 'Never'}</td>
    <td>${s.is_active ? '<span class="badge badge-success">Active</span>' : '<span class="badge badge-danger">Inactive</span>'}</td>
    <td><div style="display:flex;gap:4px">
      <button class="btn btn-xs btn-secondary" onclick="openStaffModal(${JSON.stringify(s).replace(/"/g,'&quot;')})">✏️</button>
      <button class="btn btn-xs btn-ghost" onclick="toggleStaff(${s.id})">${s.is_active?'🔒':'🔓'}</button>
    </div></td>
  </tr>`).join('');
}
function openStaffModal(staff = null) {
  const s = staff || {};
  const html = `<div class="form-grid">
    ${!s.id ? `<div class="form-group"><label>Username *</label><input class="form-input" id="sf-user" value="${esc(s.username||'')}" placeholder="staff01"></div>` : `<div class="form-group"><label>Username</label><input class="form-input" value="${esc(s.username||'')}" disabled></div>`}
    <div class="form-group"><label>Full Name</label><input class="form-input" id="sf-name" value="${esc(s.full_name||'')}" placeholder="Jane Smith"></div>
    <div class="form-group"><label>Role</label><select class="form-select" id="sf-role"><option value="staff" ${s.role==='staff'?'selected':''}>Staff</option><option value="manager" ${s.role==='manager'?'selected':''}>Manager</option><option value="superadmin" ${s.role==='superadmin'?'selected':''}>Super Admin</option></select></div>
    <div class="form-group"><label>${s.id ? 'New Password (leave blank to keep)' : 'Password *'}</label><input type="password" class="form-input" id="sf-pass" placeholder="Min 6 chars"></div>
  </div>`;
  openModal(s.id ? 'Edit Staff' : 'Add Staff Member', html, `<button class="btn btn-ghost" onclick="closeModal()">Cancel</button><button class="btn btn-primary" onclick="submitStaff(${s.id||0})">Save</button>`);
}
async function submitStaff(id) {
  const data = { id, username: document.getElementById('sf-user')?.value||'', full_name: document.getElementById('sf-name').value, role: document.getElementById('sf-role').value, password: document.getElementById('sf-pass').value };
  const res = await api('save_staff', data);
  if (res.success) { toast('Staff saved!', 'success'); closeModal(); loadStaff(); }
  else toast(res.message || 'Failed', 'error');
}
async function toggleStaff(id) {
  const res = await api('toggle_staff', { id });
  if (res.success) { toast('Updated', 'success'); loadStaff(); }
}

// ─── SETTINGS ───
async function loadSettings() {
  const res = await api('get_settings', null, 'GET');
  const s = res.settings || {};
  Object.entries(s).forEach(([k, v]) => {
    const el = document.getElementById('cfg-' + k);
    if (el) el.value = v;
  });
}
async function saveSettings() {
  const data = {};
  document.querySelectorAll('[id^="cfg-"]').forEach(el => {
    const key = el.id.replace('cfg-', '');
    data[key] = el.value;
  });
  const res = await api('save_settings', data);
  if (res.success) toast('Settings saved!', 'success');
  else toast(res.message || 'Failed', 'error');
}

// ─── TOOLS ───
async function backupSQL() {
  toast('Generating backup…', 'info');
  const res = await api('backup_sql', null, 'GET');
  if (res.sql) { downloadFile(atob(res.sql), res.filename, 'text/sql'); toast('Backup downloaded!', 'success'); }
  else toast('Backup failed', 'error');
}

async function confirmResetDemo() {
  if (!confirm('⚠️ This will delete all orders and reset all customer points to 50. Are you sure?')) return;
  if (!confirm('Final confirmation: This CANNOT be undone. Continue?')) return;
  const res = await api('reset_demo_data', {});
  if (res.success) { toast('Demo data reset!', 'success'); loadDashboard(); }
  else toast('Failed', 'error');
}

function openResetDataModal() {
  const html = `
    <div style="margin-bottom:18px;padding:14px 16px;background:var(--danger-bg);border:1px solid #fca5a5;border-radius:10px;font-size:13px;color:var(--danger)">
      ⚠️ <strong>Warning:</strong> This action is <strong>permanent and cannot be undone</strong>. Please take a database backup before proceeding.
    </div>
    <p style="font-size:13px;color:var(--text2);margin-bottom:16px;font-weight:600">Select the data you want to permanently delete:</p>
    <div style="display:flex;flex-direction:column;gap:10px">
      <label style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;transition:border-color 0.15s" onmouseenter="this.style.borderColor='var(--danger)'" onmouseleave="this.style.borderColor='var(--border)'">
        <input type="checkbox" id="rst-orders" style="margin-top:2px;accent-color:var(--danger)">
        <div><div style="font-weight:600;font-size:13px">🧾 Orders &amp; Coupon Usage</div><div style="font-size:11.5px;color:var(--text3)">Deletes all order records and coupon redemption history</div></div>
      </label>
      <label style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;transition:border-color 0.15s" onmouseenter="this.style.borderColor='var(--danger)'" onmouseleave="this.style.borderColor='var(--border)'">
        <input type="checkbox" id="rst-points" style="margin-top:2px;accent-color:var(--danger)">
        <div><div style="font-weight:600;font-size:13px">💎 Customer Points &amp; Transactions</div><div style="font-size:11.5px;color:var(--text3)">Resets all BrewCoins to 0 and clears transaction history. Membership levels reset to Bronze</div></div>
      </label>
      <label style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;transition:border-color 0.15s" onmouseenter="this.style.borderColor='var(--danger)'" onmouseleave="this.style.borderColor='var(--border)'">
        <input type="checkbox" id="rst-coupons" style="margin-top:2px;accent-color:var(--danger)">
        <div><div style="font-weight:600;font-size:13px">🎟️ Coupons</div><div style="font-size:11.5px;color:var(--text3)">Permanently deletes all coupon codes and their usage records</div></div>
      </label>
      <label style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;transition:border-color 0.15s" onmouseenter="this.style.borderColor='var(--danger)'" onmouseleave="this.style.borderColor='var(--border)'">
        <input type="checkbox" id="rst-menu" style="margin-top:2px;accent-color:var(--danger)">
        <div><div style="font-weight:600;font-size:13px">🍽️ Menu Items &amp; Categories</div><div style="font-size:11.5px;color:var(--text3)">Deletes all menu items and all menu categories</div></div>
      </label>
      <label style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;transition:border-color 0.15s" onmouseenter="this.style.borderColor='var(--danger)'" onmouseleave="this.style.borderColor='var(--border)'">
        <input type="checkbox" id="rst-combos" style="margin-top:2px;accent-color:var(--danger)">
        <div><div style="font-weight:600;font-size:13px">🎁 Combo Offers</div><div style="font-size:11.5px;color:var(--text3)">Removes all combo deals from the menu</div></div>
      </label>
      <label style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;transition:border-color 0.15s" onmouseenter="this.style.borderColor='var(--danger)'" onmouseleave="this.style.borderColor='var(--border)'">
        <input type="checkbox" id="rst-rewards" style="margin-top:2px;accent-color:var(--danger)">
        <div><div style="font-weight:600;font-size:13px">⭐ Rewards Catalog</div><div style="font-size:11.5px;color:var(--text3)">Deletes all loyalty rewards from the catalog</div></div>
      </label>
      <label style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;transition:border-color 0.15s" onmouseenter="this.style.borderColor='var(--danger)'" onmouseleave="this.style.borderColor='var(--border)'">
        <input type="checkbox" id="rst-customers" style="margin-top:2px;accent-color:var(--danger)">
        <div><div style="font-weight:600;font-size:13px">👥 All Customers</div><div style="font-size:11.5px;color:var(--text3);color:#ef4444;font-weight:500">⚠️ DANGER — Permanently deletes all customer accounts, their points, and order links</div></div>
      </label>
      <label style="display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1.5px solid var(--border);border-radius:10px;cursor:pointer;transition:border-color 0.15s" onmouseenter="this.style.borderColor='var(--danger)'" onmouseleave="this.style.borderColor='var(--border)'">
        <input type="checkbox" id="rst-logs" style="margin-top:2px;accent-color:var(--danger)">
        <div><div style="font-weight:600;font-size:13px">📋 Admin Activity Logs</div><div style="font-size:11.5px;color:var(--text3)">Wipes all admin action logs from the system</div></div>
      </label>
    </div>
    <div style="margin-top:16px;padding:12px 14px;background:var(--bg3);border-radius:10px">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px">
        <input type="checkbox" id="rst-select-all" onchange="toggleResetAll(this)" style="accent-color:var(--danger)">
        <span style="font-weight:600;color:var(--danger)">☠️ Select All (Full Reset)</span>
      </label>
    </div>
    <div style="margin-top:14px">
      <label style="font-size:12.5px;font-weight:600;color:var(--text2);display:block;margin-bottom:6px">Type <strong style="color:var(--danger)">RESET</strong> to confirm:</label>
      <input type="text" class="form-input" id="rst-confirm-text" placeholder='Type "RESET" here' autocomplete="off" style="border-color:var(--border2)">
    </div>`;

  const footer = `
    <button class="btn btn-ghost" onclick="closeModal()">Cancel</button>
    <button class="btn btn-danger" onclick="executeResetData()">🗑️ Permanently Delete Selected</button>`;

  openModal('🗑️ Reset Data', html, footer, true);
}

function toggleResetAll(checkbox) {
  const ids = ['rst-orders','rst-points','rst-coupons','rst-menu','rst-combos','rst-rewards','rst-customers','rst-logs'];
  ids.forEach(id => { const el = document.getElementById(id); if (el) el.checked = checkbox.checked; });
}

async function executeResetData() {
  const confirmText = document.getElementById('rst-confirm-text')?.value?.trim();
  if (confirmText !== 'RESET') { toast('Type RESET in the confirmation field to proceed', 'warning'); return; }

  const scopeMap = {
    'rst-orders': 'orders',
    'rst-points': 'points',
    'rst-coupons': 'coupons',
    'rst-menu': 'menu',
    'rst-combos': 'combos',
    'rst-rewards': 'rewards',
    'rst-customers': 'customers',
    'rst-logs': 'logs',
  };
  const scope = [];
  Object.entries(scopeMap).forEach(([elId, val]) => {
    if (document.getElementById(elId)?.checked) scope.push(val);
  });

  if (!scope.length) { toast('Please select at least one data category to reset', 'warning'); return; }

  const scopeLabels = scope.join(', ');
  if (!confirm(`⚠️ FINAL WARNING\n\nYou are about to permanently delete:\n${scopeLabels}\n\nThis CANNOT be undone. Are you absolutely sure?`)) return;

  closeModal();
  toast('Resetting data…', 'info');
  const res = await api('reset_data', { scope });
  if (res.success) {
    const cleared = res.cleared?.join(', ') || scopeLabels;
    toast(`✅ Cleared: ${cleared}`, 'success');
    loadDashboard();
    // Refresh relevant pages if they're loaded
    if (scope.includes('menu')) { try { loadMenuItems(1); loadCategories(); } catch(e){} }
    if (scope.includes('coupons')) { try { loadCoupons(1); } catch(e){} }
    if (scope.includes('rewards')) { try { loadRewards(); } catch(e){} }
    if (scope.includes('orders')) { try { loadOrders(1); } catch(e){} }
    if (scope.includes('customers') || scope.includes('points')) { try { loadCustomers(1); } catch(e){} }
  } else {
    toast(res.message || 'Reset failed', 'error');
  }
}

async function changeAdminPassword() {
  const current = document.getElementById('cp-current').value;
  const newPw = document.getElementById('cp-new').value;
  if (!current || !newPw) { toast('Fill both fields', 'warning'); return; }
  if (newPw.length < 6) { toast('Password too short (min 6)', 'warning'); return; }
  const res = await api('admin_change_password', { current, new: newPw });
  if (res.success) { toast('Password changed!', 'success'); document.getElementById('cp-current').value = ''; document.getElementById('cp-new').value = ''; }
  else toast(res.message || 'Failed', 'error');
}

async function showQRCode() {
  const res = await api('get_qr_data', null, 'GET');
  const url = res.url ? (location.origin + '/' + res.url) : location.origin + '/cafe_panel.php';
  const html = `<div style="text-align:center">
    <p style="margin-bottom:16px;color:var(--text3);font-size:13px">Scan to open: <strong>${url}</strong></p>
    <div id="qr-output" style="display:inline-block;padding:16px;background:#fff;border-radius:12px;box-shadow:var(--shadow-md)"></div>
    <p style="margin-top:12px;font-size:12px;color:var(--text3)">Right-click the QR code to save it</p>
  </div>`;
  openModal('📱 QR Code — Menu Page', html);
  setTimeout(() => {
    try {
      new QRCode(document.getElementById('qr-output'), { text: url, width: 200, height: 200, correctLevel: QRCode.CorrectLevel.H });
    } catch(e) { document.getElementById('qr-output').innerHTML = `<p style="color:var(--danger)">QR generation failed. URL: ${url}</p>`; }
  }, 100);
}

// ─── HELPERS ───
function esc(str) { if (str === null || str === undefined) return ''; return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function formatDate(d) { if (!d) return '—'; return new Date(d).toLocaleString('en-IN', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' }); }
function timeAgo(d) {
  const diff = Math.floor((Date.now() - new Date(d)) / 1000);
  if (diff < 60) return diff + 's ago';
  if (diff < 3600) return Math.floor(diff/60) + 'm ago';
  if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
  return Math.floor(diff/86400) + 'd ago';
}
function date() { return new Date().toISOString().slice(0,10); }
function memberBadge(level) {
  const map = { Bronze: '🥉', Silver: '🥈', Gold: '🥇', Platinum: '💎' };
  const col = { Bronze: 'badge-gray', Silver: 'badge-info', Gold: 'badge-warning', Platinum: 'badge-purple' };
  return `<span class="badge ${col[level]||'badge-gray'}">${map[level]||'🥉'} ${level||'Bronze'}</span>`;
}
function renderPagination(containerId, current, total, callback) {
  const el = document.getElementById(containerId);
  if (!el || total <= 1) { if(el) el.innerHTML = ''; return; }
  let html = `<div class="pg-info">Page ${current} of ${total}</div>`;
  html += `<button class="pg-btn" onclick="(${callback})(1)" ${current===1?'disabled':''}>«</button>`;
  html += `<button class="pg-btn" onclick="(${callback})(${current-1})" ${current===1?'disabled':''}>‹</button>`;
  const start = Math.max(1, current-2), end = Math.min(total, current+2);
  for (let i = start; i <= end; i++) html += `<button class="pg-btn ${i===current?'active':''}" onclick="(${callback})(${i})">${i}</button>`;
  html += `<button class="pg-btn" onclick="(${callback})(${current+1})" ${current===total?'disabled':''}>›</button>`;
  html += `<button class="pg-btn" onclick="(${callback})(${total})" ${current===total?'disabled':''}>»</button>`;
  el.innerHTML = html;
}
function downloadFile(content, filename, type) {
  const blob = new Blob([content], { type });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  a.click();
}

// ─── LOYALTY ANALYTICS ───
async function loadLoyaltyAnalytics() {
  console.log('[loadLoyaltyAnalytics]');
  try {
    const res = await api('loyalty_analytics', null, 'GET');
    if (!res || res.error) throw new Error(res?.error || 'API error');
    document.getElementById('la-issued').textContent = Number(res.total_issued||0).toLocaleString('en-IN');
    document.getElementById('la-redeemed').textContent = Number(res.total_redeemed||0).toLocaleString('en-IN');
    document.getElementById('la-active').textContent = res.active_loyalty || 0;
    document.getElementById('la-top-reward').textContent = res.top_reward?.name || 'N/A';

    // Top customers
    const topBody = document.getElementById('la-top-body');
    topBody.innerHTML = res.top_customers?.length ? res.top_customers.map((c,i) => `<tr>
      <td><strong style="color:var(--p2)">#${i+1}</strong></td>
      <td>${esc(c.full_name)}</td>
      <td>${memberBadge(c.membership_level)}</td>
      <td><strong>⭐ ${Number(c.points).toLocaleString('en-IN')}</strong></td>
    </tr>`).join('') : '<tr class="empty-row"><td colspan="4">No data yet</td></tr>';

    // Level distribution
    const distBody = document.getElementById('la-dist-body');
    const levelEmoji = { Bronze:'🥉', Silver:'🥈', Gold:'🥇', Platinum:'💎' };
    distBody.innerHTML = res.level_dist?.length ? res.level_dist.map(l => `<tr>
      <td>${memberBadge(l.membership_level)}</td>
      <td><strong>${l.cnt}</strong></td>
    </tr>`).join('') : '<tr class="empty-row"><td colspan="2">No data</td></tr>';

    // Recent transactions
    const txnBody = document.getElementById('la-txn-body');
    const typeBadge = { earn: 'badge-success', redeem: 'badge-warning', bonus: 'badge-purple', birthday: 'badge-info', referral: 'badge-info' };
    txnBody.innerHTML = res.recent_txns?.length ? res.recent_txns.map(t => `<tr>
      <td>${esc(t.full_name||'—')}</td>
      <td><strong ${t.points>=0?'style="color:var(--success)"':'style="color:var(--danger)"'}>${t.points>=0?'+':''}${t.points}</strong></td>
      <td><span class="badge ${typeBadge[t.type]||'badge-gray'}">${t.type}</span></td>
      <td style="font-size:12px;color:var(--text3)">${esc(t.description||'')}</td>
      <td style="font-size:11px;color:var(--text3)">${formatDate(t.created_at)}</td>
    </tr>`).join('') : '<tr class="empty-row"><td colspan="5">No transactions yet</td></tr>';
  } catch(e) {
    console.error('[loadLoyaltyAnalytics] Error:', e);
    toast('Failed to load loyalty analytics', 'error');
  }
}

// ─── LOYALTY SETTINGS PAGE ───
async function loadLoyaltySettingsPage() {
  console.log('[loadLoyaltySettingsPage]');
  const res = await api('get_settings', null, 'GET');
  const s = res.settings || {};
  const loyaltyFields = ['earn_rate','redeem_rate','max_redeem_pct','birthday_bonus','referral_bonus','daily_login_bonus'];
  loyaltyFields.forEach(k => {
    const el = document.getElementById('cfg-' + k);
    if (el && s[k] !== undefined) el.value = s[k];
  });
  // These have loy- prefix to avoid conflicts with main settings
  const loySecondaryFields = ['silver_threshold','gold_threshold','platinum_threshold','silver_multiplier'];
  loySecondaryFields.forEach(k => {
    const el = document.getElementById('loy-' + k);
    if (el && s[k] !== undefined) el.value = s[k];
  });
}

async function saveLoyaltySettings() {
  const data = {};
  const loyaltyFields = ['earn_rate','redeem_rate','max_redeem_pct','birthday_bonus','referral_bonus','daily_login_bonus'];
  loyaltyFields.forEach(k => {
    const el = document.getElementById('cfg-' + k);
    if (el) data[k] = el.value;
  });
  const loySecondaryFields = ['silver_threshold','gold_threshold','platinum_threshold','silver_multiplier'];
  loySecondaryFields.forEach(k => {
    const el = document.getElementById('loy-' + k);
    if (el) data[k] = el.value;
  });
  const res = await api('save_settings', data);
  if (res.success) toast('Loyalty settings saved! 🪙', 'success');
  else toast(res.message || 'Failed', 'error');
}

// ─── INIT ───
<?php if ($admin): ?>
// Debounce helper
function debounce(fn, delay = 350) {
  let t; return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), delay); };
}

window.addEventListener('DOMContentLoaded', () => {
  console.log('[C3 Restro] Admin Panel initializing...');

  // ── Step 1: Unlock AudioContext + build beep WAV on first click ──
  const unlockAudio = () => {
    try { getAudioCtx(); getBeepWavUrl(); } catch(e) {}
    document.removeEventListener('click', unlockAudio);
  };
  document.addEventListener('click', unlockAudio);

  // ── Step 2: Request notification permission (for background alerts) ──
  // We do this after a short delay so it doesn't fire immediately on page load
  setTimeout(async () => {
    const granted = await requestNotificationPermission();
    if (granted) {
      console.log('[Notifications] Permission granted — background alerts active');
      // Register Service Worker for background polling
      await registerOrderServiceWorker();
    } else {
      console.warn('[Notifications] Permission denied — background alerts limited to tab-visible polling');
    }
  }, 2000);

  // ── Step 3: Load dashboard ──
  loadDashboard().catch(e => console.error('[init] loadDashboard failed:', e));

  // ── Step 4: Foreground notifications polling (every 20s) ──
  loadNotifications();
  setInterval(() => loadNotifications(), 20000);

  // ── Step 5: Debounced search inputs ──
  const searches = [
    ['order-search',  () => loadOrders(1)],
    ['cust-search',   () => loadCustomers(1)],
    ['item-search',   () => loadMenuItems(1)],
    ['pts-search',    () => loadPointHistory(1)],
  ];
  searches.forEach(([id, fn]) => {
    const el = document.getElementById(id);
    if (el) {
      el.oninput = null;
      el.addEventListener('input', debounce(fn));
    }
  });

  // ── Step 6: Force-trigger the currently active page loader ──
  setTimeout(() => {
    const activePage = document.querySelector('.page.active');
    if (activePage) {
      const pageId = activePage.id.replace('page-', '');
      if (pageId !== 'dashboard' && pageLoaders[pageId]) {
        try { pageLoaders[pageId](); } catch(e) { console.error(e); }
      }
    }
  }, 100);

  console.log('[C3 Restro] Init complete.');
});
<?php endif; ?>
</script>
</body>
</html>