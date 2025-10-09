# Cloud Run で動かす Laravel + MySQL: 実践ハンズオンガイド

このリポジトリは、コンテナ化された Laravel アプリケーションを Google Cloud Run にデプロイするための、完全なハンズオンガイドです。Direct VPC Egress を利用した安全なデータベース接続と、サイドカーパターンによる効率的なコネクションプーリングを特徴とする、堅牢で本番環境に対応可能なアーキテクチャを構築します。

このドキュメントは、`01-php-mysql-demo` の内容をベースに、人気のPHPフレームワークである Laravel を用いた構成に発展させたものです。

## 最終的なアーキテクチャ

*   **アプリケーション実行環境**: Cloud Run
*   **アプリケーションフレームワーク**: Laravel
*   **Webサーバー**: Nginx + PHP-FPM
*   **データベース**: Cloud SQL for MySQL (プライベートIP経由で接続)
*   **セキュアな接続**: Direct VPC Egress
*   **コネクションプーリング**: Cloud SQL Auth Proxy (サイドカーコンテナとして)
*   **ヘルスチェック**: アプリケーションとプロキシ双方のカスタム起動プローブ
*   **構成管理**: `service.yaml` を用いた Infrastructure as Code (IaC) アプローチ
*   **ローカル開発環境**: `podman-compose` とローカルMySQLデータベース

## このガイドで学べること

*   Nginx と PHP-FPM を使った Laravel アプリケーションのコンテナ化
*   `podman-compose` によるローカル開発環境のセットアップ
*   Artisanコマンドを使ったアプリケーションキーの生成とマイグレーション
*   Secret Manager を用いた安全な機密情報（DBパスワード、APP_KEY）の管理
*   サイドカーコンテナ (Cloud SQL Auth Proxy) を使った Cloud Run へのデプロイ
*   Direct VPC Egress によるプライベートネットワーク通信の設定
*   信頼性の高いサービス起動のための、堅牢な起動プローブの実装
*   `service.yaml` を用いた宣言的な Cloud Run サービスの管理
*   デプロイの一貫性を保つための不変なイメージタグなどのベストプラクティス

## 前提条件

*   `gcloud` CLI がインストールされ、認証済みであること。
*   `podman` および `podman-compose` がインストール済みであること。
*   課金が有効になっている Google Cloud プロジェクト。

---

## ステップ1: ローカル開発環境の構築

このステップでは、ローカルマシン上で完結する開発環境を構築します。

**1. 環境変数の設定とアプリケーションキーの生成**

まず、Laravel が必要とする環境変数ファイル `.env` を準備します。`.env.example` をコピーして作成してください。

```bash
cp .env.example .env
```

次に、`--show` オプションを付けてLaravelのアプリケーションキーを生成し、表示されたキーを手動で `.env` ファイルに設定します。これは、コンテナからホストのファイルへの書き込み権限問題を確実に回避するためです。

```bash
# 1. コマンドを実行して、ターミナルにキーを表示させます
podman-compose run --rm app key:generate --show

# 2. 表示されたキー（base64:...）をコピーし、.envファイルの APP_KEY= の部分に貼り付けます
```

**2. ローカルでの実行**

コンテナをビルドして起動します。`-d` フラグでバックグラウンド実行します。

```bash
podman-compose up --build -d
```

アプリケーションが動作していることを確認します。初回起動時はデータベースのマイグレーションが実行されます。

```bash
curl http://localhost:8080
# 出力に "Successfully connected to MySQL version: ..." が含まれていれば成功です。
```

開発を終了する際は、以下のコマンドでコンテナを停止・削除します。

```bash
podman-compose down
```

---

## ステップ2: Google Cloud 環境のセットアップ

デプロイに必要なGCPサービスとIAM権限を準備します。

**1. 環境変数の設定**

まず、ご自身の環境に合わせて以下の変数を設定してください。

```bash
# --- ユーザーが設定する項目 --- #
export PROJECT_ID="[YOUR_PROJECT_ID]"
export REGION="asia-northeast1"
export SERVICE_NAME="hello-laravel"
export REPO_NAME="laravel-repo"
export INSTANCE_NAME="mysql-laravel-demo"
export DB_USER="[YOUR_DB_USER]"
export DB_PASSWORD="[YOUR_DB_PASSWORD]"
export DB_NAME="laravel_db"
# --- 設定項目ここまで --- #

# 以下の変数は自動で設定されます
export INSTANCE_CONNECTION_NAME="${PROJECT_ID}:${REGION}:${INSTANCE_NAME}"
export DB_PASSWORD_SECRET_NAME="${SERVICE_NAME}-db-password"
export APP_KEY_SECRET_NAME="${SERVICE_NAME}-app-key"
export PROJECT_NUMBER=$(gcloud projects describe "$PROJECT_ID" --format='value(projectNumber)')
export SERVICE_ACCOUNT="${PROJECT_NUMBER}-compute@developer.gserviceaccount.com"
export IMAGE_NAME="helloworld-laravel"
```

