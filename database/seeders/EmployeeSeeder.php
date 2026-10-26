<?php

namespace Database\Seeders;

use App\Modules\Core\Models\Company;
use App\Modules\Core\Models\User;
use App\Modules\Employees\Models\Employee;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class EmployeeSeeder extends Seeder
{
    public function run()
    {
        // Mints logins with a known password; never in production — security audit finding 14.
        if (app()->environment('production')) {
            return;
        }

        // Dummy staff. The real roster lives in
        // Database\Seeders\Production\RealEmployeeSeeder and is not part of
        // the default `db:seed` run.
        //
        // 'role'    — the company role assigned to the user.
        // 'manager' — email of the employee this person reports to (manager_id).
        //
        // Addresses use the reserved `example.test` domain, so a stray
        // notification can never reach a real inbox.
        $employees = [
            // Managers — the first eight have reports pointed at them below.
            ['name' => 'Ayesha Karim', 'email' => 'ayesha.karim@example.test', 'role' => 'Employee', 'status' => 1],
            ['name' => 'Bilal Nawaz', 'email' => 'bilal.nawaz@example.test', 'role' => 'Employee', 'status' => 1],
            ['name' => 'Chandni Rao', 'email' => 'chandni.rao@example.test', 'role' => 'Employee', 'status' => 1],
            ['name' => 'Danish Iqbal', 'email' => 'danish.iqbal@example.test', 'role' => 'Employee', 'status' => 1],
            ['name' => 'Erum Shafiq', 'email' => 'erum.shafiq@example.test', 'role' => 'Employee', 'status' => 1],
            ['name' => 'Faraz Siddiqui', 'email' => 'faraz.siddiqui@example.test', 'role' => 'Employee', 'status' => 1],
            ['name' => 'Ghazala Munir', 'email' => 'ghazala.munir@example.test', 'role' => 'Employee', 'status' => 1],
            ['name' => 'Hassan Raza', 'email' => 'hassan.raza@example.test', 'role' => 'Employee', 'status' => 1],

            // Individual contributors without reports.
            ['name' => 'Imran Baig', 'email' => 'imran.baig@example.test', 'role' => 'Employee', 'status' => 1],
            ['name' => 'Javeria Aslam', 'email' => 'javeria.aslam@example.test', 'role' => 'Employee', 'status' => 1],

            // Reporting lines, so the hierarchy scoping has something to walk.
            ['name' => 'Kamran Sethi', 'email' => 'kamran.sethi@example.test', 'role' => 'Employee', 'status' => 1, 'manager' => 'ghazala.munir@example.test'],
            ['name' => 'Laiba Qureshi', 'email' => 'laiba.qureshi@example.test', 'role' => 'Employee', 'status' => 1, 'manager' => 'faraz.siddiqui@example.test'],
            ['name' => 'Moiz Habib', 'email' => 'moiz.habib@example.test', 'role' => 'Employee', 'status' => 1, 'manager' => 'danish.iqbal@example.test'],
            ['name' => 'Nimra Saleem', 'email' => 'nimra.saleem@example.test', 'role' => 'Employee', 'status' => 1, 'manager' => 'bilal.nawaz@example.test'],
            ['name' => 'Owais Tariq', 'email' => 'owais.tariq@example.test', 'role' => 'Employee', 'status' => 1, 'manager' => 'hassan.raza@example.test'],
            ['name' => 'Parisa Yousuf', 'email' => 'parisa.yousuf@example.test', 'role' => 'Employee', 'status' => 1, 'manager' => 'chandni.rao@example.test'],
        ];

        /** @var array<string, Employee> $created keyed by email, to resolve managers */
        $created = [];

        // Employees are seeded into whichever company is current; their login
        // users live in the landlord database and need explicit membership of
        // that company to be able to reach it (see User::canAccessTenant).
        $company = Company::current();

        foreach ($employees as $emp) {
            $user = User::firstOrCreate(
                ['email' => $emp['email']],
                [
                    'name' => $emp['name'],
                    'password' => Hash::make('password123'),
                    'status' => 1,
                ]
            );

            // users.status defaults to 0 (inactive), so activate on re-seed too.
            if ((int) $user->status !== 1) {
                $user->forceFill(['status' => 1])->save();
            }

            if (method_exists($user, 'syncRoles')) {
                $user->syncRoles([$emp['role']]);
            }

            $company?->users()->syncWithoutDetaching([$user->id]);

            $created[$emp['email']] = Employee::firstOrCreate(
                ['user_id' => $user->id],
                [
                    'employee_id' => Employee::nextEmployeeId(),
                    'gender' => 'Male',
                    'is_active' => 1,
                ]
            );
        }

        // Second pass: managers must exist before they can be pointed at.
        foreach ($employees as $emp) {
            $manager = $created[$emp['manager'] ?? ''] ?? null;

            if ($manager && $created[$emp['email']]->manager_id !== $manager->id) {
                $created[$emp['email']]->forceFill(['manager_id' => $manager->id])->save();
            }
        }
    }
}
