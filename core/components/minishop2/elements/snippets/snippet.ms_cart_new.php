<?php
/** @var modX $modx */
/** @var array $scriptProperties */
/** @var miniShop2 $miniShop2 */
$miniShop2 = $modx->getService('miniShop2');
$miniShop2->initialize($modx->context->key);
/** @var pdoFetch $pdoFetch */
$fqn = $modx->getOption('pdoFetch.class', null, 'pdotools.pdofetch', true);
$path = $modx->getOption('pdofetch_class_path', null, MODX_CORE_PATH . 'components/pdotools/model/', true);
if ($pdoClass = $modx->loadClass($fqn, $path, false, true)) {
    $pdoFetch = new $pdoClass($modx, $scriptProperties);
} else {
    return false;
}
$pdoFetch->addTime('pdoTools loaded.');

$tpl = $modx->getOption('tpl', $scriptProperties, 'tpl.msCartNew');
$tplRow = $modx->getOption('tplRow', $scriptProperties, 'tpl.msCartProductRow');
$tplKey = $modx->getOption('tplKey', $scriptProperties, md5($tpl . $tplRow));

$scriptProperties['tplKey'] = $tplKey;
$scriptProperties['tplRow'] = $tplRow;

$_SESSION['minishop2']['msCart'][$tplKey] = $scriptProperties;

$cart = $miniShop2->cart->get();
$status = $miniShop2->cart->status();

// Do not show empty cart when displaying order details
if (!empty($_GET['msorder'])) {
    return '';
} elseif (empty($status['total_count'])) {
    return $pdoFetch->getChunk($tpl, ['scriptProperties' => $scriptProperties]);
}

// Select cart products
$where = [
    'msProduct.id:IN' => [],
];
foreach ($cart as $entry) {
    $where['msProduct.id:IN'][] = $entry['id'];
}
$where['msProduct.id:IN'] = array_unique($where['msProduct.id:IN']);

// Include products properties
$leftJoin = [
    'Data' => [
        'class' => 'msProductData',
    ],
    'Vendor' => [
        'class' => 'msVendor',
        'on' => 'Data.vendor = Vendor.id',
    ],
];

// Select columns
$select = [
    'msProduct' => !empty($includeContent)
        ? $modx->getSelectColumns('msProduct', 'msProduct')
        : $modx->getSelectColumns('msProduct', 'msProduct', '', ['content'], true),
    'Data' => $modx->getSelectColumns('msProductData', 'Data', '', ['id'], true),
    'Vendor' => $modx->getSelectColumns('msVendor', 'Vendor', 'vendor.', ['id'], true),
];

// Include products thumbnails
if (!empty($includeThumbs)) {
    $thumbs = array_map('trim', explode(',', $includeThumbs));
    if (!empty($thumbs[0])) {
        foreach ($thumbs as $thumb) {
            $leftJoin[$thumb] = [
                'class' => 'msProductFile',
                'on' => "`{$thumb}`.product_id = msProduct.id AND `{$thumb}`.parent != 0 AND `{$thumb}`.path LIKE '%/{$thumb}/%' AND `{$thumb}`.`rank` = 0",
            ];
            $select[$thumb] = "`{$thumb}`.url as '{$thumb}'";
        }
        $pdoFetch->addTime('Included list of thumbnails: <b>' . implode(', ', $thumbs) . '</b>.');
    }
}

// Add user parameters
foreach (['where', 'leftJoin', 'select'] as $v) {
    if (!empty($scriptProperties[$v])) {
        $tmp = $scriptProperties[$v];
        if (!is_array($tmp)) {
            $tmp = json_decode($tmp, true);
        }
        if (is_array($tmp)) {
            $$v = array_merge($$v, $tmp);
        }
    }
    unset($scriptProperties[$v]);
}
$pdoFetch->addTime('Conditions prepared');

$default = [
    'class' => 'msProduct',
    'where' => $where,
    'leftJoin' => $leftJoin,
    'select' => $select,
    'sortby' => 'msProduct.id',
    'sortdir' => 'ASC',
    'groupby' => 'msProduct.id',
    'limit' => 0,
    'return' => 'data',
    'nestedChunkPrefix' => 'minishop2_',
];
// Merge all properties and run!
$pdoFetch->setConfig(array_merge($default, $scriptProperties), false);

$tmp = $pdoFetch->run();
$rows = [];
foreach ($tmp as $row) {
    $rows[$row['id']] = $row;
}

// Process products in cart
$products = '';
$total = ['count' => 0, 'weight' => 0, 'cost' => 0, 'discount' => 0];
$c = 0;
//$modx->log(1, print_r($cart,1));
//$modx->log(1, print_r($status,1));
foreach ($cart as $key => $entry) {
    if (!isset($rows[$entry['id']])) {
        continue;
    }
    $product = array_merge($rows[$entry['id']], $entry);

    $product['idx'] = ++$c;

    $product['old_price'] = $miniShop2->formatPrice($entry['old_price']);
    $product['old_cost'] = $miniShop2->formatPrice($entry['old_cost']);
    $product['price'] = $miniShop2->formatPrice($entry['price']);
    $product['weight'] = $miniShop2->formatWeight($entry['weight']);
    $product['cost'] = $miniShop2->formatPrice($entry['cost']);
    $product['discount_price'] = $miniShop2->formatPrice($entry['discount_price']);
    $product['discount_cost'] = $miniShop2->formatPrice($entry['discount_cost']);

    $products .= $pdoFetch->getChunk($tplRow, $product);
}
$total['count'] = $status['total_count'];
$total['cost'] = $miniShop2->formatPrice($status['total_cost']);
$total['old_cost'] = $miniShop2->formatPrice($status['total_old_cost']);
$total['weight'] = $miniShop2->formatWeight($status['total_weight']);
$total['discount'] = $miniShop2->formatPrice($status['total_discount']);
$total['discount_percent'] = $status['total_discount_percent'];
$total['positions'] = $status['total_positions'];

$output = $pdoFetch->getChunk($tpl, [
    'total' => $total,
    'products' => $products,
    'scriptProperties' => $scriptProperties
]);

if ($modx->user->hasSessionContext('mgr') && !empty($showLog)) {
    $output .= '<pre class="msCartLog">' . print_r($pdoFetch->getTime(), true) . '</pre>';
}

if (!empty($toPlaceholder)) {
    $modx->setPlaceholder($toPlaceholder, $output);
} else {
    return $output;
}