**2. APIの有効化**

ハンズオンに必要なAPIを有効化します。

```bash
gcloud services enable sqladmin.googleapis.com secretmanager.googleapis.com artifactregistry.googleapis.com cloudbuild.googleapis.com run.googleapis.com servicenetworking.googleapis.com --project="$PROJECT_ID"
```

**3. プライベートサービス接続の設定**

(すでに `01-php-mysql-demo` で実施済みの場合は不要です)
Cloud SQL が VPC ネットワークとプライベートに通信できるように、ネットワークを一度だけ設定します。

```bash
# Googleのサービス用にIPアドレス範囲を予約します
gcloud compute addresses create google-managed-services-default \
    --global \
    --purpose=VPC_PEERING \
    --prefix-length=16 \
    --network=default \
    --project="$PROJECT_ID"

# 予約した範囲を使って、VPCとGoogleのサービスをピアリング接続します
gcloud services vpc-peerings connect \
    --service=servicenetworking.googleapis.com \
    --ranges=google-managed-services-default \
    --network=default \
    --project="$PROJECT_ID"
```

**4. Cloud SQL インスタンスの作成**

プライベートIPを持つMySQL 8.0のインスタンスを作成します。完了まで数分かかります。

```bash
gcloud sql instances create "$INSTANCE_NAME" \
    --database-version=MYSQL_8_0 \
    --edition=ENTERPRISE \
    --tier=db-g1-small \
    --availability-type=zonal \
    --storage-size=10GB \
    --region="$REGION" \
    --network=default \
    --no-assign-ip \
    --project="$PROJECT_ID"
```

**5. データベースとユーザーの作成**

```bash
gcloud sql databases create "$DB_NAME" --instance="$INSTANCE_NAME" --project="$PROJECT_ID"
gcloud sql users create "$DB_USER" --instance="$INSTANCE_NAME" --password="$DB_PASSWORD" --project="$PROJECT_ID"
```

**6. Secret Manager への機密情報登録**

データベースのパスワードと Laravel の `APP_KEY` を Secret Manager に安全に保管します。

```bash
# DBパスワード用のSecretを作成
gcloud secrets create "$DB_PASSWORD_SECRET_NAME" --replication-policy="automatic" --project="$PROJECT_ID"
echo -n "$DB_PASSWORD" | gcloud secrets versions add "$DB_PASSWORD_SECRET_NAME" --data-file=- --project="$PROJECT_ID"

# .envファイルからAPP_KEYを読み込む
export APP_KEY=$(grep '^APP_KEY=' .env | cut -d '=' -f2-)

# APP_KEY用のSecretを作成
gcloud secrets create "$APP_KEY_SECRET_NAME" --replication-policy="automatic" --project="$PROJECT_ID"
echo -n "$APP_KEY" | gcloud secrets versions add "$APP_KEY_SECRET_NAME" --data-file=- --project="$PROJECT_ID"
```

**7. IAMロールの付与**

Cloud Run サービスが Cloud SQL と Secret Manager にアクセスするための権限を付与します。

```bash
gcloud projects add-iam-policy-binding "$PROJECT_ID" \
    --member="serviceAccount:$SERVICE_ACCOUNT" \
    --role="roles/cloudsql.client"

gcloud projects add-iam-policy-binding "$PROJECT_ID" \
    --member="serviceAccount:$SERVICE_ACCOUNT" \
    --role="roles/secretmanager.secretAccessor"
```

**8. Artifact Registry リポジトリの作成**

```bash
gcloud artifacts repositories create "$REPO_NAME" \
  --repository-format=docker \
  --location="$REGION" \
  --project="$PROJECT_ID"
```

---

## ステップ3: Cloud Run サービスの定義とデプロイ

宣言的なYAMLファイルを用いて、サイドカーを含めたサービス全体を定義します。

**1. サービス定義 (`service.yaml`) の準備**

