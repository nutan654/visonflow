<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/audit_log.php';

$conn = get_db_connection();

$table = $_GET['table'] ?? null;
$user = $_GET['user'] ?? null;
$from = $_GET['from'] ?? null;   // YYYY-MM-DD
$to = $_GET['to'] ?? null;
$page = max(1, (int)($_GET['page'] ?? 1));
$page_size = min(100, max(1, (int)($_GET['page_size'] ?? 25)));
$offset = ($page - 1) * $page_size;

$where = [];
$params = [];

if ($table) { $where[] = "table_affected = :table"; $params[':table'] = $table; }
if ($user) { $where[] = "username = :user"; $params[':user'] = $user; }
if ($from) { $where[] = "created_at >= :from"; $params[':from'] = $from . " 00:00:00"; }
if ($to) { $where[] = "created_at <= :to"; $params[':to'] = $to . " 23:59:59"; }

$where_sql = count($where) ? ("WHERE " . implode(" AND ", $where)) : "";

try {
    $count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM audit_log $where_sql");
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

    $stmt = $conn->prepare(
        "SELECT id, user_id, username, action, table_affected, record_id, old_value, new_value, created_at
         FROM audit_log $where_sql
         ORDER BY created_at DESC
         LIMIT :limit OFFSET :offset"
    );
    foreach ($params as $k => $v) { $stmt->bindValue($k, $v); }
    $stmt->bindValue(':limit', $page_size, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        "status" => "success",
        "data" => $rows,
        "pagination" => [
            "page" => $page,
            "page_size" => $page_size,
            "total" => $total,
            "total_pages" => (int)ceil($total / $page_size)
        ]
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Query failed: " . $e->getMessage()]);
}
