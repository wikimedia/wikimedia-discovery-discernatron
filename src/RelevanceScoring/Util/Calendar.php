<?php

namespace WikiMedia\RelevanceScoring\Util;

/**
 * Very simple class allowing time mocking.
 */
class Calendar
{
    public function now()
    {
        return time();
    }
}
