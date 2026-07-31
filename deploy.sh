#!/usr/bin/env bash

###############################################################################
# Configuration
###############################################################################

SERVER_USER="dimeji"
SERVER_HOST="18.191.148.195"
SERVER_PATH="/var/www/visual-collection-api"
# root@18.191.148.195:/var/www/visual-collection-api

LOCAL_PATH="./"

###############################################################################
# Smoke Tests
###############################################################################

echo
echo "=========================================="
echo "Running PHPUnit Smoke Tests..."
echo "=========================================="

# vendor/bin/phpunit
/c/xampp/php/php.exe vendor/bin/phpunit

if [ $? -ne 0 ]; then
    echo
    echo "❌ Deployment cancelled."
    echo "Smoke tests failed."
    exit 1
fi

echo
echo "✅ Smoke tests passed."

###############################################################################
# Deploy
###############################################################################

echo
echo "=========================================="
echo "Deploying..."
echo "=========================================="

rsync -avz \
    --delete \
    --progress \
    --exclude-from="rsync-exclude.txt" \
    "$LOCAL_PATH" \
    "$SERVER_USER@$SERVER_HOST:$SERVER_PATH"

if [ $? -ne 0 ]; then
    echo
    echo "❌ Deployment failed."
    exit 1
fi

echo
echo "✅ Files uploaded successfully."

###############################################################################
# Optional server commands
###############################################################################

ssh "$SERVER_USER@$SERVER_HOST" << EOF

cd $SERVER_PATH

echo "Running composer..."

if [ -f composer.json ]; then
    composer install \
        --no-dev \
        --optimize-autoloader
fi

echo "Deployment completed."

EOF

echo
echo "=========================================="
echo "✅ Deployment Successful!"
echo "=========================================="