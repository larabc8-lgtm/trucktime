<?php

require __DIR__ . '/vendor/autoload.php';
require_once 'includes.php';

use Slim\Factory\AppFactory;
use Slim\Routing\RouteCollectorProxy;

$conexion = require __DIR__ . '/config/conexion.php';

define('ROOT_PATH', __DIR__);

$app = AppFactory::create();
$app->setBasePath('/trucktime');
$app->addBodyParsingMiddleware();
$app->addErrorMiddleware(true, true, true);

$app->group('/usuarios', function (RouteCollectorProxy $group) use ($conexion) {
    require ROOT_PATH . '/endpoints/usuarios.endpoints.php';
});

$app->group('/jornadas', function (RouteCollectorProxy $group) use ($conexion) {
    require ROOT_PATH . '/endpoints/jornadas.endpoints.php';
});

$app->group('/registros', function (RouteCollectorProxy $group) use ($conexion) {
    require ROOT_PATH . '/endpoints/registros.endpoints.php';
});

$app->group('/alertas', function (RouteCollectorProxy $group) use ($conexion) {
    require ROOT_PATH . '/endpoints/alertas.endpoints.php';
});

$app->group('/descanso-semanal', function (RouteCollectorProxy $group) use ($conexion) {
    require ROOT_PATH . '/endpoints/descansoSemanal.endpoints.php';
});

$app->run();
?>