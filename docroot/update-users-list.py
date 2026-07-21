#!/usr/bin/env python3
"""Update pub_key for a username in users.list from a dropped pubkey CSV file."""

from __future__ import annotations

import csv
import os
import sys
import tempfile


def normalize_header(name: str) -> str:
    return name.strip().lower().replace(" ", "_")


def extract_public_key(pubkey_file: str) -> str:
    with open(pubkey_file, newline="", encoding="utf-8") as handle:
        rows = [row for row in csv.reader(handle) if row]

    if not rows:
        raise ValueError(f"{pubkey_file} is empty")

    header = [normalize_header(cell) for cell in rows[0]]
    key_names = {"pub_key", "public_key"}

    if any(cell in key_names for cell in header):
        key_idx = next(i for i, cell in enumerate(header) if cell in key_names)
        for row in reversed(rows[1:]):
            if len(row) > key_idx and row[key_idx].strip():
                return row[key_idx].strip()

    last_row = rows[-1]
    if len(last_row) >= 2 and normalize_header(last_row[0]) not in {"username", "user"}:
        return last_row[1].strip()
    if len(last_row) == 1:
        return last_row[0].strip()

    raise ValueError(f"could not extract public key from {pubkey_file}")


def update_users_list(users_file: str, username: str, pub_key: str) -> None:
    if not os.path.isfile(users_file):
        raise FileNotFoundError(f"users list not found: {users_file}")

    with open(users_file, newline="", encoding="utf-8") as handle:
        reader = csv.DictReader(handle)
        if not reader.fieldnames:
            raise ValueError(f"{users_file} has no header row")

        fieldnames = list(reader.fieldnames)
        user_col = None
        key_col = None

        for name in fieldnames:
            normalized = normalize_header(name)
            if normalized in {"username", "user"}:
                user_col = name
            if normalized in {"pub_key", "public_key"}:
                key_col = name

        if user_col is None or key_col is None:
            raise ValueError(
                f"{users_file} must contain username and pub_key columns; found {fieldnames}"
            )

        rows = list(reader)

    updated = False
    for row in rows:
        if row.get(user_col, "").strip() == username:
            row[key_col] = pub_key
            updated = True
            break

    if not updated:
        raise ValueError(f"username {username!r} not found in {users_file}")

    directory = os.path.dirname(os.path.abspath(users_file)) or "."
    fd, temp_path = tempfile.mkstemp(prefix="users.list.", dir=directory)
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
    if len(sys.argv) != 4:
        print(
            f"usage: {sys.argv[0]} <pubkey.csv> <users.list> <username>",
            file=sys.stderr,
        )
        return 2

    pubkey_file, users_file, username = sys.argv[1:4]
    pub_key = extract_public_key(pubkey_file)
    update_users_list(users_file, username, pub_key)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(str(exc), file=sys.stderr)
        raise SystemExit(1)
