<?php

// the config file and the settings are optional

$base_dir = ''; // set base directory, something like '/.git'

$allow_delete = true; // set to false to disable delete button and delete POST request
$allow_upload = true; // set to true to allow upload files
$allow_create_folder = true; // set to false to disable folder creation
$allow_direct_link = true; // set to false to only allow downloads and not direct link
$allow_show_folders = true; // set to false to hide all subdirectories

$disallowed_patterns = ['*.php'];  // matching files not allowed to be uploaded
$hidden_patterns = ['*.php','.*']; // matching files hidden in directory index

$PASSWORD = '';  // set the password, to access the file manager...
