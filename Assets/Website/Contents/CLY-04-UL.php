<?php
// upload_ui.php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo "<!doctype html><html><body><h2>Not authenticated</h2><p>Please login to use the uploader.</p></body></html>";
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Upload Bank Statements — CounterLy</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<style>
:root{
  --bg:#0b0b0b; --card:#0f1113; --text:#e7e7e7; --muted:rgba(231,231,231,0.55);
  --accent:#ffa94d; --accent-2:#7c3aed; --glass:rgba(255,255,255,0.02);
  --radius:12px; --gap:16px;
}
*{box-sizing:border-box}
body{margin:0;font-family:Inter,system-ui,Arial;background:linear-gradient(180deg,#0b0b0b,#0f1113);color:var(--text);padding:18px}
.container{max-width:1200px;margin:0 auto}
.header{display:flex;gap:12px;align-items:center;margin-bottom:18px}
.logo{width:48px;height:48px;border-radius:10px;background:linear-gradient(135deg,var(--accent),var(--accent-2));display:flex;align-items:center;justify-content:center;font-weight:900;color:#0b0b0b}
.title{font-size:18px;font-weight:800}
.grid{display:grid;grid-template-columns:1fr 420px;gap:20px}
@media(max-width:980px){ .grid{grid-template-columns:1fr} }
.card{background:var(--card);padding:18px;border-radius:var(--radius);border:1px solid rgba(255,255,255,0.02);box-shadow:0 10px 30px rgba(0,0,0,0.6)}
.section-title{font-weight:800;margin-bottom:8px}
.small{font-size:13px;color:var(--muted)}
.controls{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.dropzone{border:2px dashed rgba(255,255,255,0.04);padding:22px;border-radius:10px;display:flex;flex-direction:column;gap:10px;align-items:center;cursor:pointer;background:var(--glass)}
.dropzone.dragover{border-color:rgba(255,169,77,0.8);background:rgba(255,169,77,0.02)}
.file-list{margin-top:12px;display:flex;flex-direction:column;gap:10px}
.file-row{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:10px;border-radius:10px;background:rgba(255,255,255,0.01)}
.file-progress{width:220px;height:10px;background:rgba(255,255,255,0.02);border-radius:6px;overflow:hidden}
.file-progress > i{display:block;height:100%;width:0%;background:linear-gradient(90deg,var(--accent),var(--accent-2))}
.file-status{font-size:13px;color:var(--muted);min-width:120px;text-align:right}
.select, input, button, textarea{font-family:inherit}
.select, input[type="text"], textarea{background:transparent;border:1px solid rgba(255,255,255,0.05);padding:8px;border-radius:8px;color:var(--text)}
select{padding:8px;border-radius:8px;background:transparent;border:1px solid rgba(255,255,255,0.04);color:var(--text)}
.btn{padding:10px 14px;border-radius:10px;cursor:pointer;border:0;font-weight:800}
.btn-primary{background:linear-gradient(90deg, rgba(255,169,77,0.12), rgba(124,58,237,0.08));border:1px solid rgba(255,169,77,0.06)}
.btn-ghost{background:transparent;border:1px solid rgba(255,255,255,0.04);color:var(--muted)}
.row{display:flex;gap:12px;align-items:center}
.footer-note{font-size:12px;color:var(--muted);margin-top:12px}
.modal { position: fixed; inset: 0; display: none; align-items:center; justify-content:center; z-index:1200; }
.modal.show { display:flex; }
.modal .panel { width: 420px; max-width:92%; background:var(--card); padding:16px; border-radius:12px; border:1px solid rgba(255,255,255,0.03); }
.form-row{display:flex;flex-direction:column;gap:6px;margin-bottom:10px}
.kv{font-size:13px;color:var(--muted)}
.counterparty-list{margin-top:12px;max-height:280px;overflow:auto}
.counterparty-item{padding:10px;border-radius:8px;border:1px solid rgba(255,255,255,0.02);margin-bottom:8px;background:rgba(255,255,255,0.01)}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div class="logo">CL</div>
    <div>
      <div class="title">CounterLy — Upload Statements</div>
      <div class="small">Upload HDFC structured statements. Groups auto-created when counterparty &ge; 2 txns.</div>
    </div>
    <div style="flex:1"></div>
    <div class="small">Signed in</div>
  </div>

  <div class="grid">
    <!-- left: uploader -->
    <div class="card">
      <div class="section-title">Upload PDF Statements</div>
      <div class="small">Select account (or create one) and upload PDF files. Parsing runs server-side.</div>

      <div style="margin-top:12px" class="row">
        <select id="accountSelect">
          <option value="">Choose account — loading...</option>
        </select>
        <button id="addAccountBtn" class="btn btn-ghost">+ Add account</button>
      </div>

      <div style="margin-top:12px">
        <div id="dropzone" class="dropzone" tabindex="0">
          <i class='bx bx-file' style="font-size:36px;color:var(--muted)"></i>
          <div id="dropText">Drag & drop PDF files here or click to browse</div>
          <div class="small">Multiple files allowed — Max 20 MB each.</div>
        </div>
      </div>

      <div class="controls" style="margin-top:12px">
        <label class="small"><input type="checkbox" id="compressPdf"> Compress on server</label>
        <div style="flex:1"></div>
        <button id="startUpload" class="btn btn-primary">Start upload</button>
        <button id="clearFiles" class="btn btn-ghost">Clear</button>
      </div>

      <div class="file-list" id="fileList"></div>
      <div class="footer-note" id="stepsLog">No uploads yet.</div>
    </div>

    <!-- right: groups & actions -->
    <div class="card">
      <div class="section-title">Counterparties & Actions</div>
      <div class="small">Auto-detected groups (counterparties with &ge; 2 transactions). Use promote to create a group from a singleton.</div>

      <div style="margin-top:12px" class="row">
        <button id="refreshGroups" class="btn btn-primary">Refresh</button>
        <div style="flex:1"></div>
        <button id="viewStatements" class="btn btn-ghost">Statements</button>
      </div>

      <div id="counterpartyList" class="counterparty-list"></div>
      <div style="margin-top:12px" class="small">Tip: Promote a transaction from Statements page to create a custom group.</div>
    </div>
  </div>
</div>

<!-- Add account modal -->
<div id="modal" class="modal" aria-hidden="true">
  <div class="panel" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
      <strong id="modalTitle">Add bank account</strong>
      <button id="closeModal" class="btn btn-ghost">Close</button>
    </div>
    <div class="form-row">
      <label class="kv">Bank name</label>
      <input id="bankName" type="text" placeholder="HDFC Bank" />
    </div>
    <div class="form-row">
      <label class="kv">Account masked</label>
      <input id="acctMask" type="text" placeholder="****4404 (optional)" />
    </div>
    <div class="form-row">
      <label class="kv">IFSC (optional)</label>
      <input id="ifsc" type="text" placeholder="HDFC0001234" />
    </div>
    <div style="display:flex;gap:8px;justify-content:flex-end">
      <button id="createAccount" class="btn btn-primary">Create</button>
    </div>
  </div>
</div>

<script>
const API = '/Assets/Website/Api/upload_api.php';
const CSRF = <?php echo json_encode($csrf); ?>;
const MAX_BYTES = 20 * 1024 * 1024;
let filesQueue = [];

const dropzone = document.getElementById('dropzone');
const fileListEl = document.getElementById('fileList');
const startBtn = document.getElementById('startUpload');
const clearBtn = document.getElementById('clearFiles');
const stepsLog = document.getElementById('stepsLog');
const compressCheckbox = document.getElementById('compressPdf');
const accountSelect = document.getElementById('accountSelect');
const addAccountBtn = document.getElementById('addAccountBtn');
const modal = document.getElementById('modal');
const closeModal = document.getElementById('closeModal');
const createAccount = document.getElementById('createAccount');
const bankName = document.getElementById('bankName');
const acctMask = document.getElementById('acctMask');
const ifsc = document.getElementById('ifsc');
const refreshGroups = document.getElementById('refreshGroups');
const counterpartyList = document.getElementById('counterpartyList');

function setSteps(msg){ stepsLog.textContent = msg; }
function escapeHtml(s){ if(!s) return ''; return String(s).replace(/[&<>"']/g, (m)=> ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]) ); }

function renderQueue(){
  fileListEl.innerHTML = '';
  if(filesQueue.length === 0){ fileListEl.style.display = 'none'; return; }
  fileListEl.style.display = 'flex';
  filesQueue.forEach((fObj, idx) =>{
    const row = document.createElement('div'); row.className='file-row';
    row.innerHTML = `
      <div style="display:flex; gap:12px; align-items:center; min-width:0;">
        <div style="min-width:0;">
          <div style="font-weight:800;">${escapeHtml(fObj.file.name)}</div>
          <div style="color:var(--muted); font-size:13px;">${(fObj.file.size/1024/1024).toFixed(2)} MB</div>
        </div>
      </div>
      <div style="display:flex; gap:12px; align-items:center;">
        <div class="file-progress" id="prog${idx}"><i></i></div>
        <div class="file-status" id="status${idx}">Queued</div>
      </div>
    `;
    fileListEl.appendChild(row);
  });
}

function handleFiles(list){
  for(const f of list){
    if(f.type !== 'application/pdf' && !f.name.toLowerCase().endsWith('.pdf')){ alert('Only PDF files allowed: ' + f.name); continue; }
    if(f.size > MAX_BYTES){ alert('File too large (max 20MB): ' + f.name); continue; }
    filesQueue.push({ file: f, state: 'queued' });
  }
  renderQueue();
}

['dragenter','dragover'].forEach(evt=> dropzone.addEventListener(evt, (e)=>{ e.preventDefault(); dropzone.classList.add('dragover'); }));
['dragleave','drop'].forEach(evt=> dropzone.addEventListener(evt, (e)=>{ e.preventDefault(); dropzone.classList.remove('dragover'); }));
dropzone.addEventListener('drop', (e)=>{ handleFiles(e.dataTransfer.files); });
dropzone.addEventListener('click', ()=>{ const ip = document.createElement('input'); ip.type='file'; ip.accept='.pdf,application/pdf'; ip.multiple=true; ip.onchange = ()=> handleFiles(ip.files); ip.click(); });
dropzone.addEventListener('keydown', (e)=>{ if(e.key === 'Enter' || e.key === ' ') { e.preventDefault(); dropzone.click(); } });

clearBtn.addEventListener('click', ()=>{ filesQueue = []; renderQueue(); setSteps('Cleared.'); });

startBtn.addEventListener('click', async ()=> {
  if (filesQueue.length === 0) return alert('No files selected');
  if (!confirm('Start upload and parse now?')) return;
  startBtn.disabled = true; clearBtn.disabled = true; setSteps('Starting uploads...');
  const accountId = accountSelect.value ? parseInt(accountSelect.value) : null;
  for (let i = 0; i < filesQueue.length; i++) {
    const item = filesQueue[i];
    document.getElementById('status'+i).textContent = 'Uploading...';
    await uploadFile(item.file, i, accountId);
  }
  setSteps('All uploads finished. Check Statements page.');
  startBtn.disabled = false; clearBtn.disabled = false;
});

function updateProgress(idx, pct, status){
  const prog = document.querySelector('#prog'+idx+' i'); if(prog) prog.style.width = pct + '%';
  const st = document.getElementById('status'+idx); if(st) st.textContent = status;
}

function uploadFile(file, idx, accountId){
  return new Promise((resolve) => {
    const xhr = new XMLHttpRequest();
    const fd = new FormData();
    fd.append('statement_pdf', file);
    fd.append('csrf_token', CSRF);
    if (accountId) fd.append('account_id', accountId);
    if (compressCheckbox.checked) fd.append('compress', '1');

    xhr.open('POST', API + '?action=upload', true);
    xhr.withCredentials = true;

    xhr.upload.onprogress = function(e){ if(e.lengthComputable){ const pct = Math.round((e.loaded / e.total) * 100); updateProgress(idx, pct, 'Uploading ' + pct + '%'); } };

    xhr.onload = function(){
      try {
        const res = JSON.parse(xhr.responseText);
        if (xhr.status >= 200 && xhr.status < 300 && res.success) {
          document.getElementById('status'+idx).textContent = 'Uploaded — Parsing';
          pollParseStatus(res.statement_id, idx).then(()=> resolve());
        } else {
          updateProgress(idx, 0, 'Error: ' + (res.error || xhr.status));
          resolve();
        }
      } catch (err) {
        updateProgress(idx, 0, 'Invalid server response');
        resolve();
      }
    };

    xhr.onerror = function(){ updateProgress(idx,0,'Network error'); resolve(); };
    xhr.send(fd);
  });
}

function pollParseStatus(statementId, idx){
  const statusEl = document.getElementById('status'+idx);
  statusEl.textContent = 'Queued for parsing...';
  return new Promise((resolve) => {
    const iv = setInterval(async () => {
      try {
        const resp = await fetch(API + '?action=status&sid=' + encodeURIComponent(statementId), { credentials: 'include' });
        if (resp.ok) {
          const j = await resp.json();
          if (j.success) {
            if (j.parse_status === 'parsed') { statusEl.textContent = 'Parsed ✓'; clearInterval(iv); resolve(); }
            else if (j.parse_status === 'error') { statusEl.textContent = 'Error parsing'; clearInterval(iv); resolve(); }
            else { statusEl.textContent = (j.parse_status || 'parsing') + '...'; }
          } else { statusEl.textContent = 'Error: ' + (j.error||'unknown'); clearInterval(iv); resolve(); }
        }
      } catch(e){
        // ignore transient
      }
    }, 2000);
    setTimeout(()=>{ clearInterval(iv); resolve(); }, 120000);
  });
}

// account management & groups
addAccountBtn.addEventListener('click', ()=> { modal.classList.add('show'); modal.setAttribute('aria-hidden','false'); bankName.focus(); });
closeModal.addEventListener('click', ()=> { modal.classList.remove('show'); modal.setAttribute('aria-hidden','true'); });

createAccount.addEventListener('click', async ()=> {
  const bank = bankName.value.trim();
  if (!bank) return alert('Enter bank name');
  createAccount.disabled = true;
  try {
    const resp = await fetch(API + '?action=add_account', {
      method: 'POST',
      credentials: 'include',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ bank_name: bank, account_number_masked: acctMask.value.trim(), ifsc: ifsc.value.trim() })
    });
    const j = await resp.json();
    if (j.success) {
      modal.classList.remove('show'); modal.setAttribute('aria-hidden','true');
      bankName.value=''; acctMask.value=''; ifsc.value='';
      await loadAccounts();
      alert('Account created');
    } else {
      alert('Failed: ' + (j.error||'unknown'));
    }
  } catch (e) { alert('Server error'); }
  createAccount.disabled = false;
});

async function loadAccounts(){
  accountSelect.innerHTML = '<option value="">Loading accounts...</option>';
  try {
    const resp = await fetch(API + '?action=list_accounts', { credentials: 'include' });
    const j = await resp.json();
    if (j.success) {
      const rows = j.accounts || [];
      accountSelect.innerHTML = '<option value="">-- Select account (optional) --</option>';
      rows.forEach(r => {
        const text = `${r.bank_name} ${r.account_number_masked ? ' · ' + r.account_number_masked : ''} ${r.ifsc ? ' · ' + r.ifsc : ''}`;
        const opt = document.createElement('option'); opt.value = r.id; opt.textContent = text;
        accountSelect.appendChild(opt);
      });
    } else {
      accountSelect.innerHTML = '<option value="">Load failed</option>';
    }
  } catch (e) {
    accountSelect.innerHTML = '<option value="">Load error</option>';
  }
}

refreshGroups.addEventListener('click', loadGroups);
async function loadGroups(){
  counterpartyList.innerHTML = 'Loading...';
  try {
    const resp = await fetch(API + '?action=get_groups', { credentials: 'include' });
    const j = await resp.json();
    if (j.success) {
      const rows = j.counterparties || [];
      if (rows.length === 0) { counterpartyList.innerHTML = '<div class="small">No groups yet</div>'; return; }
      counterpartyList.innerHTML = '';
      rows.forEach(cp => {
        const el = document.createElement('div'); el.className = 'counterparty-item';
        el.innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center;">
          <div><strong>${escapeHtml(cp.canonical_name)}</strong><div class="small">tx: ${cp.tx_count} · debit: ${(cp.total_debit_paise/100).toFixed(2)} · credit: ${(cp.total_credit_paise/100).toFixed(2)}</div></div>
          <div><button class="btn btn-ghost" data-id="${cp.id}">View</button></div>
        </div>`;
        counterpartyList.appendChild(el);
      });
    } else {
      counterpartyList.innerHTML = '<div class="small">Failed to load</div>';
    }
  } catch (e) {
    counterpartyList.innerHTML = '<div class="small">Server error</div>';
  }
}

// initial load
loadAccounts();
loadGroups();

</script>
</body>
</html>
