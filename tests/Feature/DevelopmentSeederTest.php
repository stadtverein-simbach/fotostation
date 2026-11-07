<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Station;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DevelopmentSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_users_and_station_idempotently(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@example')->sole();
        $moderator = User::where('email', 'moderator@example')->sole();

        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertSame(UserRole::Moderator, $moderator->role);
        $this->assertTrue(Hash::check('testtesttest', $admin->password));
        $this->assertTrue(Hash::check('testtesttest', $moderator->password));

        $station = Station::where('slug', 'teststation-01')->sole();
        $this->assertSame('Teststation-01', $station->name);
        $this->assertEqualsWithDelta(48.268725, (float) $station->lat, 0.000001);
        $this->assertEqualsWithDelta(13.021924, (float) $station->lng, 0.000001);
    }

    public function test_seeds_nothing_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
        $this->assertSame(0, Station::count());
    }
}
