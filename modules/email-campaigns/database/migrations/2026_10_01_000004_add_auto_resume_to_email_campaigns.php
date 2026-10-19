<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            // Set when the mail provider's sending limit pauses a campaign; the scheduler resumes it then.
            $table->timestamp('resume_at')->nullable()->after('scheduled_at');
            $table->unsignedSmallInteger('rate_limit_hits')->default(0)->after('resume_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->dropColumn(['resume_at', 'rate_limit_hits']);
        });
    }
};
