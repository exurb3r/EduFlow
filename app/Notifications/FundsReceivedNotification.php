<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class FundsReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $amount,
        public readonly string $description = 'Payment received in your wallet.',
        public readonly ?string $txHash = null,
        public readonly ?string $source = null,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'title' => "Received {$this->amount} USDC",
            'message' => $this->description,
            'amount' => $this->amount,
            'tx_hash' => $this->txHash,
            'source' => $this->source,
        ];
    }
}
