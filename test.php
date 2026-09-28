<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1 style='color:green'>PHP IS WORKING</h1>";

echo "<pre>";
echo "MYSQLHOST = " . getenv('MYSQLHOST') . "\n";
echo "MYSQLPORT = " . getenv('MYSQLPORT') . "\n";
echo "MYSQLUSER = " . getenv('MYSQLUSER') . "\n";
echo "MYSQLPASSWORD = " . (getenv('MYSQLPASSWORD') ? 'SET' : 'NOT SET') . "\n";
echo "MASTER_DB_NAME = " . getenv('MASTER_DB_NAME') . "\n";
echo "</pre>";
