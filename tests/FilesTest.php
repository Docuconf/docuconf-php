<?php

declare(strict_types=1);

namespace Docuconf\Tests;

use Docuconf\Declaration;
use Docuconf\Env;
use Docuconf\Loader;
use Docuconf\Tests\Fixtures\Route;
use Docuconf\Tests\Fixtures\Routes;
use Docuconf\Tests\Support\Certs;
use Docuconf\Violation;
use PHPUnit\Framework\TestCase;

/**
 * File inputs with real files under a DOCUCONF_FILE_ROOT, and certificates
 * generated for each test.
 */
final class FilesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/docuconf-files-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function put(string $path, string $content): void
    {
        @mkdir(dirname($this->root . $path), 0o777, true);
        file_put_contents($this->root . $path, $content);
    }

    /**
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function env(array $extra = []): array
    {
        return ['DOCUCONF_FILE_ROOT' => $this->root] + $extra;
    }

    /**
     * @param array<string, string> $env
     * @return list<array{string, string}>
     */
    private function codes(Declaration $d, array $env = [], ?int $now = null): array
    {
        $result = Loader::load($d->spec(), $this->env($env), $now);
        return array_map(fn (Violation $v) => [$v->input, $v->code], $result->violations);
    }

    private function tls(?callable $configure = null): Declaration
    {
        $d = Env::declare('svc');
        $f = $d->tls('serving-tls', '/etc/svc/tls')->required()->describe('Serving certificate');
        if ($configure !== null) {
            $configure($f);
        }
        return $d;
    }

    public function testValidTlsWithCa(): void
    {
        $ca = Certs::ca();
        [$cert, $key] = Certs::issue(['svc.internal', 'api.example.com'], 365, 'RSA', $ca);
        Certs::writeTlsDir($this->root . '/etc/svc/tls', $cert, $key, $ca[0]);
        $d = $this->tls(fn ($f) => $f->dnsNames('svc.internal', 'api.example.com')->keyAlgorithms('RSA')->minRemaining('720h')->requireCA());
        self::assertSame([], $this->codes($d));
        $values = $d->load($this->env());
        self::assertSame($this->root . '/etc/svc/tls/tls.crt', $values->file('serving-tls')?->file('tls.crt'));
    }

    public function testCaMismatch(): void
    {
        [$cert, $key] = Certs::issue(['svc.internal'], 365, 'RSA', Certs::ca('one'));
        Certs::writeTlsDir($this->root . '/etc/svc/tls', $cert, $key, Certs::ca('two')[0]);
        self::assertSame([['serving-tls', 'certificate_invalid']], $this->codes($this->tls(fn ($f) => $f->requireCA())));
    }

    public function testExpiring(): void
    {
        [$cert, $key] = Certs::issue(['svc.internal'], 10);
        Certs::writeTlsDir($this->root . '/etc/svc/tls', $cert, $key);
        self::assertSame([['serving-tls', 'certificate_expiring']], $this->codes($this->tls(fn ($f) => $f->minRemaining('720h'))));
    }

    public function testExpired(): void
    {
        [$cert, $key] = Certs::issue(['svc.internal'], 1);
        Certs::writeTlsDir($this->root . '/etc/svc/tls', $cert, $key);
        self::assertSame([['serving-tls', 'certificate_invalid']], $this->codes($this->tls(), [], time() + 3 * 86400));
    }

    public function testDnsMismatchAndWildcards(): void
    {
        [$cert, $key] = Certs::issue(['*.example.com']);
        Certs::writeTlsDir($this->root . '/etc/svc/tls', $cert, $key);
        self::assertSame([], $this->codes($this->tls(fn ($f) => $f->dnsNames('api.example.com'))));
        self::assertSame(
            [['serving-tls', 'certificate_name_mismatch']],
            $this->codes($this->tls(fn ($f) => $f->dnsNames('a.b.example.com'))),
            'a wildcard covers one label only',
        );
        self::assertSame([['serving-tls', 'certificate_name_mismatch']], $this->codes($this->tls(fn ($f) => $f->dnsNames('example.org'))));
    }

    public function testKeyMismatch(): void
    {
        [$cert] = Certs::issue(['svc.internal']);
        [, $otherKey] = Certs::issue(['svc.internal']);
        Certs::writeTlsDir($this->root . '/etc/svc/tls', $cert, $otherKey);
        self::assertSame([['serving-tls', 'key_mismatch']], $this->codes($this->tls()));
    }

    public function testKeyAlgorithm(): void
    {
        [$cert, $key] = Certs::issue(['svc.internal'], 365, 'ECDSA');
        Certs::writeTlsDir($this->root . '/etc/svc/tls', $cert, $key);
        self::assertSame([], $this->codes($this->tls(fn ($f) => $f->keyAlgorithms('ECDSA'))));
        self::assertSame([['serving-tls', 'certificate_invalid']], $this->codes($this->tls(fn ($f) => $f->keyAlgorithms('RSA'))));
    }

    public function testWatchedConfigFileIsReread(): void
    {
        $d = Env::declare('svc');
        $d->configFile('settings', '/etc/svc/settings/settings.json')->required()->reload('watch')
            ->schema(['type' => 'object', 'required' => ['port']])->describe('Service settings');
        // Kubernetes swaps a symlink to update a projected file.
        $this->put('/etc/svc/settings/..v1/settings.json', '{"port": 1}');
        symlink($this->root . '/etc/svc/settings/..v1/settings.json', $this->root . '/etc/svc/settings/settings.json');
        $file = $d->load($this->env())->file('settings');
        self::assertNotNull($file);
        self::assertTrue($file->watched());
        self::assertSame(['port' => 1], $file->value);
        self::assertFalse($file->refresh(), 'nothing changed');

        $this->put('/etc/svc/settings/..v2/settings.json', '{"port": 2}');
        unlink($this->root . '/etc/svc/settings/settings.json');
        symlink($this->root . '/etc/svc/settings/..v2/settings.json', $this->root . '/etc/svc/settings/settings.json');
        self::assertTrue($file->refresh());
        self::assertSame(['port' => 2], $file->value);

        // A change that fails its checks is logged, and the last good content stays.
        $log = tempnam(sys_get_temp_dir(), 'docuconf-log');
        $previous = ini_set('error_log', (string) $log);
        try {
            $this->put('/etc/svc/settings/..v3/settings.json', '{"other": 3}');
            unlink($this->root . '/etc/svc/settings/settings.json');
            symlink($this->root . '/etc/svc/settings/..v3/settings.json', $this->root . '/etc/svc/settings/settings.json');
            self::assertFalse($file->refresh());
        } finally {
            ini_set('error_log', (string) $previous);
        }
        self::assertSame(['port' => 2], $file->value);
        self::assertStringContainsString('file settings changed, but the new content fails its checks', (string) file_get_contents((string) $log));
        unlink((string) $log);
    }

    /** Points a projected-volume style symlink at a new version of the file. */
    private function swap(string $path, string $version, string $content): void
    {
        $dir = dirname($path);
        $this->put("$dir/$version/" . basename($path), $content);
        @unlink($this->root . $path);
        symlink("{$this->root}$dir/$version/" . basename($path), $this->root . $path);
    }

    /** @return array{\Docuconf\Files\LoadedFile, string} the watched file and the error log it writes to */
    private function watchedSettings(): array
    {
        $d = Env::declare('svc');
        $d->configFile('settings', '/etc/svc/settings/settings.json')->required()->reload('watch')
            ->schema(['type' => 'object', 'required' => ['port']])->describe('Service settings');
        $this->swap('/etc/svc/settings/settings.json', '..v1', '{"port": 1}');
        $file = $d->load($this->env())->file('settings');
        self::assertNotNull($file);
        $log = $this->root . '/error.log';
        return [$file, $log];
    }

    public function testOnChangeRunsOnAnAcceptedChangeOnly(): void
    {
        [$file, $log] = $this->watchedSettings();
        $seen = [];
        $file->onChange(function ($f) use (&$seen) {
            $seen[] = $f->value;
        });
        $previous = ini_set('error_log', $log);
        try {
            $this->swap('/etc/svc/settings/settings.json', '..v2', '{"other": 2}');
            self::assertFalse($file->refresh());
            self::assertSame([], $seen, 'not called for a rejected change');

            $this->swap('/etc/svc/settings/settings.json', '..v3', '{"port": 3}');
            self::assertTrue($file->refresh());
            self::assertSame([['port' => 3]], $seen, 'called with the new value');
        } finally {
            ini_set('error_log', (string) $previous);
        }
    }

    public function testThrowingOnChangeCallbackDoesNotStopTheReload(): void
    {
        [$file, $log] = $this->watchedSettings();
        $calls = [];
        $file->onChange(function () use (&$calls) {
            $calls[] = 'first';
            throw new \RuntimeException('secret-looking detail {"port": 2}');
        });
        $unsubscribe = $file->onChange(function () use (&$calls) {
            $calls[] = 'removed';
        });
        $file->onChange(function () use (&$calls) {
            $calls[] = 'last';
        });
        $unsubscribe();
        $previous = ini_set('error_log', $log);
        try {
            $this->swap('/etc/svc/settings/settings.json', '..v2', '{"port": 2}');
            self::assertTrue($file->refresh());
        } finally {
            ini_set('error_log', (string) $previous);
        }
        self::assertSame(['first', 'last'], $calls);
        self::assertSame(['port' => 2], $file->value);
        self::assertSame(2, $file->status()->generation);
        $logged = (string) file_get_contents($log);
        self::assertStringContainsString('an on-change callback for file settings threw RuntimeException', $logged);
        self::assertStringNotContainsString('secret-looking', $logged);
    }

    public function testReloadStatus(): void
    {
        [$file, $log] = $this->watchedSettings();
        $status = $file->status();
        self::assertSame(1, $status->generation);
        self::assertNull($status->lastReload);
        self::assertNull($status->lastRejected);

        $previous = ini_set('error_log', $log);
        try {
            $before = new \DateTimeImmutable();
            $this->swap('/etc/svc/settings/settings.json', '..v2', '{"other": 2}');
            self::assertFalse($file->refresh());
            $status = $file->status();
            self::assertSame(1, $status->generation);
            self::assertNull($status->lastReload);
            self::assertNotNull($status->lastRejected);
            self::assertSame('settings', $status->lastRejected->name);
            self::assertSame(['schema_mismatch'], $status->lastRejected->codes);
            self::assertGreaterThanOrEqual($before->getTimestamp(), $status->lastRejected->at->getTimestamp());

            $this->swap('/etc/svc/settings/settings.json', '..v3', '{"port": 3}');
            self::assertTrue($file->refresh());
            $status = $file->status();
            self::assertSame(2, $status->generation);
            self::assertNotNull($status->lastReload);
            self::assertNull($status->lastRejected, 'cleared by an accepted change');
        } finally {
            ini_set('error_log', (string) $previous);
        }
    }

    public function testOnChangeNeedsWatch(): void
    {
        $d = Env::declare('svc');
        $d->text('licence', '/etc/svc/licence/key')->required()->describe('Licence key');
        $this->put('/etc/svc/licence/key', 'one');
        $file = $d->load($this->env())->file('licence');
        self::assertNotNull($file);
        self::assertSame(1, $file->status()->generation);
        $this->expectException(\LogicException::class);
        $file->onChange(fn () => null);
    }

    public function testKeystoreReloadUsesTheBootPassword(): void
    {
        $d = Env::declare('svc');
        $d->ifPresent('KEYSTORE_PASSWORD')->secret()->describe('Keystore password');
        $d->keystore('partner', '/etc/svc/partner/store.pkcs12', 'pkcs12')->required()->passwordVar('KEYSTORE_PASSWORD')
            ->reload('watch')->describe('Partner keystore');
        $this->swap('/etc/svc/partner/store.pkcs12', '..v1', Certs::pkcs12('changeit'));
        $file = $d->load($this->env(['KEYSTORE_PASSWORD' => 'changeit']))->file('partner');
        self::assertNotNull($file);
        $changes = 0;
        $file->onChange(function () use (&$changes) {
            $changes++;
        });
        $log = $this->root . '/error.log';
        $previous = ini_set('error_log', $log);
        $env = getenv('KEYSTORE_PASSWORD');
        try {
            // A store with another password is rejected, even if the process
            // environment now says that password: the boot one is used.
            putenv('KEYSTORE_PASSWORD=rotated');
            $_ENV['KEYSTORE_PASSWORD'] = 'rotated';
            $this->swap('/etc/svc/partner/store.pkcs12', '..v2', Certs::pkcs12('rotated'));
            self::assertFalse($file->refresh());
            self::assertSame(['keystore_unreadable'], $file->status()->lastRejected?->codes);
            self::assertSame(1, $file->status()->generation);
            self::assertSame(0, $changes);
            self::assertStringNotContainsString('rotated', (string) file_get_contents($log));

            $this->swap('/etc/svc/partner/store.pkcs12', '..v3', Certs::pkcs12('changeit'));
            self::assertTrue($file->refresh());
            self::assertSame(2, $file->status()->generation);
            self::assertSame(1, $changes);
        } finally {
            ini_set('error_log', (string) $previous);
            putenv($env === false ? 'KEYSTORE_PASSWORD' : "KEYSTORE_PASSWORD=$env");
            unset($_ENV['KEYSTORE_PASSWORD']);
        }
    }

    public function testContractFirstModeReloadsWatchedFiles(): void
    {
        $contract = \Docuconf\Contract::fromJson(['apiVersion' => 'docuconf.dev/v1alpha1', 'kind' => 'ConfigContract', 'metadata' => ['name' => 'svc'],
            'vars' => new \stdClass(),
            'files' => ['licence' => ['type' => 'text', 'path' => '/etc/svc/licence/key', 'required' => true, 'reload' => 'watch',
                'pattern' => '^[a-z]+$', 'description' => 'Licence key']]]);
        $this->swap('/etc/svc/licence/key', '..v1', 'one');
        $file = $contract->load($this->env())->file('licence');
        self::assertNotNull($file);
        self::assertTrue($file->watched());
        $seen = [];
        $file->onChange(function ($f) use (&$seen) {
            $seen[] = $f->content;
        });
        $previous = ini_set('error_log', $this->root . '/error.log');
        try {
            $this->swap('/etc/svc/licence/key', '..v2', 'TWO');
            self::assertFalse($file->refresh());
            self::assertSame(['pattern_mismatch'], $file->status()->lastRejected?->codes);
            $this->swap('/etc/svc/licence/key', '..v3', 'three');
            self::assertTrue($file->refresh());
        } finally {
            ini_set('error_log', (string) $previous);
        }
        self::assertSame(['three'], $seen);
        self::assertSame(2, $file->status()->generation);
    }

    public function testRestartIsNotWatched(): void
    {
        $d = Env::declare('svc');
        $d->text('licence', '/etc/svc/licence/key')->required()->describe('Licence key');
        $this->put('/etc/svc/licence/key', 'one');
        $file = $d->load($this->env())->file('licence');
        self::assertNotNull($file);
        $this->put('/etc/svc/licence/key', 'two, longer');
        self::assertFalse($file->refresh());
        self::assertSame('one', $file->value);
    }

    public function testMalformedCertificate(): void
    {
        // No PEM at all is file_malformed; PEM that does not parse is certificate_invalid (SPEC §11.2 item 5).
        Certs::writeTlsDir($this->root . '/etc/svc/tls', 'not a certificate', 'not a key');
        self::assertSame([['serving-tls', 'file_malformed']], $this->codes($this->tls()));
        [, $key] = Certs::issue(['svc.internal'], 365);
        Certs::writeTlsDir($this->root . '/etc/svc/tls', "-----BEGIN CERTIFICATE-----\nbm90IGEgY2VydA==\n-----END CERTIFICATE-----\n", $key);
        self::assertSame([['serving-tls', 'certificate_invalid']], $this->codes($this->tls()));
    }

    public function testMissingRequiredFileAndOptionalAbsent(): void
    {
        $d = $this->tls();
        $d->text('license', '/etc/svc/license/key.txt')->describe('Licence key');
        self::assertSame([['serving-tls', 'file_missing']], $this->codes($d));
    }

    private function routes(string $format = 'yaml'): Declaration
    {
        $d = Env::declare('svc');
        $d->configFile('routes', "/etc/svc/routes/routes.$format", $format)->schema(Routes::class)->required()->pathEnv('ROUTES_FILE')->describe('Routing table');
        return $d;
    }

    public function testConfigFileBindsToTheAppType(): void
    {
        $this->put('/etc/svc/routes/routes.yaml', "routes:\n  - match: /api\n    upstream: https://api.internal\n    timeout: 5s\n");
        $routes = $this->routes()->load($this->env())->file('routes')?->value;
        self::assertInstanceOf(Routes::class, $routes);
        self::assertInstanceOf(Route::class, $routes->routes[0]);
        self::assertSame('/api', $routes->routes[0]->match);
        self::assertSame('5s', (string) $routes->routes[0]->timeout);
    }

    public function testMalformedConfig(): void
    {
        $this->put('/etc/svc/routes/routes.yaml', "routes: [unclosed\n");
        self::assertSame([['routes', 'file_malformed']], $this->codes($this->routes()));
    }

    public function testSchemaViolation(): void
    {
        $this->put('/etc/svc/routes/routes.yaml', "routes:\n  - match: api\n    upstream: https://api.internal\n");
        self::assertSame([['routes', 'schema_mismatch']], $this->codes($this->routes()));
        $this->put('/etc/svc/routes/routes.yaml', "routes: []\n");
        self::assertSame([['routes', 'schema_mismatch']], $this->codes($this->routes()));
        $this->put('/etc/svc/routes/routes.yaml', "routes:\n  - {match: /, upstream: http://x, extra: 1}\n");
        self::assertSame([['routes', 'schema_mismatch']], $this->codes($this->routes()));
    }

    public function testJsonAndTomlConfig(): void
    {
        $this->put('/etc/svc/routes/routes.json', '{"routes":[{"match":"/","upstream":"http://x"}]}');
        self::assertSame([], $this->codes($this->routes('json')));
        $this->put('/etc/svc/routes/routes.toml', "[[routes]]\nmatch = \"/\"\nupstream = \"http://x\"\n");
        self::assertSame([], $this->codes($this->routes('toml')));
        $this->put('/etc/svc/routes/routes.toml', "[[routes]\n");
        self::assertSame([['routes', 'file_malformed']], $this->codes($this->routes('toml')));
    }

    public function testPathEnvUnderTheFileRoot(): void
    {
        $this->put('/mnt/elsewhere/r.yaml', "routes:\n  - {match: /, upstream: http://x}\n");
        $loaded = $this->routes()->load($this->env(['ROUTES_FILE' => '/mnt/elsewhere/r.yaml']))->file('routes');
        self::assertSame($this->root . '/mnt/elsewhere/r.yaml', $loaded?->path);
    }

    public function testFileTooLargeAndUnreadable(): void
    {
        $d = Env::declare('svc');
        $d->binary('geoip', '/data/geoip/db.mmdb')->required()->maxSize(4)->describe('GeoIP database');
        $this->put('/data/geoip/db.mmdb', '12345');
        self::assertSame([['geoip', 'file_too_large']], $this->codes($d));
        unlink($this->root . '/data/geoip/db.mmdb');
        mkdir($this->root . '/data/geoip/db.mmdb');
        self::assertSame([['geoip', 'file_unreadable']], $this->codes($d));
    }

    public function testCaBundle(): void
    {
        $d = Env::declare('svc');
        $d->caBundle('upstream-ca', '/etc/svc/ca/bundle.pem')->required()->minCertificates(2)->describe('Upstream CAs');
        $this->put('/etc/svc/ca/bundle.pem', Certs::ca('a')[0]);
        self::assertSame([['upstream-ca', 'file_malformed']], $this->codes($d));
        $this->put('/etc/svc/ca/bundle.pem', Certs::ca('a')[0] . Certs::ca('b')[0]);
        self::assertSame([], $this->codes($d));
    }

    private function keystore(string $format): Declaration
    {
        $d = Env::declare('svc');
        $d->ifPresent('KEYSTORE_PASSWORD')->secret()->describe('Keystore password');
        $d->keystore('partner', "/etc/svc/partner/store.$format", $format)->required()->passwordVar('KEYSTORE_PASSWORD')->describe('Partner keystore');
        return $d;
    }

    public function testPkcs12Keystore(): void
    {
        $this->put('/etc/svc/partner/store.pkcs12', Certs::pkcs12('changeit'));
        self::assertSame([], $this->codes($this->keystore('pkcs12'), ['KEYSTORE_PASSWORD' => 'changeit']));
        self::assertSame([['partner', 'keystore_unreadable']], $this->codes($this->keystore('pkcs12'), ['KEYSTORE_PASSWORD' => 'wrong']));
    }

    public function testJksIntegrity(): void
    {
        $this->put('/etc/svc/partner/store.jks', Certs::jks('changeit'));
        self::assertSame([], $this->codes($this->keystore('jks'), ['KEYSTORE_PASSWORD' => 'changeit']));
        self::assertSame([['partner', 'keystore_unreadable']], $this->codes($this->keystore('jks'), ['KEYSTORE_PASSWORD' => 'wrong']));
        self::assertSame([['partner', 'keystore_unreadable']], $this->codes($this->keystore('jks')), 'unset password is empty');
    }

    public function testTextFile(): void
    {
        $d = Env::declare('svc');
        $d->text('license', '/etc/svc/license/key.txt')->required()->pattern('^[A-Z0-9]{5}(-[A-Z0-9]{5}){3}\n?$')->describe('Licence key');
        $this->put('/etc/svc/license/key.txt', "ABCDE-12345-FGHIJ-67890\n");
        self::assertSame([], $this->codes($d));
        self::assertSame("ABCDE-12345-FGHIJ-67890\n", $d->load($this->env())->file('license')?->value);
        $this->put('/etc/svc/license/key.txt', "abcde\n");
        self::assertSame([['license', 'pattern_mismatch']], $this->codes($d));
    }

    public function testFileAndVarViolationsTogether(): void
    {
        $d = $this->routes();
        $d->required('DATABASE_URL')->isUrl()->secret()->describe('Database URL');
        self::assertSame([['DATABASE_URL', 'missing_required'], ['routes', 'file_missing']], $this->codes($d));
    }
}
