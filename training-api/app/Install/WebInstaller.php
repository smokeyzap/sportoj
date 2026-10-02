<?php
declare(strict_types=1);

namespace App\Install;

use App\Http\Request;
use App\Http\Response;
use App\Support\Config;
use App\Support\Logger;
use App\Support\RateLimiter;

/**
 * Browser wizard behind public/install.php. Only usable when storage/install.key exists, the visitor presents that key
 * and storage/installed.lock does not exist (approved interpretation 7). There is deliberately no reset function.
 */
final class WebInstaller
{
    public function __construct(
        private Installer $installer,
        private Config $config,
        private RateLimiter $limiter,
        private Logger $logger
    ) {
    }

    public function handle(Request $request): Response
    {
        // Neutral for everyone when closed or not enabled: no hint which of the two it is, no secrets.
        if ($this->installer->isInstalled() || !is_file($this->installer->keyPath())) {
            return $this->page(404, 'Niet beschikbaar', '<p>Deze pagina is niet beschikbaar.</p>');
        }
        $https = $this->isHttps($request);
        if ($request->method !== 'POST') {
            return $this->page(200, 'Installatie', $this->keyForm(''));
        }
        $form = $this->form($request);
        $key = (string) ($form['key'] ?? '');
        $rateKey = 'install:' . $request->ip;
        if ($this->limiter->tooMany($rateKey, 5, 60)) {
            return $this->page(429, 'Te veel pogingen', '<p>Te veel pogingen. Probeer het over een minuut opnieuw.</p>');
        }
        if (!$this->installer->keyMatches($key)) {
            $this->limiter->hit($rateKey, 60);
            $this->logger->warning('installer: wrong key', ['ip' => $request->ip]);
            return $this->page(403, 'Installatie', $this->keyForm('Ongeldige installatiesleutel.'));
        }

        $checks = $this->installer->systemCheck($https);
        if (($form['step'] ?? '') !== 'install') {
            return $this->page(200, 'Systeemcontrole', $this->checksHtml($checks) . ($this->canProceed($checks) ? $this->userForm($key, [], '') : ''));
        }
        if (!$this->canProceed($checks)) {
            return $this->page(422, 'Systeemcontrole', $this->checksHtml($checks));
        }
        $password = (string) ($form['password'] ?? '');
        if ($password !== (string) ($form['password_confirmation'] ?? '')) {
            return $this->page(422, 'Systeemcontrole', $this->userForm($key, $form, 'Wachtwoorden komen niet overeen.'));
        }
        try {
            $schema = $this->installer->installSchema();
            $seed = $this->installer->seed();
            $this->installer->createUser((string) ($form['name'] ?? ''), (string) ($form['email'] ?? ''), $password, (string) ($form['timezone'] ?? 'Europe/Amsterdam'));
            $verify = $this->installer->verify();
            if (!Installer::allOk($verify)) {
                throw new InstallException('Verificatie mislukt: ' . implode('; ', array_map(static fn (array $v): string => $v['name'] . '=' . $v['detail'], array_filter($verify, static fn (array $v): bool => !$v['ok']))));
            }
            $this->installer->writeLock();
        } catch (InstallException $e) {
            $this->logger->error('installer failed', ['message' => $e->getMessage()]);
            return $this->page(422, 'Installatie mislukt', '<p class="err">' . $this->e($e->getMessage()) . '</p>' . $this->userForm($key, $form, ''));
        } catch (\Throwable $e) {
            $this->logger->error('installer crashed', ['message' => $e->getMessage()]);
            return $this->page(500, 'Installatie mislukt', '<p class="err">Onverwachte fout; zie serverlog.</p>');
        }
        return $this->page(200, 'Installatie voltooid', '<p>Schema: ' . $this->e($schema) . ', dataset: ' . $this->e($seed)
            . '. De installer is gesloten (storage/installed.lock) en de installatiesleutel is verwijderd.</p>');
    }

    /** @param list<array{ok:bool,required:bool}> $checks */
    private function canProceed(array $checks): bool
    {
        return Installer::checksPass($checks);
    }

    private function isHttps(Request $request): bool
    {
        if ($this->config->bool('TRUST_PROXY_HEADERS', false) && strtolower((string) $request->header('X-Forwarded-Proto')) === 'https') {
            return true;
        }
        return ($_SERVER['HTTPS'] ?? '') !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off'
            || str_starts_with((string) $this->config->get('APP_URL', ''), 'https://') && ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }

    /** @return array<string,string> */
    private function form(Request $request): array
    {
        parse_str($request->body, $out);
        return array_map(static fn ($v): string => is_string($v) ? $v : '', $out);
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function keyForm(string $error): string
    {
        return ($error !== '' ? '<p class="err">' . $this->e($error) . '</p>' : '')
            . '<form method="post"><label>Installatiesleutel (inhoud van storage/install.key)<input name="key" type="password" autocomplete="off" required></label>'
            . '<button>Controleer systeem</button></form>';
    }

    /** @param array<string,string> $v */
    private function userForm(string $key, array $v, string $error): string
    {
        $val = fn (string $k, string $d = ''): string => $this->e($v[$k] ?? $d);
        return ($error !== '' ? '<p class="err">' . $this->e($error) . '</p>' : '')
            . '<form method="post"><input type="hidden" name="step" value="install"><input type="hidden" name="key" value="' . $this->e($key) . '">'
            . '<label>Naam<input name="name" value="' . $val('name') . '" required maxlength="120"></label>'
            . '<label>E-mailadres<input name="email" type="email" value="' . $val('email') . '" required maxlength="254"></label>'
            . '<label>Wachtwoord<input name="password" type="password" autocomplete="new-password" required></label>'
            . '<label>Bevestig wachtwoord<input name="password_confirmation" type="password" autocomplete="new-password" required></label>'
            . '<label>Timezone<input name="timezone" value="' . $val('timezone', 'Europe/Amsterdam') . '" required></label>'
            . '<button>Installeer schema, content en eerste gebruiker</button></form>';
    }

    /** @param list<array{name:string,ok:bool,required:bool,detail:string}> $checks */
    private function checksHtml(array $checks): string
    {
        $rows = '';
        foreach ($checks as $c) {
            $state = $c['ok'] ? 'OK' : ($c['required'] ? 'FOUT' : 'waarschuwing');
            $rows .= '<tr><td>' . $this->e($c['name']) . '</td><td>' . $state . '</td><td>' . $this->e($c['detail']) . '</td></tr>';
        }
        return '<table>' . $rows . '</table>';
    }

    private function page(int $status, string $title, string $body): Response
    {
        $html = '<!doctype html><html lang="nl"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1"><title>' . $this->e($title) . '</title>'
            . '<style>body{font:16px system-ui;max-width:42rem;margin:2rem auto;padding:0 1rem}label{display:block;margin:.8rem 0}'
            . 'input{display:block;width:100%;padding:.5rem}button{padding:.6rem 1rem}.err{color:#b00020}td{padding:.2rem .6rem;border-bottom:1px solid #ddd}</style></head>'
            . '<body><h1>' . $this->e($title) . '</h1>' . $body . '</body></html>';
        return (new Response($status, $html, ['Content-Type' => 'text/html; charset=utf-8']))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; form-action 'self'");
    }
}
