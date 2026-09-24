<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Database;
use App\Core\Response;

Auth::requireRole('admin');

// Live follow-up of a running count: overall progress and finish estimate,
// progress per rack, counters ranking, stuck addresses and the control queue.
// Everything is computed in PHP so it behaves the same on SQLite and MySQL.

const IDLE_MINUTES = 15;   // counter with no activity for longer = idle
const STUCK_MINUTES = 15;  // address in progress with no activity for longer = stuck
const PACE_MINUTES = 60;   // window used for the pace and the finish estimate

const COUNTED = ['COMPLETED_OK', 'COMPLETED_CONTROL_REQUIRED', 'CONTROL_IN_PROGRESS', 'CONTROLLED'];
const SENT_TO_CONTROL = ['COMPLETED_CONTROL_REQUIRED', 'CONTROL_IN_PROGRESS', 'CONTROLLED'];

/** "A -01- 1" -> A, "R09-A-3" -> R09, "W2A-01-01" -> W2A, "OUTSIDE" -> OUTSIDE. */
function rack_of(string $code): string
{
    $rack = strtoupper(trim(explode('-', $code, 2)[0]));
    return $rack !== '' ? $rack : strtoupper(trim($code));
}

function ts(?string $value): ?int
{
    if ($value === null || $value === '') return null;
    $t = strtotime($value);
    return $t === false ? null : $t;
}

function latest(?int ...$values): ?int
{
    $values = array_filter($values, fn($v) => $v !== null);
    return $values ? max($values) : null;
}