リポジトリ内の `service.yaml` はテンプレートです。以下のコマンドで、ご自身の環境に合わせてプレースホルダーを実際の値に置換します。

```bash
sed -i.bak \
  -e "s|YOUR_SERVICE_NAME|$SERVICE_NAME|g" \
  -e "s|YOUR_SERVICE_ACCOUNT|$SERVICE_ACCOUNT|g" \
  -e "s|YOUR_DB_NAME|$DB_NAME|g" \
  -e "s|YOUR_DB_USER|$DB_USER|g" \
  -e "s|YOUR_DB_PASSWORD_SECRET_NAME|$DB_PASSWORD_SECRET_NAME|g" \
  -e "s|YOUR_APP_KEY_SECRET_NAME|$APP_KEY_SECRET_NAME|g" \
  -e "s|YOUR_INSTANCE_CONNECTION_NAME|$INSTANCE_CONNECTION_NAME|g" \
  service.yaml
```

**2. ビルドとデプロイ**

キャッシュ問題を避けるため、一意なタグを付けたイメージをビルドし、デプロイします。

```bash
# 1. イメージ用の一意なタグを生成
export IMAGE_TAG=$(date +%Y%m%d-%H%M%S)

# 2. 一意なタグを付けてイメージをビルド
gcloud builds submit . \
  --tag "${REGION}-docker.pkg.dev/${PROJECT_ID}/${REPO_NAME}/${IMAGE_NAME}:${IMAGE_TAG}" \
  --project="$PROJECT_ID"

# 3. service.yaml が参照するイメージタグを更新
sed -i.bak "s|image:.*# THIS WILL BE REPLACED.*|image: ${REGION}-docker.pkg.dev/${PROJECT_ID}/${REPO_NAME}/${IMAGE_NAME}:${IMAGE_TAG}|" service.yaml

# 4. 更新したYAMLファイルでサービスをデプロイ
gcloud run services replace service.yaml \
  --region="$REGION" \
  --project="$PROJECT_ID"
```

デプロイ完了後、表示されたサービスURLにアクセスし、LaravelのウェルカムページとDB接続成功メッセージを確認します。

---

## トラブルシューティングと教訓

*   **`podman-compose run` でArtisanコマンドが動かない**: `entrypoint.sh` が `run` で渡された引数を実行するよう正しく設定されていない可能性があります。`if [ "$#" -gt 0 ]; then exec php artisan "$@"; else ...; fi` のような分岐処理を入れることで、`run` と `up` の両方に対応できます。
*   **`APP_KEY` が自動設定されない**: コンテナ内からホストのファイルを書き換えるのは、セキュリティ上の制約で失敗することがあります。`artisan key:generate --show` でキーを表示させ、手動で `.env` にコピー＆ペーストするのが最も確実な方法です。
*   **`run` 実行時にDB接続エラーが出る**: `podman-compose run` は `depends_on` で指定されたサービスを自動起動しません。DB接続が必要なコマンドの場合は、先に `podman-compose up -d db` でDBコンテナを起動しておく必要があります。
*   **イメージの変更が反映されない**: `entrypoint.sh` などを修正した後に `podman-compose run` を実行しても、変更は反映されません。`podman-compose build` を実行して、イメージを明示的に再ビルドする必要があります。
*   **Cloud Run での起動プローブの失敗**: 必ず Cloud Run のログを確認します。`healthz` エンドポイントでDB接続エラーが出ていないか、NginxやPHP-FPMのエラーがないかを確認します。
*   **Secret Manager へのアクセス権**: `service.yaml` で指定した `serviceAccountName` に `roles/secretmanager.secretAccessor` が付与されていることを確認してください。

## クリーンアップ

課金を避けるため、作成したリソースを削除します。

```bash
# Cloud Run サービスの削除
gcloud run services delete "$SERVICE_NAME" --region="$REGION" --project="$PROJECT_ID" --quiet

# Artifact Registry リポジトリの削除
gcloud artifacts repositories delete "$REPO_NAME" --location="$REGION" --project="$PROJECT_ID" --quiet

# Secret の削除
gcloud secrets delete "$DB_PASSWORD_SECRET_NAME" --project="$PROJECT_ID" --quiet
gcloud secrets delete "$APP_KEY_SECRET_NAME" --project="$PROJECT_ID" --quiet

# Cloud SQL インスタンスの削除
gcloud sql instances delete "$INSTANCE_NAME" --project="$PROJECT_ID" --quiet
```
