<?php 
/**
 * Debug template - Yalnızca debug_trait tarafından kullanılmalıdır
 * @var string $method Son çağrılan metod
 * @var string $query Sorgu
 * @var string $params Parametreler (JSON)
 * @var string|null $error Hata mesajı
 */
if (!defined('NSQL_TEMPLATE')) return; 
?>
<style>
.nsql-debug {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    background: #f8f9fa;
    border: 1px solid #e9ecef;
    padding: 1.25rem;
    margin: 1rem 0;
    border-radius: 0.5rem;
    box-shadow: 0 2px 4px rgba(0,0,0,0.05);
}

.method-header {
    background: #4361ee;
    color: white;
    padding: 0.75rem 1rem;
    border-radius: 0.375rem;
    margin-bottom: 1rem;
    font-size: 1.125rem;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.method-header::before {
    content: '🔍';
}

.nsql-debug pre {
    background: #fff;
    border: 1px solid #e9ecef;
    padding: 1rem;
    margin: 0.5rem 0;
    border-radius: 0.375rem;
    overflow-x: auto;
    font-size: 0.875rem;
    line-height: 1.5;
    font-family: Monaco, Consolas, "Courier New", monospace;
}

.error {
    background: #fff5f5;
    border: 1px solid #feb2b2;
    color: #c53030;
    padding: 1rem;
    margin: 0.5rem 0;
    border-radius: 0.375rem;
    font-weight: 500;
}

.info {
    background: #ebf8ff;
    border: 1px solid #b3e5fc;
    color: #2b6cb0;
    padding: 1rem;
    margin: 0.5rem 0;
    border-radius: 0.375rem;
}

.info strong {
    display: block;
    margin-bottom: 0.5rem;
    font-size: 0.875rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
</style>

<div class="nsql-debug">
    <div class="method-header">
        <?= htmlspecialchars($method) ?>
    </div>
    
    <?php if ($error): ?>
        <div class="error">
            ⚠️ <strong>Hata:</strong> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>
    
    <div class="info">
        <strong>Sorgu:</strong>
        <pre><?= htmlspecialchars($query) ?></pre>
    </div>
      <?php if ($params): ?>
        <div class="info">
            <strong>Parametreler:</strong>
            <pre><?= htmlspecialchars($params) ?></pre>
        </div>
    <?php endif; ?>

    <?php if (isset($results) && $results !== '[]'): ?>
        <div class="info results">
            <strong>Sonuçlar:</strong>
            <pre><?= htmlspecialchars($results) ?></pre>
        </div>
    <?php endif; ?>

    <?php if (isset($results) && $results === '[]'): ?>
        <div class="info">
            <em>Sorgu sonucu boş</em>
        </div>
    <?php endif; ?>
</div>

<style>
.results pre {
    max-height: 300px;
    overflow-y: auto;
}
</style>
