# Deep Search & Replace

Site-wide search & replace for WordPress that finds text wherever it is actually stored:
Elementor, Gutenberg/block themes, classic themes and ACF.

## Why ordinary search & replace misses things

| Where the text lives | How it is stored | How this plugin handles it |
|---|---|---|
| Elementor (`_elementor_data`) | JSON with `—` escapes | JSON-aware replace, validated, then Elementor CSS/element cache cleared |
| Gutenberg / ACF blocks | HTML + JSON in `<!-- wp:acf/... {...} -->` comments | Block comments are treated as JSON, HTML as HTML |
| ACF fields, options pages, widgets, Customizer | Post meta / options, often PHP-serialized | Serialized data is rebuilt with correct lengths (objects are never instantiated) |
| Content typed in the editor | `—`, `&mdash;`, `&#8212;`, `&#x2014;` | All encoded forms matched ("Encoded forms") |
| Theme templates, block theme `parts/` `templates/` `patterns/` | Files | **Theme files** tab: scan, preview, replace with backup |
| `--` turned into a dash by WordPress, SEO title separators, plugin output | Generated at render time | **Live output filter** rewrites the final HTML |

## Usage (Tools > Deep Search & Replace)

1. **Rules**: the em dash → `, ` preset is loaded. Keep *Absorb spaces* on so `word — word` becomes `word, word` instead of `word ,  word`.
2. **Database**: **Dry run** first, check the examples, then **Replace now**. Revisions are skipped by default. Slugs, GUIDs, meta keys and option names are never changed.
3. **Theme files**: scan, review line-by-line changes, replace. Originals are saved to `uploads/dsr-backups/`.
4. **Live output filter** (optional): a safety net for anything not stored in the database.
5. **History & undo**: every run can be undone. Values edited after the run are left alone.
6. **Restore pages**: if only some pages should go back, open *Restore pages* on a run, find the page
   (filter by type / post type, search by title or ID, 50 per page) and click *Restore original*. That puts
   back the page's title, content, all its fields (Elementor, ACF), its Yoast row and its changed revisions;
   the rest of the run stays replaced. Options, terms, users and comments are listed as their own items.
   Runs made before 1.3.0 are indexed once (in batches) the first time the list is opened.

## URL map (fix links that go through a redirect)

1. Export your redirects as `old URL, new URL` (one per line). Paths (`/old/`) or full URLs both work.
2. Paste them into **URL map**, or load the CSV. Add your live domain under **Site domains** when working on a staging copy whose content links to the live site.
3. **Check map**: shows skipped lines (loops, other domains, regex or query string sources, the home page) and chains it followed (A to B to C becomes A to C).
4. Remove any text rules you don't want (for example the em dash preset), then **Dry run**. The result lists every link it would change; download it as CSV for review.
5. **Replace now**, then crawl the site again.

Matching is by whole URL: `/heroin/` matches `href="/heroin"`, `https://www.example.com/heroin/?utm=x` and Elementor's `https:\/\/example.com\/heroin\/`, but never `/heroin/detox/` or `/heroin-rehab/`. Relative paths are only matched inside quotes or as a whole stored value (menu item URLs, ACF link fields), not in running text. Redirect data (Rank Math, Redirection, Yoast Premium, Simple 301 Redirects, Safe Redirect Manager) is never changed.

## WP-CLI

```
wp dsr replace --dry-run          # em dash preset, report only
wp dsr replace --files            # database + active/parent theme files
wp dsr replace --search="Acme Inc" --replace="Acme LLC"
wp dsr replace --url-map=redirects.csv --domains=example.com --dry-run --links-csv=links.csv
wp dsr history
wp dsr undo <run-id>
wp dsr restore <run-id> <post-id>                 # one page only
wp dsr restore <run-id> <option-id> --type=option
```

## Notes

- Take a full database backup before the first real run anyway.
- If a third-party theme gets updated, file changes are overwritten. Use a child theme or the live output filter.
- CDN caches (Cloudflare etc.) are not purged automatically. Purge them after a run.
- Tests: `php tests/url-map-test.php`
