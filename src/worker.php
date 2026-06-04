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
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

// ── Bootstrap ────────────────────────────────────────────────────────────────

$config = Config::getInstance();
$logger = Logger::getInstance();

$logger->info('Sync worker starting');

// ── HTTP client with request/response logging ─────────────────────────────────
$stack = HandlerStack::create();

// Middleware::tap($before, $after):
//   $before(RequestInterface, array $options)
//   $after(RequestInterface, array $options, PromiseInterface)  ← Guzzle 7 passes promise, not response
// To log the actual response we attach a then() callback to the promise instead.
$stack->push(function (callable $handler) use ($logger): callable {
    return function (RequestInterface $request, array $options) use ($handler, $logger): mixed {
        // Log request
        $reqBody = (string)$request->getBody();
        $request->getBody()->rewind();
        $logger->debug(sprintf(
            'HTTP → %s %s%s',
            $request->getMethod(),
            (string)$request->getUri(),
            $reqBody !== '' ? ' | body: ' . mb_substr($reqBody, 0, 500) : ''
        ));

        $promise = $handler($request, $options);

        return $promise->then(function (ResponseInterface $response) use ($request, $logger): ResponseInterface {
            $respBody = (string)$response->getBody();
            $response->getBody()->rewind();
            $logger->debug(sprintf(
                'HTTP ← %d %s | body: %s',
                $response->getStatusCode(),
                (string)$request->getUri(),
                mb_substr($respBody, 0, 500)
            ));
            return $response;
        });
    };
});

$http = new Client([
    'handler' => $stack,
    'verify'  => true,
    'headers' => ['Accept' => 'application/json'],
]);

$db       = Database::getInstance();
$telegram = new TelegramBot($config, $http, $logger);
$max      = new MaxBot($config, $http, $logger);
$media    = new MediaHandler($telegram, $logger);

$worker = new SyncWorker($telegram, $max, $db, $media, $logger, $config);

// ── Green API instance setup ──────────────────────────────────────────────────
$max->ensureIncomingWebhook();

// ── Two-process architecture ──────────────────────────────────────────────────
// TG long-poll blocks for up to 25 s when idle, which would starve the MAX
// polling loop. Fork into two independent processes so each direction runs
// without blocking the other.
//
// Process A (parent) : Telegram → MAX
// Process B (child)  : MAX → Telegram

$pid = pcntl_fork();

if ($pid === -1) {
    // Fork failed — fall back to sequential loop (original behaviour)
    $logger->error('pcntl_fork failed, running in single-process fallback mode');

    $interval = (int)$config->get('SYNC_INTERVAL', 2);
    $logger->info("Worker running (single-process). Sleep interval: {$interval}s");

    while (true) {
        try {
            $worker->runCycle();
        } catch (\Throwable $e) {
            $logger->error('Unhandled exception in runCycle: ' . $e->getMessage());
        }
        sleep($interval);
    }
}

if ($pid === 0) {
    // ── Child process: MAX → Telegram ────────────────────────────────────────
    $logger->info('MAX→TG worker process started (pid=' . getmypid() . ')');

    while (true) {
        try {
            $worker->runMaxToTelegramCycle();
        } catch (\Throwable $e) {
            $logger->error('MAX→TG unhandled exception: ' . $e->getMessage());
            sleep(2);
        }
    }
} else {
    // ── Parent process: Telegram → MAX ───────────────────────────────────────
    $logger->info('TG→MAX worker process started (pid=' . getmypid() . ', child=' . $pid . ')');

    $interval = (int)$config->get('SYNC_INTERVAL', 2);

    while (true) {
        try {
            $worker->runTelegramToMaxCycle();
        } catch (\Throwable $e) {
            $logger->error('TG→MAX unhandled exception: ' . $e->getMessage());
            sleep($interval);
        }
        sleep($interval);

        // Restart child if it died
        $res = pcntl_waitpid($pid, $status, WNOHANG);
        if ($res === $pid) {
            $logger->error('MAX→TG child process died, restarting');
            $pid = pcntl_fork();
            if ($pid === 0) {
                while (true) {
                    try {
                        $worker->runMaxToTelegramCycle();
                    } catch (\Throwable $e) {
                        $logger->error('MAX→TG unhandled exception: ' . $e->getMessage());
                        sleep(2);
                    }
                }
            }
        }
    }
}
