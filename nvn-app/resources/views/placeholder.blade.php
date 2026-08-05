@extends('layouts.auth', ['title' => $area . ' dashboard', 'subtitle' => $area . ' area'])

@section('content')
<p style="font-size:15px;text-align:center;">
    <strong>{{ $area }} dashboard</strong>
</p>
<p style="font-size:13px;color:var(--muted);text-align:center;">
    Authentication is working. This placeholder is replaced by the real
    {{ strtolower($area) }} dashboard in a later phase.
</p>

<form method="POST" action="{{ route('logout') }}" style="text-align:center;">
    @csrf
    <button type="submit">Sign out</button>
</form>
@endsection
