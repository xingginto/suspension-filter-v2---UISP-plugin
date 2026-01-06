<?php

declare(strict_types=1);

use App\Http;
use Ubnt\UcrmPluginSdk\Security\PermissionNames;
use Ubnt\UcrmPluginSdk\Service\UcrmApi;
use Ubnt\UcrmPluginSdk\Service\UcrmSecurity;

chdir(__DIR__);

require __DIR__ . '/vendor/autoload.php';

$api = UcrmApi::create();

$security = UcrmSecurity::create();
$user = $security->getUser();
if (! $user || $user->isClient || ! $user->hasViewPermission(PermissionNames::CLIENTS_CLIENTS)) {
    Http::forbidden();
}

$filters = [
    'suspensionDateStart' => isset($_GET['suspensionDateStart']) ? (string) $_GET['suspensionDateStart'] : '',
    'suspensionDateEnd' => isset($_GET['suspensionDateEnd']) ? (string) $_GET['suspensionDateEnd'] : '',
    'archived' => isset($_GET['archived']) ? (string) $_GET['archived'] : '0',
    'tagId' => isset($_GET['tagId']) ? (string) $_GET['tagId'] : '',
    'latestSort' => isset($_GET['latestSort']) ? (string) $_GET['latestSort'] : 'desc',
];

$tags = $api->get('client-tags');

// Build tag lookup map for efficient access
$tagMap = [];
foreach ($tags as $tag) {
    $tagMap[(int) ($tag['id'] ?? 0)] = $tag;
}

$clientQuery = [
    'limit' => 10000,
    'offset' => 0,
];

if ($filters['archived'] !== '') {
    $clientQuery['isArchived'] = $filters['archived'] === '1' ? 1 : 0;
}

if ($filters['tagId'] !== '') {
    $clientQuery['clientTagIds[]'] = (int) $filters['tagId'];
}

$clients = $api->get('clients', $clientQuery);

// Build client lookup map for O(1) access
$clientMap = [];
foreach ($clients as $client) {
    $clientMap[(int) ($client['id'] ?? 0)] = $client;
}

$suspendedServices = $api->get(
    'clients/services',
    [
        'statuses[]' => 3,
        'limit' => 10000,
        'offset' => 0,
    ]
);

$suspendedClientDates = [];
foreach ($suspendedServices as $service) {
    $clientId = $service['clientId'] ?? null;
    if (! $clientId) {
        continue;
    }

    $dates = [];
    foreach (($service['suspensionPeriods'] ?? []) as $period) {
        if (! empty($period['startDate'])) {
            try {
                $dates[] = (new DateTimeImmutable((string) $period['startDate']))->format('Y-m-d');
            } catch (Exception $e) {
            }
        }
    }

    if (! array_key_exists($clientId, $suspendedClientDates)) {
        $suspendedClientDates[$clientId] = [];
    }

    foreach ($dates as $d) {
        $suspendedClientDates[$clientId][$d] = true;
    }
}

// First pass: identify suspended clients matching filters
$suspendedClientIds = [];
foreach ($clients as $client) {
    $clientId = $client['id'] ?? null;
    if (! $clientId) {
        continue;
    }

    $isSuspended = array_key_exists($clientId, $suspendedClientDates);
    if (! $isSuspended) {
        continue;
    }

    $start = $filters['suspensionDateStart'] !== '' ? $filters['suspensionDateStart'] : null;
    $end = $filters['suspensionDateEnd'] !== '' ? $filters['suspensionDateEnd'] : null;
    if ($start !== null || $end !== null) {
        $matchesRange = false;
        foreach (array_keys($suspendedClientDates[$clientId]) as $date) {
            if ($start !== null && $date < $start) {
                continue;
            }
            if ($end !== null && $date > $end) {
                continue;
            }
            $matchesRange = true;
            break;
        }
        if (! $matchesRange) {
            continue;
        }
    }

    $suspendedClientIds[] = $clientId;
}

// Batch fetch full client details for suspended clients only (includes tags)
$clientDetails = [];
foreach ($suspendedClientIds as $clientId) {
    $clientDetails[$clientId] = $api->get(sprintf('clients/%d', (int) $clientId));
}

