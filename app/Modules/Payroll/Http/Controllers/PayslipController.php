<?php

namespace App\Modules\Payroll\Http\Controllers;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Comment;
use App\Modules\Employees\Models\Employee;
use App\Modules\Payroll\Models\Payslip;
use App\Modules\Payroll\Services\PayslipService;
use Illuminate\Http\Request;

class PayslipController extends Controller
{
    /**
     * The signed-in employee's payslips.
     *
     * `pdf_url` names the download route rather than a file on the public disk.
     * Two things were wrong with the file: it was written once and reused for
     * ever, so a payslip corrected afterwards kept handing out the old figures;
     * and its name was built from a `pay_period` attribute that does not exist,
     * which left the month out and collapsed every month of a fiscal year onto one
     * file per employee — August's download was July's PDF.
     *
     * Listing no longer renders anything either. It used to render a PDF for every
     * payslip missing one, so the first call of a new fiscal year rendered a year
     * of them before it answered.
     */
    public function index(Request $request)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();

        if (! $employee) {
            return ApiResponse::error('Employee profile not found', 404);
        }

        $payslips = Payslip::where('employee_id', $employee->id)
            ->with('fiscalYear')
            ->orderByDesc('id')
            ->get()
            ->map(function (Payslip $payslip) {
                $payslip->pdf_url = route('payslips.pdf', ['payslip' => $payslip->id]);

                $payslip->setRelations([]);
                $payslip->makeHidden('pdf_path');

                return $payslip;
            });

        return ApiResponse::success(['count' => $payslips->count(),
            'data' => $payslips]);
    }

    /**
     * One payslip as a PDF, rendered now from the payslip as it stands.
     *
     * Scoped to the caller's own payslips. The id is in the URL, so without this
     * an employee could read a colleague's salary by changing a number.
     */
    public function pdf(Request $request, Payslip $payslip)
    {
        $employee = Employee::where('user_id', $request->user()->id)->first();

        abort_if(! $employee || $payslip->employee_id !== $employee->id, 403);

        return app(PayslipService::class)->renderPdf($payslip);
    }

    /**
     * The conversation on one payslip — accounting-implementation-plan.md Phase 7.
     *
     * Gated exactly as the Filament comments tab is: you reach that tab by being
     * able to view the payslip (PayslipPolicy — your own record or your downline;
     * privileged staff see all) and the list itself asks CommentPolicy::viewAny.
     * So an employee reads only their own payslip's thread, by the same two
     * policies rather than a re-statement of them.
     *
     * Oldest first, like the tab: a conversation read newest-first is a
     * conversation read backwards, and the first row is the objection itself.
     */
    public function comments(Request $request, Payslip $payslip)
    {
        $user = $request->user();

        abort_unless($user->can('view', $payslip) && $user->can('viewAny', Comment::class), 403);

        $comments = $payslip->comments()
            ->with('user:id,name')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Comment $comment): array => [
                'id' => $comment->id,
                'body' => $comment->body,
                'user_id' => $comment->user_id,
                'author' => $comment->user?->name,
                'parent_id' => $comment->parent_id,
                'resolved_at' => $comment->resolved_at,
                'created_at' => $comment->created_at,
            ]);

        return ApiResponse::success(['count' => $comments->count(),
            'data' => $comments]);
    }

    /**
     * Add to the conversation — the reply half of the thread, same gates as the
     * tab's Reply button: view the payslip, hold CommentCreate.
     *
     * The author is whoever is signed in. Not settable from the payload, for the
     * obvious reason.
     */
    public function addComment(Request $request, Payslip $payslip)
    {
        $user = $request->user();

        abort_unless($user->can('view', $payslip) && $user->can('create', Comment::class), 403);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:1000'],
        ]);

        $comment = $payslip->comments()->create([
            'body' => $data['body'],
            'user_id' => $user->id,
        ]);

        return ApiResponse::success(['data' => [
            'id' => $comment->id,
            'body' => $comment->body,
            'user_id' => $comment->user_id,
            'created_at' => $comment->created_at,
        ]], 201);
    }
}
