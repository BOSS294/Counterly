<?php
// upload_ui.php - client UI. Uses PDF.js in-browser text extraction and posts to upload_api.php
session_start();
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo "<!doctype html><html><body><h2>Not authenticated</h2><p>Please login to use the uploader.</p></body></html>";
    exit;
}
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Upload Bank Statement — UI</title>

<!-- PDF.js (main script) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.0.379/pdf.min.js"></script>

<style>
:root{
  --bg:#0b0b0b; --card:#0f1720; --text:#e7e7e7; --muted:rgba(231,231,231,0.56);
  --accent1:#ffa94d; --accent2:#7c3aed;
}
*{box-sizing:border-box}
body{margin:0;font-family:Inter,system-ui,Arial;background:var(--bg);color:var(--text);padding:20px}
.container{max-width:1200px;margin:0 auto}
.header{display:flex;align-items:center;gap:12px;margin-bottom:14px}
.header h1{font-size:20px;margin:0}
.grid{display:grid;grid-template-columns:1fr 420px;gap:20px}
@media(max-width:980px){ .grid{ grid-template-columns:1fr } }
.card{background:linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));padding:18px;border-radius:12px;border:1px solid rgba(255,255,255,0.03)}
.instructions p{color:var(--muted);margin:6px 0}
.dropzone{border:2px dashed rgba(255,255,255,0.04);padding:20px;border-radius:10px;display:flex;flex-direction:column;gap:10px;align-items:center;justify-content:center;cursor:pointer}
.dropzone.dragover{border-color:rgba(255,169,77,0.85);background:rgba(255,169,77,0.02)}
.controls{display:flex;gap:8px;margin-top:12px;align-items:center;flex-wrap:wrap}
.file-list{margin-top:12px;display:flex;flex-direction:column;gap:10px}
.file-row{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:10px;border-radius:10px;background:rgba(255,255,255,0.02)}
.file-progress{width:220px;height:10px;background:rgba(255,255,255,0.02);border-radius:6px;overflow:hidden}
.file-progress > i{display:block;height:100%;width:0%;background:linear-gradient(90deg,var(--accent1),var(--accent2))}
.file-status{font-size:13px;color:var(--muted);min-width:140px;text-align:right}
.btn{padding:8px 12px;border-radius:8px;cursor:pointer;border:0;font-weight:800}
.btn-primary{background:linear-gradient(90deg, rgba(255,169,77,0.12), rgba(124,58,237,0.08));border:1px solid rgba(255,169,77,0.06);color:var(--text)}
.btn-ghost{background:transparent;border:1px solid rgba(255,255,255,0.04);color:var(--muted)}
.small{font-size:13px;color:var(--muted)}
.flex-row{display:flex;gap:8px;align-items:center}
.select, input[type="text"]{background:transparent;border:1px solid rgba(255,255,255,0.04);color:var(--text);padding:8px;border-radius:8px}
.counterparty-item{padding:8px;border-radius:8px;background:rgba(255,255,255,0.01);margin-bottom:8px}
.modal{position:fixed;left:0;top:0;right:0;bottom:0;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,0.6);z-index:60}
.modal.show{display:flex}
.modal .mcard{width:420px;max-width:95%;background:var(--card);padding:16px;border-radius:12px}
.hint{font-size:12px;color:var(--muted);margin-top:8px}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>Upload Bank Statements</h1>
    <div class="small" style="margin-left:auto">Logged in • secure upload</div>
  </div>

  <div class="grid">
    <div class="card instructions">
      <h3>How it works</h3>
      <p class="small">This UI can extract text in the browser via <strong>pdf.js</strong> and send the extracted text to the server for parsing. If you upload a PDF without sending extracted text, the server will only store the PDF (no server-side PDF→text extraction is performed).</p>
      <p class="small"><strong>Privacy:</strong> extracted text and files are sent only to your account; files are stored with SHA256 checksums to avoid duplicate parsing.</p>
      <div style="margin-top:12px">
        <strong>Tips</strong>
        <ul class="small">
          <li>HDFC provides `.txt` statements — uploading the `.txt` directly is the most reliable.</li>
          <li>Max 20 MB per file.</li>
        </ul>
      </div>
    </div>

    <div class="card">
      <div id="dropzone" class="dropzone" tabindex="0">
        <div style="font-size:34px;opacity:0.9">📄</div>
        <div id="dropText">Drag & drop PDF or TXT files here or click to browse</div>
        <div class="small">Multiple files allowed — processed one-by-one.</div>
      </div>

      <div class="controls">
        <label class="small"><input type="checkbox" id="useClientExtract" checked> Extract text in browser (pdf.js)</label>
        <label class="small"><input type="checkbox" id="compressPdf"> Compress on server</label>

        <label style="margin-left:8px" class="small">Format:
          <select id="statementFormat" class="select small">
            <option value="auto">Auto (detect by extension)</option>
            <option value="pdf">PDF</option>
            <option value="txt">TXT</option>
          </select>
        </label>

        <div style="flex:1"></div>
        <select id="accountSelect" class="select small"><option>Loading accounts…</option></select>
        <button id="startUpload" class="btn btn-primary">Start</button>
        <button id="clearFiles" class="btn btn-ghost">Clear</button>
      </div>

      <div class="file-list" id="fileList"></div>
      <div class="steps-log small" id="stepsLog">No uploads yet.</div>

      <hr style="margin:12px 0;border-color:rgba(255,255,255,0.03)" />
      <div style="display:flex;gap:8px;align-items:center;justify-content:space-between">
        <div>
          <strong>Groups</strong>
          <div class="hint">Auto-created when a counterparty appears ≥ 2 times</div>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          <button id="refreshGroups" class="btn btn-ghost">Refresh</button>
          <button id="addAccountBtn" class="btn btn-ghost">Add account</button>
        </div>
      </div>
      <div id="counterpartyList" style="margin-top:10px"></div>
    </div>
  </div>
