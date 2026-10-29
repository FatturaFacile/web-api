<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $tenants = Tenant::all();
        $defaultPassword = Hash::make('Password123!');

        $usersData = [
            [
                'email' => 'admin@demo-group.com',
                'name' => 'Enterprise Administrator',
                'role' => 'admin',
                'phone' => '+39 02 1234567',
                'locale' => 'it',
                'permissions' => ['*'],
            ],
            [
                'email' => 'cfo@demo-group.com',
                'name' => 'Elena Rostagno (CFO)',
                'role' => 'finance_director',
                'phone' => '+39 02 1234568',
                'locale' => 'it',
                'permissions' => ['gl.*', 'treasury.*', 'billing.*', 'invoicing.*', 'audit.view'],
            ],
            [
                'email' => 'sales@demo-group.com',
                'name' => 'Marco Valli (Sales Director)',
                'role' => 'sales_manager',
                'phone' => '+39 02 1234569',
                'locale' => 'it',
                'permissions' => ['crm.*', 'sales.*', 'quotes.*', 'customers.*'],
            ],
            [
                'email' => 'mfg@demo-group.com',
                'name' => 'Ing. Roberto Ferri (Plant Director)',
                'role' => 'manufacturing_director',
                'phone' => '+39 02 1234570',
                'locale' => 'it',
                'permissions' => ['mfg.*', 'inventory.*', 'procurement.*', 'quality.*'],
            ],
            [
                'email' => 'service@demo-group.com',
                'name' => 'Matteo Riva (Service Lead)',
                'role' => 'service_manager',
                'phone' => '+39 02 1234571',
                'locale' => 'it',
                'permissions' => ['service.*', 'assets.*', 'dispatch.*'],
            ],
        ];

        foreach ($usersData as $data) {
            /** @var User $user */
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => $defaultPassword,
                    'phone' => $data['phone'],
                    'locale' => $data['locale'],
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]
            );

            // Attach user to each tenant in tenant_user pivot
            foreach ($tenants as $tenant) {
                if (! $user->belongsToTenant($tenant->id)) {
                    $user->tenants()->attach($tenant->id, [
                        'id' => (string) Str::uuid(),
                        'role' => $data['role'],
                        'permissions' => json_encode($data['permissions']),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    $user->tenants()->updateExistingPivot($tenant->id, [
                        'role' => $data['role'],
                        'permissions' => json_encode($data['permissions']),
                        'updated_at' => now(),
                    ]);
                }
            }
        }
    }
}
