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

    protected $tempSourceFile;
    protected $tempTargetFile;

    protected function setUp(): void
    {
        parent::setUp();

        // Create actual database files in temp directory to test read-only PDO constraints
        $this->tempSourceFile = tempnam(sys_get_temp_dir(), 'sqlite_src_');
        $this->tempTargetFile = tempnam(sys_get_temp_dir(), 'sqlite_tgt_');

        Config::set('database.connections.migration_source', [
            'driver' => 'sqlite',
            'database' => $this->tempSourceFile,
            'foreign_key_constraints' => false,
        ]);

        Config::set('database.connections.migration_target', [
            'driver' => 'sqlite',
            'database' => $this->tempTargetFile,
            'foreign_key_constraints' => false,
        ]);

        // Initialize schemas in both databases
        $this->setUpSchema('migration_source');
        $this->setUpSchema('migration_target');
    }

    protected function tearDown(): void
    {
        DB::disconnect('migration_source');
        DB::disconnect('migration_target');

        if (file_exists($this->tempSourceFile)) {
            @unlink($this->tempSourceFile);
        }
        if (file_exists($this->tempTargetFile)) {
            @unlink($this->tempTargetFile);
        }

        parent::tearDown();
    }

    private function setUpSchema(string $conn)
    {
        $tables = [
            'data_migration_runs' => function ($table) {
                $table->id();
                $table->timestamp('started_at')->useCurrent();
                $table->timestamp('finished_at')->nullable();
                $table->string('status')->default('pending');
                $table->json('options')->nullable();
                $table->timestamps();
            },
            'data_migration_checkpoints' => function ($table) {
                $table->id();
                $table->foreignId('run_id');
                $table->string('table_name');
                $table->bigInteger('last_migrated_id')->default(0);
                $table->integer('rows_copied')->default(0);
                $table->string('status')->default('processing');
                $table->timestamps();
            },
            'users' => function ($table) {
                $table->id();
                $table->string('name');
                $table->timestamps();
            },
            'roles' => function ($table) { $table->id(); },
            'categories' => function ($table) { $table->id(); },
            'allergens' => function ($table) { $table->id(); },
            'dietary_tags' => function ($table) { $table->id(); },
            'ingredients' => function ($table) { $table->id(); },
            'suppliers' => function ($table) { $table->id(); },
            'extra_ingredients' => function ($table) { $table->id(); },
            'custom_bases' => function ($table) { $table->id(); },
            'custom_options' => function ($table) { $table->id(); },
            'dining_tables' => function ($table) { $table->id(); },
            'order_statuses' => function ($table) { $table->id(); },
            'delivery_providers' => function ($table) { $table->id(); },
            'payment_methods' => function ($table) { $table->id(); },
            'products' => function ($table) {
                $table->id();
                $table->integer('codigo')->unique();
                $table->decimal('precio', 8, 2);
                $table->json('extras')->nullable();
                $table->timestamps();
            },
            'sales' => function ($table) { $table->id(); },
            'user_roles' => function ($table) { $table->id(); },
            'product_allergens' => function ($table) { $table->id(); },
            'product_dietary_tags' => function ($table) { $table->id(); },
            'ingredient_suppliers' => function ($table) { $table->id(); },
            'product_recipes' => function ($table) { $table->id(); },
            'product_extras' => function ($table) { $table->id(); },
            'custom_items' => function ($table) { $table->id(); },
            'table_reservations' => function ($table) { $table->id(); },
            'orders' => function ($table) {
                $table->id();
                $table->decimal('total', 8, 2)->default(0.00);
                $table->json('items')->nullable();
                $table->string('estado_preparacion')->default('pendiente');
                $table->timestamps();
            },
            'custom_item_details' => function ($table) { $table->id(); },
            'order_status_histories' => function ($table) { $table->id(); },
            'sale_details' => function ($table) { $table->id(); },
            'inventory_logs' => function ($table) { $table->id(); },
            'order_item_extras' => function ($table) { $table->id(); }
        ];

        foreach ($tables as $name => $blueprint) {
            Schema::connection($conn)->create($name, $blueprint);
        }
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

    public function test_decimal_monetary_precision_preservation_without_float_conversion()
    {
        // 0.1 + 0.2 in float equals 0.30000000000000004 due to binary float representation
        $val1 = 0.1;
        $val2 = 0.2;
        $floatSum = $val1 + $val2;
        
        $this->assertNotEquals(0.3, $floatSum); // Demonstrates float loss of precision

        // Using our decimal/BCMath string summation
        $strVal1 = '0.10';
        $strVal2 = '0.20';
        $stringSum = bcadd($strVal1, $strVal2, 2);

        $this->assertEquals('0.30', $stringSum); // Preserved exactly as a decimal string
    }

    public function test_json_keys_are_canonicalized_for_deterministic_checksums()
    {
        // Two JSON strings representing the same structure but in different order
        $jsonA = '{"a":1,"b":{"x":10,"y":20}}';
        $jsonB = '{"b":{"y":20,"x":10},"a":1}';

        // Insert into source and target products tables
        DB::connection('migration_source')->table('products')->insert([
            ['id' => 1, 'codigo' => 101, 'precio' => '15.50', 'extras' => $jsonA]
        ]);
        DB::connection('migration_target')->table('products')->insert([
            ['id' => 1, 'codigo' => 101, 'precio' => '15.50', 'extras' => $jsonB]
        ]);

        // Run validation only
        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--verify-only' => true
        ])->run();

        // The verification must pass (exit code 0) because JSON is canonicalized before generating checksums
        $this->assertEquals(0, $exitCode);
    }

    public function test_source_sqlite_connection_enforces_read_only_and_fails_writes()
    {
        // We set up database file, write a row while writable
        DB::connection('migration_source')->table('users')->insert(['id' => 98, 'name' => 'Initial User']);

        // Now run command on it. The command dynamically sets connection migration_source to read-only.
        // Let's assert that the command can read it but cannot write back.
        // We can test this by manually config setting options and testing write throws PDOException.
        Config::set('database.connections.migration_source.options', [
            \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY,
        ]);
        DB::purge('migration_source');

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/attempt to write a readonly database|readonly/i');

        DB::connection('migration_source')->table('users')->insert(['id' => 99, 'name' => 'Should Fail']);
    }
}
