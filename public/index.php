<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Factory\AppFactory;
use TangyApi\Config;
use TangyApi\HubProxy;

require __DIR__ . '/../vendor/autoload.php';

$config = new Config();
$proxy = new HubProxy($config);

$app = AppFactory::create();
$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(
    $config->appEnv !== 'production',
    true,
    true
);

// Local edge liveness. This does NOT depend on TangyAIHub.
$app->get('/health', function (
    ServerRequestInterface $request,
    ResponseInterface $response
) use ($config): ResponseInterface {
    $response->getBody()->write(json_encode([
        'status' => 'ok',
        'service' => 'tangy-php-api',
        'environment' => $config->appEnv,
    ]));
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withHeader('Cache-Control', 'no-store');
});

// Public safe status is delegated to the internal Hub gateway.
$app->get('/v1/status/public', fn (ServerRequestInterface $request, ResponseInterface $response)
    => $proxy->forward($request, '/status/public', false));

// Login is delegated to the internal Hub gateway. The plaintext password
// traverses only HTTPS -> this edge -> the private Docker network.
$app->post('/v1/auth/login', fn (ServerRequestInterface $request, ResponseInterface $response)
    => $proxy->forward($request, '/auth/login', false));

$app->post('/v1/auth/logout', fn (ServerRequestInterface $request, ResponseInterface $response)
    => $proxy->forward($request, '/auth/logout', true));

$app->post('/v1/heartbeat', fn (ServerRequestInterface $request, ResponseInterface $response)
    => $proxy->forward($request, '/heartbeat', true));

// Authenticated API passthrough. Example:
// /v1/hub/memory/search -> internal gateway /memory/search
$app->any('/v1/hub[/{path:.*}]', function (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
) use ($proxy): ResponseInterface {
    $path = isset($args['path']) ? '/' . ltrim((string) $args['path'], '/') : '/';
    return $proxy->forward($request, $path, true);
});

// Keep the public edge boring: no directory listing, no debug homepage.
$app->get('/', function (
    ServerRequestInterface $request,
    ResponseInterface $response
): ResponseInterface {
    $response->getBody()->write(json_encode([
        'service' => 'Tangy AI API',
        'status' => 'online',
    ]));
    return $response
        ->withHeader('Content-Type', 'application/json')
        ->withHeader('Cache-Control', 'no-store');
});

$app->run();
