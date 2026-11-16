<?php

declare(strict_types=1);

namespace Docuconf\Tests;

use Docuconf\ConfigurationError;
use Docuconf\Contract;
use Docuconf\DeclarationError;
use Docuconf\Violation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Contract-first requiredIf (SPEC §4.2.1), roles (SPEC §4.9) and includes (SPEC §4.8). */
final class ConditionalTest extends TestCase
{
    /**
     * @param array<string, mixed> $vars
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function contract(array $vars, array $extra = []): array
    {
        return array_replace(['apiVersion' => 'docuconf.dev/v1alpha1', 'kind' => 'ConfigContract', 'metadata' => ['name' => 'relay'], 'vars' => $vars], $extra);
    }

    /** @return array<string, mixed> */
    private static function tls(): array
    {
        return self::contract([
            'TLS_ENABLED' => ['type' => 'bool', 'description' => 'Serve HTTPS', 'default' => false],
            'TLS_CLIENT_CA_SUBJECT' => ['type' => 'string', 'description' => 'Subject of the client CA', 'requiredIf' => ['TLS_ENABLED' => true]],
            'LOG_FORMAT' => ['type' => 'enum', 'description' => 'Log output format', 'values' => ['text', 'json'], 'default' => 'text'],
            'LOG_SCHEMA_URL' => ['type' => 'url', 'description' => 'Schema of the logs', 'requiredIf' => ['LOG_FORMAT' => 'json']],
            'WORKERS' => ['type' => 'int', 'description' => 'Worker count', 'default' => 1],
            'POOL_NAME' => ['type' => 'string', 'description' => 'Pool name', 'requiredIf' => ['WORKERS' => 4]],
        ], ['files' => ['allowlist' => [
            'type' => 'text', 'description' => 'Client names allowed', 'path' => '/etc/relay/allowlist/allow.txt', 'requiredIf' => ['TLS_ENABLED' => true],
        ]]]);
    }

    /**
     * @param array<string, string> $env
     * @return list<Violation>
     */
    private static function violations(Contract $c, array $env): array
    {
        $env['DOCUCONF_FILE_ROOT'] = sys_get_temp_dir() . '/docuconf-conditional-' . bin2hex(random_bytes(4));
        return $c->check($env)->violations;
    }

    public function testAMissingConditionalVariableSaysWhichConditionRequiresIt(): void
    {
        $v = self::violations(Contract::fromJson(self::tls()), ['TLS_ENABLED' => 'true', 'LOG_FORMAT' => 'json', 'WORKERS' => '4']);
        $got = array_map(fn (Violation $v) => (string) $v, $v);
        sort($got);
        self::assertSame([
            'LOG_SCHEMA_URL [missing_required]: is required when LOG_FORMAT is "json"',
            'POOL_NAME [missing_required]: is required when WORKERS is 4',
            'TLS_CLIENT_CA_SUBJECT [missing_required]: is required when TLS_ENABLED is true',
            'allowlist [file_missing]: ' . $this->fileRootOf($v) . '/etc/relay/allowlist/allow.txt does not exist, and is required when TLS_ENABLED is true',
        ], $got);
    }

    /** @param list<Violation> $v */
    private function fileRootOf(array $v): string
    {
        foreach ($v as $violation) {
            if ($violation->input === 'allowlist') {
                return substr($violation->message, 0, (int) strpos($violation->message, '/etc/relay'));
            }
        }
        return '';
    }

    public function testConditionsOffMakeNothingRequired(): void
    {
        $values = Contract::fromJson(self::tls())->load(['DOCUCONF_FILE_ROOT' => sys_get_temp_dir() . '/docuconf-none']);
        self::assertNull($values->get('TLS_CLIENT_CA_SUBJECT'));
        self::assertNull($values->file('allowlist'));
    }

    public function testTheConditionComparesTheLoadedValueNotTheRawOne(): void
    {
        // "TRUE" and "+4" load as true and 4.
        $v = self::violations(Contract::fromJson(self::tls()), ['TLS_ENABLED' => 'TRUE', 'WORKERS' => '+4', 'TLS_CLIENT_CA_SUBJECT' => 'CN=CA']);
        self::assertSame(['POOL_NAME', 'allowlist'], array_map(fn (Violation $v) => $v->input, $v));
    }

