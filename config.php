<?php
/*
 * simple-file-manager
 *
 * settings and config file are optional
 */

$base_dir = ''; // set base directory, something like '/.git'

$allow_delete = true; // set false to disable delete button and delete POST request
$allow_upload = true; // set true to allow upload files
$allow_edit = true; // set true to allow edit files
$allow_create_folder = true; // set false to disable folder creation
$allow_direct_link = true; // set false to only allow downloads and not direct link
$allow_show_folders = true; // set false to hide all subdirectories

$disallowed_patterns = ['*.php'];  // matching files not allowed to be uploaded
$hidden_patterns = ['*.php','.*']; // matching files hidden in directory index

$datetime_format = 'Y-m-d H:i:s'; // default is ISO8601 style, possible parameters see https://www.php.net/manual/en/datetime.format.php
$jquery_url = '//code.jquery.com/jquery-3.6.0.min.js'; // set your own jquery url, to prevent external dependency

$PASSWORD = '';  // set the password, to access the file manager...
