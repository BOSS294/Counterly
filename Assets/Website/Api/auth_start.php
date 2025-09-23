<?php
// auth_start.php
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
    $keys = ['GOOGLE_CLIENT_ID'];
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
if (!$clientId) {
    http_response_code(500);
    echo "ERROR: GOOGLE_CLIENT_ID missing";
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <script src="https://accounts.google.com/gsi/client" async defer></script>
  <style>
    .hidden { display:none; }
  </style>
</head>
<body>
  <div class="panel" role="main" aria-label="Google sign in">
    <h2>Continue with Google</h2>
    <div id="gbtn"></div>
    <div id="status" class="hidden" role="status"></div>
  </div>

  <script>
    const CLIENT_ID = "<?php echo htmlspecialchars($clientId, ENT_QUOTES); ?>";

    function handleCredentialResponse(response) {
      const id_token = response.credential;
      if (!id_token) {
        document.getElementById('status').classList.remove('hidden');
        document.getElementById('status').textContent = 'No credential received';
        return;
      }

      (async () => {
        try {
          const res = await fetch('/Assets/Website/Api/auth_callback.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_token })
          });
          const j = await res.json();
          if (res.ok && j.success) {
            window.location.href = '/dashboard.php';
          } else {
            document.getElementById('status').classList.remove('hidden');
            document.getElementById('status').textContent = j.error || 'Sign-in failed';
          }
        } catch (err) {
          document.getElementById('status').classList.remove('hidden');
          document.getElementById('status').textContent = 'Network error';
        }
      })();
    }

    window.onload = function() {
      google.accounts.id.initialize({
        client_id: CLIENT_ID,
        callback: handleCredentialResponse,
        ux_mode: 'popup'
      });
      google.accounts.id.renderButton(
        document.getElementById('gbtn'),
        { theme: 'outline', size: 'large', type: 'standard' }
      );
      google.accounts.id.prompt();
    };
  </script>
</body>
</html>
