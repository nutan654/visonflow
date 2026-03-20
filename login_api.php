<?php
session_start(); 
header("Content-Type: application/json; charset=UTF-8");

$host = "db";
$db_name = "visionflow_db";
$db_user = "root"; 
$db_pass = ""; 

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name", $db_user, $db_pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $conn->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        password VARCHAR(255) NOT NULL,
        role VARCHAR(20) DEFAULT 'admin'
    )");

    $check = $conn->query("SELECT * FROM users WHERE username='admin'");
    if($check->rowCount() == 0) {
        $hashed_password = password_hash('admin', PASSWORD_DEFAULT);
        $conn->exec("INSERT INTO users (username, password, role) VALUES ('admin', '$hashed_password', 'admin')");
    }
    // ----------------------------------------------

} catch(PDOException $e) {
    echo json_encode(["status" => "error", "message" => "Database setup error: " . $e->getMessage()]);
    exit();
}

$data = json_decode(file_get_contents("php://input"));

if(isset($data->username) && isset($data->password)) {
    try {
        $stmt = $conn->prepare("SELECT id, username, password, role FROM users WHERE username = :username");
        $stmt->bindParam(':username', $data->username);
        $stmt->execute();

        if($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if(password_verify($data->password, $row['password'])) {
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['role'] = $row['role']; 
                $_SESSION['user'] = $row['username']; 

                http_response_code(200);
                echo json_encode([
                    "status" => "success", 
                    "message" => "Login successful",
                    "role" => $row['role'] 
                ]);
            } else {
                echo json_encode(["status" => "error", "message" => "Incorrect password"]);
            }
        } else {
            echo json_encode(["status" => "error", "message" => "User not found"]);
        }
    } catch(PDOException $e) {
        echo json_encode(["status" => "error", "message" => "Query failed: " . $e->getMessage()]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Missing credentials"]);
}
?>