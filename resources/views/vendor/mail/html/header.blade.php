@props(['url'])
{{-- Laravel's mail header with the Sprint mark next to the name. The image is attached to the mail (see AppServiceProvider). --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="cid:sprint-logo.png" width="32" height="32" alt="" style="width: 32px; height: 32px; border: 0; margin-right: 8px; vertical-align: middle;"><span style="vertical-align: middle;">{!! $slot !!}</span>
</a>
</td>
</tr>
