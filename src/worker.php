<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Config;
use App\Database;
use App\Logger;
use App\MaxBot;
use App\MediaHandler;
use App\SyncWorker;
use App\TelegramBot;
use GuzzleHttp\Client;

// ── Bootstrap ────────────────────────────────────────────────────────────────

$config = Config::getInstance();
$logger = Logger::getInstance();

$logger->info('Sync worker starting');

$http = new Client([
    'verify'  => true,
    'headers' => ['Accept' => 'application/json'],
]);

$db       = Database::getInstance();
$telegram = new TelegramBot($config, $http, $logger);
$max      = new MaxBot($config, $http, $logger);
$media    = new MediaHandler($telegram, $logger);

$worker = new SyncWorker($telegram, $max, $db, $media, $logger, $config);

// ── Main loop ─────────────────────────────────────────────────────────────────

$interval = (int)$config->get('SYNC_INTERVAL', 2); // seconds to sleep after each cycle

$logger->info("Worker running. Sleep interval: {$interval}s");

while (true) {
    try {
        $worker->runCycle();
    } catch (\Throwable $e) {
        $logger->error('Unhandled exception in runCycle: ' . $e->getMessage(), [
            'file'  => $e->getFile(),
            'line'  => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

    // Telegram long-poll already introduces ~25 s latency when idle.
    // This sleep only matters when updates arrive faster than the poll timeout.
    sleep($interval);
}
