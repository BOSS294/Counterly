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
        case 'upload_csv': api_upload_csv($db); break;
        case 'search_statements': api_search_statements($db); break;
        case 'statement_metrics': api_statement_metrics($db); break;
        case 'refresh_statement': api_refresh_statement($db); break;
        case 'delete_statement': api_delete_statement($db); break;
        case 'rename_statement': api_rename_statement($db); break;
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
// Search and list statements (server-side paging optional)
function api_search_statements($db) {
    $userId = require_auth($db);
    $q = trim((string)($_GET['q'] ?? ''));
    $page = max(1, intval($_GET['page'] ?? 1));
    $page_size = max(10, min(200, intval($_GET['page_size'] ?? 50)));
    try {
        if ($q === '') {
            // fallback to list statements
            $rows = $db->fetchAll('SELECT id, filename, file_size, parse_status, parsed_at, tx_count, created_at FROM statements WHERE user_id = ? ORDER BY id DESC LIMIT ?', [$userId, $page_size]);
        } else {
            $like = '%' . str_replace('%','\%',$q) . '%';
            $rows = $db->fetchAll('SELECT id, filename, file_size, parse_status, parsed_at, tx_count, created_at FROM statements WHERE user_id = ? AND (filename LIKE ? OR id = ?) ORDER BY id DESC LIMIT ?', [$userId, $like, intval($q), $page_size]);
        }
        jsonResp(200, ['success'=>true, 'statements'=>$rows]);
    } catch (Throwable $e) {
        jsonResp(500, ['success'=>false, 'error'=>'Server error']);
    }
}
function api_delete_statement($db) {
    $userId = require_auth($db);
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $sid = intval($data['statement_id'] ?? 0);
    $csrf = $data['csrf_token'] ?? null;
    if (!validate_csrf($csrf)) jsonResp(403, ['success'=>false,'error'=>'Invalid CSRF token']);
    if (!$sid) jsonResp(400, ['success'=>false,'error'=>'Missing statement_id']);
    try {
        $row = $db->fetch('SELECT storage_path FROM statements WHERE id = ? AND user_id = ? LIMIT 1', [$sid, $userId]);
        if (!$row) jsonResp(404, ['success'=>false,'error'=>'Not found']);
        $path = $row['storage_path'] ?? null;
        // delete DB row and any associated uploaded file (best-effort)
        $db->beginTransaction();
        $db->execute('DELETE FROM statements WHERE id = ? AND user_id = ?', [$sid, $userId]);
        $db->execute('DELETE FROM transactions WHERE statement_id = ?', [$sid]); // remove associated txns
        $db->commit();
        if ($path && is_file($path)) @unlink($path);
        $db->logAudit('delete_statement','statement',$sid,[], $userId);
        jsonResp(200, ['success'=>true]);
    } catch (Throwable $e) {
        try { $db->rollback(); } catch (Throwable $_) {}
        jsonResp(500, ['success'=>false,'error'=>'Server error']);
    }
}

function api_rename_statement($db) {
    $userId = require_auth($db);
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $sid = intval($data['statement_id'] ?? 0);
    $newName = trim((string)($data['filename'] ?? ''));
    $csrf = $data['csrf_token'] ?? null;
    if (!validate_csrf($csrf)) jsonResp(403, ['success'=>false,'error'=>'Invalid CSRF token']);
    if (!$sid || $newName === '') jsonResp(400, ['success'=>false,'error'=>'Missing params']);
    try {
        $updated = $db->execute('UPDATE statements SET filename = ?, updated_at = NOW() WHERE id = ? AND user_id = ?', [$newName, $sid, $userId]);
        $db->logAudit('rename_statement','statement',$sid,['filename'=>$newName], $userId);
        jsonResp(200, ['success'=>true]);
    } catch (Throwable $e) {
        jsonResp(500, ['success'=>false,'error'=>'Server error']);
    }
}
function api_statement_metrics($db) {
    $userId = require_auth($db);
    $sid = intval($_GET['sid'] ?? 0);
    if (!$sid) jsonResp(400, ['success'=>false,'error'=>'Missing sid']);
    try {
        // verify ownership
        $stmt = $db->fetch('SELECT id, parsed_at FROM statements WHERE id = ? AND user_id = ? LIMIT 1', [$sid, $userId]);
        if (!$stmt) jsonResp(404, ['success'=>false,'error'=>'Not found']);
        $txCountRow = $db->fetch('SELECT COUNT(*) as cnt FROM transactions WHERE statement_id = ?', [$sid]);
        $cpCountRow = $db->fetch('SELECT COUNT(DISTINCT COALESCE(counterparty_id,0)) as cnt FROM transactions WHERE statement_id = ?', [$sid]);
        jsonResp(200, [
            'success'=>true,
            'tx_count' => intval($txCountRow['cnt'] ?? 0),
            'counterparty_count' => intval($cpCountRow['cnt'] ?? 0),
            'parsed_at' => $stmt['parsed_at'] ?? null
        ]);
    } catch (Throwable $e) {
        jsonResp(500, ['success'=>false,'error'=>'Server error']);
    }
}

/**
 * Refresh statement checks & optional automated fixes.
 * POST JSON: { statement_id, csrf_token, apply_fix: bool }
 *
 * Behavior:
 *  - Scans transactions for the statement and detects issues:
 *    * txn_checksum mismatch
 *    * amount_paise == 0 while debit_paise or credit_paise present
 *    * txn_type not matching debit/credit presence
 *  - If apply_fix == true, applies safe fixes inside a transaction:
 *    * set amount_paise = max(debit_paise, credit_paise) if zero
 *    * set txn_type to 'debit'/'credit' based on debit/credit
 *    * recompute txn_checksum
 *  - After fixes, runs run_grouping_scanner and updates statement metadata.
 */
