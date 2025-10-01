#!/bin/bash
# Export environment variables
set -a
source /var/www/.env
set +a

# Run the Laravel schedule
/usr/local/bin/php /var/www/artisan schedule:run >> /var/log/cron.log 2>&1
