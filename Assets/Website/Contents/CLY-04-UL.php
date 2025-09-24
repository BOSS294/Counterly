<?php
// upload_ui.php - client UI (CSV-only variant). Uses the same backend upload_api.php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo "<!doctype html><html><body><h2>Not authenticated</h2><p>Please login to use the uploader.</p></body></html>";
    exit;
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];
?>
<head>


<!-- Page-specific styles only (no :root or body overrides) -->
<style>

.page-shell { min-height:100vh; display:flex; flex-direction:column; gap:18px; padding:24px; box-sizing:border-box; }

/* Large, wide layout */
/* give extra room for top nav; lower height slightly */
.uploader-viewport { display:flex; gap:20px; align-items:stretch; width:100%; height:calc(100vh - 140px); }

.left-panel { flex:1 1 70%; display:flex; flex-direction:column; gap:16px; min-width:0; }
.right-panel { width:420px; max-width:38%; display:flex; flex-direction:column; gap:12px; }

/* Card visual */
.card { background-color: rgba(255,255,255,0.02); border-radius:12px; padding:18px; box-shadow: 0 6px 20px rgba(2,6,23,0.6); border:1px solid rgba(255,255,255,0.03); height:100%; overflow:auto; }

/* Header */
.header { display:flex; align-items:center; gap:12px; }
.header h1 { margin:0; font-size:22px; letter-spacing:0.2px; }
.header .muted { color:rgba(255,255,255,0.6); font-size:13px; margin-left:auto; }

/* Dropzone */
.dropzone { border:2px dashed rgba(255,255,255,0.06); border-radius:12px; padding:28px; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:10px; cursor:pointer; transition: all 180ms ease; height:100%; text-align:center; }
.dropzone .icon { font-size:56px; opacity:0.95; transform:translateY(0); transition: transform 220ms ease; }
.dropzone .title { font-weight:700; font-size:18px; }
.dropzone .sub { color:rgba(255,255,255,0.65); font-size:13px; max-width:680px; }
.dropzone:hover { border-color: rgba(124,58,237,0.9); transform: translateY(-2px); box-shadow: 0 12px 30px rgba(124,58,237,0.06); }
.dropzone.dragover { border-color: rgba(124,58,237,0.95); background: rgba(124,58,237,0.02); }

