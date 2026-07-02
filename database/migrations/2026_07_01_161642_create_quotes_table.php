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
        Schema::create('quotes', function (Blueprint $table) {
                $table->id();

                $table->foreignId('building_id')->constrained()->cascadeOnDelete();

                $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();

                $table->foreignId('created_by')->constrained('users');

                $table->string('title');

                $table->text('description')->nullable();

                $table->decimal('amount', 10, 2)->default(0);

                $table->string('status')->default('pending');

                // pending | sent | approved | rejected

                $table->string('priority')->default('normal');

                // low | normal | high | urgent

                $table->string('public_token')->unique();

                $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};