function api_refresh_statement($db) {
    $userId = require_auth($db);
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $sid = intval($data['statement_id'] ?? 0);
    $csrf = $data['csrf_token'] ?? null;
    $apply = !empty($data['apply_fix']);
    if (!validate_csrf($csrf)) jsonResp(403, ['success'=>false,'error'=>'Invalid CSRF token']);
    if (!$sid) jsonResp(400, ['success'=>false,'error'=>'Missing statement_id']);

    try {
        // verify statement ownership and get account_id
        $stmtRow = $db->fetch('SELECT id, account_id, storage_path FROM statements WHERE id = ? AND user_id = ? LIMIT 1', [$sid, $userId]);
        if (!$stmtRow) jsonResp(404, ['success'=>false,'error'=>'Not found']);

        $account_id = isset($stmtRow['account_id']) ? intval($stmtRow['account_id']) : null;

        // fetch transactions for statement
        $txs = $db->fetchAll('SELECT id, txn_date, amount_paise, debit_paise, credit_paise, txn_type, reference_number, narration, txn_checksum FROM transactions WHERE statement_id = ?', [$sid]);
        if ($txs === false) $txs = [];

        $issues = [];
        $fixes = [];
        foreach ($txs as $t) {
            $tid = intval($t['id']);
            $amt = isset($t['amount_paise']) ? intval($t['amount_paise']) : 0;
            $d = isset($t['debit_paise']) ? intval($t['debit_paise']) : 0;
            $c = isset($t['credit_paise']) ? intval($t['credit_paise']) : 0;
            $tt = strtolower(trim((string)$t['txn_type'] ?? ''));
            $ref = $t['reference_number'] ?? null;
            $narr = $t['narration'] ?? '';

            // recompute checksum candidate
            $expected_ck = compute_txn_checksum($account_id, $t['txn_date'], $amt, $ref, $narr);

            if (($t['txn_checksum'] ?? '') !== $expected_ck) {
                $issues[] = ['type'=>'checksum_mismatch','transaction_id'=>$tid,'current'=>$t['txn_checksum'] ?? '','expected'=>$expected_ck];
                if ($apply) $fixes[] = ['transaction_id'=>$tid,'set'=>['txn_checksum'=>$expected_ck]];
            }

            // amount missing but debit/credit present
            if (($amt === 0) && ($d > 0 || $c > 0)) {
                $inferred = max($d, $c);
                $issues[] = ['type'=>'missing_amount','transaction_id'=>$tid,'inferred_amount_paise'=>$inferred,'debit_paise'=>$d,'credit_paise'=>$c];
                if ($apply) $fixes[] = ['transaction_id'=>$tid,'set'=>['amount_paise'=>$inferred]];
            }

            // txn_type mismatch
            $should_tt = 'other';
            if ($d > 0 && $c == 0) $should_tt = 'debit';
            else if ($c > 0 && $d == 0) $should_tt = 'credit';
            if ($should_tt !== 'other' && $should_tt !== $tt) {
                $issues[] = ['type'=>'txn_type_mismatch','transaction_id'=>$tid,'current'=>$tt,'expected'=>$should_tt];
                if ($apply) $fixes[] = ['transaction_id'=>$tid,'set'=>['txn_type'=>$should_tt]];
            }
        }

        // If apply==true, perform updates inside a transaction (batch)
        $summary = ['issues_found' => count($issues), 'fixes_applied' => 0];
        if ($apply && !empty($fixes)) {
            $db->beginTransaction();
            try {
                foreach ($fixes as $f) {
                    $tid = intval($f['transaction_id']);
                    $sets = $f['set'];
                    $params = [];
                    $sqlParts = [];
                    foreach ($sets as $col => $val) {
                        $sqlParts[] = "$col = ?";
                        $params[] = $val;
                    }
                    // if we changed amount or txn_type we also recompute checksum afterwards
                    $params[] = $tid;
                    $sql = "UPDATE transactions SET " . implode(', ', $sqlParts) . ", updated_at = NOW() WHERE id = ? LIMIT 1";
                    $db->execute($sql, $params);

                    // recompute checksum row now that changes persisted for this row
                    $rowNow = $db->fetch('SELECT account_id, txn_date, amount_paise, reference_number, narration FROM transactions WHERE id = ? LIMIT 1', [$tid]);
                    $acct = $rowNow['account_id'] ?? $account_id;
                    $ck = compute_txn_checksum($acct, $rowNow['txn_date'], intval($rowNow['amount_paise'] ?? 0), $rowNow['reference_number'] ?? null, $rowNow['narration'] ?? '');
                    $db->execute('UPDATE transactions SET txn_checksum = ?, updated_at = NOW() WHERE id = ?', [$ck, $tid]);

                    $summary['fixes_applied']++;
                }

                // run grouping scanner to refresh counterparties
                run_grouping_scanner($db, $userId);

                // update statement tx_count if necessary
                try {
                    $count = $db->fetch('SELECT COUNT(*) as c FROM transactions WHERE statement_id = ?', [$sid])['c'] ?? 0;
                    $db->execute('UPDATE statements SET tx_count = ?, updated_at = NOW() WHERE id = ?', [intval($count), $sid]);
                } catch (Throwable $_) {}

                $db->commit();
            } catch (Throwable $e) {
                try { $db->rollback(); } catch (Throwable $_) {}
                jsonResp(500, ['success'=>false,'error'=>'Failed to apply fixes','dbg'=> $e->getMessage()]);
            }
        }

        return jsonResp(200, ['success'=>true,'issues'=>$issues,'summary'=>$summary]);
    } catch (Throwable $e) {
        jsonResp(500, ['success'=>false,'error'=>'Server error','dbg'=>$e->getMessage()]);
    }
}

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
/**
 * API: upload_csv
 * Accepts: POST with csrf_token + csv_text OR file upload csv_file
 * Optional: filename, account_id
 * Behavior: store CSV in Uploads/texts/<user>, create statements row, parse CSV and insert transactions,
 * returns JSON { success:true, statement_id:..., parse_status:'parsed', inserted_rows: N }
 */
