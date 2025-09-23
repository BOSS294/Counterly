<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);


header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path' => $cookieParams['path'],
        'domain' => $cookieParams['domain'],
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// read payload
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data) || empty($data['id_token'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing id_token']);
    exit;
}
$id_token = $data['id_token'];

$secretsPath = __DIR__ . '/../../Resources/secrets.env';
$clientId = getenv('GOOGLE_CLIENT_ID') ?: null;
if (!$clientId && is_readable($secretsPath)) {
    foreach (file($secretsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (trim($line) === '' || $line[0] === '#') continue;
        [$k,$v] = array_map('trim', explode('=', $line, 2) + [1 => '']);
        $v = preg_replace('/^["\']|["\']$/', '', $v);
        if ($k === 'GOOGLE_CLIENT_ID') { $clientId = $v; break; }
    }
}
if (!$clientId) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server misconfiguration (missing GOOGLE_CLIENT_ID)']);
    exit;
}

$verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($id_token);
$opts = [
    "http" => [
        "timeout" => 5,
        "header" => "User-Agent: CounterLy/1.0\r\n"
    ]
];
$context = stream_context_create($opts);
$resp = @file_get_contents($verifyUrl, false, $context);
if ($resp === false) {
    $errorDetail = '';
    if (isset($http_response_header) && is_array($http_response_header)) {
        $errorDetail = implode(" | ", $http_response_header);
    }
    http_response_code(502);
    echo json_encode([
        'success' => false,
        'error' => 'Token verification failed',
        'detail' => $errorDetail
    ]);
    exit;
}
$tokenInfo = json_decode($resp, true);
if (!is_array($tokenInfo) || empty($tokenInfo['aud'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid token']);
    exit;
}

if ($tokenInfo['aud'] !== $clientId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token audience mismatch']);
    exit;
}
if (isset($tokenInfo['exp']) && $tokenInfo['exp'] < time()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Token expired']);
    exit;
}

$googleSub = $tokenInfo['sub'] ?? null;
$email = $tokenInfo['email'] ?? null;
$name = $tokenInfo['name'] ?? null;
$picture = $tokenInfo['picture'] ?? null; 

if (!$googleSub || !$email) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Incomplete token info']);
    exit;
}

try {
    require_once __DIR__ . '/../../Connectors/connector.php'; 
    $db = db();

    $db->beginTransaction();


    $existing = $db->fetch("SELECT * FROM users WHERE oauth_provider = 'google' AND oauth_id = ?", [$googleSub]);

    if ($existing) {
        $userId = (int)$existing['id'];
        try {
            $db->execute(
                "UPDATE users SET name = ?, email = ?, oauth_provider = 'google', oauth_id = ?, user_avtar = ?, updated_at = NOW() WHERE id = ?",
                [$name, $email, $googleSub, $picture, $userId]
            );
        } catch (\Throwable $e) {
            $db->execute(
                "UPDATE users SET name = ?, email = ?, oauth_provider = 'google', oauth_id = ?, updated_at = NOW() WHERE id = ?",
                [$name, $email, $googleSub, $userId]
            );
        }
        $db->logAudit('login', 'user', $userId, ['method' => 'google', 'action' => 'login'], $userId);
    } else {
        $byEmail = $db->fetch("SELECT * FROM users WHERE email = ? LIMIT 1", [$email]);
        if ($byEmail) {
            $userId = (int)$byEmail['id'];
            try {
                $db->execute(
                    "UPDATE users SET oauth_provider = 'google', oauth_id = ?, name = ?, user_avtar = ?, updated_at = NOW() WHERE id = ?",
                    [$googleSub, $name, $picture, $userId]
                );
            } catch (\Throwable $e) {
                $db->execute(
                    "UPDATE users SET oauth_provider = 'google', oauth_id = ?, name = ?, updated_at = NOW() WHERE id = ?",
                    [$googleSub, $name, $userId]
                );
            }
            $db->logAudit('login', 'user', $userId, ['method' => 'google', 'action' => 'link_by_email'], $userId);
        } else {
            try {
                $userId = $db->insertAndGetId(
                    "INSERT INTO users (name, email, oauth_provider, oauth_id, user_avtar, created_at, updated_at) VALUES (?, ?, 'google', ?, ?, NOW(), NOW())",
                    [$name, $email, $googleSub, $picture]
                );
            } catch (\Throwable $e) {
                $userId = $db->insertAndGetId(
                    "INSERT INTO users (name, email, oauth_provider, oauth_id, created_at, updated_at) VALUES (?, ?, 'google', ?, NOW(), NOW())",
                    [$name, $email, $googleSub]
                );
            }
            $db->logAudit('signup', 'user', $userId, ['method' => 'google', 'oauth_sub' => $googleSub], $userId);
        }
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $name;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_avatar'] = $picture;
    $_SESSION['logged_in_via'] = 'google';

    $db->commit();


    echo json_encode(['success' => true, 'user_id' => $userId, 'redirect' => '/app/dashboard.php']);
    exit;
} catch (\Throwable $e) {
    if (isset($db) && method_exists($db, 'rollback')) {
        try { $db->rollback(); } catch (\Throwable $_) {}
    }

    try {
        if (isset($db)) $db->logParse(null, 'error', 'auth_processing_failed', ['err' => $e->getMessage()]);
    } catch (\Throwable $_) {}
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error']);
    exit;
}
