#!/usr/bin/env python3
"""Write composer.dev.json: the manifest plus a path repository for naf/board.

naf/board is not on Packagist yet, so a development install resolves it from the
working copy mounted beside this project. Everything else comes from Packagist
exactly as it will in a real installation, which is the point of keeping this
file separate from composer.json rather than adding repositories to it.
"""
import json
import pathlib

root = pathlib.Path(__file__).resolve().parents[1]
data = json.loads((root / "app/composer.json").read_text())

data["repositories"] = [
    {
        "type": "path",
        # Mounted by compose.yaml; see NAF_BOARD_ROOT there.
        "url": "../board",
        "options": {
            "symlink": True,
            "versions": {"naf/board": data["require"]["naf/board"].lstrip("^")},
        },
    }
]
data["minimum-stability"] = "dev"
data["prefer-stable"] = True

(root / "app/composer.dev.json").write_text(json.dumps(data, indent=2) + "\n")
print("composer.dev.json written with a path repository for naf/board")
