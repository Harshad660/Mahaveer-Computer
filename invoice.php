<?php
require __DIR__.'/config.php';
auth();
$pdo=db();
$id=(int)($_GET['id']??0);
if(!$id) die('Invalid bill.');
$me=$pdo->prepare("SELECT username,COALESCE(role,'operator') role FROM users WHERE username=? LIMIT 1");
$me->execute([$_SESSION['username']??'']);
$me=$me->fetch()?:['username'=>'','role'=>'operator'];
$admin=$me['role']==='admin'||strtolower($me['username'])==='admin';
$q=$admin?$pdo->prepare('SELECT * FROM bills WHERE id=?'):$pdo->prepare('SELECT * FROM bills WHERE id=? AND operator_name=?');
$admin?$q->execute([$id]):$q->execute([$id,$me['username']]);
$b=$q->fetch();
if(!$b) die('Bill not found.');
$q=$pdo->prepare('SELECT * FROM bill_items WHERE bill_id=? ORDER BY id');
$q->execute([$id]);
$items=$q->fetchAll();
$shop=$pdo->query('SELECT * FROM settings WHERE id=1')->fetch()?:['shop_name'=>'Mahaveer Computer','mobile'=>'','address'=>'','gstin'=>''];
function h2($x){return htmlspecialchars((string)$x,ENT_QUOTES,'UTF-8');}
function r2($x){return '₹'.number_format((float)$x,2);}
$billNo='MC-BILL-'.str_pad($id,6,'0',STR_PAD_LEFT);
$due=(float)$b['due'];
$status=$due<=0.001?'PAID':'PAYMENT DUE';
$statusClass=$due<=0.001?'paid':'due';
?><!doctype html>
<html lang="en"><head>
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h2($shop['shop_name'])?> • Invoice <?=$billNo?></title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#eef2f7;color:#172033;font-family:Inter,Segoe UI,Arial,sans-serif;padding:28px}
.invoice{width:210mm;max-width:100%;min-height:297mm;margin:auto;background:#fff;border-radius:18px;box-shadow:0 16px 45px rgba(18,35,70,.14);overflow:hidden}
.topbar{height:9px;background:linear-gradient(90deg,#173b8f,#2563eb,#06b6d4)}
.header{padding:28px 34px 24px;display:flex;justify-content:space-between;gap:25px;border-bottom:1px solid #e6eaf0}
.brand{display:flex;gap:16px;align-items:flex-start}
.logo{width:58px;height:58px;border-radius:16px;background:linear-gradient(135deg,#173b8f,#2563eb);color:#fff;display:flex;align-items:center;justify-content:center;font-size:29px;font-weight:900;box-shadow:0 8px 18px rgba(37,99,235,.25)}
.shop h1{margin:0;color:#102f75;font-size:30px;letter-spacing:.2px;font-weight:900}
.shop .tag{margin-top:5px;color:#64748b;font-size:12px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase}
.contact{margin-top:10px;color:#475569;font-size:12px;line-height:1.65;max-width:520px}
.invoice-box{text-align:right;min-width:210px}
.invoice-box .label{font-size:11px;font-weight:900;letter-spacing:1.5px;color:#64748b}
.invoice-box h2{margin:4px 0 9px;font-size:25px;color:#173b8f;letter-spacing:.5px}
.billno{font-size:13px;font-weight:900;color:#172033}
.date{font-size:12px;color:#64748b;margin-top:4px}
.status{display:inline-block;margin-top:10px;padding:6px 11px;border-radius:999px;font-size:10px;font-weight:900;letter-spacing:1px}
.status.paid{background:#dcfce7;color:#15803d}.status.due{background:#fff7ed;color:#c2410c}
.content{padding:25px 34px 30px}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:24px}
.info-card{border:1px solid #e4e9f0;border-radius:13px;padding:15px 17px;background:linear-gradient(180deg,#fff,#f8fafc)}
.info-title{font-size:10px;letter-spacing:1.3px;text-transform:uppercase;color:#64748b;font-weight:900;margin-bottom:7px}
.info-name{font-size:15px;font-weight:900;color:#162d5f;margin-bottom:3px}
.info-text{font-size:12px;color:#475569;line-height:1.55;white-space:normal}
table{width:100%;border-collapse:separate;border-spacing:0;overflow:hidden;border:1px solid #e1e7ef;border-radius:12px}
th{background:#173b8f;color:#fff;padding:11px 9px;font-size:10px;text-transform:uppercase;letter-spacing:.65px;text-align:left;border-right:1px solid rgba(255,255,255,.12)}
th:last-child{border-right:0}
td{padding:10px 9px;border-top:1px solid #e9edf2;font-size:11px;color:#263449;vertical-align:top}
tbody tr:nth-child(even){background:#f8fafc}
.num{text-align:right;white-space:nowrap}.item{font-weight:800;color:#1d355f}.muted{color:#64748b;font-size:10px}
.bottom{display:grid;grid-template-columns:1fr 330px;gap:24px;margin-top:20px;align-items:start}
.note{border-radius:13px;background:#f1f5ff;border:1px solid #dbe6ff;padding:15px 17px;color:#475569;font-size:11px;line-height:1.65}
.note strong{display:block;color:#173b8f;font-size:11px;margin-bottom:5px;text-transform:uppercase;letter-spacing:1px}
.summary{border:1px solid #e1e7ef;border-radius:14px;overflow:hidden;background:#fff}
.summary-row{display:flex;justify-content:space-between;gap:15px;padding:9px 14px;border-bottom:1px solid #edf0f4;font-size:12px}.summary-row:last-child{border-bottom:0}
.summary-row b{font-weight:900}.grand{background:linear-gradient(135deg,#173b8f,#2563eb);color:#fff;padding:14px}.grand span{font-size:12px;font-weight:800}.grand b{font-size:20px}
.due-row{color:#c2410c;font-weight:900}.paid-row{color:#15803d;font-weight:900}
.footer{margin-top:28px;padding-top:17px;border-top:1px dashed #cbd5e1;text-align:center;color:#64748b;font-size:10px;line-height:1.7}
.footer .thanks{font-size:14px;font-weight:900;color:#173b8f;margin-bottom:3px}
.actions{text-align:center;margin:25px 0 5px}.btn{display:inline-block;background:linear-gradient(135deg,#173b8f,#2563eb);color:#fff;padding:11px 18px;border-radius:9px;text-decoration:none;border:0;font-weight:900;font-size:12px;cursor:pointer;box-shadow:0 7px 16px rgba(37,99,235,.2)}.btn.secondary{background:#fff;color:#173b8f;border:1px solid #cbd5e1;box-shadow:none}
@media(max-width:720px){body{padding:8px}.invoice{border-radius:10px}.header{padding:20px;flex-direction:column}.invoice-box{text-align:left}.content{padding:18px}.info-grid,.bottom{grid-template-columns:1fr}.bottom{gap:15px}table{display:block;overflow-x:auto;white-space:nowrap}.shop h1{font-size:25px}}
@media print{body{padding:0;background:#fff}.invoice{width:210mm;min-height:297mm;border-radius:0;box-shadow:none}.actions{display:none}.topbar{-webkit-print-color-adjust:exact;print-color-adjust:exact}.header,.content{break-inside:avoid}th,.grand,.logo{print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style></head>
<body>
<div class="invoice">
  <div class="topbar"></div>
  <header class="header">
    <div class="brand">
      <div class="logo">MC</div>
      <div class="shop">
        <h1><?=h2($shop['shop_name'])?></h1>
        <div class="tag">Computer • Laptop • CCTV • Printer • Services</div>
        <div class="contact">
          <?php if(trim($shop['address']??'')):?>📍 <?=nl2br(h2($shop['address']))?><br><?php endif;?>
          <?php if(trim($shop['mobile']??'')):?>📞 <?=h2($shop['mobile'])?><?php endif;?>
          <?php if(trim($shop['gstin']??'')):?> &nbsp;•&nbsp; GSTIN: <?=h2($shop['gstin'])?><?php endif;?>
        </div>
      </div>
    </div>
    <div class="invoice-box">
      <div class="label">Official Document</div>
      <h2>TAX INVOICE</h2>
      <div class="billno"># <?=$billNo?></div>
      <div class="date">Invoice Date: <?=h2(date('d-m-Y',strtotime($b['bill_date'])))?></div>
      <span class="status <?=$statusClass?>">● <?=$status?></span>
    </div>
  </header>

  <main class="content">
    <div class="info-grid">
      <div class="info-card">
        <div class="info-title">Bill To</div>
        <div class="info-name"><?=h2($b['customer_name'])?></div>
        <?php if(trim($b['mobile']??'')):?><div class="info-text">📞 <?=h2($b['mobile'])?></div><?php endif;?>
        <?php if(trim($b['address']??'')):?><div class="info-text">📍 <?=nl2br(h2($b['address']))?></div><?php endif;?>
      </div>
      <div class="info-card">
        <div class="info-title">Payment Details</div>
        <div class="info-name"><?=h2($b['payment_mode'])?></div>
        <div class="info-text">Payment Status: <b><?=h2($status)?></b></div>
        <div class="info-text">Operator: <?=h2($b['operator_name']??'')?></div>
      </div>
    </div>

    <table>
      <thead><tr><th>#</th><th>Category</th><th>Item / Service</th><th>Model</th><th>Serial No.</th><th>Qty</th><th>Rate</th><th>Amount</th></tr></thead>
      <tbody>
      <?php foreach($items as $i=>$it):?>
        <tr>
          <td><?=$i+1?></td><td><?=h2($it['category'])?></td><td class="item"><?=h2($it['item_name'])?></td><td><?=h2($it['model'])?></td><td><?=h2($it['serial_no'])?></td>
          <td class="num"><?=h2($it['qty'])?></td><td class="num"><?=r2($it['rate'])?></td><td class="num"><b><?=r2($it['amount'])?></b></td>
        </tr>
      <?php endforeach;?>
      </tbody>
    </table>

    <div class="bottom">
      <div>
        <?php if(trim($b['remarks']??'')):?>
        <div class="note"><strong>Remarks / Notes</strong><?=nl2br(h2($b['remarks']))?></div>
        <?php else:?>
        <div class="note"><strong>Important</strong>Please check the items, quantity and serial/model details before leaving the counter. Keep this invoice safely for future reference.</div>
        <?php endif;?>
      </div>
      <div class="summary">
        <div class="summary-row"><span>Subtotal</span><b><?=r2($b['subtotal'])?></b></div>
        <div class="summary-row"><span>Discount</span><b>- <?=r2($b['discount'])?></b></div>
        <div class="summary-row"><span>GST (<?=h2($b['gst_rate'])?>%)</span><b><?=r2($b['gst_amount'])?></b></div>
        <div class="summary-row grand"><span>Grand Total</span><b><?=r2($b['grand_total'])?></b></div>
        <div class="summary-row paid-row"><span>Paid Amount</span><b><?=r2($b['paid'])?></b></div>
        <div class="summary-row due-row"><span>Balance Due</span><b><?=r2($b['due'])?></b></div>
      </div>
    </div>

    <div class="footer">
      <div class="thanks">Thank you for choosing <?=h2($shop['shop_name'])?> 🙏</div>
      <div>For any billing or service assistance, please contact us using the number shown above.</div>
      <div style="margin-top:7px;font-weight:800">This is a computer-generated invoice.</div>
    </div>

    <div class="actions"><button class="btn" onclick="window.print()">🖨️ Print Invoice</button> <a class="btn secondary" href="billing.php">← Back to Billing</a></div>
  </main>
</div>
</body></html>
