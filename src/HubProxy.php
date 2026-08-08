<?php
declare(strict_types=1);

namespace TangyApi;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Factory\ResponseFactory;

final class HubProxy
{
    public function __construct(private readonly Config $config)
    {
    }

    public function forward(
        ServerRequestInterface $request,
        string $upstreamPath,
        bool $requireBearer = false
    ): ResponseInterface {
        if ($requireBearer && !$this->hasBearerToken($request)) {
            return $this->json(401, [
                'error' => 'authentication_required',
                'message' => 'A Bearer session token is required.'
            ]);
        }

        $body = (string) $request->getBody();
        if (strlen($body) > $this->config->maxRequestBytes) {
            return $this->json(413, [
                'error' => 'request_too_large',
                'message' => 'Request body exceeds the configured maximum size.'
            ]);
        }

        $query = $request->getUri()->getQuery();
        $url = $this->config->hubBaseUrl . '/' . ltrim($upstreamPath, '/');
        if ($query !== '') {
            $url .= '?' . $query;
        }

        $headers = $this->forwardHeaders($request);
        $headers[] = 'X-Tangy-Edge: php-api';

        $ch = curl_init($url);
        if ($ch === false) {
            return $this->json(503, [
                'error' => 'hub_unavailable',
                'message' => 'Unable to initialize upstream request.'
            ]);
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => strtoupper($request->getMethod()),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => min(5, $this->config->hubTimeout),
            CURLOPT_TIMEOUT => $this->config->hubTimeout,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        if (!in_array(strtoupper($request->getMethod()), ['GET', 'HEAD'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);

            error_log(json_encode([
                'level' => 'error',
                'event' => 'hub_proxy_failed',
                'error' => $error,
                'path' => $upstreamPath,
            ]));

            return $this->json(503, [
                'error' => 'hub_unavailable',
                'message' => 'TangyAIHub is temporarily unavailable.'
            ]);
        }

        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $rawHeaders = substr($raw, 0, $headerSize);
        $responseBody = substr($raw, $headerSize);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/json';
        curl_close($ch);

        $response = (new ResponseFactory())->createResponse($status ?: 502);
        $response->getBody()->write($responseBody);

        $response = $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Content-Type-Options', 'nosniff');

        // Preserve selected safe upstream headers.
        foreach (preg_split("/\r\n|\n|\r/", trim($rawHeaders)) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = array_map('trim', explode(':', $line, 2));
            if (in_array(strtolower($name), ['www-authenticate', 'retry-after'], true)) {
                $response = $response->withHeader($name, $value);
            }
        }

        return $response;
    }

    private function hasBearerToken(ServerRequestInterface $request): bool
    {
        $authorization = trim($request->getHeaderLine('Authorization'));
        return preg_match('/^Bearer\s+\S+$/i', $authorization) === 1;
    }

    private function forwardHeaders(ServerRequestInterface $request): array
    {
        $allowed = [
            'authorization',
            'content-type',
            'accept',
            'user-agent',
            'x-request-id',
        ];

        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            if (in_array(strtolower($name), $allowed, true)) {
                foreach ($values as $value) {
                    $headers[] = $name . ': ' . $value;
                }
            }
        }

        return $headers;
    }

    private function json(int $status, array $payload): ResponseInterface
    {
        $response = (new ResponseFactory())->createResponse($status);
        $response->getBody()->write(
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
