<?php

use App\Base\Database\Concerns\IncubatingSchema;
use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use IncubatingSchema;
    use RegistersTables;

    public function up(): void
    {
        Schema::create('people_attendance_shift_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->time('starts_at');
            $table->time('ends_at');
            $table->boolean('crosses_midnight')->default(false);
            $table->unsignedSmallInteger('expected_work_minutes')->default(480);
            $table->json('break_windows')->nullable();
            $table->json('day_type_overrides')->nullable();
            $table->string('cross_midnight_attribution')->default('shift_start_date');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status')->default('active')->index();
            $table->string('source_system')->nullable()->index();
            $table->string('source_label')->nullable();
            $table->string('source_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status', 'effective_from'], 'people_att_shift_templates_company_status_from_index');
        });
        $this->registerTable('people_attendance_shift_templates');

        Schema::create('people_attendance_punch_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attendance_shift_template_id')->constrained('people_attendance_shift_templates', indexName: 'people_att_punch_windows_shift_template_fk')->cascadeOnDelete();
            $table->string('event_type');
            $table->time('expected_at');
            $table->time('earliest_at')->nullable();
            $table->time('latest_at')->nullable();
            $table->boolean('required')->default(true);
            $table->boolean('exception_on_unmatched')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['attendance_shift_template_id', 'event_type'], 'people_att_punch_windows_template_event_index');
        });
        $this->registerTable('people_attendance_punch_windows');

        Schema::create('people_attendance_policy_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->json('cohort_predicate')->nullable();
            $table->json('work_hour_rules')->nullable();
            $table->json('lateness_rules')->nullable();
            $table->json('overtime_rules')->nullable();
            $table->json('overtime_export_rules')->nullable();
            $table->json('lateness_export_rules')->nullable();
            $table->string('currency', 3)->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('active')->index();
            $table->string('source_system')->nullable()->index();
            $table->string('source_label')->nullable();
            $table->string('source_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status', 'effective_from'], 'people_att_policy_groups_company_status_from_index');
        });
        $this->registerTable('people_attendance_policy_groups');

        Schema::create('people_attendance_allowance_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('attendance_policy_group_id')->nullable()->constrained('people_attendance_policy_groups', indexName: 'people_att_allowance_rules_policy_group_fk')->nullOnDelete();
            $table->foreignId('attendance_shift_template_id')->nullable()->constrained('people_attendance_shift_templates', indexName: 'people_att_allowance_rules_shift_template_fk')->nullOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('allowance_type')->default('daily');
            $table->string('payroll_pay_item_code')->nullable();
            $table->decimal('ceiling_amount', 12, 2)->nullable();
            $table->string('resolution_method')->default('sum');
            $table->json('condition_rows')->nullable();
            $table->text('source_script')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status')->default('active')->index();
            $table->string('source_system')->nullable()->index();
            $table->string('source_label')->nullable();
            $table->string('source_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status', 'allowance_type'], 'people_att_allowance_rules_company_status_type_index');
        });
        $this->registerTable('people_attendance_allowance_rules');

        Schema::create('people_attendance_roster_patterns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('pattern_type')->default('fixed_weekly');
            $table->json('pattern_definition');
            $table->string('status')->default('draft')->index();
            $table->string('source_system')->nullable()->index();
            $table->string('source_label')->nullable();
            $table->string('source_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'status', 'pattern_type'], 'people_att_roster_patterns_company_status_type_index');
        });
        $this->registerTable('people_attendance_roster_patterns');

        Schema::create('people_attendance_roster_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->foreignId('attendance_roster_pattern_id')->nullable()->constrained('people_attendance_roster_patterns', indexName: 'people_att_roster_assignments_pattern_fk')->nullOnDelete();
            $table->foreignId('attendance_shift_template_id')->nullable()->constrained('people_attendance_shift_templates', indexName: 'people_att_roster_assignments_shift_fk')->nullOnDelete();
            $table->foreignId('attendance_policy_group_id')->nullable()->constrained('people_attendance_policy_groups', indexName: 'people_att_roster_assignments_policy_fk')->nullOnDelete();
            $table->json('cohort_predicate')->nullable();
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('publish_state')->default('draft')->index();
            $table->string('lock_state')->default('open')->index();
            $table->unsignedInteger('revision')->default(1);
            $table->json('exceptions')->nullable();
            $table->string('source_system')->nullable()->index();
            $table->string('source_label')->nullable();
            $table->string('source_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'effective_from'], 'people_att_roster_assignments_company_employee_from_index');
            $table->index(['company_id', 'lock_state'], 'people_att_roster_assignments_company_lock_index');
        });
        $this->registerTable('people_attendance_roster_assignments');

        Schema::create('people_attendance_geofences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->string('location_label')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedInteger('radius_meters')->nullable();
            $table->string('status')->default('active')->index();
            $table->string('source_system')->nullable()->index();
            $table->string('source_label')->nullable();
            $table->string('source_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
        $this->registerTable('people_attendance_geofences');

        Schema::create('people_attendance_geofence_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->json('cohort_predicate')->nullable();
            $table->string('status')->default('active')->index();
            $table->string('source_system')->nullable()->index();
            $table->string('source_label')->nullable();
            $table->string('source_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'code']);
        });
        $this->registerTable('people_attendance_geofence_groups');

        Schema::create('people_attendance_geofence_group_fences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attendance_geofence_group_id');
            $table->foreignId('attendance_geofence_id')->constrained('people_attendance_geofences', indexName: 'people_att_group_fences_geofence_fk')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('attendance_geofence_group_id', 'people_attendance_group_fence_group_id_fk')
                ->references('id')
                ->on('people_attendance_geofence_groups')
                ->cascadeOnDelete();
            $table->unique(
                ['attendance_geofence_group_id', 'attendance_geofence_id'],
                'people_attendance_group_fence_group_fence_unique'
            );
        });
        $this->registerTable('people_attendance_geofence_group_fences');

        Schema::create('people_attendance_days', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('attendance_roster_assignment_id')->nullable()->constrained('people_attendance_roster_assignments')->nullOnDelete();
            $table->foreignId('attendance_shift_template_id')->nullable()->constrained('people_attendance_shift_templates')->nullOnDelete();
            $table->foreignId('attendance_policy_group_id')->nullable()->constrained('people_attendance_policy_groups')->nullOnDelete();
            $table->date('attendance_date');
            $table->string('status')->default('scheduled')->index();
            $table->string('day_type')->default('normal')->index();
            $table->timestamp('shift_starts_at')->nullable();
            $table->timestamp('shift_ends_at')->nullable();
            $table->unsignedSmallInteger('expected_minutes')->default(0);
            $table->unsignedSmallInteger('worked_minutes')->default(0);
            $table->unsignedSmallInteger('payable_minutes')->default(0);
            $table->unsignedSmallInteger('late_minutes')->default(0);
            $table->unsignedSmallInteger('early_out_minutes')->default(0);
            $table->unsignedSmallInteger('absent_minutes')->default(0);
            $table->unsignedSmallInteger('break_minutes')->default(0);
            $table->unsignedSmallInteger('overtime_candidate_minutes')->default(0);
            $table->date('payroll_period_date')->nullable();
            $table->json('exception_tags')->nullable();
            $table->json('projection_snapshot')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('exported_to_payroll_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'attendance_date']);
            $table->index(['company_id', 'attendance_date', 'status']);
            $table->index(['company_id', 'payroll_period_date']);
        });
        $this->registerTable('people_attendance_days');

        Schema::create('people_attendance_clock_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('attendance_day_id')->nullable()->constrained('people_attendance_days')->nullOnDelete();
            $table->foreignId('attendance_geofence_id')->nullable()->constrained('people_attendance_geofences')->nullOnDelete();
            $table->foreignId('attendance_geofence_group_id')->nullable()->constrained('people_attendance_geofence_groups', indexName: 'people_att_clock_events_geofence_group_fk')->nullOnDelete();
            $table->string('event_type');
            $table->timestamp('occurred_at');
            $table->string('timezone')->nullable();
            $table->string('source')->default('web_clock')->index();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('card_number')->nullable();
            $table->string('device_identifier')->nullable();
            $table->string('outlet_label')->nullable();
            $table->string('ip_address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('geofence_result')->nullable();
            $table->boolean('photo_evidence_present')->default(false);
            $table->foreignId('corrects_clock_event_id')->nullable()->constrained('people_attendance_clock_events')->nullOnDelete();
            $table->string('source_system')->nullable()->index();
            $table->string('source_label')->nullable();
            $table->string('source_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'occurred_at']);
            $table->index(['attendance_day_id', 'event_type'], 'people_att_clock_events_day_event_index');
            $table->index(['source', 'occurred_at']);
        });
        $this->registerTable('people_attendance_clock_events');

        Schema::create('people_attendance_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('attendance_day_id')->nullable()->constrained('people_attendance_days')->nullOnDelete();
            $table->string('adjustment_type');
            $table->string('status')->default('draft')->index();
            $table->json('requested_changes');
            $table->text('reason')->nullable();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['employee_id', 'adjustment_type']);
        });
        $this->registerTable('people_attendance_adjustments');

        Schema::create('people_attendance_overtime_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('attendance_day_id')->nullable()->constrained('people_attendance_days')->nullOnDelete();
            $table->string('request_mode')->default('post_work_actual');
            $table->string('status')->default('draft')->index();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedSmallInteger('requested_minutes')->default(0);
            $table->unsignedSmallInteger('approved_minutes')->default(0);
            $table->unsignedSmallInteger('payable_minutes')->default(0);
            $table->text('reason')->nullable();
            $table->unsignedSmallInteger('attachment_count')->default(0);
            $table->string('approval_profile_key')->nullable();
            $table->json('approval_route_snapshot')->nullable();
            $table->json('policy_snapshot')->nullable();
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users', indexName: 'people_att_overtime_requests_submitted_by_fk')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->timestamp('queued_for_payroll_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'submitted_at'], 'people_att_overtime_requests_company_status_submitted_index');
            $table->index(['employee_id', 'starts_at']);
        });
        $this->registerTable('people_attendance_overtime_requests');

        Schema::create('people_attendance_absence_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('reference')->nullable()->index();
            $table->string('status')->default('draft')->index();
            $table->date('period_starts_on');
            $table->date('period_ends_on');
            $table->date('lock_date')->nullable();
            $table->json('filters')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'period_starts_on'], 'people_att_absence_batches_company_status_period_index');
        });
        $this->registerTable('people_attendance_absence_batches');

        Schema::create('people_attendance_absence_batch_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('attendance_absence_batch_id');
            $table->foreignId('attendance_day_id')->nullable()->constrained('people_attendance_days', indexName: 'people_att_absence_entries_day_fk')->nullOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('absence_date');
            $table->string('day_type')->default('normal');
            $table->string('absence_code')->default('unauthorized_absence');
            $table->string('status')->default('candidate')->index();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('attendance_absence_batch_id', 'people_attendance_absence_entry_batch_id_fk')
                ->references('id')
                ->on('people_attendance_absence_batches')
                ->cascadeOnDelete();
            $table->unique(
                ['attendance_absence_batch_id', 'employee_id', 'absence_date'],
                'people_attendance_absence_entry_batch_employee_date_unique'
            );
            $table->index(['employee_id', 'absence_date', 'status'], 'people_att_absence_entries_employee_date_status_index');
        });
        $this->registerTable('people_attendance_absence_batch_entries');
    }

    public function down(): void
    {
        foreach ([
            'people_attendance_absence_batch_entries',
            'people_attendance_absence_batches',
            'people_attendance_overtime_requests',
            'people_attendance_adjustments',
            'people_attendance_clock_events',
            'people_attendance_days',
            'people_attendance_geofence_group_fences',
            'people_attendance_geofence_groups',
            'people_attendance_geofences',
            'people_attendance_roster_assignments',
            'people_attendance_roster_patterns',
            'people_attendance_allowance_rules',
            'people_attendance_policy_groups',
            'people_attendance_punch_windows',
            'people_attendance_shift_templates',
        ] as $table) {
            $this->unregisterTable($table);
            Schema::dropIfExists($table);
        }
    }
};
