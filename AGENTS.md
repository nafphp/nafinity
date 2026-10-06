# Working in this repository

This is the Nafinity skeleton: an installation, not the application. The product lives
in `naf/board` and arrives through Composer. If a change belongs to how Nafinity
*works*, it belongs in that repository, not here.

## Layout

    app/composer.json      what this installation requires, released packages only
    app/bootstrap.php      autoload, BASE_PATH, run -- and nothing else
    app/.env               the application's own environment; Compose still wins over it
    app/src/config.php     everything the application runs on; naf/board proposes nothing
    app/src/routes.php     routes this installation adds, loaded after every plugin
    app/src/               the owner's code, namespace Nafinity\
    app/src/plugins.php    optional local ordering; normally absent, plugins declare before/after
    app/src/extensions.php optional, runs last, may replace or remove anything
    app/src/views/         a template here wins over the package's
    app/public/index.php   the entry point
    app/storage/           uploads, sessions, queue and scheduler state
    docker/, Makefile      how it runs locally, and the production image
    compose.dev.yaml       only for developing the NAF packages: mounts their working copies

Nothing under `app/src/` is generated or owned by the board. Adding to it is the normal
way to work; there is no file here that a person is expected not to touch.

## Before changing something here

Ask whether the host is really the right place. Three things belong here and almost
nothing else does:

- **Deployment decisions** -- configuration, `.env`, Docker, what is installed.
- **Overrides** -- a template in `app/src/views/`, a definition changed in
  `app/src/extensions.php`.
- **The owner's own code** -- anything in `Nafinity\`.

A fix to how a board, a ticket or a setting behaves is a `naf/board` change. Making it
here produces an installation that silently differs from every other one, and the next
update quietly puts it back.

## Running and checking

    make first-install     prepare .env, build, install, migrate, seed, start
    make run               start everything
    bin/naf command:list   console; uses Compose from the host and PHP inside the container
    make health            readiness: database, migrations, storage, published assets
    bin/style check        this installation's PHP formatting, in the container

There are no tests here. naf/board and the other packages test themselves in their own
repositories; this installation is checked by using it, and `make health` says whether it
is ready.

`composer install` takes naf/board and everything else from Packagist, at the version
current when it runs; updating later is the owner's decision. For developing the NAF
packages themselves, `.env` sets `COMPOSE_FILE=compose.yaml:compose.dev.yaml` (bin/init-env
does that when it finds them beside this project): their working copies under
`NAF_SOURCE_ROOT` are mounted, and `bin/composer` installs every one the installation needs
as a symlink through `app/composer.dev.json`. `composer.json` itself stays clean of path
repositories, because it describes a real installation.

`docker build --target production -f docker/Dockerfile .` builds the image that ships: dependencies from Packagist,
the packages' public files published into `public/`, no Composer and no source mounts.

## Never committed

`.env`, `app/vendor/`, `app/storage/` contents, `app/composer.dev.*`, certificates, and
anything published into `app/public/assets/` or `app/public/plugins/` -- both are written by
`naf nafinity:assets:publish` on install.

## Further reading

The reference is at https://nafphp.github.io/docs/ and the release and contribution
rules are in `nafphp/docs/AGENT_WORKFLOW.md`. A README here may point at that
documentation; it must not duplicate it, and it must never carry release state.

## Console launcher

`bin/naf` forwards to `app/bin/naf`. The CLI package installs that shortcut; this
installation's `app/bin/naf-runtime` selects local PHP or the Compose service `app`, and
runs Compose from the project directory so `.env` -- and `COMPOSE_FILE` -- applies.
`NAF_CLI_RUNTIME=local|compose` explicitly overrides detection; `NAF_CLI_SERVICE`
selects another Compose service. Docker errors must never fall back to local PHP.
