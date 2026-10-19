<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->string('eyebrow', 80)->nullable()->after('preheader');      // small label above the headline
            $table->string('headline', 200)->nullable()->after('eyebrow');      // big title at the top of the email
            $table->string('button_label', 80)->nullable()->after('body');      // call-to-action button
            $table->string('button_url', 500)->nullable()->after('button_label');
        });
    }

    public function down(): void
    {
        Schema::table('email_campaigns', function (Blueprint $table) {
            $table->dropColumn(['eyebrow', 'headline', 'button_label', 'button_url']);
        });
    }
};
