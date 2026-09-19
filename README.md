# Nafinity

Project-isolated Kanban boards. This repository is the skeleton: the installation you
own, with the application itself pulled in as a dependency.

That split is the point. Extending Nafinity should never mean editing it, so everything
the product does lives in `naf/board`, and everything you decide lives here.

```
app/
  composer.json      what your installation requires
  bootstrap.php      nine lines: autoload, BASE_PATH, run
  src/
    plugins.php      the order plugins boot in
    extensions.php   your last word on what the application offers
    views/           drop a template here to override the board's
  public/index.php   the entry point
  storage/           uploads, sessions, queue and scheduler state
docker/, Makefile    how it runs locally
```

There is deliberately almost nothing here. A fresh installation shows a complete
application, and none of it is a file you could break by editing.

## Getting started

```sh
make first-install
```

That writes a `.env` with local passwords, builds the image, installs dependencies,
migrates, seeds a demo project and starts everything at https://localhost.

`make help` lists the rest.

## Making it yours

**Your own code** goes in `app/src/` under the `Nafinity\` namespace. The namespace is
yours -- nothing in `naf/board` refers to it, so rename it in `composer.json` if you
would rather call it something else.

**Overriding a template**: copy the one you want from `vendor/naf/board/src/views/` into
`app/src/views/`, keeping the path. Host views win over the package's.

**Changing what the application offers**: `app/src/extensions.php` runs after the board
and every plugin has registered, so anything reachable there can be replaced or removed.

**Adding a plugin**: `composer require` it. A package of type `naf-plugin` is found
automatically; list it in `app/src/plugins.php` only when its position matters.

## Documentation

The reference lives at https://nafphp.github.io/docs/ -- the Extending chapter covers
the registries, the contracts and what a plugin may declare.
