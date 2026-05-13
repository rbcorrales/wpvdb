<?php
namespace WPVDB;

defined('ABSPATH') || exit;

/**
 * Resumable re-embed job primitive.
 *
 * Pages through matching posts via keyset cursor, schedules embedding work
 * through the existing queue. State persists in wp_wpvdb_reindex_jobs.
 *
 * See design/embedding-enqueuer.md.
 */
class Embedding_Enqueuer {

    const AS_HOOK = 'wpvdb_enqueue_reembed_page';
    const AS_GROUP = 'wpvdb';

    const STATUS_PENDING   = 'pending';
    const STATUS_RUNNING   = 'running';
    const STATUS_PAUSED    = 'paused';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';
    const STATUS_CANCELED  = 'canceled';

    const DEFAULT_PAGE_SIZE = 1000;
    const MIN_PAGE_SIZE     = 1;
    const MAX_PAGE_SIZE     = 5000;
    const LOCK_TTL_SECONDS  = 120;

    /**
     * Get the jobs table name.
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'wpvdb_reindex_jobs';
    }

    /**
     * Get the embeddings table name.
     */
    private static function embeddings_table() {
        global $wpdb;
        return $wpdb->prefix . 'wpvdb_embeddings';
    }

    /**
     * Normalize and validate raw scope args into the canonical shape stored
     * with the job. Returned array is suitable for fingerprinting.
     *
     * @param array $args
     * @return array|\WP_Error
     */
    public static function normalize_args($args) {
        if (!is_array($args)) {
            $args = [];
        }

        $defaults = [
            'post_type'             => self::default_post_types(),
            'post_status'           => ['publish'],
            'since'                 => '',
            'only_missing'          => false,
            'only_mismatched_model' => false,
            'limit'                 => 0,
            'page_size'             => self::DEFAULT_PAGE_SIZE,
        ];

        $merged = array_merge($defaults, $args);

        $post_type = self::ensure_string_list($merged['post_type']);
        if (empty($post_type)) {
            return new \WP_Error('wpvdb_enqueuer_bad_args', 'post_type must include at least one value.');
        }
        sort($post_type);

        $post_status = self::ensure_string_list($merged['post_status']);
        if (empty($post_status)) {
            return new \WP_Error('wpvdb_enqueuer_bad_args', 'post_status must include at least one value.');
        }
        sort($post_status);

        $since = is_string($merged['since']) ? trim($merged['since']) : '';
        if ($since !== '' && !self::looks_like_date($since)) {
            return new \WP_Error('wpvdb_enqueuer_bad_args', 'since must be YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.');
        }

        $page_size = (int) $merged['page_size'];
        if ($page_size < self::MIN_PAGE_SIZE) {
            $page_size = self::DEFAULT_PAGE_SIZE;
        }
        if ($page_size > self::MAX_PAGE_SIZE) {
            $page_size = self::MAX_PAGE_SIZE;
        }

        $limit = max(0, (int) $merged['limit']);

        return [
            'post_type'             => $post_type,
            'post_status'           => $post_status,
            'since'                 => $since,
            'only_missing'          => (bool) $merged['only_missing'],
            'only_mismatched_model' => (bool) $merged['only_mismatched_model'],
            'limit'                 => $limit,
            'page_size'             => $page_size,
        ];
    }

    /**
     * Resolve default post types from settings, falling back to ['post'].
     */
    private static function default_post_types() {
        if (class_exists(__NAMESPACE__ . '\\Settings') && method_exists(Settings::class, 'get_auto_embed_post_types')) {
            $types = Settings::get_auto_embed_post_types();
            if (is_array($types) && !empty($types)) {
                return array_values(array_unique(array_map('strval', $types)));
            }
        }
        return ['post'];
    }

    private static function ensure_string_list($value) {
        if (is_string($value)) {
            $value = array_map('trim', explode(',', $value));
        } elseif (!is_array($value)) {
            return [];
        }
        $value = array_map('strval', $value);
        $value = array_filter($value, static function ($v) {
            return $v !== '';
        });
        return array_values(array_unique($value));
    }

