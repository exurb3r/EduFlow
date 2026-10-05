<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained();
            $table->string('student_number')->unique();
            $table->string('program');
            $table->integer('year_level');
            $table->string('enrollment_status')->default('enrolled');
            $table->string('academic_status')->default('qualified');
            $table->decimal('attendance_rate', 5, 2)->nullable();
            $table->timestamps();
        });

        Schema::create('academic_terms', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestamps();
        });

        Schema::create('tuition_accounts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('academic_term_id')->index()->constrained();
            $table->integer('total_amount');
            $table->integer('paid_amount');
            $table->timestamps();
            $table->unique(['student_id', 'academic_term_id']);
        });

        Schema::table('assistance_requests', function (Blueprint $table): void {
            $table->string('ticket_number')->nullable()->change();
            $table->foreignId('user_id')->nullable()->change();
            $table->string('subject')->nullable()->change();
            $table->text('description')->nullable()->change();
            $table->string('status')->default('submitted')->change();

            $table->foreignId('student_id')->nullable()->constrained('students');
            $table->foreignId('academic_term_id')->nullable()->index()->constrained('academic_terms');
            $table->string('type')->default('emergency');
            $table->bigInteger('requested_amount')->nullable();
            $table->text('reason')->nullable();
            $table->uuid('submission_key')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unique(['student_id', 'submission_key']);
        });

        $this->constrainAmounts();
    }

    private function constrainAmounts(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite cannot add CHECK constraints after CREATE TABLE.
            foreach (['INSERT', 'UPDATE'] as $operation) {
                DB::unprepared("CREATE TRIGGER tuition_accounts_amounts_{$operation}
                    BEFORE {$operation} ON tuition_accounts
                    WHEN NEW.total_amount < 0 OR NEW.paid_amount < 0 OR NEW.paid_amount > NEW.total_amount
                    BEGIN SELECT RAISE(ABORT, 'Invalid tuition account amounts'); END");
                DB::unprepared("CREATE TRIGGER assistance_requests_amounts_{$operation}
                    BEFORE {$operation} ON assistance_requests
                    WHEN NEW.requested_amount IS NOT NULL AND NEW.requested_amount < 0
                    BEGIN SELECT RAISE(ABORT, 'Invalid requested amount'); END");
            }

            return;
        }

        DB::statement('ALTER TABLE tuition_accounts ADD CONSTRAINT tuition_accounts_amounts_check CHECK (total_amount >= 0 AND paid_amount >= 0 AND paid_amount <= total_amount)');
        DB::statement('ALTER TABLE assistance_requests ADD CONSTRAINT assistance_requests_amounts_check CHECK (requested_amount IS NULL OR requested_amount >= 0)');
    }

    public function down(): void
    {
        Schema::table('assistance_requests', function (Blueprint $table): void {
            $table->dropForeign(['student_id']);
            $table->dropForeign(['academic_term_id']);
            $table->dropColumn(['student_id', 'academic_term_id', 'type', 'requested_amount', 'reason', 'submission_key', 'submitted_at']);
        });
        Schema::dropIfExists('tuition_accounts');
        Schema::dropIfExists('academic_terms');
        Schema::dropIfExists('students');
    }
};
