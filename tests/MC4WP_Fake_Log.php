<?php

/** @ignore */
class MC4WP_Fake_Log
{
    public $errors = [];

    public function error($message)
    {
        $this->errors[] = $message;
    }
}

/**
 * Class TrackingPixelTest
 *
 * @ignore
 */
