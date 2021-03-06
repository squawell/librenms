#!/usr/bin/env php
<?php

/*
 * LibreNMS
 *
 * Copyright (c) 2014 Neil Lathwood <https://github.com/laf/ http://www.lathwood.co.uk/fa>
 *
 * This program is free software: you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the
 * Free Software Foundation, either version 3 of the License, or (at your
 * option) any later version.  Please see LICENSE.txt at the top level of
 * the source code distribution for details.
 */

use LibreNMS\Config;
use LibreNMS\Validator;

chdir(__DIR__); // cwd to the directory containing this script

require_once 'includes/common.php';
require_once 'includes/functions.php';
require_once 'includes/dbFacile.php';

$options = getopt('g:m:s::h::');

if (isset($options['h'])) {
    echo
        "\n Validate setup tool

    Usage: ./validate.php [-g <group>] [-s] [-h]
        -h This help section.
        -s Print the status of each group
        -g Any validation groups you want to run, comma separated:
          Non-default groups:
          - mail: this will test your email settings  (uses default_mail option even if default_only is not set)
          - distributedpoller: this will test for the install running as a distributed poller
          - rrdcheck: this will check to see if your rrd files are corrupt
          Default groups:
          - configuration: checks various config settings are correct
          - database: checks the database for errors
          - disk: checks for disk space and other disk related issues
          - php: check that various PHP modules and functions exist
          - poller: check that the poller and discovery are running properly
          - programs: check that external programs exist and are executable
          - updates: checks the status of git and updates
          - user: check that the LibreNMS user is set properly

        Example: ./validate.php -g mail.

        "
    ;
    exit;
}


// Buffer output
ob_start();
$precheck_complete = false;
register_shutdown_function(function () {
    global $precheck_complete;

    if (!$precheck_complete) {
        print_header(version_info());
    }
});

// critical config.php checks
if (!file_exists('config.php')) {
    print_fail('config.php does not exist, please copy config.php.default to config.php');
    exit;
}

$pre_checks_failed = false;
$syntax_check = `php -ln config.php`;
if (!str_contains($syntax_check, 'No syntax errors detected')) {
    print_fail('Syntax error in config.php');
    echo $syntax_check;
    $pre_checks_failed = true;
}

$first_line = rtrim(`head -n1 config.php`);
if (!starts_with($first_line, '<?php')) {
    print_fail("config.php doesn't start with a <?php - please fix this ($first_line)");
    $pre_checks_failed = true;
}
if (str_contains(`tail config.php`, '?>')) {
    print_fail("Remove the ?> at the end of config.php");
    $pre_checks_failed = true;
}

// Composer checks
if (!file_exists('vendor/autoload.php')) {
    print_fail('Composer has not been run, dependencies are missing', 'composer install --no-dev');
    $pre_checks_failed = true;
}

if (!str_contains(shell_exec('php scripts/composer_wrapper.php --version'), 'Composer version')) {
    print_fail("No composer available, please install composer", "https://getcomposer.org/");
    $pre_checks_failed = true;
}

$dep_check = shell_exec('php scripts/composer_wrapper.php install --no-dev --dry-run');
preg_match_all('/Installing ([^ ]+\/[^ ]+) \(/', $dep_check, $dep_missing);
if (!empty($dep_missing[0])) {
    print_fail("Missing dependencies!", "composer install --no-dev");
    $pre_checks_failed = true;
    print_list($dep_missing[1], "\t %s\n");
}
preg_match_all('/Updating ([^ ]+\/[^ ]+) \(/', $dep_check, $dep_outdated);
if (!empty($dep_outdated[0])) {
    print_fail("Outdated dependencies", "composer install --no-dev");
    print_list($dep_outdated[1], "\t %s\n");
}

if ($pre_checks_failed) {
    exit;
}

$init_modules = array('nodb');
require 'includes/init.php';

// make sure install_dir is set correctly, or the next includes will fail
if (!file_exists(Config::get('install_dir').'/config.php')) {
    $suggested = realpath(__DIR__ . '/../..');
    print_fail('$config[\'install_dir\'] is not set correctly.', "It should probably be set to: $suggested");
    exit;
}

$validator = new Validator();


// Connect to MySQL
try {
    dbConnect();

    // pull in the database config settings
    mergedb();
    require 'includes/process_config.inc.php';

    $validator->ok('Database connection successful', null, 'database');
} catch (\LibreNMS\Exceptions\DatabaseConnectException $e) {
    $validator->fail('Error connecting to your database. '.$e->getMessage(), null, 'database');
}

$precheck_complete = true; // disable shutdown function
print_header($validator->getVersions());

if (isset($options['g'])) {
    $modules = explode(',', $options['g']);
} elseif (isset($options['m'])) {
    $modules = explode(',', $options['m']); // backwards compat
} else {
    $modules = array(); // all modules
}

// run checks
$validator->validate($modules, isset($options['s'])||!empty($modules));


function print_header($versions)
{
    $output = ob_get_clean();
    ob_end_clean();

    echo <<< EOF
====================================
Component | Version
--------- | -------
LibreNMS  | ${versions['local_ver']}
DB Schema | ${versions['db_schema']}
PHP       | ${versions['php_ver']}
MySQL     | ${versions['mysql_ver']}
RRDTool   | ${versions['rrdtool_ver']}
SNMP      | ${versions['netsnmp_ver']}
====================================

$output
EOF;
}

// output matches that of ValidationResult
function print_fail($msg, $fix = null)
{
    c_echo("[%RFAIL%n]  $msg");
    if ($fix && strlen($msg) > 72) {
        echo PHP_EOL . "       ";
    }

    if (!empty($fix)) {
        c_echo(" [%BFIX%n] %B$fix%n");
    }
    echo PHP_EOL;
}
