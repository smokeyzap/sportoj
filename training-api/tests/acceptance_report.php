<?php
declare(strict_types=1);

/**
 * Builds the per-acceptance-test result table from a PHPUnit JUnit log, so the report is grounded in an actual run.
 *
 *   vendor/bin/phpunit --log-junit build/junit.xml && php tests/acceptance_report.php build/junit.xml > docs/ACCEPTANCE_RESULTS.md
 *
 * AT ids are read from docs/spec/ACCEPTANCE_TESTS.md; a test belongs to an AT when its method name contains "testATnnn".
 * Exit code 1 when an in-scope AT has no passing test, a failing test, or only skipped tests.
 */
$junit = $argv[1] ?? null;
if ($junit === null || !is_file($junit)) {
    fwrite(STDERR, "usage: php tests/acceptance_report.php build/junit.xml\n");
    exit(2);
}
$spec = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/spec/ACCEPTANCE_TESTS.md');
preg_match_all('/^### AT-(\d{3}) (.+)$/m', $spec, $m, PREG_SET_ORDER);
$titles = [];
foreach ($m as $row) {
    $titles['AT' . $row[1]] = trim($row[2]);   // 'AT' prefix: numeric string keys would become ints
}

$xml = simplexml_load_file($junit);
$results = [];   // id => list of [name, status]
$total = ['tests' => 0, 'assertions' => 0, 'failed' => 0, 'skipped' => 0];
foreach ($xml->xpath('//testcase') as $case) {
    $name = (string) $case['name'];
    $class = (string) $case['class'];
    $status = isset($case->failure) || isset($case->error) ? 'FAIL' : (isset($case->skipped) ? 'SKIP' : 'PASS');
    $total['tests']++;
    $total['assertions'] += (int) $case['assertions'];
    $total['failed'] += $status === 'FAIL' ? 1 : 0;
    $total['skipped'] += $status === 'SKIP' ? 1 : 0;
    if (preg_match('/testAT(\d{3})((?:And\d{3})*)/', $name, $mm) === 1) {
        $ids = array_merge([$mm[1]], preg_match_all('/And(\d{3})/', $mm[2], $more) ? $more[1] : []);   // e.g. testAT151And152Restore...
        $short = substr(strrchr('\\' . $class, '\\'), 1);
        foreach ($ids as $id) {
            $results['AT' . $id][] = [$short . '::' . preg_replace('/^test/', '', explode(' with data set', $name)[0]), $status];
        }
    }
}

// Section Q (Laravel cutover) is explicitly not part of phase 1a (ACCEPTANCE_TESTS: "AT-001 t/m AT-152 relevant voor de PHP API").
$outOfScope = static fn (string $id): bool => (int) substr($id, 2) >= 160;
$failures = 0;
$lines = ["| AT | Omschrijving | Resultaat | Tests |", '|---|---|---|---|'];
foreach ($titles as $id => $title) {
    $tests = $results[$id] ?? [];
    $statuses = array_unique(array_column($tests, 1));
    if ($outOfScope($id)) {
        $verdict = 'buiten fase 1a';
    } elseif ($tests === []) {
        $verdict = '**geen test**';
        $failures++;
    } elseif (in_array('FAIL', $statuses, true)) {
        $verdict = '**GEFAALD**';
        $failures++;
    } elseif ($statuses === ['SKIP']) {
        $verdict = '**overgeslagen**';
        $failures++;
    } else {
        $verdict = 'geslaagd' . (in_array('SKIP', $statuses, true) ? ' (deels overgeslagen)' : '');
    }
    $names = implode('<br>', array_map(static fn (array $t): string => '`' . $t[0] . '`', array_slice($tests, 0, 6)));
    $lines[] = "| " . str_replace('AT', 'AT-', $id) . " | " . str_replace('|', '/', $title) . " | $verdict | $names |";
}
echo "# Acceptatietests: resultaat van een echte run\n\n";
echo 'Gegenereerd met `tests/acceptance_report.php` uit het JUnit-log van PHPUnit. ';
printf("Totaal in de suite: %d tests, %d asserts, %d gefaald, %d overgeslagen.\n\n", $total['tests'], $total['assertions'], $total['failed'], $total['skipped']);
echo implode("\n", $lines) . "\n";
exit($failures > 0 ? 1 : 0);
