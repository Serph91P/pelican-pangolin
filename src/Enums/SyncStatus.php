<?php

namespace PelicanDev\PelicanPangolin\Enums;

enum SyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Disabled = 'disabled';
    case Error = 'error';
    case Drift = 'drift';
    case Removing = 'removing';
    case Unselected = 'unselected';
    case Blocked = 'blocked';
}
