<?php

use App\Models\Op;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Address ops by an unguessable public_id instead of the sequential integer PK, so the op URL
 * can't be enumerated (op count / existence oracle) — OPSEC hygiene for an ephemeral ops tool.
 * The integer `id` stays the internal PK; every child FK (op_id) is untouched. SQLite allows
 * multiple NULLs under UNIQUE, so we add the column then backfill each existing op a distinct code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ops', function (Blueprint $table) {
            $table->string('public_id', 16)->nullable()->unique()->after('id');
        });

        Op::whereNull('public_id')->get()->each(
            fn (Op $op) => $op->forceFill(['public_id' => Op::freshPublicId()])->saveQuietly()
        );
    }

    public function down(): void
    {
        Schema::table('ops', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn('public_id');
        });
    }
};
