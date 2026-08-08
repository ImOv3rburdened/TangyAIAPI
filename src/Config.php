<?php
declare(strict_types=1);

namespace TangyApi;

final class Config
{
    public readonly string $hubBaseUrl;
    public readonly int $hubTimeout;
    public readonly int $maxRequestBytes;
    public readonly string $appEnv;
    public readonly string $logLevel;

    public function __construct()
    {
        $this->hubBaseUrl = rtrim(self::env('HUB_BASE_URL', 'http://gateway:8000'), '/');
        $this->hubTimeout = max(1, (int) self::env('HUB_REQUEST_TIMEOUT_SECONDS', '30'));
        $this->maxRequestBytes = max(1024, (int) self::env('MAX_REQUEST_BYTES', '1048576'));
        $this->appEnv = self::env('APP_ENV', 'production');
        $this->logLevel = strtoupper(self::env('LOG_LEVEL', 'INFO'));
    }

    private static function env(string $key, string $default = ''): string
    {
        $value = getenv($key);
        return ($value === false || $value === '') ? $default : $value;
    }
}
