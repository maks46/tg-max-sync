<?php

declare(strict_types=1);

namespace App;

/**
 * Orchestrates bidirectional sync between Telegram and MAX (via Green API).
 *
 * Direction TG → MAX:
 *   Long-poll Telegram getUpdates → for each new message in the target group
 *   that hasn't been synced yet → forward to MAX → record in DB.
 *
 * Direction MAX → TG:
 *   Poll Green API receiveNotification (HTTP-API, FIFO queue) →
 *   filter incomingMessageReceived for the target chatId →
 *   forward to Telegram → deleteNotification → record in DB.
 */
class SyncWorker
{
    private int $tgOffset = 0;

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
     * Used in single-process fallback mode.
     */
    public function runCycle(): void
    {
        $this->syncTelegramToMax();
        $this->syncMaxToTelegram();
    }

    /**
     * Run only the Telegram → MAX direction.
     * Used in the parent process of the two-process architecture.
     */
    public function runTelegramToMaxCycle(): void
    {
        $this->syncTelegramToMax();
    }

    /**
     * Run only the MAX → Telegram direction.
     * Used in the child process of the two-process architecture.
     * Calls receiveNotification once per invocation (blocks up to 5 s).
     */
    public function runMaxToTelegramCycle(): void
    {
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
                // Green API sendMessage / sendFileByUpload returns {"idMessage": "..."}
                $targetId = (string)($result['idMessage'] ?? '');
                $this->db->markSynced('telegram', $msgId, 'max', $targetId);
                if ($targetId !== '') {
                    $this->db->markSynced('max', $targetId, 'telegram', $msgId);
                }
            }
        }
    }

    /**
     * Forward one Telegram message to MAX.
     */
    private function forwardTelegramToMax(array $message): ?array
    {
        $info = $this->media->extractTelegramMedia($message);
        if ($info === null) {
            $this->logger->info('TG→MAX: unsupported message type, skipping');
            return null;
        }

        $senderName = $this->getSenderName($message);
        $caption    = $this->buildCaption($senderName, $info['text'] ?? '');

        return match ($info['type']) {
            'text' => $this->max->sendMessage($caption),

            'photo' => $this->handleTgMediaToMax(
                $info['file_id'],
                fn(string $path) => $this->max->sendPhoto($path, $caption),
            ),

            'video' => $this->handleTgMediaToMax(
                $info['file_id'],
                fn(string $path) => $this->max->sendVideo($path, $caption),
            ),

            // Video notes have no caption — send author line as a separate text message first,
            // then send the video note itself.
            'video_note' => $this->handleTgMediaToMax(
                $info['file_id'],
                function (string $path) use ($senderName): array {
                    $this->max->sendMessage("[{$senderName}] прислал видеозаметку:");
                    return $this->max->sendVideoNote($path);
                },
            ),

            default => null,
        };
    }

    /**
     * Download a Telegram file and pass the local path to $send callback.
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
    // MAX → Telegram  (Green API HTTP-API polling)
    // -------------------------------------------------------------------------

    private function syncMaxToTelegram(): void
    {
        // Green API returns one notification per call; loop until queue is empty.
        while (true) {
            $notification = $this->max->receiveNotification();
            if ($notification === null) {
                break; // queue empty or timeout
            }

            $receiptId = (int)($notification['receiptId'] ?? 0);
            $body      = $notification['body'] ?? [];

            try {
                $this->processMaxNotification($body);
            } finally {
                // Always acknowledge, even on processing errors, to avoid infinite replay.
                if ($receiptId > 0) {
                    $this->max->deleteNotification($receiptId);
                }
            }
        }
    }

    /**
     * Process a single Green API notification body.
     *
     * Handled webhook types:
     *   incomingMessageReceived  – message from another user
     *   outgoingMessageWebhook   – message sent from the phone linked to the instance
     *   outgoingAPIMessageWebhook – message sent via API (by this worker itself — skip to avoid echo)
     */
    private function processMaxNotification(array $body): void
    {
        $typeWebhook = $body['typeWebhook'] ?? '';

        // For outgoing webhooks chatId is in chatData, for incoming — in senderData
        $chatId = (string)(
            $body['chatData']['chatId']   // outgoing
            ?? $body['senderData']['chatId']  // incoming
            ?? ''
        );
        $msgId = (string)($body['idMessage'] ?? '');

        // Log every notification for diagnostics
        $this->logger->info("MAX notification: type={$typeWebhook} chatId={$chatId} idMessage={$msgId}");

        // Handled types:
        //   incomingMessageReceived  – message from another user
        //   outgoingMessageReceived  – message sent from the linked phone (owner)
        //   outgoingMessageWebhook   – same, alternative name in some API versions
        // Skipped: outgoingAPIMessageWebhook – sent by THIS worker via API → avoid echo
        $handled = [
            'incomingMessageReceived',
            'outgoingMessageReceived',
            'outgoingMessageWebhook',
        ];
        if (!in_array($typeWebhook, $handled, true)) {
            return;
        }

        // Filter to our target group chat
        if ($chatId !== $this->max->getGroupId()) {
            $this->logger->info("MAX notification: skipping chatId={$chatId} (expected {$this->max->getGroupId()})");
            return;
        }

        if ($msgId === '') {
            return;
        }

        // Skip if this message was forwarded FROM telegram (echo prevention)
        if ($this->db->isSynced('max', $msgId, 'telegram')) {
            return;
        }

        $this->logger->info("MAX→TG: processing idMessage={$msgId}");

        // For outgoing messages the sender is the instance owner — use wid as name
        $senderName = $body['senderData']['senderName']
            ?? $body['senderData']['chatName']
            ?? ($typeWebhook === 'outgoingMessageWebhook' ? 'MAX (owner)' : 'MAX user');

        $result = $this->forwardMaxToTelegram($body, $senderName);
        if ($result !== null) {
            $targetId = (string)($result['message_id'] ?? '');
            $this->db->markSynced('max', $msgId, 'telegram', $targetId);
            if ($targetId !== '') {
                $this->db->markSynced('telegram', $targetId, 'max', $msgId);
            }
        }
    }

    /**
     * Forward one MAX notification body to Telegram.
     *
     * @param string|null $senderName Override sender name (used for outgoing messages).
     */
    private function forwardMaxToTelegram(array $body, ?string $senderName = null): ?array
    {
        $info = $this->media->extractMaxMedia($body);
        if ($info === null) {
            $this->logger->info('MAX→TG: unsupported message type, skipping');
            return null;
        }

        $senderName ??= $body['senderData']['senderName'] ?? ($body['senderData']['chatName'] ?? 'MAX user');
        $caption      = $this->buildCaption($senderName, $info['text'] ?? '');

        return match ($info['type']) {
            'text' => $this->telegram->sendMessage($caption),

            'photo' => $this->handleMaxMediaToTg(
                $info['url'] ?? '',
                fn(string $path) => $this->telegram->sendPhoto($path, $caption),
            ),

            'video' => $this->handleMaxMediaToTg(
                $info['url'] ?? '',
                fn(string $path) => $this->telegram->sendVideo($path, $caption),
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

    /**
     * Build the caption/text that will be prepended to every forwarded message.
     *
     * Format:
     *   "[Author]: message text"   – when the original message has text
     *   "[Author]"                 – when there is no text (photo/video without caption)
     */
    private function buildCaption(string $senderName, string $text): string
    {
        $text = trim($text);
        return $text !== ''
            ? "[{$senderName}]: {$text}"
            : "[{$senderName}]";
    }
}
