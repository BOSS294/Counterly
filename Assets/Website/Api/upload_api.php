<?php
// upload_api.php
// Robust API for uploading PDF or extracted-text, parsing and inserting transactions.
// This version DOES NOT perform server-side PDF->text extraction (no pdftotext/gs).
// Replace your existing file with this. Keep CONNECTOR_PATH correct for your environment.

declare(strict_types=1);
ini_set('display_errors', '0'); // set to 1 only for local debug
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

session_start();

// === CONFIG ===
const DEBUG = false;
const CONNECTOR_PATH = __DIR__ . '/../../Connectors/connector.php';
const MAX_UPLOAD_BYTES = 20 * 1024 * 1024; // 20 MB

// ---------------- helpers ----------------
function jsonResp(int $code, array $payload) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}
function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
function safe_trim($v): string { return trim((string)($v ?? '')); }
function safe_string($v): string { return (string)($v ?? ''); }

// ---------------- connector ----------------
if (!is_readable(CONNECTOR_PATH)) {
    jsonResp(500, ['success'=>false, 'error'=>'Server misconfiguration: connector missing']);
}
require_once CONNECTOR_PATH;
try {
    $db = DB::get();
} catch (Throwable $e) {
    error_log('DB connect failed: ' . $e->getMessage());
    jsonResp(500, ['success'=>false, 'error'=>'Database connection failed']);
}

// ---------------- routing ----------------
$action = $_GET['action'] ?? null;

try {
    switch ($action) {
        case 'upload': api_upload($db); break;
        case 'upload_text': api_upload_text($db); break;
        case 'status': api_status($db); break;
        case 'add_account': api_add_account($db); break;
        case 'list_accounts': api_list_accounts($db); break;
        case 'get_groups': api_get_groups($db); break;
        case 'promote': api_promote($db); break;
        case 'merge_alias': api_merge_alias($db); break;
        default: jsonResp(400, ['success'=>false, 'error'=>'Unknown action']);
    }
} catch (Throwable $e) {
    try { $db->logParse(null, 'error', 'api_unhandled_exception', ['action'=>$action,'err'=>$e->getMessage()], $_SESSION['user_id'] ?? null); } catch (Throwable $_) {}
    if (DEBUG) {
        jsonResp(500, ['success'=>false, 'error'=>'Server error', 'dbg'=>$e->getMessage()]);
    }
    jsonResp(500, ['success'=>false, 'error'=>'Server error']);
}

// ---------------- auth & csrf ----------------
function require_auth($db): int {
    if (empty($_SESSION['user_id'])) jsonResp(401, ['success'=>false, 'error'=>'Not authenticated']);
    return intval($_SESSION['user_id']);
}
function validate_csrf($token): bool {
    if (empty($_SESSION['csrf_token']) || !$token) return false;
    return hash_equals($_SESSION['csrf_token'], (string)($token));
}

// ---------------- API implementations ----------------

