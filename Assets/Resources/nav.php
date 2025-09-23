<?php
// nav.php — User top navigation for signed-in dashboard
// - Uses session values set by auth_callback.php: user_id, user_name, user_email, user_avatar
// - Default name = "Ammer Aadmi"
// - No admin checks or admin redirects
// - Single notification placeholder: "Notifications are coming soon..."
// - Quick Actions removed; GitHub icon present
// - Safe HTML escaping via esc()

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    header('Location: /');
    exit;
}

$__user_name   = $_SESSION['user_name'] ?? 'Nahi Pta Bhai';
$__user_role   = $_SESSION['user_role'] ?? 'Ammer Aadmi';
$__user_avatar = $_SESSION['user_avatar'] ?? '/Assets/Website/Images/default-avatar.png';

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<head>
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
  <link rel="stylesheet" href="/Assets/Resources/base.css">
</head>

<nav class="site-header nav-fullwidth" role="navigation" aria-label="User top navigation">
  <div class="container nav-row">
    <div class="nav-left" style="min-width:220px;">
      <div class="nav-title" aria-hidden="false">
        <div class="title-main">VERSION <small class="version">V1</small></div>
        <div class="title-sub small-muted">Concise dashboard • grouped insights</div>
      </div>
    </div>

    <div class="nav-right" role="toolbar" aria-label="Top controls">
      <div class="nav-control">
        <button id="notif-toggle" class="btn btn-ghost btn-pill icon-btn" aria-haspopup="true" aria-expanded="false" aria-controls="notif-menu" title="Notifications" type="button">
          <i class='bx bx-bell' aria-hidden="true"></i>
          <span id="notif-badge" class="notif-badge" aria-hidden="true">1</span>
          <span class="sr-only">Open notifications</span>
        </button>

        <div id="notif-menu" class="dropdown-menu notif-menu" role="menu" aria-hidden="true">
          <div class="dropdown-header">
            <strong>Notifications</strong>
            <div class="dropdown-actions">
              <button id="clear-notifs" class="btn btn-ghost small" title="Clear notifications"><i class='bx bx-trash' aria-hidden="true"></i></button>
            </div>
          </div>

          <div id="notif-list" class="dropdown-list" role="list">
            <div class="notif-item" role="menuitem" tabindex="0">
              <div class="notif-icon"><i class='bx bx-info-circle' aria-hidden="true"></i></div>
              <div class="notif-body">
                <div class="notif-row">
                  <div class="notif-title">Notifications are coming soon...</div>
                  <div class="notif-time">Now</div>
                </div>
                <div class="notif-sub">We'll show important updates here.</div>
              </div>
            </div>

            <div id="notif-empty" class="notif-empty" role="none" aria-hidden="true" style="display:none;">
              <i class='bx bx-bell' aria-hidden="true"></i>
              You're all caught up
            </div>
          </div>
        </div>
      </div>

      <div class="nav-control">
        <a class="btn btn-ghost icon-btn" href="https://github.com/your-repo" target="_blank" rel="noopener noreferrer" title="View on GitHub">
          <i class='bx bxl-github' aria-hidden="true"></i>
          <span class="sr-only">Open GitHub repository</span>
        </a>
      </div>

      <div class="user-wrap" aria-haspopup="true">
        <button id="user-toggle" class="btn btn-ghost user-btn" aria-controls="user-menu" aria-expanded="false" type="button">
          <span class="avatar">
            <img src="<?= esc($__user_avatar) ?>" alt="<?= esc($__user_name) ?> avatar" loading="lazy">
          </span>

          <div class="user-info">
            <div class="user-name"><?= esc($__user_name) ?></div>
            <div class="user-role"><?= esc($__user_role) ?></div>
          </div>

          <i class='bx bx-chevron-down' aria-hidden="true"></i>
        </button>

        <div id="user-menu" class="dropdown-menu user-menu" role="menu" aria-hidden="true">
          <a href="/app/profile.php" class="dropdown-link" role="menuitem"><i class='bx bx-user-circle' aria-hidden="true"></i> Profile</a>
          <a href="/app/settings.php" class="dropdown-link" role="menuitem"><i class='bx bx-cog' aria-hidden="true"></i> Settings</a>
          <div class="dropdown-divider" role="separator"></div>
          <a href="/Assets/Website/Api/logout.php" class="dropdown-link btn-logout" role="menuitem"><i class='bx bx-log-out' aria-hidden="true"></i> Logout</a>
        </div>
      </div>
    </div>
  </div>
