<?php

function normalizeInventoryVariantId($variantId): ?string
{
    if ($variantId === null) {
        return null;
    }

    $variantId = trim((string) $variantId);
    return $variantId === '' ? null : $variantId;
}

function normalizeVariantId($variantId): ?string
{
    return normalizeInventoryVariantId($variantId);
}

function getTableColumns(PDO $pdo, string $table): array
{
    static $cache = [];

    if (isset($cache[$table])) {
        return $cache[$table];
    }

    $stmt = $pdo->query("DESCRIBE {$table}");
    $columns = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $column) {
        $columns[$column['Field']] = true;
    }

    $cache[$table] = $columns;

    return $columns;
}

function stockMovementsHasColumn(PDO $pdo, string $column): bool
{
    $columns = getTableColumns($pdo, 'stock_movements');
    return isset($columns[$column]);
}

function normalizeMovementType(string $movementType): string
{
    return $movementType === 'receiving' ? 'receive' : $movementType;
}

function getInventoryTierQuantityColumn(string $tier): string
{
    $validColumns = [
        'wholesale' => 'wholesaleQty',
        'retail' => 'retailQty',
        'shelf' => 'shelfQty',
    ];

    if (!isset($validColumns[$tier])) {
        throw new InvalidArgumentException('Invalid inventory tier');
    }

    return $validColumns[$tier];
}

function buildBatchAllocationMovementNotes(string $referenceType, string $referenceId, array $allocations): string
{
    return json_encode([
        'referenceType' => $referenceType,
        'referenceId' => $referenceId,
        'allocations' => array_values(array_map(
            static fn (array $allocation): array => [
                'batchId' => (string) ($allocation['batchId'] ?? ''),
                'batchNumber' => (string) ($allocation['batchNumber'] ?? ''),
                'quantity' => (int) ($allocation['quantity'] ?? 0),
            ],
            $allocations
        )),
    ], JSON_UNESCAPED_SLASHES);
}

function parseBatchAllocationMovementNotes(?string $notes): ?array
{
    if ($notes === null || trim($notes) === '') {
        return null;
    }

    $decoded = json_decode($notes, true);
    if (!is_array($decoded)) {
        return null;
    }

    if (!isset($decoded['referenceType'], $decoded['referenceId'], $decoded['allocations']) || !is_array($decoded['allocations'])) {
        return null;
    }

    return $decoded;
}

function insertStockMovement(PDO $pdo, array $movement): void
{
    $movementType = normalizeMovementType((string) ($movement['movementType'] ?? ''));
    if ($movementType === '') {
        throw new InvalidArgumentException('movementType is required');
    }

    $columns = ['id', 'productId', 'variantId', 'movementType', 'fromTier', 'toTier', 'quantity'];
    $placeholders = ['?', '?', '?', '?', '?', '?', '?'];
    $values = [
        $movement['id'] ?? bin2hex(random_bytes(16)),
        $movement['productId'] ?? null,
        normalizeInventoryVariantId($movement['variantId'] ?? null),
        $movementType,
        $movement['fromTier'] ?? null,
        $movement['toTier'] ?? null,
        (int) ($movement['quantity'] ?? 0),
    ];

    if (stockMovementsHasColumn($pdo, 'reason')) {
        $columns[] = 'reason';
        $placeholders[] = '?';
        $values[] = $movement['reason'] ?? null;
    }

    if (stockMovementsHasColumn($pdo, 'notes')) {
        $columns[] = 'notes';
        $placeholders[] = '?';
        $values[] = $movement['notes'] ?? null;
    }

    $actorId = $movement['performedBy'] ?? $movement['createdBy'] ?? null;
    if ($actorId !== null) {
        if (stockMovementsHasColumn($pdo, 'performedBy')) {
            $columns[] = 'performedBy';
            $placeholders[] = '?';
            $values[] = $actorId;
        } elseif (stockMovementsHasColumn($pdo, 'createdBy')) {
            $columns[] = 'createdBy';
            $placeholders[] = '?';
            $values[] = $actorId;
        }
    }

    if (array_key_exists('createdAt', $movement) && stockMovementsHasColumn($pdo, 'createdAt')) {
        $columns[] = 'createdAt';
        $placeholders[] = '?';
        $values[] = $movement['createdAt'];
    }

    $sql = sprintf(
        'INSERT INTO stock_movements (%s) VALUES (%s)',
        implode(', ', $columns),
        implode(', ', $placeholders)
    );

    $stmt = $pdo->prepare($sql);
    $stmt->execute($values);
}