</div>

<!-- modal for add account -->
<div id="modal" class="modal" aria-hidden="true">
  <div class="mcard">
    <h3 style="margin:0 0 8px 0">Add Bank Account</h3>
    <div style="display:flex;flex-direction:column;gap:8px">
      <input id="bankName" class="select" placeholder="Bank name (e.g. HDFC Bank)" />
      <input id="acctMask" class="select" placeholder="Account masked (e.g. ****4404) (optional)" />
      <input id="ifsc" class="select" placeholder="IFSC (optional)" />
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">
        <button id="closeModal" class="btn btn-ghost">Close</button>
        <button id="createAccount" class="btn btn-primary">Create</button>
      </div>
    </div>
  </div>
</div>

<script>
/* Configuration */
const API_URL = '/Assets/Website/Api/upload_api.php';
const CSRF = <?php echo json_encode($csrf); ?>;
const MAX_BYTES = 20 * 1024 * 1024;

/* DOM */
const dropzone = document.getElementById('dropzone');
const dropText = document.getElementById('dropText');
const fileListEl = document.getElementById('fileList');
const startBtn = document.getElementById('startUpload');
const clearBtn = document.getElementById('clearFiles');
const stepsLog = document.getElementById('stepsLog');
const compressCheckbox = document.getElementById('compressPdf');
const useClientExtract = document.getElementById('useClientExtract');
const statementFormat = document.getElementById('statementFormat');
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

let filesQueue = [];

/* pdf.js worker - ensure worker path matches version */
if (window['pdfjsLib']) {
  window['pdfjsLib'].GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.0.379/pdf.worker.min.js';
}

/* Utilities */
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
          <div style="color:var(--muted); font-size:13px;">${(fObj.file.size/1024/1024).toFixed(2)} MB • ${fObj.kind}</div>
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

/* File handling - accept both PDF and TXT by default; behavior influenced by statementFormat */
function fileKindFromName(file){
  const name = (file.name || '').toLowerCase();
  if (name.endsWith('.txt') || file.type === 'text/plain') return 'txt';
  if (name.endsWith('.pdf') || file.type === 'application/pdf') return 'pdf';
  return 'other';
}

