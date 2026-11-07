<?php

namespace App\Modules\Tickets\Enums;

enum TicketSource: string
{
    case Web = 'web';
    case Import = 'import';
    case Email = 'email';
    case Api = 'api';
}
