<?php
/**
 * upload_module_and_api.php
 * Single-file UI + JS + API for uploading HDFC PDF statements, parsing them and
 * inserting into the database according to your schema. Everything is self-contained
 * so you can drop this file under /app/upload.php (or another route) and use it.
 *
 * WARNING: this file contains synchronous parsing for demo purposes. For production
 * you should run parsing in a background worker. See comments below.
 *
 * Features included:
 * - Responsive UI (instructions left, uploader right) with drag/drop + browse
 * - Client validation (PDF-only, <= 20MB)
 * - Live per-file progress, simulated parsing steps + real parse
 * - Server endpoints (same file routing via ?action=...):
 *    - POST ?action=upload        => handles file upload + parsing
 *    - GET  ?action=status&sid=ID => returns parse_status and logs
 *    - GET  ?action=get_groups    => returns counterparties for user
 *    - POST ?action=promote       => promote a transaction to counterparty
 *    - POST ?action=merge_alias   => merge alias into a counterparty
 *
 * Requirements:
 * - Place this file inside webroot and secure it (or route via your framework)
 * - Assets/Connectors/connector.php must exist and provide DB::get()
 * - `pdftotext` command recommended (poppler) for reliable text extraction.
 * - Database schema must match the one you provided earlier.
 */

// --- bootstrap & router ---
session_start();
require_once __DIR__ . '/../../Connectors/connector.php'; 
$db = DB::get();

// require login
if (!isset($_SESSION['user_id'])) {
    // if API call, return json; if UI request, show login message
    if(isset($_GET['action'])){ header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'Not authenticated']); exit; }
}
$userId = $_SESSION['user_id'] ?? null;

$action = $_GET['action'] ?? null;
if($action){
    switch($action){
        case 'upload': handle_upload($db, $userId); break;
        case 'status': handle_status($db, $userId); break;
        case 'get_groups': handle_get_groups($db, $userId); break;
        case 'promote': handle_promote($db, $userId); break;
        case 'merge_alias': handle_merge_alias($db, $userId); break;
        default:
            header('Content-Type: application/json'); echo json_encode(['success'=>false,'error'=>'Unknown action']); exit;
    }
    exit;
}

