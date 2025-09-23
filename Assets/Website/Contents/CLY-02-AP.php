<style>
.dash-mod {
  display: grid;
  grid-template-columns: 1fr 360px;
  gap: 32px;
  align-items: start;
  margin-top: 22px;
  padding: 0 32px; /* Add space from page borders */
}
@media (max-width: 980px) { .dash-mod { grid-template-columns: 1fr; padding: 0 12px; } }

.welcomer-card {
  background: var(--card-bg);
  border-radius: var(--radius-lg);
  padding: 18px;
  box-shadow: var(--shadow-deep);
  display: flex;
  gap: 18px;
  align-items: flex-start;
  margin-bottom: 18px;
}

.welcome-avatar {
  width: 98px; height: 98px; border-radius: 12px; overflow: hidden;
  display: grid; place-items: center;
  border: 1px solid rgba(255,255,255,0.03);
  background: linear-gradient(135deg, rgba(255,255,255,0.02), rgba(0,0,0,0.02));
  transition: transform var(--transition-fast) ease, box-shadow var(--transition-fast) ease;
  box-shadow: 0 8px 28px rgba(0,0,0,0.45);
}
.welcome-avatar img { width: 100%; height: 100%; object-fit: cover; display: block; }
.welcome-avatar:hover { transform: translateY(-6px) rotate(-1deg) scale(1.02); box-shadow: 0 30px 80px rgba(0,0,0,0.65); }

.welcome-meta { flex: 1; display: flex; flex-direction: column; gap: 6px; }
.welcome-title {
  font-weight: 900; font-size: 18px; color: var(--text); display: flex; gap: 10px; align-items: center;
}
.welcome-back {
  color: #6C63FF; /* Indigo */
  font-weight: 900;
}
.welcome-name {
  color: #FF9800; /* Orange */
  font-weight: 900;
}
.welcome-sub { color: var(--muted); font-size: 13px; }

.role-pill { display: inline-flex; align-items: center; gap: 8px; padding: 6px 10px; border-radius: 999px; background: rgba(255,255,255,0.02); color: var(--muted); font-weight: 700; font-size: 13px; border: 1px solid rgba(255,255,255,0.03); }

.kpis-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
  margin-top: 12px;
}
.kpi-pill {
  background: linear-gradient(180deg, rgba(255,255,255,0.01), rgba(0,0,0,0.02));
  border-radius: 12px;
  padding: 12px;
  display: flex; flex-direction: row; gap: 10px; align-items: center;
  border: 1px solid rgba(255,255,255,0.03);
  transition: transform var(--transition-fast) ease, box-shadow var(--transition-fast) ease, border-color var(--transition-fast) ease;
}
.kpi-pill:hover { transform: translateY(-6px); box-shadow: 0 28px 60px rgba(0,0,0,0.6); border-color: rgba(255,169,77,0.12); }
.kpi-icon { font-size: 22px; display: flex; align-items: center; justify-content: center; width: 28px; }
.kpi-value { font-weight: 900; font-size: 16px; }
.kpi-label { font-size: 13px; font-weight: 700; }

