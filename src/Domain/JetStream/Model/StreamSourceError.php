<?php

declare(strict_types=1);

namespace Dorpmaster\Nats\Domain\JetStream\Model;

use Dorpmaster\Nats\Domain\JetStream\Internal\Decimal;
use Dorpmaster\Nats\Domain\JetStream\Internal\MetadataFields;

final readonly class StreamSourceError
{
    public int|null $code;
    public int|null $errorCode;
    public string|null $description;
    public function __construct(public JsonObject $raw)
    {
        $fields            = new MetadataFields($raw, 'source.error');
        $this->code        = Decimal::toInt($fields->decimal('code', (string) PHP_INT_MAX));
        $this->errorCode   = Decimal::toInt($fields->decimal('err_code', (string) PHP_INT_MAX));
        $this->description = $fields->text('description');
    }
}
