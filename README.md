# 在庫データ分析AIアプリ (POC)

在庫データ(Microsoft の AdventureWorks サンプルDB)に対し、自然言語で指示すると
Claude(AI)が SQL を生成・実行して結果をテーブル表示する POC です。

- **ログイン**: ID/PASS 認証(初期管理者は `.env` からシード)
- **分析画面**: モデル選択 + チャット入力 → 生成SQL・説明・結果テーブル表示、CSVダウンロード
- **履歴画面**: 過去のプロンプトと生成SQLを一覧(全ユーザー共有・実行者名付き)

## データの分離（重要）

アプリは **2つのデータベース** を使い、**2つのDBログイン** で接続を分離しています。
これにより **AIが生成・実行するSQLからアプリ情報(ユーザー等)へは到達できません**。

| DB | 内容 | 接続ログイン | 権限 |
|---|---|---|---|
| `AdventureWorks` | 在庫などの分析データ | `analysis_reader` | 読み取りのみ。AppDb には権限なし |
| `AppDb` | アプリ固有データ(ユーザー `app.Users` / 履歴 `app.QueryHistory`) | `app_user` | AppDb のみ読み書き。AdventureWorks には権限なし |

AIが実行するSQLは `analysis_reader` で走るため、`AppDb.app.Users` などは **権限エラー** になります
(加えて、AIへ渡すスキーマ説明は在庫系のみ・`SqlGuard` がクロスDB参照も拒否、という多層防御)。

## 構成（docker-compose / 将来 podman へ移行想定）

| サービス | 役割 |
|---|---|
| `db` | SQL Server 2022。AdventureWorks と AppDb を保持 |
| `db-init` | 初回に AdventureWorks を restore し、AppDb・2ログイン・テーブルを作成 |
| `redis` | IP単位レートリミット + ログインセッション |
| `backend` | PHP 8.3 + 公式 Anthropic SDK。認証 / Claude呼び出し / SQL実行 / 履歴 / CSV |
| `frontend` | Vue 2 を Vite でビルドし nginx で配信。`/api` を backend へプロキシ |

> **技術メモ**: Claude 呼び出しは公式 PHP SDK (`anthropic-ai/sdk`) を使用。SDK は PHP 8.1+ 必須のため、
> バックエンドは PHP 8.3 を採用しています(SQL Server ドライバ対応が安定しているバージョン)。
> Apple Silicon では SQL Server イメージが amd64 のみのため `platform: linux/amd64` を明示しています(エミュレーション動作)。

---

## 事前に用意するもの

### 1. Anthropic API キー（必須・課金あり）
- https://console.anthropic.com → **API Keys** で発行し `sk-ant-...` を控える。`.env` に設定。

### 2. AdventureWorks バックアップファイル（必須）
- https://learn.microsoft.com/sql/samples/adventureworks-install-configure から
  **`AdventureWorks2022.bak`** を取得し、**`db/backup/AdventureWorks2022.bak`** に配置。

### 3. Docker Desktop（または Docker Engine + compose）

---

## セットアップ手順

```bash
# 1. 環境変数ファイルを作成
cp .env.example .env
#    → .env を編集し、少なくとも以下を設定:
#      ANTHROPIC_API_KEY     … sk-ant-... のキー
#      SA_PASSWORD           … SQL Server sa の強力なパスワード
#      ANALYSIS_DB_PASSWORD  … 分析用 analysis_reader のパスワード
#      APP_DB_PASSWORD       … アプリ用 app_user のパスワード
#      APP_ADMIN_USER / APP_ADMIN_PASSWORD … 初期ログイン用の管理者アカウント

# 2. AdventureWorks2022.bak を db/backup/ に配置

# 3. 起動（初回はビルド + DB restore + 管理者シードのため数分かかります）
docker compose up -d --build

# 4. 初期化ログを確認
docker compose logs -f db-init    # "DB init complete." が出れば DB 準備OK
docker compose logs backend       # "[seed-admin] 管理者 'admin' を作成しました。" を確認
```

起動後:

- フロント: http://localhost:3000 （未ログインなら自動でログイン画面へ）
- バックエンドAPI（直接確認用）: http://localhost:8080

`.env` の `APP_ADMIN_USER` / `APP_ADMIN_PASSWORD` でログインしてください。

---

## 動作確認

```bash
# 1) ログインしてトークンを取得
TOKEN=$(curl -s -X POST http://localhost:8080/api/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"admin-change-me"}' | sed -E 's/.*"token":"([^"]+)".*/\1/')
echo "$TOKEN"

# 2) 未認証だと 401（トークン無しで叩く）
curl -i http://localhost:8080/api/models   # → HTTP/1.1 401

# 3) トークン付きでモデル一覧
curl http://localhost:8080/api/models -H "Authorization: Bearer $TOKEN"

# 4) 分析（SQL生成 → 実行）
curl -X POST http://localhost:8080/api/analyze \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"prompt":"在庫数量の合計が少ない製品トップ10","model":"claude-sonnet-5"}'

# 5) 履歴（全ユーザー共有・実行者名付き）
curl http://localhost:8080/api/history -H "Authorization: Bearer $TOKEN"
```

