<?php

/*
 * This file is part of the Symfony MakerBundle package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\MakerBundle\Tests\Maker;

use Symfony\Bundle\MakerBundle\Maker\MakeCi;
use Symfony\Bundle\MakerBundle\Test\MakerTestCase;
use Symfony\Bundle\MakerBundle\Test\MakerTestRunner;

/**
 * @author Sébastien Jean <sebastien.jean76@gmail.com>
 */
final class MakeCiTest extends MakerTestCase
{
    protected function getMakerClass(): string
    {
        return MakeCi::class;
    }

    public static function getTestDetails(): \Generator
    {
        yield 'it_generates_a_github_actions_workflow' => [self::buildMakerTest()
            ->run(static function (MakerTestRunner $runner) {
                $runner->runMaker([
                    'github', // the CI platform
                    '8.4', // the PHP version
                ]);

                self::assertFileExists($runner->getPath('.github/workflows/ci.yml'));

                $workflow = $runner->readYaml('.github/workflows/ci.yml');

                self::assertSame('CI', $workflow['name']);
                self::assertSame(['APP_ENV' => 'test', 'PHP_VERSION' => '8.4'], $workflow['env']);
                self::assertSame(['contents' => 'read'], $workflow['permissions']);
                self::assertTrue($workflow['concurrency']['cancel-in-progress']);

                // the test application requires PHPUnit, but neither PHPStan nor PHP CS Fixer
                self::assertSame(['tests', 'lint'], array_keys($workflow['jobs']));

                $lintSteps = array_column($workflow['jobs']['lint']['steps'], 'run', 'name');

                self::assertSame('composer validate --strict --no-check-publish', $lintSteps['Validate Composer files']);
                self::assertSame('php bin/console lint:container --env=prod', $lintSteps['Lint container']);
                self::assertSame('symfony lsp:check --format=github', $lintSteps['Symfony diagnostics']);
            }),
        ];

        yield 'it_generates_a_gitlab_ci_file' => [self::buildMakerTest()
            ->run(static function (MakerTestRunner $runner) {
                $runner->runMaker([
                    'gitlab', // the CI platform
                    '8.3', // the PHP version
                ]);

                self::assertFileExists($runner->getPath('.gitlab-ci.yml'));

                $pipeline = $runner->readYaml('.gitlab-ci.yml');

                self::assertSame(['ci'], $pipeline['stages']);
                self::assertSame('php:8.3-cli', $pipeline['ci']['image']);
                self::assertContains('php bin/console lint:container', $pipeline['ci']['script']);
            }),
        ];

        yield 'it_only_generates_the_jobs_of_the_required_packages' => [self::buildMakerTest()
            ->run(static function (MakerTestRunner $runner) {
                self::writeComposerRequirements($runner, [
                    'php' => '>=8.2',
                    'doctrine/doctrine-bundle' => '^2.13',
                    'doctrine/doctrine-migrations-bundle' => '^3.4',
                    'symfony/asset-mapper' => '^7.4',
                    'symfony/translation' => '^7.4',
                    'symfony/twig-bundle' => '^7.4',
                ], [
                    'php-cs-fixer/shim' => '^3.68',
                    'phpstan/phpstan' => '^2.0',
                    'phpunit/phpunit' => '^11.5',
                ]);

                $runner->runMaker([], '--platform=github --php-version=8.5 --database=mysql --no-interaction');

                $workflow = $runner->readYaml('.github/workflows/ci.yml');

                self::assertSame(['tests', 'lint', 'php-cs-fixer', 'phpstan'], array_keys($workflow['jobs']));
                self::assertSame(['mysql'], array_keys($workflow['jobs']['tests']['services']));
                self::assertStringContainsString('mysql://app:password@127.0.0.1:3306/app', $workflow['env']['DATABASE_URL']);

                $testsSteps = array_column($workflow['jobs']['tests']['steps'], 'run', 'name');

                self::assertStringContainsString('doctrine:migrations:migrate --no-interaction', $testsSteps['Setup database']);
                self::assertSame('vendor/bin/phpunit', $testsSteps['Run tests']);

                $lintSteps = array_column($workflow['jobs']['lint']['steps'], 'run', 'name');

                self::assertSame('php bin/console lint:twig --env=prod', $lintSteps['Lint Twig']);
                self::assertSame('php bin/console lint:translations', $lintSteps['Lint translations']);
                self::assertSame('php bin/console asset-map:compile --env=prod', $lintSteps['Compile assets']);
            }),
        ];

        yield 'it_omits_the_jobs_of_the_missing_packages' => [self::buildMakerTest()
            ->run(static function (MakerTestRunner $runner) {
                self::writeComposerRequirements($runner, ['php' => '>=8.2'], []);

                $runner->runMaker([], '--platform=github --php-version=8.4 --no-interaction');

                $workflow = $runner->readYaml('.github/workflows/ci.yml');

                self::assertSame(['lint'], array_keys($workflow['jobs']));
                self::assertArrayNotHasKey('DATABASE_URL', $workflow['env']);

                $lintSteps = array_column($workflow['jobs']['lint']['steps'], 'run', 'name');

                self::assertArrayNotHasKey('Lint Twig', $lintSteps);
                self::assertArrayNotHasKey('Lint translations', $lintSteps);
                self::assertArrayNotHasKey('Compile assets', $lintSteps);
            }),
        ];

        yield 'it_guesses_the_database_from_the_dotenv_file' => [self::buildMakerTest()
            ->run(static function (MakerTestRunner $runner) {
                self::writeComposerRequirements($runner, [
                    'php' => '>=8.2',
                    'doctrine/doctrine-bundle' => '^2.13',
                ], [
                    'phpunit/phpunit' => '^11.5',
                ]);

                $runner->writeFile('.env', file_get_contents($runner->getPath('.env'))."\nDATABASE_URL=\"mysql://app:!ChangeMe!@127.0.0.1:3306/app\"\n");

                $runner->runMaker([], '--platform=github --php-version=8.4 --no-interaction');

                $workflow = $runner->readYaml('.github/workflows/ci.yml');

                self::assertSame(['mysql'], array_keys($workflow['jobs']['tests']['services']));
            }),
        ];

        yield 'it_defaults_to_postgres_without_a_database_url' => [self::buildMakerTest()
            ->run(static function (MakerTestRunner $runner) {
                self::writeComposerRequirements($runner, [
                    'php' => '>=8.2',
                    'doctrine/doctrine-bundle' => '^2.13',
                ], [
                    'phpunit/phpunit' => '^11.5',
                ]);

                $runner->runMaker([], '--platform=github --php-version=8.4 --no-interaction');

                $workflow = $runner->readYaml('.github/workflows/ci.yml');

                self::assertSame(['postgres'], array_keys($workflow['jobs']['tests']['services']));
                self::assertStringContainsString('postgresql://app:password@127.0.0.1:5432/app', $workflow['env']['DATABASE_URL']);
            }),
        ];

        yield 'it_guesses_the_php_version_of_the_project' => [self::buildMakerTest()
            ->run(static function (MakerTestRunner $runner) {
                self::writeComposerRequirements($runner, ['php' => '>=8.1'], []);

                $runner->runMaker([], '--platform=gitlab --no-interaction');

                self::assertSame('php:8.1-cli', $runner->readYaml('.gitlab-ci.yml')['ci']['image']);
            }),
        ];

        yield 'it_fails_without_a_platform' => [self::buildMakerTest()
            ->run(static function (MakerTestRunner $runner) {
                $output = $runner->runMaker([], '--no-interaction', allowedToFail: true);

                self::assertStringContainsString('Provide the CI platform with the "--platform" option', $output);
                self::assertFileDoesNotExist($runner->getPath('.github/workflows/ci.yml'));
                self::assertFileDoesNotExist($runner->getPath('.gitlab-ci.yml'));
            }),
        ];

        yield 'it_fails_with_an_unsupported_platform' => [self::buildMakerTest()
            ->run(static function (MakerTestRunner $runner) {
                $output = $runner->runMaker([], '--platform=jenkins --no-interaction', allowedToFail: true);

                self::assertStringContainsString('"jenkins" is not a supported CI platform', $output);
            }),
        ];

        yield 'it_fails_with_an_unsupported_database' => [self::buildMakerTest()
            ->run(static function (MakerTestRunner $runner) {
                self::writeComposerRequirements($runner, [
                    'php' => '>=8.2',
                    'doctrine/doctrine-bundle' => '^2.13',
                ], []);

                $output = $runner->runMaker([], '--platform=github --database=sqlite --no-interaction', allowedToFail: true);

                self::assertStringContainsString('"sqlite" is not a supported database', $output);
                self::assertFileDoesNotExist($runner->getPath('.github/workflows/ci.yml'));
            }),
        ];

        yield 'it_fails_when_the_file_already_exists' => [self::buildMakerTest()
            ->run(static function (MakerTestRunner $runner) {
                $runner->writeFile('.gitlab-ci.yml', "# Already there\n");

                $output = $runner->runMaker([], '--platform=gitlab --no-interaction', allowedToFail: true);

                self::assertStringContainsString('The file ".gitlab-ci.yml" already exists', $output);
                self::assertSame("# Already there\n", file_get_contents($runner->getPath('.gitlab-ci.yml')));
            }),
        ];
    }

    /**
     * @param array<string, string> $require
     * @param array<string, string> $requireDev
     */
    private static function writeComposerRequirements(MakerTestRunner $runner, array $require, array $requireDev): void
    {
        $composerData = json_decode(file_get_contents($runner->getPath('composer.json')), true, flags: \JSON_THROW_ON_ERROR);

        $composerData['require'] = $require;
        $composerData['require-dev'] = $requireDev;

        $runner->writeFile('composer.json', json_encode($composerData, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
    }
}