function api_upload($db) {
    $userId = require_auth($db);

    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!validate_csrf($csrf)) jsonResp(403, ['success'=>false, 'error'=>'Invalid CSRF token']);

    if (empty($_FILES['statement_pdf'])) jsonResp(400, ['success'=>false, 'error'=>'No file uploaded']);
    $f = $_FILES['statement_pdf'];

    if (!is_uploaded_file($f['tmp_name'])) jsonResp(400, ['success'=>false, 'error'=>'Upload error']);
    if ($f['size'] > MAX_UPLOAD_BYTES) jsonResp(400, ['success'=>false, 'error'=>'File too large']);

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($f['tmp_name']) ?: '';
    $nameLower = strtolower($f['name'] ?? '');
    $ext = pathinfo($f['name'] ?? '', PATHINFO_EXTENSION);

    // Accept PDF or TXT but do NOT perform server-side PDF->text extraction.
    if ($mime !== 'application/pdf' && $ext !== 'pdf' && $mime !== 'text/plain' && $ext !== 'txt') {
        jsonResp(400, ['success'=>false, 'error'=>'Invalid file type']);
    }

    $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : null;

    // store file (either PDFs or txt)
    $uploaddir = __DIR__ . '/../../Uploads';
    if (!is_dir($uploaddir) && !mkdir($uploaddir, 0700, true)) jsonResp(500, ['success'=>false, 'error'=>'Server storage error']);
    $userdir = $uploaddir . '/statements/' . intval($userId);
    if (!is_dir($userdir) && !mkdir($userdir, 0700, true)) jsonResp(500, ['success'=>false, 'error'=>'Server storage error']);
    $timestamp = gmdate('Ymd_His');
    $safeName = preg_replace('/[^A-Za-z0-9._-]/','_', basename($f['name']));
    $stored = $userdir . '/' . $timestamp . '_' . $safeName;
    if (!move_uploaded_file($f['tmp_name'], $stored)) {
        try { $db->writeLocalLog('error','move_failed',['src'=>$f['tmp_name'],'dest'=>$stored]); } catch (Throwable $_) {}
        jsonResp(500, ['success'=>false, 'error'=>'Move failed']);
    }

    $sha = hash_file('sha256', $stored);

    // duplicate check
    try {
        $existing = $db->fetch('SELECT id, parse_status FROM statements WHERE file_sha256 = ? AND user_id = ? LIMIT 1', [$sha, $userId]);
        if ($existing) {
            $db->logAudit('upload_statement_duplicate','statement',$existing['id'],['filename'=>$f['name'],'sha'=>$sha], $userId);
            jsonResp(200, ['success'=>true,'statement_id'=>$existing['id'],'note'=>'duplicate_file']);
        }
    } catch (Throwable $e) {
        try { $db->writeLocalLog('warning','duplicate_check_failed',['err'=>$e->getMessage()]); } catch (Throwable $_) {}
    }

    // insert statement record
    try {
        $stmtId = $db->insertAndGetId(
            "INSERT INTO statements (user_id, account_id, filename, storage_path, file_size, file_sha256, parse_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'uploaded', NOW(), NOW())",
            [$userId, $account_id, $f['name'], $stored, intval($f['size']), $sha]
        );
        $db->logAudit('upload_statement','statement',$stmtId,['filename'=>$f['name'],'size'=>$f['size'],'sha'=>$sha, 'account_id'=>$account_id], $userId);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        try { $db->writeLocalLog('error','db_insert_statement_failed',['err'=>$msg]); } catch (Throwable $_) {}
        if (DEBUG) jsonResp(500, ['success'=>false,'error'=>'DB insert failed','db_error'=>$msg]);
        jsonResp(500, ['success'=>false,'error'=>'DB insert failed']);
    }

    $compress = ($_POST['compress'] ?? '') === '1';
    if ($compress) $db->logParse($stmtId,'info','requested_compress',['compress'=>true], $userId);

    // If a TXT file was uploaded, parse it now.
    if (strtolower($ext) === 'txt' || $mime === 'text/plain') {
        try {
            parse_and_insert($db, $stmtId, $userId, $stored);
        } catch (Throwable $e) {
            $errMsg = substr($e->getMessage(), 0, 1000);
            try { $db->execute("UPDATE statements SET parse_status = 'error', error_message = ?, updated_at = NOW() WHERE id = ?", [$errMsg, $stmtId]); } catch (Throwable $_) {}
            $db->logParse($stmtId,'error','parse_failed',['err'=>$errMsg], $userId);
            jsonResp(200, ['success'=>true,'statement_id'=>$stmtId,'parse_status'=>'error','error'=>$errMsg]);
        }
        jsonResp(200, ['success'=>true,'statement_id'=>$stmtId]);
    }

    // For PDFs: we intentionally DO NOT attempt server-side PDF->text extraction.
    // Client should extract and POST extracted text to action=upload_text (optionally sending statement_id).
    jsonResp(200, ['success'=>true,'statement_id'=>$stmtId,'note'=>'pdf_stored_no_parse','parse_status'=>'uploaded']);
}

function api_upload_text($db) {
    $userId = require_auth($db);
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!validate_csrf($csrf)) jsonResp(403, ['success'=>false, 'error'=>'Invalid CSRF token']);
    $pdfText = $_POST['pdf_text'] ?? '';
    $filename = $_POST['filename'] ?? 'uploaded.txt';
    $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : null;
    $referStmtId = isset($_POST['statement_id']) ? intval($_POST['statement_id']) : null;

    if (safe_trim($pdfText) === '') jsonResp(400, ['success'=>false,'error'=>'No PDF text provided']);

    // store text file
    $uploaddir = __DIR__ . '/../../Uploads/texts/' . intval($userId);
    if (!is_dir($uploaddir) && !mkdir($uploaddir, 0700, true)) jsonResp(500, ['success'=>false,'error'=>'Server storage error']);
    $timestamp = gmdate('Ymd_His');
    $safeName = preg_replace('/[^A-Za-z0-9._-]/','_', basename($filename));
    $stored = $uploaddir . '/' . $timestamp . '_' . $safeName . '.txt';
    file_put_contents($stored, (string)$pdfText);

    try {
        if ($referStmtId) {
            // attach to existing statement if it belongs to the user
            $row = $db->fetch('SELECT id FROM statements WHERE id = ? AND user_id = ? LIMIT 1', [$referStmtId, $userId]);
            if ($row) {
                // update storage_path, sha, size, filename, and reset parse_status to uploaded so parse_and_insert will run
                $db->execute('UPDATE statements SET filename = ?, storage_path = ?, file_size = ?, file_sha256 = ?, parse_status = ?, updated_at = NOW() WHERE id = ?', [$filename, $stored, strlen((string)$pdfText), hash('sha256', (string)$pdfText), 'uploaded', $referStmtId]);
                $stmtId = $referStmtId;
            } else {
                // fallback to creating a new statement
                $stmtId = $db->insertAndGetId(
                    "INSERT INTO statements (user_id, account_id, filename, storage_path, file_size, file_sha256, parse_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'uploaded', NOW(), NOW())",
                    [ $userId, $account_id, $filename, $stored, strlen((string)$pdfText), hash('sha256', (string)$pdfText) ]
                );
            }
        } else {
            $stmtId = $db->insertAndGetId(
                "INSERT INTO statements (user_id, account_id, filename, storage_path, file_size, file_sha256, parse_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'uploaded', NOW(), NOW())",
                [ $userId, $account_id, $filename, $stored, strlen((string)$pdfText), hash('sha256', (string)$pdfText) ]
            );
        }

        $db->logAudit('upload_statement_text','statement',$stmtId,['filename'=>$filename,'account_id'=>$account_id,'attached_to'=>$referStmtId], $userId);

        // parse the stored text (synchronous)
        parse_and_insert($db, $stmtId, $userId, $stored);
        jsonResp(200, ['success'=>true,'statement_id'=>$stmtId]);
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        try { $db->writeLocalLog('error','db_insert_statement_failed',['err'=>$msg]); } catch (Throwable $_) {}
        if (DEBUG) jsonResp(500, ['success'=>false,'error'=>'DB insert failed','db_error'=>$msg]);
        jsonResp(500, ['success'=>false,'error'=>'DB insert failed']);
    }
}

