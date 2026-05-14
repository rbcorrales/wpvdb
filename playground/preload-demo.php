<?php
/**
 * wpvdb Playground demo preloader.
 *
 * Runs once during Blueprint boot via the `runPHP` step. Seeds the demo with:
 *   - a handful of sample posts
 *   - one precomputed 768-dim embedding per post
 *   - a small set of preset query vectors that the demo UI can post to
 *     `/wpvdb/v1/query` with the `vector` field (the demo-only bypass added
 *     in edit 11 of PLAYGROUND_FEASIBILITY.md).
 *
 * Embeddings here are deterministic unit vectors seeded by post id, not real
 * semantic embeddings. The infrastructure is exercised end to end, but search
 * results are not meaningful. Replacing this with real precomputed embeddings
 * from `text-embedding-3-small` (truncated/projected to 768 dims) is on the
 * follow-up list.
 *
 * Idempotent: bails out if the demo data was already loaded for this WP
 * install (checked via the `wpvdb_demo_preloaded` option).
 *
 * @package WPVDB\Playground
 */

defined('ABSPATH') || exit;

if (! defined('WPVDB_DEMO_MODE') || ! WPVDB_DEMO_MODE) {
	return;
}

if (get_option('wpvdb_demo_preloaded')) {
	return;
}

if (! defined('WPVDB_DEFAULT_EMBED_DIM')) {
	// Plugin didn't load fully; bail. Don't mark preloaded so the next boot retries.
	return;
}

$dim = (int) WPVDB_DEFAULT_EMBED_DIM;
if ($dim < 1) {
	return;
}

/**
 * Try to load real precomputed text-embedding-3-small vectors from
 * `playground/demo-vectors.json`. The file is generated offline via
 * `tools/generate-playground-demo-vectors.py`. When present and valid, the
 * preloader uses these vectors so the demo demonstrates real semantic
 * similarity. When absent or malformed (e.g. wrong dimension, missing keys),
 * the preloader falls back to the deterministic placeholder generator below.
 *
 * Shape: `{ "model": ..., "dimensions": N, "posts": { "<seed>": [...] }, "presets": { "<slug>": [...] } }`.
 *
 * @return array|null `[ 'posts' => [seed=>vec], 'presets' => [slug=>vec] ]` or null on failure.
 */
$real_vectors_path = __DIR__ . '/demo-vectors.json';
$real_vectors = null;
if (is_readable($real_vectors_path)) {
	$raw = json_decode((string) file_get_contents($real_vectors_path), true);
	if (is_array($raw)
		&& isset($raw['dimensions'], $raw['posts'], $raw['presets'])
		&& (int) $raw['dimensions'] === $dim
		&& is_array($raw['posts'])
		&& is_array($raw['presets'])) {
		$real_vectors = array(
			'posts'   => $raw['posts'],
			'presets' => $raw['presets'],
		);
	}
}

/**
 * Generate a deterministic unit vector of the configured dimension.
 *
 * Seeded by integer so the same seed yields the same vector across runs.
 * Output is L2-normalized to unit length so cosine math behaves predictably.
 * Used only when `demo-vectors.json` is missing or malformed.
 *
 * @param int $seed
 * @param int $dim
 * @return float[]
 */
$make_vector = function ($seed, $dim) {
	mt_srand((int) $seed);
	$v = array();
	$norm_sq = 0.0;
	for ($i = 0; $i < $dim; $i++) {
		$x = (mt_rand(-1000000, 1000000) / 1000000.0);
		$v[] = $x;
		$norm_sq += $x * $x;
	}
	$norm = sqrt($norm_sq);
	if ($norm <= 0.0) {
		// Degenerate; return a safe fallback unit vector along axis 0.
		$v = array_fill(0, $dim, 0.0);
		$v[0] = 1.0;
		return $v;
	}
	for ($i = 0; $i < $dim; $i++) {
		$v[$i] = $v[$i] / $norm;
	}
	return $v;
};

/**
 * Resolve a post embedding by seed. Prefers a real vector from demo-vectors.json
 * when one is loaded for this seed; otherwise returns the deterministic
 * placeholder. The vector source is opaque to the storage layer; the row's
 * model column tags it as `wpvdb-demo-768` either way so the admin filter
 * and the JS payload reference a single stable identifier.
 */
$lookup_post_vector = function ($seed) use ($real_vectors, $make_vector, $dim) {
	if ($real_vectors !== null && isset($real_vectors['posts'][(string) $seed])) {
		$candidate = $real_vectors['posts'][(string) $seed];
		if (is_array($candidate) && count($candidate) === $dim) {
			return array_map('floatval', $candidate);
		}
	}
	return $make_vector($seed, $dim);
};

/**
 * Resolve a preset query embedding by slug, falling back to the deterministic
 * placeholder seeded by the legacy seed integer when no real vector is loaded.
 */
