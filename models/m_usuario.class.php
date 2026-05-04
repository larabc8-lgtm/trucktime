<?php
class Usuario {
    private $conn;
    private $table = "usuarios";

    // Propiedades que corresponden a las columnas de la tabla
    public $id_usuario;
    public $nombre;
    public $apellidos;
    public $email;
    public $password;
    public $activo;
    public $fecha_registro;

    public function __construct($conexion) {
        $this->conn = $conexion;
    }

    // Login con verificación bcrypt
    public function login($email, $pass) {
        $sql = "SELECT id_usuario, nombre, apellidos, email, password
                FROM " . $this->table . "
                WHERE email = ? AND activo = 1
                LIMIT 1";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $resultado = $stmt->get_result();

        if ($resultado->num_rows === 0) {
            return false;
        }

        $usuario = $resultado->fetch_assoc();

        if (!password_verify($pass, $usuario['password'])) {
            return false;
        }

        unset($usuario['password']);
        return $usuario;
    }

    // Registro con hash bcrypt
    public function registrar($nombre, $apellidos, $email, $pass) {
        // Comprobar si el email ya existe
        $check = $this->conn->prepare(
            "SELECT id_usuario FROM " . $this->table . " WHERE email = ?"
        );
        $check->bind_param("s", $email);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            return "El correo electrónico ya está registrado";
        }

        $hash = password_hash($pass, PASSWORD_BCRYPT);

        $sql = "INSERT INTO " . $this->table . "
                    (nombre, apellidos, email, password)
                VALUES (?, ?, ?, ?)";
        $stmt = $this->conn->prepare($sql);
        $stmt->bind_param("ssss", $nombre, $apellidos, $email, $hash);

        if ($stmt->execute()) {
            return true;
        }
        return "Error al guardar en la base de datos: " . $this->conn->error;
    }
}
?>