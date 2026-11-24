<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('avatar')->nullable();
            $table->boolean('is_admin')->default(false);
            $table->boolean('is_hr')->default(false);
            $table->unsignedBigInteger('company_id')->default(1);
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('designation_id')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->string('employee_id')->nullable();
            $table->string('employee_type')->nullable();
            $table->string('grade')->nullable();
            $table->date('joining_date')->nullable();
            // personal
            $table->string('gender')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('address')->nullable();
            $table->string('nationality')->nullable();
            $table->string('nid_card_number')->nullable();
            $table->string('nid_file')->nullable();
            $table->string('passport_number')->nullable();
            $table->string('passport_file')->nullable();
            $table->date('passport_expire_date')->nullable();
            $table->string('marital_status')->nullable();
            $table->string('blood_group')->nullable();
            $table->string('facebook_link')->nullable();
            $table->string('linkedin_link')->nullable();
            $table->string('instagram_link')->nullable();
            // emergency
            $table->string('emergency_name')->nullable();
            $table->string('emergency_mobile_number')->nullable();
            $table->string('emergency_mobile_relationship')->nullable();
            // financial
            $table->string('tin')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account')->nullable();
            $table->decimal('basic_salary', 12, 2)->default(0);
            // device / app
            $table->string('device_id')->nullable();
            $table->text('device_info')->nullable();
            $table->string('firebase_token')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
