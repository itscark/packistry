<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

use App\Enums\Ability;
use App\Enums\SourceProvider;
use App\Models\Package;
use App\Models\Repository;
use App\Models\User;
use App\Models\Version;
use App\Sources\Deletable;
use App\Sources\Gitea\Event\DeleteEvent;
use App\Sources\Gitea\Event\PushEvent;
use App\Sources\Gitea\Repository as GiteaRepository;
use App\Sources\Gitlab\Project;
use App\Sources\Importable;
use Database\Factories\RepositoryFactory;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\LaravelData\Data;

use function Pest\Laravel\postJson;

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/**
 * @param  Ability|Ability[]  $abilities
 */
function user(Ability|array $abilities = []): User
{
    $user = User::factory()->create();

    actingAs($user, $abilities);

    return $user;
}

/**
 * @param  Ability|Ability[]  $abilities
 */
function actingAs(User $user, Ability|array $abilities = []): void
{
    $abilities = is_array($abilities)
        ? array_map(fn (Ability $ability) => $ability->value, $abilities)
        : [$abilities->value];

    Sanctum::actingAs($user, $abilities);
}

function repository(string $name = 'sub', bool $public = false, ?Closure $closure = null): Repository
{
    return Repository::factory()
        ->state([
            'name' => $name,
        ])
        ->when($public, fn (RepositoryFactory $factory): RepositoryFactory => $factory->public())
        ->when(! is_null($closure), $closure)
        ->create();
}

function rootRepository(bool $public = false, ?Closure $closure = null): Repository
{
    return Repository::factory()
        ->when($public, fn (RepositoryFactory $factory): RepositoryFactory => $factory->public())
        ->when(! is_null($closure), $closure)
        ->root()
        ->create();
}

function rootWithPackageFromZip(bool $public = false, string $name = 'test/test', ?string $version = null, string $zip = __DIR__.'/Fixtures/project.zip'): Repository
{
    return rootRepository(
        public: $public,
        closure: fn (RepositoryFactory $factory) => $factory
            ->has(
                Package::factory()
                    ->state([
                        'name' => $name,
                    ])
                    ->has(
                        Version::factory()
                            ->fromZip($zip, $version)
                    )
            )
    );
}

function repositoryWithPackageFromZip(bool $public = false, string $name = 'test/test', ?string $version = null, string $zip = __DIR__.'/Fixtures/project.zip'): Repository
{
    return repository(
        public: $public,
        closure: fn (RepositoryFactory $factory) => $factory
            ->has(
                Package::factory()
                    ->state([
                        'name' => $name,
                    ])
                    ->has(
                        Version::factory()
                            ->fromZip($zip, $version, 'sub/')
                    )
            )
    );
}

/**
 * @return array<string, mixed>
 */
function rootAndSubRepository(bool $public = false, ?Closure $closure = null): array
{
    $prefix = $public ? 'public' : 'private';

    return [
        "$prefix repository (root)" => fn (): Repository => rootRepository(
            public: $public,
            closure: $closure
        ),
        "$prefix repository (sub)" => fn (): Repository => repository(
            public: $public,
            closure: $closure
        ),
    ];
}

/**
 * @return array<string, mixed>
 */
function rootAndSubRepositoryWithPackageFromZip(bool $public = false, string $name = 'test/test', ?string $version = null, string $zip = __DIR__.'/Fixtures/project.zip'): array
{
    $prefix = $public ? 'public' : 'private';

    return [
        "$prefix repository (root)" => fn (): Repository => rootWithPackageFromZip(
            public: $public,
            name: $name,
            version: $version,
            zip: $zip,
        ),
        "$prefix repository (sub)" => fn (): Repository => repositoryWithPackageFromZip(
            public: $public,
            name: $name,
            version: $version,
            zip: $zip,
        ),
    ];
}

/**
 * @param  Ability|Ability[]  $abilities
 * @param  array{int, int}  $statuses
 * @return array<string, mixed>
 */
function guestAnd(Ability|array $abilities, array $statuses = [200, 200]): array
{
    $values = is_array($abilities)
        ? array_map(fn (Ability $ability) => $ability->value, $abilities)
        : [$abilities->value];

    $imploded = implode(',', $values);

    return [
        "$statuses[0] guest" => [
            fn (): null => null,
            $statuses[0],
        ],
        "$statuses[1] user ($imploded)" => [
            fn (): User => user($abilities),
            $statuses[1],
        ],
    ];
}

