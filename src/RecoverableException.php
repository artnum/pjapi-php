<?php

namespace PJAPI;

use Exception;

/**
 * RecoverableException is designed to allow to pass message down to user,
 * when this exception is thrown from the backend, it won't be overriden
 * by the API.
 */
class RecoverableException extends Exception
{
}
