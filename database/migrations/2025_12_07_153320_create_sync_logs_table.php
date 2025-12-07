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
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained('users')->onDelete('cascade');
            $table->string('sync_type'); // e.g., 'products', 'inventory', 'full'
            $table->string('status'); // e.g., 'pending', 'processing', 'completed', 'failed'
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('records_processed')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index('started_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
