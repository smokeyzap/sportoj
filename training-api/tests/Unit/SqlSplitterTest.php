<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Install\SqlSplitter;
use PHPUnit\Framework\TestCase;

final class SqlSplitterTest extends TestCase
{
    public function testSplitsSimpleStatements(): void
    {
        self::assertSame(['SELECT 1', 'SELECT 2'], SqlSplitter::split("SELECT 1;\nSELECT 2;\n"));
    }

    public function testSemicolonsInsideStringsDoNotSplit(): void
    {
        $sql = "INSERT INTO t VALUES ('a; b', \"c; d\", `e;f`);\nSELECT 1";
        self::assertSame(["INSERT INTO t VALUES ('a; b', \"c; d\", `e;f`)", 'SELECT 1'], SqlSplitter::split($sql));
    }

    public function testEscapedQuotes(): void
    {
        $sql = "INSERT INTO t VALUES ('it''s; ok', 'back\\'slash; x');SELECT 3;";
        self::assertSame(["INSERT INTO t VALUES ('it''s; ok', 'back\\'slash; x')", 'SELECT 3'], SqlSplitter::split($sql));
    }

    public function testCommentsAreDroppedButNotInsideStrings(): void
    {
        $sql = "-- header; with semicolon\nSELECT 1; /* block; comment */ SELECT '-- not a comment; really';\n# hash comment;\nSELECT 4";
        self::assertSame(['SELECT 1', "SELECT '-- not a comment; really'", 'SELECT 4'], SqlSplitter::split($sql));
    }

    public function testMultilineStringsSurvive(): void
    {
        $out = SqlSplitter::split("INSERT INTO t VALUES ('line1;\nline2');");
        self::assertSame(["INSERT INTO t VALUES ('line1;\nline2')"], $out);
    }

    public function testRealSchemaAndSeedSplitIntoTheExpectedStatementCounts(): void
    {
        $schema = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/database/schema.sql'));
        self::assertSame(13, count(array_filter($schema, static fn ($s) => str_starts_with($s, 'CREATE TABLE'))));
        self::assertSame(1, count(array_filter($schema, static fn ($s) => str_starts_with($s, 'INSERT INTO app_schema_versions'))));
        $seed = SqlSplitter::split((string) file_get_contents(dirname(__DIR__, 2) . '/database/seed.sql'));
        self::assertContains('START TRANSACTION', $seed);
        self::assertContains('COMMIT', $seed);
        self::assertGreaterThan(array_search('START TRANSACTION', $seed, true), array_search('COMMIT', $seed, true));
        self::assertCount(5, array_filter($seed, static fn ($s) => str_starts_with($s, 'ALTER TABLE')));
    }
}
