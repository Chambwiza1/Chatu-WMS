<?php

namespace App\Libraries\Core;

/**
 * The record does not exist in the current tenant (HTTP 404). Used for every lookup by id, so an id belonging to
 * another organisation looks exactly like one that does not exist.
 */
class NotFoundException extends BizException
{
}
