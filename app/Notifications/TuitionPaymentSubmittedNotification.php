<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TuitionPaymentSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $amount,
        public readonly string $description = 'Tuition payment sent to Arc Testnet rail for processing.',
        public readonly ?string $term = null,
        public readonly ?string $requestKey = null,
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
            'title' => "Tuition payment of {$this->amount} USDC sent",
            'message' => $this->description,
            'amount' => $this->amount,
            'term' => $this->term,
            'request_key' => $this->requestKey,
            'type' => 'tuition_payment_submitted',
        ];
    }
}
