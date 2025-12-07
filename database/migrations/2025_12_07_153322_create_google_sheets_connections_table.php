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
        Schema::create('google_sheets_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('users')->onDelete('cascade');
            $table->string('sheet_id');
            $table->text('sheet_url');
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique('shop_id'); // One connection per shop
            $table->index('sheet_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('google_sheets_connections');
    }
};
