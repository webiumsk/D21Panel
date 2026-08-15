<?php

return [

    /*
    |--------------------------------------------------------------------------
    | RegWatch legislative monitoring (docs/LEGAL.md)
    |--------------------------------------------------------------------------
    |
    | The scheduled regwatch:monitor command fetches each active source,
    | diffs it against the stored snapshot and records detections in
    | regwatch_changes (status 'new') for human review. It never touches
    | regwatch_rules. Opt-in via REGWATCH_ENABLED.
    |
    */

    'enabled' => (bool) env('REGWATCH_ENABLED', false),

    /** Alert address for detected changes. Empty = log only. */
    'notify_email' => env('REGWATCH_NOTIFY_EMAIL'),

    /** HTTP timeout (seconds) for fetching a monitored source. */
    'http_timeout' => (int) env('REGWATCH_HTTP_TIMEOUT', 30),

    /** Storage disk + directory for normalized source snapshots. */
    'snapshot_disk' => env('REGWATCH_SNAPSHOT_DISK', 'local'),
    'snapshot_dir' => 'regwatch/snapshots',

    /** Max characters of diff persisted on a change row (and sent to the classifier). */
    'max_diff_chars' => (int) env('REGWATCH_MAX_DIFF_CHARS', 20000),

    /*
    | Claude API classification of detected diffs (relevance + summary only,
    | never legal facts - the LEGAL.md critical rule). Disabled unless an API
    | key is configured; a change row is recorded either way.
    */
    'classifier' => [
        'api_key' => env('REGWATCH_ANTHROPIC_API_KEY', env('ANTHROPIC_API_KEY')),
        'model' => env('REGWATCH_CLAUDE_MODEL', 'claude-sonnet-5'),
        'base_url' => env('REGWATCH_ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'timeout' => (int) env('REGWATCH_CLASSIFIER_TIMEOUT', 60),
        /** Delay between retries of transient API failures (429/5xx). */
        'retry_delay_ms' => (int) env('REGWATCH_CLASSIFIER_RETRY_DELAY_MS', 1000),
    ],

];
