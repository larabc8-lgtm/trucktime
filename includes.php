<?php

$baseDir = __DIR__ . '/models';

require_once $baseDir . '/m_usuario.class.php';
require_once $baseDir . '/m_jornada.class.php';
require_once $baseDir . '/m_registroactividad.class.php';
require_once $baseDir . '/m_alerta.class.php';
require_once $baseDir . '/m_descansoSemanal.class.php';

/**
 * Función global para estandarizar las respuestas JSON en la API
 */
function jsonResponse($response, $message, $status, $data = null) {
    $payload = [
        "status"  => ($status >= 200 && $status < 300) ? "success" : "error",
        "message" => $message
    ];

    if ($data !== null) {
        $payload['data'] = $data;
    }

    $response->getBody()->write(json_encode($payload));
    
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withStatus($status);
}