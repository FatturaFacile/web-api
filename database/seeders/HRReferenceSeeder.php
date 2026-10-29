<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

final class HRReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            DepartmentSeeder::class,
            JobGradeSeeder::class,
            PositionSeeder::class,
            SkillSeeder::class,
            CertificationSeeder::class,
            LeaveTypeSeeder::class,
            HolidayCalendarSeeder::class,
        ]);
    }
}
