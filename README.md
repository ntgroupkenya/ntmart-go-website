# NTmart POS Go: website

The marketing website for **NTmart POS Go**, the native Windows rewrite of NTmart POS. It runs on the same MySQL/MariaDB database as the PHP edition.

It is a static site with a classic look and a modern layout: serif headings (Libre Caslon Text), a clean sans-serif body (Instrument Sans), a deep green and brass palette, light mode only. Plain HTML and one stylesheet, with no JavaScript and no build step. Fonts load from Google Fonts, with Georgia and system fonts as fallbacks.

## Pages

- `index.html`: home page (headline, benefits, who it's for, how to get started, questions, demo call-to-action)
- `features.html`: full feature list and what's coming soon
- `contact.html`: book a demo, contact details, pricing note
- `getting-started.html`: technical setup guide (linked from the footer)
- `assets/style.css`: the stylesheet (colours and fonts are set as variables at the top)
- `assets/favicon.svg`: logo and favicon

## Preview locally

Open `index.html` in a browser, or serve the folder:

```sh
python3 -m http.server 8000
```

Then visit <http://localhost:8000>.

## Publish

The site is static, so any static host works. For GitHub Pages, go to **Settings → Pages**, choose **Deploy from a branch**, and pick the branch with `/ (root)`. The `.nojekyll` file makes Pages serve the files as they are.

## Keeping it up to date

The feature lists come from the app's `PLAN.md`. When something in **Coming soon** on `features.html` ships, move it into the main list.

Until Phase 8 (licensing and release) ships, the site must not offer the Go edition for installation at client shops; it says the product is in final testing and invites shops to book a demo.

`contact.html` has placeholder contact details (phone, email, hours) marked with a `TODO`. Replace them before publishing.