/**
 * @return array<string, mixed>
 */
function giteaEventHeaders(Importable|Deletable $event, string $secret = 'secret'): array
{
    $eventType = match ($event::class) {
        PushEvent::class => 'push',
        DeleteEvent::class => 'delete',
        default => throw new RuntimeException('unknown event')
    };

    return ['X-Hub-Signature-256' => eventSignature($event, $secret), 'X-Gitea-Event' => $eventType];
}

/**
 * @return array<string, mixed>
 */
function eventHeaders(Importable|Deletable $event, string $secret = 'secret'): array
{
    return match ($event::class) {
        PushEvent::class, DeleteEvent::class => giteaEventHeaders($event, $secret),
        \App\Sources\Gitlab\Event\PushEvent::class => gitlabEventHeader($secret),
        default => throw new RuntimeException('unknown event')
    };
}

/**
 * @return array<string, mixed>
 */
function gitlabEventHeader(string $secret = 'secret'): array
{
    return ['X-Gitlab-Token' => $secret, 'X-Gitlab-Event' => 'Push Hook'];
}

function eventSignature(mixed $event, string $secret): string
{
    $json = json_encode($event);

    if ($json === false) {
        throw new RuntimeException('failed to decode json');
    }

    return 'sha256='.hash_hmac('sha256', $json, $secret);
}

/**
 * @return array<string, mixed>
 */
function providerPushEvents(string $refType = 'tags', string $ref = '1.0.0'): array
{
    return [
        'gitea' => [
            'provider' => SourceProvider::GITEA,
            'event' => new PushEvent(
                ref: "refs/$refType/$ref",
                repository: new GiteaRepository(
                    id: 1,
                    name: 'test',
                    fullName: 'vendor/test',
                    htmlUrl: 'http://localhost:3000/vendor/test',
                    url: 'http://localhost:3000/api/v1/repos/vendor/test',
                )
            ),
            'archivePath' => __DIR__.'/Fixtures/gitea-jamie-test.zip',
        ],
        'gitlab' => [
            'provider' => SourceProvider::GITLAB,
            'event' => new \App\Sources\Gitlab\Event\PushEvent(
                ref: "refs/$refType/$ref",
                after: 'after',
                before: 'before',
                checkoutSha: 'checkoutsha',
                project: new Project(
                    id: 1,
                    name: 'test',
                    pathWithNamespace: 'vendor/test',
                    webUrl: 'http://localhost/group/test',
                )
            ),
            'archivePath' => __DIR__.'/Fixtures/gitlab-jamie-test.zip',
        ],
    ];
}

function fakeZipArchiveDownload(Importable $event, string $archivePath): void
{
    /** @var string $content */
    $content = file_get_contents($archivePath);

    Http::fake([
        $event->zipUrl() => Http::response($content, headers: ['content-type' => 'application/zip']),
    ]);
}

function webhook(Repository $repository, SourceProvider $provider, (Importable&Data)|(Deletable&data) $event, ?string $archivePath = null): TestResponse
{
    if (! is_null($archivePath) && $event instanceof Importable) {
        fakeZipArchiveDownload($event, $archivePath);
    }

    return postJson($repository->url("/incoming/$provider->value"), $event->toArray(), eventHeaders($event));
}

/**
 * @return array<string, mixed>
 */
function providerDeleteEvents(string $refType = 'tags', string $ref = '1.0.0'): array
{
    return [
        'gitea' => [
            'provider' => SourceProvider::GITEA,
            'event' => new DeleteEvent(
                ref: $ref,
                refType: $refType === 'heads' ? 'branch' : 'tag',
                pusherType: 'user',
                repository: new GiteaRepository(
                    id: 1,
                    name: 'test',
                    fullName: 'vendor/test',
                    htmlUrl: 'http://localhost/vendor/test',
                    url: 'http://localhost/api/v1/repos/vendor/test',
                )
            ),
        ],
        'gitlab' => [
            'provider' => SourceProvider::GITLAB,
            'event' => new \App\Sources\Gitlab\Event\PushEvent(
                ref: "refs/$refType/$ref",
                after: '0000000000000000000000000000000000000000',
                before: 'before',
                checkoutSha: null,
                project: new Project(
                    id: 1,
                    name: 'test',
                    pathWithNamespace: 'vendor/test',
                    webUrl: 'http://localhost/vendor/test',
                )
            ),
        ],
    ];
}
