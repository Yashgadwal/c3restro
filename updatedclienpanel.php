<?php

/**
 * C3 Restaurant - Client Panel
 * Single-file PHP + MySQL Application
 * No framework, no folder structure
 */

// ============================================================
// SPEED: Output buffering + gzip compression
// ============================================================
ob_start();

// ============================================================
// DATABASE CONFIGURATION - Edit these before deploying
// ============================================================
define('DB_HOST', 'sql208.infinityfree.com');
define('DB_USER', 'if0_42049744');
define('DB_PASS', '02118200YashYg');
define('DB_NAME', 'if0_42049744_c3');

// ============================================================
// LOYALTY ECONOMY CONFIGURATION
// ============================================================
define('POINTS_PER_RUPEE', 1);          // Customer earns 1 point per ₹1 spent
define('POINTS_REDEEM_RATE', 40);       // 40 points = ₹1 redemption value (was 20 — halved to reduce discount drain)
define('MAX_REDEEM_PERCENT', 15);       // Max 15% of order value can be paid by points (was 20%)
define('WELCOME_BONUS_POINTS', 100);    // Welcome points on signup (was 200)
define('REFERRAL_BONUS_POINTS', 150);   // Both referrer & new user get this (was 300)
define('BIRTHDAY_BONUS_POINTS', 300);   // Birthday bonus (was 500)
define('DAILY_LOGIN_MIN', 5);           // Min daily login bonus points (was 10)
define('DAILY_LOGIN_MAX', 15);          // Max daily login bonus points (was 30)
define('STREAK_7_DAY_BONUS', 75);       // 7-day streak milestone bonus (was 150)
define('TIER_BRONZE_MIN', 0);
define('TIER_SILVER_MIN', 2000);        // Harder to reach (was 1000)
define('TIER_GOLD_MIN', 8000);          // Harder to reach (was 5000)
define('TIER_PLATINUM_MIN', 25000);     // Harder to reach (was 15000)

