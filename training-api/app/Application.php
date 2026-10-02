<?php
declare(strict_types=1);

namespace App;

use App\Auth\AuthService;
use App\Auth\PasswordHasher;
use App\Auth\SessionService;
use App\Http\Kernel;
use App\Repositories\ProgramRepository;
use App\Repositories\SessionRepository;
use App\Repositories\UserProgramRepository;
use App\Repositories\UserRepository;
use App\Services\AssignmentService;
use App\Services\BlockDecisionService;
use App\Services\ExtraWorkoutService;
use App\Services\HistoryService;
use App\Services\NextActionService;
use App\Services\ProgramService;
use App\Services\ProgressService;
use App\Support\Clock;
use App\Support\Config;
use App\Support\Db;
use App\Support\Logger;
use App\Support\RateLimiter;

/** Hand-wired service container; production needs no dependencies beyond PHP itself. */
final class Application
{
    /** @var array<string,object> */
    private array $instances = [];

    public function __construct(public readonly Config $config)
    {
    }

    /** @template T of object @param class-string<T> $class @param callable():T $make @return T */
    private function once(string $class, callable $make): object
    {
        return $this->instances[$class] ??= $make();
    }

    public function clock(): Clock
    {
        return $this->once(Clock::class, fn () => new Clock());
    }

    public function db(): Db
    {
        return $this->once(Db::class, fn () => new Db($this->config));
    }

    public function logger(): Logger
    {
        return $this->once(Logger::class, fn () => new Logger(
            $this->config->path('LOG_PATH', 'storage/logs/app.log'),
            $this->config->get('LOG_LEVEL', 'warning') ?? 'warning'
        ));
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->once(RateLimiter::class, fn () => new RateLimiter($this->config->path('RATELIMIT_PATH', 'storage/ratelimit'), $this->logger()));
    }

    public function users(): UserRepository
    {
        return $this->once(UserRepository::class, fn () => new UserRepository($this->db()));
    }

    public function programs(): ProgramRepository
    {
        return $this->once(ProgramRepository::class, fn () => new ProgramRepository($this->db()));
    }

    public function userPrograms(): UserProgramRepository
    {
        return $this->once(UserProgramRepository::class, fn () => new UserProgramRepository($this->db()));
    }

    public function sessionRepo(): SessionRepository
    {
        return $this->once(SessionRepository::class, fn () => new SessionRepository($this->db()));
    }

    public function hasher(): PasswordHasher
    {
        return $this->once(PasswordHasher::class, fn () => new PasswordHasher());
    }

    public function sessions(): SessionService
    {
        return $this->once(SessionService::class, fn () => new SessionService($this->db(), $this->config, $this->clock()));
    }

    public function auth(): AuthService
    {
        return $this->once(AuthService::class, fn () => new AuthService(
            $this->db(), $this->config, $this->users(), $this->hasher(), $this->sessions(), $this->rateLimiter(), $this->logger()
        ));
    }

    public function progress(): ProgressService
    {
        return $this->once(ProgressService::class, fn () => new ProgressService($this->userPrograms()));
    }

    public function nextAction(): NextActionService
    {
        return $this->once(NextActionService::class, fn () => new NextActionService($this->db(), $this->userPrograms(), $this->programs()));
    }

    public function programService(): ProgramService
    {
        return $this->once(ProgramService::class, fn () => new ProgramService(
            $this->db(), $this->clock(), $this->programs(), $this->userPrograms(), $this->progress(), $this->nextAction()
        ));
    }

    public function assignments(): AssignmentService
    {
        return $this->once(AssignmentService::class, fn () => new AssignmentService(
            $this->db(), $this->clock(), $this->programs(), $this->userPrograms(), $this->sessionRepo(), $this->progress(), $this->nextAction()
        ));
    }

    public function blockDecisions(): BlockDecisionService
    {
        return $this->once(BlockDecisionService::class, fn () => new BlockDecisionService(
            $this->db(), $this->clock(), $this->programs(), $this->userPrograms(), $this->progress(), $this->nextAction()
        ));
    }

    public function extraWorkouts(): ExtraWorkoutService
    {
        return $this->once(ExtraWorkoutService::class, fn () => new ExtraWorkoutService(
            $this->db(), $this->clock(), $this->programs(), $this->userPrograms(), $this->sessionRepo()
        ));
    }

    public function history(): HistoryService
    {
        return $this->once(HistoryService::class, fn () => new HistoryService($this->db()));
    }

    public function kernel(): Kernel
    {
        return $this->once(Kernel::class, fn () => new Kernel($this));
    }
}
