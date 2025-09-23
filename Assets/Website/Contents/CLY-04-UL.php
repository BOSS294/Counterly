<?php
// upload_ui.php
// Single-page UI: HTML + CSS + JS only. Talks to upload_api.php via fetch.
// Requires logged-in session. Generates CSRF token.

session_start();
if (!isset($_SESSION['user_id'])) {
    // Lightweight UI when not logged in
    http_response_code(401);
    echo "<!doctype html><html><body><h2>Not authenticated</h2><p>Please login to use the uploader.</p></body></html>";
    exit;
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];
$userId = intval($_SESSION['user_id']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Upload Bank Statement — UI</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<style>
/* compact theme adapted from your base.css */
:root{--bg:#0b0b0b;--text:#e7e7e7;--muted:rgba(231,231,231,0.54);--accent:#ffa94d}
body{margin:0;font-family:DM Sans,system-ui,Arial;background:var(--bg);color:var(--text);padding:24px}
.container{max-width:1200px;margin:0 auto}
.grid{display:grid;grid-template-columns:1fr 420px;gap:24px}
@media(max-width:980px){.grid{grid-template-columns:1fr}}
.card{background:rgba(255,255,255,0.03);padding:18px;border-radius:12px;border:1px solid rgba(255,255,255,0.04)}
.dropzone{border:2px dashed rgba(255,255,255,0.06);padding:22px;border-radius:10px;display:flex;flex-direction:column;gap:10px;align-items:center;cursor:pointer}
.dropzone.dragover{border-color:rgba(255,169,77,0.8);background:rgba(255,169,77,0.02)}
.controls{display:flex;gap:10px;margin-top:12px;align-items:center}
.file-list{margin-top:12px;display:flex;flex-direction:column;gap:10px}
.file-row{display:flex;gap:12px;align-items:center;justify-content:space-between;padding:10px;border-radius:10px;background:rgba(255,255,255,0.01)}
.file-progress{width:240px;height:10px;background:rgba(255,255,255,0.02);border-radius:6px;overflow:hidden}
.file-progress > i{display:block;height:100%;width:0%;background:linear-gradient(90deg,#ffa94d,#7c3aed)}
.file-status{font-size:13px;color:var(--muted);min-width:140px;text-align:right}
.btn{padding:10px 14px;border-radius:10px;cursor:pointer;border:0;font-weight:800}
.btn-primary{background:linear-gradient(90deg, rgba(255,169,77,0.12), rgba(124,58,237,0.08));border:1px solid rgba(255,169,77,0.06)}
.btn-ghost{background:transparent;border:1px solid rgba(255,255,255,0.04);color:var(--muted)}
.small{font-size:13px;color:var(--muted)}
.steps-log{margin-top:12px;font-size:13px;color:var(--muted);min-height:48px}
a.link{color:#A3BFFA;text-decoration:none}
</style>
</head>
<body>
<div class="container">
  <div class="grid">
    <div class="card">
      <h2>Upload HDFC PDF Statements</h2>
      <p class="small">Files are stored only for your account. We keep SHA256 checksum to detect duplicates. Max 20MB / PDF only.</p>
      <ul class="small">
        <li>Drag & drop or browse PDFs.</li>
        <li>Parsing runs on server; progress shown per file.</li>
        <li>Groups are auto-created when a counterparty appears &ge; 2 times.</li>
      </ul>
      <div style="margin-top:12px" class="small">Support: <a class="link" href="/app/help.php">Help Center</a> • <a class="link" href="/app/privacy.php">Privacy Policy</a></div>
    </div>

    <div class="card">
      <div id="dropzone" class="dropzone" tabindex="0">
        <i class='bx bx-file'></i>
        <p id="dropText">Drag & drop PDF files here or click to browse</p>
        <small class="small">Multiple files allowed — parsed one-by-one.</small>
      </div>

      <div class="controls">
        <label class="small"><input type="checkbox" id="compressPdf"> Compress on server</label>
        <div style="flex:1"></div>
        <button id="startUpload" class="btn btn-primary">Start upload</button>
        <button id="clearFiles" class="btn btn-ghost">Clear</button>
      </div>

      <div class="file-list" id="fileList"></div>
      <div class="steps-log" id="stepsLog">No uploads yet.</div>
    </div>
  </div>
</div>

<script>
const API_URL = '/Assets/Website/Api/upload_api.php'; 
const CSRF_TOKEN = <?php echo json_encode($csrf); ?>;
const MAX_BYTES = 20 * 1024 * 1024;
const dropzone = document.getElementById('dropzone');
const fileListEl = document.getElementById('fileList');
const startBtn = document.getElementById('startUpload');
const clearBtn = document.getElementById('clearFiles');
const stepsLog = document.getElementById('stepsLog');
const compressCheckbox = document.getElementById('compressPdf');
let filesQueue = [];

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
        <div style="width:8px;"></div>
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

startBtn.addEventListener('click', async ()=>{
  if(filesQueue.length === 0) return alert('No files selected');
  startBtn.disabled = true; clearBtn.disabled = true; setSteps('Starting uploads...');
  for(let i=0;i<filesQueue.length;i++){
    const item = filesQueue[i];
    document.getElementById('status'+i).textContent = 'Uploading...';
    await uploadFile(item.file, i);
  }
  setSteps('All uploads finished. Check Statements page.');
  startBtn.disabled = false; clearBtn.disabled = false;
});

function updateProgress(idx, pct, status){
  const prog = document.querySelector('#prog'+idx+' i'); if(prog) prog.style.width = pct + '%';
  const st = document.getElementById('status'+idx); if(st) st.textContent = status;
}

async function uploadFile(file, idx){
  return new Promise((resolve) => {
    const xhr = new XMLHttpRequest();
    const fd = new FormData();
    fd.append('statement_pdf', file);
    fd.append('compress', compressCheckbox.checked ? '1' : '0');
    fd.append('csrf_token', CSRF_TOKEN);

    xhr.open('POST', API_URL + '?action=upload', true);
    xhr.withCredentials = true;

    xhr.upload.onprogress = function(e){ if(e.lengthComputable){ const pct = Math.round((e.loaded / e.total) * 100); updateProgress(idx, pct, 'Uploading ' + pct + '%'); } };

    xhr.onload = function(){
      if(xhr.status >=200 && xhr.status < 300){
        try{
          const res = JSON.parse(xhr.responseText);
          if(res.success){
            document.getElementById('status'+idx).textContent = 'Uploaded — Parsing';
            pollParseStatus(res.statement_id, idx).then(()=> resolve());
          } else {
            updateProgress(idx, 0, 'Error: ' + (res.error || 'unknown'));
            resolve();
          }
        }catch(err){ updateProgress(idx, 0, 'Invalid server response'); resolve(); }
      } else {
        updateProgress(idx, 0, 'Upload failed: ' + xhr.status); resolve(); }
    };

    xhr.onerror = function(){ updateProgress(idx,0,'Network error'); resolve(); };
    xhr.send(fd);
  });
}

async function pollParseStatus(statementId, idx){
  const statusEl = document.getElementById('status'+idx);
  statusEl.textContent = 'Queued for parsing...';
  return new Promise((resolve)=>{
    const iv = setInterval(async ()=>{
      try{
        const resp = await fetch(API_URL + '?action=status&sid=' + encodeURIComponent(statementId), { credentials: 'include' });
        if(resp.ok){
          const j = await resp.json();
          if(j.success){
            if(j.parse_status === 'parsed'){
              statusEl.textContent = 'Parsed ✓';
              clearInterval(iv); resolve();
            } else if(j.parse_status === 'error'){
              statusEl.textContent = 'Error parsing';
              clearInterval(iv); resolve();
            } else {
              statusEl.textContent = (j.parse_status || 'parsing') + '...';
            }
          } else {
            // server returned auth or other error
            statusEl.textContent = 'Error: ' + (j.error || 'unknown');
            clearInterval(iv); resolve();
          }
        }
      }catch(e){
        // ignore transient
      }
    }, 2000);
    // safety timeout for UI polling
    setTimeout(()=>{ clearInterval(iv); resolve(); }, 120000);
  });
}
</script>
</body>
</html>
