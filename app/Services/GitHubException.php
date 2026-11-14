<?php

namespace App\Services;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Something GitHub said no to, or couldn't be reached; the message is meant for the user. */
class GitHubException extends HttpException
{
    public function __construct(string $message, int $status = 422)
    {
        parent::__construct($status, $message);
    }
}
