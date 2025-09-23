<?php

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

function loadSecrets() {
    $keys = ['GOOGLE_CLIENT_ID','GOOGLE_CLIENT_SECRET'];
    $out = [];
    foreach ($keys as $k) {
        $v = getenv($k);
        if ($v !== false && $v !== '') $out[$k] = $v;
    }
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
                $v = preg_replace('/^["\']|["\']$/', '', $v);
                if (!isset($out[$k])) $out[$k] = $v;
            }
        }
    }
    return $out;
}

$secrets = loadSecrets();
$clientId = $secrets['GOOGLE_CLIENT_ID'] ?? null;

$redirectUri = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . $_SERVER['HTTP_HOST'] . '/Assets/Website/Api/auth_callback.php';

if (!$clientId) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR: GOOGLE_CLIENT_ID missing. Add it to environment or Assets/Resources/secrets.env";
    exit;
}

$state = bin2hex(random_bytes(16));
$_SESSION['oauth2_state'] = $state;

$params = [
    'response_type' => 'code',
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'scope' => 'openid email profile',
    'state' => $state,
    'access_type' => 'offline',
    'prompt' => 'select_account'
];

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);

if (isset($_GET['debug']) && $_GET['debug'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'authUrl' => $authUrl,
        'state' => $state,
        'redirect_uri' => $redirectUri
    ], JSON_PRETTY_PRINT);
    exit;
}

header('Location: ' . $authUrl, true, 302);
exit;
