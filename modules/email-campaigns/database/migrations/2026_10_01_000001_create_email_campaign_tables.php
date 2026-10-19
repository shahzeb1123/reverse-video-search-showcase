<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');                       // internal label
            $table->string('subject');
            $table->string('preheader')->nullable();      // inbox preview text
            $table->string('from_name')->nullable();      // overrides the SMTP from-name only
            $table->string('reply_to')->nullable();
            $table->longText('body');                     // HTML with {{merge}} tags

            // Audience: users | subscribers | both, plus segment filters (json)
            $table->string('audience', 20)->default('users');
            $table->json('filters')->nullable();

            // draft | scheduled | preparing | sending | paused | completed | cancelled | failed
            $table->string('status', 20)->default('draft')->index();
            $table->string('status_note')->nullable();    // e.g. why it was auto-paused
            $table->timestamp('scheduled_at')->nullable()->index();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->unsignedSmallInteger('throttle_per_minute')->default(60);
            $table->boolean('track_opens')->default(true);
            $table->boolean('track_clicks')->default(true);

            // Counters (recipient rows are the source of truth; these are for fast lists)
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);   // suppressed / opted out
            $table->unsignedInteger('opened_count')->default(0);    // unique recipients
            $table->unsignedInteger('clicked_count')->default(0);   // unique recipients
            $table->unsignedInteger('unsubscribed_count')->default(0);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        Schema::create('email_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('token', 40)->unique();        // public id for tracking + unsubscribe links

            // pending | sending | sent | failed | skipped
            $table->string('status', 12)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamp('opened_at')->nullable();
            $table->unsignedSmallInteger('open_count')->default(0);
            $table->timestamp('clicked_at')->nullable();
            $table->unsignedSmallInteger('click_count')->default(0);
            $table->timestamp('unsubscribed_at')->nullable();

            $table->timestamps();

            $table->unique(['campaign_id', 'email']);
            $table->index(['campaign_id', 'status']);
        });

        // Global do-not-email list: unsubscribes and hard bounces. Every campaign checks it.
        Schema::create('email_suppressions', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();            // always stored lower-case
            $table->string('reason', 20)->default('unsubscribed');   // unsubscribed | bounced | manual
            $table->unsignedBigInteger('campaign_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_suppressions');
        Schema::dropIfExists('email_campaign_recipients');
        Schema::dropIfExists('email_campaigns');
    }
};
