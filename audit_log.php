<?php
/**
 * Shared DB connection + audit logging helper.
 * Include this from any API endpoint that needs $conn or log_change().
 */

function get_db_connection() {
    $host = getenv("DB_HOST") ?: "db";
    $port = getenv("DB_PORT") ?: "5432";
    $db_name = getenv("DB_NAME") ?: "visionflow_db";
    $username = getenv("DB_USER") ?: "postgres";
    $password = getenv("DB_PASS") ?: "";

    try {
        $conn = new PDO("pgsql:host=$host;port=$port;dbname=$db_name;options='--search_path=visionflow,public'", $username, $password);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        ensure_schema($conn);
        return $conn;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database connection failed: " . $e->getMessage()]);
        exit();
    }
}

/**
 * Self-healing schema bootstrap.
 *
 * On Render (and most managed Postgres hosts), the SQL files under
 * migrations/ are NOT run automatically the way they are with local
 * `docker compose up` -- someone has to run them by hand once against the
 * database. If that manual step is skipped, every page that queries e.g.
 * `customers` or `prescriptions` fails with "relation ... does not exist".
 *
 * To make the app work out of the box regardless of whether that manual
 * step was done, every connection checks (cheaply, via to_regclass) whether
 * the schema already exists and, if not, applies both migration files
 * itself. Both files are idempotent (CREATE TABLE IF NOT EXISTS / INSERT
 * ... ON CONFLICT DO NOTHING), so running them here is always safe -- it
 * either creates everything + seed data on the very first request, or does
 * nothing at all once the schema is already in place.
 */
function ensure_schema($conn) {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    try {
        $exists = $conn->query("SELECT to_regclass('visionflow.customers')")->fetchColumn();
        if ($exists) return;
    } catch (Exception $e) {
        // fall through and try to bootstrap anyway
    }

    foreach (['migrations/000_base_schema.sql', 'migrations/001_finance_tables.sql'] as $rel) {
        $file = __DIR__ . '/' . $rel;
        if (!file_exists($file)) continue;
        try {
            $conn->exec(file_get_contents($file));
        } catch (PDOException $e) {
            error_log("ensure_schema: failed applying $rel: " . $e->getMessage());
        }
    }
}

/**
 * Record a change to the audit log.
 *
 * @param PDO    $conn
 * @param string $action          'CREATE' | 'UPDATE' | 'DELETE'
 * @param string $table           table name affected
 * @param int    $record_id       primary key of the affected row
 * @param mixed  $old_value       associative array/object or null
 * @param mixed  $new_value       associative array/object or null
 */
function log_change($conn, $action, $table, $record_id, $old_value = null, $new_value = null) {
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $user_id = $_SESSION['user_id'] ?? null;
    $username = $_SESSION['user'] ?? 'system';

    $stmt = $conn->prepare(
        "INSERT INTO audit_log (user_id, username, action, table_affected, record_id, old_value, new_value)
         VALUES (:user_id, :username, :action, :table_affected, :record_id, :old_value, :new_value)"
    );
    $stmt->execute([
        ':user_id'        => $user_id,
        ':username'       => $username,
        ':action'         => $action,
        ':table_affected' => $table,
        ':record_id'      => (int)$record_id,
        ':old_value'      => $old_value !== null ? json_encode($old_value) : null,
        ':new_value'      => $new_value !== null ? json_encode($new_value) : null,
    ]);
}
