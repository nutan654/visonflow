<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/audit_log.php';

$conn = get_db_connection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $conn->prepare(
        "SELECT v.*,
                COALESCE((SELECT SUM(po.quantity * po.unit_cost) FROM purchase_orders po WHERE po.vendor_id = v.id AND po.status != 'cancelled'), 0) AS total_po_value,
                COALESCE((SELECT SUM(po.quantity * po.unit_cost) FROM purchase_orders po WHERE po.vendor_id = v.id AND po.status IN ('ordered','received')), 0) AS outstanding_ap
         FROM vendors v ORDER BY v.name"
    );
    $stmt->execute();
    echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit();
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit();
}

$data = json_decode(file_get_contents("php://input"));

if (empty($data->name)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Vendor name is required"]);
    exit();
}

try {
    $stmt = $conn->prepare(
        "INSERT INTO vendors (name, contact_email, contact_phone, payment_terms_days)
         VALUES (:name, :email, :phone, :terms)
         RETURNING id"
    );
    $stmt->execute([
        ':name' => htmlspecialchars(strip_tags($data->name)),
        ':email' => $data->contact_email ?? null,
        ':phone' => $data->contact_phone ?? null,
        ':terms' => $data->payment_terms_days ?? 30,
    ]);
    $new_id = (int)$stmt->fetchColumn();
    log_change($conn, 'CREATE', 'vendors', $new_id, null, (array)$data);
    echo json_encode(["status" => "success", "message" => "Vendor added", "vendor_id" => $new_id]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Insert failed: " . $e->getMessage()]);
}