// Build final results
$filteredClients = [];
foreach ($suspendedClientIds as $clientId) {
    $clientDetail = $clientDetails[$clientId] ?? [];
    $clientTags = $clientDetail['tags'] ?? [];

    $suspensionDates = array_keys($suspendedClientDates[$clientId]);
    sort($suspensionDates);
    $latestSuspensionDate = count($suspensionDates) > 0 ? end($suspensionDates) : null;

    $filteredClients[] = [
        'client' => $clientDetail,
        'tags' => $clientTags,
        'suspensionDates' => $suspensionDates,
        'latestSuspensionDate' => $latestSuspensionDate,
    ];
}

usort(
    $filteredClients,
    static function (array $a, array $b): int {
        $sort = isset($_GET['latestSort']) ? (string) $_GET['latestSort'] : 'desc';
        $ad = $a['latestSuspensionDate'] ?? '';
        $bd = $b['latestSuspensionDate'] ?? '';

        if ($ad !== $bd) {
            if ($sort === 'asc') {
                return $ad <=> $bd;
            }

            return $bd <=> $ad;
        }

        return (int) ($a['client']['id'] ?? 0) <=> (int) ($b['client']['id'] ?? 0);
    }
);

$qs = $_GET;
$qs['latestSort'] = ($filters['latestSort'] === 'asc') ? 'desc' : 'asc';
$toggleUrl = '?' . http_build_query($qs);
$sortIcon = $filters['latestSort'] === 'desc' ? '↓' : '↑';

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Client Suspension Filter</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
    body { font-family: 'Inter', sans-serif; }
  </style>
