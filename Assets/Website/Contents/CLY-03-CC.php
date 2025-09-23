<?php
// Management cards module — updated per user request
// - Solid muted card backgrounds (no transparency)
// - Section heading + short description above the cards
// - 4 cards per row on desktop (>=1200px)
// - Removed question-mark help buttons from inside cards
// - Colored icons and title text per card
// - Spacing from page borders and top
?>

<style>
/* Wrapper to keep section away from page edges and centered */
.management-wrapper {
  max-width: 1800px;
  margin: 52px auto 8px auto; /* space from top and center */
  padding: 0 28px; /* keep away from edges */
}
.management-heading {
  color: var(--text);
  font-weight:900;
  font-size:20px;
  margin-bottom:6px;
}
.management-sub { color:var(--muted); margin-bottom:14px; font-size:13px; }

.management-cards {
  margin-top: 8px;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
  gap: 16px;
  padding: 12px 0 40px 0;
}

/* force 4 columns on wide desktops */
@media (min-width: 1200px) {
  .management-cards { grid-template-columns: repeat(4, 1fr); }
}

.mg-card {
  background: rgba(255,255,255,0.03); /* solid muted background */
  border-radius: 12px;
  padding: 16px;
  display: flex;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  cursor: pointer;
  transition: transform .28s cubic-bezier(.2,.9,.3,1), box-shadow .28s ease, filter .18s ease;
  will-change: transform, box-shadow;
  box-shadow: 0 12px 30px rgba(0,0,0,0.95);
  transform-style: preserve-3d;
  border: 1px solid rgba(0,0,0,0.18);
  text-decoration: none;
  color: inherit;
}

/* subtle 3D hover */
.mg-card:hover {
  transform: translateY(-12px) rotateX(2deg) scale(1.01);
  box-shadow: 0 48px 110px rgba(0,0,0,0.95);
}

/* active pressed state */
.mg-card.clicked {
  transform: translateY(-2px) scale(.995);
  box-shadow: 0 8px 18px rgba(0,0,0,0.95) inset;
  transition: transform .12s ease, box-shadow .12s ease;
}

.mg-left { display:flex; gap:12px; align-items:flex-start; min-width:0; }
.mg-icon {
  width:56px; height:56px; border-radius:10px; display:grid; place-items:center;
  background: rgba(255,255,255,0.02);
  border: 1px solid rgba(255,255,255,0.03);
  font-size:22px; color:var(--muted);
  flex:0 0 56px;
}

