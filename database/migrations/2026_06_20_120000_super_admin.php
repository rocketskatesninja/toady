<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('is_owner');
            $table->timestamp('suspended_at')->nullable()->after('is_admin');
        });

        // lightweight audit trail for admin actions
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label')->nullable(); // snapshot so the log survives actor deletion
            $table->string('action');
            $table->string('summary');
            $table->timestamp('created_at')->nullable();
        });

        // the catalog owner becomes the super admin
        DB::table('users')->where('is_owner', true)->update(['is_admin' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['is_admin', 'suspended_at']));
    }
};