ブラウザで http://localhost:3000 を開き、ログイン → 分析 → 結果テーブル → CSVダウンロード、
履歴画面で過去プロンプト・SQL・実行者を確認できます。

### 情報漏洩が防げていることの確認（任意）

```bash
# 「ユーザー一覧を取得して」と指示しても、AIは在庫スキーマしか知らず該当表を返せない。
curl -X POST http://localhost:8080/api/analyze \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"prompt":"アプリのユーザー一覧をすべて取得して","model":"claude-sonnet-5"}'

# さらに、analysis_reader で直接 AppDb を読もうとすると権限エラーになる（二重防御）。
docker compose exec db /opt/mssql-tools/bin/sqlcmd -S localhost -U analysis_reader \
  -P "$ANALYSIS_DB_PASSWORD" -d AdventureWorks \
  -Q "SELECT * FROM AppDb.app.Users"   # → 権限エラー
```

---

## 安全性について

- **接続分離**: AI実行は `analysis_reader`(AppDb無権限)。アプリ処理は `app_user`(AdventureWorks無権限)。
- **SqlGuard**: AI生成SQLは **SELECT 単文のみ許可**。`INSERT/UPDATE/DELETE/DROP/ALTER/EXEC/MERGE/INTO`、
  `USE`/`OPENROWSET` 等の DB切替・リンクサーバ、`AppDb` 参照・3部名のクロスDB参照を実行前に拒否。
- **プロンプト**: AIへ渡すスキーマ説明は在庫系のみ(アプリ表の存在を伝えない)。
- **認証**: パスワードは bcrypt(`password_hash`)で保存。ログインは Redis のセッショントークン(既定8時間)。
- 返却行数は `MAX_ROWS`(既定 500)で上限化。

## レートリミット

- `.env` の `RATE_LIMIT_WINDOW_SEC` 秒あたり、同一IPから `RATE_LIMIT_MAX` 回まで(既定: 3600秒で20回)。
- 超過時は HTTP 429 と `Retry-After` を返します。

---

## 設定項目（.env）

| 変数 | 既定 | 説明 |
|---|---|---|
| `ANTHROPIC_API_KEY` | — | Claude API キー（必須） |
| `SA_PASSWORD` | — | SQL Server sa パスワード（必須） |
| `ANALYSIS_DB_PASSWORD` | — | 分析用 analysis_reader のパスワード（必須） |
| `APP_DB_PASSWORD` | — | アプリ用 app_user のパスワード（必須） |
| `APP_DB_NAME` | AppDb | アプリ用データベース名 |
| `APP_DB_USER` | app_user | アプリ用DBユーザー名 |
| `APP_ADMIN_USER` | admin | 初期管理者ユーザー名（シード） |
| `APP_ADMIN_PASSWORD` | — | 初期管理者パスワード（必須） |
| `SESSION_TTL_SEC` | 28800 | ログインセッションの有効期限（秒） |
| `RATE_LIMIT_MAX` | 20 | ウィンドウあたりの最大リクエスト数 |
| `RATE_LIMIT_WINDOW_SEC` | 3600 | レートリミットのウィンドウ秒数 |
| `DEFAULT_MODEL` | claude-sonnet-5 | モデル選択セレクタの初期値 |
| `MAX_ROWS` | 500 | 1回の分析で返す最大行数 |

選択可能なモデル: `claude-haiku-4-5`（安価・高速） / `claude-sonnet-5`（バランス・既定） / `claude-opus-4-8`（最高精度）。

---

## よくあるトラブル

- **`db-init` が "AdventureWorks2022.bak not found" で止まる**
  → `db/backup/AdventureWorks2022.bak` を配置して `docker compose up -d` を再実行。
- **ログインできない / "[seed-admin]" がエラー**
  → `docker compose logs backend` を確認。DB起動前だとリトライ待ちになる。`APP_ADMIN_PASSWORD` 未設定だとシードされない。
- **backend が 500 / `ANTHROPIC_API_KEY が未設定`**
  → `.env` の `ANTHROPIC_API_KEY` を設定し `docker compose up -d` し直す。
- **restore で論理ファイル名エラー**
  → `AdventureWorks2022.bak` 以外の場合、`db/init/restore.sh` の `MOVE 'AdventureWorks2022' ...` を実ファイルの論理名に合わせる。

## スコープ外（POC）

- PDF / Excel(.xlsx) 出力（**CSVのみ**対応）
- ユーザー自己登録UI（初期管理者は `.env` シード）
- ナレッジ蓄積、SQL生成の自動リトライ/修正ループ
