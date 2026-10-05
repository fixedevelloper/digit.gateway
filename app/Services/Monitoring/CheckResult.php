<?php

namespace App\Services\Monitoring;

final class CheckResult
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $status,
        public readonly string $message,
        public readonly int|float|null $value = null,
    ) {}

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }

    public function toArray(): array
    {
        return ['name' => $this->name, 'label' => $this->label, 'status' => $this->status, 'message' => $this->message, 'value' => $this->value];
    }
}
