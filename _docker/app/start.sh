#!/bin/bash

# Start npm in development mode
npm run dev &

# Start supervisor to manage PHP-FPM and scheduler
/usr/bin/supervisord -c /etc/supervisor/conf.d/supervisor.conf
