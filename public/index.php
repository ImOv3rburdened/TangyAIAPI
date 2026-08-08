<?php
declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response;
use TangyApi\Config;
use TangyApi\HubProxy;

require __DIR__ . '/../vendor/autoload.php';

$config = new Config();
$proxy = new HubProxy($config);

$app = AppFactory::create();

$app->addBodyParsingMiddleware();
$app->addRoutingMiddleware();

$app->add(function (
    ServerRequestInterface $request,
    RequestHandlerInterface $handler
): ResponseInterface {
    $origin = $request->getHeaderLine('Origin');

    $allowedOrigins = [
        'https://tangycatteai.com',
        'https://www.tangycatteai.com',
    ];

    $isAllowedOrigin = in_array($origin, $allowedOrigins, true);

    if (strtoupper($request->getMethod()) === 'OPTIONS') {
        $response = new Response(204);

        if ($isAllowedOrigin) {
            $response = $response
                ->withHeader('Access-Control-Allow-Origin', $origin)
                ->withHeader('Access-Control-Allow-Credentials', 'true')
                ->withHeader(
                    'Access-Control-Allow-Methods',
                    'GET, POST, PUT, PATCH, DELETE, OPTIONS'
                )
                ->withHeader(
                    'Access-Control-Allow-Headers',
                    'Authorization, Content-Type, Accept, Origin, X-Requested-With'
                )
                ->withHeader('Access-Control-Max-Age', '86400')
                ->withHeader('Vary', 'Origin');
        }

        return $response;
    }

    $response = $handler->handle($request);
    
    if ($isAllowedOrigin) {
        $response = $response
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Vary', 'Origin');
    }

    return $response;
});

$errorMiddleware = $app->addErrorMiddleware(
    $config->appEnv !== 'production',
    true,
    true
);

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

$app->get(
    '/v1/status/public',
    fn (
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface =>
        $proxy->forward($request, '/status/public', false)
);

$app->post(
    '/v1/auth/login',
    fn (
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface =>
        $proxy->forward($request, '/auth/login', false)
);

$app->post(
    '/v1/auth/logout',
    fn (
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface =>
        $proxy->forward($request, '/auth/logout', true)
);

$app->post(
    '/v1/heartbeat',
    fn (
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface =>
        $proxy->forward($request, '/heartbeat', true)
);

$app->any('/v1/hub[/{path:.*}]', function (
    ServerRequestInterface $request,
    ResponseInterface $response,
    array $args
) use ($proxy): ResponseInterface {
    $path = isset($args['path'])
        ? '/' . ltrim((string) $args['path'], '/')
        : '/';

    return $proxy->forward($request, $path, true);
});

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
