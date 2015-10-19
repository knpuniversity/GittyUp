<?php

namespace KnpU\GittyUp;

class GitException extends \Exception
{
    /**
     * Does this error represent a merge conflict?
     *
     * @return bool
     */
    public function isConflict()
    {
        return strpos($this->getMessage(), 'CONFLICT') !== false;
    }
}