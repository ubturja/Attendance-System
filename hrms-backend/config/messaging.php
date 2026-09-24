<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Maximum total size of one chat message
    |--------------------------------------------------------------------------
    |
    | Several files may be attached to a single message. Their combined size,
    | including files kept when a message is edited, cannot exceed this many
    | bytes. 1 GiB matches the HRMS messaging rule.
    |
    */

    'max_message_bytes' => (int) env('MESSAGING_MAX_MESSAGE_BYTES', 1073741824),

];
