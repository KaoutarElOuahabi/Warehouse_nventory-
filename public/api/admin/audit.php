<?php
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Core\Auth;
use App\Core\Response;
use App\Services\AuditService;

Auth::requireRole('admin', 'control');

$filters = [];
if (!empty($_GET['entity_type'])) $filters['entity_type'] = $_GET['entity_type'];
if (!empty($_GET['entity_id'])) $filters['entity_id'] = (int)$_GET['entity_id'];
if (!empty($_GET['from'])) $filters['from'] = $_GET['from'];
if (!empty($_GET['to'])) $filters['to'] = $_GET['to'];
$limit = min((int)($_GET['limit'] ?? 200), 1000);
$offset = max((int)($_GET['offset'] ?? 0), 0);

$rows = AuditService::list($filters, $limit, $offset);

Response::ok(['entries' => $rows]);