function moveBatchStockFEFO(PDO $pdo, string $productId, ?string $variantId, string $sourceTier, ?string $destTier, int $quantity): array
{
    if ($quantity <= 0) {
        throw new InvalidArgumentException('Quantity must be greater than zero');
    }

    $sourceColumn = getInventoryTierQuantityColumn($sourceTier);
    $destColumn = $destTier !== null ? getInventoryTierQuantityColumn($destTier) : null;
    $variantId = normalizeInventoryVariantId($variantId);

    $sql = "SELECT id, batchNumber, {$sourceColumn}
        FROM product_batches
        WHERE productId = :productId
          AND status != 'disposed'
          AND {$sourceColumn} > 0";
    $params = [':productId' => $productId];

    if ($variantId === null) {
        $sql .= ' AND variantId IS NULL';
    } else {
        $sql .= ' AND variantId = :variantId';
        $params[':variantId'] = $variantId;
    }

    $sql .= ' ORDER BY expirationDate ASC, receivedDate ASC FOR UPDATE';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $remaining = $quantity;
    $allocations = [];

    foreach ($batches as $batch) {
        if ($remaining <= 0) {
            break;
        }

        $availableQty = (int) $batch[$sourceColumn];
        if ($availableQty <= 0) {
            continue;
        }

        $movedQty = min($remaining, $availableQty);
        $updateSql = "UPDATE product_batches SET {$sourceColumn} = {$sourceColumn} - :quantity";
        if ($destColumn !== null) {
            $updateSql .= ", {$destColumn} = {$destColumn} + :quantity";
        }
        $updateSql .= ' WHERE id = :id';

        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([
            ':quantity' => $movedQty,
            ':id' => $batch['id'],
        ]);

        $allocations[] = [
            'batchId' => $batch['id'],
            'batchNumber' => $batch['batchNumber'],
            'quantity' => $movedQty,
        ];
        $remaining -= $movedQty;
    }

    if ($remaining > 0) {
        throw new RuntimeException('Insufficient batch stock to complete operation');
    }

    return $allocations;
}

function restoreBatchStock(PDO $pdo, string $productId, ?string $variantId, string $tier, int $quantity, ?array $allocations = null): array
{
    if ($quantity <= 0) {
        throw new InvalidArgumentException('Quantity must be greater than zero');
    }

    $tierColumn = getInventoryTierQuantityColumn($tier);
    $variantId = normalizeInventoryVariantId($variantId);

    if (is_array($allocations) && $allocations !== []) {
        $restored = [];
        $restoredTotal = 0;

        foreach ($allocations as $allocation) {
            $batchId = trim((string) ($allocation['batchId'] ?? ''));
            $allocationQty = (int) ($allocation['quantity'] ?? 0);

            if ($batchId === '' || $allocationQty <= 0) {
                continue;
            }

            $stmt = $pdo->prepare("SELECT id, batchNumber, status FROM product_batches WHERE id = ? FOR UPDATE");
            $stmt->execute([$batchId]);
            $batch = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$batch) {
                throw new RuntimeException('Unable to restore batch stock because one allocated batch no longer exists');
            }

            if (($batch['status'] ?? '') === 'disposed') {
                throw new RuntimeException('Unable to restore batch stock to a disposed batch');
            }

            $updateStmt = $pdo->prepare("UPDATE product_batches SET {$tierColumn} = {$tierColumn} + :quantity WHERE id = :id");
            $updateStmt->execute([
                ':quantity' => $allocationQty,
                ':id' => $batchId,
            ]);

            $restored[] = [
                'batchId' => $batchId,
                'batchNumber' => $batch['batchNumber'],
                'quantity' => $allocationQty,
            ];
            $restoredTotal += $allocationQty;
        }

        if ($restoredTotal !== $quantity) {
            throw new RuntimeException('Recorded batch allocation data does not match the quantity being restored');
        }

        return $restored;
    }

    $sql = "SELECT id, batchNumber
        FROM product_batches
        WHERE productId = :productId
          AND status != 'disposed'";
    $params = [':productId' => $productId];

    if ($variantId === null) {
        $sql .= ' AND variantId IS NULL';
    } else {
        $sql .= ' AND variantId = :variantId';
        $params[':variantId'] = $variantId;
    }

    $sql .= ' ORDER BY receivedDate DESC, expirationDate DESC LIMIT 1 FOR UPDATE';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $batch = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$batch) {
        throw new RuntimeException('Unable to restore batch stock because no eligible batch was found');
    }

    $updateStmt = $pdo->prepare("UPDATE product_batches SET {$tierColumn} = {$tierColumn} + :quantity WHERE id = :id");
    $updateStmt->execute([
        ':quantity' => $quantity,
        ':id' => $batch['id'],
    ]);

    return [[
        'batchId' => $batch['id'],
        'batchNumber' => $batch['batchNumber'],
        'quantity' => $quantity,
    ]];
}

