<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// --- GRUPO: /alertas ---

// POST /alertas/crear
$group->post('/crear', function (Request $request, Response $response) use ($conexion) {
   
    $params      = $request->getParsedBody();
    $id_jornada  = (int)($params['id_jornada']  ?? 0);
    $tipo_alerta = trim($params['tipo_alerta']  ?? '');
    $mensaje     = trim($params['mensaje']      ?? '');

    $tiposValidos = [
        'conduccion_maxima_diaria',
        'pausa_obligatoria',
        'descanso_insuficiente',
        'conduccion_semanal',
        'conduccion_quincenal',
        'descanso_diario_insuficiente',
        'descanso_reducido_limite',
        'descanso_fraccionado_info'
    ];

    if ($id_jornada === 0 || empty($tipo_alerta) || empty($mensaje)) {
        return jsonResponse($response, "id_jornada, tipo_alerta y mensaje son obligatorios", 400);
    }

    if (!in_array($tipo_alerta, $tiposValidos)) {
        return jsonResponse($response, "tipo_alerta inválido", 400);
    }

    $alerta              = new Alerta($conexion);
    $alerta->id_jornada  = $id_jornada;
    $alerta->tipo_alerta = $tipo_alerta;
    $alerta->mensaje     = $mensaje;
    $id_alerta           = $alerta->crearAlerta();

    if ($id_alerta) {
        return jsonResponse($response, "Alerta creada", 201, ["id_alerta" => $id_alerta]);
    }

    return jsonResponse($response, "No se pudo crear la alerta", 500);
});

// GET /alertas/jornada/{id}
$group->get('/jornada/{id}', function (Request $request, Response $response, $args) use ($conexion) {
    
    $id_jornada = (int)$args['id'];
    $alerta     = new Alerta($conexion);
    $alertas    = $alerta->getAlertasJornada($id_jornada);

    $response->getBody()->write(json_encode(["status" => "success", "data" => $alertas]));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
});

// GET /alertas/noleidas/{id_usuario}
$group->get('/noleidas/{id_usuario}', function (Request $request, Response $response, $args) use ($conexion) {
    
    $id_usuario = (int)$args['id_usuario'];
    $alerta     = new Alerta($conexion);
    $alertas    = $alerta->getAlertasNoLeidas($id_usuario);

    return jsonResponse($response, "Alertas pendientes", 200, [
            "total" => count($alertas),
            "alertas" => $alertas
        ]);
});

// PUT /alertas/leer/{id}
$group->put('/leer/{id}', function (Request $request, Response $response, $args) use ($conexion) {
    
    $id_alerta = (int)$args['id'];
    $alerta    = new Alerta($conexion);
    if ($alerta->marcarLeida($id_alerta)) {
        return jsonResponse($response, "Alerta marcada como leída", 200);
    }

    return jsonResponse($response, "No se encontró la alerta", 404);
});

// PUT /alertas/leertodas/{id_jornada}
$group->put('/leertodas/{id_jornada}', function (Request $request, Response $response, $args) use ($conexion) {
   
    $id_jornada = (int)$args['id_jornada'];
    $alerta     = new Alerta($conexion);
    $res        = $alerta->marcarTodasLeidas($id_jornada);

    if ($res) {
        return jsonResponse($response, "Todas las alertas marcadas como leídas", 200);
    }
    return jsonResponse($response, "No se encontraron alertas para esta jornada", 404);
});

// POST /alertas/comprobar
$group->post('/comprobar', function (Request $request, Response $response) use ($conexion) {
    
    $params     = $request->getParsedBody();
    $id_jornada = (int)($params['id_jornada'] ?? 0);

    if ($id_jornada === 0) {
        return jsonResponse($response, "id_jornada es obligatorio", 400);
    }

    $stmtUser = $conexion->prepare("SELECT id_usuario FROM jornadas WHERE id_jornada = ?");
    $stmtUser->bind_param("i", $id_jornada);
    $stmtUser->execute();
    $filaUser   = $stmtUser->get_result()->fetch_assoc();
    $id_usuario = $filaUser ? (int)$filaUser['id_usuario'] : 0;

    $alerta           = new Alerta($conexion);
    $alertasGeneradas = $alerta->comprobarAlertas($id_jornada, $id_usuario);

    return jsonResponse($response, "Comprobación finalizada", 200, [
        "alertas_generadas" => $alertasGeneradas
    ]);
});