<?php
/*
 * the follwong settings and config file itself are all optional
 */

$base_dir = ''; // set base directory, something like '/.git'

$allow_delete = true; // set to false to disable delete button and delete POST request
$allow_upload = true; // set to true to allow upload files
$allow_create_folder = true; // set to false to disable folder creation
$allow_direct_link = true; // set to false to only allow downloads and not direct link
$allow_show_folders = true; // set to false to hide all subdirectories

$disallowed_patterns = ['*.php'];  // matching files not allowed to be uploaded
$hidden_patterns = ['*.php','.*']; // matching files hidden in directory index

$datetime_format = 'Y-m-d H:i:s'; // default is ISO8601, possible paramters are https://www.php.net/manual/en/datetime.format.php
$jquery_url = '//code.jquery.com/jquery-3.6.0.min.js'; // set your own jquery url, to prevent external dependency

$PASSWORD = '';  // set the password, to access the file manager...
