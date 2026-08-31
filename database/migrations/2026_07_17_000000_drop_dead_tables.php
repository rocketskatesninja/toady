<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop two tables created in the original schema but wired to nothing — no model, controller, or command
 * ever referenced `step_comments` or `saved_plans` (step notes live on op_steps.notes; templates use
 * op_step_templates). Removing dead schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('step_comments');
        Schema::dropIfExists('saved_plans');
    }

    public function down(): void
    {
        // Nothing to restore — these tables were never used by application code.
    }
};
