<?php

/*
 * This file is part of the Symfony MakerBundle package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Bundle\MakerBundle\Maker;

use Symfony\Bundle\MakerBundle\ConsoleStyle;
use Symfony\Bundle\MakerBundle\DependencyBuilder;
use Symfony\Bundle\MakerBundle\Exception\RuntimeCommandException;
use Symfony\Bundle\MakerBundle\FileManager;
use Symfony\Bundle\MakerBundle\Generator;
use Symfony\Bundle\MakerBundle\InputConfiguration;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * @author Sébastien Jean <sebastien.jean76@gmail.com>
 *
 * @internal
 */
final class MakeCi extends AbstractMaker
{
    /**
     * @var array<string, array{label: string, path: string, template: string}>
     */
    private const PLATFORMS = [
        'github' => [
            'label' => 'GitHub Actions',
            'path' => '.github/workflows/ci.yml',
            'template' => 'ci/github.tpl.php',
        ],
        'gitlab' => [
            'label' => 'GitLab CI',
            'path' => '.gitlab-ci.yml',
            'template' => 'ci/gitlab.tpl.php',
        ],
    ];

    /**
     * @var array<string, string>
     */
    private const DATABASES = [
        'postgres' => 'PostgreSQL',
        'mysql' => 'MySQL',
        'none' => 'None',
    ];

    public function __construct(private FileManager $fileManager)
    {
    }

    public static function getCommandName(): string
    {
        return 'make:ci';
    }

    public static function getCommandDescription(): string
    {
        return 'Create a CI configuration file for your project';
    }

    public function configureCommand(Command $command, InputConfiguration $inputConfig): void
    {
        $command
            ->addOption('platform', null, InputOption::VALUE_REQUIRED, \sprintf('The CI platform to generate a configuration for (<fg=yellow>%s</>)', implode('</> or <fg=yellow>', array_keys(self::PLATFORMS))))
            ->addOption('php-version', null, InputOption::VALUE_REQUIRED, 'The PHP version the CI runs on (e.g. <fg=yellow>8.4</>)')
            ->addOption('database', null, InputOption::VALUE_REQUIRED, \sprintf('The database service the tests run against (<fg=yellow>%s</>)', implode('</>, <fg=yellow>', array_keys(self::DATABASES))))
            ->setHelp($this->getHelpFileContents('MakeCi.txt'))
        ;
    }

    public function interact(InputInterface $input, ConsoleStyle $io, Command $command): void
    {
        if (!$input->getOption('platform')) {
            $choices = [];

            foreach (self::PLATFORMS as $name => $platform) {
                $choices[$name] = $platform['label'];
            }

            $input->setOption('platform', $io->choice('Which CI platform do you want to use?', $choices, array_key_first($choices)));
        }

        if (!$input->getOption('php-version')) {
            $input->setOption('php-version', $io->ask('Which PHP version should the CI run on?', $this->guessPhpVersion()));
        }

        // without Doctrine, the generated file does not need a database service
        if (!$input->getOption('database') && isset($this->getRequiredPackages()['doctrine/doctrine-bundle'])) {
            $input->setOption('database', $io->choice('Which database should the tests run against?', self::DATABASES, $this->guessDatabase()));
        }
    }

