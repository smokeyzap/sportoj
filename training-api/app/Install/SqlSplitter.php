<?php
declare(strict_types=1);

namespace App\Install;

/** Splits a SQL script into statements, aware of quotes, comments and escapes (no client binary needed on shared hosting). */
final class SqlSplitter
{
    /** @return list<string> */
    public static function split(string $sql): array
    {
        $statements = [];
        $buf = '';
        $len = strlen($sql);
        $i = 0;
        while ($i < $len) {
            $c = $sql[$i];
            $n = $sql[$i + 1] ?? '';
            if ($c === "'" || $c === '"' || $c === '`') {
                $end = self::skipQuoted($sql, $i, $c);
                $buf .= substr($sql, $i, $end - $i);
                $i = $end;
                continue;
            }
            if (($c === '-' && $n === '-' && (($sql[$i + 2] ?? ' ') === ' ' || ($sql[$i + 2] ?? '') === "\t" || ($sql[$i + 2] ?? '') === "\n")) || $c === '#') {
                $nl = strpos($sql, "\n", $i);
                $i = $nl === false ? $len : $nl;
                continue;
            }
            if ($c === '/' && $n === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 2;
                $buf .= ' ';
                continue;
            }
            if ($c === ';') {
                $stmt = trim($buf);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $buf = '';
                $i++;
                continue;
            }
            $buf .= $c;
            $i++;
        }
        $stmt = trim($buf);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }
        return $statements;
    }

    private static function skipQuoted(string $sql, int $start, string $quote): int
    {
        $len = strlen($sql);
        $i = $start + 1;
        while ($i < $len) {
            $c = $sql[$i];
            if ($c === '\\' && $quote !== '`') {
                $i += 2;
                continue;
            }
            if ($c === $quote) {
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2;
                    continue;
                }
                return $i + 1;
            }
            $i++;
        }
        return $len;
    }
}
