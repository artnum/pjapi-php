<?php

namespace PJAPI;

use Exception;
use Throwable;
use Iterator;
use stdClass;
use Generator;
use ArrayIterator;

const JAPI_VERSION = 100;
/* ues http error value, can fit into legacy code */
const ERR_BAD_REQUEST = 400;
const ERR_INTERNAL = 500;
const ERR_FORBIDDEN = 403;
const ERR_DUPLICATE = 409;


class PJAPI
{
    /* as it's converted to base64 use multiple of 3 to avoid padding. The
     * default pad char is = which has meaning in HTTP, even if it poses no
     * problem to have = char in boundary.
     */
    public const BOUNDARY_RAND_BYTES = 9;
    protected Request $request;
    protected string $routeDirectory;
    protected PJAPIIterator $iterator;
    private string $boundary;
    private array $partbuffer;
    private int $partbuffer_count;
    private bool $debug;

    /**
     * Class constructor, obviously, it initialize stuff
     *
     * @param $routeDirectory Path to where all routes are storede. It's
     *                        one file per route. A route is a namepsace
     *                        that provides any number of function that
     *                        can be called.
     * @param $debug          Is in debug mode
     */
    public function __construct(string $routeDirectory, bool $debug = false)
    {
        $this->debug = $debug;
        if (is_dir($routeDirectory) === false) {
            throw new Exception('Invalid route directory', ERR_BAD_REQUEST);
        }
        if (is_readable($routeDirectory) === false) {
            throw new Exception('Invalid route directory', ERR_BAD_REQUEST);
        }
        $this->routeDirectory = $routeDirectory;
        /* According to my testing on my machine :
         * - random_bytes is faster than uniqid
         * - for some reason, random_bytes run x2 faster on PHP ZTS than non-ZTS
         * PHP.
         * - for some reason, base64_encode is a bit faster than bin2hex.
         */
        $this->boundary = 'PJAPI-' . base64_encode(
            random_bytes(self::BOUNDARY_RAND_BYTES)
        );
        $this->partbuffer = [];
        $this->partbuffer_count = 0;
    }

    /**
     * Print a formated string, use vsprintf.
     *
     * @param int    $stream    The stream to print out
     * @param string $string    The string to print with formatting tag
     * @param mixed  ...$format Variable arguments
     *
     * @return void
     */
    public function printf(int $stream, string $string, ...$format): void
    {
        $this->print($stream, vsprintf($string, $format));
    }

    public function print(int $stream, string $string): bool
    {
        if (!isset($this->partbuffer[$stream])) {
            return false;
        }
        $this->partbuffer[$stream] .= $string;
        return true;
    }

    /**
     * Emit an error to the client, also log the whole stack trace
     *
     * @param int       $stream The stream to receive the error.
     * @param Throwable $e      The exception to be sent
     *
     * @return void
     */
    protected function emitError(int $stream, Throwable $e): void
    {
        $message = 'An error occured';
        if ($this->debug) {
            $message = $e->getMessage();
        } elseif ($e instanceof UserFacingException) {
            $message = $e->getMessage();
        }
        $this->printf(
            $stream,
            '{"error":true, "message": %s, "version": %d, "code": %d}',
            json_encode($message),
            JAPI_VERSION,
            $e->getCode()
        );
        error_log(
            $e->getMessage()
            . ' '
            . $e->getFile()
            . ' '
            . $e->getLine()
            . PHP_EOL
            . $e->getTraceAsString()
        );
        for ($e = $e->getPrevious(); $e !== null; $e = $e->getPrevious()) {
            error_log(
                $e->getMessage()
                . ' '
                . $e->getFile()
                . ' '
                . $e->getLine()
                . PHP_EOL
                . $e->getTraceAsString()
            );
        }
    }

    public function openStream(string $type = 'application/json; charset=utf-8'): int
    {
        $current_part = $this->partbuffer_count++;
        $this->partbuffer[$current_part]
            = "--" . $this->boundary . "\r\n"
            . 'Content-Type: ' . $type . "\r\n\r\n";

        return $current_part;
    }

    public function openTextStream(): int
    {
        return $this->openStream('plain/text; charset=utf-8');
    }

