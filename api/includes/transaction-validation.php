<?php
/**
 * Transaction data validation functions
 */

class TransactionValidationError extends Exception {}

/**
 * Validate transaction item data
 */
function validateTransactionItem(array $item): array {
    $errors = [];

    // Validate productId
    if (empty($item['productId'])) {
        $errors[] = 'Product ID is required';
    } elseif (!is_string($item['productId'])) {
        $errors[] = 'Product ID must be a string';
    }

    // Validate quantity
    if (!isset($item['quantity'])) {
        $errors[] = 'Quantity is required';
    } elseif (!is_numeric($item['quantity'])) {
        $errors[] = 'Quantity must be numeric';
    } elseif ((float)$item['quantity'] <= 0) {
        $errors[] = 'Quantity must be greater than 0';
    }

    // Validate unitPrice
    if (!isset($item['unitPrice'])) {
        $errors[] = 'Unit price is required';
    } elseif (!is_numeric($item['unitPrice'])) {
        $errors[] = 'Unit price must be numeric';
    } elseif ((float)$item['unitPrice'] < 0) {
        $errors[] = 'Unit price cannot be negative';
    }

    // Validate variantId (optional)
    if (isset($item['variantId']) && $item['variantId'] !== null && !is_string($item['variantId'])) {
        $errors[] = 'Variant ID must be a string or null';
    }

    return $errors;
}

/**
 * Validate transaction data
 */
function validateTransaction(array $data): array {
    $errors = [];

    // Validate items
    if (empty($data['items']) || !is_array($data['items'])) {
        $errors[] = 'Transaction must contain at least one item';
    } else {
        foreach ($data['items'] as $index => $item) {
            $itemErrors = validateTransactionItem($item);
            if (!empty($itemErrors)) {
                $errors[] = "Item " . ($index + 1) . ": " . implode(", ", $itemErrors);
            }
        }
    }

    // Validate subtotal
    if (!isset($data['subtotal'])) {
        $errors[] = 'Subtotal is required';
    } elseif (!is_numeric($data['subtotal'])) {
        $errors[] = 'Subtotal must be numeric';
    } elseif ((float)$data['subtotal'] < 0) {
        $errors[] = 'Subtotal cannot be negative';
    }

    // Validate total
    if (!isset($data['total'])) {
        $errors[] = 'Total is required';
    } elseif (!is_numeric($data['total'])) {
        $errors[] = 'Total must be numeric';
    } elseif ((float)$data['total'] < 0) {
        $errors[] = 'Total cannot be negative';
    }

    // Validate discount (optional)
    if (isset($data['discount'])) {
        if (!is_numeric($data['discount'])) {
            $errors[] = 'Discount must be numeric';
        } elseif ((float)$data['discount'] < 0) {
            $errors[] = 'Discount cannot be negative';
        } else {
            $discount = (float)$data['discount'];
            $subtotal = (float)$data['subtotal'];
            if ($discount > $subtotal) {
                $errors[] = 'Discount cannot exceed subtotal';
            }
        }
    }

    // Validate paymentType
    if (empty($data['paymentType'])) {
        $errors[] = 'Payment type is required';
    } elseif (!in_array($data['paymentType'], ['cash', 'gcash', 'maya'])) {
        $errors[] = 'Invalid payment type. Must be one of: cash, gcash, maya';
    }

    // Validate calculation consistency
    if (isset($data['items']) && isset($data['subtotal'])) {
        $calculatedSubtotal = 0;
        foreach ($data['items'] as $item) {
            if (isset($item['quantity']) && isset($item['unitPrice'])) {
                $calculatedSubtotal += (float)$item['quantity'] * (float)$item['unitPrice'];
            }
        }
        $tolerance = 0.01;
        if (abs($calculatedSubtotal - (float)$data['subtotal']) > $tolerance) {
            $errors[] = 'Subtotal does not match sum of items (calculated: ' . round($calculatedSubtotal, 2) . ')';
        }
    }

    // Validate total = subtotal - discount
    if (isset($data['subtotal']) && isset($data['total'])) {
        $discount = isset($data['discount']) ? (float)$data['discount'] : 0;
        $expectedTotal = (float)$data['subtotal'] - $discount;
        $tolerance = 0.01;
        if (abs($expectedTotal - (float)$data['total']) > $tolerance) {
            $errors[] = 'Total does not equal subtotal minus discount (calculated: ' . round($expectedTotal, 2) . ')';
        }
    }

    return $errors;
}

/**
 * Validate refund request
 */
function validateRefundRequest(array $data): array {
    $errors = [];

    if (empty($data['transactionId'])) {
        $errors[] = 'Transaction ID is required';
    } elseif (!is_string($data['transactionId'])) {
        $errors[] = 'Transaction ID must be a string';
    }

    // Reason is optional but if provided should be a string
    if (isset($data['reason']) && !is_string($data['reason'])) {
        $errors[] = 'Reason must be a string';
    }

    return $errors;
}
?>
