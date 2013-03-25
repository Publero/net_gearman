Net/Gearman
===========

[![Build Status](https://secure.travis-ci.org/Publero/net_gearman.png?branch=master)](http://travis-ci.org/Publero/net_gearman)

PHP library for interfacing with Danga's Gearman. Gearman is a system to farm out work to other machines,
dispatching function calls to machines that are better suited to do work, to do work in parallel, to load
balance lots of function calls, or to call functions between languages. 

Installation
------------

Add following line to your composer.json require
``` json
"publero/net_gearman": "1.0.x-dev"
``` 

You can use following command
``` sh
composer.phar require --dev publero/net_geaman:1.0.x
```

Examples
--------

### Client

``` php
<?php

$client = new \Net\Gearman\Client();
$client->addServer();

$callback = function ($func, $handle, $result) {
    echo $result;
};
$set = new Set();
$task = new Task('replace', 'PHP is best programming language!', uniqid());
$task->attachCallback($callback, Task::TASK_COMPLETE);
$set->addTask($task);
$client->runSet($set);
```

### Worker

``` php
<?php

$function = function($payload) {
    return str_replace('java', 'php', $payload);
};

$worker = new \Net\Gearman\Worker();
$worker->addServer();
$worker->addFunction('replace', $function);

$worker->work();
```
