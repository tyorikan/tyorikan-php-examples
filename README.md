# PHP Examples for Google Cloud

このリポジトリは、Google Cloud で PHP アプリケーションを動かすための、様々な構成パターンをまとめたハンズオン集です。

## ハンズオンコンテンツ

### [01-php-mysql-demo](./01-php-mysql-demo/)

基本的な `PHP-FPM` + `Nginx` + `MySQL` の3層構成です。`docker-compose` を使ってローカル環境を構築します。

```mermaid
graph TD
    subgraph "Docker Compose"
        User -- "HTTP/HTTPS" --> Nginx
        Nginx -- "FastCGI" --> PHP
        PHP -- "TCP" --> MySQL[(MySQL)]
    end
```

### [02-laravel-mysql-demo](./02-laravel-mysql-demo/)

`01-php-mysql-demo` と同じ3層構成ですが、PHPフレームワークとして `Laravel` を使用した例です。

```mermaid
graph TD
    subgraph "Docker Compose"
        User -- "HTTP/HTTPS" --> Nginx
        Nginx -- "FastCGI" --> Laravel[PHP-FPM / Laravel]
        Laravel -- "TCP" --> MySQL[(MySQL)]
    end
```

### [03-php-nginx-sidecar-demo](./03-php-nginx-sidecar-demo/)

Cloud Run の **サイドカー（マルチコンテナ）機能** を使った構成です。1つの Cloud Run サービスの中で、`Nginx` コンテナと `PHP-FPM` コンテナを同時に動かします。

```mermaid
graph TD
    subgraph "Cloud Run Service"
        direction LR
        subgraph "Pod"
            User -- "HTTP/HTTPS" --> Nginx[Container: Nginx]
            Nginx -- "localhost" --> PHP[Container: PHP-FPM]
        end
    end
```

### [04-php-buildpacks-demo](./04-php-buildpacks-demo/)

`Dockerfile` を使わず、Google Cloud Buildpacks によって単一のコンテナイメージをビルドする構成です。コンテナの中には `Nginx` と `PHP-FPM` が両方含まれています。

```mermaid
graph TD
    subgraph "Cloud Run Service"
        User -- "HTTP/HTTPS" --> Container["Container<br>(Nginx + PHP-FPM)"]
    end
```
