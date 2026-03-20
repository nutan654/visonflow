<?php

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$host = "db";
$db_name = "visionflow_db";
$username = "root"; 
$password = "";     

try {
    $conn = new PDO("mysql:host=" . $host . ";dbname=" . $db_name, $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $exception) {
    http_response_code(500); 
    echo json_encode(array("message" => "Database connection failed: " . $exception->getMessage()));
    exit();
}

try {
    $query = "SELECT id, name, shape, material, price, img FROM products WHERE stock_status != 'out_of_stock'";
    $stmt = $conn->prepare($query);
    $stmt->execute();

    $products = array();

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        
        $row['price'] = (float)$row['price'];
        array_push($products, $row);
    }

    http_response_code(200); 
    echo json_encode($products);

} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(array("message" => "Query failed."));
}
?>