function api_upload_csv($db) {
    $userId = require_auth($db);
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    if (!validate_csrf($csrf)) jsonResp(403, ['success'=>false, 'error'=>'Invalid CSRF token']);

    $account_id = isset($_POST['account_id']) ? intval($_POST['account_id']) : null;
    $filename = safe_trim($_POST['filename'] ?? 'uploaded.csv');

    $csv_text = '';
    // prefer uploaded file
    if (!empty($_FILES['csv_file']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $f = $_FILES['csv_file'];
        if ($f['size'] > MAX_UPLOAD_BYTES) jsonResp(400, ['success'=>false,'error'=>'File too large']);
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($f['tmp_name']) ?: '';
        // allow text/csv too
        if (strpos($mime,'text') === false && strpos(strtolower($f['name']), '.csv') === false) {
            // still allow but warn
        }
        $csv_text = (string)file_get_contents($f['tmp_name']);
        $filename = safe_trim($f['name'] ?? $filename);
    } else {
        $csv_text = $_POST['csv_text'] ?? '';
    }

    if (safe_trim($csv_text) === '') jsonResp(400, ['success'=>false,'error'=>'No CSV provided']);

    // store CSV file in uploads/texts/<user>
    $uploaddir = __DIR__ . '/../../Uploads/texts/' . intval($userId);
    if (!is_dir($uploaddir) && !mkdir($uploaddir, 0700, true)) jsonResp(500, ['success'=>false, 'error'=>'Server storage error']);
    $timestamp = gmdate('Ymd_His');
    $safeName = preg_replace('/[^A-Za-z0-9._-]/','_', basename($filename));
    $stored = $uploaddir . '/' . $timestamp . '_' . $safeName . '.csv';
    if (file_put_contents($stored, (string)$csv_text) === false) jsonResp(500, ['success'=>false,'error'=>'Failed to store CSV']);

    // create statement row (so imports behave same as parsed statements)
    try {
        $stmtId = $db->insertAndGetId(
            "INSERT INTO statements (user_id, account_id, filename, storage_path, file_size, file_sha256, parse_status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, 'uploaded', NOW(), NOW())",
            [$userId, $account_id, $filename, $stored, strlen((string)$csv_text), hash('sha256', (string)$csv_text)]
        );
        $db->logAudit('upload_csv','statement',$stmtId,['filename'=>$filename,'account_id'=>$account_id], $userId);
    } catch (Throwable $e) {
        $db->writeLocalLog('error','db_insert_statement_failed',['err'=>$e->getMessage()]);
        jsonResp(500, ['success'=>false,'error'=>'DB insert failed']);
    }

    // parse and insert CSV synchronously
    try {
        $inserted = parse_csv_and_insert($db, $stmtId, $userId, $stored);
        jsonResp(200, ['success'=>true,'statement_id'=>$stmtId,'parse_status'=>'parsed','inserted_rows'=>$inserted]);
    } catch (Throwable $e) {
        $err = substr($e->getMessage(),0,1000);
        try { $db->execute("UPDATE statements SET parse_status = 'error', error_message = ?, updated_at = NOW() WHERE id = ?", [$err, $stmtId]); } catch (Throwable $_) {}
        $db->logParse($stmtId,'error','csv_parse_failed',['err'=>$err], $userId);
        jsonResp(200, ['success'=>false,'statement_id'=>$stmtId,'parse_status'=>'error','error'=>$err]);
    }
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
    $nlines = count($lines);

    // locate header line (if present)
    $headerIndex = null;
    $headerLine = '';
    for ($i = 0; $i < $nlines; $i++) {
        $ln = $lines[$i];
        if (preg_match('/\bDate\b.*\bValue Dt\b.*\bWithdrawal\b.*\bDeposit\b.*\bClosing Balance\b/i', $ln)) {
            $headerIndex = $i;
            $headerLine = $ln;
            break;
        }
    }

    if ($headerIndex !== null) {
        // compute approximate column positions from header
        $hl = $headerLine;
        $datePos = (mb_stripos($hl, 'Date') !== false) ? mb_stripos($hl, 'Date') : 0;
        $narrPos = (mb_stripos($hl, 'Narration') !== false) ? mb_stripos($hl, 'Narration') : ($datePos + 10);
        $refPos  = (mb_stripos($hl, 'Chq') !== false) ? mb_stripos($hl, 'Chq') : ((mb_stripos($hl, 'Ref') !== false) ? mb_stripos($hl, 'Ref') : ($narrPos + 40));
        $valPos  = (mb_stripos($hl, 'Value Dt') !== false) ? mb_stripos($hl, 'Value Dt') : ((mb_stripos($hl, 'Value') !== false) ? mb_stripos($hl, 'Value') : ($refPos + 20));
        $withPos = (mb_stripos($hl, 'Withdrawal') !== false) ? mb_stripos($hl, 'Withdrawal') : ($valPos + 10);
        $depPos  = (mb_stripos($hl, 'Deposit') !== false) ? mb_stripos($hl, 'Deposit') : ($withPos + 18);
        $balPos  = (mb_stripos($hl, 'Closing Balance') !== false) ? mb_stripos($hl, 'Closing Balance') : ($depPos + 18);

        // ensure integer positions
        $positions = [
            'date' => max(0,intval($datePos)),
            'narr' => max(0,intval($narrPos)),
            'ref'  => max(0,intval($refPos)),
            'val'  => max(0,intval($valPos)),
            'with' => max(0,intval($withPos)),
            'dep'  => max(0,intval($depPos)),
            'bal'  => max(0,intval($balPos))
        ];

        // walk lines and parse
        for ($i = 0; $i < $nlines; $i++) {
            $raw = $lines[$i];
            $trim = safe_trim($raw);
            if ($trim === '') continue;
            // skip header / footer lines
            if (preg_match('/hdfc bank ltd|page no\.|statement of accounts|statement from|statement summary|opening balance|dr count|cr count|\*\*continue\*\*/iu', $trim)) continue;

            // detect starting date
            if (preg_match('/^\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\b/u', $raw, $dm)) {
                $lnlen = mb_strlen($raw);
                $slice = function($start, $end=null) use ($raw, $lnlen) {
                    if ($start >= $lnlen) return '';
                    if ($end === null) return rtrim(mb_substr($raw, $start));
                    $len = max(0, $end - $start);
                    return rtrim(mb_substr($raw, $start, $len));
                };

                // slice columns
                $dateRaw = safe_trim(mb_substr($raw, $positions['date'], max(10, $positions['narr'] - $positions['date'])));
                $narration = safe_trim($slice($positions['narr'], $positions['ref']));
                $refField  = safe_trim($slice($positions['ref'], $positions['val']));
                $valueDt   = safe_trim($slice($positions['val'], $positions['with']));
                $withdraw  = safe_trim($slice($positions['with'], $positions['dep']));
                $deposit   = safe_trim($slice($positions['dep'], $positions['bal']));
                $balance   = safe_trim($slice($positions['bal']));

                // append continuation lines that don't start with date
                $j = $i + 1;
                while ($j < $nlines) {
                    $next = $lines[$j];
                    if ($next === null) break;
                    if (preg_match('/^\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\b/u', $next)) break; // new txn
                    $cont = safe_trim(mb_substr($next, $positions['narr'], max(0, $positions['ref'] - $positions['narr'])));
                    if ($cont !== '') $narration .= ' ' . preg_replace('/\s+/', ' ', $cont);
                    $j++;
                }
                $i = $j - 1; // outer loop will advance

                // Normalize date
                $txn_date = null;
                if (preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})/', $dateRaw, $ddm)) {
                    $dd = str_pad($ddm[1],2,'0',STR_PAD_LEFT);
                    $mm = str_pad($ddm[2],2,'0',STR_PAD_LEFT);
                    $yy = $ddm[3];
                    if (strlen($yy) === 2) $yy = '20' . $yy;
                    $txn_date = "$yy-$mm-$dd";
                }

                // amounts must match money token pattern to be accepted
                $moneyRe = '/\d{1,3}(?:,\d{3})*(?:\.\d{1,2})?/';
                $withdraw_val = ($withdraw !== '' && preg_match($moneyRe, $withdraw, $mW)) ? $mW[0] : '';
                $deposit_val  = ($deposit !== '' && preg_match($moneyRe, $deposit, $mD)) ? $mD[0] : '';
                $balance_val  = ($balance !== '' && preg_match($moneyRe, $balance, $mB)) ? $mB[0] : '';

                $withdraw_paise = ($withdraw_val === '') ? null : normalize_amount_to_paise($withdraw_val);
                $deposit_paise  = ($deposit_val === '') ? null : normalize_amount_to_paise($deposit_val);
                $balance_paise  = ($balance_val === '' ? 0 : normalize_amount_to_paise($balance_val));

                // defensive: ignore unrealistic parsed amounts (likely caused by mis-sliced columns)
                $MAX_VALIDATE_PAISE = 1000000000000; // 10^12 paise = 10^10 rupees (safety)
                if ($withdraw_paise !== null && abs($withdraw_paise) > $MAX_VALIDATE_PAISE) $withdraw_paise = null;
                if ($deposit_paise !== null && abs($deposit_paise) > $MAX_VALIDATE_PAISE) $deposit_paise = null;

                $txn_type = 'other';
                if ($withdraw_paise !== null && $withdraw_paise > 0) $txn_type = 'debit';
                elseif ($deposit_paise !== null && $deposit_paise > 0) $txn_type = 'credit';

                $narr_clean = preg_replace('/\s+/', ' ', $narration);

                // extract reference: prefer alnum token in refField (not pure amount)
                $reference = null;
                if ($refField !== '') {
                    if (preg_match('/([A-Za-z0-9\-\/]{5,})/', $refField, $rm)) $reference = $rm[1];
                }

                $amount_paise = $withdraw_paise !== null ? $withdraw_paise : ($deposit_paise !== null ? $deposit_paise : 0);

                $txns[] = [
                    'txn_date' => $txn_date,
                    'value_date' => $valueDt ?: null,
                    'narration' => $narr_clean,
                    'raw_line' => safe_trim($raw),
                    'reference' => $reference,
                    'txn_type' => $txn_type,
                    'amount_paise' => $amount_paise,
                    'debit_paise' => $withdraw_paise,
                    'credit_paise' => $deposit_paise,
                    'balance_paise' => $balance_paise
                ];
            }
        }

        return $txns;
    }

    // header not found: fallback to buffer approach (improved)
    $buffer = null;
    foreach ($lines as $ln) {
        $ln_trim = safe_trim($ln);
        if ($ln_trim === '') continue;
        if (preg_match('/^\s*(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\s+(.+)$/u', $ln_trim)) {
            if ($buffer !== null) {
                $tx = process_buffer_line($buffer);
                if ($tx) $txns[] = $tx;
            }
            $buffer = $ln_trim;
        } else {
            if ($buffer === null) continue;
            $buffer .= ' ' . $ln_trim;
        }
    }
    if ($buffer !== null) {
        $tx = process_buffer_line($buffer);
        if ($tx) $txns[] = $tx;
    }
    return $txns;
}
function process_buffer_line($line) {
    if ($line === null) return null;
    $s = preg_replace('/\s+/', ' ', safe_trim($line));

    // Money pattern
    $money = '[0-9]{1,3}(?:,[0-9]{3})*(?:\.\d{1,2})?';

    // Primary pattern: Date + Narration (non-greedy) + Ref (alnum) + ValueDt + (withdraw|deposit) + (withdraw|deposit) + ClosingBalance
    $pat = '/^(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\s+(.+?)\s+([A-Za-z0-9\-\/]{4,})\s+(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\s+(' . $money . ')?\s*(' . $money . ')?\s*(' . $money . ')$/';

    if (preg_match($pat, $s, $m)) {
        $date_raw = safe_trim($m[1]);
        $rest_narration = safe_trim($m[2]);
        $ref = safe_trim($m[3]);
        $value_dt_raw = safe_trim($m[4]);
        $maybe1 = $m[5] ?? '';
        $maybe2 = $m[6] ?? '';
        $balance = $m[7] ?? null;

        // Determine which of maybe1/maybe2 is withdrawal/deposit: HDFC layout commonly has withdrawal column first then deposit
        $withdraw = '';
        $deposit = '';
        if ($maybe1 !== '' && $maybe2 !== '') {
            // both present -> use positions: earlier numeric likely withdraw; but safer: check whether one is much smaller (heuristic)
            $withdraw = $maybe1;
            $deposit  = $maybe2;
        } else if ($maybe1 !== '') {
            // only first is present -> could be either withdrawal or deposit depending on layout; use spacing heuristics:
            // If narration contains 'UPI-' and balance is greater than this amount then treat as deposit else debit.
            $withdraw = $maybe1; $deposit = '';
        } else if ($maybe2 !== '') {
            $withdraw = ''; $deposit = $maybe2;
        }

    } else {
        // Fallback: match date + narration + value_date + last three numeric tokens
        $fallback = '/^(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\s+(.+?)\s+(\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4})\s+(' . $money . ')?\s*(' . $money . ')?\s*(' . $money . ')$/';
        if (preg_match($fallback, $s, $mm)) {
            $date_raw = safe_trim($mm[1]);
            $rest_narration = safe_trim($mm[2]);
            $ref = null;
            $value_dt_raw = safe_trim($mm[3]);
            $withdraw = $mm[4] ?? '';
            $deposit  = $mm[5] ?? '';
            $balance  = $mm[6] ?? null;
        } else {
            return null; // can't parse
        }
    }

    // Normalize dates
    $txn_date = null;
    if (!empty($date_raw) && preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})/', $date_raw, $dparts)) {
        $d = str_pad($dparts[1],2,'0',STR_PAD_LEFT);
        $mm = str_pad($dparts[2],2,'0',STR_PAD_LEFT);
        $yy = $dparts[3]; if (strlen($yy) === 2) $yy = '20' . $yy;
        $txn_date = "$yy-$mm-$d";
    }

    $value_date = null;
    if (!empty($value_dt_raw) && preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})/', $value_dt_raw, $vparts)) {
        $vd = str_pad($vparts[1],2,'0',STR_PAD_LEFT);
        $vm = str_pad($vparts[2],2,'0',STR_PAD_LEFT);
        $vy = $vparts[3]; if (strlen($vy) === 2) $vy = '20' . $vy;
        $value_date = "$vy-$vm-$vd";
    }

    $narration_full = $rest_narration;

    // amounts - only accept if match money regex (this avoids interpreting references as money)
    $withdraw_paise = ($withdraw === '' ? null : normalize_amount_to_paise(preg_replace('/[^\d\.,\-]/','', $withdraw)));
    $deposit_paise  = ($deposit === '' ? null : normalize_amount_to_paise(preg_replace('/[^\d\.,\-]/','', $deposit)));
    $balance_paise  = ($balance === null ? 0 : normalize_amount_to_paise(preg_replace('/[^\d\.,\-]/','', $balance)));

    // defensive clamp (avoid insane values)
    $MAX = 1000000000000;
    if ($withdraw_paise !== null && abs($withdraw_paise) > $MAX) $withdraw_paise = null;
    if ($deposit_paise !== null && abs($deposit_paise) > $MAX) $deposit_paise = null;

    $txn_type = 'other';
    if ($withdraw_paise !== null && $withdraw_paise > 0) $txn_type = 'debit';
    else if ($deposit_paise !== null && $deposit_paise > 0) $txn_type = 'credit';

    $reference = ($ref !== null ? trim($ref) : null);

    $amount_paise = $withdraw_paise !== null ? $withdraw_paise : ($deposit_paise !== null ? $deposit_paise : 0);

    // try to create a clean alias candidate
    $alias_candidate = extract_alias_candidate($narration_full);

    return [
        'txn_date' => $txn_date,
        'value_date' => $value_date,
        'narration' => $narration_full,
        'raw_line' => $s,
        'reference' => $reference,
        'txn_type' => $txn_type,
        'amount_paise' => $amount_paise,
        'debit_paise' => $withdraw_paise,
        'credit_paise' => $deposit_paise,
        'balance_paise' => $balance_paise,
        'alias_candidate' => $alias_candidate
    ];
}


