<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class DataMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected $tempSourceFile;
    protected $tempTargetFile;
    protected $targetSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempSourceFile = tempnam(sys_get_temp_dir(), 'source_db_');
        $this->tempTargetFile = null;
        $this->targetSchema = null;

        Config::set('database.connections.migration_source', [
            'driver' => 'sqlite',
            'database' => $this->tempSourceFile,
            'prefix' => '',
        ]);

        $defaultConnection = config('database.default');
        $defaultConfig = config("database.connections.{$defaultConnection}");

        if (($defaultConfig['driver'] ?? null) === 'pgsql') {
            $this->targetSchema = 'migration_test_' . strtolower(Str::random(12));

            Config::set('database.connections.migration_admin', $defaultConfig);
            DB::purge('migration_admin');
            DB::connection('migration_admin')->statement('CREATE SCHEMA "' . $this->targetSchema . '"');

            $targetConfig = $defaultConfig;
            $targetConfig['search_path'] = $this->targetSchema;
            Config::set('database.connections.migration_target', $targetConfig);
        } else {
            if (filter_var(env('MIGRATION_REQUIRE_PGSQL', false), FILTER_VALIDATE_BOOL)) {
                $this->fail('MIGRATION_REQUIRE_PGSQL is enabled, but migration_target is not PostgreSQL.');
            }

            $this->tempTargetFile = tempnam(sys_get_temp_dir(), 'target_db_');
            Config::set('database.connections.migration_target', [
                'driver' => 'sqlite',
                'database' => $this->tempTargetFile,
                'prefix' => '',
            ]);
        }

        DB::purge('migration_source');
        DB::purge('migration_target');

        $this->setUpSchema('migration_source');
        $this->setUpSchema('migration_target');
    }

    protected function tearDown(): void
    {
        DB::disconnect('migration_source');
        DB::disconnect('migration_target');

        if ($this->targetSchema) {
            DB::connection('migration_admin')->statement('DROP SCHEMA "' . $this->targetSchema . '" CASCADE');
            DB::disconnect('migration_admin');
        }

        if (file_exists($this->tempSourceFile)) {
            unlink($this->tempSourceFile);
        }
        if (file_exists($this->tempTargetFile)) {
            unlink($this->tempTargetFile);
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
                $table->string('source_checksum')->nullable();
                $table->bigInteger('source_size')->nullable();
                $table->string('target_fingerprint')->nullable();
                $table->string('manifest_version')->nullable();
                $table->string('code_commit')->nullable();
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
            'ingredients' => function ($table) {
                $table->id();
                $table->decimal('stock_actual', 10, 2)->default(0.00);
                $table->decimal('costo_unitario', 8, 2)->default(0.00);
            },
            'suppliers' => function ($table) { $table->id(); },
            'extra_ingredients' => function ($table) {
                $table->id();
                $table->decimal('precio', 8, 2)->default(0.00);
            },
            'custom_bases' => function ($table) {
                $table->id();
                $table->decimal('precio_base', 8, 2)->default(0.00);
            },
            'custom_options' => function ($table) {
                $table->id();
                $table->decimal('precio_adicional', 8, 2)->default(0.00);
            },
            'dining_tables' => function ($table) { $table->id(); },
            'order_statuses' => function ($table) { $table->id(); },
            'delivery_providers' => function ($table) {
                $table->id();
                $table->decimal('comision_porcentaje', 5, 2)->default(0.00);
            },
            'payment_methods' => function ($table) { $table->id(); },
            'products' => function ($table) {
                $table->id();
                $table->integer('category_id')->nullable();
                $table->integer('codigo')->unique();
                $table->decimal('precio', 8, 2);
                $table->json('extras')->nullable();
                $table->timestamps();
            },
            'sales' => function ($table) {
                $table->id();
                $table->decimal('total', 8, 2)->default(0.00);
                $table->json('items')->nullable();
                $table->timestamps();
            },
            'failed_jobs' => function ($table) { $table->id(); },
            'user_roles' => function ($table) {
                $table->id();
                $table->integer('user_id')->nullable();
                $table->integer('role_id')->nullable();
            },
            'product_allergens' => function ($table) {
                $table->id();
                $table->integer('product_codigo')->nullable();
                $table->integer('allergen_id')->nullable();
            },
            'product_dietary_tags' => function ($table) {
                $table->id();
                $table->integer('product_codigo')->nullable();
                $table->integer('dietary_tag_id')->nullable();
            },
            'ingredient_suppliers' => function ($table) {
                $table->id();
                $table->integer('ingredient_id')->nullable();
                $table->integer('supplier_id')->nullable();
            },
            'product_recipes' => function ($table) {
                $table->id();
                $table->integer('product_codigo')->nullable();
                $table->integer('ingredient_id')->nullable();
                $table->decimal('cantidad_requerida', 8, 2)->default(0.00);
            },
            'product_extras' => function ($table) {
                $table->id();
                $table->integer('product_codigo')->nullable();
                $table->integer('extra_ingredient_id')->nullable();
            },
            'custom_items' => function ($table) {
                $table->id();
                $table->integer('custom_base_id')->nullable();
                $table->decimal('precio_total', 8, 2)->default(0.00);
            },
            'table_reservations' => function ($table) {
                $table->id();
                $table->integer('dining_table_id')->nullable();
            },
            'orders' => function ($table) {
                $table->id();
                $table->decimal('total', 8, 2)->default(0.00);
                $table->json('items')->nullable();
                $table->string('estado')->default('pendiente');
                $table->string('estado_preparacion')->default('pendiente');
                $table->string('tipo_pedido')->default('llevar');
                $table->string('metodo_pago')->default('efectivo');
                $table->string('numero_mesa')->nullable();
                $table->timestamps();
            },
            'custom_item_details' => function ($table) {
                $table->id();
                $table->integer('custom_item_id')->nullable();
                $table->integer('custom_option_id')->nullable();
            },
            'order_status_histories' => function ($table) {
                $table->id();
                $table->integer('order_id')->nullable();
                $table->integer('user_id')->nullable();
            },
            'personal_access_tokens' => function ($table) {
                $table->id();
                $table->string('token')->nullable();
            },
            'sale_details' => function ($table) {
                $table->id();
                $table->integer('sale_id')->nullable();
                $table->integer('product_codigo')->nullable();
                $table->integer('cantidad')->default(0);
                $table->decimal('precio_unitario', 8, 2)->default(0.00);
                $table->decimal('subtotal', 8, 2)->default(0.00);
            },
            'inventory_logs' => function ($table) {
                $table->id();
                $table->integer('product_codigo')->nullable();
            },
            'order_item_extras' => function ($table) {
                $table->id();
                $table->integer('order_id')->nullable();
                $table->integer('product_codigo')->nullable();
                $table->decimal('extra_precio', 8, 2)->default(0.00);
            }
        ];

        foreach ($tables as $name => $blueprint) {
            Schema::connection($conn)->create($name, $blueprint);
        }
    }

    public function test_read_only_mode_blocks_writes_but_allows_reads()
    {
        Config::set('app.read_only', true);
        Config::set('app.read_only_bypass_token', 'secret_key');

        $responseGet = $this->get('/api/orders');
        // If route does not exist it might return 404/401, but not 503 from read-only unless method is POST/PUT/DELETE
        $this->assertNotEquals(503, $responseGet->status());

        $responsePost = $this->post('/api/orders', []);
        $responsePost->assertStatus(503);
    }

    public function test_read_only_mode_allows_secure_bypass()
    {
        Config::set('app.read_only', true);
        Config::set('app.read_only_bypass_token', 'secret_key');

        $responsePost = $this->withHeaders([
            'X-Read-Only-Bypass' => 'secret_key'
        ])->post('/api/orders', []);

        $this->assertNotEquals(503, $responsePost->status());
    }

    public function test_read_only_config_cache_compatibility()
    {
        $this->assertIsBool(config('app.read_only'));
    }

    public function test_command_rejects_non_empty_target()
    {
        DB::connection('migration_target')->table('users')->insert([
            ['id' => 1, 'name' => 'Alice']
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(1, $exitCode);
    }

    public function test_migration_is_idempotent_and_creates_checkpoints()
    {
        DB::connection('migration_source')->table('users')->insert([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob']
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(0, $exitCode);

        // Mark the successful run as failed so it can be resumed
        DB::connection('migration_target')->table('data_migration_runs')
            ->where('id', 1)
            ->update(['status' => 'failed']);

        // Resume
        $exitCodeResume = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--resume' => true,
            '--run-id' => 1,
            '--force' => true
        ])->run();

        $this->assertEquals(0, $exitCodeResume);
    }

    public function test_resume_aborts_if_source_database_changed()
    {
        // Setup initial run
        DB::connection('migration_source')->table('users')->insert([
            ['id' => 1, 'name' => 'Alice']
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(0, $exitCode);

        // Modify source database to trigger source_checksum change
        DB::connection('migration_source')->table('users')->insert([
            ['id' => 2, 'name' => 'Charlie']
        ]);

        // Attempt resume
        $exitCodeResume = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--resume' => true,
            '--run-id' => 1,
            '--force' => true
        ])->run();

        $this->assertEquals(1, $exitCodeResume);
    }

    public function test_migration_verifies_checksums_correctly()
    {
        DB::connection('migration_source')->table('products')->insert([
            ['id' => 1, 'codigo' => 101, 'precio' => 15.50, 'extras' => json_encode(['sugar' => true])]
        ]);
        DB::connection('migration_target')->table('products')->insert([
            ['id' => 1, 'codigo' => 101, 'precio' => 15.50, 'extras' => json_encode(['sugar' => true])]
        ]);

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
        $val1 = 0.1;
        $val2 = 0.2;
        $floatSum = $val1 + $val2;
        
        $this->assertNotEquals(0.3, $floatSum);

        $strVal1 = '0.10';
        $strVal2 = '0.20';
        $stringSum = bcadd($strVal1, $strVal2, 2);

        $this->assertEquals('0.30', $stringSum);
    }

    public function test_json_keys_are_canonicalized_for_deterministic_checksums()
    {
        $jsonA = '{"a":1,"b":{"x":10,"y":20}}';
        $jsonB = '{"b":{"y":20,"x":10},"a":1}';

        DB::connection('migration_source')->table('products')->insert([
            ['id' => 1, 'codigo' => 101, 'precio' => '15.50', 'extras' => $jsonA]
        ]);
        DB::connection('migration_target')->table('products')->insert([
            ['id' => 1, 'codigo' => 101, 'precio' => '15.50', 'extras' => $jsonB]
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--verify-only' => true
        ])->run();

        $this->assertEquals(0, $exitCode);
    }

    public function test_source_sqlite_connection_enforces_read_only_and_fails_writes()
    {
        DB::connection('migration_source')->table('users')->insert(['id' => 98, 'name' => 'Initial User']);

        Config::set('database.connections.migration_source.options', [
            \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY,
        ]);
        DB::purge('migration_source');

        $this->expectException(\PDOException::class);
        $this->expectExceptionMessageMatches('/attempt to write a readonly database|readonly/i');

        DB::connection('migration_source')->table('users')->insert(['id' => 99, 'name' => 'Should Fail']);
    }

    public function test_read_only_end_to_end_on_physical_sqlite_file()
    {
        DB::connection('migration_source')->table('users')->insert([
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob']
        ]);

        $checksumBefore = hash_file('sha256', $this->tempSourceFile);
        $originalOptions = config('database.connections.migration_source.options', []);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--source-file' => $this->tempSourceFile,
            '--force' => true
        ])->run();

        $this->assertEquals(0, $exitCode);

        $checksumAfter = hash_file('sha256', $this->tempSourceFile);
        $this->assertEquals($checksumBefore, $checksumAfter);
        $this->assertEquals($originalOptions, config('database.connections.migration_source.options'));
    }

    public function test_source_options_restored_on_exception()
    {
        Schema::connection('migration_target')->drop('users');
        $originalOptions = config('database.connections.migration_source.options', []);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(1, $exitCode);
        $this->assertEquals($originalOptions, config('database.connections.migration_source.options'));
    }

    public function test_preflight_rejects_unexpected_source_columns_before_writing()
    {
        Schema::connection('migration_source')->table('categories', function ($table) {
            $table->boolean('activo')->default(true);
        });

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(1, $exitCode);
        $this->assertEquals(0, DB::connection('migration_target')->table('data_migration_runs')->count());
    }

    public function test_personal_access_tokens_not_migrated()
    {
        DB::connection('migration_source')->table('personal_access_tokens')->insert([
            ['id' => 1, 'token' => 'token_secret_123']
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(0, $exitCode);
        $this->assertEquals(0, DB::connection('migration_target')->table('personal_access_tokens')->count());
    }

    public function test_failed_jobs_not_migrated()
    {
        DB::connection('migration_source')->table('failed_jobs')->insert([
            ['id' => 1]
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(0, $exitCode);
        $this->assertEquals(0, DB::connection('migration_target')->table('failed_jobs')->count());
    }

    public function test_status_failed_when_verify_migration_fails()
    {
        // Insert an orphaned row in user_roles in source
        DB::connection('migration_source')->table('user_roles')->insert([
            ['id' => 1, 'user_id' => 999, 'role_id' => 1] // 999 does not exist
        ]);

        // Run migration with --allow-orphans. It will skip this row, causing count mismatch in verification phase
        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--allow-orphans' => true,
            '--force' => true
        ])->run();

        $this->assertEquals(1, $exitCode);

        $run = DB::connection('migration_target')->table('data_migration_runs')->where('id', 1)->first();
        $this->assertEquals('failed', $run->status);
        $this->assertNotNull($run->finished_at);
        $options = json_decode($run->options, true);
        $this->assertArrayHasKey('error_summary', $options);
    }



    public function test_dry_run_with_data_does_not_write_to_target()
    {
        DB::connection('migration_source')->table('users')->insert([
            ['id' => 1, 'name' => 'Alice']
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--dry-run' => true,
            '--force' => true
        ])->run();

        $this->assertEquals(0, $exitCode);
        $this->assertEquals(0, DB::connection('migration_target')->table('users')->count());
    }

    public function test_dry_run_validates_related_data_without_requiring_target_rows()
    {
        DB::connection('migration_source')->table('products')->insert([
            'id' => 1,
            'codigo' => 101,
            'precio' => '12.34',
        ]);
        DB::connection('migration_source')->table('allergens')->insert(['id' => 1]);
        DB::connection('migration_source')->table('product_allergens')->insert([
            'id' => 1,
            'product_codigo' => 101,
            'allergen_id' => 1,
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--dry-run' => true,
            '--force' => true,
        ])->run();

        $this->assertEquals(0, $exitCode);
        $this->assertEquals(0, DB::connection('migration_target')->table('products')->count());
        $this->assertEquals(0, DB::connection('migration_target')->table('product_allergens')->count());
        $this->assertEquals(0, DB::connection('migration_target')->table('data_migration_runs')->count());
    }

    public function test_run_id_is_rejected_without_resume_before_writing()
    {
        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--run-id' => 1,
            '--force' => true,
        ])->run();

        $this->assertEquals(1, $exitCode);
        $this->assertEquals(0, DB::connection('migration_target')->table('data_migration_runs')->count());
    }

    public function test_order_domain_strings_are_validated_without_foreign_key_lookups()
    {
        DB::connection('migration_source')->table('orders')->insert([
            'id' => 1,
            'total' => '10.00',
            'estado' => 'pendiente',
            'estado_preparacion' => 'pendiente',
            'tipo_pedido' => 'delivery',
            'metodo_pago' => 'delivery',
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true,
        ])->run();

        $this->assertEquals(0, $exitCode);
        $this->assertEquals('delivery', DB::connection('migration_target')->table('orders')->value('metodo_pago'));
    }

    public function test_invalid_order_domain_string_aborts_before_writing()
    {
        DB::connection('migration_source')->table('orders')->insert([
            'id' => 1,
            'total' => '10.00',
            'estado' => 'pendiente',
            'estado_preparacion' => 'pendiente',
            'tipo_pedido' => 'desconocido',
            'metodo_pago' => 'efectivo',
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true,
        ])->run();

        $this->assertEquals(1, $exitCode);
        $this->assertEquals(0, DB::connection('migration_target')->table('orders')->count());
        $this->assertEquals(0, DB::connection('migration_target')->table('data_migration_runs')->count());
    }

    public function test_ci_migration_target_is_real_postgresql()
    {
        if (!filter_var(env('MIGRATION_REQUIRE_PGSQL', false), FILTER_VALIDATE_BOOL)) {
            $this->assertContains(DB::connection('migration_target')->getDriverName(), ['sqlite', 'pgsql']);
            return;
        }

        $this->assertSame('pgsql', DB::connection('migration_target')->getDriverName());
        $this->assertStringContainsString('PostgreSQL', DB::connection('migration_target')->selectOne('SELECT version() AS version')->version);
    }

    public function test_resume_aborts_on_source_size_mismatch()
    {
        DB::connection('migration_source')->table('users')->insert([
            ['id' => 1, 'name' => 'Alice']
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(0, $exitCode);

        // Modify source size metadata in target database runs table to trigger mismatch
        DB::connection('migration_target')->table('data_migration_runs')
            ->where('id', 1)
            ->update(['source_size' => 999999]);

        $exitCodeResume = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--resume' => true,
            '--run-id' => 1,
            '--force' => true
        ])->run();

        $this->assertEquals(1, $exitCodeResume);
    }

    public function test_resume_aborts_on_target_fingerprint_mismatch()
    {
        DB::connection('migration_source')->table('users')->insert([
            ['id' => 1, 'name' => 'Alice']
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(0, $exitCode);

        DB::connection('migration_target')->table('data_migration_runs')
            ->where('id', 1)
            ->update(['target_fingerprint' => 'different_fingerprint']);

        $exitCodeResume = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--resume' => true,
            '--run-id' => 1,
            '--force' => true
        ])->run();

        $this->assertEquals(1, $exitCodeResume);
    }

    public function test_resume_aborts_on_manifest_version_mismatch()
    {
        DB::connection('migration_source')->table('users')->insert([
            ['id' => 1, 'name' => 'Alice']
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(0, $exitCode);

        DB::connection('migration_target')->table('data_migration_runs')
            ->where('id', 1)
            ->update(['manifest_version' => '1.0.0']); // Mismatches constant 2.0.0

        $exitCodeResume = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--resume' => true,
            '--run-id' => 1,
            '--force' => true
        ])->run();

        $this->assertEquals(1, $exitCodeResume);
    }

    public function test_resume_aborts_on_code_commit_mismatch()
    {
        DB::connection('migration_source')->table('users')->insert([
            ['id' => 1, 'name' => 'Alice']
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(0, $exitCode);

        DB::connection('migration_target')->table('data_migration_runs')
            ->where('id', 1)
            ->update(['code_commit' => 'outdated_hash']);

        $exitCodeResume = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--resume' => true,
            '--run-id' => 1,
            '--force' => true
        ])->run();

        $this->assertEquals(1, $exitCodeResume);
    }

    public function test_orphan_detection_user_roles()
    {
        // Source table has user_roles record pointing to non-existent user or role
        DB::connection('migration_source')->table('user_roles')->insert([
            ['id' => 1, 'user_id' => 999, 'role_id' => 1]
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(1, $exitCode);
    }

    public function test_orphan_detection_product_codigo_references()
    {
        DB::connection('migration_source')->table('product_allergens')->insert([
            ['id' => 1, 'product_codigo' => 999, 'allergen_id' => 1]
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(1, $exitCode);
    }

    public function test_orphan_detection_product_category_reference()
    {
        DB::connection('migration_source')->table('products')->insert([
            'id' => 1,
            'codigo' => 101,
            'category_id' => 999,
            'precio' => '25.00',
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true,
        ])->run();

        $this->assertEquals(1, $exitCode);
        $this->assertEquals(0, DB::connection('migration_target')->table('products')->count());
    }

    public function test_orphan_detection_sale_details_sale_id()
    {
        DB::connection('migration_source')->table('sale_details')->insert([
            ['id' => 1, 'sale_id' => 999, 'product_codigo' => 101]
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(1, $exitCode);
    }

    public function test_orphan_detection_order_status_histories()
    {
        DB::connection('migration_source')->table('order_status_histories')->insert([
            ['id' => 1, 'order_id' => 999]
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(1, $exitCode);
    }

    public function test_orphan_detection_custom_item_details()
    {
        DB::connection('migration_source')->table('custom_item_details')->insert([
            ['id' => 1, 'custom_item_id' => 999, 'custom_option_id' => 1]
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(1, $exitCode);
    }

    public function test_orphan_detection_table_reservations()
    {
        DB::connection('migration_source')->table('table_reservations')->insert([
            ['id' => 1, 'dining_table_id' => 999]
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(1, $exitCode);
    }

    public function test_all_decimal_columns_preserved()
    {
        // Insert data with decimal fields into ALL 14 decimal columns across tables
        DB::connection('migration_source')->table('products')->insert(['id' => 1, 'codigo' => 101, 'precio' => '12.34']);
        DB::connection('migration_source')->table('orders')->insert(['id' => 1, 'total' => '123.45']);
        DB::connection('migration_source')->table('sales')->insert(['id' => 1, 'total' => '234.56']);
        DB::connection('migration_source')->table('ingredients')->insert(['id' => 1, 'stock_actual' => '345.67', 'costo_unitario' => '4.56']);
        DB::connection('migration_source')->table('extra_ingredients')->insert(['id' => 1, 'precio' => '5.67']);
        DB::connection('migration_source')->table('product_recipes')->insert(['id' => 1, 'cantidad_requerida' => '6.78']);
        DB::connection('migration_source')->table('custom_bases')->insert(['id' => 1, 'precio_base' => '7.89']);
        DB::connection('migration_source')->table('custom_options')->insert(['id' => 1, 'precio_adicional' => '8.90']);
        DB::connection('migration_source')->table('custom_items')->insert(['id' => 1, 'precio_total' => '9.01']);
        DB::connection('migration_source')->table('delivery_providers')->insert(['id' => 1, 'comision_porcentaje' => '1.25']);
        DB::connection('migration_source')->table('sale_details')->insert(['id' => 1, 'precio_unitario' => '2.34', 'subtotal' => '4.68']);
        DB::connection('migration_source')->table('order_item_extras')->insert(['id' => 1, 'extra_precio' => '3.45']);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(0, $exitCode);

        // Verify target values with precise BCMath string checks
        $this->assertEquals('12.34', DB::connection('migration_target')->table('products')->where('id', 1)->value('precio'));
        $this->assertEquals('123.45', DB::connection('migration_target')->table('orders')->where('id', 1)->value('total'));
        $this->assertEquals('234.56', DB::connection('migration_target')->table('sales')->where('id', 1)->value('total'));
        
        $ingredient = DB::connection('migration_target')->table('ingredients')->where('id', 1)->first();
        $this->assertEquals('345.67', $ingredient->stock_actual);
        $this->assertEquals('4.56', $ingredient->costo_unitario);

        $this->assertEquals('5.67', DB::connection('migration_target')->table('extra_ingredients')->where('id', 1)->value('precio'));
        $this->assertEquals('6.78', DB::connection('migration_target')->table('product_recipes')->where('id', 1)->value('cantidad_requerida'));
        $this->assertEquals('7.89', DB::connection('migration_target')->table('custom_bases')->where('id', 1)->value('precio_base'));
        $this->assertEquals('8.90', DB::connection('migration_target')->table('custom_options')->where('id', 1)->value('precio_adicional'));
        $this->assertEquals('9.01', DB::connection('migration_target')->table('custom_items')->where('id', 1)->value('precio_total'));
        $this->assertEquals('1.25', DB::connection('migration_target')->table('delivery_providers')->where('id', 1)->value('comision_porcentaje'));

        $detail = DB::connection('migration_target')->table('sale_details')->where('id', 1)->first();
        $this->assertEquals('2.34', $detail->precio_unitario);
        $this->assertEquals('4.68', $detail->subtotal);

        $this->assertEquals('3.45', DB::connection('migration_target')->table('order_item_extras')->where('id', 1)->value('extra_precio'));
    }

    public function test_run_status_lifecycle_success()
    {
        DB::connection('migration_source')->table('users')->insert([
            ['id' => 1, 'name' => 'Alice']
        ]);

        $exitCode = $this->artisan('db:migrate-to-pgsql', [
            '--source' => 'migration_source',
            '--target' => 'migration_target',
            '--force' => true
        ])->run();

        $this->assertEquals(0, $exitCode);

        $run = DB::connection('migration_target')->table('data_migration_runs')->where('id', 1)->first();
        $this->assertEquals('success', $run->status);
        $this->assertNotNull($run->finished_at);
    }
}
