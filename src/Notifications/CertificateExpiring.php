<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * Opt-in notification sent by certificates:check when a certificate is nearing
 * expiry. Channels are configurable via certificates.notifications.channels.
 */
final class CertificateExpiring extends Notification
{
    public function __construct(
        public readonly Certificate $certificate,
        public readonly int $daysUntilExpiry,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        /** @var list<string> $channels */
        $channels = config('certificates.notifications.channels', ['mail']);

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject((string) trans('certificates::messages.notifications.expiring.subject', [
                'domain' => $this->certificate->domain,
            ]))
            ->line((string) trans('certificates::messages.notifications.expiring.line', [
                'domain' => $this->certificate->domain,
                'days' => $this->daysUntilExpiry,
            ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'domain' => $this->certificate->domain,
            'driver' => $this->certificate->driver,
            'days_until_expiry' => $this->daysUntilExpiry,
            'expires_at' => $this->certificate->expires_at?->toIso8601String(),
        ];
    }
}
