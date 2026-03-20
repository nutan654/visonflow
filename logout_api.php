<?php
session_start();

$_SESSION = array();

session_destroy();

header("Content-Type: application/json");
echo json_encode(["status" => "success", "message" => "Logged out securely"]);
?>
