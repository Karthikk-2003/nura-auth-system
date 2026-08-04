<?php
require_once __DIR__ . '/db.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Method not allowed. Only POST requests are accepted.', 405);
}

// Parse request data (JSON body or standard $_POST)
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

// Extract fields
$username  = trim($input['username'] ?? '');
$email     = trim($input['email'] ?? '');
$password  = $input['password'] ?? '';
$name      = trim($input['name'] ?? '');
$age       = filter_var($input['age'] ?? 0, FILTER_VALIDATE_INT);
$bio       = trim($input['bio'] ?? '');
$interests = $input['interests'] ?? [];

// Server-side validation
$errors = [];

if (empty($username) || !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
    $errors[] = 'Username must be 3-30 characters long and contain only letters, numbers, and underscores.';
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Please provide a valid email address.';
}

if (empty($password) || strlen($password) < 6) {
    $errors[] = 'Password must be at least 6 characters long.';
}

if (empty($name)) {
    $errors[] = 'Full Name is required.';
}

if ($age === false || $age < 1 || $age > 120) {
    $errors[] = 'Please enter a valid age between 1 and 120.';
}

if (!empty($errors)) {
    jsonResponse(false, ['message' => implode(' ', $errors), 'errors' => $errors], 400);
}

try {
    $pdo = getMySQLConnection();

    // Check for duplicate Username or Email using Prepared Statements
    $checkStmt = $pdo->prepare('SELECT id, username, email FROM users WHERE username = :username OR email = :email LIMIT 1');
    $checkStmt->execute([
        ':username' => $username,
        ':email'    => $email
    ]);

    $existingUser = $checkStmt->fetch();
    if ($existingUser) {
        if (strtolower($existingUser['email']) === strtolower($email)) {
            jsonResponse(false, 'An account with this email already exists.', 409);
        } else {
            jsonResponse(false, 'This username is already taken. Please choose another.', 409);
        }
    }

    // Secure password using BCRYPT
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);

    // Insert user account into MySQL using Prepared Statement
    $insertStmt = $pdo->prepare('INSERT INTO users (username, email, password_hash) VALUES (:username, :email, :password_hash)');
    $insertStmt->execute([
        ':username'      => $username,
        ':email'         => $email,
        ':password_hash' => $passwordHash
    ]);

    $userId = (int)$pdo->lastInsertId();

    // Insert extended profile data into MongoDB
    $profileSaved = saveMongoProfile($userId, [
        'name'      => $name,
        'age'       => $age,
        'bio'       => $bio,
        'interests' => $interests
    ]);

    if (!$profileSaved) {
        error_log("Failed to save MongoDB profile for user_id {$userId}");
    }

    jsonResponse(true, [
        'message' => 'Registration successful! You may now log in.',
        'user_id' => $userId,
        'username' => $username
    ], 201);

} catch (\PDOException $e) {
    error_log('Registration MySQL Exception: ' . $e->getMessage());
    jsonResponse(false, 'Database error occurred during registration. Please try again.', 500);
} catch (\Exception $e) {
    error_log('Registration General Exception: ' . $e->getMessage());
    jsonResponse(false, 'An unexpected server error occurred.', 500);
}
