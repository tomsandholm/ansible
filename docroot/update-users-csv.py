#!/usr/bin/env python3
"""Update public_key for a username in users.csv from a dropped pubkey CSV file."""

from __future__ import annotations

import csv
import os
import sys
import tempfile

PUBLIC_KEY_COLUMNS = {"public_key", "pub_key"}
USERNAME_COLUMNS = {"username", "user"}


def normalize_header(name: str) -> str:
    return name.strip().lower().replace(" ", "_")


def extract_pubkey_data(pubkey_file: str) -> tuple[str, str]:
    with open(pubkey_file, newline="", encoding="utf-8") as handle:
        rows = [row for row in csv.reader(handle) if row]

    if not rows:
        raise ValueError(f"{pubkey_file} is empty")

    header = [normalize_header(cell) for cell in rows[0]]
    has_header = any(
        cell in USERNAME_COLUMNS or cell in PUBLIC_KEY_COLUMNS for cell in header
    )

    if has_header:
        try:
            user_idx = next(
                i for i, cell in enumerate(header) if cell in USERNAME_COLUMNS
            )
            key_idx = next(
                i for i, cell in enumerate(header) if cell in PUBLIC_KEY_COLUMNS
            )
        except StopIteration as exc:
            raise ValueError(
                f"{pubkey_file} must contain username and public key columns"
            ) from exc

        for row in reversed(rows[1:]):
            if len(row) <= max(user_idx, key_idx):
                continue
            username = row[user_idx].strip()
            public_key = row[key_idx].strip()
            if username and public_key:
                return username, public_key

        raise ValueError(f"could not extract username and public key from {pubkey_file}")

    last_row = rows[-1]
    if len(last_row) >= 2:
        username = last_row[0].strip()
        public_key = last_row[1].strip()
        if username and public_key:
            return username, public_key

    raise ValueError(f"could not extract username and public key from {pubkey_file}")


def update_users_csv(users_file: str, username: str, public_key: str) -> None:
    if not os.path.isfile(users_file):
        raise FileNotFoundError(f"users file not found: {users_file}")

    with open(users_file, newline="", encoding="utf-8") as handle:
        reader = csv.DictReader(handle)
        if not reader.fieldnames:
            raise ValueError(f"{users_file} has no header row")

        fieldnames = list(reader.fieldnames)
        user_col = None
        key_col = None

        for name in fieldnames:
            normalized = normalize_header(name)
            if normalized in USERNAME_COLUMNS:
                user_col = name
            if normalized in PUBLIC_KEY_COLUMNS:
                key_col = name

        if user_col is None or key_col is None:
            raise ValueError(
                f"{users_file} must contain username and public_key columns; found {fieldnames}"
            )

        rows = list(reader)

    updated = False
    for row in rows:
        if row.get(user_col, "").strip() == username:
            row[key_col] = public_key
            updated = True
            break

    if not updated:
        raise ValueError(f"username {username!r} not found in {users_file}")

    directory = os.path.dirname(os.path.abspath(users_file)) or "."
    fd, temp_path = tempfile.mkstemp(prefix="users.csv.", dir=directory)
    os.close(fd)

    try:
        with open(temp_path, "w", newline="", encoding="utf-8") as handle:
            writer = csv.DictWriter(handle, fieldnames=fieldnames)
            writer.writeheader()
            writer.writerows(rows)
        os.replace(temp_path, users_file)
    except Exception:
        if os.path.exists(temp_path):
            os.unlink(temp_path)
        raise


def main() -> int:
    if len(sys.argv) != 3:
        print(
            f"usage: {sys.argv[0]} <pubkey.csv> <users.csv>",
            file=sys.stderr,
        )
        return 2

    pubkey_file, users_file = sys.argv[1:3]
    username, public_key = extract_pubkey_data(pubkey_file)
    update_users_csv(users_file, username, public_key)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        raise SystemExit(1)
