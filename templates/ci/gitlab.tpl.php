stages:
    - ci

ci:
    stage: ci
    image: php:<?= $php_version ?>-cli

    cache:
        key:
            files:
                - composer.lock
        paths:
            - vendor/

    before_script:
        - apt-get update && apt-get install --yes --no-install-recommends git unzip libicu-dev
        - docker-php-ext-install intl
        - curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
        - composer install --no-interaction --no-progress --prefer-dist

    script:
        - composer audit
        - php bin/console lint:container
        - php bin/console lint:yaml config --parse-tags
<?php if ($with_twig): ?>
        - php bin/console lint:twig templates
<?php endif; ?>
<?php if ($with_doctrine): ?>
        - php bin/console doctrine:schema:validate --skip-sync --no-interaction
<?php endif; ?>
<?php if ($with_php_cs_fixer): ?>
        - PHP_CS_FIXER_IGNORE_ENV=1 vendor/bin/php-cs-fixer check --diff
<?php endif; ?>
<?php if ($with_phpstan): ?>
        - vendor/bin/phpstan analyse --no-progress
<?php endif; ?>
<?php if ($with_phpunit): ?>
        - vendor/bin/phpunit
<?php endif; ?>
