<?php

namespace App\Modules\Leave\Models;

use App\Models\TenantModel as Model;
use App\Modules\Core\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step of a multi-step leave approval — see LeaveApprovalChain. */
class LeaveApproval extends Model
{
    protected $fillable = ['leave_request_id', 'step', 'role', 'approved_by', 'approved_at'];

    protected $casts = [
        'step' => 'integer',
        'approved_at' => 'datetime',
    ];

    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
