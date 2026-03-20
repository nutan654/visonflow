<?php
session_start();
header("Content-Type: application/json; charset=UTF-8");

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["status" => "error", "message" => "Unauthorized access."]);
    exit();
}

$host = "db";
$db_name = "visionflow_db";
$username = "root"; 
$password = ""; 

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    
    $query = "
        SELECT 
            customers.first_name, 
            customers.last_name, 
            customers.email,
            prescriptions.eye_type, 
            prescriptions.sphere, 
            prescriptions.cylinder, 
            prescriptions.axis,
            prescriptions.pd,
            prescriptions.exam_date
        FROM customers
        JOIN prescriptions ON customers.id = prescriptions.customer_id
        ORDER BY prescriptions.exam_date DESC, customers.last_name ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute();

    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

    http_response_code(200);
    echo json_encode(["status" => "success", "data" => $records]);

} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
}
?>