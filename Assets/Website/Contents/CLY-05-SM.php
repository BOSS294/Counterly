<!-- Statement Manager Module (embed this inside your page, base.css already loaded) -->
<section id="stmt-manager-module" class="stmt-manager">
  <div class="stmt-header">
    <div class="stmt-title">Statements — manager</div>
    <div class="stmt-controls">
      <input id="stmtSearch" class="stmt-search" placeholder="Search by filename, id..." />
      <button id="btnSearch" class="btn btn-ghost">Search</button>
      <button id="btnRefreshList" class="btn btn-ghost">Refresh</button>
      <div style="width:10px"></div>
      <button id="btnUploadCsv" class="btn btn-primary">Upload CSV</button>
    </div>
  </div>

  <div class="stmt-body">
    <div class="stmt-left">
      <div id="stmtList" class="stmt-list">Loading...</div>
      <div class="stmt-pager">
        <button id="prevPage" class="btn btn-ghost">Prev</button>
        <span id="pageInfo" class="small">Page 1</span>
        <button id="nextPage" class="btn btn-ghost">Next</button>
      </div>
    </div>

    <aside class="stmt-side">
      <div class="stmt-card">
        <div class="small muted">Auto-created counterparties (total)</div>
        <div id="sideCpCount" class="stmt-big">—</div>
        <div style="margin-top:10px">
          <button id="sideRefreshCp" class="btn btn-ghost">Refresh count</button>
        </div>
      </div>

      <div class="stmt-card" style="margin-top:12px">
        <div class="small muted">Quick tips</div>
        <ul class="small">
          <li>Upload only CSV produced by the converter.</li>
          <li>Use Refresh to detect data issues and optionally apply fixes.</li>
          <li>Download or view statement text from the list actions.</li>
        </ul>
      </div>
    </aside>
  </div>

  <!-- Hidden file input for CSV upload -->
  <input id="fileCsvInput" type="file" accept=".csv" style="display:none" />

  <!-- Modals -->
  <div id="modalOverlay" class="stm-modal" aria-hidden="true">
    <div class="stm-modal-card">
      <div class="stm-modal-header">
        <strong id="modalTitle">Modal</strong>
        <button id="modalClose" class="btn btn-ghost">Close</button>
      </div>
      <div id="modalBody" class="stm-modal-body"></div>
      <div class="stm-modal-actions" id="modalActions"></div>
    </div>
  </div>
</section>

