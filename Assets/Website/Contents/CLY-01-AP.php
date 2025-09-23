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
<style>
.access-split {
  min-height: 100vh;
  display: grid;
  grid-template-columns: 1fr 480px;
  align-items: stretch;
  background: var(--bg-gradient);
  gap: 0;
  overflow: hidden;
}
.brand-col { padding: 8vh 8vw; position: relative; color: var(--text); }
.brand-col::before,
.brand-col::after {
  content: "";
  position: absolute;
  pointer-events: none;
  filter: blur(28px);
  opacity: 0.075;
  mix-blend-mode: screen;
}
.brand-col::before {
  width: 520px;
  height: 520px;
  right: -120px;
  top: 20%;
  border-radius: 50%;
  background: radial-gradient(circle at 30% 30%, rgba(255,169,77,0.35), transparent 35%),
              radial-gradient(circle at 70% 70%, rgba(168,107,255,0.25), transparent 40%);
  transform: rotate(-8deg);
}
.brand-col::after {
  width: 420px;
  height: 140px;
  left: -60px;
  bottom: 8%;
  background: linear-gradient(120deg, rgba(255,255,255,0.02), rgba(255,255,255,0.00));
  transform: skewY(-12deg);
}
.rupee-deco {
  position: absolute;
  font-size: 160px;
  color: rgba(255,255,255,0.03);
  transform: rotate(-18deg);
  pointer-events: none;
  user-select: none;
  text-shadow: none;
  animation: floatSlow 10s ease-in-out infinite;
}
.rupee-deco.r1 { right: 8%; top: 12%; font-size: 160px; transform: rotate(-8deg); animation-delay: 0s; }
.rupee-deco.r2 { left: 6%; bottom: 10%; font-size: 120px; transform: rotate(6deg); animation-delay: 2s; opacity: 0.035; }
.rupee-deco.r3 { left: 18%; top: 4%; font-size: 80px; transform: rotate(-28deg); animation-delay: 4s; opacity: 0.04; }
@keyframes floatSlow {
  0% { transform: translateY(0) rotate(-8deg) scale(1); }
  50% { transform: translateY(-12px) rotate(-6deg) scale(1.02); }
  100% { transform: translateY(0) rotate(-8deg) scale(1); }
}
.brand-title { font-size: 74px; font-weight: 900; line-height: 1; letter-spacing: -1px; margin-bottom: 10px;; padding: 0;
     background: linear-gradient(135deg, #ffb86b, #ff7b7b, #a86bff); -webkit-background-clip: text; 
     background-clip: text; -webkit-text-fill-color: transparent; text-shadow: 0 4px 0 rgba(0,0,0,0.45), 0 20px 40px rgba(0,0,0,0.6); }
@keyframes subtlePop {
  0% { transform: translateY(18px) scale(.98); opacity: 0; filter: blur(8px);}
  100% { transform: translateY(0) scale(1); opacity: 1; filter: blur(0);}
}
@keyframes shine {
  0% { transform: translateX(-110%); }
  100% { transform: translateX(110%); }
}
.brand-tag { margin-top: -8px; font-weight:700; letter-spacing:2px; color: var(--muted); font-size:14px; text-transform:uppercase; }
.brand-moto { margin-top: 14px; font-size:28px; color: var(--muted); max-width:82ch; line-height:1.6; }
.auth-col {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 8vh 48px;
  border-left: 1px solid rgba(255,255,255,0.02);
  position: relative;
  background: linear-gradient(180deg, rgba(255,255,255,0.006), rgba(255,255,255,0.0));
}
.auth-panel {
  width: 100%;
  max-width: 360px;
  padding: 28px 28px;
  border-radius: 14px;
  background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
  border: 1px solid rgba(255,255,255,0.03);
  box-shadow: 0 12px 34px rgba(0,0,0,0.6);
  display: flex;
  flex-direction: column;
  gap: 16px;
  align-items: stretch;
}
.auth-panel h1 {
  font-size: 22px;
  margin: 0;
  letter-spacing: -0.6px;
  color: var(--text);
  font-weight: 800;
}
.auth-panel p.desc {
  margin: 0;
  color: var(--muted);
  font-size: 14px;
  line-height: 1.5;
}
.google-btn {
  display: inline-flex;
  gap: 12px;
  align-items: center;
  justify-content: center;
  width: 100%;
  padding: 14px 18px;
  border-radius: 12px;
  background: #fff;
  color: #222;
  font-weight: 800;
  text-decoration: none;
  border: 0;
  box-shadow: 0 10px 30px rgba(2,6,23,0.36);
  transition: transform var(--transition-fast) ease, box-shadow var(--transition-fast) ease;
  font-size: 15px;
}
.desc-bottom { 
    font-size: 23px!important;
    color: red; 
    text-align: left!important;
}
.google-btn:hover { transform: translateY(-4px); box-shadow: 0 18px 44px rgba(2,6,23,0.45); }
.google-btn:active { transform: translateY(1px); }
.small-muted { font-size:13px; color:var(--muted); text-align:center; }
.signout { color: var(--muted); text-decoration: underline; text-decoration-thickness: 1px; }
@media (max-width: 1100px) {
  .access-split { grid-template-columns: 1fr 420px; }
  .brand-col { padding-left: 6vw; padding-right: 6vw; }
  .auth-col { padding-left: 28px; padding-right: 28px; }
}

@media (max-width: 820px) {
  .access-split { grid-template-columns: 1fr; }
  .brand-col { padding: 6vh 6vw; order: 1; }
  .auth-col { padding: 6vh 6vw; order: 2; border-left: 0; border-top: 1px solid rgba(255,255,255,0.02); }
  .auth-panel { max-width: 100%; }
  .brand-title::after { display: none; }
}
.google-btn:focus { outline: 3px solid rgba(66,133,244,0.14); outline-offset: 3px; border-radius: 12px; }
</style>
<div class="access-split" role="main" aria-labelledby="clyTitle">
  <div class="brand-col" aria-label="CounterLy brand area">
    <div class="rupee-deco r1">₹</div>
    <div class="rupee-deco r2">₹</div>
    <div class="rupee-deco r3">₹</div>
    <div style="max-width:66ch; position:relative; z-index:2;">
      <div class="brand-title" id="clyTitle">CounterLy</div>
      <div class="brand-tag" aria-hidden="true">Clearer transactions. Faster decisions.</div>
      <p class="brand-moto" style="margin-top:18px;">
        Upload PDF bank statements and instantly get clean, grouped insights.
        CounterLy automatically clusters counterparties so you can quickly see who you pay or receive from and when.
      </p>
      <p class="desc-bottom " style="margin-top:18px; max-width:58ch;">
        No passwords required — sign in with Google. We only store your name, email and avatar for account management.
      </p>
    </div>
  </div>
  <aside class="auth-col" aria-label="Sign in area">
    <div class="auth-panel" role="region" aria-labelledby="authHead">
      <h1 id="authHead">Sign in to CounterLy</h1>
      <p class="desc">Use your Google account to create or access your CounterLy workspace. Your statements and parsing history are private and auditable.</p>
      <a class="google-btn" id="googleSignBtn" title="Continue with Google" rel="nofollow" href="javascript:void(0);">
        <svg width="20" height="20" viewBox="0 0 533.5 544.3" class="google-svg" aria-hidden="true" focusable="false">
          <path fill="#4285F4" d="M533.5 278.4c0-17.4-1.4-34.1-4.1-50.4H272v95.5h146.9c-6.4 34.4-26.1 63.6-55.6 83.2v68.9h89.7c52.6-48.4 82.5-120.1 82.5-197.2z"/>
          <path fill="#34A853" d="M272 544.3c73.6 0 135.6-24.4 180.8-66.3l-89.7-68.9c-25 17-57 27.1-91.1 27.1-69.9 0-129.3-47.2-150.6-110.4H29.9v69.7C75.3 486 167.6 544.3 272 544.3z"/>
          <path fill="#FBBC05" d="M121.4 327.9c-11.3-33.7-11.3-69.9 0-103.6V154.6H29.9c-44.4 88.9-44.4 195.1 0 284l91.5-110.7z"/>
          <path fill="#EA4335" d="M272 108.1c39.9 0 75.9 13.7 104.1 40.6l78.1-78.1C403.9 24.6 335.5 0 272 0 167.6 0 75.3 58.3 29.9 154.6l91.5 69.7c21.3-63.2 80.7-110.4 150.6-110.4z"/>
        </svg>
        <span>Continue with Google</span>
      </a>
      <div id="status" class="hidden" role="status"></div>
      <p class="small-muted" style="margin:0; text-align:center;">By continuing you agree to our Terms & Privacy policy.</p>
      <div style="height:6px"></div>
      <div style="display:flex; justify-content:center; gap:10px; align-items:center;">
        <span class="small-muted">Already signed in?</span>
        <a class="signout" href="/Assets/Website/Api/logout.php">Sign out</a>
      </div>
    </div>
  </aside>
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

    document.getElementById('googleSignBtn').onclick = function(e) {
      e.preventDefault();
      google.accounts.id.prompt();
    };
  };
</script>