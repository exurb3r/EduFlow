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
            // The financial seeders attach budgets and a treasury to the
            // organization; the assistance request hangs off the student that
            // EducationDemoSeeder creates.
            $this->call([
                DemoUsersSeeder::class,
                EduFlowFinancialSeeder::class,
                EducationDemoSeeder::class,
                EduFlowPlanSeeder::class,
            ]);
        }
    }
}