    public function testAConditionVariableWithAViolationMakesNothingRequired(): void
    {
        $v = self::violations(Contract::fromJson(self::tls()), ['TLS_ENABLED' => 'yes']);
        self::assertSame(['TLS_ENABLED/invalid_type'], array_map(fn (Violation $v) => "{$v->input}/{$v->code}", $v));
    }

    public function testAProfileDefaultTurnsAConditionOn(): void
    {
        $c = self::tls();
        $c['vars']['APP_ENV'] = ['type' => 'string', 'description' => 'The profile', 'default' => 'dev'];
        $c['profiles'] = ['selector' => 'APP_ENV', 'default' => 'dev', 'defaults' => ['prod' => ['TLS_ENABLED' => true]]];
        $v = self::violations(Contract::fromJson($c), ['APP_ENV' => 'prod']);
        self::assertSame(['TLS_CLIENT_CA_SUBJECT', 'allowlist'], array_map(fn (Violation $v) => $v->input, $v));
        self::assertSame([], self::violations(Contract::fromJson($c), ['APP_ENV' => 'prod', 'TLS_ENABLED' => 'false']));
    }

    /** @return array<string, mixed> */
    private static function shop(): array
    {
        return self::contract([
            'DOCUCONF_ROLE' => ['type' => 'enum', 'description' => 'The role this workload runs', 'required' => true, 'values' => ['web', 'worker']],
            'ALLOWED_ORIGINS' => ['type' => 'list', 'items' => 'string', 'description' => 'Origins the web server accepts', 'required' => true, 'roles' => ['web']],
            'QUEUES' => ['type' => 'list', 'items' => 'string', 'description' => 'Queues the worker takes', 'required' => true, 'roles' => ['worker']],
            'CACHE' => ['type' => 'bool', 'description' => 'Cache responses', 'default' => true],
            'CACHE_URL' => ['type' => 'url', 'description' => 'Cache server', 'roles' => ['web'], 'requiredIf' => ['CACHE' => true]],
        ], ['roles' => ['web', 'worker']]);
    }

    public function testARoleNarrowsWhatIsRequired(): void
    {
        $c = Contract::fromJson(self::shop());
        self::assertSame(['web', 'worker'], $c->roles());
        $codes = fn (array $env) => array_map(fn (Violation $v) => "{$v->input}/{$v->code}", self::violations($c, $env));
        self::assertSame(['QUEUES/missing_required'], $codes(['DOCUCONF_ROLE' => 'worker']));
        self::assertSame(['ALLOWED_ORIGINS/missing_required', 'CACHE_URL/missing_required'], $codes(['DOCUCONF_ROLE' => 'web']));
        // With no role, or an unknown one, the role is the only problem.
        self::assertSame(['DOCUCONF_ROLE/missing_required'], $codes([]));
        self::assertSame(['DOCUCONF_ROLE/not_in_enum'], $codes(['DOCUCONF_ROLE' => 'cron']));
    }

    public function testAFileOfAnotherRoleIsOptional(): void
    {
        $c = self::shop();
        $c['files'] = ['robots' => ['type' => 'text', 'description' => 'robots.txt', 'required' => true, 'roles' => ['web'], 'path' => '/etc/shop/robots/robots.txt']];
        $codes = fn (array $env) => array_map(fn (Violation $v) => "{$v->input}/{$v->code}", self::violations(Contract::fromJson($c), $env));
        self::assertSame([], $codes(['DOCUCONF_ROLE' => 'worker', 'QUEUES' => 'a']));
        self::assertSame(['robots/file_missing'], $codes(['DOCUCONF_ROLE' => 'web', 'ALLOWED_ORIGINS' => 'x', 'CACHE' => 'false']));
    }

