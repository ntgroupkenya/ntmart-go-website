# NTmart POS (Go edition)

A native Windows rewrite of NTmart POS in Go. It runs on the **same MySQL/MariaDB database** as the current PHP version, so both can be used side by side while modules move over. See [PLAN.md](PLAN.md) for the roadmap.

**Status: preview (Phase 1).** It covers signing in and browsing the catalogue. It has no licence enforcement yet, so don't give it to clients.

## Run

```powershell
.\build.ps1
.\NTmartPOS.exe
```

On first run it asks for the database server (default: `localhost:3306`, user `root`, database `ntmart`). The details are saved to `%APPDATA%\NTmartGo\config.json`. Use **Database…** on the sign-in screen to change them. Sign in with an existing NTmart user account.

## Test

```powershell
$env:NTMART_TEST_DSN = 'root@tcp(localhost:3306)/ntmart_godev?parseTime=true&charset=utf8mb4'
go test ./...
```

The integration tests insert and delete rows, so point them only at a disposable copy, never at a client database. Without the variable, only the pure unit tests run.

## Layout

- `main.go`: startup (connect, sign in, main window, sign out)
- `internal/config`: database connection settings
- `internal/store`: data access to the NTmart schema (auth, settings, products)
- `ui`: windows and dialogs (lxn/walk, native Win32 controls)