function api_status($db) {
    $userId = require_auth($db);
    $sid = intval($_GET['sid'] ?? 0);
    if (!$sid) jsonResp(400, ['success'=>false, 'error'=>'Missing sid']);
    try {
        $row = $db->fetch('SELECT id, parse_status, parsed_at, error_message, tx_count FROM statements WHERE id = ? AND user_id = ? LIMIT 1', [$sid, $userId]);
        if (!$row) jsonResp(404, ['success'=>false, 'error'=>'Not found']);
        $logs = $db->fetchAll('SELECT level, message, meta, created_at FROM parse_logs WHERE statement_id = ? ORDER BY id DESC LIMIT 50', [$sid]);
        jsonResp(200, ['success'=>true,'parse_status'=>$row['parse_status'],'parsed_at'=>$row['parsed_at'],'error_message'=>$row['error_message'],'tx_count'=>$row['tx_count'],'logs'=>$logs]);
    } catch (Throwable $e) {
        $db->logParse($sid,'error','status_read_failed',['err'=>$e->getMessage()], $userId);
        jsonResp(500, ['success'=>false,'error'=>'Server error']);
    }
}

// --- (accounts, counterparties, promote, merge_alias, and parsing helpers remain unchanged) ---

// ----- accounts -----
function api_add_account($db) {
    $userId = require_auth($db);
    $data = getJsonInput();
    $bank_name = safe_trim($data['bank_name'] ?? '');
    $acct_mask = safe_trim($data['account_number_masked'] ?? null) ?: null;
    $ifsc = safe_trim($data['ifsc'] ?? null) ?: null;
    $branch = safe_trim($data['branch'] ?? null) ?: null;
    if ($bank_name === '') jsonResp(400, ['success'=>false,'error'=>'Missing bank_name']);
    try {
        $id = $db->insertAndGetId(
            "INSERT INTO accounts (user_id, bank_name, account_number_masked, ifsc, branch, currency, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'INR', NOW(), NOW())",
            [$userId, $bank_name, $acct_mask, $ifsc, $branch]
        );
        $db->logAudit('create_account','account',$id,['bank'=>$bank_name],$userId);
        jsonResp(200, ['success'=>true,'account_id'=>$id]);
    } catch (Throwable $e) {
        $db->logParse(null,'error','add_account_failed',['err'=>$e->getMessage()],$userId);
        if (DEBUG) jsonResp(500, ['success'=>false,'error'=>'Server error','db_error'=>$e->getMessage()]);
        jsonResp(500, ['success'=>false,'error'=>'Server error']);
    }
}

function api_list_accounts($db) {
    $userId = require_auth($db);
    try {
        $rows = $db->fetchAll('SELECT id, bank_name, account_number_masked, ifsc, branch FROM accounts WHERE user_id = ? ORDER BY id DESC', [$userId]);
        jsonResp(200, ['success'=>true,'accounts'=>$rows]);
    } catch (Throwable $e) {
        $db->logParse(null,'error','list_accounts_failed',['err'=>$e->getMessage()],$userId);
        jsonResp(500, ['success'=>false,'error'=>'Server error']);
    }
}

