# Cloud Run で動かす PHP + Nginx (サイドカー) + MySQL: 実践ハンズオンガイド

このリポジトリは、コンテナ化された PHP と MySQL アプリケーションを Google Cloud Run にデプロイするための、完全なハンズオンガイドです。Direct VPC Egress を利用した安全なデータベース接続と、サイドカーパターンによる効率的なコネクションプーリングを特徴とする、堅牢で本番環境に対応可能なアーキテクチャを構築します。

このドキュメントは、反復的な開発とトラブルシューティングのプロセスの最終成果であり、その過程で得られたベストプラクティスと教訓をまとめたものです。

## 最終的なアーキテクチャ

*   **アプリケーション実行環境**: Cloud Run
*   **Webサーバー**: Nginx (メインコンテナ)
*   **PHP-FPM**: PHP-FPM (サイドカー)
*   **データベース**: Cloud SQL for MySQL (プライベートIP経由で接続)
*   **セキュアな接続**: Direct VPC Egress
*   **コネクションプーリング**: Cloud SQL Auth Proxy (サイドカーコンテナとして)
*   **ヘルスチェック**: アプリケーションとプロキシ双方のカスタム起動プローブ
*   **構成管理**: `service.yaml` を用いた Infrastructure as Code (IaC) アプローチ
*   **ローカル開発環境**: `podman-compose` とローカルMySQLデータベース

## このガイドで学べること

*   Nginx と PHP-FPM をそれぞれ別のコンテナとして構成する方法
*   `podman-compose` によるローカル開発環境のセットアップ
*   Secret Manager を用いた安全な機密情報の管理
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

このステップでは、ローカルマシン上で完結する開発環境を構築します。各ファイルはリポジトリ内に含まれています。

**1. ファイルの確認**

リポジトリ内の各ファイルの役割を確認します。

*   `php-app/.env`: ローカル用の環境変数ファイル
*   `php-app/index.php`: メインのアプリケーション
*   `php-app/health.php`: ヘルスチェック用エンドポイント
*   `nginx/nginx.conf.template`: Nginx の設定テンプレート
*   `nginx/entrypoint.sh`: Nginx コンテナの起動スクリプト
*   `php-app/Dockerfile`: PHP-FPM コンテナの定義
*   `nginx/Dockerfile`: Nginx コンテナの定義
*   `compose.yaml`: ローカル環境のサービス定義
*   `php-app/.gitignore`: Gitの無視ファイル
*   `php-app/.dockerignore`: Dockerの無視ファイル

**2. ローカルでの実行**

コンテナをビルドして起動します。

```bash
podman-compose up --build
```

アプリケーションが動作していることを確認します。

```bash
curl http://localhost:8080
# 期待される出力: Hello World! Successfully connected to MySQL version: ...
```

---

## ステップ2: Google Cloud 環境のセットアップ

デプロイに必要なGCPサービスとIAM権限を準備します。

**1. 環境変数の設定**

まず、ご自身の環境に合わせて以下の変数を設定してください。このシェルで設定した変数は、後続のコマンドで自動的に使用されます。

```bash
# --- ユーザーが設定する項目 --- #
export PROJECT_ID="[YOUR_PROJECT_ID]"
export REGION="asia-northeast1"
export SERVICE_NAME="hello-php-nginx"
export REPO_NAME="php-nginx-repo"
export INSTANCE_NAME="mysql-demo"
export DB_USER="[YOUR_DB_USER]"
export DB_PASSWORD="[YOUR_DB_PASSWORD]"
export DB_NAME="demo"
# --- 設定項目ここまで --- #

# 以下の変数は自動で設定されます
export INSTANCE_CONNECTION_NAME="${PROJECT_ID}:${REGION}:${INSTANCE_NAME}"
export SECRET_NAME="${SERVICE_NAME}-db-password"
export PROJECT_NUMBER=$(gcloud projects describe "$PROJECT_ID" --format='value(projectNumber)')
export SERVICE_ACCOUNT="${PROJECT_NUMBER}-compute@developer.gserviceaccount.com"
export PHP_IMAGE_NAME="php-app"
export NGINX_IMAGE_NAME="nginx"
```

**2. APIの有効化**

ハンズオンに必要なAPIを有効化します。

```bash
gcloud services enable sqladmin.googleapis.com secretmanager.googleapis.com artifactregistry.googleapis.com cloudbuild.googleapis.com run.googleapis.com servicenetworking.googleapis.com --project="$PROJECT_ID"
```

**3. プライベートサービス接続の設定**

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

プライベートIPを持つMySQL 8.0のインスタンスを作成します。デモ用途のため、コストを抑えた最小構成を指定します。完了まで数分かかります。

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

作成したインスタンス内に、アプリケーションが使用するデータベースとユーザーを作成します。

```bash
gcloud sql databases create "$DB_NAME" --instance="$INSTANCE_NAME" --project="$PROJECT_ID"

gcloud sql users create "$DB_USER" --instance="$INSTANCE_NAME" --password="$DB_PASSWORD" --project="$PROJECT_ID"
```

