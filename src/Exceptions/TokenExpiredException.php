<?php
/**
 * Created by PhpStorm.
 * User: bahasolaptop2
 * Date: 19/09/19
 * Time: 10:17
 */

namespace EdwinFadilah\Passport\Exceptions;


class TokenExpiredException extends HttpRequestException
{
    public function __construct($message = "Token is already expired", array $errors = [], $data = null, \Exception $previous = null, array $headers = [], $code = 0)
    {
        parent::__construct(401, $message, $errors, $data, $previous, $headers, $code);
    }
}