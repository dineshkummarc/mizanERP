<?php

namespace App\Modules\Core\Filament\Resources\Roles\Schemas;

use App\Modules\Core\Filament\Resources\Roles\RoleResource;
use App\Support\ModuleMap;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;
use Spatie\Permission\Models\Permission;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Role')
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            // Scoped to the company, because roles are. Every
                            // company has an Employee and an Administrator, so a
                            // rule that checks the whole table rejects the name a
                            // role already has the moment a second company exists
                            // — "The name has already been taken." about the only
                            // role of that name in the company you are looking at.
                            //
                            // ignoreRecord alone is not enough: it excludes the row
                            // being edited and then still matches every other
                            // company's copy. The resource's getEloquentQuery()
                            // does scope by company, but a unique rule builds its
                            // own query and never goes through it.
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule->where(
                                    config('permission.column_names.team_foreign_key', 'company_id'),
                                    RoleResource::currentCompanyId(),
                                ),
                            ),

                        Select::make('guard_name')
                            ->options(['web' => 'web'])
                            ->default('web')
                            ->required(),
                    ])
                    ->columns(2),

                Section::make('Permissions')
                    ->description('Assign permissions, grouped by module.')
                    ->schema(self::permissionCheckboxes())
                    ->columns(1)
                    ->collapsible(),
            ]);
    }

    /**
     * A CheckboxList per permission group. State is handled manually by the
     * Create/Edit pages (see SyncsGroupedPermissions), so these are not
     * dehydrated into the role's own attributes.
     *
     * @return array<CheckboxList>
     */
    public static function permissionCheckboxes(): array
    {
        return self::groupedPermissions()
            ->map(fn (Collection $perms, string $group) => CheckboxList::make(self::groupKey($group))
                ->label($group)
                ->options($perms->pluck('name', 'id')->toArray())
                ->columns(3)
                ->bulkToggleable()
                ->dehydrated(false))
            ->values()
            ->all();
    }

    /**
     * @return Collection<string, Collection<int, Permission>>
     */
    public static function groupedPermissions(): Collection
    {
        return Permission::query()
            ->orderBy('group')
            ->orderBy('name')
            ->get()
            ->groupBy('group')
            // A switched-off module's permissions are not offered: assigning
            // rights to a feature the company cannot reach is noise at best.
            //
            // Filtering here also removes them from the sync in
            // SyncsGroupedPermissions, which is why that trait has to preserve
            // what it no longer renders — see preservedPermissionIds().
            ->filter(fn (Collection $permissions, string $group) => static::groupIsVisible($group));
    }

    /**
     * A group no module claims stays visible: it is unmapped rather than
     * disabled, and hiding it would silently drop permissions nobody can find.
     * ModuleCoverageTest fails the build if such a group exists.
     */
    protected static function groupIsVisible(string $group): bool
    {
        $module = ModuleMap::moduleForPermissionGroup($group);

        return $module === null || modules()->enabled($module);
    }

    public static function groupKey(string $group): string
    {
        return 'perm_'.Str::slug($group, '_');
    }
}
