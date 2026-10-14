<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TuitionPaymentSuccessNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $amount,
        public readonly string $description = 'Tuition payment settled on Arc Testnet.',
        public readonly ?string $txHash = null,
        public readonly ?string $term = null,
        public readonly ?string $appliedAmount = null,
        public readonly ?string $overpaymentAmount = null,
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
            'tx_hash' => $this->txHash,
            'term' => $this->term,
            'applied_amount' => $this->appliedAmount,
            'overpayment_amount' => $this->overpaymentAmount,
            'type' => 'tuition_payment',
        ];
    }
}
