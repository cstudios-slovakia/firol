# Connection — template

This is a template. Actual credentials belong in `.env` (gitignored)
or in GitHub Secrets for CI/CD. NEVER commit them.

## SSH (production server)
- Host: <prod-ssh-host>
- Port: <prod-ssh-port>
- User: <prod-ssh-user>
- Command: `ssh <user>@<host> -p <port>`

## Production URL
https://app.poapp.sk/

## Database (MariaDB)
- Host: <db-host>
- DB name: <db-name>
- User: <db-user>
- Pass: <see .env>

CLI:
```bash
mysql -h <host> -u <user> -p'<pass>' -D <db-name>
```

## GitHub Secrets required for deploy workflow
- `PROD_SSH_HOST`
- `PROD_SSH_PORT`
- `PROD_SSH_USER`
- `PROD_SSH_PASSWORD` (or migrate to `PROD_SSH_PRIVATE_KEY`)
