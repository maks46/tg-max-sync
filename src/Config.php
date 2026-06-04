<?php

declare(strict_types=1);

namespace App;

class Config
{
    private static ?self $instance = null;
    private array $data = [];

    private function __construct()
    {
        $envFile = '/app/.env';
        if (file_exists($envFile)) {
            $this->loadEnvFile($envFile);
        }
        // Fallback: read from actual environment variables
        $this->data = array_merge($this->data, $_ENV, getenv() ?: []);
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadEnvFile(string $path): void
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                // Strip surrounding quotes
                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                }
                $this->data[$key] = $value;
                putenv("$key=$value");
            }
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? getenv($key) ?: $default;
    }

    public function require(string $key): string
    {
        $value = $this->get($key);
        if ($value === null || $value === '' || $value === false) {
            throw new \RuntimeException("Required config key '$key' is not set.");
        }
        return (string)$value;
    }
}