</nav>

<style>
.nav-fullwidth {
  position: sticky;
  top: 0;
  z-index: 999;
  width: 100%;
  left: 0;
  border-radius: 0;
  background: linear-gradient(180deg, rgba(6,18,15,0.94), rgba(10,26,22,0.92));
  border-bottom: 1px solid rgba(255,255,255,0.02);
  box-shadow: var(--shadow-deep);
}
.nav-row { display:flex; gap:20px; align-items:center; padding:8px 18px; }

/* Left side */
.nav-left { display:flex; gap:12px; align-items:center; }
.nav-title { display:flex; flex-direction:column; line-height:1; }
.title-main { font-weight:900; font-size:15px; color:var(--text); display:flex; align-items:center; gap:8px; }
.version { font-weight:700; font-size:11px; color:var(--muted); margin-left:6px; }
.title-sub { font-size:12px; color:var(--muted); margin-top:2px; }

/* Right side */
.nav-right { display:flex; gap:10px; align-items:center; margin-left:auto; }

/* Buttons */
.icon-btn { display:inline-flex; align-items:center; justify-content:center; padding:8px 10px; color:var(--muted); }
.icon-btn:hover { color:var(--text); transform:translateY(-2px); }

/* Notification badge */
.notif-badge {
  display:inline-block;
  min-width:20px;
  height:20px;
  padding:0 6px;
  border-radius:999px;
  font-weight:900;
  font-size:12px;
  line-height:20px;
  text-align:center;
  background: linear-gradient(90deg, var(--accent), var(--accent-2));
  color: var(--text);
  box-shadow: 0 6px 20px rgba(47,122,74,0.08);
  margin-left:6px;
  vertical-align:middle;
}

