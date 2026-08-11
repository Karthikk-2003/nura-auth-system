<?php
/**
 * Database Connection & Utility Functions for Polyglot Persistence Architecture
 * - MySQL: Primary user authentication credentials
 * - MongoDB: Extended flexible profile documents
 * - Redis: Session state & token management with TTL
 */

// Set JSON output and CORS headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * Get MySQL PDO Connection
 */
function getMySQLConnection() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $host = getenv('MYSQL_HOST') ?: 'localhost';
    $port = getenv('MYSQL_PORT') ?: '3306';
    $db   = getenv('MYSQL_DB') ?: 'auth_db';
    $user = getenv('MYSQL_USER') ?: 'auth_user';
    $pass = getenv('MYSQL_PASSWORD') ?: 'auth_password';
    $charset = 'utf8mb4';

    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
    $options = [
        PDO::MYSQL_ATTR_SSL_CA                  => true,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
        PDO::ATTR_ERRMODE                       => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE            => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES              => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        return $pdo;
    } catch (\Throwable $e) {
        jsonResponse(false, 'MySQL Connection Error: ' . $e->getMessage(), 500);
        exit;
    }
}

/**
 * Get MongoDB Connection Manager (Supports Atlas SRV format and Auth)
 */
function getMongoDBManager() {
    static $manager = null;
    if ($manager !== null) {
        return $manager;
    }

    $host = getenv('MONGO_HOST') ?: '127.0.0.1';
    $user = getenv('MONGO_USER') ?: '';
    $pass = getenv('MONGO_PASSWORD') ?: '';
    $db   = getenv('MONGO_DB') ?: 'auth_profile_db';

    // Sanitize host string: strip protocol prefixes and trailing parameters
    $cleanHost = preg_replace('/^(https?:\/\/|mongodb\+srv:\/\/|mongodb:\/\/)/i', '', trim($host));
    $cleanHost = explode('/', $cleanHost)[0];
    $cleanHost = explode('?', $cleanHost)[0];

    // Format Atlas SRV connection string
    if (strpos($cleanHost, 'mongodb.net') !== false) {
        $auth = (!empty($user) && !empty($pass)) ? rawurlencode($user) . ':' . rawurlencode($pass) . '@' : '';
        $uri  = "mongodb+srv://{$auth}{$cleanHost}/{$db}?retryWrites=true&w=majority";
    } else {
        $port = getenv('MONGO_PORT') ?: '27017';
        $auth = (!empty($user) && !empty($pass)) ? rawurlencode($user) . ':' . rawurlencode($pass) . '@' : '';
        $uri  = "mongodb://{$auth}{$cleanHost}:{$port}/{$db}";
    }

    try {
        // Standard Manager instantiation maintaining native SNI TLS handshake
        $manager = new MongoDB\Driver\Manager($uri);
        return $manager;
    } catch (\Throwable $e) {
        jsonResponse(false, 'MongoDB Connection Error: ' . $e->getMessage(), 500);
        exit;
    }
}
/**
 * Save or Update Extended Profile in MongoDB
 */
