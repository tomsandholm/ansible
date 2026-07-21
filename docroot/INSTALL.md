# Installation Guide

This guide covers deploying the SSH public key registration web app and the `pubkey-watcher` systemd service to a Linux host. All application files are intended to live under `/var/www/html`.

## Prerequisites

- Ubuntu/Debian-based Linux host (or equivalent)
- Root or sudo access
- Network connectivity to `pa-infn-dc01.infinera.com` on port 389 (LDAP)
- A populated `users.csv` with the users who are allowed to register keys

## 1. Install packages

```bash
sudo apt update
sudo apt install -y nginx php php-fpm php-ldap inotify-tools python3
```

If you use Apache instead of nginx:

```bash
sudo apt install -y apache2 libapache2-mod-php php-ldap inotify-tools python3
```

## 2. Deploy application files

Copy the contents of this directory to the web root:

```bash
sudo mkdir -p /var/www/html/pubkey
sudo cp index.php pubkey-watcher.sh update-users-csv.py pubkey-watcher.service users.csv /var/www/html/
sudo cp -r pubkey /var/www/html/
```

Make the watcher scripts executable:

```bash
sudo chmod +x /var/www/html/pubkey-watcher.sh /var/www/html/update-users-csv.py
```

## 3. Configure `users.csv`

Edit `/var/www/html/users.csv` and add every user who should be able to register a key. Each user must already exist in the file before the watcher can update their key.

The file must use this header row:

```csv
username,uid,gid,email,home_dir,public_key,hostname
```

Example entries with an empty `public_key` ready to be filled in by the web form:

```csv
username,uid,gid,email,home_dir,public_key,hostname
stu01,2001,2001,stu01@gmail.com,/home/stu01,,tom1.tsand.org
stu02,2002,2002,stu02@gmail.com,/home/stu02,,tom2.tsand.org
```

The `public_key` column can be empty initially; it will be filled in when the user submits a key through the web form.

## 4. Set permissions

The web server needs to write to `pubkey/`. The watcher needs to read drop files and update `users.csv`.

### nginx + php-fpm (typical)

```bash
sudo chown -R www-data:www-data /var/www/html/pubkey /var/www/html/users.csv
sudo chmod 750 /var/www/html/pubkey
sudo chmod 640 /var/www/html/users.csv
```

If the watcher runs as root (default in the provided unit file), root can update `users.csv` even when it is owned by `www-data`. Ensure the web server can still write new files into `pubkey/`.

### Optional: run watcher as www-data

If you prefer the watcher to run as the same user as the web server, add these lines to the `[Service]` section of the unit file before installing it:

```ini
User=www-data
Group=www-data
```

In that case, make sure `www-data` can write to `users.csv`:

```bash
sudo chown www-data:www-data /var/www/html/users.csv
sudo chmod 640 /var/www/html/users.csv
```

## 5. Configure the web server

### nginx example

Create or update a site configuration (for example `/etc/nginx/sites-available/default`):

```nginx
server {
    listen 80;
    root /var/www/html;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

Enable and reload:

```bash
sudo nginx -t
sudo systemctl reload nginx
```

### Apache example

Ensure `mod_php` is enabled and the document root points to `/var/www/html`:

```bash
sudo a2enmod php*
sudo systemctl reload apache2
```

## 6. Verify Active Directory settings

Confirm the constants at the top of `/var/www/html/index.php` match your environment:

```php
define('AD_SERVER', 'pa-infn-dc01.infinera.com');
define('AD_DOMAIN', 'infinera.com');
define('AD_NETBIOS', 'INFINERA');
```

Adjust these values if your domain or NetBIOS name differs.

## 7. Install and start the systemd service

Copy the unit file and enable the service:

```bash
sudo cp /var/www/html/pubkey-watcher.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable pubkey-watcher.service
sudo systemctl start pubkey-watcher.service
```

Check status:

```bash
sudo systemctl status pubkey-watcher.service
```

Follow logs:

```bash
journalctl -u pubkey-watcher -f
```

## 8. Test the installation

### Test the web form

1. Open the site in a browser.
2. Log in with valid Active Directory credentials.
3. Submit a username that exists in `users.csv` and a valid SSH public key.
4. Confirm you are logged out and see a success message.

### Test the watcher

After submitting a key, verify:

```bash
# Drop file should be gone
ls /var/www/html/pubkey/

# users.csv should contain the new key
grep stu01 /var/www/html/users.csv

# Watcher should log success
journalctl -u pubkey-watcher --since "5 minutes ago"
```

### Manual watcher test

You can also test the Python helper directly:

```bash
cat > /tmp/test.csv <<'EOF'
Username,Public Key
stu01,ssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABgQDtest user@host
EOF

sudo python3 /var/www/html/update-users-csv.py /tmp/test.csv /var/www/html/users.csv stu01
grep stu01 /var/www/html/users.csv
```

## Troubleshooting

### LDAP login fails

- Confirm `php-ldap` is installed: `php -m | grep ldap`
- Verify the web server can reach `pa-infn-dc01.infinera.com:389`
- Check AD domain/NetBIOS settings in `index.php`
- Review the web server error log

### Web form cannot write to `pubkey/`

- Check ownership and permissions on `/var/www/html/pubkey`
- Confirm PHP runs as `www-data` (or the user that owns the directory)

### Watcher does not update `users.csv`

- Confirm the service is running: `systemctl status pubkey-watcher`
- Ensure the username exists in `users.csv`
- Verify the file header includes `username` and `public_key` columns
- Check logs: `journalctl -u pubkey-watcher -e`
- Verify `inotify-tools` is installed: `which inotifywait`
- Confirm `update-users-csv.py` is executable

### Drop file remains in `pubkey/`

This usually means the watcher failed to process the file. Common causes:

- Username not found in `users.csv`
- Invalid filename (must be `<username>.csv` with alphanumeric, `_`, or `-` characters)
- Python error while parsing the CSV

Inspect the log for details:

```bash
journalctl -t pubkey-watcher -e
```

## Service management

```bash
# Start / stop / restart
sudo systemctl start pubkey-watcher
sudo systemctl stop pubkey-watcher
sudo systemctl restart pubkey-watcher

# Disable at boot
sudo systemctl disable pubkey-watcher

# View recent log output
journalctl -u pubkey-watcher -n 50
```

## Uninstall

```bash
sudo systemctl stop pubkey-watcher
sudo systemctl disable pubkey-watcher
sudo rm /etc/systemd/system/pubkey-watcher.service
sudo systemctl daemon-reload
```

Remove application files from `/var/www/html` if no longer needed.
