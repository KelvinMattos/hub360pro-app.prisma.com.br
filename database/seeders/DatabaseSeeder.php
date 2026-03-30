<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Company;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 1. Criar Empresa Principal (Multitenancy)
        $company = Company::firstOrCreate(
            ['cnpj' => '00000000000100'],
            ['name' => 'Prisma Ads Hub', 'active' => true]
        );

        // 2. Criar Usuário de Teste solicitado pelo usuário
        User::updateOrCreate(
            ['email' => 'cliente@cliente.com.br'],
            [
                'name' => 'Cliente PrismaAds',
                'password' => Hash::make('123456'),
                'company_id' => $company->id,
                'email_verified_at' => now(),
            ]
        );

        // 3. Usuário padrão do Laravel (opcional)
        User::updateOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => Hash::make('password'),
                'company_id' => $company->id,
            ]
        );
    }
}
