<?php

namespace Fluxx\Client;

final class HubspotLogEntry
{
    private array $messages;

    public function __construct(
        private readonly string $source,
        private readonly string $sourceMore,
        string|array $message,
    ) {
        $this->messages = is_array($message) ? array_values($message) : [$message];
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getSourceMore(): string
    {
        return $this->sourceMore;
    }

    public function setMessage(string|array $message): self
    {
        $this->messages = is_array($message) ? array_values($message) : [$message];

        return $this;
    }

    public function messageAsString(): string
    {
        return implode(' | ', $this->messages);
    }
}
