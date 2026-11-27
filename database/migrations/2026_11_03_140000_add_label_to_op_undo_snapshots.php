<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each undoable plan edit was, in a few words ("deleted portal “Lighthouse”") — so the Undo button can say
 * exactly what it will reverse. Snapshots taken before this have none and read as "the last plan change".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('op_undo_snapshots', function (Blueprint $table) {
            $table->string('label', 160)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('op_undo_snapshots', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
