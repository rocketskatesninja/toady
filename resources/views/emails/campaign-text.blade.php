@if(!empty($header)){{ $header }}

@endif
{{ $bodyText }}
@if(!empty($signature))

{{ $signature }}
@endif
@if(!empty($unsubscribeUrl))

--
Unsubscribe: {{ $unsubscribeUrl }}
@endif
