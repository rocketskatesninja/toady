<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // user-submitted "report a problem" tickets (message + optional screenshots), seen by the owner
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); // keep the report if the user leaves
            $table->string('reply_email')->nullable();
            $table->text('message');
            $table->string('url')->nullable();          // the page they were on
            $table->string('user_agent', 512)->nullable();
            $table->json('attachments')->nullable();     // stored paths on the private disk
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
