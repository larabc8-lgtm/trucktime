<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// --- GRUPO: /registros ---

// POST /registros/iniciar
$group->post('/iniciar', function (Request $request, Response $response) use ($conexion) {
    
    $params         = $request->getParsedBody();
    $id_jornada     = (int)($params['id_jornada']   ?? 0);
    $tipo_actividad = trim($params['tipo_actividad'] ?? '');
    $latitud        = $params['latitud']  ?? null;
    $longitud       = $params['longitud'] ?? null;

    $tiposValidos = ['conduccion', 'descanso', 'pausa', 'disponibilidad', 'otros_trabajos'];

    if ($id_jornada === 0 || empty($tipo_actividad)) {
        return jsonResponse($response, "id_jornada y tipo_actividad son obligatorios", 400);
    }

    if (!in_array($tipo_actividad, $tiposValidos)) {
        return jsonResponse($response, "tipo_actividad inválido. Permitidos: " . implode(', ', $tiposValidos), 400);
    }

    $registro                 = new RegistroActividad($conexion);
    $registro->id_jornada     = $id_jornada;
    $registro->tipo_actividad = $tipo_actividad;
    $registro->latitud        = $latitud  !== null ? (float)$latitud  : null;
    $registro->longitud       = $longitud !== null ? (float)$longitud : null;

    $id_registro = $registro->iniciarRegistro();

    if ($id_registro) {
        return jsonResponse($response, "Actividad iniciada: $tipo_actividad", 201, ["id_registro" => $id_registro]);
    }

    return jsonResponse($response, "No se pudo registrar la actividad", 500);
});

// GET /registros/jornada/{id}
$group->get('/jornada/{id}', function (Request $request, Response $response, $args) use ($conexion) {
    $id_jornada = (int)$args['id'];
    $registro   = new RegistroActividad($conexion);
    $registros  = $registro->getRegistrosJornada($id_jornada);

    return jsonResponse($response, "Registros de la jornada obtenidos", 200, $registros);
});

// GET /registros/conduccion/{id_jornada}
$group->get('/conduccion/{id_jornada}', function (Request $request, Response $response, $args) use ($conexion) {
    
    $id_jornada = (int)$args['id_jornada'];
    $registro   = new RegistroActividad($conexion);
    $data       = $registro->getMinutosConduccion($id_jornada);

    return jsonResponse($response, "Cálculo de conducción obtenido", 200, $data);
});