<?php

namespace Aldanux\StatamicPolls\Votes;

use Carbon\CarbonImmutable;

/**
 * One cast vote. Voter and IP are stored as keyed hashes (never the raw cookie, user id or
 * address), enough to stop duplicates without keeping personal data.
 */
final readonly class Vote
{
    public function __construct(
        public string $pollId,
        public string $optionKey,
        public string $voterId,
        public string $ipHash,
        public ?string $site = null,
        public ?CarbonImmutable $createdAt = null,
    ) {}

    /**
     * @param  array{poll: string, option: string, voter: string, ip: string, site?: string|null, at?: string|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['poll'],
            $data['option'],
            $data['voter'],
            $data['ip'],
            $data['site'] ?? null,
            isset($data['at']) ? CarbonImmutable::parse($data['at']) : null,
        );
    }

    /**
     * @return array{poll: string, option: string, voter: string, ip: string, site: string|null, at: string}
     */
    public function toArray(): array
    {
        return [
            'poll' => $this->pollId,
            'option' => $this->optionKey,
            'voter' => $this->voterId,
            'ip' => $this->ipHash,
            'site' => $this->site,
            'at' => ($this->createdAt ?? CarbonImmutable::now())->toIso8601String(),
        ];
    }
}
