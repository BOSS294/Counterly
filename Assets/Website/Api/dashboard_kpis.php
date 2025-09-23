<?php
header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'not_authenticated']);
    exit;
}

$userId = (int) $_SESSION['user_id'];

try {
    require_once __DIR__ . '/../../Connectors/connector.php'; 
    $db = db();

    $stmt = $db->fetchAll("SELECT COUNT(*) AS cnt, MAX(parsed_at) AS last_parsed FROM statements WHERE user_id = ?", [$userId]);
    $statements_count = (int)($stmt[0]['cnt'] ?? 0);
    $last_parsed = $stmt[0]['last_parsed'] ?? null;

    $tx = $db->fetch("
        SELECT 
            COUNT(*) AS tx_count,
            COALESCE(SUM(debit_paise),0) AS total_debit_paise,
            COALESCE(SUM(credit_paise),0) AS total_credit_paise
        FROM transactions
        WHERE user_id = ?
    ", [$userId]);

    $transactions_count = (int)($tx['tx_count'] ?? 0);
    $total_debit_paise = (int)($tx['total_debit_paise'] ?? 0);
    $total_credit_paise = (int)($tx['total_credit_paise'] ?? 0);

    $cp = $db->fetch("SELECT COUNT(*) AS cnt FROM counterparties WHERE user_id = ?", [$userId]);
    $counterparties_count = (int)($cp['cnt'] ?? 0);

    $lb = $db->fetch("SELECT balance_paise FROM transactions WHERE user_id = ? AND balance_paise IS NOT NULL ORDER BY txn_date DESC, id DESC LIMIT 1", [$userId]);
    $latest_balance_paise = $lb ? (int)$lb['balance_paise'] : null;

    $topCps = $db->fetchAll("
        SELECT c.canonical_name, c.tx_count, c.total_debit_paise, c.total_credit_paise
        FROM counterparties c
        WHERE c.user_id = ?
        ORDER BY c.tx_count DESC
        LIMIT 5
    ", [$userId]);

    $seriesByDate = $db->fetchAll("
        SELECT txn_date,
               COALESCE(SUM(debit_paise)/100,0) AS debit,
               COALESCE(SUM(credit_paise)/100,0) AS credit,
               COUNT(*) AS cnt
        FROM transactions
        WHERE user_id = ? AND txn_date >= CURDATE() - INTERVAL 29 DAY
        GROUP BY txn_date
        ORDER BY txn_date ASC
    ", [$userId]);

    $dates = [];
    $debitSeries = [];
    $creditSeries = [];
    foreach ($seriesByDate as $r) {
        $dates[] = $r['txn_date'];
        $debitSeries[] = (float)$r['debit'];
        $creditSeries[] = (float)$r['credit'];
    }

    $lastStatement = $db->fetch("SELECT id, filename, statement_from, statement_to, parsed_at FROM statements WHERE user_id = ? ORDER BY created_at DESC LIMIT 1", [$userId]);

    $response = [
        'user' => [
            'id' => $userId,
            'name' => $_SESSION['user_name'] ?? null,
            'email' => $_SESSION['user_email'] ?? null,
            'avatar' => $_SESSION['user_avatar'] ?? null,
            'role' => $_SESSION['user_role'] ?? 'Member',
        ],
        'kpis' => [
            'statements_count' => $statements_count,
            'transactions_count' => $transactions_count,
            'counterparties_count' => $counterparties_count,
            'total_debit' => $total_debit_paise / 100.0,
            'total_credit' => $total_credit_paise / 100.0,
            'latest_balance' => $latest_balance_paise !== null ? $latest_balance_paise / 100.0 : null,
            'last_parsed' => $last_parsed,
            'last_statement' => $lastStatement ?: null,
        ],
        'top_counterparties' => $topCps,
        'chart' => [
            'dates' => $dates,
            'debit' => $debitSeries,
            'credit' => $creditSeries
        ]
    ];

    echo json_encode($response);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'server_error', 'message' => $e->getMessage()]);
    exit;
}