function handleFiles(list){
  for(const f of list){
    const kind = fileKindFromName(f);
    // If user forced a format, respect it (for hinting); but still allow main types.
    if (statementFormat.value === 'pdf' && kind !== 'pdf') { alert('PDF format selected — only PDF files allowed: ' + f.name); continue; }
    if (statementFormat.value === 'txt' && kind !== 'txt') { alert('TXT format selected — only .txt files allowed: ' + f.name); continue; }
    if (kind !== 'pdf' && kind !== 'txt') { alert('Only PDF or TXT files allowed: ' + f.name); continue; }
    if(f.size > MAX_BYTES){ alert('File too large (max 20MB): ' + f.name); continue; }
    filesQueue.push({ file: f, state: 'queued', kind: kind });
  }
  renderQueue();
}

/* drag/drop UI */
['dragenter','dragover'].forEach(evt=> dropzone.addEventListener(evt, (e)=>{ e.preventDefault(); dropzone.classList.add('dragover'); }));
['dragleave','drop'].forEach(evt=> dropzone.addEventListener(evt, (e)=>{ e.preventDefault(); dropzone.classList.remove('dragover'); }));
dropzone.addEventListener('drop', (e)=>{ handleFiles(e.dataTransfer.files); });
dropzone.addEventListener('click', ()=>{ const ip = document.createElement('input'); ip.type='file'; ip.accept='.pdf,.txt,application/pdf,text/plain'; ip.multiple=true; ip.onchange = ()=> handleFiles(ip.files); ip.click(); });
dropzone.addEventListener('keydown', (e)=>{ if(e.key === 'Enter' || e.key === ' ') { e.preventDefault(); dropzone.click(); } });

clearBtn.addEventListener('click', ()=>{ filesQueue = []; renderQueue(); setSteps('Cleared.'); });

startBtn.addEventListener('click', async ()=> {
  if(filesQueue.length === 0) return alert('No files selected');
  if(!confirm('Start upload and parse now?')) return;
  startBtn.disabled = true; clearBtn.disabled = true; setSteps('Starting uploads...');
  const accountId = accountSelect.value ? parseInt(accountSelect.value) : null;
  for(let i=0;i<filesQueue.length;i++){
    const item = filesQueue[i];
    const stEl = document.getElementById('status'+i);
    if(stEl) stEl.textContent = 'Processing...';
    await processFile(item.file, i, accountId, item.kind);
  }
  setSteps('All uploads finished. Check Statements page.');
  startBtn.disabled = false; clearBtn.disabled = false;
});

/* PDF.js extraction (client) */
async function extractPdfText(file){
  if (!window['pdfjsLib']) throw new Error('pdfjs not loaded');
  const pdfjsLib = window['pdfjsLib'];
  pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/4.0.379/pdf.worker.min.js';
  const arrayBuffer = await file.arrayBuffer();
  const loadingTask = pdfjsLib.getDocument({ data: arrayBuffer, nativeImageDecoderSupport: 'none' });
  const pdf = await loadingTask.promise;
  let text = '';
  for (let i = 1; i <= pdf.numPages; i++) {
    const page = await pdf.getPage(i);
    const content = await page.getTextContent();
    text += content.items.map(item => item.str).join(' ') + '\n';
  }
  return text;
}

