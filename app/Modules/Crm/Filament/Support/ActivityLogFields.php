<?php

namespace App\Modules\Crm\Filament\Support;

use App\Modules\Crm\Models\Activity;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * The four fields that log a call, a meeting or a note — shared by the deal's row action and the
 * customer's timeline tab (`docs/bexio-gap-plan.md` Phase 2, item 4), so the two cannot drift.
 *
 * `occurred_at` is not asked for: `Activity::booted()` stamps now, and a call is logged as it ends.
 */
class ActivityLogFields
{
    /** @return array<int, Select|TextInput|Textarea> */
    public static function make(): array
    {
        return [
            Select::make('kind')
                ->options(array_combine(Activity::KINDS, array_map('ucfirst', Activity::KINDS)))
                ->default(Activity::KIND_CALL)
                ->required(),
            TextInput::make('outcome')->maxLength(255)->placeholder('Send pricing')
                ->helperText('What came of it. This is what makes an activity more than a comment.'),
            TextInput::make('duration_minutes')->label('Minutes')->numeric(),
            Textarea::make('body')->rows(2),
        ];
    }
}
