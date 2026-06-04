<?php

declare(strict_types=1);

namespace App;

/**
 * Handles downloading media from Telegram and preparing local paths
 * so that MaxBot can upload them.
 *
 * Files are stored temporarily in /app/storage and cleaned up after sync.
 */
class MediaHandler
{
    private string $storageDir;

    public function __construct(
        private readonly TelegramBot $telegram,
        private readonly Logger $logger,
    ) {
        $this->storageDir = '/app/storage';
        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Extract media info from a Telegram message.
     *
     * Returns an array with keys:
     *   - type    : 'text' | 'photo' | 'video' | 'video_note'
     *   - text    : caption or message text (may be empty string)
     *   - file_id : Telegram file_id (absent for text messages)
     *
     * Returns null if the message type is unsupported.
     */
    public function extractTelegramMedia(array $message): ?array
    {
        // Plain text
        if (isset($message['text'])) {
            return ['type' => 'text', 'text' => $message['text']];
        }

        // Photo — Telegram sends an array of sizes; pick the largest
        if (isset($message['photo'])) {
            $largest = end($message['photo']);
            return [
                'type'    => 'photo',
                'file_id' => $largest['file_id'],
                'text'    => $message['caption'] ?? '',
            ];
        }

        // Video
        if (isset($message['video'])) {
            return [
                'type'    => 'video',
                'file_id' => $message['video']['file_id'],
                'text'    => $message['caption'] ?? '',
            ];
        }

        // Video note (circle video)
        if (isset($message['video_note'])) {
            return [
                'type'    => 'video_note',
                'file_id' => $message['video_note']['file_id'],
                'text'    => '',
            ];
        }

        return null; // sticker, audio, document, etc. — skip
    }

    /**
     * Extract media info from a Green API notification body.
     *
     * Green API format (incomingMessageReceived):
     *   body.messageData.typeMessage  = textMessage | imageMessage | videoMessage | documentMessage | ...
     *   body.messageData.textMessageData.textMessage
     *   body.messageData.fileMessageData.downloadUrl
     *   body.messageData.fileMessageData.caption
     *
     * Returns same shape as extractTelegramMedia():
     *   ['type' => 'text'|'photo'|'video', 'text' => string, 'url' => string|null]
     *
     * Returns null if unsupported type.
     */
    public function extractMaxMedia(array $body): ?array
    {
        $messageData = $body['messageData'] ?? [];
        $typeMessage = $messageData['typeMessage'] ?? '';

        return match ($typeMessage) {
            'textMessage', 'extendedTextMessage' => [
                'type' => 'text',
                'text' => $messageData['textMessageData']['textMessage']
                    ?? $messageData['extendedTextMessageData']['text']
                    ?? '',
            ],

            'imageMessage' => [
                'type' => 'photo',
                'url'  => $messageData['fileMessageData']['downloadUrl'] ?? null,
                'text' => $messageData['fileMessageData']['caption'] ?? '',
            ],

            'videoMessage' => [
                'type' => 'video',
                'url'  => $messageData['fileMessageData']['downloadUrl'] ?? null,
                'text' => $messageData['fileMessageData']['caption'] ?? '',
            ],

            // documentMessage, audioMessage, etc. – skip
            default => null,
        };
    }

    /**
     * Download a Telegram file by file_id to a temp path.
     * Returns the local file path, or null on failure.
     */
    public function downloadFromTelegram(string $fileId): ?string
    {
        $filePath = $this->telegram->getFilePath($fileId);
        if ($filePath === null) {
            $this->logger->error("TG getFilePath returned null for file_id={$fileId}");
            return null;
        }

        $ext      = pathinfo($filePath, PATHINFO_EXTENSION) ?: 'bin';
        $destPath = $this->storageDir . '/' . uniqid('tg_', true) . '.' . $ext;

        $ok = $this->telegram->downloadFile($filePath, $destPath);
        if (!$ok) {
            return null;
        }

        return $destPath;
    }

    /**
     * Download a file from a direct URL to a temp path.
     * Used for MAX attachments that provide a direct URL.
     * Returns the local file path, or null on failure.
     */
    public function downloadFromUrl(string $url): ?string
    {
        $ext      = pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'bin';
        $destPath = $this->storageDir . '/' . uniqid('max_', true) . '.' . $ext;

        try {
            $ctx = stream_context_create(['http' => ['timeout' => 60]]);
            $data = file_get_contents($url, false, $ctx);
            if ($data === false) {
                throw new \RuntimeException("file_get_contents failed for URL: {$url}");
            }
            file_put_contents($destPath, $data);
            return $destPath;
        } catch (\Throwable $e) {
            $this->logger->error("downloadFromUrl error: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Delete a temporary file after it has been forwarded.
     */
    public function cleanup(string $localPath): void
    {
        if (file_exists($localPath)) {
            unlink($localPath);
        }
    }
}
