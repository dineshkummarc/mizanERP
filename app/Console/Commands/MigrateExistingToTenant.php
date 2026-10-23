<?php

namespace App\Console\Commands;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Multitenancy\CompanyProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-time (idempotent) migration of the pre-tenancy single-database dataset
 * into a dedicated tenant database for a "default" company, and backfills every
 * existing user as a member of that company.
 *
 * Domain tables are read from the landlord (default) connection — where the
 * original data still lives after the migration split — and copied into the
 * newly provisioned tenant database.
 */
class MigrateExistingToTenant extends Command
{
    protected $signature = 'tenancy:migrate-existing
        {--name=Default : Display name for the company}
        {--slug=default : Slug / tenant route key}
        {--fresh : Truncate tenant tables before copying}';

    protected $description = 'Move the existing single-DB dataset into a tenant database for a default company';

    public function handle(CompanyProvisioner $provisioner): int
    {
        $tenantConnection = config('multitenancy.tenant_database_connection_name');

        if (! $tenantConnection || $tenantConnection === config('database.default')) {
            $this->error('A dedicated tenant database connection must be configured (TENANT_DATABASE_CONNECTION).');

            return self::FAILURE;
        }

        $slug = (string) $this->option('slug');

        $company = Company::where('slug', $slug)->first();

        if (! $company) {
            $this->info("Provisioning company '{$slug}' (schema only, no baseline seed)…");
            $company = $provisioner->provision((string) $this->option('name'), $slug, seedBaseline: false);
        } else {
            $this->info("Company '{$slug}' already exists — reusing it.");
        }

        $this->copyDomainData($company, $tenantConnection);
        $this->backfillMemberships($company);
        $this->backfillRoles($company);

        $this->info("Done. Company '{$company->slug}' (#{$company->id}) is ready.");

        return self::SUCCESS;
    }

    protected function copyDomainData(Company $company, string $tenantConnection): void
    {
        $source = config('database.default');
        $fresh = (bool) $this->option('fresh');

        $company->makeCurrent();

        try {
            $tables = collect(Schema::connection($tenantConnection)->getTableListing())
                ->map(fn ($t) => $this->stripPrefix($t, $tenantConnection))
                ->reject(fn ($t) => in_array($t, ['migrations'], true))
                ->values();

            Schema::connection($tenantConnection)->withoutForeignKeyConstraints(function () use ($tables, $source, $tenantConnection, $fresh) {
                foreach ($tables as $table) {
                    if (! Schema::connection($source)->hasTable($table)) {
                        $this->warn("  · {$table}: not present in source, skipped");

                        continue;
                    }

                    $target = DB::connection($tenantConnection)->table($table);

                    if ($target->exists()) {
                        if (! $fresh) {
                            $this->line("  · {$table}: already has data, skipped (use --fresh to overwrite)");

                            continue;
                        }
                        $target->truncate();
                    }

                    $count = 0;
                    foreach (array_chunk(DB::connection($source)->table($table)->get()->all(), 500) as $chunk) {
                        $rows = array_map(fn ($row) => (array) $row, $chunk);
                        $target->insert($rows);
                        $count += count($rows);
                    }

                    $this->line("  · {$table}: copied {$count} row(s)");
                }
            });
        } finally {
            Company::forgetCurrent();
        }
    }

    protected function backfillMemberships(Company $company): void
    {
        $userIds = User::query()->pluck('id');
        $existing = $company->users()->pluck('users.id')->all();
        $toAttach = $userIds->reject(fn ($id) => in_array($id, $existing, true))->all();

        if ($toAttach) {
            $company->users()->attach($toAttach);
        }

        $this->info('Attached '.count($toAttach)." new member(s); {$userIds->count()} user(s) total.");
    }

    /**
     * Seed the default company's roles and re-assign each user's pre-existing
     * (pre-teams) roles into this company's team.
     */
    protected function backfillRoles(Company $company): void
    {
        $registrar = app(\Spatie\Permission\PermissionRegistrar::class);
        $morphKey = config('permission.column_names.model_morph_key', 'model_id');

        // Snapshot pre-migration role assignments by user (across any team).
        $existing = DB::table(config('permission.table_names.model_has_roles'))
            ->join(config('permission.table_names.roles').' as r', 'r.id', '=', 'role_id')
            ->where('model_type', User::class)
            ->get([$morphKey.' as user_id', 'r.name'])
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('name')->unique()->all());

        // Create this company's own set of roles.
        $registrar->setPermissionsTeamId($company->getKey());

        try {
            (new \Database\Seeders\RoleSeeder)->run();

            $companyRoleNames = \Spatie\Permission\Models\Role::where('company_id', $company->getKey())
                ->pluck('name')->all();

            $remapped = 0;
            foreach ($existing as $userId => $roleNames) {
                if (! $user = User::find($userId)) {
                    continue;
                }
                foreach (array_intersect($roleNames, $companyRoleNames) as $name) {
                    $user->assignRole($name);
                    $remapped++;
                }
            }

            $this->info("Seeded company roles; remapped {$remapped} role assignment(s).");
        } finally {
            $registrar->setPermissionsTeamId(null);
        }
    }

    /**
     * getTableListing() may return schema-qualified names on some drivers.
     */
    protected function stripPrefix(string $table, string $connection): string
    {
        if (str_contains($table, '.')) {
            $table = substr($table, strrpos($table, '.') + 1);
        }

        $prefix = DB::connection($connection)->getTablePrefix();

        return $prefix && str_starts_with($table, $prefix)
            ? substr($table, strlen($prefix))
            : $table;
    }
}
