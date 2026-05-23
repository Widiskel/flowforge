<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $tenantId = (string) Str::uuid();

        DB::table('tenants')->insert([
            'id' => $tenantId,
            'name' => 'FlowForge Demo Tenant',
            'slug' => 'flowforge-demo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $accounts = [
            ['name' => 'Demo Admin', 'email' => 'admin@flowforge.test', 'role' => 'admin'],
            ['name' => 'Demo Editor', 'email' => 'editor@flowforge.test', 'role' => 'editor'],
            ['name' => 'Demo Viewer', 'email' => 'viewer@flowforge.test', 'role' => 'viewer'],
        ];

        foreach ($accounts as $account) {
            User::query()->updateOrCreate(
                ['email' => $account['email']],
                [
                    'tenant_id' => $tenantId,
                    'name' => $account['name'],
                    'role' => $account['role'],
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );
        }
    }
}
