<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// reusable, op-scoped sets of directives ("templates") — save one location's objectives, apply to others.
// op_id cascade means they're purged with the op on close, like everything else.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('op_step_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('op_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('steps'); // [{action, text, mods, qty}, ...]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('op_step_templates');
    }
};
