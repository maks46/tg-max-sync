<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Client for the MAX messenger via Green API (https://green-api.com/v3/docs/).
 *
 * Green API uses HTTP polling (ReceiveNotification / DeleteNotification) instead
 * of the old botapi.max.ru approach.
 *
 * Request format:
 *   {{apiUrl}}/waInstance{{idInstance}}/METHOD/{{apiTokenInstance}}
 *
 * Credentials required in .env:
 *   GREEN_API_URL        – e.g. https://api.green-api.com
 *   GREEN_API_MEDIA_URL  – e.g. https://media.green-api.com
 *   GREEN_API_INSTANCE   – numeric instance id
 *   GREEN_API_TOKEN      – apiTokenInstance
 *   MAX_GROUP_ID         – chatId of the target group (numeric, e.g. -10000000000000)
 */
class MaxBot
{
    private string $apiUrl;
    private string $mediaUrl;
    private string $idInstance;
    private string $apiToken;
    private string $groupId;

    public function __construct(
        private readonly Config $config,
        private readonly Client $http,
        private readonly Logger $logger,
    ) {
        $this->apiUrl     = rtrim($this->config->require('GREEN_API_URL'), '/');
        $this->mediaUrl   = rtrim($this->config->require('GREEN_API_MEDIA_URL'), '/');
        $this->idInstance = $this->config->require('GREEN_API_INSTANCE');
        $this->apiToken   = $this->config->require('GREEN_API_TOKEN');
        $this->groupId    = $this->config->require('MAX_GROUP_ID');
    }

    public function getGroupId(): string
    {
        return $this->groupId;
    }

    // -------------------------------------------------------------------------
    // Instance setup
    // -------------------------------------------------------------------------

    /**
     * Ensure the Green API instance has incomingWebhook enabled.
     * Called once at worker startup. After setSettings the instance reboots (~30 s).
     */
    public function ensureIncomingWebhook(): void
    {
        // Read current settings
        try {
            $response = $this->http->get($this->buildUrl('getSettings'), ['timeout' => 10]);
            $settings = json_decode((string)$response->getBody(), true) ?? [];
        } catch (GuzzleException $e) {
            $this->logger->error('MAX getSettings error: ' . $e->getMessage());
            return;
        }

        $needsUpdate = ($settings['incomingWebhook'] ?? '') !== 'yes'
            || ($settings['outgoingAPIMessageWebhook'] ?? '') !== 'yes'
            || ($settings['outgoingMessageWebhook'] ?? '') !== 'yes';

        if (!$needsUpdate) {
            $this->logger->info('MAX webhooks already enabled');
            return;
        }

        $this->logger->info('MAX webhooks not fully enabled — enabling via setSettings');

        try {
            $this->http->post($this->buildUrl('setSettings'), [
                'json'    => [
                    'incomingWebhook'           => 'yes',
                    'outgoingAPIMessageWebhook'  => 'yes',
                    'outgoingMessageWebhook'    => 'yes',
                ],
                'timeout' => 10,
            ]);
            $this->logger->info('MAX setSettings done — instance will reboot, waiting 60 s');
            sleep(60);
        } catch (GuzzleException $e) {
            $this->logger->error('MAX setSettings error: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Polling  (HTTP-API mode)
    // -------------------------------------------------------------------------

    /**
     * Receive one notification from the queue (long-poll, up to 5 s).
     *
     * Returns:
     *   ['receiptId' => int, 'body' => array]  – when a notification is available
     *   null                                   – queue is empty / timeout
     */
    public function receiveNotification(): ?array
    {
        $receiveTimeout = 5;
        $url = $this->buildUrl('receiveNotification') . "?receiveTimeout={$receiveTimeout}";
        try {
            $response = $this->http->get($url, ['timeout' => $receiveTimeout + 10]);
            $data = json_decode((string)$response->getBody(), true);
            if (empty($data)) {
                return null;
            }
            return $data;
        } catch (GuzzleException $e) {
            // cURL error 28 = connection timeout — expected when the long-poll queue is
            // empty and the server closes the connection after receiveTimeout seconds.
            // Log as debug to avoid flooding the error log with normal idle cycles.
            if (str_contains($e->getMessage(), 'cURL error 28')) {
                $this->logger->debug('MAX receiveNotification: long-poll timeout (queue empty)');
                return null;
            }
            $this->logger->error('MAX receiveNotification error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Acknowledge (delete) a processed notification so the queue advances.
     *
     * Correct URL: DELETE /waInstance{id}/deleteNotification/{token}/{receiptId}
     */
    public function deleteNotification(int $receiptId): void
    {
        // Token comes before receiptId in the path
        $url = "{$this->apiUrl}/waInstance{$this->idInstance}/deleteNotification/{$this->apiToken}/{$receiptId}";
        try {
            $this->http->delete($url, ['timeout' => 10]);
        } catch (GuzzleException $e) {
            $this->logger->error("MAX deleteNotification({$receiptId}) error: " . $e->getMessage());
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
        return $this->postJson('sendMessage', [
            'chatId'  => $chatId ?? $this->groupId,
            'message' => $text,
        ]);
    }

    /**
     * Send a photo (or any image) from a local file path.
     */
    public function sendPhoto(string $localPath, string $caption = '', ?string $chatId = null): array
    {
        return $this->sendFileByUpload($localPath, $caption, $chatId);
    }

    /**
     * Send a video from a local file path.
     */
    public function sendVideo(string $localPath, string $caption = '', ?string $chatId = null): array
    {
        return $this->sendFileByUpload($localPath, $caption, $chatId);
    }

    /**
     * Send a video note (circle video). Green API has no special type; send as video.
     */
    public function sendVideoNote(string $localPath, ?string $chatId = null): array
    {
        return $this->sendFileByUpload($localPath, '', $chatId);
    }

    /**
     * Upload and send any file via multipart/form-data (sendFileByUpload).
     *
     * POST {{mediaUrl}}/waInstance{{idInstance}}/sendFileByUpload/{{apiTokenInstance}}
     * form-data: chatId, file (binary), fileName, caption
     */
    public function sendFileByUpload(string $localPath, string $caption = '', ?string $chatId = null): array
    {
        $url      = $this->buildMediaUrl('sendFileByUpload');
        $fileName = basename($localPath);
        $mime     = $this->mimeType($localPath);

        $multipart = [
            ['name' => 'chatId',   'contents' => $chatId ?? $this->groupId],
            ['name' => 'fileName', 'contents' => $fileName],
            ['name' => 'file',     'contents' => fopen($localPath, 'rb'), 'filename' => $fileName, 'headers' => ['Content-Type' => $mime]],
        ];
        if ($caption !== '') {
            $multipart[] = ['name' => 'caption', 'contents' => $caption];
        }

        try {
            $response = $this->http->post($url, [
                'multipart' => $multipart,
                'timeout'   => 120,
            ]);
            return json_decode((string)$response->getBody(), true) ?? [];
        } catch (GuzzleException $e) {
            $this->logger->error("MAX sendFileByUpload error: {$e->getMessage()}");
            return [];
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Build a standard API URL: {{apiUrl}}/waInstance{{idInstance}}/METHOD/{{apiTokenInstance}}
     */
    private function buildUrl(string $method): string
    {
        return "{$this->apiUrl}/waInstance{$this->idInstance}/{$method}/{$this->apiToken}";
    }

    /**
     * Build a media API URL using the media endpoint (for file uploads).
     */
    private function buildMediaUrl(string $method): string
    {
        return "{$this->mediaUrl}/waInstance{$this->idInstance}/{$method}/{$this->apiToken}";
    }

    /**
     * POST JSON to a Green API endpoint and return decoded response.
     */
    private function postJson(string $method, array $body): array
    {
        $url = $this->buildUrl($method);
        try {
            $response = $this->http->post($url, [
                'json'    => $body,
                'timeout' => 30,
            ]);
            return json_decode((string)$response->getBody(), true) ?? [];
        } catch (GuzzleException $e) {
            $this->logger->error("MAX POST {$method} error: {$e->getMessage()}");
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
