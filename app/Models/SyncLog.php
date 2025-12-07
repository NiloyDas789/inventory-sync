<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'sync_type',
        'status',
        'started_at',
        'completed_at',
        'records_processed',
        'error_message',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'records_processed' => 'integer',
    ];

    /**
     * Get the shop that owns this sync log
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shop_id');
    }

    /**
     * Mark sync as started
     */
    public function markAsStarted(): self
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);
        return $this;
    }

    /**
     * Mark sync as completed
     */
    public function markAsCompleted(int $recordsProcessed = 0): self
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'records_processed' => $recordsProcessed,
        ]);
        return $this;
    }

    /**
     * Mark sync as failed
     */
    public function markAsFailed(string $errorMessage): self
    {
        $this->update([
            'status' => 'failed',
            'completed_at' => now(),
            'error_message' => $errorMessage,
        ]);
        return $this;
    }

    /**
     * Update records processed count
     */
    public function updateRecordsProcessed(int $count): self
    {
        $this->update(['records_processed' => $count]);
        return $this;
    }
}
