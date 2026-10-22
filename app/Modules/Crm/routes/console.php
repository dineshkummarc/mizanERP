<?php

use App\Modules\Crm\Console\Commands\RemindNextActions;
use Illuminate\Support\Facades\Schedule;

/**
 * The morning digest of next actions — one message per person, once per due date. TenantAware, and
 * it skips companies with the module switched off.
 */
Schedule::command(RemindNextActions::class)->dailyAt('07:30');
