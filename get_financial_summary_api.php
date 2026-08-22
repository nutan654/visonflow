<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/audit_log.php';

$conn = get_db_connection();

try {
    // Revenue: sum of paid invoice amounts, bucketed by month
    $rev_stmt = $conn->prepare(
        "SELECT TO_CHAR(p.payment_date, 'YYYY-MM') AS month, SUM(p.amount) AS revenue
         FROM payments p GROUP BY month ORDER BY month"
    );
    $rev_stmt->execute();
    $revenue_by_month = $rev_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Outstanding AR
    $ar_stmt = $conn->prepare(
        "SELECT COALESCE(SUM((amount + tax_amount) -
            COALESCE((SELECT SUM(amount) FROM payments p WHERE p.invoice_id = invoices.id), 0)), 0) AS outstanding_ar
         FROM invoices WHERE status IN ('pending','partially_paid','overdue')"
    );
    $ar_stmt->execute();
    $outstanding_ar = (float)$ar_stmt->fetch(PDO::FETCH_ASSOC)['outstanding_ar'];

    // Outstanding AP
    $ap_stmt = $conn->prepare(
        "SELECT COALESCE(SUM(quantity * unit_cost), 0) AS outstanding_ap
         FROM purchase_orders WHERE status IN ('ordered','received')"
    );
    $ap_stmt->execute();
    $outstanding_ap = (float)$ap_stmt->fetch(PDO::FETCH_ASSOC)['outstanding_ap'];

    // Inventory valuation: sum of quantity * unit_cost for received POs (proxy for stock on hand cost)
    $inv_val_stmt = $conn->prepare(
        "SELECT TO_CHAR(order_date, 'YYYY-MM') AS month, SUM(quantity * unit_cost) AS value
         FROM purchase_orders WHERE status IN ('received','paid') GROUP BY month ORDER BY month"
    );
    $inv_val_stmt->execute();
    $inventory_value_by_month = $inv_val_stmt->fetchAll(PDO::FETCH_ASSOC);

    $total_inventory_value = array_sum(array_column($inventory_value_by_month, 'value'));

    // Total revenue (all-time) and COGS estimate (all received/paid PO cost) -> gross margin
    $total_revenue = array_sum(array_column($revenue_by_month, 'revenue'));
    $cogs_stmt = $conn->prepare("SELECT COALESCE(SUM(quantity * unit_cost), 0) AS cogs FROM purchase_orders WHERE status IN ('received','paid')");
    $cogs_stmt->execute();
    $cogs = (float)$cogs_stmt->fetch(PDO::FETCH_ASSOC)['cogs'];
    $gross_margin = $total_revenue - $cogs;
    $gross_margin_pct = $total_revenue > 0 ? round(($gross_margin / $total_revenue) * 100, 1) : null;

    echo json_encode([
        "status" => "success",
        "revenue_by_month" => $revenue_by_month,
        "total_revenue" => $total_revenue,
        "outstanding_ar" => $outstanding_ar,
        "outstanding_ap" => $outstanding_ap,
        "inventory_value_by_month" => $inventory_value_by_month,
        "total_inventory_value" => $total_inventory_value,
        "cogs" => $cogs,
        "gross_margin" => $gross_margin,
        "gross_margin_pct" => $gross_margin_pct
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Query failed: " . $e->getMessage()]);
}
