#!/bin/sh
# 1. Try to update config from subscription → /app/data/xray-config.json
# 2. Start xray if config exists (even if update failed — use cached config).
# 3. Exit cleanly (code 0) if no config at all.

php /app/xray/update-config.php >> /app/logs/xray-update.log 2>&1
UPDATE_EXIT=$?

if [ $UPDATE_EXIT -ne 0 ]; then
    echo "xray: update-config.php exited with code $UPDATE_EXIT" >> /app/logs/xray-update.log
fi

if [ -f /app/data/xray-config.json ]; then
    echo "xray: starting with /app/data/xray-config.json" >> /app/logs/xray-update.log
    exec /usr/local/bin/xray run -c /app/data/xray-config.json
else
    echo "xray: no config found, skipping" >> /app/logs/xray-update.log
    exit 0
fi
