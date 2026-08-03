# Contributing to Quizgeist

Thank you for helping improve Quizgeist. Keep changes focused, explain the
user-facing effect, and run the relevant checks before opening a pull request.

## Bug reports

Use the issue tracker for reproducible, non-security bugs. Include the
Quizgeist, Moodle and PHP versions; the browser and device; exact reproduction
steps; the expected and actual results; and relevant logs or stack traces.
Remove personal, course and other sensitive data before posting.

Do not publish suspected security vulnerabilities in an issue. Follow
`SECURITY.md` instead.

## Frontend

The frontend requires Node.js 20 or newer. The intended build command from the
repository root is exactly:

```sh
cd frontend && npm ci && npm run build
```

In this public repository, `frontend/package.json` currently starts `build`
with `check:p13`, which invokes `../../checks/p13_readiness.js`. That file is
not included here, so the one-command build stops before compiling. After
`npm ci`, the available build steps can be run individually from `frontend/`:

```sh
npm run typecheck
npm run build:js
npm run build:loader
npm run build:css
npm run build:assets
```

The asset step verifies and copies local assets; it does not download missing
files, so it reports an error if the required local assets are absent.

## Moodle and PHPUnit

From the Moodle root, initialise the PHPUnit environment once and run the
plugin suite:

```sh
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --testsuite mod_quizgeist_tests
```

Use Moodle APIs, capability checks, privacy metadata and language strings as
established by the plugin. Follow the Moodle Coding Style for PHP, JavaScript,
CSS and documentation.

## Pull requests and licensing

Describe the problem, approach and verification performed. Keep generated
build output consistent with project conventions and update tests or
documentation when behaviour changes.

Contributions are provided under the GNU General Public License version 3 or
later, consistent with `LICENSE`.
