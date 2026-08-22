<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/audit_log.php';

$conn = get_db_connection();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $vendor_id = $_GET['vendor_id'] ?? null;
    $sql = "SELECT po.*, v.name AS vendor_name, (po.quantity * po.unit_cost) AS total_cost
            FROM purchase_orders po JOIN vendors v ON v.id = po.vendor_id";
    $params = [];
    if ($vendor_id) { $sql .= " WHERE po.vendor_id = :vendor_id"; $params[':vendor_id'] = $vendor_id; }
    $sql .= " ORDER BY po.order_date DESC, po.id DESC";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    echo json_encode(["status" => "success", "data" => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit();
}

if ($method === 'PUT') {
    // update PO status (e.g. mark received/paid)
    $data = json_decode(file_get_contents("php://input"));
    if (empty($data->id) || empty($data->status)) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "id and status are required"]);
        exit();
    }
    try {
        $before_stmt = $conn->prepare("SELECT * FROM purchase_orders WHERE id = :id");
        $before_stmt->execute([':id' => $data->id]);
        $before = $before_stmt->fetch(PDO::FETCH_ASSOC);

        $extra_date_sql = '';
        if ($data->status === 'received') $extra_date_sql = ", received_date = CURRENT_DATE";

        $stmt = $conn->prepare("UPDATE purchase_orders SET status = :status $extra_date_sql WHERE id = :id");
        $stmt->execute([':status' => $data->status, ':id' => $data->id]);

        log_change($conn, 'UPDATE', 'purchase_orders', (int)$data->id, ['status' => $before['status'] ?? null], ['status' => $data->status]);
        echo json_encode(["status" => "success", "message" => "PO updated"]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Update failed: " . $e->getMessage()]);
    }
    exit();
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    exit();
}

$data = json_decode(file_get_contents("php://input"));

if (empty($data->vendor_id) || empty($data->product_name) || empty($data->quantity) || empty($data->unit_cost)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "vendor_id, product_name, quantity, unit_cost are required"]);
    exit();
}

try {
    $po_number = 'PO-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
    $order_date = $data->order_date ?? date('Y-m-d');

    $stmt = $conn->prepare(
        "INSERT INTO purchase_orders (po_number, vendor_id, product_name, quantity, unit_cost, status, order_date, expected_date)
         VALUES (:po_number, :vendor_id, :product_name, :quantity, :unit_cost, 'ordered', :order_date, :expected_date)
         RETURNING id"
    );
    $stmt->execute([
        ':po_number' => $po_number,
        ':vendor_id' => (int)$data->vendor_id,
        ':product_name' => htmlspecialchars(strip_tags($data->product_name)),
        ':quantity' => (int)$data->quantity,
        ':unit_cost' => (float)$data->unit_cost,
        ':order_date' => $order_date,
        ':expected_date' => $data->expected_date ?? null,
    ]);

    $new_id = (int)$stmt->fetchColumn();
    log_change($conn, 'CREATE', 'purchase_orders', $new_id, null, (array)$data + ['po_number' => $po_number]);

    echo json_encode(["status" => "success", "message" => "Purchase order created", "po_id" => $new_id, "po_number" => $po_number]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Insert failed: " . $e->getMessage()]);
}
