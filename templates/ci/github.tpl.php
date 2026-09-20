name: CI

# Global environment variables.
env:
  APP_ENV: test
  # For an application, test the PHP version we actually use in production.
  PHP_VERSION: '<?= $php_version ?>'
<?php if ('postgres' === $database): ?>

  # Point Doctrine at the PostgreSQL service below.
  DATABASE_URL: 'postgresql://app:password@127.0.0.1:5432/app?serverVersion=16&charset=utf8'

  # Using MySQL instead? Comment out PostgreSQL above and use this.
  # DATABASE_URL: 'mysql://app:password@127.0.0.1:3306/app?serverVersion=8.0.32&charset=utf8mb4'
<?php elseif ('mysql' === $database): ?>

  # Point Doctrine at the MySQL service below.
  DATABASE_URL: 'mysql://app:password@127.0.0.1:3306/app?serverVersion=8.0.32&charset=utf8mb4'

  # Using PostgreSQL instead? Comment out MySQL above and use this.
  # DATABASE_URL: 'postgresql://app:password@127.0.0.1:5432/app?serverVersion=16&charset=utf8'
<?php endif; ?>

# Run for every pull request, plus the final commit that lands on main.
on:
  push:
    branches:
      - main
  pull_request:

# CI only needs to read the repository.
permissions:
  contents: read

# If we push again, cancel the now-outdated run.
concurrency:
  group: ${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true

jobs:
<?php if ($with_phpunit): ?>
  tests:
    name: Tests
    runs-on: ubuntu-latest
<?php if ('postgres' === $database): ?>

    # Run PostgreSQL for this job.
    services:
      postgres:
        image: postgres:16-alpine
        env:
          POSTGRES_DB: app
          POSTGRES_USER: app
          POSTGRES_PASSWORD: password
        ports:
          - 5432:5432
        options: >-
          --health-cmd "pg_isready -d app -U app"
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

      # Using MySQL instead? Comment out the PostgreSQL service above and use this.
      # mysql:
      #   image: mysql:8.0
      #   env:
      #     MYSQL_DATABASE: app
      #     MYSQL_USER: app
      #     MYSQL_PASSWORD: password
      #     MYSQL_ROOT_PASSWORD: password
      #   ports:
      #     - 3306:3306
      #   options: >-
      #     --health-cmd "mysqladmin ping -h 127.0.0.1 -uapp -ppassword"
      #     --health-interval 10s
      #     --health-timeout 5s
      #     --health-retries 5
<?php elseif ('mysql' === $database): ?>

    # Run MySQL for this job.
    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_DATABASE: app
          MYSQL_USER: app
          MYSQL_PASSWORD: password
          MYSQL_ROOT_PASSWORD: password
        ports:
          - 3306:3306
        options: >-
          --health-cmd "mysqladmin ping -h 127.0.0.1 -uapp -ppassword"
          --health-interval 10s
          --health-timeout 5s
          --health-retries 5

      # Using PostgreSQL instead? Comment out the MySQL service above and use this.
      # postgres:
      #   image: postgres:16-alpine
      #   env:
      #     POSTGRES_DB: app
      #     POSTGRES_USER: app
      #     POSTGRES_PASSWORD: password
      #   ports:
      #     - 5432:5432
      #   options: >-
      #     --health-cmd "pg_isready -d app -U app"
      #     --health-interval 10s
      #     --health-timeout 5s
      #     --health-retries 5
<?php endif; ?>

    steps:
      # Check out the application.
      - name: Checkout
        uses: actions/checkout@v7

      # Install PHP.
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ env.PHP_VERSION }}
          coverage: none

      # Install the exact locked dependencies and automatically cache Composer downloads.
      - name: Install dependencies
        uses: ramsey/composer-install@v4
        with:
          require-lock-file: true
<?php if ($with_doctrine): ?>

      # Create the test database and bring its schema up to date.
      - name: Setup database
        run: |
          php bin/console doctrine:database:create --if-not-exists
<?php if ($with_migrations): ?>
          php bin/console doctrine:migrations:migrate --no-interaction
<?php else: ?>
          php bin/console doctrine:schema:create