/* processFile: txt files are uploaded to upload_text; pdf files are extracted client-side if enabled or uploaded as binary otherwise */
async function processFile(file, idx, accountId, kind){
  const statusEl = document.getElementById('status'+idx);
  const progEl = document.querySelector('#prog'+idx+' i');
  try {
    // TXT file -> upload as text (server will parse)
    if (kind === 'txt') {
      statusEl.textContent = 'Reading .txt file...';
      const txt = await file.text();
      if (!txt || txt.trim().length < 10) { statusEl.textContent = 'Empty text file'; return; }
      statusEl.textContent = 'Uploading text...';
      progEl.style.width = '20%';
      const fd = new FormData();
      fd.append('pdf_text', txt);
      fd.append('filename', file.name);
      fd.append('csrf_token', CSRF);
      if (accountId) fd.append('account_id', accountId);
      if (compressCheckbox.checked) fd.append('compress', '1');
      const res = await fetch(API_URL + '?action=upload_text', { method: 'POST', credentials: 'include', body: fd });
      const j = await res.json();
      if (j.success) {
        progEl.style.width = '100%';
        statusEl.textContent = 'Uploaded (text) — Parsing';
        await pollParseStatus(j.statement_id, idx);
      } else {
        statusEl.textContent = 'Server error: ' + (j.error||'unknown');
      }
      return;
    }

    // PDF file -> try client extraction if enabled
    if (kind === 'pdf') {
      if (useClientExtract.checked) {
        statusEl.textContent = 'Extracting text (browser)...';
        let pdfText = '';
        try {
          pdfText = await extractPdfText(file);
        } catch(e) {
          console.warn('Client extraction failed:', e);
        }
        if (pdfText && pdfText.trim().length > 30) {
          // Send extracted text to upload_text; allow server to attach to a previously-uploaded PDF by statement_id if desired
          statusEl.textContent = 'Uploading extracted text...';
          progEl.style.width = '20%';
          const fd = new FormData();
          fd.append('pdf_text', pdfText);
          fd.append('filename', file.name);
          fd.append('csrf_token', CSRF);
          if (accountId) fd.append('account_id', accountId);
          if (compressCheckbox.checked) fd.append('compress', '1');
          const res = await fetch(API_URL + '?action=upload_text', { method: 'POST', credentials: 'include', body: fd });
          const j = await res.json();
          if (j.success) {
            progEl.style.width = '100%';
            statusEl.textContent = 'Uploaded (text) — Parsing';
            await pollParseStatus(j.statement_id, idx);
            return;
          } else {
            statusEl.textContent = 'Server error: ' + (j.error||'unknown');
            return;
          }
        } else {
          statusEl.textContent = 'No text extracted, falling back to PDF upload';
        }
      }

      // fallback: upload PDF binary to server (server will store but will NOT extract text automatically)
      statusEl.textContent = 'Uploading PDF (will be stored, no server extraction)...';
      progEl.style.width = '5%';
      const fd2 = new FormData();
      fd2.append('statement_pdf', file);
      fd2.append('csrf_token', CSRF);
      if (accountId) fd2.append('account_id', accountId);
      if (compressCheckbox.checked) fd2.append('compress', '1');

      const xhr = new XMLHttpRequest();
      xhr.open('POST', API_URL + '?action=upload', true);
      xhr.withCredentials = true;
      xhr.upload.onprogress = function(e){ if(e.lengthComputable){ const pct = Math.round((e.loaded / e.total) * 100); progEl.style.width = pct + '%'; statusEl.textContent = 'Uploading ' + pct + '%'; } };
      await new Promise((resolve) => {
        xhr.onload = async function(){
          let j = null;
          try { j = JSON.parse(xhr.responseText); } catch(e){ /* ignore */ }
          if (xhr.status >=200 && xhr.status < 300 && j && j.success) {
            statusEl.textContent = 'Uploaded — Stored (no server-side PDF→text extraction).';
            progEl.style.width = '100%';
            // If server returned parse_status let's show it; otherwise the client can extract and upload_text referencing statement_id in a follow-up step
            if (j.parse_status) statusEl.textContent += ' Status: ' + j.parse_status;
            // NOTE: client can now extract text and call upload_text with statement_id to attach the text and trigger parsing.
          } else {
            statusEl.textContent = 'Upload failed: ' + (j && j.error ? j.error : xhr.status);
          }
          resolve();
        };
        xhr.onerror = function(){ statusEl.textContent = 'Network error'; resolve(); };
        xhr.send(fd2);
      });

      return;
    }

    statusEl.textContent = 'Unsupported file type';
  } catch (err) {
    console.error('processFile error', err);
    const statusEl = document.getElementById('status'+idx);
    statusEl.textContent = 'Error: ' + (err.message || 'unknown');
  }
}

