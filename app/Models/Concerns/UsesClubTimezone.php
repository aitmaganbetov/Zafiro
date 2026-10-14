<?php

namespace App\Models\Concerns;

use App\Services\CurrentClub;

/**
 * Dates are stored in UTC (config app.timezone) and read in the time zone of the
 * current club, so every page, report and API response shows the club's local time.
 * Writing converts back to UTC, so stored values never depend on the club.
 */
trait UsesClubTimezone
{
    protected function asDateTime($value)
    {
        return parent::asDateTime($value)->copy()->setTimezone(app(CurrentClub::class)->timezone());
    }

    public function fromDateTime($value)
    {
        return empty($value) ? $value : parent::asDateTime($value)->copy()
            ->setTimezone(config('app.timezone'))
            ->format($this->getDateFormat());
    }
}
