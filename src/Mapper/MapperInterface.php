<?php

namespace Fluxx\Mapper;

interface MapperInterface
{
    public static function getDefinition(): string;
    public function treat(string|array $string): string;
}