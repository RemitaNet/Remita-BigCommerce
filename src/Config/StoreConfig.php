<?php

class StoreConfig
{
private array $config;

public function __construct()
{
$this->config = include __DIR__ . '/../../config.php';
}

public function get(string $key)
{
return $this->config[$key] ?? null;
}
}
