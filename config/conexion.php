<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Cargar .env si existe
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!getenv($key)) {
            putenv("$key=$value");
        }
    }
}

// Railway (producción) → .env (local) → XAMPP defaults
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
