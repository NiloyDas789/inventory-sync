<?php

namespace App\Notifications;

use App\Models\SyncLog;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SyncProgressNotification extends Notification
{
    use Queueable;

    protected SyncLog $syncLog;
    protected int $processed;
    protected string $message;

    /**
     * Create a new notification instance.
     */
    public function __construct(SyncLog $syncLog, int $processed, string $message)
    {
        $this->syncLog = $syncLog;
        $this->processed = $processed;
        $this->message = $message;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // Use database channel for real-time updates
        return ['database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Sync Progress Update')
            ->line("Sync operation: {$this->message}")
            ->line("Records processed: {$this->processed}")
            ->line("Status: {$this->syncLog->status}")
            ->action('View Sync Log', url("/sync/logs/{$this->syncLog->id}"));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'sync_log_id' => $this->syncLog->id,
            'sync_type' => $this->syncLog->sync_type,
            'status' => $this->syncLog->status,
            'processed' => $this->processed,
            'total' => $this->syncLog->records_processed,
            'message' => $this->message,
            'percentage' => $this->syncLog->records_processed > 0 
                ? (int) (($this->processed / $this->syncLog->records_processed) * 100) 
                : 0,
            'created_at' => now()->toIso8601String(),
        ];
    }
}
