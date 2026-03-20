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
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Database connection failed"]);
    exit();
}

$data = json_decode(file_get_contents("php://input"));

if(
    !empty($data->first_name) && !empty($data->last_name) && !empty($data->email) && 
    !empty($data->eye_type) && isset($data->sphere) && isset($data->cylinder) && 
    isset($data->axis) && !empty($data->pd)
) {
    
    try {
        $conn->beginTransaction();

        $checkCustomer = $conn->prepare("SELECT id FROM customers WHERE email = :email");
        $checkCustomer->bindValue(':email', htmlspecialchars(strip_tags($data->email)));
        $checkCustomer->execute();

        $customer_id = null;

        if ($checkCustomer->rowCount() > 0) {
            $row = $checkCustomer->fetch(PDO::FETCH_ASSOC);
            $customer_id = $row['id'];
        } else {
            $insertCustomer = $conn->prepare("INSERT INTO customers (first_name, last_name, email, phone) VALUES (:first_name, :last_name, :email, :phone)");
            
            $insertCustomer->bindValue(':first_name', htmlspecialchars(strip_tags($data->first_name)));
            $insertCustomer->bindValue(':last_name', htmlspecialchars(strip_tags($data->last_name)));
            $insertCustomer->bindValue(':email', htmlspecialchars(strip_tags($data->email)));
            $insertCustomer->bindValue(':phone', htmlspecialchars(strip_tags($data->phone ?? '')));
            
            $insertCustomer->execute();
            
            $customer_id = $conn->lastInsertId();
        }

        $insertPrescription = $conn->prepare("INSERT INTO prescriptions (customer_id, eye_type, sphere, cylinder, axis, pd, exam_date) VALUES (:customer_id, :eye_type, :sphere, :cylinder, :axis, :pd, :exam_date)");

        $insertPrescription->bindValue(':customer_id', $customer_id);
        $insertPrescription->bindValue(':eye_type', htmlspecialchars(strip_tags($data->eye_type)));
        $insertPrescription->bindValue(':sphere', (float)$data->sphere);
        $insertPrescription->bindValue(':cylinder', (float)$data->cylinder);
        $insertPrescription->bindValue(':axis', (int)$data->axis);
        $insertPrescription->bindValue(':pd', (float)$data->pd);
        
        $exam_date = !empty($data->exam_date) ? $data->exam_date : date('Y-m-d');
        $insertPrescription->bindValue(':exam_date', $exam_date);

        $insertPrescription->execute();

        $conn->commit();

        http_response_code(200);
        echo json_encode(["status" => "success", "message" => "Customer and prescription saved successfully!"]);

    } catch(PDOException $e) {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Database error: " . $e->getMessage()]);
    }

} else {
    http_response_code(400); 
    echo json_encode(["status" => "error", "message" => "Incomplete data. Please fill all required fields."]);
}
?>