// ----- counterparties -----
function api_get_groups($db) {
    $userId = require_auth($db);
    try {
        $rows = $db->fetchAll('SELECT id, canonical_name, tx_count, total_debit_paise, total_credit_paise FROM counterparties WHERE user_id = ? ORDER BY tx_count DESC LIMIT 200', [$userId]);
        jsonResp(200, ['success'=>true,'counterparties'=>$rows]);
    } catch (Throwable $e) {
        $db->logParse(null,'error','get_groups_failed',['err'=>$e->getMessage()], $userId);
        jsonResp(500, ['success'=>false,'error'=>'Server error']);
    }
}

function api_promote($db) {
    $userId = require_auth($db);
    $data = getJsonInput();
    $txnId = intval($data['transaction_id'] ?? 0);
    $canonical = safe_trim($data['canonical_name'] ?? '');
    if (!$txnId || $canonical === '') jsonResp(400, ['success'=>false,'error'=>'Missing params']);
    try {
        $db->beginTransaction();
        $t = $db->fetch('SELECT * FROM transactions WHERE id = ? AND user_id = ? LIMIT 1', [$txnId, $userId]);
        if (!$t) { $db->rollback(); jsonResp(404, ['success'=>false,'error'=>'Transaction not found']); }

        $cpId = $db->insertAndGetId('INSERT INTO counterparties (user_id, canonical_name, type, first_seen, last_seen, tx_count, total_debit_paise, total_credit_paise, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW(), 0, 0, 0, NOW(), NOW())', [$userId, $canonical, 'other']);
        $aliasCandidate = substr(normalize_narration($t['narration']),0,200);
        $db->insertAndGetId('INSERT INTO counterparty_aliases (counterparty_id, alias, alias_type, created_at) VALUES (?, ?, ?, NOW())', [$cpId, $aliasCandidate, 'narration']);
        $db->execute('UPDATE transactions SET counterparty_id = ?, manual_flag = 1, updated_at = NOW() WHERE id = ?', [$cpId, $txnId]);
        $db->insertAndGetId('INSERT INTO tx_counterparty_history (transaction_id, old_counterparty_id, new_counterparty_id, changed_by_user_id, reason, created_at) VALUES (?, ?, ?, ?, ?, NOW())', [$txnId, null, $cpId, $userId, 'manual_promote']);
        $agg = $db->fetch('SELECT COUNT(*) as cnt, SUM(debit_paise) as sdebit, SUM(credit_paise) as scredit FROM transactions WHERE counterparty_id = ?', [$cpId]);
        $db->execute('UPDATE counterparties SET tx_count = ?, total_debit_paise = COALESCE(?,0), total_credit_paise = COALESCE(?,0), last_seen = NOW(), updated_at = NOW() WHERE id = ?', [ intval($agg['cnt']), intval($agg['sdebit'] ?? 0), intval($agg['scredit'] ?? 0), $cpId ]);
        $db->commit();
        $db->logAudit('promote_counterparty','counterparty',$cpId,['transaction_id'=>$txnId], $userId);
        jsonResp(200, ['success'=>true,'counterparty_id'=>$cpId]);
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable $_) {}
        $db->logParse(null,'error','promote_failed',['err'=>$e->getMessage()], $userId);
        jsonResp(500, ['success'=>false,'error'=>'Server error']);
    }
}

function api_merge_alias($db) {
    $userId = require_auth($db);
    $data = getJsonInput();
    $alias = safe_trim($data['alias'] ?? '');
    $cpId = intval($data['counterparty_id'] ?? 0);
    if ($alias === '' || !$cpId) jsonResp(400, ['success'=>false,'error'=>'Missing params']);
    try {
        $exists = $db->fetch('SELECT id FROM counterparty_aliases WHERE counterparty_id = ? AND alias = ? LIMIT 1', [$cpId, $alias]);
        if (!$exists) $db->insertAndGetId('INSERT INTO counterparty_aliases (counterparty_id, alias, alias_type, created_at) VALUES (?, ?, ?, NOW())', [$cpId, $alias, 'narration']);
        $pattern = '%' . str_replace('%','\%',$alias) . '%';
        $db->execute('UPDATE transactions SET counterparty_id = ?, updated_at = NOW() WHERE user_id = ? AND narration LIKE ?', [$cpId, $userId, $pattern]);
        $agg = $db->fetch('SELECT COUNT(*) as cnt, SUM(debit_paise) as sdebit, SUM(credit_paise) as scredit FROM transactions WHERE counterparty_id = ?', [$cpId]);
        $db->execute('UPDATE counterparties SET tx_count = ?, total_debit_paise = COALESCE(?,0), total_credit_paise = COALESCE(?,0), last_seen = NOW(), updated_at = NOW() WHERE id = ?', [ intval($agg['cnt']), intval($agg['sdebit'] ?? 0), intval($agg['scredit'] ?? 0), $cpId ]);
        $db->logAudit('merge_alias','counterparty',$cpId,['alias'=>$alias], $userId);
        jsonResp(200, ['success'=>true]);
    } catch (Throwable $e) {
        $db->logParse(null,'error','merge_alias_failed',['err'=>$e->getMessage(),'alias'=>$alias,'cpId'=>$cpId], $userId);
        jsonResp(500, ['success'=>false,'error'=>'Server error']);
    }
}

