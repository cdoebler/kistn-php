<?php

namespace Kistn;

enum TransmitMode: string
{
    case Always   = 'true';
    case Never    = 'false';
    case OnDemand = 'on-demand';
}