    public function testIncludesAreReadAndExported(): void
    {
        $c = Contract::fromJson(self::contract(
            ['GOMAXPROCS' => ['type' => 'int', 'description' => 'Maximum number of OS threads', 'min' => 1]],
            ['includes' => ['go-runtime@1']],
        ));
        self::assertSame(['go-runtime@1'], $c->includes());
        self::assertStringContainsString('includes: ["go-runtime@1"]', $c->toCue());
    }

    public function testRolesAndRequiredIfAreExported(): void
    {
        $cue = Contract::fromJson(self::shop())->toCue();
        self::assertStringContainsString('roles: ["web", "worker"]', $cue);
        self::assertMatchesRegularExpression('/CACHE_URL: \{[^}]*requiredIf: \{\s*CACHE: true\s*\}\s*roles: \["web"\]/', $cue);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function badContracts(): iterable
    {
        $flag = ['type' => 'bool', 'description' => 'A flag'];
        $input = fn (array $extra) => ['type' => 'string', 'description' => 'A conditional input'] + $extra;
        yield 'two conditions' => [self::contract(['F' => $flag, 'G' => $flag, 'X' => $input(['requiredIf' => ['F' => true, 'G' => true]])]),
            'X: requiredIf must name one variable and its value: {VAR: value}'];
        yield 'a float value' => [self::contract(['F' => $flag, 'X' => $input(['requiredIf' => ['F' => 1.5]])]),
            'X: requiredIf must name one variable and a string, integer or boolean value'];
        yield 'undeclared' => [self::contract(['X' => $input(['requiredIf' => ['NOPE' => true]])]),
            'X: requiredIf must name another declared variable, not "NOPE"'];
        yield 'itself' => [self::contract(['X' => $input(['requiredIf' => ['X' => 'a']])]),
            'X: requiredIf must name another declared variable, not "X"'];
        yield 'a secret' => [self::contract(['S' => ['type' => 'string', 'description' => 'A secret', 'secret' => true], 'X' => $input(['requiredIf' => ['S' => 'a']])]),
            'X: requiredIf names S, which is not a plain string, int, bool or enum variable'];
        yield 'a duration' => [self::contract(['D' => ['type' => 'duration', 'description' => 'A timeout'], 'X' => $input(['requiredIf' => ['D' => '1m']])]),
            'X: requiredIf names D, which is not a plain string, int, bool or enum variable'];
        yield 'a bool for an int' => [self::contract(['N' => ['type' => 'int', 'description' => 'A number'], 'X' => $input(['requiredIf' => ['N' => true]])]),
            'X: requiredIf: N is true is not a value N can have'];
        yield 'an int for a bool' => [self::contract(['F' => $flag, 'X' => $input(['requiredIf' => ['F' => 1]])]),
            'X: requiredIf: F is 1 is not a value F can have'];
        yield 'not one of the values' => [self::contract(['E' => ['type' => 'enum', 'description' => 'A choice', 'values' => ['a', 'b']], 'X' => $input(['requiredIf' => ['E' => 'c']])]),
            'X: requiredIf: E is "c" is not a value E can have: '];
        yield 'out of range' => [self::contract(['N' => ['type' => 'int', 'description' => 'A number', 'max' => 5], 'X' => $input(['requiredIf' => ['N' => 9]])]),
            'X: requiredIf: N is 9 is not a value N can have: '];
        yield 'required' => [self::contract(['F' => $flag, 'X' => $input(['required' => true, 'requiredIf' => ['F' => true]])]),
            'X: an input with requiredIf cannot be required or have a default'];
        yield 'a default' => [self::contract(['F' => $flag, 'X' => $input(['default' => 'a', 'requiredIf' => ['F' => true]])]),
            'X: an input with requiredIf cannot be required or have a default'];
        yield 'deprecated' => [self::contract(['F' => $flag, 'X' => $input(['deprecated' => ['message' => 'Use Y'], 'requiredIf' => ['F' => true]])]),
            'X: a conditionally required input cannot be deprecated'];
        yield 'a file on a secret' => [self::contract(['S' => ['type' => 'string', 'description' => 'A secret', 'secret' => true]], ['files' => ['allow' => [
            'type' => 'text', 'description' => 'An allowlist', 'path' => '/etc/app/allow/a.txt', 'requiredIf' => ['S' => 'x'],
        ]]]), 'file allow: requiredIf names S, which is not a plain string, int, bool or enum variable'];
        $role = ['type' => 'enum', 'description' => 'The role', 'required' => true, 'values' => ['web', 'worker']];
        yield 'empty roles' => [self::contract(['DOCUCONF_ROLE' => $role], ['roles' => []]),
            'roles must be a list of at least one role name'];
        yield 'a role that is not a DNS label' => [self::contract(['DOCUCONF_ROLE' => $role], ['roles' => ['Web', 'worker']]),
            'role "Web" must be a DNS label'];
        yield 'a role listed twice' => [self::contract(['DOCUCONF_ROLE' => $role], ['roles' => ['web', 'worker', 'web']]),
            'role web is listed twice'];
        yield 'input roles without contract roles' => [self::contract(['X' => $input(['roles' => ['web']])]),
            'X: has roles, but the contract declares none'];
        yield 'an input role not in the contract' => [self::contract(['DOCUCONF_ROLE' => $role, 'X' => $input(['roles' => ['cron']])], ['roles' => ['web', 'worker']]),
            "X: role cron is not one of the contract's roles, web, worker"];
        yield 'a file role not in the contract' => [self::contract(['DOCUCONF_ROLE' => $role], ['roles' => ['web', 'worker'], 'files' => ['allow' => [
            'type' => 'text', 'description' => 'An allowlist', 'path' => '/etc/app/allow/a.txt', 'roles' => ['cron'],
        ]]]), "file allow: role cron is not one of the contract's roles, web, worker"];
        yield 'no DOCUCONF_ROLE' => [self::contract([], ['roles' => ['web', 'worker']]),
            'DOCUCONF_ROLE: a contract with roles declares it as a required enum whose values are the roles, web, worker, used by every role'];
        yield 'DOCUCONF_ROLE not required' => [self::contract(['DOCUCONF_ROLE' => ['required' => false] + $role], ['roles' => ['web', 'worker']]),
            'DOCUCONF_ROLE: a contract with roles declares it'];
        yield 'DOCUCONF_ROLE values differ' => [self::contract(['DOCUCONF_ROLE' => ['values' => ['worker', 'web']] + $role], ['roles' => ['web', 'worker']]),
            'DOCUCONF_ROLE: a contract with roles declares it'];
        yield 'DOCUCONF_ROLE with roles' => [self::contract(['DOCUCONF_ROLE' => ['roles' => ['web']] + $role], ['roles' => ['web', 'worker']]),
            'DOCUCONF_ROLE: a contract with roles declares it'];
        yield 'includes not a list' => [self::contract([], ['includes' => 'otel@1']),
            'includes must be a list of fragment names, such as otel@1'];
        yield 'a fragment without a major version' => [self::contract([], ['includes' => ['otel']]),
            'includes: "otel" is not a fragment name, name@major'];
        yield 'a fragment listed twice' => [self::contract([], ['includes' => ['otel@1', 'otel@1']]),
            'includes: otel@1 is listed twice'];
    }

    /** @param array<string, mixed> $contract */
    #[DataProvider('badContracts')]
    public function testTheReaderRefuses(array $contract, string $expected): void
    {
        try {
            Contract::fromJson($contract);
            self::fail('expected a DeclarationError');
        } catch (DeclarationError $e) {
            self::assertStringContainsString($expected, $e->getMessage());
        }
    }

    public function testLoadThrowsWithTheConditionInTheMessage(): void
    {
        try {
            Contract::fromJson(self::tls())->load(['LOG_FORMAT' => 'json', 'DOCUCONF_FILE_ROOT' => sys_get_temp_dir() . '/docuconf-none', 'DOCUCONF_TERMINATION_LOG' => '/dev/null']);
            self::fail('expected a ConfigurationError');
        } catch (ConfigurationError $e) {
            self::assertStringContainsString('LOG_SCHEMA_URL', $e->getMessage());
            self::assertStringContainsString('is required when LOG_FORMAT is "json"', $e->getMessage());
        }
    }
}
