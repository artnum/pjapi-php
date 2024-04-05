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
            $help->args = ['name' => $arg->name, 'type' => strval($arg->getType()), 'optional' => $arg->isOptional()];
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
            $neededArgs = $reflection->getMethod($operation)->getParameters();
            $args = [];
            if (count($neededArgs) > 0) {
                if (!isset($this->payload->{$this->keys[$this->step]}->arguments)) {
                    throw new Exception('Missing arguments ' . $operation, ERR_BAD_REQUEST);
                }
                foreach ($neededArgs as $arg) {
                    if (!isset($this->payload->{$this->keys[$this->step]}->arguments->{$arg->name})) {
                        throw new Exception('Missing argument ' . $arg->name . ' ' . $operation, ERR_BAD_REQUEST);
                    }
                    $args[$arg->name] = $this->payload->{$this->keys[$this->step]}->arguments->{$arg->name};
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
    protected Request $request;
    protected string $routeDirectory;
    protected PJAPIIterator $iterator;
    private string $boundary;

    public function __construct(string $routeDirectory) {
        if (is_dir($routeDirectory) === false) {
            throw new Exception('Invalid route directory', ERR_BAD_REQUEST);
        }
        if (is_readable($routeDirectory) === false) {
            throw new Exception('Invalid route directory', ERR_BAD_REQUEST);
        }
        $this->routeDirectory = $routeDirectory;
        $this->boundary = 'PJAPI' . md5(uniqid());

    }

    public function init () {
        try {
            header('Content-Type: multipart/mixed; boundary=--' . $this->boundary, true);
            $this->request = new Request();
            $payload = $this->request->getPayload();
            $this->iterator = new PJAPIIterator($this->routeDirectory, $payload, $this);
        } catch (Exception $e) {
            $this->startPart(true);
            $this->emitError($e);
            $this->endPart();
            exit;
        }
    }

    protected function emitError (Throwable $e) {
        printf('{"error":true, "message": %s, "version": %d, "code": %d}', json_encode($e->getMessage()), JAPI_VERSION, $e->getCode());
        error_log($e->getMessage() . ' ' . $e->getFile() . ' ' . $e->getLine() . PHP_EOL . $e->getTraceAsString());
        for ($e = $e->getPrevious(); $e !== null; $e = $e->getPrevious()) {
            error_log($e->getMessage() . ' ' . $e->getFile() . ' ' . $e->getLine() . PHP_EOL . $e->getTraceAsString());
        }
    }

    public function startPart (bool $last = false) {
        echo '--' . $this->boundary . ($last ? "--\r\n" : "\r\n") ;
        if ($last) { return; }
        echo 'Content-Type: application/json; charset=utf-8' . "\r\n";
        echo "\r\n";
    }

    public function endPart () {
        echo "\r\n";
    }

    public function run () {
            $success = 0;
            $error = 0;
            try {
                foreach ($this->iterator as $item) {
                    if ($item[1] instanceof Throwable) {
                        $error++;
                        $this->startPart();
                        echo '{"' . $item[0] . '":';
                        $this->emitError($item[1]);
                        echo '}';
                        $this->endPart();
                        continue;
                    }
                    if ($item[1] instanceof Generator 
                            || $item[1] instanceof Iterator
                            || is_array($item[1])) {
                        if (is_array($item[1])) {
                            $item[1] = new ArrayIterator($item[1]);
                        }
                        if ($item[1]->valid()) {
                            $this->startPart();
                            printf('{"%s": {"error": false, "result": [', $item[0]);    
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
                                print($encoded);
                                $item[1]->next();
                            } while($item[1]->valid() && ($doPrint ? print(',') : true));
                            echo ']}}';
                            $this->endPart();
                        } else {
                            $this->startPart();
                            printf('{"%s": {"error": false, "result": []}}', $item[0]);
                            $this->endPart();
                        }
                    } else {
                        $this->startPart();
                        $encoded = json_encode($item[1]);
                        if ($encoded === false) {
                            error_log('Invalid JSON encoding ' . json_last_error_msg());
                            $this->emitError(new Exception('Invalid JSON encoding', ERR_INTERNAL));
                            $this->endPart();
                            continue;
                        }

                        printf('{"%s": {"error": false, "result": %s}}', $item[0], $encoded);
                        $this->endPart();
                    }
                    $success++;
                }
            } catch (Throwable $e) {
                $this->startPart();
                $this->emitError($e);
                $this->endPart();
            }
            $this->startPart(true);
        }
}