    public function generate(InputInterface $input, ConsoleStyle $io, Generator $generator): void
    {
        $platform = $this->getPlatform($input->getOption('platform'));
        $phpVersion = $input->getOption('php-version') ?: $this->guessPhpVersion();

        if (!preg_match('/^\d+\.\d+$/', $phpVersion)) {
            throw new RuntimeCommandException(\sprintf('"%s" is not a valid PHP version: use the "--php-version" option with a major and a minor version (e.g. 8.4).', $phpVersion));
        }

        if ($this->fileManager->fileExists($platform['path'])) {
            throw new RuntimeCommandException(\sprintf('The file "%s" already exists: remove or rename it before running this command.', $platform['path']));
        }

        $packages = $this->getRequiredPackages();
        $withDoctrine = isset($packages['doctrine/doctrine-bundle']);

        $generator->generateFile($platform['path'], $platform['template'], [
            'php_version' => $phpVersion,
            'database' => $withDoctrine ? $this->getDatabase($input->getOption('database')) : null,
            'with_doctrine' => $withDoctrine,
            'with_migrations' => isset($packages['doctrine/doctrine-migrations-bundle']),
            'with_phpunit' => isset($packages['phpunit/phpunit']) || isset($packages['symfony/phpunit-bridge']),
            'with_twig' => isset($packages['symfony/twig-bundle']),
            'with_translation' => isset($packages['symfony/translation']),
            'with_asset_mapper' => isset($packages['symfony/asset-mapper']),
            'with_php_cs_fixer' => isset($packages['friendsofphp/php-cs-fixer']) || isset($packages['php-cs-fixer/shim']),
            'with_phpstan' => isset($packages['phpstan/phpstan']),
        ]);

        $generator->writeChanges();

        $this->writeSuccessMessage($io);

        $io->text([
            'Next:',
            \sprintf('- Open <info>%s</info> and adapt it to your project (branch names, services, deployment, etc.)', $platform['path']),
            '- Commit it and push: the pipeline runs on your next push.',
        ]);
        $io->newLine();
    }

    public function configureDependencies(DependencyBuilder $dependencies): void
    {
        // the generated file is not PHP code: there is nothing to require
    }

    /**
     * @return array{label: string, path: string, template: string}
     */
    private function getPlatform(?string $name): array
    {
        if (!$name) {
            throw new RuntimeCommandException(\sprintf('Provide the CI platform with the "--platform" option (%s).', implode(', ', array_keys(self::PLATFORMS))));
        }

        $name = strtolower($name);

        if (!isset(self::PLATFORMS[$name])) {
            throw new RuntimeCommandException(\sprintf('"%s" is not a supported CI platform: use the "--platform" option with one of %s.', $name, implode(', ', array_keys(self::PLATFORMS))));
        }

        return self::PLATFORMS[$name];
    }

    /**
     * @return string|null the database service to run the tests against, or null for none
     */
    private function getDatabase(?string $name): ?string
    {
        if (!$name) {
            return $this->guessDatabase();
        }

        $name = strtolower($name);

        if (!isset(self::DATABASES[$name])) {
            throw new RuntimeCommandException(\sprintf('"%s" is not a supported database: use the "--database" option with one of %s.', $name, implode(', ', array_keys(self::DATABASES))));
        }

        return 'none' === $name ? null : $name;
    }

    /**
     * The database of the DATABASE_URL environment variable, PostgreSQL by default.
     */
    private function guessDatabase(): string
    {
        if (!$this->fileManager->fileExists('.env')) {
            return 'postgres';
        }

        if (!preg_match('/^\s*DATABASE_URL\s*=\s*["\']?(\w+):/m', $this->fileManager->getFileContents('.env'), $matches)) {
            return 'postgres';
        }

        return match (strtolower($matches[1])) {
            'mysql', 'mariadb', 'pdo_mysql' => 'mysql',
            default => 'postgres',
        };
    }

    /**
     * The PHP version required by the project, or the one running this command.
     */
    private function guessPhpVersion(): string
    {
        $requirement = $this->getComposerData()['require']['php'] ?? null;

        if (\is_string($requirement) && preg_match('/(\d+\.\d+)/', $requirement, $matches)) {
            return $matches[1];
        }

        return \sprintf('%d.%d', \PHP_MAJOR_VERSION, \PHP_MINOR_VERSION);
    }

    /**
     * Packages required by the project, both in "require" and "require-dev".
     *
     * @return array<string, string>
     */
    private function getRequiredPackages(): array
    {
        $composerData = $this->getComposerData();

        $packages = array_merge(
            \is_array($composerData['require'] ?? null) ? $composerData['require'] : [],
            \is_array($composerData['require-dev'] ?? null) ? $composerData['require-dev'] : [],
        );

        return array_change_key_case($packages);
    }

    /**
     * @return array<string, mixed>
     */
    private function getComposerData(): array
    {
        if (!$this->fileManager->fileExists('composer.json')) {
            return [];
        }

        try {
            $data = json_decode($this->fileManager->getFileContents('composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($data) ? $data : [];
    }
}
