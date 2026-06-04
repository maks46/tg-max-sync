<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Client for the MAX Bot API (https://botapi.max.ru).
 *
 * API uses marker-based pagination for getUpdates (not offset).
 * Media must be uploaded first via /uploads, then attached to messages.
 */
class MaxBot
{
    private const BASE_URL = 'https://botapi.max.ru';

    private string $groupId;
    private string $token;

    public function __construct(
        private readonly Config $config,
        private readonly Client $http,
        private readonly Logger $logger,
    ) {
        $this->token   = $this->config->require('MAX_BOT_TOKEN');
        $this->groupId = $this->config->require('MAX_GROUP_ID');
    }

    public function getGroupId(): string
    {
        return $this->groupId;
    }

    // -------------------------------------------------------------------------
    // Polling
    // -------------------------------------------------------------------------

    /**
     * Fetch updates from MAX.
     * Returns ['updates' => [...], 'marker' => int|null]
     *
     * @param int|null $marker Pass the marker returned by the previous call.
     */
    public function getUpdates(?int $marker = null): array
    {
        $query = ['limit' => 100];
        if ($marker !== null) {
            $query['marker'] = $marker;
        }

        try {
            $response = $this->http->get(self::BASE_URL . '/updates', [
                'query'   => $query,
                'headers' => $this->authHeaders(),
                'timeout' => 30,
            ]);
            $body = json_decode((string)$response->getBody(), true);
            return [
                'updates' => $body['updates'] ?? [],
                'marker'  => $body['marker']  ?? null,
            ];
        } catch (GuzzleException $e) {
            $this->logger->error('MAX getUpdates error: ' . $e->getMessage());
            return ['updates' => [], 'marker' => $marker];
        }
    }

    // -------------------------------------------------------------------------
    // Sending
    // -------------------------------------------------------------------------

    /**
     * Send a plain-text message to the group chat.
     */
    public function sendMessage(string $text, ?string $chatId = null): array
    {
        return $this->post('/messages', [
            'text' => $text,
        ], $chatId);
    }

    /**
     * Send a photo. $source is a local file path.
     * The file is uploaded first, then attached to the message.
     */
    public function sendPhoto(string $localPath, string $caption = '', ?string $chatId = null): array
    {
        $token = $this->uploadMedia($localPath, 'image');
        if ($token === null) {
            return [];
        }

        $body = [
            'attachments' => [
                [
                    'type'    => 'image',
                    'payload' => ['token' => $token],
                ],
            ],
        ];
        if ($caption !== '') {
            $body['text'] = $caption;
        }

        return $this->post('/messages', $body, $chatId);
    }

    /**
     * Send a video. $source is a local file path.
     */
    public function sendVideo(string $localPath, string $caption = '', ?string $chatId = null): array
    {
        $token = $this->uploadMedia($localPath, 'video');
        if ($token === null) {
            return [];
        }

        $body = [
            'attachments' => [
                [
                    'type'    => 'video',
                    'payload' => ['token' => $token],
                ],
            ],
        ];
        if ($caption !== '') {
            $body['text'] = $caption;
        }

        return $this->post('/messages', $body, $chatId);
    }

    /**
     * Send a video note (circle video). MAX doesn't have a separate type;
     * we send it as a regular video with the "video_note" flag in payload.
     */
    public function sendVideoNote(string $localPath, ?string $chatId = null): array
    {
        // Upload as video; MAX doesn't distinguish circle videos natively
        return $this->sendVideo($localPath, '', $chatId);
    }

    // -------------------------------------------------------------------------
    // Media upload
    // -------------------------------------------------------------------------

    /**
     * Upload a local file to MAX and return the reusable token.
     *
     * @param string $type  'image' | 'video' | 'file'
     * @return string|null  Token to use in attachment payload, or null on error.
     */
    public function uploadMedia(string $localPath, string $type): ?string
    {
        // Step 1: get an upload URL from MAX
        try {
            $response = $this->http->post(self::BASE_URL . '/uploads', [
                'query'   => ['type' => $type],
                'headers' => $this->authHeaders(),
                'timeout' => 15,
            ]);
            $info = json_decode((string)$response->getBody(), true);
        } catch (GuzzleException $e) {
            $this->logger->error("MAX uploadMedia (get URL) error: {$e->getMessage()}");
            return null;
        }

        $uploadUrl = $info['url'] ?? null;
        if (!$uploadUrl) {
            $this->logger->error('MAX uploadMedia: no upload URL in response', $info);
            return null;
        }

        // Step 2: PUT the file to the upload URL
        try {
            $response = $this->http->put($uploadUrl, [
                'body'    => fopen($localPath, 'rb'),
                'headers' => ['Content-Type' => $this->mimeType($localPath)],
                'timeout' => 120,
            ]);
            $result = json_decode((string)$response->getBody(), true);
        } catch (GuzzleException $e) {
            $this->logger->error("MAX uploadMedia (PUT) error: {$e->getMessage()}");
            return null;
        }

        // The token can be at different keys depending on media type
        return $result['token']
            ?? $result['fileId']
            ?? $result['id']
            ?? null;
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token];
    }

    /**
     * POST JSON to a MAX API endpoint.
     */
    private function post(string $path, array $body, ?string $chatId = null): array
    {
        $chatId ??= $this->groupId;

        try {
            $response = $this->http->post(self::BASE_URL . $path, [
                'query'   => ['chat_id' => $chatId],
                'json'    => $body,
                'headers' => $this->authHeaders(),
                'timeout' => 30,
            ]);
            return json_decode((string)$response->getBody(), true) ?? [];
        } catch (GuzzleException $e) {
            $this->logger->error("MAX POST {$path} error: {$e->getMessage()}");
            return [];
        }
    }

    private function mimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'mp4'         => 'video/mp4',
            'mov'         => 'video/quicktime',
            'avi'         => 'video/x-msvideo',
            default       => 'application/octet-stream',
        };
    }
}
