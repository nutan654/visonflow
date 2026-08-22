<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/audit_log.php';

$conn = get_db_connection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // list invoices, optional status filter
    $status = $_GET['status'] ?? null;
    $sql = "SELECT * FROM invoices";
    $params = [];
    if ($status) { $sql .= " WHERE status = :status"; $params[':status'] = $status; }
    $sql .= " ORDER BY issue_date DESC, id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit();
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit();
}

$data = json_decode(file_get_contents("php://input"));

if (empty($data->amount) || empty($data->due_date)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "amount and due_date are required"]);
    exit();
}

try {
    $issue_date = $data->issue_date ?? date('Y-m-d');
    $tax = isset($data->tax_amount) ? (float)$data->tax_amount : 0;
    $invoice_number = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));

    $stmt = $conn->prepare(
        "INSERT INTO invoices (invoice_number, order_id, customer_name, amount, tax_amount, status, issue_date, due_date)
         VALUES (:invoice_number, :order_id, :customer_name, :amount, :tax_amount, 'pending', :issue_date, :due_date)
         RETURNING id"
    );
    $stmt->execute([
        ':invoice_number' => $invoice_number,
        ':order_id' => $data->order_id ?? null,
        ':customer_name' => $data->customer_name ?? null,
        ':amount' => (float)$data->amount,
        ':tax_amount' => $tax,
        ':issue_date' => $issue_date,
        ':due_date' => $data->due_date,
    ]);

    $new_id = (int)$stmt->fetchColumn();
    log_change($conn, 'CREATE', 'invoices', $new_id, null, (array)$data + ['invoice_number' => $invoice_number]);

    echo json_encode(["status" => "success", "message" => "Invoice created", "invoice_id" => $new_id, "invoice_number" => $invoice_number]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Insert failed: " . $e->getMessage()]);
}
