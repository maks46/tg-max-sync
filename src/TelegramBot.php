<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class TelegramBot
{
    private string $baseUrl;
    private string $groupId;

    public function __construct(
        private readonly Config $config,
        private readonly Client $http,
        private readonly Logger $logger,
    ) {
        $token = $this->config->require('TELEGRAM_BOT_TOKEN');
        $this->baseUrl = "https://api.telegram.org/bot{$token}";
        $this->groupId = $this->config->require('TELEGRAM_GROUP_ID');
    }

    public function getGroupId(): string
    {
        return $this->groupId;
    }

    /**
     * Long-poll for updates. Returns array of update objects.
     */
    public function getUpdates(int $offset = 0, int $timeout = 25): array
    {
        try {
            $response = $this->http->get("{$this->baseUrl}/getUpdates", [
                'query' => [
                    'offset'  => $offset,
                    'timeout' => $timeout,
                    'allowed_updates' => json_encode(['message']),
                ],
                'timeout' => $timeout + 5,
            ]);
            $body = json_decode((string)$response->getBody(), true);
            if (!($body['ok'] ?? false)) {
                $this->logger->warning('TG getUpdates not ok', $body);
                return [];
            }
            return $body['result'] ?? [];
        } catch (GuzzleException $e) {
            $this->logger->error('TG getUpdates error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Send plain text message to the group.
     */
    public function sendMessage(string $text, ?string $chatId = null): array
    {
        return $this->apiPost('sendMessage', [
            'chat_id' => $chatId ?? $this->groupId,
            'text'    => $text,
        ]);
    }

    /**
     * Send a photo. $source can be a local file path or a URL.
     */
    public function sendPhoto(string $source, string $caption = '', ?string $chatId = null): array
    {
        $chat = $chatId ?? $this->groupId;

        if (file_exists($source)) {
            return $this->apiPost('sendPhoto', [
                'chat_id' => $chat,
                'caption' => $caption,
            ], ['photo' => $source]);
        }

        // URL or file_id — send as form field
        return $this->apiPost('sendPhoto', [
            'chat_id' => $chat,
            'photo'   => $source,
            'caption' => $caption,
        ]);
    }

    /**
     * Send a video.
     */
    public function sendVideo(string $source, string $caption = '', ?string $chatId = null): array
    {
        $chat = $chatId ?? $this->groupId;

        if (file_exists($source)) {
            return $this->apiPost('sendVideo', [
                'chat_id' => $chat,
                'caption' => $caption,
            ], ['video' => $source]);
        }

        return $this->apiPost('sendVideo', [
            'chat_id' => $chat,
            'video'   => $source,
            'caption' => $caption,
        ]);
    }

    /**
     * Send a video note (circle video, ≤60s).
     */
    public function sendVideoNote(string $source, ?string $chatId = null): array
    {
        $chat = $chatId ?? $this->groupId;

        if (file_exists($source)) {
            return $this->apiPost('sendVideoNote', [
                'chat_id' => $chat,
            ], ['video_note' => $source]);
        }

        return $this->apiPost('sendVideoNote', [
            'chat_id'    => $chat,
            'video_note' => $source,
        ]);
    }

    /**
     * Get file info by file_id. Returns the file_path relative to Telegram CDN.
     */
    public function getFilePath(string $fileId): ?string
    {
        $result = $this->apiPost('getFile', ['file_id' => $fileId]);
        return $result['file_path'] ?? null;
    }

    /**
     * Download a Telegram file to a local path.
     * $filePath is the value returned by getFilePath().
     */
    public function downloadFile(string $filePath, string $destPath): bool
    {
        $token = $this->config->require('TELEGRAM_BOT_TOKEN');
        $url = "https://api.telegram.org/file/bot{$token}/{$filePath}";

        try {
            $this->http->get($url, ['sink' => $destPath]);
            return true;
        } catch (GuzzleException $e) {
            $this->logger->error("TG downloadFile error: {$e->getMessage()}");
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * POST to a Telegram method with optional multipart file fields.
     *
     * @param array<string,string> $fields   Scalar form fields
     * @param array<string,string> $files    field_name => local_file_path
     */
    private function apiPost(string $method, array $fields, array $files = []): array
    {
        try {
            if (!empty($files)) {
                $multipart = [];
                foreach ($fields as $name => $value) {
                    $multipart[] = ['name' => $name, 'contents' => (string)$value];
                }
                foreach ($files as $name => $path) {
                    $multipart[] = [
                        'name'     => $name,
                        'contents' => fopen($path, 'rb'),
                        'filename' => basename($path),
                    ];
                }
                $response = $this->http->post("{$this->baseUrl}/{$method}", [
                    'multipart' => $multipart,
                    'timeout'   => 60,
                ]);
            } else {
                $response = $this->http->post("{$this->baseUrl}/{$method}", [
                    'form_params' => $fields,
                    'timeout'     => 30,
                ]);
            }

            $body = json_decode((string)$response->getBody(), true);
            if (!($body['ok'] ?? false)) {
                $this->logger->warning("TG {$method} not ok", $body);
                return [];
            }
            return $body['result'] ?? [];
        } catch (GuzzleException $e) {
            $this->logger->error("TG {$method} error: {$e->getMessage()}");
            return [];
        }
    }
}
