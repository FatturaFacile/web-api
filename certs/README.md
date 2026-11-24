# Local HTTPS certificates

Both `ng serve` (frontend) and `Caddyfile.local` (backend API on `https://localhost:8080`) run over HTTPS and read `certs/localhost.crt` and `certs/localhost.key`.
Each developer generates their own pair with [mkcert](https://github.com/FiloSottile/mkcert); the files are git-ignored.
Run the commands below from the repository root.

## macOS

```bash
brew install mkcert
brew install nss   # only if you use Firefox
mkcert -install
mkcert -cert-file certs/localhost.crt -key-file certs/localhost.key localhost 127.0.0.1 ::1
```

## Windows (PowerShell)

```powershell
winget install FiloSottile.mkcert
winget install CaddyServer.Caddy
mkcert -install
mkcert -cert-file certs/localhost.crt -key-file certs/localhost.key localhost 127.0.0.1 ::1
```

## Running the local HTTPS API

```powershell
# Terminal 1: PHP worker on internal HTTP port 8001
php artisan serve --host=127.0.0.1 --port=8001

# Terminal 2: Caddy TLS reverse proxy on https://localhost:8080
caddy run --config Caddyfile.local --adapter caddyfile
```

Restart the browser after `mkcert -install`, then open `https://localhost:8080/up` or the frontend at `https://localhost:4200`.