/* Controls row */
.controls { display:flex; gap:10px; align-items:center; margin-top:12px; flex-wrap:wrap; }
.btn { padding:10px 14px; border-radius:10px; border:0; cursor:pointer; font-weight:700; transition: transform 120ms ease, box-shadow 120ms ease; }
.btn-primary { background:linear-gradient(90deg, rgba(255,169,77,0.12), rgba(124,58,237,0.08)); color:var(--text, #fff); border:1px solid rgba(255,169,77,0.06); }
.btn-ghost { background:transparent; border:1px solid rgba(255,255,255,0.04); color:rgba(255,255,255,0.9); }
.btn:active { transform: translateY(1px); }
/* full-width ghost button style */
.btn.btn-ghost[style] { justify-content:center; }

/* File list (compact) */
.file-list { display:flex; flex-direction:column; gap:10px; }
.file-row { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:10px; border-radius:8px; background:rgba(255,255,255,0.01); border:1px solid rgba(255,255,255,0.02); }
.file-meta { min-width:0; overflow:hidden; }
.file-meta .name { font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:420px; }
.file-meta .size { color:rgba(255,255,255,0.55); font-size:13px; }

/* progress */
.progress { width:220px; height:10px; background:rgba(255,255,255,0.03); border-radius:999px; overflow:hidden; box-shadow: inset 0 -2px 6px rgba(0,0,0,0.35); }
.progress > i { display:block; height:100%; width:0%; background: linear-gradient(90deg, rgba(255,169,77,0.95), rgba(124,58,237,0.95)); transition: width 420ms cubic-bezier(.2,.9,.2,1); }

/* status small */
.small { font-size:13px; color:rgba(255,255,255,0.7); }
.steps-log { font-size:13px; color:rgba(255,255,255,0.6); }

/* right column: instructions & summary */
.summary { display:flex; flex-direction:column; gap:12px; }
.summary .big { font-size:18px; font-weight:800; }
.summary .muted { color:rgba(255,255,255,0.6); font-size:13px; }

/* counterparty count */
.cp-count { display:flex; gap:12px; align-items:center; justify-content:space-between; padding:12px; border-radius:8px; background: rgba(255,255,255,0.01); border:1px solid rgba(255,255,255,0.02); }
.cp-count .label { color:rgba(255,255,255,0.7); font-size:13px; }
.cp-count .value { font-size:20px; font-weight:800; }

/* footer hint and convert link */
.hint { color:rgba(255,255,255,0.6); font-size:13px; }
.convert-link { color:rgba(255,169,77,1); font-weight:700; text-decoration:underline; cursor:pointer; }

/* modal small */
.modal { position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,0.6); z-index:80; }
.modal.show { display:flex; }
.modal .mcard { padding:18px; border-radius:12px; background:rgba(255,255,255,0.03); width:480px; max-width:94%; }

/* responsive tweaks */
@media (max-width:980px) {
  .uploader-viewport { flex-direction:column; height:auto; }
  .right-panel { width:100%; max-width:100%; }
}
</style>
</head>
<body>
<div class="page-shell">
  <div class="header">
    <h1>CSV Bank Statement Uploader</h1>
    <div class="muted">CSV-only • server will parse and insert</div>
  </div>

  <div class="uploader-viewport">
    <div class="left-panel">
      <div class="card" style="display:flex;flex-direction:column;gap:14px;">
        <div style="display:flex;align-items:center;gap:16px;">
          <div style="font-weight:800;font-size:18px">Upload your converted CSV</div>
          <div class="small" style="margin-left:auto">Max file: 20 MB</div>
        </div>

        <div id="dropzone" class="dropzone" tabindex="0" aria-label="Drop CSV file here">
          <div class="icon" aria-hidden="true">
            <!-- simple box/download SVG icon -->
            <svg width="56" height="56" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
              <rect x="2" y="3" width="20" height="14" rx="2" stroke="currentColor" stroke-width="1.4" fill="none" opacity="0.95"/>
              <path d="M7 10l5 5 5-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
              <path d="M12 3v6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
            </svg>
          </div>

          <div class="title">Drop CSV file here</div>
          <div class="sub">Drop a CSV file (the one produced by the converter) or click to browse. The page accepts only <strong>.csv</strong> files. After upload the server will parse and insert transactions.</div>
          <div class="sub hint">If you have a <code>.txt</code> HDFC statement, first convert it to CSV using <a href="convert.html" class="convert-link">convert.html</a>.</div>
        </div>

        <div class="controls">
          <div style="display:flex;gap:8px;align-items:center;">
            <label class="small"><input id="optNullSide" type="checkbox" /> Upload with NULL for non-used side</label>
            <button id="addAccountBtn" class="btn btn-ghost" type="button" style="margin-left:8px">Add account</button>
          </div>

          <div style="flex:1"></div>
          <select id="accountSelect" class="select small" style="min-width:220px"><option>Loading accounts…</option></select>
          <button id="startUpload" class="btn btn-primary">Start Parse</button>
          <button id="clearFiles" class="btn btn-ghost">Clear</button>
        </div>


        <div id="fileList" class="file-list" aria-live="polite"></div>
        <div id="stepsLog" class="steps-log small">No CSV uploaded yet.</div>
      </div>
    </div>

    <div class="right-panel">
      <div class="card summary">
        <div class="big">Quick instructions</div>
        <div class="muted">
          <ol style="padding-left:16px;margin:6px 0 0 0;">
            <li><strong>Convert .txt → .csv</strong> if needed using <a href="convert.html" class="convert-link">convert.html</a>.</li>
            <li>Drag & drop or click to browse and select a single <code>.csv</code> file.</li>
            <li>Choose account (optional).</li>
            <li>Click <strong>Start Parse</strong> — the server will process and insert transactions. Use the Verify report to inspect parsing issues.</li>
          </ol>
        </div>

        <div class="cp-count" style="margin-top:6px;">
          <div>
            <div class="label">Auto-created counterparties (count)</div>
            <div class="small hint">This is the total number of counterparties associated with your account.</div>
          </div>
          <div class="value" id="cpCount">—</div>
        </div>

        <div style="margin-top:8px">
          <div style="margin-bottom:8px">
            <div class="hint">Only counts are shown here. View the Groups page for details.</div>
          </div>
          <button id="refreshGroups" class="btn btn-ghost" style="width:100%; padding:10px 12px">Refresh Count</button>
        </div>

      </div>

      <div class="card">
        <div style="font-weight:800">Status</div>
        <div id="statusBox" class="small" style="margin-top:8px">Idle</div>
      </div>
    </div>
  </div>
</div>
<!-- Add Account modal -->
<div id="addAccountModal" class="modal" aria-hidden="true">
  <div class="mcard" role="dialog" aria-modal="true" aria-labelledby="addAccountTitle">
    <h3 id="addAccountTitle" style="margin:0 0 8px 0">Add Bank Account</h3>
    <div style="display:flex;flex-direction:column;gap:8px">
      <input id="bankName" class="select" placeholder="Bank name (e.g. HDFC Bank)" />
      <input id="acctMask" class="select" placeholder="Account masked (e.g. ****4404) (optional)" />
      <input id="ifsc" class="select" placeholder="IFSC (optional)" />
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">
        <button id="closeAddAccount" class="btn btn-ghost">Close</button>
        <button id="createAccount" class="btn btn-primary">Create</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal (unused currently but kept for future micro interactions) -->
<div id="modal" class="modal" aria-hidden="true">
  <div class="mcard">
    <div style="font-weight:800;margin-bottom:8px">Notice</div>
    <div class="small" id="modalText"></div>
    <div style="display:flex;justify-content:flex-end;margin-top:12px">
      <button id="closeModal" class="btn btn-ghost">Close</button>
    </div>
  </div>
</div>

<script>
/* ---------- Config ---------- */
const API_URL = '/Assets/Website/Api/upload_api.php';
const CSRF = <?php echo json_encode($csrf); ?>;
const MAX_BYTES = 20 * 1024 * 1024;

/* ---------- DOM ---------- */
const dropzone = document.getElementById('dropzone');
const fileListEl = document.getElementById('fileList');
const startBtn = document.getElementById('startUpload');
const clearBtn = document.getElementById('clearFiles');
const stepsLog = document.getElementById('stepsLog');
const accountSelect = document.getElementById('accountSelect');
const refreshGroups = document.getElementById('refreshGroups');
const cpCountEl = document.getElementById('cpCount');
const statusBox = document.getElementById('statusBox');

let queuedFile = null;
let optNullSide = document.getElementById('optNullSide');

/* ---------- Utilities ---------- */
function setSteps(msg){ stepsLog.textContent = msg; }
function setStatus(msg){ statusBox.textContent = msg; }
function esc(s){ return String(s||''); }

/* ---------- Render queue (single-file UX) ---------- */
function renderQueue() {
  fileListEl.innerHTML = '';
  if (!queuedFile) { fileListEl.style.display = 'none'; return; }
  fileListEl.style.display = 'flex';
  const f = queuedFile;
  const row = document.createElement('div');
  row.className = 'file-row';
  row.innerHTML = `
    <div style="display:flex;gap:12px;align-items:center;min-width:0">
      <div class="file-meta">
        <div class="name">${esc(f.name)}</div>
        <div class="size small">${(f.size/1024/1024).toFixed(2)} MB • CSV</div>
      </div>
    </div>
    <div style="display:flex;gap:12px;align-items:center">
      <div class="progress"><i id="progBar" style="width:0%"></i></div>
      <div class="small" id="fileStatus">Queued</div>
    </div>
  `;
  fileListEl.appendChild(row);
}

/* ---------- File handling (CSV only) ---------- */
function fileKindFromName(file){
  const name = (file.name || '').toLowerCase();
  if (name.endsWith('.csv') || file.type === 'text/csv' || file.type === 'application/vnd.ms-excel') return 'csv';
  return 'other';
}

function handleFiles(list){
  if (!list || list.length === 0) return;
  const f = list[0]; // accept only first file
  const kind = fileKindFromName(f);
  if (kind !== 'csv') { alert('Only CSV files are accepted. Please provide a .csv file.'); return; }
  if (f.size > MAX_BYTES) { alert('File too large (max 20 MB).'); return; }
  queuedFile = f;
  renderQueue();
  setSteps('File ready: ' + f.name);
  setStatus('Ready to upload CSV.');
}

/* Drag/drop events */
['dragenter','dragover'].forEach(evt => {
  dropzone.addEventListener(evt, (e) => { e.preventDefault(); e.stopPropagation(); dropzone.classList.add('dragover'); });
});
['dragleave','drop'].forEach(evt => {
  dropzone.addEventListener(evt, (e) => { e.preventDefault(); e.stopPropagation(); dropzone.classList.remove('dragover'); });
});
dropzone.addEventListener('drop', (e) => {
  const dt = e.dataTransfer;
  handleFiles(dt.files);
});
dropzone.addEventListener('click', () => {
  const ip = document.createElement('input');
  ip.type = 'file';
  ip.accept = '.csv,text/csv,application/csv';
  ip.onchange = () => handleFiles(ip.files);
  ip.click();
});
dropzone.addEventListener('keydown', (e)=> { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); dropzone.click(); } });

