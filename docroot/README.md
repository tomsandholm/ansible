# SSH Public Key Registration

A small web application and background service for collecting SSH public keys from users and merging them into a central user list.

## Overview

This project has two parts that work together:

1. **Web form (`index.php`)** — Users log in with Active Directory credentials, submit their username and SSH public key, and are logged out automatically after a successful submission.
2. **Background watcher (`pubkey-watcher`)** — A systemd service monitors the `pubkey/` directory. When a new key file appears, it updates the matching user in `users.csv` and removes the drop file.

The web app does not write directly to `users.csv`. Instead, it drops a short-lived CSV file into `pubkey/`, and the watcher service picks it up asynchronously.

## Components

| File | Description |
|------|-------------|
| `index.php` | PHP web UI with Active Directory (LDAP) login and public key submission |
| `pubkey/` | Drop directory for incoming `<username>.csv` key files |
| `users.csv` | Master CSV file used by downstream automation (for example Ansible playbooks) |
| `pubkey-watcher.sh` | Shell script that uses `inotifywait` to watch `pubkey/` |
| `update-users-csv.py` | Python helper that reads a drop file and updates `users.csv` |
| `pubkey-watcher.service` | systemd unit that runs the watcher continuously |

## Workflow

```
User → index.php (AD login)
     → submits username + SSH public key
     → writes pubkey/<username>.csv
     → user is logged out

pubkey-watcher (systemd)
     → detects new file in pubkey/
     → reads public key from CSV
     → updates public_key for matching username in users.csv
     → deletes pubkey/<username>.csv
```

## Active Directory configuration

LDAP settings are defined at the top of `index.php`:

- **AD server:** `pa-infn-dc01.infinera.com`
- **Domain:** `infinera.com`
- **NetBIOS name:** `INFINERA`

Users can log in with `jdoe`, `jdoe@infinera.com`, or `INFINERA\jdoe`.

## File formats

### `pubkey/<username>.csv`

Created by the web form. Example for user `stu01`:

```csv
Username,Public Key
stu01,ssh-rsa AAAAB3NzaC1yc2E... user@host
```

The watcher reads the public key from this file and then deletes it.

### `users.csv`

The master user list consumed by downstream automation. The header row defines the columns:

```csv
username,uid,gid,email,home_dir,public_key,hostname
```

Example rows:

```csv
username,uid,gid,email,home_dir,public_key,hostname
stu01,2001,2001,stu01@gmail.com,/home/stu01,,tom1.tsand.org
stu02,2002,2002,stu02@gmail.com,/home/stu02,ssh-rsa AAAAB3NzaC1yc2E... user@host,tom2.tsand.org
```

Column reference:

| Column | Description |
|--------|-------------|
| `username` | Login name; used to match incoming key submissions |
| `uid` | Numeric user ID |
| `gid` | Numeric group ID |
| `email` | User email address |
| `home_dir` | Home directory path |
| `public_key` | SSH public key (updated by the watcher) |
| `hostname` | Target host for this user |

Notes:

- The `username` column must already contain the user before a key can be updated.
- The watcher only updates the `public_key` column on existing rows; it does not create new users.
- If a username is not found, the drop file is left in place and an error is logged.

## Supported SSH key types

The web form accepts keys starting with:

- `ssh-rsa`
- `ssh-ed25519`
- `ecdsa-sha2-nistp256`

## Requirements

- PHP with the LDAP extension (`php-ldap`)
- A web server with PHP support (Apache or nginx + php-fpm)
- `inotify-tools` (provides `inotifywait`)
- Python 3
- Network access from the web server to the Active Directory server on port 389 (LDAP)
- systemd (for the background watcher service)

## Logging

The watcher writes messages to the system log with the identifier `pubkey-watcher`:

```bash
journalctl -u pubkey-watcher -f
```

## Related files

- `index.php.old` — earlier version using local password authentication (not used in production)
- `app.py` — standalone Flask prototype (not part of the deployed workflow)

## Installation

See [INSTALL.md](INSTALL.md) for deployment and service setup instructions.
