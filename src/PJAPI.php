<?php

namespace PJAPI;

use Exception;
use Throwable;
use Iterator;
use ReflectionClass;
use stdClass;
use Generator;
use ArrayIterator;

const JAPI_VERSION = 100;
const ERR_BAD_REQUEST = 400;
const ERR_INTERNAL = 500;

class PJAPIIterator implements Iterator {
    private string $routeDirectory;
    private object|array $payload;
    private int $step;
    private array $keys = [];
    private array $namespaces = [];
    private PJAPI $pjapi;

    public function __construct(string $routeDirectory, object|array $payload, PJAPI $pjapi) {
        $this->routeDirectory = $routeDirectory;
        $this->payload = $payload;
        $this->step = 0;
        $this->keys = array_keys(get_object_vars($payload));
        $this->namespaces = [];
        $this->pjapi = $pjapi;
    }

    private function loadRouteFile (string $file) {
        if (is_readable($file) === false) {
            throw new Exception('Invalid namespace', ERR_BAD_REQUEST);
        }

        $result = call_user_func_array(function ($file, $env) {
            extract($env);
            return require_once $file;
        }, [$file, ['PJAPI' => $this->pjapi, 'PJAPILoader' => true]]);
        if (is_object($result) === false) {
            throw new Exception('Invalid namespace', ERR_BAD_REQUEST);
        }
        return $result;
    }

    private function helpOperation (stdClass $request, object $ns) {
        if (!str_starts_with($request->function, 'HELP:')) { return false; }
        $operation = substr($request->function, 5);
        $help = new stdClass();
        $help->function =  $operation;
        $help->args = [];
        $help->return = 'void';

        $reflection = new ReflectionClass(get_class($ns));
        if (!$reflection->hasMethod($operation)) {
            $help->function = 'Invalid operation';
            return $help;
        }
        $reflection = $reflection->getMethod($operation);
        $neededArgs = $reflection->getParameters();
        foreach ($neededArgs as $arg) {
            $help->args = [
                'name' => $arg->name,
                'type' => strval($arg->getType()),
                'optional' => $arg->isOptional()
            ];
        }
        $help->return = strval($reflection->getReturnType());
        return [$this->keys[$this->step], $help];
    }

    private function helpNS (stdClass $request, object $ns) {
        if ($request->function !== 'HELP') { return false; }
        $help = new stdClass();
        $help->functions = [];
        $reflection = new ReflectionClass(get_class($ns));
        $methods = $reflection->getMethods();
        foreach ($methods as $method) {
            if (!$method->isPublic() || $method->name === '__construct' || $method->name === '__destruct') {
                continue;
            }
            $help->functions[] = $method->name;
        }
        return [$this->keys[$this->step], $help];
    }

    public function current():mixed {
        try {
            if (preg_match('/^[[:alnum:]:\.\-_]+/', $this->keys[$this->step]) === 0) {
                throw new Exception('Invalid id ' . $this->keys[$this->step], ERR_BAD_REQUEST);
            }
            if (!isset($this->payload->{$this->keys[$this->step]}->ns)
                    || preg_match('/^[[:alnum:]][[:alnum:]_]+/', $this->payload->{$this->keys[$this->step]}->ns) === 0) {
                throw new Exception('Invalid namespace', ERR_BAD_REQUEST);
            }
            if (!isset($this->payload->{$this->keys[$this->step]}->function)
                    || preg_match('/^[[:alnum:]][[:alnum:]_\:]+/', $this->payload->{$this->keys[$this->step]}->ns) === 0) {
                throw new Exception('Invalid operation', ERR_BAD_REQUEST);
            }

            $namespace = $this->payload->{$this->keys[$this->step]}->ns;
            $ns = null;
            if (isset($this->namespaces[$namespace])) {
                $ns = $this->namespaces[$namespace];
            } else {
                $ns = $this->loadRouteFile($this->routeDirectory . '/' . $namespace . '.php');
                $this->namespaces[$namespace] = $ns;
            }

            $isHelpNS = $this->helpNS($this->payload->{$this->keys[$this->step]}, $ns);
            if ($isHelpNS !== false) {
                return $isHelpNS;
            }

            $isHelpOperation = $this->helpOperation($this->payload->{$this->keys[$this->step]}, $ns);
            if ($isHelpOperation !== false) {
                return $isHelpOperation;
            }
            $operation = $this->payload->{$this->keys[$this->step]}->function;
 
            $reflection = new ReflectionClass(get_class($ns));
            if (!$reflection->hasMethod($operation)) {
                throw new Exception('Invalid operation', ERR_BAD_REQUEST);
            }
            $functionArgs = array_map(
                function($arg) {
                    return [
                        'name' => $arg->name,
                        'optional' => ($arg->isOptional() || $arg->isDefaultValueAvailable() || $arg->isVariadic()) ? true : false
                    ];
                },
                $reflection->getMethod($operation)->getParameters()
            );
            $args = [];
            if (count($functionArgs) > 0) {
                foreach ($functionArgs as $arg) {
                    /* optional argument, it's not set, skip it */
                    if (
                        $arg['optional']
                        && !isset($this->payload->{$this->keys[$this->step]}->arguments->{$arg['name']}) 
                    )
                    {
                        continue;
                    }
                    /* not optional and not set, throw an error */
                    if (
                        $arg['optional'] === false
                        && !isset($this->payload->{$this->keys[$this->step]}->arguments->{$arg['name']})
                    ) {
                        throw new Exception('Missing argument ' . $arg['name'] . ' ' . $operation, ERR_BAD_REQUEST);
                    }
                    /* argument is set, so set it */
                    $args[$arg['name']] = $this->payload->{$this->keys[$this->step]}->arguments->{$arg['name']};
                }
            }

            return [$this->keys[$this->step], call_user_func_array([$ns, $operation], $args)];
        } catch (Throwable $e) {
            return [$this->keys[$this->step], $e];
        }
    }