    public function openJsonStream(): int
    {
        return $this->openStream('application/json; charset=utf-8');
    }

    public function closeStream(int $stream): void
    {
        if (!isset($this->partbuffer[$stream])) {
            return;
        }
        echo $this->partbuffer[$stream] . "\r\n";
        unset($this->partbuffer[$stream]);
        flush();
    }

    public function closeRequest(): void
    {
        echo '--' . $this->boundary . "--\r\n";
        flush();
        fastcgi_finish_request();
    }

    public function init(mixed $context = null): void
    {
        try {
            header('Content-Type: multipart/mixed; boundary=--' . $this->boundary, true);
            $this->request = new Request();
            $payload = $this->request->getPayload();
            $this->iterator = new PJAPIIterator($this->routeDirectory, $payload, $this, $context);
        } catch (Exception $e) {
            $stream = $this->openJsonStream();
            $this->emitError($stream, $e);
            $this->closeStream($stream);
            $this->closeRequest($stream);
            exit;
        }
    }

    /**
     * Filter object, remove keys starting with _.
     *
     * @param stdClass $object The object to be filtered
     *
     * @return stdClass A filtered object
     */
    protected function filter(stdClass $object): stdClass
    {
        foreach ($object as $key => $value) {
            if (str_starts_with($key, '_')) {
                unset($object->key);
            } else {
                if (is_object($value)) {
                    $this->filter($value);
                }
            }
        }
        return $object;
    }

    /**
     * Filter and encode an object to JSON
     *
     * @param stdClass $object The object to be encoded
     *
     * @return string The encoded json object
     */
    protected function encode(stdClass $object): string
    {
        return json_encode($this->filter($object));
    }

    public function run(): void
    {
        $success = 0;
        $error = 0;
        try {
            foreach ($this->iterator as $item) {
                if ($item[1] instanceof Throwable) {
                    $error++;
                    $streamid = $this->openJsonStream();
                    $this->print($streamid, '{"' . $item[0] . '":');
                    $this->emitError($streamid, $item[1]);
                    $this->print($streamid, '}');
                    $this->closeStream($streamid);
                    continue;
                }
                if ($item[1] instanceof Generator
                        || $item[1] instanceof Iterator
                        || is_array($item[1])) {
                    if (is_array($item[1])) {
                        $item[1] = new ArrayIterator($item[1]);
                    }
                    if ($item[1]->valid()) {
                        $streamid = $this->openJsonStream();
                        $this->printf($streamid, '{"%s": {"error": false, "result": [', $item[0]);
                        do {
                            $doPrint = true;
                            /* in case of error in a single item, we skip it */
                            $encoded = $this->encode($item[1]->current());
                            if ($encoded === false) {
                                $item[1]->next();
                                error_log('Invalid JSON encoding ' . json_last_error_msg());
                                $doPrint = false;
                                continue;
                            }
                            $this->print($streamid, $encoded);
                            $item[1]->next();
                        } while ($item[1]->valid() && ($doPrint ? $this->print($streamid, ',') : true));
                        $this->print($streamid, ']}}');
                        $this->closeStream($streamid);
                    } else {
                        $streamid = $this->openJsonStream();
                        $this->printf($streamid, '{"%s": {"error": false, "result": []}}', $item[0]);
                        $this->closeStream($streamid);
                    }
                } else {
                    $streamid = $this->openJsonStream();
                    $encoded = $this->encode($item[1]);
                    if ($encoded === false) {
                        error_log('Invalid JSON encoding ' . json_last_error_msg());
                        $this->print($streamid, '{"' . $item[0] . '":');
                        $this->emitError($streamid, new Exception('Invalid JSON encoding', ERR_INTERNAL));
                        $this->print($streamid, '}');
                        $this->closeStream($streamid);
                        continue;
                    }

                    $this->printf($streamid, '{"%s": {"error": false, "result": %s}}', $item[0], $encoded);
                    $this->closeStream($streamid);
                }
                $success++;
            }
        } catch (Throwable $e) {
            $streamid = $this->openJsonStream();
            $this->emitError($streamid, $e);
            $this->closeStream($streamid);
        }
        $this->closeRequest();
    }
}
