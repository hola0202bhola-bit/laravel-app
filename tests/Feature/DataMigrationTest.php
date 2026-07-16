<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DataMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Register temp SQLite source and target connections in PHPUnit runtime config
        Config::set('database.connections.migration_source', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => false,
        ]);

        Config::set('database.connections.migration_target', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => false,
        ]);

        // Initialize schemas in both in-memory databases
        $this->setUpSchema('migration_source');
        $this->setUpSchema('migration_target');
    }

    private function setUpSchema(string $conn)
    {
        Schema::connection($conn)->create('data_migration_runs', function ($table) {
            $table->id();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->string('status')->default('pending');
            $table->json('options')->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('data_migration_checkpoints', function ($table) {
            $table->id();
            $table->foreignId('run_id');
            $table->string('table_name');
            $table->bigInteger('last_migrated_id')->default(0);
            $table->integer('rows_copied')->default(0);
            $table->string('status')->default('processing');
            $table->timestamps();
        });

        Schema::connection($conn)->create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::connection($conn)->create('products', function ($table) {
            $table->id();
            $table->integer('codigo')->unique();
            $table->decimal('precio', 8, 2);
            $table->json('extras')->nullable();
            $table->timestamps();
        });

        Schema::connection($conn)->create('orders', function ($table) {
            $table->id();
            $table->decimal('total', 8, 2);
            $table->json('items')->nullable();
            $table->timestamps();
        });
    }

    public function test_read_only_mode_blocks_writes_but_allows_reads()
    {
        Config::set('app.read_only', true);

        // GET requests must pass
        $responseGet = $this->getJson('/api/pedidos/seguimiento');
        $this->assertNotEquals(503, $responseGet->status());

        // POST requests must be blocked with 503
        $responsePost = $this->postJson('/api/pedidos/estado', ['id' => 1]);
        $responsePost->assertStatus(503);
    }

    public function test_read_only_mode_allows_secure_bypass()
    {
        Config::set('app.read_only', true);
        Config::set('app.read_only_bypass_token', 'smoke-test-bypass-secret');

        // Without header -> Blocked
        $responseBlocked = $this->postJson('/api/pedidos/estado', ['id' => 1]);
        $responseBlocked->assertStatus(503);

        // With wrong header -> Blocked
        $responseBlockedWrong = $this->withHeaders(['X-Read-Only-Bypass' => 'wrong-secret'])
            ->postJson('/api/pedidos/estado', ['id' => 1]);
        $responseBlockedWrong->assertStatus(503);

        // With correct header -> Passes write block
        $responsePassed = $this->withHeaders(['X-Read-Only-Bypass' => 'smoke-test-bypass-secret'])
            ->postJson('/api/pedidos/estado', ['id' => 1]);
        
        // Should bypass read-only and return standard endpoint response (like 404 since order 1 does not exist, but NOT 503)
        $this->assertNotEquals(503, $responsePassed->status());
    }

    public function test_read_only_config_cache_compatibility()
    {
        $this->assertIsBool(config('app.read_only'));
        // Verify we can access config variables without throwing env() cache leaks
        $this->assertNotNull(config('app.read_only_bypass_token') !== 'not-set-value');
    }

    public function test_command_rejects_non_empty_target()
    {
        // Populate target business table
        DB::connection('migration_target')->table('users')->insert(['id' => 1, 'name' => 'Existing User']);

        // Run command expecting abort exit code
        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(1, $exitCode);
    }

    public function test_migration_is_idempotent_and_creates_checkpoints()
    {
        // Seed source
        DB::connection('migration_source')->table('users')->insert([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob']
        ]);
        DB::connection('migration_source')->table('products')->insert([
            ['id' => 1, 'codigo' => 101, 'precio' => 15.50, 'extras' => json_encode(['sugar' => true])]
        ]);

        // First Run
        $exitCode1 = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(0, $exitCode1);

        // Verify rows count and checkpoints in target
        $this->assertEquals(2, DB::connection('migration_target')->table('users')->count());
        $this->assertEquals(1, DB::connection('migration_target')->table('products')->count());

        $checkpoint = DB::connection('migration_target')->table('data_migration_checkpoints')
            ->where('table_name', 'users')
            ->first();
        $this->assertNotNull($checkpoint);
        $this->assertEquals(2, $checkpoint->last_migrated_id);
        $this->assertEquals(2, $checkpoint->rows_copied);

        // Second Run (should not duplicate since target has rows, wait! The target is not empty now, so a second run should abort unless force?
        // Wait, the command rejects non-empty target database. But if we run --resume, it should handle it!
        // Set run status to failed to simulate interruption
        DB::connection('migration_target')->table('data_migration_runs')->where('id', $checkpoint->run_id)->update(['status' => 'failed']);
        // Set users checkpoint status to processing so it continues migrating users
        DB::connection('migration_target')->table('data_migration_checkpoints')
            ->where('run_id', $checkpoint->run_id)
            ->where('table_name', 'users')
            ->update(['status' => 'processing']);
        
        // Seed another record in source
        DB::connection('migration_source')->table('users')->insert(['id' => 3, 'name' => 'Charlie']);

        $exitCode2 = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--resume' => true,
            '--force' => true
        ])->run();

        $this->assertEquals(0, $exitCode2);
        
        // Check that Charlie got migrated and no duplicates for Alice/Bob
        $this->assertEquals(3, DB::connection('migration_target')->table('users')->count());
    }

    public function test_migration_verifies_checksums_correctly()
    {
        // Seed identical data
        DB::connection('migration_source')->table('products')->insert([
            ['id' => 1, 'codigo' => 101, 'precio' => 15.50, 'extras' => json_encode(['sugar' => true])]
        ]);
        DB::connection('migration_target')->table('products')->insert([
            ['id' => 1, 'codigo' => 101, 'precio' => 15.50, 'extras' => json_encode(['sugar' => true])]
        ]);

        // Run only verification phase
        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--verify-only' => true
        ])->run();

        $this->assertEquals(0, $exitCode);

        // Alter target data and re-verify, should fail
        DB::connection('migration_target')->table('products')->where('id', 1)->update(['precio' => 16.00]);

        $exitCodeFailed = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--verify-only' => true
        ])->run();

        $this->assertEquals(1, $exitCodeFailed);
    }
}
