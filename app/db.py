"""Database access. Raw SQL against psycopg3 — the schema is the design artefact
(docs/04-data-model.md), so an ORM mapping layer would only obscure it."""

from __future__ import annotations

import os
from contextlib import contextmanager
from pathlib import Path

import psycopg
from psycopg.rows import dict_row

DEFAULT_DSN = os.environ.get(
    "MESSI_DSN", "postgresql://postgres@/messi?host=/var/tmp/messi-pg/sock"
)


@contextmanager
def connect(dsn: str | None = None):
    with psycopg.connect(dsn or DEFAULT_DSN, row_factory=dict_row, autocommit=False) as conn:
        yield conn


def init_schema(conn) -> None:
    conn.execute(Path(__file__).with_name("schema.sql").read_text())
    conn.commit()


def record_event(conn, org_id, event_type, entity_type, entity_id, actor_id=None, payload=None):
    """Append-only audit. Every state change writes one, which is what makes
    "who changed this, and when" a query rather than a memory exercise."""
    import json

    conn.execute(
        """INSERT INTO events (organization_id, event_type, entity_type, entity_id, actor_id, payload)
           VALUES (%s, %s, %s, %s, %s, %s)""",
        (org_id, event_type, entity_type, entity_id, actor_id, json.dumps(payload or {})),
    )
