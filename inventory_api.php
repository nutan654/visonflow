<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . '/audit_log.php';
$conn = get_db_connection();

$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents("php://input"));

try {
    switch ($method) {
        
        case 'POST':
            $stmt = $conn->prepare("INSERT INTO inventory (name, shape, material, price, image_url) VALUES (:name, :shape, :material, :price, :image_url) RETURNING id");
            $stmt->execute([
                ':name' => htmlspecialchars(strip_tags($data->name)),
                ':shape' => htmlspecialchars(strip_tags($data->shape)),
                ':material' => htmlspecialchars(strip_tags($data->material)),
                ':price' => (float)$data->price,
                ':image_url' => htmlspecialchars(strip_tags($data->image_url))
            ]);
            $new_id = (int)$stmt->fetchColumn();
            log_change($conn, 'CREATE', 'inventory', $new_id, null, (array)$data);
            echo json_encode(["status" => "success", "message" => "Product added successfully!"]);
            break;

        case 'GET':
            $stmt = $conn->prepare("SELECT * FROM inventory ORDER BY id DESC");
            $stmt->execute();
            $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(["status" => "success", "data" => $products]);
            break;

        case 'PUT':
            $before_stmt = $conn->prepare("SELECT * FROM inventory WHERE id = :id");
            $before_stmt->execute([':id' => (int)$data->id]);
            $before = $before_stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $conn->prepare("UPDATE inventory SET name = :name, price = :price WHERE id = :id");
            $stmt->execute([
                ':name' => htmlspecialchars(strip_tags($data->name)),
                ':price' => (float)$data->price,
                ':id' => (int)$data->id
            ]);
            log_change($conn, 'UPDATE', 'inventory', (int)$data->id, $before, [
                'name' => $data->name, 'price' => $data->price
            ]);
            echo json_encode(["status" => "success", "message" => "Product updated successfully!"]);
            break;

        case 'DELETE':
            $before_stmt = $conn->prepare("SELECT * FROM inventory WHERE id = :id");
            $before_stmt->execute([':id' => (int)$data->id]);
            $before = $before_stmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $conn->prepare("DELETE FROM inventory WHERE id = :id");
            $stmt->execute([':id' => (int)$data->id]);
            log_change($conn, 'DELETE', 'inventory', (int)$data->id, $before, null);
            echo json_encode(["status" => "success", "message" => "Product deleted!"]);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(["status" => "error", "message" => "Method not allowed"]);
            break;
    }
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "SQL Error: " . $e->getMessage()]);
}
?>