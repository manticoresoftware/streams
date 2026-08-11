#!/bin/sh
set -x

[ ! -f /var/www/html/index.php ] && cp -rn /scaler_source_code/* /var/www/html/
find /var/www/html/ -type f -exec chmod 644 {} \;

echo "Init complete"