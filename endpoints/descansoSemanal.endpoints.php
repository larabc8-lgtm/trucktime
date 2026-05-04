<?php
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

// --- GRUPO: /descanso-semanal ---

// POST /descanso-semanal/marcar
// El conductor confirma que un registro es su descanso semanal
$group->post('/marcar', function (Request $request, Response $response) use ($conexion) {

    $params      = $request->getParsedBody();
    $id_registro = (int)($params['id_registro'] ?? 0);
    $id_usuario  = (int)($params['id_usuario']  ?? 0);

    if ($id_registro === 0 || $id_usuario === 0) {
        return jsonResponse($response, "id_registro e id_usuario son obligatorios", 400);
    }

    $descanso = new DescansoSemanal($conexion);
    $res      = $descanso->marcarDescansoSemanal($id_registro, $id_usuario);

    if (isset($res['error'])) {
        return jsonResponse($response, $res['error'], 400);
    }

    // Mensaje según tipo de descanso
    if ($res['tipo'] === 'normal') {
        $mensaje = "Descanso semanal normal registrado ({$res['duracion_h']}h {$res['duracion_m']}min). ¡Buen descanso!";
    } else {
        $mensaje = "Descanso semanal reducido registrado. Debes compensar {$res['deuda_h']}h en las próximas 3 semanas.";
    }

    return jsonResponse($response, $mensaje, 200, $res);
});

// GET /descanso-semanal/estado/{id_usuario}
// Estado actual: días trabajados, compensaciones pendientes
$group->get('/estado/{id_usuario}', function (Request $request, Response $response, $args) use ($conexion) {

    $id_usuario = (int)$args['id_usuario'];
    $descanso   = new DescansoSemanal($conexion);

    $dias        = $descanso->getDiasConsecutivosSinDescansoSemanal($id_usuario);
    $pendientes  = $descanso->getCompensacionesPendientes($id_usuario);

    // Nivel de alerta según días trabajados
    $nivelAlerta = 'ok';
    if ($dias >= 6)      $nivelAlerta = 'critico';
    elseif ($dias >= 5)  $nivelAlerta = 'aviso';

        $data   = [
            "dias_consecutivos"      => $dias,
            "dias_hasta_limite"      => max(0, 6 - $dias),
            "nivel_alerta"           => $nivelAlerta,
            "compensaciones_pendientes" => $pendientes,
            "total_horas_deuda"      => array_sum(array_column($pendientes, 'horas_deuda')),
        ];
    return jsonResponse($response, "Estado semanal obtenido", 200, $data);
});

// POST /descanso-semanal/comprobar
// Llamado al iniciar jornada para generar alertas semanales
$group->post('/comprobar', function (Request $request, Response $response) use ($conexion) {

    $params     = $request->getParsedBody();
    $id_jornada = (int)($params['id_jornada'] ?? 0);
    $id_usuario = (int)($params['id_usuario'] ?? 0);

    if ($id_jornada === 0 || $id_usuario === 0) {
        return jsonResponse($response, "id_jornada e id_usuario son obligatorios", 400);
    }

    $descanso         = new DescansoSemanal($conexion);
    $alertasGeneradas = $descanso->comprobarAlertasSemanales($id_jornada, $id_usuario);

    return jsonResponse($response, "Comprobación semanal realizada", 200, [
        "alertas_generadas" => $alertasGeneradas
    ]);
});

// GET /descanso-semanal/ultimo/{id_usuario}
// Obtener el último descanso semanal completado
$group->get('/ultimo/{id_usuario}', function (Request $request, Response $response, $args) use ($conexion) {

    $id_usuario = (int)$args['id_usuario'];
    $descanso   = new DescansoSemanal($conexion);
    $ultimo     = $descanso->getUltimoDescansoSemanal($id_usuario);

    return jsonResponse($response, "Último descanso obtenido", 200, $ultimo);
});
?>