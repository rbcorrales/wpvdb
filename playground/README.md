# wpvdb Playground demo

A Blueprint and preloader that boot wpvdb in WordPress Playground with the
demo mode constants set and a small seeded fixture loaded. Pairs with the
plan in `PLAYGROUND_FEASIBILITY.md` (edits 12 / 14 / 15 / 16).

## Files

- `blueprint.json`: the Blueprint that Playground consumes. Defines
  `SQLITE_JOURNAL_MODE = MEMORY` and `WPVDB_DEMO_MODE = true`, logs the
  visitor in as admin, installs the plugin, activates it, then runs
  `preload-demo.php`.
- `preload-demo.php`: idempotent preloader. Creates a few sample posts,
  inserts deterministic 768 dim placeholder embeddings, and saves a set of
  preset query vectors to `wp_options.wpvdb_demo_preset_queries` for the
  demo UI to use against `/wpvdb/v1/query?vector=...`.
- This file.

## Public Playground

Update the `installPlugin` step's URL in `blueprint.json` to point at a
public zip of the branch you want to demo. Then load:

```
https://playground.wordpress.net/?blueprint-url=<absolute URL to your blueprint.json>
```

The Blueprint URL must itself be publicly reachable (CORS allowed). For a
quick share, host the JSON on GitHub Pages or a Gist raw URL.

## Local testing with wp-now

`wp-now start --path=./wpvdb-playground` already mounts and activates the
plugin in plugin mode, so the `installPlugin` and `activatePlugin` steps
are no ops locally. The `defineWpConfigConsts` step is also not honored by
wp-now's boot path the same way Playground's host does it (Blueprint steps
run after boot anyway, see the constant-ordering note in
`PLAYGROUND_FEASIBILITY.md`).

To exercise the demo path locally, drop a small mu-plugin into wp-now's
shared mu-plugin dir to define the same constants:

```bash
cat > ~/.wp-now/mu-plugins/0-wpvdb-demo-mode.php <<'EOF'
<?php
if (!defined('SQLITE_JOURNAL_MODE')) define('SQLITE_JOURNAL_MODE', 'MEMORY');
if (!defined('WPVDB_DEMO_MODE')) define('WPVDB_DEMO_MODE', true);
EOF
```

Then reset the wp-now site dir and restart so the constants take effect at
boot. Once running, manually require `preload-demo.php` via wp-cli or just
visit an admin page that triggers a hook where it runs (currently it does
not auto-run locally; see Limitations).

## Limitations (today)

- Embeddings are deterministic unit vectors seeded by post id. The search
  infrastructure works end to end but results are not semantically
  meaningful. Swapping in real `text-embedding-3-small` precomputed
  embeddings (truncated or Matryoshka-projected to 768 dims) is on the
  follow up list.
- `preload-demo.php` runs only via the Blueprint's `runPHP` step in public
  Playground. Local wp-now testing needs to invoke it manually.
- No demo UI yet that calls `/wpvdb/v1/query` with the preset query
  vectors. The data shape is in place (`wp_options.wpvdb_demo_preset_queries`)
  but the React/JS UI on top of it is a separate piece.
