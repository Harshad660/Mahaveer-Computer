<?php
require "config.php";
global $DB_HOST,$DB_NAME,$DB_USER,$DB_PASS;
try {
 $pdo = new PDO("mysql:host=$DB_HOST;charset=utf8mb4",$DB_USER,$DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $pdo->exec("CREATE DATABASE IF NOT EXISTS `$DB_NAME` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
 $pdo->exec("USE `$DB_NAME`");
 $sql = [
 "CREATE TABLE IF NOT EXISTS users(id INT AUTO_INCREMENT PRIMARY KEY,username VARCHAR(80) UNIQUE,password_hash VARCHAR(255) NOT NULL,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
 "CREATE TABLE IF NOT EXISTS customers(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(150) NOT NULL,mobile VARCHAR(30),city VARCHAR(120),note TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)",
 "CREATE TABLE IF NOT EXISTS services(id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(180) NOT NULL,rate DECIMAL(10,2) DEFAULT 0,active TINYINT(1) DEFAULT 1)",
 "CREATE TABLE IF NOT EXISTS applications(id INT AUTO_INCREMENT PRIMARY KEY,customer_id INT NOT NULL,service_id INT NOT NULL,total DECIMAL(10,2) DEFAULT 0,paid DECIMAL(10,2) DEFAULT 0,status VARCHAR(30) DEFAULT 'Pending',work_date DATE,remark TEXT,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(customer_id) REFERENCES customers(id),FOREIGN KEY(service_id) REFERENCES services(id))",
 "CREATE TABLE IF NOT EXISTS payments(id INT AUTO_INCREMENT PRIMARY KEY,customer_id INT NOT NULL,application_id INT NULL,amount DECIMAL(10,2) NOT NULL,mode VARCHAR(40) DEFAULT 'Cash',note VARCHAR(255),payment_date DATE,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,FOREIGN KEY(customer_id) REFERENCES customers(id),FOREIGN KEY(application_id) REFERENCES applications(id) ON DELETE SET NULL)",
 "CREATE TABLE IF NOT EXISTS settings(id INT PRIMARY KEY,shop_name VARCHAR(180),mobile VARCHAR(40),address TEXT,gstin VARCHAR(40))"
 ];
 foreach($sql as $q)$pdo->exec($q);
 $count=$pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'];
 if(!$count){$st=$pdo->prepare("INSERT INTO users(username,password_hash) VALUES(?,?)");$st->execute(['admin',password_hash('admin123',PASSWORD_DEFAULT)]);}
 $pdo->exec("INSERT IGNORE INTO settings(id,shop_name,mobile,address,gstin) VALUES(1,'Mahaveer Computer','','New Town, Badnera, Amravati, Maharashtra','')");
 $defaults=[
 ['Aaple Sarkar / Setu Application',50],['Income Certificate',100],['Domicile Certificate',100],['Caste Certificate',100],['PAN Card',100],['Passport Application',150],['Online Form Filling',50],['Print / Xerox',5],['Photo / Passport Photo',40],['Computer / Printer Service',0]
 ];
 $st=$pdo->prepare("INSERT INTO services(name,rate) VALUES(?,?)");
 foreach($defaults as $x){$st->execute($x);}
 echo "<h2>Installation completed.</h2><p>Login: <b>admin</b> / <b>admin123</b></p><p><a href='login.php'>Open Login</a></p><p>For security, delete or rename install.php after installation.</p>";
} catch(Exception $e){ echo "<h2>Installation error</h2><pre>".e($e->getMessage())."</pre>"; }
?>