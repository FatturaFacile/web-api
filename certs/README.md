# Local HTTPS certificates

`ng serve` runs over HTTPS and reads `certs/localhost.crt` and `certs/localhost.key`.
Each developer generates their own pair with [mkcert](https://github.com/FiloSottile/mkcert); the files are git-ignored.
Run the commands below from the repository root.

## macOS

```bash
brew install mkcert
brew install nss   # only if you use Firefox
mkcert -install
mkcert -cert-file certs/localhost.crt -key-file certs/localhost.key localhost 127.0.0.1
```

## Windows (PowerShell)

```powershell
winget install FiloSottile.mkcert
mkcert -install
mkcert -cert-file certs/localhost.crt -key-file certs/localhost.key localhost 127.0.0.1
```

Restart the browser after `mkcert -install`, then open `https://localhost:4200`.
