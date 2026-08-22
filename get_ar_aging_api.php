<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/audit_log.php';

$conn = get_db_connection();

try {
    // Only unpaid / partially paid invoices count toward outstanding AR
    $stmt = $conn->prepare(
        "SELECT id, invoice_number, customer_name, amount, tax_amount, status, issue_date, due_date,
                (CURRENT_DATE - due_date) AS days_past_due,
                (amount + tax_amount) - COALESCE((SELECT SUM(amount) FROM payments p WHERE p.invoice_id = invoices.id), 0) AS balance_due
         FROM invoices
         WHERE status IN ('pending','partially_paid','overdue')
         ORDER BY due_date ASC"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $buckets = [
        'current' => 0,      // not yet due
        '0_30' => 0,
        '31_60' => 0,
        '61_plus' => 0,
    ];
    $bucketed_invoices = [
        'current' => [], '0_30' => [], '31_60' => [], '61_plus' => []
    ];

    foreach ($rows as $row) {
        $days = (int)$row['days_past_due'];
        $balance = (float)$row['balance_due'];

        if ($days < 0) {
            $bucket = 'current';
        } elseif ($days <= 30) {
            $bucket = '0_30';
        } elseif ($days <= 60) {
            $bucket = '31_60';
        } else {
            $bucket = '61_plus';
        }

        $buckets[$bucket] += $balance;
        $bucketed_invoices[$bucket][] = $row;
    }

    echo json_encode([
        "status" => "success",
        "totals" => $buckets,
        "total_outstanding" => array_sum($buckets),
        "buckets" => $bucketed_invoices
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Query failed: " . $e->getMessage()]);
}