/* Poll parse status */
function pollParseStatus(statementId, idx){
  const statusEl = document.getElementById('status'+idx);
  statusEl.textContent = 'Queued for parsing...';
  return new Promise((resolve) => {
    const iv = setInterval(async () => {
      try {
        const resp = await fetch(API_URL + '?action=status&sid=' + encodeURIComponent(statementId), { credentials: 'include' });
        if (resp.ok) {
          const j = await resp.json();
          if (j.success) {
            if (j.parse_status === 'parsed') { statusEl.textContent = 'Parsed ✓'; clearInterval(iv); resolve(); }
            else if (j.parse_status === 'error') { statusEl.textContent = 'Error parsing'; console.warn('parse error', j.error_message); clearInterval(iv); resolve(); }
            else { statusEl.textContent = (j.parse_status || 'parsing') + '...'; }
          } else {
            statusEl.textContent = 'Error: ' + (j.error || 'unknown');
            clearInterval(iv); resolve();
          }
        }
      } catch(e){
        // ignore transient network glitches
      }
    }, 2000);
    setTimeout(()=>{ clearInterval(iv); resolve(); }, 120000);
  });
}

/* Accounts & groups */
addAccountBtn.addEventListener('click', ()=> { modal.classList.add('show'); modal.setAttribute('aria-hidden','false'); bankName.focus(); });
closeModal.addEventListener('click', ()=> { modal.classList.remove('show'); modal.setAttribute('aria-hidden','true'); });

