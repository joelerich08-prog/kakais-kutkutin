<?php

require_once __DIR__ . '/../middleware/cors.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/inventory.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

try {
    $pdo = Database::getInstance();

    $actorColumn = stockMovementsHasColumn($pdo, 'performedBy')
        ? 'performedBy'
        : (stockMovementsHasColumn($pdo, 'createdBy') ? 'createdBy' : null);

    $performedBySelect = $actorColumn !== null
        ? "COALESCE(u.name, sm.{$actorColumn}, 'Unknown User') AS performedBy"
        : "'Unknown User' AS performedBy";

    $joinUsers = $actorColumn !== null
        ? "LEFT JOIN users u ON sm.{$actorColumn} = u.id"
        : '';

    $notesSelect = stockMovementsHasColumn($pdo, 'notes')
        ? 'sm.notes'
        : 'NULL AS notes';

    $stmt = $pdo->query(
        "SELECT
            sm.id,
            sm.productId,
            sm.variantId,
            p.name AS productName,
            pv.name AS variantName,
            CASE WHEN sm.movementType = 'receiving' THEN 'receive' ELSE sm.movementType END AS movementType,
            sm.fromTier,
            sm.toTier,
            sm.quantity,
            sm.reason,
            {$notesSelect},
            {$performedBySelect},
            sm.createdAt
        FROM stock_movements sm
        INNER JOIN products p ON sm.productId = p.id
        LEFT JOIN product_variants pv ON sm.variantId = pv.id
        {$joinUsers}
        ORDER BY sm.createdAt DESC"
    );

    $movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($movements);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch stock movements', 'details' => $e->getMessage()]);
}
?>
