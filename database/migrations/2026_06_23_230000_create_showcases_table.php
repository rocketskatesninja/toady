<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('showcases', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('story')->nullable();
            $table->string('credit')->nullable();       // submitter / author name (free text)
            $table->json('images')->nullable();         // up to 3 stored image paths (private disk, streamed out)
            $table->json('tagged_ids')->nullable();     // tagged registered-user ids
            $table->boolean('published')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('showcases');
    }
};
