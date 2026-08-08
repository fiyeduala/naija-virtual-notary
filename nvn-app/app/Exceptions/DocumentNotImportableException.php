<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The client's PDF cannot be opened for sealing.
 *
 * Thrown when FPDI's parser refuses the file and nothing on this server can
 * rewrite it into a form the parser accepts. The message is written to be read
 * by a notary in the middle of a session rather than by a developer in a log —
 * it has to say what to do next, because the person who sees it is the one who
 * has to tell the client something.
 */
class DocumentNotImportableException extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'This PDF is saved in a newer format than the sealing engine can open, so it '
            . 'cannot be notarised as it stands. Nothing has been lost — the document is '
            . 'still here and your placements are saved. The quickest fix is to ask the '
            . 'client to open it, choose Print, and save it again as a PDF; that produces '
            . 'a file this accepts. Uploading it as images works too. If it is urgent, an '
            . 'administrator can convert it for you.',
            0,
            $previous
        );
    }
}
