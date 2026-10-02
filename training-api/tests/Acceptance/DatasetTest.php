<?php
declare(strict_types=1);

namespace Tests\Acceptance;

use App\Support\Config;
use App\Support\Db;
use PHPUnit\Framework\TestCase;
use Tests\Support\Env;

/** AT-002, AT-003, AT-004 against the database the suite was bootstrapped with (installer path). */
final class DatasetTest extends TestCase
{
    private Db $db;
    /** @var array<string,mixed> */
    private array $json;

    protected function setUp(): void
    {
        $this->db = new Db(new Config(Env::dbSettings(), dirname(__DIR__, 2)));
        $this->json = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/docs/spec/training-program.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testAT002SeedIsComplete(): void
    {
        $count = fn (string $t): int => (int) $this->db->value("SELECT COUNT(*) FROM $t");
        self::assertSame(1, $count('programs'));
        self::assertSame(5, $count('training_blocks'));
        self::assertSame(30, $count('workout_templates'));
        self::assertSame(30, $count('block_workouts'));
        self::assertSame(143, $count('workout_exercises'));
    }

    public function testAT003DatasetMetadata(): void
    {
        self::assertSame('1.0.0', $this->db->value("SELECT meta_value FROM app_meta WHERE meta_key = 'dataset_version'"));
        self::assertSame($this->json['source']['sha256'], $this->db->value("SELECT meta_value FROM app_meta WHERE meta_key = 'source_sha256'"));
        self::assertSame($this->json['data_version'], $this->db->value("SELECT meta_value FROM app_meta WHERE meta_key = 'dataset_version'"));
        self::assertSame('1.0.0', $this->db->value('SELECT version FROM app_schema_versions'));
    }

    public function testAT004ProgramStructure(): void
    {
        $blocks = $this->db->all('SELECT * FROM training_blocks ORDER BY sequence');
        self::assertSame([3, 3, 3, 3, 2], array_map(static fn ($b) => (int) $b['default_cycle_count'], $blocks));
        self::assertSame(14, array_sum(array_map(static fn ($b) => (int) $b['default_cycle_count'], $blocks)));
        foreach ($blocks as $b) {
            $bw = $this->db->all('SELECT sequence, day_label FROM block_workouts WHERE training_block_id = ? ORDER BY sequence', [$b['id']]);
            self::assertCount(6, $bw);
            self::assertSame([1, 2, 3, 4, 5, 6], array_map(static fn ($r) => (int) $r['sequence'], $bw));
            self::assertSame(['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag'], array_column($bw, 'day_label'));
        }
        // original weeks tile 1..14 without gaps or overlap
        $next = 1;
        foreach ($blocks as $b) {
            self::assertSame($next, (int) $b['original_week_start']);
            self::assertSame($next + (int) $b['default_cycle_count'] - 1, (int) $b['original_week_end']);
            $next = (int) $b['original_week_end'] + 1;
        }
        self::assertSame(15, $next);
    }

    public function testDatabaseContentEqualsTheJsonSourceOfTruth(): void
    {
        $p = $this->json['program'];
        $row = $this->db->one('SELECT * FROM programs');
        self::assertSame([$p['public_id'], $p['code'], $p['name'], $p['version']], [$row['public_id'], $row['code'], $row['name'], $row['version']]);
        self::assertSame(84, $p['default_total_assignments']);

        foreach ($p['blocks'] as $jb) {
            $b = $this->db->one('SELECT * FROM training_blocks WHERE public_id = ?', [$jb['public_id']]);
            self::assertNotNull($b, $jb['name']);
            self::assertSame([$jb['sequence'], $jb['original_week_start'], $jb['original_week_end'], $jb['default_cycle_count']],
                [(int) $b['sequence'], (int) $b['original_week_start'], (int) $b['original_week_end'], (int) $b['default_cycle_count']]);
            foreach ($jb['workouts'] as $jw) {
                $t = $this->db->one('SELECT * FROM workout_templates WHERE public_id = ?', [$jw['public_id']]);
                self::assertNotNull($t, $jw['code']);
                foreach (['code', 'name', 'category', 'category_label', 'source_category_label', 'video_url', 'protocol_type', 'instructions', 'source_text'] as $f) {
                    self::assertSame($jw[$f], $t[$f], $jw['code'] . ' ' . $f);
                }
                self::assertEquals($jw['protocol_config'] === [] ? new \stdClass() : $jw['protocol_config'], json_decode((string) $t['protocol_config'], $jw['protocol_config'] === [] ? false : true));
                $bw = $this->db->one('SELECT * FROM block_workouts WHERE public_id = ?', [$jw['block_workout_public_id']]);
                self::assertSame([$jw['sequence'], $jw['day_label'], (int) $b['id'], (int) $t['id']], [(int) $bw['sequence'], $bw['day_label'], (int) $bw['training_block_id'], (int) $bw['workout_template_id']]);
                $ex = $this->db->all('SELECT * FROM workout_exercises WHERE workout_template_id = ? ORDER BY sequence', [$t['id']]);
                self::assertCount(count($jw['exercises']), $ex, $jw['code']);
                foreach ($jw['exercises'] as $i => $je) {
                    foreach (['name', 'sets', 'reps', 'duration_seconds', 'notes', 'source_line', 'public_id'] as $f) {
                        self::assertSame($je[$f], $ex[$i][$f], $jw['code'] . ' exercise ' . ($i + 1) . ' ' . $f);
                    }
                    self::assertSame($je['sequence'], (int) $ex[$i]['sequence']);
                }
            }
        }
    }

    public function testFidelityOfTheKnownSourceDeviationsIsPreserved(): void
    {
        $r = $this->db->one("SELECT source_category_label, category, protocol_type FROM workout_templates WHERE source_category_label = 'Abs AMRRAP' LIMIT 1");
        self::assertSame(['Abs AMRRAP', 'abs', 'amrap'], array_values($r ?? []), 'CONTENT_VALIDATION_REPORT: spelling kept, technical category normalised');
        $u = $this->db->one("SELECT category, category_label FROM workout_templates WHERE source_category_label = 'Upperbody' LIMIT 1");
        self::assertSame(['upper_body', 'Upper body'], array_values($u ?? []));
        self::assertSame(30, (int) $this->db->value("SELECT COUNT(*) FROM workout_templates WHERE video_url LIKE 'http%'"));
    }
}