/**
 * parse_csv_and_insert
 * Reads CSV file at $storedPath and inserts transactions.
 * Returns number of inserted rows.
 */
function parse_csv_and_insert($db, $stmtId, $userId, $storedPath) {
    // update statement status
    try { $db->execute('UPDATE statements SET parse_status = ?, updated_at = NOW() WHERE id = ?', ['parsing', $stmtId]); } catch (Throwable $_) {}

    // open file safely
    $fh = @fopen($storedPath, 'r');
    if (!$fh) throw new RuntimeException('Cannot open CSV file');

    // read header
    $header = fgetcsv($fh);
    if ($header === false) { fclose($fh); throw new RuntimeException('CSV empty or unreadable'); }
    // normalize header names (lowercase trimmed)
    $hmap = [];
    foreach ($header as $i => $h) {
        $key = mb_strtolower(trim((string)$h), 'UTF-8');
        $hmap[$key] = $i;
    }

    // helper to lookup a column by several aliases
    $col = function(array $alts) use ($hmap) {
        foreach ($alts as $a) {
            $a = mb_strtolower(trim($a), 'UTF-8');
            if (array_key_exists($a, $hmap)) return $hmap[$a];
        }
        return null;
    };

    // column indexes (support alternate names)
    $c_txn_date = $col(['txn_date','date']);
    $c_value_date = $col(['value_date','value dt','value_dt']);
    $c_counterparty = $col(['counterparty','counter_party','counter-party']);
    $c_narration = $col(['narration','description','narr']);
    $c_reference = $col(['reference','reference_number','ref','ref_no']);
    $c_txn_type = $col(['txn_type','type']);
    $c_amount_rupees = $col(['amount_rupees','amount','amount_paise','amt_rupees']);
    $c_debit_paise = $col(['debit_paise','debit']);
    $c_credit_paise = $col(['credit_paise','credit']);
    $c_balance_rupees = $col(['balance_rupees','balance','balance_paise']);
    $c_raw_line = $col(['raw_line','raw','rawline']);
    // --- Header-type flags: detect whether CSV headers already use paise integer columns ---
    $has_amount_paise_header  = array_key_exists('amount_paise', $hmap);
    $has_debit_paise_header   = array_key_exists('debit_paise', $hmap);
    $has_credit_paise_header  = array_key_exists('credit_paise', $hmap);
    $has_balance_paise_header = array_key_exists('balance_paise', $hmap);

    // Also detect rupee-named headers for clarity (optional)
    $has_amount_rupees_header = array_key_exists('amount_rupees', $hmap) || array_key_exists('amount', $hmap);
    $has_balance_rupees_header = array_key_exists('balance_rupees', $hmap) || array_key_exists('balance', $hmap);

    // minimal header validation
    if ($c_txn_date === null || $c_narration === null) {
        fclose($fh);
        throw new RuntimeException('CSV missing required columns: txn_date and narration are required');
    }

    $rows = [];
    $rowCount = 0;
    // read each CSV row
    while (($row = fgetcsv($fh)) !== false) {
        // skip blank rows
        if (count($row) === 1 && trim((string)$row[0]) === '') continue;
        // build associative mapping
        $rec = [];
        $rec['txn_date'] = isset($row[$c_txn_date]) ? trim((string)$row[$c_txn_date]) : '';
        $rec['value_date'] = ($c_value_date !== null && isset($row[$c_value_date])) ? trim((string)$row[$c_value_date]) : null;
        $rec['counterparty'] = ($c_counterparty !== null && isset($row[$c_counterparty])) ? trim((string)$row[$c_counterparty]) : '';
        $rec['narration'] = isset($row[$c_narration]) ? trim((string)$row[$c_narration]) : '';
        $rec['reference'] = ($c_reference !== null && isset($row[$c_reference])) ? trim((string)$row[$c_reference]) : null;
        $rec['txn_type'] = ($c_txn_type !== null && isset($row[$c_txn_type])) ? strtolower(trim((string)$row[$c_txn_type])) : null;
        $rec['raw_line'] = ($c_raw_line !== null && isset($row[$c_raw_line])) ? trim((string)$row[$c_raw_line]) : ($rec['txn_date'] . ' ' . $rec['narration']);
        // amounts: priority: debit_paise/credit_paise columns, else amount (which might be rupees or paise)
        // We use header flags ($has_*_paise_header, $has_amount_rupees_header) to avoid double-conversion.

        $debit_paise = null;
        $credit_paise = null;

        // debit column present
        if ($c_debit_paise !== null && isset($row[$c_debit_paise]) && trim((string)$row[$c_debit_paise]) !== '') {
            $d = trim((string)$row[$c_debit_paise]);
            if ($has_debit_paise_header) {
                $debit_paise = is_numeric(str_replace(',', '', $d)) ? intval(str_replace(',', '', $d)) : normalize_amount_to_paise($d);
            } else {
                $debit_paise = normalize_amount_to_paise($d);
            }
        }

        // credit column present
        if ($c_credit_paise !== null && isset($row[$c_credit_paise]) && trim((string)$row[$c_credit_paise]) !== '') {
            $cval = trim((string)$row[$c_credit_paise]);
            if ($has_credit_paise_header) {
                $credit_paise = is_numeric(str_replace(',', '', $cval)) ? intval(str_replace(',', '', $cval)) : normalize_amount_to_paise($cval);
            } else {
                $credit_paise = normalize_amount_to_paise($cval);
            }
        }

        // fallback: amount column may be "amount_paise" (integer) or "amount"/"amount_rupees" (rupees)
        if (($debit_paise === null || $debit_paise === 0) && ($credit_paise === null || $credit_paise === 0)
            && $c_amount_rupees !== null && isset($row[$c_amount_rupees]) && trim((string)$row[$c_amount_rupees]) !== '') {
            $amtRaw = trim((string)$row[$c_amount_rupees]);
            if ($has_amount_paise_header) {
                $ap = is_numeric(str_replace(',', '', $amtRaw)) ? intval(str_replace(',', '', $amtRaw)) : normalize_amount_to_paise($amtRaw);
            } else {
                $ap = normalize_amount_to_paise($amtRaw);
            }
            if ($rec['txn_type'] === 'debit' || $rec['txn_type'] === 'dr') $debit_paise = $ap;
            else if ($rec['txn_type'] === 'credit' || $rec['txn_type'] === 'cr') $credit_paise = $ap;
            else $debit_paise = $ap; // legacy default
        }

        // final normalization
        $debit_paise  = ($debit_paise  === null) ? null : intval($debit_paise);
        $credit_paise = ($credit_paise === null) ? null : intval($credit_paise);

        if (($debit_paise === null || $debit_paise === 0) && ($credit_paise === null || $credit_paise === 0)) {
            // fallback to amount_rupees column
            if ($c_amount_rupees !== null && isset($row[$c_amount_rupees]) && trim((string)$row[$c_amount_rupees]) !== '') {
                $amt = trim((string)$row[$c_amount_rupees]);
                // if amount column contains paise as integer, normalize_amount_to_paise will handle both
                $ap = normalize_amount_to_paise($amt);
                // Determine inferred txn type from txn_type column if present
                if ($rec['txn_type'] === 'debit' || $rec['txn_type'] === 'dr') {
                    $debit_paise = $ap;
                } else if ($rec['txn_type'] === 'credit' || $rec['txn_type'] === 'cr') {
                    $credit_paise = $ap;
                } else {
                    // No txn_type hint — decide by sign or by sample heuristic: if balance increases then credit
                    // As CSV is pre-filtered we assume positive amounts with txn_type blank are credit if closing balance is greater than previous? Can't determine here — default to debit if ambiguous.
                    // To be safe: treat as debit if file has "debit_paise" header missing — but since your CSV generator sets txn_type, we'll trust it.
                    $debit_paise = $ap;
                }
            }
        }

        // normalize txn_date to YYYY-MM-DD if possible
        $txn_date_norm = null;
        if (!empty($rec['txn_date']) && preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})/', $rec['txn_date'], $dm)) {
            $d = str_pad($dm[1],2,'0',STR_PAD_LEFT);
            $m = str_pad($dm[2],2,'0',STR_PAD_LEFT);
            $y = $dm[3]; if (strlen($y) === 2) $y = '20' . $y;
            $txn_date_norm = "$y-$m-$d";
        } elseif (preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $rec['txn_date'])) {
            $txn_date_norm = $rec['txn_date'];
        } else {
            $txn_date_norm = null;
        }

        // if we have value_date normalize similarly
        $value_date_norm = null;
        if (!empty($rec['value_date']) && preg_match('/(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{2,4})/', $rec['value_date'], $vm)) {
            $vd = str_pad($vm[1],2,'0',STR_PAD_LEFT);
            $vmn = str_pad($vm[2],2,'0',STR_PAD_LEFT);
            $vy = $vm[3]; if (strlen($vy) === 2) $vy = '20' . $vy;
            $value_date_norm = "$vy-$vmn-$vd";
        } elseif (!empty($rec['value_date']) && preg_match('/^\d{4}\-\d{2}\-\d{2}$/', $rec['value_date'])) {
            $value_date_norm = $rec['value_date'];
        }

        // decide txn_type reliably
        $tt = $rec['txn_type'];
        if (!$tt) {
            if ($debit_paise !== null && $debit_paise > 0) $tt = 'debit';
            else if ($credit_paise !== null && $credit_paise > 0) $tt = 'credit';
            else $tt = 'other';
        } else {
            $tt = strtolower($tt);
            if (in_array($tt, ['dr','debit'])) $tt = 'debit';
            else if (in_array($tt, ['cr','credit'])) $tt = 'credit';
            else $tt = 'other';
        }
        $balance_paise = null;
        if ($c_balance_rupees !== null && isset($row[$c_balance_rupees]) && trim((string)$row[$c_balance_rupees]) !== '') {
            $balRaw = trim((string)$row[$c_balance_rupees]);
            if ($has_balance_paise_header) {
                $balance_paise = is_numeric(str_replace(',', '', $balRaw)) ? intval(str_replace(',', '', $balRaw)) : normalize_amount_to_paise($balRaw);
            } else {
                // treat as rupees and convert
                $balance_paise = normalize_amount_to_paise($balRaw);
            }
        } else {
            $balance_paise = 0;
        }
        // fallback: compute amount_paise as debit or credit
        $amount_paise = ($debit_paise !== null && $debit_paise > 0) ? $debit_paise : (($credit_paise !== null && $credit_paise > 0) ? $credit_paise : 0);

        $rows[] = [
            'txn_date' => $txn_date_norm,
            'value_date' => $value_date_norm,
            'narration' => $rec['narration'],
            'raw_line' => $rec['raw_line'],
            'reference' => $rec['reference'],
            'txn_type' => $tt,
            'amount_paise' => intval($amount_paise),
            'debit_paise' => $debit_paise === null ? null : intval($debit_paise),
            'credit_paise' => $credit_paise === null ? null : intval($credit_paise),
            'balance_paise' => $balance_paise
        ];
        $rowCount++;
    } // end while

    fclose($fh);

    // insert into DB within transaction (same INSERT used in parse_and_insert)
    $db->beginTransaction();
    try {
        $insertSql = "INSERT INTO transactions (statement_id, user_id, account_id, txn_date, narration, raw_line, reference_number, txn_type, amount_paise, debit_paise, credit_paise, balance_paise, txn_checksum, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()) 
            ON DUPLICATE KEY UPDATE updated_at = NOW()";
        foreach ($rows as $r) {
            $account_id = null;
            // attach the statement account if present
            $stmtRow = $db->fetch('SELECT account_id FROM statements WHERE id = ? LIMIT 1', [$stmtId]);
            if ($stmtRow) $account_id = isset($stmtRow['account_id']) ? intval($stmtRow['account_id']) : null;
            $txn_checksum = compute_txn_checksum($account_id, $r['txn_date'], $r['amount_paise'], $r['reference'], $r['narration']);
            $db->query($insertSql, [
                $stmtId, $userId, $account_id, $r['txn_date'], $r['narration'], $r['raw_line'], $r['reference'], $r['txn_type'], intval($r['amount_paise']), ($r['debit_paise']===null?null:intval($r['debit_paise'])), ($r['credit_paise']===null?null:intval($r['credit_paise'])), intval($r['balance_paise']), $txn_checksum
            ]);
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }

    // run grouping and finalize
    run_grouping_scanner($db, $userId);

    try {
        $count = $db->fetch('SELECT COUNT(*) as c FROM transactions WHERE statement_id = ?', [$stmtId])['c'] ?? 0;
        $db->execute('UPDATE statements SET parse_status = ?, parsed_at = NOW(), tx_count = ?, updated_at = NOW() WHERE id = ?', ['parsed', intval($count), $stmtId]);
        $db->logParse($stmtId,'info','csv_parse_complete',['inserted'=>$count], $userId);
    } catch (Throwable $e) {
        $db->logParse($stmtId,'warning','final_update_failed',['err'=>$e->getMessage()], $userId);
    }

    return $rowCount;
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
    $n = normalize_narration($narration);
    if ($n === '') return '';

    // Remove known prefixes
    $n = preg_replace('/^(rev-|upi-|nwd-|pos-|fee-|int-|cr-|dr-|\brev\b-?)/i', '', $n);

    // Replace multiple spaces
    $n = preg_replace('/\s+/', ' ', trim($n));

    // If UPI token exists, try to capture the human name portion before the UPI id or before numeric tokens
    // Typical forms: "UPI-VIDYASAGAR CHAUDHARI-9770472492@AXIS" or "UPI-VEDANT DEVENDRA PADH-VEDANTPADHIYAR1 78@OKICICI..."
    if (preg_match('/upi-([^@0-9]+?)(?:@|-[0-9@]|-[a-z0-9]{2,}|$)/i', $n, $m)) {
        $cand = trim($m[1]);
    } else {
        // otherwise split by '-' and take the first meaningful chunk that is not phone/upi/ref
        $parts = preg_split('/\s*-\s*/', $n);
        $cand = '';
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p === '') continue;
            // skip tokens that look like UPI ids / bank codes or long numbers
            if (preg_match('/@/',$p)) break;
            if (preg_match('/\d{6,}/', preg_replace('/\D/','', $p))) break;
            if (preg_match('/^(ubn|utib|hdfc|okicici|yesb|sbi|axis|paytm|sbip|pnr|bank|zomato|google|gpay)/i', $p)) break;
            // skip tokens that are likely "PAYMENT FROM PHONE" etc
            if (preg_match('/payment from|paid via|payment/i', $p)) break;

            $cand = $p;
            break;
        }
        if ($cand === '') {
            // fallback: take first word chunk up to an '@' or first numeric token
            $tmp = preg_split('/[@0-9]/', $n);
            $cand = trim($tmp[0] ?? $n);
        }
    }

    // remove trailing short tokens or codes
    $cand = preg_replace('/\b(upi|paytm|gpay|sbip|bank|utm|payu|merch|online|payment|paymentfrom)\b/i', '', $cand);
    $cand = trim(preg_replace('/[\.\,\/\_]+$/', '', $cand));
    // keep at most 3 words
    $cand = implode(' ', array_slice(explode(' ', $cand), 0, 3));
    $cand = preg_replace('/\s+/', ' ', $cand);
    // Title Case
    $cand = mb_convert_case($cand, MB_CASE_TITLE, "UTF-8");
    return $cand;
}


