<?php

declare(strict_types=1);

namespace App;

/**
 * Orchestrates bidirectional sync between Telegram and MAX.
 *
 * Direction TG → MAX:
 *   Long-poll Telegram getUpdates → for each new message in the target group
 *   that hasn't been synced yet → forward to MAX → record in DB.
 *
 * Direction MAX → TG:
 *   Poll MAX /updates with marker → for each new message in the target chat
 *   that hasn't been synced yet → forward to Telegram → record in DB.
 */
class SyncWorker
{
    private int $tgOffset   = 0;
    private ?int $maxMarker = null;

    public function __construct(
        private readonly TelegramBot  $telegram,
        private readonly MaxBot       $max,
        private readonly Database     $db,
        private readonly MediaHandler $media,
        private readonly Logger       $logger,
        private readonly Config       $config,
    ) {}

    /**
     * Run one full sync cycle (TG → MAX, then MAX → TG).
     * Called in a loop from worker.php.
     */
    public function runCycle(): void
    {
        $this->syncTelegramToMax();
        $this->syncMaxToTelegram();
    }

    // -------------------------------------------------------------------------
    // Telegram → MAX
    // -------------------------------------------------------------------------

    private function syncTelegramToMax(): void
    {
        $updates = $this->telegram->getUpdates($this->tgOffset);

        foreach ($updates as $update) {
            $this->tgOffset = $update['update_id'] + 1;

            $message = $update['message'] ?? null;
            if ($message === null) {
                continue;
            }

            $chatId = (string)($message['chat']['id'] ?? '');
            if ($chatId !== $this->telegram->getGroupId()) {
                continue;
            }

            $msgId = (string)($message['message_id'] ?? '');

            // Skip if this message was forwarded FROM max (echo prevention)
            if ($this->db->isSynced('telegram', $msgId, 'max')) {
                continue;
            }

            $this->logger->info("TG→MAX: processing message_id={$msgId}");

            $result = $this->forwardTelegramToMax($message);
            if ($result !== null) {
                $targetId = (string)($result['id'] ?? $result['message_id'] ?? '');
                // Record: telegram msgId was synced to max targetId
                $this->db->markSynced('telegram', $msgId, 'max', $targetId);
                // Prevent the reflected MAX message from being re-forwarded back
                if ($targetId !== '') {
                    $this->db->markSynced('max', $targetId, 'telegram', $msgId);
                }
            }
        }
    }

    /**
     * Forward one Telegram message to MAX.
     * Returns the MAX API response array, or null on failure / unsupported type.
     */
    private function forwardTelegramToMax(array $message): ?array
    {
        $info = $this->media->extractTelegramMedia($message);
        if ($info === null) {
            $this->logger->info('TG→MAX: unsupported message type, skipping');
            return null;
        }

        $senderName = $this->getSenderName($message);
        $prefix     = "[{$senderName}]: ";

        return match ($info['type']) {
            'text' => $this->max->sendMessage($prefix . $info['text']),

            'photo' => $this->handleTgMediaToMax(
                $info['file_id'],
                fn(string $path) => $this->max->sendPhoto($path, $prefix . $info['text']),
            ),

            'video' => $this->handleTgMediaToMax(
                $info['file_id'],
                fn(string $path) => $this->max->sendVideo($path, $prefix . $info['text']),
            ),

            'video_note' => $this->handleTgMediaToMax(
                $info['file_id'],
                fn(string $path) => $this->max->sendVideoNote($path),
            ),

            default => null,
        };
    }

    /**
     * Download a Telegram file and pass the local path to $send callback.
     * Cleans up the temp file afterwards.
     */
    private function handleTgMediaToMax(string $fileId, callable $send): ?array
    {
        $localPath = $this->media->downloadFromTelegram($fileId);
        if ($localPath === null) {
            $this->logger->error("TG→MAX: failed to download file_id={$fileId}");
            return null;
        }

        try {
            return $send($localPath) ?: null;
        } finally {
            $this->media->cleanup($localPath);
        }
    }

    // -------------------------------------------------------------------------
    // MAX → Telegram
    // -------------------------------------------------------------------------

    private function syncMaxToTelegram(): void
    {
        $result = $this->max->getUpdates($this->maxMarker);

        $updates         = $result['updates'] ?? [];
        $this->maxMarker = $result['marker']  ?? $this->maxMarker;

        foreach ($updates as $update) {
            if (($update['update_type'] ?? '') !== 'message_created') {
                continue;
            }

            $message = $update['message'] ?? null;
            if ($message === null) {
                continue;
            }

            $chatId = (string)($message['recipient']['chat_id'] ?? '');
            if ($chatId !== $this->max->getGroupId()) {
                continue;
            }

            $msgId = (string)($message['body']['mid'] ?? '');
            if ($msgId === '') {
                continue;
            }

            // Skip if this message was forwarded FROM telegram (echo prevention)
            if ($this->db->isSynced('max', $msgId, 'telegram')) {
                continue;
            }

            $this->logger->info("MAX→TG: processing mid={$msgId}");

            $result2 = $this->forwardMaxToTelegram($message);
            if ($result2 !== null) {
                $targetId = (string)($result2['message_id'] ?? '');
                $this->db->markSynced('max', $msgId, 'telegram', $targetId);
                if ($targetId !== '') {
                    $this->db->markSynced('telegram', $targetId, 'max', $msgId);
                }
            }
        }
    }

    /**
     * Forward one MAX message to Telegram.
     */
    private function forwardMaxToTelegram(array $message): ?array
    {
        $info = $this->media->extractMaxMedia($message);
        if ($info === null) {
            $this->logger->info('MAX→TG: unsupported message type, skipping');
            return null;
        }

        $senderName = $message['sender']['name'] ?? 'MAX user';
        $prefix     = "[{$senderName}]: ";

        return match ($info['type']) {
            'text' => $this->telegram->sendMessage($prefix . $info['text']),

            'photo' => $this->handleMaxMediaToTg(
                $info['url'] ?? '',
                fn(string $path) => $this->telegram->sendPhoto($path, $prefix . $info['text']),
            ),

            'video' => $this->handleMaxMediaToTg(
                $info['url'] ?? '',
                fn(string $path) => $this->telegram->sendVideo($path, $prefix . $info['text']),
            ),

            default => null,
        };
    }

    /**
     * Download a MAX file from URL and pass the local path to $send callback.
     */
    private function handleMaxMediaToTg(string $url, callable $send): ?array
    {
        if ($url === '') {
            $this->logger->error('MAX→TG: empty media URL, skipping');
            return null;
        }

        $localPath = $this->media->downloadFromUrl($url);
        if ($localPath === null) {
            $this->logger->error("MAX→TG: failed to download URL={$url}");
            return null;
        }

        try {
            return $send($localPath) ?: null;
        } finally {
            $this->media->cleanup($localPath);
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getSenderName(array $message): string
    {
        $from  = $message['from'] ?? [];
        $parts = array_filter([
            $from['first_name'] ?? '',
            $from['last_name']  ?? '',
        ]);
        return implode(' ', $parts) ?: ($from['username'] ?? 'TG user');
    }
}