// ============================================================
// DATABASE SETUP & CONNECTION
// ============================================================
function getDB()
{
  static $pdo = null;
  if ($pdo === null) {
    try {
      $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
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

function setupDatabase()
{
  $db = getDB();

  // Disable FK checks for compatibility with shared hosting
  try { $db->exec("SET FOREIGN_KEY_CHECKS=0"); } catch(PDOException $e) {}

  $tables = [
    "customers" => "CREATE TABLE IF NOT EXISTS `customers` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `full_name` VARCHAR(100) NOT NULL,
        `mobile` VARCHAR(20) UNIQUE NOT NULL,
        `password` VARCHAR(255) NOT NULL,
        `birthday` DATE NULL,
        `points` INT DEFAULT 0,
        `membership_level` ENUM('Bronze','Silver','Gold','Platinum') DEFAULT 'Bronze',
        `referral_code` VARCHAR(10) UNIQUE,
        `referred_by` INT NULL,
        `dark_mode` TINYINT(1) DEFAULT 0,
        `last_login_bonus_date` DATE NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "menu_categories" => "CREATE TABLE IF NOT EXISTS `menu_categories` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(50) NOT NULL,
        `icon` VARCHAR(50) DEFAULT '☕',
        `sort_order` INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "menu_items" => "CREATE TABLE IF NOT EXISTS `menu_items` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `category_id` INT,
        `name` VARCHAR(100) NOT NULL,
        `description` TEXT,
        `price` DECIMAL(10,2) NOT NULL,
        `image_url` VARCHAR(500),
        `is_bestseller` TINYINT(1) DEFAULT 0,
        `is_recommended` TINYINT(1) DEFAULT 0,
        `is_available` TINYINT(1) DEFAULT 1,
        `sort_order` INT DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "combo_offers" => "CREATE TABLE IF NOT EXISTS `combo_offers` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100) NOT NULL,
        `description` TEXT,
        `original_price` DECIMAL(10,2),
        `combo_price` DECIMAL(10,2),
        `image_url` VARCHAR(500),
        `is_active` TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "orders" => "CREATE TABLE IF NOT EXISTS `orders` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `customer_id` INT NOT NULL,
        `order_number` VARCHAR(20) UNIQUE,
        `items_json` TEXT,
        `subtotal` DECIMAL(10,2),
        `discount` DECIMAL(10,2) DEFAULT 0,
        `points_used` INT DEFAULT 0,
        `total` DECIMAL(10,2),
        `coupon_code` VARCHAR(20),
        `status` ENUM('pending','confirmed','preparing','ready','completed','cancelled') DEFAULT 'pending',
        `order_type` ENUM('dine-in','pickup') DEFAULT 'dine-in',
        `points_earned` INT DEFAULT 0,
        `notes` TEXT,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "coupons" => "CREATE TABLE IF NOT EXISTS `coupons` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `code` VARCHAR(20) UNIQUE NOT NULL,
        `discount_type` ENUM('percent','fixed') DEFAULT 'percent',
        `discount_value` DECIMAL(10,2),
        `min_order` DECIMAL(10,2) DEFAULT 0,
        `max_uses` INT DEFAULT 100,
        `used_count` INT DEFAULT 0,
        `expires_at` DATE NULL,
        `is_active` TINYINT(1) DEFAULT 1,
        `description` VARCHAR(200)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "coupon_usage" => "CREATE TABLE IF NOT EXISTS `coupon_usage` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `coupon_id` INT,
        `customer_id` INT,
        `order_id` INT,
        `used_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "point_transactions" => "CREATE TABLE IF NOT EXISTS `point_transactions` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `customer_id` INT,
        `points` INT,
        `type` ENUM('earn','redeem','bonus','birthday','referral','login','streak') DEFAULT 'earn',
        `description` VARCHAR(200),
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "rewards" => "CREATE TABLE IF NOT EXISTS `rewards` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(100),
        `description` VARCHAR(200),
        `points_required` INT,
        `reward_type` ENUM('discount','freeitem','coupon') DEFAULT 'discount',
        `reward_value` VARCHAR(100),
        `is_active` TINYINT(1) DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "game_rewards" => "CREATE TABLE IF NOT EXISTS `game_rewards` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `customer_id` INT NOT NULL,
        `game_type` ENUM('spin','scratch','card','giftbox','treasure','dailytap') NOT NULL,
        `display_reward` VARCHAR(100) NOT NULL,
        `actual_coupon_code` VARCHAR(20) NOT NULL,
        `actual_discount` INT NOT NULL,
        `min_order` DECIMAL(10,2) DEFAULT 199,
        `is_used` TINYINT(1) DEFAULT 0,
        `expires_at` DATE NOT NULL,
        `played_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "game_plays" => "CREATE TABLE IF NOT EXISTS `game_plays` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `customer_id` INT NOT NULL,
        `game_type` VARCHAR(20) NOT NULL,
        `played_date` DATE NOT NULL,
        UNIQUE KEY `unique_daily` (`customer_id`, `game_type`, `played_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "game_settings" => "CREATE TABLE IF NOT EXISTS `game_settings` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `game_type` VARCHAR(20) UNIQUE NOT NULL,
        `is_enabled` TINYINT(1) DEFAULT 1,
        `chance_10pct` INT DEFAULT 45,
        `chance_20pct` INT DEFAULT 15,
        `chance_blnt` INT DEFAULT 40,
        `expiry_days` INT DEFAULT 5
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "leaderboard_cache" => "CREATE TABLE IF NOT EXISTS `leaderboard_cache` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `leaderboard_type` ENUM('spending','games','streak') NOT NULL,
        `month_year` VARCHAR(7) NOT NULL,
        `customer_id` INT NOT NULL,
        `rank` INT NOT NULL,
        `score` DECIMAL(12,2) DEFAULT 0,
        `reward_given` TINYINT(1) DEFAULT 0,
        UNIQUE KEY `unique_lb` (`leaderboard_type`, `month_year`, `customer_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "cafe_settings" => "CREATE TABLE IF NOT EXISTS `cafe_settings` (
        `key` VARCHAR(100) PRIMARY KEY,
        `value` TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "visit_streaks" => "CREATE TABLE IF NOT EXISTS `visit_streaks` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `customer_id` INT UNIQUE NOT NULL,
        `current_streak` INT DEFAULT 0,
        `longest_streak` INT DEFAULT 0,
        `last_visit_date` DATE NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
  ];

  foreach ($tables as $name => $sql) {
    try {
      $db->exec($sql);
    } catch (PDOException $e) {
      throw new PDOException("Table [$name]: " . $e->getMessage(), (int)$e->getCode());
    }
  }

  // Insert sample data if empty
  $count = $db->query("SELECT COUNT(*) FROM menu_categories")->fetchColumn();
  if ($count == 0) {
    insertSampleData($db);
  }

  // Seed game settings
  $gsCount = $db->query("SELECT COUNT(*) FROM game_settings")->fetchColumn();
  if ($gsCount == 0) {
    $db->exec("INSERT INTO game_settings (game_type,is_enabled,chance_10pct,chance_20pct,chance_blnt,expiry_days) VALUES
      ('spin',1,45,15,40,5),
      ('scratch',1,45,15,40,5),
      ('card',1,45,15,40,5),
      ('giftbox',1,45,15,40,5),
      ('treasure',1,45,15,40,7),
      ('dailytap',1,45,15,40,3)
    ");
  }
  // Seed default cafe settings
  $db->exec("INSERT IGNORE INTO cafe_settings (`key`,`value`) VALUES
    ('instagram_url','https://www.instagram.com/arabica_officiall'),
    ('cafe_name','C3 Restaurant')
  ");
}

function insertSampleData($db)
{
  // Categories
  $db->exec("INSERT INTO `menu_categories` (`name`, `icon`, `sort_order`) VALUES
        ('Coffee', '☕', 1), ('Tea & More', '🍵', 2), ('Cold Drinks', '🧊', 3),
        ('Snacks', '🥐', 4), ('Desserts', '🍰', 5), ('Combos', '🎁', 6)");

  // Menu Items
  $db->exec("INSERT INTO `menu_items` (`category_id`, `name`, `description`, `price`, `image_url`, `is_bestseller`, `is_recommended`) VALUES
        (1, 'Signature Espresso', 'Rich double-shot espresso with velvety crema, sourced from Ethiopian highlands', 180.00, 'https://images.unsplash.com/photo-1510591509098-f4fdc6d0ff04?w=400&q=80', 1, 1),
        (1, 'Caramel Macchiato', 'Smooth espresso layered over vanilla milk with golden caramel drizzle', 280.00, 'https://images.unsplash.com/photo-1485808191679-5f86510bd9d4?w=400&q=80', 1, 0),
        (1, 'Cappuccino Classic', 'Perfect balance of espresso, steamed milk and thick foam art', 220.00, 'https://images.unsplash.com/photo-1572442388796-11668a67e53d?w=400&q=80', 0, 1),
        (1, 'Flat White', 'Microfoam milk poured over ristretto shots, silky and intense', 250.00, 'https://images.unsplash.com/photo-1577968897966-3d4325b36b61?w=400&q=80', 0, 0),
        (1, 'Mocha Latte', 'Espresso meets Belgian chocolate in a creamy warm embrace', 290.00, 'https://images.unsplash.com/photo-1461023058943-07fcbe16d735?w=400&q=80', 0, 1),
        (2, 'Masala Chai', 'Traditional Indian spiced tea with cardamom, ginger and cinnamon', 120.00, 'https://images.unsplash.com/photo-1571934811356-5cc061b6821f?w=400&q=80', 1, 1),
        (2, 'Green Tea Latte', 'Premium matcha powder whisked with steamed oat milk', 220.00, 'https://images.unsplash.com/photo-1515823662972-da6a2e4d3002?w=400&q=80', 0, 0),
        (2, 'Chamomile Honey', 'Soothing chamomile tea with raw forest honey and lemon', 160.00, 'https://images.unsplash.com/photo-1564890369478-c89ca6d9cde9?w=400&q=80', 0, 0),
        (3, 'Cold Brew Supreme', '24-hour cold-steeped coffee concentrate served over crystal ice', 320.00, 'https://images.unsplash.com/photo-1461023058943-07fcbe16d735?w=400&q=80', 1, 1),
        (3, 'Mango Passion Cooler', 'Fresh Alphonso mango blended with passion fruit and mint', 260.00, 'https://images.unsplash.com/photo-1546171753-97d7676e4602?w=400&q=80', 0, 1),
        (3, 'Iced Matcha Latte', 'Ceremonial grade matcha shaken with coconut milk over ice', 280.00, 'https://images.unsplash.com/photo-1515823662972-da6a2e4d3002?w=400&q=80', 0, 0),
        (4, 'Croissant Butter', 'Flaky French-style croissant baked fresh every morning', 160.00, 'https://images.unsplash.com/photo-1555507036-ab1f4038808a?w=400&q=80', 1, 0),
        (4, 'Avocado Toast', 'Sourdough toast topped with smashed avocado, chilli flakes and microgreens', 280.00, 'https://images.unsplash.com/photo-1603046891744-76e6300f82ef?w=400&q=80', 0, 1),
        (4, 'Paneer Sandwich', 'Grilled cottage cheese with mint chutney in toasted multigrain bread', 220.00, 'https://images.unsplash.com/photo-1481070414801-51fd732d7184?w=400&q=80', 0, 0),
        (5, 'Belgian Waffle', 'Crispy waffle with fresh berries, whipped cream and maple syrup', 320.00, 'https://images.unsplash.com/photo-1562376552-0d160a2f238d?w=400&q=80', 1, 1),
        (5, 'Tiramisu Classic', 'Authentic Italian tiramisu with mascarpone and espresso-soaked ladyfingers', 280.00, 'https://images.unsplash.com/photo-1571877227200-a0d98ea607e9?w=400&q=80', 0, 1),
        (5, 'Chocolate Lava Cake', 'Warm dark chocolate cake with a molten centre, served with vanilla gelato', 350.00, 'https://images.unsplash.com/photo-1578985545062-69928b1d9587?w=400&q=80', 0, 0)
    ");

  // Combo Offers
  $db->exec("INSERT INTO `combo_offers` (`name`, `description`, `original_price`, `combo_price`, `image_url`) VALUES
        ('Morning Bliss Combo', 'Cappuccino + Butter Croissant + Fresh Orange Juice', 540.00, 399.00, 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=400&q=80'),
        ('Power Lunch Combo', 'Cold Brew + Avocado Toast + Tiramisu', 880.00, 649.00, 'https://images.unsplash.com/photo-1504674900247-0877df9cc836?w=400&q=80'),
        ('Sweet Evening Combo', 'Caramel Macchiato + Belgian Waffle + Chocolate Lava Cake', 950.00, 749.00, 'https://images.unsplash.com/photo-1470338745628-171cf53de3a8?w=400&q=80')
    ");

  // Coupons
  $db->exec("INSERT INTO `coupons` (`code`, `discount_type`, `discount_value`, `min_order`, `description`) VALUES
        ('WELCOME20', 'percent', 20, 200, '20% off on your first order'),
        ('FLAT50', 'fixed', 50, 300, 'Flat ₹50 off on orders above ₹300'),
        ('BREW15', 'percent', 15, 150, '15% off for loyal customers')
    ");

  // Rewards - 20 pts = ₹1 redemption value
  $db->exec("INSERT INTO `rewards` (`name`, `description`, `points_required`, `reward_type`, `reward_value`) VALUES
        ('Free Espresso', 'Redeem for a complimentary signature espresso', 800, 'freeitem', 'Signature Espresso'),
        ('₹50 Off', 'Get ₹50 discount on your next order', 1200, 'discount', '50'),
        ('Free Dessert', 'Treat yourself to a Belgian Waffle on us!', 2000, 'freeitem', 'Belgian Waffle'),
        ('Buy 1 Get 1 Coffee', 'Bring a friend — one coffee is on us!', 3500, 'coupon', 'BOGO50'),
        ('₹200 Off Premium', 'Big reward for our most loyal members', 6000, 'discount', '200')
    ");

  // Sample admin customer
  $hash = password_hash('admin123', PASSWORD_DEFAULT);
  $db->exec("INSERT IGNORE INTO `customers` (`full_name`, `mobile`, `password`, `birthday`, `points`, `membership_level`, `referral_code`) VALUES
        ('Demo Customer', '9876543210', '$hash', '1995-04-18', 1250, 'Silver', 'DEMO001')
    ");
}

// ============================================================
// SESSION & AUTH HELPERS
// ============================================================
session_start();
try {
  setupDatabase();
} catch (PDOException $e) {
  error_log('C3 DB Setup Error: ' . $e->getMessage());
  die('<h2 style="font-family:sans-serif;color:#c00;padding:2rem;">Database setup error: ' . htmlspecialchars($e->getMessage()) . '</h2>');
}

function isLoggedIn()
{
  return isset($_SESSION['customer_id']);
}
function getCustomer()
{
  if (!isLoggedIn()) return null;
  $db = getDB();
  $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
  $stmt->execute([$_SESSION['customer_id']]);
  return $stmt->fetch();
}
function getMembershipBadge($level)
{
  $badges = ['Bronze' => '🥉', 'Silver' => '🥈', 'Gold' => '🥇', 'Platinum' => '💎'];
  return $badges[$level] ?? '🥉';
}
function updateMembership($customer_id, $points)
{
  $level = 'Bronze';
  if ($points >= TIER_PLATINUM_MIN) $level = 'Platinum';
  elseif ($points >= TIER_GOLD_MIN) $level = 'Gold';
  elseif ($points >= TIER_SILVER_MIN) $level = 'Silver';
  getDB()->prepare("UPDATE customers SET membership_level=? WHERE id=?")->execute([$level, $customer_id]);
  return $level;
}
function generateOrderNumber()
{
  return 'BC' . strtoupper(substr(uniqid(), -6));
}
function generateReferralCode($name)
{
  return strtoupper(substr(preg_replace('/[^a-z]/i', '', strtolower($name)), 0, 3)) . rand(100, 999);
}
function isBirthdayMonth($birthday)
{
  if (!$birthday) return false;
  $bday = date_create($birthday); return $bday && date_format($bday,'m-d') === date('m-d');
}

// ============================================================
// GAME HELPERS
// ============================================================
function pickActualReward($game_type, $db)
{
  $settings = $db->prepare("SELECT * FROM game_settings WHERE game_type=?");
  $settings->execute([$game_type]);
  $s = $settings->fetch();
  if (!$s) $s = ['chance_10pct' => 45, 'chance_20pct' => 15, 'chance_blnt' => 40, 'expiry_days' => 5];
  $roll = rand(1, 100);
  $c10 = $s['chance_10pct'];
  $c20 = $s['chance_20pct'];
  if ($roll <= $c20) {
    return ['discount' => 20, 'min_order' => 399, 'expiry_days' => $s['expiry_days']];
  } elseif ($roll <= $c20 + $c10) {
    return ['discount' => 10, 'min_order' => 199, 'expiry_days' => $s['expiry_days']];
  } else {
    return ['discount' => 0, 'min_order' => 0, 'expiry_days' => $s['expiry_days']]; // better luck
  }
}

function mapDisplayToActual($display_reward, $actual_discount)
{
  // What text to show in wallet based on display name + actual discount
  if ($actual_discount == 0) return 'Better Luck Next Time';
  if ($actual_discount == 20) return 'Mega Reward: 20% OFF';
  return 'Lucky Reward: 10% OFF';
}

function generateGameCoupon($customer_id, $game_type, $display_reward, $db)
{
  $actual = pickActualReward($game_type, $db);
  if ($actual['discount'] == 0) {
    return ['type' => 'blnt', 'display' => $display_reward, 'actual_discount' => 0];
  }
  $code = 'GAME' . strtoupper(substr(uniqid(), -6));
  $expiry = date('Y-m-d', strtotime("+{$actual['expiry_days']} days"));
  // Insert into coupons table
  $db->prepare("INSERT INTO coupons (code,discount_type,discount_value,min_order,description,max_uses,expires_at) VALUES (?,'percent',?,?,?,1,?)")
    ->execute([$code, $actual['discount'], $actual['min_order'], "Game Reward: {$actual['discount']}% OFF", $expiry]);
  $coupon_id = $db->lastInsertId();
  // Insert into game_rewards
  $db->prepare("INSERT INTO game_rewards (customer_id,game_type,display_reward,actual_coupon_code,actual_discount,min_order,expires_at) VALUES (?,?,?,?,?,?,?)")
    ->execute([$customer_id, $game_type, $display_reward, $code, $actual['discount'], $actual['min_order'], $expiry]);
  return ['type' => 'win', 'code' => $code, 'display' => $display_reward, 'actual_discount' => $actual['discount'], 'min_order' => $actual['min_order'], 'expiry' => $expiry];
}

function updateVisitStreak($customer_id, $db)
{
  $row = $db->prepare("SELECT * FROM visit_streaks WHERE customer_id=?");
  $row->execute([$customer_id]);
  $streak = $row->fetch();
  $today = date('Y-m-d');
  if (!$streak) {
    $db->prepare("INSERT INTO visit_streaks (customer_id,current_streak,longest_streak,last_visit_date) VALUES (?,1,1,?)")->execute([$customer_id, $today]);
    return 1;
  }
  $last = $streak['last_visit_date'];
  if ($last == $today) return $streak['current_streak'];
  $yesterday = date('Y-m-d', strtotime('-1 day'));
  $new_streak = ($last == $yesterday) ? $streak['current_streak'] + 1 : 1;
  $longest = max($streak['longest_streak'], $new_streak);
  $db->prepare("UPDATE visit_streaks SET current_streak=?,longest_streak=?,last_visit_date=? WHERE customer_id=?")->execute([$new_streak, $longest, $today, $customer_id]);
  // 7-day streak milestone bonus
  if ($new_streak > 0 && $new_streak % 7 === 0) {
    $bonus = STREAK_7_DAY_BONUS;
    $db->prepare("UPDATE customers SET points=points+? WHERE id=?")->execute([$bonus, $customer_id]);
    $db->prepare("INSERT INTO point_transactions (customer_id,points,type,description) VALUES (?,?,'streak','🔥 " . ($new_streak) . "-day streak milestone bonus!')")->execute([$customer_id, $bonus]);
  }
  return $new_streak;
}

// ============================================================
// API / AJAX HANDLERS
// ============================================================
if (isset($_GET['api'])) {
  header('Content-Type: application/json; charset=utf-8');
  // Prevent browser caching of API responses
  header('Cache-Control: no-store, no-cache, must-revalidate');
  header('Pragma: no-cache');
  // Catch any PHP warnings/notices so they don't break JSON
  set_error_handler(function ($errno, $errstr) {
    // suppress non-fatal errors during API calls to avoid broken JSON
    return true;
  });
  $action = $_GET['api'];
  $db = getDB();

  if ($action === 'signup') {
    $data = json_decode(file_get_contents('php://input'), true);
    $name = trim($data['full_name'] ?? '');
    $mobile = trim($data['mobile'] ?? '');
    $password = $data['password'] ?? '';
    $birthday = $data['birthday'] ?? null;
    $refer_code = strtoupper(trim($data['refer_code'] ?? ''));

    if (!$name || !$mobile || !$password) {
      echo json_encode(['success' => false, 'message' => 'All fields required']);
      exit;
    }
    $existing = $db->prepare("SELECT id FROM customers WHERE mobile=?");
    $existing->execute([$mobile]);
    if ($existing->fetch()) {
      echo json_encode(['success' => false, 'message' => 'Mobile number already registered']);
      exit;
    }

    // Validate referral code if provided
    $referred_by_id = null;
    if ($refer_code) {
      $ref_check = $db->prepare("SELECT id FROM customers WHERE referral_code=?");
      $ref_check->execute([$refer_code]);
      $referrer = $ref_check->fetch();
      if ($referrer) {
        $referred_by_id = $referrer['id'];
      } else {
        echo json_encode(['success' => false, 'message' => 'Invalid referral code']);
        exit;
      }
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $referral = generateReferralCode($name);
    $stmt = $db->prepare("INSERT INTO customers (full_name,mobile,password,birthday,referral_code,referred_by) VALUES (?,?,?,?,?,?)");
    $stmt->execute([$name, $mobile, $hash, $birthday ?: null, $referral, $referred_by_id]);
    $id = $db->lastInsertId();
    // Welcome points
    $db->prepare("UPDATE customers SET points=? WHERE id=?")->execute([WELCOME_BONUS_POINTS, $id]);
    $db->prepare("INSERT INTO point_transactions (customer_id,points,type,description) VALUES (?,?,'bonus','🎉 Welcome bonus — start earning!')")->execute([$id, WELCOME_BONUS_POINTS]);
    // Referral bonus for BOTH users
    if ($referred_by_id) {
      $db->prepare("UPDATE customers SET points=points+? WHERE id=?")->execute([REFERRAL_BONUS_POINTS, $referred_by_id]);
      $db->prepare("INSERT INTO point_transactions (customer_id,points,type,description) VALUES (?,?,'referral','👥 Referral bonus — friend joined!')")->execute([$referred_by_id, REFERRAL_BONUS_POINTS]);
      // New user also gets referral bonus
      $db->prepare("UPDATE customers SET points=points+? WHERE id=?")->execute([REFERRAL_BONUS_POINTS, $id]);
      $db->prepare("INSERT INTO point_transactions (customer_id,points,type,description) VALUES (?,?,'referral','👥 Referral bonus — joined via friend!')")->execute([$id, REFERRAL_BONUS_POINTS]);
    }
    $_SESSION['customer_id'] = $id;
    echo json_encode(['success' => true, 'message' => 'Account created successfully!']);
    exit;
  }

  if ($action === 'login') {
    $data = json_decode(file_get_contents('php://input'), true);
    $mobile = trim($data['mobile'] ?? '');
    $password = $data['password'] ?? '';
    $remember = $data['remember'] ?? false;
    $stmt = $db->prepare("SELECT * FROM customers WHERE mobile=?");
    $stmt->execute([$mobile]);
    $customer = $stmt->fetch();
    if ($customer && password_verify($password, $customer['password'])) {
      $_SESSION['customer_id'] = $customer['id'];
      if ($remember) {
        $token = bin2hex(random_bytes(32));
        setcookie('remember_token', $token, time() + 30 * 24 * 3600, '/', '', false, true);
      }
      echo json_encode(['success' => true]);
    } else {
      echo json_encode(['success' => false, 'message' => 'Invalid mobile or password']);
    }
    exit;
  }

  if ($action === 'logout') {
    session_destroy();
    setcookie('remember_token', '', time() - 3600, '/');
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'get_menu') {
    $cats = $db->query("SELECT * FROM menu_categories ORDER BY sort_order")->fetchAll();
    $items = $db->query("SELECT mi.*, mc.name as category_name FROM menu_items mi LEFT JOIN menu_categories mc ON mi.category_id=mc.id WHERE mi.is_available=1 ORDER BY mi.sort_order")->fetchAll();
    $combos = $db->query("SELECT * FROM combo_offers WHERE is_active=1")->fetchAll();
    // Include cafe settings for guest access
    $settings = [];
    foreach ($db->query("SELECT `key`,`value` FROM cafe_settings")->fetchAll() as $row) {
      $settings[$row['key']] = $row['value'];
    }
    echo json_encode(['categories' => $cats, 'items' => $items, 'combos' => $combos, 'settings' => $settings]);
    exit;
  }

  if ($action === 'get_settings') {
    $settings = [];
    foreach ($db->query("SELECT `key`,`value` FROM cafe_settings")->fetchAll() as $row) {
      $settings[$row['key']] = $row['value'];
    }
    echo json_encode(['success' => true, 'settings' => $settings]);
    exit;
  }

  if ($action === 'update_instagram' && isLoggedIn()) {
    $data = json_decode(file_get_contents('php://input'), true);
    $url = trim($data['url'] ?? '');
    if (!$url) {
      echo json_encode(['success' => false, 'message' => 'URL required']);
      exit;
    }
    $db->prepare("INSERT INTO cafe_settings (`key`,`value`) VALUES ('instagram_url',?) ON DUPLICATE KEY UPDATE `value`=?")->execute([$url, $url]);
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'get_dashboard') {
    if (!isLoggedIn()) {
      echo json_encode(['success' => false, 'message' => 'Not authenticated', 'customer' => null]);
      exit;
    }
    $customer = getCustomer();
    $orders = $db->prepare("SELECT * FROM orders WHERE customer_id=? ORDER BY created_at DESC LIMIT 5");
    $orders->execute([$_SESSION['customer_id']]);
    $recentOrders = $orders->fetchAll();
    $rewards = $db->query("SELECT * FROM rewards WHERE is_active=1 ORDER BY points_required")->fetchAll();
    $transactions = $db->prepare("SELECT * FROM point_transactions WHERE customer_id=? ORDER BY created_at DESC LIMIT 10");
    $transactions->execute([$_SESSION['customer_id']]);
    $txns = $transactions->fetchAll();
    $recommended = $db->query("SELECT * FROM menu_items WHERE is_recommended=1 AND is_available=1 LIMIT 6")->fetchAll();
    $birthday_reward = isBirthdayMonth($customer['birthday']);
    // Check if birthday reward already given this year
    $bday_check = $db->prepare("SELECT id FROM point_transactions WHERE customer_id=? AND type='birthday' AND YEAR(created_at)=YEAR(NOW())");
    $bday_check->execute([$_SESSION['customer_id']]);
    $bday_given = $bday_check->fetch();
    if ($birthday_reward && !$bday_given) {
      $db->prepare("UPDATE customers SET points=points+? WHERE id=?")->execute([BIRTHDAY_BONUS_POINTS, $_SESSION['customer_id']]);
      $db->prepare("INSERT INTO point_transactions (customer_id,points,type,description) VALUES (?,?,'birthday','🎂 Birthday special bonus!')")->execute([$_SESSION['customer_id'], BIRTHDAY_BONUS_POINTS]);
      $customer['points'] += BIRTHDAY_BONUS_POINTS;
      $birthday_reward = true;
      $birthday_reward_given = true;
    }
    echo json_encode([
      'customer' => $customer,
      'recent_orders' => $recentOrders,
      'rewards' => $rewards,
      'transactions' => $txns,
      'recommended' => $recommended,
      'birthday_reward' => $birthday_reward,
      'birthday_reward_new' => $birthday_reward_given ?? false
    ]);
    exit;
  }

  // ============================================================
  // DAILY LOGIN BONUS
  // ============================================================
  if ($action === 'claim_daily_bonus' && isLoggedIn()) {
    $customer = getCustomer();
    $today = date('Y-m-d');
    if ($customer['last_login_bonus_date'] === $today) {
      echo json_encode(['success' => false, 'message' => 'Already claimed today', 'already_claimed' => true]);
      exit;
    }
    $bonus = rand(DAILY_LOGIN_MIN, DAILY_LOGIN_MAX);
    $db->prepare("UPDATE customers SET points=points+?, last_login_bonus_date=? WHERE id=?")->execute([$bonus, $today, $_SESSION['customer_id']]);
    $db->prepare("INSERT INTO point_transactions (customer_id,points,type,description) VALUES (?,?,'login','🌟 Daily login bonus!')")->execute([$_SESSION['customer_id'], $bonus]);
    $new_points = $db->prepare("SELECT points FROM customers WHERE id=?");
    $new_points->execute([$_SESSION['customer_id']]);
    $np = $new_points->fetchColumn();
    updateMembership($_SESSION['customer_id'], $np);
    echo json_encode(['success' => true, 'bonus' => $bonus, 'new_points' => $np]);
    exit;
  }

  if ($action === 'place_order' && isLoggedIn()) {
    $data = json_decode(file_get_contents('php://input'), true);
    $items = $data['items'] ?? [];
    $points_use = intval($data['points_use'] ?? 0);
    $order_type = $data['order_type'] ?? 'dine-in';
    $notes = $data['notes'] ?? '';

    if (empty($items)) {
      echo json_encode(['success' => false, 'message' => 'Cart is empty']);
      exit;
    }

    $subtotal = 0;
    foreach ($items as $item) {
      $subtotal += $item['price'] * $item['qty'];
    }

    $customer = getCustomer();
    // Tier multiplier for earning
    $multiplier = 1.0;
    if ($customer['membership_level'] === 'Silver') $multiplier = 1.15;
    elseif ($customer['membership_level'] === 'Gold') $multiplier = 1.3;
    elseif ($customer['membership_level'] === 'Platinum') $multiplier = 1.5;

    $points_discount = 0;
    if ($points_use > 0) {
      $max_redeem_rupees = $subtotal * (MAX_REDEEM_PERCENT / 100);
      $max_points_allowed = floor($max_redeem_rupees * POINTS_REDEEM_RATE);
      $max_use = min($points_use, $customer['points'], $max_points_allowed);
      $points_discount = $max_use / POINTS_REDEEM_RATE;
      $points_use = $max_use;
    }

    $total = max(0, $subtotal - $points_discount);
    $points_earned = floor($total * POINTS_PER_RUPEE * $multiplier);
    $order_num = generateOrderNumber();

    $stmt = $db->prepare("INSERT INTO orders (customer_id,order_number,items_json,subtotal,discount,points_used,total,status,order_type,points_earned,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$_SESSION['customer_id'], $order_num, json_encode($items), $subtotal, $points_discount, $points_use, $total, 'pending', $order_type, $points_earned, $notes]);
    $order_id = $db->lastInsertId();

    if ($points_use > 0) {
      $db->prepare("UPDATE customers SET points=points-? WHERE id=?")->execute([$points_use, $_SESSION['customer_id']]);
      $db->prepare("INSERT INTO point_transactions (customer_id,points,type,description) VALUES (?,-?,'redeem','Points redeemed on order $order_num (₹" . number_format($points_discount, 2) . " off at 40pts=₹1)')")->execute([$_SESSION['customer_id'], $points_use]);
    }

    echo json_encode(['success' => true, 'order_number' => $order_num, 'points_earned' => $points_earned, 'total' => $total]);
    exit;
  }

  // ============================================================
  // UPDATE ORDER STATUS — awards C3 Coins ONLY on 'completed'
  // ============================================================
  if ($action === 'update_order_status' && isLoggedIn()) {
    $data = json_decode(file_get_contents('php://input'), true);
    $order_id   = intval($data['order_id'] ?? 0);
    $new_status = $data['status'] ?? '';

    $allowed = ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'];
    if (!$order_id || !in_array($new_status, $allowed)) {
      echo json_encode(['success' => false, 'message' => 'Invalid request']);
      exit;
    }

    $ord_stmt = $db->prepare("SELECT * FROM orders WHERE id=?");
    $ord_stmt->execute([$order_id]);
    $order = $ord_stmt->fetch();

    if (!$order) {
      echo json_encode(['success' => false, 'message' => 'Order not found']);
      exit;
    }

    $prev_status = $order['status'];

    if (in_array($prev_status, ['completed', 'cancelled'])) {
      echo json_encode(['success' => false, 'message' => 'Order already ' . $prev_status]);
      exit;
    }

    $db->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$new_status, $order_id]);

    $points_awarded = 0;

    // Award C3 Coins ONLY when status becomes 'completed'
    if ($new_status === 'completed') {
      $points_to_award = intval($order['points_earned']);
      $cust_id         = intval($order['customer_id']);
      $order_num_ref   = $order['order_number'];

      if ($points_to_award > 0) {
        $db->prepare("UPDATE customers SET points=points+? WHERE id=?")->execute([$points_to_award, $cust_id]);
        $db->prepare("INSERT INTO point_transactions (customer_id,points,type,description) VALUES (?,?,'earn','C3 Coins earned — Order #$order_num_ref completed')")->execute([$cust_id, $points_to_award]);

        $new_pts_stmt = $db->prepare("SELECT points FROM customers WHERE id=?");
        $new_pts_stmt->execute([$cust_id]);
        $new_pts = $new_pts_stmt->fetchColumn();
        updateMembership($cust_id, $new_pts);

        $points_awarded = $points_to_award;
      }
    }

    echo json_encode(['success' => true, 'status' => $new_status, 'points_awarded' => $points_awarded]);
    exit;
  }

  if ($action === 'update_profile' && isLoggedIn()) {
    $data = json_decode(file_get_contents('php://input'), true);
    $name = trim($data['full_name'] ?? '');
    $birthday = $data['birthday'] ?? null;
    $db->prepare("UPDATE customers SET full_name=?, birthday=? WHERE id=?")->execute([$name, $birthday ?: null, $_SESSION['customer_id']]);
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'change_password' && isLoggedIn()) {
    $data = json_decode(file_get_contents('php://input'), true);
    $current = $data['current'] ?? '';
    $new = $data['new'] ?? '';
    $customer = getCustomer();
    if (!password_verify($current, $customer['password'])) {
      echo json_encode(['success' => false, 'message' => 'Current password incorrect']);
      exit;
    }
    $db->prepare("UPDATE customers SET password=? WHERE id=?")->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['customer_id']]);
    echo json_encode(['success' => true]);
    exit;
  }

  if ($action === 'toggle_dark_mode' && isLoggedIn()) {
    $customer = getCustomer();
    $new_mode = $customer['dark_mode'] ? 0 : 1;
    $db->prepare("UPDATE customers SET dark_mode=? WHERE id=?")->execute([$new_mode, $_SESSION['customer_id']]);
    echo json_encode(['success' => true, 'dark_mode' => $new_mode]);
    exit;
  }

  if ($action === 'redeem_reward' && isLoggedIn()) {
    $data = json_decode(file_get_contents('php://input'), true);
    $reward_id = intval($data['reward_id'] ?? 0);
    $customer = getCustomer();
    $reward = $db->prepare("SELECT * FROM rewards WHERE id=? AND is_active=1");
    $reward->execute([$reward_id]);
    $r = $reward->fetch();
    if (!$r) {
      echo json_encode(['success' => false, 'message' => 'Reward not found']);
      exit;
    }
    if ($customer['points'] < $r['points_required']) {
      echo json_encode(['success' => false, 'message' => 'Insufficient points']);
      exit;
    }
    $db->prepare("UPDATE customers SET points=points-? WHERE id=?")->execute([$r['points_required'], $_SESSION['customer_id']]);
    $stmt = $db->prepare("INSERT INTO point_transactions (customer_id, points, type, description) VALUES (?, ?, 'redeem', ?)");
    $stmt->execute([
      $_SESSION['customer_id'],
      -$r['points_required'],
      'Redeemed: ' . $r['name']
    ]);
    $coupon_code = 'RWD' . strtoupper(substr(uniqid(), -6));
    $db->prepare("INSERT INTO coupons (code,discount_type,discount_value,min_order,description,max_uses) VALUES (?,'fixed',?,0,?,1)")->execute([$coupon_code, $r['reward_value'], $r['name']]);
    echo json_encode(['success' => true, 'coupon' => $coupon_code, 'reward' => $r]);
    exit;
  }

  if ($action === 'get_orders' && isLoggedIn()) {
    $stmt = $db->prepare("SELECT * FROM orders WHERE customer_id=? ORDER BY created_at DESC");
    $stmt->execute([$_SESSION['customer_id']]);
    echo json_encode(['orders' => $stmt->fetchAll()]);
    exit;
  }

  // ============================================================
  // GAME APIs
  // ============================================================
  if ($action === 'get_games' && isLoggedIn()) {
    $settings = $db->query("SELECT * FROM game_settings")->fetchAll();
    $settingsMap = [];
    foreach ($settings as $s) $settingsMap[$s['game_type']] = $s;
    $today = date('Y-m-d');
    // Check which games played today
    $played = $db->prepare("SELECT game_type FROM game_plays WHERE customer_id=? AND played_date=?");
    $played->execute([$_SESSION['customer_id'], $today]);
    $playedToday = array_column($played->fetchAll(), 'game_type');
    // Wallet coupons (unused game rewards)
    $wallet = $db->prepare("SELECT * FROM game_rewards WHERE customer_id=? AND is_used=0 AND expires_at >= CURDATE() ORDER BY played_at DESC");
    $wallet->execute([$_SESSION['customer_id']]);
    $walletItems = $wallet->fetchAll();
    // Streak
    $streakRow = $db->prepare("SELECT * FROM visit_streaks WHERE customer_id=?");
    $streakRow->execute([$_SESSION['customer_id']]);
    $streak = $streakRow->fetch();
    echo json_encode([
      'settings' => $settingsMap,
      'played_today' => $playedToday,
      'wallet' => $walletItems,
      'streak' => $streak ?: ['current_streak' => 0, 'longest_streak' => 0]
    ]);
    exit;
  }

  if ($action === 'play_game' && isLoggedIn()) {
    $data = json_decode(file_get_contents('php://input'), true);
    $game_type = $data['game_type'] ?? '';
    $display_reward = $data['display_reward'] ?? 'Mystery Reward';
    $valid_games = ['spin', 'scratch', 'card', 'giftbox', 'treasure', 'dailytap'];
    if (!in_array($game_type, $valid_games)) {
      echo json_encode(['success' => false, 'message' => 'Invalid game']);
      exit;
    }
    // Check game enabled
    $gs = $db->prepare("SELECT * FROM game_settings WHERE game_type=?");
    $gs->execute([$game_type]);
    $gameSettings = $gs->fetch();
    if (!$gameSettings || !$gameSettings['is_enabled']) {
      echo json_encode(['success' => false, 'message' => 'Game is not available']);
      exit;
    }
    $today = date('Y-m-d');
    // Check if already played today
    try {
      $db->prepare("INSERT INTO game_plays (customer_id,game_type,played_date) VALUES (?,?,?)")->execute([$_SESSION['customer_id'], $game_type, $today]);
    } catch (Exception $e) {
      echo json_encode(['success' => false, 'message' => 'You already played this game today! Come back tomorrow.']);
      exit;
    }
    // Update streak
    updateVisitStreak($_SESSION['customer_id'], $db);
    // Award random points 3-8 (was 5-50 — reduced to prevent free discount abuse)
    $points_awarded = rand(3, 8);
    $db->prepare("UPDATE customers SET points=points+? WHERE id=?")->execute([$points_awarded, $_SESSION['customer_id']]);
    $db->prepare("INSERT INTO point_transactions (customer_id,points,type,description) VALUES (?,?,'bonus',?)")->execute([$_SESSION['customer_id'], $points_awarded, ucfirst($game_type) . ' game bonus points']);
    // Generate reward
    $result = generateGameCoupon($_SESSION['customer_id'], $game_type, $display_reward, $db);
    $result['points_awarded'] = $points_awarded;
    echo json_encode(['success' => true, 'result' => $result]);
    exit;
  }

  if ($action === 'get_wallet' && isLoggedIn()) {
    $wallet = $db->prepare("SELECT gr.*, c.discount_value, c.min_order as coupon_min FROM game_rewards gr LEFT JOIN coupons c ON gr.actual_coupon_code=c.code WHERE gr.customer_id=? ORDER BY gr.is_used ASC, gr.played_at DESC");
    $wallet->execute([$_SESSION['customer_id']]);
    $items = $wallet->fetchAll();
    echo json_encode(['wallet' => $items]);
    exit;
  }

  if ($action === 'get_leaderboard' && isLoggedIn()) {
    $month = date('Y-m');
    // Spending leaderboard
    $spending = $db->prepare("
      SELECT c.id, c.full_name, SUM(o.total) as total_spent, COUNT(o.id) as total_orders,
        CONCAT(SUBSTRING(c.full_name,1,2),'***') as display_name
      FROM customers c
      JOIN orders o ON o.customer_id=c.id
      WHERE DATE_FORMAT(o.created_at,'%Y-%m')=?
      GROUP BY c.id ORDER BY total_spent DESC LIMIT 10
    ");
    $spending->execute([$month]);
    $spendList = $spending->fetchAll();

    // Game winners leaderboard
    $games = $db->prepare("
      SELECT c.id, c.full_name, COUNT(gr.id) as total_wins,
        CONCAT(SUBSTRING(c.full_name,1,2),'***') as display_name
      FROM customers c
      JOIN game_rewards gr ON gr.customer_id=c.id
      WHERE DATE_FORMAT(gr.played_at,'%Y-%m')=? AND gr.actual_discount > 0
      GROUP BY c.id ORDER BY total_wins DESC LIMIT 10
    ");
    $games->execute([$month]);
    $gameList = $games->fetchAll();

    // Streak leaderboard
    $streaks = $db->prepare("
      SELECT c.id, c.full_name, vs.current_streak, vs.longest_streak,
        CONCAT(SUBSTRING(c.full_name,1,2),'***') as display_name
      FROM customers c JOIN visit_streaks vs ON vs.customer_id=c.id
      ORDER BY vs.current_streak DESC LIMIT 10
    ");
    $streaks->execute();
    $streakList = $streaks->fetchAll();

    // My ranks
    $mySpendRank = 0;
    foreach ($spendList as $i => $row) {
      if ($row['id'] == $_SESSION['customer_id']) {
        $mySpendRank = $i + 1;
        break;
      }
    }
    $myGameRank = 0;
    foreach ($gameList as $i => $row) {
      if ($row['id'] == $_SESSION['customer_id']) {
        $myGameRank = $i + 1;
        break;
      }
    }
    $myStreakRank = 0;
    foreach ($streakList as $i => $row) {
      if ($row['id'] == $_SESSION['customer_id']) {
        $myStreakRank = $i + 1;
        break;
      }
    }

    echo json_encode([
      'spending' => $spendList,
      'games' => $gameList,
      'streaks' => $streakList,
      'my_spend_rank' => $mySpendRank,
      'my_game_rank' => $myGameRank,
      'my_streak_rank' => $myStreakRank,
      'month' => date('F Y')
    ]);
    exit;
  }

  if ($action === 'admin_game_settings' && isLoggedIn()) {
    // Simple admin check - in production add proper admin role
    $customer = getCustomer();
    if ($customer['mobile'] !== '9876543210') {
      echo json_encode(['error' => 'Unauthorized']);
      exit;
    }
    $data = json_decode(file_get_contents('php://input'), true);
    foreach ($data['settings'] as $game_type => $s) {
      $db->prepare("UPDATE game_settings SET is_enabled=?,chance_10pct=?,chance_20pct=?,chance_blnt=?,expiry_days=? WHERE game_type=?")
        ->execute([$s['is_enabled'], $s['chance_10pct'], $s['chance_20pct'], $s['chance_blnt'], $s['expiry_days'], $game_type]);
    }
    echo json_encode(['success' => true]);
    exit;
  }

  echo json_encode(['error' => 'Unknown action']);
  exit;
}

// Check remember me cookie
if (!isLoggedIn() && isset($_COOKIE['remember_token'])) {
  // Simple remember - in production use a tokens table
}

$customer = getCustomer();
$dark_mode = $customer ? $customer['dark_mode'] : 0;
?>
<!DOCTYPE html>
<html lang="en" <?= $dark_mode ? 'data-theme="dark"' : '' ?>>

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, viewport-fit=cover">
  <title>C3 Restaurant — Your Cafe Experience</title>
  <!-- Speed: DNS prefetch for external resources -->
  <link rel="dns-prefetch" href="https://fonts.googleapis.com">
  <link rel="dns-prefetch" href="https://images.unsplash.com">
  <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
  <noscript>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
  </noscript>
  <style>
    /* ============================================================
   CSS CUSTOM PROPERTIES
   ============================================================ */
    :root {
      --primary: #1a5c38;
      --primary-light: #2d7a4f;
      --primary-dark: #133f27;
      --primary-glow: rgba(26, 92, 56, 0.12);
      --grad: linear-gradient(135deg, #1a5c38 0%, #2d7a4f 100%);
      --grad-warm: linear-gradient(135deg, #1a5c38 0%, #2d7a4f 100%);
      --grad-soft: linear-gradient(135deg, rgba(26, 92, 56, 0.06) 0%, rgba(26, 92, 56, 0.02) 100%);
      --glass: rgba(255, 255, 255, 0.72);
      --glass-border: rgba(255, 255, 255, 0.55);
      --gold: #F59E0B;
      --silver: #94A3B8;
      --bronze: #CD7F32;
      --success: #10B981;
      --danger: #EF4444;
      --warn: #F59E0B;
      --bg: #f8f9fa;
      --bg-card: #ffffff;
      --bg-secondary: #f1f3f5;
      --text: #111827;
      --text-secondary: #6b7280;
      --text-muted: #9ca3af;
      --border: #e5e7eb;
      --border-light: #f3f4f6;
      --shadow-sm: 0 1px 4px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
      --shadow: 0 4px 16px rgba(0,0,0,0.08), 0 1px 4px rgba(0,0,0,0.04);
      --shadow-lg: 0 12px 40px rgba(0,0,0,0.1), 0 4px 12px rgba(0,0,0,0.06);
      --shadow-green: 0 4px 16px rgba(26, 92, 56, 0.2);
      --shadow-card: 0 1px 6px rgba(0,0,0,0.06), 0 1px 2px rgba(0,0,0,0.04);
      --radius-sm: 10px;
      --radius: 16px;
      --radius-lg: 22px;
      --radius-xl: 28px;
      --font-display: 'Cormorant Garamond', 'Playfair Display', Georgia, serif;
      --font-body: 'DM Sans', system-ui, sans-serif;
      --transition: all 0.22s cubic-bezier(0.4, 0, 0.2, 1);
      --transition-bounce: all 0.32s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    [data-theme="dark"] {
      --bg: #0f1117;
      --bg-card: #1a1d23;
      --bg-secondary: #22262e;
      --text: #f3f4f6;
      --text-secondary: #9ca3af;
      --text-muted: #6b7280;
      --border: #2d3139;
      --border-light: #22262e;
      --glass: rgba(15, 17, 23, 0.85);
      --glass-border: rgba(255,255,255,0.08);
      --shadow: 0 6px 24px rgba(0, 0, 0, 0.45);
      --shadow-lg: 0 20px 60px rgba(0, 0, 0, 0.55);
      --shadow-card: 0 4px 24px rgba(0, 0, 0, 0.4);
    }

    /* ============================================================
   BASE STYLES
   ============================================================ */
    *,
    *::before,
    *::after {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    html {
      scroll-behavior: smooth;
      -webkit-tap-highlight-color: transparent;
    }

    body {
      font-family: var(--font-body);
      background: var(--bg);
      background-image:
        radial-gradient(ellipse at 20% 0%, rgba(26, 92, 56, 0.04) 0%, transparent 50%),
        radial-gradient(ellipse at 80% 100%, rgba(26, 92, 56, 0.02) 0%, transparent 50%);
      background-attachment: fixed;
      color: var(--text);
      min-height: 100vh;
      font-size: 15px;
      line-height: 1.6;
      transition: background 0.3s, color 0.3s;
      overflow-x: hidden;
    }

    [data-theme="dark"] body {
      background-image:
        radial-gradient(ellipse at 20% 0%, rgba(26, 92, 56, 0.06) 0%, transparent 50%),
        radial-gradient(ellipse at 80% 100%, rgba(26, 92, 56, 0.04) 0%, transparent 50%);
    }

    img {
      max-width: 100%;
      display: block;
    }

    button {
      cursor: pointer;
      border: none;
      background: none;
      font-family: inherit;
    }

    input,
    select,
    textarea {
      font-family: inherit;
    }

    a {
      text-decoration: none;
      color: inherit;
    }

    .hidden {
      display: none !important;
    }

    .sr-only {
      position: absolute;
      width: 1px;
      height: 1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
    }

    /* ============================================================
   SCROLLBAR
   ============================================================ */
    ::-webkit-scrollbar {
      width: 6px;
      height: 6px;
    }

    ::-webkit-scrollbar-track {
      background: transparent;
    }

    ::-webkit-scrollbar-thumb {
      background: var(--border);
      border-radius: 10px;
    }

    /* ============================================================
   AUTH SCREEN — PREMIUM GLASSMORPHISM
   ============================================================ */
    #auth-screen {
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: linear-gradient(145deg, #111827 0%, #1a5c38 40%, #1f2937 100%);
      position: relative;
      overflow: hidden;
    }

    .auth-bg-art {
      position: absolute;
      inset: 0;
      pointer-events: none;
      z-index: 0;
      overflow: hidden;
    }

    .auth-orb {
      position: absolute;
      border-radius: 50%;
      filter: blur(70px);
      opacity: 0.35;
      animation: floatOrb 9s ease-in-out infinite;
    }

    .auth-orb-1 {
      width: 600px;
      height: 600px;
      background: radial-gradient(circle, #2d7a4f, #1a5c38);
      top: -200px;
      right: -150px;
      animation-delay: 0s;
    }

    .auth-orb-2 {
      width: 400px;
      height: 400px;
      background: radial-gradient(circle, #1a5c38, #133f27);
      bottom: -120px;
      left: -100px;
      animation-delay: 3.5s;
    }

    .auth-orb-3 {
      width: 250px;
      height: 250px;
      background: radial-gradient(circle, #d1d5db, #1a5c38);
      top: 45%;
      left: 25%;
      animation-delay: 6s;
      opacity: 0.2;
    }

    .auth-orb-4 {
      width: 180px;
      height: 180px;
      background: radial-gradient(circle, #2d7a4f, #1a5c38);
      top: 20%;
      left: 60%;
      animation-delay: 2s;
      opacity: 0.15;
    }

    @keyframes floatOrb {

      0%,
      100% {
        transform: translateY(0) scale(1) rotate(0deg);
      }

      33% {
        transform: translateY(-25px) scale(1.04) rotate(3deg);
      }

      66% {
        transform: translateY(15px) scale(0.97) rotate(-2deg);
      }
    }

    /* Decorative mesh lines */
    .auth-bg-art::before {
      content: '';
      position: absolute;
      inset: 0;
      background-image:
        linear-gradient(rgba(255, 255, 255, 0.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255, 255, 255, 0.03) 1px, transparent 1px);
      background-size: 60px 60px;
    }

    .auth-card {
      background: var(--bg-card);
      border-radius: 0;
      padding: 8px 28px 40px;
      width: 100%;
      max-width: 100%;
      position: relative;
      z-index: 1;
    }

    /* Drag handle at top of white card */
    .auth-card::before {
      content: '';
      display: block;
      width: 40px;
      height: 4px;
      border-radius: 2px;
      background: var(--border);
      margin: 0 auto 20px;
    }

    [data-theme="dark"] .auth-card {
      background: var(--bg-card);
    }

    .auth-logo {
      text-align: center;
      margin-bottom: 20px;
    }

    .auth-logo-mark {
      width: 56px;
      height: 56px;
      background: var(--grad);
      border-radius: 16px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 10px;
      font-size: 24px;
      box-shadow: var(--shadow-green);
      position: relative;
    }

    .auth-logo h1 {
      font-family: var(--font-display);
      font-size: 22px;
      font-weight: 700;
      background: var(--grad);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      letter-spacing: -0.5px;
    }

    .auth-logo p {
      color: var(--text-secondary);
      font-size: 13px;
      margin-top: 3px;
      font-weight: 400;
    }

    .auth-tabs {
      display: flex;
      background: var(--bg-secondary);
      border-radius: var(--radius);
      padding: 5px;
      margin-bottom: 22px;
      gap: 4px;
      border: 1px solid var(--border);
    }

    .auth-tab {
      flex: 1;
      padding: 11px 10px;
      text-align: center;
      border-radius: 11px;
      font-weight: 500;
      font-size: 14px;
      color: var(--text-secondary);
      transition: var(--transition);
      cursor: pointer;
    }

    .auth-tab.active {
      background: var(--grad);
      color: white;
      box-shadow: var(--shadow-green);
      font-weight: 600;
    }

    /* Floating label form inputs */
    .form-group {
      margin-bottom: 18px;
      position: relative;
    }

    .form-label {
      display: block;
      font-size: 12.5px;
      font-weight: 600;
      color: var(--text-secondary);
      margin-bottom: 7px;
      text-transform: uppercase;
      letter-spacing: 0.6px;
    }

    .form-input {
      width: 100%;
      padding: 13px 16px;
      background: var(--bg-secondary);
      border: 1.5px solid var(--border);
      border-radius: var(--radius-sm);
      font-size: 15px;
      color: var(--text);
      transition: var(--transition);
      outline: none;
      -webkit-appearance: none;
    }

    .form-input:focus {
      border-color: var(--primary-light);
      background: var(--bg-card);
      box-shadow: 0 0 0 4px var(--primary-glow), var(--shadow-sm);
    }

    .form-input::placeholder {
      color: var(--text-muted);
      font-weight: 400;
    }

    .form-input-icon {
      position: relative;
    }

    .form-input-icon .form-input {
      padding-left: 46px;
    }

    .form-input-icon .icon {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      font-size: 16px;
      pointer-events: none;
    }

    .remember-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 20px;
    }

    .checkbox-label {
      display: flex;
      align-items: center;
      gap: 8px;
      cursor: pointer;
      font-size: 14px;
      color: var(--text-secondary);
    }

    .checkbox-label input[type="checkbox"] {
      width: 16px;
      height: 16px;
      accent-color: var(--primary);
      cursor: pointer;
    }

    /* ============================================================
   PREMIUM BUTTONS
   ============================================================ */
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      padding: 14px 24px;
      border-radius: var(--radius-sm);
      font-size: 15px;
      font-weight: 600;
      font-family: var(--font-body);
      transition: var(--transition);
      cursor: pointer;
      border: none;
      white-space: nowrap;
      position: relative;
      overflow: hidden;
      letter-spacing: 0.2px;
    }

    .btn::before {
      content: '';
      position: absolute;
      inset: 0;
      background: rgba(255, 255, 255, 0);
      transition: background 0.2s;
    }

    .btn:hover::before {
      background: rgba(255, 255, 255, 0.1);
    }

    .btn:active {
      transform: scale(0.97);
    }

    /* Ripple effect */
    .btn .ripple {
      position: absolute;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.3);
      transform: scale(0);
      animation: rippleAnim 0.6s linear;
      pointer-events: none;
    }

    @keyframes rippleAnim {
      to {
        transform: scale(4);
        opacity: 0;
      }
    }

    .btn-primary {
      background: var(--grad);
      color: white;
      box-shadow: var(--shadow-green);
      width: 100%;
    }

    .btn-primary:hover {
      box-shadow: 0 6px 20px rgba(26, 92, 56, 0.3);
      transform: translateY(-2px);
    }

    .btn-outline {
      background: transparent;
      color: var(--primary);
      border: 1.5px solid var(--primary);
      width: 100%;
    }

    .btn-outline:hover {
      background: var(--bg-secondary);
    }

    .btn-ghost {
      background: var(--bg-secondary);
      color: var(--text);
      border: 1px solid var(--border);
    }

    .btn-ghost:hover {
      background: var(--border);
    }

    .btn-danger {
      background: linear-gradient(135deg, #EF4444, #DC2626);
      color: white;
      box-shadow: 0 4px 16px rgba(239, 68, 68, 0.25);
    }

    .btn-danger:hover {
      box-shadow: 0 8px 28px rgba(239, 68, 68, 0.35);
      transform: translateY(-1px);
    }

    .btn-success {
      background: linear-gradient(135deg, #10B981, #059669);
      color: white;
    }

    .btn-whatsapp {
      background: linear-gradient(135deg, #25D366, #128C7E);
      color: white;
      box-shadow: 0 4px 16px rgba(37, 211, 102, 0.3);
    }

    .btn-whatsapp:hover {
      box-shadow: 0 8px 28px rgba(37, 211, 102, 0.4);
      transform: translateY(-1px);
    }

    .btn-sm {
      padding: 9px 16px;
      font-size: 13px;
    }

    .btn-xs {
      padding: 5px 10px;
      font-size: 12px;
      border-radius: 8px;
    }

    .btn-icon {
      width: 40px;
      height: 40px;
      padding: 0;
      border-radius: 12px;
    }

    /* ============================================================
   APP LAYOUT
   ============================================================ */
    #app {
      display: none;
      flex-direction: column;
      min-height: 100vh;
    }

    #app.visible {
      display: flex;
    }

    /* TOP NAV — Premium glassmorphism */
    .topnav {
      position: sticky;
      top: 0;
      z-index: 100;
      background: rgba(255, 255, 255, 0.85);
      border-bottom: 1px solid var(--border);
      padding: 0 16px;
      height: 66px;
      display: flex;
      align-items: center;
      gap: 10px;
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
      box-shadow: 0 1px 8px rgba(0,0,0,0.06);
      min-width: 0;
    }

    [data-theme="dark"] .topnav {
      background: rgba(15, 17, 23, 0.92);
      border-bottom-color: var(--border);
    }

    .topnav-logo {
      display: flex;
      align-items: center;
      gap: 8px;
      min-width: 0;
      flex-shrink: 1;
    }

    .topnav-logo-mark {
      width: 40px;
      height: 40px;
      background: var(--grad);
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      box-shadow: var(--shadow-green);
      flex-shrink: 0;
      transition: var(--transition-bounce);
    }

    .topnav-logo-mark:hover {
      transform: scale(1.08) rotate(-3deg);
    }

    .topnav-brand {
      font-family: var(--font-display);
      font-weight: 700;
      font-size: 20px;
      background: var(--grad);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      letter-spacing: -0.3px;
    }

    .topnav-spacer {
      flex: 1;
    }

    .topnav-actions {
      display: flex;
      align-items: center;
      gap: 6px;
      flex-shrink: 0;
    }

    .nav-icon-btn {
      width: 42px;
      height: 42px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: var(--bg-secondary);
      color: var(--text-secondary);
      font-size: 17px;
      transition: var(--transition-bounce);
      cursor: pointer;
      position: relative;
      border: 1px solid var(--border);
    }

    .nav-icon-btn:hover {
      background: var(--primary-glow);
      color: var(--primary);
      border-color: var(--primary-light);
      transform: scale(1.05);
    }

    .nav-icon-btn .badge {
      position: absolute;
      top: -5px;
      right: -5px;
      min-width: 19px;
      height: 19px;
      border-radius: 10px;
      background: var(--grad);
      color: white;
      font-size: 10px;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0 4px;
      border: 2px solid var(--bg-card);
      box-shadow: var(--shadow-green);
    }

    /* BOTTOM NAV — Premium floating style */
    .bottomnav {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      z-index: 100;
      background: rgba(255, 255, 255, 0.92);
      border-top: 1px solid var(--border);
      display: flex;
      padding-bottom: env(safe-area-inset-bottom);
      box-shadow: 0 -2px 12px rgba(0,0,0,0.06);
      backdrop-filter: blur(20px);
      -webkit-backdrop-filter: blur(20px);
    }

    [data-theme="dark"] .bottomnav {
      background: rgba(15, 17, 23, 0.92);
    }

    .bottomnav-item {
      flex: 1;
      display: flex;
      flex-direction: column;
      align-items: center;
      padding: 10px 4px 9px;
      cursor: pointer;
      transition: var(--transition);
      font-size: 10.5px;
      font-weight: 500;
      color: var(--text-muted);
      -webkit-tap-highlight-color: transparent;
      position: relative;
    }

    .bottomnav-item .nav-icon {
      font-size: 21px;
      margin-bottom: 3px;
      transition: transform 0.25s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    .bottomnav-item.active {
      color: var(--primary);
    }

    .bottomnav-item.active .nav-icon {
      transform: translateY(-3px) scale(1.12);
    }

    .bottomnav-item.active::before {
      content: '';
      position: absolute;
      top: 0;
      left: 50%;
      transform: translateX(-50%);
      width: 36px;
      height: 3px;
      border-radius: 0 0 4px 4px;
      background: var(--grad);
      box-shadow: 0 2px 8px var(--primary-glow);
    }

    .bottomnav-item .bnav-badge {
      position: absolute;
      top: 6px;
      right: calc(50% - 22px);
      min-width: 17px;
      height: 17px;
      border-radius: 9px;
      background: var(--grad);
      color: white;
      font-size: 9px;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0 3px;
      box-shadow: var(--shadow-green);
    }

    /* MAIN CONTENT */
    .main-content {
      flex: 1;
      padding: 20px 18px 104px;
      max-width: 500px;
      margin: 0 auto;
      width: 100%;
    }

    /* ============================================================
   PAGES / TABS
   ============================================================ */
    .page {
      display: none;
      animation: pageIn 0.3s ease;
    }

    .page.active {
      display: block;
    }

    @keyframes pageIn {
      from {
        opacity: 0;
        transform: translateY(10px);
      }

      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    /* ============================================================
   PREMIUM CARDS
   ============================================================ */
    .card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow-card);
      overflow: hidden;
      transition: var(--transition);
    }

    .card:hover {
      box-shadow: var(--shadow);
    }

    .card-pad {
      padding: 20px;
     
    }

    .card-header {
      padding: 16px 20px;
      border-bottom: 1px solid var(--border-light);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .card-title {
      font-weight: 700;
      font-size: 15px;
      letter-spacing: -0.2px;
    }

    /* Glass card variant */
    .card-glass {
      background: rgba(255, 255, 255, 0.7);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid var(--glass-border);
    }

    [data-theme="dark"] .card-glass {
      background: rgba(15, 17, 23, 0.7);
    }

    /* ============================================================
   DASHBOARD — Premium Welcome Banner
   ============================================================ */
    .welcome-banner {
      background: var(--grad);
      border-radius: var(--radius-lg);
      padding: 26px 24px;
      color: white;
      margin-bottom: 20px;
      position: relative;
      overflow: hidden;
      box-shadow: var(--shadow-green);
    }

    .welcome-banner::before {
      content: '☕';
      position: absolute;
      right: -14px;
      top: -24px;
      font-size: 130px;
      opacity: 0.07;
      transform: rotate(-12deg);
    }

    .welcome-banner::after {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(135deg, rgba(255, 255, 255, 0.08) 0%, transparent 60%);
      pointer-events: none;
    }

    .welcome-greeting {
      font-size: 12px;
      opacity: 0.75;
      margin-bottom: 3px;
      text-transform: uppercase;
      letter-spacing: 1px;
      font-weight: 600;
    }

    .welcome-name {
      font-family: var(--font-display);
      font-size: 26px;
      font-weight: 700;
      margin-bottom: 3px;
      letter-spacing: -0.5px;
    }

    .welcome-sub {
      font-size: 12.5px;
      opacity: 0.72;
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 12px;
      margin-bottom: 20px;
    }

    .stat-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 18px 16px;
      box-shadow: var(--shadow-card);
      transition: var(--transition-bounce);
      position: relative;
      overflow: hidden;
    }

    .stat-card::before {
      content: '';
      position: absolute;
      top: 0;
      right: 0;
      width: 60px;
      height: 60px;
      border-radius: 0 0 0 100%;
      background: var(--grad-soft);
    }

    .stat-card:hover {
      transform: translateY(-3px);
      box-shadow: var(--shadow);
    }

    .stat-icon {
      font-size: 24px;
      margin-bottom: 10px;
    }

    .stat-value {
      font-size: 26px;
      font-weight: 800;
      line-height: 1;
      margin-bottom: 4px;
      letter-spacing: -1px;
    }

    .stat-label {
      font-size: 11.5px;
      color: var(--text-secondary);
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.4px;
    }

    .stat-value.points {
      background: var(--grad);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
    }

    .membership-badge {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 5px 10px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 700;
      margin-top: 8px;
      letter-spacing: 0.3px;
    }

    .badge-bronze {
      background: rgba(205, 127, 50, 0.12);
      color: var(--bronze);
      border: 1px solid rgba(205, 127, 50, 0.2);
    }

    .badge-silver {
      background: rgba(148, 163, 184, 0.12);
      color: var(--silver);
      border: 1px solid rgba(148, 163, 184, 0.2);
    }

    .badge-gold {
      background: rgba(245, 158, 11, 0.12);
      color: var(--gold);
      border: 1px solid rgba(245, 158, 11, 0.2);
    }

    .section-title {
      font-size: 17px;
      font-weight: 700;
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      letter-spacing: -0.3px;
    }

    .section-title .see-all {
      font-size: 12.5px;
      font-weight: 600;
      color: var(--primary);
      cursor: pointer;
      padding: 4px 10px;
      background: var(--bg-secondary);
      border-radius: 20px;
      border: 1px solid var(--border);
      transition: var(--transition);
    }

    .section-title .see-all:hover {
      background: var(--primary-glow);
      border-color: var(--primary-light);
    }

    .progress-bar-wrap {
      background: var(--bg-secondary);
      border-radius: 10px;
      height: 8px;
      overflow: hidden;
      margin: 8px 0;
      border: 1px solid var(--border);
    }

    .progress-bar-fill {
      height: 100%;
      border-radius: 10px;
      background: var(--grad);
      transition: width 1s cubic-bezier(0.34, 1.56, 0.64, 1);
      box-shadow: 0 0 8px var(--primary-glow);
    }

    .reward-cards-row {
      display: flex;
      gap: 12px;
      overflow-x: auto;
      padding-bottom: 6px;
      scrollbar-width: none;
    }

    .reward-cards-row::-webkit-scrollbar {
      display: none;
    }

    .reward-card-mini {
      flex-shrink: 0;
      width: 148px;
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 16px;
      cursor: pointer;
      transition: var(--transition-bounce);
      box-shadow: var(--shadow-card);
    }

    .reward-card-mini:hover {
      transform: scale(1.04) translateY(-2px);
      box-shadow: var(--shadow);
      border-color: var(--primary-light);
    }

    .reward-card-mini.locked {
      opacity: 0.6;
      cursor: default;
    }

    .reward-card-mini.locked:hover {
      transform: none;
      box-shadow: var(--shadow-card);
      border-color: var(--border);
    }

    .reward-card-mini .r-icon {
      font-size: 26px;
      margin-bottom: 8px;
    }

    .reward-card-mini .r-name {
      font-size: 13px;
      font-weight: 700;
      margin-bottom: 3px;
      letter-spacing: -0.2px;
    }

    .reward-card-mini .r-pts {
      font-size: 11px;
      color: var(--text-secondary);
      font-weight: 500;
    }

    .reward-card-mini .r-status {
      font-size: 10px;
      font-weight: 700;
      margin-top: 10px;
      padding: 4px 10px;
      border-radius: 12px;
      display: inline-block;
      letter-spacing: 0.3px;
    }

    .r-status.available {
      background: rgba(16, 185, 129, 0.12);
      color: var(--success);
      border: 1px solid rgba(16, 185, 129, 0.2);
    }

    .r-status.locked {
      background: var(--bg-secondary);
      color: var(--text-muted);
      border: 1px solid var(--border);
    }

    /* Birthday banner */
    .birthday-banner {
      background: linear-gradient(135deg, #F59E0B, #EF4444, #EC4899);
      border-radius: var(--radius);
      padding: 18px 20px;
      color: white;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      gap: 14px;
      animation: birthdayPulse 2.5s ease-in-out infinite;
      box-shadow: 0 8px 32px rgba(245, 158, 11, 0.3);
    }

    @keyframes birthdayPulse {

      0%,
      100% {
        box-shadow: 0 8px 32px rgba(245, 158, 11, 0.3);
      }

      50% {
        box-shadow: 0 8px 48px rgba(245, 158, 11, 0.5);
      }
    }

    .birthday-icon {
      font-size: 36px;
    }

    .birthday-text h3 {
      font-weight: 700;
      font-size: 15px;
    }

    .birthday-text p {
      font-size: 12px;
      opacity: 0.9;
      margin-top: 2px;
    }

    /* Order history */
    .order-item {
      padding: 14px 0;
      border-bottom: 1px solid var(--border-light);
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .order-item:last-child {
      border-bottom: none;
    }

    .order-icon {
      width: 42px;
      height: 42px;
      border-radius: 14px;
      background: var(--bg-secondary);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      flex-shrink: 0;
      border: 1px solid var(--border);
    }

    .order-info {
      flex: 1;
      min-width: 0;
    }

    .order-num {
      font-weight: 700;
      font-size: 14px;
      letter-spacing: -0.2px;
    }

    .order-date {
      font-size: 12px;
      color: var(--text-muted);
      margin-top: 1px;
    }

    .order-right {
      text-align: right;
      flex-shrink: 0;
    }

    .order-total {
      font-weight: 800;
      font-size: 15px;
    }

    .order-status {
      font-size: 10.5px;
      font-weight: 700;
      padding: 2px 8px;
      border-radius: 8px;
      margin-top: 3px;
      display: inline-block;
      letter-spacing: 0.3px;
      text-transform: capitalize;
    }

    .status-pending {
      background: rgba(245, 158, 11, 0.12);
      color: var(--gold);
    }

    .status-confirmed {
      background: rgba(79, 70, 229, 0.12);
      color: #4F46E5;
    }

    .status-preparing {
      background: rgba(245, 158, 11, 0.12);
      color: var(--gold);
    }

    .status-ready {
      background: rgba(16, 185, 129, 0.12);
      color: var(--success);
    }

    .status-completed {
      background: rgba(16, 185, 129, 0.08);
      color: var(--success);
    }

    .status-cancelled {
      background: rgba(239, 68, 68, 0.08);
      color: var(--danger);
    }

    /* Recommended items */
    .rec-items-row {
      display: flex;
      gap: 12px;
      overflow-x: auto;
      padding-bottom: 6px;
      scrollbar-width: none;
    }

    .rec-items-row::-webkit-scrollbar {
      display: none;
    }

    .rec-item-card {
      flex-shrink: 0;
      width: 155px;
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
      transition: var(--transition-bounce);
      cursor: pointer;
      box-shadow: var(--shadow-card);
    }

    .rec-item-card:hover {
      transform: translateY(-4px);
      box-shadow: var(--shadow);
      border-color: var(--primary-light);
    }

    .rec-item-img {
      width: 100%;
      height: 110px;
      object-fit: cover;
      transition: transform 0.4s ease;
    }

    .rec-item-card:hover .rec-item-img {
      transform: scale(1.06);
    }

    .rec-item-body {
      padding: 10px 12px 12px;
    }

    .rec-item-name {
      font-size: 12.5px;
      font-weight: 700;
      margin-bottom: 3px;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
      letter-spacing: -0.2px;
    }

    .rec-item-price {
      font-size: 14px;
      font-weight: 800;
      color: var(--primary);
    }

    /* ============================================================
   MENU
   ============================================================ */
    .search-bar-wrap {
      position: relative;
      margin-bottom: 16px;
    }

    .search-bar-wrap .search-icon {
      position: absolute;
      left: 14px;
      top: 50%;
      transform: translateY(-50%);
      font-size: 16px;
      color: var(--text-muted);
      pointer-events: none;
    }

    .search-bar {
      width: 100%;
      padding: 12px 16px 12px 44px;
      background: var(--bg-card);
      border: 1.5px solid var(--border);
      border-radius: var(--radius-sm);
      font-size: 15px;
      color: var(--text);
      outline: none;
      transition: var(--transition);
    }

    .search-bar:focus {
      border-color: var(--primary-light);
      box-shadow: 0 0 0 3px var(--primary-glow);
    }

    .category-tabs {
      display: flex;
      gap: 8px;
      overflow-x: auto;
      padding-bottom: 2px;
      scrollbar-width: none;
      margin-bottom: 20px;
      position: sticky;
      top: 64px;
      z-index: 50;
      background: var(--bg);
      padding-top: 12px;
      padding-bottom: 8px;
      margin-top: -8px;
    }

    .category-tabs::-webkit-scrollbar {
      display: none;
    }

    .cat-tab {
      flex-shrink: 0;
      padding: 8px 14px;
      background: var(--bg-card);
      border: 1.5px solid var(--border);
      border-radius: 20px;
      font-size: 13px;
      font-weight: 500;
      color: var(--text-secondary);
      cursor: pointer;
      transition: var(--transition);
      white-space: nowrap;
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .cat-tab.active {
      background: var(--primary);
      border-color: var(--primary);
      color: white;
      box-shadow: var(--shadow-green);
    }

    .cat-tab:hover:not(.active) {
      border-color: var(--primary);
      color: var(--primary);
    }

    .menu-section {
      margin-bottom: 28px;
    }

    .menu-section-title {
      font-family: var(--font-display);
      font-size: 18px;
      font-weight: 700;
      margin-bottom: 14px;
      padding-left: 4px;
      color: var(--text);
    }

    .menu-item-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      overflow: hidden;
      display: flex;
      gap: 0;
      margin-bottom: 12px;
      transition: var(--transition-bounce);
      box-shadow: var(--shadow-sm);
    }

    .menu-item-card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow);
    }

    .menu-item-img {
      width: 100px;
      height: 100px;
      object-fit: cover;
      flex-shrink: 0;
    }

    .menu-item-img-placeholder {
      width: 100px;
      height: 100px;
      background: var(--bg-secondary);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 32px;
      flex-shrink: 0;
    }

    .menu-item-body {
      flex: 1;
      padding: 12px 14px;
      display: flex;
      flex-direction: column;
      min-width: 0;
    }

    .menu-item-name {
      font-weight: 600;
      font-size: 14px;
      margin-bottom: 3px;
    }

    .menu-item-desc {
      font-size: 12px;
      color: var(--text-secondary);
      line-height: 1.4;
      flex: 1;
      overflow: hidden;
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
    }

    .menu-item-footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-top: 8px;
    }

    .menu-item-price {
      font-weight: 700;
      font-size: 16px;
      color: var(--primary);
    }

    .bestseller-badge {
      display: inline-flex;
      align-items: center;
      gap: 3px;
      background: rgba(245, 158, 11, 0.12);
      color: var(--gold);
      border: 1px solid rgba(245, 158, 11, 0.3);
      padding: 2px 7px;
      border-radius: 6px;
      font-size: 10px;
      font-weight: 700;
      margin-bottom: 4px;
    }

    .add-btn {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      background: var(--grad);
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      font-weight: 300;
      box-shadow: var(--shadow-green);
      transition: var(--transition-bounce);
      flex-shrink: 0;
    }

    .add-btn:hover {
      transform: scale(1.15);
      box-shadow: 0 4px 20px rgba(107, 33, 168, 0.4);
    }

    .add-btn:active {
      transform: scale(0.95);
    }

    .qty-control {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .qty-btn {
      width: 28px;
      height: 28px;
      border-radius: 7px;
      background: var(--bg-secondary);
      border: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      transition: var(--transition);
    }

    .qty-btn:hover {
      background: var(--primary);
      color: white;
      border-color: var(--primary);
    }

    .qty-num {
      font-weight: 700;
      font-size: 15px;
      min-width: 20px;
      text-align: center;
    }

    /* Combo Cards */
    .combo-card {
      border-radius: var(--radius-lg);
      overflow: hidden;
      position: relative;
      margin-bottom: 14px;
      box-shadow: var(--shadow);
    }

    .combo-card-img {
      width: 100%;
      height: 160px;
      object-fit: cover;
    }

    .combo-card-overlay {
      position: absolute;
      inset: 0;
      background: linear-gradient(to top, rgba(0, 0, 0, 0.75) 0%, transparent 50%);
      padding: 16px;
      display: flex;
      flex-direction: column;
      justify-content: flex-end;
    }

    .combo-name {
      color: white;
      font-family: var(--font-display);
      font-size: 18px;
      font-weight: 700;
    }

    .combo-desc {
      color: rgba(255, 255, 255, 0.8);
      font-size: 12px;
      margin: 2px 0 8px;
    }

    .combo-prices {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .combo-original {
      color: rgba(255, 255, 255, 0.6);
      text-decoration: line-through;
      font-size: 14px;
    }

    .combo-price {
      color: var(--gold);
      font-size: 20px;
      font-weight: 700;
    }

    .combo-save {
      background: var(--gold);
      color: #000;
      padding: 2px 8px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 700;
    }

    /* ============================================================
   CART
   ============================================================ */
    .cart-empty {
      text-align: center;
      padding: 60px 20px;
    }

    .cart-empty-icon {
      font-size: 64px;
      margin-bottom: 16px;
      opacity: 0.5;
    }

    .cart-empty h3 {
      font-size: 18px;
      font-weight: 700;
      margin-bottom: 8px;
    }

    .cart-empty p {
      color: var(--text-secondary);
      font-size: 14px;
    }

    .cart-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 14px 0;
      border-bottom: 1px solid var(--border-light);
    }

    .cart-item:last-child {
      border-bottom: none;
    }

    .cart-item-img {
      width: 56px;
      height: 56px;
      border-radius: 10px;
      object-fit: cover;
      flex-shrink: 0;
    }

    .cart-item-info {
      flex: 1;
      min-width: 0;
    }

    .cart-item-name {
      font-weight: 600;
      font-size: 14px;
    }

    .cart-item-price {
      color: var(--primary);
      font-weight: 700;
      font-size: 14px;
    }

    .cart-item-remove {
      color: var(--danger);
      font-size: 16px;
      cursor: pointer;
      padding: 6px;
    }

    .order-summary-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px 0;
      font-size: 14px;
    }

    .order-summary-row.total {
      font-weight: 700;
      font-size: 17px;
      border-top: 1px solid var(--border);
      padding-top: 14px;
      margin-top: 4px;
    }

    .order-summary-row.discount {
      color: var(--success);
    }

    .order-summary-row.points {
      color: var(--primary);
    }

    .coupon-row {
      display: flex;
      gap: 8px;
      margin: 12px 0;
    }

    .coupon-row .form-input {
      flex: 1;
    }

    .order-type-toggle {
      display: flex;
      gap: 8px;
      margin-bottom: 16px;
    }

    .order-type-btn {
      flex: 1;
      padding: 10px;
      border-radius: var(--radius-sm);
      border: 1.5px solid var(--border);
      font-size: 14px;
      font-weight: 500;
      color: var(--text-secondary);
      background: var(--bg-card);
      transition: var(--transition);
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }

    .order-type-btn.active {
      border-color: var(--primary);
      color: var(--primary);
      background: rgba(107, 33, 168, 0.06);
    }

    .points-slider-wrap {
      background: var(--bg-secondary);
      border-radius: var(--radius-sm);
      padding: 14px;
      margin: 12px 0;
    }

    .points-slider {
      width: 100%;
      accent-color: var(--primary);
      cursor: pointer;
    }

    /* ============================================================
   LOYALTY
   ============================================================ */
    .loyalty-hero {
      background: var(--grad);
      border-radius: var(--radius-lg);
      padding: 28px;
      color: white;
      text-align: center;
      margin-bottom: 20px;
    }

    .loyalty-points-big {
      font-size: 52px;
      font-weight: 800;
      line-height: 1;
      font-family: var(--font-display);
    }

    .loyalty-label {
      opacity: 0.8;
      font-size: 14px;
      margin-top: 4px;
    }

    .tier-info {
      display: flex;
      gap: 8px;
      justify-content: center;
      margin-top: 16px;
      flex-wrap: wrap;
    }

    .tier-badge {
      background: rgba(255, 255, 255, 0.15);
      backdrop-filter: blur(8px);
      border: 1px solid rgba(255, 255, 255, 0.25);
      padding: 6px 14px;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 600;
      color: white;
    }

    .transaction-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 0;
      border-bottom: 1px solid var(--border-light);
    }

    .transaction-item:last-child {
      border-bottom: none;
    }

    .txn-icon {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      flex-shrink: 0;
    }

    .txn-earn {
      background: rgba(16, 185, 129, 0.1);
    }

    .txn-redeem {
      background: rgba(239, 68, 68, 0.1);
    }

    .txn-bonus {
      background: rgba(245, 158, 11, 0.1);
    }

    .txn-birthday {
      background: rgba(236, 72, 153, 0.1);
    }

    .txn-referral {
      background: rgba(79, 70, 229, 0.1);
    }

    .txn-info {
      flex: 1;
      min-width: 0;
    }

    .txn-desc {
      font-size: 13px;
      font-weight: 500;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .txn-date {
      font-size: 11px;
      color: var(--text-muted);
    }

    .txn-pts {
      font-weight: 700;
      font-size: 15px;
    }

    .txn-pts.positive {
      color: var(--success);
    }

    .txn-pts.negative {
      color: var(--danger);
    }

    .referral-card {
      background: var(--grad-soft);
      border: 1px dashed var(--primary);
      border-radius: var(--radius);
      padding: 18px;
      text-align: center;
      margin-top: 12px;
    }

    .referral-code {
      font-size: 24px;
      font-weight: 800;
      letter-spacing: 4px;
      color: var(--primary);
      font-family: var(--font-display);
      margin: 8px 0;
    }

    /* ============================================================
   PROFILE
   ============================================================ */
    .profile-hero {
      text-align: center;
      padding: 28px 20px;
      margin-bottom: 20px;
    }

    .profile-avatar {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      background: var(--grad);
      color: white;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 32px;
      font-weight: 700;
      font-family: var(--font-display);
      margin: 0 auto 14px;
      box-shadow: var(--shadow-green);
    }

    .profile-name {
      font-family: var(--font-display);
      font-size: 22px;
      font-weight: 700;
    }

    .profile-mobile {
      color: var(--text-secondary);
      font-size: 14px;
      margin-top: 2px;
    }

    .setting-row {
      display: flex;
      align-items: center;
      gap: 14px;
      padding: 15px 0;
      border-bottom: 1px solid var(--border-light);
      cursor: pointer;
      transition: var(--transition);
    }

    .setting-row:last-child {
      border-bottom: none;
    }

    .setting-row:hover {
      color: var(--primary);
    }

    .setting-icon {
      width: 38px;
      height: 38px;
      border-radius: 10px;
      background: var(--bg-secondary);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      flex-shrink: 0;
    }

    .setting-label {
      flex: 1;
      font-weight: 500;
      font-size: 14px;
    }

    .setting-arrow {
      color: var(--text-muted);
      font-size: 14px;
    }

    /* Toggle switch */
    .toggle-switch {
      position: relative;
      width: 46px;
      height: 26px;
    }

    .toggle-switch input {
      opacity: 0;
      width: 0;
      height: 0;
    }

    .toggle-knob {
      position: absolute;
      inset: 0;
      border-radius: 13px;
      background: var(--border);
      cursor: pointer;
      transition: var(--transition);
    }

    .toggle-knob::before {
      content: '';
      position: absolute;
      height: 20px;
      width: 20px;
      left: 3px;
      bottom: 3px;
      border-radius: 50%;
      background: white;
      transition: var(--transition);
      box-shadow: var(--shadow-sm);
    }

    input:checked+.toggle-knob {
      background: var(--primary);
    }

    input:checked+.toggle-knob::before {
      transform: translateX(20px);
    }

    /* ============================================================
   MODALS
   ============================================================ */
    .modal-overlay {
      position: fixed;
      inset: 0;
      z-index: 200;
      background: rgba(0, 0, 0, 0.5);
      backdrop-filter: blur(4px);
      display: flex;
      align-items: flex-end;
      justify-content: center;
      animation: modalFadeIn 0.2s ease;
    }

    .modal-overlay.centered {
      align-items: center;
      padding: 20px;
    }

    @keyframes modalFadeIn {
      from {
        opacity: 0;
      }

      to {
        opacity: 1;
      }
    }

    .modal-sheet {
      background: var(--bg-card);
      border-radius: var(--radius-xl) var(--radius-xl) 0 0;
      padding: 0;
      width: 100%;
      max-width: 480px;
      max-height: 90vh;
      overflow-y: auto;
      animation: slideUpModal 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    .modal-dialog {
      background: var(--bg-card);
      border-radius: var(--radius-xl);
      padding: 28px;
      width: 100%;
      max-width: 380px;
      animation: scaleIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    @keyframes slideUpModal {
      from {
        transform: translateY(100%);
      }

      to {
        transform: translateY(0);
      }
    }

    @keyframes scaleIn {
      from {
        transform: scale(0.85);
        opacity: 0;
      }

      to {
        transform: scale(1);
        opacity: 1;
      }
    }

    .modal-handle {
      width: 36px;
      height: 4px;
      border-radius: 2px;
      background: var(--border);
      margin: 14px auto;
    }

    .modal-header {
      padding: 16px 24px;
      border-bottom: 1px solid var(--border);
      font-size: 17px;
      font-weight: 700;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .modal-body {
      padding: 20px 24px;
    }

    .modal-close {
      width: 32px;
      height: 32px;
      border-radius: 8px;
      background: var(--bg-secondary);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-size: 16px;
    }

    [data-theme="dark"] .toast {
      background: var(--bg-card);
      border-color: var(--border);
    }

    [data-theme="dark"] .modal-sheet,
    [data-theme="dark"] .modal-dialog {
      background: var(--bg-card);
    }

    [data-theme="dark"] .modal-close {
      background: var(--bg-secondary);
      color: var(--text);
    }

    /* ============================================================
   TOAST NOTIFICATIONS
   ============================================================ */
    #toast-container {
      position: fixed;
      bottom: 90px;
      left: 50%;
      transform: translateX(-50%);
      z-index: 9999;
      display: flex;
      flex-direction: column;
      gap: 8px;
      width: calc(100% - 32px);
      max-width: 400px;
      pointer-events: none;
    }

    .toast {
      display: flex;
      align-items: center;
      gap: 12px;
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 12px 16px;
      box-shadow: var(--shadow-lg);
      animation: toastIn 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
      pointer-events: all;
    }

    .toast.removing {
      animation: toastOut 0.3s ease forwards;
    }

    .toast-icon {
      font-size: 20px;
      flex-shrink: 0;
    }

    .toast-msg {
      flex: 1;
      font-size: 14px;
      font-weight: 500;
    }

    .toast.success {
      border-left: 3px solid var(--success);
    }

    .toast.error {
      border-left: 3px solid var(--danger);
    }

    .toast.info {
      border-left: 3px solid var(--primary);
    }

    .toast.warning {
      border-left: 3px solid var(--gold);
    }

    @keyframes toastIn {
      from {
        opacity: 0;
        transform: translateY(20px) scale(0.9);
      }

      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }

    @keyframes toastOut {
      to {
        opacity: 0;
        transform: translateY(-10px) scale(0.95);
      }
    }

    /* ============================================================
   LOADING STATES
   ============================================================ */
    .skeleton {
      background: linear-gradient(90deg, var(--bg-secondary) 25%, var(--border-light) 50%, var(--bg-secondary) 75%);
      background-size: 400% 100%;
      animation: shimmer 1.4s infinite;
      border-radius: var(--radius-sm);
    }

    @keyframes shimmer {
      0% {
        background-position: 200% 0;
      }

      100% {
        background-position: -200% 0;
      }
    }

    .loading-spinner {
      display: inline-block;
      width: 20px;
      height: 20px;
      border: 2px solid rgba(255, 255, 255, 0.3);
      border-top-color: white;
      border-radius: 50%;
      animation: spin 0.7s linear infinite;
    }

    @keyframes spin {
      to {
        transform: rotate(360deg);
      }
    }

    .page-loader {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 60px 20px;
      gap: 16px;
    }

    .page-loader .spinner {
      width: 36px;
      height: 36px;
      border-width: 3px;
      border-color: var(--border);
      border-top-color: var(--primary);
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
    }

    .page-loader p {
      color: var(--text-muted);
      font-size: 14px;
    }

    /* ============================================================
   UTILITY CLASSES
   ============================================================ */
    .text-center {
      text-align: center;
    }

    .text-sm {
      font-size: 13px;
    }

    .text-muted {
      color: var(--text-secondary);
    }

    .mt-4 {
      margin-top: 4px;
    }

    .mt-8 {
      margin-top: 8px;
    }

    .mt-12 {
      margin-top: 12px;
    }

    .mt-16 {
      margin-top: 16px;
    }

    .mt-20 {
      margin-top: 20px;
    }

    .mb-8 {
      margin-bottom: 8px;
    }

    .mb-12 {
      margin-bottom: 12px;
    }

    .mb-16 {
      margin-bottom: 16px;
    }

    .mb-20 {
      margin-bottom: 20px;
    }

    .flex {
      display: flex;
    }

    .flex-col {
      flex-direction: column;
    }

    .items-center {
      align-items: center;
    }

    .justify-between {
      justify-content: space-between;
    }

    .gap-8 {
      gap: 8px;
    }

    .gap-12 {
      gap: 12px;
    }

    .w-full {
      width: 100%;
    }

    .font-bold {
      font-weight: 700;
    }

    /* ============================================================
   DIVIDER
   ============================================================ */
    .divider {
      height: 1px;
      background: var(--border);
      margin: 16px 0;
    }

    .divider-text {
      display: flex;
      align-items: center;
      gap: 12px;
      color: var(--text-muted);
      font-size: 12px;
      margin: 20px 0;
    }

    .divider-text::before,
    .divider-text::after {
      content: '';
      flex: 1;
      height: 1px;
      background: var(--border);
    }

    /* ============================================================
   RESPONSIVE — Mobile-first fixes
   ============================================================ */

    /* Ensure body never overflows horizontally */
    html,
    body {
      max-width: 100%;
      overflow-x: hidden;
    }

    /* Topnav: collapse brand text on very small screens */
    @media (max-width: 400px) {
      .topnav {
        padding: 0 12px;
        gap: 8px;
      }

      .topnav-brand {
        font-size: 16px;
      }

      .topnav-logo-mark {
        width: 34px;
        height: 34px;
        font-size: 15px;
      }

      .topnav-actions {
        gap: 5px;
      }

      .btn-rewards-topnav {
        font-size: 11px;
        padding: 7px 9px;
      }

      .points-chip {
        font-size: 11px;
        padding: 7px 9px;
      }

      .nav-icon-btn {
        width: 36px;
        height: 36px;
        font-size: 15px;
      }
    }

    /* ============================================================
       MOBILE: Move cart + dark mode to bottom-left floating cluster
       ============================================================ */
    @media (max-width: 600px) {
      /* Hide cart & dark-mode from top nav on mobile */
      #dark-mode-btn,
      #cart-nav-btn {
        display: none !important;
      }

      /* Bottom-left floating button cluster */
      .mobile-bottom-left-btns {
        position: fixed;
        bottom: 80px; /* sits just above the bottom nav */
        left: 14px;
        z-index: 500;
        display: flex;
        flex-direction: column;
        gap: 10px;
        align-items: center;
      }

      .mobile-float-btn {
        width: 48px;
        height: 48px;
        border-radius: 14px;
        background: var(--bg-card);
        border: 1px solid var(--border);
        box-shadow: 0 4px 16px rgba(0,0,0,0.14);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        cursor: pointer;
        position: relative;
        transition: transform 0.18s ease, box-shadow 0.18s ease;
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
      }

      .mobile-float-btn:active {
        transform: scale(0.92);
        box-shadow: 0 2px 8px rgba(0,0,0,0.10);
      }

      .mobile-float-btn .badge {
        position: absolute;
        top: -5px;
        right: -5px;
        min-width: 19px;
        height: 19px;
        border-radius: 10px;
        background: var(--grad);
        color: white;
        font-size: 10px;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 0 4px;
        border: 2px solid var(--bg-card);
        box-shadow: var(--shadow-green);
      }
    }

    /* Hide the floating cluster on desktop — it's only for mobile */
    @media (min-width: 601px) {
      .mobile-bottom-left-btns {
        display: none !important;
      }
    }

    /* Auth card responsive */
    @media (max-width: 480px) {
      .auth-card {
        padding: 28px 20px;
        border-radius: var(--radius-lg) var(--radius-lg) 0 0;
      }

      .auth-logo h1 {
        font-size: 22px;
      }

      .auth-logo-mark {
        width: 60px;
        height: 60px;
        font-size: 24px;
      }
    }

    @media (max-width: 360px) {
      .auth-card {
        padding: 22px 16px;
      }

      .main-content {
        padding: 14px 12px 100px;
      }

      .stats-grid {
        gap: 8px;
      }

      .stat-value {
        font-size: 20px;
      }

      .stat-card {
        padding: 14px 12px;
      }

      .welcome-name {
        font-size: 22px;
      }

      .welcome-banner {
        padding: 20px 18px;
      }

      .games-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 8px;
      }

      .game-card {
        padding: 14px 10px 10px;
      }

      .game-card-icon {
        font-size: 28px;
      }
    }

    /* General small screen tweaks */
    @media (max-width: 390px) {
      .main-content {
        padding: 16px 14px 100px;
      }

      .stats-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 10px;
      }

      .stat-value {
        font-size: 22px;
      }

      .section-title {
        font-size: 15px;
      }

      .reward-card-mini {
        width: 136px;
      }

      .rec-item-card {
        width: 140px;
      }

      .rec-item-img {
        height: 95px;
      }
    }

    /* Tablet/Desktop: wider layout */
    @media (min-width: 600px) {
      .main-content {
        max-width: 560px;
        padding: 24px 24px 110px;
      }

      .auth-card {
        border-radius: var(--radius-xl);
      }

      .auth-modal-inner {
        border-radius: var(--radius-xl);
      }

      .games-grid {
        grid-template-columns: repeat(3, 1fr);
      }

      .stats-grid {
        grid-template-columns: repeat(4, 1fr);
      }
    }

    /* Fix for modal bottom sheet on large screens */
    @media (min-width: 600px) {
      .modal-sheet {
        border-radius: var(--radius-xl);
        margin-bottom: 20px;
      }
    }

    /* ============================================================
   GAMES PAGE
   ============================================================ */
    .games-header {
      background: var(--grad);
      border-radius: var(--radius-lg);
      padding: 20px;
      margin-bottom: 16px;
      color: white;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .games-header-title {
      font-size: 20px;
      font-weight: 700;
    }

    .games-header-sub {
      font-size: 13px;
      opacity: 0.8;
      margin-top: 2px;
    }

    .streak-badge {
      background: rgba(255, 255, 255, 0.18);
      border-radius: var(--radius);
      padding: 8px 14px;
      text-align: center;
    }

    .streak-badge .streak-num {
      font-size: 22px;
      font-weight: 700;
      line-height: 1;
    }

    .streak-badge .streak-lbl {
      font-size: 10px;
      opacity: 0.85;
      margin-top: 2px;
    }

    .games-tabs {
      display: flex;
      gap: 8px;
      margin-bottom: 16px;
      background: var(--bg-secondary);
      border-radius: var(--radius);
      padding: 4px;
    }

    .games-tab {
      flex: 1;
      text-align: center;
      padding: 8px 4px;
      border-radius: calc(var(--radius) - 4px);
      font-size: 13px;
      font-weight: 500;
      color: var(--text-secondary);
      cursor: pointer;
      transition: var(--transition);
    }

    .games-tab.active {
      background: var(--bg-card);
      color: var(--primary);
      font-weight: 600;
      box-shadow: var(--shadow-sm);
    }

    .games-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 12px;
      margin-bottom: 16px;
    }

    .game-card {
      background: var(--bg-card);
      border-radius: var(--radius);
      border: 1.5px solid var(--border);
      padding: 18px 14px 14px;
      text-align: center;
      cursor: pointer;
      transition: var(--transition-bounce);
      position: relative;
      overflow: hidden;
    }

    .game-card:active {
      transform: scale(0.96);
    }

    .game-card.played {
      opacity: 0.6;
      cursor: default;
    }

    .game-card.disabled-game {
      opacity: 0.4;
      cursor: not-allowed;
    }

    .game-card-icon {
      font-size: 36px;
      margin-bottom: 8px;
      line-height: 1;
    }

    .game-card-name {
      font-size: 14px;
      font-weight: 600;
      color: var(--text);
      margin-bottom: 4px;
    }

    .game-card-sub {
      font-size: 11px;
      color: var(--text-muted);
    }

    .game-card-badge {
      position: absolute;
      top: 8px;
      right: 8px;
      background: var(--success);
      color: white;
      font-size: 9px;
      font-weight: 700;
      padding: 2px 6px;
      border-radius: 20px;
      text-transform: uppercase;
    }

    .game-card-badge.played-badge {
      background: var(--text-muted);
    }

    .game-card-badge.off-badge {
      background: var(--danger);
    }

    .wallet-empty {
      text-align: center;
      padding: 28px 20px;
      color: var(--text-muted);
      font-size: 14px;
    }

    .wallet-empty-icon {
      font-size: 40px;
      margin-bottom: 8px;
    }

    .wallet-item {
      /* display: flex; */
      align-items: center;
      gap: 14px;
      padding: 14px 0;
      border-bottom: 1px solid var(--border-light);
    }

    .wallet-item:last-child {
      border-bottom: none;
    }

    .wallet-icon {
      width: 44px;
      height: 44px;
      background: var(--grad-soft);
      border-radius: var(--radius-sm);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      flex-shrink: 0;
    }

    .wallet-info {
      flex: 1;
    }

    .wallet-title {
      font-size: 14px;
      font-weight: 600;
      color: var(--text);
    }

    .wallet-meta {
      font-size: 12px;
      color: var(--text-muted);
      margin-top: 2px;
    }

    .wallet-code {
      font-size: 12px;
      font-weight: 700;
      background: var(--bg-secondary);
      color: var(--primary);
      padding: 4px 10px;
      border-radius: 20px;
      cursor: pointer;
      white-space: nowrap;
    }

    .lb-tabs {
      display: flex;
      gap: 6px;
      margin-bottom: 12px;
    }

    .lb-tab {
      flex: 1;
      text-align: center;
      padding: 7px 4px;
      border-radius: var(--radius-sm);
      font-size: 12px;
      font-weight: 500;
      color: var(--text-secondary);
      background: var(--bg-secondary);
      cursor: pointer;
      transition: var(--transition);
    }

    .lb-tab.active {
      background: var(--primary);
      color: white;
      font-weight: 600;
    }

    .lb-row {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px 0;
      border-bottom: 1px solid var(--border-light);
    }

    .lb-row:last-child {
      border-bottom: none;
    }

    .lb-rank {
      width: 28px;
      height: 28px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 13px;
      font-weight: 700;
      border-radius: 50%;
      background: var(--bg-secondary);
      color: var(--text-secondary);
      flex-shrink: 0;
    }

    .lb-rank.gold {
      background: rgba(245, 158, 11, 0.15);
      color: #D97706;
    }

    .lb-rank.silver {
      background: rgba(148, 163, 184, 0.15);
      color: #64748B;
    }

    .lb-rank.bronze {
      background: rgba(205, 127, 50, 0.15);
      color: #92400E;
    }

    [data-theme="dark"] .lb-rank.gold { color: #FBBF24; }
    [data-theme="dark"] .lb-rank.silver { color: #94A3B8; }
    [data-theme="dark"] .lb-rank.bronze { color: #CD7F32; }

    .lb-name {
      flex: 1;
      font-size: 14px;
      font-weight: 500;
      color: var(--text);
    }

    .lb-score {
      font-size: 13px;
      font-weight: 700;
      color: var(--primary);
    }

    /* Game result modal */
    .game-result-icon {
      font-size: 64px;
      text-align: center;
      margin: 8px 0 12px;
    }

    .game-result-title {
      font-size: 22px;
      font-weight: 700;
      text-align: center;
      margin-bottom: 6px;
    }

    .game-result-sub {
      font-size: 14px;
      color: var(--text-secondary);
      text-align: center;
      margin-bottom: 4px;
    }

    .game-result-code {
      text-align: center;
      font-size: 20px;
      font-weight: 800;
      letter-spacing: 2px;
      color: var(--primary);
      background: var(--bg-secondary);
      padding: 12px;
      border-radius: var(--radius-sm);
      margin: 16px 0 8px;
      cursor: pointer;
    }

    .game-result-expiry {
      font-size: 12px;
      color: var(--text-muted);
      text-align: center;
      margin-bottom: 16px;
    }

    /* ============================================================
   AUTH MODAL OVERLAY (new — not a page gate)
   ============================================================ */
    .auth-modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      width: 100%;
      height: 100%;
      z-index: 9000;
      background: rgba(10, 4, 30, 0.82);
      backdrop-filter: blur(10px);
      -webkit-backdrop-filter: blur(10px);
      display: flex;
      align-items: flex-end;
      justify-content: center;
      animation: modalFadeIn 0.25s ease;
      /* Prevent touch/scroll events from reaching background */
      overscroll-behavior: contain;
    }

    .auth-modal-overlay.hidden {
      display: none !important;
    }

    .auth-modal-inner {
      width: 100%;
      max-width: 480px;
      max-height: 90vh;
      overflow-y: auto;
      overflow-x: hidden;
      position: relative;
      border-radius: var(--radius-xl) var(--radius-xl) 0 0;
      background: linear-gradient(160deg, #111827 0%, #1a5c38 50%, #1f2937 100%);
      animation: slideUpModal 0.38s cubic-bezier(0.34, 1.4, 0.64, 1);
      /* Allow scrolling inside */
      overscroll-behavior: contain;
    }

    .auth-modal-inner .auth-bg-art {
      border-radius: var(--radius-xl) var(--radius-xl) 0 0;
      position: absolute;
      inset: 0;
      overflow: hidden;
    }

    .auth-modal-close {
      position: absolute;
      top: 16px;
      right: 16px;
      z-index: 20;
      background: rgba(255, 255, 255, 0.15);
      border: 1px solid rgba(255, 255, 255, 0.25);
      color: white;
      border-radius: 20px;
      padding: 6px 14px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      backdrop-filter: blur(6px);
      transition: var(--transition);
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .auth-modal-close:hover {
      background: rgba(255, 255, 255, 0.25);
    }

    /* ============================================================
   LOGIN / SIGNUP TOPNAV BUTTON
   ============================================================ */
    .btn-rewards-topnav {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 8px 12px;
      background: var(--grad);
      color: white;
      border: none;
      border-radius: 20px;
      font-size: 12px;
      font-weight: 700;
      cursor: pointer;
      white-space: nowrap;
      box-shadow: var(--shadow-green);
      transition: var(--transition);
      letter-spacing: 0.1px;
      font-family: var(--font-body);
      max-width: 160px;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .btn-rewards-topnav:hover {
      box-shadow: 0 6px 24px rgba(124, 58, 237, 0.5);
      transform: translateY(-1px) scale(1.02);
    }

    .btn-rewards-topnav:active {
      transform: scale(0.97);
    }

    /* Points chip for logged-in users */
    .points-chip {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 8px 13px;
      background: var(--bg-secondary);
      border: 1.5px solid var(--primary-light);
      color: var(--primary);
      border-radius: 20px;
      font-size: 12px;
      font-weight: 700;
      cursor: pointer;
      transition: var(--transition);
    }

    .points-chip:hover {
      background: var(--primary-glow);
    }

    /* ============================================================
   FLOATING INSTAGRAM BUTTON
   ============================================================ */
    .ig-float-btn {
      position: fixed;
      right: 14px;
      bottom: 86px;
      z-index: 500;
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 11px 14px 11px 13px;
      background: linear-gradient(135deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%);
      color: white;
      border-radius: 28px;
      text-decoration: none;
      font-size: 13px;
      font-weight: 700;
      font-family: var(--font-body);
      box-shadow: 0 4px 20px rgba(220, 39, 67, 0.4);
      transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
      overflow: hidden;
      max-width: 48px;
    }

    .ig-float-btn:hover {
      max-width: 160px;
      box-shadow: 0 8px 32px rgba(220, 39, 67, 0.5);
      transform: translateY(-2px);
    }

    .ig-float-label {
      white-space: nowrap;
      overflow: hidden;
      opacity: 0;
      max-width: 0;
      transition: all 0.3s ease;
    }

    .ig-float-btn:hover .ig-float-label {
      opacity: 1;
      max-width: 100px;
    }

    /* ============================================================
   DELAYED REWARDS POPUP
   ============================================================ */
    .rewards-popup {
      position: fixed;
      bottom: 90px;
      left: 50%;
      transform: translateX(-50%) translateY(20px);
      z-index: 800;
      width: calc(100% - 32px);
      max-width: 380px;
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 22px 20px 18px;
      box-shadow: 0 8px 40px rgba(76, 29, 149, 0.18), var(--shadow-lg);
      text-align: center;
      animation: popupSlideUp 0.45s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;
    }

    [data-theme="dark"] .rewards-popup {
      box-shadow: 0 8px 40px rgba(0, 0, 0, 0.6), var(--shadow-lg);
    }

    .rewards-popup.hidden {
      display: none;
    }

    @keyframes popupSlideUp {
      from {
        opacity: 0;
        transform: translateX(-50%) translateY(40px);
      }

      to {
        opacity: 1;
        transform: translateX(-50%) translateY(0);
      }
    }

    .rewards-popup::before {
      content: '';
      position: absolute;
      inset: 0;
      border-radius: var(--radius-lg);
      padding: 1.5px;
      background: var(--grad);
      -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
      -webkit-mask-composite: xor;
      mask-composite: exclude;
      pointer-events: none;
    }

    .rewards-popup-close {
      position: absolute;
      top: 10px;
      right: 12px;
      background: none;
      border: none;
      font-size: 15px;
      color: var(--text-muted);
      cursor: pointer;
      padding: 4px 6px;
      border-radius: 6px;
    }

    .rewards-popup-close:hover {
      background: var(--bg-secondary);
    }

    .rewards-popup-icon {
      font-size: 36px;
      margin-bottom: 6px;
    }

    .rewards-popup-title {
      font-size: 17px;
      font-weight: 700;
      margin-bottom: 6px;
      color: var(--text);
    }

    .rewards-popup-sub {
      font-size: 13px;
      color: var(--text-secondary);
      margin-bottom: 16px;
      line-height: 1.5;
    }

    .rewards-popup-cta {
      width: 100%;
      padding: 13px;
      background: var(--grad);
      color: white;
      border: none;
      border-radius: var(--radius-sm);
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      margin-bottom: 10px;
      font-family: var(--font-body);
      box-shadow: var(--shadow-green);
      transition: var(--transition);
    }

    .rewards-popup-cta:hover {
      box-shadow: 0 8px 28px rgba(124, 58, 237, 0.5);
      transform: translateY(-1px);
    }

    .rewards-popup-skip {
      font-size: 12px;
      color: var(--text-muted);
      cursor: pointer;
      text-decoration: underline;
    }

    /* Guest-mode locked page placeholder */
    .guest-locked-page {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 60px 24px;
      text-align: center;
      gap: 12px;
    }

    .guest-locked-icon {
      font-size: 56px;
      margin-bottom: 4px;
    }

    .guest-locked-title {
      font-size: 20px;
      font-weight: 700;
    }

    .guest-locked-sub {
      font-size: 14px;
      color: var(--text-secondary);
      line-height: 1.6;
      margin-bottom: 8px;
    }

    .guest-locked-perks {
      display: flex;
      flex-direction: column;
      gap: 8px;
      background: var(--bg-secondary);
      border-radius: var(--radius);
      padding: 16px;
      width: 100%;
      margin-bottom: 8px;
      text-align: left;
    }

    .guest-locked-perk {
      font-size: 13px;
      color: var(--text);
      display: flex;
      gap: 10px;
      align-items: center;
    }

    /* Admin Instagram edit */
    .ig-admin-row {
      display: flex;
      gap: 8px;
      align-items: center;
      margin-top: 8px;
    }

    .ig-admin-row input {
      flex: 1;
    }

    /* ============================================================
   LOYALTY ECONOMY: COIN COUNTER, DAILY BONUS, CONFETTI
   ============================================================ */
    .coin-counter-card {
      background: var(--grad);
      border-radius: var(--radius-lg);
      padding: 18px 20px;
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      cursor: pointer;
      box-shadow: var(--shadow-green);
      transition: var(--transition-bounce);
      position: relative;
      overflow: hidden;
    }

    .coin-counter-card::before {
      content: '';
      position: absolute;
      inset: 0;
      background: radial-gradient(circle at 80% 50%, rgba(255, 255, 255, 0.12) 0%, transparent 70%);
      pointer-events: none;
    }

    .coin-counter-card:active {
      transform: scale(0.98);
    }

    .coin-counter-left {
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .coin-anim {
      font-size: 38px;
      animation: coinSpin 3s ease-in-out infinite;
      filter: drop-shadow(0 0 8px rgba(245, 158, 11, 0.8));
    }

    @keyframes coinSpin {

      0%,
      80%,
      100% {
        transform: rotateY(0deg);
      }

      40% {
        transform: rotateY(180deg);
      }
    }

    .coin-pts {
      font-size: 36px;
      font-weight: 900;
      color: white;
      line-height: 1;
      letter-spacing: -1px;
      text-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    }

    .coin-label {
      font-size: 12px;
      color: rgba(255, 255, 255, 0.8);
      margin-top: 2px;
    }

    .coin-counter-right {
      text-align: right;
    }

    .daily-bonus-banner {
      background: linear-gradient(135deg, #F59E0B 0%, #EF4444 100%);
      border-radius: var(--radius);
      padding: 14px 16px;
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      gap: 12px;
      cursor: pointer;
      color: white;
      box-shadow: 0 4px 20px rgba(245, 158, 11, 0.35);
      transition: var(--transition-bounce);
    }

    .daily-bonus-banner:active {
      transform: scale(0.98);
    }

    @keyframes confettiFall {
      0% {
        opacity: 1;
        transform: translate(0, 0) rotate(0deg) scale(1);
      }

      100% {
        opacity: 0;
        transform: translate(var(--dx, 0), 200px) rotate(720deg) scale(0.3);
      }
    }

    /* Tier badge Platinum color */
    .badge-platinum {
      background: linear-gradient(135deg, #374151, #111827);
      color: white;
    }

    /* Transaction type colors for new types */
    .txn-login {
      background: rgba(16, 185, 129, 0.12);
      color: #10B981;
    }

    .txn-streak {
      background: rgba(239, 68, 68, 0.12);
      color: #EF4444;
    }

    /* ============================================================
   SKELETON LOADERS
   ============================================================ */
    .skel {
      background: linear-gradient(90deg, var(--bg-secondary) 25%, var(--border-light) 50%, var(--bg-secondary) 75%);
      background-size: 400% 100%;
      animation: shimmer 1.4s infinite;
      border-radius: var(--radius-sm);
    }
    .skel-card { height: 96px; border-radius: var(--radius); margin-bottom: 12px; }
    .skel-line { height: 14px; margin-bottom: 8px; }
    .skel-line.short { width: 60%; }
    .skel-circle { width: 44px; height: 44px; border-radius: 50%; flex-shrink: 0; }
    .skel-menu-item {
      display: flex; gap: 0; border-radius: var(--radius); overflow: hidden;
      margin-bottom: 12px; border: 1px solid var(--border);
    }
    .skel-menu-img { width: 100px; height: 100px; flex-shrink: 0; background: var(--bg-secondary); animation: shimmer 1.4s infinite; background-size: 400% 100%; background-image: linear-gradient(90deg, var(--bg-secondary) 25%, var(--border-light) 50%, var(--bg-secondary) 75%); }
    .skel-menu-body { flex: 1; padding: 14px; display: flex; flex-direction: column; gap: 8px; }

    /* ============================================================
   CART FLY ANIMATION
   ============================================================ */
    @keyframes flyToCart {
      0%   { opacity: 1; transform: scale(1) translate(0,0); }
      60%  { opacity: 1; transform: scale(0.7) translate(var(--fly-x), var(--fly-y)); }
      100% { opacity: 0; transform: scale(0.2) translate(var(--fly-x2), var(--fly-y2)); }
    }
    .cart-fly-dot {
      position: fixed; width: 20px; height: 20px; border-radius: 50%;
      background: var(--grad); z-index: 9999; pointer-events: none;
      box-shadow: var(--shadow-green);
      animation: flyToCart 0.55s cubic-bezier(0.4,0,0.6,1) forwards;
    }

    /* ============================================================
   FAVOURITE BUTTON
   ============================================================ */
    .fav-btn {
      position: absolute; top: 8px; right: 8px;
      width: 28px; height: 28px; border-radius: 50%;
      background: var(--bg-card); border: 1px solid var(--border);
      display: flex; align-items: center; justify-content: center;
      font-size: 14px; cursor: pointer; z-index: 2;
      transition: var(--transition-bounce);
      box-shadow: var(--shadow-sm);
    }
    .fav-btn:active { transform: scale(0.85); }
    .fav-btn.active { background: #fff0f3; border-color: #f43f5e; }

    /* ============================================================
   SWIPE-TO-DELETE CART ITEM
   ============================================================ */
    .cart-item-wrap {
      position: relative; overflow: hidden; border-radius: var(--radius-sm);
    }
    .cart-item-delete-bg {
      position: absolute; right: 0; top: 0; bottom: 0;
      width: 80px; background: var(--danger);
      display: flex; align-items: center; justify-content: center;
      font-size: 20px; color: white; border-radius: 0 var(--radius-sm) var(--radius-sm) 0;
    }
    .cart-item-swipeable {
      position: relative; background: var(--bg-card);
      transition: transform 0.2s ease;
      touch-action: pan-y;
    }

    /* ============================================================
   ORDER STATUS PULSE INDICATOR
   ============================================================ */
    .status-pulse {
      display: inline-flex; align-items: center; gap: 5px;
    }
    .status-pulse-dot {
      width: 8px; height: 8px; border-radius: 50%;
      background: var(--gold); flex-shrink: 0;
      animation: pulseDot 1.5s ease-in-out infinite;
    }
    .status-pulse-dot.green { background: var(--success); }
    .status-pulse-dot.red   { background: var(--danger); }
    @keyframes pulseDot {
      0%,100% { box-shadow: 0 0 0 0 currentColor; opacity: 1; }
      50%      { box-shadow: 0 0 0 5px transparent; opacity: 0.7; }
    }

    /* ============================================================
   ORDER COUNTDOWN TIMER CARD
   ============================================================ */
    .order-countdown-bar {
      position: fixed;
      bottom: 72px;
      left: 50%;
      transform: translateX(-50%) translateY(0);
      z-index: 600;
      width: calc(100% - 24px);
      max-width: 460px;
      background: var(--bg-card);
      border: 1.5px solid var(--primary-light);
      border-radius: var(--radius-lg);
      padding: 13px 16px 11px;
      box-shadow: 0 6px 32px rgba(26,92,56,0.18), var(--shadow-lg);
      display: flex;
      align-items: center;
      gap: 12px;
      animation: countdownSlideUp 0.45s cubic-bezier(0.34,1.56,0.64,1) forwards;
      cursor: pointer;
    }
    .order-countdown-bar.hidden { display: none; }
    @keyframes countdownSlideUp {
      from { opacity:0; transform: translateX(-50%) translateY(30px); }
      to   { opacity:1; transform: translateX(-50%) translateY(0); }
    }
    .order-countdown-bar.urgent {
      border-color: #F59E0B;
      background: linear-gradient(135deg, rgba(245,158,11,0.07), var(--bg-card));
      animation: urgentPulse 1.2s ease-in-out infinite;
    }
    .order-countdown-bar.done {
      border-color: var(--success);
      background: linear-gradient(135deg, rgba(16,185,129,0.08), var(--bg-card));
    }
    @keyframes urgentPulse {
      0%,100% { box-shadow: 0 6px 32px rgba(245,158,11,0.15); }
      50%      { box-shadow: 0 6px 32px rgba(245,158,11,0.45); }
    }
    .countdown-icon {
      font-size: 28px;
      flex-shrink: 0;
      transition: transform 0.3s ease;
    }
    .order-countdown-bar:hover .countdown-icon { transform: scale(1.15); }
    .countdown-body { flex: 1; min-width: 0; }
    .countdown-title {
      font-size: 13px; font-weight: 700; color: var(--text);
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .countdown-sub {
      font-size: 11px; color: var(--text-muted); margin-top: 1px;
    }
    .countdown-time {
      font-size: 22px; font-weight: 900;
      font-family: var(--font-display);
      color: var(--primary);
      letter-spacing: -0.5px;
      flex-shrink: 0;
      min-width: 60px;
      text-align: right;
      transition: color 0.4s ease;
    }
    .order-countdown-bar.urgent .countdown-time { color: #D97706; }
    .order-countdown-bar.done .countdown-time   { color: var(--success); }
    .countdown-progress-track {
      position: absolute; bottom: 0; left: 0; right: 0; height: 3px;
      background: var(--border); border-radius: 0 0 var(--radius-lg) var(--radius-lg); overflow: hidden;
    }
    .countdown-progress-fill {
      height: 100%; background: var(--grad);
      transition: width 1s linear, background 0.4s ease;
      border-radius: 0 0 var(--radius-lg) var(--radius-lg);
    }
    .order-countdown-bar.urgent .countdown-progress-fill { background: linear-gradient(90deg,#F59E0B,#EF4444); }
    .order-countdown-bar.done   .countdown-progress-fill { background: var(--success); width:100%!important; }

    /* ============================================================
   BADGE PULSE ON CART ADD
   ============================================================ */
    @keyframes badgePop {
      0%   { transform: scale(1); }
      40%  { transform: scale(1.65); }
      70%  { transform: scale(0.88); }
      100% { transform: scale(1); }
    }
    .badge-pop { animation: badgePop 0.38s cubic-bezier(0.34,1.56,0.64,1); }

    /* ============================================================
   STREAK FIRE FLICKER ANIMATION
   ============================================================ */
    @keyframes streakFlicker {
      0%,100% { transform: scale(1) rotate(-2deg); filter: drop-shadow(0 0 4px rgba(239,68,68,0.5)); }
      30%      { transform: scale(1.18) rotate(3deg); filter: drop-shadow(0 0 10px rgba(245,158,11,0.9)); }
      60%      { transform: scale(0.95) rotate(-1deg); filter: drop-shadow(0 0 6px rgba(239,68,68,0.7)); }
    }
    .streak-num { animation: streakFlicker 1.4s ease-in-out infinite; display: inline-block; }

    /* ============================================================
   TIER-UP MODAL BOUNCE
   ============================================================ */
    @keyframes bounceIn {
      0%   { transform: scale(0.2) rotate(-15deg); opacity:0; }
      55%  { transform: scale(1.2) rotate(5deg); opacity:1; }
      80%  { transform: scale(0.93) rotate(-2deg); }
      100% { transform: scale(1) rotate(0); }
    }
    .tier-up-icon { animation: bounceIn 0.7s cubic-bezier(0.34,1.56,0.64,1) forwards; }

    /* ============================================================
   SKELETON → CONTENT FADE-IN
   ============================================================ */
    @keyframes fadeSlideUp {
      from { opacity:0; transform:translateY(14px); }
      to   { opacity:1; transform:translateY(0); }
    }
    #dashboard-content > *,
    #menu-content > *,
    #loyalty-content > *,
    #games-content > *,
    #profile-content > * {
      animation: fadeSlideUp 0.32s ease both;
    }

    /* ============================================================
   POINTS FLOAT-UP ON EARN
   ============================================================ */
    @keyframes floatUp {
      0%   { opacity:1; transform: translateY(0) scale(1); }
      100% { opacity:0; transform: translateY(-60px) scale(1.3); }
    }
    .points-float {
      position: fixed; pointer-events: none; z-index: 99999;
      font-size: 18px; font-weight: 900; color: var(--primary);
      text-shadow: 0 2px 8px rgba(26,92,56,0.3);
      animation: floatUp 1.2s ease-out forwards;
    }

    /* ============================================================
   PULL-TO-REFRESH INDICATOR
   ============================================================ */
    .ptr-indicator {
      position: fixed; top: 66px; left: 50%; transform: translateX(-50%) translateY(-60px);
      background: var(--bg-card); border: 1px solid var(--border);
      border-radius: 20px; padding: 8px 16px; font-size: 13px;
      font-weight: 600; color: var(--text-secondary);
      display: flex; align-items: center; gap: 8px;
      z-index: 90; transition: transform 0.3s ease, opacity 0.3s ease;
      box-shadow: var(--shadow); opacity: 0;
    }
    .ptr-indicator.visible { transform: translateX(-50%) translateY(8px); opacity: 1; }
    .ptr-spinner {
      width: 16px; height: 16px; border: 2px solid var(--border);
      border-top-color: var(--primary); border-radius: 50%;
      animation: spin 0.7s linear infinite;
    }

    /* ============================================================
   SEARCH HISTORY CHIPS
   ============================================================ */
    .search-chips {
      display: flex; gap: 6px; flex-wrap: wrap; padding: 6px 0 10px;
    }
    .search-chip {
      padding: 5px 12px; background: var(--bg-secondary);
      border: 1px solid var(--border); border-radius: 20px;
      font-size: 12px; font-weight: 500; color: var(--text-secondary);
      cursor: pointer; transition: var(--transition); white-space: nowrap;
      display: flex; align-items: center; gap: 4px;
    }
    .search-chip:hover { background: var(--primary-glow); color: var(--primary); border-color: var(--primary-light); }
    .search-chip .chip-x { font-size: 10px; opacity: 0.6; }

    /* ============================================================
   MILESTONE BADGES ON PROFILE
   ============================================================ */
    .milestones-grid {
      display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 4px;
    }
    .milestone-badge {
      background: var(--bg-secondary); border: 1px solid var(--border);
      border-radius: var(--radius); padding: 12px 8px; text-align: center;
      transition: var(--transition-bounce);
    }
    .milestone-badge.unlocked {
      background: var(--grad-soft); border-color: var(--primary-light);
    }
    .milestone-badge.unlocked:hover { transform: translateY(-2px); box-shadow: var(--shadow-sm); }
    .milestone-icon { font-size: 24px; margin-bottom: 4px; }
    .milestone-name { font-size: 10px; font-weight: 700; letter-spacing: 0.2px; line-height: 1.3; }
    .milestone-badge:not(.unlocked) { opacity: 0.45; filter: grayscale(1); }

    /* ============================================================
   REORDER BUTTON
   ============================================================ */
    .reorder-btn {
      font-size: 11px; font-weight: 700; color: var(--primary);
      background: var(--primary-glow); border: 1px solid var(--primary-light);
      border-radius: 8px; padding: 4px 10px; cursor: pointer;
      transition: var(--transition); white-space: nowrap;
    }
    .reorder-btn:hover { background: var(--primary); color: white; }

    /* ============================================================
   ESTIMATED WAIT TIME BADGE
   ============================================================ */
    .wait-badge {
      display: inline-flex; align-items: center; gap: 4px;
      background: rgba(245,158,11,0.12); color: var(--gold);
      border: 1px solid rgba(245,158,11,0.25); border-radius: 8px;
      font-size: 11px; font-weight: 700; padding: 3px 8px;
    }

    /* ============================================================
   ITEM CUSTOMISATION SHEET
   ============================================================ */
    .customise-option {
      display: flex; align-items: center; justify-content: space-between;
      padding: 10px 0; border-bottom: 1px solid var(--border-light);
    }
    .customise-option:last-child { border-bottom: none; }
    .customise-label { font-size: 14px; font-weight: 500; }
    .customise-chips { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 4px; }
    .customise-chip {
      padding: 5px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;
      border: 1.5px solid var(--border); background: var(--bg-secondary);
      color: var(--text-secondary); cursor: pointer; transition: var(--transition);
    }
    .customise-chip.selected {
      background: var(--primary); color: white; border-color: var(--primary);
    }

    /* Floating Call Button */
    .call-float-btn {
      position: fixed;
      right: 14px;
      bottom: 148px;
      z-index: 500;
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 11px 14px 11px 13px;
      background: linear-gradient(135deg, #10B981, #059669);
      color: white;
      border-radius: 28px;
      text-decoration: none;
      font-size: 13px;
      font-weight: 700;
      font-family: var(--font-body);
      box-shadow: 0 4px 20px rgba(16, 185, 129, 0.4);
      transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
      overflow: hidden;
      max-width: 48px;
    }

    .call-float-btn:hover {
      max-width: 160px;
      transform: translateY(-2px);
      box-shadow: 0 8px 32px rgba(16, 185, 129, 0.5);
    }

    .call-float-label {
      white-space: nowrap;
      overflow: hidden;
      opacity: 0;
      max-width: 0;
      transition: all 0.3s ease;
    }

    .call-float-btn:hover .call-float-label {
      opacity: 1;
      max-width: 100px;
    }
  </style>
  <!-- end styles -->
</head>

<body>

  <!-- ============================================================
     AUTH MODAL OVERLAY (shown on demand, not as a gate)
     ============================================================ -->
  <div id="auth-screen" class="auth-modal-overlay hidden">
    <div class="auth-modal-inner">
      <!-- Purple gradient header with orbs and branding -->
      <div class="auth-bg-art">
        <div class="auth-orb auth-orb-1"></div>
        <div class="auth-orb auth-orb-2"></div>
        <div class="auth-orb auth-orb-3"></div>
      </div>
      <div style="position:relative;z-index:2;padding:20px 20px 28px;display:flex;align-items:center;justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:10px;">
          <div style="width:38px;height:38px;background:rgba(255,255,255,0.18);backdrop-filter:blur(8px);border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:19px;border:1px solid rgba(255,255,255,0.3);">☕</div>
          <div>
            <div style="font-family:var(--font-display);font-size:17px;font-weight:700;color:white;letter-spacing:-0.2px;">C3 Restaurant</div>
            <div style="font-size:11px;color:rgba(255,255,255,0.7);margin-top:1px;">Sign in to earn & redeem rewards</div>
          </div>
        </div>
        <button onclick="closeAuthModal()" style="background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.25);color:white;border-radius:20px;padding:7px 14px;font-size:12px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;font-family:var(--font-body);backdrop-filter:blur(6px);">✕ Skip</button>
      </div>

      <!-- White card section -->
      <div class="auth-card" style="position:relative;z-index:2;">
        <div class="auth-tabs">
          <div class="auth-tab active" onclick="switchAuthTab('login')">Sign In</div>
          <div class="auth-tab" onclick="switchAuthTab('signup')">Create Account</div>
        </div>

        <!-- Login Form -->
        <div id="login-form">
          <div class="form-group">
            <label class="form-label">Mobile Number</label>
            <div class="form-input-icon">
              <span class="icon">📱</span>
              <input type="tel" class="form-input" id="login-mobile" placeholder="Enter your mobile number" maxlength="10">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Password</label>
            <div class="form-input-icon">
              <span class="icon">🔒</span>
              <input type="password" class="form-input" id="login-password" placeholder="Enter your password">
            </div>
          </div>
          <div class="remember-row">
            <label class="checkbox-label">
              <input type="checkbox" id="remember-me"> Remember me
            </label>
          </div>
          <button class="btn btn-primary" onclick="doLogin()">
            <span id="login-btn-text">Sign In</span>
          </button>
          <div class="divider-text">demo: 9876543210 / admin123</div>
        </div>

        <!-- Signup Form -->
        <div id="signup-form" class="hidden">
          <div class="form-group">
            <label class="form-label">Full Name</label>
            <div class="form-input-icon">
              <span class="icon">👤</span>
              <input type="text" class="form-input" id="signup-name" placeholder="Your full name">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Mobile Number</label>
            <div class="form-input-icon">
              <span class="icon">📱</span>
              <input type="tel" class="form-input" id="signup-mobile" placeholder="10-digit mobile number" maxlength="10">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Password</label>
            <div class="form-input-icon">
              <span class="icon">🔒</span>
              <input type="password" class="form-input" id="signup-password" placeholder="Create a password">
            </div>
          </div>
          <div class="form-group">
            <label class="form-label">Birthday <span class="text-muted text-sm">(for special rewards!)</span></label>
            <input type="date" class="form-input" id="signup-birthday">
          </div>
          <div class="form-group">
            <label class="form-label">Referral Code <span class="text-muted text-sm">(optional — earn bonus points!)</span></label>
            <div class="form-input-icon">
              <span class="icon">🎁</span>
              <input type="text" class="form-input" id="signup-refer-code" placeholder="Enter friend's referral code" maxlength="10" style="text-transform:uppercase;">
            </div>
          </div>
          <button class="btn btn-primary" onclick="doSignup()">
            <span id="signup-btn-text">Create Account</span>
          </button>
        </div>
      </div><!-- /auth-card -->
    </div><!-- /auth-modal-inner -->
  </div><!-- /auth-screen -->

  <!-- ============================================================
     MAIN APP (always visible — guest browsing allowed)
     ============================================================ -->
  <div id="app" class="visible">
    <!-- Top Nav -->
    <nav class="topnav">
      <div class="topnav-logo">
        <div class="topnav-logo-mark">☕</div>
        <span class="topnav-brand">C3 Restaurant</span>
      </div>
      <div class="topnav-spacer"></div>
      <div class="topnav-actions">
        <div class="nav-icon-btn" onclick="toggleDarkMode()" id="dark-mode-btn" title="Toggle Dark Mode">
          🌙
        </div>
        <div class="nav-icon-btn" onclick="goToPage('cart')" id="cart-nav-btn" title="Cart">
          🛒
          <span class="badge hidden" id="cart-badge">0</span>
        </div>
        <!-- Guest: Login/Signup button | Logged-in: Points chip -->
        <?php if (!isLoggedIn()): ?>
          <button class="btn-rewards-topnav" onclick="openAuthModal('signup')" id="login-topnav-btn">
            🪙 Join Free — Get 200 Coins
          </button>
        <?php else: ?>
          <div class="points-chip" onclick="goToPage('loyalty')" title="Your Points">
            ⭐ <span id="topnav-points">···</span>
          </div>
        <?php endif; ?>
      </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
      <!-- DASHBOARD -->
      <div id="page-home" class="page active">
        <div id="dashboard-content">
          <div class="page-loader">
            <div class="spinner"></div>
            <p>Loading your dashboard...</p>
          </div>
        </div>
      </div>

      <!-- MENU -->
      <div id="page-menu" class="page">
        <div id="menu-content">
          <div style="padding:4px 0;">
            <div class="skel" style="height:44px;border-radius:22px;margin-bottom:14px;"></div>
            <div style="display:flex;gap:8px;margin-bottom:18px;overflow:hidden;">
              <div class="skel" style="height:34px;width:70px;border-radius:20px;flex-shrink:0;"></div>
              <div class="skel" style="height:34px;width:80px;border-radius:20px;flex-shrink:0;"></div>
              <div class="skel" style="height:34px;width:65px;border-radius:20px;flex-shrink:0;"></div>
            </div>
            <div class="skel-menu-item"><div class="skel-menu-img"></div><div class="skel-menu-body"><div class="skel skel-line"></div><div class="skel skel-line short"></div></div></div>
            <div class="skel-menu-item"><div class="skel-menu-img"></div><div class="skel-menu-body"><div class="skel skel-line"></div><div class="skel skel-line short"></div></div></div>
            <div class="skel-menu-item"><div class="skel-menu-img"></div><div class="skel-menu-body"><div class="skel skel-line"></div><div class="skel skel-line short"></div></div></div>
          </div>
        </div>
      </div>

      <!-- CART -->
      <div id="page-cart" class="page">
        <div id="cart-content"></div>
      </div>

      <!-- LOYALTY -->
      <div id="page-loyalty" class="page">
        <div id="loyalty-content">
          <div class="page-loader">
            <div class="spinner"></div>
            <p>Loading rewards...</p>
          </div>
        </div>
      </div>

      <!-- GAMES -->
      <div id="page-games" class="page">
        <div id="games-content">
          <div class="page-loader">
            <div class="spinner"></div>
            <p>Loading games...</p>
          </div>
        </div>
      </div>

      <!-- PROFILE -->
      <div id="page-profile" class="page">
        <div id="profile-content">
          <div class="page-loader">
            <div class="spinner"></div>
            <p>Loading profile...</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Bottom Nav -->
    <nav class="bottomnav">
      <div class="bottomnav-item active" id="nav-menu" onclick="goToPage('menu')">
        <span class="nav-icon">🍽️</span>
        <span>Menu</span>
      </div>
      <div class="bottomnav-item" id="nav-home" onclick="goToPage('home')">
        <span class="nav-icon">🏠</span>
        <span>Dashboard</span>
      </div>
      <div class="bottomnav-item" id="nav-games" onclick="goToPage('games')">
        <span class="nav-icon">🎰</span>
        <span>Games</span>
      </div>
      <div class="bottomnav-item" id="nav-loyalty" onclick="goToPage('loyalty')">
        <span class="nav-icon">⭐</span>
        <span>Rewards</span>
      </div>
      <div class="bottomnav-item" id="nav-profile" onclick="goToPage('profile')">
        <span class="nav-icon">👤</span>
        <span>Profile</span>
      </div>
    </nav>
  </div>

  <!-- Mobile Bottom-Left: Cart + Dark Mode floating buttons -->
  <div class="mobile-bottom-left-btns">
    <div class="mobile-float-btn" onclick="toggleDarkMode()" id="dark-mode-btn-mobile" title="Toggle Dark Mode">
      🌙
    </div>
    <div class="mobile-float-btn" onclick="goToPage('cart')" id="cart-nav-btn-mobile" title="Cart">
      🛒
      <span class="badge hidden" id="cart-badge-mobile">0</span>
    </div>
  </div>

  <!-- Pull-to-refresh indicator -->
  <div class="ptr-indicator" id="ptr-indicator">
    <div class="ptr-spinner"></div> Pull to refresh
  </div>

  <!-- Order Countdown Timer Bar -->
  <div id="order-countdown-bar" class="order-countdown-bar hidden" onclick="goToPage('home')">
    <div class="countdown-icon" id="countdown-icon">⏳</div>
    <div class="countdown-body">
      <div class="countdown-title" id="countdown-title">Order in progress…</div>
      <div class="countdown-sub" id="countdown-sub">Tap to view dashboard</div>
    </div>
    <div class="countdown-time" id="countdown-time">--:--</div>
    <div class="countdown-progress-track">
      <div class="countdown-progress-fill" id="countdown-progress-fill" style="width:100%"></div>
    </div>
  </div>

  <!-- Toast Container -->
  <div id="toast-container"></div>

  <!-- Floating Instagram Button -->
  <a id="ig-float-btn" href="https://www.instagram.com/arabica_officiall" target="_blank" rel="noopener" class="ig-float-btn" title="Follow us on Instagram">
    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
      <rect x="2" y="2" width="20" height="20" rx="6" stroke="white" stroke-width="2" />
      <circle cx="12" cy="12" r="4.5" stroke="white" stroke-width="2" />
      <circle cx="17.5" cy="6.5" r="1.2" fill="white" />
    </svg>
    <span class="ig-float-label">Instagram</span>
  </a>

  <!-- Floating Call Button -->
  <a href="tel:9575131552" class="call-float-btn" title="Call Us">
    <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
      <path d="M3.654 1.328a.678.678 0 0 1 .737-.169l2.522 1.01c.329.132.445.52.28.822l-1.1 2.2a.678.678 0 0 0 .145.777l2.457 2.457a.678.678 0 0 0 .777.145l2.2-1.1c.302-.165.69-.049.822.28l1.01 2.522a.678.678 0 0 1-.168.737l-1.272 1.272c-.74.74-1.846 1.065-2.877.702-2.537-.89-5.33-3.683-6.22-6.22-.363-1.03-.038-2.137.702-2.877L3.654 1.328z" />
    </svg>

    <span class="call-float-label">Call Us</span>
  </a>

  <!-- Delayed Rewards Popup (for guests) -->
  <div id="rewards-popup" class="rewards-popup hidden">
    <button class="rewards-popup-close" onclick="dismissRewardsPopup()">✕</button>
    <div class="rewards-popup-icon">🪙</div>
    <div class="rewards-popup-title">Get 200 FREE C3 Coins!</div>
    <div class="rewards-popup-sub">Sign up in 30 seconds — get 100 welcome coins + earn coins on every order. 40 coins = ₹1 off!</div>
    <button class="rewards-popup-cta" onclick="dismissRewardsPopup();openAuthModal('signup')">Claim My 200 Coins 🎁</button>
    <div class="rewards-popup-skip" onclick="dismissRewardsPopup()">Maybe later</div>
  </div>

  <!-- ============================================================
     JAVASCRIPT
     ============================================================ -->
  <script>
    // ============================================================
    // STATE
    // ============================================================
    const State = {
      currentPage: 'home',
      cart: JSON.parse(localStorage.getItem('brewcraft_cart') || '[]'),
      menu: {
        categories: [],
        items: [],
        combos: []
      },
      dashboard: null,
      menuLoaded: false,
      dashboardLoaded: false,
      loyaltyLoaded: false,
      profileLoaded: false,
      searchQuery: '',
      activeCategory: 'all',
      orderType: 'dine-in',
      pointsToUse: 0,
      darkMode: document.documentElement.dataset.theme === 'dark',
      isLoggedIn: <?= isLoggedIn() ? 'true' : 'false' ?>,
      rewardsPopupDismissed: false,
      instagramUrl: 'https://www.instagram.com/arabica_officiall',
    };

    // ============================================================
    // LOYALTY ECONOMY CONSTANTS (must match PHP defines)
    // ============================================================
    const LOYALTY = {
      POINTS_PER_RUPEE: <?= POINTS_PER_RUPEE ?>,
      POINTS_REDEEM_RATE: <?= POINTS_REDEEM_RATE ?>, // 40 pts = ₹1
      MAX_REDEEM_PERCENT: <?= MAX_REDEEM_PERCENT ?>, // 15% max redemption
      TIERS: {
        Bronze: {
          min: <?= TIER_BRONZE_MIN ?>,
          icon: '🥉',
          color: '#CD7F32',
          multiplier: 1.0,
          benefits: 'Basic rewards'
        },
        Silver: {
          min: <?= TIER_SILVER_MIN ?>,
          icon: '🥈',
          color: '#94A3B8',
          multiplier: 1.15,
          benefits: '1.15× points multiplier'
        },
        Gold: {
          min: <?= TIER_GOLD_MIN ?>,
          icon: '🥇',
          color: '#F59E0B',
          multiplier: 1.3,
          benefits: '1.3× points + Birthday reward'
        },
        Platinum: {
          min: <?= TIER_PLATINUM_MIN ?>,
          icon: '💎',
          color: '#A855F7',
          multiplier: 1.5,
          benefits: '1.5× points + Secret menu + Exclusive access'
        }
      }
    };

    // ============================================================
    // UTILS
    // ============================================================
    function api(action, data = null) {
      const opts = {
        method: data ? 'POST' : 'GET',
        headers: {
          'Content-Type': 'application/json'
        }
      };
      if (data) opts.body = JSON.stringify(data);
      return fetch(`?api=${action}`, opts).then(r => {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.text().then(text => {
          try {
            return JSON.parse(text);
          } catch (e) {
            console.error('API JSON parse error for action:', action, text.substring(0, 200));
            throw new Error('Invalid server response');
          }
        });
      });
    }

    function toast(msg, type = 'info', duration = 3000) {
      const icons = {
        success: '✅',
        error: '❌',
        info: 'ℹ️',
        warning: '⚠️'
      };
      const el = document.createElement('div');
      el.className = `toast ${type}`;
      el.innerHTML = `<span class="toast-icon">${icons[type]||'ℹ️'}</span><span class="toast-msg">${msg}</span>`;
      const container = document.getElementById('toast-container');
      container.appendChild(el);
      setTimeout(() => {
        el.classList.add('removing');
        setTimeout(() => el.remove(), 350);
      }, duration);
    }

    function fmt(price) {
      return '₹' + parseFloat(price).toFixed(0);
    }

    function esc(s) {
      return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function timeAgo(ts) {
      const d = new Date(ts),
        now = new Date();
      const diff = (now - d) / 1000;
      if (diff < 60) return 'just now';
      if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
      if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
      return d.toLocaleDateString('en-IN', {
        day: 'numeric',
        month: 'short'
      });
    }

    function saveCart() {
      localStorage.setItem('brewcraft_cart', JSON.stringify(State.cart));
    }

    function getCartTotal() {
      return State.cart.reduce((s, i) => s + i.price * i.qty, 0);
    }

    function getCartCount() {
      return State.cart.reduce((s, i) => s + i.qty, 0);
    }

    function updateCartBadge() {
      const count = getCartCount();
      ['cart-badge', 'cart-bnav-badge', 'cart-badge-mobile'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
          el.textContent = count;
          el.classList.toggle('hidden', count === 0);
        }
      });
    }

    // ============================================================
    // AUTH — Guest-first modal approach
    // ============================================================

    // Tracks where to return after successful login/signup
    // { page: 'cart', action: 'placeOrder' } etc.
    State._authPending = null;

    function openAuthModal(tab = 'login', pendingPage = null, pendingAction = null) {
      const screen = document.getElementById('auth-screen');
      if (!screen) return;

      // Store where to return after login
      State._authPending = (pendingPage || pendingAction)
        ? { page: pendingPage, action: pendingAction }
        : null;

      screen.classList.remove('hidden');
      document.body.style.overflow = 'hidden';
      switchAuthTab(tab);

      // Reset button labels safely (elements only exist when guest — guard with ?.)
      const loginBtnText = document.getElementById('login-btn-text');
      const signupBtnText = document.getElementById('signup-btn-text');
      if (loginBtnText) loginBtnText.textContent = 'Sign In';
      if (signupBtnText) signupBtnText.textContent = 'Create Account';

      // Focus first field for faster UX
      setTimeout(() => {
        const first = tab === 'login'
          ? document.getElementById('login-mobile')
          : document.getElementById('signup-name');
        if (first) first.focus();
      }, 120);
    }

    function closeAuthModal() {
      const screen = document.getElementById('auth-screen');
      if (screen) screen.classList.add('hidden');
      document.body.style.overflow = '';
    }

    function switchAuthTab(tab) {
      document.querySelectorAll('.auth-tab').forEach((el, i) => {
        el.classList.toggle('active', (i === 0) === (tab === 'login'));
      });
      const loginForm = document.getElementById('login-form');
      const signupForm = document.getElementById('signup-form');
      if (loginForm) loginForm.classList.toggle('hidden', tab !== 'login');
      if (signupForm) signupForm.classList.toggle('hidden', tab !== 'signup');

      // Auto-fill referral code from URL ?ref= param
      if (tab === 'signup') {
        const urlRef = new URLSearchParams(window.location.search).get('ref');
        const refInput = document.getElementById('signup-refer-code');
        if (urlRef && refInput && !refInput.value) {
          refInput.value = urlRef.toUpperCase();
          toast('Referral code applied! 🎉', 'success', 2500);
        }
      }
    }

    async function doLogin() {
      const mobileEl = document.getElementById('login-mobile');
      const passwordEl = document.getElementById('login-password');
      const rememberEl = document.getElementById('remember-me');
      if (!mobileEl || !passwordEl) return;

      const mobile = mobileEl.value.trim();
      const password = passwordEl.value;
      const remember = rememberEl ? rememberEl.checked : false;

      if (!mobile || !password) {
        toast('Please fill all fields', 'warning');
        return;
      }
      const btn = document.getElementById('login-btn-text');
      if (btn) btn.innerHTML = '<span class="loading-spinner"></span>';

      try {
        const res = await api('login', { mobile, password, remember });
        if (res.success) {
          toast('Welcome back! 👋', 'success');
          showApp();
        } else {
          toast(res.message || 'Login failed', 'error');
          if (btn) btn.textContent = 'Sign In';
        }
      } catch(e) {
        toast('Connection error. Try again.', 'error');
        if (btn) btn.textContent = 'Sign In';
      }
    }

    async function doSignup() {
      const nameEl     = document.getElementById('signup-name');
      const mobileEl   = document.getElementById('signup-mobile');
      const passwordEl = document.getElementById('signup-password');
      const birthdayEl = document.getElementById('signup-birthday');
      const referEl    = document.getElementById('signup-refer-code');
      if (!nameEl || !mobileEl || !passwordEl) return;

      const full_name  = nameEl.value.trim();
      const mobile     = mobileEl.value.trim();
      const password   = passwordEl.value;
      const birthday   = birthdayEl ? birthdayEl.value : '';
      const refer_code = referEl ? referEl.value.trim().toUpperCase() : '';

      if (!full_name || !mobile || !password) {
        toast('Please fill all required fields', 'warning');
        return;
      }
      if (mobile.length !== 10) {
        toast('Enter valid 10-digit mobile number', 'warning');
        return;
      }
      const btn = document.getElementById('signup-btn-text');
      if (btn) btn.innerHTML = '<span class="loading-spinner"></span>';

      try {
        const res = await api('signup', { full_name, mobile, password, birthday, refer_code });
        if (res.success) {
          SFX.success();
          haptic([50, 30, 80]);
          triggerConfetti();
          toast('🎉 ' + (res.message || 'Account created!') + ' Welcome aboard! 🪙', 'success');
          showApp();
        } else {
          toast(res.message || 'Signup failed', 'error');
          if (btn) btn.textContent = 'Create Account';
        }
      } catch(e) {
        toast('Connection error. Try again.', 'error');
        if (btn) btn.textContent = 'Create Account';
      }
    }

    function showApp() {
      closeAuthModal();
      State.isLoggedIn = true;

      // Show points chip, hide login button
      const loginBtn = document.getElementById('login-topnav-btn');
      if (loginBtn) loginBtn.style.display = 'none';

      // Add points chip to topnav if it doesn't exist yet
      const pointsChip = document.querySelector('.points-chip');
      if (!pointsChip) {
        const actions = document.querySelector('.topnav-actions');
        if (actions) {
          const chip = document.createElement('div');
          chip.className = 'points-chip';
          chip.id = 'topnav-points-chip';
          chip.onclick = () => goToPage('loyalty');
          chip.title = 'Your Points';
          chip.innerHTML = '⭐ <span id="topnav-points">···</span>';
          actions.appendChild(chip);
        }
      }

      // Mark all pages stale so they reload with logged-in data
      State.dashboardLoaded = false;
      State.menuLoaded = false;
      State.loyaltyLoaded = false;
      State.profileLoaded = false;
      State.dashboard = null;

      // Return to where the user was, or complete a pending action
      const pending = State._authPending;
      State._authPending = null;

      if (pending && pending.action === 'placeOrder') {
        // Return to cart and attempt order again
        goToPage('cart');
        setTimeout(() => placeOrder(), 300);
      } else if (pending && pending.page) {
        goToPage(pending.page);
      } else {
        goToPage('home');
      }
    }

    async function doLogout() {
      await api('logout');
      State.cart = [];
      localStorage.removeItem('brewcraft_cart');
      location.reload();
    }

    // ============================================================
    // GAMES PAGE
    // ============================================================
    const GameState = {
      data: null,
      leaderboard: null,
      activeTab: 'play', // 'play' | 'wallet' | 'leaderboard'
      activeLbTab: 'spending'
    };

    const GAME_META = {
      spin: {
        icon: '🎰',
        name: 'Spin Wheel',
        sub: 'Spin for a prize'
      },
      scratch: {
        icon: '🎴',
        name: 'Scratch Card',
        sub: 'Scratch & reveal'
      },
      card: {
        icon: '🃏',
        name: 'Lucky Card',
        sub: 'Pick your card'
      },
      giftbox: {
        icon: '🎁',
        name: 'Gift Box',
        sub: 'Open a mystery gift'
      },
      treasure: {
        icon: '🏴‍☠️',
        name: 'Treasure Hunt',
        sub: 'Find the treasure'
      },
      dailytap: {
        icon: '👆',
        name: 'Daily Tap',
        sub: 'Tap for luck'
      }
    };

    async function loadGamesPage() {
      const el = document.getElementById('games-content');
      el.innerHTML = `<div class="page-loader"><div class="spinner"></div><p>Loading games...</p></div>`;
      try {
        GameState.data = await api('get_games');
      } catch (e) {
        el.innerHTML = `<div class="wallet-empty"><div class="wallet-empty-icon">⚠️</div><p>Failed to load games. Please try again.</p></div>`;
        return;
      }
      renderGamesPage();
    }

    function renderGamesPage() {
      const el = document.getElementById('games-content');
      const d = GameState.data;
      const streak = d.streak || {
        current_streak: 0,
        longest_streak: 0
      };

      const tabsHtml = `
        <div class="games-tabs">
          <div class="games-tab ${GameState.activeTab==='play'?'active':''}" onclick="switchGameTab('play')">🎮 Play</div>
          <div class="games-tab ${GameState.activeTab==='wallet'?'active':''}" onclick="switchGameTab('wallet')">👜 Wallet</div>
          <div class="games-tab ${GameState.activeTab==='leaderboard'?'active':''}" onclick="switchGameTab('leaderboard')">🏆 Board</div>
        </div>`;

      const headerHtml = `
        <div class="games-header">
          <div>
            <div class="games-header-title">🎮 Game Zone</div>
            <div class="games-header-sub">Play daily to win rewards!</div>
          </div>
          <div class="streak-badge">
            <div class="streak-num">🔥${streak.current_streak}</div>
            <div class="streak-lbl">Day Streak</div>
          </div>
        </div>`;

      let bodyHtml = '';
      if (GameState.activeTab === 'play') {
        bodyHtml = renderGamesGrid(d);
      } else if (GameState.activeTab === 'wallet') {
        bodyHtml = renderWallet(d.wallet);
      } else {
        bodyHtml = renderLeaderboardSection();
      }

      el.innerHTML = headerHtml + tabsHtml + bodyHtml;

      if (GameState.activeTab === 'leaderboard' && !GameState.leaderboard) {
        fetchLeaderboard();
      }
    }

    function renderGamesGrid(d) {
      const settings = d.settings || {};
      const playedToday = d.played_today || [];
      const order = ['spin', 'scratch', 'card', 'giftbox', 'treasure', 'dailytap'];

      const cards = order.map(type => {
        const meta = GAME_META[type];
        const s = settings[type];
        const enabled = s && s.is_enabled == 1;
        const played = playedToday.includes(type);

        let badgeHtml = '';
        if (!enabled) badgeHtml = `<div class="game-card-badge off-badge">Off</div>`;
        else if (played) badgeHtml = `<div class="game-card-badge played-badge">Played</div>`;
        else badgeHtml = `<div class="game-card-badge">Play</div>`;

        const cardClass = `game-card${played?' played':''}${!enabled?' disabled-game':''}`;
        const clickHandler = (!enabled || played) ? '' : `onclick="openGameModal('${type}')"`;

        return `
          <div class="${cardClass}" ${clickHandler}>
            ${badgeHtml}
            <div class="game-card-icon">${meta.icon}</div>
            <div class="game-card-name">${meta.name}</div>
            <div class="game-card-sub">${played ? '✅ Done for today' : (!enabled ? 'Unavailable' : meta.sub)}</div>
          </div>`;
      }).join('');

      return `<div class="games-grid">${cards}</div>`;
    }

    function renderWallet(wallet) {
      if (!wallet || wallet.length === 0) {
        return `<div class="card"><div class="wallet-empty">
          <div class="wallet-empty-icon">👜</div>
          <p>No rewards yet.<br>Play games to win prizes!</p>
        </div></div>`;
      }
      const items = wallet.map(w => {
        const expDate = w.expires_at ? w.expires_at.split('T')[0].split(' ')[0] : '';
        const icon = w.actual_discount >= 20 ? '🏆' : w.actual_discount > 0 ? '🎟️' : '💔';
        const label = w.actual_discount > 0 ? `${w.actual_discount}% OFF` : 'Better Luck';
        const minOrder = w.min_order > 0 ? ` · Min ₹${w.min_order}` : '';
        return `
          <div class="wallet-item">
            <div class="wallet-icon">${icon}</div>
            <div class="wallet-info">
              <div class="wallet-title">${esc(w.display_reward)}</div>
              <div class="wallet-meta">${label}${minOrder} · Exp: ${expDate}</div>
            </div>
            <div class="wallet-code" style="background:var(--bg-secondary);color:var(--text-muted);font-size:11px;">${w.is_used ? 'Used' : 'Active'}</div>
          </div>`;
      }).join('');
      return `<div class="card"><div class="card-pad" style="padding-top:4px;padding-bottom:4px;">${items}</div></div>`;
    }

    function renderLeaderboardSection() {
      const lbHtml = GameState.leaderboard ?
        buildLeaderboardHtml(GameState.leaderboard) :
        `<div class="page-loader"><div class="spinner"></div><p>Loading leaderboard...</p></div>`;
      const lbTabs = `
        <div class="lb-tabs">
          <div class="lb-tab ${GameState.activeLbTab==='spending'?'active':''}" onclick="switchLbTab('spending')">💰 Spending</div>
          <div class="lb-tab ${GameState.activeLbTab==='games'?'active':''}" onclick="switchLbTab('games')">🎮 Games</div>
          <div class="lb-tab ${GameState.activeLbTab==='streaks'?'active':''}" onclick="switchLbTab('streaks')">🔥 Streaks</div>
        </div>`;
      return `<div class="card"><div class="card-pad">${lbTabs}<div id="lb-body">${lbHtml}</div></div></div>`;
    }

    function buildLeaderboardHtml(lb) {
      const list = lb[GameState.activeLbTab] || [];
      if (!list.length) return `<div class="wallet-empty"><div class="wallet-empty-icon">📭</div><p>No data yet this month.</p></div>`;
      return list.map((row, i) => {
        const rankClass = i === 0 ? 'gold' : i === 1 ? 'silver' : i === 2 ? 'bronze' : '';
        const rankLabel = i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : `#${i+1}`;
        let score = '';
        if (GameState.activeLbTab === 'spending') score = `₹${parseFloat(row.total_spent||0).toFixed(0)}`;
        else if (GameState.activeLbTab === 'games') score = `${row.total_wins} wins`;
        else score = `${row.current_streak}🔥`;
        return `
          <div class="lb-row">
            <div class="lb-rank ${rankClass}">${rankLabel}</div>
            <div class="lb-name">${esc(row.display_name)}</div>
            <div class="lb-score">${score}</div>
          </div>`;
      }).join('');
    }

    async function fetchLeaderboard() {
      const lb = await api('get_leaderboard');
      GameState.leaderboard = lb;
      const lbBody = document.getElementById('lb-body');
      if (lbBody) lbBody.innerHTML = buildLeaderboardHtml(lb);
    }

    function switchGameTab(tab) {
      GameState.activeTab = tab;
      renderGamesPage();
    }

    function switchLbTab(tab) {
      GameState.activeLbTab = tab;
      const lbBody = document.getElementById('lb-body');
      if (lbBody && GameState.leaderboard) lbBody.innerHTML = buildLeaderboardHtml(GameState.leaderboard);
      document.querySelectorAll('.lb-tab').forEach(t => t.classList.remove('active'));
      document.querySelectorAll('.lb-tab').forEach(t => {
        if (t.textContent.toLowerCase().includes(tab.replace('streaks', 'streak'))) t.classList.add('active');
      });
    }

    function openGameModal(game_type) {
      if (game_type === 'spin') openSpinWheel();
      else if (game_type === 'scratch') openScratchCard();
      else if (game_type === 'card') openLuckyCard();
      else if (game_type === 'giftbox') openGiftBox();
      else if (game_type === 'treasure') openTreasureHunt();
      else if (game_type === 'dailytap') openDailyTap();
    }

    /* ---- SPIN WHEEL ---- */
    function openSpinWheel() {
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay centered';
      const segments = ['3 pts', '🎉 WIN', '5 pts', '8 pts', 'Try Again', '4 pts', '🏆 20%', '6 pts'];
      const colors = ['#FF6B6B', '#4ECDC4', '#FFE66D', '#A8E6CF', '#FF8B94', '#FFA07A', '#C3B1E1', '#87CEEB'];
      overlay.innerHTML = `
        <div class="modal-dialog" style="max-width:340px;text-align:center;">
          <div style="font-size:28px;font-weight:700;margin-bottom:4px;">🎰 Spin Wheel</div>
          <p style="color:var(--text-secondary);font-size:13px;margin-bottom:16px;">Spin to win points & rewards!</p>
          <div style="position:relative;display:inline-block;margin-bottom:16px;">
            <div style="position:absolute;top:-14px;left:50%;transform:translateX(-50%);font-size:28px;z-index:10;filter:drop-shadow(0 2px 4px rgba(0,0,0,0.3));">▼</div>
            <canvas id="spin-canvas" width="260" height="260" style="border-radius:50%;box-shadow:0 8px 32px rgba(0,0,0,0.25);display:block;"></canvas>
          </div>
          <div style="display:flex;gap:10px;">
            <button class="btn btn-ghost w-full" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
            <button class="btn btn-primary w-full" id="spin-btn" onclick="doSpin(this)">🎰 Spin!</button>
          </div>
        </div>`;
      document.body.appendChild(overlay);

      const canvas = document.getElementById('spin-canvas');
      const ctx = canvas.getContext('2d');
      const arc = (2 * Math.PI) / segments.length;
      let currentAngle = 0;

      function drawWheel(angle) {
        ctx.clearRect(0, 0, 260, 260);
        segments.forEach((seg, i) => {
          const start = angle + i * arc;
          ctx.beginPath();
          ctx.moveTo(130, 130);
          ctx.arc(130, 130, 125, start, start + arc);
          ctx.fillStyle = colors[i];
          ctx.fill();
          ctx.strokeStyle = '#fff';
          ctx.lineWidth = 2;
          ctx.stroke();
          ctx.save();
          ctx.translate(130, 130);
          ctx.rotate(start + arc / 2);
          ctx.textAlign = 'right';
          ctx.fillStyle = '#333';
          ctx.font = 'bold 12px system-ui';
          ctx.fillText(seg, 110, 5);
          ctx.restore();
        });
        // Center circle
        ctx.beginPath();
        ctx.arc(130, 130, 18, 0, 2 * Math.PI);
        ctx.fillStyle = '#fff';
        ctx.fill();
        ctx.strokeStyle = '#ddd';
        ctx.lineWidth = 2;
        ctx.stroke();
        ctx.fillStyle = '#333';
        ctx.font = 'bold 11px system-ui';
        ctx.textAlign = 'center';
        ctx.fillText('SPIN', 130, 134);
      }
      drawWheel(0);

      window._spinState = {
        angle: 0,
        spinning: false,
        drawWheel
      };
    }

    async function doSpin(btn) {
      if (window._spinState && window._spinState.spinning) return;
      btn.disabled = true;
      btn.innerHTML = '<span class="loading-spinner"></span>';
      SFX.whoosh();
      haptic(30);
      const canvas = document.getElementById('spin-canvas');
      if (!canvas) return;

      const totalRot = (5 + Math.random() * 5) * 2 * Math.PI + Math.random() * 2 * Math.PI;
      const duration = 4000;
      const start = performance.now();
      const startAngle = window._spinState.angle;
      window._spinState.spinning = true;

      function easeOut(t) {
        return 1 - Math.pow(1 - t, 3);
      }

      function animate(now) {
        const elapsed = now - start;
        const t = Math.min(elapsed / duration, 1);
        window._spinState.angle = startAngle + totalRot * easeOut(t);
        window._spinState.drawWheel(window._spinState.angle);
        if (t < 1) requestAnimationFrame(animate);
        else {
          window._spinState.spinning = false;
          submitGameAndShow('spin', btn);
        }
      }
      requestAnimationFrame(animate);
    }

    /* ---- SCRATCH CARD ---- */
    function openScratchCard() {
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay centered';
      overlay.innerHTML = `
        <div class="modal-dialog" style="max-width:320px;text-align:center;">
          <div style="font-size:28px;font-weight:700;margin-bottom:4px;">🎴 Scratch Card</div>
          <p style="color:var(--text-secondary);font-size:13px;margin-bottom:16px;">Scratch to reveal your reward!</p>
          <div style="position:relative;width:240px;height:140px;margin:0 auto 20px;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.2);">
            <div id="scratch-hidden" style="position:absolute;inset:0;background:linear-gradient(135deg,#FF6B6B,#FF8E53);display:flex;align-items:center;justify-content:center;border-radius:16px;">
              <div style="text-align:center;color:white;">
                <div style="font-size:40px;">🎁</div>
                <div style="font-size:16px;font-weight:700;margin-top:4px;">Your Reward!</div>
              </div>
            </div>
            <canvas id="scratch-canvas" width="240" height="140" style="position:absolute;inset:0;border-radius:16px;cursor:crosshair;"></canvas>
          </div>
          <div style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">Scratch or tap "Reveal" to uncover!</div>
          <div style="display:flex;gap:10px;">
            <button class="btn btn-ghost w-full" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
            <button class="btn btn-primary w-full" id="scratch-btn" onclick="doScratchReveal(this)">✨ Reveal!</button>
          </div>
        </div>`;
      document.body.appendChild(overlay);

      const canvas = document.getElementById('scratch-canvas');
      const ctx = canvas.getContext('2d');
      const grad = ctx.createLinearGradient(0, 0, 240, 140);
      grad.addColorStop(0, '#C0C0C0');
      grad.addColorStop(0.5, '#E8E8E8');
      grad.addColorStop(1, '#A0A0A0');
      ctx.fillStyle = grad;
      ctx.fillRect(0, 0, 240, 140);
      ctx.fillStyle = '#999';
      ctx.font = 'bold 15px system-ui';
      ctx.textAlign = 'center';
      ctx.fillText('🪙 SCRATCH HERE 🪙', 120, 78);

      let isDrawing = false;

      function scratch(e) {
        const rect = canvas.getBoundingClientRect();
        const x = (e.touches ? e.touches[0].clientX : e.clientX) - rect.left;
        const y = (e.touches ? e.touches[0].clientY : e.clientY) - rect.top;
        ctx.globalCompositeOperation = 'destination-out';
        ctx.beginPath();
        ctx.arc(x * (240 / rect.width), y * (140 / rect.height), 22, 0, 2 * Math.PI);
        ctx.fill();
      }
      canvas.addEventListener('mousedown', () => isDrawing = true);
      canvas.addEventListener('mouseup', () => isDrawing = false);
      canvas.addEventListener('mousemove', e => {
        if (isDrawing) scratch(e);
      });
      canvas.addEventListener('touchmove', e => {
        e.preventDefault();
        scratch(e);
      }, {
        passive: false
      });
    }

    async function doScratchReveal(btn) {
      btn.disabled = true;
      btn.innerHTML = '<span class="loading-spinner"></span>';
      const canvas = document.getElementById('scratch-canvas');
      if (canvas) {
        const ctx = canvas.getContext('2d');
        ctx.globalCompositeOperation = 'destination-out';
        ctx.fillRect(0, 0, 240, 140);
      }
      await new Promise(r => setTimeout(r, 600));
      submitGameAndShow('scratch', btn);
    }

    /* ---- LUCKY CARD ---- */
    function openLuckyCard() {
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay centered';
      const cardEmojis = ['🃏', '🎴', '🀄', '🎰', '⭐', '🌟', '💫', '🎯'];
      const cardsHtml = cardEmojis.map((e, i) => `
        <div class="lucky-card" id="lcard-${i}" onclick="pickLuckyCard(${i}, this)" style="
          width:60px;height:80px;border-radius:10px;
          background:linear-gradient(135deg,#667eea,#764ba2);
          display:flex;align-items:center;justify-content:center;
          font-size:28px;cursor:pointer;border:2px solid rgba(255,255,255,0.3);
          transition:transform 0.2s,box-shadow 0.2s;
          box-shadow:0 4px 12px rgba(0,0,0,0.2);">❓</div>`).join('');
      overlay.innerHTML = `
        <div class="modal-dialog" style="max-width:340px;text-align:center;">
          <div style="font-size:28px;font-weight:700;margin-bottom:4px;">🃏 Lucky Card</div>
          <p style="color:var(--text-secondary);font-size:13px;margin-bottom:20px;">Pick one card to reveal your prize!</p>
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;justify-items:center;margin-bottom:20px;">${cardsHtml}</div>
          <button class="btn btn-ghost w-full" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
        </div>`;
      document.body.appendChild(overlay);
    }

    let _cardPicked = false;
    async function pickLuckyCard(idx, el) {
      if (_cardPicked) return;
      _cardPicked = true;
      document.querySelectorAll('.lucky-card').forEach((c, i) => {
        if (i !== idx) {
          c.style.opacity = '0.35';
          c.style.cursor = 'default';
        }
      });
      el.style.transform = 'scale(1.15) rotateY(180deg)';
      el.style.boxShadow = '0 8px 24px rgba(0,0,0,0.4)';
      el.style.background = 'linear-gradient(135deg,#FFD700,#FF8C00)';
      el.textContent = '🌟';
      await new Promise(r => setTimeout(r, 700));
      _cardPicked = false;
      const fakeBtn = {
        disabled: false,
        innerHTML: '',
        closest: () => el.closest('.modal-overlay')
      };
      submitGameAndShow('card', fakeBtn);
    }

    /* ---- GIFT BOX ---- */
    function openGiftBox() {
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay centered';
      overlay.innerHTML = `
        <div class="modal-dialog" style="max-width:300px;text-align:center;">
          <div style="font-size:28px;font-weight:700;margin-bottom:4px;">🎁 Gift Box</div>
          <p style="color:var(--text-secondary);font-size:13px;margin-bottom:20px;">Tap the box to open your mystery gift!</p>
          <div id="giftbox-wrap" onclick="openGiftBoxAnim(this)" style="cursor:pointer;display:inline-block;margin-bottom:24px;">
            <div id="giftbox-icon" style="font-size:90px;line-height:1;transition:transform 0.4s;filter:drop-shadow(0 8px 16px rgba(0,0,0,0.25));">🎁</div>
            <div style="font-size:13px;color:var(--text-muted);margin-top:8px;">Tap to open!</div>
          </div>
          <div style="display:flex;gap:10px;">
            <button class="btn btn-ghost w-full" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
          </div>
        </div>`;
      document.body.appendChild(overlay);
    }

    let _boxOpened = false;
    async function openGiftBoxAnim(wrap) {
      if (_boxOpened) return;
      _boxOpened = true;
      const icon = document.getElementById('giftbox-icon');
      icon.style.transform = 'scale(1.3) rotate(-10deg)';
      await new Promise(r => setTimeout(r, 200));
      icon.style.transform = 'scale(0.8) rotate(10deg)';
      await new Promise(r => setTimeout(r, 200));
      icon.style.transform = 'scale(1.5) rotate(0deg)';
      icon.textContent = '🎊';
      await new Promise(r => setTimeout(r, 400));
      icon.style.transform = 'scale(1)';
      await new Promise(r => setTimeout(r, 400));
      _boxOpened = false;
      submitGameAndShow('giftbox', {
        disabled: false,
        innerHTML: '',
        closest: () => wrap.closest('.modal-overlay')
      });
    }

    /* ---- TREASURE HUNT ---- */
    function openTreasureHunt() {
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay centered';
      const spots = ['🌴', '🪨', '🌊', '⛰️', '🏝️', '🌿', '🪵', '🗿', '🌋'];
      const spotsHtml = spots.map((e, i) => `
        <div onclick="digTreasure(${i},this)" style="
          width:70px;height:70px;border-radius:14px;
          background:var(--bg-secondary);border:2px solid var(--border);
          display:flex;align-items:center;justify-content:center;
          font-size:30px;cursor:pointer;transition:all 0.2s;">${e}</div>`).join('');
      overlay.innerHTML = `
        <div class="modal-dialog" style="max-width:340px;text-align:center;">
          <div style="font-size:28px;font-weight:700;margin-bottom:4px;">🏴‍☠️ Treasure Hunt</div>
          <p style="color:var(--text-secondary);font-size:13px;margin-bottom:16px;">Tap a spot to dig for treasure!</p>
          <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;justify-items:center;margin-bottom:20px;">${spotsHtml}</div>
          <button class="btn btn-ghost w-full" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
        </div>`;
      document.body.appendChild(overlay);
    }

    let _treasureDug = false;
    async function digTreasure(idx, el) {
      if (_treasureDug) return;
      _treasureDug = true;
      document.querySelectorAll('[onclick^="digTreasure"]').forEach((c, i) => {
        if (i !== idx) {
          c.style.opacity = '0.35';
          c.style.cursor = 'default';
        }
      });
      el.textContent = '💥';
      el.style.background = 'linear-gradient(135deg,#FFD700,#FF8C00)';
      el.style.borderColor = '#FFD700';
      await new Promise(r => setTimeout(r, 300));
      el.textContent = '🪙';
      await new Promise(r => setTimeout(r, 500));
      _treasureDug = false;
      submitGameAndShow('treasure', {
        disabled: false,
        innerHTML: '',
        closest: () => el.closest('.modal-overlay')
      });
    }

    /* ---- DAILY TAP ---- */
    function openDailyTap() {
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay centered';
      overlay.innerHTML = `
        <div class="modal-dialog" style="max-width:300px;text-align:center;">
          <div style="font-size:28px;font-weight:700;margin-bottom:4px;">👆 Daily Tap</div>
          <p style="color:var(--text-secondary);font-size:13px;margin-bottom:16px;">Tap the button as fast as you can!</p>
          <div style="margin-bottom:20px;">
            <div id="tap-count-display" style="font-size:48px;font-weight:900;color:var(--primary);line-height:1;">0</div>
            <div style="font-size:13px;color:var(--text-muted);">taps</div>
            <div id="tap-timer-display" style="font-size:18px;font-weight:700;margin-top:8px;color:var(--text-secondary);">3.0s</div>
          </div>
          <button id="tap-btn" onclick="registerTap(this)" style="
            width:120px;height:120px;border-radius:50%;
            background:linear-gradient(135deg,var(--primary),var(--primary-dark,#c0392b));
            border:none;font-size:48px;cursor:pointer;
            box-shadow:0 8px 24px rgba(0,0,0,0.3);
            transition:transform 0.1s;margin-bottom:20px;display:block;margin:0 auto 20px;">👆</button>
          <div style="display:flex;gap:10px;">
            <button class="btn btn-ghost w-full" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
            <button class="btn btn-primary w-full" id="tap-start-btn" onclick="startTapGame(this)">▶ Start!</button>
          </div>
        </div>`;
      document.body.appendChild(overlay);
      document.getElementById('tap-btn').disabled = true;
      window._tapState = {
        count: 0,
        active: false,
        done: false
      };
    }

    function startTapGame(startBtn) {
      startBtn.disabled = true;
      startBtn.textContent = 'Go!';
      const tapBtn = document.getElementById('tap-btn');
      tapBtn.disabled = false;
      window._tapState.active = true;
      let remaining = 3.0;
      const timerEl = document.getElementById('tap-timer-display');
      const interval = setInterval(() => {
        remaining -= 0.1;
        if (timerEl) timerEl.textContent = Math.max(0, remaining).toFixed(1) + 's';
        if (remaining <= 0) {
          clearInterval(interval);
          window._tapState.active = false;
          window._tapState.done = true;
          if (tapBtn) tapBtn.disabled = true;
          setTimeout(() => submitGameAndShow('dailytap', {
            disabled: false,
            innerHTML: '',
            closest: () => document.querySelector('.modal-overlay')
          }), 500);
        }
      }, 100);
    }

    function registerTap(btn) {
      if (!window._tapState || !window._tapState.active) return;
      window._tapState.count++;
      btn.style.transform = 'scale(0.9)';
      setTimeout(() => {
        btn.style.transform = 'scale(1)';
      }, 80);
      const el = document.getElementById('tap-count-display');
      if (el) el.textContent = window._tapState.count;
    }

    /* ---- SHARED SUBMIT ---- */
    async function submitGameAndShow(game_type, btn) {
      const overlay = btn.closest ? btn.closest('.modal-overlay') : document.querySelector('.modal-overlay');
      if (overlay) overlay.remove();
      const meta = GAME_META[game_type];
      const res = await api('play_game', {
        game_type,
        display_reward: meta.name + ' Reward'
      });
      if (!res.success) {
        toast(res.message || 'Could not play game', 'error');
        return;
      }
      GameState.data = await api('get_games');
      renderGamesPage();
      showGameResult(res.result, meta);
    }



    function showGameResult(result, meta) {
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay centered';
      const isWin = result.type === 'win';
      const pts = result.points_awarded || 0;
      if (isWin) {
        SFX.tada();
        haptic([40, 20, 60]);
        triggerMiniConfetti(document.querySelector('.games-grid'));
      } else {
        SFX.error();
        haptic(30);
      }
      overlay.innerHTML = `
        <div class="modal-dialog" style="max-width:320px;">
          <div class="game-result-icon">${isWin ? '🎉' : '✨'}</div>
          <div class="game-result-title">${isWin ? 'You Won!' : 'Nice Try!'}</div>
          <div style="text-align:center;background:linear-gradient(135deg,#FFD700,#FF8C00);border-radius:12px;padding:10px 16px;margin:12px 0;color:#fff;font-weight:700;font-size:18px;">
            +${pts} Points Earned! ⭐
          </div>
          <div class="game-result-sub">${isWin ? `${result.actual_discount}% OFF Reward Won! 🎉` : 'Keep playing for bigger rewards!'}</div>
          <button class="btn btn-primary w-full" onclick="this.closest('.modal-overlay').remove()">${isWin ? '🛍️ Shop Now' : 'OK 👍'}</button>
        </div>`;
      document.body.appendChild(overlay);
    }

    function copyToClipboard(text, msg) {
      navigator.clipboard?.writeText(text).then(() => toast(msg || 'Copied!', 'success')).catch(() => {
        const el = document.createElement('textarea');
        el.value = text;
        document.body.appendChild(el);
        el.select();
        document.execCommand('copy');
        el.remove();
        toast(msg || 'Copied!', 'success');
      });
    }

    // ============================================================
    // NAVIGATION
    // ============================================================
    function goToPage(page) {
      haptic(5);
      // Gate pages that require login for guests (dashboard + loyalty + games + profile)
      const authRequired = ['home', 'loyalty', 'games', 'profile'];
      if (authRequired.includes(page) && !State.isLoggedIn) {
        openAuthModal('login', page, null);
        toast('Please sign in to access this section 🔐', 'info');
        return;
      }
      State.currentPage = page;
      document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
      document.querySelectorAll('.bottomnav-item').forEach(n => n.classList.remove('active'));
      document.getElementById('page-' + page)?.classList.add('active');
      document.getElementById('nav-' + page)?.classList.add('active');

      if (page === 'home' && !State.dashboardLoaded) loadDashboard();
      if (page === 'menu' && !State.menuLoaded) loadMenu();
      if (page === 'cart') {
        if (State.isLoggedIn && !State.dashboard) {
          api('get_dashboard').then(data => {
            if (data && data.customer) State.dashboard = data;
            renderCart(); setTimeout(initSwipeDelete, 50);
          }).catch(() => { renderCart(); setTimeout(initSwipeDelete, 50); });
        } else {
          renderCart(); setTimeout(initSwipeDelete, 50);
        }
      }
      if (page === 'games') loadGamesPage();
      if (page === 'loyalty' && !State.loyaltyLoaded) loadLoyalty();
      if (page === 'profile' && !State.profileLoaded) loadProfile();
    }

    // ============================================================
    // DARK MODE
    // ============================================================
    function applyDarkMode(isDark) {
      State.darkMode = isDark;
      document.documentElement.dataset.theme = isDark ? 'dark' : '';
      localStorage.setItem('brewcraft_darkmode', isDark ? '1' : '0');
      // Update all dark mode toggle buttons (topnav + mobile float)
      ['dark-mode-btn', 'dark-mode-btn-mobile'].forEach(id => {
        const btn = document.getElementById(id);
        if (btn) btn.textContent = isDark ? '☀️' : '🌙';
      });
      // Update profile page toggle if visible
      const toggle = document.querySelector('.toggle-switch input[type="checkbox"]');
      if (toggle) toggle.checked = isDark;
    }

    async function toggleDarkMode() {
      const newMode = !State.darkMode;
      // Apply immediately so UI feels instant
      applyDarkMode(newMode);
      toast(newMode ? '🌙 Dark mode on' : '☀️ Light mode on', 'info', 1500);
      // Persist to DB if logged in
      if (State.isLoggedIn) {
        try {
          await api('toggle_dark_mode');
        } catch (e) {
          // DB save failed but localStorage is already updated — not critical
        }
      }
    }

    // ============================================================
    // DASHBOARD
    // ============================================================
    async function loadDashboard() {
      State.dashboardLoaded = true;
      // Dashboard is login-gated; goToPage already redirects guests before reaching here
      const el = document.getElementById('dashboard-content');
      // Show skeleton loader instead of spinner text for perceived speed
      el.innerHTML = `
        <div style="padding:4px 0;animation:pulse 1.4s ease-in-out infinite;">
          <div class="skel skel-card" style="height:110px;margin-bottom:16px;"></div>
          <div class="skel skel-card" style="height:70px;margin-bottom:16px;"></div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px;">
            <div class="skel skel-card" style="height:88px;"></div>
            <div class="skel skel-card" style="height:88px;"></div>
          </div>
          <div class="skel skel-line" style="width:50%;margin-bottom:14px;"></div>
          <div style="display:flex;gap:12px;overflow:hidden;">
            <div class="skel" style="width:148px;height:130px;border-radius:var(--radius);flex-shrink:0;"></div>
            <div class="skel" style="width:148px;height:130px;border-radius:var(--radius);flex-shrink:0;"></div>
            <div class="skel" style="width:148px;height:130px;border-radius:var(--radius);flex-shrink:0;"></div>
          </div>
        </div>
        <style>@keyframes pulse{0%,100%{opacity:1}50%{opacity:0.45}}</style>`;
      try {
        const data = await Promise.race([
          api('get_dashboard'),
          new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), 10000))
        ]);
        if (!data || !data.customer) {
          // Session may have expired — check if server says not authenticated
          if (data && data.message === 'Not authenticated') {
            el.innerHTML = `<div class="cart-empty"><div class="cart-empty-icon">🔐</div><h3>Session Expired</h3><p>Please sign in again to view your dashboard.</p><button class="btn btn-primary mt-16" style="width:auto;padding:12px 28px;" onclick="openAuthModal('login')">Sign In</button></div>`;
            State.isLoggedIn = false;
          } else {
            el.innerHTML = `<div class="cart-empty"><div class="cart-empty-icon">⚠️</div><h3>Could not load dashboard</h3><p>Please try refreshing the page.</p><button class="btn btn-primary mt-16" style="width:auto;padding:12px 28px;" onclick="State.dashboardLoaded=false;loadDashboard()">Retry</button></div>`;
          }
          return;
        }
        State.dashboard = data;
        renderDashboard(data);
        // Update topnav points chip
        const pts = document.getElementById('topnav-points');
        if (pts) pts.textContent = data.customer.points + ' 🪙';
        // Check for tier upgrade
        checkTierUpgrade(data.customer.membership_level);
      } catch (e) {
        const isTimeout = e && e.message === 'timeout';
        el.innerHTML = `<div class="cart-empty"><div class="cart-empty-icon">⚠️</div><h3>${isTimeout ? 'Server Timeout' : 'Connection Error'}</h3><p>${isTimeout ? 'The server took too long to respond.' : 'Could not reach the server. Check your connection.'}</p><button class="btn btn-primary mt-16" style="width:auto;padding:12px 28px;" onclick="State.dashboardLoaded=false;loadDashboard()">Retry</button></div>`;
      }
    }

    function renderDashboard(data) {
      const c = data.customer;
      const level = c.membership_level;
      const levelColors = {
        Bronze: 'badge-bronze',
        Silver: 'badge-silver',
        Gold: 'badge-gold',
        Platinum: 'badge-gold'
      };
      const tierOrder = ['Bronze', 'Silver', 'Gold', 'Platinum'];
      const tierIdx = tierOrder.indexOf(level);
      const nextTier = LOYALTY.TIERS[tierOrder[tierIdx + 1]] || null;
      const nextTierName = tierOrder[tierIdx + 1] || null;
      const nextLevelPts = nextTier ? nextTier.min : null;
      const progress = nextLevelPts ? Math.min((c.points / nextLevelPts) * 100, 100) : 100;
      const ptsNeeded = nextLevelPts ? Math.max(0, nextLevelPts - c.points) : 0;

      // Rupee value of points (40 pts = ₹1)
      const pointsRupeeValue = (c.points / LOYALTY.POINTS_REDEEM_RATE).toFixed(2);

      let birthdayHtml = '';
      if (data.birthday_reward) {
        birthdayHtml = `
      <div class="birthday-banner">
        <div class="birthday-icon">🎂</div>
        <div class="birthday-text">
          <h3>Happy Birthday, ${esc(c.full_name.split(' ')[0])}!</h3>
          <p>🎁 You've received 300 bonus C3 Coins on your birthday!</p>
        </div>
      </div>`;
      }

      // Daily login bonus button
      const todayStr = new Date().toISOString().split('T')[0];
      const canClaimLogin = c.last_login_bonus_date !== todayStr;

      let recentOrdersHtml = '';
      if (data.recent_orders.length === 0) {
        recentOrdersHtml = `<div class="text-center" style="padding:20px 0;color:var(--text-muted);font-size:14px;">No orders yet. Explore the menu!</div>`;
      } else {
        recentOrdersHtml = data.recent_orders.map(o => {
          const items = JSON.parse(o.items_json || '[]');
          const itemNames = items.slice(0, 2).map(i => i.name).join(', ') + (items.length > 2 ? ' +more' : '');
          return `
        <div class="order-item">
          <div class="order-icon">🧾</div>
          <div class="order-info">
            <div class="order-num">#${esc(o.order_number)}</div>
            <div class="order-date">${esc(itemNames)} · ${timeAgo(o.created_at)}</div>
          </div>
          <div class="order-right">
            <div class="order-total">${fmt(o.total)}</div>
            <div class="order-status status-${o.status}">${o.status}</div>
          </div>
        </div>`;
        }).join('');
      }

      let rewardsHtml = data.rewards.map(r => {
        const canRedeem = c.points >= r.points_required;
        const pct = Math.min((c.points / r.points_required) * 100, 100);
        const almostThere = !canRedeem && pct >= 70;
        return `
      <div class="reward-card-mini ${canRedeem ? '' : 'locked'}" onclick="${canRedeem ? `openRedeemModal(${r.id},'${esc(r.name)}',${r.points_required})` : ''}">
        <div class="r-icon">${r.reward_type === 'freeitem' ? '🎁' : '🏷️'}</div>
        <div class="r-name">${esc(r.name)}</div>
        <div class="r-pts">${r.points_required} pts</div>
        ${almostThere ? `<div style="width:100%;background:var(--border);border-radius:4px;height:3px;margin:3px 0;"><div style="width:${pct}%;height:3px;background:var(--gold);border-radius:4px;"></div></div><div style="font-size:9px;color:var(--gold);font-weight:700;">Almost there!</div>` : ''}
        <span class="r-status ${canRedeem ? 'available' : 'locked'}">${canRedeem ? 'Redeem' : '🔒'}</span>
      </div>`;
      }).join('');

      let recHtml = data.recommended.map(item => `
    <div class="rec-item-card" onclick="addToCart(${item.id},'${esc(item.name)}',${item.price},'${esc(item.image_url||'')}')">
      ${item.image_url ? `<img class="rec-item-img" src="${esc(item.image_url)}" alt="${esc(item.name)}" loading="lazy" onerror="this.style.display='none'">` : '<div class="rec-item-img" style="background:var(--bg-secondary);display:flex;align-items:center;justify-content:center;font-size:28px;">☕</div>'}
      <div class="rec-item-body">
        <div class="rec-item-name">${esc(item.name)}</div>
        <div class="rec-item-price">${fmt(item.price)}</div>
      </div>
    </div>`).join('');

      document.getElementById('dashboard-content').innerHTML = `
    ${birthdayHtml}
    <div class="welcome-banner">
      <div class="welcome-greeting">Good ${getGreeting()},</div>
      <div class="welcome-name">${esc(c.full_name)}</div>
      <div class="welcome-sub">✨ ${getMemberIcon(level)} ${level} Member · ${level === 'Platinum' ? 'Max tier achieved! 💎' : `${ptsNeeded} pts to ${nextTierName}`}</div>
    </div>

    <!-- Glowing coin counter -->
    <div class="coin-counter-card" onclick="goToPage('loyalty')">
      <div class="coin-counter-left">
        <div class="coin-anim">🪙</div>
        <div>
          <div class="coin-pts" id="coin-pts-display">${c.points}</div>
          <div class="coin-label">C3 Coins · Worth ₹${pointsRupeeValue}</div>
        </div>
      </div>
      <div class="coin-counter-right">
        <span class="membership-badge ${levelColors[level]}">${getMemberIcon(level)} ${level}</span>
        <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Tap to redeem →</div>
      </div>
    </div>

    <!-- Daily Login Bonus -->
    ${canClaimLogin ? `
    <div class="daily-bonus-banner" id="daily-bonus-btn" onclick="claimDailyBonus()">
      <span style="font-size:20px;">🌟</span>
      <div style="flex:1;">
        <div style="font-weight:700;font-size:14px;">Daily Login Bonus!</div>
        <div style="font-size:12px;opacity:0.85;">Tap to claim 10–30 free C3 Coins today</div>
      </div>
      <span style="font-size:18px;font-weight:800;">→</span>
    </div>` : `
    <div class="daily-bonus-banner" style="opacity:0.5;cursor:default;">
      <span style="font-size:20px;">✅</span>
      <div style="flex:1;"><div style="font-weight:700;font-size:14px;">Daily Bonus Claimed!</div><div style="font-size:12px;opacity:0.85;">Come back tomorrow for more coins</div></div>
    </div>`}

    ${nextLevelPts ? `
    <div class="card mb-20">
      <div class="card-pad">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
          <span class="text-sm font-bold">${getMemberIcon(level)} ${level} → ${getMemberIcon(nextTierName)} ${nextTierName}</span>
          <span class="text-sm text-muted">${c.points} / ${nextLevelPts} pts</span>
        </div>
        <div class="progress-bar-wrap"><div class="progress-bar-fill" style="width:${progress}%;transition:width 1s ease;"></div></div>
        <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-secondary);margin-top:6px;">
          <span>🎯 ${ptsNeeded} more points to unlock <strong>${nextTierName}</strong></span>
          <span style="color:var(--primary);font-weight:700;">${Math.round(progress)}%</span>
        </div>
      </div>
    </div>` : '<div class="card mb-20"><div class="card-pad" style="text-align:center;color:var(--gold);font-weight:700;">💎 You\'ve reached the highest tier — Platinum!</div></div>'}

    <div class="section-title">
      🏆 Rewards for You
      <span class="see-all" onclick="goToPage('loyalty')">See all →</span>
    </div>
    <div class="reward-cards-row">${rewardsHtml}</div>

    <div class="section-title mt-20">
      Recent Orders
      <span class="see-all" onclick="goToPage('profile')">See all →</span>
    </div>
    <div class="card mb-20">
      <div class="card-pad" style="padding-top:4px;padding-bottom:4px;">${recentOrdersHtml}</div>
    </div>

    <div class="section-title">
      Recommended for You
      <span class="see-all" onclick="goToPage('menu')">View menu →</span>
    </div>
    <div class="rec-items-row">${recHtml}</div>
  `;
      // Animate coin counter
      animateCoinCount('coin-pts-display', 0, c.points, 1200);
    }

    function getGreeting() {
      const h = new Date().getHours();
      if (h < 12) return 'Morning';
      if (h < 17) return 'Afternoon';
      return 'Evening';
    }

    function getMemberIcon(level) {
      return {
        Bronze: '🥉',
        Silver: '🥈',
        Gold: '🥇',
        Platinum: '💎'
      } [level] || '🥉';
    }

    // Animated coin counter (dopamine hit)
    function animateCoinCount(elId, from, to, duration) {
      const el = document.getElementById(elId);
      if (!el) return;
      const start = Date.now();
      const diff = to - from;

      function step() {
        const elapsed = Date.now() - start;
        const progress = Math.min(elapsed / duration, 1);
        const ease = 1 - Math.pow(1 - progress, 3);
        el.textContent = Math.round(from + diff * ease);
        if (progress < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    }

    // Confetti burst (lightweight)
    function triggerConfetti() {
      const colors = ['#1a5c38', '#F59E0B', '#10B981', '#EC4899', '#3B82F6'];
      for (let i = 0; i < 32; i++) {
        const dot = document.createElement('div');
        dot.style.cssText = `position:fixed;pointer-events:none;z-index:99999;width:8px;height:8px;border-radius:50%;
          background:${colors[i%colors.length]};top:${40+Math.random()*20}%;left:${30+Math.random()*40}%;
          animation:confettiFall ${0.8+Math.random()*0.8}s ease-out forwards;
          transform:translate(${(Math.random()-0.5)*200}px,${-50-Math.random()*100}px) rotate(${Math.random()*360}deg);`;
        document.body.appendChild(dot);
        setTimeout(() => dot.remove(), 1600);
      }
    }

    async function claimDailyBonus() {
      const btn = document.getElementById('daily-bonus-btn');
      if (btn) btn.style.opacity = '0.6';
      const res = await api('claim_daily_bonus');
      if (res.success) {
        SFX.coin();
        haptic([30, 20, 30]);
        triggerConfetti();
        floatPoints(res.bonus, btn);
        toast(`🌟 +${res.bonus} C3 Coins credited! Keep the streak alive!`, 'success', 3500);
        State.dashboardLoaded = false;
        State.loyaltyLoaded = false;
        await loadDashboard();
        // Check tier upgrade
        if (State.dashboard && State.dashboard.customer) {
          checkTierUpgrade(State.dashboard.customer.membership_level);
        }
      } else if (res.already_claimed) {
        toast('✅ Already claimed today! Come back tomorrow 🌅', 'info');
      } else {
        toast(res.message || 'Try again', 'error');
      }
    }

    // ============================================================
    // MENU
    // ============================================================
    async function loadMenu() {
      State.menuLoaded = true;
      const data = await api('get_menu');
      State.menu = data;
      // Update Instagram URL from settings
      if (data.settings && data.settings.instagram_url) {
        State.instagramUrl = data.settings.instagram_url;
        const igBtn = document.getElementById('ig-float-btn');
        if (igBtn) igBtn.href = State.instagramUrl;
      }
      renderMenu();
      // Start delayed rewards popup timer for guests
      if (!State.isLoggedIn && !State.rewardsPopupDismissed) {
        startRewardsPopupTimer();
      }
    }

    function renderMenu() {
      const {
        categories,
        items,
        combos
      } = State.menu;
      const q = State.searchQuery.toLowerCase();
      const cat = State.activeCategory;

      let filteredItems = items.filter(item => {
        const matchSearch = !q || item.name.toLowerCase().includes(q) || (item.description || '').toLowerCase().includes(q);
        const matchCat = cat === 'all' || item.category_id == cat;
        return matchSearch && matchCat;
      });

      // Group by category
      const grouped = {};
      filteredItems.forEach(item => {
        const cname = item.category_name || 'Other';
        if (!grouped[cname]) grouped[cname] = [];
        grouped[cname].push(item);
      });

      const catTabsHtml = `
    <div class="category-tabs" id="cat-tabs">
      <div class="cat-tab ${cat==='all'?'active':''}" onclick="filterCategory('all')">All ✨</div>
      ${categories.map(c => `<div class="cat-tab ${cat==c.id?'active':''}" onclick="filterCategory(${c.id})">${c.icon} ${esc(c.name)}</div>`).join('')}
    </div>`;

      let itemsHtml = '';
      if (filteredItems.length === 0) {
        itemsHtml = `<div style="text-align:center;padding:48px 20px;">
      <div style="font-size:48px;margin-bottom:12px;opacity:0.4">🔍</div>
      <h3 style="margin-bottom:8px;">Nothing found</h3>
      <p style="color:var(--text-secondary);font-size:14px;">Try a different search or category</p>
    </div>`;
      } else {
        Object.entries(grouped).forEach(([catName, catItems]) => {
          itemsHtml += `<div class="menu-section"><div class="menu-section-title">${esc(catName)}</div>`;
          catItems.forEach(item => {
            const cartItem = State.cart.find(c => c.id == item.id);
            const inCart = cartItem && cartItem.qty > 0;
            itemsHtml += `
         <div class="menu-item-card" id="menu-item-${item.id}">
  ${item.image_url
    ? `<img 
        class="menu-item-img" 
        src="${esc(item.image_url)}" 
        alt="${esc(item.name)}" 
        loading="lazy"
        onerror="this.outerHTML='<div class=&quot;menu-item-img-placeholder&quot;>☕</div>'"
      >`
    : `<div class="menu-item-img-placeholder">☕</div>`}

  <div class="menu-item-body">
    <div class="menu-item-name">${esc(item.name)}</div>
    <div class="menu-item-desc">${esc(item.description || '')}</div>

    <div class="menu-item-footer">
      <div class="menu-item-price">${fmt(item.price)}</div>

      <div id="cart-ctrl-${item.id}">
        ${inCart
          ? `<div class="qty-control">
              <button class="qty-btn" onclick="updateQty(${item.id},-1)">−</button>
              <span class="qty-num">${cartItem.qty}</span>
              <button class="qty-btn" onclick="updateQty(${item.id},1)">+</button>
            </div>`
          : `<div class="add-btn" onclick="addToCart(${item.id}, '${esc(item.name).replace(/'/g, "\\'")}', ${item.price}, '${esc(item.image_url || '')}', this)" oncontextmenu="event.preventDefault();openCustomiseModal(${item.id},'${esc(item.name).replace(/'/g,"\\'")}',${item.price},'${esc(item.image_url||'')}')">+</div>`
        }
      </div>
    </div>
  </div>
</div>`;
          });
          itemsHtml += '</div>';
        });
      }

      // Combos
      let combosHtml = '';
      if (combos.length > 0 && !q && cat === 'all') {
        combosHtml = `
      <div class="menu-section">
        <div class="menu-section-title">🎁 Combo Offers</div>
        ${combos.map(combo => {
          const save = combo.original_price - combo.combo_price;
          return `
          <div class="combo-card">
            ${combo.image_url ? `<img class="combo-card-img" src="${esc(combo.image_url)}" loading="lazy" onerror="this.src='https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?w=400&q=80'">` : '<div class="combo-card-img" style="background:var(--bg-secondary);display:flex;align-items:center;justify-content:center;font-size:40px;">🎁</div>'}
            <div class="combo-card-overlay">
              <div class="combo-name">${esc(combo.name)}</div>
              <div class="combo-desc">${esc(combo.description||'')}</div>
              <div class="combo-prices">
                <span class="combo-original">${fmt(combo.original_price)}</span>
                <span class="combo-price">${fmt(combo.combo_price)}</span>
                <span class="combo-save">Save ${fmt(save)}</span>
              </div>
              <div id="combo-cart-ctrl-${combo.id}" style="margin-top:10px;">
                ${(()=>{ const cid='combo_'+combo.id; const ci=State.cart.find(c=>c.id===cid); return ci && ci.qty>0
                  ? `<div class="qty-control" style="background:rgba(255,255,255,0.15);border-radius:20px;padding:2px 6px;">
                      <button class="qty-btn" onclick="updateComboQty('${cid}',-1)" style="color:#fff;">−</button>
                      <span class="qty-num" style="color:#fff;min-width:20px;text-align:center;">${ci.qty}</span>
                      <button class="qty-btn" onclick="updateComboQty('${cid}',1)" style="color:#fff;">+</button>
                    </div>`
                  : `<button class="btn btn-primary" onclick="addComboToCart(${combo.id},'${esc(combo.name).replace(/'/g,"\\'")}',${combo.combo_price},'${esc(combo.image_url||'')}',this)" style="padding:8px 20px;font-size:13px;border-radius:20px;background:rgba(255,255,255,0.25);border:1.5px solid rgba(255,255,255,0.7);color:#fff;backdrop-filter:blur(4px);">🛒 Add to Cart</button>`
                })()}
              </div>
            </div>
          </div>`;
        }).join('')}
      </div>`;
      }

      // On first load, render the full shell (search bar + category tabs + results container)
      // On subsequent calls (search/filter), only update the results container to preserve focus
      const menuContent = document.getElementById('menu-content');
      if (!document.getElementById('menu-search')) {
        menuContent.innerHTML = `
    <div class="search-bar-wrap">
      <span class="search-icon">🔍</span>
      <input type="text" class="search-bar" placeholder="Search coffee, snacks..." id="menu-search">
    </div>
    <div id="search-chips" class="search-chips"></div>
    ${catTabsHtml}
    <div id="menu-results"></div>
  `;
        const searchInput = document.getElementById('menu-search');
        searchInput.value = State.searchQuery;
        searchInput.addEventListener('input', function() {
          State.searchQuery = this.value;
          renderMenu();
        });
        searchInput.addEventListener('blur', function() {
          if (this.value.length >= 2) { addToSearchHistory(this.value); renderSearchChips(); }
        });
        renderSearchChips();
      } else {
        // Update only the category tabs active state without touching search input
        const catTabsEl = document.getElementById('cat-tabs');
        if (catTabsEl) catTabsEl.outerHTML = catTabsHtml;
      }

      // Always update just the results area
      const resultsEl = document.getElementById('menu-results');
      if (resultsEl) resultsEl.innerHTML = combosHtml + itemsHtml;
    }

    function searchMenu(q) {
      State.searchQuery = q;
      State.menuLoaded = true;
      renderMenu();
    }

    function filterCategory(cat) {
      State.activeCategory = cat;
      renderMenu();
      // Scroll to top of menu
      document.getElementById('page-menu').scrollIntoView({
        behavior: 'smooth',
        block: 'start'
      });
    }

    // ============================================================
    // CART
    // ============================================================
    function _origAddToCart(id, name, price, image) {
      const existing = State.cart.find(c => c.id == id);
      if (existing) {
        existing.qty++;
      } else {
        State.cart.push({
          id,
          name,
          price: parseFloat(price),
          image,
          qty: 1
        });
        // Trigger rewards popup on first add-to-cart for guests
        if (!State.isLoggedIn && !State.rewardsPopupDismissed) {
          showRewardsPopup();
        }
      }
      saveCart();
      updateCartBadge();
      updateMenuCartCtrl(id);
      toast(`${name} added to cart 🛒`, 'success', 1500);
    }

    function updateQty(id, delta) {
      haptic(6);
      const item = State.cart.find(c => c.id == id);
      if (!item) return;
      item.qty += delta;
      if (item.qty <= 0) {
        State.cart = State.cart.filter(c => c.id != id);
      }
      saveCart();
      updateCartBadge();
      updateMenuCartCtrl(id);
      if (State.currentPage === 'cart') renderCart();
    }

    function removeFromCart(id) {
      State.cart = State.cart.filter(c => c.id != id);
      saveCart();
      updateCartBadge();
      updateMenuCartCtrl(id);
      renderCart();
      toast('Item removed', 'info', 1500);
    }

    function updateMenuCartCtrl(id) {
      const ctrl = document.getElementById(`cart-ctrl-${id}`);
      if (!ctrl) return;
      const cartItem = State.cart.find(c => c.id == id);
      const item = State.menu.items.find(i => i.id == id);
      if (!item) return;
      if (cartItem && cartItem.qty > 0) {
        ctrl.innerHTML = `<div class="qty-control">
      <button class="qty-btn" onclick="updateQty(${id},-1)">−</button>
      <span class="qty-num">${cartItem.qty}</span>
      <button class="qty-btn" onclick="updateQty(${id},1)">+</button>
    </div>`;
      } else {
        ctrl.innerHTML = `<div class="add-btn" onclick="addToCart(${id},'${esc(item.name).replace(/'/g,"\\'")}',${item.price},'${esc(item.image_url||'')}')">+</div>`;
      }
    }

    function renderCart() {
      const cart = State.cart;
      const subtotal = getCartTotal();
      const maxRedeemRupees = subtotal * (LOYALTY.MAX_REDEEM_PERCENT / 100);
      const maxPointsAllowed = Math.floor(maxRedeemRupees * LOYALTY.POINTS_REDEEM_RATE);
      const availablePoints = State.dashboard ? State.dashboard.customer.points : 0;
      const maxPointsDiscount = Math.min(availablePoints, maxPointsAllowed);
      const pointsLoading = State.isLoggedIn && !State.dashboard;

      if (cart.length === 0) {
        document.getElementById('cart-content').innerHTML = `
      <div class="cart-empty">
        <div class="cart-empty-icon">🛒</div>
        <h3>Your cart is empty</h3>
        <p>Add some delicious items from our menu</p>
        <button class="btn btn-primary mt-16" style="width:auto;padding:12px 28px;" onclick="goToPage('menu')">Explore Menu</button>
      </div>`;
        return;
      }

      const totalAfterDiscount = Math.max(0, subtotal - State.pointsToUse / LOYALTY.POINTS_REDEEM_RATE);

      document.getElementById('cart-content').innerHTML = `
    <div class="section-title">Your Cart</div>

    <div class="order-type-toggle">
      <button class="order-type-btn ${State.orderType==='dine-in'?'active':''}" onclick="setOrderType('dine-in')">🍽️ Dine-In</button>
      <button class="order-type-btn ${State.orderType==='pickup'?'active':''}" onclick="setOrderType('pickup')">🥡 Pickup</button>
    </div>

    <div class="card mb-16">
      <div class="card-header">
        <span class="card-title">Items (${cart.length})</span>
        <span style="font-size:13px;color:var(--text-secondary);">${getCartCount()} qty</span>
      </div>
      <div class="card-pad" style="padding-top:4px;padding-bottom:4px;">
        ${cart.map(item => `
          <div class="cart-item-wrap">
            <div class="cart-item-delete-bg">🗑️</div>
            <div class="cart-item-swipeable cart-item" data-item-id="${item.id}">
              ${item.image ? `<img class="cart-item-img" src="${esc(item.image)}" onerror="this.style.display='none'">` : `<div class="cart-item-img" style="background:var(--bg-secondary);display:flex;align-items:center;justify-content:center;font-size:20px;">${item.isCombo ? '🎁' : '☕'}</div>`}
              <div class="cart-item-info">
                <div class="cart-item-name">${esc(item.name)}</div>
                <div class="cart-item-price">${fmt(item.price)} × ${item.qty} = ${fmt(item.price*item.qty)}</div>
              </div>
              <div class="qty-control">
                <button class="qty-btn" onclick="${item.isCombo ? `updateComboQty('${item.id}',-1)` : `updateQty(${item.id},-1)`}">−</button>
                <span class="qty-num">${item.qty}</span>
                <button class="qty-btn" onclick="${item.isCombo ? `updateComboQty('${item.id}',1)` : `updateQty(${item.id},1)`}">+</button>
              </div>
            </div>
          </div>`).join('')}
      </div>
    </div>

    ${pointsLoading ? `
    <div class="card mb-16">
      <div class="card-pad" style="text-align:center;padding:18px;">
        <div style="font-size:13px;color:var(--text-secondary);">🪙 Loading your C3 Coins...</div>
      </div>
    </div>` : maxPointsDiscount > 0 ? `
    <div class="card mb-16">
      <div class="card-pad">
        <div>
          <div class="section-title" style="font-size:14px;margin-bottom:0;">🪙 Use C3 Coins</div>
          <span style="font-size:13px;color:var(--text-secondary);">${availablePoints} coins available · Max ${LOYALTY.MAX_REDEEM_PERCENT}% of order</span>
        </div>
        <div class="points-slider-wrap">
          <input type="range" class="points-slider" min="0" max="${maxPointsDiscount}" value="${State.pointsToUse}" step="10" oninput="updatePointsUse(this.value)" id="points-range">
          <div style="display:flex;justify-content:space-between;margin-top:8px;font-size:13px;">
            <span>Using: <strong>${State.pointsToUse} coins (${fmt(State.pointsToUse / LOYALTY.POINTS_REDEEM_RATE)})</strong></span>
            <span style="color:var(--text-muted)">Max: ${maxPointsDiscount} coins</span>
          </div>
          <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">40 C3 Coins = ₹1 discount</div>
        </div>
      </div>
    </div>` : ''}

    <div class="card mb-16">
      <div class="card-pad">
        <div class="section-title" style="font-size:14px;margin-bottom:12px;">Order Summary</div>
        <div class="order-summary-row"><span>Subtotal</span><span>${fmt(subtotal)}</span></div>
        ${State.pointsToUse > 0 ? `<div class="order-summary-row points"><span>C3 Coins (${State.pointsToUse} coins)</span><span>-${fmt(State.pointsToUse / LOYALTY.POINTS_REDEEM_RATE)}</span></div>` : ''}
        <div class="order-summary-row total"><span>Total</span><span>${fmt(totalAfterDiscount)}</span></div>
        <div style="font-size:12px;color:var(--success);margin-top:8px;">🪙 You'll earn ~${Math.floor(totalAfterDiscount)} C3 Coins on this order</div>
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Special Instructions (optional)</label>
      <input type="text" class="form-input" id="order-notes" placeholder="e.g. Less sugar, extra hot...">
    </div>

    <button class="btn btn-whatsapp w-full mb-8" onclick="placeOrderWhatsapp()">
      📱 Order via WhatsApp
    </button>
    <button class="btn btn-primary w-full" onclick="placeOrder()">
      ✅ Confirm Order — ${fmt(totalAfterDiscount)}
    </button>
    ${!State.isLoggedIn ? `
    <div style="margin-top:14px;background:var(--bg-secondary);border-radius:var(--radius);padding:14px;text-align:center;border:1.5px dashed var(--primary-light);">
      <div style="font-size:13px;font-weight:700;color:var(--primary);margin-bottom:4px;">🪙 Earn C3 Coins on this order!</div>
      <div style="font-size:12px;color:var(--text-secondary);margin-bottom:10px;">Sign up free — get 100 welcome coins + earn on this order</div>
      <button class="btn btn-primary" style="padding:10px 20px;width:auto;" onclick="openAuthModal('signup','cart','placeOrder')">Join & Earn Coins</button>
    </div>` : ''}
    <div style="text-align:center;margin-top:10px;font-size:12px;color:var(--text-muted);">🏪 ${State.orderType === 'dine-in' ? 'Dine-In' : 'Pickup'} · No delivery</div>
  `;
    }

    function setOrderType(type) {
      State.orderType = type;
      renderCart();
      setTimeout(initSwipeDelete, 50);
    }

    function updatePointsUse(val) {
      State.pointsToUse = parseInt(val) || 0;
      const discRow = document.querySelector('.order-summary-row.points');
      const totalRow = document.querySelector('.order-summary-row.total span:last-child');
      const subtotal = getCartTotal();
      const total = Math.max(0, subtotal - State.pointsToUse / LOYALTY.POINTS_REDEEM_RATE);
      if (totalRow) totalRow.textContent = fmt(total);
      const ptslabel = document.querySelector('.points-slider-wrap strong');
      if (ptslabel) ptslabel.textContent = `${State.pointsToUse} coins (${fmt(State.pointsToUse / LOYALTY.POINTS_REDEEM_RATE)})`;
      if (discRow) discRow.querySelector('span:last-child').textContent = `-${fmt(State.pointsToUse / LOYALTY.POINTS_REDEEM_RATE)}`;
      if (discRow) discRow.querySelector('span:first-child').textContent = `C3 Coins (${State.pointsToUse} coins)`;
    }

    async function placeOrder() {
      if (State.cart.length === 0) {
        toast('Cart is empty', 'warning');
        return;
      }
      if (!State.isLoggedIn) {
        openAuthModal('login', 'cart', 'placeOrder');
        toast('Please sign in to place your order — your cart is saved! 🛒', 'info');
        return;
      }
      const notes = document.getElementById('order-notes')?.value || '';
      const items = State.cart.map(i => ({
        id: i.id,
        name: i.name,
        price: i.price,
        qty: i.qty
      }));
      const res = await api('place_order', {
        items,
        points_use: State.pointsToUse,
        order_type: State.orderType,
        notes
      });
      if (res.success) {
        State.cart = [];
        State.pointsToUse = 0;
        saveCart();
        updateCartBadge();
        State.dashboardLoaded = false;
        State.loyaltyLoaded = false;
        showOrderSuccessModal(res.order_number, res.points_earned, res.total);
      } else {
        toast(res.message || 'Order failed', 'error');
      }
    }

    function placeOrderWhatsapp() {
      const subtotal = getCartTotal();
      const total = Math.max(0, subtotal - State.pointsToUse / LOYALTY.POINTS_REDEEM_RATE);
      const items = State.cart.map(i => `• ${i.name} x${i.qty} = ${fmt(i.price*i.qty)}`).join('\n');
      const notes = document.getElementById('order-notes')?.value || '';
      const msg = `🛒 *New Order - C3 Restaurant*\n\n${items}\n\n*Subtotal:* ${fmt(subtotal)}\n${State.pointsToUse>0?`*C3 Coins (${State.pointsToUse}):* -${fmt(State.pointsToUse/LOYALTY.POINTS_REDEEM_RATE)}\n`:''}*Total:* ${fmt(total)}\n\n*Type:* ${State.orderType}\n${notes?`*Notes:* ${notes}`:''}`;
      window.open(`https://wa.me/?text=${encodeURIComponent(msg)}`, '_blank');
    }

    function showOrderSuccessModal(orderNum, pointsEarned, total, orderType) {
      SFX.success();
      haptic([50, 30, 80, 30, 100]);
      triggerConfetti();
      // Start countdown timer on main screen
      startOrderCountdown(orderNum, orderType || State.orderType || 'dine-in');
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay centered';
      overlay.innerHTML = `
    <div class="modal-dialog" style="text-align:center;">
      <div style="font-size:56px;margin-bottom:12px;animation:scaleIn 0.5s cubic-bezier(0.34,1.56,0.64,1)">🎉</div>
      <h2 style="font-family:var(--font-display);font-size:22px;margin-bottom:8px;">Order Placed!</h2>
      <p style="color:var(--text-secondary);font-size:14px;margin-bottom:20px;">Your order has been confirmed</p>
      <div style="background:var(--bg-secondary);border-radius:var(--radius-sm);padding:16px;margin-bottom:20px;">
        <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px;">Order Number</div>
        <div style="font-size:22px;font-weight:800;letter-spacing:2px;color:var(--primary)">#${orderNum}</div>
        <div style="font-size:13px;color:var(--success);margin-top:8px;">🪙 +${pointsEarned} C3 Coins earned!</div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">Worth ₹${(pointsEarned/20).toFixed(2)} towards your next order</div>
        <div style="font-size:13px;color:var(--text-secondary);margin-top:4px;">Total: ${fmt(total)}</div>
        <div style="font-size:12px;color:var(--primary);margin-top:8px;font-weight:600;">⏱️ Est. ${State.orderType==='pickup'?'10':'15'} min · Timer shown on screen</div>
      </div>
      <button class="btn btn-primary" onclick="this.closest('.modal-overlay').remove();goToPage('home')">Back to Home 🏠</button>
    </div>`;
      document.body.appendChild(overlay);
      // Float points near coin chip
      setTimeout(() => {
        if (pointsEarned > 0) floatPoints(pointsEarned, document.querySelector('.points-chip'));
      }, 600);
    }

    // ============================================================
    // LOYALTY
    // ============================================================
    async function loadLoyalty() {
      State.loyaltyLoaded = true;
      if (!State.dashboard) {
        const data = await api('get_dashboard');
        State.dashboard = data;
      }
      const data = State.dashboard;
      const c = data.customer;
      const tierOrder = ['Bronze', 'Silver', 'Gold', 'Platinum'];
      const tierIdx = tierOrder.indexOf(c.membership_level);
      const nextTierName = tierOrder[tierIdx + 1] || null;
      const nextTier = nextTierName ? LOYALTY.TIERS[nextTierName] : null;
      const nextLevelPts = nextTier ? nextTier.min : null;
      const pointsRupeeValue = (c.points / LOYALTY.POINTS_REDEEM_RATE).toFixed(2);

      const txnHtml = data.transactions.length === 0 ?
        '<div style="text-align:center;padding:20px;color:var(--text-muted);font-size:14px;">No transactions yet</div>' :
        data.transactions.map(t => {
          const isPos = t.points > 0;
          const typeIcon = {
            earn: '💰',
            redeem: '🔄',
            bonus: '🎁',
            birthday: '🎂',
            referral: '👥',
            login: '🌟',
            streak: '🔥'
          } [t.type] || '⭐';
          return `
        <div class="transaction-item">
          <div class="txn-icon txn-${t.type}">${typeIcon}</div>
          <div class="txn-info">
            <div class="txn-desc">${esc(t.description)}</div>
            <div class="txn-date">${timeAgo(t.created_at)}</div>
          </div>
          <div class="txn-pts ${isPos?'positive':'negative'}">${isPos?'+':''}${t.points}</div>
        </div>`;
        }).join('');

      const rewardsHtml = data.rewards.map(r => {
        const canRedeem = c.points >= r.points_required;
        const progress = Math.min((c.points / r.points_required) * 100, 100);
        const almostThere = !canRedeem && progress >= 60;
        return `
      <div class="card mb-12" style="transition:var(--transition-bounce);">
        <div class="card-pad" style="display:block;">
          <div style="display:block;align-items:center;gap:14px;margin-bottom:10px;">
            <div style="width:52px;height:52px;border-radius:14px;background:${canRedeem?'var(--grad)':'var(--bg-secondary)'};display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;${canRedeem?'box-shadow:var(--shadow-green)':''}">${r.reward_type==='freeitem'?'🎁':'🏷️'}</div>
            <div style="flex:1;min-width:0;">
              <div style="font-weight:700;font-size:15px;">${esc(r.name)}</div>
              <div style="font-size:12px;color:var(--text-secondary);">${esc(r.description)}</div>
            </div>
            <button class="btn ${canRedeem?'btn-primary':'btn-ghost'} btn-sm" style="flex-shrink:0;" ${canRedeem?`onclick="openRedeemModal(${r.id},'${esc(r.name)}',${r.points_required})"`:''} ${!canRedeem?'disabled':''}>
              ${canRedeem?'🎁 Redeem':'🔒'}
            </button>
          </div>
          <div class="progress-bar-wrap" style="margin:0 0 4px;"><div class="progress-bar-fill" style="width:${progress}%;transition:width 1.2s ease;"></div></div>
          <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);">
            <span>${c.points} / ${r.points_required} coins</span>
            ${almostThere ? `<span style="color:var(--gold);font-weight:700;">⚡ Almost there! ${r.points_required - c.points} more coins</span>` : `<span>${Math.round(progress)}% complete</span>`}
          </div>
        </div>
      </div>`;
      }).join('');

      // Tier roadmap
      const tiersHtml = tierOrder.map((t, i) => {
        const tier = LOYALTY.TIERS[t];
        const isActive = c.membership_level === t;
        const isUnlocked = tierIdx >= i;
        return `
        <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--border-light);">
          <div style="font-size:24px;opacity:${isUnlocked?1:0.3};">${tier.icon}</div>
          <div style="flex:1;">
            <div style="font-weight:700;font-size:14px;${isActive?'color:var(--primary)':''}">${t} ${isActive?'← You are here':''}</div>
            <div style="font-size:12px;color:var(--text-muted);">${tier.benefits}</div>
          </div>
          <div style="font-size:12px;font-weight:700;color:${isUnlocked?'var(--success)':'var(--text-muted)'};">${tier.min > 0 ? tier.min+' pts' : 'Free'}</div>
        </div>`;
      }).join('');

      document.getElementById('loyalty-content').innerHTML = `
    <div class="loyalty-hero">
      <div style="font-size:14px;opacity:0.85;margin-bottom:4px;">🪙 Your C3 Coin Balance</div>
      <div class="loyalty-points-big" id="loyalty-pts-display">${c.points}</div>
      <div class="loyalty-label">= Worth ₹${pointsRupeeValue} in discounts</div>
      <div class="tier-info">
        <div class="tier-badge">${getMemberIcon(c.membership_level)} ${c.membership_level} Member</div>
        ${nextTier ? `<div class="tier-badge">🎯 ${nextLevelPts - c.points} pts to ${nextTierName}</div>` : '<div class="tier-badge">💎 Max Tier Achieved!</div>'}
      </div>
      <div style="font-size:12px;opacity:0.7;margin-top:8px;">40 C3 Coins = ₹1 · Max ${LOYALTY.MAX_REDEEM_PERCENT}% off per order</div>
    </div>

    <div class="section-title">🏆 Redeem Rewards</div>
    ${rewardsHtml}

    <div class="section-title mt-20">📈 Membership Tiers</div>
    <div class="card mb-20">
      <div class="card-pad" style="padding-top:4px;padding-bottom:4px;">
        ${tiersHtml}
      </div>
    </div>

    <div class="section-title mt-20">👥 Referral Reward</div>
    <div class="referral-card">
      <div style="font-size:13px;color:var(--text-secondary);margin-bottom:4px;">Your referral code</div>
      <div class="referral-code">${esc(c.referral_code)}</div>
      <div style="font-size:12px;color:var(--text-secondary);margin-bottom:12px;">Share with friends — both of you earn <strong>300 C3 Coins</strong>!</div>
      <button class="btn btn-outline btn-sm" style="width:auto;" onclick="copyReferral('${esc(c.referral_code)}')">📋 Copy Code</button>
    </div>

    <div class="section-title mt-20">💳 Point History</div>
    <div class="card">
      <div class="card-pad" style="padding-top:4px;padding-bottom:4px;">${txnHtml}</div>
    </div>
  `;
      animateCoinCount('loyalty-pts-display', 0, c.points, 1400);
    }

    function copyReferral(code) {
      navigator.clipboard.writeText(code).then(() => toast('Referral code copied! 🎉', 'success')).catch(() => toast('Code: ' + code, 'info'));
    }

    function copyReferralLink(code) {
      const url = window.location.origin + window.location.pathname + '?ref=' + encodeURIComponent(code);
      navigator.clipboard.writeText(url)
        .then(() => toast('Referral link copied! Share it with friends 🔗', 'success'))
        .catch(() => toast('Link: ' + url, 'info', 6000));
    }

    function openRedeemModal(rewardId, name, pts) {
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay centered';
      overlay.innerHTML = `
    <div class="modal-dialog" style="text-align:center;">
      <div style="font-size:40px;margin-bottom:12px;">🎁</div>
      <h2 style="font-family:var(--font-display);font-size:20px;margin-bottom:8px;">Redeem Reward</h2>
      <p style="color:var(--text-secondary);font-size:14px;margin-bottom:4px;"><strong>${esc(name)}</strong></p>
      <p style="color:var(--text-secondary);font-size:14px;margin-bottom:20px;">This will use <strong style="color:var(--primary)">${pts} points</strong></p>
      <div style="display:flex;gap:10px;">
        <button class="btn btn-ghost w-full" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
        <button class="btn btn-primary w-full" onclick="doRedeemReward(${rewardId},this.closest('.modal-overlay'))">Confirm</button>
      </div>
    </div>`;
      document.body.appendChild(overlay);
    }

    async function doRedeemReward(rewardId, overlay) {
      const res = await api('redeem_reward', {
        reward_id: rewardId
      });
      if (res.success) {
        overlay.remove();
        toast(`🎉 Reward redeemed! ${res.reward.name} unlocked`, 'success', 4000);
        State.dashboardLoaded = false;
        State.loyaltyLoaded = false;
      } else {
        toast(res.message || 'Failed to redeem', 'error');
      }
    }

    // ============================================================
    // PROFILE
    // ============================================================
    async function loadProfile() {
      State.profileLoaded = true;
      if (!State.dashboard) {
        const data = await api('get_dashboard');
        State.dashboard = data;
      }
      const c = State.dashboard.customer;
      renderProfile(c);
    }

    function renderProfile(c) {
      const initials = c.full_name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
      const bday = c.birthday ? new Date(c.birthday).toLocaleDateString('en-IN', {
        day: 'numeric',
        month: 'long'
      }) : 'Not set';

      document.getElementById('profile-content').innerHTML = `
    <div class="profile-hero">
      <div class="profile-avatar">${esc(initials)}</div>
      <div class="profile-name">${esc(c.full_name)}</div>
      <div class="profile-mobile">📱 ${esc(c.mobile)}</div>
      <span class="membership-badge ${c.membership_level === 'Platinum' ? 'badge-gold' : c.membership_level === 'Gold' ? 'badge-gold' : c.membership_level === 'Silver' ? 'badge-silver' : 'badge-bronze'}" style="margin-top:10px;">
        ${getMemberIcon(c.membership_level)} ${c.membership_level} Member · ${c.points} 🪙
      </span>
    </div>

    <div class="card mb-16">
      <div class="card-pad" style="padding-top:4px;padding-bottom:4px;">
        <div class="setting-row" onclick="openEditProfile(${JSON.stringify(c).replace(/"/g,'&quot;')})">
          <div class="setting-icon">✏️</div>
          <div class="setting-label">Edit Profile</div>
          <div class="setting-arrow">›</div>
        </div>
        <div class="setting-row" onclick="openChangePassword()">
          <div class="setting-icon">🔒</div>
          <div class="setting-label">Change Password</div>
          <div class="setting-arrow">›</div>
        </div>
        <div class="setting-row">
          <div class="setting-icon">🎂</div>
          <div class="setting-label">Birthday: ${esc(bday)}</div>
          <div class="setting-arrow">›</div>
        </div>
      </div>
    </div>

    <div class="card mb-16">
      <div class="card-header"><span class="card-title">Order History</span></div>
      <div class="card-pad" style="padding-top:4px;padding-bottom:4px;" id="order-history-content">
        ${renderOrderHistory(State.dashboard.recent_orders)}
      </div>
      <div style="padding:0 20px 16px;">
        <button class="btn btn-ghost w-full btn-sm" onclick="loadAllOrders()">View All Orders</button>
      </div>
    </div>

    <div class="card mb-16">
      <div class="card-pad" style="padding-top:4px;padding-bottom:4px;">
        <div class="setting-row" onclick="goToPage('loyalty')">
          <div class="setting-icon">⭐</div>
          <div class="setting-label">Saved Rewards</div>
          <div class="setting-arrow">›</div>
        </div>
        <div class="setting-row" style="cursor:default;">
          <div class="setting-icon">${State.darkMode ? '☀️' : '🌙'}</div>
          <div class="setting-label">Dark Mode</div>
          <label class="toggle-switch">
            <input type="checkbox" ${State.darkMode ? 'checked' : ''} onchange="toggleDarkMode()">
            <span class="toggle-knob"></span>
          </label>
        </div>
        <div class="setting-row" onclick="copyReferral('${esc(c.referral_code)}')">
          <div class="setting-icon">👥</div>
          <div class="setting-label">Referral Code: <strong>${esc(c.referral_code)}</strong></div>
          <div class="setting-arrow">📋</div>
        </div>
        <div class="setting-row" onclick="copyReferralLink('${esc(c.referral_code)}')">
          <div class="setting-icon">🔗</div>
          <div class="setting-label">Copy Referral Link</div>
          <div class="setting-arrow">📤</div>
        </div>
      </div>
    </div>

    <button class="btn btn-danger w-full" onclick="confirmLogout()">🚪 Sign Out</button>

    <div style="text-align:center;margin:24px 0 8px;padding:16px;border-radius:var(--radius);background:var(--bg-secondary);border:1px solid var(--border);">
      <div style="font-size:11px;color:var(--text-muted);margin-bottom:4px;letter-spacing:0.5px;text-transform:uppercase;">Powered by</div>
      <a href="https://nexora-scale.unaux.com/qrcode.html?i=1" target="_blank" style="text-decoration:none;display:inline-flex;align-items:center;gap:6px;">
        <span style="font-size:15px;">💻</span>
        <span style="font-size:15px;font-weight:800;color:var(--text);letter-spacing:-0.3px;">Nexora <span style="color:#25D366;">Scale</span></span>
        <span style="font-size:11px;color:var(--text-secondary);">Ujjain</span>
      </a>
      <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Digital solutions for modern businesses</div>
    </div>
  `;
      // Add milestones after render
      const milestoneHtml = renderMilestones(c, State.dashboard ? State.dashboard.recent_orders : []);
      const signOutBtn = document.querySelector('#page-profile .btn-danger');
      if (signOutBtn) signOutBtn.insertAdjacentHTML('afterend', milestoneHtml);
    }

    function renderOrderHistory(orders) {
      if (!orders || orders.length === 0) return '<div style="text-align:center;padding:16px;color:var(--text-muted);font-size:14px;">No orders yet</div>';
      return orders.map(o => {
        const items = JSON.parse(o.items_json || '[]');
        const itemNames = items.slice(0, 2).map(i => i.name).join(', ') + (items.length > 2 ? ' +more' : '');
        const isActive = ['pending','confirmed','preparing','ready'].includes(o.status);
        const dotClass = o.status === 'completed' ? 'green' : o.status === 'cancelled' ? 'red' : '';
        const itemsJson = esc(JSON.stringify(items)).replace(/'/g, "&#39;");
        return `
      <div class="order-item">
        <div class="order-icon">🧾</div>
        <div class="order-info">
          <div class="order-num">#${esc(o.order_number)}</div>
          <div class="order-date">${esc(itemNames)} · ${timeAgo(o.created_at)}</div>
          ${o.status !== 'completed' && o.status !== 'cancelled' ? `<span class="wait-badge">⏱ ~15 min</span>` : ''}
        </div>
        <div class="order-right">
          <div class="order-total">${fmt(o.total)}</div>
          <div class="order-status status-${o.status}">
            ${isActive ? `<span class="status-pulse"><span class="status-pulse-dot ${dotClass}"></span></span> ` : ''}${o.status}
          </div>
          ${o.status === 'completed' ? `<div class="reorder-btn mt-4" onclick="reorder('${itemsJson}')">↺ Reorder</div>` : ''}
        </div>
      </div>`;
      }).join('');
    }

    async function loadAllOrders() {
      const res = await api('get_orders');
      const el = document.getElementById('order-history-content');
      if (el) el.innerHTML = renderOrderHistory(res.orders);
    }

    function openEditProfile(customer) {
      const c = typeof customer === 'string' ? JSON.parse(customer) : customer;
      const bday = c.birthday ? c.birthday.split('T')[0].split(' ')[0] : '';
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay';
      overlay.innerHTML = `
    <div class="modal-sheet">
      <div class="modal-handle"></div>
      <div class="modal-header">Edit Profile <div class="modal-close" onclick="this.closest('.modal-overlay').remove()">✕</div></div>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Full Name</label>
          <input type="text" class="form-input" id="edit-name" value="${esc(c.full_name)}">
        </div>
        <div class="form-group">
          <label class="form-label">Birthday</label>
          <input type="date" class="form-input" id="edit-birthday" value="${esc(bday)}">
        </div>
        <button class="btn btn-primary w-full mt-8" onclick="saveProfile(this.closest('.modal-overlay'))">Save Changes</button>
      </div>
    </div>`;
      document.body.appendChild(overlay);
    }

    async function saveProfile(overlay) {
      const name = document.getElementById('edit-name').value.trim();
      const birthday = document.getElementById('edit-birthday').value;
      if (!name) {
        toast('Name is required', 'warning');
        return;
      }
      const res = await api('update_profile', {
        full_name: name,
        birthday
      });
      if (res.success) {
        overlay.remove();
        toast('Profile updated!', 'success');
        State.dashboardLoaded = false;
        State.profileLoaded = false;
        await loadProfile();
      } else toast('Update failed', 'error');
    }

    function openChangePassword() {
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay';
      overlay.innerHTML = `
    <div class="modal-sheet">
      <div class="modal-handle"></div>
      <div class="modal-header">Change Password <div class="modal-close" onclick="this.closest('.modal-overlay').remove()">✕</div></div>
      <div class="modal-body">
        <div class="form-group">
          <label class="form-label">Current Password</label>
          <input type="password" class="form-input" id="pw-current" placeholder="Current password">
        </div>
        <div class="form-group">
          <label class="form-label">New Password</label>
          <input type="password" class="form-input" id="pw-new" placeholder="New password">
        </div>
        <button class="btn btn-primary w-full mt-8" onclick="changePassword(this.closest('.modal-overlay'))">Update Password</button>
      </div>
    </div>`;
      document.body.appendChild(overlay);
    }

    async function changePassword(overlay) {
      const current = document.getElementById('pw-current').value;
      const newpw = document.getElementById('pw-new').value;
      if (!current || !newpw) {
        toast('Fill all fields', 'warning');
        return;
      }
      if (newpw.length < 6) {
        toast('Password too short', 'warning');
        return;
      }
      const res = await api('change_password', {
        current,
        new: newpw
      });
      if (res.success) {
        overlay.remove();
        toast('Password updated!', 'success');
      } else toast(res.message || 'Failed', 'error');
    }

    function confirmLogout() {
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay centered';
      overlay.innerHTML = `
    <div class="modal-dialog" style="text-align:center;">
      <div style="font-size:36px;margin-bottom:12px;">👋</div>
      <h2 style="font-size:18px;margin-bottom:8px;">Sign Out?</h2>
      <p style="color:var(--text-secondary);font-size:14px;margin-bottom:20px;">See you next time!</p>
      <div style="display:flex;gap:10px;">
        <button class="btn btn-ghost w-full" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
        <button class="btn btn-danger w-full" onclick="doLogout()">Sign Out</button>
      </div>
    </div>`;
      document.body.appendChild(overlay);
    }

    // ============================================================
    // REWARDS POPUP — delayed for guests
    // ============================================================
    let _rewardsPopupTimer = null;

    function startRewardsPopupTimer() {
      if (_rewardsPopupTimer || State.rewardsPopupDismissed || State.isLoggedIn) return;
      const delay = 15000 + Math.random() * 5000; // 15-20 seconds
      _rewardsPopupTimer = setTimeout(showRewardsPopup, delay);
    }

    function showRewardsPopup() {
      if (State.rewardsPopupDismissed || State.isLoggedIn) return;
      clearTimeout(_rewardsPopupTimer);
      const popup = document.getElementById('rewards-popup');
      if (popup) popup.classList.remove('hidden');
    }

    function dismissRewardsPopup() {
      State.rewardsPopupDismissed = true;
      const popup = document.getElementById('rewards-popup');
      if (popup) popup.classList.add('hidden');
    }

    // ============================================================
    // ADMIN: Update Instagram URL (can be called from admin panel)
    // ============================================================
    async function adminUpdateInstagram(url) {
      const res = await api('update_instagram', {
        url
      });
      if (res.success) {
        State.instagramUrl = url;
        const igBtn = document.getElementById('ig-float-btn');
        if (igBtn) igBtn.href = url;
        toast('Instagram link updated! 🔗', 'success');
      } else {
        toast(res.message || 'Failed', 'error');
      }
    }

    // ============================================================
    // HAPTIC FEEDBACK
    // ============================================================
    function haptic(ms = 10) {
      if (navigator.vibrate) navigator.vibrate(ms);
    }

    // ============================================================
    // CART FLY ANIMATION
    // ============================================================
    function flyToCart(sourceEl) {
      const cartBtn = document.getElementById('cart-nav-btn') || document.getElementById('cart-nav-btn-mobile');
      if (!sourceEl || !cartBtn) return;
      const src = sourceEl.getBoundingClientRect();
      const dst = cartBtn.getBoundingClientRect();
      const dot = document.createElement('div');
      dot.className = 'cart-fly-dot';
      dot.style.left = (src.left + src.width/2 - 10) + 'px';
      dot.style.top  = (src.top  + src.height/2 - 10) + 'px';
      const dx = dst.left - src.left, dy = dst.top - src.top;
      dot.style.setProperty('--fly-x',  (dx * 0.4) + 'px');
      dot.style.setProperty('--fly-y',  (dy * 0.4) + 'px');
      dot.style.setProperty('--fly-x2', dx + 'px');
      dot.style.setProperty('--fly-y2', dy + 'px');
      document.body.appendChild(dot);
      setTimeout(() => dot.remove(), 600);
    }

    function addToCart(id, name, price, image, sourceEl) {
      haptic(8);
      SFX.pop();
      if (sourceEl) { flyToCart(sourceEl); triggerMiniConfetti(sourceEl); }
      _origAddToCart(id, name, price, image);
      popCartBadge();
    }

    // ============================================================
    // COMBO CART HELPERS
    // ============================================================
    function addComboToCart(comboId, name, price, image, sourceEl) {
      haptic(8);
      SFX.pop();
      if (sourceEl) { flyToCart(sourceEl); triggerMiniConfetti(sourceEl); }
      const cid = 'combo_' + comboId;
      const existing = State.cart.find(c => c.id === cid);
      if (existing) {
        existing.qty++;
      } else {
        State.cart.push({ id: cid, name, price: parseFloat(price), image, qty: 1, isCombo: true });
        if (!State.isLoggedIn && !State.rewardsPopupDismissed) showRewardsPopup();
      }
      saveCart();
      updateCartBadge();
      updateComboCartCtrl(comboId);
      popCartBadge();
      toast(`${name} added to cart 🎁`, 'success', 1800);
    }

    function updateComboQty(cid, delta) {
      haptic(6);
      const item = State.cart.find(c => c.id === cid);
      if (!item) return;
      item.qty += delta;
      if (item.qty <= 0) State.cart = State.cart.filter(c => c.id !== cid);
      saveCart();
      updateCartBadge();
      const numericId = parseInt(cid.replace('combo_', ''));
      updateComboCartCtrl(numericId);
      if (State.currentPage === 'cart') renderCart();
    }

    function updateComboCartCtrl(comboId) {
      const ctrl = document.getElementById('combo-cart-ctrl-' + comboId);
      if (!ctrl) return;
      const cid = 'combo_' + comboId;
      const ci = State.cart.find(c => c.id === cid);
      const combo = State.menu && State.menu.combos ? State.menu.combos.find(c => c.id == comboId) : null;
      if (ci && ci.qty > 0) {
        ctrl.innerHTML = `<div class="qty-control" style="background:rgba(255,255,255,0.15);border-radius:20px;padding:2px 6px;">
          <button class="qty-btn" onclick="updateComboQty('${cid}',-1)" style="color:#fff;">&#8722;</button>
          <span class="qty-num" style="color:#fff;min-width:20px;text-align:center;">${ci.qty}</span>
          <button class="qty-btn" onclick="updateComboQty('${cid}',1)" style="color:#fff;">+</button>
        </div>`;
      } else {
        const imgUrl = combo ? (combo.image_url || '').replace(/'/g,"\\'") : '';
        const comboPrice = combo ? combo.combo_price : 0;
        const comboName = combo ? combo.name.replace(/'/g,"\\'").replace(/"/g,'&quot;') : '';
        ctrl.innerHTML = `<button class="btn btn-primary" onclick="addComboToCart(${comboId},'${comboName}',${comboPrice},'${imgUrl}',this)" style="padding:8px 20px;font-size:13px;border-radius:20px;background:rgba(255,255,255,0.25);border:1.5px solid rgba(255,255,255,0.7);color:#fff;backdrop-filter:blur(4px);">&#128722; Add to Cart</button>`;
      }
    }

    // ============================================================
    // FAVOURITES
    // ============================================================
    function getFavourites() {
      return JSON.parse(localStorage.getItem('c3_favs') || '[]');
    }
    function toggleFavourite(id, name, btn) {
      haptic(12);
      let favs = getFavourites();
      const idx = favs.findIndex(f => f.id == id);
      if (idx >= 0) {
        favs.splice(idx, 1);
        if (btn) { btn.textContent = '🤍'; btn.classList.remove('active'); }
        toast('Removed from favourites', 'info', 1500);
      } else {
        favs.push({ id, name });
        if (btn) { btn.textContent = '❤️'; btn.classList.add('active'); }
        toast(`❤️ ${name} saved to favourites!`, 'success', 1800);
      }
      localStorage.setItem('c3_favs', JSON.stringify(favs));
    }
    function isFavourite(id) {
      return getFavourites().some(f => f.id == id);
    }

    // ============================================================
    // SWIPE-TO-DELETE CART ITEMS
    // ============================================================
    function initSwipeDelete() {
      document.querySelectorAll('.cart-item-swipeable').forEach(el => {
        let startX = 0, dx = 0, swiping = false;
        el.addEventListener('touchstart', e => { startX = e.touches[0].clientX; swiping = true; }, { passive: true });
        el.addEventListener('touchmove', e => {
          if (!swiping) return;
          dx = e.touches[0].clientX - startX;
          if (dx < 0) el.style.transform = `translateX(${Math.max(dx, -80)}px)`;
        }, { passive: true });
        el.addEventListener('touchend', () => {
          swiping = false;
          if (dx < -60) {
            const id = el.dataset.itemId;
            el.style.transform = 'translateX(-100%)';
            el.style.transition = 'transform 0.25s ease';
            setTimeout(() => removeFromCart(id), 250);
            haptic(20);
          } else {
            el.style.transform = '';
            el.style.transition = 'transform 0.2s ease';
          }
          dx = 0;
        });
      });
    }

    // ============================================================
    // PULL-TO-REFRESH
    // ============================================================
    (function initPullToRefresh() {
      let startY = 0, pulling = false;
      const ind = document.getElementById('ptr-indicator');
      const mainContent = document.querySelector('.main-content');
      if (!mainContent || !ind) return;
      mainContent.addEventListener('touchstart', e => {
        if (mainContent.scrollTop === 0) { startY = e.touches[0].clientY; pulling = true; }
      }, { passive: true });
      mainContent.addEventListener('touchmove', e => {
        if (!pulling) return;
        const dy = e.touches[0].clientY - startY;
        if (dy > 40) { ind.classList.add('visible'); ind.querySelector('.ptr-spinner').style.display = 'block'; }
      }, { passive: true });
      mainContent.addEventListener('touchend', async () => {
        if (!pulling) return; pulling = false;
        if (ind.classList.contains('visible')) {
          ind.innerHTML = '<div class="ptr-spinner"></div> Refreshing...';
          haptic(15);
          const page = State.currentPage;
          if (page === 'menu')    { State.menuLoaded = false; await loadMenu(); }
          if (page === 'home')    { State.dashboardLoaded = false; await loadDashboard(); }
          if (page === 'loyalty') { State.loyaltyLoaded = false; await loadLoyalty(); }
          if (page === 'games')   { await loadGames(); }
          setTimeout(() => { ind.classList.remove('visible'); ind.innerHTML = '<div class="ptr-spinner"></div> Pull to refresh'; }, 400);
        }
      });
    })();

    // ============================================================
    // SEARCH HISTORY
    // ============================================================
    function getSearchHistory() {
      return JSON.parse(localStorage.getItem('c3_search_hist') || '[]');
    }
    function addToSearchHistory(q) {
      if (!q || q.length < 2) return;
      let hist = getSearchHistory().filter(h => h !== q);
      hist.unshift(q);
      hist = hist.slice(0, 5);
      localStorage.setItem('c3_search_hist', JSON.stringify(hist));
    }
    function removeFromSearchHistory(q) {
      const hist = getSearchHistory().filter(h => h !== q);
      localStorage.setItem('c3_search_hist', JSON.stringify(hist));
      renderSearchChips();
    }
    function renderSearchChips() {
      const container = document.getElementById('search-chips');
      if (!container) return;
      const hist = getSearchHistory();
      if (hist.length === 0) { container.innerHTML = ''; return; }
      container.innerHTML = hist.map(h => `
        <div class="search-chip" onclick="applySearchChip('${esc(h)}')">
          🕐 ${esc(h)}
          <span class="chip-x" onclick="event.stopPropagation();removeFromSearchHistory('${esc(h)}')">✕</span>
        </div>`).join('');
    }
    function applySearchChip(q) {
      State.searchQuery = q;
      const inp = document.getElementById('menu-search');
      if (inp) inp.value = q;
      renderMenu();
    }

    // ============================================================
    // MILESTONES
    // ============================================================
    function getMilestones(customer, orders) {
      const totalOrders = orders ? orders.length : 0;
      const totalSpent  = orders ? orders.reduce((s, o) => s + parseFloat(o.total || 0), 0) : 0;
      return [
        { icon: '🥇', name: 'First Order',   unlocked: totalOrders >= 1  },
        { icon: '🔟', name: '10 Orders',      unlocked: totalOrders >= 10 },
        { icon: '💰', name: '₹1K Spent',      unlocked: totalSpent >= 1000 },
        { icon: '🔥', name: '7-Day Streak',   unlocked: (customer.longest_streak||0) >= 7 },
        { icon: '👥', name: 'Referral Star',  unlocked: !!customer.referred_by },
        { icon: '💎', name: 'Platinum',        unlocked: customer.membership_level === 'Platinum' },
      ];
    }
    function renderMilestones(customer, orders) {
      const milestones = getMilestones(customer, orders);
      return `
        <div class="card mb-16">
          <div class="card-header"><span class="card-title">🏅 Achievements</span></div>
          <div class="card-pad">
            <div class="milestones-grid">
              ${milestones.map(m => `
                <div class="milestone-badge ${m.unlocked ? 'unlocked' : ''}">
                  <div class="milestone-icon">${m.icon}</div>
                  <div class="milestone-name">${m.name}</div>
                </div>`).join('')}
            </div>
            <div style="font-size:11px;color:var(--text-muted);text-align:center;margin-top:8px;">${milestones.filter(m=>m.unlocked).length}/${milestones.length} unlocked</div>
          </div>
        </div>`;
    }

    // ============================================================
    // REORDER
    // ============================================================
    function reorder(itemsJson) {
      haptic(10);
      const items = JSON.parse(itemsJson);
      let added = 0;
      items.forEach(item => {
        const existing = State.cart.find(c => c.id == item.id);
        if (existing) existing.qty += item.qty || 1;
        else State.cart.push({ id: item.id, name: item.name, price: parseFloat(item.price), image: '', qty: item.qty || 1 });
        added++;
      });
      saveCart(); updateCartBadge();
      toast(`🛒 ${added} item${added>1?'s':''} added to cart!`, 'success', 2000);
      goToPage('cart');
    }

    // ============================================================
    // ITEM CUSTOMISATION MODAL
    // ============================================================
    function openCustomiseModal(id, name, price, image) {
      haptic(8);
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay';
      let selectedSpice = 'Medium', selectedSize = 'Regular', selectedNote = '';
      overlay.innerHTML = `
    <div class="modal-sheet">
      <div class="modal-handle"></div>
      <div class="modal-header">${esc(name)} <div class="modal-close" onclick="this.closest('.modal-overlay').remove()">✕</div></div>
      <div class="modal-body">
        <div class="customise-option">
          <div>
            <div class="customise-label">Size</div>
            <div class="customise-chips" id="size-chips">
              ${['Small','Regular','Large'].map(s=>`<div class="customise-chip${s==='Regular'?' selected':''}" onclick="selectChip('size-chips',this,'${s}')">${s}</div>`).join('')}
            </div>
          </div>
        </div>
        <div class="customise-option">
          <div>
            <div class="customise-label">Sugar / Spice Level</div>
            <div class="customise-chips" id="spice-chips">
              ${['Mild','Medium','Strong'].map(s=>`<div class="customise-chip${s==='Medium'?' selected':''}" onclick="selectChip('spice-chips',this,'${s}')">${s}</div>`).join('')}
            </div>
          </div>
        </div>
        <div class="customise-option" style="flex-direction:column;align-items:flex-start;">
          <div class="customise-label" style="margin-bottom:8px;">Special Note</div>
          <input type="text" class="form-input" id="cust-note" placeholder="e.g. Less ice, oat milk..." style="font-size:14px;">
        </div>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:12px 0 0;">
          <div style="font-size:17px;font-weight:800;color:var(--primary);">${fmt(price)}</div>
          <button class="btn btn-primary" style="width:auto;padding:12px 24px;" onclick="
            const note = document.getElementById('cust-note').value;
            const size = document.querySelector('#size-chips .selected')?.textContent || 'Regular';
            const spice= document.querySelector('#spice-chips .selected')?.textContent || 'Medium';
            const suffix = [size!=='Regular'?size:'', spice!=='Medium'?spice:'', note].filter(Boolean).join(', ');
            const fullName = suffix ? '${esc(name)} ('+suffix+')' : '${esc(name)}';
            this.closest('.modal-overlay').remove();
            _origAddToCart(${id}, fullName, ${price}, '${esc(image)}');
            haptic(8);
            toast(fullName+' added to cart 🛒','success',1800);
            saveCart(); updateCartBadge(); updateMenuCartCtrl(${id});
          ">Add to Cart +</button>
        </div>
      </div>
    </div>`;
      document.body.appendChild(overlay);
    }
    function selectChip(groupId, el, val) {
      document.querySelectorAll('#'+groupId+' .customise-chip').forEach(c => c.classList.remove('selected'));
      el.classList.add('selected');
    }

    // ============================================================
    // INIT
    // ============================================================
    // ============================================================
    // SOUND ENGINE (Web Audio API — zero dependencies)
    // ============================================================
    const SFX = {
      _ctx: null,
      _muted: localStorage.getItem('c3_muted') === '1',
      _getCtx() {
        if (!this._ctx) this._ctx = new (window.AudioContext || window.webkitAudioContext)();
        // Resume if browser suspended it (common on mobile)
        if (this._ctx.state === 'suspended') this._ctx.resume();
        return this._ctx;
      },
      _play(fn) { if (!this._muted) { try { fn(this._getCtx()); } catch(e) {} } },

      // ✅ Coin collect — earn points / add to cart
      coin() {
        this._play(ctx => {
          [523, 659, 784, 1047].forEach((freq, i) => {
            const o = ctx.createOscillator(), g = ctx.createGain();
            o.connect(g); g.connect(ctx.destination);
            o.frequency.value = freq; o.type = 'sine';
            const t = ctx.currentTime + i * 0.075;
            g.gain.setValueAtTime(0.13, t);
            g.gain.exponentialRampToValueAtTime(0.001, t + 0.18);
            o.start(t); o.stop(t + 0.18);
          });
        });
      },

      // 🎉 Success chime — order placed / tier up
      success() {
        this._play(ctx => {
          [523, 659, 784, 1047, 1319].forEach((freq, i) => {
            const o = ctx.createOscillator(), g = ctx.createGain();
            o.connect(g); g.connect(ctx.destination);
            o.frequency.value = freq; o.type = 'triangle';
            const t = ctx.currentTime + i * 0.11;
            g.gain.setValueAtTime(0.17, t);
            g.gain.exponentialRampToValueAtTime(0.001, t + 0.35);
            o.start(t); o.stop(t + 0.35);
          });
        });
      },

      // 🛒 Pop — add to cart
      pop() {
        this._play(ctx => {
          const o = ctx.createOscillator(), g = ctx.createGain();
          o.connect(g); g.connect(ctx.destination);
          o.frequency.setValueAtTime(420, ctx.currentTime);
          o.frequency.exponentialRampToValueAtTime(200, ctx.currentTime + 0.09);
          o.type = 'sine';
          g.gain.setValueAtTime(0.14, ctx.currentTime);
          g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.11);
          o.start(); o.stop(ctx.currentTime + 0.11);
        });
      },

      // 🎰 Whoosh — spin wheel start
      whoosh() {
        this._play(ctx => {
          const sr = ctx.sampleRate, len = Math.floor(sr * 0.35);
          const buf = ctx.createBuffer(1, len, sr);
          const d = buf.getChannelData(0);
          for (let i = 0; i < len; i++) d[i] = (Math.random()*2-1) * (1 - i/len);
          const src = ctx.createBufferSource(), f = ctx.createBiquadFilter();
          f.type = 'bandpass'; f.frequency.value = 900; f.Q.value = 0.8;
          src.buffer = buf; src.connect(f); f.connect(ctx.destination);
          src.start();
        });
      },

      // 🏆 Tada — milestone / badge unlock / tier up
      tada() {
        this._play(ctx => {
          [392, 523, 659, 784, 1047].forEach((freq, i) => {
            const o = ctx.createOscillator(), g = ctx.createGain();
            o.connect(g); g.connect(ctx.destination);
            o.frequency.value = freq; o.type = i===4 ? 'square' : 'triangle';
            const t = ctx.currentTime + i * 0.07;
            g.gain.setValueAtTime(0.16, t);
            g.gain.exponentialRampToValueAtTime(0.001, t + 0.38);
            o.start(t); o.stop(t + 0.38);
          });
        });
      },

      // 🔔 Ding — order ready notification
      ding() {
        this._play(ctx => {
          [880, 1109].forEach((freq, i) => {
            const o = ctx.createOscillator(), g = ctx.createGain();
            o.connect(g); g.connect(ctx.destination);
            o.frequency.value = freq; o.type = 'sine';
            const t = ctx.currentTime + i * 0.14;
            g.gain.setValueAtTime(0.2, t);
            g.gain.exponentialRampToValueAtTime(0.001, t + 0.6);
            o.start(t); o.stop(t + 0.6);
          });
        });
      },

      // ❌ Error buzz
      error() {
        this._play(ctx => {
          const o = ctx.createOscillator(), g = ctx.createGain();
          o.connect(g); g.connect(ctx.destination);
          o.frequency.value = 110; o.type = 'sawtooth';
          g.gain.setValueAtTime(0.09, ctx.currentTime);
          g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.22);
          o.start(); o.stop(ctx.currentTime + 0.22);
        });
      },

      // Toggle mute
      toggleMute() {
        this._muted = !this._muted;
        localStorage.setItem('c3_muted', this._muted ? '1' : '0');
        return this._muted;
      }
    };

    // Patch toast to play error sound on error type
    const _origToast = toast;
    window.toast = function(msg, type='info', duration=3000) {
      _origToast(msg, type, duration);
      if (type === 'error') SFX.error();
    };

    // ============================================================
    // POINTS FLOAT-UP ANIMATION
    // ============================================================
    function floatPoints(pts, anchorEl) {
      const el = document.createElement('div');
      el.className = 'points-float';
      el.textContent = '+' + pts + ' 🪙';
      const rect = anchorEl ? anchorEl.getBoundingClientRect() : { left: window.innerWidth/2 - 40, top: window.innerHeight * 0.35 };
      el.style.left = (rect.left + (anchorEl ? rect.width/2 - 30 : 0)) + 'px';
      el.style.top  = rect.top + 'px';
      document.body.appendChild(el);
      setTimeout(() => el.remove(), 1300);
    }

    // ============================================================
    // MINI CONFETTI (anchor-based burst, no full-screen flash)
    // ============================================================
    function triggerMiniConfetti(anchorEl) {
      const colors = ['#1a5c38','#F59E0B','#10B981','#EC4899','#3B82F6','#8B5CF6'];
      const rect = anchorEl ? anchorEl.getBoundingClientRect() : { left: window.innerWidth/2, top: 80, width:0, height:0 };
      const cx = rect.left + rect.width/2, cy = rect.top + rect.height/2;
      for (let i = 0; i < 22; i++) {
        const p = document.createElement('div');
        const isRect = i % 3 === 0;
        p.style.cssText = `position:fixed;left:${cx}px;top:${cy}px;width:${isRect?'6px':'7px'};height:${isRect?'10px':'7px'};
          border-radius:${isRect?'2px':'50%'};pointer-events:none;z-index:99999;
          background:${colors[i%colors.length]};`;
        document.body.appendChild(p);
        const angle = (Math.PI * 2 * i) / 22 + (Math.random() - 0.5) * 0.5;
        const dist  = 45 + Math.random() * 65;
        p.animate([
          { transform: 'translate(0,0) scale(1) rotate(0deg)', opacity: 1 },
          { transform: `translate(${Math.cos(angle)*dist}px,${Math.sin(angle)*dist - 35}px) scale(0) rotate(${360+Math.random()*180}deg)`, opacity: 0 }
        ], { duration: 650 + Math.random() * 350, easing: 'ease-out' }).onfinish = () => p.remove();
      }
    }

    // ============================================================
    // CART BADGE POP
    // ============================================================
    function popCartBadge() {
      ['cart-badge','cart-bnav-badge','cart-badge-mobile'].forEach(id => {
        const el = document.getElementById(id);
        if (el && !el.classList.contains('hidden')) {
          el.classList.remove('badge-pop');
          void el.offsetWidth; // force reflow
          el.classList.add('badge-pop');
          el.addEventListener('animationend', () => el.classList.remove('badge-pop'), { once: true });
        }
      });
    }

    // ============================================================
    // TIER-UP CELEBRATION MODAL
    // ============================================================
    function showTierUpModal(newTier) {
      SFX.tada();
      haptic([50, 30, 80]);
      triggerConfetti();
      const tierData = LOYALTY.TIERS[newTier] || {};
      const overlay = document.createElement('div');
      overlay.className = 'modal-overlay centered';
      overlay.innerHTML = `
        <div class="modal-dialog" style="text-align:center;overflow:visible;">
          <div class="tier-up-icon" style="font-size:62px;margin-bottom:4px;">${tierData.icon||'🏆'}</div>
          <h2 style="font-family:var(--font-display);font-size:24px;margin:8px 0 4px;">Tier Up! 🎉</h2>
          <div style="font-size:18px;font-weight:800;color:var(--primary);margin-bottom:8px;">You're now ${newTier}!</div>
          <div style="font-size:13px;color:var(--text-secondary);margin-bottom:20px;">${tierData.benefits||''}</div>
          <button class="btn btn-primary" onclick="this.closest('.modal-overlay').remove()">Awesome! 🎉</button>
        </div>`;
      document.body.appendChild(overlay);
    }

    // Track tier for detecting upgrades after orders/logins
    State._lastKnownTier = null;
    function checkTierUpgrade(newTier) {
      const order = ['Bronze','Silver','Gold','Platinum'];
      if (State._lastKnownTier && order.indexOf(newTier) > order.indexOf(State._lastKnownTier)) {
        showTierUpModal(newTier);
      }
      State._lastKnownTier = newTier;
    }

    // ============================================================
    // ORDER COUNTDOWN TIMER
    // ============================================================
    const CountdownState = {
      intervalId: null,
      orderNum: null,
      endsAt: null,      // Date object
      totalMs: null,
      orderType: null,
    };

    const COUNTDOWN_DURATION = {
      'dine-in': 15 * 60 * 1000,  // 15 mins
      'pickup':  10 * 60 * 1000,  // 10 mins
    };

    function startOrderCountdown(orderNum, orderType) {
      // Clear any previous countdown
      if (CountdownState.intervalId) clearInterval(CountdownState.intervalId);

      const dur = COUNTDOWN_DURATION[orderType] || COUNTDOWN_DURATION['dine-in'];
      CountdownState.orderNum  = orderNum;
      CountdownState.orderType = orderType;
      CountdownState.totalMs   = dur;
      CountdownState.endsAt    = new Date(Date.now() + dur);

      // Persist so it survives page navigation within session
      sessionStorage.setItem('c3_countdown', JSON.stringify({
        orderNum, orderType,
        endsAt: CountdownState.endsAt.toISOString(),
        totalMs: dur
      }));

      showCountdownBar();
      tickCountdown();
      CountdownState.intervalId = setInterval(tickCountdown, 1000);
    }

    function showCountdownBar() {
      const bar = document.getElementById('order-countdown-bar');
      if (bar) { bar.classList.remove('hidden','done','urgent'); }
    }

    function tickCountdown() {
      const bar    = document.getElementById('order-countdown-bar');
      const timeEl = document.getElementById('countdown-time');
      const fillEl = document.getElementById('countdown-progress-fill');
      const iconEl = document.getElementById('countdown-icon');
      const titleEl = document.getElementById('countdown-title');
      const subEl   = document.getElementById('countdown-sub');
      if (!bar || !timeEl) return;

      const now  = Date.now();
      const end  = CountdownState.endsAt.getTime();
      const left = Math.max(0, end - now);
      const pct  = (left / CountdownState.totalMs) * 100;

      const mins = Math.floor(left / 60000);
      const secs = Math.floor((left % 60000) / 1000);
      const label = left === 0 ? '✅ Ready!' : `${String(mins).padStart(2,'0')}:${String(secs).padStart(2,'0')}`;

      timeEl.textContent = label;
      if (fillEl) fillEl.style.width = pct + '%';

      if (left === 0) {
        // Order ready!
        clearInterval(CountdownState.intervalId);
        bar.classList.add('done');
        bar.classList.remove('urgent');
        if (iconEl) iconEl.textContent = '✅';
        if (titleEl) titleEl.textContent = `Order #${CountdownState.orderNum} is Ready!`;
        if (subEl) subEl.textContent = CountdownState.orderType === 'dine-in' ? 'Head to your table 🍽️' : 'Come pick it up! 🥡';
        SFX.ding();
        haptic([80, 40, 80]);
        toast(`🔔 Order #${CountdownState.orderNum} is ready!`, 'success', 5000);
        // Auto-hide after 30s
        setTimeout(() => { if(bar) bar.classList.add('hidden'); }, 30000);
      } else if (left < 3 * 60 * 1000) {
        // Last 3 mins — urgent
        bar.classList.add('urgent');
        if (iconEl) iconEl.textContent = '🔥';
        if (titleEl) titleEl.textContent = `Order #${CountdownState.orderNum} — Almost Ready!`;
        if (subEl)   subEl.textContent = CountdownState.orderType === 'dine-in' ? 'Serving soon 🍽️' : 'Almost there! 🥡';
      } else {
        if (iconEl) iconEl.textContent = '⏳';
        if (titleEl) titleEl.textContent = `Order #${CountdownState.orderNum} in progress`;
        if (subEl)   subEl.textContent = CountdownState.orderType === 'dine-in' ? 'Being prepared for you 🍳' : 'Getting your order ready 🥡';
      }
    }

    function restoreCountdown() {
      const saved = sessionStorage.getItem('c3_countdown');
      if (!saved) return;
      try {
        const { orderNum, orderType, endsAt, totalMs } = JSON.parse(saved);
        const end = new Date(endsAt);
        if (end > new Date()) {
          CountdownState.orderNum  = orderNum;
          CountdownState.orderType = orderType;
          CountdownState.endsAt    = end;
          CountdownState.totalMs   = totalMs;
          showCountdownBar();
          tickCountdown();
          if (CountdownState.intervalId) clearInterval(CountdownState.intervalId);
          CountdownState.intervalId = setInterval(tickCountdown, 1000);
        } else {
          sessionStorage.removeItem('c3_countdown');
        }
      } catch(e) {}
    }

    document.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        const authScreen = document.getElementById('auth-screen');
        if (authScreen && !authScreen.classList.contains('hidden')) {
          const loginForm = document.getElementById('login-form');
          if (loginForm && !loginForm.classList.contains('hidden')) doLogin();
          else doSignup();
        }
      }
    });

    // Close auth modal on backdrop click (clicking the dark overlay, NOT the modal card)
    document.getElementById('auth-screen').addEventListener('click', function(e) {
      if (e.target === this) {
        closeAuthModal();
      }
    });

    // Close regular modals on backdrop click
    document.addEventListener('click', function(e) {
      if (e.target.classList.contains('modal-overlay')) {
        e.target.remove();
      }
    });

    // Init
    updateCartBadge();
    // Restore dark mode from localStorage (works for guests too; DB value wins for logged-in users)
    (function() {
      const savedDark = localStorage.getItem('brewcraft_darkmode');
      // PHP already set data-theme for logged-in users; for guests, apply localStorage preference
      if (!State.isLoggedIn && savedDark !== null) {
        applyDarkMode(savedDark === '1');
      }
      // Sync button icon to current state (already set by PHP for logged-in, now set for guests)
      ['dark-mode-btn', 'dark-mode-btn-mobile'].forEach(id => {
        const btn = document.getElementById(id);
        if (btn) btn.textContent = State.darkMode ? '☀️' : '🌙';
      });
    })();
    // Always open on Menu page first (front page); logged-in users can click Dashboard
    goToPage('menu');

    // Restore any active order countdown from session
    restoreCountdown();

    // Auto-open signup with referral code if ?ref= in URL
    const _urlRef = new URLSearchParams(window.location.search).get('ref');
    if (_urlRef) {
      openAuthModal('signup');
      setTimeout(() => {
        const refInput = document.getElementById('signup-refer-code');
        if (refInput && !refInput.value) {
          refInput.value = _urlRef.toUpperCase();
          toast('Referral code applied! 🎉', 'success', 2500);
        }
      }, 300);
    }
  </script>
  <!-- Watermark -->
  <div style="
    position:fixed;bottom:72px;left:50%;transform:translateX(-50%);
    z-index:8000;pointer-events:none;text-align:center;">
    <a href="https://nexora-scale.unaux.com/qrcode.html?i=1" target="_blank" style="
      color:rgba(150,150,150,0.8);font-size:9.5px;font-weight:600;
      text-decoration:none;letter-spacing:0.2px;pointer-events:all;
      white-space:nowrap;
      background:var(--bg-card);border:1px solid var(--border);
      border-radius:10px;padding:3px 8px;display:inline-block;
      box-shadow:0 1px 6px rgba(0,0,0,0.1);">
      💻 <strong style="color:#25D366;">Nexora Scale</strong>
    </a>


</body>

</html>
