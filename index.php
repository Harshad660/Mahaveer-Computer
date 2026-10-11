<?php
require __DIR__.'/config.php';
date_default_timezone_set('Asia/Kolkata');
auth();
$today=date('Y-m-d');
if (!function_exists('ensure_schema')) {
    function ensure_schema(){
        try {
            $pdo=db();
            $c=$pdo->query("SHOW COLUMNS FROM applications LIKE 'sub_service'")->fetch();
            if(!$c) $pdo->exec("ALTER TABLE applications ADD sub_service VARCHAR(50) NOT NULL DEFAULT ''");
            $c=$pdo->query("SHOW COLUMNS FROM applications LIKE 'operator_name'")->fetch();
            if(!$c) $pdo->exec("ALTER TABLE applications ADD operator_name VARCHAR(100) NOT NULL DEFAULT ''");
            $c=$pdo->query("SHOW COLUMNS FROM payments LIKE 'operator_name'")->fetch();
            if(!$c) $pdo->exec("ALTER TABLE payments ADD operator_name VARCHAR(100) NOT NULL DEFAULT ''");
            $pdo->exec("UPDATE applications SET operator_name='admin' WHERE operator_name='' OR operator_name IS NULL");
            $pdo->exec("UPDATE payments SET operator_name='admin' WHERE operator_name='' OR operator_name IS NULL");
        } catch(Throwable $e) {
            // Keep the existing site available; schema errors can be handled by the hosting DB tools.
        }
    }
}
ensure_schema();
$pdo=db();
try {
    $c=$pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    if(!$c) $pdo->exec("ALTER TABLE users ADD role VARCHAR(20) NOT NULL DEFAULT 'operator'");
    $pdo->exec("UPDATE users SET role='admin' WHERE username='admin'");
} catch(Throwable $e) {}
$me=$pdo->prepare("SELECT id,username,COALESCE(role,'operator') role FROM users WHERE username=? LIMIT 1");
$me->execute([$_SESSION['username']??'']);
$currentUser=$me->fetch() ?: ['id'=>0,'username'=>($_SESSION['username']??''),'role'=>'operator'];
$isAdmin=($currentUser['role']==='admin' || strtolower($currentUser['username'])==='admin');
$operatorName=$currentUser['username'];
$ownWhere=$isAdmin?'':' WHERE operator_name='. $pdo->quote($operatorName);
$pdo->exec("UPDATE users SET role='operator' WHERE role IS NULL OR role=''");
$view=$_GET['view']??'dashboard';
$settings=$pdo->query('SELECT * FROM settings WHERE id=1')->fetch() ?: ['shop_name'=>'Mahaveer Computer','mobile'=>'','address'=>'','gstin'=>''];
if($isAdmin){
    $customers=$pdo->query('SELECT * FROM customers ORDER BY id DESC')->fetchAll();
}else{
    $stc=$pdo->prepare("SELECT DISTINCT c.* FROM customers c JOIN applications a ON a.customer_id=c.id WHERE a.operator_name=? ORDER BY c.id DESC");
    $stc->execute([$operatorName]); $customers=$stc->fetchAll();
}
$services=$pdo->query('SELECT * FROM services WHERE active=1 ORDER BY name')->fetchAll();
$operators=$isAdmin ? $pdo->query("SELECT id,username,role FROM users WHERE COALESCE(role,'operator')='operator' ORDER BY username")->fetchAll() : [];
function nav($v,$label,$icon){global $view;echo "<a class='".($view===$v?'active':'')."' href='?view=$v'><span>$icon</span><b>$label</b></a>";}
function wa($mobile,$msg){$n=preg_replace('/\D/','',$mobile??'');if(strlen($n)===10)$n='91'.$n;return 'https://wa.me/'.$n.'?text='.rawurlencode($msg);}
$search=trim($_GET['q']??'');
$filterFrom=trim($_GET['from']??'');
$filterTo=trim($_GET['to']??'');
$filterService=(int)($_GET['service_id']??0);
$filterSubService=trim($_GET['sub_service']??'');
$filterOperator=$isAdmin?trim($_GET['operator']??''): $operatorName;
$filterStatus=trim($_GET['status']??'');
$filterMode=trim($_GET['mode']??'');
$page=max(1,(int)($_GET['page']??1));
$perPage=20;
function pager($total,$page,$perPage){
 $pages=max(1,(int)ceil($total/$perPage));
 if($pages<=1)return '';
 $out='<div class="pager">';
 if($page>1){$q=$_GET;$q['page']=$page-1;$out.='<a href="?'.e(http_build_query($q)).'">‹ Previous</a>';}
 for($i=1;$i<=$pages;$i++){if($i===1||$i===$pages||abs($i-$page)<=2){$q=$_GET;$q['page']=$i;$cls=$i===$page?' class="active"':'';$out.='<a'.$cls.' href="?'.e(http_build_query($q)).'">'.$i.'</a>';}}
 if($page<$pages){$q=$_GET;$q['page']=$page+1;$out.='<a href="?'.e(http_build_query($q)).'">Next ›</a>';}
 $out.='</div>'; return $out;
}

