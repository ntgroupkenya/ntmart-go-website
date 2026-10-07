# NTmart POS Go: website

The product website for **NTmart POS Go**, the native Windows rewrite of NTmart POS. It runs on the same MySQL/MariaDB database as the PHP edition.

It is a static site: plain HTML, CSS and a little JavaScript, with no build step.

## Pages

- `index.html`: home page (overview, why Go, features, roadmap, FAQ)
- `getting-started.html`: requirements, build and run, database connection, running next to PHP, developer notes
- `assets/style.css`: styles, with light and dark themes
- `assets/site.js`: theme toggle, mobile menu, copy buttons on code blocks
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

The roadmap and feature lists come from the app's `PLAN.md`. Update the **Roadmap** section of `index.html` when a phase changes status.

Until Phase 8 (licensing and release) ships, the site must keep saying that the Go edition is a preview and must not be given to clients.

The contact call-to-action at the bottom of `index.html` has a `TODO` for the real NTmart contact link.
