{{--
    "This job came from a partner body."

    One definition of the marker, because it has to look the same on six
    screens and mean the same thing on all of them: the fee is the body's
    negotiated figure, not the public price, and the platform's own notary
    must be the one to seal it.

    A bare .pill rather than a status pill. The brand colour already reads as
    a different kind of fact from "Completed" or "Awaiting you", so the two
    sit side by side without competing. Renders nothing at all for ordinary
    work, which is almost every request — so a call site needs no @if of its
    own, only the separator it already draws.

    The body's commission is deliberately absent. That is between the platform
    and the body, and a notary at the desk has no reason to see it.
--}}
@props(['request'])

@if ($request->fromOrganization())
    <span class="pill" title="Referred by {{ $request->organizationName() }} — notarized in-house">
        {{ $request->organizationName() }}
    </span>
@endif
