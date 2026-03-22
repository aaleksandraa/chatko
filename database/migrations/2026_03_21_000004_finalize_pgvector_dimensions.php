<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const EMBEDDING_DIMENSIONS = 1536;

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        } catch (QueryException) {
            return;
        }

        $this->ensureVectorColumnWithDimensions('product_embeddings', 'embedding_vector_pg');
        $this->ensureVectorColumnWithDimensions('knowledge_chunks', 'embedding_vector_pg');

        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_product_embeddings_vector_cosine
            ON product_embeddings USING ivfflat (embedding_vector_pg vector_cosine_ops) WITH (lists = 100)',
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS idx_knowledge_chunks_vector_cosine
            ON knowledge_chunks USING ivfflat (embedding_vector_pg vector_cosine_ops) WITH (lists = 100)',
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            DB::statement('DROP INDEX IF EXISTS idx_product_embeddings_vector_cosine');
            DB::statement('DROP INDEX IF EXISTS idx_knowledge_chunks_vector_cosine');
        } catch (QueryException) {
            // No-op.
        }
    }

    private function ensureVectorColumnWithDimensions(string $table, string $column): void
    {
        $currentType = DB::scalar(
            "SELECT format_type(a.atttypid, a.atttypmod)
            FROM pg_attribute a
            INNER JOIN pg_class c ON c.oid = a.attrelid
            INNER JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = current_schema()
              AND c.relname = ?
              AND a.attname = ?
              AND a.attnum > 0
              AND NOT a.attisdropped
            LIMIT 1",
            [$table, $column],
        );

        if ($currentType === null) {
            DB::statement(
                "ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS {$column} vector(".self::EMBEDDING_DIMENSIONS.')',
            );

            return;
        }

        if ($currentType === 'vector') {
            $this->enforceVectorDimensions($table, $column);
        }
    }

    private function enforceVectorDimensions(string $table, string $column): void
    {
        $dimensionedType = 'vector('.self::EMBEDDING_DIMENSIONS.')';

        try {
            DB::statement(
                "ALTER TABLE {$table}
                ALTER COLUMN {$column} TYPE {$dimensionedType}
                USING {$column}::{$dimensionedType}",
            );
        } catch (QueryException) {
            DB::statement("UPDATE {$table} SET {$column} = NULL WHERE {$column} IS NOT NULL");
            DB::statement(
                "ALTER TABLE {$table}
                ALTER COLUMN {$column} TYPE {$dimensionedType}
                USING {$column}::{$dimensionedType}",
            );
        }
    }
};