clearBtn.addEventListener('click', () => {
  queuedFile = null;
  renderQueue();
  setSteps('Cleared.');
  setStatus('Idle');
});
/* --- Add Account modal handlers --- */
const addAccountBtn = document.getElementById('addAccountBtn');
const addAccountModal = document.getElementById('addAccountModal');
const closeAddAccount = document.getElementById('closeAddAccount');
const createAccount = document.getElementById('createAccount');
const bankName = document.getElementById('bankName');
const acctMask = document.getElementById('acctMask');
const ifsc = document.getElementById('ifsc');

if (addAccountBtn) {
  addAccountBtn.addEventListener('click', () => {
    if (addAccountModal) { addAccountModal.classList.add('show'); addAccountModal.setAttribute('aria-hidden','false'); bankName.focus(); }
  });
}
if (closeAddAccount) {
  closeAddAccount.addEventListener('click', () => {
    if (addAccountModal) { addAccountModal.classList.remove('show'); addAccountModal.setAttribute('aria-hidden','true'); }
  });
}
if (createAccount) {
  createAccount.addEventListener('click', async () => {
    const bank = (bankName.value || '').trim();
    if (!bank) return alert('Enter bank name');
    createAccount.disabled = true;
    try {
      const resp = await fetch(API_URL + '?action=add_account', {
        method: 'POST',
        credentials: 'include',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ bank_name: bank, account_number_masked: (acctMask.value || '').trim(), ifsc: (ifsc.value || '').trim() })
      });
      const j = await resp.json();
      if (j.success) {
        // hide modal and refresh accounts
        if (addAccountModal) { addAccountModal.classList.remove('show'); addAccountModal.setAttribute('aria-hidden','true'); }
        bankName.value = ''; acctMask.value = ''; ifsc.value = '';
        await loadAccounts();
        alert('Account created');
      } else {
        alert('Failed: ' + (j.error || 'unknown'));
      }
    } catch (e) {
      alert('Server error');
    } finally {
      createAccount.disabled = false;
    }
  });
}

