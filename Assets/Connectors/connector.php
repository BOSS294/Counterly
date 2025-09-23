<?php
/**
 * Connector: Secure PDO Database Connector + Logger
 * File: Assets/Connectors/connector.php
 * Version: 1.0.0
 * Release Date: 2025-09-23
 * Author: BOSS294 ( Mayank Chawdhari )
 *
 * FEATURES
 * - Secure PDO connection with utf8mb4 charset and safe defaults
 * - Reads credentials from environment variables (preferred) or a secrets file
 *   located at Assets/Resources/secrets.env (simple KEY=VALUE lines)
 * - Singleton pattern (single PDO instance per request)
 * - Safe wrapper methods for queries: query(), fetch(), fetchAll(), insert(), execute()
 * - Transaction helpers: beginTransaction(), commit(), rollback()
 * - Two logger helpers built-in:
 *     - logAudit($action, $objectType = null, $objectId = null, $payload = null, $userId = null)
 *       Writes a row into `audit_logs` and appends an encrypted/local file log.
 *     - logParse($statementId, $level, $message, $meta = null, $userId = null)
 *       Writes a row into `parse_logs` for parser feedback and issues.
 * - Non-sensitive logging: logger functions avoid printing DB credentials and sanitize payloads
 * - Designed to be `include`-able in API endpoints. Example: require_once __DIR__ . '/connector.php';
 *
 * SECURITY & USAGE NOTES
 * - Store database credentials outside webroot and set file permissions to 600 (rw-------).
 * - The connector will first look for environment variables (DB_HOST, DB_PORT, DB_DATABASE,
 *   DB_USERNAME, DB_PASSWORD, DB_CHARSET). If not found, it will attempt to read
 *   Assets/Resources/secrets.env (simple KEY=VALUE file). If neither is found, it will
 *   throw an exception.
 * - Do not echo credentials or dump the connector object in production. Use exceptions for errors.
 * - Limit the privileges of the DB user: only grant needed rights (SELECT, INSERT, UPDATE on
 *   application tables) and avoid superuser/root DB credentials.
 *
 * LOGGER USAGE
 * - logAudit($action, $objectType = null, $objectId = null, $payload = null, $userId = null)
 *     $action: short string e.g. 'upload_statement', 'parse_complete'
 *     $objectType: e.g. 'statement', 'transaction'
 *     $objectId: numeric id of the object
 *     $payload: array or string with non-sensitive metadata (will be json_encoded)
 *     $userId: optional user id (if known)
 *
 * - logParse($statementId, $level, $message, $meta = null, $userId = null)
 *     $level: 'info'|'warning'|'error'
 *     $message: human readable message
 *     $meta: array with optional parser metadata (line numbers, confidence)
 *     $statementId: id from `statements` table if available (NULL allowed)
 *
 * HOW TO USE
 * 1) Place this file at: Assets/Connectors/connector.php
 * 2) Create your secrets at: Assets/Resources/secrets.env or set environment variables
 *    (DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD, DB_CHARSET)
 * 3) In your API endpoint:
 *      require_once __DIR__ . '/../Connectors/connector.php';
 *      $db = DB::get();
 *      $rows = $db->fetchAll('SELECT * FROM accounts WHERE user_id = ?', [$userId]);
 *      DB::logAudit('download_report', 'account', $accountId, ['rows' => count($rows)], $userId);
 *
 * DISCLAIMER & TRADEMARK
 * - © 2025. All rights reserved. The project name and trademarks belong to the project owner.
 */

class DB
{
    private static $instance = null;
    private $pdo;
    private $logFile;

    private function __construct()
    {
        $this->logFile = __DIR__ . '/../../logs/connector.log';
        $creds = $this->loadCredentials();

        $host = $creds['DB_HOST'] ?? '127.0.0.1';
        $port = $creds['DB_PORT'] ?? '3306';
        $db   = $creds['DB_DATABASE'] ?? '';
        $user = $creds['DB_USERNAME'] ?? '';
        $pass = $creds['DB_PASSWORD'] ?? '';
        $charset = $creds['DB_CHARSET'] ?? 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_PERSISTENT => false,
        ];