function fetchRecordedBatchAllocations(
    PDO $pdo,
    string $movementType,
    string $referenceType,
    string $referenceId,
    string $productId,
    ?string $variantId = null
): ?array {
    if (!stockMovementsHasColumn($pdo, 'notes')) {
        return null;
    }

    $variantId = normalizeInventoryVariantId($variantId);

    $sql = 'SELECT notes
        FROM stock_movements
        WHERE movementType = :movementType
          AND productId = :productId
          AND ' . ($variantId === null ? 'variantId IS NULL' : 'variantId = :variantId') . '
        ORDER BY createdAt DESC';
    $params = [
        ':movementType' => normalizeMovementType($movementType),
        ':productId' => $productId,
    ];
    if ($variantId !== null) {
        $params[':variantId'] = $variantId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $parsed = parseBatchAllocationMovementNotes($row['notes'] ?? null);
        if (!$parsed) {
            continue;
        }

        if (($parsed['referenceType'] ?? null) === $referenceType && ($parsed['referenceId'] ?? null) === $referenceId) {
            return is_array($parsed['allocations']) ? $parsed['allocations'] : null;
        }
    }

    return null;
}

function fetchInventoryLevel(PDO $pdo, string $productId, ?string $variantId = null, bool $lock = false): ?array
{
    $variantId = normalizeInventoryVariantId($variantId);
    $sql = 'SELECT id, productId, variantId, wholesaleQty, retailQty, shelfQty, wholesaleUnit, retailUnit, shelfUnit, pcsPerPack, packsPerBox, shelfRestockLevel, wholesaleReorderLevel, retailRestockLevel, updatedAt
            FROM inventory_levels
            WHERE productId = :productId AND ' . ($variantId === null ? 'variantId IS NULL' : 'variantId = :variantId') . ($lock ? ' FOR UPDATE' : '');

    $stmt = $pdo->prepare($sql);
    $params = [':productId' => $productId];
    if ($variantId !== null) {
        $params[':variantId'] = $variantId;
    }

    $stmt->execute($params);
    $inventory = $stmt->fetch(PDO::FETCH_ASSOC);

    return $inventory ?: null;
}

function aggregateBatchStock(PDO $pdo, string $productId, ?string $variantId = null): array
{
    $variantId = normalizeInventoryVariantId($variantId);
    $sql = 'SELECT
                COALESCE(SUM(wholesaleQty), 0) AS wholesaleQty,
                COALESCE(SUM(retailQty), 0) AS retailQty,
                COALESCE(SUM(shelfQty), 0) AS shelfQty
            FROM product_batches
            WHERE productId = :productId';

    $params = [':productId' => $productId];
    if ($variantId === null) {
        $sql .= ' AND variantId IS NULL';
    } else {
        $sql .= ' AND variantId = :variantId';
        $params[':variantId'] = $variantId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'wholesaleQty' => (int) ($row['wholesaleQty'] ?? 0),
        'retailQty' => (int) ($row['retailQty'] ?? 0),
        'shelfQty' => (int) ($row['shelfQty'] ?? 0),
    ];
}

function createInventoryLevel(PDO $pdo, string $productId, ?string $variantId = null): array
{
    $variantId = normalizeInventoryVariantId($variantId);
    $stock = aggregateBatchStock($pdo, $productId, $variantId);
    $inventoryId = bin2hex(random_bytes(16));

    $stmt = $pdo->prepare(
        'INSERT INTO inventory_levels (
            id,
            productId,
            variantId,
            wholesaleQty,
            retailQty,
            shelfQty,
            wholesaleUnit,
            retailUnit,
            shelfUnit,
            pcsPerPack,
            packsPerBox,
            shelfRestockLevel,
            wholesaleReorderLevel,
            retailRestockLevel
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $inventoryId,
        $productId,
        $variantId,
        $stock['wholesaleQty'],
        $stock['retailQty'],
        $stock['shelfQty'],
        'box',
        'pack',
        'pack',
        1,
        1,
        0,
        0,
        0,
    ]);

    return fetchInventoryLevel($pdo, $productId, $variantId, false) ?: [
        'id' => $inventoryId,
        'productId' => $productId,
        'variantId' => $variantId,
        'wholesaleQty' => $stock['wholesaleQty'],
        'retailQty' => $stock['retailQty'],
        'shelfQty' => $stock['shelfQty'],
        'wholesaleUnit' => 'box',
        'retailUnit' => 'pack',
        'shelfUnit' => 'pack',
        'pcsPerPack' => 1,
        'packsPerBox' => 1,
        'shelfRestockLevel' => 0,
        'wholesaleReorderLevel' => 0,
        'retailRestockLevel' => 0,
        'updatedAt' => date('Y-m-d H:i:s'),
    ];
}

function getOrCreateInventoryLevel(PDO $pdo, string $productId, ?string $variantId = null, bool $lock = false): array
{
    $variantId = normalizeInventoryVariantId($variantId);

    $inventory = fetchInventoryLevel($pdo, $productId, $variantId, $lock);
    if ($inventory) {
        return $inventory;
    }

    return createInventoryLevel($pdo, $productId, $variantId);
}