try {
    $pdo = Database::connection();
    $now = time();

    $users = [];
    foreach ($pdo->query('SELECT id, full_name, username, role, active FROM users')->fetchAll() as $u) {
        $users[(int)$u['id']] = $u;
    }
    $name = fn($id) => $id !== null && isset($users[(int)$id]) ? $users[(int)$id]['full_name'] : null;

    $addresses = [];
    foreach ($pdo->query('SELECT id, code, status, completed_by, completed_at, controlled_by, controlled_at, updated_at FROM addresses')->fetchAll() as $a) {
        $a['last_activity'] = ts($a['updated_at']);
        $a['last_by'] = null;
        $addresses[(int)$a['id']] = $a;
    }

    // Per-user and per-address activity from the count lines.
    $people = [];
    $person = function (int $uid) use (&$people) {
        if (!isset($people[$uid])) {
            $people[$uid] = ['lines' => 0, 'addresses' => 0, 'sent_to_control' => 0, 'last' => null, 'last_address_id' => null,
                'controlled' => 0, 'counted_last_hour' => 0];
        }
        return $uid;
    };

    $lines = $pdo->query(
        'SELECT address_id, entered_by, entered_at, last_edited_by, last_edited_at, source
         FROM physical_counts WHERE is_deleted = 0'
    )->fetchAll();
    foreach ($lines as $l) {
        $aid = (int)$l['address_id'];
        $entered = ts($l['entered_at']);
        $edited = ts($l['last_edited_at']);
        if ($l['source'] === 'data_entry') {
            $uid = $person((int)$l['entered_by']);
            $people[$uid]['lines']++;
            if ($entered !== null && ($people[$uid]['last'] === null || $entered >= $people[$uid]['last'])) {
                $people[$uid]['last'] = $entered;
                $people[$uid]['last_address_id'] = $aid;
            }
        }
        if ($edited !== null && $l['last_edited_by'] !== null) {
            $uid = $person((int)$l['last_edited_by']);
            $people[$uid]['last'] = latest($people[$uid]['last'], $edited);
        }
        if (isset($addresses[$aid])) {
            $lineLast = latest($entered, $edited);
            if ($lineLast !== null && $lineLast >= (int)$addresses[$aid]['last_activity']) {
                $addresses[$aid]['last_activity'] = $lineLast;
                $addresses[$aid]['last_by'] = $edited !== null && $edited === $lineLast ? $l['last_edited_by'] : $l['entered_by'];
            }
        }
    }

    // Overall, racks, stuck addresses, control queue, and completion credits.
    $statusCounts = array_fill_keys(['NOT_STARTED', 'IN_PROGRESS', 'COMPLETED_OK', 'COMPLETED_CONTROL_REQUIRED', 'CONTROL_IN_PROGRESS', 'CONTROLLED'], 0);
    $racks = [];
    $stuck = [];
    $queue = [];
    $countedLastWindow = 0;
    $paceFrom = $now - PACE_MINUTES * 60;

    foreach ($addresses as $a) {
        $status = $a['status'];
        $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

        $rack = rack_of($a['code']);
        if (!isset($racks[$rack])) {
            $racks[$rack] = ['rack' => $rack, 'total' => 0, 'not_started' => 0, 'in_progress' => 0, 'counted' => 0,
                'waiting_control' => 0, 'final' => 0, 'stuck' => 0, 'counters' => []];
        }
        $r = &$racks[$rack];
        $r['total']++;
        if ($status === 'NOT_STARTED') $r['not_started']++;
        if ($status === 'IN_PROGRESS') $r['in_progress']++;
        if (in_array($status, COUNTED, true)) $r['counted']++;
        if (in_array($status, ['COMPLETED_CONTROL_REQUIRED', 'CONTROL_IN_PROGRESS'], true)) $r['waiting_control']++;
        if (in_array($status, ['COMPLETED_OK', 'CONTROLLED'], true)) $r['final']++;

        $completedAt = ts($a['completed_at']);
        if (in_array($status, COUNTED, true) && $a['completed_by'] !== null) {
            $uid = $person((int)$a['completed_by']);
            $people[$uid]['addresses']++;
            if (in_array($status, SENT_TO_CONTROL, true)) $people[$uid]['sent_to_control']++;
            $people[$uid]['last'] = latest($people[$uid]['last'], $completedAt);
            if ($completedAt !== null && $completedAt >= $paceFrom) {
                $countedLastWindow++;
                $people[$uid]['counted_last_hour']++;
            }
            $r['counters'][(int)$a['completed_by']] = true;
        }
        if ($status === 'CONTROLLED' && $a['controlled_by'] !== null) {
            $uid = $person((int)$a['controlled_by']);
            $people[$uid]['controlled']++;
        }

        if ($status === 'IN_PROGRESS') {
            $idle = $a['last_activity'] !== null ? intdiv($now - $a['last_activity'], 60) : null;
            if ($idle !== null && $idle >= STUCK_MINUTES) {
                $r['stuck']++;
                $stuck[] = ['code' => $a['code'], 'rack' => $rack, 'idle_minutes' => $idle, 'last_by' => $name($a['last_by'])];
            }
        }
        if (in_array($status, ['COMPLETED_CONTROL_REQUIRED', 'CONTROL_IN_PROGRESS'], true)) {
            $since = $completedAt ?? $a['last_activity'];
            $queue[] = ['code' => $a['code'], 'status' => $status, 'counted_by' => $name($a['completed_by']),
                'waiting_minutes' => $since !== null ? intdiv($now - $since, 60) : null];
        }
        unset($r);
    }

    $total = count($addresses);
    $counted = 0;
    foreach (COUNTED as $s) $counted += $statusCounts[$s];
    $remaining = $total - $counted;
    $pacePerHour = $countedLastWindow * 60 / PACE_MINUTES;
    $etaMinutes = ($remaining > 0 && $pacePerHour > 0) ? (int)ceil($remaining / $pacePerHour * 60) : null;

    // Racks: most behind first, finished racks last, then by name.
    $rackRows = array_map(function ($r) {
        $r['percent'] = $r['total'] ? (int)floor($r['counted'] * 100 / $r['total']) : 0;
        $r['counters'] = count($r['counters']);
        return $r;
    }, array_values($racks));
    usort($rackRows, fn($x, $y) => [$x['percent'], $x['rack']] <=> [$y['percent'], $y['rack']]);

    // Counters ranking: everyone with the entry role plus anyone who counted.
    $counters = [];
    $controllers = [];
    foreach ($users as $uid => $u) {
        $p = $people[$uid] ?? null;
        $isCounter = $u['role'] === 'entry' || ($p && ($p['lines'] > 0 || $p['addresses'] > 0));
        $isController = $u['role'] === 'control' || ($p && $p['controlled'] > 0);
        if (!$isCounter && !$isController) continue;
        if (!(int)$u['active'] && !$p) continue;
        $p = $p ?? ['lines' => 0, 'addresses' => 0, 'sent_to_control' => 0, 'last' => null, 'last_address_id' => null, 'controlled' => 0, 'counted_last_hour' => 0];
        $idle = $p['last'] !== null ? intdiv($now - $p['last'], 60) : null;

        if ($isCounter && ($u['role'] !== 'control' || $p['lines'] > 0)) {
            $current = null;
            if ($p['last_address_id'] !== null && isset($addresses[$p['last_address_id']])
                && $addresses[$p['last_address_id']]['status'] === 'IN_PROGRESS') {
                $current = $addresses[$p['last_address_id']]['code'];
            }
            $counters[] = [
                'name' => $u['full_name'], 'username' => $u['username'],
                'addresses' => $p['addresses'], 'lines' => $p['lines'], 'last_hour' => $p['counted_last_hour'],
                'sent_to_control' => $p['sent_to_control'],
                'ok_percent' => $p['addresses'] ? (int)round(($p['addresses'] - $p['sent_to_control']) * 100 / $p['addresses']) : null,
                'current_address' => $current,
                'idle_minutes' => $idle,
                'state' => $idle === null ? 'not_started' : ($idle >= IDLE_MINUTES ? 'idle' : 'active'),
            ];
        }
        if ($isController) {
            $controllers[] = ['name' => $u['full_name'], 'controlled' => $p['controlled']];
        }
    }
    // Most addresses first; on a tie, the one who needed control less often.
    usort($counters, fn($x, $y) => [$y['addresses'], $y['ok_percent'] ?? -1, $y['lines'], $x['name']]
        <=> [$x['addresses'], $x['ok_percent'] ?? -1, $x['lines'], $y['name']]);
    usort($controllers, fn($x, $y) => [$y['controlled'], $x['name']] <=> [$x['controlled'], $y['name']]);

    usort($stuck, fn($x, $y) => $y['idle_minutes'] <=> $x['idle_minutes']);
    usort($queue, fn($x, $y) => ($y['waiting_minutes'] ?? -1) <=> ($x['waiting_minutes'] ?? -1));

    $activeNow = count(array_filter($counters, fn($c) => $c['state'] === 'active'));

    Response::ok([
        'generated_at' => date('Y-m-d H:i:s', $now),
        'settings' => ['idle_minutes' => IDLE_MINUTES, 'stuck_minutes' => STUCK_MINUTES, 'pace_minutes' => PACE_MINUTES],
        'overall' => [
            'total' => $total,
            'counted' => $counted,
            'remaining' => $remaining,
            'final' => $statusCounts['COMPLETED_OK'] + $statusCounts['CONTROLLED'],
            'percent' => $total ? (int)floor($counted * 100 / $total) : 0,
            'status_counts' => $statusCounts,
            'pace_per_hour' => round($pacePerHour, 1),
            'eta_minutes' => $etaMinutes,
            'eta_at' => $etaMinutes !== null ? date('H:i', $now + $etaMinutes * 60) : null,
            'counters_active' => $activeNow,
            'sent_to_control_percent' => $counted
                ? (int)round(($statusCounts['COMPLETED_CONTROL_REQUIRED'] + $statusCounts['CONTROL_IN_PROGRESS'] + $statusCounts['CONTROLLED']) * 100 / $counted)
                : null,
        ],
        'racks' => $rackRows,
        'counters' => $counters,
        'controllers' => $controllers,
        'stuck' => $stuck,
        'control_queue' => [
            'waiting' => $statusCounts['COMPLETED_CONTROL_REQUIRED'],
            'in_progress' => $statusCounts['CONTROL_IN_PROGRESS'],
            'oldest_minutes' => $queue ? $queue[0]['waiting_minutes'] : null,
            'items' => $queue,
        ],
    ]);
} catch (\Throwable $e) {
    error_log('[inventory-app] admin live failed: ' . $e->getMessage());
    Response::error('Live view query failed.', 500);
}
