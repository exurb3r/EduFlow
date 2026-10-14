<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        $this->call([
            RolesAndPermissionsSeeder::class,
        ]);

        if (app()->environment(['local', 'testing'])) {
            // Demo users (including the password `password`) are retained for
            // local testing only. Financial demo data, wallets, transactions,
            // budgets, invoices and assistance requests now come from the
            // Circle/Lepton testnet path, not the database.
            $this->call([
                DemoUsersSeeder::class,
            ]);
        }
    }
}
