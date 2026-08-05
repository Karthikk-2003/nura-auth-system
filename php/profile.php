<?php
require_once __DIR__ . '/db.php';

// Extract token from Bearer Header, Cookie, or Input Parameter
$sessionToken = getBearerToken();

if (empty($sessionToken)) {
    jsonResponse(false, 'Unauthorized: No session token provided. Please log in.', 401);
}

// Validate active session token against Redis
$session = getSession($sessionToken);

if (!$session || empty($session['user_id'])) {
    jsonResponse(false, 'Unauthorized: Invalid or expired session. Please log in again.', 401);
}

$userId = (int)$session['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        $pdo = getMySQLConnection();

        // Query primary account credentials from MySQL using Prepared Statement
        $stmt = $pdo->prepare('SELECT id, username, email, created_at FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $mysqlUser = $stmt->fetch();

        if (!$mysqlUser) {
            jsonResponse(false, 'User account not found.', 404);
        }

        // Query extended profile details from MongoDB using user_id
        $mongoProfile = getMongoProfile($userId);

        // Safely extract all MongoDB profile fields with fallbacks
        $profileName       = $mongoProfile['name']       ?? ($mongoProfile['full_name'] ?? '');
        $profileAge        = (int)($mongoProfile['age']  ?? 0);
        $profileBio        = $mongoProfile['bio']        ?? '';
        $profileInterests  = $mongoProfile['interests']  ?? [];
        $profileUpdatedAt  = $mongoProfile['updated_at'] ?? null;

        // Normalize interests to a plain array of strings
        if (!is_array($profileInterests)) {
            $profileInterests = [];
        }

        jsonResponse(true, [
            'user' => [
                'id'         => (int)$mysqlUser['id'],
                'username'   => $mysqlUser['username'],
                'email'      => $mysqlUser['email'],
                'created_at' => $mysqlUser['created_at']
            ],
            'profile' => [
                'name'       => $profileName,
                'age'        => $profileAge,
                'bio'        => $profileBio,
                'interests'  => array_values(array_filter($profileInterests)),
                'updated_at' => $profileUpdatedAt
            ]
        ], 200);

    } catch (\PDOException $e) {
        error_log('Profile GET MySQL Exception: ' . $e->getMessage());
        jsonResponse(false, 'Failed to retrieve profile credentials.', 500);
    } catch (\Exception $e) {
        error_log('Profile GET Exception: ' . $e->getMessage());
        jsonResponse(false, 'Server error fetching profile data.', 500);
    }
} elseif ($method === 'POST' || $method === 'PUT') {
    // Parse update payload
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }

    $name      = trim($input['name'] ?? '');
    $age       = filter_var($input['age'] ?? 0, FILTER_VALIDATE_INT);
    $bio       = trim($input['bio'] ?? '');
    $interests = $input['interests'] ?? [];
    $username  = trim($input['username'] ?? '');

    // Normalize interests input (supports comma-separated string or array)
    if (is_string($interests)) {
        $interests = array_values(array_filter(array_map('trim', explode(',', $interests))));
    } elseif (is_array($interests)) {
        $interests = array_values(array_filter(array_map('trim', $interests)));
    } else {
        $interests = [];
    }

    $errors = [];

    if (empty($name)) {
        $errors[] = 'Full Name is required.';
    }

    if ($age === false || $age < 1 || $age > 120) {
        $errors[] = 'Please enter a valid age between 1 and 120.';
    }

    if (!empty($username) && !preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $errors[] = 'Username must be 3-30 characters long (letters, numbers, underscores).';
    }

    if (!empty($errors)) {
        jsonResponse(false, ['message' => implode(' ', $errors), 'errors' => $errors], 400);
    }

    try {
        $pdo = getMySQLConnection();

        // If username was provided and changed, perform duplicate check and update MySQL
        if (!empty($username)) {
            $checkStmt = $pdo->prepare('SELECT id FROM users WHERE username = :username AND id != :id LIMIT 1');
            $checkStmt->execute([':username' => $username, ':id' => $userId]);
            if ($checkStmt->fetch()) {
                jsonResponse(false, 'Username is already taken by another user.', 409);
            }

            $updateUserStmt = $pdo->prepare('UPDATE users SET username = :username WHERE id = :id');
            $updateUserStmt->execute([':username' => $username, ':id' => $userId]);

            // Update session cache in Redis with new username
            $session['username'] = $username;
            saveSession($sessionToken, $session, 86400);
        }

        // Upsert extended profile document into MongoDB
        $profileUpdated = saveMongoProfile($userId, [
            'name'      => $name,
            'age'       => (int)$age,
            'bio'       => $bio,
            'interests' => $interests
        ]);

        if (!$profileUpdated) {
            jsonResponse(false, 'Failed to update profile details in MongoDB.', 500);
        }

        jsonResponse(true, [
            'message' => 'Profile updated successfully!',
            'profile' => [
                'name'      => $name,
                'age'       => (int)$age,
                'bio'       => $bio,
                'interests' => $interests
            ]
        ], 200);

    } catch (\PDOException $e) {
        error_log('Profile UPDATE MySQL Exception: ' . $e->getMessage());
        jsonResponse(false, 'Database error while updating profile.', 500);
    } catch (\Exception $e) {
        error_log('Profile UPDATE Exception: ' . $e->getMessage());
        jsonResponse(false, 'Server error updating profile.', 500);
    }
} else {
    jsonResponse(false, 'Method Not Allowed', 405);
}
