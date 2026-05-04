<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;


// --- GRUPO: /usuarios ---

// POST /usuarios/login
$group->post('/login', function (Request $request, Response $response) use ($conexion) {

    $params = $request->getParsedBody();
    $email  = trim($params['email']    ?? '');
    $pass   = trim($params['password'] ?? '');

    if (empty($email) || empty($pass)) {
        return jsonResponse($response, "Email y contraseña son obligatorios", 400);
    }

    $userModel    = new Usuario($conexion);
    $datosUsuario = $userModel->login($email, $pass);

    if ($datosUsuario) {
        return jsonResponse($response, "Login correcto", 200, $datosUsuario);
    }

    return jsonResponse($response, "Email o contraseña incorrectos", 401);
});

// POST /usuarios/registro
$group->post('/registro', function (Request $request, Response $response) use ($conexion) {

    $params    = $request->getParsedBody();
    $nombre    = trim($params['nombre']    ?? '');
    $apellidos = trim($params['apellidos'] ?? '');
    $email     = trim($params['email']     ?? '');
    $pass      = trim($params['password']  ?? '');

    if (empty($nombre) || empty($apellidos) || empty($email) || empty($pass)) {
        return jsonResponse($response, "Todos los campos son obligatorios", 400);
    }

    // Validación extra: formato de email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return jsonResponse($response, "El formato del email no es válido", 400);
    }
    $userModel = new Usuario($conexion);
    $res       = $userModel->registrar($nombre, $apellidos, $email, $pass);

    if ($res === true) {
        return jsonResponse($response, "Usuario registrado correctamente", 201);
    }

    return jsonResponse($response, $res, 409);
});