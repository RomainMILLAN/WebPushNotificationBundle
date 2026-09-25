<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The same schema as the Doctrine mapping of the Symfony bridge: both adapters go
 * through the core SubscriptionRowMapper.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('web_push_subscription', static function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->string('owner_type', 16);
            $table->string('subscriber_id', 191)->nullable();
            // Possibly encrypted (v1:<keyId>:...): text, not a bounded string.
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('push_host', 253);
            $table->text('p256dh');
            $table->text('auth');
            $table->string('content_encoding', 16);
            $table->dateTime('registered_at');
            $table->dateTime('last_registered_at');
            $table->dateTime('retired_at')->nullable();
            $table->string('retirement_reason', 16)->nullable();

            $table->index(['subscriber_id', 'retired_at']);
            $table->index(['owner_type', 'retired_at']);
            $table->index('last_registered_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_push_subscription');
    }
};
