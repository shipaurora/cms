@props(['url'])
{{-- The CMS's mark (resources/images/brand-mark.png: mail clients drop SVG), then its name. --}}
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block;">
<img src="{{ route('aurora.brand', 'brand-mark.png') }}" class="brand-mark" width="30" height="30" alt=""><span class="brand-name">{{ trim(strip_tags($slot)) }}</span>
</a>
</td>
</tr>
