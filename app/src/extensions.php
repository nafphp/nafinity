<?php

declare(strict_types=1);

/**
 * Your last word on what the application offers.
 *
 * This file runs after the board has registered its defaults and after every
 * extension provider has had its turn, so anything reachable here can be replaced or
 * removed -- including a definition a plugin just added. It is optional: delete
 * it and nothing changes.
 *
 * The registries live behind Naf\Board\extensions(); what each of them accepts
 * is in docs/Extensibility.md of naf/board, and the overview in "How Nafinity
 * is extended" at https://nafphp.github.io/docs/.
 *
 * Example -- remove a board filter a plugin registered:
 *
 *     use function Naf\Board\extensions;
 *
 *     extensions()->boardFilters()->remove('example.only-mine');
 */
