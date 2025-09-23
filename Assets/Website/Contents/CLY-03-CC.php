
<style>

.management-cards {
  margin-top: 20px;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 16px;
  padding: 12px 0 40px 0;
}

.mg-card {
  --bg: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(0,0,0,0.02));
  background: var(--bg);
  border-radius: 12px;
  padding: 14px;
  display: flex;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  cursor: pointer;
  transition: transform .28s cubic-bezier(.2,.9,.3,1), box-shadow .28s ease, filter .18s ease;
  will-change: transform, box-shadow;
  box-shadow: 0 12px 30px rgba(0,0,0,0.95); 
  transform-style: preserve-3d;
  border: 1px solid rgba(255,255,255,0.03);
  text-decoration: none;
  color: inherit;
}


.mg-card:hover {
  transform: translateY(-10px) rotateX(2deg) scale(1.01);
  box-shadow: 0 34px 80px rgba(0,0,0,0.98);
}


.mg-card.clicked {
  transform: translateY(-2px) scale(.995);
  box-shadow: 0 8px 18px rgba(0,0,0,0.95) inset;
  transition: transform .12s ease, box-shadow .12s ease;
}

.mg-left { display:flex; gap:12px; align-items:flex-start; min-width:0; }
.mg-icon {
  width:56px; height:56px; border-radius:10px; display:grid; place-items:center;
  background: linear-gradient(135deg, rgba(255,255,255,0.02), rgba(0,0,0,0.02));
  border: 1px solid rgba(255,255,255,0.03);
  font-size:22px; color:var(--muted);
  flex:0 0 56px;
}


