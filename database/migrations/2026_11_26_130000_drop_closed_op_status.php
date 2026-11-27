<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Nothing has set an op to "closed" since closing started purging the op outright, so the status leaves the
    // allowed set. SQLite keeps the set as a CHECK, so this rebuilds the table; `type` is redefined too because
    // the rebuild only keeps the CHECKs of columns it is given.
    public function up(): void
    {
        if (DB::table('ops')->where('status', 'closed')->exists()) {
            throw new RuntimeException('There are closed ops; delete or reopen them before dropping the status.');
        }
        $this->statuses(['planning', 'active', 'complete']);
    }

    public function down(): void
    {
        $this->statuses(['planning', 'active', 'complete', 'closed']);
    }

    private function statuses(array $allowed): void
    {
        Schema::table('ops', function (Blueprint $table) use ($allowed) {
            $table->enum('status', $allowed)->default('planning')->change();
            $table->enum('type', ['visible', 'hidden', 'any_order'])->default('any_order')->change();
        });
    }
};