</head>
<body class="bg-gray-50 min-h-screen">
<div class="max-w-[1600px] mx-auto px-4 sm:px-6 lg:px-8 py-8">
  
  <!-- Header -->
  <div class="mb-8">
    <h1 class="text-3xl font-bold text-gray-900">Client Suspension Filter</h1>
    <p class="mt-2 text-sm text-gray-600">Filter and view suspended clients by date range, archive status, and tags.</p>
  </div>

  <!-- Filter Card -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 mb-6">
    <form method="GET">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
        
        <div>
          <label for="suspensionDateStart" class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
          <input type="date" name="suspensionDateStart" id="suspensionDateStart" 
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                 value="<?= htmlspecialchars($filters['suspensionDateStart']) ?>">
        </div>

        <div>
          <label for="suspensionDateEnd" class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
          <input type="date" name="suspensionDateEnd" id="suspensionDateEnd"
                 class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors"
                 value="<?= htmlspecialchars($filters['suspensionDateEnd']) ?>">
        </div>

        <div>
          <label for="archived" class="block text-sm font-medium text-gray-700 mb-1">Archive Status</label>
          <select name="archived" id="archived"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors bg-white">
            <option value="" <?= $filters['archived'] === '' ? 'selected' : '' ?>>All Clients</option>
            <option value="0" <?= $filters['archived'] === '0' ? 'selected' : '' ?>>Active Only</option>
            <option value="1" <?= $filters['archived'] === '1' ? 'selected' : '' ?>>Archived Only</option>
          </select>
        </div>

        <div>
          <label for="tagId" class="block text-sm font-medium text-gray-700 mb-1">Client Tag</label>
          <select name="tagId" id="tagId"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors bg-white">
            <option value="" <?= $filters['tagId'] === '' ? 'selected' : '' ?>>All Tags</option>
            <?php foreach ($tags as $tag): ?>
              <option value="<?= htmlspecialchars((string) ($tag['id'] ?? '')) ?>" <?= $filters['tagId'] === (string) ($tag['id'] ?? '') ? 'selected' : '' ?>>
                <?= htmlspecialchars((string) ($tag['name'] ?? '')) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label for="latestSort" class="block text-sm font-medium text-gray-700 mb-1">Sort Order</label>
          <select name="latestSort" id="latestSort"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg shadow-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors bg-white">
            <option value="desc" <?= $filters['latestSort'] === 'desc' ? 'selected' : '' ?>>Newest First</option>
            <option value="asc" <?= $filters['latestSort'] === 'asc' ? 'selected' : '' ?>>Oldest First</option>
          </select>
        </div>

        <div class="flex items-end gap-2">
          <button type="submit" 
                  class="flex-1 px-4 py-2 bg-blue-600 text-white font-medium rounded-lg hover:bg-blue-700 focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors shadow-sm">
            Apply Filters
          </button>
          <a href="?" 
             class="px-4 py-2 bg-gray-100 text-gray-700 font-medium rounded-lg hover:bg-gray-200 focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 transition-colors">
            Reset
          </a>
        </div>

      </div>
    </form>
  </div>

  <!-- Results Summary -->
  <div class="flex items-center justify-between mb-4">
    <div class="flex items-center gap-2">
      <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
        <?= count($filteredClients) ?> client<?= count($filteredClients) !== 1 ? 's' : '' ?> found
      </span>
      <?php if ($filters['suspensionDateStart'] || $filters['suspensionDateEnd']): ?>
        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-amber-100 text-amber-800">
          Date filtered
        </span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Table Card -->
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <div class="overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">ID</th>
            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">User Ident</th>
            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Name / Company</th>
            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">
              <a href="<?= htmlspecialchars($toggleUrl) ?>" class="inline-flex items-center gap-1 hover:text-blue-600 transition-colors">
                Latest Suspension
                <span class="text-blue-500"><?= $sortIcon ?></span>
              </a>
            </th>
            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">All Suspension Dates</th>
            <th scope="col" class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Tags</th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <?php if (count($filteredClients) === 0): ?>
            <tr>
              <td colspan="7" class="px-4 py-12 text-center">
                <div class="flex flex-col items-center">
                  <svg class="w-12 h-12 text-gray-300 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                  </svg>
                  <p class="text-gray-500 font-medium">No suspended clients found</p>
                  <p class="text-gray-400 text-sm mt-1">Try adjusting your filter criteria</p>
                </div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($filteredClients as $row): ?>
              <?php $c = $row['client']; ?>
              <tr class="hover:bg-gray-50 transition-colors">
                <td class="px-4 py-3 whitespace-nowrap">
                  <span class="text-sm font-mono text-gray-600"><?= htmlspecialchars((string) ($c['id'] ?? '')) ?></span>
                </td>
                <td class="px-4 py-3 whitespace-nowrap">
                  <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars((string) ($c['userIdent'] ?? '-')) ?></span>
                </td>
                <td class="px-4 py-3">
                  <div class="text-sm font-medium text-gray-900">
                    <?= htmlspecialchars(trim((string) (($c['firstName'] ?? '') . ' ' . ($c['lastName'] ?? '')))) ?: '-' ?>
                  </div>
                  <?php if (! empty($c['companyName'])): ?>
                    <div class="text-sm text-gray-500"><?= htmlspecialchars((string) $c['companyName']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="px-4 py-3 whitespace-nowrap">
                  <div class="flex flex-col gap-1">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800">
                      Suspended
                    </span>
                    <?php if (! empty($c['isArchived'])): ?>
                      <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800">
                        Archived
                      </span>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="px-4 py-3 whitespace-nowrap">
                  <span class="text-sm font-medium text-gray-900"><?= htmlspecialchars((string) ($row['latestSuspensionDate'] ?? '-')) ?></span>
                </td>
                <td class="px-4 py-3">
                  <div class="flex flex-wrap gap-1 max-w-xs">
                    <?php foreach (array_slice($row['suspensionDates'], 0, 5) as $date): ?>
                      <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200">
                        <?= htmlspecialchars($date) ?>
                      </span>
                    <?php endforeach; ?>
                    <?php if (count($row['suspensionDates']) > 5): ?>
                      <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">
                        +<?= count($row['suspensionDates']) - 5 ?> more
                      </span>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="px-4 py-3">
                  <?php if (! empty($row['tags'])): ?>
                    <div class="flex flex-wrap gap-1">
                      <?php foreach ($row['tags'] as $tag): ?>
                        <?php if (! empty($tag['name'])): ?>
                          <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-50 text-purple-700 border border-purple-200">
                            <?= htmlspecialchars((string) $tag['name']) ?>
                          </span>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </div>
                  <?php else: ?>
                    <span class="text-sm text-gray-400">-</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Footer -->
  <div class="mt-6 text-center text-sm text-gray-500">
    Client Suspension Filter Plugin v2.0.0
  </div>

</div>
</body>
</html>
