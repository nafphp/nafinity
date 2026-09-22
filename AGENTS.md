# Working in this repository

This is the Nafinity skeleton: an installation, not the application. The product lives
in `naf/board` and arrives through Composer. If a change belongs to how Nafinity
*works*, it belongs in that repository, not here.

## Layout

    app/composer.json      what this installation requires
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
    docker/, Makefile      how it runs locally

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
    make health            readiness: database, migrations, storage
    make test              the suites
    bin/style check        formatting, in the container

`naf/board` is not on Packagist yet, so a development install resolves it from a working
copy mounted from among the other NAF packages -- see `NAF_BOARD_ROOT` in
`compose.yaml` and `bin/generate-dev-manifest`. `composer.json` itself stays clean of path
repositories, because it describes a real installation.

## Never committed

`.env`, `app/vendor/`, `app/storage/` contents, `app/composer.dev.*`, certificates, and
anything published into `app/public/plugins/` -- that directory is written by
`naf nafinity:assets:publish` on install.

## Further reading

The reference is at https://nafphp.github.io/docs/ and the release and contribution
rules are in `nafphp/docs/AGENT_WORKFLOW.md`. A README here may point at that
documentation; it must not duplicate it, and it must never carry release state.
