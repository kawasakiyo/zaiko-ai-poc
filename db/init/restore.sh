#!/bin/bash
# SQL Server の起動を待って、分析データ(AdventureWorks)とアプリデータ(AppDb)を初期化する。
#
# セキュリティ方針:
#  - analysis_reader … AdventureWorks を読むだけ(db_datareader)。AppDb にはユーザーを作らない。
#  - app_user        … AppDb を読み書き。AdventureWorks にはユーザーを作らない。
# これにより、AI実行用の analysis_reader からアプリ情報(ユーザー等)へは物理的に到達できない。
#
# db-init サービスから一度だけ実行され、冪等(再実行しても安全)。
set -euo pipefail

SA_PASSWORD="${MSSQL_SA_PASSWORD:?MSSQL_SA_PASSWORD is required}"
ANALYSIS_DB_PASSWORD="${ANALYSIS_DB_PASSWORD:?ANALYSIS_DB_PASSWORD is required}"
APP_DB_PASSWORD="${APP_DB_PASSWORD:?APP_DB_PASSWORD is required}"
APP_DB_NAME="${APP_DB_NAME:-AppDb}"
APP_DB_USER="${APP_DB_USER:-app_user}"
BAK="/var/opt/mssql/backup/AdventureWorks2022.bak"
SQLCMD=(/opt/mssql-tools/bin/sqlcmd -S db -U sa -P "$SA_PASSWORD" -b)

echo "Waiting for SQL Server to accept connections..."
for i in $(seq 1 60); do
  if "${SQLCMD[@]}" -Q "SELECT 1" >/dev/null 2>&1; then
    echo "SQL Server is up."
    break
  fi
  if [ "$i" -eq 60 ]; then
    echo "ERROR: SQL Server did not become ready in time." >&2
    exit 1
  fi
  sleep 2
done

# ---- 分析データ(AdventureWorks)の復元 ----
if [ ! -f "$BAK" ]; then
  echo "ERROR: $BAK not found." >&2
  echo "       AdventureWorks2022.bak を db/backup/ に配置してください(README 参照)。" >&2
  exit 1
fi

if "${SQLCMD[@]}" -h -1 -Q "SET NOCOUNT ON; SELECT name FROM sys.databases WHERE name='AdventureWorks'" | grep -q "AdventureWorks"; then
  echo "AdventureWorks already restored; skipping RESTORE."
else
  echo "Restoring AdventureWorks..."
  "${SQLCMD[@]}" -Q "RESTORE DATABASE [AdventureWorks] FROM DISK='$BAK' \
    WITH MOVE 'AdventureWorks2022' TO '/var/opt/mssql/data/AdventureWorks.mdf', \
         MOVE 'AdventureWorks2022_log' TO '/var/opt/mssql/data/AdventureWorks_log.ldf', \
         REPLACE"
fi

# ---- 分析用ログイン analysis_reader(AdventureWorks 読み取りのみ) ----
echo "Ensuring analysis_reader (read-only on AdventureWorks)..."
"${SQLCMD[@]}" -Q "IF SUSER_ID('analysis_reader') IS NULL CREATE LOGIN analysis_reader WITH PASSWORD='$ANALYSIS_DB_PASSWORD', CHECK_POLICY=OFF;"
"${SQLCMD[@]}" -d AdventureWorks -Q "IF USER_ID('analysis_reader') IS NULL CREATE USER analysis_reader FOR LOGIN analysis_reader;"
"${SQLCMD[@]}" -d AdventureWorks -Q "IF IS_ROLEMEMBER('db_datareader','analysis_reader') = 0 ALTER ROLE db_datareader ADD MEMBER analysis_reader;"

# ---- アプリ用データベース AppDb ----
echo "Ensuring application database [$APP_DB_NAME]..."
"${SQLCMD[@]}" -Q "IF DB_ID('$APP_DB_NAME') IS NULL CREATE DATABASE [$APP_DB_NAME];"
# CREATE SCHEMA はバッチ先頭である必要があるため EXEC で包む。
"${SQLCMD[@]}" -d "$APP_DB_NAME" -Q "IF SCHEMA_ID('app') IS NULL EXEC('CREATE SCHEMA app');"
"${SQLCMD[@]}" -d "$APP_DB_NAME" -Q "IF OBJECT_ID('app.Users') IS NULL CREATE TABLE app.Users (Id INT IDENTITY(1,1) PRIMARY KEY, Username NVARCHAR(100) NOT NULL UNIQUE, PasswordHash NVARCHAR(255) NOT NULL, CreatedAt DATETIME2 NOT NULL CONSTRAINT DF_Users_CreatedAt DEFAULT SYSUTCDATETIME());"
"${SQLCMD[@]}" -d "$APP_DB_NAME" -Q "IF OBJECT_ID('app.QueryHistory') IS NULL CREATE TABLE app.QueryHistory (Id INT IDENTITY(1,1) PRIMARY KEY, UserId INT NOT NULL, Prompt NVARCHAR(MAX) NOT NULL, Model NVARCHAR(100) NOT NULL, GeneratedSql NVARCHAR(MAX) NOT NULL, Explanation NVARCHAR(MAX) NULL, CreatedAt DATETIME2 NOT NULL CONSTRAINT DF_QueryHistory_CreatedAt DEFAULT SYSUTCDATETIME());"
# 既存テーブル（Explanation 追加前）向けの冪等マイグレーション。
"${SQLCMD[@]}" -d "$APP_DB_NAME" -Q "IF COL_LENGTH('app.QueryHistory','Explanation') IS NULL ALTER TABLE app.QueryHistory ADD Explanation NVARCHAR(MAX) NULL;"

# ---- アプリ用ログイン app_user(AppDb のみ 読み書き) ----
echo "Ensuring app_user (read/write on $APP_DB_NAME only)..."
"${SQLCMD[@]}" -Q "IF SUSER_ID('$APP_DB_USER') IS NULL CREATE LOGIN [$APP_DB_USER] WITH PASSWORD='$APP_DB_PASSWORD', CHECK_POLICY=OFF;"
"${SQLCMD[@]}" -d "$APP_DB_NAME" -Q "IF USER_ID('$APP_DB_USER') IS NULL CREATE USER [$APP_DB_USER] FOR LOGIN [$APP_DB_USER];"
"${SQLCMD[@]}" -d "$APP_DB_NAME" -Q "IF IS_ROLEMEMBER('db_datareader','$APP_DB_USER') = 0 ALTER ROLE db_datareader ADD MEMBER [$APP_DB_USER];"
"${SQLCMD[@]}" -d "$APP_DB_NAME" -Q "IF IS_ROLEMEMBER('db_datawriter','$APP_DB_USER') = 0 ALTER ROLE db_datawriter ADD MEMBER [$APP_DB_USER];"

echo "DB init complete."
