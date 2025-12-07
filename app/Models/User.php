<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Osiset\ShopifyApp\Contracts\ShopModel as IShopModel;
use Osiset\ShopifyApp\Traits\ShopModel as ShopModelTrait;

class User extends Authenticatable implements IShopModel
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, ShopModelTrait;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'shopify_grandfathered',
        'shopify_namespace',
        'shopify_freemium',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'shopify_grandfathered' => 'boolean',
            'shopify_freemium' => 'boolean',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Get the Google Sheets connection for this shop
     */
    public function googleSheetsConnection()
    {
        return $this->hasOne(GoogleSheetsConnection::class, 'shop_id');
    }

    /**
     * Get the sync field mappings for this shop
     */
    public function syncFieldMappings()
    {
        return $this->hasMany(SyncFieldMapping::class, 'shop_id');
    }
}
