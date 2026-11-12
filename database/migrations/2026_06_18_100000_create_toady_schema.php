<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * toady v2 — ephemeral, op-centric schema. Every op-child table cascades on the op's
 * deletion, so closing an op purges all of its data in one DELETE. The master catalog and
 * a saved user's plans/profile are the only durable rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- ops (ephemeral; purged on close) ----
        Schema::create('ops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['visible', 'hidden', 'any_order'])->default('any_order');
            $table->enum('status', ['planning', 'active', 'closed'])->default('planning');
            $table->string('join_token', 32)->unique();
            $table->boolean('allow_export')->default(true);
            $table->text('goals')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('op_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('op_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('role', ['operative', 'agent'])->default('agent');
            $table->timestamps();
            $table->unique(['op_id', 'user_id']);
        });

        // ---- op content (waypoints snapshot intel; steps = the checklist/directives) ----
        Schema::create('op_waypoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('op_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('seq')->default(0);
            $table->enum('role', ['anchor', 'spine', 'target', 'waypoint'])->default('waypoint');
            $table->string('title')->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            // snapshot intel copied from the master catalog or entered op-local
            $table->string('gate_pin')->nullable();
            $table->text('access_notes')->nullable();
            $table->string('parking')->nullable();
            $table->string('hours')->nullable();
            $table->text('hazards')->nullable();
            $table->timestamps();
            $table->index(['op_id', 'seq']);
        });

        Schema::create('op_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('op_id')->constrained()->cascadeOnDelete();
            $table->foreignId('op_waypoint_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('phase', ['prep', 'run'])->default('run');
            $table->unsignedInteger('seq')->default(0);
            $table->string('text');
            $table->string('action')->nullable();   // hack|deploy|link|field|mod|capture|passphrase|move|note
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resos')->nullable();
            $table->string('mods')->nullable();
            $table->json('links')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('done')->default(false);
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
            $table->index(['op_id', 'phase', 'seq']);
        });

        Schema::create('op_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('op_id')->constrained()->cascadeOnDelete();
            $table->string('portal_title')->nullable();
            $table->unsignedInteger('needed')->default(0);
            $table->unsignedInteger('held')->default(0);
            $table->timestamps();
        });

        // ---- live + comms (all op-scoped, purged) ----
        Schema::create('op_presence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('op_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('sharing')->default(false);
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedInteger('accuracy')->nullable();
            $table->timestamp('last_seen')->nullable();
            $table->timestamps();
            $table->unique(['op_id', 'user_id']);
        });

        Schema::create('op_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('op_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['op_id', 'id']);
        });

        // 1:1 direct messages, scoped to an op (purged with it).
        Schema::create('direct_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('op_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['op_id', 'sender_id', 'recipient_id']);
        });

        Schema::create('step_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('op_step_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        // ---- durable: master catalog (owner-only edit) ----
        Schema::create('master_portals', function (Blueprint $table) {
            $table->id();
            $table->string('guid')->unique();
            $table->string('title')->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('region')->nullable();
            $table->string('source')->nullable();
            $table->string('first_seen')->nullable();
            $table->string('last_seen')->nullable();
            $table->string('gate_pin')->nullable();
            $table->text('access_notes')->nullable();
            $table->string('parking')->nullable();
            $table->string('hours')->nullable();
            $table->text('hazards')->nullable();
            $table->timestamps();
            $table->index('region');
            $table->index('title');
            $table->index(['lat', 'lng']);
        });

        // ---- durable: per-user (only meaningful for saved accounts) ----
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint', 1024);
            $table->string('p256dh')->nullable();
            $table->string('auth')->nullable();
            $table->timestamps();
        });

        Schema::create('saved_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->json('payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'saved_plans', 'push_subscriptions', 'master_portals', 'step_comments',
            'direct_messages', 'op_messages', 'op_presence', 'op_keys', 'op_steps',
            'op_waypoints', 'op_participants', 'ops',
        ] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
