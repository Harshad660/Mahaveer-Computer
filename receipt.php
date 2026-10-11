<?php
require __DIR__.'/config.php';
auth();

try {
    // Make sure fields used by newer versions exist. Safe if already present.
    if (function_exists('db')) {
        $pdo = db();
        $c = $pdo->query("SHOW COLUMNS FROM payments LIKE 'operator_name'")->fetch();
        if (!$c) $pdo->exec("ALTER TABLE payments ADD operator_name VARCHAR(100) NOT NULL DEFAULT ''");
        $c = $pdo->query("SHOW COLUMNS FROM applications LIKE 'operator_name'")->fetch();
        if (!$c) $pdo->exec("ALTER TABLE applications ADD operator_name VARCHAR(100) NOT NULL DEFAULT ''");
    }

    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) throw new Exception('Invalid receipt number.');

    $d = db();
    $sql = "SELECT p.*, c.name AS customer, c.mobile AS mobile,
                   a.total AS application_total, a.paid AS application_paid,
                   a.id AS application_id, sv.name AS service
            FROM payments p
            LEFT JOIN customers c ON c.id=p.customer_id
            LEFT JOIN applications a ON a.id=p.application_id
            LEFT JOIN services sv ON sv.id=a.service_id
            WHERE p.id=? LIMIT 1";
    $s = $d->prepare($sql);
    $s->execute([$id]);
    $p = $s->fetch();
    if (!$p) throw new Exception('Receipt not found.');

    $shop = $d->query('SELECT * FROM settings WHERE id=1 LIMIT 1')->fetch();
    if (!$shop) $shop = ['shop_name'=>'Mahaveer Computer','address'=>'','mobile'=>'','gstin'=>''];

    $total = $p['application_id'] !== null ? (float)$p['application_total'] : null;
    $paid  = $p['application_id'] !== null ? (float)$p['application_paid'] : null;
    $balance = $p['application_id'] !== null ? ($total - $paid) : null;
    $label = ($balance !== null && $balance < 0) ? 'Advance / Excess' : 'Due Amount';
    $value = $balance === null ? null : abs($balance);
    $operator = $p['operator_name'] ?? '';
    $mode = $p['mode'] ?? '';
    $note = $p['note'] ?? '';
    $paymentDate = $p['payment_date'] ?? date('Y-m-d');

} catch (Throwable $e) {
    http_response_code(500);
    echo '<!doctype html><html><body style="font-family:Arial;padding:30px">';
    echo '<h2>Receipt Error</h2><p>'.e($e->getMessage()).'</p>';
    echo '<p><a href="index.php?view=payments">← Back to Payments</a></p>';
    echo '</body></html>';
    exit;
}
?>
<!doctype html>
<html><head>
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Receipt MC-<?=str_pad($id,6,'0',STR_PAD_LEFT)?></title>
<style>
body{font-family:Arial,sans-serif;background:#eee;padding:20px}.receipt{background:#fff;max-width:650px;margin:auto;padding:30px;border:1px solid #ddd}.center{text-align:center}table{width:100%;border-collapse:collapse}td{padding:10px;border-bottom:1px solid #ddd}.amt{text-align:right;font-size:24px;font-weight:bold}.summary{margin-top:15px;border:1px solid #ddd;border-radius:8px;padding:10px}.summary div{display:flex;justify-content:space-between;padding:7px 0}.actions{text-align:center;margin-top:20px}.btn{padding:10px 14px;border:0;border-radius:6px;text-decoration:none;background:#3157c9;color:#fff;cursor:pointer}@media print{body{background:#fff;padding:0}.print{display:none}.receipt{border:0}}
</style></head>
<body><div class="receipt">
<div class="center"><h2><?=e($shop['shop_name'] ?? 'Mahaveer Computer')?></h2><p><?=e($shop['address'] ?? '')?><br><?=e($shop['mobile'] ?? '')?></p><hr><h3>PAYMENT RECEIPT</h3></div>
<table>
<tr><td>Receipt No.</td><td>MC-<?=str_pad($id,6,'0',STR_PAD_LEFT)?></td></tr>
<tr><td>Date</td><td><?=e($paymentDate)?></td></tr>
<tr><td>Customer</td><td><?=e($p['customer'] ?? '')?><?php if(($p['mobile'] ?? '')!==''):?> (<?=e($p['mobile'])?>)<?php endif;?></td></tr>
<tr><td>Service</td><td><?=e($p['service'] ?? 'Payment')?></td></tr>
<tr><td>Operator</td><td><?=e($operator)?></td></tr>
<tr><td>Payment Mode</td><td><?=e($mode)?></td></tr>
</table>
<?php if($p['application_id'] !== null):?>
<div class="summary">
<div><span>Total Amount</span><b><?=money($total)?></b></div>
<div><span>Total Paid</span><b><?=money($paid)?></b></div>
<div><span><?=e($label)?></span><b><?=money($value)?></b></div>
</div>
<?php endif;?>
<p class="amt">Received This Time: <?=money($p['amount'] ?? 0)?></p>
<?php if($note!==''):?><p>Note: <?=e($note)?></p><?php endif;?>
<hr><div class="center">Thank you for choosing <?=e($shop['shop_name'] ?? 'Mahaveer Computer')?></div>
<div class="actions print"><button class="btn" onclick="window.print()">🖨️ Print Receipt</button> <a class="btn" href="index.php?view=payments">Back</a></div>
</div></body></html>
