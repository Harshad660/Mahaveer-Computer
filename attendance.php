<?php
require __DIR__.'/config.php'; auth();
$pdo = db();

$pdo->exec("CREATE TABLE IF NOT EXISTS employees (
 id INT AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 mobile VARCHAR(30) NULL,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TABLE IF NOT EXISTS attendance (
 id INT AUTO_INCREMENT PRIMARY KEY,
 employee_id INT NOT NULL,
 att_date DATE NOT NULL,
 entry_time TIME NULL,
 exit_time TIME NULL,
 status VARCHAR(30) NOT NULL DEFAULT 'Absent',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_emp_date(employee_id,att_date),
 INDEX(att_date),
 CONSTRAINT fk_att_emp FOREIGN KEY(employee_id) REFERENCES employees(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function attendanceStatus($date, $entry, $exit=null) {
    if (date('N', strtotime($date)) == 7) return 'Holiday';
    if (!$entry) return 'Absent';

    // Entry-time status
    $t = strtotime($entry);
    $entryStatus = 'OK';
    if ($t <= strtotime('10:15:00')) $entryStatus = 'OK';
    elseif ($t <= strtotime('10:45:00')) $entryStatus = 'Late Mark';
    else $entryStatus = 'Half Day';

    // Exit-time rule
    // Before 6:00 PM = Half Day
    // 6:01 PM to 6:45 PM = Late Mark
    // 6:00 PM or after 6:45 PM = keep entry-time status
    if ($exit) {
        $exitTime = date('H:i:s', strtotime($exit));
        if ($exitTime < '18:00:00') return 'Half Day';
        if ($exitTime >= '18:01:00' && $exitTime <= '18:45:00') return 'Late Mark';
    }
    return $entryStatus;
}

$today=date('Y-m-d');
$date=$_GET['date']??$today;
$month=$_GET['month']??date('Y-m');

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (isset($_POST['add_employee'])) {
        $name=trim($_POST['name']??''); $mobile=trim($_POST['mobile']??'');
        if ($name!=='') {
            $s=$pdo->prepare('INSERT INTO employees(name,mobile) VALUES(?,?)');
            $s->execute([$name,$mobile]);
        }
        header('Location: attendance.php?date='.urlencode($_POST['att_date']??$today)); exit;
    }

    if (isset($_POST['save_attendance'])) {
        $employeeId=(int)($_POST['employee_id']??0);
        $attDate=$_POST['att_date']??$today;
        $entry=trim($_POST['entry_time']??'');
        $exit=trim($_POST['exit_time']??'');
        if (date('N',strtotime($attDate))==7) { $entry=''; $exit=''; }
        $entry=$entry!==''?$entry:null;
        $exit=$exit!==''?$exit:null;
        $status=attendanceStatus($attDate,$entry,$exit);
        $s=$pdo->prepare("INSERT INTO attendance(employee_id,att_date,entry_time,exit_time,status)
            VALUES(?,?,?,?,?)
            ON DUPLICATE KEY UPDATE entry_time=VALUES(entry_time),exit_time=VALUES(exit_time),status=VALUES(status)");
        $s->execute([$employeeId,$attDate,$entry,$exit,$status]);
        header('Location: attendance.php?date='.urlencode($attDate).'&saved=1'); exit;
    }
}

$employees=$pdo->query("SELECT * FROM employees WHERE active=1 ORDER BY name")->fetchAll();
$rows=[];
foreach($employees as $e){
    $s=$pdo->prepare('SELECT * FROM attendance WHERE employee_id=? AND att_date=?');
    $s->execute([$e['id'],$date]);
    $a=$s->fetch();
    $rows[]=['e'=>$e,'a'=>$a,'status'=>attendanceStatus($date,$a['entry_time']??null,$a['exit_time']??null)];
}
?>
<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mahaveer Computer - Employee Attendance</title>
<style>
body{font-family:Arial,sans-serif;background:#f3f6fb;color:#172033;font-weight:700;margin:0}.wrap{max-width:1200px;margin:auto;padding:18px}.top{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap}.card{background:#fff;border-radius:14px;padding:16px;margin:14px 0;box-shadow:0 5px 18px #00000012}h1{color:#2447ad}h2{font-size:20px;color:#334a8f}input,button{padding:10px;border:1px solid #d7dfec;border-radius:8px;font-weight:700}input[type=time]{min-width:120px}button{background:#3155c8;color:#fff;border:0;cursor:pointer}.green{background:#168a57}.red{background:#c93636}.blue{background:#2857c5}table{width:100%;border-collapse:collapse;background:#fff}th,td{padding:10px;border-bottom:1px solid #e3e8f1;text-align:left;vertical-align:middle}th{background:#3155c8;color:#fff}.badge{display:inline-block;padding:5px 9px;border-radius:20px;color:#fff}.status-OK{background:#168a57}.status-Late-Mark{background:#d87918}.status-Half-Day{background:#b88700}.status-Absent{background:#c93636}.status-Holiday{background:#2857c5}.muted{color:#64748b;font-size:13px}.saved{background:#e6f7ee;color:#087443;border:1px solid #9fe0bd;padding:9px 12px;border-radius:8px;margin-top:10px}.timebox{display:flex;gap:5px;align-items:center}.timebox small{display:block;color:#64748b;font-size:11px}@media(max-width:700px){table{font-size:13px;display:block;overflow-x:auto;white-space:nowrap}th,td{padding:7px}}
</style></head><body><div class="wrap">
<div class="top"><h1>🕘 Employee Attendance</h1><a href="index.php">← Back to Dashboard</a></div>
<div class="card"><form method="get"><label>Date: <input type="date" name="date" value="<?=htmlspecialchars($date)?>"></label> <button>Show</button></form><div class="muted">Sunday is automatically Holiday. No entry on a working day is Absent.</div><?php if(isset($_GET['saved'])):?><div class="saved">✓ Attendance saved successfully.</div><?php endif;?></div>
<div class="card"><h2>Add Employee</h2><form method="post"><input type="hidden" name="att_date" value="<?=htmlspecialchars($date)?>"><input name="name" placeholder="Employee Name" required><input name="mobile" placeholder="Mobile (optional)"><button name="add_employee" value="1">+ Add Employee</button></form></div>
<div class="card"><h2>Attendance – <?=date('d-m-Y',strtotime($date))?></h2>
<table><tr><th>Employee</th><th>Entry Time</th><th>Exit Time</th><th>Status</th><th>Action</th></tr>
<?php foreach($rows as $r): $e=$r['e'];$a=$r['a'];$st=$r['status'];$fid='att_'.$e['id'];$isSun=date('N',strtotime($date))==7; ?>
<tr>
<td><?=htmlspecialchars($e['name'])?></td>
<td><div class="timebox"><input form="<?=$fid?>" type="time" name="entry_time" value="<?=htmlspecialchars($a['entry_time']?substr($a['entry_time'],0,5):'')?>" <?=$isSun?'disabled':''?>></div></td>
<td><div class="timebox"><input form="<?=$fid?>" type="time" name="exit_time" value="<?=htmlspecialchars($a['exit_time']?substr($a['exit_time'],0,5):'')?>" <?=$isSun?'disabled':''?>></div></td>
<td><span class="badge status-<?=str_replace(' ','-',$st)?>"><?=htmlspecialchars($st)?></span></td>
<td><form id="<?=$fid?>" method="post"><input type="hidden" name="employee_id" value="<?=$e['id']?>"><input type="hidden" name="att_date" value="<?=htmlspecialchars($date)?>"><button class="blue" name="save_attendance" value="1" <?=$isSun?'disabled':''?>>💾 Save</button></form></td>
</tr>
<?php endforeach;?></table>
<div class="muted" style="margin-top:12px"><b>Timing:</b> 10:15 तक = OK &nbsp; | &nbsp; 10:15–10:45 = Late Mark &nbsp; | &nbsp; 10:45 के बाद = Half Day</div>
</div>
<div class="card"><h2>Monthly Summary</h2><form method="get"><input type="hidden" name="date" value="<?=htmlspecialchars($date)?>"><label>Month: <input type="month" name="month" value="<?=htmlspecialchars($month)?>"></label> <button>Show Month</button></form>
<table><tr><th>Employee</th><th>OK</th><th>Late</th><th>Half Day</th><th>Absent</th><th>Holiday</th></tr>
<?php foreach($employees as $e){$counts=['OK'=>0,'Late Mark'=>0,'Half Day'=>0,'Absent'=>0,'Holiday'=>0];$start=$month.'-01';$end=date('Y-m-t',strtotime($start));$d=$start;while($d<=$end){$s=$pdo->prepare('SELECT entry_time,exit_time FROM attendance WHERE employee_id=? AND att_date=?');$s->execute([$e['id'],$d]);$a=$s->fetch();$st=attendanceStatus($d,$a['entry_time']??null,$a['exit_time']??null);$counts[$st]++;$d=date('Y-m-d',strtotime($d.' +1 day'));} ?>
<tr><td><?=htmlspecialchars($e['name'])?></td><td><?=$counts['OK']?></td><td><?=$counts['Late Mark']?></td><td><?=$counts['Half Day']?></td><td><?=$counts['Absent']?></td><td><?=$counts['Holiday']?></td></tr>
<?php } ?></table></div></div></body></html>