if($view==='customers'){
 $sql="SELECT a.*,c.name customer,c.mobile,c.note customer_note,s.name service,(SELECT GROUP_CONCAT(DISTINCT p.mode ORDER BY p.id DESC SEPARATOR ', ') FROM payments p WHERE p.application_id=a.id) payment_modes FROM applications a JOIN customers c ON c.id=a.customer_id JOIN services s ON s.id=a.service_id";
 $args=[];$conds=[];
 if(!$isAdmin){$conds[]='a.operator_name=?';$args[]=$operatorName;}
 if($search!==''){$conds[]='(c.name LIKE ? OR c.mobile LIKE ? OR s.name LIKE ? OR a.sub_service LIKE ? OR a.operator_name LIKE ?)';$x='%'.$search.'%';$args[]=$x;$args[]=$x;$args[]=$x;$args[]=$x;$args[]=$x;}
 if($filterFrom!==''){$conds[]='a.work_date>=?';$args[]=$filterFrom;}
 if($filterTo!==''){$conds[]='a.work_date<=?';$args[]=$filterTo;}
 if($filterService>0){$conds[]='a.service_id=?';$args[]=$filterService;}
 if($filterSubService!==''){$conds[]='a.sub_service=?';$args[]=$filterSubService;}
 if($isAdmin && $filterOperator!==''){$conds[]='a.operator_name=?';$args[]=$filterOperator;}
 if($filterStatus!==''){$conds[]='a.status=?';$args[]=$filterStatus;}
 if($conds){$sql.=' WHERE '.implode(' AND ',$conds);}
 $sql.=" ORDER BY a.work_date DESC,a.id DESC";$st=$pdo->prepare($sql);$st->execute($args);$allRows=$st->fetchAll();$totalRows=count($allRows);$rows=array_slice($allRows,($page-1)*$perPage,$perPage);
}
if($view==='status'){
 $q="SELECT a.*,c.name customer,c.mobile,c.note customer_note,s.name service,(SELECT p.mode FROM payments p WHERE p.application_id=a.id ORDER BY p.id DESC LIMIT 1) last_mode FROM applications a JOIN customers c ON c.id=a.customer_id JOIN services s ON s.id=a.service_id";
 $args=[];$conds=[];
 if(!$isAdmin){$conds[]='a.operator_name=?';$args[]=$operatorName;}
 if($search!==''){$conds[]='(c.name LIKE ? OR c.mobile LIKE ? OR s.name LIKE ? OR a.sub_service LIKE ? OR a.operator_name LIKE ?)';$x='%'.$search.'%';$args[]=$x;$args[]=$x;$args[]=$x;$args[]=$x;$args[]=$x;}
 if($filterFrom!==''){$conds[]='a.work_date>=?';$args[]=$filterFrom;}
 if($filterTo!==''){$conds[]='a.work_date<=?';$args[]=$filterTo;}
 if($filterService>0){$conds[]='a.service_id=?';$args[]=$filterService;}
 if($filterSubService!==''){$conds[]='a.sub_service=?';$args[]=$filterSubService;}
 if($isAdmin && $filterOperator!==''){$conds[]='a.operator_name=?';$args[]=$filterOperator;}
 if($filterStatus!==''){$conds[]='a.status=?';$args[]=$filterStatus;}
 if($conds)$q.=' WHERE '.implode(' AND ',$conds);
 $q.=" ORDER BY CASE a.status WHEN 'Pending' THEN 1 WHEN 'Processing' THEN 2 WHEN 'Ready' THEN 3 WHEN 'Delivered' THEN 4 ELSE 5 END, a.work_date DESC,a.id DESC";$st=$pdo->prepare($q);$st->execute($args);$allRows=$st->fetchAll();$totalRows=count($allRows);$rows=array_slice($allRows,($page-1)*$perPage,$perPage);
}
if($view==='payments'){
 $q="SELECT p.*,c.name customer,c.mobile,a.total application_total,a.paid application_paid,s.name service FROM payments p JOIN customers c ON c.id=p.customer_id LEFT JOIN applications a ON a.id=p.application_id LEFT JOIN services s ON s.id=a.service_id";
 $args=[];$conds=[];
 if(!$isAdmin){$conds[]='p.operator_name=?';$args[]=$operatorName;}
 if($filterFrom!==''){$conds[]='p.payment_date>=?';$args[]=$filterFrom;}
 if($filterTo!==''){$conds[]='p.payment_date<=?';$args[]=$filterTo;}
 if($filterService>0){$conds[]='a.service_id=?';$args[]=$filterService;}
 if($filterSubService!==''){$conds[]='a.sub_service=?';$args[]=$filterSubService;}
 if($isAdmin && $filterOperator!==''){$conds[]='p.operator_name=?';$args[]=$filterOperator;}
 if($filterMode!==''){$conds[]='p.mode=?';$args[]=$filterMode;}
 if($search!==''){$conds[]='(c.name LIKE ? OR c.mobile LIKE ? OR s.name LIKE ? OR a.sub_service LIKE ? OR p.operator_name LIKE ?)';$x='%'.$search.'%';$args[]=$x;$args[]=$x;$args[]=$x;$args[]=$x;$args[]=$x;}
 if($conds)$q.=' WHERE '.implode(' AND ',$conds);
 $q.=' ORDER BY p.payment_date DESC,p.id DESC';$st=$pdo->prepare($q);$st->execute($args);$allRows=$st->fetchAll();$totalRows=count($allRows);$rows=array_slice($allRows,($page-1)*$perPage,$perPage);
}
if($isAdmin){
 $stats=$pdo->query("SELECT (SELECT COUNT(DISTINCT customer_id) FROM applications WHERE work_date='".$today."') customers,(SELECT COUNT(*) FROM applications WHERE status<>'Delivered') open_apps,(SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date='".$today."') today_paid,(SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date='".$today."' AND mode='Cash') today_cash,(SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_date='".$today."' AND mode='Online') today_online,(SELECT COALESCE(SUM(GREATEST(total-paid,0)),0) FROM applications) due")->fetch();
 // Show every operator, including Admin and operators with zero collection today.
 $opDaily=$pdo->query("SELECT u.username operator_name, COALESCE(SUM(CASE WHEN p.payment_date='".$today."' THEN p.amount ELSE 0 END),0) total
   FROM users u LEFT JOIN payments p ON p.operator_name=u.username
   WHERE u.role='admin' OR u.role='operator'
   GROUP BY u.id,u.username ORDER BY total DESC,u.username")->fetchAll();
}else{
 $st=$pdo->prepare("SELECT COUNT(DISTINCT customer_id) customers,COUNT(*) open_apps,COALESCE((SELECT SUM(amount) FROM payments WHERE payment_date='".$today."' AND operator_name=?),0) today_paid,COALESCE((SELECT SUM(amount) FROM payments WHERE payment_date='".$today."' AND operator_name=? AND mode='Cash'),0) today_cash,COALESCE((SELECT SUM(amount) FROM payments WHERE payment_date='".$today."' AND operator_name=? AND mode='Online'),0) today_online,COALESCE(SUM(GREATEST(total-paid,0)),0) due FROM applications WHERE operator_name=? AND work_date='".$today."'");
 $st->execute([$operatorName,$operatorName,$operatorName,$operatorName]);$stats=$st->fetch();
 $opDaily=[['operator_name'=>$operatorName,'total'=>$stats['today_paid']]];
}
?><!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($settings['shop_name'])?></title><link rel="stylesheet" href="style.css"><style>
/* Mahaveer Computer - Colour + Bold Theme */
:root{
  --mc-blue:#3155c8;
  --mc-blue-dark:#213b9a;
  --mc-purple:#6d3fd1;
  --mc-cyan:#0ea5b7;
  --mc-green:#169b62;
  --mc-orange:#f08a24;
  --mc-red:#d94a4a;
  --mc-yellow:#d99a00;
  --mc-bg:#f3f6fb;
  --mc-card:#ffffff;
  --mc-text:#172033;
  --mc-border:#dbe2ef;
}
*{box-sizing:border-box}
body{
  font-family:Arial,"Segoe UI",sans-serif;
  font-weight:700;
  color:var(--mc-text);
  background:linear-gradient(135deg,#f5f7ff 0%,#eef7ff 48%,#f7f2ff 100%);
}
body h1,body h2,body h3,body h4,body h5,body h6,.brand,.nav a,.top,strong,th,.metric,label,button,.btn,.paidtag{
  font-weight:800;
}
input,select,textarea{font-weight:700;border:1px solid var(--mc-border);border-radius:8px}
input:focus,select:focus,textarea:focus{
  outline:2px solid rgba(49,85,200,.18);
  border-color:var(--mc-blue);
}
.muted,small{font-weight:600}

/* Sidebar / navigation */
.sidebar,.nav{
  background:linear-gradient(180deg,#1f3fa8 0%,#4d36b8 52%,#6b3fc4 100%) !important;
  color:#fff;
}
.sidebar a,.nav a{
  color:#fff !important;
  border-radius:9px;
  margin:3px 8px;
}
.sidebar a:hover,.nav a:hover{
  background:rgba(255,255,255,.16) !important;
}
.sidebar a.active,.nav a.active{
  background:#fff !important;
  color:var(--mc-blue-dark) !important;
  box-shadow:0 3px 10px rgba(0,0,0,.14);
}

/* Main panels/cards */
.panel,.card,.tablewrap,.box{
  background:var(--mc-card);
  border:1px solid var(--mc-border);
  border-radius:12px;
  box-shadow:0 5px 18px rgba(31,63,168,.08);
}
.metric{
  background:linear-gradient(145deg,#fff,#f7f9ff);
  border:1px solid var(--mc-border);
  border-radius:12px;
  box-shadow:0 4px 14px rgba(31,63,168,.08);
}
.metric:nth-of-type(1){border-top:4px solid var(--mc-blue)}
.metric:nth-of-type(2){border-top:4px solid var(--mc-purple)}
.metric:nth-of-type(3){border-top:4px solid var(--mc-orange)}
.metric:nth-of-type(4){border-top:4px solid var(--mc-green)}
.metric:nth-of-type(5){border-top:4px solid var(--mc-cyan)}
.metric:nth-of-type(6){border-top:4px solid var(--mc-red)}

h1{color:var(--mc-blue-dark)}
h2,h3{color:#263a72}

/* Buttons */
button,.btn{
  background:linear-gradient(135deg,var(--mc-blue),var(--mc-purple)) !important;
  color:#fff !important;
  border:0 !important;
  border-radius:8px;
  font-weight:800 !important;
  box-shadow:0 3px 8px rgba(49,85,200,.22);
}
button:hover,.btn:hover{filter:brightness(1.08)}
.btn.secondary{background:#64748b !important}
.btn.danger{background:linear-gradient(135deg,#d13d3d,#a91f3f) !important}

/* Tables */
table{border-collapse:separate;border-spacing:0;overflow:hidden}
th{
  background:linear-gradient(135deg,#3155c8,#6d3fd1) !important;
  color:#fff !important;
}
td{font-weight:700}
tr:nth-child(even) td{background:#f7f9ff}
tr:hover td{background:#eef3ff}

/* Status colours */
.status-Pending,.pending{
  background:#ffd9d9 !important;color:#b42323 !important;font-weight:900 !important;
}
.status-Processing,.processing{
  background:#d9e9ff !important;color:#1557a6 !important;font-weight:900 !important;
}
.status-Ready,.ready{
  background:#fff0b8 !important;color:#8a6200 !important;font-weight:900 !important;
}
.status-Delivered,.delivered{
  background:#d8f5df !important;color:#087a3d !important;font-weight:900 !important;
}
.paidtag{
  background:#d8f5df !important;color:#087a3d !important;
  border-radius:999px;padding:4px 9px;font-weight:900;
}

/* Links */
.pager{display:flex;gap:6px;justify-content:center;align-items:center;flex-wrap:wrap;margin-top:16px;padding:10px}.pager a{display:inline-block;padding:7px 11px;border-radius:7px;background:#eef1ff;color:#263a8f;text-decoration:none;font-weight:800;border:1px solid #cbd3f5}.pager a.active{background:#3155c8;color:#fff;border-color:#3155c8}.pager a:hover{background:#6d3fd1;color:#fff}
a{font-weight:800}
a:not(.btn):hover{color:var(--mc-blue)}
</style></head><body><div class="layout"><aside class="side"><div class="brand"><?=e($settings['shop_name'])?><small>Service & Seva Management</small></div><nav class="nav"><?php nav('dashboard','Dashboard','🏠');nav('customers','Customer & Work','👤');nav('status','Application Status','📋');nav('payments','Payments & Message','💳');nav('services','Services & Rates','🛠️');?><a href='billing.php' class='billing-nav'><span>🧾</span><b>Billing</b></a><?php nav('reports','Reports','📊');nav('settings','Settings','⚙️');?><a href='attendance.php' class='attendance-link'>🕘 <b>Employee Attendance</b></a><a href="logout.php">🚪 <b>Logout</b></a></nav></aside><main class="main"><header class="top"><strong><?=e($settings['shop_name'])?></strong><div>👤 <?=e($_SESSION['username'])?></div></header><section class="page">
<?php if($view==='dashboard'): ?><div class="title"><div><h1>Dashboard</h1><p class="muted">आज का काम और operator-wise collection</p></div><a class="btn" href="?view=newwork">＋ New Work</a></div><div class="cards"><?php foreach([['👥',"Today's Customers",$stats['customers']],['📋','Open Applications',$stats['open_apps']],['💰',"Today's Collection",money($stats['today_paid'])],['💵','Cash Collection',money($stats['today_cash'])],['💳','Online Collection',money($stats['today_online'])],['⏳','Pending Due',money($stats['due'])]] as $x):?><div class="card"><div><?=$x[0]?></div><div class="muted"><?=$x[1]?></div><div class="metric"><?=$x[2]?></div></div><?php endforeach;?></div><div class="grid"><div class="panel"><h3>Operator-wise Today's Payment</h3><table><tr><th>Operator</th><th>Today's Collection</th></tr><?php foreach($opDaily as $o):?><tr><td><b><?=e($o['operator_name'])?></b></td><td><b><?=money($o['total'])?></b></td></tr><?php endforeach;if(!$opDaily):?><tr><td colspan="2">आज कोई payment नहीं है.</td></tr><?php endif;?></table></div><div class="panel"><h3>Quick Actions</h3><p><a class="btn secondary" href="?view=newwork">👤 New Customer + Work</a></p><p><a class="btn secondary" href="?view=status">📋 Application Status</a></p><p><a class="btn secondary" href="?view=payments">💳 Payments & Message</a></p><p><a class="btn secondary" href="billing.php">🧾 CCTV & Computer Billing</a></p></div></div>
<?php elseif($view==='customers'): ?><div class="title"><div><h1>Customer & Daily Work</h1><p class="muted">Customer और service एक ही जगह manage करें</p></div><a class="btn" href="?view=newwork">＋ New Work</a></div><div class="panel searchbox"><form method="get" class="form"><input type="hidden" name="view" value="customers"><div class="field"><label>Customer Search</label><input name="q" value="<?=e($search)?>" placeholder="🔎 Name / mobile / service / sub service / operator"></div><div class="field"><label>From Date</label><input type="date" name="from" value="<?=e($filterFrom)?>"></div><div class="field"><label>To Date</label><input type="date" name="to" value="<?=e($filterTo)?>"></div><div class="field"><label>Service</label><select name="service_id"><option value="0">All Services</option><?php foreach($services as $sv):?><option value="<?=$sv['id']?>" <?=$filterService===(int)$sv['id']?'selected':''?>><?=e($sv['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Sub Service</label><select name="sub_service"><option value="">All Sub Services</option><option value="Tahsil work" <?=$filterSubService==='Tahsil work'?'selected':''?>>Tahsil work</option><option value="General work" <?=$filterSubService==='General work'?'selected':''?>>General work</option></select></div><?php if($isAdmin):?><div class="field"><label>Operator</label><select name="operator"><option value="">All Operators</option><?php foreach($operators as $op):?><option value="<?=e($op['username'])?>" <?=$filterOperator===$op['username']?'selected':''?>><?=e($op['username'])?></option><?php endforeach;?></select></div><?php endif;?><div class="field"><label>Status</label><select name="status"><option value="">All Status</option><?php foreach(['Pending','Processing','Ready','Delivered'] as $st):?><option value="<?=$st?>" <?=$filterStatus===$st?'selected':''?>><?=$st?></option><?php endforeach;?></select></div><div class="actions"><button>🔎 Filter</button><a class="btn secondary" href="?view=customers">Clear</a></div></form></div><div class="panel"><table><tr><th>Customer Name</th><th>Service Name</th><th>Sub Service</th><th>Date</th><th>Total</th><th>Paid</th><th>Due</th><th>Operator</th><?php if($isAdmin):?><th>Assign Operator</th><?php endif;?><th>Edit</th><th>Delete</th></tr><?php foreach($rows as $r):$due=max(0,(float)$r['total']-(float)$r['paid']);?><tr><td><b><?=e($r['customer'])?></b><br><small><?=e($r['mobile'])?></small><?php if(trim($r['remark']??'')!==''):?><br><small style="color:#6d3fd1;font-weight:800">📝 <?=e($r['remark'])?></small><?php endif;?></td><td><?=e($r['service'])?></td><td><?=e($r['sub_service']??'')?></td><td><?=e($r['work_date'])?></td><td><?=money($r['total'])?><?php if(trim($r['payment_modes']??'')!==''):?><br><small style="font-weight:800;color:#3155c8">💳 <?=e($r['payment_modes'])?></small><?php endif;?></td><td><?=money($r['paid'])?></td><td><b><?=money($due)?></b></td><td><?=e($r['operator_name'])?></td><?php if($isAdmin):?><td><form method="post" action="action.php" style="display:flex;gap:5px;align-items:center"><input type="hidden" name="action" value="assign_operator"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="return_to" value="<?=e($_SERVER['REQUEST_URI'])?>"><select name="operator_name" style="min-width:120px"><?php foreach($operators as $op):?><option value="<?=e($op['username'])?>" <?=$r['operator_name']===$op['username']?'selected':''?>><?=e($op['username'])?></option><?php endforeach;?></select><button class="btn secondary">Assign</button></form></td><?php endif;?><td><a class="btn secondary" href="?view=editwork&id=<?=$r['id']?>">✏️ Edit</a></td><td><form method="post" action="action.php" onsubmit="return confirm('Delete this work and its payments?');"><input type="hidden" name="action" value="application_delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="btn danger">🗑️ Delete</button></form></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="11">No records found.</td></tr><?php endif;?></table><?=pager($totalRows,$page,$perPage)?></div>
<?php elseif($view==='status'): ?><div class="title"><div><h1>Application Status</h1><p class="muted">Application progress और due payment</p></div><a class="btn" href="?view=newwork">＋ New Work</a></div><div class="panel searchbox"><form method="get" class="form"><input type="hidden" name="view" value="status"><div class="field"><label>Customer Search</label><input name="q" value="<?=e($search)?>" placeholder="🔎 Customer name / mobile / service / operator"></div><div class="field"><label>From Date</label><input type="date" name="from" value="<?=e($filterFrom)?>"></div><div class="field"><label>To Date</label><input type="date" name="to" value="<?=e($filterTo)?>"></div><div class="field"><label>Service</label><select name="service_id"><option value="0">All Services</option><?php foreach($services as $sv):?><option value="<?=$sv['id']?>" <?=$filterService===(int)$sv['id']?'selected':''?>><?=e($sv['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Sub Service</label><select name="sub_service"><option value="">All Sub Services</option><option value="Tahsil work" <?=$filterSubService==='Tahsil work'?'selected':''?>>Tahsil work</option><option value="General work" <?=$filterSubService==='General work'?'selected':''?>>General work</option></select></div><?php if($isAdmin):?><div class="field"><label>Operator</label><select name="operator"><option value="">All Operators</option><?php foreach($operators as $op):?><option value="<?=e($op['username'])?>" <?=$filterOperator===$op['username']?'selected':''?>><?=e($op['username'])?></option><?php endforeach;?></select></div><?php endif;?><div class="field"><label>Status</label><select name="status"><option value="">All Status</option><?php foreach(['Pending','Processing','Ready','Delivered'] as $st):?><option value="<?=$st?>" <?=$filterStatus===$st?'selected':''?>><?=$st?></option><?php endforeach;?></select></div><div class="actions"><button>🔎 Filter</button><a class="btn secondary" href="?view=status">Clear</a></div></form></div><div class="panel"><div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;font-size:13px;font-weight:700"><span style="background:#ffd6d6;color:#a40000;padding:6px 10px;border-radius:7px">🔴 Pending</span><span style="background:#cfe8ff;color:#075a9c;padding:6px 10px;border-radius:7px">🔵 Processing</span><span style="background:#fff0a6;color:#7a5a00;padding:6px 10px;border-radius:7px">🟡 Ready</span><span style="background:#ccefd8;color:#126b2e;padding:6px 10px;border-radius:7px">🟢 Delivered</span></div><table><tr><th>Application Date</th><th>Customer</th><th>Service</th><th>Sub Service</th><th>Due Amount</th><th>Status</th><?php if($isAdmin):?><th>Assign Operator</th><?php endif;?><th>Payment Mode</th><th>Action</th></tr><?php foreach($rows as $r):$due=max(0,(float)$r['total']-(float)$r['paid']);$paid=(float)$r['paid'];$total=(float)$r['total'];$svc=($r['service']??'Service');$waMsg="Namaste ".($r['customer']??'')." ji,\n\nAapka ".$svc." Ready ho Chuka hai, Krupaya Mahaveer Computer se Apka ".$svc." Collect Kare,\n\nThank you,\nMahaveer Computer\nNear RDIK college New town Badnera,\n7588328854";$waLink=wa($r['mobile']??'', $waMsg);?><tr><td><?=e($r['work_date'])?></td><td><?=e($r['customer'])?><br><small><?=e($r['mobile'])?></small><?php if(trim($r['remark']??'')!==''):?><br><small style="color:#6d3fd1;font-weight:800">📝 <?=e($r['remark'])?></small><?php endif;?></td><td><?=e($r['service'])?></td><td><?=e($r['sub_service']??'')?></td><td><b><?=money($due)?></b></td><td><form method="post" action="action.php"><input type="hidden" name="action" value="status"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="return_to" value="<?=e($_SERVER['REQUEST_URI'])?>"><select name="status" onchange="this.form.submit()" style="font-weight:700;min-width:125px;padding:7px 10px;border-radius:7px;border:1px solid #bbb;background:<?= $r['status']==='Pending'?'#ffd6d6':($r['status']==='Processing'?'#cfe8ff':($r['status']==='Ready'?'#fff0a6':'#ccefd8')) ?>;color:<?= $r['status']==='Pending'?'#a40000':($r['status']==='Processing'?'#075a9c':($r['status']==='Ready'?'#7a5a00':'#126b2e')) ?>;"><?php foreach(['Pending','Processing','Ready','Delivered'] as $s):?><option <?=$r['status']===$s?'selected':''?>><?=$s?></option><?php endforeach;?></select></form></td><?php if($isAdmin):?><td><form method="post" action="action.php" style="display:flex;gap:5px;align-items:center"><input type="hidden" name="action" value="assign_operator"><input type="hidden" name="id" value="<?=$r['id']?>"><input type="hidden" name="return_to" value="<?=e($_SERVER['REQUEST_URI'])?>"><select name="operator_name" style="min-width:120px"><?php foreach($operators as $op):?><option value="<?=e($op['username'])?>" <?=$r['operator_name']===$op['username']?'selected':''?>><?=e($op['username'])?></option><?php endforeach;?></select><button class="btn secondary">Assign</button></form></td><?php endif;?><td><?php if($due>0): ?><select name="mode" form="pay<?=$r['id']?>"><option value="Cash">Cash</option><option value="Online">Online</option></select><?php else: ?><span class="paidtag">✓ Paid<?=!empty($r['last_mode'])?' · '.e($r['last_mode']):''?></span><?php endif; ?></td><td><?php if($due>0): ?><form method="post" action="action.php" class="inlinepay" id="pay<?=$r['id']?>"><input type="hidden" name="action" value="payment"><input type="hidden" name="customer_id" value="<?=$r['customer_id']?>"><input type="hidden" name="application_id" value="<?=$r['id']?>"><input type="hidden" name="payment_date" value="<?=$today?>"><input type="hidden" name="return_to" value="<?=e($_SERVER['REQUEST_URI'])?>"><input name="amount" type="number" step="0.01" min="0.01" max="<?=e($due)?>" placeholder="Pay amount" required><button class="btn">💰 Pay</button></form><?php elseif($r['status']==='Ready' || $r['status']==='Delivered'): ?><a class="btn wa" target="_blank" rel="noopener" href="<?=e($waLink)?>">📲 WhatsApp</a><?php else: ?>—<?php endif; ?></td></tr><?php endforeach;?></table><?=pager($totalRows,$page,$perPage)?></div>
<?php elseif($view==='payments'): ?><div class="title"><div><h1>Payments & Message</h1><p class="muted">Receipt और WhatsApp message</p></div><a class="btn" href="?view=newpayment">＋ New Payment</a></div><div class="panel searchbox"><form method="get" class="form"><input type="hidden" name="view" value="payments"><div class="field"><label>Customer Search</label><input name="q" value="<?=e($search)?>" placeholder="🔎 Name / mobile / service / sub service / operator"></div><div class="field"><label>From Date</label><input type="date" name="from" value="<?=e($filterFrom)?>"></div><div class="field"><label>To Date</label><input type="date" name="to" value="<?=e($filterTo)?>"></div><div class="field"><label>Service</label><select name="service_id"><option value="0">All Services</option><?php foreach($services as $sv):?><option value="<?=$sv['id']?>" <?=$filterService===(int)$sv['id']?'selected':''?>><?=e($sv['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Sub Service</label><select name="sub_service"><option value="">All Sub Services</option><option value="Tahsil work" <?=$filterSubService==='Tahsil work'?'selected':''?>>Tahsil work</option><option value="General work" <?=$filterSubService==='General work'?'selected':''?>>General work</option></select></div><?php if($isAdmin):?><div class="field"><label>Operator</label><select name="operator"><option value="">All Operators</option><?php foreach($operators as $op):?><option value="<?=e($op['username'])?>" <?=$filterOperator===$op['username']?'selected':''?>><?=e($op['username'])?></option><?php endforeach;?></select></div><?php endif;?><div class="field"><label>Payment Mode</label><select name="mode"><option value="">All Modes</option><option value="Cash" <?=$filterMode==='Cash'?'selected':''?>>Cash</option><option value="Online" <?=$filterMode==='Online'?'selected':''?>>Online</option></select></div><div class="actions"><button>🔎 Filter</button><a class="btn secondary" href="?view=payments">Clear</a></div></form></div><div class="panel"><table><tr><th>Date</th><th>Customer</th><th>Amount</th><th>Receipt Print</th><th>WhatsApp Message</th><th>Edit/Delete</th></tr><?php foreach($rows as $p):$msg="Namaste ".($p['customer']??'').", aapki payment ₹".number_format($p['amount'],2)." (Receipt MC-".str_pad($p['id'],6,'0',STR_PAD_LEFT).") date ".date('d-m-Y',strtotime($p['payment_date']))." ko receive hui. Mahaveer Computer";?><tr><td><?=e($p['payment_date'])?></td><td><b><?=e($p['customer'])?></b><br><small><?=e($p['service']??'')?></small></td><td><?=money($p['amount'])?></td><td><a class="btn secondary" target="_blank" href="receipt.php?id=<?=$p['id']?>">🧾 Print Receipt</a></td><td><a class="btn wa" target="_blank" href="<?=e(wa($p['mobile'],$msg))?>">📲 WhatsApp</a></td><td><a class="btn secondary" href="?view=editpayment&id=<?=$p['id']?>">✏️ Edit</a> <form method="post" action="action.php" style="display:inline" onsubmit="return confirm('Delete this payment?');"><input type="hidden" name="action" value="payment_delete"><input type="hidden" name="id" value="<?=$p['id']?>"><button class="btn danger">🗑️ Delete</button></form></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="6">No payments found.</td></tr><?php endif;?></table><?=pager($totalRows,$page,$perPage)?></div>
<?php elseif($view==='services'): ?><div class="title"><div><h1>Services & Rates</h1><p class="muted">Service list</p></div><a class="btn" href="?view=newservice">＋ Add Service</a></div><div class="panel"><table><tr><th>Service</th><th>Rate</th><th>Actions</th></tr><?php foreach($services as $s):?><tr><td><?=e($s['name'])?></td><td><b><?=money($s['rate'])?></b></td><td><a class="btn secondary" href="?view=editservice&id=<?=$s['id']?>">✏️ Edit</a> <form method="post" action="action.php" style="display:inline" onsubmit="return confirm('Delete this service? Existing applications will be kept.');"><input type="hidden" name="action" value="service_delete"><input type="hidden" name="id" value="<?=$s['id']?>"><button class="btn danger">🗑️ Delete</button></form></td></tr><?php endforeach;?></table></div>
<?php elseif($view==='reports'):
$type=$_GET['type']??'daily';$from=$_GET['from']??date('Y-m-d');$to=$_GET['to']??date('Y-m-d');$serviceFilter=(int)($_GET['service_id']??0);$subServiceFilter=trim($_GET['sub_service']??'');$operatorFilter=$isAdmin?trim($_GET['operator']??''):$operatorName;$reportMode=trim($_GET['mode']??'');
if($type==='daily'){$from=$to=date('Y-m-d');}elseif($type==='monthly'){$from=date('Y-m-01');$to=date('Y-m-d');}else{if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from))$from=date('Y-m-01');if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))$to=date('Y-m-d');if($from>$to){$tmp=$from;$from=$to;$to=$tmp;}}
$args=[$from,$to];$where=" WHERE p.payment_date BETWEEN ? AND ?";if($serviceFilter){$where.=' AND a.service_id=?';$args[]=$serviceFilter;}if($subServiceFilter!==''){$where.=' AND a.sub_service=?';$args[]=$subServiceFilter;}if($operatorFilter){$where.=' AND p.operator_name=?';$args[]=$operatorFilter;}if($reportMode!==''){$where.=' AND p.mode=?';$args[]=$reportMode;}
$st=$pdo->prepare("SELECT COALESCE(SUM(p.amount),0) total,COALESCE(SUM(CASE WHEN p.mode='Cash' THEN p.amount ELSE 0 END),0) cash_total,COALESCE(SUM(CASE WHEN p.mode='Online' THEN p.amount ELSE 0 END),0) online_total FROM payments p LEFT JOIN applications a ON a.id=p.application_id".$where);$st->execute($args);$totals=$st->fetch();$received=$totals['total'];$cashTotal=$totals['cash_total'];$onlineTotal=$totals['online_total'];
if($type==='service'){$g=$pdo->prepare("SELECT COALESCE(s.name,'Other') label,COALESCE(SUM(p.amount),0) total,COUNT(p.id) entries FROM payments p LEFT JOIN applications a ON a.id=p.application_id LEFT JOIN services s ON s.id=a.service_id".$where." GROUP BY s.id,s.name ORDER BY total DESC");$g->execute($args);$grouped=$g->fetchAll();$groupTitle='Service-wise Collection';$gcols=['Service','Collection','Entries'];}
elseif($type==='operator'){$g=$pdo->prepare("SELECT COALESCE(p.operator_name,'') label,COALESCE(SUM(p.amount),0) total,COUNT(p.id) entries FROM payments p LEFT JOIN applications a ON a.id=p.application_id".$where." GROUP BY p.operator_name ORDER BY total DESC");$g->execute($args);$grouped=$g->fetchAll();$groupTitle='Operator-wise Collection';$gcols=['Operator','Collection','Entries'];}
else{$g=$pdo->prepare("SELECT p.payment_date label,COALESCE(SUM(p.amount),0) total,COUNT(p.id) entries FROM payments p LEFT JOIN applications a ON a.id=p.application_id".$where." GROUP BY p.payment_date ORDER BY p.payment_date DESC");$g->execute($args);$grouped=$g->fetchAll();$groupTitle=$type==='monthly'?'Monthly / Date-wise Collection':'Daily Collection';$gcols=['Date','Collection','Entries'];}
?><div class="title"><div><h1>Reports</h1><p class="muted">Daily / Monthly / Custom Date / Service-wise / Operator-wise</p></div><button class="btn secondary" onclick="window.print()">🖨️ Print</button></div><div class="panel"><form method="get" class="form"><input type="hidden" name="view" value="reports"><div class="field"><label>Report Type</label><select name="type"><option value="daily" <?=$type==='daily'?'selected':''?>>Daily</option><option value="monthly" <?=$type==='monthly'?'selected':''?>>Monthly</option><option value="custom" <?=$type==='custom'?'selected':''?>>Custom Date</option><option value="service" <?=$type==='service'?'selected':''?>>Service-wise</option><option value="operator" <?=$type==='operator'?'selected':''?>>Operator-wise</option></select></div><div class="field"><label>From Date</label><input type="date" name="from" value="<?=e($from)?>"></div><div class="field"><label>To Date</label><input type="date" name="to" value="<?=e($to)?>"></div><div class="field"><label>Service</label><select name="service_id"><option value="0">All Services</option><?php foreach($services as $sv):?><option value="<?=$sv['id']?>" <?=$serviceFilter===$sv['id']?'selected':''?>><?=e($sv['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Sub Service</label><select name="sub_service"><option value="">All Sub Services</option><option value="Tahsil work" <?=$subServiceFilter==='Tahsil work'?'selected':''?>>Tahsil work</option><option value="General work" <?=$subServiceFilter==='General work'?'selected':''?>>General work</option></select></div><?php if($isAdmin): ?><div class="field"><label>Operator</label><select name="operator"><option value="">All Operators</option><?php foreach($operators as $op):?><option value="<?=e($op['username'])?>" <?=$operatorFilter===$op['username']?'selected':''?>><?=e($op['username'])?></option><?php endforeach;?></select></div><?php else: ?><input type="hidden" name="operator" value="<?=e($operatorName)?>"><?php endif; ?><div class="field"><label>Payment Mode</label><select name="mode"><option value="">Cash + Online</option><option value="Cash" <?=$reportMode==='Cash'?'selected':''?>>Cash</option><option value="Online" <?=$reportMode==='Online'?'selected':''?>>Online</option></select></div><div class="actions"><button>🔎 Show Report</button></div></form></div><div class="cards"><div class="card"><div class="muted">Total Received</div><div class="metric"><?=money($received)?></div></div><div class="card"><div class="muted">Cash</div><div class="metric"><?=money($cashTotal)?></div></div><div class="card"><div class="muted">Online</div><div class="metric"><?=money($onlineTotal)?></div></div><div class="card"><div class="muted">Report Period</div><div class="metric" style="font-size:18px"><?=e(date('d-m-Y',strtotime($from)))?> → <?=e(date('d-m-Y',strtotime($to)))?></div></div></div><div class="panel"><h3><?=e($groupTitle)?></h3><table><tr><th><?=e($gcols[0])?></th><th><?=e($gcols[1])?></th><th><?=e($gcols[2])?></th></tr><?php foreach($grouped as $gr):?><tr><td><?=e($gr['label'])?></td><td><b><?=money($gr['total'])?></b></td><td><?=e($gr['entries'])?></td></tr><?php endforeach;if(!$grouped):?><tr><td colspan="3">No payment found.</td></tr><?php endif;?></table></div>
<?php elseif($view==='settings'): ?><div class="title"><h1>Settings</h1></div><div class="panel"><form class="form" method="post" action="action.php"><input type="hidden" name="action" value="settings"><div class="field"><label>Shop Name</label><input name="shop_name" value="<?=e($settings['shop_name'])?>"></div><div class="field"><label>Mobile</label><input name="mobile" value="<?=e($settings['mobile'])?>"></div><div class="field full"><label>Address</label><textarea name="address"><?=e($settings['address'])?></textarea></div><div class="field"><label>GSTIN</label><input name="gstin" value="<?=e($settings['gstin'])?>"></div><div class="actions full"><button>Save Settings</button></div></form></div><?php if($isAdmin): ?><div class="panel"><h3>Operators</h3><form method="post" action="action.php" class="form"><input type="hidden" name="action" value="operator"><div class="field"><label>Operator Username</label><input name="username" required></div><div class="field"><label>Password</label><input name="password" type="password" required></div><div class="actions"><button>＋ Add Operator</button></div></form><table><tr><th>Operator</th><th>Action</th></tr><?php foreach($operators as $o):?><tr><td><?=e($o['username'])?></td><td><?php if($o['username']!==$_SESSION['username']):?><form method="post" action="action.php" onsubmit="return confirm('Delete this operator?');"><input type="hidden" name="action" value="operator_delete"><input type="hidden" name="id" value="<?=$o['id']?>"><button class="btn danger">Delete</button></form><?php else:?>Current Login<?php endif;?></td></tr><?php endforeach;?></table></div><?php endif; ?>
<?php elseif($view==='newwork' || $view==='editwork'):
$id=(int)($_GET['id']??0);$edit=null;$editPaymentMode='Cash';if($view==='editwork'){
 $st=$isAdmin?$pdo->prepare('SELECT * FROM applications WHERE id=?'):$pdo->prepare('SELECT * FROM applications WHERE id=? AND operator_name=?');
 $isAdmin?$st->execute([$id]):$st->execute([$id,$operatorName]);$edit=$st->fetch();if(!$edit)die('<div class="panel">Work not found.</div>');
 $epm=$pdo->prepare('SELECT mode FROM payments WHERE application_id=? ORDER BY id DESC LIMIT 1');$epm->execute([$id]);$editPaymentMode=$epm->fetchColumn()?:'Cash';
}
?><div class="title"><div><h1><?=$edit?'✏️ Edit Work':'New Customer + Work'?></h1><p class="muted">Customer और application एक ही entry में</p></div></div><div class="panel"><form class="form" method="post" action="action.php"><?php if($edit):?><input type="hidden" name="action" value="application_update"><input type="hidden" name="id" value="<?=$edit['id']?>"><?php else:?><input type="hidden" name="action" value="application"><?php endif;?><?php if($edit):$ec=$pdo->prepare('SELECT * FROM customers WHERE id=?');$ec->execute([$edit['customer_id']]);$ec=$ec->fetch();?><input type="hidden" name="customer_id" value="<?=$edit['customer_id']?>"><div class="field"><label>Customer Name *</label><input name="customer_name" value="<?=e($ec['name'])?>" required></div><div class="field"><label>Mobile</label><input name="customer_mobile" value="<?=e($ec['mobile'])?>"></div><?php else:?><div class="field"><label>Customer Name *</label><input name="customer_name" placeholder="Customer name manually enter करें" required></div><div class="field"><label>Mobile</label><input name="customer_mobile" inputmode="numeric" placeholder="Mobile number"></div><?php endif;?><div class="field"><label>Service *</label><select name="service_id" required><?php foreach($services as $s):?><option value="<?=$s['id']?>" <?=$edit&&$edit['service_id']==$s['id']?'selected':''?>><?=e($s['name'])?> — <?=money($s['rate'])?></option><?php endforeach;?></select></div><div class="field"><label>Sub Service *</label><select name="sub_service" required><option value="">Select Sub Service</option><option value="Tahsil work" <?=$edit&&($edit['sub_service']??'')==='Tahsil work'?'selected':''?>>Tahsil work</option><option value="General work" <?=$edit&&($edit['sub_service']??'')==='General work'?'selected':''?>>General work</option></select></div><?php if($isAdmin):?><div class="field"><label>Assign Operator</label><select name="operator_name" required><option value="">Select Operator</option><?php foreach($operators as $op):?><option value="<?=e($op['username'])?>" <?=($edit&&($edit['operator_name']??'')===$op['username'])?'selected':''?>><?=e($op['username'])?></option><?php endforeach;?></select></div><?php endif;?><div class="field"><label>Date</label><input name="work_date" type="date" value="<?=e($edit['work_date']??$today)?>" required></div><div class="field"><label>Total Amount ₹</label><input name="total" type="number" step="0.01" value="<?=e($edit['total']??'0')?>" required></div><?php if(!$edit):?><div class="field"><label>Paid Amount ₹</label><input name="paid" type="number" step="0.01" value="0"><small>Opening payment, if any</small></div><?php endif;?><div class="field"><label>Payment Mode</label><select name="paid_mode"><option value="Cash" <?=$editPaymentMode==='Cash'?'selected':''?>>Cash</option><option value="Online" <?=$editPaymentMode==='Online'?'selected':''?>>Online</option></select><?php if($edit):?><small>Saved for the latest payment of this work.</small><?php else:?><small>Opening payment mode</small><?php endif;?></div><div class="field"><label>Status</label><select name="status"><?php foreach(['Pending','Processing','Ready','Delivered'] as $s):?><option <?=$edit&&$edit['status']===$s?'selected':''?>><?=$s?></option><?php endforeach;?></select></div><div class="field full"><label>Remark</label><textarea name="remark"><?=e($edit['remark']??'')?></textarea></div><div class="actions full"><button><?=$edit?'💾 Update Work':'💾 Save Work'?></button><a class="btn secondary" href="?view=customers">Cancel</a></div></form></div>
<?php elseif($view==='newcustomer'): ?><div class="title"><h1>New Customer</h1></div><div class="panel"><form class="form" method="post" action="action.php"><input type="hidden" name="action" value="customer"><div class="field"><label>Name *</label><input name="name" required></div><div class="field"><label>Mobile</label><input name="mobile"></div><div class="field"><label>City</label><input name="city"></div><div class="field full"><label>Note</label><input name="note"></div><div class="actions full"><button>Save Customer</button></div></form></div>
<?php elseif($view==='editpayment'): $id=(int)($_GET['id']??0);$st=$isAdmin?$pdo->prepare('SELECT * FROM payments WHERE id=?'):$pdo->prepare('SELECT * FROM payments WHERE id=? AND operator_name=?');$isAdmin?$st->execute([$id]):$st->execute([$id,$operatorName]);$ep=$st->fetch();if($isAdmin){$apps2=$pdo->query("SELECT a.*,c.name customer,s.name service FROM applications a JOIN customers c ON c.id=a.customer_id JOIN services s ON s.id=a.service_id ORDER BY a.id DESC")->fetchAll();}else{$st2=$pdo->prepare("SELECT a.*,c.name customer,s.name service FROM applications a JOIN customers c ON c.id=a.customer_id JOIN services s ON s.id=a.service_id WHERE a.operator_name=? ORDER BY a.id DESC");$st2->execute([$operatorName]);$apps2=$st2->fetchAll();} ?><div class="title"><h1>✏️ Edit Payment</h1></div><div class="panel"><form class="form" method="post" action="action.php"><input type="hidden" name="action" value="payment_update"><input type="hidden" name="id" value="<?=$ep['id']?>"><div class="field"><label>Customer</label><select name="customer_id"><?php foreach($customers as $c):?><option value="<?=$c['id']?>" <?=$c['id']==$ep['customer_id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select></div><div class="field"><label>Application</label><select name="application_id"><option value="">None</option><?php foreach($apps2 as $a):?><option value="<?=$a['id']?>" <?=$ep['application_id']==$a['id']?'selected':''?>><?=e($a['customer'])?> — <?=e($a['service'])?></option><?php endforeach;?></select></div><div class="field"><label>Amount ₹</label><input name="amount" type="number" step="0.01" value="<?=e($ep['amount'])?>" required></div><div class="field"><label>Payment Mode</label><select name="mode"><?php foreach(['Cash','Online'] as $m):?><option <?=$ep['mode']===$m?'selected':''?>><?=$m?></option><?php endforeach;?></select></div><div class="field"><label>Date</label><input name="payment_date" type="date" value="<?=e($ep['payment_date'])?>" required></div><div class="field full"><label>Note</label><input name="note" value="<?=e($ep['note'])?>"></div><div class="actions full"><button>💾 Update Payment</button></div></form></div>
<?php elseif($view==='newpayment'): $selected=(int)($_GET['app']??0);if($isAdmin){$apps2=$pdo->query("SELECT a.*,c.name customer,s.name service FROM applications a JOIN customers c ON c.id=a.customer_id JOIN services s ON s.id=a.service_id ORDER BY a.id DESC")->fetchAll();}else{$st2=$pdo->prepare("SELECT a.*,c.name customer,s.name service FROM applications a JOIN customers c ON c.id=a.customer_id JOIN services s ON s.id=a.service_id WHERE a.operator_name=? ORDER BY a.id DESC");$st2->execute([$operatorName]);$apps2=$st2->fetchAll();} ?><div class="title"><h1>New Payment</h1></div><div class="panel"><form class="form" method="post" action="action.php"><input type="hidden" name="action" value="payment"><div class="field"><label>Application</label><select name="application_id" required><option value="">Select Application</option><?php foreach($apps2 as $a):?><option value="<?=$a['id']?>" <?=$selected===$a['id']?'selected':''?>><?=e($a['customer'])?> — <?=e($a['service'])?> — Due <?=money($a['total']-$a['paid'])?></option><?php endforeach;?></select></div><div class="field"><label>Amount ₹</label><input name="amount" type="number" step="0.01" required></div><div class="field"><label>Payment Mode</label><select name="mode"><option value="Cash">Cash</option><option value="Online">Online</option></select></div><div class="field"><label>Date</label><input name="payment_date" type="date" value="<?=$today?>"></div><div class="field full"><label>Note</label><input name="note"></div><div class="actions full"><button>💾 Save & Receipt</button></div></form></div>
<?php elseif($view==='newservice'): ?><div class="title"><h1>Add Service</h1></div><div class="panel"><form class="form" method="post" action="action.php"><input type="hidden" name="action" value="service"><div class="field"><label>Service Name</label><input name="name" required></div><div class="field"><label>Rate ₹</label><input name="rate" type="number" step="0.01"></div><div class="actions full"><button>Save Service</button><a class="btn secondary" href="?view=services">Cancel</a></div></form></div>
<?php elseif($view==='editservice'): $sid=(int)($_GET['id']??0);$ss=$pdo->prepare('SELECT * FROM services WHERE id=?');$ss->execute([$sid]);$svc=$ss->fetch(); if(!$svc): ?><div class="panel"><h3>Service not found.</h3></div><?php else: ?><div class="title"><h1>✏️ Edit Service & Rate</h1></div><div class="panel"><form class="form" method="post" action="action.php"><input type="hidden" name="action" value="service_update"><input type="hidden" name="id" value="<?=$svc['id']?>"><div class="field"><label>Service Name</label><input name="name" value="<?=e($svc['name'])?>" required></div><div class="field"><label>Rate ₹</label><input name="rate" type="number" step="0.01" value="<?=e($svc['rate'])?>" required></div><div class="actions full"><button>💾 Update Service</button><a class="btn secondary" href="?view=services">Cancel</a></div></form></div><?php endif; ?>
<?php endif; ?></section></main></div></body></html>
