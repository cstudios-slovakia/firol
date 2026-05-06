# Connection — template

This is a template. Actual credentials belong in `.env` (gitignored)
or in GitHub Secrets for CI/CD. NEVER commit them.

## SSH (Websupport hosting)
- Host: shell.r5.websupport.sk
- Port: 29607
- User: <your-uid>
- Command: `ssh <your-uid>@shell.r5.websupport.sk -p 29607`

## Production URL
https://firol.cstudios.ninja/
Deploy target: cstudios.ninja/sub/firol

## Database (MariaDB, external Websupport host)
- Host: l8uz.your-database.de
- DB name: <db-name>
- User: <db-user>
- Pass: <see .env>

CLI:
```bash
mysql -h <host> -u <user> -p'<pass>' -D <db-name>
```

## GitHub Secrets required for deploy workflow
- `SSH_HOST` = shell.r5.websupport.sk
- `SSH_PORT` = 29607
- `SSH_USER` = <your-uid>
- `SSH_PASSWORD` = <your-ssh-password>   (or prefer `SSH_PRIVATE_KEY`)