**6. DBパスワード用の Secret を作成**

データベースのパスワードを Secret Manager に安全に保管します。

```bash
gcloud secrets create "$SECRET_NAME" --replication-policy="automatic" --project="$PROJECT_ID"

# Secretがシステムに伝播するまで10秒待機します
echo "Waiting for secret to be created..."
sleep 10

echo -n "$DB_PASSWORD" | gcloud secrets versions add "$SECRET_NAME" --data-file=- --project="$PROJECT_ID"
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

コンテナイメージを保管する場所を作成します。

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
# service.yaml 内のプレースホルダーを環境変数の値で置換します
sed -i.bak \
  -e "s|YOUR_SERVICE_NAME|$SERVICE_NAME|g" \
  -e "s|YOUR_SERVICE_ACCOUNT|$SERVICE_ACCOUNT|g" \
  -e "s|YOUR_DB_NAME|$DB_NAME|g" \
  -e "s|YOUR_DB_USER|$DB_USER|g" \
  -e "s|YOUR_SECRET_NAME|$SECRET_NAME|g" \
  -e "s|YOUR_INSTANCE_CONNECTION_NAME|$INSTANCE_CONNECTION_NAME|g" \
  service.yaml
```

**2. ビルドとデプロイ**

キャッシュ問題を避けるため、一意なタグを付けたイメージをビルドし、デプロイします。

```bash
# 1. イメージ用の一意なタグを生成
export IMAGE_TAG=$(date +%Y%m%d-%H%M%S)

# 2. PHP-FPM イメージをビルド
gcloud builds submit ./php-app \
  --tag "${REGION}-docker.pkg.dev/${PROJECT_ID}/${REPO_NAME}/${PHP_IMAGE_NAME}:${IMAGE_TAG}" \
  --project="$PROJECT_ID"

# 3. Nginx イメージをビルド
gcloud builds submit ./nginx \
  --tag "${REGION}-docker.pkg.dev/${PROJECT_ID}/${REPO_NAME}/${NGINX_IMAGE_NAME}:${IMAGE_TAG}" \
  --project="$PROJECT_ID"

# 4. service.yaml が参照するイメージタグを更新
sed -i.bak "s|image:.*# THIS WILL BE REPLACED BY THE BUILD COMMAND (nginx).*|image: ${REGION}-docker.pkg.dev/${PROJECT_ID}/${REPO_NAME}/${NGINX_IMAGE_NAME}:${IMAGE_TAG}|" service.yaml
sed -i.bak "s|image:.*# THIS WILL BE REPLACED BY THE BUILD COMMAND (php-app).*|image: ${REGION}-docker.pkg.dev/${PROJECT_ID}/${REPO_NAME}/${PHP_IMAGE_NAME}:${IMAGE_TAG}|" service.yaml

# 5. 更新したYAMLファイルでサービスをデプロイ
gcloud run services replace service.yaml \
  --region="$REGION" \
  --project="$PROJECT_ID"
```

デプロイ完了後、表示されたサービスURLにアクセスし、「Successfully connected」メッセージを確認します。

---

## トラブルシューティングと教訓

*   **`gcloud` の `grpc` エラー**: ローカルの Google Cloud CLI のインストールに問題があることを示します。最も確実な修正方法は、CLIの完全な再インストールです。
*   **起動プローブの失敗**: 必ず Cloud Run のログを確認します。失敗の理由（404 Not Found, Connection Refused, Permission Denied など）が記録されています。
*   **サイドカーの依存関係**: あるコンテナがサイドカーに依存する場合、そのサイドカー自身も `startupProbe` を持ち、いつ準備完了になったかを Cloud Run に伝える必要があります。
*   **プロキシの待受アドレス**: Cloud SQL Auth Proxy は、Cloud Run のヘルスチェッカーがアクセスできるよう、`0.0.0.0` でリッスンする必要があります。デフォルトの `127.0.0.1` ではプローブが失敗します。
*   **不変なイメージタグ**: `:latest` タグはキャッシュにより予期せぬ動作を引き起こす可能性があります。本番デプロイでは、常にタイムスタンプやGitのコミットハッシュのような、一意で不変なタグを使用すべきです。

## クリーンアップ

課金を避けるため、作成したリソースを削除します。

```bash
# Cloud Run サービスの削除
gcloud run services delete "$SERVICE_NAME" --region="$REGION" --project="$PROJECT_ID" --quiet

# Artifact Registry リポジトリの削除
gcloud artifacts repositories delete "$REPO_NAME" --location="$REGION" --project="$PROJECT_ID" --quiet

# Secret の削除
gcloud secrets delete "$SECRET_NAME" --project="$PROJECT_ID" --quiet

# Cloud SQL インスタンスの削除
gcloud sql instances delete "$INSTANCE_NAME" --project="$PROJECT_ID" --quiet
```