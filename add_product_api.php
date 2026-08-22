<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // 401 Unauthorized
    echo json_encode(["status" => "error", "message" => "Unauthorized. Please log in."]);
    exit();
}

require_once __DIR__ . '/audit_log.php';
$conn = get_db_connection();

$data = json_decode(file_get_contents("php://input"));

if(!empty($data->name) && !empty($data->price) && !empty($data->shape) && !empty($data->material)) {
    
    $query = "INSERT INTO products (name, shape, material, price, img) VALUES (:name, :shape, :material, :price, :img) RETURNING id";
    $stmt = $conn->prepare($query);

    $stmt->bindValue(':name', htmlspecialchars(strip_tags($data->name)));
    $stmt->bindValue(':shape', htmlspecialchars(strip_tags($data->shape)));
    $stmt->bindValue(':material', htmlspecialchars(strip_tags($data->material)));
    $stmt->bindValue(':price', $data->price);
    
    $img = !empty($data->img) ? htmlspecialchars(strip_tags($data->img)) : "https://via.placeholder.com/150";
    $stmt->bindValue(':img', $img);

    if($stmt->execute()) {
        require_once __DIR__ . '/audit_log.php';
        $new_id = (int)$stmt->fetchColumn();
        log_change($conn, 'CREATE', 'products', $new_id, null, [
            'name' => $data->name, 'shape' => $data->shape,
            'material' => $data->material, 'price' => $data->price, 'img' => $img
        ]);
        echo json_encode(["status" => "success", "message" => "Product added to inventory!"]);
    } else {
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Unable to add product to database."]);
    }
} else {
    http_response_code(400); // 400 Bad Request
    echo json_encode(["status" => "error", "message" => "Incomplete data. Please fill all fields."]);
}
?>