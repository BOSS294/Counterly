<?php
session_start();

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

$redirectUri = $secrets['OAUTH_REDIRECT_URI'] ?? ( (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/Assets/Website/Api/auth_callback.php' );

if (!$clientId) {
    http_response_code(500);
    echo "Google client ID not configured.";
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
header('Location: ' . $authUrl);
exit;
