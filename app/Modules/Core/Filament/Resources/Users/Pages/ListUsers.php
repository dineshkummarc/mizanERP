<?php

namespace App\Modules\Core\Filament\Resources\Users\Pages;

use App\Filament\Support\HelpAction;
use App\Modules\Core\Filament\Resources\Users\Actions\InviteUserAction;
use App\Modules\Core\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            HelpAction::make('users', 'Users: Help'),
            // Create is for staff; Invite is for the accountant — docs/bexio-gap-plan.md Phase 2, item 10.
            InviteUserAction::make(),
            CreateAction::make(),
        ];
    }
}
