<?php

namespace App\Enums;

/**
 * What applying a Pixelforge generation snapshot did to our record.
 */
enum SnapshotResult: string
{
    // The status moved forward (and the cost was recorded on success).
    case Applied = 'applied';

    // The snapshot was not newer than what we have (duplicate or out of order).
    case Stale = 'ignored_stale';

    // Pixelforge billed a generation we had already reported to the user as
    // failed. The provider cost is recorded, the user isn't charged.
    case AbsorbedLateSuccess = 'absorbed_late_success';
}
