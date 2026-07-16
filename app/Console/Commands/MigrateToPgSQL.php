<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Carbon\Carbon;

class MigrateToPgSQL extends Command
{
    const MANIFEST_VERSION = '2.0.0';

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

    // List of business tables to migrate in topological dependency order
    protected $migrateTables = [
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
        'sale_details',
        'inventory_logs',
        'order_item_extras'
    ];

    // Tables that must be left empty in PostgreSQL or regenerated empty
    protected $regenerateTables = [
        'personal_access_tokens',
        'failed_jobs'
    ];

    // Tables excluded from normal business migration flow
    protected $excludedTables = [
        'migrations',
        'password_reset_tokens',
        'data_migration_runs',
        'data_migration_checkpoints'
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

        if ($resume && !$runIdOpt) {
            $this->error('Safety violation: The --run-id option is strictly required when using --resume.');
            return 1;
        }

        if ($runIdOpt && !$resume) {
            $this->error('Safety violation: The --run-id option may only be used together with --resume.');
            return 1;
        }

        if ($dryRun && $resume) {
            $this->error('Safety violation: --dry-run cannot be combined with --resume.');
            return 1;
        }

        if ($batchSize < 1) {
            $this->error('The --batch-size option must be a positive integer.');
            return 1;
        }

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

        $runId = null;

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
            foreach ($this->migrateTables as $table) {
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
            $completedTables = [];
            $checkpoints = [];

            // Gather current database metadata
            $currentSourceChecksum = ($sourceDbPath !== ':memory:' && file_exists($sourceDbPath)) ? hash_file('sha256', $sourceDbPath) : 'in-memory-checksum';
            $currentSourceSize = ($sourceDbPath !== ':memory:' && file_exists($sourceDbPath)) ? filesize($sourceDbPath) : 0;
            $currentTargetFingerprint = $this->computeTargetFingerprint($targetConn);
            $currentCodeCommit = $this->getCodeCommit();

            if ($resume) {
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

                // Verify source and target integrity
                if ($lastRun->source_checksum !== $currentSourceChecksum) {
                    $this->error("Abort resume: Source SQLite database file checksum has changed since the migration started.");
                    return 1;
                }

                if (($lastRun->source_size ?? 0) != $currentSourceSize) {
                    $this->error("Abort resume: Source SQLite database file size has changed since the migration started.");
                    return 1;
                }

                if ($lastRun->target_fingerprint !== $currentTargetFingerprint) {
                    $this->error("Abort resume: Target database fingerprint has changed since the migration started.");
                    return 1;
                }

                if (($lastRun->manifest_version ?? '1.0.0') !== self::MANIFEST_VERSION) {
                    $this->error("Abort resume: Migrator manifest version does not match original run.");
                    return 1;
                }

                if ($lastRun->code_commit !== $currentCodeCommit) {
                    $this->error("Abort resume: Code repository commit hash does not match original run.");
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

                // Update run status to running
                DB::connection($targetConn)->table('data_migration_runs')
                    ->where('id', $runId)
                    ->update(['status' => 'running', 'updated_at' => now()]);

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

                if (!$dryRun) {
                    // Truncate regenerateTables on a fresh new run
                    foreach ($this->regenerateTables as $table) {
                        if (Schema::connection($targetConn)->hasTable($table)) {
                            DB::connection($targetConn)->table($table)->truncate();
                        }
                    }

                    $runId = DB::connection($targetConn)->table('data_migration_runs')->insertGetId([
                        'started_at' => now(),
                        'status' => 'running',
                        'options' => json_encode([
                            'batch_size' => $batchSize,
                            'allow_orphans' => $allowOrphans
                        ], JSON_THROW_ON_ERROR),
                        'source_checksum' => $currentSourceChecksum,
                        'source_size' => $currentSourceSize,
                        'target_fingerprint' => $currentTargetFingerprint,
                        'manifest_version' => self::MANIFEST_VERSION,
                        'code_commit' => $currentCodeCommit,
                        'created_at' => now(),
                        'updated_at' => now()
                    ]);
                    $this->info("Created new migration run ID: {$runId}");
                }
            }

            // Run migrations topologicially
            $errorsCount = 0;
            foreach ($this->migrateTables as $table) {
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
                        $errMsg = "Aborting migration due to errors/orphans in table: {$table}";
                        $this->error($errMsg);
                        $this->markRunFailed($targetConn, $runId, $errMsg);
                        return 1;
                    }
                } catch (\Throwable $e) {
                    $errMsg = "Failed migrating table {$table}: " . $e->getMessage();
                    $this->error($errMsg);
                    $this->markRunFailed($targetConn, $runId, $errMsg);
                    return 1;
                }
            }

            if ($dryRun) {
                $this->info("Dry-run complete. All steps executed successfully against memory. No data was written.");
                return 0;
            }

            // Align sequences in PostgreSQL
            $this->info("Aligning PostgreSQL sequences...");
            $this->alignSequences($targetConn);

            // Set run status to verifying
            DB::connection($targetConn)->table('data_migration_runs')
                ->where('id', $runId)->update(['status' => 'verifying', 'updated_at' => now()]);

            $this->info("=== Migration complete! Running validation phase ===");
            $verified = $this->verifyMigration($sourceConn, $targetConn);

            if ($verified && $errorsCount === 0) {
                DB::connection($targetConn)->table('data_migration_runs')
                    ->where('id', $runId)->update(['status' => 'success', 'finished_at' => now(), 'updated_at' => now()]);
                return 0;
            } else {
                $this->markRunFailed($targetConn, $runId, "Verification phase failed or errors count is non-zero.");
                return 1;
            }
        } catch (\Throwable $e) {
            $msg = "Fatal migration error: " . $e->getMessage() . "\n" . $e->getTraceAsString();
            $this->error($msg);
            $this->markRunFailed($targetConn, $runId, $e->getMessage());
            return 1;
        } finally {
            if ($sourceDriver === 'sqlite') {
                config(["database.connections.{$sourceConn}.options" => $originalOptions]);
                DB::purge($sourceConn);
            }
        }
    }

    /**
     * Update run status to failed with finished_at and error summary
     */
    private function markRunFailed($targetConn, $runId, string $errorSummary)
    {
        if ($runId) {
            try {
                $run = DB::connection($targetConn)->table('data_migration_runs')->where('id', $runId)->first();
                $options = $run ? json_decode($run->options ?? '{}', true) : [];
                $options['error_summary'] = $errorSummary;

                DB::connection($targetConn)->table('data_migration_runs')
                    ->where('id', $runId)
                    ->update([
                        'status' => 'failed',
                        'options' => json_encode($options, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'finished_at' => now(),
                        'updated_at' => now()
                    ]);
            } catch (\Exception $e) {
                // Ignore failure to write failed state
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

        // Determine columns
        $columns = Schema::connection($sourceConn)->getColumnListing($table);

        $query = DB::connection($sourceConn)->table($table);
        if ($pk === 'id') {
            $query->where('id', '>', $lastMigratedId)->orderBy('id', 'asc');
        }

        $query->chunk($batchSize, function ($rows) use ($sourceConn, $targetConn, $table, $columns, &$lastMigratedId, &$rowsCopied, $runId, $dryRun, $allowOrphans, &$errors, $pk) {
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

                // 6. Validate relationships against the populated side
                // Preflight already validated source relationships. During a dry-run no
                // parent rows exist in the target, so validate against the read-only source.
                $relationshipConn = $dryRun ? $sourceConn : $targetConn;
                if ($orphanError = $this->detectOrphan($relationshipConn, $table, $record)) {
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
     * Check if record references missing entities using real database schema relationships
     */
    private function detectOrphan($conn, $table, $record): ?string
    {
        $relations = [
            'user_roles' => [
                'user_id' => ['table' => 'users', 'column' => 'id'],
                'role_id' => ['table' => 'roles', 'column' => 'id'],
            ],
            'product_allergens' => [
                'product_codigo' => ['table' => 'products', 'column' => 'codigo'],
                'allergen_id'    => ['table' => 'allergens', 'column' => 'id'],
            ],
            'product_dietary_tags' => [
                'product_codigo' => ['table' => 'products', 'column' => 'codigo'],
                'dietary_tag_id' => ['table' => 'dietary_tags', 'column' => 'id'],
            ],
            'ingredient_suppliers' => [
                'ingredient_id' => ['table' => 'ingredients', 'column' => 'id'],
                'supplier_id'   => ['table' => 'suppliers', 'column' => 'id'],
            ],
            'product_recipes' => [
                'product_codigo' => ['table' => 'products', 'column' => 'codigo'],
                'ingredient_id'  => ['table' => 'ingredients', 'column' => 'id'],
            ],
            'product_extras' => [
                'product_codigo'      => ['table' => 'products', 'column' => 'codigo'],
                'extra_ingredient_id' => ['table' => 'extra_ingredients', 'column' => 'id'],
            ],
            'custom_items' => [
                'custom_base_id' => ['table' => 'custom_bases', 'column' => 'id'],
            ],
            'custom_item_details' => [
                'custom_item_id'   => ['table' => 'custom_items', 'column' => 'id'],
                'custom_option_id' => ['table' => 'custom_options', 'column' => 'id'],
            ],
            'table_reservations' => [
                'dining_table_id' => ['table' => 'dining_tables', 'column' => 'id'],
            ],
            'order_status_histories' => [
                'order_id' => ['table' => 'orders', 'column' => 'id'],
                'user_id'  => ['table' => 'users', 'column' => 'id'],
            ],
            'sale_details' => [
                'sale_id'        => ['table' => 'sales', 'column' => 'id'],
                'product_codigo' => ['table' => 'products', 'column' => 'codigo'],
            ],
            'inventory_logs' => [
                'product_codigo' => ['table' => 'products', 'column' => 'codigo'],
            ],
            'order_item_extras' => [
                'order_id'       => ['table' => 'orders', 'column' => 'id'],
                'product_codigo' => ['table' => 'products', 'column' => 'codigo'],
            ],
        ];

        if (!isset($relations[$table])) {
            return null;
        }

        foreach ($relations[$table] as $fkField => $ref) {
            $value = $record[$fkField] ?? null;
            if (!is_null($value)) {
                $exists = DB::connection($conn)
                    ->table($ref['table'])
                    ->where($ref['column'], $value)
                    ->exists();
                if (!$exists) {
                    return "Column {$fkField} value {$value} references {$ref['table']}.{$ref['column']} which does not exist.";
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

        foreach ($this->migrateTables as $table) {
            try {
                $maxId = DB::connection($targetConn)->table($table)->max('id');
                if ($maxId) {
                    DB::connection($targetConn)->statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), {$maxId}, true);");
                } else {
                    DB::connection($targetConn)->statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), 1, false);");
                }
            } catch (\Throwable $e) {
                // Table might not have incrementing id sequence
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

        // Verify migrated tables
        foreach ($this->migrateTables as $table) {
            if (!Schema::connection($sourceConn)->hasTable($table) || !Schema::connection($targetConn)->hasTable($table)) {
                $this->error("Verification failed: Required table '{$table}' is missing in source or target schema.");
                $hasErrors = true;
                continue;
            }

            $pk = 'id';

            $sourceCount = DB::connection($sourceConn)->table($table)->count();
            $targetCount = DB::connection($targetConn)->table($table)->count();

            $minId = null;
            $maxId = null;
            if ($pk === 'id') {
                $minId = DB::connection($targetConn)->table($table)->min('id');
                $maxId = DB::connection($targetConn)->table($table)->max('id');
            }

            // Decimal sums verification for ALL decimal columns
            $decimalCols = $this->getDecimalColumns($table);
            $sourceSums = [];
            $targetSums = [];

            foreach ($decimalCols as $col) {
                $scale = $this->getDecimalScale($table, $col);
                $sourceSum = $this->normalizeDecimalString(0, $scale);
                $targetSum = $this->normalizeDecimalString(0, $scale);

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

                $sourceSums[$col] = $sourceSum;
                $targetSums[$col] = $targetSum;

                if ($sourceSum !== $targetSum) {
                    $this->error("Verification failed: Decimal sum mismatch in table '{$table}', column '{$col}'. Source: {$sourceSum}, Target: {$targetSum}");
                    $hasErrors = true;
                }
            }

            // SHA-256 Canonical serialization check
            $sourceHash = $this->generateTableChecksum($sourceConn, $table, $pk);
            $targetHash = $this->generateTableChecksum($targetConn, $table, $pk);
            $checksumMatch = ($sourceHash === $targetHash) ? 'PASSED' : 'FAILED';

            if ($sourceCount !== $targetCount || $checksumMatch === 'FAILED') {
                $hasErrors = true;
            }

            $sourceSumStr = empty($decimalCols) ? '0.00' : implode(', ', array_map(fn($c) => "{$c}:{$sourceSums[$c]}", $decimalCols));
            $targetSumStr = empty($decimalCols) ? '0.00' : implode(', ', array_map(fn($c) => "{$c}:{$targetSums[$c]}", $decimalCols));

            $results[] = [
                $table,
                $sourceCount,
                $targetCount,
                $minId ?? 'N/A',
                $maxId ?? 'N/A',
                $sourceSumStr,
                $targetSumStr,
                $checksumMatch
            ];
        }

        $this->table($headers, $results);

        // Verify regenerated tables must exist and be empty
        foreach ($this->regenerateTables as $table) {
            if (!Schema::connection($targetConn)->hasTable($table)) {
                $this->error("Verification failed: Regenerated table '{$table}' is missing in target schema.");
                $hasErrors = true;
                continue;
            }
            $targetCount = DB::connection($targetConn)->table($table)->count();
            if ($targetCount > 0) {
                $this->error("Verification failed: Regenerated table '{$table}' has {$targetCount} rows, but must be empty.");
                $hasErrors = true;
            }
        }

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
                    $record[$key] = bcadd(trim($value), '0', $this->getDecimalScale($table, $key));
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

    /**
     * Run Preflight Validation Check
     */
    private function runPreflightValidation($sourceConn, $targetConn, $allowOrphans): bool
    {
        $this->info("Running preflight validation checks...");
        // 1. Schema columns inspection to detect "activo" or other columns not in manifest
        foreach ($this->migrateTables as $table) {
            if (!Schema::connection($sourceConn)->hasTable($table)) continue;

            try {
                $sourceCols = DB::connection($sourceConn)->select("PRAGMA table_info(\"{$table}\")");
                $targetCols = Schema::connection($targetConn)->getColumnListing($table);
                $targetColNames = array_map('strtolower', $targetCols);

                foreach ($sourceCols as $col) {
                    $colName = $col->name;
                    $colType = $col->type;

                    // Abort if column name is 'activo'
                    if (strtolower($colName) === 'activo') {
                        $msg = "Preflight failed: Source table '{$table}' contains 'activo' column, which is not in the official migrations schema. Column: {$colName}, Type: {$colType}";
                        $this->error($msg);
                        return false;
                    }

                    // Abort if column in source is not present in target schema
                    if (!in_array(strtolower($colName), $targetColNames)) {
                        $msg = "Preflight failed: Source column '{$colName}' in table '{$table}' is not present in target schema. Column: {$colName}, Type: {$colType}";
                        $this->error($msg);
                        return false;
                    }
                }
            } catch (\Throwable $e) {
                $this->error("Preflight error on table {$table}: " . $e->getMessage());
                return false;
            }
        }

        // 2. Verify required tables exist in source and target
        foreach ($this->migrateTables as $table) {
            if (!Schema::connection($sourceConn)->hasTable($table)) {
                $msg = "Preflight failed: Required table '{$table}' is missing in source SQLite database.";
                $this->error($msg);
                return false;
            }
            if (!Schema::connection($targetConn)->hasTable($table)) {
                $msg = "Preflight failed: Required table '{$table}' is missing in target database schema.";
                $this->error($msg);
                return false;
            }
        }

        foreach ($this->regenerateTables as $table) {
            if (!Schema::connection($targetConn)->hasTable($table)) {
                $msg = "Preflight failed: Required regenerated table '{$table}' is missing in target database schema.";
                $this->error($msg);
                return false;
            }
        }

        // 3. Verify all JSON columns contain valid JSON in source
        foreach ($this->migrateTables as $table) {
            if (!Schema::connection($sourceConn)->hasTable($table)) continue;
            
            $jsonCols = $this->getJSONColumns($table);
            $pk = 'id';
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
                    $msg = "Preflight failed: Found {$invalidCount} invalid JSON records in table '{$table}', column '{$col}' in source database.";
                    $this->error($msg);
                    return false;
                }
            }
        }

        // 4. Verify no orphaned references in source database (against SOURCE connection)
        $hasOrphans = false;
        foreach ($this->migrateTables as $table) {
            if (!Schema::connection($sourceConn)->hasTable($table)) continue;

            $pk = 'id';
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
            $msg = "Preflight failed: Orphaned references found. Correct them or run with --allow-orphans if permitted.";
            $this->error($msg);
            return false;
        }

        // 5. Validate semantic domain fields in orders
        if (!$this->validateDomainFields($sourceConn)) {
            $msg = "Preflight failed: Semantic domain validation failed on orders table.";
            $this->error($msg);
            return false;
        }

        $this->info("Preflight validation successful!");
        return true;
    }

    /**
     * Validate semantic domain fields in orders table
     */
    private function validateDomainFields($conn): bool
    {
        if (!Schema::connection($conn)->hasTable('orders')) {
            return true;
        }

        $allowedEstados = ['pendiente', 'en_preparacion', 'listo', 'entregado', 'cancelado', 'rechazado', 'completado'];
        $allowedEstadosPreparacion = ['pendiente', 'en_preparacion', 'listo', 'cancelado'];
        $allowedTipos = ['llevar', 'mesa', 'delivery', 'comedor'];
        $allowedPagos = ['efectivo', 'tarjeta', 'delivery', 'transferencia', 'sin_pago', 'tarjeta_credito', 'tarjeta_debito', 'transferencia_bancaria', 'online', 'yape', 'plin', 'pos'];

        $invalidCount = 0;

        DB::connection($conn)->table('orders')->orderBy('id')->lazy()->each(function ($row) use ($allowedEstados, $allowedEstadosPreparacion, $allowedTipos, $allowedPagos, &$invalidCount) {
            $record = (array)$row;
            
            // 1. Validate estado
            if (!isset($record['estado']) || !in_array($record['estado'], $allowedEstados, true)) {
                $value = $record['estado'] ?? 'NULL';
                $this->error("Semantic failure: Order ID {$record['id']} has invalid estado: '{$value}'.");
                $invalidCount++;
            }

            // 2. Validate estado_preparacion if present
            if (!isset($record['estado_preparacion']) || !in_array($record['estado_preparacion'], $allowedEstadosPreparacion, true)) {
                $value = $record['estado_preparacion'] ?? 'NULL';
                $this->error("Semantic failure: Order ID {$record['id']} has invalid estado_preparacion: '{$value}'.");
                $invalidCount++;
            }

            // 3. Validate tipo_pedido
            if (!isset($record['tipo_pedido']) || !in_array($record['tipo_pedido'], $allowedTipos, true)) {
                $value = $record['tipo_pedido'] ?? 'NULL';
                $this->error("Semantic failure: Order ID {$record['id']} has invalid tipo_pedido: '{$value}'.");
                $invalidCount++;
            }

            // 4. Validate metodo_pago
            if (!isset($record['metodo_pago']) || !in_array($record['metodo_pago'], $allowedPagos, true)) {
                $value = $record['metodo_pago'] ?? 'NULL';
                $this->error("Semantic failure: Order ID {$record['id']} has invalid metodo_pago: '{$value}'.");
                $invalidCount++;
            }

            // 5. Validate numero_mesa
            if (isset($record['tipo_pedido']) && $record['tipo_pedido'] === 'mesa') {
                if (!isset($record['numero_mesa']) || trim((string)$record['numero_mesa']) === '') {
                    $this->error("Semantic failure: Order ID {$record['id']} has type 'mesa' but missing or empty numero_mesa.");
                    $invalidCount++;
                }
            }
        });

        return $invalidCount === 0;
    }

    /**
     * Compute target fingerprint securely
     */
    private function computeTargetFingerprint(string $conn): string
    {
        $config = config("database.connections.{$conn}", []);
        $host = $config['host'] ?? '';
        $port = $config['port'] ?? '';
        $database = $config['database'] ?? '';
        $schema = $config['schema'] ?? $config['search_path'] ?? 'public';
        $username = $config['username'] ?? '';
        
        $serverVersion = '';
        try {
            $serverVersion = DB::connection($conn)->selectOne("SELECT version() as v")->v ?? '';
        } catch (\Throwable $e) {
            // Ignore if connection not queryable
        }

        $payload = json_encode([
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'schema' => $schema,
            'username' => $username,
            'server_version' => $serverVersion
        ], JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }

    /**
     * Get current repository commit hash
     */
    private function getCodeCommit(): string
    {
        return trim(@shell_exec('git rev-parse HEAD')) ?: 'unknown';
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
            'product_recipes' => ['cantidad_requerida'],
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
        try {
            $targetConn = $this->option('target');
            if (Schema::connection($targetConn)->hasTable($table)) {
                $columns = Schema::connection($targetConn)->getColumnListing($table);
                $bools = [];
                foreach ($columns as $col) {
                    try {
                        $type = Schema::connection($targetConn)->getColumnType($table, $col);
                        if ($type === 'boolean') {
                            $bools[] = $col;
                        }
                    } catch (\Throwable $ex) {
                        // ignore
                    }
                }
                return $bools;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return [];
    }

    private function getDecimalScale($table, $column): int
    {
        $map = [
            'products' => ['precio' => 2],
            'orders' => ['total' => 2],
            'sales' => ['total' => 2],
            'ingredients' => ['stock_actual' => 2, 'costo_unitario' => 2],
            'extra_ingredients' => ['precio' => 2],
            'product_recipes' => ['cantidad_requerida' => 2],
            'custom_bases' => ['precio_base' => 2],
            'custom_options' => ['precio_adicional' => 2],
            'custom_items' => ['precio_total' => 2],
            'delivery_providers' => ['comision_porcentaje' => 2],
            'sale_details' => ['precio_unitario' => 2, 'subtotal' => 2],
            'order_item_extras' => ['extra_precio' => 2]
        ];
        return $map[$table][$column] ?? 2;
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
