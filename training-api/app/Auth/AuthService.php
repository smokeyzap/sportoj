<?php
declare(strict_types=1);

namespace App\Auth;

use App\Http\ApiException;
use App\Presenters\Presenter;
use App\Repositories\UserRepository;
use App\Support\Config;
use App\Support\Db;
use App\Support\Logger;
use App\Support\RateLimiter;

final class AuthService
{
    public function __construct(
        private Db $db,
        private Config $config,
        private UserRepository $users,
        private PasswordHasher $hasher,
        private SessionService $sessions,
        private RateLimiter $limiter,
        private Logger $logger
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @return array{user:array<string,mixed>,csrf:string,cookie:string}
     */
    public function login(array $input, string $ip): array
    {
        $email = $input['email'] ?? null;
        $password = $input['password'] ?? null;
        $errors = [];
        if (!is_string($email) || trim($email) === '' || mb_strlen($email) > 254) {
            $errors['email'] = 'Verplicht (maximaal 254 tekens).';
        }
        if (!is_string($password) || $password === '') {
            $errors['password'] = 'Verplicht.';
        }
        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        $email = mb_strtolower(trim($email));
        $window = max(1, $this->config->int('RATE_LOGIN_WINDOW', 60));
        $max = max(1, $this->config->int('RATE_LOGIN_MAX', 5));
        $ipMax = max($max, $this->config->int('RATE_LOGIN_IP_MAX', 30));
        $pairKey = 'login:' . $ip . '|' . $email;
        $ipKey = 'login-ip:' . $ip;

        if ($this->limiter->tooMany($pairKey, $max, $window) || $this->limiter->tooMany($ipKey, $ipMax, $window)) {
            $this->logger->warning('login rate limited', ['ip' => $ip, 'email_hash' => substr(hash('sha256', $email), 0, 12)]);
            throw new ApiException(429, 'RATE_LIMITED', 'Te veel loginpogingen.', null, [
                'Retry-After' => (string) $this->limiter->retryAfter($pairKey, $window),
            ]);
        }

        $user = $this->users->findByEmail($email);
        $valid = $this->hasher->verify($password, $user['password'] ?? $this->hasher->dummyHash()) && $user !== null;
        if (!$valid) {
            $this->limiter->hit($pairKey, $window);
            $this->limiter->hit($ipKey, $window);
            $this->logger->warning('login failed', ['ip' => $ip, 'email_hash' => substr(hash('sha256', $email), 0, 12)]);
            throw new ApiException(401, 'INVALID_CREDENTIALS', 'E-mailadres of wachtwoord is onjuist.');
        }
        $this->limiter->clear($pairKey);

        if ($this->hasher->needsRehash((string) $user['password'])) {
            $this->users->updatePassword((int) $user['id'], $this->hasher->hash($password));   // BR-175
        }
        $s = $this->sessions->create((int) $user['id']);
        return ['user' => Presenter::user($user), 'csrf' => $s['csrf'], 'cookie' => $this->sessions->cookieHeader($s['token'], $s['expires'])];
    }
}
