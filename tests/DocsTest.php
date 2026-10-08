<?php

declare(strict_types=1);

namespace Docuconf\Tests;

use Docuconf\Contract;
use Docuconf\Declaration;
use Docuconf\DeclarationError;
use Docuconf\Docs;
use Docuconf\Env;
use Docuconf\Laravel\Env as LaravelEnv;
use Docuconf\Tests\Support\CueVet;
use PHPUnit\Framework\TestCase;

/** description and details from PHPDoc comments (SPEC §4.2, §14.7). */
final class DocsTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function exportJson(Declaration $env): array
    {
        /** @var array<string, mixed> */
        return \Docuconf\Export\Exporter::toArray($env->spec());
    }

    private static function documented(): Declaration
    {
        return require __DIR__ . '/Fixtures/documented.php';
    }

    public function testDescriptionAndDetailsFromThePhpDocComment(): void
    {
        $vars = self::exportJson(self::documented())['vars'];
        self::assertSame('HTTP listen port', $vars['PORT']['description']);
        self::assertSame(
            "Behind the mesh, keep the default. The sidecar forwards `Ingress`\ntraffic here; see `PORT` in the chart.",
            $vars['PORT']['details'],
        );
        self::assertSame(['type', 'description', 'details'], array_slice(array_keys($vars['PORT']), 0, 3));
        self::assertArrayNotHasKey('details', $vars['PLAIN']);
    }

    public function testListsCodeBlocksAndTagsBecomeCommonMark(): void
    {
        $region = self::exportJson(self::documented())['vars']['REGION'];
        self::assertSame('Cloud region for object storage', $region['description']);
        self::assertSame(
            "Change it together with the bucket:\n\n- `eu-west-1` for Europe\n- `us-east-1` for the US\n\n"
            . "```sh\nREGION=us-east-1 php artisan serve\n```\n\nSee <https://example.com/regions>.",
            $region['details'],
        );
    }

    public function testExplicitDescriptionAndDetailsWin(): void
    {
        $workers = self::exportJson(self::documented())['vars']['WORKERS'];
        self::assertSame('Worker processes', $workers['description']);
        self::assertSame('One per *core*.', $workers['details']);
    }

    public function testFileInputsReadTheirComment(): void
    {
        $license = self::exportJson(self::documented())['files']['license'];
        self::assertSame('Licence key file', $license['description']);
        self::assertSame('Issued per customer; rotate it yearly.', $license['details']);
    }

    public function testLaravelConfigEntriesReadTheirComment(): void
    {
        LaravelEnv::flush();
        try {
            $config = require __DIR__ . '/Fixtures/laravel_documented.php';
            self::assertSame(8080, $config['port']);
            $vars = self::exportJson(LaravelEnv::declaration('svc'))['vars'];
        } finally {
            LaravelEnv::flush();
        }
        self::assertSame('HTTP listen port', $vars['DOC_PORT']['description']);
        self::assertSame('Behind the mesh, keep the default.', $vars['DOC_PORT']['details']);
        self::assertSame('Request timeout', $vars['DOC_TIMEOUT']['description']);
        self::assertArrayNotHasKey('details', $vars['DOC_TIMEOUT']);
        self::assertSame('One per core.', $vars['DOC_WORKERS']['details']);
    }

    public function testSplitAndConversion(): void
    {
        self::assertSame(['One line', null], Docs::split('/** One line. */'));
        self::assertSame(
            ['Wrapped over lines', "Rest.\n\nMore."],
            Docs::split("/**\n * Wrapped\n * over lines.\n *\n * Rest.\n *\n * More.\n */"),
        );
        self::assertSame(['', null], Docs::split('/** @var int */'));
        self::assertSame(
            'Use `Foo::bar()`, [the guide](https://example.com) and `{@link kept}`.',
            Docs::toMarkdown('Use {@see Foo::bar()}, {@link https://example.com the guide} and `{@link kept}`.{@inheritDoc}'),
        );
        self::assertSame('Text.', Docs::toMarkdown("Text.\n@param int \$x dropped\n  and its continuation"));
    }

    public function testMissingDescriptionFails(): void
    {
        $env = Env::declare('svc');
        $env->ifPresent('NOTHING')->isInteger()->default(3);
        $this->expectException(DeclarationError::class);
        $this->expectExceptionMessage('NOTHING: needs a description of at least 5 characters');
        $env->export();
    }

    public function testBlankDetailsFail(): void
    {
        $env = Env::declare('svc');
        $env->ifPresent('BLANK')->default('x')->describe('Has blank details')->details("  \n ");
        $this->expectException(DeclarationError::class);
        $this->expectExceptionMessage('BLANK: details must not be blank');
        $env->export();
    }

    public function testDetailsOver4000CodePointsFail(): void
    {
        $ok = Env::declare('svc');
        $ok->ifPresent('MOST')->default('x')->describe('At the limit')->details(str_repeat('日本', 2000));
        self::assertSame(4000, mb_strlen(self::exportJson($ok)['vars']['MOST']['details']));

        $env = Env::declare('svc');
        $env->ifPresent('LONG')->default('x')->describe('Has long details')->details(str_repeat('日本', 2000) . '!');
        $this->expectException(DeclarationError::class);
        $this->expectExceptionMessage('LONG: details are 4001 characters; at most 4000 are allowed');
        $env->export();
    }

    public function testDetailsAreNotReadAtRuntime(): void
    {
        $env = Env::declare('svc');
        /**
         * HTTP listen port.
         *
         * Keep the default.
         */
        $env->ifPresent('PORT')->isInteger()->default(8080);
        self::assertSame(9090, $env->load(['PORT' => '9090'])->get('PORT'));
    }

    public function testContractFirstLoadsDetailsAndChecksThem(): void
    {
        $contract = [
            'apiVersion' => 'docuconf.dev/v1alpha1',
            'kind' => 'ConfigContract',
            'metadata' => ['name' => 'svc'],
            'vars' => ['PORT' => ['type' => 'int', 'description' => 'HTTP listen port', 'details' => "Keep it.\n\n- a\n- b", 'default' => 8080]],
        ];
        self::assertSame(1, Contract::fromJson($contract)->load(['PORT' => '1'])->get('PORT'));
        $contract['vars']['PORT']['details'] = ' ';
        $this->expectException(DeclarationError::class);
        $this->expectExceptionMessage('details must not be blank');
        Contract::fromJson($contract)->load([]);
    }

    public function testDetailsPassTheMetaSchema(): void
    {
        $cue = self::documented()->export();
        self::assertStringContainsString('details:', $cue);
        CueVet::vet($cue);
    }
}
