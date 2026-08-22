<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/audit_log.php';

$conn = get_db_connection();
$data = json_decode(file_get_contents("php://input"));

if (empty($data->invoice_id) || empty($data->amount) || empty($data->payment_date)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "invoice_id, amount, payment_date are required"]);
    exit();
}

try {
    $conn->beginTransaction();

    $invoice_id = (int)$data->invoice_id;

    $inv_stmt = $conn->prepare("SELECT * FROM invoices WHERE id = :id FOR UPDATE");
    $inv_stmt->execute([':id' => $invoice_id]);
    $invoice = $inv_stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invoice) {
        $conn->rollBack();
        http_response_code(404);
        echo json_encode(["status" => "error", "message" => "Invoice not found"]);
        exit();
    }

    $pay_stmt = $conn->prepare(
        "INSERT INTO payments (invoice_id, amount, payment_date, method, reference)
         VALUES (:invoice_id, :amount, :payment_date, :method, :reference)
         RETURNING id"
    );
    $pay_stmt->execute([
        ':invoice_id' => $invoice_id,
        ':amount' => (float)$data->amount,
        ':payment_date' => $data->payment_date,
        ':method' => $data->method ?? 'bank_transfer',
        ':reference' => $data->reference ?? null,
    ]);
    $payment_id = (int)$pay_stmt->fetchColumn();

    // Recalculate status based on total paid vs invoice total (amount + tax)
    $sum_stmt = $conn->prepare("SELECT COALESCE(SUM(amount), 0) AS paid FROM payments WHERE invoice_id = :id");
    $sum_stmt->execute([':id' => $invoice_id]);
    $total_paid = (float)$sum_stmt->fetch(PDO::FETCH_ASSOC)['paid'];
    $total_due = (float)$invoice['amount'] + (float)$invoice['tax_amount'];

    $new_status = 'pending';
    if ($total_paid >= $total_due) {
        $new_status = 'paid';
    } elseif ($total_paid > 0) {
        $new_status = 'partially_paid';
    }

    $upd_stmt = $conn->prepare("UPDATE invoices SET status = :status WHERE id = :id");
    $upd_stmt->execute([':status' => $new_status, ':id' => $invoice_id]);

    log_change($conn, 'CREATE', 'payments', $payment_id, null, (array)$data);
    log_change($conn, 'UPDATE', 'invoices', $invoice_id, ['status' => $invoice['status']], ['status' => $new_status]);

    $conn->commit();

    echo json_encode([
        "status" => "success",
        "message" => "Payment recorded",
        "payment_id" => $payment_id,
        "invoice_status" => $new_status,
        "total_paid" => $total_paid,
        "total_due" => $total_due
    ]);
} catch (PDOException $e) {
    $conn->rollBack();
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Failed: " . $e->getMessage()]);
}