    public function key():mixed {
        return $this->step;
    }

    public function next():void {
        $this->step++;
    }

    public function rewind():void {
        $this->step = 0;
    }

    public function valid(): bool {
        if ($this->step >= count($this->keys)) {
            return false;
        }
        return true;
    }
}

class PJAPI {
    /* as it's converted to base64 use multiple of 3 to avoid padding. The 
     * default pad char is = which has meaning in HTTP, even if it poses no 
     * problem to have = char in boundary.
     */
    const BOUNDARY_RAND_BYTES = 9;
    const CHUNK_SIZE = 4096;
    protected Request $request;
    protected string $routeDirectory;
    protected PJAPIIterator $iterator;
    private string $boundary;
    private array $partbuffer;
    private int $partbuffer_count;

    public function __construct(string $routeDirectory) {
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
        $this->boundary = 'PJAPI-' . base64_encode(random_bytes(self::BOUNDARY_RAND_BYTES));
        $this->partbuffer = [];
        $this->partbuffer_count = 0;
    }

    public function printf(int $stream, string $string, ...$format)
    {
        $this->print($stream, vsprintf($string, $format));
    }

    public function print (int $stream, string $string)
    {
        if (!isset($this->partbuffer[$stream])) { return false; }
        $this->partbuffer[$stream] .= $string;
        if (strlen($this->partbuffer[$stream]) >= self::CHUNK_SIZE) {
            $this->flushStream($stream);
        }
        return true;
    }

    public function flushStream(int $stream) {
        if (!isset($this->partbuffer[$stream])) { return; }
        echo $this->partbuffer[$stream];
        $this->partbuffer[$stream] = '';
        flush();
    }

    protected function emitError (int $stream, Throwable $e) {
        $this->printf($stream, '{"error":true, "message": %s, "version": %d, "code": %d}', json_encode($e->getMessage()), JAPI_VERSION, $e->getCode());
        error_log($e->getMessage() . ' ' . $e->getFile() . ' ' . $e->getLine() . PHP_EOL . $e->getTraceAsString());
        for ($e = $e->getPrevious(); $e !== null; $e = $e->getPrevious()) {
            error_log($e->getMessage() . ' ' . $e->getFile() . ' ' . $e->getLine() . PHP_EOL . $e->getTraceAsString());
        }
    }

    public function openStream($type = 'application/json; charset=utf-8'): int
    {
        $current_part = $this->partbuffer_count++;
        $this->partbuffer[$current_part] = 
            "--" . $this->boundary . "\r\n"
            . 'Content-Type: ' . $type . "\r\n\r\n";
        
        return $current_part;
    }

    public function openTextStream():int {
        return $this->openStream('plain/text; charset=utf-8');
    }

    public function openJsonStream():int {
        return $this->openStream('application/json; charset=utf-8');
    }

    public function closeStream(int $stream) {
        if (!isset($this->partbuffer[$stream])) { return; }
        $this->partbuffer[$stream] .= "\r\n";
        $this->flushStream($stream);
        unset($this->partbuffer[$stream]);
    }

    public function closeRequest()
    {
        echo '--' . $this->boundary . "--\r\n";
        flush();
        fastcgi_finish_request();
    }

    public function init () {
        try {
            header('Content-Type: multipart/mixed; boundary=--' . $this->boundary, true);
            $this->request = new Request();
            $payload = $this->request->getPayload();
            $this->iterator = new PJAPIIterator($this->routeDirectory, $payload, $this);
        } catch (Exception $e) {
            $stream = $this->openJsonStream();
            $this->emitError($stream, $e);
            $this->closeStream($stream);
            $this->closeRequest($stream);
            exit;
        }
    }

    public function run () {
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
                            $encoded = json_encode($item[1]->current());
                            if ($encoded === false) {
                                $item[1]->next();
                                error_log('Invalid JSON encoding ' . json_last_error_msg());
                                $doPrint = false;
                                continue;
                            }
                            $this->print($streamid, $encoded);
                            $item[1]->next();
                        } while($item[1]->valid() && ($doPrint ? $this->print($streamid, ',') : true));
                        $this->print($streamid, ']}}');
                        $this->closeStream($streamid);
                    } else {
                        $streamid = $this->openJsonStream();
                        $this->printf($streamid, '{"%s": {"error": false, "result": []}}', $item[0]);
                        $this->closeStream($streamid);
                    }
                } else {
                    $streamid = $this->openJsonStream();
                    $encoded = json_encode($item[1]);
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