<?php

namespace Aldanux\StatamicPolls\Enums;

enum Audience: string
{
    case Public = 'public';
    case Authenticated = 'authenticated';
}
