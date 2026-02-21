# Project: API (Laravel)

## Overview
Pet project that supports other projects. Laravel 10 API application.

## Tech Stack
- **Framework**: Laravel 10.48.28
- **Language**: PHP
- **PHP version (web)**: 8.2 (ea-php82 via cPanel `.htaccess` handler)
- **PHP version (CLI)**: 8.3 (differs from web — always test against 8.2)

## Deployment
- **Production server**: SSH host `bigcats` (connect via `ssh bigcats`)
- **Server path**: `~/api`
- **Deployment method**: Automatic on file save from IDE (SFTP/sync)
- **No local or staging environment** — bigcats is the only runtime environment

## Logs
- **Laravel log**: `~/api/storage/logs/laravel.log` on bigcats
- **PHP error log**: `~/api/error_log` on bigcats
- View recent logs: `ssh bigcats "tail -100 ~/api/storage/logs/laravel.log"`
