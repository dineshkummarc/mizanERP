<?php

namespace App\Modules\Leave\Http\Controllers;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Employees\Models\Employee;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveBalance;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Support\TenantStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The employee's own leave, over the same API that serves /my-payslips.
 *
 * docs/hrms-plan.md §11: leave balance and apply-for-leave are the obvious next
 * endpoints after payslips, because shipping the module without them makes leave a
 * web-only feature in a phone-first market. Both are scoped to the signed-in
 * employee the way PayslipController is — the caller's employee record, nobody
 * else's — and filing goes through LeaveRequestService::submit(), so the API gets
 * the same notice check, overlap refusal and approver notification the panel gets.
 */
class LeaveController extends Controller
{
    /**
     * The signed-in employee's balance, per counted leave type.
     *
     * The terms are carried rather than collapsed into one number — "why is my
     * balance 4.5" is the question this module is asked most often, and a bare
     * total cannot answer it (LeaveBalanceBreakdown says the same). Uncounted
     * types (unlimited sick, unpaid) are left out: they have no balance, and a 0
     * would read as "none left" rather than "not rationed".
     */
    public function balances(Request $request, LeaveBalance $balance)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();

        if (! $employee) {
            return ApiResponse::error('Employee profile not found', 404);
        }

        $data = LeaveType::query()
            ->active()
            ->orderBy('sort')
            ->get()
            ->filter->isCounted()
            ->values()
            ->map(function (LeaveType $type) use ($employee, $balance): array {
                $breakdown = $balance->for($employee, $type);

                return [
                    'leave_type_id' => $type->id,
                    'code' => $type->code,
                    'label' => $type->label,
                    'year_start' => $breakdown->windowStart->toDateString(),
                    'year_end' => $breakdown->windowEnd->toDateString(),
                    // False means "no entitlement opened yet", which is not the
                    // same statement as a zero balance somebody has spent.
                    'opened' => ! $breakdown->isUngenerated(),
                    'opening' => $breakdown->opening,
                    'carried_in' => $breakdown->carriedIn,
                    'accrued' => $breakdown->accrued,
                    'adjustments' => $breakdown->adjustments,
                    'credited' => $breakdown->credited(),
                    'taken' => $breakdown->taken,
                    'pending' => $breakdown->pending,
                    'remaining' => $breakdown->remaining(),
                ];
            });

        return ApiResponse::success(['count' => $data->count(),
            'data' => $data]);
    }

    /**
     * File leave for the signed-in employee — always for themselves.
     *
     * employee_id is not accepted from the payload: filing on somebody's behalf is
     * an HR act and stays in the panel where EmployeeAccess scopes who may. The
     * service does the real checks (notice, overlap, an all-non-working range) and
     * its refusals come back as 422s with the same wording the form shows.
     */
    public function apply(Request $request, LeaveRequestService $service)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();

        if (! $employee) {
            return ApiResponse::error('Employee profile not found', 404);
        }

        // The same gate the panel's create page asks (LeaveRequestPolicy).
        abort_unless($request->user()->can('create', LeaveRequest::class), 403);

        $data = $request->validate([
            'leave_type_id' => ['required', 'integer'],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'is_half_day' => ['sometimes', 'boolean'],
            'half_day_period' => [
                'required_if:is_half_day,true',
                'nullable',
                'in:'.LeaveRequest::HALF_FIRST.','.LeaveRequest::HALF_SECOND,
            ],
            'reason' => ['nullable', 'string', 'max:2000'],
            // Multipart, the same file the form takes — a medical certificate photographed on the phone.
            'document' => ['nullable', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:8192'],
        ]);

        // Checked here rather than with an `exists` rule: exists() reads the
        // default connection, and leave_types live on the tenant's.
        $type = LeaveType::query()->active()->find($data['leave_type_id']);

        if (! $type) {
            throw ValidationException::withMessages([
                'leave_type_id' => 'That leave type does not exist or is no longer offered.',
            ]);
        }

        // A type that requires a document is refused without one rather than quietly filed; with one, it is
        // stored where the form stores it (disk `public`, tenant-rooted by the company resolved above).
        if ($type->requires_document && ! $request->hasFile('document')) {
            throw ValidationException::withMessages([
                'document' => "{$type->label} needs a supporting document — attach one as `document`.",
            ]);
        }

        // On the company's own directory, built from the company the request resolved rather than from the
        // `public` disk: the single-database setup activates a company without re-pointing that disk, and
        // the file controller reads from the company's directory either way.
        $documentPath = $request->hasFile('document')
            ? Storage::build(['driver' => 'local', 'root' => TenantStorage::publicRoot($request->attributes->get('company'))])
                ->putFile('leave-requests', $request->file('document'))
            : null;

        $leaveRequest = new LeaveRequest([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'from_date' => $data['from_date'],
            'to_date' => $data['to_date'],
            'is_half_day' => (bool) ($data['is_half_day'] ?? false),
            'half_day_period' => $data['half_day_period'] ?? null,
            'reason' => $data['reason'] ?? null,
            'document_path' => $documentPath,
        ]);

        try {
            $leaveRequest = $service->submit($leaveRequest, $request->user());
        } catch (InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), 422);
        }

        return ApiResponse::success(['data' => [
            'id' => $leaveRequest->id,
            'status' => $leaveRequest->status,
            'leave_type_id' => $leaveRequest->leave_type_id,
            'from_date' => $leaveRequest->from_date->toDateString(),
            'to_date' => $leaveRequest->to_date->toDateString(),
            'is_half_day' => $leaveRequest->is_half_day,
            'half_day_period' => $leaveRequest->half_day_period,
            // What the range will cost, planned now; regenerated at approval.
            'days' => (float) $leaveRequest->days,
        ]], 201);
    }
}