$lookup_preset_vector = function ($slug, $fallback_seed) use ($real_vectors, $make_vector, $dim) {
	if ($real_vectors !== null && isset($real_vectors['presets'][$slug])) {
		$candidate = $real_vectors['presets'][$slug];
		if (is_array($candidate) && count($candidate) === $dim) {
			return array_map('floatval', $candidate);
		}
	}
	return $make_vector($fallback_seed, $dim);
};

// 20 demo posts across 4 topics (cooking, travel, wordpress, outdoors).
// Seeds 1-20 are the JSON keys that tools/generate-playground-demo-vectors.py
// writes into demo-vectors.json; if you change a post's seed or content here,
// mirror the change in that script's DEMO_POSTS list and re-run it.
$sample_posts = array(
	// --- Cooking (seeds 1-5) ---
	array(
		'title'   => 'Pasta carbonara',
		'content' => "Whisk eggs with grated pecorino and black pepper. Crisp guanciale in a hot pan until rendered, then toss hot pasta in the fat. Remove from heat and stir in the egg mixture with a splash of pasta water; the residual heat sets a silky sauce without scrambling.",
		'seed'    => 1,
	),
	array(
		'title'   => 'Slow-cooked beef stew for cold evenings',
		'content' => "Brown the beef in batches so it sears rather than steams. Build a stock with onion, carrot, garlic, red wine, bay, and thyme. Simmer covered for three hours until the connective tissue gives way and the meat falls apart at the touch of a fork.",
		'seed'    => 2,
	),
	array(
		'title'   => 'Sourdough basics',
		'content' => "Feed a starter for at least a week before baking. Mix flour and water, autolyse for an hour, then fold every thirty minutes through the bulk ferment. Shape the dough, proof cold overnight, score the top, and bake in a covered Dutch oven.",
		'seed'    => 3,
	),
	array(
		'title'   => 'Vegetable curry',
		'content' => "Bloom whole cumin, coriander, and mustard seeds in ghee until they pop. Add onion, garlic, ginger, and a tin of tomato. Simmer cauliflower, potato, and chickpeas in coconut milk until tender. Finish with cilantro, lime, and a pinch of salt.",
		'seed'    => 4,
	),
	array(
		'title'   => 'Knife skills primer',
		'content' => "A sharp knife is safer than a dull one because it stays where you put it. Curl your fingertips back into a claw and anchor the blade against your knuckles. Practice the rock chop on a bunch of parsley before tackling onions or shallots.",
		'seed'    => 5,
	),

	// --- Travel (seeds 6-10) ---
	array(
		'title'   => 'Lisbon alleys',
		'content' => "The cobblestone alleys of Alfama widen into a square where vendors sell bread and azulejos. Pigeons scatter as a yellow tram clatters past. Climb to Castelo de Sao Jorge for a view across the Tagus to the Cristo Rei statue on the far bank.",
		'seed'    => 6,
	),
	array(
		'title'   => 'Tokyo morning markets',
		'content' => "Tsukiji outer market wakes at five. Stall holders slice maguro under fluorescent light while regulars line up for tamagoyaki on a stick and freshly grilled scallops in their shells. Walk through before the tour buses arrive and the prices double.",
		'seed'    => 7,
	),
	array(
		'title'   => 'Marrakech souks',
		'content' => "Beyond Jemaa el-Fnaa the souks fold into themselves: brass lanterns, leather slippers, mountains of saffron and ras el hanout. Tea is poured from a meter above the glass to froth the surface; refuse the cup and you have already accepted the next round of bargaining.",
		'seed'    => 8,
	),
	array(
		'title'   => 'Edinburgh closes',
		'content' => "The Royal Mile drops away into narrow closes that lead to hidden courtyards. Each has a story: plague pits walled up at Mary King's Close, the ghost of Greyfriars Bobby, smugglers, poets, the doctor whose dissections inspired Jekyll and Hyde.",
		'seed'    => 9,
	),
	array(
		'title'   => 'Panama City, Panama',
		'content' => "Casco Viejo's colonial balconies look across the bay to a skyline of glass towers. Walk the seawall at dusk while the city lights come up, then duck into a salsa club where locals dance until the bands stop and the streets cool down enough to walk back.",
		'seed'    => 10,
	),

	// --- WordPress / web dev (seeds 11-15) ---
	array(
		'title'   => 'Vector search with wpvdb',
		'content' => "WordPress sites can do semantic search by storing embeddings alongside posts. The wpvdb plugin handles chunking long content, generating embeddings through OpenAI or a custom provider, and querying similarity against a custom MariaDB table using native VECTOR functions.",
		'seed'    => 11,
	),
	array(
		'title'   => 'Headless WordPress',
		'content' => "Decouple the front end by treating WordPress as a content API. React, Next.js, or Astro can render pages from the REST API or a GraphQL gateway while the WP admin stays familiar to editors. Authentication still flows through application passwords or JWT.",
		'seed'    => 12,
	),
	array(
		'title'   => 'Block themes',
		'content' => "Theme.json declares the design system: typography scales, color palettes, layout widths, spacing tokens. Block templates replace PHP template hierarchy files, so editors can rearrange the front end visually without touching code or losing the existing performance budget.",
		'seed'    => 13,
	),
	array(
		'title'   => 'REST API endpoints',
		'content' => "Custom endpoints register through register_rest_route with a namespace, route, methods, callback, and permission_callback. Authentication uses cookies for browsers, application passwords for scripts and CLI, and OAuth for third-party integrations like Zapier or Make.",
		'seed'    => 14,
	),
	array(
		'title'   => 'Performance budgets',
		'content' => "Set a Lighthouse target for every page template and fail CI when it regresses. Lazy-load images, defer non-critical JavaScript, use Server Timing headers to spot slow database queries before they ship, and pin a budget per route in the pull request template.",
		'seed'    => 15,
	),

	// --- Outdoors / nature (seeds 16-20) ---
	array(
		'title'   => 'Sierra hiking',
		'content' => "The granite spine of the Sierra rises above tree line by mid-July. Carry layers; afternoon thunderstorms build over the high passes around two even in the dry months. The trail from Tuolumne Meadows to Mount Conness is a classic introduction to high alpine terrain.",
		'seed'    => 16,
	),
	array(
		'title'   => 'Native pollinators',
		'content' => "Bumblebees pollinate plants honeybees cannot reach because they vibrate flowers loose. Mason bees emerge in early spring, weeks before honeybees are even active. Plant native wildflowers in clumps across a full season of bloom to support both species.",
		'seed'    => 17,
	),
	array(
		'title'   => 'Coastal birding',
		'content' => "Migrating shorebirds stage on mudflats in spring and fall. Bring a spotting scope; semipalmated plovers and least sandpipers look identical at a hundred meters without one. Best light is the first two hours after sunrise, before the tide pushes the birds inland.",
		'seed'    => 18,
	),
	array(
		'title'   => 'Winter trail prep',
		'content' => "Microspikes handle packed snow and rolling terrain. Crampons and an ice axe are the line you cross into mountaineering, and they need practice on a low-consequence slope first. Always carry a beacon, shovel, and probe when traveling in avalanche country.",
		'seed'    => 19,
	),
	array(
		'title'   => 'Mushroom foraging',
		'content' => "Chanterelles fruit after summer rain in oak and conifer forests across the Pacific Northwest. Look for the golden vase shape with false gills and a peppery, fruity scent. When in doubt, photograph and ask a local mycologist; toxic species can look identical to a beginner.",
		'seed'    => 20,
	),
);

