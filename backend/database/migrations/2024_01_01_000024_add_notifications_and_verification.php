<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform v1.3: notification templates (global + per-org overrides),
 * in-app notification center, worker/vendor verification steps, and org
 * notification settings (WhatsApp / OTP toggles — provider-gated).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Editable message templates. org_type/org_id NULL = the global
        // default (super admin); a row with org set = that org's override.
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60);            // welcome_user, vendor_approved, ...
            $table->string('channel', 20)->default('email'); // email|inapp|whatsapp
            $table->string('org_type', 20)->nullable();      // company|vendor|NULL=global
            $table->unsignedBigInteger('org_id')->nullable();
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->unique(['key', 'channel', 'org_type', 'org_id'], 'tpl_scope_unique');
        });

        // In-app notification center (per login).
        Schema::create('notifications_inapp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 60);           // vendor_approved, worker_registered, ...
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->json('data')->nullable();     // ids/links for the UI
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });

        Schema::table('workers', function (Blueprint $table) {
            if (! Schema::hasColumn('workers', 'email')) {
                $table->string('email', 150)->nullable()->after('mobile');
            }
            if (! Schema::hasColumn('workers', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('email');
            }
            if (! Schema::hasColumn('workers', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            }
        });

        Schema::table('vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('vendors', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable()->after('contact_phone');
            }
            if (! Schema::hasColumn('vendors', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable()->after('email_verified_at');
            }
            if (! Schema::hasColumn('vendors', 'settings')) {
                $table->json('settings')->nullable();
            }
        });

        // companies already has settings json.
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('notifications_inapp');
        Schema::table('workers', function (Blueprint $table) {
            $table->dropColumn(['email', 'email_verified_at', 'phone_verified_at']);
        });
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn(['email_verified_at', 'phone_verified_at', 'settings']);
        });
    }
};
