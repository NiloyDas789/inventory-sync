<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Exception;

class GoogleSheetsConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'shop_id',
        'sheet_id',
        'sheet_url',
        'access_token',
        'refresh_token',
        'last_synced_at',
    ];

    protected $casts = [
        'last_synced_at' => 'datetime',
    ];

    /**
     * Get the shop that owns this connection
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shop_id');
    }

    /**
     * Get decrypted access token
     */
    public function getDecryptedAccessToken(): ?string
    {
        if (!$this->access_token) {
            return null;
        }

        try {
            return Crypt::decryptString($this->access_token);
        } catch (Exception $e) {
            Log::error('Failed to decrypt access token', [
                'connection_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get decrypted refresh token
     */
    public function getDecryptedRefreshToken(): ?string
    {
        if (!$this->refresh_token) {
            return null;
        }

        try {
            return Crypt::decryptString($this->refresh_token);
        } catch (Exception $e) {
            Log::error('Failed to decrypt refresh token', [
                'connection_id' => $this->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if connection has valid tokens
     */
    public function hasValidTokens(): bool
    {
        return !empty($this->refresh_token);
    }

    /**
     * Encrypt and set access token
     */
    public function setAccessToken(?string $value): self
    {
        $this->attributes['access_token'] = $value ? Crypt::encryptString($value) : null;
        return $this;
    }

    /**
     * Encrypt and set refresh token
     */
    public function setRefreshToken(?string $value): self
    {
        $this->attributes['refresh_token'] = $value ? Crypt::encryptString($value) : null;
        return $this;
    }
}
