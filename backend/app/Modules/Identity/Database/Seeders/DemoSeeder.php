<?php

namespace App\Modules\Identity\Database\Seeders;

use App\Modules\Identity\Enums\Role;
use App\Modules\Identity\Models\Company;
use App\Modules\Identity\Models\Membership;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;

/**
 * SYNTHETIC DEMO DATA for local development. The user, the companies, their NITs and contact
 * details are fictitious and do not identify any real person or business. Safe to run again.
 */
final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::updateOrCreate(
            ['email' => 'demo@example.com'],
            ['name' => 'Demo User', 'password' => 'password'],
        );

        // Synthetic NITs; the Company model computes their check digits.
        $companies = [
            [
                'name' => 'Demo Comercial SAS',
                'legal_name' => 'Demo Comercial SAS',
                'identification_number' => '990000001',
                'email' => 'billing@demo-comercial.example',
                'phone' => '+57 601 000 0001',
                'address' => 'Calle 1 # 2-03',
                'city' => 'Bogotá',
            ],
            [
                'name' => 'Demo Servicios SAS',
                'legal_name' => 'Demo Servicios SAS',
                'identification_number' => '990000002',
                'email' => 'billing@demo-servicios.example',
                'phone' => '+57 604 000 0002',
                'address' => 'Carrera 4 # 5-06',
                'city' => 'Medellín',
            ],
        ];

        foreach ($companies as $attributes) {
            $company = Company::updateOrCreate(
                ['identification_number' => $attributes['identification_number']],
                $attributes,
            );

            Membership::updateOrCreate(
                ['company_id' => $company->getKey(), 'user_id' => $user->getKey()],
                ['role' => Role::Owner],
            );
        }
    }
}
