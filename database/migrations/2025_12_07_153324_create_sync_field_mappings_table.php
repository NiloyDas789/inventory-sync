<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sync_field_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('users')->onDelete('cascade');
            $table->string('shopify_field'); // e.g., 'product_title', 'inventory_quantity', 'price'
            $table->string('sheet_column'); // e.g., 'A', 'B', 'Product Name'
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->index(['shop_id', 'is_active']);
            $table->index('display_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_field_mappings');
    }
};
