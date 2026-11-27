<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable pre-edit snapshots of an op's plan (the undo stack). One row per captured edit;
        // `data` holds the op's three plan tables verbatim. Pruned to the newest N per op, purged on close.
        Schema::create('op_undo_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('op_id')->constrained()->cascadeOnDelete();
            $table->json('data');
            $table->timestamp('created_at')->nullable();
            $table->index(['op_id', 'id']); // "latest per op" pop + prune
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('op_undo_snapshots');
    }
};
