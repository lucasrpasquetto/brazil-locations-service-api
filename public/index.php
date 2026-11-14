<?php

declare(strict_types=1);

use App\Controllers\LocationController;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Slim\App;

require __DIR__ . '/../vendor/autoload.php';

App\Env::load(__DIR__ . '/../.env');

/** @var App $app */
$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

$app->add(function (Request $request, $handler) {
    $response = $handler->handle($request);

    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Methods', 'GET, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type')
        ->withHeader('X-Content-Type-Options', 'nosniff');
});

$errorMiddleware = $app->addErrorMiddleware(false, true, true);
$errorMiddleware->setDefaultErrorHandler(function (Request $request, \Throwable $exception) use ($app): Response {
    $response = $app->getResponseFactory()->createResponse(500);
    $response->getBody()->write(json_encode([
        'error' => 'internal_error',
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE));

    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$c = new LocationController();

$app->get('/health', [$c, 'health']);
$app->get('/v1/locations/countries', [$c, 'countries']);
$app->get('/v1/locations/regions', [$c, 'regions']);
$app->get('/v1/locations/states', [$c, 'states']);
$app->get('/v1/locations/states/{uf}/cities', [$c, 'citiesByState']);
$app->get('/v1/locations/cities', [$c, 'searchCities']);
$app->get('/v1/locations/cities/{code}', [$c, 'showCity']);

$app->get('/openapi.json', function (Request $request, Response $response): Response {
    $response->getBody()->write((string) file_get_contents(__DIR__ . '/../docs/openapi.json'));

    return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
});

$app->get('/llms.txt', function (Request $request, Response $response): Response {
    $response->getBody()->write((string) file_get_contents(__DIR__ . '/../docs/llms.txt'));

    return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
});

$app->get('/', function (Request $request, Response $response): Response {
    $response->getBody()->write((string) file_get_contents(__DIR__ . '/../docs/index.html'));

    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

$app->get('/docs', function (Request $request, Response $response): Response {
    $response->getBody()->write((string) file_get_contents(__DIR__ . '/../docs/index.html'));

    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

$app->run();
