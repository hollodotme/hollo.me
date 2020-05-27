---
layout: posts
title: Multi-language-version builds on Circle CI
tags: [CI, Circle CI, multi, PHP, code, rabbitmq, OSS]
permalink: /php/multi-language-version-builds-on-circle-ci.html
slug: php
---

Unlike [travis-ci.org](https://travic-ci.org), [Circle CI](https://circleci.com) does not support build matrices. 
This post will show you how to set up an extensible workflow for multi PHP version builds.

I started working on a new version of the [IceHawk framework](https://icehawk.github.io) and want to support the two current
versions of PHP: 7.2 and 7.3. Until now all builds for the framework were processed on travis-ci, but as I use Circle CI at the job 
I wanted to switch over, so I can reuse some existing build templates. Circle CI offers free builds for open source projects with up to 4 
parallel containers. (In a paid plan you have to pay $150/month for the same parallelism.)  

---

## Build jobs

In this project I defined the following jobs for each PHP version:

* Build the docker image and push it to [hub.docker.com](https://hub.docker.com)
* Checkout the code and install the dependencies via [composer](https://getcomposer.org)
* Run PHP linting on the source code (`php -l`)
* Run a static code analysis using [PHPStan](https://phpstan.org)
* Run the unit tests using [PHPUnit](https://phpunit.de) and upload code coverage to [codecov.io](https://codecov.io)
* Run the integration tests using [PHPUnit](https://phpunit.de)
* Create some code metrics using [PhpMetrics](https://www.phpmetrics.org)
* Auto-create a github release, if a git tag is present on branch master using [ghr](https://github.com/tcnksm/ghr)

---

## Workflow

In the end, the whole workflow looks like this.
[![IceHawk CI build workflow](/assets/img/posts/icehawk-ci-build.png)](/assets/img/posts/icehawk-ci-build.png)

---

## Step by Step

### Contexts and environment variables

Circle CI has a feature called "contexts", which is a set of custom environment variables. You can require a context 
for your jobs in your workflow definition. In order to make things version specific, I added two contexts to the Circle CI 
organization. Both contain just a single variable:

**Context > php72**
```bash
PHP_VERSION="7.2"
```

**Context > php73**
```bash
PHP_VERSION="7.3"
```

All environment variables that are version independent like docker hub credentials, github oauth token and codecov token
are set up as simple environment variables in the Circle CI project settings.

Those environment variables must not be required explicitly, they will be available in every build job automatically.

### Working directories

Circle CI workflows share _ONE_ storage known as "workspace", which can be attached to every build job in order to
use files/results from previous jobs. And there lies a problem. When running e.g. `composer install` on different
versions of PHP it can install different versions of packages, depending on their platform requirements. So using a 
single checkout/install directory may cause problems on subsequent build jobs.

And there is another problem to the `working_directory` directive in Circle CI. It does not expand environment variables.
So it is not possible to use the previously set environment variable `$PHP_VERSION` in the path value of `working_directory`.

Long story short, in order to have separate installation directories for each PHP version, I created aliases for each 
directory which will be injected into the jobs accordingly.

{% raw %}
```yaml
workdir-72: &workdir-72
  working_directory: ~/repo/7.2

workdir-73: &workdir-73
  working_directory: ~/repo/7.3
``` 
{% endraw %}

### Step definitions

In most cases the step definitions / commands will be identical for all PHP versions, 
so we can also create aliases for them. I prefix them with "shared-", so it's clear that they are used multiple times.

The first job is to build and push the PHP docker images that will be used in nearly all subsequent jobs.

The Dockerfile and further build configs for each PHP version are located in the repository at `.docker/php/<PHP_VERSION>/Dockerfile`.

{% raw %}
```yaml
shared-build: &shared-build
  working_directory: ~/repo
  machine:
    docker_layer_caching: true
  steps:
    - checkout
    - run:
        name: Build docker image
        command: >
          docker build 
          -t "$CIRCLE_PROJECT_USERNAME/$CIRCLE_PROJECT_REPONAME:$PHP_VERSION-cli" 
          -f .docker/php/$PHP_VERSION/Dockerfile 
          .docker/php/$PHP_VERSION
    - run:
        name: Login to hub.docker.com
        command: |
          echo $DOCKER_HUB_PASSWORD | docker login -u $DOCKER_HUB_USER --password-stdin
    - run:
        name: Push docker image
        command: |
          docker push "$CIRCLE_PROJECT_USERNAME/$CIRCLE_PROJECT_REPONAME:$PHP_VERSION-cli"
```
{% endraw %}

This job is a bit special, because:

* It doesn't use the separated working directories.
* It uses a real virtual machine at Circle CI to run the docker commands.

As you can see various environment variables are used in these steps:

* `$CIRCLE_PROJECT_USERNAME/$CIRCLE_PROJECT_REPONAME` is the project's slug at GitHub and those are provided by Circle CI by default
* `$PHP_VERSION` from the required context
* `$DOCKER_HUB_USER` & `$DOCKER_HUB_PASSWORD` from the project's environment variables

The next job alias will checkout the code, do the `composer install` and persist the project to the workspace.

{% raw %}
```yaml
shared-code: &shared-code
  docker:
    - image: $CIRCLE_PROJECT_USERNAME/$CIRCLE_PROJECT_REPONAME:$PHP_VERSION-cli
  steps:
    - checkout

    # Download and cache dependencies
    - restore_cache:
        keys:
          - v1-dependencies-{{ checksum "composer.json" }}-$PHP_VERSION

    - run:
        name: Update composer
        command: composer self-update

    - run:
        name: Install dependencies
        command: |
          composer install -o --prefer-dist --no-interaction

    - save_cache:
        paths:
          - ./vendor
        key: v1-dependencies-{{ checksum "composer.json" }}-$PHP_VERSION

    - run:
        name: Prepare log directories
        command: |
          mkdir -p build/logs/coverage
          mkdir -p build/logs/junit
          mkdir -p build/logs/phpmetrics

    - persist_to_workspace:
        root: ~/repo
        paths:
          - "*"
```
{% endraw %}

**Please note:** As this job has different working directories for each context, I persist everything under `~/repo` 
to the shared workspace. Everything means exactly two directories `~/repo/7.2` & `~/repo/7.3`. The paths listing also is not 
able to expand environment variables, so it's sadly not possible to use `- $PHP_VERSION` here.  

All the following aliases all work the same way. They just have different steps/commands.

### Job definitions

The next config section defines the actual jobs that shall be available for the workflow.

Basically you create job names and inject the appropriate aliases.

{% raw %}
```yaml
jobs:
  "php-7.2-build":
    <<: *shared-build

  "php-7.3-build":
    <<: *shared-build

  "php-7.2-code":
    <<: *shared-code
    <<: *workdir-72

  "php-7.3-code":
    <<: *shared-code
    <<: *workdir-73

  "php-7.2-linting":
    <<: *shared-linting
    <<: *workdir-72

  "php-7.3-linting":
    <<: *shared-linting
    <<: *workdir-73

  "php-7.2-unit-tests":
    <<: *shared-unit-tests
    <<: *workdir-72

  "php-7.3-unit-tests":
    <<: *shared-unit-tests
    <<: *workdir-73

  "php-7.2-integration-tests":
    <<: *shared-integration-tests
    <<: *workdir-72

  "php-7.3-integration-tests":
    <<: *shared-integration-tests
    <<: *workdir-73

  "php-7.2-phpstan":
    <<: *shared-phpstan
    <<: *workdir-72

  "php-7.3-phpstan":
    <<: *shared-phpstan
    <<: *workdir-73

  "php-7.2-phpmetrics":
    <<: *shared-phpmetrics
    <<: *workdir-72

  "php-7.3-phpmetrics":
    <<: *shared-phpmetrics
    <<: *workdir-73

  "php-7.2-github-release":
    <<: *shared-github-release
    <<: *workdir-72

  "php-7.3-github-release":
    <<: *shared-github-release
    <<: *workdir-73
```
{% endraw %}

### Workflow definition

The last config section describes the workflow and the dependencies of jobs. As you can see I created two parallel 
pipelines, one for each PHP version with jobs requiring the right version context.

{% raw %}
```yaml
workflows:
  version: 2
  build-test-analyze:
    jobs:

      # PHP 7.2 jobs

      - "php-7.2-build":
          context: php72
      - "php-7.2-code":
          context: php72
          requires:
            - "php-7.2-build"
      - "php-7.2-linting":
          context: php72
          requires:
            - "php-7.2-code"
      - "php-7.2-phpstan":
          context: php72
          requires:
            - "php-7.2-code"
      - "php-7.2-unit-tests":
          context: php72
          requires:
            - "php-7.2-linting"
            - "php-7.2-phpstan"
      - "php-7.2-integration-tests":
          context: php72
          requires:
            - "php-7.2-linting"
            - "php-7.2-phpstan"
      - "php-7.2-phpmetrics":
          context: php72
          requires:
            - "php-7.2-unit-tests"
            - "php-7.2-integration-tests"
      - "php-7.2-github-release":
          context: php72
          requires:
            - "php-7.2-phpmetrics"
          filters:
            branches:
              only: master

      # PHP 7.3 jobs

      - "php-7.3-build":
          context: php73
      - "php-7.3-code":
          context: php73
          requires:
            - "php-7.3-build"
      - "php-7.3-linting":
          context: php73
          requires:
            - "php-7.3-code"
      - "php-7.3-phpstan":
          context: php73
          requires:
            - "php-7.3-code"
      - "php-7.3-unit-tests":
          context: php73
          requires:
            - "php-7.3-linting"
            - "php-7.3-phpstan"
      - "php-7.3-integration-tests":
          context: php73
          requires:
            - "php-7.3-linting"
            - "php-7.3-phpstan"
      - "php-7.3-phpmetrics":
          context: php73
          requires:
            - "php-7.3-unit-tests"
            - "php-7.3-integration-tests"
      - "php-7.3-github-release":
          context: php73
          requires:
            - "php-7.3-phpmetrics"
          filters:
            branches:
              only: master
``` 
{% endraw %}

**Please note:** The last job is only visible and executed on branch master.

---

## Putting it all together

{% raw %}
```yaml
version: 2

# Specify working directories for each PHP version
# Unfortunately Circle CI is not able to expand environment/context variables in
# the value for working_directory
workdir-72: &workdir-72
  working_directory: ~/repo/7.2

workdir-73: &workdir-73
  working_directory: ~/repo/7.3

# Define steps to build docker images for each PHP version
shared-build: &shared-build
  working_directory: ~/repo
  machine:
    docker_layer_caching: true
  steps:
    - checkout
    - run:
        name: Build docker image
        command: >
          docker build 
          -t "$CIRCLE_PROJECT_USERNAME/$CIRCLE_PROJECT_REPONAME:$PHP_VERSION-cli" 
          -f .docker/php/$PHP_VERSION/Dockerfile 
          .docker/php/$PHP_VERSION
    - run:
        name: Login to hub.docker.com
        command: |
          echo $DOCKER_HUB_PASSWORD | docker login -u $DOCKER_HUB_USER --password-stdin
    - run:
        name: Push docker image
        command: |
          docker push "$CIRCLE_PROJECT_USERNAME/$CIRCLE_PROJECT_REPONAME:$PHP_VERSION-cli"

# Define steps to build the code and its dependencies
# Persist it to the workspace when done
shared-code: &shared-code
  docker:
    - image: $CIRCLE_PROJECT_USERNAME/$CIRCLE_PROJECT_REPONAME:$PHP_VERSION-cli
  steps:
    - checkout

    # Download and cache dependencies
    - restore_cache:
        keys:
          - v1-dependencies-{{ checksum "composer.json" }}-$PHP_VERSION

    - run:
        name: Update composer
        command: composer self-update

    - run:
        name: Install dependencies
        command: |
          composer install -o --prefer-dist --no-interaction

    - save_cache:
        paths:
          - ./vendor
        key: v1-dependencies-{{ checksum "composer.json" }}-$PHP_VERSION

    - run:
        name: Prepare log directories
        command: |
          mkdir -p build/logs/coverage
          mkdir -p build/logs/junit
          mkdir -p build/logs/phpmetrics

    - persist_to_workspace:
        root: ~/repo
        paths:
          - "*"

# Define steps to check for PHP parse errors
shared-linting: &shared-linting
  docker:
    - image: $CIRCLE_PROJECT_USERNAME/$CIRCLE_PROJECT_REPONAME:$PHP_VERSION-cli
  steps:
    - attach_workspace:
        at: ~/repo

    - run:
        name: Check for PHP parse errors
        command: find ./src -type f -name '*.php' -print0 | xargs -0 -n1 -P4 php -l -n | (! grep -v "No syntax errors detected" )

# Define steps to run unit tests
shared-unit-tests: &shared-unit-tests
  docker:
    - image: $CIRCLE_PROJECT_USERNAME/$CIRCLE_PROJECT_REPONAME:$PHP_VERSION-cli
  steps:
    - attach_workspace:
        at: ~/repo

    - run:
        name: Run unit tests
        command: vendor/bin/phpunit.phar -c build --testsuite Unit --log-junit build/logs/junit/junit.xml --coverage-html build/logs/coverage --coverage-clover=coverage.xml

    - run:
        name: Upload code coverage to codecov.io
        command: bash <(curl -s https://codecov.io/bash)

    - store_test_results:
        path: build/logs/junit

    - store_artifacts:
        path: build/logs/junit
        destination: code-coverage-junit

    - store_artifacts:
        path: build/logs/coverage
        destination: code-coverage-html

# Define steps to run integration tests
shared-integration-tests: &shared-integration-tests
  docker:
    - image: $CIRCLE_PROJECT_USERNAME/$CIRCLE_PROJECT_REPONAME:$PHP_VERSION-cli
  steps:
    - attach_workspace:
        at: ~/repo

    - run:
        name: Run integration tests
        command: vendor/bin/phpunit.phar -c build --testsuite Integration --log-junit build/logs/junit/junit.xml

    - store_test_results:
        path: build/logs/junit

    - store_artifacts:
        path: build/logs/junit
        destination: code-coverage-junit

# Define steps to run phpmetrics
shared-phpmetrics: &shared-phpmetrics
  docker:
    - image: $CIRCLE_PROJECT_USERNAME/$CIRCLE_PROJECT_REPONAME:$PHP_VERSION-cli
  steps:
    - attach_workspace:
        at: ~/repo

    - run:
        name: Run PHP metrics
        command: vendor/bin/phpmetrics.phar --report-html=build/logs/phpmetrics src/

    - store_artifacts:
        path: build/logs/phpmetrics
        destination: php-metrics-report

# Define steps to run phpstan
shared-phpstan: &shared-phpstan
  docker:
    - image: $CIRCLE_PROJECT_USERNAME/$CIRCLE_PROJECT_REPONAME:$PHP_VERSION-cli
  steps:
    - attach_workspace:
        at: ~/repo

    - run:
        name: Run PHPStan
        command: vendor/bin/phpstan.phar analyze --level max src/

# Define steps to auto-create a github release, if a tag is present
shared-github-release: &shared-github-release
  docker:
    - image: cibuilds/github
  steps:
    - attach_workspace:
        at: ~/repo
    - run:
        name: Display git tag file
        command: |
          VERSION=$(cat ./TAG)
          echo ${VERSION}
    - run:
        name: Create release at GitHub
        command: VERSION=$(cat ./TAG) &&
          if [[ $VERSION =~ ^v.*$ ]] ;
          then
          ghr -t ${GITHUB_OAUTH_TOKEN}
          -u $CIRCLE_PROJECT_USERNAME
          -r $CIRCLE_PROJECT_REPONAME
          -c ${CIRCLE_SHA1}
          -b "See [CHANGELOG](https://github.com/$CIRCLE_PROJECT_USERNAME/$CIRCLE_PROJECT_REPONAME/blob/${VERSION}/CHANGELOG.md)"
          -delete ${VERSION} ./docs;
          else
          echo "No version found - no release triggered";
          fi

# Define the actual jobs from the templates above
jobs:
  "php-7.2-build":
    <<: *shared-build

  "php-7.3-build":
    <<: *shared-build

  "php-7.2-code":
    <<: *shared-code
    <<: *workdir-72

  "php-7.3-code":
    <<: *shared-code
    <<: *workdir-73

  "php-7.2-linting":
    <<: *shared-linting
    <<: *workdir-72

  "php-7.3-linting":
    <<: *shared-linting
    <<: *workdir-73

  "php-7.2-unit-tests":
    <<: *shared-unit-tests
    <<: *workdir-72

  "php-7.3-unit-tests":
    <<: *shared-unit-tests
    <<: *workdir-73

  "php-7.2-integration-tests":
    <<: *shared-integration-tests
    <<: *workdir-72

  "php-7.3-integration-tests":
    <<: *shared-integration-tests
    <<: *workdir-73

  "php-7.2-phpstan":
    <<: *shared-phpstan
    <<: *workdir-72

  "php-7.3-phpstan":
    <<: *shared-phpstan
    <<: *workdir-73

  "php-7.2-phpmetrics":
    <<: *shared-phpmetrics
    <<: *workdir-72

  "php-7.3-phpmetrics":
    <<: *shared-phpmetrics
    <<: *workdir-73

  "php-7.2-github-release":
    <<: *shared-github-release
    <<: *workdir-72

  "php-7.3-github-release":
    <<: *shared-github-release
    <<: *workdir-73

# Define the workflows for each PHP version
workflows:
  version: 2
  build-test-analyze:
    jobs:

      # PHP 7.2 jobs

      - "php-7.2-build":
          context: php72
      - "php-7.2-code":
          context: php72
          requires:
            - "php-7.2-build"
      - "php-7.2-linting":
          context: php72
          requires:
            - "php-7.2-code"
      - "php-7.2-phpstan":
          context: php72
          requires:
            - "php-7.2-code"
      - "php-7.2-unit-tests":
          context: php72
          requires:
            - "php-7.2-linting"
            - "php-7.2-phpstan"
      - "php-7.2-integration-tests":
          context: php72
          requires:
            - "php-7.2-linting"
            - "php-7.2-phpstan"
      - "php-7.2-phpmetrics":
          context: php72
          requires:
            - "php-7.2-unit-tests"
            - "php-7.2-integration-tests"
      - "php-7.2-github-release":
          context: php72
          requires:
            - "php-7.2-phpmetrics"
          filters:
            branches:
              only: master

      # PHP 7.3 jobs

      - "php-7.3-build":
          context: php73
      - "php-7.3-code":
          context: php73
          requires:
            - "php-7.3-build"
      - "php-7.3-linting":
          context: php73
          requires:
            - "php-7.3-code"
      - "php-7.3-phpstan":
          context: php73
          requires:
            - "php-7.3-code"
      - "php-7.3-unit-tests":
          context: php73
          requires:
            - "php-7.3-linting"
            - "php-7.3-phpstan"
      - "php-7.3-integration-tests":
          context: php73
          requires:
            - "php-7.3-linting"
            - "php-7.3-phpstan"
      - "php-7.3-phpmetrics":
          context: php73
          requires:
            - "php-7.3-unit-tests"
            - "php-7.3-integration-tests"
      - "php-7.3-github-release":
          context: php73
          requires:
            - "php-7.3-phpmetrics"
          filters:
            branches:
              only: master
```
{% endraw %}

---

## Conclusion

Creating a build matrix on Circle CI for multiple language versions still comes with a lot of config duplication and is messy to read, 
but it's doable and can be extended with further versions.

Having one particular job for each version can also be a benefit when it comes to differences in commands or options for 
that version as you don't have to add conditionals. Instead you can write a different alias and inject it or simply replace 
the step definition alias with actual steps for that particular job.

---

<small>12/28/2018</small>