/* User wrap */
.user-wrap { position:relative; display:flex; align-items:center; gap:8px; }
.user-btn { display:flex; align-items:center; gap:10px; padding:6px 10px; border-radius:999px; }
.avatar { width:44px; height:44px; border-radius:999px; overflow:hidden; display:grid; place-items:center; background:linear-gradient(135deg, rgba(149,214,164,0.06), rgba(47,122,74,0.02)); border:1px solid rgba(255,255,255,0.02); }
.avatar img { width:100%; height:100%; object-fit:cover; display:block; }
.user-info { min-width:160px; max-width:320px; display:flex; flex-direction:column; align-items:flex-start; text-align:left; }
.user-name { font-weight:900; font-size:14px; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.user-role { font-size:11px; color:var(--muted); margin-top:2px; }

/* Dropdowns / menus */
.dropdown-menu {
  position:absolute;
  right:0;
  top:56px;
  width:360px;
  max-height:360px;
  background: var(--card-bg);
  border-radius:12px;
  box-shadow: var(--shadow-deep);
  border: 1px solid rgba(255,255,255,0.03);
  overflow:hidden;
  opacity:0;
  visibility:hidden;
  transform: translateY(-6px);
  transition: all .16s cubic-bezier(.2,.9,.2,1);
  z-index: 1100;
  padding:0;
}
.dropdown-menu.open { opacity:1; visibility:visible; transform: translateY(0); }

/* Dropdown header */
.dropdown-header { display:flex; align-items:center; justify-content:space-between; padding:12px 14px; border-bottom:1px solid rgba(255,255,255,0.03); }
.dropdown-actions { display:flex; gap:8px; align-items:center; }

/* Notification list */
.dropdown-list { overflow:auto; max-height:400px; }
.notif-item { display:flex; gap:12px; padding:12px 14px; align-items:flex-start; border-bottom:1px solid rgba(255,255,255,0.02); color:inherit; transition: all .18s ease; }
.notif-item:hover { transform: translateX(6px); background: linear-gradient(180deg, rgba(149,214,164,0.02), rgba(6,18,15,0.02)); }
.notif-icon { width:44px; height:44px; border-radius:10px; display:grid; place-items:center; background:linear-gradient(135deg, rgba(47,122,74,0.06), rgba(149,214,164,0.02)); border:1px solid rgba(255,255,255,0.02); font-size:18px; }
.notif-body { flex:1; }
.notif-row { display:flex; justify-content:space-between; gap:8px; align-items:center; }
.notif-title { font-weight:900; color:var(--text); }
.notif-time { font-size:12px; color:var(--muted); }
.notif-sub { font-size:13px; color:var(--muted); margin-top:6px; }
.notif-highlight { color:var(--text); font-weight:800; }

/* empty state */
.notif-empty { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:28px; color:var(--muted); gap:8px; }

/* User dropdown */
.user-menu { top:56px; right:0; width:220px; padding:10px; background:var(--card-bg); }
.dropdown-link { display:flex; align-items:center; gap:10px; padding:10px; text-decoration:none; color:var(--text); font-weight:800; border-radius:8px; }
.dropdown-link:hover { background: linear-gradient(180deg, rgba(149,214,164,0.02), rgba(6,18,15,0.02)); color:var(--accent-2); transform: translateX(4px); }
.dropdown-divider { height:1px; background: rgba(255,255,255,0.03); margin:6px 0; }

@media (max-width: 920px) {
  .user-info { min-width:140px; }
  .dropdown-menu { right:8px; left:auto; width: calc(100vw - 32px); max-width: 420px; }
  .nav-row { padding:8px 12px; gap:12px; }
}
@media (max-width: 560px) {
  .nav-row { padding:8px 10px; gap:8px; }
  .title-sub { display:none; } 
  .notif-badge { display:none; }
}

@keyframes modal-pop { from { transform: translateY(8px) scale(.992); opacity: 0 } to { transform: translateY(0) scale(1); opacity: 1 } }
</style>

<script>
(function(){
  const notifToggle = document.getElementById('notif-toggle');
  const notifMenu = document.getElementById('notif-menu');
  const notifBadge = document.getElementById('notif-badge');
  const notifList = document.getElementById('notif-list');
  const notifEmpty = document.getElementById('notif-empty');
  const clearBtn = document.getElementById('clear-notifs');

  const userToggle = document.getElementById('user-toggle');
  const userMenu = document.getElementById('user-menu');

  function openMenu(el, toggleEl) {
    el.classList.add('open');
    el.setAttribute('aria-hidden','false');
    if (toggleEl) toggleEl.setAttribute('aria-expanded','true');
  }
  function closeMenu(el, toggleEl) {
    el.classList.remove('open');
    el.setAttribute('aria-hidden','true');
    if (toggleEl) toggleEl.setAttribute('aria-expanded','false');
  }
  function toggleMenu(el, toggleEl) {
    const isOpen = el.classList.contains('open');
    if (isOpen) closeMenu(el, toggleEl); else openMenu(el, toggleEl);
  }

  if (notifToggle && notifMenu) {
    notifToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleMenu(notifMenu, notifToggle);
      if (userMenu && userMenu.classList.contains('open')) closeMenu(userMenu, userToggle);
    });
  }

  if (clearBtn) {
    clearBtn.addEventListener('click', (e) => {
      e.preventDefault();
      const items = notifList.querySelectorAll('.notif-item');
      items.forEach(it => it.remove());
      if (notifEmpty) { notifEmpty.style.display = 'flex'; notifEmpty.setAttribute('aria-hidden','false'); }
      updateBadge(0);
    });
  }

  function updateBadge(n) {
    if (!notifBadge) return;
    if (n <= 0) {
      notifBadge.style.display = 'none';
      notifBadge.textContent = '0';
    } else {
      notifBadge.style.display = 'inline-block';
      notifBadge.textContent = n;
    }
  }

  if (userToggle && userMenu) {
    userToggle.addEventListener('click', (e) => {
      e.stopPropagation();
      toggleMenu(userMenu, userToggle);
      if (notifMenu && notifMenu.classList.contains('open')) closeMenu(notifMenu, notifToggle);
    });
  }

  document.addEventListener('click', (e) => {
    if (notifMenu && !notifMenu.contains(e.target) && notifToggle && !notifToggle.contains(e.target)) closeMenu(notifMenu, notifToggle);
    if (userMenu && !userMenu.contains(e.target) && userToggle && !userToggle.contains(e.target)) closeMenu(userMenu, userToggle);
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      if (notifMenu) closeMenu(notifMenu, notifToggle);
      if (userMenu) closeMenu(userMenu, userToggle);
    }
  });

  (function init() {
    const items = notifList ? notifList.querySelectorAll('.notif-item') : [];
    updateBadge(items.length);
    if ((!items || items.length === 0) && notifEmpty) notifEmpty.setAttribute('aria-hidden','false');
  })();

})();
</script>
