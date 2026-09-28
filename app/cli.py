"""Command line: python -m cli initdb|seed|tick|reset"""

from __future__ import annotations

import sys

from db import connect, init_schema


def main(argv):
    if not argv:
        print(__doc__)
        return 1
    command = argv[0]
    with connect() as conn:
        if command == "initdb":
            init_schema(conn)
            print("schema applied")
        elif command == "seed":
            import seed as seed_mod
            print("seeded:", seed_mod.seed(conn))
        elif command == "tick":
            import engine
            print("tick:", engine.tick(conn))
        elif command == "reset":
            conn.execute("DROP SCHEMA public CASCADE; CREATE SCHEMA public;")
            conn.commit()
            init_schema(conn)
            print("reset")
        else:
            print(f"unknown command: {command}")
            return 1
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
