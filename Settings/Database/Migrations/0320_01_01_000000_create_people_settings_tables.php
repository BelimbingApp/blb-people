<?php

use App\Base\Database\Concerns\RegistersTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use RegistersTables;

    public function up(): void
    {
        Schema::create('people_reference_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('people_reference_entries')->nullOnDelete();
            $table->string('type')->index();
            $table->string('code');
            $table->string('name');
            $table->string('level')->nullable()->index();
            $table->text('description')->nullable();
            $table->string('status')->default('active')->index();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('source_system')->nullable()->index();
            $table->string('source_label')->nullable();
            $table->string('source_code')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'type', 'code']);
            $table->index(['company_id', 'type', 'status']);
            $table->index(['source_system', 'source_label', 'source_code'], 'people_ref_entries_source_identity_index');
        });
        $this->registerTable('people_reference_entries');

        Schema::create('people_reference_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('people_reference_entry_id')->constrained('people_reference_entries')->cascadeOnDelete();
            $table->string('source_system');
            $table->string('source_type');
            $table->string('source_code');
            $table->string('source_label')->nullable();
            $table->string('status')->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'source_system', 'source_type', 'source_code'], 'people_ref_aliases_company_source_unique');
            $table->index(['people_reference_entry_id', 'status']);
        });
        $this->registerTable('people_reference_aliases');

        Schema::create('people_employee_work_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('cost_center_id')->nullable()->constrained('people_reference_entries')->nullOnDelete();
            $table->foreignId('organization_unit_id')->nullable()->constrained('people_reference_entries')->nullOnDelete();
            $table->foreignId('employment_group_id')->nullable()->constrained('people_reference_entries')->nullOnDelete();
            $table->foreignId('job_title_id')->nullable()->constrained('people_reference_entries')->nullOnDelete();
            $table->foreignId('workforce_class_id')->nullable()->constrained('people_reference_entries')->nullOnDelete();
            $table->foreignId('job_grade_id')->nullable()->constrained('people_reference_entries')->nullOnDelete();
            $table->foreignId('work_calendar_id')->nullable()->constrained('people_reference_entries')->nullOnDelete();
            $table->string('pay_rate_type')->nullable();
            $table->date('hired_on')->nullable();
            $table->date('resigned_on')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('employee_id');
            $table->index(['cost_center_id', 'organization_unit_id'], 'people_emp_work_profiles_cost_org_index');
            $table->index(['employment_group_id', 'job_title_id', 'workforce_class_id'], 'people_emp_work_profiles_group_job_class_index');
        });
        $this->registerTable('people_employee_work_profiles');

        Schema::create('people_saved_employee_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('visibility')->default('private')->index();
            $table->string('status')->default('active')->index();
            $table->json('filters');
            $table->json('sort')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'user_id', 'name']);
            $table->index(['company_id', 'visibility', 'status']);
        });
        $this->registerTable('people_saved_employee_views');

        Schema::create('people_employee_portal_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('login_identifier')->nullable()->index();
            $table->string('display_name');
            $table->string('email')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_invited_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('employee_id');
            $table->index(['user_id', 'status']);
        });
        $this->registerTable('people_employee_portal_accesses');

        Schema::create('people_employee_profile_change_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users', indexName: 'people_emp_profile_req_requested_by_fk')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users', indexName: 'people_emp_profile_req_reviewed_by_fk')->nullOnDelete();
            $table->string('request_type')->default('profile_update')->index();
            $table->string('status')->default('submitted')->index();
            $table->json('requested_changes');
            $table->json('review_notes')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'status'], 'people_emp_profile_req_employee_status_index');
        });
        $this->registerTable('people_employee_profile_change_requests');

        Schema::create('people_restricted_person_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('person_name')->nullable();
            $table->string('document_type')->nullable();
            $table->string('document_number')->nullable();
            $table->string('status')->default('active')->index();
            $table->string('visibility')->default('restricted')->index();
            $table->text('summary')->nullable();
            $table->json('details')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'document_type', 'document_number'], 'people_restricted_entries_document_index');
        });
        $this->registerTable('people_restricted_person_entries');

        Schema::create('people_import_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_system');
            $table->string('source_label');
            $table->string('target_type');
            $table->boolean('dry_run')->default(true);
            $table->string('status')->default('pending')->index();
            $table->json('summary')->nullable();
            $table->json('row_results')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'source_system', 'target_type']);
        });
        $this->registerTable('people_import_jobs');

        Schema::create('people_notification_delivery_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete();
            $table->nullableMorphs('notifiable', 'people_delivery_logs_notifiable_index');
            $table->string('channel')->index();
            $table->string('recipient')->nullable();
            $table->string('subject')->nullable();
            $table->string('status')->index();
            $table->timestamp('sent_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'channel', 'status'], 'people_delivery_logs_company_channel_status_index');
        });
        $this->registerTable('people_notification_delivery_logs');

        Schema::create('people_calendar_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_calendar_id')->constrained('people_reference_entries')->cascadeOnDelete();
            $table->date('occurs_on');
            $table->string('name');
            $table->string('kind')->default('non_working_day')->index();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['work_calendar_id', 'occurs_on', 'kind'], 'people_calendar_exceptions_calendar_date_kind_unique');
        });
        $this->registerTable('people_calendar_exceptions');
    }

    public function down(): void
    {
        foreach ([
            'people_calendar_exceptions',
            'people_notification_delivery_logs',
            'people_import_jobs',
            'people_restricted_person_entries',
            'people_employee_profile_change_requests',
            'people_employee_portal_accesses',
            'people_saved_employee_views',
            'people_employee_work_profiles',
            'people_reference_aliases',
            'people_reference_entries',
        ] as $table) {
            $this->unregisterTable($table);
            Schema::dropIfExists($table);
        }
    }
};
