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
    $db   = getenv('MYSQL_DB') ?: 'auth_db';
    $user = getenv('MYSQL_USER') ?: 'auth_user';
    $pass = getenv('MYSQL_PASSWORD') ?: 'auth_password';
    $charset = 'utf8mb4';

    $dsn = "mysql:host={$host};dbname={$db};charset={$charset}";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        return $pdo;
    } catch (\PDOException $e) {
        jsonResponse(false, 'MySQL Connection Error: ' . $e->getMessage(), 500);
        exit;
    }
}

/**
 * Get MongoDB Driver Manager Connection
 */
function getMongoDBManager() {
    static $manager = null;
    if ($manager !== null) {
        return $manager;
    }

    $host = getenv('MONGO_HOST') ?: 'localhost';
    $port = getenv('MONGO_PORT') ?: '27017';

    try {
        $manager = new MongoDB\Driver\Manager("mongodb://{$host}:{$port}");
        return $manager;
    } catch (\Exception $e) {
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
    } catch (\Exception $e) {
        error_log('Mongo Save Error: ' . $e->getMessage());
        return false;
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
    } catch (\Exception $e) {
        error_log('Mongo Fetch Error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Get Redis Connection
 */
function getRedisConnection() {
    static $redis = null;
    if ($redis !== null) {
        return $redis;
    }

    $host = getenv('REDIS_HOST') ?: 'localhost';
    $port = (int)(getenv('REDIS_PORT') ?: 6379);

    try {
        $redis = new Redis();
        $redis->connect($host, $port);
        return $redis;
    } catch (\Exception $e) {
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
    } catch (\Exception $e) {
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
    } catch (\Exception $e) {
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
    } catch (\Exception $e) {
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