// ---------------- Parsing helpers ----------------

function normalize_narration($s){
    $s = $s ?? '';
    $t = preg_replace('/\s+/', ' ', safe_string($s));
    $t = safe_trim($t);
    $t = mb_strtolower($t, 'UTF-8');
    $t = preg_replace('/[\x00-\x1F\x7F]/u','',$t);
    return $t;
}

function normalize_amount_to_paise($amt_str){
    $s = safe_string($amt_str);
    $s = safe_trim(str_replace(',', '', $s));
    if ($s === '') return 0;
    $s = preg_replace('/[^0-9.\-]/','', $s);
    if ($s === '' || $s === '.') return 0;
    $val = floatval($s);
    return (int) round($val * 100);
}

function compute_txn_checksum($account_id, $txn_date, $amount_paise, $reference, $narration){
    $canon = sprintf('%s|%s|%d|%s|%s', $account_id ?? '0', $txn_date ?? '', $amount_paise ?? 0, $reference ?? '', normalize_narration($narration));
    return hash('sha256', $canon);
}

function parse_hdfc_text_to_txns($rawText){
    $lines = preg_split('/\r?\n/u', safe_string($rawText));
    $txns = [];
    $buffer = null;

    foreach ($lines as $ln) {
        $ln_trim = safe_trim($ln);
        if ($ln_trim === '') continue;

        // Skip known header/footer boilerplate (case-insensitive)
        if (preg_match('/hdfc bank ltd|statement of accounts|page no\.?:|account branch|account no|statement from|statement to|a\/c open date|cust id|branch code|product code|rtgs\/neft ifsc|statement summary|opening balance|dr count|cr count|\*\*continue\*\*/iu', $ln_trim)) {
            // If we hit a statement summary footer, stop parsing further
            if (preg_match('/statement summary|opening balance.*debits.*credits|dr count|cr count/i', $ln_trim)) break;
            continue;
        }

        // Column header separators should be ignored
        if (preg_match('/^-{2,}|^date\s+narration/i', $ln_trim)) {
            continue;
        }

        // If a line starts with a date pattern -> new transaction start
        if (preg_match('/^\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\s+(.+)$/u', $ln_trim)) {
            if ($buffer !== null) {
                $tx = process_buffer_line($buffer);
                if ($tx) $txns[] = $tx;
            }
            // start new buffer; keep original spacing collapsed
            $buffer = preg_replace('/\s+/', ' ', $ln_trim);
        } else {
            // continuation line: append but mark separation so numeric extraction won't cross lines wrongly
            if ($buffer === null) {
                // stray continuation outside a record — ignore
                continue;
            }
            // Insert a clear separator token between the first-line and continuation lines.
            $buffer .= ' ||CONT|| ' . preg_replace('/\s+/', ' ', $ln_trim);
        }
    }

    // finalize last buffered transaction
    if ($buffer !== null) {
        $tx = process_buffer_line($buffer);
        if ($tx) $txns[] = $tx;
    }

    return $txns;
}
function process_buffer_line($line) {
    if ($line === null) return null;
    // collapse whitespace, keep continuation separator
    $s = preg_replace('/\s+/', ' ', safe_trim($line));

    // Must start with a date
    if (!preg_match('/^(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\s+(.*)$/u', $s, $m)) return null;
    $date_raw = safe_trim($m[1]);
    $rest = safe_trim($m[2]);

    // Normalise date => YYYY-MM-DD
    $dateParts = preg_split('/[\/\-]/', $date_raw);
    if (count($dateParts) >= 3) {
        $d = str_pad(safe_trim($dateParts[0]),2,'0',STR_PAD_LEFT);
        $mth = str_pad(safe_trim($dateParts[1]),2,'0',STR_PAD_LEFT);
        $y = safe_trim($dateParts[2]);
        if (strlen($y) === 2) $y = '20' . $y;
        $txn_date = "$y-$mth-$d";
    } else {
        $txn_date = null;
    }

    // Remove our internal continuation separator for parsing columns but keep it for name extraction
    $rest_for_columns = preg_replace('/\s*\|\|CONT\|\|\s*/u', ' ', $rest);

    // Primary attempt: narration + 3 numeric columns at end (withdrawal, deposit, balance)
    if (preg_match('/^(.*\S)\s+([\d,]+(?:\.\d{1,2})?|-)\s+([\d,]+(?:\.\d{1,2})?|-)\s+([\d,]+(?:\.\d{1,2})?)$/u', $rest_for_columns, $mm)) {
        $narration = safe_trim($mm[1]);
        $withdraw = $mm[2];
        $deposit = $mm[3];
        $balance = $mm[4];
    } else if (preg_match('/^(.*\S)\s+([\d,]+(?:\.\d{1,2})?)\s+([\d,]+(?:\.\d{1,2})?)$/u', $rest_for_columns, $mm2)) {
        // two numeric columns (e.g., withdrawal + balance or deposit + balance)
        $narration = safe_trim($mm2[1]);
        $withdraw = $mm2[2];
        $deposit = '-';
        $balance = $mm2[3];
    } else {
        // FALLBACK: find monetary tokens that are not adjacent to letters or @ (avoid UPI ids)
        // Use negative lookbehind/ahead to ensure tokens are separated from letters/@
        if (preg_match_all('/(?<![A-Za-z@])([\d]{1,3}(?:,[\d]{3})*(?:\.\d{1,2})?|\d+(?:\.\d{1,2})?)(?![A-Za-z@])/u', $rest_for_columns, $numM)) {
            $nums = $numM[1];
            $ncount = count($nums);
            if ($ncount >= 1) $balance = $nums[$ncount - 1]; else $balance = null;
            if ($ncount >= 2) $deposit = $nums[$ncount - 2]; else $deposit = '-';
            if ($ncount >= 3) $withdraw = $nums[$ncount - 3]; else $withdraw = null;

            // Build narration by removing those monetary tokens (only the ones we detected) and also remove isolated reference-like tokens that are clearly IDs
            $narration = $rest;
            // Remove exact tokens matched from the narration for safety (replace with single space)
            foreach ($nums as $tk) {
                $narration = preg_replace('/\b' . preg_quote($tk, '/') . '\b/u', ' ', $narration);
            }
            // Remove common trailing reference tokens with @ or blocks like '-UPI' etc from the narration for cleanliness
            $narration = preg_replace('/\s*\|\|CONT\|\|\s*/u', ' ', $narration); // remove our separator
            $narration = preg_replace('/\s*@[A-Za-z0-9\.\-_]+/u', ' ', $narration);
            $narration = preg_replace('/-?upi-?/iu', ' ', $narration); // drop literal 'UPI' markers
            $narration = preg_replace('/-?ubn?\d+/iu', ' ', $narration);
            $narration = preg_replace('/\s{2,}/u', ' ', $narration);
            $narration = safe_trim($narration);
        } else {
            // nothing that resembles amounts -> give up on this line
            return null;
        }
    }

    // extract reference (ref/chq/utr or email)
    $reference = null;
    if ($narration !== '' && preg_match('/(ref[:\-\s]*|chq[:.\-\s]*|utr[:\-\s]*)([A-Za-z0-9\-\/]+)$/iu', $narration, $mr)) {
        $reference = $mr[2] ?? null;
    } else if ($narration !== '' && preg_match('/([A-Za-z0-9.\-_]+@[A-Za-z0-9.\-]+)/u', $narration, $me)) {
        $reference = $me[1] ?? null;
    } else {
        // If the rest contains a long numeric token that looks like a transaction id but not money, capture it as reference
        if (preg_match('/\b([0-9]{8,})\b/u', $rest, $mr2)) {
            $reference = $mr2[1];
        }
    }

    // convert to paise and determine txn type
    $withdraw_paise = ($withdraw === '-' || $withdraw === null ? null : normalize_amount_to_paise($withdraw));
    $deposit_paise  = ($deposit === '-' || $deposit === null ? null : normalize_amount_to_paise($deposit));
    $amount_paise   = $withdraw_paise !== null ? $withdraw_paise : ($deposit_paise !== null ? $deposit_paise : 0);
    $balance_paise  = $balance === null ? 0 : normalize_amount_to_paise($balance);

    $txn_type = 'other';
    if ($withdraw_paise !== null && $withdraw_paise > 0) $txn_type = 'debit';
    else if ($deposit_paise !== null && $deposit_paise > 0) $txn_type = 'credit';

    // Keep the full narration (use original collapsed spacing), but also provide a cleaned narration for alias extraction
    $full_narration = safe_trim($narration);

    return [
        'txn_date' => $txn_date,
        'narration' => $full_narration,
        'raw_line' => $s,
        'reference' => $reference,
        'txn_type' => $txn_type,
        'amount_paise' => $amount_paise,
        'debit_paise' => $withdraw_paise,
        'credit_paise' => $deposit_paise,
        'balance_paise' => $balance_paise
    ];
}



