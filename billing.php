<?php
require __DIR__.'/config.php'; date_default_timezone_set('Asia/Kolkata'); auth(); $pdo=db();
$me=$pdo->prepare("SELECT username,COALESCE(role,'operator') role FROM users WHERE username=? LIMIT 1");$me->execute([$_SESSION['username']??'']);$me=$me->fetch()?:['username'=>'','role'=>'operator'];$admin=$me['role']==='admin'||strtolower($me['username'])==='admin';$op=$me['username'];
$pdo->exec("CREATE TABLE IF NOT EXISTS bills(id INT AUTO_INCREMENT PRIMARY KEY,bill_date DATE NOT NULL,customer_name VARCHAR(200) NOT NULL,mobile VARCHAR(30) DEFAULT '',address TEXT,gstin VARCHAR(30) DEFAULT '',subtotal DECIMAL(12,2) DEFAULT 0,discount DECIMAL(12,2) DEFAULT 0,gst_rate DECIMAL(5,2) DEFAULT 0,gst_amount DECIMAL(12,2) DEFAULT 0,grand_total DECIMAL(12,2) DEFAULT 0,paid DECIMAL(12,2) DEFAULT 0,due DECIMAL(12,2) DEFAULT 0,payment_mode VARCHAR(30) DEFAULT 'Cash',remarks TEXT,operator_name VARCHAR(100) DEFAULT '',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE IF NOT EXISTS bill_items(id INT AUTO_INCREMENT PRIMARY KEY,bill_id INT NOT NULL,category VARCHAR(60) DEFAULT '',item_name VARCHAR(200) NOT NULL,model VARCHAR(150) DEFAULT '',serial_no VARCHAR(150) DEFAULT '',qty DECIMAL(10,2) DEFAULT 1,rate DECIMAL(12,2) DEFAULT 0,amount DECIMAL(12,2) DEFAULT 0,INDEX(bill_id),CONSTRAINT fk_bill_items_bill FOREIGN KEY(bill_id) REFERENCES bills(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$shop=$pdo->query("SELECT * FROM settings WHERE id=1 LIMIT 1")->fetch()?:['shop_name'=>'Mahaveer Computer','mobile'=>'','address'=>'','gstin'=>''];
function h($x){return htmlspecialchars((string)$x,ENT_QUOTES,'UTF-8');}
function rs($x){return '₹'.number_format((float)$x,2);}
$view=$_GET['view']??'list';$id=(int)($_GET['id']??0);$edit=null;$items=[];
if($view==='edit'&&$id){$q=$admin?$pdo->prepare('SELECT * FROM bills WHERE id=?'):$pdo->prepare('SELECT * FROM bills WHERE id=? AND operator_name=?');$admin?$q->execute([$id]):$q->execute([$id,$op]);$edit=$q->fetch();if(!$edit)die('Bill not found.');$q=$pdo->prepare('SELECT * FROM bill_items WHERE bill_id=? ORDER BY id');$q->execute([$id]);$items=$q->fetchAll();}
if($view==='delete'&&$id){if(!$admin)die('Admin only.');$pdo->prepare('DELETE FROM bills WHERE id=?')->execute([$id]);header('Location: billing.php');exit;}
if($view==='list'){$sql='SELECT * FROM bills';$w=[];$a=[];$q=trim($_GET['q']??'');if(!$admin){$w[]='operator_name=?';$a[]=$op;}if($q!==''){$w[]='(customer_name LIKE ? OR mobile LIKE ? OR CAST(id AS CHAR) LIKE ?)';$x="%$q%";array_push($a,$x,$x,$x);}if(!empty($_GET['from'])){$w[]='bill_date>=?';$a[]=$_GET['from'];}if(!empty($_GET['to'])){$w[]='bill_date<=?';$a[]=$_GET['to'];}if(!empty($_GET['mode'])){$w[]='payment_mode=?';$a[]=$_GET['mode'];}if(($_GET['status']??'')==='Due')$w[]='due>0';if(($_GET['status']??'')==='Paid')$w[]='due<=0';if($w)$sql.=' WHERE '.implode(' AND ',$w);$sql.=' ORDER BY id DESC';$q=$pdo->prepare($sql);$q->execute($a);$rows=$q->fetchAll();}
?><!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Billing — <?=h($shop['shop_name'])?></title>
<link rel="stylesheet" href="style.css">
<style>
/* Sidebar matching index.php */
.sidebar,.nav{background:linear-gradient(180deg,#1f3fa8 0%,#4d36b8 52%,#6b3fc4 100%) !important;color:#fff}
.sidebar a,.nav a{color:#fff !important;border-radius:9px;margin:3px 8px;display:block;text-decoration:none;padding:12px;font-weight:800}
.sidebar a:hover,.nav a:hover{background:rgba(255,255,255,.16) !important}
.sidebar a.active,.nav a.active{background:#fff !important;color:#213b9a !important;box-shadow:0 3px 10px rgba(0,0,0,.14)}
/* Billing-specific card styles */
.bill-card{background:#fff;border:1px solid #e7edf5;border-radius:18px;padding:20px;margin-bottom:16px;box-shadow:0 10px 28px rgba(16,43,100,.04)}
.bill-card h2{margin:0 0 18px;color:#1e3a8a}
.bill-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}
.field{display:flex;flex-direction:column;gap:6px}
.field label{font-size:12px;color:#475569;font-weight:800;text-transform:uppercase;letter-spacing:.3px}
.field input,.field select,.field textarea{padding:11px 12px;border:1px solid #d7e0eb;border-radius:10px;background:#fbfdff;font-weight:650;outline:none}
.field input:focus,.field select:focus,.field textarea:focus{border-color:#4f7ff0;box-shadow:0 0 0 3px rgba(37,99,235,.08)}
.items{width:100%;border-collapse:separate;border-spacing:0;margin-top:14px;overflow:hidden;border:1px solid #e2e8f0;border-radius:12px}
.items th{background:#111f43;color:#fff;padding:11px;text-align:left;font-size:12px}
.items td{padding:7px;border-bottom:1px solid #edf1f6;background:#fff}
.items tr:last-child td{border-bottom:0}
.items input,.items select{width:100%;box-sizing:border-box;padding:8px;border:1px solid #d7e0eb;border-radius:7px}
.scroll{overflow:auto}
.summary{margin:18px 0 0 auto;max-width:420px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:13px;overflow:hidden}
.summary div{display:flex;justify-content:space-between;padding:11px 14px;border-bottom:1px solid #e7edf5}
.summary div:last-child{border-bottom:0;background:#eef4ff;font-size:18px}
.bill-table{width:100%;border-collapse:separate;border-spacing:0;overflow:hidden;border:1px solid #e2e8f0;border-radius:12px}
.bill-table th{background:#111f43;color:#fff;padding:12px;text-align:left;font-size:12px;font-weight:800}
.bill-table td{padding:11px;border-bottom:1px solid #edf1f6;font-weight:700}
.bill-table tr:nth-child(even) td{background:#fafcff}
.bill-table tr:hover td{background:#f1f6ff}
.due{color:#dc2626;font-weight:900}
.paid-ok{color:#059669;font-weight:900}
.titlebar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}
.bill-actions{display:flex;gap:9px;flex-wrap:wrap}
@media(max-width:1000px){.bill-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:850px){.side{width:68px}.brand{font-size:0}.brand small,.nav b{display:none}.nav a{text-align:center}.main{margin-left:68px;width:calc(100% - 68px)}}
@media(max-width:600px){.bill-grid{grid-template-columns:1fr}.page{padding:14px}}
</style>
</head><body>
<div class="layout">
<aside class="side">
  <div class="brand"><?=h($shop['shop_name'])?><small>Service &amp; Seva Management</small></div>
  <nav class="nav">
    <a href="index.php?view=dashboard"><span>🏠</span> <b>Dashboard</b></a>
    <a href="index.php?view=customers"><span>👤</span> <b>Customer &amp; Work</b></a>
    <a href="index.php?view=status"><span>📋</span> <b>Application Status</b></a>
    <a href="index.php?view=payments"><span>💳</span> <b>Payments &amp; Message</b></a>
    <a href="index.php?view=services"><span>🛠️</span> <b>Services &amp; Rates</b></a>
    <a href="billing.php" class="active"><span>🧾</span> <b>Billing</b></a>
    <a href="index.php?view=reports"><span>📊</span> <b>Reports</b></a>
    <a href="index.php?view=settings"><span>⚙️</span> <b>Settings</b></a>
    <a href="attendance.php">🕘 <b>Employee Attendance</b></a>
    <a href="logout.php">🚪 <b>Logout</b></a>
  </nav>
</aside>
<main class="main">
<header class="top"><strong><?=h($shop['shop_name'])?></strong><div>👤 <?=h($op)?></div></header>
<section class="page">

<div class="titlebar">
  <div><h1 style="margin:0;color:#213b9a">🧾 CCTV &amp; Computer Billing</h1><p class="muted">Bill management and invoice printing</p></div>
  <div class="bill-actions">
    <a class="btn" href="billing.php">📋 Billing History</a>
    <a class="btn" style="background:linear-gradient(135deg,#059669,#10b981)" href="billing.php?view=new">＋ New Bill</a>
  </div>
</div>

<?php if($view==='list'):?>
<div class="panel">
<form method="get" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px">
  <input name="q" value="<?=h($_GET['q']??'')?>" placeholder="🔎 Customer / Mobile / Bill No." style="flex:1;min-width:180px">
  <input type="date" name="from" value="<?=h($_GET['from']??'')?>">
  <input type="date" name="to" value="<?=h($_GET['to']??'')?>">
  <select name="mode"><option value="">All Modes</option><option value="Cash">Cash</option><option value="Online">Online / UPI</option></select>
  <select name="status"><option value="">All Status</option><option value="Paid">Paid</option><option value="Due">Due</option></select>
  <button class="btn">🔎 Filter</button>
  <a class="btn secondary" href="billing.php">Clear</a>
</form>
<table class="bill-table">
<tr><th>Bill No.</th><th>Date</th><th>Customer</th><th>Total</th><th>Paid</th><th>Due</th><th>Mode</th><th>Action</th></tr>
<?php foreach($rows as $b):?>
<tr>
  <td><b>MC-BILL-<?=str_pad($b['id'],6,'0',STR_PAD_LEFT)?></b></td>
  <td><?=h($b['bill_date'])?></td>
  <td><b><?=h($b['customer_name'])?></b><br><small><?=h($b['mobile'])?></small></td>
  <td><?=rs($b['grand_total'])?></td>
  <td class="paid-ok"><?=rs($b['paid'])?></td>
  <td class="<?=($b['due']>0?'due':'paid-ok')?>"><?=rs($b['due'])?></td>
  <td><?=h($b['payment_mode'])?></td>
  <td>
    <a class="btn" target="_blank" href="invoice.php?id=<?=$b['id']?>">🧾 Print</a>
    <a class="btn secondary" href="?view=edit&id=<?=$b['id']?>">✏️ Edit</a>
    <?php if($admin):?><a class="btn danger" href="?view=delete&id=<?=$b['id']?>" onclick="return confirm('Delete this bill?')">🗑️</a><?php endif;?>
  </td>
</tr>
<?php endforeach;if(!$rows):?><tr><td colspan="8" style="text-align:center;padding:30px;color:#888">No bills found.</td></tr><?php endif;?>
</table>
</div>

<?php else:
$items=$items?:[['category'=>'Computer','item_name'=>'','model'=>'','serial_no'=>'','qty'=>1,'rate'=>0]];
?>
<div class="panel">
<h2><?=($edit?'✏️ Edit Bill':'＋ New Bill')?></h2>
<form method="post" action="billing_action.php">
<input type="hidden" name="action" value="<?=($edit?'update':'create')?>">
<?php if($edit):?><input type="hidden" name="id" value="<?=$edit['id']?>"><?php endif;?>
<div class="bill-grid" style="margin-bottom:16px">
  <div class="field"><label>Date</label><input type="date" name="bill_date" value="<?=h($edit['bill_date']??date('Y-m-d'))?>" required></div>
  <div class="field"><label>Customer Name *</label><input name="customer_name" value="<?=h($edit['customer_name']??'')?>" required></div>
  <div class="field"><label>Mobile</label><input name="mobile" value="<?=h($edit['mobile']??'')?>"></div>
  <div class="field"><label>GSTIN</label><input name="gstin" value="<?=h($edit['gstin']??'')?>"></div>
  <div class="field" style="grid-column:1/-1"><label>Address</label><textarea name="address"><?=h($edit['address']??'')?></textarea></div>
</div>
<div class="scroll">
<table class="items" id="items">
<tr><th>Category</th><th>Item / Service *</th><th>Model</th><th>Serial No.</th><th>Qty</th><th>Rate</th><th>Amount</th><th></th></tr>
<?php foreach($items as $it):?>
<tr class="row">
  <td><select name="category[]"><option>Computer</option><option>Laptop</option><option>CCTV</option><option>Printer</option><option>Accessories</option><option>Service</option><option>Other</option></select></td>
  <td><input name="item_name[]" required value="<?=h($it['item_name']??(string)'')?>"></td>
  <td><input name="model[]" value="<?=h($it['model']??(string)'')?>"></td>
  <td><input name="serial_no[]" value="<?=h($it['serial_no']??(string)'')?>"></td>
  <td><input class="qty" name="qty[]" type="number" step=".01" value="<?=h($it['qty']??1)?>"></td>
  <td><input class="rate" name="rate[]" type="number" step=".01" value="<?=h($it['rate']??0)?>"></td>
  <td><input class="amount" readonly value="0"></td>
  <td><button type="button" class="btn danger remove">×</button></td>
</tr>
<?php endforeach;?>
</table>
</div>
<p><button type="button" class="btn" style="background:linear-gradient(135deg,#059669,#10b981)" id="add">＋ Add Item</button></p>
<div class="bill-grid" style="margin-top:16px">
  <div class="field"><label>Discount ₹</label><input id="discount" name="discount" type="number" step=".01" value="<?=h($edit['discount']??0)?>"></div>
  <div class="field"><label>GST %</label><input id="gst" name="gst_rate" type="number" step=".01" value="<?=h($edit['gst_rate']??0)?>"></div>
  <div class="field"><label>Paid ₹</label><input id="paid" name="paid" type="number" step=".01" value="<?=h($edit['paid']??0)?>"></div>
  <div class="field"><label>Payment Mode</label><select name="payment_mode"><option>Cash</option><option>Online</option></select></div>
  <div class="field" style="grid-column:1/-1"><label>Remarks</label><textarea name="remarks"><?=h($edit['remarks']??'')?></textarea></div>
</div>
<div class="summary">
  <div><span>Subtotal</span><b id="sub">₹0.00</b></div>
  <div><span>Discount</span><b id="dis">₹0.00</b></div>
  <div><span>GST</span><b id="gt">₹0.00</b></div>
  <div><span>Grand Total</span><b id="grand">₹0.00</b></div>
  <div><span>Due</span><b id="due">₹0.00</b></div>
</div>
<p style="margin-top:16px">
  <button class="btn" style="background:linear-gradient(135deg,#059669,#10b981)">💾 Save &amp; Print Invoice</button>
  <a class="btn secondary" href="billing.php">Cancel</a>
</p>
</form>
</div>
<script>
function calc(){let sub=0;document.querySelectorAll('.row').forEach(r=>{let q=+r.querySelector('.qty').value||0,rate=+r.querySelector('.rate').value||0,a=q*rate;r.querySelector('.amount').value=a.toFixed(2);sub+=a});let d=+discount.value||0,g=+gst.value||0,ga=Math.max(0,sub-d)*g/100,grand=Math.max(0,sub-d)+ga,p=+paid.value||0;document.querySelector('#sub').textContent='₹'+sub.toFixed(2);document.querySelector('#dis').textContent='₹'+d.toFixed(2);document.querySelector('#gt').textContent='₹'+ga.toFixed(2);document.querySelector('#grand').textContent='₹'+grand.toFixed(2);document.querySelector('#due').textContent='₹'+Math.max(0,grand-p).toFixed(2)}
function bind(r){r.querySelectorAll('input').forEach(x=>x.addEventListener('input',calc));r.querySelector('.remove').onclick=()=>{if(document.querySelectorAll('.row').length>1){r.remove();calc()}}}
document.querySelectorAll('.row').forEach(bind);
document.querySelectorAll('#discount,#gst,#paid').forEach(x=>x.addEventListener('input',calc));
add.onclick=()=>{let r=document.querySelector('.row').cloneNode(true);r.querySelectorAll('input').forEach(x=>x.value=x.classList.contains('qty')?1:0);r.querySelector('[name="item_name[]"]').value='';document.querySelector('#items').appendChild(r);bind(r);calc()};
calc();
</script>
<?php endif;?>

</section></main></div></body></html>
