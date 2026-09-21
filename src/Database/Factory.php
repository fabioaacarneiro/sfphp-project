<?php

namespace SfphpProject\src\Database;

abstract class Factory
{
    protected int $count = 1;
    protected array $overrides = [];

    public function count(int $count): static
    {
        $this->count = $count;
        return $this;
    }

    public function make(array $attributes = []): array|object
    {
        $overrides = array_merge($this->overrides, $attributes);
        $data = $this->definition();

        foreach ($overrides as $key => $value) {
            $data[$key] = $value;
        }

        return $this->count === 1 ? $data : $this->createMany($data);
    }

    public function create(array $attributes = []): array|object
    {
        $data = $this->make($attributes);
        $model = $this->model();

        if (is_array($data)) {
            return $model::create($data);
        }

        $results = [];
        foreach ($data as $item) {
            $results[] = $model::create($item);
        }
        return $results;
    }

    protected function createMany(array $template): array
    {
        $results = [];
        for ($i = 0; $i < $this->count; $i++) {
            $results[] = $this->generateVariation($template);
        }
        return $results;
    }

    protected function generateVariation(array $template): array
    {
        $variation = $template;
        foreach ($variation as $key => $value) {
            if (is_callable($value)) {
                $variation[$key] = $value($this);
            }
        }
        return $variation;
    }

    abstract public function definition(): array;

    abstract protected function model(): string;
}
