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

## Website

This repository also holds the NTmart Go marketing website: plain HTML, CSS and JavaScript with no build step and no jQuery. Open `index.html` in a browser, or serve the folder from any web host. The contact form needs PHP.

### Pages

| Page | Contents |
| --- | --- |
| `index.html` | Home: hero with app mockup, benefits, setup steps, roadmap, call to action |
| `features.html` | Features, with a sticky topbar that highlights the current section |
| `pricing.html` | Plans with a monthly/yearly toggle, plus a comparison table |
| `faq.html` | Questions in expandable cards, with a sticky sidebar |
| `about.html` | Story and values |
| `privacy.html` | Privacy policy with a sticky sidebar |
| `download.html` | Preview download, requirements, install steps |
| `contact.html` | AJAX contact form with validation (posts to `mail.php`) |

### Before going live

- **Prices:** `pricing.html` has placeholder prices. Edit the `data-monthly` and `data-yearly` values and the plan details.
- **Contact details:** set `$emailTo` and `$emailFrom` in `mail.php`, and the email, phone and address in `contact.html`.
- **Privacy policy:** `privacy.html` is template text. Have it reviewed before publishing.
- **Download link:** the button in `download.html` asks visitors to request a preview. Point it at the installer when one is public.

### Colour schemes

There are 8 schemes in `assets/css/themes/`: `blue`, `green`, `red` and `violet`, each also as `-gradient`. Change the theme `<link>` in the `<head>` of every page, for example:

```powershell
(Get-ChildItem *.html) | ForEach-Object { (Get-Content $_) -replace 'themes/blue-gradient.css', 'themes/green.css' | Set-Content $_ }
```

Every colour is a CSS variable at the top of `assets/css/main.css`, so a new scheme is just a small file that overrides `--accent`, `--accent-dark`, `--accent-soft` and `--hero-bg`.

### Building blocks

- **Grid:** `.container`, `.row` and 12 columns. `.col-N` applies at all widths, `.col-m-N` below 1200px, `.col-t-N` below 990px and `.col-l-N` below 768px. Hide elements with `.d-none`, `.d-t-none` or `.d-l-none`.
- **Header:** `<header class="header-home">` with `.background.background--wave` for the wave edge. Use `--wave-light` when the next section is `.section--light`. Add `.header-home--center-content` to centre the content.
- **Sections:** `.section`, plus `--light`, `--dark`, `--accent`, `--half` or `--tight`. Put the title in `.section__head` with `.section__eyebrow`, `.section__title` and `.section__description`.
- **Buttons:** `.site-btn` plus `--accent`, `--dark`, `--light`, `--invert` or `--ghost` (outline on coloured backgrounds). `--small` and `--block` change the size.
- **Links:** `.link--accent`, `.link--accent-bold`, `.link--gray`.
- **Cards:** `.card` with `.card__icon`, `.card__title` and `.card__text`. Also `.check-list`, `.steps`/`.step`, `.illustration`, `.notice` and `.badge`.
- **FAQ cards:** native `<details class="faq__card card">`, so they work without JavaScript.
- **Sidebar and topbar navigation:** add `data-spy="item"` (sidebar) or `data-spy="link"` (topbar) to the `<nav>`. Its `#anchor` links highlight as their sections scroll into view.
- **Pricing toggle:** a checkbox with `id="billing"`. Any element with `data-monthly` and `data-yearly` switches its text.
- **Forms:** add `data-ajax` to a `<form>` for validation (`required`, `type="email"`, `pattern`, `minlength`) and submission with `fetch`. The handler must return JSON `{ "ok": true|false, "message": "..." }`. A `<select data-prefill>` is filled from the query string, for example `contact.html?topic=preview`.
- **Menu:** the same menu markup is in every page. When you add a page, update both the desktop nav and `#mobile-menu`.

All behaviour is in `assets/js/main.js`, which is loaded on every page. Fonts come from Google Fonts (Nunito) and icons from [Material Design Icons](https://pictogrammers.com/library/mdi/) (`<i class="mdi mdi-NAME"></i>`).