/* ---------- Upload & parse CSV ---------- */
async function startParse() {
  if (!queuedFile) return alert('Please select a CSV file first.');
  startBtn.disabled = true;
  clearBtn.disabled = true;
  setSteps('Uploading CSV...');
  setStatus('Uploading...');

  const statusTextEl = document.getElementById('fileStatus');
  const progBar = document.getElementById('progBar');

  try {
    const fd = new FormData();
    fd.append('csv_file', queuedFile);
    fd.append('filename', queuedFile.name);
    fd.append('csrf_token', CSRF);
    if (accountSelect.value) fd.append('account_id', accountSelect.value);
    // pass option whether to prefer NULL for non-used side (server uses parse_csv_and_insert to interpret)
    if (optNullSide && optNullSide.checked) fd.append('null_for_other_side', '1');

    // Use XHR so we can display upload progress
    await new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', API_URL + '?action=upload_csv', true);
      xhr.withCredentials = true;
      xhr.upload.onprogress = (e) => {
        if (e.lengthComputable) {
          const pct = Math.round((e.loaded / e.total) * 100);
          progBar.style.width = pct + '%';
          if (statusTextEl) statusTextEl.textContent = 'Uploading: ' + pct + '%';
          setStatus('Uploading: ' + pct + '%');
        }
      };
      xhr.onload = () => {
        let j = null;
        try { j = JSON.parse(xhr.responseText); } catch (e) { j = null; }
        if (xhr.status >= 200 && xhr.status < 300 && j && j.success) {
          progBar.style.width = '100%';
          if (statusTextEl) statusTextEl.textContent = 'Uploaded. Parsing...';
          setStatus('Uploaded. Parsing...');
          // If server processed synchronously it returns parse_status and inserted_rows; otherwise poll.
          if (j.parse_status === 'parsed' || j.parse_status === 'error') {
            // immediate result
            resolve(j);
          } else if (j.statement_id) {
            // poll for status
            pollParseStatus(j.statement_id).then(() => resolve(j)).catch(err => reject(err));
          } else {
            // fallback: try to parse statement id from response or resolve anyway
            resolve(j || {});
          }
        } else {
          const errMsg = (j && j.error) ? j.error : ('HTTP ' + xhr.status);
          reject(new Error('Upload failed: ' + errMsg));
        }
      };
      xhr.onerror = () => { reject(new Error('Network error during upload')); };
      xhr.send(fd);
    });

    setSteps('Parsing started on server. Waiting for completion...');
    setStatus('Parsing...');

    // After server parsing completes, refresh counterparty count
    await new Promise(resolve => setTimeout(resolve, 700));
    await loadGroups(); // refresh counts
    setSteps('Parse completed (see status).');
    setStatus('Done');
  } catch (err) {
    console.error(err);
    setSteps('Error: ' + (err.message || err));
    setStatus('Error');
    alert('Upload or parse failed: ' + (err.message || err));
  } finally {
    startBtn.disabled = false;
    clearBtn.disabled = false;
  }
}

