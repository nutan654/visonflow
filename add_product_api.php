<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

if (!isset($_SESSION['user_id'])) {
    http_response_code(401); // 401 Unauthorized
    echo json_encode(["status" => "error", "message" => "Unauthorized. Please log in."]);
    exit();
}

$host = "db";
$db_name = "visionflow_db";
$username = "root"; 
$password = ""; 

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit();
}

$data = json_decode(file_get_contents("php://input"));

if(!empty($data->name) && !empty($data->price) && !empty($data->shape) && !empty($data->material)) {
    
    $query = "INSERT INTO products (name, shape, material, price, img) VALUES (:name, :shape, :material, :price, :img)";
    $stmt = $conn->prepare($query);

    $stmt->bindValue(':name', htmlspecialchars(strip_tags($data->name)));
    $stmt->bindValue(':shape', htmlspecialchars(strip_tags($data->shape)));
    $stmt->bindValue(':material', htmlspecialchars(strip_tags($data->material)));
    $stmt->bindValue(':price', $data->price);
    
    $img = !empty($data->img) ? htmlspecialchars(strip_tags($data->img)) : "https://via.placeholder.com/150";
    $stmt->bindValue(':img', $img);

    if($stmt->execute()) {
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