    private static function looks_like_date($value) {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $value);
    }

    /**
     * Compute deterministic fingerprint from canonical args + provider + model.
     */
    public static function compute_fingerprint($args, $provider, $model) {
        $payload = [
            'args'     => $args,
            'provider' => (string) $provider,
            'model'    => (string) $model,
        ];
        return hash('sha256', wp_json_encode($payload));
    }

    /**
     * Resolve the active provider + model snapshot at job-creation time.
     */
    private static function resolve_provider_model($override_provider, $override_model) {
        $provider = is_string($override_provider) && $override_provider !== ''
            ? $override_provider
            : Settings::get_active_provider();
        if (empty($provider)) {
            $provider = 'openai';
        }

        $model = is_string($override_model) && $override_model !== ''
            ? $override_model
            : Settings::get_default_model();

        return [(string) $provider, (string) $model];
    }

    /**
     * Snapshot the current maximum post ID matching the scope. Used as the
     * upper bound so a long-running job does not chase newly-created posts.
     */
    private static function snapshot_upper_bound($args) {
        global $wpdb;
        $where  = self::build_scope_where_sql($args, $params);
        $params_with_zero = array_merge([0], $params);
        $sql = "SELECT COALESCE(MAX(ID), 0) FROM {$wpdb->posts} WHERE ID > %d {$where}";
        $prepared = $wpdb->prepare($sql, $params_with_zero);
        return (int) $wpdb->get_var($prepared);
    }

    /**
     * Build the WHERE fragment shared by snapshot + page queries.
     * Always appended to a query that already has a "WHERE ID > %d" clause;
     * caller supplies the leading column.
     *
     * Populates $params (by reference) with placeholder values in order.
     */
    private static function build_scope_where_sql($args, &$params) {
        $params = [];
        $clauses = [];

        $post_types = $args['post_type'];
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        $clauses[] = "post_type IN ({$placeholders})";
        foreach ($post_types as $pt) {
            $params[] = $pt;
        }

        $post_status = $args['post_status'];
        $placeholders = implode(',', array_fill(0, count($post_status), '%s'));
        $clauses[] = "post_status IN ({$placeholders})";
        foreach ($post_status as $ps) {
            $params[] = $ps;
        }

        if (!empty($args['since'])) {
            $since = strlen($args['since']) === 10 ? $args['since'] . ' 00:00:00' : $args['since'];
            $clauses[] = 'post_modified_gmt >= %s';
            $params[] = $since;
        }

        return 'AND ' . implode(' AND ', $clauses);
    }

    /**
     * Start a new job. Returns ['job_id' => N, 'dedup' => bool, 'estimate' => int]
     * or WP_Error on validation failure.
     *
     * Options accepted in $opts:
     *   - dry_run:  bool. If true, returns estimate only, no row created.
     *   - force:    bool. If true, bypass fingerprint dedup.
     *   - provider: string override.
     *   - model:    string override.
     *   - paused:   bool. If true, create row but do not schedule first page.
     */
    public static function start_job($args, $opts = []) {
        global $wpdb;

        $normalized = self::normalize_args($args);
        if (is_wp_error($normalized)) {
            return $normalized;
        }

        list($provider, $model) = self::resolve_provider_model(
            isset($opts['provider']) ? $opts['provider'] : '',
            isset($opts['model']) ? $opts['model'] : ''
        );

        $fingerprint = self::compute_fingerprint($normalized, $provider, $model);

        if (!empty($opts['dry_run'])) {
            $estimate = self::estimate_total($normalized);
            return [
                'job_id'      => 0,
                'dedup'       => false,
                'estimate'    => $estimate,
                'provider'    => $provider,
                'model'       => $model,
                'fingerprint' => $fingerprint,
            ];
        }

        if (empty($opts['force'])) {
            $existing = self::find_active_by_fingerprint($fingerprint);
            if ($existing) {
                return [
                    'job_id'      => (int) $existing['job_id'],
                    'dedup'       => true,
                    'estimate'    => null,
                    'provider'    => $provider,
                    'model'       => $model,
                    'fingerprint' => $fingerprint,
                ];
            }
        }

        $upper_bound = self::snapshot_upper_bound($normalized);
        $now = current_time('mysql');

        $inserted = $wpdb->insert(
            self::table_name(),
            [
                'status'         => self::STATUS_PENDING,
                'provider'       => $provider,
                'model'          => $model,
                'scope_args'     => wp_json_encode($normalized),
                'fingerprint'    => $fingerprint,
                'last_seen_id'   => 0,
                'upper_bound_id' => $upper_bound,
                'scanned_count'  => 0,
                'queued_count'   => 0,
                'skipped_count'  => 0,
                'lock_until'     => null,
                'last_error'     => null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s']
        );

        if ($inserted === false) {
            return new \WP_Error('wpvdb_enqueuer_insert_failed', $wpdb->last_error);
        }

        $job_id = (int) $wpdb->insert_id;

        if (empty($opts['paused'])) {
            self::schedule_next_page($job_id, 0);
        } else {
            self::set_status($job_id, self::STATUS_PAUSED);
        }

        return [
            'job_id'      => $job_id,
            'dedup'       => false,
            'estimate'    => null,
            'provider'    => $provider,
            'model'       => $model,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Rough count of posts that match the scope, ignoring only_* filters.
     * Cheap upper bound for dry-run.
     */
    public static function estimate_total($args) {
        global $wpdb;
        $where  = self::build_scope_where_sql($args, $params);
        $params_with_zero = array_merge([0], $params);
        $sql = "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID > %d {$where}";
        return (int) $wpdb->get_var($wpdb->prepare($sql, $params_with_zero));
    }

    /**
     * Look up an active (pending / running / paused) job by fingerprint.
     */
    private static function find_active_by_fingerprint($fingerprint) {
        global $wpdb;
        $active = [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_PAUSED];
        $placeholders = implode(',', array_fill(0, count($active), '%s'));
        $params = array_merge([$fingerprint], $active);
        $sql = $wpdb->prepare(
            "SELECT * FROM " . self::table_name() . " WHERE fingerprint = %s AND status IN ({$placeholders}) ORDER BY job_id DESC LIMIT 1",
            $params
        );
        return $wpdb->get_row($sql, ARRAY_A);
    }

    /**
     * Acquire the per-job page lock atomically and mark status=running. Returns
     * the job row on success, null if another worker owns it or it is no longer
     * active.
     */
    private static function acquire_lock($job_id) {
        global $wpdb;
        $now = current_time('mysql');
        $lock_expiry = gmdate('Y-m-d H:i:s', time() + self::LOCK_TTL_SECONDS);

        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table_name() . "
             SET lock_until = %s, status = %s, updated_at = %s
             WHERE job_id = %d
               AND status IN (%s, %s)
               AND (lock_until IS NULL OR lock_until < %s)",
            $lock_expiry,
            self::STATUS_RUNNING,
            $now,
            $job_id,
            self::STATUS_PENDING,
            self::STATUS_RUNNING,
            $now
        ));

        if ($affected === false || $affected === 0) {
            return null;
        }

        return self::get_job($job_id);
    }

    private static function release_lock($job_id, $cursor_advance = null, $scanned_delta = 0, $queued_delta = 0, $skipped_delta = 0, $finalize_status = null) {
        global $wpdb;
        $now = current_time('mysql');

        $sets = [
            'lock_until = NULL',
            'scanned_count = scanned_count + ' . (int) $scanned_delta,
            'queued_count = queued_count + ' . (int) $queued_delta,
            'skipped_count = skipped_count + ' . (int) $skipped_delta,
            'updated_at = %s',
        ];
        $params = [$now];

        if ($cursor_advance !== null) {
            $sets[] = 'last_seen_id = %d';
            $params[] = (int) $cursor_advance;
        }

        if ($finalize_status !== null) {
            $sets[] = 'status = %s';
            $params[] = $finalize_status;
        } else {
            $sets[] = 'status = %s';
            $params[] = self::STATUS_PENDING;
        }

        $params[] = (int) $job_id;

        $sql = "UPDATE " . self::table_name() . " SET " . implode(', ', $sets) . " WHERE job_id = %d";
        $wpdb->query($wpdb->prepare($sql, $params));
    }

    /**
     * AS callback: process one enqueue page for the given job.
     */
    public static function process_page($job_id) {
        global $wpdb;
        $job_id = (int) $job_id;
        if ($job_id <= 0) {
            return;
        }

        $job = self::acquire_lock($job_id);
        if (!$job) {
            return;
        }

        try {
            $args = json_decode($job['scope_args'], true);
            if (!is_array($args)) {
                self::release_lock($job_id, null, 0, 0, 0, self::STATUS_FAILED);
                self::record_error($job_id, 'scope_args is not valid JSON');
                return;
            }

            $cursor       = (int) $job['last_seen_id'];
            $upper_bound  = (int) $job['upper_bound_id'];
            $page_size    = (int) $args['page_size'];
            $limit_total  = (int) $args['limit'];
            $already_queued = (int) $job['queued_count'];

            $effective_page_size = $page_size;
            if ($limit_total > 0) {
                $remaining = $limit_total - $already_queued;
                if ($remaining <= 0) {
                    self::release_lock($job_id, $cursor, 0, 0, 0, self::STATUS_COMPLETED);
                    return;
                }
                $effective_page_size = min($page_size, $remaining);
            }

            $start_time   = microtime(true);
            $budget       = (int) apply_filters('wpvdb_enqueue_page_budget_seconds', 20);

            $post_ids = self::fetch_page_post_ids($cursor, $upper_bound, $args, $effective_page_size);

            if (empty($post_ids)) {
                self::release_lock($job_id, $cursor, 0, 0, 0, self::STATUS_COMPLETED);
                return;
            }

            $last_examined = $cursor;
            $scanned = 0;
            $queued  = 0;
            $skipped = 0;

            $skip_ids = self::compute_skip_set($post_ids, $args, $job['model']);

            $batch_items = [];
            foreach ($post_ids as $pid) {
                if ((microtime(true) - $start_time) > $budget) {
                    break;
                }

                $pid = (int) $pid;
                $last_examined = $pid;
                $scanned++;

                if (isset($skip_ids[$pid])) {
                    $skipped++;
                    continue;
                }

                $batch_items[] = [
                    'post_id'  => $pid,
                    'model'    => $job['model'],
                    'provider' => $job['provider'],
                ];
                $queued++;
            }

            if (!empty($batch_items)) {
                $queue = new \WPVDB\WPVDB_Queue();
                $queue->push_batch_to_queue($batch_items);
            }

            $more_remaining = $last_examined < $upper_bound;
            if ($limit_total > 0 && ($already_queued + $queued) >= $limit_total) {
                $more_remaining = false;
            }

            $finalize = $more_remaining ? self::STATUS_PENDING : self::STATUS_COMPLETED;
            self::release_lock($job_id, $last_examined, $scanned, $queued, $skipped, $finalize);

            if ($more_remaining) {
                self::schedule_next_page($job_id, 1);
            }
        } catch (\Throwable $e) {
            self::release_lock($job_id, null, 0, 0, 0, self::STATUS_FAILED);
            self::record_error($job_id, $e->getMessage());
        }
    }

    /**
     * Stash an error message on the job row for visibility via status.
     */
    private static function record_error($job_id, $message) {
        global $wpdb;
        $wpdb->update(
            self::table_name(),
            ['last_error' => $message, 'updated_at' => current_time('mysql')],
            ['job_id' => (int) $job_id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Page of post IDs above the cursor within the scope.
     *
     * @return int[]
     */
    private static function fetch_page_post_ids($cursor, $upper_bound, $args, $page_size) {
        global $wpdb;

        if ($upper_bound > 0 && $cursor >= $upper_bound) {
            return [];
        }

        $where = self::build_scope_where_sql($args, $params);
        $sql_params = array_merge([(int) $cursor, (int) $upper_bound], $params, [(int) $page_size]);

        $sql = "SELECT ID FROM {$wpdb->posts}
                WHERE ID > %d AND ID <= %d {$where}
                ORDER BY ID ASC
                LIMIT %d";

        $rows = $wpdb->get_col($wpdb->prepare($sql, $sql_params));
        return array_map('intval', $rows);
    }

    /**
     * For a list of post IDs, return a map [post_id => true] for posts that
     * should be skipped due to only_missing / only_mismatched_model filters.
     */
    private static function compute_skip_set($post_ids, $args, $model) {
        $skip = [];
        if (empty($post_ids)) {
            return $skip;
        }

        $only_missing    = !empty($args['only_missing']);
        $only_mismatched = !empty($args['only_mismatched_model']);

        if (!$only_missing && !$only_mismatched) {
            return $skip;
        }

        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));

        if ($only_missing) {
            $sql = "SELECT DISTINCT doc_id FROM " . self::embeddings_table() . "
                    WHERE doc_id IN ({$placeholders}) AND doc_type = 'post'";
            $present = $wpdb->get_col($wpdb->prepare($sql, $post_ids));
            foreach ($present as $pid) {
                $skip[(int) $pid] = true;
            }
        }

        if ($only_mismatched) {
            $params = array_merge($post_ids, [(string) $model]);
            $sql = "SELECT DISTINCT doc_id FROM " . self::embeddings_table() . "
                    WHERE doc_id IN ({$placeholders}) AND doc_type = 'post' AND model = %s";
            $matching = $wpdb->get_col($wpdb->prepare($sql, $params));
            foreach ($matching as $pid) {
                $skip[(int) $pid] = true;
            }
        }

        return $skip;
    }

    /**
     * Schedule the next page action for a job.
     */
    private static function schedule_next_page($job_id, $delay_seconds = 0) {
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time() + (int) $delay_seconds,
                self::AS_HOOK,
                [(int) $job_id],
                self::AS_GROUP
            );
        }
    }

    /**
     * Get a job row as an associative array.
     */
    public static function get_job($job_id) {
        global $wpdb;
        return $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM " . self::table_name() . " WHERE job_id = %d", (int) $job_id),
            ARRAY_A
        );
    }

    /**
     * List recent jobs, newest first.
     */
    public static function list_jobs($limit = 20) {
        global $wpdb;
        $limit = max(1, min(200, (int) $limit));
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM " . self::table_name() . " ORDER BY job_id DESC LIMIT %d", $limit),
            ARRAY_A
        );
    }

    /**
     * Mark a job canceled. Already-scheduled AS pages will exit early because
     * the lock acquisition checks status.
     */
    public static function cancel_job($job_id) {
        return self::set_status($job_id, self::STATUS_CANCELED);
    }

    /**
     * Resume a paused job by setting it back to pending and scheduling the
     * next page.
     */
    public static function resume_job($job_id) {
        global $wpdb;
        $now = current_time('mysql');
        $affected = $wpdb->query($wpdb->prepare(
            "UPDATE " . self::table_name() . "
             SET status = %s, lock_until = NULL, updated_at = %s
             WHERE job_id = %d AND status = %s",
            self::STATUS_PENDING,
            $now,
            (int) $job_id,
            self::STATUS_PAUSED
        ));
        if ($affected) {
            self::schedule_next_page((int) $job_id, 0);
            return true;
        }
        return false;
    }

    /**
     * Internal status setter.
     */
    private static function set_status($job_id, $status) {
        global $wpdb;
        $now = current_time('mysql');
        $affected = $wpdb->update(
            self::table_name(),
            ['status' => $status, 'updated_at' => $now],
            ['job_id' => (int) $job_id],
            ['%s', '%s'],
            ['%d']
        );
        return $affected !== false;
    }
}
