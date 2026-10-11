<?php
require __DIR__ . '/config.php';
date_default_timezone_set('Asia/Kolkata');
auth();
$p=$_POST; $a=$p['action']??''; $d=db();
try{
 $c=$d->query("SHOW COLUMNS FROM applications LIKE 'sub_service'")->fetch();
 if(!$c)$d->exec("ALTER TABLE applications ADD sub_service VARCHAR(50) NOT NULL DEFAULT ''");
 $c=$d->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
 if(!$c)$d->exec("ALTER TABLE users ADD role VARCHAR(20) NOT NULL DEFAULT 'operator'");
 $d->exec("UPDATE users SET role='admin' WHERE username='admin'");
}catch(Throwable $e){}
$stMe=$d->prepare("SELECT username,COALESCE(role,'operator') role FROM users WHERE username=? LIMIT 1");$stMe->execute([$_SESSION['username']??'']);$me=$stMe->fetch()?:['username'=>($_SESSION['username']??''),'role'=>'operator'];
$isAdmin=($me['role']==='admin'||strtolower($me['username'])==='admin');$operatorName=$me['username'];
function require_admin(){global $isAdmin;if(!$isAdmin)throw new Exception('Admin access required.');}
function own_application($id){global $d,$isAdmin,$operatorName;if($isAdmin)return true;$q=$d->prepare('SELECT 1 FROM applications WHERE id=? AND operator_name=?');$q->execute([(int)$id,$operatorName]);return (bool)$q->fetchColumn();}
function own_payment($id){global $d,$isAdmin,$operatorName;if($isAdmin)return true;$q=$d->prepare('SELECT 1 FROM payments WHERE id=? AND operator_name=?');$q->execute([(int)$id,$operatorName]);return (bool)$q->fetchColumn();}
function back($view='dashboard'){
  global $p;
  $returnTo=trim($p['return_to']??'');
  if($returnTo!=='' && preg_match('/^\/?index\.php\?view=[A-Za-z0-9_&=.%+\-]*$/',$returnTo)){ header('Location: '.$returnTo); exit; }
  header('Location: index.php?view='.$view); exit;
}
function valid_operator($username){
  global $d;
  $q=$d->prepare("SELECT username FROM users WHERE username=? AND COALESCE(role,'operator')='operator' LIMIT 1");
  $q->execute([$username]);
  return (bool)$q->fetchColumn();
}
try {
 if($a==='customer'){
  $s=$d->prepare('INSERT INTO customers(name,mobile,city,note) VALUES(?,?,?,?)');
  $s->execute([trim($p['name']),trim($p['mobile']??''),trim($p['city']??''),trim($p['note']??'')]); back('customers');
 }
 if($a==='customer_update'){
  $s=$d->prepare('UPDATE customers SET name=?,mobile=?,city=?,note=? WHERE id=?');
  $s->execute([trim($p['name']),trim($p['mobile']??''),trim($p['city']??''),trim($p['note']??''),(int)$p['id']]); back('customers');
 }
 if($a==='service'){ require_admin();
  $name=trim($p['name']??''); if($name==='') throw new Exception('Service name is required.');
  $s=$d->prepare('INSERT INTO services(name,rate,active) VALUES(?,?,1)'); $s->execute([$name,(float)($p['rate']??0)]); back('services');
 }
 if($a==='service_update'){ require_admin();
  $id=(int)($p['id']??0); $name=trim($p['name']??''); if($id<=0||$name==='') throw new Exception('Invalid service.');
  $s=$d->prepare('UPDATE services SET name=?,rate=? WHERE id=?'); $s->execute([$name,(float)($p['rate']??0),$id]); back('services');
 }
 if($a==='service_delete'){ require_admin();
  $id=(int)($p['id']??0); if($id<=0) throw new Exception('Invalid service.');
  // Soft-delete so existing applications/history remain intact.
  $s=$d->prepare('UPDATE services SET active=0 WHERE id=?'); $s->execute([$id]); back('services');
 }
 if($a==='application'){
  $total=(float)($p['total']??0); $paid=(float)($p['paid']??0);
   $op=$_SESSION['username'];
   if($isAdmin && trim($p['operator_name']??'')!==''){
     $requestedOp=trim($p['operator_name']);
     if(!valid_operator($requestedOp)) throw new Exception('Invalid operator selected.');
     $op=$requestedOp;
   }
  $name=trim($p['customer_name']??''); $mobile=trim($p['customer_mobile']??'');
  if($name==='') throw new Exception('Customer name is required.');
  if($paid<0) throw new Exception('Paid amount cannot be negative.');
  $d->beginTransaction();
  $customerId=0;
  if($mobile!==''){
    $q=$d->prepare('SELECT id FROM customers WHERE mobile=? ORDER BY id DESC LIMIT 1');$q->execute([$mobile]);$customerId=(int)($q->fetchColumn()?:0);
  }
  if(!$customerId){
    $q=$d->prepare('SELECT id FROM customers WHERE name=? ORDER BY id DESC LIMIT 1');$q->execute([$name]);$customerId=(int)($q->fetchColumn()?:0);
  }
  if($customerId){$d->prepare('UPDATE customers SET name=?,mobile=? WHERE id=?')->execute([$name,$mobile,$customerId]);}
  else {$q=$d->prepare('INSERT INTO customers(name,mobile,city,note) VALUES(?,?,?,?)');$q->execute([$name,$mobile,'','']);$customerId=(int)$d->lastInsertId();}
  $s=$d->prepare('INSERT INTO applications(customer_id,service_id,sub_service,total,paid,status,work_date,remark,operator_name) VALUES(?,?,?,?,?,?,?,?,?)');
  $s->execute([$customerId,(int)$p['service_id'],trim($p['sub_service']??''),$total,$paid,$p['status']??'Pending',$p['work_date']?:date('Y-m-d'),trim($p['remark']??''),$op]);
  if($paid>0){ $appId=$d->lastInsertId(); $s=$d->prepare('INSERT INTO payments(customer_id,application_id,amount,mode,note,payment_date,operator_name) VALUES(?,?,?,?,?,?,?)'); $s->execute([$customerId,$appId,$paid,$p['paid_mode']??'Cash','Opening payment',date('Y-m-d'),$op]); }
  $d->commit(); back('customers');
 }
 if($a==='application_update'){
  $id=(int)$p['id']; if(!own_application($id)) throw new Exception('You can edit only your own work.');
  $s=$d->prepare('UPDATE customers SET name=?,mobile=? WHERE id=(SELECT customer_id FROM applications WHERE id=?)');
  $s->execute([trim($p['customer_name']),trim($p['customer_mobile']??''),$id]);
  // Payment mode on Edit Work applies to the latest payment recorded for this work.
  $editPaymentMode=trim($p['paid_mode']??'');
  if($editPaymentMode!=='' && !in_array($editPaymentMode,['Cash','Online'],true)) throw new Exception('Invalid payment mode.');
  if($isAdmin && trim($p['operator_name']??'')!==''){
    $newOp=trim($p['operator_name']);
    if(!valid_operator($newOp)) throw new Exception('Invalid operator selected.');
    $s=$d->prepare('UPDATE applications SET customer_id=?,service_id=?,sub_service=?,total=?,status=?,work_date=?,remark=?,operator_name=? WHERE id=?');
    $s->execute([(int)$p['customer_id'],(int)$p['service_id'],trim($p['sub_service']??''),(float)($p['total']??0),$p['status']??'Pending',$p['work_date']?:date('Y-m-d'),trim($p['remark']??''),$newOp,$id]);
  } else {
    $s=$d->prepare('UPDATE applications SET customer_id=?,service_id=?,sub_service=?,total=?,status=?,work_date=?,remark=? WHERE id=?');
    $s->execute([(int)$p['customer_id'],(int)$p['service_id'],trim($p['sub_service']??''),(float)($p['total']??0),$p['status']??'Pending',$p['work_date']?:date('Y-m-d'),trim($p['remark']??''),$id]);
  }
  // Keep the selected mode in sync with the latest payment for this application.
  if($editPaymentMode!==''){
    $pm=$d->prepare('SELECT id FROM payments WHERE application_id=? ORDER BY id DESC LIMIT 1');
    $pm->execute([$id]);
    $paymentId=$pm->fetchColumn();
    if($paymentId){
      $d->prepare('UPDATE payments SET mode=? WHERE id=?')->execute([$editPaymentMode,(int)$paymentId]);
    }
  }
  back('customers');
 }
 if($a==='assign_operator'){
  require_admin();
  $id=(int)($p['id']??0);
  $newOp=trim($p['operator_name']??'');
  if($id<=0) throw new Exception('Invalid work.');
  if($newOp==='') throw new Exception('Please select an operator.');
  if(!valid_operator($newOp)) throw new Exception('Invalid operator selected.');
  $q=$d->prepare('SELECT id FROM applications WHERE id=? LIMIT 1');
  $q->execute([$id]);
  if(!$q->fetchColumn()) throw new Exception('Work not found.');
  $u=$d->prepare('UPDATE applications SET operator_name=? WHERE id=?');
  $u->execute([$newOp,$id]);
  back('customers');
 }

 if($a==='status'){
  if(!own_application((int)$p['id'])) throw new Exception('You can update only your own application.');
  $s=$d->prepare('UPDATE applications SET status=? WHERE id=?'); $s->execute([$p['status'],(int)$p['id']]); back('status');
 }
 if($a==='payment'){
  $amount=(float)($p['amount']??0); if($amount<=0) throw new Exception('Amount must be greater than 0.');
  $appId=$p['application_id']!==''?(int)$p['application_id']:null; $customerId=(int)$p['customer_id']; $op=$operatorName;
  $d->beginTransaction();
  if($appId){if(!own_application($appId)) throw new Exception('You can collect payment only for your own work.');$q=$d->prepare('SELECT customer_id,operator_name FROM applications WHERE id=? FOR UPDATE');$q->execute([$appId]);$app=$q->fetch();if(!$app)throw new Exception('Application not found.');$customerId=(int)$app['customer_id']; if(!$isAdmin) $op=$operatorName; else $op=$operatorName;}
  $s=$d->prepare('INSERT INTO payments(customer_id,application_id,amount,mode,note,payment_date,operator_name) VALUES(?,?,?,?,?,?,?)');
  $s->execute([$customerId,$appId,$amount,$p['mode']??'Cash',trim($p['note']??''),$p['payment_date']?:date('Y-m-d'),$op]);
  if($appId){$u=$d->prepare('UPDATE applications SET paid=paid+? WHERE id=?');$u->execute([$amount,$appId]);
   $q=$d->prepare('SELECT total,paid FROM applications WHERE id=?');$q->execute([$appId]);$ap=$q->fetch();
   if($ap && (float)$ap['paid'] >= (float)$ap['total']){$d->prepare("UPDATE applications SET paid=GREATEST(paid,total), status='Delivered' WHERE id=?")->execute([$appId]);}
  }
  $id=$d->lastInsertId(); $d->commit(); header('Location: receipt.php?id='.$id); exit;
 }
 if($a==='payment_update'){
  $id=(int)$p['id']; if(!own_payment($id)) throw new Exception('You can edit only your own payment.'); $amount=(float)($p['amount']??0); if($amount<=0)throw new Exception('Amount must be greater than 0.');
  $d->beginTransaction();
  $q=$d->prepare('SELECT * FROM payments WHERE id=? FOR UPDATE');$q->execute([$id]);$old=$q->fetch();if(!$old)throw new Exception('Payment not found.');
  if($old['application_id']){$u=$d->prepare('UPDATE applications SET paid=paid-? WHERE id=?');$u->execute([(float)$old['amount'],(int)$old['application_id']]);}
  $newApp=$p['application_id']!==''?(int)$p['application_id']:null; $customerId=(int)$p['customer_id'];
  if($newApp){if(!own_application($newApp)) throw new Exception('You can assign payment only to your own work.');$q=$d->prepare('SELECT customer_id FROM applications WHERE id=? FOR UPDATE');$q->execute([$newApp]);$app=$q->fetch();if(!$app)throw new Exception('Application not found.');$customerId=(int)$app['customer_id'];$u=$d->prepare('UPDATE applications SET paid=paid+? WHERE id=?');$u->execute([$amount,$newApp]);
   $q=$d->prepare('SELECT total,paid FROM applications WHERE id=?');$q->execute([$newApp]);$nap=$q->fetch();
   if($nap && (float)$nap['paid'] >= (float)$nap['total']){$d->prepare("UPDATE applications SET paid=GREATEST(paid,total), status='Delivered' WHERE id=?")->execute([$newApp]);}
  }
  $u=$d->prepare('UPDATE payments SET customer_id=?,application_id=?,amount=?,mode=?,note=?,payment_date=? WHERE id=?');
  $u->execute([$customerId,$newApp,$amount,$p['mode']??'Cash',trim($p['note']??''),$p['payment_date']?:date('Y-m-d'),$id]);
  $d->commit(); header('Location: receipt.php?id='.$id); exit;
 }
 if($a==='payment_delete'){
  $id=(int)$p['id']; if(!own_payment($id)) throw new Exception('You can delete only your own payment.'); $d->beginTransaction();$q=$d->prepare('SELECT application_id,amount FROM payments WHERE id=? FOR UPDATE');$q->execute([$id]);$pay=$q->fetch();if(!$pay)throw new Exception('Payment not found.');if($pay['application_id']){$u=$d->prepare('UPDATE applications SET paid=paid-? WHERE id=?');$u->execute([(float)$pay['amount'],(int)$pay['application_id']]);}$d->prepare('DELETE FROM payments WHERE id=?')->execute([$id]);$d->commit();back('payments');
 }
 if($a==='application_delete'){
  $id=(int)$p['id']; if(!own_application($id)) throw new Exception('You can delete only your own work.');$d->beginTransaction();$d->prepare('DELETE FROM payments WHERE application_id=?')->execute([$id]);$d->prepare('DELETE FROM applications WHERE id=?')->execute([$id]);$d->commit();back('customers');
 }
 if($a==='customer_delete'){
  $id=(int)$p['id']; if(!$isAdmin) throw new Exception('Only admin can delete a customer record.');$d->beginTransaction();$q=$d->prepare('SELECT id FROM applications WHERE customer_id=?');$q->execute([$id]);$ids=$q->fetchAll(PDO::FETCH_COLUMN);$d->prepare('DELETE FROM payments WHERE customer_id=?')->execute([$id]);if($ids){$in=implode(',',array_fill(0,count($ids),'?'));$d->prepare("DELETE FROM payments WHERE application_id IN ($in)")->execute($ids);$d->prepare("DELETE FROM applications WHERE id IN ($in)")->execute($ids);}$d->prepare('DELETE FROM customers WHERE id=?')->execute([$id]);$d->commit();back('customers');
 }
 if($a==='settings'){ require_admin();$s=$d->prepare('UPDATE settings SET shop_name=?,mobile=?,address=?,gstin=? WHERE id=1');$s->execute([$p['shop_name'],$p['mobile'],$p['address'],$p['gstin']]);back('settings');}
 if($a==='operator'){ require_admin();
  $u=trim($p['username']);$pw=$p['password'];if($u===''||$pw==='')throw new Exception('Username and password required.');$s=$d->prepare('INSERT INTO users(username,password_hash) VALUES(?,?)');$s->execute([$u,password_hash($pw,PASSWORD_DEFAULT)]);back('settings');
 }
 if($a==='operator_delete'){ require_admin();if((int)$p['id']<=0)throw new Exception('Invalid operator.');$d->prepare('DELETE FROM users WHERE id=? AND username<>?')->execute([(int)$p['id'],$_SESSION['username']]);back('settings');}
 back();
} catch(Throwable $e){ if($d->inTransaction())$d->rollBack(); http_response_code(500); echo '<h3>Error</h3><pre>'.e($e->getMessage()).'</pre><p><a href="index.php">Back</a></p>'; }
