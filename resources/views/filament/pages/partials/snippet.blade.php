<div class="relative" x-data="{ copied: false }">
    <pre class="max-h-[32rem] overflow-auto rounded-xl bg-gray-950 p-4 pt-10 font-mono text-[13px] leading-relaxed text-gray-100 ring-1 ring-white/10"><code>{{ $code }}</code></pre>
    <button type="button" class="absolute top-2.5 right-2.5 rounded-md bg-white/10 px-2 py-1 text-xs font-medium text-white hover:bg-white/20" x-on:click="navigator.clipboard.writeText(@js($code)); copied = true; setTimeout(() => copied = false, 2000)" x-text="copied ? @js(__('Copied ✓')) : @js(__('Copy'))"></button>
</div>
