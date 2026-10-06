<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeployReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_never_creates_accounts_in_production(): void
    {
        $this->app['env'] = 'production';

        // Directo (sin db:seed, que además pide confirmación en producción):
        // se prueba la guarda del propio seeder.
        (new AdminUserSeeder)->run();

        $this->assertSame(0, User::count());
    }

    public function test_demo_seeder_still_works_locally(): void
    {
        $this->seed(AdminUserSeeder::class);

        $this->assertGreaterThan(0, User::count());
    }

    public function test_rolling_back_legacy_users_migration_keeps_company_column(): void
    {
        $migration = require database_path('migrations/0001_01_01_000003_add_company_to_users_table.php');

        $migration->down();

        $this->assertTrue(Schema::hasColumn('users', 'company_id'));
    }

    public function test_env_example_documents_required_variables(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        foreach (['WHATSAPP_APP_SECRET', 'WHATSAPP_VERIFY_TOKEN', 'FACEBOOK_CLIENT_SECRET', 'MERCADOPAGO_ACCESS_TOKEN', 'SUPER_ADMIN_EMAIL'] as $var) {
            $this->assertStringContainsString($var.'=', $example);
        }
    }
}
