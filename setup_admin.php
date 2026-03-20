<?php
$host = "db";
$db_name = "visionflow_db";
$user = "root"; 
$pass = "";     

try {
    $conn = new PDO("mysql:host=$host;dbname=$db_name", $user, $pass);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $username = "admin";
    $password_raw = "admin123";
    $hashed_password = password_hash($password_raw, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES (:user, :pass)");
    $stmt->bindParam(':user', $username);
    $stmt->bindParam(':pass', $hashed_password);
    $stmt->execute();

    echo "Admin user created successfully! You can now delete this file.";
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>