createAccount.addEventListener('click', async ()=> {
  const bank = bankName.value.trim();
  if (!bank) return alert('Enter bank name');
  createAccount.disabled = true;
  try {
    const resp = await fetch(API_URL + '?action=add_account', {
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
    const resp = await fetch(API_URL + '?action=list_accounts', { credentials: 'include' });
    const j = await resp.json();
    if (j.success) {
      const rows = j.accounts || [];
      accountSelect.innerHTML = '<option value="">-- Select account (optional) --</option>';
      rows.forEach(r => {
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

refreshGroups.addEventListener('click', loadGroups);
async function loadGroups(){
  counterpartyList.innerHTML = 'Loading...';
  try {
    const resp = await fetch(API_URL + '?action=get_groups', { credentials: 'include' });
    const j = await resp.json();
    if (j.success) {
      const rows = j.counterparties || [];
      if (rows.length === 0) { counterpartyList.innerHTML = '<div class="small">No groups yet</div>'; return; }
      counterpartyList.innerHTML = '';
      rows.forEach(cp => {
        const el = document.createElement('div'); el.className = 'counterparty-item';
        el.innerHTML = `<div style="display:flex;justify-content:space-between;align-items:center">
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

/* Hook statementFormat to update dropzone hint */
statementFormat.addEventListener('change', () => {
  const val = statementFormat.value;
  if (val === 'pdf') dropText.textContent = 'Drag & drop PDF files here or click to browse';
  else if (val === 'txt') dropText.textContent = 'Drag & drop TXT files here or click to browse';
  else dropText.textContent = 'Drag & drop PDF or TXT files here or click to browse';
});

/* Double-click to browse (alternate) */
dropzone.addEventListener('dblclick', () => {
  const ip = document.createElement('input'); ip.type='file'; ip.accept='.pdf,.txt,application/pdf,text/plain'; ip.multiple=true;
  ip.onchange = ()=> handleFiles(ip.files);
  ip.click();
});

/* Init */
renderQueue();
loadAccounts();
loadGroups();
setSteps('Ready — choose PDFs/TXTs to upload.');
</script>
<script>
// === CSV Upload / Preview / Send ===
// Assumes variables from your page exist:
//   API_URL, CSRF, accountSelect, file input handling, renderQueue() ... etc.
// Adds CSV handling on top of existing file flow.

(function(){
  /* helper CSV parser (simple RFC4180-ish) */
  function parseCSV(text) {
    // returns array of rows (each row is array of cells)
    const rows = [];
    let i = 0, len = text.length;
    let cur = '', row = [], inQuotes = false;
    while (i < len) {
      const ch = text[i];
      const nxt = text[i+1];
      if (inQuotes) {
        if (ch === '"') {
          if (nxt === '"') { cur += '"'; i += 2; continue; } // escaped quote
          inQuotes = false; i++; continue;
        } else {
          cur += ch; i++; continue;
        }
      } else {
        if (ch === '"') { inQuotes = true; i++; continue; }
        if (ch === ',') { row.push(cur); cur = ''; i++; continue; }
        if (ch === '\r') { i++; continue; }
        if (ch === '\n') { row.push(cur); rows.push(row); row = []; cur = ''; i++; continue; }
        cur += ch; i++;
      }
    }
    // final cell
    if (inQuotes) {
      // malformed csv - still flush
      row.push(cur);
      rows.push(row);
    } else {
      if (cur !== '' || row.length>0) { row.push(cur); rows.push(row); }
    }
    return rows;
  }

  function headerIndexMap(headers) {
    const map = {};
    headers.forEach((h, idx) => {
      map[h.trim().toLowerCase()] = idx;
    });
    return map;
  }

  // Validate presence of required headers (allow some alternates)
  function validateHeaders(hmap) {
    const required = [
      ['txn_date','date'],
      ['narration'],
      ['counterparty'],
      ['txn_type'],
      ['amount_rupees','amount','amount_paise'],
      ['debit_paise','debit'],
      ['credit_paise','credit'],
      ['balance_rupees','balance','balance_paise']
    ];
    const missing = [];
    for (const alt of required) {
      const ok = alt.some(a => a && (a in hmap));
      if (!ok) missing.push(alt[0]);
    }
    return missing;
  }

  function rowsToObjects(rows) {
    if (!rows || rows.length === 0) return [];
    const headers = rows[0].map(h => (h||'').trim());
    const hmap = headerIndexMap(headers);
    const missing = validateHeaders(hmap);
    if (missing.length) throw new Error('CSV missing required columns: ' + missing.join(', '));
    const objs = [];
    for (let r = 1; r < rows.length; r++) {
      const row = rows[r];
      // Skip empty lines
      if (row.join('').trim() === '') continue;
      const get = (names) => {
        if (!Array.isArray(names)) names = [names];
        for (const n of names) {
          if (n && (n in hmap)) return (row[hmap[n]]||'').trim();
        }
        return '';
      };
      const o = {
        txn_date: get(['txn_date','date']),
        value_date: get(['value_date']),
        counterparty: get(['counterparty']),
        narration: get(['narration']),
        reference: get(['reference','reference_number']),
        txn_type: get(['txn_type']),
        amount_rupees: get(['amount_rupees','amount']),
        debit_paise: get(['debit_paise','debit']),
        credit_paise: get(['credit_paise','credit']),
        balance_rupees: get(['balance_rupees','balance'])
      };
      objs.push(o);
    }
    return objs;
  }

  function previewCSV(text) {
    try {
      const rows = parseCSV(text);
      const objs = rowsToObjects(rows);
      return { rows, objs, error: null };
    } catch (err) {
      return { rows: null, objs: null, error: err.message || String(err) };
    }
  }

  // UI elements used in your page:
  const csvInput = document.createElement('input');
  csvInput.type = 'file';
  csvInput.accept = '.csv,text/csv';
  csvInput.multiple = false;

  // Add a small CSV upload button to the dropzone (or reuse existing dropzone logic)
  const csvBtn = document.createElement('button');
  csvBtn.type = 'button';
  csvBtn.textContent = 'Upload CSV';
  csvBtn.title = 'Upload pre-parsed CSV (converted by the converter)';
  csvBtn.style.marginLeft = '8px';
  // append near startBtn if available
  const startBtn = document.getElementById('startUpload');
  if (startBtn && startBtn.parentNode) startBtn.parentNode.appendChild(csvBtn);

  csvBtn.addEventListener('click', ()=> csvInput.click());
  csvInput.addEventListener('change', async (ev) => {
    const f = ev.target.files[0];
    if (!f) return;
    if (f.size > MAX_BYTES) { alert('CSV too large'); return; }
    const txt = await f.text();
    const pr = previewCSV(txt);
    if (pr.error) { alert('CSV parse error: ' + pr.error); return; }
    // show preview (first 10) - reuse preview element if exists
    const previewEl = document.getElementById('preview');
    if (previewEl) {
      previewEl.textContent = pr.objs.slice(0,10).map(r => `${r.txn_date} | ${r.counterparty} | ${r.txn_type} | ${r.amount_rupees} | ${r.narration}`).join('\n');
    }
    if (!confirm(`CSV seems valid with ${pr.objs.length} rows. Upload to server and insert into DB?`)) return;

    // upload CSV as text to API action=upload_csv
    try {
      const fd = new FormData();
      fd.append('csv_text', txt);
      fd.append('filename', f.name);
      fd.append('csrf_token', CSRF);
      const accountId = accountSelect.value ? accountSelect.value : '';
      if (accountId) fd.append('account_id', accountId);

      // optional: allow chunked progress UI by using fetch; show steps
      const status = document.getElementById('status0') || document.getElementById('stepsLog');
      if (status) status.textContent = 'Uploading CSV...';
      const resp = await fetch(API_URL + '?action=upload_csv', { method: 'POST', credentials: 'include', body: fd });
      const j = await resp.json();
      if (j.success) {
        if (status) status.textContent = `CSV uploaded, statement_id=${j.statement_id}. Parsing completed: ${j.parse_status||'parsed'}. Inserted ${j.inserted_rows||'?' } rows.`;
        alert('CSV uploaded and processed successfully.');
        // optionally refresh groups/accounts
        if (typeof loadGroups === 'function') loadGroups();
      } else {
        alert('Server error: ' + (j.error || 'unknown'));
      }
    } catch (err) {
      console.error(err);
      alert('Upload failed: ' + (err && err.message ? err.message : String(err)));
    }
  });

  // also support pasting CSV text into inputText and a new button "Upload pasted CSV"
  const pasteBtn = document.createElement('button');
  pasteBtn.type = 'button';
  pasteBtn.textContent = 'Upload pasted CSV';
  pasteBtn.style.marginLeft = '8px';
  if (startBtn && startBtn.parentNode) startBtn.parentNode.appendChild(pasteBtn);
  pasteBtn.addEventListener('click', async () => {
    const txt = document.getElementById('inputText').value || '';
    if (!txt.trim()) return alert('Paste CSV text into the large textarea first.');
    const pr = previewCSV(txt);
    if (pr.error) return alert('CSV parse error: ' + pr.error);
    if (!confirm(`CSV seems valid with ${pr.objs.length} rows. Upload now?`)) return;
    try {
      const fd = new FormData();
      fd.append('csv_text', txt);
      fd.append('filename', 'pasted.csv');
      fd.append('csrf_token', CSRF);
      const accountId = accountSelect.value ? accountSelect.value : '';
      if (accountId) fd.append('account_id', accountId);
      const status = document.getElementById('stepsLog');
      if (status) status.textContent = 'Uploading CSV...';
      const resp = await fetch(API_URL + '?action=upload_csv', { method: 'POST', credentials: 'include', body: fd });
      const j = await resp.json();
      if (j.success) {
        if (status) status.textContent = `CSV uploaded, statement_id=${j.statement_id}. Parsing completed: ${j.parse_status||'parsed'}. Inserted ${j.inserted_rows||'?' } rows.`;
        alert('CSV uploaded and processed successfully.');
        if (typeof loadGroups === 'function') loadGroups();
      } else {
        alert('Server error: ' + (j.error || 'unknown'));
      }
    } catch (err) {
      console.error(err);
      alert('Upload failed: ' + (err && err.message ? err.message : String(err)));
    }
  });

})(); // IIFE end
</script>

</body>
</html>
