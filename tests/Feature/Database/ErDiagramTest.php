<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Keeps the ER diagram in docs/03-DATA-MODEL.md honest: every relationship it
 * draws must be a real foreign key in MySQL, and every foreign key must be
 * drawn. Parent entities are written on the left of each relationship line.
 */
class ErDiagramTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_documented_diagram_matches_the_foreign_keys_in_mysql(): void
    {
        $actual = collect(DB::select(<<<'SQL'
            SELECT DISTINCT referenced_table_name AS parent, table_name AS child
            FROM information_schema.key_column_usage
            WHERE table_schema = DATABASE() AND referenced_table_name IS NOT NULL
            SQL))
            ->map(fn (object $key): string => "{$key->parent} -> {$key->child}")
            ->sort()
            ->values()
            ->all();

        $this->assertSame($actual, $this->documentedRelationships());
    }

    /**
     * @return list<string>
     */
    private function documentedRelationships(): array
    {
        $markdown = (string) file_get_contents(base_path('docs/03-DATA-MODEL.md'));
        preg_match('/```mermaid\s+erDiagram(.*?)```/s', $markdown, $diagram);
        preg_match_all('/^\s*([A-Z_]+)\s+\S{2}--\S{2}\s+([A-Z_]+)\s*:/m', $diagram[1] ?? '', $lines, PREG_SET_ORDER);

        return collect($lines)
            ->map(fn (array $line): string => strtolower($line[1]).' -> '.strtolower($line[2]))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
