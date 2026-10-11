<?php
require __DIR__.'/config.php'; auth(); $pdo=db();
$pdo->exec("CREATE TABLE IF NOT EXISTS attendance (id INT AUTO_INCREMENT PRIMARY KEY,employee_id INT NOT NULL,att_date DATE NOT NULL,entry_time TIME NULL,exit_time TIME NULL,status VARCHAR(30) NOT NULL DEFAULT 'Absent',created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_emp_date(employee_id,att_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$employee=(int)($_POST['employee_id']??0);$date=$_POST['att_date']??date('Y-m-d');
function st($date,$entry){if(date('N',strtotime($date))==7)return 'Holiday';if(!$entry)return 'Absent';$t=strtotime($entry);if($t<=strtotime('10:15:00'))return 'OK';if($t<=strtotime('10:45:00'))return 'Late Mark';return 'Half Day';}
if(isset($_POST['mark_entry'])){$time=date('H:i:s');$status=st($date,$time);$q=$pdo->prepare("INSERT INTO attendance(employee_id,att_date,entry_time,status) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE entry_time=VALUES(entry_time),status=VALUES(status)");$q->execute([$employee,$date,$time,$status]);}
if(isset($_POST['mark_exit'])){$q=$pdo->prepare("UPDATE attendance SET exit_time=? WHERE employee_id=? AND att_date=?");$q->execute([date('H:i:s'),$employee,$date]);}
header('Location: attendance.php?date='.urlencode($date));exit;
