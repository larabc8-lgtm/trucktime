<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// --- GRUPO: /jornadas ---

// POST /jornadas/abrir
$group->post('/abrir', function (Request $request, Response $response) use ($conexion) {

    $params     = $request->getParsedBody();
    $id_usuario = (int)($params['id_usuario'] ?? 0);

    if ($id_usuario === 0) {
        return jsonResponse($response, "id_usuario es obligatorio", 400);
    }

    $jornada             = new Jornada($conexion);
    $jornada->id_usuario = $id_usuario;
    $res                 = $jornada->abrirJornada();

    if (!is_array($res)) {
        return jsonResponse($response, $res ?: "Ya tienes una jornada abierta o error interno", 409);
    }

     
    // Generar alertas de descanso al abrir la jornada
    $id_jornada = $res['id_jornada'];
    $alerta     = new Alerta($conexion); 
    $alertasGeneradas    = [];  
 
    // Alerta: descanso insuficiente (< 9h)
    if ($res['tipo_descanso'] === 'insuficiente') {
        $alerta->id_jornada  = $id_jornada;
        $alerta->tipo_alerta = 'descanso_diario_insuficiente';
        $alerta->mensaje     = 'El descanso previo fue inferior a 9 horas. Esto incumple el Reglamento CE 561/2006.';
        if ($alerta->crearAlerta()) $alertasGeneradas[] = $alerta->tipo_alerta;
    }
 
    //Alerta: Descanso fraccionado (3h + 9h) ---
    if ($res['tipo_descanso'] === 'fraccionado') {
        $alerta->id_jornada  = $id_jornada;
        $alerta->tipo_alerta = 'descanso_insuficiente';
        $alerta->mensaje     = 'Tu descanso fue fraccionado (3h + 9h). Recuerda que solo es válido si completas ambos bloques.';
        if ($alerta->crearAlerta()) $alertasGeneradas[] = 'descanso_fraccionado';
    }
 
    // Alerta: límite de 3 descansos reducidos alcanzado
    if ($res['descansos_reducidos_semana'] >= 3) {
        $alerta->id_jornada  = $id_jornada;
        $alerta->tipo_alerta = 'descanso_reducido_limite';
        $alerta->mensaje     = 'Has agotado los 3 descansos reducidos esta semana. ';
        if ($alerta->crearAlerta()) $alertasGeneradas[] = $alerta->tipo_alerta;
    }
 
    $resultado = [
        "status"     => "success",
        "message"    => "Jornada abierta",
        "id_jornada" => $id_jornada,
        "descanso"   => [
            "tipo"               => $res['tipo_descanso'],
            "reducidos_semana"   => $res['descansos_reducidos_semana'],
            "reducidos_restantes"=> max(0, 3 - $res['descansos_reducidos_semana']),
        ],
        "alertas_generadas" => $alertasGeneradas
    ];

    return jsonResponse($response, "Jornada abierta correctamente", 201, $resultado);
});

// PUT /jornadas/cerrar/{id}
$group->put('/cerrar/{id}', function (Request $request, Response $response, $args) use ($conexion) {
    
    $id_jornada = (int)$args['id'];
    $jornada    = new Jornada($conexion);
    if ($jornada->cerrarJornada($id_jornada)) {
        return jsonResponse($response, "Jornada cerrada correctamente", 200);
    }

    return jsonResponse($response, "No se encontró jornada activa o error al cerrar", 404);
});

// GET /jornadas/usuario/{id}
$group->get('/usuario/{id}', function (Request $request, Response $response, $args) use ($conexion) {
  
    $id_usuario = (int)$args['id'];
    $jornada    = new Jornada($conexion);
    $jornadas   = $jornada->getJornadasUsuario($id_usuario);

    return jsonResponse($response, "Jornadas del usuario encontradas", 200, $jornadas);
});

// GET /jornadas/activa/{id_usuario}
$group->get('/activa/{id_usuario}', function (Request $request, Response $response, $args) use ($conexion) {
  
    $id_usuario = (int)$args['id_usuario'];
    $jornada    = new Jornada($conexion);
    $activa     = $jornada->getJornadaActiva($id_usuario);

    if ($activa) {
        return jsonResponse($response, "Jornada activa encontrada", 200, $activa);
    }

    return jsonResponse($response, "No hay jornada activa", 404);
});

// GET /jornadas/resumen/{id_usuario} → Resumen semanal y mensual
$group->get('/resumen/{id_usuario}', function (Request $request, Response $response, $args) use ($conexion) {
   
    $id_usuario = (int)$args['id_usuario'];
    $jornada    = new Jornada($conexion);
    $resumen    = $jornada->getResumenConduccion($id_usuario);
 
    return jsonResponse($response, "Resumen obtenido", 200, $resumen);
});