<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The Tasks widget: a minimal op checklist, separate from the plan's directives (no portals/actions/order). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('op_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('op_id')->constrained()->cascadeOnDelete(); // closing the op purges its tasks
            $table->string('text', 200);
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete(); // null = anyone
            $table->boolean('done')->default(false);
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('op_tasks');
    }
};