global $wpdb;
$table = $wpdb->prefix . 'wpvdb_embeddings';

foreach ($sample_posts as $sample) {
	$post_id = wp_insert_post(array(
		'post_title'   => $sample['title'],
		'post_content' => $sample['content'],
		'post_status'  => 'publish',
		'post_type'    => 'post',
	), true);

	if (is_wp_error($post_id) || ! $post_id) {
		continue;
	}

	$embedding = $lookup_post_vector($sample['seed']);
	$wpdb->insert(
		$table,
		array(
			'doc_id'         => $post_id,
			'doc_type'       => 'post',
			'chunk_id'       => 'chunk-0',
			'chunk_index'    => 0,
			'chunk_content'  => $sample['content'],
			'model'          => 'wpvdb-demo-' . (int) $dim,
			'embedding'      => wp_json_encode($embedding),
			'embedding_date' => current_time('mysql'),
		),
		array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
	);
}

// Four preset query buttons, one per topic cluster. The `seed` integer is the
// fallback seed used by lookup_preset_vector() when no real vector is present
// for this id in demo-vectors.json. Picking seeds that match an exemplar post
// in the matching cluster keeps the placeholder fallback at least loosely
// connected to the topic.
$preset_queries = array(
	array(
		'id'    => 'cooking',
		'label' => 'Easy dinner recipes',
		'seed'  => 2,  // beef stew
	),
	array(
		'id'    => 'travel',
		'label' => 'Interesting cities to walk',
		'seed'  => 6,  // Lisbon
	),
	array(
		'id'    => 'wordpress',
		'label' => 'WordPress and search',
		'seed'  => 11, // wpvdb
	),
	array(
		'id'    => 'outdoors',
		'label' => 'Hiking and the outdoors',
		'seed'  => 16, // Sierra hiking
	),
);

$presets_with_vectors = array();
foreach ($preset_queries as $preset) {
	$presets_with_vectors[] = array(
		'id'     => $preset['id'],
		'label'  => $preset['label'],
		'vector' => $lookup_preset_vector($preset['id'], $preset['seed']),
	);
}

update_option('wpvdb_demo_preset_queries', $presets_with_vectors, false);
update_option('wpvdb_demo_preloaded', time(), false);
