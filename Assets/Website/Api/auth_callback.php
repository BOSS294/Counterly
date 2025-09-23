<?php
session_start();
require_once __DIR__ . '/../../Connectors/connector.php';
function loadSecrets() {
    $keys = ['GOOGLE_CLIENT_ID','GOOGLE_CLIENT_SECRET','OAUTH_REDIRECT_URI'];
    $out = [];
    foreach ($keys as $k) {
        $v = getenv($k);
        if ($v !== false && $v !== '') $out[$k] = $v;
    }
    if (count($out) < 3) {
        $secretsPath = __DIR__ . '/../../Resources/secrets.env';
        if (is_readable($secretsPath)) {
            $lines = file($secretsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $k = trim($parts[0]);
                    $v = trim($parts[1]);
                    $v = preg_replace('/^\"|\"$|^\'|\'$/', '', $v);
                    if (in_array($k, $keys) && !isset($out[$k])) $out[$k] = $v;
                }
            }
        }
    }
    return $out;
}
$secrets = loadSecrets();
$clientId = $secrets['GOOGLE_CLIENT_ID'] ?? null;
$clientSecret = $secrets['GOOGLE_CLIENT_SECRET'] ?? null;
$redirectUri = $secrets['OAUTH_REDIRECT_URI'] ?? ( (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/Assets/Website/Api/auth_callback.php' );

if (!isset($_GET['state']) || !isset($_SESSION['oauth2_state']) || $_GET['state'] !== $_SESSION['oauth2_state']) {
    http_response_code(400);
    echo "Invalid OAuth state.";
    exit;
}
unset($_SESSION['oauth2_state']);

if (isset($_GET['error'])) {
    $err = htmlspecialchars($_GET['error']);
    $db = DB::get();
    $db->logParse(null, 'error', 'google_oauth_error: ' . $err, ['query' => $_GET]);
    echo "OAuth error: " . $err;
    exit;
}

if (!isset($_GET['code'])) {
    http_response_code(400);
    echo "Missing code from Google.";
    exit;
}

$code = $_GET['code'];
$tokenUrl = 'https://oauth2.googleapis.com/token';
$post = [
    'code' => $code,
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code'
];
$ch = curl_init($tokenUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
$response = curl_exec($ch);
$curlErr = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($response === false) {
    $db = DB::get();
    $db->logParse(null, 'error', 'oauth_token_request_failed', ['curl_err' => $curlErr]);
    http_response_code(500);
    echo "Token exchange failed.";
    exit;
}
$tokenData = json_decode($response, true);
if (!$tokenData || empty($tokenData['access_token'])) {
    $db = DB::get();
    $db->logParse(null, 'error', 'oauth_token_parsing_failed', ['response' => $response, 'http_code' => $httpCode]);
    http_response_code(500);
    echo "Token exchange failed (invalid response).";
    exit;
}
$accessToken = $tokenData['access_token'];

$userinfoUrl = 'https://www.googleapis.com/oauth2/v3/userinfo';
$ch = curl_init($userinfoUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $accessToken
]);
$uiResp = curl_exec($ch);
$uErr = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($uiResp === false) {
    $db = DB::get();
    $db->logParse(null, 'error', 'userinfo_request_failed', ['curl_err' => $uErr]);
    http_response_code(500);
    echo "Userinfo fetch failed.";
    exit;
}

$userinfo = json_decode($uiResp, true);
if (!$userinfo || empty($userinfo['sub'])) {
    $db = DB::get();
    $db->logParse(null, 'error', 'userinfo_parsing_failed', ['response' => $uiResp]);
    http_response_code(500);
    echo "Userinfo fetch failed (invalid response).";
    exit;
}

$googleSub = $userinfo['sub'];
$email = $userinfo['email'] ?? null;
$name = $userinfo['name'] ?? null;
$picture = $userinfo['picture'] ?? null;
$provider = 'google';

try {
    $db = DB::get();

    $existing = $db->fetch("SELECT * FROM users WHERE oauth_provider = :p AND oauth_id = :oid LIMIT 1", [
        ':p' => $provider, ':oid' => $googleSub
    ]);

    if ($existing) {
        $db->execute("UPDATE users SET name = :name, email = :email, user_avtar = :avatar, updated_at = NOW() WHERE id = :id", [
            ':name' => $name,
            ':email' => $email,
            ':avatar' => $picture,
            ':id' => $existing['id']
        ]);
        $userId = (int)$existing['id'];
        $db->logAudit('login', 'user', $userId, ['method' => 'google', 'action' => 'login'], $userId);
    } else {
        $userId = null;
        if ($email) {
            $byEmail = $db->fetch("SELECT * FROM users WHERE email = :email LIMIT 1", [':email' => $email]);
            if ($byEmail) {
                $db->execute("UPDATE users SET oauth_provider = :p, oauth_id = :oid, user_avtar = :avatar, name = :name, updated_at = NOW() WHERE id = :id", [
                    ':p' => $provider,
                    ':oid' => $googleSub,
                    ':avatar' => $picture,
                    ':name' => $name,
                    ':id' => $byEmail['id']
                ]);
                $userId = (int)$byEmail['id'];
            }
        }

        if (!$userId) {
            $insertSql = "INSERT INTO users (name, email, oauth_provider, oauth_id, user_avtar, created_at, updated_at)
                          VALUES (:name, :email, :p, :oid, :avatar, NOW(), NOW())";
            $params = [
                ':name' => $name,
                ':email' => $email,
                ':p' => $provider,
                ':oid' => $googleSub,
                ':avatar' => $picture
            ];
            $db->insertAndGetId($insertSql, $params);
            $userId = (int)$db->fetch("SELECT LAST_INSERT_ID() AS id")['id'];
        }

        $db->logAudit('signup', 'user', $userId, ['method' => 'google', 'oauth_sub' => $googleSub], $userId);
    }

    // Session
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_name'] = $name;
    $_SESSION['user_email'] = $email;
    $_SESSION['user_avatar'] = $picture;
    $_SESSION['logged_in_via'] = 'google';

    header('Location: /dashboard.php');
    exit;

} catch (Throwable $e) {
    try {
        $db = DB::get();
        $db->logParse(null, 'error', 'auth_processing_failed', ['err' => $e->getMessage()]);
    } catch (Throwable $t) { /* ignore ( Jaise usne tujhe kiya tha :) */ }
    http_response_code(500);
    echo "Login failed. Please try again later.";
    exit;
}