<style>
/* Module-local styles only (no :root nor body rules) */
.stmt-manager { border-radius:10px; padding:12px; background: rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.03); display:block; box-sizing:border-box; }
.stmt-header { display:flex; align-items:center; gap:12px; margin-bottom:12px; }
.stmt-title { font-weight:800; font-size:16px; }
.stmt-controls { margin-left:auto; display:flex; gap:8px; align-items:center; }
.stmt-search { padding:8px; border-radius:8px; border:1px solid rgba(255,255,255,0.04); background:transparent; color:inherit; min-width:240px; }
.stmt-body { display:flex; gap:16px; align-items:flex-start; }
.stmt-left { flex:1; min-width:0; }
.stmt-list { max-height:420px; overflow:auto; border-radius:8px; border:1px solid rgba(255,255,255,0.02); padding:8px; }
.stmt-item { display:flex; align-items:center; justify-content:space-between; padding:10px; border-radius:8px; margin-bottom:8px; background:rgba(255,255,255,0.01); }
.stmt-meta { min-width:0; overflow:hidden; }
.stmt-fname { font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:480px; }
.stmt-actions { display:flex; gap:8px; align-items:center; }
.stmt-side { width:320px; max-width:36%; min-width:220px; }
.stmt-card { padding:12px; border-radius:8px; background:rgba(255,255,255,0.01); border:1px solid rgba(255,255,255,0.02); }
.small { font-size:13px; color:rgba(255,255,255,0.75); }
.muted { color:rgba(255,255,255,0.6); font-size:13px; }
.stmt-big { font-size:20px; font-weight:800; margin-top:6px; }
.btn { padding:8px 10px; border-radius:8px; border:0; cursor:pointer; font-weight:700; }
.btn-primary { background:linear-gradient(90deg,#ffa94d,#7c3aed); color:#081022; }
.btn-ghost { background:transparent; border:1px solid rgba(255,255,255,0.04); color:inherit; }
.stm-modal { position:fixed; left:0; top:0; right:0; bottom:0; display:none; align-items:center; justify-content:center; background:rgba(0,0,0,0.6); z-index:9999; }
.stm-modal.show { display:flex; }
.stm-modal-card { width:840px; max-width:96%; max-height:80vh; overflow:auto; background:#fff; color:#000; border-radius:8px; padding:16px; box-sizing:border-box; }
.stm-modal-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
.stm-modal-body { font-family:monospace; white-space:pre-wrap; max-height:60vh; overflow:auto; background:#f7f7f7; padding:12px; border-radius:6px; }
.stm-modal-actions { margin-top:12px; display:flex; gap:8px; justify-content:flex-end; }
.stmt-pager { display:flex; gap:8px; align-items:center; margin-top:8px; }
</style>

<script>
/* Statement Manager Module JS
   Expects:
     - API_URL global or change here
     - CSRF token injected as CSRF var
   Endpoints used (add to upload_api.php):
     GET  ?action=list_statements
     GET  ?action=search_statements&q=...
     GET  ?action=statement_metrics&sid=...
     POST ?action=rename_statement  (JSON: statement_id, filename, csrf_token)
     POST ?action=delete_statement  (JSON: statement_id, csrf_token)
     POST ?action=refresh_statement (JSON: statement_id, csrf_token, apply_fix)
     POST ?action=upload_csv (multipart/form-data) used by Upload CSV button
*/

const API_URL = (typeof API_URL !== 'undefined') ? API_URL : '/Assets/Website/Api/upload_api.php';
const CSRF_TOKEN = (typeof CSRF !== 'undefined') ? CSRF : '';

/* State */
let page = 1, pageSize = 25, lastQuery = '';

/* DOM refs */
const listEl = document.getElementById('stmtList');
const searchInput = document.getElementById('stmtSearch');
const btnSearch = document.getElementById('btnSearch');
const btnRefreshList = document.getElementById('btnRefreshList');
const btnUploadCsv = document.getElementById('btnUploadCsv');
const fileCsvInput = document.getElementById('fileCsvInput');
const modalOverlay = document.getElementById('modalOverlay');
const modalTitle = document.getElementById('modalTitle');
const modalBody = document.getElementById('modalBody');
const modalActions = document.getElementById('modalActions');
const modalClose = document.getElementById('modalClose');
const sideCpCount = document.getElementById('sideCpCount');
const sideRefreshCp = document.getElementById('sideRefreshCp');
const prevPage = document.getElementById('prevPage');
const nextPage = document.getElementById('nextPage');
const pageInfo = document.getElementById('pageInfo');

btnSearch.addEventListener('click', ()=> { lastQuery = searchInput.value.trim(); page = 1; loadList(); });
btnRefreshList.addEventListener('click', ()=> { lastQuery=''; searchInput.value=''; page=1; loadList(); });
btnUploadCsv.addEventListener('click', ()=> fileCsvInput.click());
fileCsvInput.addEventListener('change', handleCsvUpload);
modalClose.addEventListener('click', closeModal);
sideRefreshCp.addEventListener('click', loadCpCount);
prevPage.addEventListener('click', ()=> { if (page>1) { page--; loadList(); }});
nextPage.addEventListener('click', ()=> { page++; loadList(); });

function showModal(title, bodyHtml, actionsHtml){
  modalTitle.textContent = title;
  modalBody.innerHTML = bodyHtml || '';
  modalActions.innerHTML = actionsHtml || '';
  modalOverlay.classList.add('show');
  modalOverlay.setAttribute('aria-hidden','false');
}
function closeModal(){ modalOverlay.classList.remove('show'); modalOverlay.setAttribute('aria-hidden','true'); }

/* Helper fetch wrappers */
async function apiGet(params){
  const url = API_URL + '?' + new URLSearchParams(params).toString();
  const res = await fetch(url, { credentials:'include' });
  return res.json();
}
async function apiPostJson(params, obj){
  const url = API_URL + '?' + new URLSearchParams(params).toString();
  const res = await fetch(url, { method:'POST', credentials:'include', headers:{ 'Content-Type':'application/json' }, body: JSON.stringify(obj) });
  return res.json();
}

/* Load list (server-side paging — if you want server paging add params) */
async function loadList(){
  listEl.innerHTML = '<div class="small muted">Loading…</div>';
  try {
    let payload;
    if (lastQuery) payload = await apiGet({ action:'search_statements', q:lastQuery, page: page, page_size: pageSize });
    else payload = await apiGet({ action:'list_statements', page: page, page_size: pageSize });
    if (!payload || !payload.success) { listEl.innerHTML = '<div class="small muted">Failed to load</div>'; return; }
    const rows = payload.statements || [];
    if (rows.length === 0) { listEl.innerHTML = '<div class="small muted">No statements</div>'; pageInfo.textContent='Page ' + page; return; }
    renderList(rows);
    pageInfo.textContent = 'Page ' + page;
  } catch (err) {
    console.error(err);
    listEl.innerHTML = '<div class="small muted">Error loading</div>';
  }
}

/* Render statement rows */
function renderList(rows){
  listEl.innerHTML = '';
  for (const s of rows){
    const item = document.createElement('div'); item.className='stmt-item';
    const meta = document.createElement('div'); meta.className='stmt-meta';
    const fname = document.createElement('div'); fname.className='stmt-fname'; fname.textContent = (s.filename || '(no name)') + ' · id:' + s.id;
    const sub = document.createElement('div'); sub.className='small muted'; sub.textContent = (s.parse_status || '') + ' · ' + (s.tx_count ? s.tx_count + ' tx' : '');
    meta.appendChild(fname); meta.appendChild(sub);

    const actions = document.createElement('div'); actions.className='stmt-actions';
    // View / Download
    const btnView = mkBtn('View', ()=> viewStatement(s.id));
    const btnMetrics = mkBtn('Metrics', ()=> showMetrics(s.id));
    const btnRename = mkBtn('Rename', ()=> renameStatement(s.id, s.filename));
    const btnDelete = mkBtn('Delete', ()=> deleteStatementConfirm(s.id));
    const btnRefresh = mkBtn('Refresh', ()=> refreshStatementFlow(s.id));
    const btnDownload = document.createElement('a'); btnDownload.className='btn btn-ghost'; btnDownload.href = API_URL + '?action=download_statement&sid=' + encodeURIComponent(s.id); btnDownload.textContent = 'Download';

    actions.appendChild(btnView);
    actions.appendChild(btnMetrics);
    actions.appendChild(btnRefresh);
    actions.appendChild(btnRename);
    actions.appendChild(btnDelete);
    actions.appendChild(btnDownload);

    item.appendChild(meta);
    item.appendChild(actions);
    listEl.appendChild(item);
  }
}

function mkBtn(label, cb){
  const b = document.createElement('button'); b.className='btn btn-ghost'; b.textContent = label; b.addEventListener('click', cb); return b;
}

/* View statement text / details */
async function viewStatement(sid){
  try {
    const j = await apiGet({ action:'get_statement', sid: sid });
    if (!j.success) { alert('Failed to load'); return; }
    const text = j.statement_text || '(no text attached)';
    showModal('Statement ID ' + sid + ' — text', '<pre style="white-space:pre-wrap; font-family:monospace; max-height:60vh; overflow:auto;">' + escapeHtml(text) + '</pre>',
      '<button class="btn btn-ghost" onclick="closeModal()">Close</button>');
  } catch (err) {
    console.error(err); alert('Error');
  }
}

/* Statement metrics (transactions, counterparties count) */
async function showMetrics(sid){
  try {
    const j = await apiGet({ action:'statement_metrics', sid: sid });
    if (!j.success) { alert('Failed to get metrics'); return; }
    const html = '<div class="small"><strong>Statement ID:</strong> ' + sid + '</div>'
             + '<div class="small"><strong>Transactions:</strong> ' + (j.tx_count || 0) + '</div>'
             + '<div class="small"><strong>Distinct counterparties:</strong> ' + (j.counterparty_count || 0) + '</div>'
             + '<div style="margin-top:10px" class="small"><strong>Last parsed:</strong> ' + (j.parsed_at || '(n/a)') + '</div>';
    showModal('Metrics for ' + sid, html, '<button class="btn btn-ghost" onclick="closeModal()">Close</button>');
  } catch (err) { console.error(err); alert('Error'); }
}

/* Rename statement */
function renameStatement(sid, oldName){
  const newName = prompt('Rename statement (id ' + sid + ')', oldName || '');
  if (!newName) return;
  apiPostJson({ action:'rename_statement' }, { statement_id: sid, filename: newName, csrf_token: CSRF_TOKEN }).then(j=>{
    if (j.success) { loadList(); closeModal(); } else alert('Rename failed: ' + (j.error||'unknown'));
  }).catch(err=>{ console.error(err); alert('Error'); });
}

/* Delete statement */
function deleteStatementConfirm(sid){
  showModal('Confirm delete', 'Delete statement ID ' + sid + ' and its transactions? This cannot be undone.', 
    '<button class="btn btn-ghost" id="delCancel">Cancel</button><button class="btn btn-primary" id="delConfirm">Delete</button>');
  document.getElementById('delCancel').onclick = closeModal;
  document.getElementById('delConfirm').onclick = async () => {
    try {
      const j = await apiPostJson({ action:'delete_statement' }, { statement_id: sid, csrf_token: CSRF_TOKEN });
      if (j.success) { closeModal(); loadList(); loadCpCount(); } else alert('Delete failed: ' + (j.error||'unknown'));
    } catch (err) { console.error(err); alert('Error'); }
  };
}

/* Refresh statement flow:
   1) call refresh_statement with apply_fix=false to get issues
   2) show issues to user in modal and ask confirm to apply fixes
   3) if confirmed call with apply_fix=true to apply fixes (server will update rows)
*/
async function refreshStatementFlow(sid){
  showModal('Running checks', 'Checking statement for issues (this may take a few seconds)...', '<button class="btn btn-ghost" onclick="closeModal()">Close</button>');
  try {
    const j = await apiPostJson({ action:'refresh_statement' }, { statement_id: sid, csrf_token: CSRF_TOKEN, apply_fix: false });
    if (!j.success) { modalBody.innerHTML = 'Check failed: ' + (j.error || 'unknown'); modalActions.innerHTML = '<button class="btn btn-ghost" onclick="closeModal()">Close</button>'; return; }
    const issues = j.issues || [];
    if (issues.length === 0){
      modalBody.innerHTML = '<div class="small">No issues found. No action required.</div>';
      modalActions.innerHTML = '<button class="btn btn-ghost" onclick="closeModal()">Close</button>';
      return;
    }
    // Build friendly issues summary
    let html = '<div class="small">Detected ' + issues.length + ' potential problems. Preview (first 2000 chars):</div>';
    html += '<pre style="max-height:50vh; overflow:auto; white-space:pre-wrap;">' + escapeHtml(JSON.stringify(issues.slice(0,50), null, 2)) + '</pre>';
    html += '<div class="small">Apply the suggested fixes? (recommended if you trust the automated change)</div>';
    modalBody.innerHTML = html;
    modalActions.innerHTML = '<button class="btn btn-ghost" id="fixCancel">Cancel</button><button class="btn btn-primary" id="fixApply">Apply fixes</button>';
    document.getElementById('fixCancel').onclick = closeModal;
    document.getElementById('fixApply').onclick = async () => {
      modalBody.innerHTML = '<div class="small">Applying fixes (please wait)...</div>';
      modalActions.innerHTML = '';
      try {
        const j2 = await apiPostJson({ action:'refresh_statement' }, { statement_id: sid, csrf_token: CSRF_TOKEN, apply_fix: true });
        if (!j2.success) { modalBody.innerHTML = 'Apply failed: ' + (j2.error || 'unknown'); modalActions.innerHTML = '<button class="btn btn-ghost" onclick="closeModal()">Close</button>'; return; }
        modalBody.innerHTML = '<div class="small">Fixes applied.</div><pre style="max-height:50vh;overflow:auto">' + escapeHtml(JSON.stringify(j2.summary || {}, null, 2)) + '</pre>';
        modalActions.innerHTML = '<button class="btn btn-ghost" onclick="closeModal()">Close</button>';
        loadList();
        loadCpCount();
      } catch (err) { console.error(err); modalBody.innerHTML = 'Error applying fixes'; modalActions.innerHTML = '<button class="btn btn-ghost" onclick="closeModal()">Close</button>'; }
    };
  } catch (err) {
    console.error(err);
    modalBody.innerHTML = 'Error running check';
    modalActions.innerHTML = '<button class="btn btn-ghost" onclick="closeModal()">Close</button>';
  }
}

/* Upload CSV (simple) */
async function handleCsvUpload(ev){
  const f = ev.target.files[0];
  if (!f) return;
  if (!f.name.toLowerCase().endsWith('.csv')) { alert('Please select a .csv file'); return; }
  if (!confirm('Upload CSV and parse now?')) return;
  const fd = new FormData();
  fd.append('csv_file', f);
  fd.append('filename', f.name);
  fd.append('csrf_token', CSRF_TOKEN);
  try {
    const resp = await fetch(API_URL + '?action=upload_csv', { method:'POST', credentials:'include', body: fd });
    const j = await resp.json();
    if (j.success) { alert('Uploaded'); loadList(); loadCpCount(); } else { alert('Upload failed: ' + (j.error||'unknown')); }
  } catch (err) { console.error(err); alert('Upload error'); }
  fileCsvInput.value='';
}

/* Load cp count for side panel */
async function loadCpCount(){
  sideCpCount.textContent = '…';
  try {
    const j = await apiGet({ action:'get_groups' });
    if (j && j.success) sideCpCount.textContent = (j.counterparties || []).length;
    else sideCpCount.textContent = '–';
  } catch (err) { sideCpCount.textContent = '–'; }
}

/* Utilities */
function escapeHtml(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

/* Initialize */
loadList(); loadCpCount();
</script>