.mg-meta { display:flex; flex-direction:column; gap:6px; min-width:0; }
.mg-title { font-weight:900; font-size:15px; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.mg-desc { font-size:13px; color:var(--muted); line-height:1.3; max-height:64px; overflow:hidden; text-overflow:ellipsis; }


.mg-right { display:flex; align-items:center; gap:10px; flex:0 0 auto; }
.help-btn {
  width:36px; height:36px; border-radius:8px; display:grid; place-items:center; cursor:pointer;
  border:1px solid rgba(255,255,255,0.06); background:transparent; color:var(--muted);
}
.help-btn:hover { background: rgba(255,255,255,0.01); transform: translateY(-2px); }


.mg-modal-backdrop { position:fixed; inset:0; background:rgba(0,0,0,0.6); display:none; align-items:center; justify-content:center; z-index:9999; }
.mg-modal { width:min(720px, 95%); background:var(--card-bg); border-radius:12px; padding:18px; border:1px solid rgba(255,255,255,0.04); box-shadow:0 40px 120px rgba(0,0,0,0.95); color:var(--text); }
.mg-modal h3 { margin:0 0 8px 0; font-size:18px; }
.mg-modal p { margin:0 0 12px 0; color:var(--muted); }
.mg-modal .close { float:right; cursor:pointer; background:transparent; border:0; font-weight:900; font-size:18px; color:var(--muted); }


.mg-card:focus { outline: 3px solid rgba(255,169,77,0.08); outline-offset: 4px; }


@media (max-width:520px) {
  .mg-desc { max-height:88px; }
  .mg-icon{ width:48px; height:48px; }
}
</style>

<div class="management-cards" id="managementCards" aria-live="polite" role="list">
</div>

<div class="mg-modal-backdrop" id="mgModal" role="dialog" aria-modal="true" aria-hidden="true">
  <div class="mg-modal" role="document">
    <button class="close" id="mgModalClose" aria-label="Close">✕</button>
    <h3 id="mgModalTitle">Help</h3>
    <p id="mgModalBody">Instructions appear here.</p>
  </div>
</div>

<script>

(function(){
  const MAX_DESC = 200;

  const cards = [
    {
      key: 'upload',
      title: 'Statement Upload',
      icon: 'bx-upload',
      desc: 'Upload your HDFC PDF statements here. The parser extracts dates, narrations, reference numbers, debit/credit amounts and closing balance. Files are stored immutably with provenance for safety.',
      href: '/app/upload.php'
    },
    {
      key: 'statements',
      title: 'Statement Manager',
      icon: 'bx-file',
      desc: 'Browse, search and re-parse uploaded statements. View original OCR lines, correct misreads, and manage statement provenance. Re-parse when you fix a PDF read issue to refresh parsed rows.',
      href: '/app/statements.php'
    },
    {
      key: 'counterparties',
      title: 'Counterparty Manager',
      icon: 'bx-group',
      desc: 'Review and merge automatically detected counterparties. Add aliases, promote singleton transactions to named groups, and enrich merchants with GSTIN or website metadata for better matching.',
      href: '/app/counterparties.php'
    },
    {
      key: 'debits',
      title: 'Debit Management',
      icon: 'bx-arrow-to-bottom',
      desc: 'View, tag and export all debit transactions. Flag recurring debits, create rules to auto-classify debits, and generate reports for recurring payments or suspicious withdrawals.',
      href: '/app/debits.php'
    },
    {
      key: 'credits',
      title: 'Credit Management',
      icon: 'bx-arrow-to-top',
      desc: 'Manage incoming credits: group salary, refunds, and UPI receipts. Tag credits, build automation rules, and export credit histories for tax or accounting purposes.',
      href: '/app/credits.php'
    },
    {
      key: 'balance',
      title: 'Balance Management',
      icon: 'bx-wallet',
      desc: 'Track daily balances, view statement-level closing balances, reconcile multiple accounts and generate snapshots to monitor liquidity and trends over time.',
      href: '/app/balances.php'
    },
    {
      key: 'reports',
      title: 'Reports & Exports',
      icon: 'bx-file-blank',
      desc: 'Generate PDF/CSV reports for selected periods, counterparties or transaction types. Schedule exports and download reconciled reports for accounting or legal purposes.',
      href: '/app/reports.php'
    },
    {
      key: 'rules',
      title: 'Rules & Automation',
      icon: 'bx-collection',
      desc: 'Create and manage grouping rules, pattern matching, and automated merges. Promote patterns to aliases so future uploads automatically classify transactions correctly.',
      href: '/app/rules.php'
    }
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

    a.innerHTML = `
      <div class="mg-left">
        <div class="mg-icon"><i class="bx ${card.icon}"></i></div>
        <div class="mg-meta">
          <div class="mg-title">${escapeHtml(card.title)}</div>
          <div class="mg-desc" title="${escapeHtml(card.desc)}">${escapeHtml(fitDesc(card.desc))}</div>
        </div>
      </div>
      <div class="mg-right">
        <button class="help-btn" type="button" aria-label="Help for ${escapeHtml(card.title)}" data-help-key="${card.key}">
          <i class="bx bx-question-mark"></i>
        </button>
      </div>
    `;

    a.addEventListener('click', function(evt){
      if(evt.target.closest('.help-btn')) return;
      evt.preventDefault();
      a.classList.add('clicked');
      setTimeout(()=> {
        window.location.href = a.href;
      }, 150);
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

  const modal = document.getElementById('mgModal');
  const modalTitle = document.getElementById('mgModalTitle');
  const modalBody = document.getElementById('mgModalBody');
  const modalClose = document.getElementById('mgModalClose');

  const helpMap = {
    'upload': {
      title: 'Statement Upload — How it works',
      body: 'Drop or select HDFC PDF statements. Parser concatenates multi-line narrations, detects UPI IDs and masked accounts, and stores an immutable statement record with a checksum for deduplication.'
    },
    'statements': {
      title: 'Statement Manager — Tips',
      body: 'Open a statement to view raw OCR lines, correct any narrator issues, and re-run parsing for that file. Use the provenance panel to trace when each statement was uploaded.'
    },
    'counterparties': {
      title: 'Counterparty Manager — Usage',
      body: 'Merge similar aliases, promote singleton transactions to named counterparties, add manual aliases (phone/UPI/account mask) and see aggregated debit/credit totals per counterparty.'
    },
    'debits': {
      title: 'Debit Management — What to do',
      body: 'Filter all debit transactions, tag recurring payments, create exports for accounting, and add automation rules to classify similar debits automatically.'
    },
    'credits': {
      title: 'Credit Management — What to do',
      body: 'Group credits by source, tag salary entries, issue refunds list, and export grouped credit history for tax time or bookkeeping.'
    },
    'balance': {
      title: 'Balance Management — How it helps',
      body: 'Track closing balances across statements, reconcile cross-account balances, and generate daily snapshots for quick liquidity checks.'
    },
    'reports': {
      title: 'Reports & Exports',
      body: 'Create scheduled or one-off CSV/PDF exports filtered by date range, counterparty or transaction type. Exports include provenance info for audit trails.'
    },
    'rules': {
      title: 'Rules & Automation',
      body: 'Create regex or fuzzy rules to map narrations to counterparties. Promote successful rules and apply them retroactively to past statements.'
    }
  };

  container.addEventListener('click', function(e){
    const hb = e.target.closest('.help-btn');
    if(!hb) return;
    e.stopPropagation();
    const key = hb.dataset.helpKey;
    const info = helpMap[key] || { title: 'Help', body: 'No info available.' };
    modalTitle.textContent = info.title;
    modalBody.textContent = info.body;
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden','false');
    modal.querySelector('.mg-modal').focus();
  });

  modalClose.addEventListener('click', closeModal);
  modal.addEventListener('click', function(e){ if(e.target === modal) closeModal(); });
  document.addEventListener('keydown', function(e){ if(e.key === 'Escape') closeModal(); });

  function closeModal(){
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden','true');
  }

  function escapeHtml(s){
    if(!s) return '';
    return String(s).replace(/[&<>"']/g, (m)=> ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]) );
  }
})();
</script>
