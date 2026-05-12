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
 * Generate a deterministic unit vector of the configured dimension.
 *
 * Seeded by integer so the same seed yields the same vector across runs.
 * Output is L2-normalized to unit length so cosine math behaves predictably.
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

$sample_posts = array(
	array(
		'title'   => 'Vector search in WordPress',
		'content' => "WordPress sites can do semantic search by storing embeddings alongside posts. The wpvdb plugin handles chunking, embedding generation, and similarity queries against a custom table.",
		'seed'    => 1,
	),
	array(
		'title'   => 'Slow-cooked beef stew for cold evenings',
		'content' => "Brown the beef in batches. Build a stock with onion, carrot, garlic, red wine, and herbs. Simmer for three hours until the meat falls apart.",
		'seed'    => 2,
	),
	array(
		'title'   => 'A first walk through the city',
		'content' => "Cobblestone alleys widen into a square where vendors sell bread and flowers. Pigeons scatter as a tram clatters past.",
		'seed'    => 3,
	),
	array(
		'title'   => 'Setting up local WordPress with wp-now',
		'content' => "wp-now boots a WordPress instance on PHP-WASM with a bundled SQLite driver. Plugin mode auto-activates the plugin from the path argument.",
		'seed'    => 4,
	),
	array(
		'title'   => 'Why MariaDB 11.7 matters for embeddings',
		'content' => "Native VECTOR columns and the VEC_DISTANCE_COSINE function arrived in MariaDB 11.7. Before that, similarity search had to run in application code over JSON-encoded vectors.",
		'seed'    => 5,
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

	$embedding = $make_vector($sample['seed'], $dim);
	$wpdb->insert(
		$table,
		array(
			'doc_id'         => $post_id,
			'doc_type'       => 'post',
			'chunk_id'       => 'chunk-0',
			'chunk_index'    => 0,
			'chunk_content'  => $sample['content'],
			'model'          => 'wpvdb-demo-deterministic-' . (int) $dim,
			'embedding'      => wp_json_encode($embedding),
			'embedding_date' => current_time('mysql'),
		),
		array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
	);
}

$preset_queries = array(
	array(
		'id'    => 'cooking',
		'label' => 'Recipes and cooking',
		'seed'  => 2,
	),
	array(
		'id'    => 'travel',
		'label' => 'Travel and city walks',
		'seed'  => 3,
	),
	array(
		'id'    => 'wordpress',
		'label' => 'WordPress and wpvdb',
		'seed'  => 1,
	),
);

$presets_with_vectors = array();
foreach ($preset_queries as $preset) {
	$presets_with_vectors[] = array(
		'id'     => $preset['id'],
		'label'  => $preset['label'],
		'vector' => $make_vector($preset['seed'], $dim),
	);
}

update_option('wpvdb_demo_preset_queries', $presets_with_vectors, false);
update_option('wpvdb_demo_preloaded', time(), false);
