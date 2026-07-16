<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class MigrateToPgSQL extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'db:migrate-to-pgsql
        {--source=sqlite : The source database connection (default: sqlite)}
        {--target=pgsql_direct : The target PostgreSQL connection (default: pgsql_direct)}
        {--source-file= : Optional SQLite source file override}
        {--dry-run : Simulate the migration without writing to PostgreSQL}
        {--verify-only : Run only the validation verification phase}
        {--resume : Resume the last pending or failed run}
        {--run-id= : Specific run ID to resume}
        {--force : Force the migration by bypassing interactive confirmations}
        {--allow-orphans : Allow orphans on catalog tables but report and skip them}
        {--batch-size=200 : Chunk/batch size}
        {--report : Generate a verbose report}';

    /**
     * The console command description.
     */
    protected $description = 'Migrate Cafe Sublime database from SQLite to PostgreSQL with transactional integrity';

    // List of business tables in topological dependency order
    protected $topologicalTables = [
        // Level 0 (no dependencies)
        'users',
        'roles',
        'categories',
        'allergens',
        'dietary_tags',
        'ingredients',
        'suppliers',
        'extra_ingredients',
        'custom_bases',
        'custom_options',
        'dining_tables',
        'order_statuses',
        'delivery_providers',
        'payment_methods',
        'products',
        'sales',
        'failed_jobs',
        
        // Level 1 (direct dependencies)
        'user_roles',
        'product_allergens',
        'product_dietary_tags',
        'ingredient_suppliers',
        'product_recipes',
        'product_extras',
        'custom_items',
        'table_reservations',
        'orders',
        
        // Level 2 (dependencies on Level 1)
        'custom_item_details',
        'order_status_histories',
        'personal_access_tokens',
        'sale_details',
        'inventory_logs',
        'order_item_extras'
    ];

    // Core transactional tables where orphans are strictly forbidden
    protected $coreTransactionalTables = [
        'orders',
        'sales',
        'users',
        'payment_methods',
        'order_status_histories'
    ];

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $sourceConn = $this->option('source');
        $targetConn = $this->option('target');
        $sourceFile = $this->option('source-file');
        $dryRun = $this->option('dry-run');
        $verifyOnly = $this->option('verify-only');
        $resume = $this->option('resume');
        $runIdOpt = $this->option('run-id');
        $force = $this->option('force');
        $allowOrphans = $this->option('allow-orphans');
        $batchSize = intval($this->option('batch-size'));

        $this->info("=== Starting Cafe Sublime SQLite to PostgreSQL Migration ===");
        $this->info("App Timezone: " . config('app.timezone'));
        $this->info("Source Connection: {$sourceConn}");
        $this->info("Target Connection: {$targetConn}");
        if ($dryRun) {
            $this->warn("!!! DRY RUN MODE ACTIVE - No writes will occur on target connection !!!");
        }

        // Apply source file override if specified
        if ($sourceFile) {
            config(["database.connections.{$sourceConn}.database" => $sourceFile]);
            DB::purge($sourceConn);
        }

        $originalOptions = config("database.connections.{$sourceConn}.options", []);
        $sourceDriver = config("database.connections.{$sourceConn}.driver");

        try {
            // Enforce SQLite source connection to be opened in read-only mode dynamically
            if ($sourceDriver === 'sqlite') {
                config(["database.connections.{$sourceConn}.options" => [
                    \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY,
                ]]);
                DB::purge($sourceConn);
            }

            // Verify source connection is readable
            try {
                $sourceDbPath = config("database.connections.{$sourceConn}.database");
                $this->info("Source DB Path: {$sourceDbPath}");
                if ($sourceDbPath !== ':memory:' && !file_exists($sourceDbPath)) {
                    $this->error("Source database file does not exist: {$sourceDbPath}");
                    return 1;
                }
                // Read-only confirmation
                DB::connection($sourceConn)->select("PRAGMA foreign_keys;");
            } catch (\Exception $e) {
                $this->error("Could not read source SQLite database: " . $e->getMessage());
                return 1;
            }

            // Verify target business tables status
            $nonEmptyTables = [];
            foreach ($this->topologicalTables as $table) {
                if (Schema::connection($targetConn)->hasTable($table)) {
                    $count = DB::connection($targetConn)->table($table)->count();
                    if ($count > 0) {
                        $nonEmptyTables[] = "{$table} ({$count} rows)";
                    }
                }
            }

            if (!empty($nonEmptyTables) && !$verifyOnly && !$resume) {
                $this->error("Target database is not empty. The following business tables have rows:");
                foreach ($nonEmptyTables as $nonEmptyTable) {
                    $this->error(" - {$nonEmptyTable}");
                }
                $this->error("Migration aborted. An empty target database is required for a new import.");
                return 1;
            }

            // Run Preflight Validation Check
            $preflight = $this->runPreflightValidation($sourceConn, $targetConn, $allowOrphans);
            if (!$preflight) {
                return 1;
            }

            if ($verifyOnly) {
                $this->info("Running verification checks only...");
                return $this->verifyMigration($sourceConn, $targetConn) ? 0 : 1;
            }

            // Handle resume or new run
            $runId = null;
            $completedTables = [];
            $checkpoints = [];

            if ($resume) {
                if (!$runIdOpt) {
                    $this->error("Safety violation: The --run-id option is strictly required when using --resume.");
                    return 1;
                }

                $lastRun = DB::connection($targetConn)->table('data_migration_runs')
                    ->where('id', $runIdOpt)
                    ->first();

                if (!$lastRun) {
                    $this->error("No migration run found with ID: {$runIdOpt}");
                    return 1;
                }

                if ($lastRun->status !== 'pending' && $lastRun->status !== 'failed') {
                    $this->error("Migration run ID {$runIdOpt} is not in a resumeable state (current status: {$lastRun->status}).");
                    return 1;
                }

                // Gather current database metadata
                $currentSourceChecksum = ($sourceDbPath !== ':memory:' && file_exists($sourceDbPath)) ? hash_file('sha256', $sourceDbPath) : 'in-memory-checksum';
                $currentTargetFingerprint = hash('sha256', $targetConn . '-' . config("database.connections.{$targetConn}.database"));

                // Verify source and target integrity
                if ($lastRun->source_checksum !== $currentSourceChecksum) {
                    $this->error("Abort resume: Source SQLite database file checksum has changed since the migration started.");
                    return 1;
                }

                if ($lastRun->target_fingerprint !== $currentTargetFingerprint) {
                    $this->error("Abort resume: Target database fingerprint has changed since the migration started.");
                    return 1;
                }

                // Verify options match
                $currentOptions = json_encode([
                    'batch_size' => $batchSize,
                    'allow_orphans' => $allowOrphans
                ], JSON_THROW_ON_ERROR);

                if ($lastRun->options !== $currentOptions) {
                    $this->error("Abort resume: Migration run options do not match original options.");
                    return 1;
                }

                $runId = $lastRun->id;
                $this->info("Resuming migration run ID: {$runId}");

                $dbCheckpoints = DB::connection($targetConn)
                    ->table('data_migration_checkpoints')
                    ->where('run_id', $runId)
                    ->get();

                foreach ($dbCheckpoints as $cp) {
                    $checkpoints[$cp->table_name] = $cp;
                    if ($cp->status === 'completed') {
                        $completedTables[] = $cp->table_name;
                    }
                }
            } else {
                if (!$force && !$this->confirm("Are you sure you want to begin the migration?")) {
                    $this->info("Migration cancelled by user.");
                    return 0;
                }

                // Gather metadata for new run
                $sourceChecksum = ($sourceDbPath !== ':memory:' && file_exists($sourceDbPath)) ? hash_file('sha256', $sourceDbPath) : 'in-memory-checksum';
                $sourceSize = ($sourceDbPath !== ':memory:' && file_exists($sourceDbPath)) ? filesize($sourceDbPath) : 0;
                $targetFingerprint = hash('sha256', $targetConn . '-' . config("database.connections.{$targetConn}.database"));
                $codeCommit = trim(@shell_exec('git rev-parse HEAD')) ?: 'unknown';

                if (!$dryRun) {
                    $runId = DB::connection($targetConn)->table('data_migration_runs')->insertGetId([
                        'started_at' => now(),
                        'status' => 'pending',
                        'options' => json_encode([
                            'batch_size' => $batchSize,
                            'allow_orphans' => $allowOrphans
                        ], JSON_THROW_ON_ERROR),
                        'source_checksum' => $sourceChecksum,
                        'source_size' => $sourceSize,
                        'target_fingerprint' => $targetFingerprint,
                        'manifest_version' => '1.0.0',
                        'code_commit' => $codeCommit,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                    $this->info("Created new migration run ID: {$runId}");
                }
            }

            // Run migrations topologicially
            $errorsCount = 0;
            foreach ($this->topologicalTables as $table) {
                if (!Schema::connection($sourceConn)->hasTable($table) || !Schema::connection($targetConn)->hasTable($table)) {
                    $this->warn("Table {$table} does not exist in source or target. Skipping.");
                    continue;
                }

                if (in_array($table, $completedTables)) {
                    $this->info("Table {$table} already completed. Skipping.");
                    continue;
                }

                $this->info("Migrating table: {$table}");
                
                $lastMigratedId = 0;
                $rowsCopied = 0;
                if (isset($checkpoints[$table])) {
                    $lastMigratedId = $checkpoints[$table]->last_migrated_id;
                    $rowsCopied = $checkpoints[$table]->rows_copied;
                    $this->info("Resuming table {$table} from ID: {$lastMigratedId}");
                }

                try {
                    $tableErrors = $this->migrateTable($sourceConn, $targetConn, $table, $lastMigratedId, $rowsCopied, $runId, $batchSize, $dryRun, $allowOrphans);
                    $errorsCount += $tableErrors;

                    if ($tableErrors > 0 && !$allowOrphans) {
                        $this->error("Aborting migration due to errors/orphans in table: {$table}");
                        if (!$dryRun) {
                            DB::connection($targetConn)->table('data_migration_runs')
                                ->where('id', $runId)->update(['status' => 'failed', 'finished_at' => now()]);
                        }
                        return 1;
                    }
                } catch (\Exception $e) {
                    $this->error("Failed migrating table {$table}: " . $e->getMessage());
                    if (!$dryRun) {
                        DB::connection($targetConn)->table('data_migration_runs')
                            ->where('id', $runId)->update(['status' => 'failed', 'finished_at' => now()]);
                    }
                    return 1;
                }
            }

            // Align sequences in PostgreSQL
            if (!$dryRun) {
                $this->info("Aligning PostgreSQL sequences...");
                $this->alignSequences($targetConn);

                // Set run status to success
                DB::connection($targetConn)->table('data_migration_runs')
                    ->where('id', $runId)->update(['status' => 'success', 'finished_at' => now()]);
            }

            $this->info("=== Migration complete! Running validation phase ===");
            $verified = $this->verifyMigration($sourceConn, $targetConn);

            return ($verified && $errorsCount === 0) ? 0 : 1;
        } finally {
            if ($sourceDriver === 'sqlite') {
                config(["database.connections.{$sourceConn}.options" => $originalOptions]);
                DB::purge($sourceConn);
            }
        }
    }

    /**
     * Migrate single table in chunks
     */
    private function migrateTable($sourceConn, $targetConn, $table, &$lastMigratedId, &$rowsCopied, $runId, $batchSize, $dryRun, $allowOrphans): int
    {
        $errors = 0;

        // Initialize checkpoint record if not dry-run
        if (!$dryRun) {
            DB::connection($targetConn)->table('data_migration_checkpoints')->updateOrInsert(
                ['run_id' => $runId, 'table_name' => $table],
                ['last_migrated_id' => $lastMigratedId, 'rows_copied' => $rowsCopied, 'status' => 'processing', 'updated_at' => now()]
            );
        }

        $pk = 'id';
        if ($table === 'password_reset_tokens') {
            $pk = 'email';
        }

        // Determine columns
        $columns = Schema::connection($sourceConn)->getColumnListing($table);

        $query = DB::connection($sourceConn)->table($table);
        if ($pk === 'id') {
            $query->where('id', '>', $lastMigratedId)->orderBy('id', 'asc');
        }

        $query->chunk($batchSize, function ($rows) use ($targetConn, $table, $columns, &$lastMigratedId, &$rowsCopied, $runId, $dryRun, $allowOrphans, &$errors, $pk) {
            $recordsToInsert = [];

            foreach ($rows as $row) {
                $record = (array)$row;

                // 1. Validate JSON columns
                $jsonColumns = $this->getJSONColumns($table);
                foreach ($jsonColumns as $jsonCol) {
                    if (isset($record[$jsonCol]) && is_string($record[$jsonCol])) {
                        try {
                            // Decode to validate JSON format strictly
                            $decoded = json_decode($record[$jsonCol], true, 512, JSON_THROW_ON_ERROR);
                            // Normalize JSON formatting
                            $record[$jsonCol] = json_encode($decoded, JSON_THROW_ON_ERROR);
                        } catch (\JsonException $e) {
                            $this->error("JSON validation failed in table {$table}, row ID {$record[$pk]}: " . $e->getMessage());
                            $errors++;
                            return false; // Aborts chunk loop
                        }
                    }
                }

                // 2. Map Booleans (PostgreSQL requires true/false bool, SQLite stores as 0/1)
                $boolColumns = $this->getBooleanColumns($table);
                foreach ($boolColumns as $boolCol) {
                    if (isset($record[$boolCol])) {
                        $record[$boolCol] = ($record[$boolCol] === 1 || $record[$boolCol] === '1' || $record[$boolCol] === true);
                    }
                }

                // 3. Nullable and empty string conversions
                $nullableStrings = $this->getNullableStringColumns($table);
                foreach ($nullableStrings as $nullCol) {
                    if (isset($record[$nullCol]) && $record[$nullCol] === '') {
                        $record[$nullCol] = null;
                    }
                }

                // 4. Decimal mapping: preserve as precise decimal strings
                $decimalColumns = $this->getDecimalColumns($table);
                foreach ($decimalColumns as $decCol) {
                    if (isset($record[$decCol]) && is_numeric($record[$decCol])) {
                        $record[$decCol] = $this->normalizeDecimalString($record[$decCol], $this->getDecimalScale($table, $decCol));
                    }
                }

                // 5. Timezone mapping: verify raw strings parse under UTC
                if (isset($record['created_at']) && $record['created_at']) {
                    $record['created_at'] = Carbon::parse($record['created_at'])->toDateTimeString();
                }
                if (isset($record['updated_at']) && $record['updated_at']) {
                    $record['updated_at'] = Carbon::parse($record['updated_at'])->toDateTimeString();
                }

                // 6. Validate Orphans
                if ($orphanError = $this->detectOrphan($targetConn, $table, $record)) {
                    $isCore = in_array($table, $this->coreTransactionalTables);
                    $msg = "Orphaned reference detected in table {$table}, record ID {$record[$pk]}: {$orphanError}";
                    if ($isCore || !$allowOrphans) {
                        $this->error($msg);
                        $errors++;
                        return false;
                    } else {
                        $this->warn("{$msg} (Skipping record as permitted by --allow-orphans)");
                        continue; // Skips this row from insertion
                    }
                }

                $recordsToInsert[] = $record;
                if ($pk === 'id') {
                    $lastMigratedId = $record['id'];
                }
            }

            // Insert records batch inside a transaction containing the checkpoint update
            if (!$dryRun && !empty($recordsToInsert)) {
                DB::connection($targetConn)->transaction(function () use ($targetConn, $table, $recordsToInsert, $runId, $lastMigratedId, &$rowsCopied) {
                    DB::connection($targetConn)->table($table)->insert($recordsToInsert);
                    $rowsCopied += count($recordsToInsert);

                    DB::connection($targetConn)->table('data_migration_checkpoints')
                        ->where('run_id', $runId)
                        ->where('table_name', $table)
                        ->update([
                            'last_migrated_id' => $lastMigratedId,
                            'rows_copied' => $rowsCopied,
                            'updated_at' => now()
                        ]);
                });
            } else {
                $rowsCopied += count($recordsToInsert);
            }
        });

        if ($errors === 0 && !$dryRun) {
            DB::connection($targetConn)->table('data_migration_checkpoints')
                ->where('run_id', $runId)
                ->where('table_name', $table)
                ->update(['status' => 'completed', 'updated_at' => now()]);
        }

        return $errors;
    }

    /**
     * Check if record references missing entities
     */
    private function detectOrphan($targetConn, $table, $record): ?string
    {
        $relations = [
            'user_roles' => [
                'user_id' => 'users',
                'role_id' => 'roles'
            ],
            'product_allergens' => [
                'allergen_id' => 'allergens'
            ],
            'product_dietary_tags' => [
                'dietary_tag_id' => 'dietary_tags'
            ],
            'ingredient_suppliers' => [
                'ingredient_id' => 'ingredients',
                'supplier_id' => 'suppliers'
            ],
            'product_recipes' => [
                'ingredient_id' => 'ingredients'
            ],
            'product_extras' => [
                'extra_ingredient_id' => 'extra_ingredients'
            ],
            'custom_items' => [
                'custom_base_id' => 'custom_bases'
            ],
            'custom_item_details' => [
                'custom_item_id' => 'custom_items',
                'custom_option_id' => 'custom_options'
            ],
            'table_reservations' => [
                'dining_table_id' => 'dining_tables'
            ],
            'order_status_histories' => [
                'order_id' => 'orders',
                'user_id' => 'users'
            ],
            'products' => [
                'categoria_id' => 'categories'
            ],
            'orders' => [
                'mesa_id' => 'dining_tables',
                'estado_id' => 'order_statuses'
            ],
            'sales' => [
                'pedido_id' => 'orders',
                'metodo_pago_id' => 'payment_methods'
            ],
            'sale_details' => [
                'venta_id' => 'sales',
                'producto_id' => 'products'
            ],
            'inventory_logs' => [
                'ingrediente_id' => 'ingredients',
                'usuario_id' => 'users'
            ]
        ];

        if (!isset($relations[$table])) {
            return null;
        }

        foreach ($relations[$table] as $fkField => $parentTable) {
            if (isset($record[$fkField]) && !is_null($record[$fkField])) {
                $exists = DB::connection($targetConn)->table($parentTable)->where('id', $record[$fkField])->exists();
                if (!$exists) {
                    return "Column {$fkField} value {$record[$fkField]} does not exist in parent table {$parentTable}.";
                }
            }
        }

        return null;
    }

    /**
     * Sincroniza secuencias pgsql
     */
    private function alignSequences($targetConn)
    {
        $driver = DB::connection($targetConn)->getDriverName();
        if ($driver !== 'pgsql') {
            return;
        }

        foreach ($this->topologicalTables as $table) {
            if ($table === 'password_reset_tokens') continue;

            $maxId = DB::connection($targetConn)->table($table)->max('id');
            if ($maxId) {
                DB::connection($targetConn)->statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), {$maxId}, true);");
            } else {
                DB::connection($targetConn)->statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), 1, false);");
            }
        }
    }

    /**
     * Verification Phase
     */
    private function verifyMigration($sourceConn, $targetConn): bool
    {
        $hasErrors = false;

        $headers = ['Table', 'Source Row Count', 'Target Row Count', 'Min ID', 'Max ID', 'Source Sum', 'Target Sum', 'Checksum Match'];
        $results = [];

        foreach ($this->topologicalTables as $table) {
            if (!Schema::connection($sourceConn)->hasTable($table) || !Schema::connection($targetConn)->hasTable($table)) {
                $this->error("Verification failed: Required table '{$table}' is missing in source or target schema.");
                $hasErrors = true;
                continue;
            }

            $pk = ($table === 'password_reset_tokens') ? 'email' : 'id';

            $sourceCount = DB::connection($sourceConn)->table($table)->count();
            $targetCount = DB::connection($targetConn)->table($table)->count();

            $minId = null;
            $maxId = null;
            if ($pk === 'id') {
                $minId = DB::connection($targetConn)->table($table)->min('id');
                $maxId = DB::connection($targetConn)->table($table)->max('id');
            }

            // Decimal sums verification
            $decimalCol = $this->getDecimalColumns($table);
            $scale = 2;
            if (!empty($decimalCol)) {
                $col = $decimalCol[0];
                $scale = $this->getDecimalScale($table, $col);
            }
            $sourceSum = $this->normalizeDecimalString(0, $scale);
            $targetSum = $this->normalizeDecimalString(0, $scale);
            if (!empty($decimalCol)) {
                $col = $decimalCol[0];
                
                // SQLite Sum
                $sourceRows = DB::connection($sourceConn)->table($table)->pluck($col);
                foreach ($sourceRows as $val) {
                    if (is_numeric($val)) {
                        $sourceSum = bcadd($sourceSum, $this->normalizeDecimalString($val, $scale), $scale);
                    }
                }

                // pgsql Sum
                $targetRows = DB::connection($targetConn)->table($table)->pluck($col);
                foreach ($targetRows as $val) {
                    if (is_numeric($val)) {
                        $targetSum = bcadd($targetSum, $this->normalizeDecimalString($val, $scale), $scale);
                    }
                }
            }

            // SHA-256 Canonical serialization check
            $sourceHash = $this->generateTableChecksum($sourceConn, $table, $pk);
            $targetHash = $this->generateTableChecksum($targetConn, $table, $pk);
            $checksumMatch = ($sourceHash === $targetHash) ? 'PASSED' : 'FAILED';

            if ($sourceCount !== $targetCount || $checksumMatch === 'FAILED') {
                $hasErrors = true;
            }

            $results[] = [
                $table,
                $sourceCount,
                $targetCount,
                $minId ?? 'N/A',
                $maxId ?? 'N/A',
                $sourceSum,
                $targetSum,
                $checksumMatch
            ];
        }

        $this->table($headers, $results);

        // Verification of orders count grouped by preparation status
        if (Schema::connection($sourceConn)->hasTable('orders') && Schema::connection($targetConn)->hasTable('orders')) {
            $this->info("Checking workflow status metrics...");
            $sourceStatusGroup = DB::connection($sourceConn)->table('orders')
                ->select('estado_preparacion', DB::raw('count(*) as total'))
                ->groupBy('estado_preparacion')
                ->pluck('total', 'estado_preparacion')
                ->toArray();

            $targetStatusGroup = DB::connection($targetConn)->table('orders')
                ->select('estado_preparacion', DB::raw('count(*) as total'))
                ->groupBy('estado_preparacion')
                ->pluck('total', 'estado_preparacion')
                ->toArray();

            $this->info("Source orders status groups: " . json_encode($sourceStatusGroup));
            $this->info("Target orders status groups: " . json_encode($targetStatusGroup));

            if ($sourceStatusGroup !== $targetStatusGroup) {
                $this->error("Workflow status group metrics mismatch!");
                $hasErrors = true;
            }
        }

        if ($hasErrors) {
            $this->error("Verification FAILED! Differences found between source and target database.");
            return false;
        }

        $this->info("Verification SUCCESSFUL! SQLite and PostgreSQL databases are structurally and content-wise identical.");
        return true;
    }

    /**
     * Recursive helper to canonicalize JSON array by sorting all keys recursively.
     */
    private function canonicalizeJson($data)
    {
        if (is_array($data)) {
            ksort($data);
            foreach ($data as $key => $value) {
                $data[$key] = $this->canonicalizeJson($value);
            }
        }
        return $data;
    }

    /**
     * Generates a deterministic SHA-256 hash of a table's data using chunking and canonical representation.
     */
    private function generateTableChecksum($conn, $table, $pk): string
    {
        $sha = hash_init('sha256');

        $decimalCols = $this->getDecimalColumns($table);
        $boolCols = $this->getBooleanColumns($table);
        $nullableCols = $this->getNullableStringColumns($table);
        $jsonCols = $this->getJSONColumns($table);

        DB::connection($conn)->table($table)->orderBy($pk)->lazy()->each(function ($row) use ($sha, $table, $decimalCols, $boolCols, $nullableCols, $jsonCols) {
            $record = (array)$row;

            // Canonical normalization of values
            foreach ($record as $key => $value) {
                if (in_array($key, $decimalCols) && is_numeric($value)) {
                    // Use bcadd to prevent float casting precision loss
                    $record[$key] = bcadd(trim($value), '0', 2);
                } elseif (in_array($key, $boolCols)) {
                    $record[$key] = ($value === 1 || $value === '1' || $value === true);
                } elseif (in_array($key, $nullableCols) && $value === '') {
                    $record[$key] = null;
                } elseif (in_array($key, $jsonCols) && !is_null($value)) {
                    // Decode, recursively ksort, and encode back
                    $decoded = is_string($value) ? json_decode($value, true) : $value;
                    if (is_array($decoded)) {
                        $canonicalized = $this->canonicalizeJson($decoded);
                        $record[$key] = json_encode($canonicalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }
                }
            }

            // Sort keys to guarantee canonical JSON format representation
            ksort($record);

            hash_update($sha, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
        });

        return hash_final($sha);
    }

    private function runPreflightValidation($sourceConn, $targetConn, $allowOrphans): bool
    {
        $this->info("Running preflight validation checks...");

        // 1. Verify required tables exist in source and target
        foreach ($this->topologicalTables as $table) {
            if ($table === 'migrations' || $table === 'password_reset_tokens' || $table === 'failed_jobs' || $table === 'personal_access_tokens') {
                continue;
            }
            if (!Schema::connection($sourceConn)->hasTable($table)) {
                $this->error("Preflight failed: Required table '{$table}' is missing in source SQLite database.");
                return false;
            }
            if (!Schema::connection($targetConn)->hasTable($table)) {
                $this->error("Preflight failed: Required table '{$table}' is missing in target database schema.");
                return false;
            }
        }

        // 2. Verify all JSON columns contain valid JSON in source
        foreach ($this->topologicalTables as $table) {
            if (!Schema::connection($sourceConn)->hasTable($table)) continue;
            
            $jsonCols = $this->getJSONColumns($table);
            $pk = ($table === 'password_reset_tokens') ? 'email' : 'id';
            foreach ($jsonCols as $col) {
                $invalidCount = DB::connection($sourceConn)->table($table)
                    ->orderBy($pk)
                    ->lazy()
                    ->filter(function ($row) use ($col) {
                        $val = ((array)$row)[$col] ?? null;
                        if (is_null($val) || $val === '') return false;
                        try {
                            json_decode($val, true, 512, JSON_THROW_ON_ERROR);
                            return false;
                        } catch (\JsonException $e) {
                            return true;
                        }
                    })
                    ->count();

                if ($invalidCount > 0) {
                    $this->error("Preflight failed: Found {$invalidCount} invalid JSON records in table '{$table}', column '{$col}' in source database.");
                    return false;
                }
            }
        }

        // 3. Verify no orphaned references in source database
        $hasOrphans = false;
        foreach ($this->topologicalTables as $table) {
            if (!Schema::connection($sourceConn)->hasTable($table)) continue;

            $pk = ($table === 'password_reset_tokens') ? 'email' : 'id';
            DB::connection($sourceConn)->table($table)->orderBy($pk)->lazy()->each(function ($row) use ($table, $sourceConn, $allowOrphans, $pk, &$hasOrphans) {
                $record = (array)$row;
                if ($orphanError = $this->detectOrphan($sourceConn, $table, $record)) {
                    $isCore = in_array($table, $this->coreTransactionalTables);
                    $msg = "Orphan reference in source table {$table}, ID {$record[$pk]}: {$orphanError}";
                    if ($isCore || !$allowOrphans) {
                        $this->error($msg);
                        $hasOrphans = true;
                    } else {
                        $this->warn("{$msg} (Will skip during import)");
                    }
                }
            });
        }

        if ($hasOrphans) {
            $this->error("Preflight failed: Orphaned references found. Correct them or run with --allow-orphans if permitted.");
            return false;
        }

        $this->info("Preflight validation successful!");
        return true;
    }

    private function getJSONColumns($table): array
    {
        $map = [
            'products' => ['extras'],
            'orders' => ['items'],
            'sales' => ['items']
        ];
        return $map[$table] ?? [];
    }

    private function getDecimalColumns($table): array
    {
        $map = [
            'products' => ['precio'],
            'orders' => ['total'],
            'sales' => ['total'],
            'ingredients' => ['stock_actual', 'costo_unitario'],
            'extra_ingredients' => ['precio'],
            'custom_bases' => ['precio_base'],
            'custom_options' => ['precio_adicional'],
            'custom_items' => ['precio_total'],
            'delivery_providers' => ['comision_porcentaje'],
            'sale_details' => ['precio_unitario', 'subtotal'],
            'order_item_extras' => ['extra_precio']
        ];
        return $map[$table] ?? [];
    }

    private function getNullableStringColumns($table): array
    {
        $map = [
            'suppliers' => ['contacto', 'telefono'],
            'categories' => ['icono'],
            'allergens' => ['icono'],
            'table_reservations' => ['notas'],
            'orders' => ['codigo_delivery', 'numero_mesa'],
            'products' => ['imagen', 'descripcion']
        ];
        return $map[$table] ?? [];
    }

    private function getBooleanColumns($table): array
    {
        $map = [
            'categories' => ['activo'],
            'dining_tables' => ['activo'],
            'order_statuses' => ['activo'],
            'payment_methods' => ['activo'],
            'delivery_providers' => ['activo'],
            'products' => ['activo'],
            'extra_ingredients' => ['activo'],
            'custom_bases' => ['activo'],
            'custom_options' => ['activo'],
            'ingredients' => ['activo']
        ];
        return $map[$table] ?? [];
    }

    private function getDecimalScale($table, $column): int
    {
        return 2;
    }

    private function normalizeDecimalString($value, int $scale): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return bcadd('0', '0', $scale);
        }
        return bcadd($value, '0', $scale);
    }
}