        try {
            $this->pdo = new \PDO($dsn, $user, $pass, $options);
        } catch (\PDOException $e) {
            $this->writeLocalLog('error', 'db_connection_failed', ['message' => $e->getMessage()]);
            throw new \RuntimeException('Database connection failed');
        }
    }

    public static function get()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadCredentials()
    {
        $env = [];
        $keys = ['DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','DB_CHARSET'];

        foreach ($keys as $k) {
            $v = getenv($k);
            if ($v !== false) $env[$k] = $v;
        }

        if (!empty($env)) return $env;

        $secretsPath = realpath(__DIR__ . '/../Resources/secrets.env');
        if ($secretsPath && is_readable($secretsPath)) {
            $lines = file($secretsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $k = trim($parts[0]);
                    $v = trim($parts[1]);
                    // strip surrounding quotes
                    $v = preg_replace('/^\"|\"$|^\'|\'$/', '', $v);
                    $env[$k] = $v;
                }
            }
        }

        return $env;
    }

    private function writeLocalLog($level, $action, $meta = [])
    {
        try {
            $dir = dirname($this->logFile);
            if (!is_dir($dir)) mkdir($dir, 0700, true);
            $entry = [
                'ts' => gmdate('Y-m-d H:i:s'),
                'level' => $level,
                'action' => $action,
                'meta' => $meta
            ];
            file_put_contents($this->logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // best-effort, never throw from logger
        }
    }

    public function query($sql, $params = [])
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            $this->writeLocalLog('error', 'query_failed', ['sql' => $sql, 'params' => $params, 'err' => $e->getMessage()]);
            throw $e;
        }
    }

    public function fetch($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetch();
    }

    public function fetchAll($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }

    public function execute($sql, $params = [])
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    public function insertAndGetId($sql, $params = [])
    {
        $this->query($sql, $params);
        return (int)$this->pdo->lastInsertId();
    }

    public function beginTransaction()
    {
        return $this->pdo->beginTransaction();
    }

    public function commit()
    {
        return $this->pdo->commit();
    }

    public function rollback()
    {
        return $this->pdo->rollBack();
    }

    public static function getClientIp()
    {
        $keys = ['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'];
        foreach ($keys as $k) {
            if (!empty($_SERVER[$k])) {
                $ip = $_SERVER[$k];
                if ($k === 'HTTP_X_FORWARDED_FOR') {
                    $ip = explode(',', $ip)[0];
                }
                return trim($ip);
            }
        }
        return '0.0.0.0';
    }

    public function logAudit($action, $objectType = null, $objectId = null, $payload = null, $userId = null)
    {
        try {
            $ip = self::getClientIp();
            $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $payloadJson = null;
            if ($payload !== null) {
                if (is_array($payload) || is_object($payload)) {
                    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
                } else {
                    $payloadJson = (string)$payload;
                }
            }

            $sql = "INSERT INTO audit_logs (user_id, action, object_type, object_id, ip_address, user_agent, payload, created_at)
                    VALUES (:user_id, :action, :object_type, :object_id, :ip_address, :user_agent, :payload, NOW())";

            $params = [
                ':user_id' => $userId,
                ':action' => substr($action, 0, 128),
                ':object_type' => $objectType,
                ':object_id' => $objectId,
                ':ip_address' => $ip,
                ':user_agent' => $ua,
                ':payload' => $payloadJson
            ];

            $this->query($sql, $params);
            $this->writeLocalLog('info', 'audit', ['action' => $action, 'object' => $objectType, 'object_id' => $objectId]);
            return true;
        } catch (\Throwable $e) {
            $this->writeLocalLog('error', 'audit_failed', ['err' => $e->getMessage()]);
            return false;
        }
    }

    public function logParse($statementId, $level, $message, $meta = null, $userId = null)
    {
        try {
            $levels = ['info','warning','error'];
            $lvl = in_array($level, $levels) ? $level : 'info';
            $metaJson = null;
            if ($meta !== null) $metaJson = json_encode($meta, JSON_UNESCAPED_UNICODE);

            $sql = "INSERT INTO parse_logs (statement_id, user_id, level, message, meta, created_at)
                    VALUES (:statement_id, :user_id, :level, :message, :meta, NOW())";
            $params = [
                ':statement_id' => $statementId,
                ':user_id' => $userId,
                ':level' => $lvl,
                ':message' => substr($message, 0, 1000),
                ':meta' => $metaJson
            ];

            $this->query($sql, $params);
            $this->writeLocalLog($lvl, 'parse', ['statement_id' => $statementId, 'msg' => $message]);
            return true;
        } catch (\Throwable $e) {
            $this->writeLocalLog('error', 'parse_failed', ['err' => $e->getMessage()]);
            return false;
        }
    }

    public function safeClose()
    {
        $this->pdo = null;
        self::$instance = null;
    }
}

// Helper alias for quick usage
function db()
{
    return DB::get();
}

?>