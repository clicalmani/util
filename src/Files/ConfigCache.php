<?php
namespace Clicalmani\Util\Files;

class ConfigCache extends Cache
{
    protected $dir = '/framework/cache/config';
    protected string $prefix = 'config-';
}