<?php
require_once __DIR__ . '/db.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed. Only POST requests are accepted.', 405);
}

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$email    = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

// Basic server-side validation
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(false, 'Please provide a valid email address.', 400);
}

if (empty($password)) {
    jsonResponse(false, 'Password field cannot be empty.', 400);
}

try {
    $pdo = getMySQLConnection();

    // Prepared statement query for user account lookup
    $stmt = $pdo->prepare('SELECT id, username, email, password_hash FROM users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Uniform error response to prevent user enumeration attacks
        jsonResponse(false, 'Invalid email or password.', 401);
    }

    // Verify password against stored BCRYPT hash
    if (!password_verify($password, $user['password_hash'])) {
        jsonResponse(false, 'Invalid email or password.', 401);
    }

    // Generate cryptographically secure 64-character token
    $sessionToken = bin2hex(random_bytes(32));

    // Construct session data dictionary
    $sessionData = [
        'user_id'    => (int)$user['id'],
        'username'   => $user['username'],
        'email'      => $user['email'],
        'login_time' => date('Y-m-d H:i:s')
    ];

    // Store in Redis with key session:<token> and 24-hour TTL (86,400 seconds)
    $redisSaved = saveSession($sessionToken, $sessionData, 86400);

    if (!$redisSaved) {
        jsonResponse(false, 'Session storage service unavailable. Please try again.', 500);
    }

    // Set HTTP-Only Cookie for session transport security
    setcookie('session_token', $sessionToken, [
        'expires'  => time() + 86400,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false, // Set to true if running over HTTPS in production
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    jsonResponse(true, [
        'message'       => 'Login successful!',
        'session_token' => $sessionToken,
        'user'          => [
            'id'       => (int)$user['id'],
            'username' => $user['username'],
            'email'    => $user['email']
        ]
    ], 200);

} catch (\PDOException $e) {
    error_log('Login MySQL Exception: ' . $e->getMessage());
    jsonResponse(false, 'Database authentication error occurred.', 500);
} catch (\Exception $e) {
    error_log('Login General Exception: ' . $e->getMessage());
    jsonResponse(false, 'An unexpected error occurred during login.', 500);
}
