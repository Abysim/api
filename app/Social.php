<?php
/**
 * @package App
 * @author Abysim <abysim@whitelion.me>
 */

namespace App;

abstract class Social
{
    /**
     * @param string $text
     * @param array $media
     */
    abstract public function post(string $text, array $media = []): mixed;
}
