<?php

declare(strict_types=1);

namespace App;

class Logger
{
    private static ?self $instance = null;
    private string $logLevel;
    private string $logFile;
    private int $maxBytes;      // max log file size in bytes before rotation
    private int $maxFiles;      // number of rotated files to keep

    private const LEVELS = ['debug' => 0, 'info' => 1, 'warning' => 2, 'error' => 3];

    private function __construct()
    {
        $config = Config::getInstance();

        $this->logLevel = strtolower($config->get('LOG_LEVEL', 'info'));

        $logDir = '/app/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        $this->logFile = $logDir . '/app.log';

        // LOG_MAX_SIZE: human-readable like "10M", "512K", "5M". Default 10 MB.
        $this->maxBytes = $this->parseSize((string)$config->get('LOG_MAX_SIZE', '10M'));

        // LOG_MAX_FILES: how many rotated files to keep. Default 5.
        $this->maxFiles = max(1, (int)$config->get('LOG_MAX_FILES', 5));
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // -------------------------------------------------------------------------
    // Public logging methods
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function log(string $level, string $message, array $context = []): void
    {
        $configuredLevel = self::LEVELS[$this->logLevel] ?? 1;
        $messageLevel    = self::LEVELS[$level]          ?? 1;

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

        $this->rotateIfNeeded();
        file_put_contents($this->logFile, $line, FILE_APPEND);
    }

    /**
     * Rotate the log file if it exceeds $maxBytes.
     * Keeps up to $maxFiles rotated copies: app.log.1, app.log.2, ...
     */
    private function rotateIfNeeded(): void
    {
        if (!file_exists($this->logFile)) {
            return;
        }

        if (filesize($this->logFile) < $this->maxBytes) {
            return;
        }

        // Shift existing rotated files: .5 → deleted, .4 → .5, ..., .1 → .2
        for ($i = $this->maxFiles; $i >= 1; $i--) {
            $old = "{$this->logFile}.{$i}";
            $new = "{$this->logFile}." . ($i + 1);
            if (file_exists($old)) {
                if ($i === $this->maxFiles) {
                    unlink($old); // drop the oldest
                } else {
                    rename($old, $new);
                }
            }
        }

        // Rename current log to .1
        rename($this->logFile, "{$this->logFile}.1");
    }

    /**
     * Parse human-readable size strings like "10M", "512K", "1G" into bytes.
     */
    private function parseSize(string $size): int
    {
        $size  = trim($size);
        $unit  = strtoupper(substr($size, -1));
        $value = (int)$size;

        return match ($unit) {
            'G' => $value * 1024 * 1024 * 1024,
            'M' => $value * 1024 * 1024,
            'K' => $value * 1024,
            default => $value > 0 ? $value : 10 * 1024 * 1024, // fallback 10 MB
        };
    }
}