<?php endif; ?>

      # Verify that the migrations and Doctrine mapping agree. If not, show the missing SQL.
      - name: Validate database schema
        run: |
          php bin/console doctrine:schema:validate || {
            echo "::group::Schema changes"
            php bin/console doctrine:schema:update --dump-sql
            echo "::endgroup::"
            exit 1
          }
<?php endif; ?>

      # Run the test suite.
      - name: Run tests
        run: vendor/bin/phpunit

<?php endif; ?>
  lint:
    name: Lint
    runs-on: ubuntu-latest

    steps:
      # Check out the application.
      - name: Checkout
        uses: actions/checkout@v7

      # Install PHP and the Symfony CLI.
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ env.PHP_VERSION }}
          tools: symfony-cli
          coverage: none

      # Make sure composer.json is valid and composer.lock is in sync.
      - name: Validate Composer files
        run: composer validate --strict --no-check-publish

      # Install the exact locked dependencies and automatically cache Composer downloads.
      - name: Install dependencies
        uses: ramsey/composer-install@v4
        with:
          require-lock-file: true

      # Make sure the production service container compiles correctly.
      - name: Lint container
        run: php bin/console lint:container --env=prod
<?php if ($with_twig): ?>

      # Catch invalid Twig before it reaches production.
      - name: Lint Twig
        run: php bin/console lint:twig --env=prod
<?php endif; ?>

      # Catch invalid Symfony YAML configuration.
      - name: Lint YAML
        run: php bin/console lint:yaml config --parse-tags
<?php if ($with_translation): ?>

      # Validate the contents of all translation catalogs.
      - name: Lint translations
        run: php bin/console lint:translations
<?php endif; ?>

      # Catch Symfony-specific problems like unknown routes, templates and services.
      - name: Symfony diagnostics
        run: symfony lsp:check --format=github
<?php if ($with_asset_mapper): ?>

      # Make sure production assets can be compiled.
      - name: Compile assets
        run: php bin/console asset-map:compile --env=prod
<?php endif; ?>

      # Exercise the production cache warmers before deployment.
      - name: Warm production cache
        run: APP_DEBUG=0 php bin/console cache:warmup --env=prod
<?php if ($with_php_cs_fixer): ?>

  php-cs-fixer:
    name: PHP CS Fixer
    runs-on: ubuntu-latest

    steps:
      # Check out the application.
      - name: Checkout
        uses: actions/checkout@v7

      # Use the same PHP version as the application.
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ env.PHP_VERSION }}
          coverage: none

      # Install the project-local PHP CS Fixer version and its dependencies.
      - name: Install dependencies
        uses: ramsey/composer-install@v4
        with:
          require-lock-file: true

      # Check code style without changing files, and show the diff when it fails.
      - name: Check code style
        run: vendor/bin/php-cs-fixer check --diff --using-cache=no
<?php endif; ?>
<?php if ($with_phpstan): ?>

  phpstan:
    name: PHPStan
    runs-on: ubuntu-latest

    steps:
      # Check out the application.
      - name: Checkout
        uses: actions/checkout@v7

      # Use the same PHP version as the application.
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ env.PHP_VERSION }}
          coverage: none

      # Install the project-local PHPStan version and its extensions.
      - name: Install dependencies
        uses: ramsey/composer-install@v4
        with:
          require-lock-file: true

      # Reuse the result cache from a previous run so PHPStan only re-analyzes what changed.
      - name: Restore PHPStan result cache
        uses: actions/cache/restore@v6
        with:
          path: /tmp/phpstan
          key: phpstan-result-cache-v1-${{ env.PHP_VERSION }}-${{ github.run_id }}
          restore-keys: |
            phpstan-result-cache-v1-${{ env.PHP_VERSION }}-

      # Run static analysis using the project's PHPStan configuration.
      - name: Run PHPStan
        run: vendor/bin/phpstan analyse --no-progress

      # Save the result cache, even when the analysis found errors.
      - name: Save PHPStan result cache
        uses: actions/cache/save@v6
        if: ${{ !cancelled() }}
        with:
          path: /tmp/phpstan
          key: phpstan-result-cache-v1-${{ env.PHP_VERSION }}-${{ github.run_id }}
<?php endif; ?>