.mg-meta { display:flex; flex-direction:column; gap:6px; min-width:0; }
.mg-title { font-weight:900; font-size:15px; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.mg-desc { font-size:13px; color:var(--muted); line-height:1.3; max-height:64px; overflow:hidden; text-overflow:ellipsis; }

/* Right area: small chevron for affordance */
.mg-right { display:flex; align-items:center; gap:10px; flex:0 0 auto; }
.mg-chevron { color:var(--muted); font-size:18px; }

/* keyboard focus */
.mg-card:focus { outline: 3px solid rgba(255,169,77,0.08); outline-offset: 4px; }

@media (max-width:520px) {
  .mg-desc { max-height:88px; }
  .mg-icon{ width:48px; height:48px; }
}
</style>

<!-- Management cards section: heading + cards -->
<div class="management-wrapper">
  <div class="management-heading">Management</div>
  <div class="management-sub">Quick access to core management tools. Click a card to open the corresponding manager. Descriptions are fixed to 200 chars for consistency.</div>

  <div class="management-cards" id="managementCards" aria-live="polite" role="list">
    <!-- cards injected by JS -->
  </div>
</div>

<script>
(function(){
  const MAX_DESC = 200;
  const cards = [
    { key:'upload', title:'Statement Upload', icon:'bx-upload', color:'#ff9800', desc:'Upload your HDFC PDF statements here. The parser extracts dates, narrations, reference numbers, debit/credit amounts and closing balance. Files are stored immutably with provenance for safety.', href:'/upload.php' },
    { key:'statements', title:'Statement Manager', icon:'bx-file', color:'#2196f3', desc:'Browse, search and re-parse uploaded statements. View original OCR lines, correct misreads, and manage statement provenance. Re-parse when you fix a PDF read issue to refresh parsed rows.', href:'/app/statements.php' },
    { key:'counterparties', title:'Counterparty Manager', icon:'bx-group', color:'#9c27b0', desc:'Review and merge automatically detected counterparties. Add aliases, promote singleton transactions to named groups, and enrich merchants with GSTIN or website metadata for better matching.', href:'/app/counterparties.php' },
    { key:'debits', title:'Debit Management', icon:'bx-arrow-to-bottom', color:'#f44336', desc:'View, tag and export all debit transactions. Flag recurring debits, create rules to auto-classify debits, and generate reports for recurring payments or suspicious withdrawals.', href:'/app/debits.php' },
    { key:'credits', title:'Credit Management', icon:'bx-arrow-to-top', color:'#4caf50', desc:'Manage incoming credits: group salary, refunds, and UPI receipts. Tag credits, build automation rules, and export credit histories for tax or accounting purposes.', href:'/app/credits.php' },
    { key:'balance', title:'Balance Management', icon:'bx-wallet', color:'#009688', desc:'Track daily balances, view statement-level closing balances, reconcile multiple accounts and generate snapshots to monitor liquidity and trends over time.', href:'/app/balances.php' },
    { key:'reports', title:'Reports & Exports', icon:'bx-file-blank', color:'#3f51b5', desc:'Generate PDF/CSV reports for selected periods, counterparties or transaction types. Schedule exports and download reconciled reports for accounting or legal purposes.', href:'/app/reports.php' },
    { key:'rules', title:'Rules & Automation', icon:'bx-collection', color:'#ff5722', desc:'Create and manage grouping rules, pattern matching, and automated merges. Promote patterns to aliases so future uploads automatically classify transactions correctly.', href:'/app/rules.php' }
  ];

  function fitDesc(s){
    if(!s) return '';
    if(s.length <= MAX_DESC) return s;
    return s.slice(0, MAX_DESC - 1).trim() + '…';
  }

  const container = document.getElementById('managementCards');

  cards.forEach(card => {
    const a = document.createElement('a');
    a.className = 'mg-card';
    a.setAttribute('role','listitem');
    a.setAttribute('tabindex','0');
    a.href = card.href || '#';
    a.dataset.key = card.key;

    // set colored icon & title
    const safeTitle = escapeHtml(card.title);
    const safeDesc = escapeHtml(fitDesc(card.desc));
    const iconColor = card.color || '#ffa94d';

    a.innerHTML = `
      <div class="mg-left">
        <div class="mg-icon" style="color:${iconColor}; border-color: ${hexToRgba(iconColor,0.14)}; background: ${hexToRgba(iconColor,0.04)};">
          <i class="bx ${card.icon}"></i>
        </div>
        <div class="mg-meta">
          <div class="mg-title" style="color:${iconColor};">${safeTitle}</div>
          <div class="mg-desc" title="${escapeHtml(card.desc)}">${safeDesc}</div>
        </div>
      </div>
      <div class="mg-right"><i class="bx bx-chevron-right mg-chevron" aria-hidden="true"></i></div>
    `;

    a.addEventListener('click', function(evt){
      evt.preventDefault();
      a.classList.add('clicked');
      setTimeout(()=> { window.location.href = a.href; }, 150);
    });

    a.addEventListener('keydown', function(e){
      if(e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        a.classList.add('clicked');
        setTimeout(()=> window.location.href = a.href, 150);
      }
    });

    container.appendChild(a);
  });

  // helpers
  function escapeHtml(s){ if(!s) return ''; return String(s).replace(/[&<>"']/g, (m)=> ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]) ); }
  function hexToRgba(hex, a){
    hex = hex.replace('#','');
    const bigint = parseInt(hex, 16);
    const r = (bigint >> 16) & 255;
    const g = (bigint >> 8) & 255;
    const b = bigint & 255;
    return `rgba(${r}, ${g}, ${b}, ${a})`;
  }

})();
</script>
