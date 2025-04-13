<?php

namespace App\Container;

use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;
use Exception;
use Closure;

final class ServiceContainer implements ContainerInterface
{
    /** @var array<string, Closure> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];


    /**
     * Registers a service factory by ID
     * @param string $id
     * @param \Closure $factory
     * @return void
     */
    public function set(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    /**
     * Finds an entry of the container by its identifier and returns it.
     * 
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Psr\Container\ContainerExceptionInterface
     */
    public function get(string $id): mixed
    {
        if (!$this->has($id)) {
            throw new class($id) extends Exception implements NotFoundExceptionInterface {
                public function __construct(string $id)
                {
                    parent::__construct("Service '{$id}' not found in container.");
                }
            };
        }

        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        $factory = $this->factories[$id];
        try {
            $instance = $factory($this);
            $this->instances[$id] = $instance;
            return $instance;
        } catch (\Throwable $e) {
            throw new class($id, $e) extends Exception implements ContainerExceptionInterface {
                public function __construct(string $id, \Throwable $previous)
                { // Catch Throwable
                    parent::__construct("Error while creating service '{$id}': " . $previous->getMessage(), 0, $previous);
                }
            };
        }
    }

    /**
     * Returns true if the container can return an entry for the given identifier.
     */
    public function has(string $id): bool
    {
        return isset($this->factories[$id]);
    }
}
