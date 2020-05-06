<?php

namespace Clue\React\SQLite;

use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;
use React\EventLoop\Factory;
use React\Stream\DuplexResourceStream;
use React\Stream\ReadableResourceStream;
use React\Stream\ThroughStream;
use React\Stream\WritableResourceStream;

if (!function_exists('runWorker')) {
    function runWorker($sockAddr = null) {
        $loop = Factory::create();

        if ($sockAddr) {
            // socket address given, so try to connect through socket (Windows)
            $socket = stream_socket_client($sockAddr);
            $stream = new DuplexResourceStream($socket, $loop);

            // pipe input through a wrapper stream so that an error on the input stream
            // will not immediately close the output stream without a chance to report
            // this error through the output stream.
            $through = new ThroughStream();
            $stream->on('data', function ($data) use ($through) {
                $through->write($data);
            });

            $in = new Decoder($through);
            $out = new Encoder($stream);
        } else {
            // no socket address given, use process I/O pipes
            $in = new Decoder(new ReadableResourceStream(\STDIN, $loop));
            $out = new Encoder(new WritableResourceStream(\STDOUT, $loop));
        }

        // report error when input is invalid NDJSON
        $in->on('error', function (Exception $e) use ($out) {
            $out->end(array(
                'error' => array(
                    'code' => -32700, // parse error
                    'message' => 'input error: ' . $e->getMessage()
                )
            ));
        });

        $db = null;
        $in->on('data', function ($data) use (&$db, $in, $out) {
            if (!isset($data->id, $data->method, $data->params) || !\is_scalar($data->id) || !\is_string($data->method) || !\is_array($data->params)) {
                // input is valid JSON, but not JSON-RPC => close input and end output with error
                $in->close();
                $out->end(array(
                    'error' => array(
                        'code' => -32600, // invalid message
                        'message' => 'malformed message'
                    )
                ));
                return;
            }

            if ($data->method === 'open' && \count($data->params) === 1 && \is_string($data->params[0])) {
                // open database with one parameter: $filename
                try {
                    $db = new \SQLite3(
                        $data->params[0]
                    );

                    $out->write(array(
                        'id' => $data->id,
                        'result' => true
                    ));
                } catch (Exception $e) {
                    $out->write(array(
                        'id' => $data->id,
                        'error' => array('message' => $e->getMessage())
                    ));
                }
            } elseif ($data->method === 'open' && \count($data->params) === 2 && \is_string($data->params[0]) && \is_int($data->params[1])) {
                // open database with two parameters: $filename, $flags
                try {
                    $db = new \SQLite3(
                        $data->params[0],
                        $data->params[1]
                    );

                    $out->write(array(
                        'id' => $data->id,
                        'result' => true
                    ));
                } catch (Exception $e) {
                    $out->write(array(
                        'id' => $data->id,
                        'error' => array('message' => $e->getMessage())
                    ));
                }
            } elseif ($data->method === 'exec' && $db !== null && \count($data->params) === 1 && \is_string($data->params[0])) {
                // execute statement and suppress PHP warnings
                $ret = @$db->exec($data->params[0]);

                if ($ret === false) {
                    $out->write(array(
                        'id' => $data->id,
                        'error' => array('message' => $db->lastErrorMsg())
                    ));
                } else {
                    $out->write(array(
                        'id' => $data->id,
                        'result' => array(
                            'insertId' => $db->lastInsertRowID(),
                            'changed' => $db->changes()
                        )
                    ));
                }
            } elseif ($data->method === 'query' && $db !== null && \count($data->params) === 2 && \is_string($data->params[0]) && (\is_array($data->params[1]) || \is_object($data->params[1]))) {
                // execute statement and suppress PHP warnings
                if ($data->params[1] === []) {
                    $result = @$db->query($data->params[0]);
                } else {
                    $statement = @$db->prepare($data->params[0]);
                    if ($statement === false) {
                        $result = false;
                    } else {
                        foreach ($data->params[1] as $index => $value) {
                            if ($value === null) {
                                $type = \SQLITE3_NULL;
                            } elseif ($value === true || $value === false) {
                                // explicitly cast bool to int because SQLite does not have a native boolean
                                $type = \SQLITE3_INTEGER;
                                $value = (int)$value;
                            } elseif (\is_int($value)) {
                                $type = \SQLITE3_INTEGER;
                            } elseif (isset($value->float)) {
                                $type = \SQLITE3_FLOAT;
                                $value = (float)$value->float;
                            } elseif (isset($value->base64)) {
                                // base64-decode string parameters as BLOB
                                $type = \SQLITE3_BLOB;
                                $value = \base64_decode($value->base64);
                            } else {
                                $type = \SQLITE3_TEXT;
                            }

                            $statement->bindValue(
                                \is_int($index) ? $index + 1 : $index,
                                $value,
                                $type
                            );
                        }
                        $result = @$statement->execute();
                    }
                }

                if ($result === false) {
                    $out->write(array(
                        'id' => $data->id,
                        'error' => array('message' => $db->lastErrorMsg())
                    ));
                } else {
                    if ($result->numColumns() !== 0) {
                        // Fetch all rows only if this result set has any columns.
                        // INSERT/UPDATE/DELETE etc. do not return any columns, trying
                        // to fetch the results here will issue the same query again.
                        $rows = $columns = [];
                        for ($i = 0, $n = $result->numColumns(); $i < $n; ++$i) {
                            $columns[] = $result->columnName($i);
                        }

                        while (($row = $result->fetchArray(\SQLITE3_ASSOC)) !== false) {
                            // base64-encode any string that is not valid UTF-8 without control characters (BLOB)
                            foreach ($row as &$value) {
                                if (\is_string($value) && \preg_match('/[\x00-\x08\x11\x12\x14-\x1f\x7f]/u', $value) !== 0) {
                                    $value = ['base64' => \base64_encode($value)];
                                } elseif (\is_float($value)) {
                                    $value = ['float' => $value];
                                }
                            }
                            $rows[] = $row;
                        }
                    } else {
                        $rows = $columns = null;
                    }
                    $result->finalize();

                    $out->write(array(
                        'id' => $data->id,
                        'result' => array(
                            'columns' => $columns,
                            'rows' => $rows,
                            'insertId' => $db->lastInsertRowID(),
                            'changed' => $db->changes()
                        )
                    ));
                }
            } elseif ($data->method === 'close' && $db !== null && \count($data->params) === 0) {
                // close database and remove reference
                $db->close();
                $db = null;

                $out->write(array(
                    'id' => $data->id,
                    'result' => null
                ));
            } else {
                // no matching method found => report soft error and keep stream alive
                $out->write(array(
                    'id' => $data->id,
                    'error' => array(
                        'code' => -32601, // invalid method
                        'message' => 'invalid method call'
                    )
                ));
            }
        });

        $loop->run();
    }
}
