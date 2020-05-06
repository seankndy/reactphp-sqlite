<?php

// This child worker process will be started by the main process to start communication over process pipe I/O
//
// Communication happens via newline-delimited JSON-RPC messages, see:
// $ php res/sqlite-worker.php
// < {"id":0,"method":"open","params":["test.db"]}
// > {"id":0,"result":true}
//
// Or via socket connection (used for Windows, which does not support non-blocking process pipe I/O)
// $ nc localhost 8080
// $ php res/sqlite-worker.php localhost:8080

use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;
use React\EventLoop\Factory;
use React\Stream\DuplexResourceStream;
use React\Stream\ReadableResourceStream;
use React\Stream\ThroughStream;
use React\Stream\WritableResourceStream;

if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    // local project development, go from /res to /vendor
    require __DIR__ . '/../vendor/autoload.php';
} else {
    // project installed as dependency, go upwards from /vendor/clue/reactphp-sqlite/res
    require __DIR__ . '/../../../autoload.php';
}

Clue\React\SQLite\runWorker();
