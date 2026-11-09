#!/bin/bash
# バックエンドコンテナのエントリポイント。
# 起動時に初期管理者をシードしてから Apache を起動する。
set -e

# 初期管理者のシードはバックグラウンドで実行する。
# （内部でAppDbの準備をリトライ待ちする。API自体はすぐ起動させ、DB準備完了後にシードされる。）
php /var/www/html/bin/seed-admin.php &

exec apache2-foreground