/* Poll parse status helper */
function pollParseStatus(statementId, timeoutMs = 120000) {
  return new Promise((resolve, reject) => {
    const start = Date.now();
    const iv = setInterval(async () => {
      try {
        const resp = await fetch(API_URL + '?action=status&sid=' + encodeURIComponent(statementId), { credentials: 'include' });
        if (!resp.ok) throw new Error('Status check failed');
        const j = await resp.json();
        if (!j.success) { clearInterval(iv); return reject(new Error(j.error || 'status error')); }
        if (j.parse_status === 'parsed') { clearInterval(iv); setSteps('Parsed ✓'); setStatus('Parsed ✓'); resolve(j); return; }
        if (j.parse_status === 'error') { clearInterval(iv); setSteps('Parse error'); setStatus('Parse error'); resolve(j); return; }
        // otherwise still parsing
        setSteps('Parsing on server... (' + (j.parse_status || 'pending') + ')');
        setStatus('Parsing: ' + (j.parse_status || 'pending'));
        if (Date.now() - start > timeoutMs) { clearInterval(iv); resolve(j); return; }
      } catch (e) {
        // ignore transient errors, but stop after timeout
        if (Date.now() - start > timeoutMs) { clearInterval(iv); resolve({}); return; }
      }
    }, 1800);
  });
}

/* ---------- Accounts & Groups (counts-only) ---------- */
async function loadAccounts(){
  accountSelect.innerHTML = '<option value="">Loading accounts…</option>';
  try {
    const resp = await fetch(API_URL + '?action=list_accounts', { credentials: 'include' });
    const j = await resp.json();
    if (j.success) {
      accountSelect.innerHTML = '<option value="">-- Select account (optional) --</option>';
      (j.accounts || []).forEach(r => {
        const text = `${r.bank_name}${r.account_number_masked ? ' · ' + r.account_number_masked : ''}${r.ifsc ? ' · ' + r.ifsc : ''}`;
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

async function loadGroups(){
  cpCountEl.textContent = '…';
  try {
    const resp = await fetch(API_URL + '?action=get_groups', { credentials: 'include' });
    const j = await resp.json();
    if (j.success) {
      const rows = j.counterparties || [];
      cpCountEl.textContent = String(rows.length);
    } else {
      cpCountEl.textContent = '–';
    }
  } catch (e) {
    cpCountEl.textContent = '–';
  }
}

/* ---------- Bind events ---------- */
startBtn.addEventListener('click', startParse);
refreshGroups.addEventListener('click', loadGroups);

/* Init */
renderQueue();
loadAccounts();
loadGroups();
setSteps('Ready — drop a CSV or click to browse. See convert.html to convert .txt → .csv.');
setStatus('Idle');

</script>
