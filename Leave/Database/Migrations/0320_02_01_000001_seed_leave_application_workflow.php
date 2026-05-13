<?php

use App\Modules\People\Leave\Models\LeaveRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FLOW = 'leave_application';

    public function up(): void
    {
        $now = now();

        DB::table('base_workflow')->updateOrInsert(
            ['code' => self::FLOW],
            [
                'label' => 'Leave Application',
                'module' => 'people.leave',
                'description' => 'Employee leave request lifecycle: draft → submitted → approved/rejected/cancelled → applied/withdrawn.',
                'model_class' => LeaveRequest::class,
                'is_active' => true,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        $statuses = [
            [LeaveRequest::STATUS_DRAFT, 'Draft', 10],
            [LeaveRequest::STATUS_SUBMITTED, 'Submitted', 20],
            [LeaveRequest::STATUS_APPROVED, 'Approved', 30],
            [LeaveRequest::STATUS_REJECTED, 'Rejected', 40],
            [LeaveRequest::STATUS_CANCELLED, 'Cancelled', 50],
            [LeaveRequest::STATUS_APPLIED, 'Applied', 60],
            [LeaveRequest::STATUS_WITHDRAWN, 'Withdrawn', 70],
        ];

        foreach ($statuses as [$code, $label, $position]) {
            DB::table('base_workflow_status_configs')->updateOrInsert(
                ['flow' => self::FLOW, 'code' => $code],
                [
                    'label' => $label,
                    'position' => $position,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        $transitions = [
            [LeaveRequest::STATUS_DRAFT, LeaveRequest::STATUS_SUBMITTED, 'Submit', 'leave.request.submit'],
            [LeaveRequest::STATUS_DRAFT, LeaveRequest::STATUS_CANCELLED, 'Cancel Draft', 'leave.request.cancel'],
            [LeaveRequest::STATUS_SUBMITTED, LeaveRequest::STATUS_APPROVED, 'Approve', 'leave.request.approve'],
            [LeaveRequest::STATUS_SUBMITTED, LeaveRequest::STATUS_REJECTED, 'Reject', 'leave.request.approve'],
            [LeaveRequest::STATUS_SUBMITTED, LeaveRequest::STATUS_CANCELLED, 'Cancel', 'leave.request.cancel'],
            [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_APPLIED, 'Apply', 'leave.request.apply'],
            [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_WITHDRAWN, 'Withdraw', 'leave.request.withdraw'],
            [LeaveRequest::STATUS_APPROVED, LeaveRequest::STATUS_CANCELLED, 'Cancel After Approval', 'leave.request.cancel'],
            [LeaveRequest::STATUS_APPLIED, LeaveRequest::STATUS_WITHDRAWN, 'Withdraw After Apply', 'leave.request.withdraw'],
        ];

        foreach ($transitions as $index => [$from, $to, $label, $capability]) {
            DB::table('base_workflow_status_transitions')->updateOrInsert(
                ['flow' => self::FLOW, 'from_code' => $from, 'to_code' => $to],
                [
                    'label' => $label,
                    'capability' => $capability,
                    'position' => $index * 10,
                    'is_active' => true,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        DB::table('base_workflow_status_transitions')->where('flow', self::FLOW)->delete();
        DB::table('base_workflow_status_configs')->where('flow', self::FLOW)->delete();
        DB::table('base_workflow')->where('code', self::FLOW)->delete();
    }
};