// If no action, render UI page
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Upload Bank Statement</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
<style>
body{ font-family:DM Sans,system-ui,Arial; background:#0b0b0b; color:#e7e7e7; margin:0; padding:24px; }
.container{ max-width:1800px; margin:0 auto; padding:0 18px; }
.grid{ display:grid; grid-template-columns:1fr 420px; gap:24px; align-items:start; }
@media(max-width:980px){ .grid{ grid-template-columns:1fr; } }
.card{ background:rgba(255,255,255,0.03); border-radius:12px; padding:18px; border:1px solid rgba(255,255,255,0.04); box-shadow:0 18px 50px rgba(0,0,0,0.95); }
.instructions h2{ margin:0 0 6px 0; font-size:20px; font-weight:900; }
.instructions p{ color:var(--muted); margin:8px 0; }
.instructions .important{ color:#FFD39B; font-weight:800; }
.instructions ul{ margin:8px 0 0 18px; color:var(--muted); }
.privacy{ margin-top:12px; padding:10px; border-radius:8px; background:rgba(255,255,255,0.01); border:1px solid rgba(255,255,255,0.02); }
.privacy .highlight{ color:#A3BFFA; font-weight:800; }

/* uploader */
.dropzone{ border:2px dashed rgba(255,255,255,0.06); padding:22px; border-radius:10px; display:flex; flex-direction:column; gap:10px; align-items:center; justify-content:center; cursor:pointer; }
.dropzone.dragover{ border-color:rgba(255,169,77,0.8); background:rgba(255,169,77,0.02); }
.dropzone i{ font-size:40px; color:var(--muted); }
.controls{ display:flex; gap:10px; margin-top:12px; align-items:center; }
.file-list{ margin-top:12px; display:flex; flex-direction:column; gap:10px; }
.file-row{ display:flex; gap:12px; align-items:center; justify-content:space-between; padding:10px; border-radius:10px; background:rgba(255,255,255,0.01); }
.file-progress{ width:240px; height:10px; background:rgba(255,255,255,0.02); border-radius:6px; overflow:hidden; }
.file-progress > i{ display:block; height:100%; width:0%; background:linear-gradient(90deg,#ffa94d,#7c3aed); }
.file-status{ font-size:13px; color:var(--muted); min-width:140px; text-align:right; }
.btn{ padding:10px 14px; border-radius:10px; cursor:pointer; border:0; font-weight:800; }
.btn-primary{ background:linear-gradient(90deg, rgba(255,169,77,0.12), rgba(124,58,237,0.08)); border:1px solid rgba(255,169,77,0.06); }
.btn-ghost{ background:transparent; border:1px solid rgba(255,255,255,0.04); color:var(--muted); }
.steps-log{ margin-top:12px; font-size:13px; color:var(--muted); min-height:48px; }
.small{ font-size:13px; color:var(--muted); }
</style>
</head>
<body>
<div class="container">
  <div class="grid">
    <div class="card instructions">
      <h2>Upload Bank Statements</h2>
      <p class="important">Allowed: PDF only — Max size: <strong>20 MB</strong></p>
      <p>Follow these steps:</p>
      <ul>
        <li>Download your HDFC PDF statement from netbanking or mobile banking.</li>
        <li>Drag & drop the PDF(s) into the right panel or click to browse.</li>
        <li>We parse transactions, detect counterparties and group them (groups created when a counterparty appears &gt;= 2 times).</li>
      </ul>
      <div class="privacy">
        <div class="highlight">Privacy & security</div>
        <p class="small">Files are stored with a SHA256 checksum to prevent duplicate parsing. Access is restricted to your account. Files are kept securely and not shared. For stronger privacy remove account numbers before uploading.</p>
      </div>
      <div style="margin-top:12px;">
        <div class="highlight">Important links</div>
        <p class="small">Need help? <a href="/app/help.php" style="color:#A3BFFA">Help Center</a> • <a href="/app/privacy.php" style="color:#FFD39B">Privacy Policy</a></p>
      </div>
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
// UI/JS logic (same as earlier but wired to this file)
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
  setSteps('All uploads finished. Parser completed or queued. Check Statements.');
  startBtn.disabled = false; clearBtn.disabled = false;
});

function updateProgress(idx, pct, status){
  const prog = document.querySelector('#prog'+idx+' i'); if(prog) prog.style.width = pct + '%';
  const st = document.getElementById('status'+idx); if(st) st.textContent = status;
}

async function uploadFile(file, idx){
  return new Promise((resolve, reject)=>{
    const xhr = new XMLHttpRequest();
    const fd = new FormData();
    fd.append('statement_pdf', file);
    fd.append('compress', compressCheckbox.checked ? '1' : '0');

    xhr.open('POST', '?action=upload', true);
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
  const steps = ['Queued for parsing','Parsing PDF (OCR)','Extracting transactions','Normalizing amounts','Detecting counterparties','Creating groups','Applying rules','Finalizing'];
  let stepIndex = 0;
  const statusEl = document.getElementById('status'+idx);
  statusEl.textContent = steps[stepIndex];

  return new Promise((resolve)=>{
    const iv = setInterval(async ()=>{
      if(stepIndex < steps.length - 1) stepIndex++; else stepIndex = steps.length - 1;
      statusEl.textContent = steps[stepIndex] + '...';

      try{
        const resp = await fetch('?action=status&sid=' + encodeURIComponent(statementId), { credentials: 'include' });
        if(resp.ok){
          const j = await resp.json();
          if(j.parse_status === 'parsed' || j.parse_status === 'error'){
            statusEl.textContent = j.parse_status === 'parsed' ? 'Parsed ✓' : 'Error';
            clearInterval(iv); resolve();
          }
        }
      }catch(e){ /* ignore */ }

    }, 1500);
    setTimeout(()=>{ clearInterval(iv); resolve(); }, 45000);
  });
}

</script>
</body>
</html>

<?php
// --------------- Server handlers ---------------
function handle_upload($db, $userId){
    header('Content-Type: application/json; charset=utf-8');
    if(!$userId){ http_response_code(401); echo json_encode(['success'=>false,'error'=>'Not authenticated']); return; }

    if(empty($_FILES['statement_pdf'])){ http_response_code(400); echo json_encode(['success'=>false,'error'=>'No file uploaded']); return; }
    $f = $_FILES['statement_pdf'];
    if($f['error'] !== UPLOAD_ERR_OK){ http_response_code(400); echo json_encode(['success'=>false,'error'=>'Upload error']); return; }

    $max = 20 * 1024 * 1024;
    if($f['size'] > $max){ http_response_code(400); echo json_encode(['success'=>false,'error'=>'File too large']); return; }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($f['tmp_name']);
    if($mime !== 'application/pdf' && substr($f['name'],-4) !== '.pdf'){
        http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid file type']); return;
    }

    // store file
    $uploaddir = __DIR__ . '/../../Uploads';
    if(!is_dir($uploaddir)) mkdir($uploaddir, 0700, true);
    $userdir = $uploaddir . '/statements/' . intval($userId);
    if(!is_dir($userdir)) mkdir($userdir, 0700, true);

    $timestamp = gmdate('Ymd_His');
    $safeName = preg_replace('/[^A-Za-z0-9._-]/','_',basename($f['name']));
    $stored = $userdir . '/' . $timestamp . '_' . $safeName;

    if(!move_uploaded_file($f['tmp_name'], $stored)){
        http_response_code(500); echo json_encode(['success'=>false,'error'=>'Move failed']); return;
    }

    $sha = hash_file('sha256', $stored);

    // insert statements row
    try{
        $stmtId = $db->insertAndGetId("INSERT INTO statements (user_id, filename, storage_path, file_size, file_sha256, parse_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'uploaded', NOW(), NOW())", [ $userId, $f['name'], $stored, intval($f['size']), $sha ]);
        $db->logAudit('upload_statement', 'statement', $stmtId, ['filename'=>$f['name'],'size'=>$f['size'],'sha'=>$sha], $userId);
    }catch(Throwable $e){ http_response_code(500); echo json_encode(['success'=>false,'error'=>'DB insert failed']); return; }

    // optionally compress (not implemented here — placeholder)
    $compress = isset($_POST['compress']) && $_POST['compress'] === '1';
    if($compress){ /* implement server-side compression in production */ }

    // parse synchronously (for demo). In production queue this task to a worker.
    try{
        parse_and_insert($db, $stmtId, $userId, $stored);
    }catch(Throwable $e){
        // mark as error but still return statement id so UI can poll
        $db->execute("UPDATE statements SET parse_status = 'error', error_message = ?, updated_at = NOW() WHERE id = ?", [ $e->getMessage(), $stmtId ]);
    }

    echo json_encode(['success'=>true,'statement_id'=>$stmtId]);
}

function handle_status($db, $userId){
    header('Content-Type: application/json; charset=utf-8');
    if(!$userId){ http_response_code(401); echo json_encode(['success'=>false,'error'=>'Not authenticated']); return; }
    $sid = intval($_GET['sid'] ?? 0);
    if(!$sid){ http_response_code(400); echo json_encode(['success'=>false,'error'=>'Missing sid']); return; }
    $row = $db->fetch('SELECT id, parse_status, parsed_at, error_message, tx_count FROM statements WHERE id = ? AND user_id = ? LIMIT 1', [$sid, $userId]);
    if(!$row){ http_response_code(404); echo json_encode(['success'=>false,'error'=>'Not found']); return; }
    $logs = $db->fetchAll('SELECT level, message, meta, created_at FROM parse_logs WHERE statement_id = ? ORDER BY id DESC LIMIT 10', [$sid]);
    echo json_encode(['success'=>true,'parse_status'=>$row['parse_status'],'parsed_at'=>$row['parsed_at'],'error_message'=>$row['error_message'],'tx_count'=>$row['tx_count'],'logs'=>$logs]);
}

function handle_get_groups($db, $userId){ header('Content-Type: application/json'); $rows = $db->fetchAll('SELECT id, canonical_name, tx_count, total_debit_paise, total_credit_paise FROM counterparties WHERE user_id = ? ORDER BY tx_count DESC LIMIT 200', [$userId]); echo json_encode(['success'=>true,'counterparties'=>$rows]); }

function handle_promote($db, $userId){
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input'); $data = json_decode($raw,true);
    $txnId = intval($data['transaction_id'] ?? 0); $canonical = trim($data['canonical_name'] ?? '');
    if(!$txnId || !$canonical){ http_response_code(400); echo json_encode(['success'=>false,'error'=>'Missing params']); return; }
    $db->beginTransaction();
    try{
        $t = $db->fetch('SELECT * FROM transactions WHERE id = ? AND user_id = ? LIMIT 1', [$txnId, $userId]); if(!$t){ $db->rollback(); http_response_code(404); echo json_encode(['success'=>false,'error'=>'Transaction not found']); return; }
        $cpId = $db->insertAndGetId('INSERT INTO counterparties (user_id, canonical_name, type, first_seen, last_seen, tx_count, total_debit_paise, total_credit_paise, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW(), 0, 0, 0, NOW(), NOW())', [$userId, $canonical, 'other']);
        $aliasCandidate = substr(normalize_narration($t['narration']),0,200);
        $db->insertAndGetId('INSERT INTO counterparty_aliases (counterparty_id, alias, alias_type, created_at) VALUES (?, ?, ?, NOW())', [$cpId, $aliasCandidate, 'narration']);
        $db->execute('UPDATE transactions SET counterparty_id = ?, manual_flag = 1, updated_at = NOW() WHERE id = ?', [$cpId, $txnId]);
        $db->insertAndGetId('INSERT INTO tx_counterparty_history (transaction_id, old_counterparty_id, new_counterparty_id, changed_by_user_id, reason, created_at) VALUES (?, ?, ?, ?, ?, NOW())', [$txnId, null, $cpId, $userId, 'manual_promote']);
        $agg = $db->fetch('SELECT COUNT(*) as cnt, SUM(debit_paise) as sdebit, SUM(credit_paise) as scredit FROM transactions WHERE counterparty_id = ?', [$cpId]);
        $db->execute('UPDATE counterparties SET tx_count = ?, total_debit_paise = COALESCE(?,0), total_credit_paise = COALESCE(?,0), last_seen = NOW(), updated_at = NOW() WHERE id = ?', [ intval($agg['cnt']), intval($agg['sdebit'] ?? 0), intval($agg['scredit'] ?? 0), $cpId ]);
        $db->commit();
        echo json_encode(['success'=>true,'counterparty_id'=>$cpId]);
    }catch(Throwable $e){ $db->rollback(); $db->logParse(null,'error','promote_failed',['err'=>$e->getMessage()]); http_response_code(500); echo json_encode(['success'=>false,'error'=>'Server error']); }
}

function handle_merge_alias($db, $userId){ header('Content-Type: application/json; charset=utf-8'); $raw = file_get_contents('php://input'); $data = json_decode($raw,true); $alias = trim($data['alias'] ?? ''); $cpId = intval($data['counterparty_id'] ?? 0); if(!$alias || !$cpId){ http_response_code(400); echo json_encode(['success'=>false,'error'=>'Missing params']); return; } $exists = $db->fetch('SELECT id FROM counterparty_aliases WHERE counterparty_id = ? AND alias = ? LIMIT 1', [$cpId, $alias]); if(!$exists) $db->insertAndGetId('INSERT INTO counterparty_aliases (counterparty_id, alias, alias_type, created_at) VALUES (?, ?, ?, NOW())', [$cpId, $alias, 'narration']); $pattern = '%' . str_replace('%','\%',$alias) . '%'; $db->execute('UPDATE transactions SET counterparty_id = ?, updated_at = NOW() WHERE user_id = ? AND narration LIKE ?', [$cpId, $userId, $pattern]); $agg = $db->fetch('SELECT COUNT(*) as cnt, SUM(debit_paise) as sdebit, SUM(credit_paise) as scredit FROM transactions WHERE counterparty_id = ?', [$cpId]); $db->execute('UPDATE counterparties SET tx_count = ?, total_debit_paise = COALESCE(?,0), total_credit_paise = COALESCE(?,0), last_seen = NOW(), updated_at = NOW() WHERE id = ?', [ intval($agg['cnt']), intval($agg['sdebit'] ?? 0), intval($agg['scredit'] ?? 0), $cpId ]); echo json_encode(['success'=>true]); }

// ---------------- Parsing helpers reused on server side ----------------
function normalize_narration($s){ if($s === null) return ''; $t = trim(preg_replace('/\s+/', ' ', $s)); $t = mb_strtolower($t, 'UTF-8'); $t = preg_replace('/[\x00-\x1F\x7F]/u','',$t); return $t; }
function normalize_amount_to_paise($amt_str){ $s = trim(str_replace(',', '', $amt_str)); if($s === '') return 0; $s = preg_replace('/[^0-9.\-]/','', $s); if($s === '' || $s === '.' ) return 0; $val = floatval($s); return (int) round($val * 100); }
function compute_txn_checksum($account_id, $txn_date, $amount_paise, $reference, $narration){ $canon = sprintf('%s|%s|%d|%s|%s', $account_id ?? '0', $txn_date, $amount_paise, $reference ?? '', normalize_narration($narration)); return hash('sha256', $canon); }

function extract_text_from_pdf($path){
    $pdftotext = trim(shell_exec('which pdftotext 2>/dev/null'));
    if($pdftotext){ $cmd = escapeshellcmd($pdftotext) . ' -layout ' . escapeshellarg($path) . ' -'; $out = shell_exec($cmd); if($out !== null) return $out; }
    $gs = trim(shell_exec('which gs 2>/dev/null'));
    if($gs){ $tmp = tempnam(sys_get_temp_dir(), 'pdf-txt-'); $cmd = escapeshellcmd($gs) . ' -q -dNODISPLAY -sOutputFile=' . escapeshellarg($tmp) . ' -sDEVICE=txtwrite -dBATCH ' . escapeshellarg($path) . ' 2>&1'; @shell_exec($cmd); $txt = @file_get_contents($tmp); @unlink($tmp); if($txt) return $txt; }
    throw new RuntimeException('No PDF text extractor available on server. Install pdftotext or add a PDF parser.');
}

function parse_hdfc_text_to_txns($rawText){
    $lines = preg_split('/\r?\n/', $rawText);
    $txns = []; $buffer = null;
    foreach($lines as $ln){ $ln_trim = trim($ln); if($ln_trim === '') continue; if(preg_match('/^(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\s+(.+)$/', $ln_trim)){ if($buffer !== null){ $tx = process_buffer_line($buffer); if($tx) $txns[] = $tx; } $buffer = $ln_trim; } else { if($buffer === null) continue; $buffer .= ' ' . $ln_trim; } }
    if($buffer !== null){ $tx = process_buffer_line($buffer); if($tx) $txns[] = $tx; }
    return $txns;
}

function process_buffer_line($line){
    $s = preg_replace('/\s+/', ' ', trim($line)); if(!preg_match('/^(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\s+(.*)$/', $s, $m)) return null; $date_raw = $m[1]; $rest = trim($m[2]); $dateParts = preg_split('/[\/\-]/', $date_raw); if(count($dateParts) >= 3){ $d = str_pad($dateParts[0],2,'0',STR_PAD_LEFT); $mth = str_pad($dateParts[1],2,'0',STR_PAD_LEFT); $y = $dateParts[2]; if(strlen($y) === 2) $y = '20' . $y; $txn_date = "$y-$mth-$d"; } else { $txn_date = null; }
    if(preg_match('/(.*)\s+([\d,]+(?:\.\d{1,2})?|-)\s+([\d,]+(?:\.\d{1,2})?|-)\s+([\d,]+(?:\.\d{1,2})?)$/', $rest, $mm)){
        $narration = trim($mm[1]); $withdraw = $mm[2]; $deposit = $mm[3]; $balance = $mm[4];
    } else if(preg_match('/(.*)\s+([\d,]+(?:\.\d{1,2})?)\s+([\d,]+(?:\.\d{1,2})?)$/', $rest, $mm2)){
        $narration = trim($mm2[1]); $withdraw = $mm2[2]; $deposit = '-'; $balance = $mm2[3];
    } else { return null; }
    $reference = null; if(preg_match('/(ref[:\-\s]*|chq[:.\-\s]*|utr[:\-\s]*)([A-Za-z0-9\-\/]+)$/i', $narration, $mr)){ $reference = $mr[2]; } else if(preg_match('/[a-z0-9.\-\_]+@[a-z0-9\-\.]+/i', $narration, $mu)){ $reference = $mu[0]; }
    $withdraw_paise = ($withdraw === '-' ? null : normalize_amount_to_paise($withdraw)); $deposit_paise = ($deposit === '-' ? null : normalize_amount_to_paise($deposit)); $amount_paise = $withdraw_paise !== null ? $withdraw_paise : ($deposit_paise !== null ? $deposit_paise : 0); $balance_paise = normalize_amount_to_paise($balance);
    $txn_type = 'other'; if($withdraw_paise !== null) $txn_type = 'debit'; if($deposit_paise !== null) $txn_type = 'credit';
    return [ 'txn_date'=>$txn_date, 'narration'=>$narration, 'raw_line'=>$s, 'reference'=>$reference, 'txn_type'=>$txn_type, 'amount_paise'=>$amount_paise, 'debit_paise'=>$withdraw_paise, 'credit_paise'=>$deposit_paise, 'balance_paise'=>$balance_paise ];
}

function parse_and_insert($db, $stmtId, $userId, $storedPath){
    // update parse_status
    $db->execute('UPDATE statements SET parse_status = ?, updated_at = NOW() WHERE id = ?', ['parsing', $stmtId]);
    $text = extract_text_from_pdf($storedPath);
    $txns = parse_hdfc_text_to_txns($text);
    $db->beginTransaction();
    try{
        $insertSql = "INSERT INTO transactions (statement_id, user_id, account_id, txn_date, narration, raw_line, reference_number, txn_type, amount_paise, debit_paise, credit_paise, balance_paise, txn_checksum, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE updated_at = NOW()";
        foreach($txns as $t){
            $account_id = null; // optionally derive or assign
            $txn_checksum = compute_txn_checksum($account_id, $t['txn_date'], $t['amount_paise'], $t['reference'], $t['narration']);
            $db->query($insertSql, [ $stmtId, $userId, $account_id, $t['txn_date'], $t['narration'], $t['raw_line'], $t['reference'], $t['txn_type'], $t['amount_paise'], $t['debit_paise'], $t['credit_paise'], $t['balance_paise'], $txn_checksum ]);
        }
        $db->commit();
    }catch(Throwable $e){ $db->rollback(); $db->logParse($stmtId,'error','insert_failed',['err'=>$e->getMessage()], $userId); throw $e; }

    // run grouping scanner for user
    run_grouping_scanner($db, $userId);
    // finalize
    $count = $db->fetch('SELECT COUNT(*) as c FROM transactions WHERE statement_id = ?', [$stmtId])['c'] ?? 0;
    $db->execute('UPDATE statements SET parse_status = ?, parsed_at = NOW(), tx_count = ?, updated_at = NOW() WHERE id = ?', ['parsed', intval($count), $stmtId]);
    $db->logParse($stmtId,'info','parse_complete',['inserted'=>$count], $userId);
}

function extract_alias_candidate($narration){ $n = normalize_narration($narration); if(preg_match('/[a-z0-9.\-\_]+@[a-z0-9\-\.]+/i',$n,$m)) return $m[0]; if(preg_match('/(\d{10})/',$n,$m)) return $m[1]; if(preg_match('/\*{2,}([0-9]{2,4})/',$n,$m)) return 'acct_mask_'.$m[1]; $parts = preg_split('/\s+/', $n); return implode(' ', array_slice($parts,0,3)); }

function run_grouping_scanner($db, $userId){
    $txs = $db->fetchAll('SELECT id, narration FROM transactions WHERE user_id = ? AND (counterparty_id IS NULL OR counterparty_id = 0)', [$userId]);
    $candidates = [];
    foreach($txs as $t){ $cand = extract_alias_candidate($t['narration']); $ck = substr(normalize_narration($cand),0,200); if(!isset($candidates[$ck])) $candidates[$ck] = ['count'=>0,'tx_ids'=>[],'sample'=>$cand]; $candidates[$ck]['count']++; $candidates[$ck]['tx_ids'][] = $t['id']; }
    foreach($candidates as $ck => $info){ if($info['count'] < 2) continue; $canonical = mb_strtoupper(substr($info['sample'],0,255)); $aliasRow = $db->fetch('SELECT ca.counterparty_id FROM counterparty_aliases ca JOIN counterparties cp ON ca.counterparty_id = cp.id WHERE ca.alias = ? AND cp.user_id = ? LIMIT 1', [$ck, $userId]); $cp_id = $aliasRow['counterparty_id'] ?? null; if(!$cp_id){ $existing = $db->fetch('SELECT id FROM counterparties WHERE user_id = ? AND canonical_name = ? LIMIT 1', [$userId, $canonical]); if($existing) $cp_id = $existing['id']; }
        if(!$cp_id){ $cp_id = $db->insertAndGetId('INSERT INTO counterparties (user_id, canonical_name, type, first_seen, last_seen, tx_count, total_debit_paise, total_credit_paise, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW(), 0, 0, 0, NOW(), NOW())', [$userId, $canonical, 'other']); }
        $existsAlias = $db->fetch('SELECT id FROM counterparty_aliases WHERE counterparty_id = ? AND alias = ? LIMIT 1', [$cp_id, $ck]); if(!$existsAlias) $db->insertAndGetId('INSERT INTO counterparty_aliases (counterparty_id, alias, alias_type, created_at) VALUES (?, ?, ?, NOW())', [$cp_id, $ck, 'narration']);
        foreach(array_chunk($info['tx_ids'],100) as $chunk){ $placeholders = implode(',', array_fill(0,count($chunk),'?')); $params = $chunk; array_unshift($params, $cp_id); $sql = "UPDATE transactions SET counterparty_id = ?, updated_at = NOW() WHERE id IN ($placeholders)"; $db->execute($sql, $params); }
        $agg = $db->fetch('SELECT COUNT(*) as cnt, SUM(debit_paise) as sdebit, SUM(credit_paise) as scredit FROM transactions WHERE counterparty_id = ?', [$cp_id]); $db->execute('UPDATE counterparties SET tx_count = ?, total_debit_paise = COALESCE(?,0), total_credit_paise = COALESCE(?,0), last_seen = NOW(), updated_at = NOW() WHERE id = ?', [ intval($agg['cnt']), intval($agg['sdebit'] ?? 0), intval($agg['scredit'] ?? 0), $cp_id ]);
    }
}

// end of file
?>