.kpi-statements .kpi-value, .kpi-statements .kpi-label { color: #2196F3; } /* Blue */
.kpi-transactions .kpi-value, .kpi-transactions .kpi-label { color: #4CAF50; } /* Green */
.kpi-counterparties .kpi-value, .kpi-counterparties .kpi-label { color: #9C27B0; } /* Purple */
.kpi-debit .kpi-value, .kpi-debit .kpi-label { color: #F44336; } /* Red */
.kpi-credit .kpi-value, .kpi-credit .kpi-label { color: #FF9800; } /* Orange */
.kpi-balance .kpi-value, .kpi-balance .kpi-label { color: #009688; } /* Teal */

.quick-chart-card {
  background: var(--card-bg);
  border-radius: var(--radius-md);
  padding: 12px;
  box-shadow: var(--shadow-deep);
  border: 1px solid rgba(255,255,255,0.03);
  display: flex; flex-direction: column; gap: 8px;
  min-height: 220px;
  margin-bottom: 18px;
}

.empty-state { color: var(--muted); padding: 18px; text-align: center; border-radius: 10px; background: rgba(255,255,255,0.01); }
.kpi-loading { height: 18px; width: 120px; background: linear-gradient(90deg, rgba(255,255,255,0.02), rgba(255,255,255,0.04)); border-radius: 8px; }
</style>

<!-- Boxicons CDN for icons -->
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

<div class="dash-mod">
  <div>
    <div class="welcomer-card" id="welcomerCard">
      <div class="welcome-avatar" id="welcomeAvatar">
        <img src="/Assets/Website/Images/default-avatar.png" alt="avatar" id="welcomeAvatarImg">
      </div>

      <div class="welcome-meta">
        <div style="display:flex; align-items:center; justify-content:space-between; gap:12px;">
          <div>
            <div class="welcome-title" id="welcomeTitle">
              <span class="welcome-back">Welcome Back,</span>
              <span class="welcome-name" id="welcomeName">User</span>
              <span style="font-size:18px;">👋</span>
            </div>
            <div class="welcome-sub" id="welcomeRole">Role — Member</div>
          </div>

          <div style="text-align:right;">
            <div class="role-pill" id="todayDate">Loading date</div>
            <div style="height:6px;"></div>
            <div class="role-pill" id="istTime">--:--:-- IST</div>
          </div>
        </div>

        <div class="kpis-grid" id="kpisGrid">
          <div class="kpi-pill kpi-statements">
            <span class="kpi-icon"><i class='bx bx-file'></i></span>
            <div>
              <div class="kpi-value">—</div>
              <div class="kpi-label">Statements</div>
            </div>
          </div>
          <div class="kpi-pill kpi-transactions">
            <span class="kpi-icon"><i class='bx bx-transfer'></i></span>
            <div>
              <div class="kpi-value">—</div>
              <div class="kpi-label">Transactions</div>
            </div>
          </div>
          <div class="kpi-pill kpi-counterparties">
            <span class="kpi-icon"><i class='bx bx-group'></i></span>
            <div>
              <div class="kpi-value">—</div>
              <div class="kpi-label">Counterparties</div>
            </div>
          </div>
          <div class="kpi-pill kpi-debit">
            <span class="kpi-icon"><i class='bx bx-arrow-to-bottom'></i></span>
            <div>
              <div class="kpi-value">—</div>
              <div class="kpi-label">Total Debit (₹)</div>
            </div>
          </div>
          <div class="kpi-pill kpi-credit">
            <span class="kpi-icon"><i class='bx bx-arrow-to-top'></i></span>
            <div>
              <div class="kpi-value">—</div>
              <div class="kpi-label">Total Credit (₹)</div>
            </div>
          </div>
          <div class="kpi-pill kpi-balance">
            <span class="kpi-icon"><i class='bx bx-wallet'></i></span>
            <div>
              <div class="kpi-value">—</div>
              <div class="kpi-label">Latest Balance (₹)</div>
            </div>
          </div>
        </div>

        <div id="noDataMsg" class="empty-state" style="display:none; margin-top:12px;">
          Please upload any document to prepare KPIs and chart.
        </div>
      </div>
    </div>

    <div style="margin-top:12px;">
      <div style="color:var(--muted); font-weight:800; margin-bottom:8px;">Top counterparties</div>
      <div id="topCpList" style="display:grid; gap:8px;"></div>
    </div>
  </div>

  <div class="quick-chart-card">
    <div style="display:flex; justify-content:space-between; align-items:center;">
      <div style="font-weight:900; color:var(--text)">Last 30 days</div>
      <div style="color:var(--muted); font-size:13px;">Quick view</div>
    </div>

    <div id="chartRoot" style="height:220px; margin-top:6px;"></div>

    <div id="chartEmpty" class="empty-state" style="display:none;">No transactions to chart yet.</div>
  </div>
</div>

<script>
const API_URL = '/Assets/Website/Api/dashboard_kpis.php';

function toINR(n) {
  if (n === null || n === undefined) return '--';
  return Number(n).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function updateISTClock() {
  const now = new Date();
  const utc = now.getTime() + (now.getTimezoneOffset() * 60000);
  const istOffset = 5.5 * 60 * 60 * 1000;
  const ist = new Date(utc + istOffset);
  const dateStr = ist.toLocaleDateString('en-IN', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
  const timeStr = ist.toLocaleTimeString('en-IN', { hour12: false });
  document.getElementById('todayDate').textContent = dateStr;
  document.getElementById('istTime').textContent = timeStr + ' IST';
}
setInterval(updateISTClock, 1000);
updateISTClock();

async function loadDashboard() {
  try {
    const res = await fetch(API_URL, { credentials: 'include' });
    if (!res.ok) throw new Error('Network');
    const data = await res.json();
    if (data.error) throw new Error(data.error);

    const user = data.user || {};
    document.getElementById('welcomeName').textContent = user.name || 'User';
    document.getElementById('welcomeRole').textContent = (user.role || 'Member');
    const avatarEl = document.getElementById('welcomeAvatarImg');
    if (user.avatar) avatarEl.src = user.avatar;
    else avatarEl.src = '/Assets/Website/Images/default-avatar.png';

    // KPIs
    const k = data.kpis || {};
    const pills = document.querySelectorAll('#kpisGrid .kpi-pill');
    const values = [
      k.statements_count ?? 0,
      k.transactions_count ?? 0,
      k.counterparties_count ?? 0,
      toINR(k.total_debit ?? 0),
      toINR(k.total_credit ?? 0),
      (k.latest_balance !== null && k.latest_balance !== undefined) ? toINR(k.latest_balance) : '--'
    ];

    pills.forEach((p, idx) => {
      const v = p.querySelector('.kpi-value');
      v.textContent = values[idx];
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
        div.className = 'kpi-pill kpi-counterparties';
        div.style.display = 'flex';
        div.style.justifyContent = 'space-between';
        div.innerHTML = `<span class="kpi-icon"><i class='bx bx-group'></i></span>
                         <div style="font-weight:800">${escapeHtml(cp.canonical_name)}</div>
                         <div style="text-align:right; color:var(--muted)">${(cp.tx_count ?? 0)} tx</div>`;
        cpList.appendChild(div);
      });
    } else {
      cpList.innerHTML = '<div class="empty-state">No counterparties yet.</div>';
    }

    // Chart: if we have dates
    const chartRoot = document.getElementById('chartRoot');
    const chartEmpty = document.getElementById('chartEmpty');
    if (data.chart && data.chart.dates && data.chart.dates.length > 0) {
      chartEmpty.style.display = 'none';
      chartRoot.style.display = 'block';

      const options = {
        chart: {
          type: 'area',
          height: 220,
          toolbar: { show: false },
          animations: { enabled: true }
        },
        stroke: { curve: 'smooth', width: 2 },
        series: [
          { name: 'Debit', data: data.chart.debit },
          { name: 'Credit', data: data.chart.credit }
        ],
        xaxis: {
          categories: data.chart.dates,
          labels: { rotate: -45, hideOverlappingLabels: true, datetimeUTC: false },
          type: 'category'
        },
        yaxis: { labels: { formatter: function(val){ return '₹' + val.toFixed(0); } } },
        tooltip: { y: { formatter: (val) => '₹' + Number(val).toFixed(2) } },
        colors: [ getComputedStyle(document.documentElement).getPropertyValue('--danger') || '#ff6b6b', getComputedStyle(document.documentElement).getPropertyValue('--success') || '#6ee7b7' ],
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.45, opacityTo: 0.05 } },
        legend: { position: 'top' }
      };

      if (window._dashChart) {
        try { window._dashChart.destroy(); } catch(e){}
      }
      window._dashChart = new ApexCharts(chartRoot, options);
      window._dashChart.render();

    } else {
      chartRoot.style.display = 'none';
      chartEmpty.style.display = 'block';
    }

  } catch (err) {
    console.error(err);
    document.getElementById('noDataMsg').style.display = 'block';
    document.getElementById('noDataMsg').textContent = 'Unable to load dashboard. Please try again.';
  }
}

function escapeHtml(s){
  if(!s) return '';
  return s.replace(/[&<>"']/g, (m) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

loadDashboard();
</script>
