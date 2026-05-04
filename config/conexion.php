<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Busca las variables de Railway. Si NO existen, usa los valores locales de XAMPP.
$host = getenv('MYSQLHOST') ?: 'localhost';
$user = getenv('MYSQLUSER') ?: 'root'; 
$pass = getenv('MYSQLPASSWORD') ?: '';
$db   = getenv('MYSQLDATABASE') ?: 'camioneros_db';
$port = getenv('MYSQLPORT') ? (int)getenv('MYSQLPORT') : 3306;

$conexion = new mysqli($host, $user, $pass, $db, $port);
$conexion->set_charset("utf8mb4");

if ($conexion->connect_error) {
    error_log("Fallo de conexión: " . $conexion->connect_error);
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error interno del servidor"]);
    exit;
}
return $conexion;
?>