function run_grouping_scanner($db, $userId){
    // transactions with no counterparty
    $txs = $db->fetchAll('SELECT id, narration FROM transactions WHERE user_id = ? AND (counterparty_id IS NULL OR counterparty_id = 0)', [$userId]);
    if (empty($txs)) return;

    $candidates = [];
    foreach ($txs as $t) {
        $cand = extract_alias_candidate($t['narration']);
        $cand = safe_trim($cand);
        if ($cand === '') continue;

        $ck = substr(normalize_narration($cand), 0, 200);
        if ($ck === '') continue;

        // blacklist common junk
        $blacklist = [
            'payment from phone','payment from','payment','paid via','upi','pos','nwd','paymentfrom'
        ];
        if (in_array($ck, $blacklist, true)) continue;

        if (!isset($candidates[$ck])) $candidates[$ck] = ['count'=>0,'tx_ids'=>[],'sample'=>$cand];
        $candidates[$ck]['count']++;
        $candidates[$ck]['tx_ids'][] = $t['id'];
    }

    foreach ($candidates as $ck => $info) {
        if ($info['count'] < 2) continue; // require at least 2 occurrences
        $sample = safe_trim($info['sample']);
        if ($sample === '') continue;

        // canonical (title case)
        $canonical = mb_convert_case($sample, MB_CASE_TITLE, 'UTF-8');
        $canonical = mb_substr($canonical, 0, 255);

        // 1) try to find alias mapping
        $aliasRow = $db->fetch('SELECT ca.counterparty_id FROM counterparty_aliases ca JOIN counterparties cp ON ca.counterparty_id = cp.id WHERE ca.alias = ? AND cp.user_id = ? LIMIT 1', [$ck, $userId]);
        $cp_id = $aliasRow['counterparty_id'] ?? null;

        // 2) try existing canonical match
        if (!$cp_id) {
            $existing = $db->fetch('SELECT id FROM counterparties WHERE user_id = ? AND canonical_name = ? LIMIT 1', [$userId, $canonical]);
            if ($existing) $cp_id = $existing['id'];
        }

        // 3) create new
        if (!$cp_id) {
            $cp_id = $db->insertAndGetId('INSERT INTO counterparties (user_id, canonical_name, type, first_seen, last_seen, tx_count, total_debit_paise, total_credit_paise, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW(), 0, 0, 0, NOW(), NOW())', [$userId, $canonical, 'other']);
        }

        // ensure alias exists
        $existsAlias = $db->fetch('SELECT id FROM counterparty_aliases WHERE counterparty_id = ? AND alias = ? LIMIT 1', [$cp_id, $ck]);
        if (!$existsAlias) $db->insertAndGetId('INSERT INTO counterparty_aliases (counterparty_id, alias, alias_type, created_at) VALUES (?, ?, ?, NOW())', [$cp_id, $ck, 'narration']);

        // update transactions in chunks (user-scoped)
        foreach (array_chunk($info['tx_ids'], 100) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $params = array_merge([$cp_id, $userId], $chunk);
            $sql = "UPDATE transactions SET counterparty_id = ?, updated_at = NOW() WHERE user_id = ? AND id IN ($placeholders)";
            $db->execute($sql, $params);
        }

        // recompute aggregates for that counterparty/user
        $agg = $db->fetch('SELECT COUNT(*) as cnt, SUM(debit_paise) as sdebit, SUM(credit_paise) as scredit FROM transactions WHERE counterparty_id = ? AND user_id = ?', [$cp_id, $userId]);
        $db->execute('UPDATE counterparties SET tx_count = ?, total_debit_paise = COALESCE(?,0), total_credit_paise = COALESCE(?,0), last_seen = NOW(), updated_at = NOW() WHERE id = ?', [ intval($agg['cnt']), intval($agg['sdebit'] ?? 0), intval($agg['scredit'] ?? 0), $cp_id ]);

        $db->logAudit('auto_group_created','counterparty',$cp_id,['alias'=>$ck,'tx_count'=>$info['count']], $userId);
    }
}