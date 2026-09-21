# Nafinity

Project-isolated Kanban boards. This repository is the skeleton: the installation you
own, with the application itself pulled in as a dependency.

## Documentation

<https://nafphp.github.io/docs/> — the **Build with NafPHP** chapter covers what is in
this repository, how to make it yours, and the two mechanisms a plugin extends it
through. The full extension reference ships with `naf/board`, in its
`docs/Extensibility.md`.

## Install

Docker and `make` are all you need.

```sh
make first-install
```

That writes a `.env` with local passwords, builds the image, installs dependencies,
migrates, writes the declared roles, publishes the stylesheets and scripts into
`app/public/`, seeds a demo project and starts everything at <https://localhost>.

`make help` lists the rest.

## License

Proprietary.
