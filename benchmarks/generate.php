<?php

declare(strict_types=1);

/**
 * Synthetic Flarum dataset generator. Run against a disposable benchmark DB only.
 * Example:
 * php benchmarks/generate.php --confirm-isolated --dsn=... --user=... --password=...
 */

$options = getopt('', ['confirm-isolated', 'dsn:', 'user:', 'password:', 'users::', 'posts::', 'batch::', 'prefix::']);
if (! isset($options['confirm-isolated']) || empty($options['dsn']) || ! isset($options['user'], $options['password'])) {
    fwrite(STDERR, "Refusing to run. Supply --confirm-isolated, --dsn, --user and --password.\n");
    exit(2);
}

$userCount = max(1, (int) ($options['users'] ?? 100000));
$postCount = max($userCount, (int) ($options['posts'] ?? 1000000));
$batchSize = max(100, min(10000, (int) ($options['batch'] ?? 5000)));
$prefix = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($options['prefix'] ?? ''));
$pdo = new PDO((string) $options['dsn'], (string) $options['user'], (string) $options['password'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$tables = [
    'users' => $prefix.'users',
    'discussions' => $prefix.'discussions',
    'posts' => $prefix.'posts',
];
foreach ($tables as $table) {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    if ((int) $stmt->fetchColumn() !== 1) {
        throw new RuntimeException("Required Flarum table missing: {$table}");
    }
}

$columnExists = static function (string $table, string $column) use ($pdo): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() === 1;
};
$approval = $columnExists($tables['posts'], 'is_approved') && $columnExists($tables['discussions'], 'is_approved');
$maxUserId = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$tables['users']}`")->fetchColumn();
$maxDiscussionId = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$tables['discussions']}`")->fetchColumn();
$maxPostId = (int) $pdo->query("SELECT COALESCE(MAX(id), 0) FROM `{$tables['posts']}`")->fetchColumn();
$discussionCount = (int) ceil($postCount / 10);
$started = microtime(true);

$insertRows = static function (PDO $pdo, string $table, array $columns, array $rows): void {
    if (! $rows) {
        return;
    }
    $tuple = '('.implode(',', array_fill(0, count($columns), '?')).')';
    $sql = "INSERT INTO `{$table}` (`".implode('`,`', $columns).'`) VALUES '.implode(',', array_fill(0, count($rows), $tuple));
    $flat = [];
    foreach ($rows as $row) {
        array_push($flat, ...$row);
    }
    $pdo->prepare($sql)->execute($flat);
};

echo "Generating {$userCount} users...\n";
for ($offset = 0; $offset < $userCount; $offset += $batchSize) {
    $rows = [];
    $limit = min($userCount, $offset + $batchSize);
    for ($i = $offset; $i < $limit; ++$i) {
        $id = $maxUserId + $i + 1;
        $rows[] = [
            $id, 'mpbench_'.$id, 'mpbench_'.$id.'@invalid.example', 1,
            '!benchmark-login-disabled!', gmdate('Y-m-d H:i:s', time() - ($i % 365) * 86400),
        ];
    }
    $insertRows($pdo, $tables['users'], ['id', 'username', 'email', 'is_email_confirmed', 'password', 'joined_at'], $rows);
}

echo "Generating {$discussionCount} discussions...\n";
$discussionColumns = ['id', 'title', 'slug', 'comment_count', 'created_at', 'user_id', 'is_private'];
if ($approval) {
    $discussionColumns[] = 'is_approved';
}
for ($offset = 0; $offset < $discussionCount; $offset += $batchSize) {
    $rows = [];
    $limit = min($discussionCount, $offset + $batchSize);
    for ($i = $offset; $i < $limit; ++$i) {
        $row = [
            $maxDiscussionId + $i + 1, 'mpbench_'.$i, 'mpbench-'.$i,
            min(10, $postCount - $i * 10), gmdate('Y-m-d H:i:s'), $maxUserId + ($i % $userCount) + 1, 0,
        ];
        if ($approval) {
            $row[] = 1;
        }
        $rows[] = $row;
    }
    $insertRows($pdo, $tables['discussions'], $discussionColumns, $rows);
}

echo "Generating {$postCount} posts...\n";
$postColumns = ['id', 'discussion_id', 'user_id', 'type', 'number', 'content', 'created_at', 'is_private'];
if ($approval) {
    $postColumns[] = 'is_approved';
}
for ($offset = 0; $offset < $postCount; $offset += $batchSize) {
    $rows = [];
    $limit = min($postCount, $offset + $batchSize);
    for ($i = $offset; $i < $limit; ++$i) {
        $discussionIndex = intdiv($i, 10);
        $number = ($i % 10) + 1;
        // Most users are light posters; every 100th user is deliberately heavier.
        $authorIndex = $i % 100 === 0 ? intdiv($i, 100) % max(1, intdiv($userCount, 100)) : $i % $userCount;
        $row = [
            $maxPostId + $i + 1, $maxDiscussionId + $discussionIndex + 1,
            $maxUserId + $authorIndex + 1, 'comment', $number, '<t><p>benchmark</p></t>',
            gmdate('Y-m-d H:i:s'), 0,
        ];
        if ($approval) {
            $row[] = 1;
        }
        $rows[] = $row;
    }
    $insertRows($pdo, $tables['posts'], $postColumns, $rows);
    if (($offset / $batchSize) % 20 === 0) {
        printf("  %.1f%%\n", 100 * $limit / $postCount);
    }
}

printf("Done in %.2fs. Peak generator memory: %.1f MiB\n", microtime(true) - $started, memory_get_peak_usage(true) / 1048576);
