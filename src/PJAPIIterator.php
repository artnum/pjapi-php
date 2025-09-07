<?php

namespace PJAPI;

use Exception;
use Iterator;
use stdClass;
use ReflectionClass;
use Throwable;

class PJAPIIterator implements Iterator
{
    private string $routeDirectory;
    private object|array $payload;
    private int $step;
    private array $keys = [];
    private array $namespaces = [];
    private PJAPI $pjapi;
    private mixed $context = null;

    /**
     * Construct an iterator
     *
     * @param $routeDirectory Route directory
     * @param $payload        Payload
     * @param $pjapi          API
     * @param $context        Context coming from user code.
     *
     * @return void
     */
    public function __construct(
        string $routeDirectory,
        object|array $payload,
        PJAPI $pjapi,
        mixed $context = null
    ) {
        $this->routeDirectory = $routeDirectory;
        $this->payload = $payload;
        $this->step = 0;
        $this->keys = array_keys(get_object_vars($payload));
        $this->namespaces = [];
        $this->pjapi = $pjapi;
        $this->context = $context;
    }

    private function loadRouteFile(string $file): mixed
    {
        if (is_readable($file) === false) {
            throw new Exception('Invalid namespace', ERR_BAD_REQUEST);
        }

        $result = call_user_func_array(
            function ($file, $env) {
                extract($env);
                return include_once $file;
            },
            [
                $file,
                [
                'PJAPI' => $this->pjapi,
                'PJAPILoader' => true,
                'AppContext' => $this->context
                ]
            ]
        );
        if (is_object($result) === false) {
            throw new Exception('Invalid namespace', ERR_BAD_REQUEST);
        }
        return $result;
    }

    /**
     * Get help on a specific operation
     *
     * @param $request The request
     * @param $ns      The namespace
     *
     * @return Current step and help info
     */
    private function helpOperation(
        stdClass $request,
        object $ns
    ): bool|stdClass|array {
        if (!str_starts_with($request->function, 'HELP:')) {
            return false;
        }
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

    private function helpNS(stdClass $request, object $ns): bool|array
    {
        if ($request->function !== 'HELP') {
            return false;
        }
        $help = new stdClass();
        $help->functions = [];
        $reflection = new ReflectionClass(get_class($ns));
        $methods = $reflection->getMethods();
        foreach ($methods as $method) {
            if (!$method->isPublic()
                || $method->name === '__construct'
                || $method->name === '__destruct'
            ) {
                continue;
            }
            $help->functions[] = $method->name;
        }
        return [$this->keys[$this->step], $help];
    }

    public function current(): mixed
    {
        try {
            if (preg_match(
                '/^[[:alnum:]:\.\-_]+/',
                $this->keys[$this->step]
            ) === 0
            ) {
                throw new Exception(
                    'Invalid id ' . $this->keys[$this->step],
                    ERR_BAD_REQUEST
                );
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
                $ns = $this->loadRouteFile(
                    $this->routeDirectory . '/' . $namespace . '.php'
                );
                $this->namespaces[$namespace] = $ns;
            }

            $isHelpNS = $this->helpNS(
                $this->payload->{$this->keys[$this->step]},
                $ns
            );
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
                function ($arg) {
                    return [
                        'name' => $arg->name,
                        'optional' => ($arg->isOptional()
                            || $arg->isDefaultValueAvailable()
                            || $arg->isVariadic()) ? true : false
                    ];
                },
                $reflection->getMethod($operation)->getParameters()
            );
            $args = [];
            if (count($functionArgs) > 0) {
                foreach ($functionArgs as $arg) {
                    /* optional argument, it's not set, skip it */
                    if ($arg['optional']
                        && !isset($this->payload->{$this->keys[$this->step]}->arguments->{$arg['name']})
                    ) {
                        continue;
                    }
                    /* not optional and not set, throw an error */
                    if ($arg['optional'] === false
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

    public function key(): mixed
    {
        return $this->step;
    }

    public function next(): void
    {
        $this->step++;
    }

    public function rewind(): void
    {
        $this->step = 0;
    }

    public function valid(): bool
    {
        if ($this->step >= count($this->keys)) {
            return false;
        }
        return true;
    }
}
