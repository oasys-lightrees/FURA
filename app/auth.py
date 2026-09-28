"""Passwords and sessions.

scrypt from the standard library rather than a dependency: it is a memory-hard KDF and
adds nothing to install. Argon2id is the eventual target (docs/08 section 8.2) along with
MFA and rotating refresh tokens — none of that is here yet, and this is not ready for
anything reachable from the internet.
"""

from __future__ import annotations

import hashlib
import hmac
import os
import secrets
from datetime import datetime, timedelta, timezone

SESSION_TTL = timedelta(days=7)
COOKIE_NAME = "messi_session"

_N, _R, _P = 2**14, 8, 1


def hash_password(password: str) -> str:
    salt = os.urandom(16)
    dk = hashlib.scrypt(password.encode(), salt=salt, n=_N, r=_R, p=_P, dklen=32)
    return f"scrypt${_N}${_R}${_P}${salt.hex()}${dk.hex()}"


def verify_password(password: str, stored: str) -> bool:
    try:
        scheme, n, r, p, salt_hex, dk_hex = stored.split("$")
        if scheme != "scrypt":
            return False
        dk = hashlib.scrypt(
            password.encode(), salt=bytes.fromhex(salt_hex),
            n=int(n), r=int(r), p=int(p), dklen=len(dk_hex) // 2,
        )
    except (ValueError, TypeError):
        return False
    return hmac.compare_digest(dk.hex(), dk_hex)


def create_session(conn, user_id: int) -> str:
    token = secrets.token_urlsafe(32)
    conn.execute(
        "INSERT INTO sessions (token, user_id, expires_at) VALUES (%s, %s, %s)",
        (token, user_id, datetime.now(timezone.utc) + SESSION_TTL),
    )
    return token


def user_for_session(conn, token: str | None):
    if not token:
        return None
    row = conn.execute(
        """SELECT u.id, u.organization_id, u.email, u.display_name, u.role
           FROM sessions s JOIN users u ON u.id = s.user_id
           WHERE s.token = %s AND s.expires_at > now()""",
        (token,),
    ).fetchone()
    return row


def destroy_session(conn, token: str | None) -> None:
    if token:
        conn.execute("DELETE FROM sessions WHERE token = %s", (token,))
