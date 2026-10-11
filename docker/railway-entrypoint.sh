#!/bin/sh
set -eu
PORT="${PORT:-8080}"
case "$PORT" in ''|*[!0-9]*) echo 'Invalid PORT' >&2; exit 1;; esac
# Ensure exactly one MPM is enabled for mod_php.
# This also repairs images with conflicting MPM symlinks.
rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf \
      /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf
a2enmod mpm_prefork >/dev/null
# Apache must bind to the PORT supplied by Railway.
sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf
# Apache allows project .htaccess deny rules and rewrite rules.
cat >/etc/apache2/conf-available/gasgo.conf <<'EOF'
<Directory /var/www/html>
    AllowOverride All
    Require all granted
    Options -Indexes
</Directory>
<Directory /var/www/html/config>
    Require all denied
</Directory>
<Directory /var/www/html/database>
    Require all denied
</Directory>
<Directory /var/www/html/docker>
    Require all denied
</Directory>
<Directory /var/www/html/tests>
    Require all denied
</Directory>
<FilesMatch "^(Dockerfile|railway\.json|\.env.*|README.*|.*\.sql|.*SETUP.*|.*\.md|.*\.sh)$">
    Require all denied
</FilesMatch>
EOF
a2enconf gasgo >/dev/null
mkdir -p /var/www/html/uploads/stations /var/www/html/public/uploads/stations
# When Railway mounts a volume, it masks the image's original directory.
# Seed existing station photos the first time an empty Railway volume is mounted.
if [ -d /opt/gasgo-original-photos ]; then
  cp -n /opt/gasgo-original-photos/* /var/www/html/public/uploads/stations/ 2>/dev/null || true
fi
chown -R www-data:www-data /var/www/html/uploads /var/www/html/public/uploads
apache2ctl configtest
exec apache2-foreground
