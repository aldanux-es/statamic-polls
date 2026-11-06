<?php

namespace Aldanux\StatamicPolls\Enums;

/**
 * What happened to a vote. Ignored votes (bots, honeypot) are answered like Recorded ones so a
 * bot learns nothing.
 */
enum VoteOutcome: string
{
    case Recorded = 'recorded';
    case Ignored = 'ignored';
    case AlreadyVoted = 'already_voted';
    case NotOpen = 'not_open';
    case LoginRequired = 'login_required';
    case InvalidOption = 'invalid_option';
    case TooManyAttempts = 'too_many_attempts';
    case VerificationFailed = 'verification_failed';

    public function counts(): bool
    {
        return $this === self::Recorded || $this === self::Ignored;
    }

    public function httpStatus(): int
    {
        return match ($this) {
            self::Recorded, self::Ignored => 200,
            self::AlreadyVoted => 409,
            self::NotOpen => 403,
            self::LoginRequired => 401,
            self::InvalidOption, self::VerificationFailed => 422,
            self::TooManyAttempts => 429,
        };
    }
}
