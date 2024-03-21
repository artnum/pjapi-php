<?php

namespace PJAPI;

use Exception;
use stdClass;

class Request
{
    protected string $reqid;
    protected int $version;
    protected array|stdClass $payload;
    protected stdClass|null $body = null;

    function __construct()
    {
        try {
            if (!isset($_SERVER['CONTENT_TYPE']) || $_SERVER['CONTENT_TYPE'] !== 'application/json') {
                throw new Exception('Invalid content type');
            }
            if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
                throw new Exception('Invalid protocol');
            }
      
            $content = file_get_contents('php://input');
            $body = json_decode($content);
            if (!isset($body->version)
                    || empty($body->version)
                    || !is_numeric($body->version)) {
                throw new Exception('Missing version');
            }
            $this->version = intval($body->version);

            if (!isset($body->payload)
                    && empty($body->payload)
                    && !is_object($body->payload)) {
                throw new Exception('Missing payload');
            }
            $this->payload = $body->payload;
        } catch (Exception $e) {
            throw new Exception('Invalid request', 400 , $e);
        }
    }

    function getPayload (): array|stdClass
    {
        return $this->payload;
    }

    function getVersion (): int
    {
        return $this->version;
    }

}