function saveMongoProfile($userId, $profileData) {
    try {
        $manager = getMongoDBManager();
        $db = getenv('MONGO_DB') ?: 'auth_profile_db';
        $bulk = new MongoDB\Driver\BulkWrite();

        $interestsArray = [];
        if (isset($profileData['interests'])) {
            if (is_array($profileData['interests'])) {
                $interestsArray = array_values(array_filter(array_map('trim', $profileData['interests'])));
            } else {
                $interestsArray = array_values(array_filter(array_map('trim', explode(',', (string)$profileData['interests']))));
            }
        }

        $document = [
            'user_id'    => (int)$userId,
            'name'       => isset($profileData['name']) ? sanitizeInput($profileData['name']) : '',
            'age'        => isset($profileData['age']) ? (int)$profileData['age'] : 0,
            'bio'        => isset($profileData['bio']) ? sanitizeInput($profileData['bio']) : '',
            'interests'  => $interestsArray,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $bulk->update(
            ['user_id' => (int)$userId],
            ['$set' => $document],
            ['upsert' => true]
        );

        $result = $manager->executeBulkWrite("{$db}.profiles", $bulk);
        return true;
    } catch (\Throwable $e) {
        error_log('Mongo Save Error: ' . $e->getMessage());
        jsonResponse(false, 'MongoDB Save Error: ' . $e->getMessage(), 500);
        exit;
    }
}

/**
 * Retrieve Extended Profile from MongoDB
 */
function getMongoProfile($userId) {
    try {
        $manager = getMongoDBManager();
        $db = getenv('MONGO_DB') ?: 'auth_profile_db';
        $query = new MongoDB\Driver\Query(['user_id' => (int)$userId]);
        $cursor = $manager->executeQuery("{$db}.profiles", $query);
        $results = $cursor->toArray();

        if (!empty($results)) {
            $doc = (array)$results[0];
            unset($doc['_id']); // Exclude BSON ObjectId for JSON serialization
            return $doc;
        }

        return [
            'user_id' => (int)$userId,
            'name' => '',
            'age' => 0,
            'bio' => '',
            'interests' => [],
            'updated_at' => null
        ];
    } catch (\Throwable $e) {
        error_log('Mongo Fetch Error: ' . $e->getMessage());
        jsonResponse(false, 'MongoDB Fetch Error: ' . $e->getMessage(), 500);
        exit;
    }
}

/**
 * Get Redis Connection (supports TLS for Upstash and authentication)
 */
function getRedisConnection() {
    static $client = null;
    if ($client !== null) {
        return $client;
    }

    $host     = getenv('REDIS_HOST') ?: '127.0.0.1';
    $port     = (int)(getenv('REDIS_PORT') ?: 6379);
    $password = getenv('REDIS_PASSWORD') ?: null;

    // Ensure scheme is tls for Upstash remote endpoints
    $scheme = (strpos($host, 'upstash.io') !== false) ? 'tls' : 'tcp';

    try {
        if (class_exists('Predis\Client')) {
            $client = new Predis\Client([
                'scheme'   => $scheme,
                'host'     => $host,
                'port'     => $port,
                'password' => $password,
                'ssl'      => ['verify_peer' => false]
            ]);
            return $client;
        } else {
            $redis = new Redis();
            $connectHost = ($scheme === 'tls' && strpos($host, 'tls://') !== 0) ? 'tls://' . $host : $host;
            $redis->connect($connectHost, $port);
            if (!empty($password)) {
                $redis->auth($password);
            }
            $client = $redis;
            return $client;
        }
    } catch (\Throwable $e) {
        jsonResponse(false, 'Redis Connection Error: ' . $e->getMessage(), 500);
        exit;
    }
}

/**
 * Save Session Data in Redis with key `session:<token>` and 24h TTL (86,400s)
 */
function saveSession($token, $sessionData, $ttl = 86400) {
    try {
        $redis = getRedisConnection();
        $key = "session:" . $token;
        return $redis->setex($key, $ttl, json_encode($sessionData));
    } catch (\Throwable $e) {
        error_log('Redis Save Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get Active Session Data from Redis by token
 */
function getSession($token) {
    if (empty($token)) return null;

    try {
        $redis = getRedisConnection();
        $key = "session:" . $token;
        $val = $redis->get($key);
        return $val ? json_decode($val, true) : null;
    } catch (\Throwable $e) {
        error_log('Redis Fetch Error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Delete Session Key from Redis
 */
function deleteSession($token) {
    if (empty($token)) return false;

    try {
        $redis = getRedisConnection();
        $key = "session:" . $token;
        return $redis->del($key) > 0;
    } catch (\Throwable $e) {
        error_log('Redis Delete Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Extract Session Token from Request Headers, Cookies, or Body
 */
function getBearerToken() {
    $headers = null;
    if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER["Authorization"]);
    } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
        if (isset($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        }
    }

    if (!empty($headers)) {
        if (preg_match('/Bearer\s(\S+)/i', $headers, $matches)) {
            return $matches[1];
        }
    }

    if (!empty($_COOKIE['session_token'])) {
        return $_COOKIE['session_token'];
    }

    $rawInput = json_decode(file_get_contents('php://input'), true);
    if (!empty($rawInput['session_token'])) {
        return $rawInput['session_token'];
    }

    if (!empty($_REQUEST['session_token'])) {
        return $_REQUEST['session_token'];
    }

    return null;
}

/**
 * Helper to sanitize input strings
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    return htmlspecialchars(trim((string)$data), ENT_QUOTES, 'UTF-8');
}

/**
 * Standard JSON API Response Helper
 */
function jsonResponse($success, $payload, $statusCode = 200) {
    http_response_code($statusCode);
    $response = ['success' => (bool)$success];
    if (is_string($payload)) {
        $response['message'] = $payload;
    } elseif (is_array($payload)) {
        $response = array_merge($response, $payload);
    }
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}