function parse_and_insert($db, $stmtId, $userId, $storedPath){
    // mark parsing started
    try {
        $db->execute('UPDATE statements SET parse_status = ?, updated_at = NOW() WHERE id = ?', ['parsing', $stmtId]);
    } catch (Throwable $_) {}

    // read text (only text files should be parsed)
    $text = '';
    try {
        $ext = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));
        if ($ext !== 'txt') {
            // Defensive: only parse textual uploads; PDFs must have text uploaded separately.
            $err = 'Server configured to not perform PDF->text extraction. Provide a .txt statement or upload extracted text via upload_text.';
            try { $db->execute("UPDATE statements SET parse_status = 'needs_text', error_message = ?, updated_at = NOW() WHERE id = ?", [$err, $stmtId]); } catch (Throwable $_) {}
            $db->logParse($stmtId, 'warning', 'parse_skipped_non_text', ['err'=>$err], $userId);
            throw new RuntimeException($err);
        }
        $text = (string)file_get_contents($storedPath);
    } catch (Throwable $e) {
        $err = substr($e->getMessage(), 0, 1000);
        try { $db->execute("UPDATE statements SET parse_status = 'error', error_message = ?, updated_at = NOW() WHERE id = ?", [$err, $stmtId]); } catch (Throwable $_) {}
        $db->logParse($stmtId, 'error', 'extract_failed', ['err'=>$err], $userId);
        throw $e;
    }

    $txns = parse_hdfc_text_to_txns($text);

    // fetch statement.account_id if present so transactions get linked to account
    $stmtRow = $db->fetch('SELECT account_id FROM statements WHERE id = ? LIMIT 1', [$stmtId]);
    $stmt_account_id = isset($stmtRow['account_id']) ? intval($stmtRow['account_id']) : null;

    $db->beginTransaction();
    try {
        $insertSql = "INSERT INTO transactions (statement_id, user_id, account_id, txn_date, narration, raw_line, reference_number, txn_type, amount_paise, debit_paise, credit_paise, balance_paise, txn_checksum, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE updated_at = NOW()";
        foreach ($txns as $t) {
            $account_id = $stmt_account_id;
            $txn_checksum = compute_txn_checksum($account_id, $t['txn_date'], $t['amount_paise'], $t['reference'], $t['narration']);
            $db->query($insertSql, [ $stmtId, $userId, $account_id, $t['txn_date'], $t['narration'], $t['raw_line'], $t['reference'], $t['txn_type'], $t['amount_paise'], $t['debit_paise'], $t['credit_paise'], $t['balance_paise'], $txn_checksum ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        $db->logParse($stmtId,'error','insert_failed',['err'=>$e->getMessage()], $userId);
        throw $e;
    }

    // grouping scanner
    run_grouping_scanner($db, $userId);

    // finalize statement row (tx_count and parsed_at)
    try {
        $count = $db->fetch('SELECT COUNT(*) as c FROM transactions WHERE statement_id = ?', [$stmtId])['c'] ?? 0;
        $db->execute('UPDATE statements SET parse_status = ?, parsed_at = NOW(), tx_count = ?, updated_at = NOW() WHERE id = ?', ['parsed', intval($count), $stmtId]);
        $db->logParse($stmtId,'info','parse_complete',['inserted'=>$count], $userId);
    } catch (Throwable $e) {
        $db->logParse($stmtId,'warning','final_update_failed',['err'=>$e->getMessage()], $userId);
    }
}
function extract_alias_candidate($narration){
    $raw = safe_trim($narration);
    if ($raw === '') return '';

    // Normalize to a simpler working string
    $n = normalize_narration($raw);

    // 1) If email present, return it
    if (preg_match('/[a-z0-9.\-\_]+@[a-z0-9\-\.]+/i', $n, $m)) return strtolower($m[0]);

    // 2) If purely 10-digit mobile present, return it
    if (preg_match('/\b(\d{10})\b/', $n, $m)) return $m[1];

    // 3) If pattern begins with 'upi-' or contains '-upi-' try to extract the name segment
    //    UPI lines often: UPI-<NAME>-<UPIID>  OR UPI-<NAME>-<SOMETHING>-UPI ...
    if (preg_match('/(?:^|[^A-Za-z0-9])upi[-\s]*([^\-@]{3,120})(?:[-@]|$)/iu', $n, $m)) {
        $cand = trim($m[1]);
        // remove trailing numbers/UPI ids and tokens like "bank", "pay", codes
        $cand = preg_replace('/\s*@.*$/u', '', $cand);       // remove @... tokens
        $cand = preg_replace('/\d+/u', '', $cand);           // remove numbers
        $cand = preg_replace('/\b(yesb|ybl|sbi|axis|okb|okicici|paytm|upi|bank|ltd|pvt|ltd)\b/iu', ' ', $cand);
        $cand = preg_replace('/[^a-z\s\.&\'\-]/iu','', $cand);
        $cand = preg_replace('/\s{2,}/u',' ', $cand);
        $cand = safe_trim($cand);
        if ($cand !== '') return $cand;
    }

    // 4) Some narrations are hyphen-separated: pick the first hyphen-delimited segment that looks like a name
    $parts = preg_split('/[-\|\/]/u', $n);
    foreach ($parts as $p) {
        $p2 = trim($p);
        // skip short tokens and tokens that look like codes or UPI ids
        if (strlen($p2) < 3) continue;
        if (preg_match('/^(00|000|up|utr|chq|aor|nwd|fee|atm)/iu', $p2)) continue;
        // remove emails/handles and trailing numbers
        $p2 = preg_replace('/\s*@.*$/u','',$p2);
        $p2 = preg_replace('/\d+/u','',$p2);
        $p2 = preg_replace('/[^a-z\s\.&\'\-]/iu','', $p2);
        $p2 = preg_replace('/\s{2,}/u',' ', $p2);
        $p2 = safe_trim($p2);
        // heuristic: must contain a letter and at least one space (first + last) or be a single-word merchant
        if ($p2 !== '' && preg_match('/[a-z]/i', $p2)) {
            // return first up-to-4 words as alias
            $words = preg_split('/\s+/', $p2);
            return implode(' ', array_slice($words, 0, 4));
        }
    }

    // 5) fallback: first 3 meaningful words from normalized narration
    $parts2 = preg_split('/\s+/', $n);
    $out = [];
    foreach ($parts2 as $w) {
        if (preg_match('/^\d+$/', $w)) continue;
        if (strlen($w) < 2) continue;
        $out[] = $w;
        if (count($out) >= 4) break;
    }
    return implode(' ', $out);
}


function run_grouping_scanner($db, $userId){
    $txs = $db->fetchAll('SELECT id, narration FROM transactions WHERE user_id = ? AND (counterparty_id IS NULL OR counterparty_id = 0)', [$userId]);
    if (empty($txs)) return;
    $candidates = [];
    foreach ($txs as $t) {
        $cand = extract_alias_candidate($t['narration']);
        $ck = substr(normalize_narration($cand),0,200);
        if (!isset($candidates[$ck])) $candidates[$ck] = ['count'=>0,'tx_ids'=>[],'sample'=>$cand];
        $candidates[$ck]['count']++;
        $candidates[$ck]['tx_ids'][] = $t['id'];
    }
    foreach ($candidates as $ck => $info) {
        if ($info['count'] < 2) continue;
        $canonical = mb_strtoupper(substr($info['sample'],0,255));
        $aliasRow = $db->fetch('SELECT ca.counterparty_id FROM counterparty_aliases ca JOIN counterparties cp ON ca.counterparty_id = cp.id WHERE ca.alias = ? AND cp.user_id = ? LIMIT 1', [$ck, $userId]);
        $cp_id = $aliasRow['counterparty_id'] ?? null;
        if (!$cp_id) {
            $existing = $db->fetch('SELECT id FROM counterparties WHERE user_id = ? AND canonical_name = ? LIMIT 1', [$userId, $canonical]);
            if ($existing) $cp_id = $existing['id'];
        }
        if (!$cp_id) {
            $cp_id = $db->insertAndGetId('INSERT INTO counterparties (user_id, canonical_name, type, first_seen, last_seen, tx_count, total_debit_paise, total_credit_paise, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW(), 0, 0, 0, NOW(), NOW())', [$userId, $canonical, 'other']);
        }
        $existsAlias = $db->fetch('SELECT id FROM counterparty_aliases WHERE counterparty_id = ? AND alias = ? LIMIT 1', [$cp_id, $ck]);
        if (!$existsAlias) $db->insertAndGetId('INSERT INTO counterparty_aliases (counterparty_id, alias, alias_type, created_at) VALUES (?, ?, ?, NOW())', [$cp_id, $ck, 'narration']);
        foreach (array_chunk($info['tx_ids'],100) as $chunk) {
            $placeholders = implode(',', array_fill(0,count($chunk),'?'));
            $params = $chunk;
            array_unshift($params, $cp_id);
            $sql = "UPDATE transactions SET counterparty_id = ?, updated_at = NOW() WHERE id IN ($placeholders)";
            $db->execute($sql, $params);
        }
        $agg = $db->fetch('SELECT COUNT(*) as cnt, SUM(debit_paise) as sdebit, SUM(credit_paise) as scredit FROM transactions WHERE counterparty_id = ?', [$cp_id]);
        $db->execute('UPDATE counterparties SET tx_count = ?, total_debit_paise = COALESCE(?,0), total_credit_paise = COALESCE(?,0), last_seen = NOW(), updated_at = NOW() WHERE id = ?', [ intval($agg['cnt']), intval($agg['sdebit'] ?? 0), intval($agg['scredit'] ?? 0), $cp_id ]);
        $db->logAudit('auto_group_created','counterparty',$cp_id,['alias'=>$ck,'tx_count'=>$info['count']], $userId);
    }
}
