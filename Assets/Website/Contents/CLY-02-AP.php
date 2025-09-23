<?php
// CLY-02-AP.php — Redesigned dashboard module (updated per request)
// Changes: darker (black) shadows, removed Upload/View Statements buttons from welcome panel,
// added Quick Actions as bordered, left-icon buttons with left-aligned text, small welcome panel improvements.
?>

<style>
/* Scoped styles for the redesigned dashboard module */
.dash-mod {
  display: grid;
  grid-template-columns: 1fr 380px;
  gap: 28px;
  align-items: start;
  margin-top: 28px;
  padding: 18px 28px;
}
@media (max-width: 980px) { .dash-mod { grid-template-columns: 1fr; padding: 12px; } }

/* card base */
.card--glass {
  background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(0,0,0,0.03));
  border-radius: 16px;
  padding: 18px;
  /* stronger black shadows as requested */
  box-shadow: 0 20px 60px rgba(0,0,0,0.9);
  border: 1px solid rgba(255,255,255,0.03);
}

.welcomer-card {
  display: flex;
  gap: 20px;
  align-items: center;
  padding: 20px;
  border-radius: 16px;
  overflow: hidden;
  position: relative;
}

/* big avatar with neon ring */
.welcome-avatar {
  width: 140px; height: 140px; border-radius: 18px; overflow: hidden;
  display: grid; place-items: center; flex: 0 0 140px;
  position: relative;
}
.welcome-avatar::before {
  content: "";
  position: absolute; inset: -6px; border-radius: 20px;
  background: linear-gradient(135deg, rgba(255,169,77,0.12), rgba(124,58,237,0.08));
  filter: blur(12px);
  z-index: 0;
}
.welcome-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; border-radius: 12px; position: relative; z-index: 1; }

