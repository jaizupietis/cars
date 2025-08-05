<?php
// config.php - Database configuration and utility functions

class Config {
    // Database configuration
    const DB_HOST = 'localhost';
    const DB_NAME = 'car_comparator';
    const DB_USER = 'root';
    const DB_PASS = 'asus0011r';
    
    // Cache settings
    const CACHE_DURATION = 3600; // 1 hour in seconds
    const ENABLE_CACHE = true;
    
    // Rate limiting
    const MAX_REQUESTS_PER_MINUTE = 60;
    
    // Supported sites configuration
    const SITES = [
        'finn' => [
            'name' => 'FINN.no',
            'country' => 'Norway',
            'currency' => 'NOK',
            'url' => 'https://www.finn.no'
        ],
        'auto24' => [
            'name' => 'Auto24',
            'country' => 'Estonia',
            'currency' => 'EUR',
            'url' => 'https://www.auto24.ee'
        ],
        'ss' => [
            'name' => 'SS.lv',
            'country' => 'Latvia',
            'currency' => 'EUR',
            'url' => 'https://www.ss.lv'
        ],
        'autoplius' => [
            'name' => 'Autoplius',
            'country' => 'Lithuania',
            'currency' => 'EUR',
            'url' => 'https://lv.m.autoplius.lt'
        ],
        'autoscout24' => [
            'name' => 'AutoScout24',
            'country' => 'Europe',
            'currency' => 'EUR',
            'url' => 'https://www.autoscout24.com'
        ],
        'mobile' => [
            'name' => 'Mobile.de',
            'country' => 'Germany',
            'currency' => 'EUR',
            'url' => 'https://mobile.de'
        ]
    ];
}

class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $this->connection = new PDO(
                "mysql:host=" . Config::DB_HOST . ";dbname=" . Config::DB_NAME,
                Config::DB_USER,
                Config::DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"
                ]
            );
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function query($sql, $params = []) {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}

class Cache {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function get($key) {
        if (!Config::ENABLE_CACHE) return null;
        
        $stmt = $this->db->query(
            "SELECT data, expires_at FROM cache WHERE cache_key = ? AND expires_at > NOW()",
            [$key]
        );
        
        $result = $stmt->fetch();
        return $result ? json_decode($result['data'], true) : null;
    }
    
    public function set($key, $data, $duration = null) {
        if (!Config::ENABLE_CACHE) return;
        
        $duration = $duration ?? Config::CACHE_DURATION;
        $expiresAt = date('Y-m-d H:i:s', time() + $duration);
        
        $this->db->query(
            "INSERT INTO cache (cache_key, data, expires_at) VALUES (?, ?, ?) 
             ON DUPLICATE KEY UPDATE data = VALUES(data), expires_at = VALUES(expires_at)",
            [$key, json_encode($data), $expiresAt]
        );
    }
    
    public function delete($key) {
        $this->db->query("DELETE FROM cache WHERE cache_key = ?", [$key]);
    }
    
    public function cleanup() {
        $this->db->query("DELETE FROM cache WHERE expires_at <= NOW()");
    }
}

class RateLimiter {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function checkLimit($ip) {
        $stmt = $this->db->query(
            "SELECT COUNT(*) as count FROM rate_limits 
             WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 MINUTE)",
            [$ip]
        );
        
        $result = $stmt->fetch();
        return $result['count'] < Config::MAX_REQUESTS_PER_MINUTE;
    }
    
    public function recordRequest($ip) {
        $this->db->query(
            "INSERT INTO rate_limits (ip_address, created_at) VALUES (?, NOW())",
            [$ip]
        );
        
        // Clean up old records
        $this->db->query(
            "DELETE FROM rate_limits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
    }
}

class Logger {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    public function log($level, $message, $context = []) {
        $this->db->query(
            "INSERT INTO logs (level, message, context, created_at) VALUES (?, ?, ?, NOW())",
            [$level, $message, json_encode($context)]
        );
    }
    
    public function info($message, $context = []) {
        $this->log('info', $message, $context);
    }
    
    public function error($message, $context = []) {
        $this->log('error', $message, $context);
    }
    
    public function warning($message, $context = []) {
        $this->log('warning', $message, $context);
    }
}

// Utility functions
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function validateBrand($brand) {
    return preg_match('/^[a-zA-Z0-9\s\-]{1,50}$/', $brand);
}

function validateModel($model) {
    return preg_match('/^[a-zA-Z0-9\s\-\.]{0,50}$/', $model);
}

function validatePrice($price) {
    return is_numeric($price) && $price > 0 && $price <= 10000000;
}

function formatPrice($price, $currency = 'EUR') {
    $symbols = [
        'EUR' => '€',
        'NOK' => 'kr',
        'USD' => '$'
    ];
    
    $symbol = $symbols[$currency] ?? $currency;
    return $symbol . number_format($price, 0, ',', ' ');
}

function getUserIP() {
    $keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ips = explode(',', $_SERVER[$key]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Error handler
function handleError($errno, $errstr, $errfile, $errline) {
    $logger = new Logger();
    $logger->error("PHP Error: $errstr", [
        'file' => $errfile,
        'line' => $errline,
        'error_number' => $errno
    ]);
    
    if (!(error_reporting() & $errno)) {
        return false;
    }
    
    return true;
}

set_error_handler('handleError');

?>
