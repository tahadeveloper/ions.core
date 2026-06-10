<?php

declare(strict_types=1);

/**
 * Unit tests for the log-based N+1 heuristic (Ions\Database\NPlusOneDetector).
 *
 * The detector is pure (no kernel/container/db): it consumes Illuminate
 * getQueryLog() entries (['query' => sql, 'bindings' => [...], 'time' => ms]),
 * normalizes each SELECT into a shape-pattern and flags patterns repeated
 * >= threshold times. Default threshold: 5.
 */

use Ions\Database\NPlusOneDetector;

function nPlusOneEntry(string $sql, float $time = 1.0): array
{
    return ['query' => $sql, 'bindings' => [], 'time' => $time];
}

test('normalize collapses whitespace and case into one pattern', function () {
    $a = NPlusOneDetector::normalize("SELECT * FROM widgets   WHERE id = ?");
    $b = NPlusOneDetector::normalize("select *  from widgets where id = ?\n");

    expect($a)->toBe('select * from widgets where id = ?')
        ->and($b)->toBe($a);
});

test('normalize collapses IN lists, numeric and string literals', function () {
    expect(NPlusOneDetector::normalize('select * from t where id in (?, ?, ?)'))
        ->toBe('select * from t where id in (...)')
        ->and(NPlusOneDetector::normalize('select * from t where id in (?)'))
        ->toBe('select * from t where id in (...)')
        ->and(NPlusOneDetector::normalize("select * from t where id = 42 and name = 'bob'"))
        ->toBe('select * from t where id = ? and name = ?');
});

test('analyze flags a pattern at the default threshold of 5', function () {
    $log = array_fill(0, 5, nPlusOneEntry('select * from widgets where id = ?'));

    $flagged = NPlusOneDetector::analyze($log);

    expect($flagged)->toHaveCount(1)
        ->and($flagged[0]['pattern'])->toBe('select * from widgets where id = ?')
        ->and($flagged[0]['count'])->toBe(5);
});

test('analyze stays quiet one below the default threshold', function () {
    $log = array_fill(0, 4, nPlusOneEntry('select * from widgets where id = ?'));

    expect(NPlusOneDetector::analyze($log))->toBe([]);
});

test('analyze respects a custom threshold boundary', function () {
    $log = array_fill(0, 3, nPlusOneEntry('select * from widgets where id = ?'));

    expect(NPlusOneDetector::analyze($log, 4))->toBe([])
        ->and(NPlusOneDetector::analyze($log, 3))->toHaveCount(1);
});

test('analyze counts distinct patterns separately', function () {
    $log = array_merge(
        array_fill(0, 5, nPlusOneEntry('select * from widgets where id = ?')),
        array_fill(0, 3, nPlusOneEntry('select * from gizmos where id = ?')),
        array_fill(0, 5, nPlusOneEntry('select name from users where email = ?')),
    );

    $flagged = NPlusOneDetector::analyze($log);
    $patterns = array_column($flagged, 'pattern');

    expect($flagged)->toHaveCount(2)
        ->and($patterns)->toContain('select * from widgets where id = ?')
        ->and($patterns)->toContain('select name from users where email = ?')
        ->and($patterns)->not->toContain('select * from gizmos where id = ?');
});

test('analyze ignores non-SELECT statements', function () {
    $log = array_fill(0, 10, nPlusOneEntry('insert into widgets (name) values (?)'));
    $log = array_merge($log, array_fill(0, 10, nPlusOneEntry('update widgets set name = ? where id = ?')));

    expect(NPlusOneDetector::analyze($log))->toBe([]);
});

test('analyze ignores SELECTs without a WHERE clause', function () {
    // No WHERE => not a per-row lookup shape; repeating it is not the N+1 signal.
    $log = array_fill(0, 10, nPlusOneEntry('select count(*) from widgets'));

    expect(NPlusOneDetector::analyze($log))->toBe([]);
});

test('analyze returns an empty list for an empty log', function () {
    expect(NPlusOneDetector::analyze([]))->toBe([]);
});

test('analyze skips malformed log entries without throwing', function () {
    $log = [
        'garbage-string',
        42,
        null,
        ['no_query_key' => true],
        ['query' => 12345],                                       // non-string query
        ['query' => 'select * from widgets where id = ?'],        // missing time
        ['query' => 'select * from widgets where id = ?', 'time' => 'NaN-ish'],
    ];
    $log = array_merge($log, array_fill(0, 3, nPlusOneEntry('select * from widgets where id = ?')));

    $flagged = NPlusOneDetector::analyze($log); // 5 valid entries of the same pattern

    expect($flagged)->toHaveCount(1)
        ->and($flagged[0]['count'])->toBe(5);
});

test('analyze sums query time per pattern', function () {
    $log = [
        nPlusOneEntry('select * from widgets where id = ?', 1.5),
        nPlusOneEntry('select * from widgets where id = ?', 2.0),
        nPlusOneEntry('select * from widgets where id = ?', 0.5),
        nPlusOneEntry('select * from widgets where id = ?', 1.0),
        nPlusOneEntry('select * from widgets where id = ?', 1.0),
    ];

    $flagged = NPlusOneDetector::analyze($log);

    expect($flagged[0]['total_time'])->toBe(6.0);
});

test('binding values do not split the pattern (IN list sizes group together)', function () {
    $log = [
        nPlusOneEntry('select * from widgets where id in (?)'),
        nPlusOneEntry('select * from widgets where id in (?, ?)'),
        nPlusOneEntry('select * from widgets where id in (?, ?, ?)'),
        nPlusOneEntry('select * from widgets where id in (?, ?, ?, ?)'),
        nPlusOneEntry('select * from widgets where id in (?, ?, ?, ?, ?)'),
    ];

    $flagged = NPlusOneDetector::analyze($log);

    expect($flagged)->toHaveCount(1)
        ->and($flagged[0]['pattern'])->toBe('select * from widgets where id in (...)');
});
