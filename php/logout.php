<?php
require_once __DIR__ . '/db.php';

// Accept POST or GET requests for logout
$sessionToken = getBearerToken();

if ($sessionToken) {
    // Delete session token key from Redis
    deleteSession($sessionToken);
}

// Expire HTTP-Only Cookie
setcookie('session_token', '', [
    'expires'  => time() - 3600,
    'path'     => '/',
    'domain'   => '',
    'secure'   => false,
    'httponly' => true,
    'samesite' => 'Lax'
]);

jsonResponse(true, 'Logged out successfully.', 200);
