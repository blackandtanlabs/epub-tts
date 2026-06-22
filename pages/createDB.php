<?php
$a=new PDO("sqlite:label_help");



$SQLcommand = "CREATE TABLE males
	(
	label TEXT PRIMARY KEY,
	labelCase TEXT NOT NULL,
	accessCount INTEGER
	)";
$a->exec($SQLcommand);

$SQLcommand = "CREATE TABLE females
	(
	label TEXT PRIMARY KEY,
	labelCase TEXT NOT NULL,
	accessCount INTEGER
	)";
$a->exec($SQLcommand);

$SQLcommand = "CREATE TABLE pasttense
	(
	label TEXT PRIMARY KEY,
	accessCount INTEGER
	)";
$a->exec($SQLcommand);
