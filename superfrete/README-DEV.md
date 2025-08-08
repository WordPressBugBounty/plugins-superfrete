# SuperFrete WooCommerce Development Environment

This is a complete development environment for the SuperFrete WooCommerce plugin using Nix and Docker.

## Prerequisites

- [Nix package manager](https://nixos.org/download.html)
- [direnv](https://direnv.net/docs/installation.html)
- [Docker](https://docs.docker.com/get-docker/)
- [Docker Compose](https://docs.docker.com/compose/install/)

## Getting Started

1. Allow direnv to use the Nix flake:

```bash
direnv allow
```

2. Start the Docker containers:

```bash
docker-compose up -d
```

3. The environment will be set up automatically, including:
   - WordPress installation
   - WooCommerce plugin activation
   - SuperFrete plugin activation
   - Basic store configuration with Brazilian settings

4. Access the environment:
   - WordPress site: http://localhost:8087
   - Admin login: admin/admin
   - phpMyAdmin: http://localhost:8086 (server: db, username: root, password: rootpassword)

## Development Workflow

The current directory is mounted as the SuperFrete plugin directory in WordPress. Any changes you make to the plugin files will be immediately reflected in the WordPress environment.

## WP-CLI Commands

You can run WP-CLI commands with:

```bash
docker-compose run --rm wp-cli wp <command>
```

For example, to list all plugins:

```bash
docker-compose run --rm wp-cli wp plugin list
```

## Database Management

Use phpMyAdmin at http://localhost:8086 or connect directly with:

```bash
docker-compose exec db mysql -u wordpress -pwordpress wordpress
```

## Troubleshooting

- If WordPress can't connect to the database, wait a few moments for MySQL to initialize fully.
- If plugins don't activate automatically, activate them manually through the WordPress admin.
- If you encounter architecture compatibility issues (ARM64/Apple Silicon):
  - The docker-compose.yml uses ARM64-compatible images
  - If you still have issues, you may need to use `--platform linux/amd64` for specific services
- To reset the environment completely:
  ```bash
  docker-compose down -v
  docker-compose up -d
  ```

## Testing the SuperFrete Plugin

1. Log in to the WordPress admin
2. Go to WooCommerce -> Settings -> Shipping -> Shipping Options
3. Configure your SuperFrete API credentials
4. Create a test product
5. Test the shipping calculator on the product page 
