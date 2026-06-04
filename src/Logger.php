<?php

declare(strict_types=1);

namespace App;

class Logger
{
    private static ?self $instance = null;
    private string $logLevel;
    private string $logDir;

    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private function __construct()
    {
        $config = Config::getInstance();
        $this->logLevel = strtolower($config->get('LOG_LEVEL', 'info'));
        $this->logDir = '/app/logs';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        $configuredLevel = self::LEVELS[$this->logLevel] ?? 1;
        $messageLevel = self::LEVELS[$level] ?? 1;

        if ($messageLevel < $configuredLevel) {
            return;
        }

        $contextStr = empty($context) ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE);
        $line = sprintf(
            "[%s] [%s] %s%s\n",
            date('Y-m-d H:i:s'),
            strtoupper($level),
            $message,
            $contextStr
        );

        echo $line;
        file_put_contents($this->logDir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }
}