.welcome-meta { flex: 1; display: flex; flex-direction: column; gap: 10px; }
.welcome-title { font-weight: 900; font-size: 20px; color: var(--text); display: flex; gap: 10px; align-items: center; }
.welcome-back { color: #A3BFFA; font-weight: 800; font-size: 14px; letter-spacing: 0.4px; }
.welcome-name { color: #FFD39B; font-weight: 900; font-size: 22px; }
.welcome-sub { color: var(--muted); font-size: 13px; }

/* subtle improvement: small tagline under welcome */
.welcome-tagline { color: rgba(255,255,255,0.45); font-size:13px; margin-top:4px; }

.role-pill { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 999px; background: rgba(255,255,255,0.02); color: #FFD39B; font-weight: 800; font-size: 13px; border: 1px solid rgba(255,255,255,0.03); }

.kpis-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-top: 8px; }
.kpi-pill { padding: 14px; border-radius: 12px; display: flex; gap: 12px; align-items: center; border: 1px solid rgba(255,255,255,0.03); transition: transform .18s ease, box-shadow .18s ease; background: linear-gradient(180deg, rgba(255,255,255,0.01), rgba(0,0,0,0.02)); }
.kpi-pill:hover { transform: translateY(-6px); box-shadow: 0 18px 48px rgba(0,0,0,0.9); }
.kpi-icon { font-size: 22px; width: 36px; height: 36px; display:grid; place-items:center; border-radius:8px; background: rgba(255,255,255,0.02); }
.kpi-value { font-weight: 900; font-size: 18px; }
.kpi-label { font-size: 12px; color: var(--muted); font-weight:700; }

/* visual accent column */
.side-column {
  display: flex; flex-direction: column; gap: 12px;
}
.quick-chart-card { min-height: 320px; display:flex; flex-direction:column; gap:12px; }

.top-cp-list { display:flex; flex-direction:column; gap:8px; }
.top-cp-item { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:10px; border-radius:10px; border:1px solid rgba(255,255,255,0.02); background: rgba(255,255,255,0.01); font-weight:700; }
.top-cp-left { display:flex; gap:10px; align-items:center; }
.cp-avatar { width:38px; height:38px; border-radius:10px; display:grid; place-items:center; background:linear-gradient(135deg, rgba(255,255,255,0.02), rgba(0,0,0,0.02)); }
.cp-name { font-weight:800; }
.cp-txcount { color:var(--muted); font-size:13px; }

.empty-state { color: var(--muted); padding: 18px; text-align: center; border-radius: 10px; background: rgba(255,255,255,0.01); }

/* Quick action buttons: bordered, no background, left icon + left-aligned text */
.quick-actions { display:flex; flex-direction:column; gap:10px; }
.quick-action-btn {
  display:flex; align-items:center; gap:12px; padding:10px 12px; border-radius:10px;
  border:1px solid rgba(255,255,255,0.06); background:transparent; color:var(--text);
  justify-content:flex-start; text-decoration:none; font-weight:800;
}
.quick-action-btn i { min-width:24px; text-align:center; font-size:18px; color:var(--muted); }
.quick-action-btn:hover { transform: translateY(-3px); box-shadow: 0 14px 36px rgba(0,0,0,0.85); }

/* animations */
@keyframes floaty { 0% { transform: translateY(0);} 50% { transform: translateY(-6px);} 100% { transform: translateY(0);} }
.welcome-avatar img:hover { animation: floaty 2.6s ease-in-out infinite; }

/* utility */
.small { font-size:13px; color:var(--muted); }
.action-row { display:flex; gap:10px; align-items:center; }
.btn--primary { background: linear-gradient(90deg, rgba(255,169,77,0.12), rgba(124,58,237,0.08)); padding:10px 14px; border-radius:10px; border:1px solid rgba(255,169,77,0.06); font-weight:800; }

/* responsive tweaks */
@media (max-width:600px){ .welcome-avatar{ width:96px; height:96px; flex:0 0 96px; } .welcome-name{ font-size:18px; } }
</style>

<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

<div class="dash-mod">
  <div>
    <div class="card--glass welcomer-card">
      <div class="welcome-avatar" id="welcomeAvatar">
        <img src="/Assets/Website/Images/default-avatar.png" alt="avatar" id="welcomeAvatarImg">
      </div>

      <div class="welcome-meta">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:16px;">
          <div style="min-width:0;">
            <div class="welcome-title">
              <div class="welcome-back">Welcome back,</div>
              <div class="welcome-name" id="welcomeName">User</div>
              <div style="font-size:18px; margin-left:6px;">✨</div>
            </div>
            <div class="welcome-sub" id="welcomeRole">Role — Member</div>
            <div class="welcome-tagline">You're doing great — here's a quick summary of your activity.</div>
          </div>

          <div style="text-align:right; display:flex; flex-direction:column; gap:8px; align-items:flex-end;">
            <div class="role-pill" id="todayDate">Loading date</div>
            <div class="role-pill" id="istTime">--:--:-- IST</div>
            <div style="height:6px;"></div>
            <!-- removed Upload / View Statements buttons per request -->
          </div>
        </div>

        <div class="kpis-grid" id="kpisGrid">
          <div class="kpi-pill">
            <span class="kpi-icon"><i class='bx bx-file'></i></span>
            <div>
              <div class="kpi-value" data-target="0">—</div>
              <div class="kpi-label">Statements</div>
            </div>
          </div>
          <div class="kpi-pill">
            <span class="kpi-icon"><i class='bx bx-transfer'></i></span>
            <div>
              <div class="kpi-value" data-target="0">—</div>
              <div class="kpi-label">Transactions</div>
            </div>
          </div>
          <div class="kpi-pill">
            <span class="kpi-icon"><i class='bx bx-group'></i></span>
            <div>
              <div class="kpi-value" data-target="0">—</div>
              <div class="kpi-label">Counterparties</div>
            </div>
          </div>
          <div class="kpi-pill">
            <span class="kpi-icon"><i class='bx bx-arrow-to-bottom'></i></span>
            <div>
              <div class="kpi-value" data-target="0">—</div>
              <div class="kpi-label">Total Debit (₹)</div>
            </div>
          </div>
          <div class="kpi-pill">
            <span class="kpi-icon"><i class='bx bx-arrow-to-top'></i></span>
            <div>
              <div class="kpi-value" data-target="0">—</div>
              <div class="kpi-label">Total Credit (₹)</div>
            </div>
          </div>
          <div class="kpi-pill">
            <span class="kpi-icon"><i class='bx bx-wallet'></i></span>
            <div>
              <div class="kpi-value" data-target="0">—</div>
              <div class="kpi-label">Latest Balance (₹)</div>
            </div>
          </div>
        </div>

        <div id="noDataMsg" class="empty-state" style="display:none; margin-top:12px;">
          Please upload any statement to prepare KPIs and chart.
        </div>
      </div>
    </div>

    <div style="margin-top:14px;">
      <div style="color:var(--muted); font-weight:800; margin-bottom:8px;">Top counterparties</div>
      <div id="topCpList" class="top-cp-list"></div>
    </div>
  </div>

  <div class="side-column">
    <div class="card--glass quick-chart-card">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <div style="font-weight:900; color:var(--text)">Last 30 days</div>
        <div style="color:var(--muted); font-size:13px;">Quick view</div>
      </div>

      <div id="chartRoot" style="height:220px; margin-top:6px;"></div>
      <div id="chartEmpty" class="empty-state" style="display:none;">No transactions to chart yet.</div>

      <div style="display:flex; gap:10px; align-items:center; justify-content:space-between; margin-top:8px;">
        <div class="small">Hover the chart to see day-level totals. Tap a counterparty to filter.</div>
        <div style="display:flex; gap:8px;">
          <button id="filterAllBtn" class="btn--primary">All</button>
          <button id="filterDebitBtn" class="btn--primary">Debits</button>
        </div>
      </div>
    </div>

    <div class="card--glass" style="padding:12px;">
      <div style="font-weight:900; margin-bottom:8px;">Quick actions</div>
      <div class="quick-actions">
        <a href="/app/upload.php" class="quick-action-btn"><i class='bx bx-upload'></i><span>Upload statement</span></a>
        <a href="/app/counterparties.php" class="quick-action-btn"><i class='bx bx-group'></i><span>Manage counterparties</span></a>
        <a href="/app/settings.php" class="quick-action-btn"><i class='bx bx-user'></i><span>Profile & settings</span></a>
        <a href="/app/reports.php" class="quick-action-btn"><i class='bx bx-file-blank'></i><span>Download reports</span></a>
      </div>
    </div>
  </div>
</div>

<script>
// keep API URL same as you already use
const API_URL = '/Assets/Website/Api/dashboard_kpis.php';

function toINRNumber(n){
  if (n === null || n === undefined) return 0;
  return Number(n) / 100.0; // API already returns rupees, but K/V here keep safe
}

function toINR(n){
  if (n === null || n === undefined) return '--';
  // if the API returns paise, convert; if already rupees, this still looks ok
  const val = Number(n);
  if (Math.abs(val) > 1000000) return val.toLocaleString('en-IN', { maximumFractionDigits: 0 });
  return val.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// animated counter
function animateCount(el, start, end, duration=850){
  const range = end - start;
  let startTime = null;
  function step(timestamp){
    if (!startTime) startTime = timestamp;
    const progress = Math.min((timestamp - startTime) / duration, 1);
    const eased = 1 - Math.pow(1 - progress, 3);
    const current = Math.round(start + (range * eased));
    el.textContent = current.toLocaleString('en-IN');
    if (progress < 1) requestAnimationFrame(step);
  }
  requestAnimationFrame(step);
}

async function loadDashboard(){
  try{
    const res = await fetch(API_URL, { credentials: 'include' });
    if (!res.ok) throw new Error('Network');
    const data = await res.json();
    if (data.error) throw new Error(data.error);

    const user = data.user || {};
    document.getElementById('welcomeName').textContent = user.name || 'User';
    document.getElementById('welcomeRole').textContent = (user.role || 'Member');
    const avatarEl = document.getElementById('welcomeAvatarImg');
    if (user.avatar) avatarEl.src = user.avatar; else avatarEl.src = '/Assets/Website/Images/default-avatar.png';

    const k = data.kpis || {};
    const targets = [k.statements_count ?? 0, k.transactions_count ?? 0, k.counterparties_count ?? 0, Math.round((k.total_debit ?? 0)), Math.round((k.total_credit ?? 0)), (k.latest_balance !== null && k.latest_balance !== undefined) ? Math.round(k.latest_balance) : 0];

    const pillEls = document.querySelectorAll('#kpisGrid .kpi-value');
    pillEls.forEach((el, idx)=>{
      const end = targets[idx] ?? 0;
      animateCount(el, 0, end);
    });

    if ((k.transactions_count ?? 0) <= 0) {
      document.getElementById('noDataMsg').style.display = 'block';
    } else {
      document.getElementById('noDataMsg').style.display = 'none';
    }

    const cpList = document.getElementById('topCpList');
    cpList.innerHTML = '';
    if (data.top_counterparties && data.top_counterparties.length > 0) {
      data.top_counterparties.forEach(cp => {
        const div = document.createElement('div');
        div.className = 'top-cp-item';
        div.innerHTML = `
          <div class="top-cp-left">
            <div class="cp-avatar"> <i class='bx bx-user-circle' style='font-size:18px;'></i></div>
            <div style='min-width:0;'>
              <div class='cp-name'>${escapeHtml(cp.canonical_name)}</div>
              <div class='cp-txcount'>${(cp.tx_count ?? 0)} tx • ₹${toINR(cp.total_debit_paise ?? 0)}</div>
            </div>
          </div>
          <div style='text-align:right; color:var(--muted); font-weight:800;'>${(cp.tx_count ?? 0)} tx</div>
        `;
        div.addEventListener('click', ()=>{
          // hook: future filter
          alert('Filter by ' + cp.canonical_name);
        });
        cpList.appendChild(div);
      });
    } else {
      cpList.innerHTML = '<div class="empty-state">No counterparties yet.</div>';
    }

    // Chart
    const chartRoot = document.getElementById('chartRoot');
    const chartEmpty = document.getElementById('chartEmpty');
    if (data.chart && data.chart.dates && data.chart.dates.length > 0) {
      chartEmpty.style.display = 'none'; chartRoot.style.display = 'block';

      const danger = getComputedStyle(document.documentElement).getPropertyValue('--danger') || '#ff6b6b';
      const success = getComputedStyle(document.documentElement).getPropertyValue('--success') || '#6ee7b7';

      const options = {
        chart: { type: 'area', height: 220, toolbar: { show: false } },
        stroke: { curve: 'smooth', width: 2 },
        series: [ { name: 'Debit', data: data.chart.debit }, { name: 'Credit', data: data.chart.credit } ],
        xaxis: { categories: data.chart.dates, labels: { rotate: -45 }, type: 'category' },
        yaxis: { labels: { formatter: function(val){ return '₹' + Math.round(val); } } },
        tooltip: { y: { formatter: (val) => '₹' + Number(val).toFixed(2) } },
        colors: [ danger.trim(), success.trim() ],
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.45, opacityTo: 0.05 } },
        legend: { position: 'top' }
      };

      if (window._dashChart) try{ window._dashChart.destroy(); } catch(e){}
      window._dashChart = new ApexCharts(chartRoot, options);
      window._dashChart.render();
    } else {
      chartRoot.style.display = 'none'; chartEmpty.style.display = 'block';
    }

  }catch(err){
    console.error(err);
    document.getElementById('noDataMsg').style.display = 'block';
    document.getElementById('noDataMsg').textContent = 'Unable to load dashboard. Please try again.';
  }
}

function escapeHtml(s){ if(!s) return ''; return s.replace(/[&<>\"']/g, (m)=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }

// IST clock
function updateISTClock(){
  const now = new Date();
  const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
  const istOffset = 5.5 * 60 * 60 * 1000;
  const ist = new Date(utc + istOffset);
  const dateStr = ist.toLocaleDateString('en-IN', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
  const timeStr = ist.toLocaleTimeString('en-IN', { hour12: false });
  document.getElementById('todayDate').textContent = dateStr;
  document.getElementById('istTime').textContent = timeStr + ' IST';
}
setInterval(updateISTClock, 1000); updateISTClock();

// init
loadDashboard();

</script>